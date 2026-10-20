<?php

namespace Tests\Unit;

use App\Models\CalibrationSession;
use App\Services\Calibration\Profiles\AutoclaveProfile;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\CentrifugeProfile;
use App\Services\Calibration\Profiles\Enclosure\OvenProfile;
use App\Services\Calibration\Profiles\TachometerProfile;
use App\Services\Calibration\Profiles\ThermocoupleProfile;
use App\Services\Calibration\Profiles\ThermohygroProfile;
use App\Services\Calibration\Profiles\ThermometerGlassProfile;
use App\Services\Calibration\Profiles\TidsProfile;
use App\Services\Calibration\Profiles\TimerStopwatchProfile;
use App\Services\Calibration\Profiles\TitsProfile;
use App\Support\TataLetakSertifikat;
use Tests\TestCase;

/**
 * Hook bentuk sertifikat per profil (lapisan H, K-50-15: sertifikat persis
 * lembar `SERTIFIKAT` master) untuk keluarga Suhu dan Waktu. Sumber tiap
 * nilai = sel master yang disebut di docblock profilnya (Studio
 * `workbook_master` aktif, dibaca 20 Okt 2026).
 *
 * Yang diadu di sini JAWABAN hook — persis yang dibekukan builder ke
 * snapshot — lewat `TataLetakSertifikat::normalisasi*`, jadi salah ketik
 * kunci ikut merah. Render PDF-nya dijaga `SertifikatSemuaAlatSatuHalamanTest`.
 */
class SertifikatHookSuhuWaktuTest extends TestCase
{
    /** @param  array<string, mixed>  $atribut */
    private function sesi(array $atribut = []): CalibrationSession
    {
        return (new CalibrationSession)->forceFill($atribut);
    }

    /** @return array<string, mixed> */
    private function tataLetak(CalibrationProfile $profil, ?CalibrationSession $sesi = null): array
    {
        return TataLetakSertifikat::normalisasi($profil->tataLetakSertifikat($sesi ?? $this->sesi()));
    }

    /** @param  array<string, mixed>  $tl */
    private function labelStandar(array $tl): array
    {
        return array_column($tl['kolom_standar_digunakan'], 'label');
    }

    public function test_tits_tids_thermocouple_glass_judul_oc_tanpa_baris_satuan_dan_label_u95_master(): void
    {
        foreach ([TitsProfile::class, TidsProfile::class, ThermocoupleProfile::class, ThermometerGlassProfile::class] as $kelas) {
            $tl = $this->tataLetak(app($kelas));

            // 173!E19:L19, 193!E19, 175!E19, 176!E20.
            $this->assertSame(
                ['Standard Reading (oC)', 'Unit Under Test (oC)', 'Correction (oC)'],
                array_column($tl['kolom_hasil'], 'judul'),
                $kelas,
            );
            $this->assertSame(['standar', 'uut', 'koreksi'], array_column($tl['kolom_hasil'], 'kolom'), $kelas);
            $this->assertFalse($tl['satuan_baris_sendiri'], $kelas);
            // 173!E29 — dua spasi ikut master.
            $this->assertSame('Uncertainty  95%  ±', $tl['label_u95'], $kelas);
            $this->assertSame(['Name', 'Merk/Type', 'Serial Number', 'Traceable to SI through'], $this->labelStandar($tl), $kelas);
            $this->assertSame('Issuance Date', $tl['label_tanggal_terbit'], $kelas);
            // Bentuk bersama tetap terbawa (`parent::`), bukan ditulis ulang.
            $this->assertSame(TataLetakSertifikat::MAYORITAS_MASTER['kepala'], $tl['kepala'], $kelas);
        }
    }

    public function test_end_of_certificate_hanya_tits_dan_autoclave(): void
    {
        $this->assertSame('--------- End of Certificate  ---------', $this->tataLetak(app(TitsProfile::class))['akhir_sertifikat']);
        $this->assertSame('-------------- End of Certificate  --------------', $this->tataLetak(app(AutoclaveProfile::class))['akhir_sertifikat']);

        foreach ([TidsProfile::class, ThermocoupleProfile::class, ThermometerGlassProfile::class, ThermohygroProfile::class, OvenProfile::class] as $kelas) {
            $this->assertArrayNotHasKey('akhir_sertifikat', $this->tataLetak(app($kelas)), $kelas);
        }
    }

    public function test_format_tits_u95_per_varian_master(): void
    {
        $tits = app(TitsProfile::class);

        $measure = TataLetakSertifikat::normalisasiFormat($tits->formatKolomSertifikat($this->sesi(['mode_kalibrasi' => 'measure']), 100.0, '°C'));
        $source = TataLetakSertifikat::normalisasiFormat($tits->formatKolomSertifikat($this->sesi(['mode_kalibrasi' => 'source']), 100.0, '°C'));

        // 173!E20:L28 & 174!E16:L23 `0.0`; U 173!L29 `0.00`, 174!L24 `0.0`; k O30/O25 `0`.
        $this->assertSame(['standar' => '0.0', 'uut' => '0.0', 'koreksi' => '0.0', 'u95' => '0.00', 'k' => '0'], $measure);
        $this->assertSame('0.0', $source['u95']);
        $this->assertSame('5,0', TataLetakSertifikat::formatAngka(5.0, $source['standar']));
        // 173!P14 `0` (dulu 1 desimal).
        $this->assertSame(0, $tits->desimalKelembabanEnv());
    }

    public function test_glass_mencetak_tipe_pencelupan_di_atas_tabel(): void
    {
        $glass = app(ThermometerGlassProfile::class);

        $this->assertSame([[
            'posisi' => TataLetakSertifikat::POSISI_ATAS_HASIL,
            'judul' => null,
            'baris' => [],
            'tabel' => [['Tipe Thermometer Glass :', 'Total Immersion']],
        ]], TataLetakSertifikat::normalisasiBlok($glass->catatanSertifikat($this->sesi(['tipe_pencelupan' => 'Total Immersion']))));

        // Belum dipilih: tidak ada baris kosong (validator yang menandainya).
        $this->assertSame([], $glass->catatanSertifikat($this->sesi()));
    }

    public function test_thermohygro_kolom_u95_gabung_dan_label_lingkungan(): void
    {
        $tl = $this->tataLetak(app(ThermohygroProfile::class));

        // 154!E20:U20, U22 digabung; baris satuan tetap (E21), sel U95 kosong.
        $this->assertSame(
            ['Standard Reading', 'Unit Under Test', 'Correction', 'Uncertainty  95%  ±'],
            array_column($tl['kolom_hasil'], 'judul'),
        );
        $this->assertSame([false, false, false, true], array_column($tl['kolom_hasil'], 'gabung'));
        $this->assertSame('', $tl['kolom_hasil'][3]['satuan']);
        $this->assertTrue($tl['satuan_baris_sendiri']);
        $this->assertSame(['Name', 'Merk/Type', 'Serial Number', 'Traceable to SI through'], $this->labelStandar($tl));

        $labelLingkungan = collect($tl['kepala'])->flatten(1)->filter()->firstWhere('kunci', 'env_condition')['label'];
        $this->assertSame('Environmental Condition', $labelLingkungan);

        // UUT sengaja tidak diformat (suhu `0.0`, %RH `0`, parameter baris tidak diketahui).
        $this->assertSame(
            ['standar' => '0.00', 'koreksi' => '0.00', 'u95' => '0.0', 'k' => '0'],
            app(ThermohygroProfile::class)->formatKolomSertifikat($this->sesi(), 25.0, '°C'),
        );
    }

    public function test_autoclave_dan_enclosure_label_standard_used_dan_tanggal_terbit(): void
    {
        $autoclave = $this->tataLetak(app(AutoclaveProfile::class));
        $this->assertSame('Standard Used :', $autoclave['judul_standar_digunakan']);
        $this->assertSame('Issuance Date:', $autoclave['label_tanggal_terbit']);
        $this->assertSame(['Name', 'Merk/Type', 'Serial Number', 'Traceable to SI through'], $this->labelStandar($autoclave));

        $oven = $this->tataLetak(app(OvenProfile::class));
        $this->assertSame('Standard used :', $oven['judul_standar_digunakan']);
        $this->assertSame('Issuance Date', $oven['label_tanggal_terbit']);
        $this->assertSame(['Name', 'Merk/Type', 'Serial Number', 'Traceable to SI through'], $this->labelStandar($oven));
    }

    public function test_centrifuge_dan_tachometer_u95_gabung_per_rentang_dan_penafian(): void
    {
        foreach ([CentrifugeProfile::class, TachometerProfile::class] as $kelas) {
            $profil = app($kelas);
            $tl = $this->tataLetak($profil);

            // 180!C17:S18 / 182!C17:S18 — `Unit Under Test`, bukan `Setting`.
            $this->assertSame(['Standard Value', 'Unit Under Test', 'Correction', 'U95% (±)'], array_column($tl['kolom_hasil'], 'judul'), $kelas);
            $this->assertSame(['Rpm', 'Rpm', 'Rpm', 'Rpm'], array_column($tl['kolom_hasil'], 'satuan'), $kelas);
            $this->assertSame([false, false, false, true], array_column($tl['kolom_hasil'], 'gabung'), $kelas);

            // 180!A56 / 182!A59, sesudah tanda tangan.
            $blok = TataLetakSertifikat::normalisasiBlok($profil->catatanSertifikat($this->sesi()));
            $this->assertCount(1, $blok, $kelas);
            $this->assertSame(TataLetakSertifikat::POSISI_SESUDAH_TTD, $blok[0]['posisi']);
            $this->assertStringStartsWith('It is prohibited to quote, reproduce, and/or publish', $blok[0]['baris'][0]);
            $this->assertStringStartsWith('This certificate is valid when it is registered and sealed', $blok[0]['baris'][1]);

            $this->assertSame(
                ['standar' => '0.0', 'uut' => '0.0', 'koreksi' => '0.0', 'u95' => '0.0', 'k' => '0'],
                TataLetakSertifikat::normalisasiFormat($profil->formatKolomSertifikat($this->sesi(), 5000.0, 'rpm')),
                $kelas,
            );
        }

        // 182!C31:J36 `0` mulai 10000 rpm; Centrifuge 180!C31:J33 tetap `0.0`.
        $this->assertSame('0', app(TachometerProfile::class)->formatKolomSertifikat($this->sesi(), 10000.0, 'rpm')['standar']);
        $this->assertSame('0.0', app(TachometerProfile::class)->formatKolomSertifikat($this->sesi(), 7000.0, 'rpm')['uut']);
        $this->assertSame('0.0', app(CentrifugeProfile::class)->formatKolomSertifikat($this->sesi(), 15000.0, 'rpm')['standar']);
    }

    public function test_timer_u95_satu_kotak_dan_k_general(): void
    {
        $timer = app(TimerStopwatchProfile::class);
        $tl = $this->tataLetak($timer);

        // 183!E17:X18.
        $this->assertSame(['Standard Value', 'Unit Under Test', 'Correction', 'Uncertainty 95% ±'], array_column($tl['kolom_hasil'], 'judul'));
        $this->assertSame(['S', 'S', 'S', 'S'], array_column($tl['kolom_hasil'], 'satuan'));
        $this->assertTrue($tl['kolom_hasil'][3]['gabung']);
        $this->assertSame([], $timer->catatanSertifikat($this->sesi()));

        // X19 `0.0`, V29 General.
        $format = TataLetakSertifikat::normalisasiFormat($timer->formatKolomSertifikat($this->sesi(), 60.0, null));
        $this->assertSame(['u95' => '0.0', 'k' => 'General'], $format);
        $this->assertSame('0,8', TataLetakSertifikat::formatAngka(0.81, $format['u95']));
    }

    public function test_tachometer_urutan_dan_tanda_persis_master_182(): void
    {
        $tacho = app(TachometerProfile::class);
        $tl = $this->tataLetak($tacho);

        // 182!C17:P17: Standard Value = set point (kunci `unit_under_test`),
        // Unit Under Test = standar terkoreksi (kunci `standard_value`).
        $this->assertSame(['uut', 'standar', 'koreksi', 'u95'], array_column($tl['kolom_hasil'], 'kolom'));
        $this->assertSame(['Standard Value', 'Unit Under Test', 'Correction', 'U95% (±)'], array_column($tl['kolom_hasil'], 'judul'));
        $this->assertSame(-1, $tacho->tandaKoreksiSertifikat());
        $this->assertSame(1, app(CentrifugeProfile::class)->tandaKoreksiSertifikat());

        // Angka master 182: baris 60 rpm C19/J19/P19 = 60.0 / 59.8 / 0.2
        // (PERHITUNGAN!G22 = 60, G32 = 59,78, koreksi tersimpan G32 − G22 = −0,22);
        // baris 15000 rpm C34/J34/P34 = 15001 / 15000 / 1.1.
        $cetak = function (float $setPoint, float $terkoreksi) use ($tacho, $tl): array {
            $baris = [
                'standard_value' => $terkoreksi,
                'unit_under_test' => $setPoint,
                'correction' => $tacho->tandaKoreksiSertifikat() * ($terkoreksi - $setPoint),
                'format' => $tacho->formatKolomSertifikat($this->sesi(), $setPoint, 'rpm'),
            ];

            return array_map(
                static fn (array $k): string => TataLetakSertifikat::teksSel($baris, $k['kolom'], [], 1),
                array_slice($tl['kolom_hasil'], 0, 3),
            );
        };

        $this->assertSame(['60,0', '59,8', '0,2'], $cetak(60.0, 59.78));
        $this->assertSame(['15001', '15000', '1,1'], $cetak(15001.0, 14999.88));
    }
}
