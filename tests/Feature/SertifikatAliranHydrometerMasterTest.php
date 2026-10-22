<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\Calibration\Profiles\FlowmeterFlowrateProfile;
use App\Services\Calibration\VarianMetodeFlowmeter;
use App\Services\DataTampilanSertifikat;
use App\Support\Angka;
use App\Support\FlowmeterMentah;
use App\Support\TataLetakSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PetaJsonTanpaUrutan;
use Tests\TestCase;

/**
 * K-50-15 lapisan H, kelompok Aliran + Hydrometer: bentuk sertifikat baru
 * mengikuti lembar `SERTIFIKAT` master aktif Studio — Flowmeter 148/149/150/185,
 * Hydrometer 188/189. Hanya bentuk cetak: angka snapshot tetap presisi penuh
 * dan sama dengan sebelum hook ini (dijaga `FlowmeterSertifikatTest`,
 * `HydrometerSesiTest`).
 */
class SertifikatAliranHydrometerMasterTest extends TestCase
{
    use PetaJsonTanpaUrutan;
    use RefreshDatabase;

    private function siap(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
    }

    private function terbitkan(string $nomorSesi): Certificate
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();

        // Peringatan sesi contoh (master belum divalidasi, jarak titik tabel)
        // memang ada dan sengaja tidak dihilangkan — lihat FlowmeterSertifikatTest.
        $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    private function teks(Certificate $sertifikat): string
    {
        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();
        $tanpaGaya = preg_replace('#<style>.*?</style>#su', '', $html);

        return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($tanpaGaya)));
    }

    /** @param  list<string>  $urutan */
    private function assertUrutan(array $urutan, string $teks): void
    {
        $posisi = -1;

        foreach ($urutan as $potongan) {
            $ketemu = mb_strpos($teks, $potongan, $posisi + 1);
            $this->assertNotFalse($ketemu, "`{$potongan}` tidak ditemukan sesudah posisi {$posisi}.");
            $posisi = $ketemu;
        }
    }

    public function test_flowmeter_uut_dulu_format_dan_label_ikut_master(): void
    {
        $this->siap();

        // nomor sesi => [format nilai, format u95 (null = U95 mutlak lama), judul U95, master, kolom]
        $kasus = [
            // `148!N20:R22`: Fluids Condition gravimetri (Temperature, Type of Fluids).
            'DEMO-FM-GRAV-TOT-001' => ['0.00', '0.0', 'U95% ±', '148',
                ['uut', 'standar', 'koreksi', 'u95', 'tambahan:suhu_fluida', 'tambahan:jenis_fluida']],
            // UFM `149!O20:O22` / `185!N24:N26`: U95 relatif `%OR` format `0.0`.
            'DEMO-FM-TOT-001' => ['0.00', null, 'U95% ±', '149', ['uut', 'standar', 'koreksi', 'u95_relatif']],
            'DEMO-FM-FLW-001' => ['0.000', null, 'U95% ±', '185', ['uut', 'standar', 'koreksi', 'u95_relatif']],
        ];

        foreach ($kasus as $nomorSesi => [$nilai, $u95, $judulU95, $master, $kolom]) {
            $sertifikat = $this->terbitkan($nomorSesi);
            $snapshot = $sertifikat->snapshot;

            $this->assertSame(
                $kolom,
                array_column($snapshot['tata_letak']['kolom_hasil'], 'kolom'),
                "{$nomorSesi}: urutan kolom master {$master} — UUT dulu.",
            );

            foreach ($snapshot['hasil'] as $baris) {
                $harapan = ['standar' => $nilai, 'uut' => $nilai, 'koreksi' => $nilai, 'k' => '0'];

                if ($u95 !== null) {
                    $harapan['u95'] = $u95;
                }

                // UFM: format U95 relatif dibaca dari `format_sertifikat` peta sel
                // (K-50-15, 22 Okt 2026) dan ikut dibekukan per baris — sama `0.0`
                // dengan spesifikasi kolom template, angka cetak tidak berubah.
                if (in_array('u95_relatif', $kolom, true)) {
                    $harapan['u95_relatif'] = '0.0';
                }

                $this->assertEquals($harapan, $baris['format'], "{$nomorSesi}: format sel master {$master}.");
            }

            $teks = $this->teks($sertifikat);
            $this->assertUrutan(['Unit Under Test', 'Standard Indication', 'Correction', $judulU95], $teks);
            $this->assertUrutan(['Standard used :', 'Name', 'Merk/Type', 'Serial Number', 'Traceable to SI through'], $teks);
            $this->assertStringNotContainsString('Merk/Type :', $teks, "{$nomorSesi}: kolom standar master {$master} tanpa titik dua.");
            $this->assertStringNotContainsString('Issuance Date :', $teks);
            $this->assertStringContainsString('Issuance Date', $teks);
            $this->assertMatchesRegularExpression('/Coverage Factor \( k \) = 2(?![0-9,.])/u', $teks, "{$nomorSesi}: k format `0`.");
        }
    }

    /**
     * UFM: kolom U95 dicetak relatif `%OR` = U95 / UUT × 100, format `0.0`
     * (`149!O22 = K62/S23/D22*100`, `185!N26 = K62/E26*100`). Gravimetri:
     * `Fluids Condition` dari data sesi — suhu air = rata-rata (rata-rata awal,
     * rata-rata akhir) titik itu (`148!N22 = AVERAGE('PERHITUNGAN FC'!D55:G55)`),
     * jenis fluida isian sesi.
     */
    public function test_flowmeter_u95_relatif_ufm_dan_kondisi_fluida_gravimetri(): void
    {
        $this->siap();

        foreach (['DEMO-FM-TOT-001', 'DEMO-FM-FLW-001'] as $nomorSesi) {
            $sertifikat = $this->terbitkan($nomorSesi);
            $snapshot = $sertifikat->snapshot;
            $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();
            $this->assertStringContainsString('%OR', $html, "{$nomorSesi}: satuan kolom U95 relatif.");
            $this->assertArrayNotHasKey('tambahan', $snapshot['hasil'][0], "{$nomorSesi}: UFM tanpa kolom fluida.");

            foreach (TataLetakSertifikat::barisDicetak($snapshot['hasil']) as $baris) {
                $harapan = number_format(abs($baris['u95'] / $baris['unit_under_test'] * 100), 1, '.', '');
                $sel = TataLetakSertifikat::teksSel($baris, 'u95_relatif', $snapshot, 2, ['format' => '0.0']);
                $this->assertSame($harapan, $sel, "{$nomorSesi} titik {$baris['titik_ke']}: U95 %OR.");
                $this->assertMatchesRegularExpression('#<td[^>]*>'.preg_quote($sel, '#').'</td>#u', $html, "{$nomorSesi}: sel %OR tercetak.");
            }
        }

        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-GRAV-TOT-001')->firstOrFail();
        $sertifikat = $this->terbitkan('DEMO-FM-GRAV-TOT-001');
        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();
        $this->assertStringContainsString('Fluids Condition Temperature', $html);
        $this->assertStringContainsString('Type of Fluids', $html);

        foreach ($sertifikat->snapshot['hasil'] as $baris) {
            $mentah = FlowmeterMentah::dari($sesi->rawMeasurements()->where('titik_ke', $baris['titik_ke'])->get());
            $awal = $mentah[FlowmeterMentah::PERAN_SUHU_AWAL];
            $akhir = $mentah[FlowmeterMentah::PERAN_SUHU_AKHIR];
            $this->assertNotEmpty($awal);
            $this->assertNotEmpty($akhir);
            $this->assertEqualsWithDelta(
                (array_sum($awal) / count($awal) + array_sum($akhir) / count($akhir)) / 2,
                $baris['tambahan']['suhu_fluida'],
                1e-9,
                "Titik {$baris['titik_ke']}: suhu fluida = AVERAGE(rata awal, rata akhir).",
            );
            $this->assertSame(
                FlowmeterMentah::blokSesi($sesi->spesifikasi_alat)['jenis_fluida'],
                $baris['tambahan']['jenis_fluida'],
            );
        }
    }

    /**
     * Standard Indication gravimetri = VOLUME, seperti `148!D22:D24`
     * (`'PERHITUNGAN FC'!D69` dst.): 140,529 / 501,724 / 1003,364 L — bukan
     * massa air 139,87 kg berlabel L. Tabelnya harus menjumlah: UUT +
     * Correction = Standard.
     */
    public function test_flowmeter_gravimetri_standard_indication_volume_master_148(): void
    {
        $this->siap();
        $snapshot = $this->terbitkan('DEMO-FM-GRAV-TOT-001')->snapshot;

        $master = [1 => 140.52944129426598, 2 => 501.7242276961913, 3 => 1003.3639826190846];
        $cetakMaster = [1 => '140.53', 2 => '501.72', 3 => '1003.36'];

        $this->assertCount(3, $snapshot['hasil']);

        foreach ($snapshot['hasil'] as $baris) {
            $titik = $baris['titik_ke'];
            $this->assertEqualsWithDelta($master[$titik], $baris['standard_value'], 1e-6, "Titik {$titik}: `148!D` volume.");
            $this->assertEqualsWithDelta(
                $baris['standard_value'],
                $baris['unit_under_test'] + $baris['correction'],
                1e-9,
                "Titik {$titik}: UUT + Correction harus = Standard.",
            );
            $this->assertSame(
                $cetakMaster[$titik],
                str_replace(',', '.', TataLetakSertifikat::teksSel($baris, 'standar', $snapshot, 2)),
            );
        }
    }

    /**
     * Flowrate gravimetri (master 150) tidak punya sesi contoh — seedernya
     * sengaja tidak menanamnya (`FlowmeterGravimetriSeeder`). Yang diuji di
     * sini pemetaan cetaknya saja: sesi Flowrate contoh dengan label varian
     * diganti di memori, tanpa menghitung, menyimpan, atau menerbitkan apa pun.
     */
    public function test_flowrate_gravimetri_memakai_format_dan_judul_master_150(): void
    {
        $this->siap();
        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-FLW-001')->firstOrFail();
        $spek = $sesi->spesifikasi_alat;
        $spek[FlowmeterMentah::KUNCI_SESI]['varian_metode'] = VarianMetodeFlowmeter::GRAVIMETRI->value;
        $sesi->spesifikasi_alat = $spek;
        $profil = new FlowmeterFlowrateProfile;

        $this->assertSame(
            ['standar' => '0.0000', 'uut' => '0.0000', 'koreksi' => '0.0000', 'u95' => '0.0000', 'k' => '0'],
            $profil->formatKolomSertifikat($sesi, 100.0, 'm3/h'),
        );
        $this->assertSame('Uncertainty 95% ±', $profil->tataLetakSertifikat($sesi)['kolom_hasil'][3]['judul']);
    }

    public function test_hydrometer_suhu_acuan_judul_u95_dan_format_ikut_master(): void
    {
        $this->siap();
        $sertifikat = $this->terbitkan('DEMO-HYD-001');
        $snapshot = $sertifikat->snapshot;

        // Sesi contoh memakai beban tambahan (sinker) = master 188.
        foreach ($snapshot['hasil'] as $baris) {
            $this->assertEquals(
                ['standar' => '0.000', 'uut' => '0.000', 'koreksi' => '0.000', 'u95' => '0.00000', 'k' => '0'],
                $baris['format'],
            );
        }

        $this->assertSamaPetaJson([[
            'posisi' => TataLetakSertifikat::POSISI_ATAS_HASIL,
            'judul' => null,
            'baris' => [],
            'tabel' => [['Suhu acuan Hydrometer :', Angka::keTitik(TataLetakSertifikat::formatAngka(20.0, '0.0')).' °C']],
        ]], $snapshot['catatan_blok']);

        $teks = $this->teks($sertifikat);
        $this->assertStringContainsString('Environment Condition', $teks);
        $this->assertUrutan(['Calibration Report :', 'Suhu acuan Hydrometer :', 'Nominal Value', 'Actual Value',
            'Correction', 'U95%, K=2', 'Standard Used :'], $teks);
        // `0.610` di master (`E17` `0.000`), bukan `0,61`: nol di belakang tidak dibuang.
        $this->assertStringContainsString(
            Angka::keTitik(TataLetakSertifikat::formatAngka((float) $snapshot['hasil'][0]['standard_value'], '0.000')),
            $teks,
        );
        $this->assertStringContainsString('coverage factor ( k ) = 2', $teks);
        $this->assertStringNotContainsString('convidence', mb_strtolower($teks));
    }
}
