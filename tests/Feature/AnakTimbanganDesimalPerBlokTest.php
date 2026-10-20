<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\Profiles\AnakTimbanganProfile;
use App\Services\CertificateExcelExporter;
use App\Services\CertificateSnapshotBuilder;
use App\Services\DataTampilanSertifikat;
use App\Support\AnakTimbanganMentah;
use App\Support\Angka;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Desimal cetak Anak Timbangan ikut workbook master PER NERACA — keputusan
 * pemilik 14 Okt 2026, mengganti "lima desimal tetap" 7 Okt 2026.
 *
 * ## Bukti master yang dipatok di sini
 *
 * Format sel `SERTIFIKAT!I` (Conventional Mass) di empat workbook tanpa sandi
 * (`1.1 Anak Timbangan F1 … imp.xlsx`, `Anak timbangan F2_IMTE-LQ-197 hal
 * 1/2/3.xlsx`) sama dengan format kolom pembacaannya di `INPUT DATA` pada 51
 * dari 51 baris, dan format kolom itu = desimal resolusi neraca yang menimbang
 * kepingnya (`INPUT DATA!AB20` → `X27:X31` "Res. Balance") pada 27 dari 27
 * baris berdata nyata: Semi Micro 0,00001 g → 5, Analytical 0,0001 g → 4,
 * Fujitsu 0,001 g → 3. Bukan digit yang diketik: `I28` F1 diketik `0.5`
 * tercetak `0,4975`, `I17` F2 hal 3 diketik `500` tercetak `500,001`.
 *
 * U95 (`SERTIFIKAT!L`, satuan mg) dua desimal tetap di 51 dari 51 baris;
 * `k` (`N38`) format `0`.
 */
class AnakTimbanganDesimalPerBlokTest extends TestCase
{
    use RefreshDatabase;

    /** Tiga neraca yang dicentang kertas lapangan set F2 1 g – 500 g (§46, §28). */
    private const TIGA_NERACA = ['Semi Micro Balance', 'Analytical Balance', 'Electronic Balance Fujitsu'];

    /** Nomor seri ketiganya di master data standar (`TabelStandarAnakTimbangan`). */
    private const SERI_TIGA_NERACA = ['C543502629', '1129063525', 'SIDIK/134/2024'];

    /**
     * Sel master → nominal, neraca workbook (`INPUT DATA!AB20`), desimal cetak.
     *
     * @return array<string, array{list<string>, float, int}>
     */
    public static function selMaster(): array
    {
        return [
            'F1 SERTIFIKAT!I17 100 g, Analytical' => [['Analytical Balance'], 100.0, 4],
            'F1 SERTIFIKAT!I28 0,5 g (diketik 0.5), Analytical' => [['Analytical Balance'], 0.5, 4],
            'F2 hal 1 SERTIFIKAT!I17 1 g, Semi Micro' => [['Semi Micro Balance'], 1.0, 5],
            'F2 hal 1 SERTIFIKAT!I24 50 g, Semi Micro' => [['Semi Micro Balance'], 50.0, 5],
            'F2 hal 2 SERTIFIKAT!I18 200 g, Analytical' => [['Analytical Balance'], 200.0, 4],
            'F2 hal 3 SERTIFIKAT!I17 500 g (diketik 500), Fujitsu' => [['Electronic Balance Fujitsu'], 500.0, 3],
            // Kertas lapangan mencentang ketiganya; tiap keping ditimbang di
            // neraca tercentang terkecil yang sanggup — hasilnya sama dengan
            // ketiga halaman workbook (satu neraca per halaman).
            'tiga neraca, 50 g → Semi Micro (hal 1)' => [self::TIGA_NERACA, 50.0, 5],
            'tiga neraca, 100 g → Analytical (hal 2)' => [self::TIGA_NERACA, 100.0, 4],
            'tiga neraca, 500 g → Fujitsu (hal 3)' => [self::TIGA_NERACA, 500.0, 3],
        ];
    }

    /** @param  list<string>  $neraca */
    private static function sesiTiruan(array $neraca, string $satuan = 'g'): CalibrationSession
    {
        return new CalibrationSession(['spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => [
            'timbangan_daftar' => $neraca,
            'satuan' => $satuan,
        ]]]);
    }

    /** @param  list<string>  $neraca */
    #[DataProvider('selMaster')]
    public function test_desimal_keping_ikut_resolusi_neraca_yang_menimbangnya(array $neraca, float $nominalG, int $desimal): void
    {
        $profil = new AnakTimbanganProfile;
        $sesi = self::sesiTiruan($neraca);

        // Sertifikat & layar HP (satuan g) — titik_ukur hasil hitung selalu gram.
        $this->assertSame($desimal, $profil->desimalSertifikatTitikSesi($sesi, $nominalG, 'g'));
        $this->assertSame($desimal, $profil->desimalSertifikatTitikSesi($sesi, $nominalG));
        // Lembar perhitungan — pembacaan mentah dalam satuan ketik.
        $this->assertSame($desimal, $profil->desimalTitikSesi($sesi, $nominalG));
        // U95 2 desimal mg = 5 desimal g, berapa pun desimal kepingnya.
        $this->assertSame(5, $profil->desimalU95TitikSesi($sesi, $nominalG, 'g'));
    }

    /**
     * Sesi bersatuan kg (Mettler 30 kg, resolusi 0,01 g): sertifikat mencetak kg
     * (5 desimal = 0,01 g), layar HP menulis gram (2 desimal = 0,01 g). Resolusi
     * yang sama, dua satuan — bukan dua aturan. U95 tetap 0,01 mg.
     */
    public function test_sesi_kg_desimal_mengikuti_satuan_yang_ditulis(): void
    {
        $profil = new AnakTimbanganProfile;
        $sesi = self::sesiTiruan(['Electronic Balance  Mettler'], 'kg');

        $this->assertSame(5, $profil->desimalSertifikatTitikSesi($sesi, 20000.0, 'kg'));
        $this->assertSame(5, $profil->desimalSertifikatTitikSesi($sesi, 20000.0));
        $this->assertSame(2, $profil->desimalSertifikatTitikSesi($sesi, 20000.0, 'g'));
        $this->assertSame(8, $profil->desimalU95TitikSesi($sesi, 20000.0, 'kg'));
        $this->assertSame(5, $profil->desimalU95TitikSesi($sesi, 20000.0, 'g'));
        // Pembacaan mentah sesi kg tersimpan dalam kg (`20` = 20 kg).
        $this->assertSame(5, $profil->desimalTitikSesi($sesi, 20.0));
    }

    /**
     * Keping yang tidak sanggup dipikul neraca mana pun (tidak akan pernah
     * terhitung) dan sesi tanpa blok jatuh ke cadangan profil, bukan ke angka
     * karangan.
     */
    public function test_tanpa_neraca_jatuh_ke_cadangan(): void
    {
        $profil = new AnakTimbanganProfile;

        $this->assertNull($profil->desimalSertifikatTitikSesi(self::sesiTiruan(['Semi Micro Balance']), 500.0));
        $this->assertNull($profil->desimalSertifikatTitikSesi(new CalibrationSession, 50.0));
        $this->assertSame(5, $profil->desimalSertifikat());
    }

    /**
     * U95 dua desimal MILIGRAM, dibulatkan setengah menjauhi nol seperti
     * format sel Excel. Dicetak dalam satuan kolomnya (g), lima desimal —
     * angkanya identik dengan kolom mg master.
     *
     * @return array<string, array{float, string}>
     */
    public static function u95Master(): array
    {
        return [
            'F2 hal 1 SERTIFIKAT!L26 0,0092 mg' => [0.00919961581905, '0,01'],
            'F2 hal 3 SERTIFIKAT!L17 1,065 mg' => [1.06527814134, '1,07'],
            'F1 SERTIFIKAT!L17 0,126 mg' => [0.126317918076, '0,13'],
            'setengah tepat 0,125 mg' => [0.125, '0,13'],
            'setengah 1,005 mg (biner 1,00499…)' => [1.005, '1,01'],
            // 3,5e-5 g / 1000 = 3,4999999999999996e-8 kg di biner — tanpa
            // pembulatan presisi-simpan di konversi kg tercetak `0,03` mg.
            'setengah 0,035 mg (pembagian kg)' => [0.035, '0,04'],
            'setengah 14,005 mg' => [14.005, '14,01'],
        ];
    }

    #[DataProvider('u95Master')]
    public function test_u95_dua_desimal_miligram_bulat_setengah_menjauhi_nol(float $mg, string $tercetakMg): void
    {
        $profil = new AnakTimbanganProfile;

        // Yang tercetak di master (kolom mg, format `0.00`).
        $this->assertSame($tercetakMg, Angka::hasil($mg, 2));

        // Jalur nyata: kalkulator memberi U95 mg/1000 dalam gram, controller
        // menyimpannya 8 desimal (`bulatkanHitungan`, `decimal(20,8)`),
        // snapshot mencetak angka tersimpan itu.
        $tersimpanG = round($mg / 1000, 8);

        $sesiG = self::sesiTiruan(self::TIGA_NERACA);
        $tercetakG = Angka::hasil($tersimpanG, $profil->desimalU95TitikSesi($sesiG, 50.0, 'g'));
        $this->assertSame($tercetakMg, self::keMg($tercetakG, 1e3), "U95 {$mg} mg: sistem mencetak {$tercetakG} g.");

        // Sesi kg: snapshot mengubah gram → kg lewat `cetakDalamSatuanAlat()`,
        // lalu mencetak delapan desimal kg (= 0,01 mg).
        $sesiKg = self::sesiTiruan(['Electronic Balance  Mettler'], 'kg');
        $cetak = $profil->cetakDalamSatuanAlat($sesiKg);
        $tercetakKg = Angka::hasil(($cetak['ubah'])($tersimpanG, 1), $profil->desimalU95TitikSesi($sesiKg, 20000.0, 'kg'));
        $this->assertSame($tercetakMg, self::keMg($tercetakKg, 1e6), "U95 {$mg} mg: sistem mencetak {$tercetakKg} kg.");
    }

    /** `0,00107` (g) → `1,07` (mg): geser koma, tanpa aritmetika biner. */
    private static function keMg(string $tercetak, float $faktor): string
    {
        $geser = (int) round(log10($faktor));
        [$bulat, $pecahan] = explode(',', $tercetak) + [1 => ''];
        $digit = ltrim($bulat.substr($pecahan, 0, $geser), '0');
        $sisa = substr($pecahan, $geser);

        return ($digit === '' ? '0' : $digit).($sisa === '' ? '' : ','.$sisa);
    }

    /** `SERTIFIKAT!N38` format `0`: k 1,9743–1,9766 tercetak `2`. */
    public function test_k_nol_desimal(): void
    {
        $profil = new AnakTimbanganProfile;

        $this->assertSame(0, $profil->desimalFaktorCakupan());
        foreach ([1.97657506583, 1.97427095703, 1.97559031501] as $k) {
            $this->assertSame('2', Angka::id($k, $profil->desimalFaktorCakupan()));
        }
    }

    /**
     * Jalur penuh: HP → simpan → setujui → snapshot → PDF/Excel, dan layar HP
     * (detail, pratinjau, lembar perhitungan) memakai desimal yang SAMA dengan
     * sertifikat. Tiga neraca dicentang; keping 50 g, 100 g, 500 g.
     */
    public function test_alur_penuh_desimal_per_keping_sama_di_semua_tampilan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $payload = $this->payloadTigaNeraca();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();
        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        // Pratinjau dulu — jalur hitung yang sama dengan simpan.
        $pratinjau = $this->actingAs($teknisi)->postJson('/api/calibrations/preview', $payload)->assertOk();
        $this->assertSame([5, 4, 3], array_column($pratinjau->json('data.titik'), 'desimal'));
        $this->assertSame([5, 5, 5], array_column($pratinjau->json('data.titik'), 'desimal_u95'));
        $this->assertSame([5, 4, 3], array_column($pratinjau->json('data.lembar_perhitungan.1.titik'), 'desimal'));

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', $payload)->assertCreated()->json('data.id');

        $detail = $this->actingAs($admin)->getJson("/api/calibrations/{$id}")->assertOk();
        $this->assertSame([5, 4, 3], array_column($detail->json('data.titik'), 'desimal'));
        $this->assertSame([5, 5, 5], array_column($detail->json('data.titik'), 'desimal_u95'));

        $perhitungan = $this->actingAs($admin)->getJson("/api/calibrations/{$id}/perhitungan")->assertOk();
        $this->assertSame([5, 4, 3], array_column($perhitungan->json('data.hasil.1.titik'), 'desimal'));

        $this->denganOrderNumber($id)->actingAs($admin)
            ->postJson("/api/calibrations/{$id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertOk();

        $sertifikat = CalibrationSession::findOrFail($id)->certificate()->firstOrFail();
        $baris = collect($sertifikat->snapshot['hasil'])->sortBy('titik_ke')->values();

        $this->assertSame([5, 4, 3], $baris->pluck('desimal')->all());
        $this->assertSame([5, 5, 5], $baris->pluck('desimal_u95')->all());
        $this->assertSame([0, 0, 0], $baris->pluck('desimal_k')->all());
        $this->assertSame(['g', 'g', 'g'], $baris->pluck('satuan')->all());

        // Layar = sertifikat, titik demi titik.
        $this->assertSame($baris->pluck('desimal')->all(), array_column($detail->json('data.titik'), 'desimal'));

        // PDF. Sertifikat baru berbentuk master (K-50-15, pemilik 20 Okt 2026):
        // titik desimal seperti tampilan sel master — desimalnya tetap sama.
        $html = $this->html($sertifikat);
        foreach ($baris as $b) {
            $this->assertStringContainsString('<td>'.Angka::keTitik(Angka::hasil((float) $b['unit_under_test'], $b['desimal'])).'</td>', $html);
            $this->assertStringContainsString('<td>'.Angka::keTitik(Angka::hasil((float) $b['u95'], 5)).'</td>', $html);
        }
        // Keping 500 g: tiga desimal, bukan lima.
        $this->assertStringNotContainsString('<td>'.Angka::keTitik(Angka::hasil((float) $baris[2]['unit_under_test'], 5)).'</td>', $html);
        $this->assertMatchesRegularExpression('/Coverage Factor \( k \) =\s*2\s*</', $html);

        // Excel — angka (bukan teks), dibulatkan sama persis dengan PDF.
        $sel = $this->selExcel($sertifikat);
        foreach ($baris as $b) {
            $barisExcel = collect($sel)->first(
                fn (array $r): bool => is_numeric($r[0] ?? null) && abs((float) $r[0] - (float) $b['standard_value']) < 1e-9,
            );
            $this->assertNotNull($barisExcel, 'Baris Excel keping '.$b['standard_value'].' g nggak ketemu.');
            $this->assertEqualsWithDelta(round((float) $b['unit_under_test'], $b['desimal']), (float) $barisExcel[1], 1e-12);
            $this->assertEqualsWithDelta(round((float) $b['u95'], 5), (float) $barisExcel[3], 1e-12);
        }
    }

    /**
     * Sertifikat yang SUDAH terbit tidak berubah bentuk: desimalnya dibaca dari
     * snapshot, bukan dihitung ulang dari profil. Snapshot di bawah meniru
     * bentuk terbitan 7–13 Okt 2026 (lima desimal tetap, tanpa `desimal_k`).
     *
     * Ikut: sesi kg — sertifikat menulis kg, layar menulis gram.
     */
    public function test_snapshot_lama_tidak_berubah_dan_sesi_kg(): void
    {
        $this->seed(DatabaseSeeder::class);

        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();
        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', $this->payloadTigaNeraca())->assertCreated()->json('data.id');
        $this->denganOrderNumber($id)->actingAs($admin)->postJson("/api/calibrations/{$id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])->assertOk();

        $sertifikat = CalibrationSession::findOrFail($id)->certificate()->firstOrFail();
        $snapshot = $sertifikat->snapshot;
        foreach ($snapshot['hasil'] as $i => $b) {
            $snapshot['hasil'][$i]['desimal'] = 5;
            $snapshot['hasil'][$i]['desimal_u95'] = 5;
            unset($snapshot['hasil'][$i]['desimal_k']);
        }
        $snapshot['desimal'] = 5;
        // Terbitan 7–13 Okt belum punya `tata_letak` (K-50-15 lahir 18 Okt):
        // tanpa kunci itu lembar dicetak bentuk lama (koma, k 2 desimal).
        unset($snapshot['tata_letak'], $snapshot['satuan_cetak']);
        $sertifikat->update(['snapshot' => $snapshot]);
        $sebelum = $sertifikat->fresh()->snapshot;

        $html = $this->html($sertifikat->fresh());
        $sel = $this->selExcel($sertifikat->fresh());
        $this->actingAs($admin)->getJson("/api/certificates/{$sertifikat->id}")->assertOk();
        $this->actingAs($admin)->getJson("/api/calibrations/{$id}")->assertOk();

        $this->assertEquals($sebelum, $sertifikat->fresh()->snapshot, 'Membaca sertifikat tidak boleh menulis ulang snapshot-nya.');

        $baris = collect($sebelum['hasil'])->sortBy('titik_ke')->values();
        $keping500 = $baris[2];
        // Lima desimal seperti waktu terbit, bukan tiga menurut aturan baru.
        $this->assertStringContainsString('<td>'.Angka::hasil((float) $keping500['unit_under_test'], 5).'</td>', $html);
        $this->assertStringNotContainsString('<td>'.Angka::hasil((float) $keping500['unit_under_test'], 3).'</td>', $html);
        $barisExcel = collect($sel)->first(
            fn (array $r): bool => is_numeric($r[0] ?? null) && abs((float) $r[0] - 500.0) < 1e-9,
        );
        $this->assertEqualsWithDelta(round((float) $keping500['unit_under_test'], 5), (float) $barisExcel[1], 1e-12);
        // `k` tanpa `desimal_k` = perilaku lama (2 desimal, nol belakang dibuang).
        $this->assertStringContainsString(Angka::idRingkas((float) $baris[0]['faktor_cakupan_k'], 2), $html);

        // Sesi kg, Mettler 30 kg: sertifikat kg 5 desimal & U95 8; layar g 2 & 5.
        $idKg = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            ...$this->payloadTigaNeraca(),
            // Tanggal sama dengan `test_satuan_kg_terhitung_sama_dengan_gram`.
            'tanggal_kalibrasi' => '2025-12-01',
            'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => [
                ...$this->blokSesi(), 'kelas_uut' => 'M2', 'kelas_standar' => 'F1', 'satuan' => 'kg',
            ]],
            'standar_dicek' => [$this->centang('3127471')],
            'measurements' => [[
                'titik_ukur' => 20.0,
                'at_s1' => [20.00180], 'at_t1' => [19.99075], 'at_t2' => [19.99093], 'at_s2' => [20.00180],
            ]],
        ])->assertCreated()->json('data.id');

        $sesiKg = CalibrationSession::with(['equipment', 'uncertaintyCalculations', 'organization'])->findOrFail($idKg);
        $this->assertCount(1, $sesiKg->uncertaintyCalculations, 'Keping 20 kg harus terhitung.');
        $barisKg = (new ReflectionMethod(CertificateSnapshotBuilder::class, 'hasil'))
            ->invoke(app(CertificateSnapshotBuilder::class), $sesiKg);
        $this->assertSame('kg', $barisKg[0]['satuan']);
        $this->assertSame(5, $barisKg[0]['desimal']);
        $this->assertSame(8, $barisKg[0]['desimal_u95']);

        $layarKg = $this->actingAs($admin)->getJson("/api/calibrations/{$idKg}")->assertOk();
        $this->assertSame('g', $layarKg->json('data.titik.0.satuan'));
        $this->assertSame(2, $layarKg->json('data.titik.0.desimal'));
        $this->assertSame(5, $layarKg->json('data.titik.0.desimal_u95'));
    }

    /** @return array<string, mixed> */
    private function blokSesi(): array
    {
        return [
            'kelas_uut' => 'F1',
            'kelas_standar' => 'E2',
            'meter_lingkungan' => 'Thermobarometer',
            'kapasitas_min_g' => 1,
            'kapasitas_g' => 500,
            'suhu_awal' => 23.1, 'suhu_akhir' => 23.0,
            'kelembaban_awal' => 55.0, 'kelembaban_akhir' => 56.0,
            'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
        ];
    }

    /** @return array{standard_id: int, dipakai: bool} */
    private function centang(string $seri): array
    {
        return ['standard_id' => Standard::where('serial_number', $seri)->firstOrFail()->id, 'dipakai' => true];
    }

    /**
     * Keping 50 g, 100 g, 500 g ditulis dengan resolusi neraca masing-masing.
     *
     * @return array<string, mixed>
     */
    private function payloadTigaNeraca(): array
    {
        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-AT-001')->firstOrFail();

        return [
            'equipment_id' => $contoh->equipment_id,
            'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
            'tanggal_kalibrasi' => '2026-09-11',
            'suhu_awal' => 23.1, 'suhu_akhir' => 23.0,
            'kelembaban_awal' => 55.0, 'kelembaban_akhir' => 56.0,
            'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => $this->blokSesi()],
            'standar_dicek' => array_map(fn (string $s): array => $this->centang($s), self::SERI_TIGA_NERACA),
            'measurements' => [
                ['titik_ukur' => 50.0, 'at_s1' => [50.00030], 'at_t1' => [50.00031], 'at_t2' => [50.00032], 'at_s2' => [50.00030]],
                ['titik_ukur' => 100.0, 'at_s1' => [100.0004], 'at_t1' => [100.0005], 'at_t2' => [100.0006], 'at_s2' => [100.0004]],
                ['titik_ukur' => 500.0, 'at_s1' => [500.001], 'at_t1' => [500.002], 'at_t2' => [500.002], 'at_s2' => [500.001]],
            ],
        ];
    }

    private function html(Certificate $sertifikat): string
    {
        $bahan = app(DataTampilanSertifikat::class)->untuk($sertifikat);

        return view('sertifikat.pdf', [...$bahan, 'paksaPadat' => false])->render();
    }

    /** @return list<list<mixed>> */
    private function selExcel(Certificate $sertifikat): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'uji-at-').'.xlsx';
        app(CertificateExcelExporter::class)->satu($sertifikat, $berkas);

        $sel = [];
        $pembaca = new Reader;
        $pembaca->open($berkas);
        foreach ($pembaca->getSheetIterator() as $lembar) {
            foreach ($lembar->getRowIterator() as $baris) {
                $sel[] = array_map(static fn ($c) => $c->getValue(), $baris->getCells());
            }
        }
        $pembaca->close();
        @unlink($berkas);

        return $sel;
    }
}
