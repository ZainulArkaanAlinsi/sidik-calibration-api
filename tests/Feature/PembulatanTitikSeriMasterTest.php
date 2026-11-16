<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\DialIndicatorProfile;
use App\Services\Calibration\Profiles\JangkaSorongProfile;
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Services\Calibration\Profiles\ProvingRingProfile;
use App\Services\Calibration\Profiles\ThermocoupleProfile;
use App\Services\Calibration\Profiles\TidsProfile;
use App\Services\Calibration\Profiles\TimbanganProfile;
use App\Services\Calibration\Profiles\TimerStopwatchProfile;
use App\Services\CertificateSnapshotBuilder;
use App\Support\AngkaFloatEksak;
use App\Support\PresisiPenuh;
use App\Support\TataLetakSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PK-1 — pembulatan titik SERI sertifikat sama dengan master (audit 16 Nov, G1).
 *
 * Master = Excel: yang tercetak adalah tampilan double sel. Nilai tepat di
 * tengah dua pembulatan (`x,xx5`) jatuh menurut sisa biner double-nya:
 *
 *     TIDS Recorder `071-CAL-325` titik 3: 94,235 − 93,48 = 0,7549999999999955
 *     master `SERTIFIKAT!L22` mencetak 0,75; aplikasi dulu mencetak 0,76.
 *
 * Sebabnya bukan aturan cetak (`Angka::tampilanExcel` sudah meniru Excel),
 * melainkan nilai yang sampai kepadanya: `uncertainty_calculations.koreksi`
 * `decimal(20,8)` sudah dibulatkan ke 0,755 — sisa biner yang menentukan arah
 * seri hilang. Perbaikannya membawa float aslinya di jejak baris itu
 * (`App\Support\PresisiPenuh`); kolom, migrasi, dan aturan cetak tidak berubah.
 *
 * Yang dijaga di sini: (1) kasus master TIDS ujung ke ujung lewat HTTP, (2)
 * keluaran API tidak berubah bentuk, (3) baris lama (tanpa kunci) tercetak
 * persis seperti dulu, (4) lebih dari satu profil membaca lewat jalur yang
 * sama, (5) hitung ulang tidak menghilangkan float beku.
 */
class PembulatanTitikSeriMasterTest extends TestCase
{
    use RefreshDatabase;

    /** Ejaan yang MENGIKAT — persis lampiran akreditasi LK-285-IDN no. 2. */
    private const NAMA_LAMPIRAN = 'Temperatur Indikator dengan Sensor';

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();
    }

    private function alatTids(): Equipment
    {
        return Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'suhu-dan-kelembapan'])->id,
            'nama_alat' => 'Multiparameter (Suhu)',
            'nama_alat_kemampuan' => self::NAMA_LAMPIRAN,
            'range_min' => -20, 'range_max' => 600,
            'satuan' => '°C', 'resolusi' => 0.01, 'toleransi' => null,
        ]);
    }

    private function seedCmcTids(): void
    {
        foreach ([[-20.0, 150.0, 0.86], [150.0, 400.0, 1.4], [400.0, 600.0, 3.1]] as [$min, $maks, $u]) {
            CalibrationCapability::factory()->create([
                'nama_alat' => self::NAMA_LAMPIRAN,
                'range_min' => $min,
                'range_max' => $maks,
                'satuan' => '°C',
                'ketidakpastian_terbaik' => $u,
                'satuan_ketidakpastian' => '°C',
                'metode' => TidsProfile::KODE_METODE,
            ]);
        }
    }

    /**
     * Sesi Recorder `071-CAL-325` persis seperti `TidsMasterTest` /
     * `TidsU95TidakBocorTest` — masukannya salinan `INPUT DATA!` master.
     *
     * @return array<string, mixed>
     */
    private function payloadRecorder(Equipment $alat, Standard $recorder): array
    {
        return [
            'equipment_id' => $alat->id,
            'standard_id' => $recorder->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_ruang' => 29.55,
            'kelembaban' => 60.5,
            'tipe_sensor' => 'Type K',
            'alat_bantu' => 'A',
            'titik_es' => [0.2, 0.4],
            'measurements' => [
                ['titik_ukur' => 0.0, 'satuan' => '°C', 'no_probe' => 1,
                    'standar' => [0.0, 0.0, 0.0, 0.0, 0.0], 'uut' => [0.0, 0.0, 0.0, 0.0, 0.0]],
                ['titik_ukur' => 60.0, 'satuan' => '°C', 'no_probe' => 2,
                    'standar' => [60.1, 60.1, 60.1, 60.2, 60.2], 'uut' => [60.3, 60.34, 60.45, 60.47, 60.41]],
                ['titik_ukur' => 100.0, 'satuan' => '°C', 'no_probe' => 3,
                    'standar' => [92.8, 93.4, 94.5, 94.6, 94.6], 'uut' => [92.29, 92.85, 94.05, 94.09, 94.12]],
                ['titik_ukur' => 200.0, 'satuan' => '°C', 'no_probe' => 4,
                    'standar' => [193.0, 193.0, 193.1, 193.1, 193.1], 'uut' => [192.17, 192.19, 192.21, 192.24, 192.25]],
            ],
        ];
    }

    /** @return array{CalibrationSession, array<string, mixed>} */
    private function simpanRecorder(): array
    {
        $alat = $this->alatTids();
        $this->seedCmcTids();

        $recorder = Standard::factory()->create([
            'nama' => 'Temperature Recorder Graphtech GL840',
            'merk' => 'Graphtech',
            'serial_number' => 'C305B1470',
        ]);

        $payload = $this->payloadRecorder($alat, $recorder);

        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated()
            ->json('data.id');

        return [CalibrationSession::findOrFail($id), $payload];
    }

    /**
     * Teks sel seperti yang dicetak (setelah pemisah desimal tata letak),
     * dinormalkan ke titik desimal supaya tidak bergantung bentuk tata letak.
     *
     * @param  array<string, mixed>  $potongan  `hasil` + `desimal` (+ `tata_letak`)
     */
    private function cetak(array $potongan, int $baris, string $kolom): string
    {
        $teks = TataLetakSertifikat::teksSel($potongan['hasil'][$baris], $kolom, $potongan, (int) $potongan['desimal']);

        return str_replace(',', '.', $teks);
    }

    // ---------------------------------------------------------------------
    // 1. Kasus master TIDS, ujung ke ujung
    // ---------------------------------------------------------------------

    public function test_tids_titik_tiga_tercetak_0_75_seperti_master_bukan_0_76(): void
    {
        [$sesi] = $this->simpanRecorder();

        $baris = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
        $this->assertCount(4, $baris);

        // Data tersimpan TIDAK berubah: kolom tetap yang dibulatkan 8 desimal,
        // persis seperti sebelum perbaikan (tanpa migrasi, tanpa nilai lain).
        $this->assertSame(0.755, (float) $baris[2]->koreksi, 'Kolom `koreksi` tetap 8 desimal: 0,755.');

        // Float asli dibawa di jejak baris itu, sebagai teks round-trip.
        $beku = PresisiPenuh::petaBekuBaris($baris[2]);
        $this->assertIsArray($beku, 'Baris titik seri wajib membawa float aslinya.');
        $this->assertIsString($beku['koreksi']);
        $this->assertSame('0.7549999999999955', $beku['koreksi'], 'Teks round-trip float master `SERTIFIKAT!L22`, apa adanya.');

        // Di kolom JSON (MySQL: tipe JSON, bukan teks) kunci beku tetap STRING —
        // bukan double yang boleh bergeser satu ULP — dan tidak bergeser sedikit pun.
        $mentah = json_decode((string) DB::table('uncertainty_calculations')->where('id', $baris[2]->id)->value('type_b_components'), true);
        $this->assertIsString($mentah[PresisiPenuh::KUNCI]['koreksi'], 'Di kolom JSON, float beku tetap teks.');
        $this->assertSame('0.7549999999999955', $mentah[PresisiPenuh::KUNCI]['koreksi']);
        $asli = (float) $beku['koreksi'];
        $this->assertEqualsWithDelta(0.755, $asli, 1e-12);
        $this->assertLessThan(0.755, $asli, 'Sisa biner di BAWAH tengah — itu yang bikin master mencetak 0,75.');

        // Sertifikat BARU: dari baris tersimpan, lewat pembuat snapshot produksi.
        $sertifikat = Certificate::factory()->create(['calibration_session_id' => $sesi->id]);
        $snapshot = app(CertificateSnapshotBuilder::class)->bangun($sesi->fresh(), $sertifikat);

        $this->assertSame($asli, $snapshot['hasil'][2]['correction'], 'Snapshot membawa float aslinya.');
        $this->assertSame('0.75', $this->cetak($snapshot, 2, 'koreksi'), 'SERTIFIKAT!L22 master = 0,75.');

        // Standard Reading titik itu (E22) master mencetak 94,24 — tidak boleh bergeser.
        $this->assertSame('94.24', $this->cetak($snapshot, 2, 'standar'));

        // Titik lain yang bukan seri tidak berubah.
        $this->assertSame('0.94', $this->cetak($snapshot, 3, 'koreksi'));
        $this->assertSame('-0.18', $this->cetak($snapshot, 1, 'koreksi'));
    }

    /**
     * Keluaran API tidak boleh berubah bentuk: klien membaca
     * `type_b_components` sebagai DAFTAR (Dart `List`), dan kunci beku milik
     * server tidak boleh bocor ke sana — di pratinjau maupun di sesi tersimpan.
     */
    public function test_api_tidak_membocorkan_kunci_beku_dan_pratinjau_sama_dengan_tersimpan(): void
    {
        [$sesi, $payload] = $this->simpanRecorder();

        $tersimpan = $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->json('data.titik');

        $pratinjau = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $payload)
            ->assertOk()
            ->json('data.titik');

        $this->assertCount(4, $tersimpan);
        $this->assertCount(4, $pratinjau);

        foreach ($tersimpan as $i => $titik) {
            $this->assertArrayNotHasKey(PresisiPenuh::KUNCI, $titik['type_b_components'], "Kunci beku bocor ke API (tersimpan, titik {$i}).");
            $this->assertArrayNotHasKey(PresisiPenuh::KUNCI, $pratinjau[$i]['type_b_components'], "Kunci beku bocor ke API (pratinjau, titik {$i}).");
            $this->assertTrue(array_is_list($titik['type_b_components']), 'Klien membaca daftar komponen.');
            // Struktur, kunci, dan teks dibandingkan KETAT; angka desimal dengan
            // selisih relatif 1e-12. Kolom JSON MySQL menggeser double 16–17
            // digit satu ULP (0.37202150475476453 → 0.3720215047547646 di CI) —
            // perilaku lama DB yang tidak ada hubungannya dengan float beku
            // (kunci beku sendiri teks, lihat tes di atas).
            $this->assertKomponenSama($pratinjau[$i]['type_b_components'], $titik['type_b_components'], "type_b_components titik {$i}");
            $this->assertEqualsWithDelta((float) $pratinjau[$i]['koreksi'], (float) $titik['koreksi'], 1e-12);
        }
    }

    /**
     * Dua daftar komponen budget sama: kunci & teks persis, bilangan desimal
     * dalam selisih relatif 1e-12 (derau ULP kolom JSON MySQL).
     */
    private function assertKomponenSama(mixed $diharapkan, mixed $aktual, string $jalur): void
    {
        if (is_array($diharapkan)) {
            $this->assertIsArray($aktual, $jalur);
            $this->assertSame(array_keys($diharapkan), array_keys($aktual), "Kunci beda di {$jalur}.");

            foreach ($diharapkan as $kunci => $nilai) {
                $this->assertKomponenSama($nilai, $aktual[$kunci], "{$jalur}.{$kunci}");
            }

            return;
        }

        if (is_float($diharapkan) || is_float($aktual)) {
            $this->assertEqualsWithDelta((float) $diharapkan, (float) $aktual, 1e-12 * max(1.0, abs((float) $diharapkan)), $jalur);

            return;
        }

        $this->assertSame($diharapkan, $aktual, $jalur);
    }

    // ---------------------------------------------------------------------
    // 2. Sertifikat lama / baris lama tidak bergeser
    // ---------------------------------------------------------------------

    /**
     * Baris yang lahir sebelum perbaikan tidak punya kunci beku. Dibaca dari
     * kolom seperti dulu → sertifikat yang dibangun ulang dari baris itu tetap
     * 0,76. (Itu BUKAN klaim cetaknya benar; itu klaim cetaknya tidak bergeser
     * diam-diam. Memperbaikinya = hitung ulang sesinya.)
     */
    public function test_baris_tanpa_kunci_beku_tercetak_persis_seperti_dulu(): void
    {
        [$sesi] = $this->simpanRecorder();

        // Bentuk baris lama: kunci beku dibuang, kolom tak disentuh — lewat
        // query builder, bukan model, supaya tak ada yang membekukan ulang.
        foreach ($sesi->uncertaintyCalculations as $baris) {
            $jejak = (array) $baris->type_b_components;     // cast: sudah tanpa kunci beku
            DB::table('uncertainty_calculations')->where('id', $baris->id)->update(['type_b_components' => json_encode($jejak)]);
        }

        $sesi = $sesi->fresh();
        $this->assertNull(PresisiPenuh::petaBekuBaris($sesi->uncertaintyCalculations()->orderBy('titik_ke')->get()[2]), 'Prasyarat: kunci beku sudah tidak ada.');

        $sertifikat = Certificate::factory()->create(['calibration_session_id' => $sesi->id]);
        $snapshot = app(CertificateSnapshotBuilder::class)->bangun($sesi, $sertifikat);

        $this->assertSame(0.755, $snapshot['hasil'][2]['correction']);
        $this->assertSame('0.76', $this->cetak($snapshot, 2, 'koreksi'));
    }

    /**
     * Snapshot yang SUDAH terbit membeku angkanya (`correction: 0.755`) dan
     * renderer tidak disentuh perbaikan ini: cetak ulang tetap 0,76 — sama
     * persis seperti yang dipegang pelanggan. Hanya snapshot BARU yang lain.
     */
    public function test_snapshot_lama_dirender_ulang_tetap_seperti_yang_terbit(): void
    {
        $snapshotLama = [
            'desimal' => 2,
            'hasil' => [[
                'titik_ke' => 3, 'standard_value' => 94.235, 'unit_under_test' => 93.48,
                'correction' => 0.755, 'u95' => 1.65, 'desimal' => 2,
            ]],
        ];

        $this->assertSame('0.76', $this->cetak($snapshotLama, 0, 'koreksi'));
        $this->assertSame('94.24', $this->cetak($snapshotLama, 0, 'standar'));
    }

    // ---------------------------------------------------------------------
    // 3. Lebih dari satu profil, jalur yang sama
    // ---------------------------------------------------------------------

    /** @return array<string, array{class-string<CalibrationProfile>}> */
    public static function profil(): array
    {
        return [
            'timbangan' => [TimbanganProfile::class],
            'micrometer' => [MicrometerProfile::class],
            'thermocouple' => [ThermocoupleProfile::class],
            'jangka sorong' => [JangkaSorongProfile::class],
            'dial indicator' => [DialIndicatorProfile::class],
        ];
    }

    /**
     * Desimal yang dicetak untuk satu kolom tabel hasil, atau null bila kolom
     * itu `General` (presisi penuh: tidak ada pembulatan yang bisa jatuh di seri).
     *
     * @param  array<string, mixed>  $baris  satu baris `hasil` snapshot
     * @param  array<string, mixed>  $potongan
     */
    private function desimalKolom(array $baris, array $potongan, string $kolom): ?int
    {
        if (isset($baris['format'][$kolom])) {
            return TataLetakSertifikat::desimalFormat($baris['format'][$kolom]);
        }

        return match ($kolom) {
            'koreksi' => (int) ($potongan['desimal_koreksi'] ?? $baris['desimal']),
            'u95' => (int) ($baris['desimal_u95'] ?? $baris['desimal']),
            default => (int) $baris['desimal'],
        };
    }

    /**
     * Titik seri di tiap profil, di SEMUA kolom yang dicetak (Standard Value,
     * Unit Under Test, Correction, U95%): nilai tepat di tengah pada desimal
     * kolom itu sendiri, sedikit di BAWAH tengah seperti sel Excel. Baris
     * ditulis lewat model (jalur yang dipakai hitung ulang & seeder).
     *
     * Pembandingnya fungsi cetak produksi yang sama (`teksSel`) diberi float
     * asli — jadi yang diuji hanya "float asli sampai ke snapshot", bukan
     * tebakan desimal tiap profil.
     *
     * @param  class-string<CalibrationProfile>  $kelas
     */
    #[DataProvider('profil')]
    public function test_titik_seri_semua_kolom_cetak_tiap_profil_seperti_nilai_double(string $kelas): void
    {
        $profil = new $kelas;
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
        ]);
        $sesi = CalibrationSession::factory()->create(['equipment_id' => $alat->id]);

        $baris = static fn (array $nilai): array => [
            'calibration_session_id' => $sesi->id,
            'titik_ke' => 1,
            'error' => -$nilai['koreksi'],
            'standar_deviasi' => 0.01,
            'jumlah_pengulangan' => 3,
            'type_a' => 0.006,
            'type_b' => 0.1,
            'ketidakpastian_gabungan' => 0.1,
            'faktor_cakupan_k' => 2.0,
            'keputusan' => 'PASS',
            'metode' => 'uji',
            'calculated_at' => now(),
            ...$nilai,
        ];

        // kolom cetak => [kolom `uncertainty_calculations`, kunci `hasil` snapshot, besaran kasar nilai itu]
        $kolomCetak = [
            'standar' => ['titik_ukur', 'standard_value', 50.0],
            'uut' => ['rata_rata', 'unit_under_test', 51.0],
            'koreksi' => ['koreksi', 'correction', 0.7],
            'u95' => ['ketidakpastian_diperluas', 'u95', 0.2],
        ];

        // 1) Probe: berapa desimal tiap kolom di sertifikat profil ini?
        $probe = UncertaintyCalculation::create($baris([
            'titik_ukur' => 50.0, 'rata_rata' => 51.0, 'koreksi' => 0.1, 'ketidakpastian_diperluas' => 0.2,
        ]));
        $potongan = app(CertificateSnapshotBuilder::class)->potonganCetak($sesi->fresh());
        $desimal = [];

        foreach (array_keys($kolomCetak) as $kolom) {
            $desimal[$kolom] = $this->desimalKolom($potongan['hasil'][0], $potongan, $kolom);
        }

        $probe->delete();

        // 2) Titik seri pada desimal tiap kolom, di bawah tengah ±6e-15 relatif
        //    (derau seperti sel Excel hasil pengurangan dua bilangan sebesar itu).
        $asli = [];
        $nilai = ['titik_ukur' => 50.0, 'rata_rata' => 51.0, 'koreksi' => 0.1, 'ketidakpastian_diperluas' => 0.2];

        foreach ($kolomCetak as $kolom => [$kolomDb, , $dasar]) {
            $d = $desimal[$kolom];

            if ($d === null) {
                continue;           // `General`: presisi penuh, tak ada pembulatan
            }

            // N,5 satuan desimal ke-d: tepat di tengah dua pembulatan.
            $tengah = ((int) round($dasar * 10 ** $d) + 0.5) / (10 ** $d);
            $asli[$kolom] = $tengah * (1 - 6e-15);
            $nilai[$kolomDb] = $asli[$kolom];

            $this->assertNotSame($asli[$kolom], round($asli[$kolom], 8), 'Prasyarat: float asli kehilangan sesuatu di 8 desimal.');
        }

        $this->assertNotSame([], $asli, "{$kelas}: semua kolom `General` — profil ini tidak punya pembulatan yang bisa diuji.");

        UncertaintyCalculation::create($baris($nilai));

        $potongan = app(CertificateSnapshotBuilder::class)->potonganCetak($sesi->fresh());
        $b = $potongan['hasil'][0];

        foreach ($asli as $kolom => $float) {
            [, $kunci] = $kolomCetak[$kolom];
            $this->assertSame($desimal[$kolom], $this->desimalKolom($b, $potongan, $kolom), "{$kelas}/{$kolom}: desimal bergeser antara probe dan data uji.");

            $tanda = $b[$kunci] < 0 ? -1.0 : 1.0;
            $this->assertSame($float, abs($b[$kunci]), "{$kelas}/{$kolom}: float asli tidak sampai ke snapshot.");

            $teks = fn (float $v): string => TataLetakSertifikat::teksSel([...$b, $kunci => $tanda * $v], $kolom, $potongan, (int) $potongan['desimal']);
            $master = $teks($float);

            $this->assertNotSame(
                $master,
                $teks(round($float, 8)),
                "Sapuan tanpa gigi di {$kelas}/{$kolom} (d={$desimal[$kolom]}): nilai terbulat harusnya tercetak lain dari master.",
            );
            $this->assertSame(
                $master,
                TataLetakSertifikat::teksSel($b, $kolom, $potongan, (int) $potongan['desimal']),
                "{$kelas}/{$kolom}: titik seri tercetak lain dari master.",
            );
        }
    }

    // ---------------------------------------------------------------------
    // 4. Hitung ulang
    // ---------------------------------------------------------------------

    /**
     * `kalibrasi:hitung-ulang` menulis ulang baris lewat model dengan float
     * mentah (tanpa `bulatkanHitungan`): kunci beku harus ikut lahir di sana.
     */
    public function test_hitung_ulang_tetap_membawa_float_beku(): void
    {
        [$sesi] = $this->simpanRecorder();

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesi->nomor_sesi], '--no-interaction' => true])
            ->assertSuccessful();

        $baris = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
        $this->assertCount(4, $baris);
        $this->assertLessThan(0.755, (float) (PresisiPenuh::petaBekuBaris($baris[2])['koreksi'] ?? 9.0));

        $sertifikat = Certificate::factory()->create(['calibration_session_id' => $sesi->id]);
        $snapshot = app(CertificateSnapshotBuilder::class)->bangun($sesi->fresh(), $sertifikat);

        $this->assertSame('0.75', $this->cetak($snapshot, 2, 'koreksi'));
    }

    // ---------------------------------------------------------------------
    // 5. Penyimpan model: hook tidak melempar, simpan-ulang tidak menghapus kunci
    // ---------------------------------------------------------------------

    /** Sesi kosong untuk alat bernama kemampuan `$profil` (tanpa titik hitung). */
    private function sesiKosong(CalibrationProfile $profil): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
        ]);

        return CalibrationSession::factory()->create(['equipment_id' => $alat->id]);
    }

    /**
     * Atribut satu baris hitung minimal.
     *
     * @param  array<string, mixed>  $nilai
     * @return array<string, mixed>
     */
    private function atributBaris(int $sesiId, array $nilai): array
    {
        return [
            'calibration_session_id' => $sesiId,
            'titik_ke' => 1,
            'titik_ukur' => 50.0,
            'rata_rata' => 51.0,
            'koreksi' => 0.1,
            'error' => -0.1,
            'standar_deviasi' => 0.01,
            'jumlah_pengulangan' => 3,
            'type_a' => 0.006,
            'type_b' => 0.1,
            'ketidakpastian_gabungan' => 0.1,
            'ketidakpastian_diperluas' => 0.2,
            'faktor_cakupan_k' => 2.0,
            'keputusan' => 'PASS',
            'metode' => 'uji',
            'calculated_at' => now(),
            ...$nilai,
        ];
    }

    /**
     * Hook `save()` tidak boleh melempar apa pun bentuk `type_b_components`.
     *
     * CI 16 Nov 2026: `SnapshotSertifikatTahanUrutanTest` menyalin
     * `getAttributes()` mentah (jejak berupa TEKS JSON) ke `create()`, dan hook
     * versi pertama melempar `TypeError` (`?array`, string diberikan). Bentuk
     * yang bukan daftar/peta dilewati dan disimpan apa adanya, seperti cast
     * `array` bawaan dulu; kolom tetap dibaca seperti sebelum PR ini.
     */
    public function test_simpan_baris_dengan_jejak_bukan_daftar_tidak_melempar(): void
    {
        $sesi = $this->sesiKosong(new TimbanganProfile);

        $bentuk = [
            '[{"sumber":"a"}]',
            '{"0":{"sumber":"a"},"presisi_penuh":{"koreksi":"0.7549999999999955"}}',
            '42',
            'bukan json',
            '"teks"',
            '',
        ];

        foreach ($bentuk as $ke => $mentah) {
            $baris = UncertaintyCalculation::create($this->atributBaris($sesi->id, [
                'titik_ke' => $ke + 1,
                'koreksi' => 0.7549999999999955,          // akan dibekukan bila jejaknya daftar
                'type_b_components' => $mentah,
            ]));

            $this->assertTrue($baris->exists, "Jejak `{$mentah}` menggagalkan simpan.");
            $this->assertSame($mentah, $baris->fresh()->type_b_components, 'Nilai bukan array disimpan apa adanya (perilaku cast `array` lama).');
            $this->assertNull(PresisiPenuh::petaBekuBaris($baris->fresh()), 'Teks JSON mentah tidak dibaca sebagai peta beku.');
            $this->assertSame(0.755, PresisiPenuh::baca($baris->fresh(), 'koreksi'), 'Dibaca dari kolom, seperti dulu.');
        }
    }

    /** Jejak kosong, null, atau daftar: baris baru membekukan float aslinya lewat penyimpan model. */
    public function test_simpan_baris_baru_membekukan_float_asli_untuk_semua_bentuk_jejak(): void
    {
        $sesi = $this->sesiKosong(new TimbanganProfile);
        $komponen = [['sumber' => 'resolusi', 'keterangan' => 'x', 'distribusi' => 'persegi', 'nilai' => 0.003]];

        foreach ([null, [], $komponen] as $ke => $jejak) {
            $baris = UncertaintyCalculation::create($this->atributBaris($sesi->id, [
                'titik_ke' => $ke + 1,
                'koreksi' => 0.7549999999999955,
                'type_b_components' => $jejak,
            ]))->fresh();

            $this->assertSame(['koreksi' => '0.7549999999999955'], PresisiPenuh::petaBekuBaris($baris), 'Jejak '.json_encode($jejak));
            $this->assertSame($jejak ?? [], (array) $baris->type_b_components, 'Isi jejak yang terbaca tidak berubah.');
            $this->assertSame(0.7549999999999955, PresisiPenuh::baca($baris, 'koreksi'));
        }
    }

    /**
     * Penulis yang membaca daftar (bersih), mengubahnya, lalu menyimpan balik
     * tidak tahu kunci beku itu ada — dan tidak boleh menghapusnya.
     */
    public function test_simpan_ulang_lewat_atribut_tidak_menghapus_kunci_beku(): void
    {
        [$sesi] = $this->simpanRecorder();
        $baris = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get()[2];

        $beku = PresisiPenuh::petaBekuBaris($baris);
        $this->assertNotNull($beku, 'Prasyarat: baris titik seri membawa kunci beku.');

        $daftar = $baris->type_b_components;
        $this->assertTrue(array_is_list($daftar));
        $this->assertArrayNotHasKey(PresisiPenuh::KUNCI, $daftar, 'Pembaca Eloquent tidak melihat kunci beku.');
        $this->assertArrayNotHasKey(PresisiPenuh::KUNCI, $baris->toArray()['type_b_components']);
        $this->assertStringNotContainsString(PresisiPenuh::KUNCI, $baris->toJson());

        $baris->type_b_components = [...$daftar, ['sumber' => 'catatan_uji', 'keterangan' => 'ditambah penulis lain', 'distribusi' => 'jejak', 'nilai' => 1]];
        $baris->save();

        $segar = UncertaintyCalculation::findOrFail($baris->id);
        $this->assertSame($beku, PresisiPenuh::petaBekuBaris($segar), 'Kunci beku hilang waktu disimpan ulang lewat atribut.');
        $this->assertCount(count($daftar) + 1, $segar->type_b_components);
        $this->assertSame('catatan_uji', $segar->type_b_components[count($daftar)]['sumber']);
        $this->assertSame(0.7549999999999955, PresisiPenuh::baca($segar, 'koreksi'));

        // Menulis null itu keputusan sadar: jejaknya (dan kunci beku itu) hilang.
        $segar->type_b_components = null;
        $segar->save();
        $this->assertNull(PresisiPenuh::petaBekuBaris(UncertaintyCalculation::findOrFail($baris->id)));
    }

    // ---------------------------------------------------------------------
    // 6. Pembaca lain di pembuat snapshot: tiap cabang membaca float beku
    // ---------------------------------------------------------------------

    /**
     * `nilaiStandarDariKoreksi()` (Waktu, Frekuensi, Gaya, Tekanan, Aliran):
     * Standard Value disusun balik = rata-rata UUT + koreksi, KEDUANYA dari
     * float beku. Cabang ini dulu tidak punya tes yang menggigit: dibaca dari
     * kolom, semua tes tetap hijau (tinjau PK-1, mutan MG).
     */
    public function test_standard_value_dari_koreksi_membaca_float_beku(): void
    {
        $profil = new TimerStopwatchProfile;
        $this->assertTrue($profil->nilaiStandarDariKoreksi(), 'Prasyarat: profil ini menyusun Standard Value dari koreksi.');

        $sesi = $this->sesiKosong($profil);
        $rata = 100.12345678901235;      // 17 angka penting: sisa di luar 8 desimal
        $koreksi = -0.12345678901234568;
        $this->assertNotSame($rata, round($rata, 8));
        $this->assertNotSame($koreksi, round($koreksi, 8));

        $baris = UncertaintyCalculation::create($this->atributBaris($sesi->id, ['rata_rata' => $rata, 'koreksi' => $koreksi]))->fresh();

        $this->assertNotSame(
            $rata + $koreksi,
            (float) $baris->rata_rata + (float) $baris->koreksi,
            'Sapuan tanpa gigi: jumlah dari kolom harusnya beda dari jumlah float asli.',
        );

        $hasil = app(CertificateSnapshotBuilder::class)->potonganCetak($sesi->fresh())['hasil'][0];

        $this->assertSame($rata + $koreksi, $hasil['standard_value'], 'Standard Value harus dari float beku rata_rata + koreksi.');
        $this->assertSame($rata, $hasil['unit_under_test']);
    }

    /**
     * `uutIkutSatuanAlat() === false` (Proving Ring: UUT = jumlah DIVISI dial):
     * cabang non-konversi membaca float beku juga (mutan MH).
     */
    public function test_uut_tanpa_konversi_membaca_float_beku(): void
    {
        $profil = new ProvingRingProfile;
        $this->assertFalse($profil->uutIkutSatuanAlat(), 'Prasyarat: UUT profil ini tidak ikut konversi satuan.');

        $sesi = $this->sesiKosong($profil);
        $rata = 237.49358750000012;
        $this->assertNotSame($rata, round($rata, 8));

        $baris = UncertaintyCalculation::create($this->atributBaris($sesi->id, ['rata_rata' => $rata]))->fresh();
        $this->assertNotSame($rata, (float) $baris->rata_rata, 'Prasyarat: kolom tidak membawa float aslinya.');

        $hasil = app(CertificateSnapshotBuilder::class)->potonganCetak($sesi->fresh())['hasil'][0];

        $this->assertSame($rata, $hasil['unit_under_test'], 'UUT tanpa konversi harus dari float beku.');
    }

    /**
     * Enclosure `2405.03.AV` dengan float beku ditulis di set point pertama
     * lewat penyimpan model (kolom dibulatkan 8 desimal, seperti jalur simpan
     * sesi). Pulangannya: bagian `enclosure` snapshot + float asli yang ditulis.
     *
     * @return array{array<string, mixed>, float, float}
     */
    private function enclosureDenganFloatBeku(): array
    {
        $this->seed(DatabaseSeeder::class);
        $sesi = CalibrationSession::where('nomor_sesi', '2405.03.AV')->firstOrFail();
        $titik = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertNotEmpty($titik);

        $indikator = (float) $titik[0]->rata_rata + 1.2345678901e-9;
        $u95 = (float) $titik[0]->ketidakpastian_diperluas + 2.3456789012e-9;
        $this->assertNotSame($indikator, round($indikator, 8));
        $this->assertNotSame($u95, round($u95, 8));

        $titik[0]->type_b_components = [
            ...$titik[0]->type_b_components,
            PresisiPenuh::KUNCI => [
                'rata_rata' => AngkaFloatEksak::kodekan($indikator),
                'ketidakpastian_diperluas' => AngkaFloatEksak::kodekan($u95),
            ],
        ];
        $titik[0]->rata_rata = round($indikator, 8);
        $titik[0]->ketidakpastian_diperluas = round($u95, 8);
        $titik[0]->save();

        $sertifikat = Certificate::factory()->create(['calibration_session_id' => $sesi->id]);
        $enc = app(CertificateSnapshotBuilder::class)->bangun($sesi->fresh(), $sertifikat)['enclosure'];

        // Set point lain tidak disentuh: dibaca dari kolom/kunci masing-masing.
        $this->assertEqualsWithDelta((float) $titik[1]->rata_rata, $enc['set_point'][1]['indikator'], 1e-8);

        return [$enc, $indikator, $u95];
    }

    /** Enclosure: `indikator` (rata_rata) set point dibaca dari float beku (mutan ME). */
    public function test_enclosure_indikator_membaca_float_beku(): void
    {
        [$enc, $indikator] = $this->enclosureDenganFloatBeku();

        $this->assertSame($indikator, $enc['set_point'][0]['indikator'], 'Indikator enclosure harus dari float beku.');
    }

    /** Enclosure: `u95` set point dibaca dari float beku (mutan MF). */
    public function test_enclosure_u95_membaca_float_beku(): void
    {
        [$enc, , $u95] = $this->enclosureDenganFloatBeku();

        $this->assertSame($u95, $enc['set_point'][0]['u95'], 'U95 enclosure harus dari float beku.');
    }
}
