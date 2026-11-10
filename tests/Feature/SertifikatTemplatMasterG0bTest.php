<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\CertificateSnapshotBuilder;
use App\Support\FormatMasterSertifikat;
use App\Support\TataLetakSertifikat;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * G0-b (sertifikat persis master, keputusan pemilik 10 Nov 2026): kop
 * pracetak (P1), tanggal per lembar (P2), Capacity/Graduation diketik (P3),
 * minus nol (P4). Semuanya dikendalikan KUNCI baru di `snapshot.tata_letak`
 * (`kop_pracetak`, `tanggal_master`, `kapasitas_master`) dan `tanda_nol` per
 * baris; cetak ulang snapshot lama dijaga `SertifikatBekuTidakBerubahTest`.
 *
 * Sumber: `margin.atas`, rumus `Issuance Date` (`=T9+4`), dan format sel
 * `[$-421]`/`[$-809]` di `master/<workbook>/sertifikat*.json` + `_ringkas.md`.
 */
class SertifikatTemplatMasterG0bTest extends TestCase
{
    /** @return array<string, CalibrationProfile> */
    private function semuaProfil(): array
    {
        $hasil = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $hasil[$profil->kode()] ??= $profil;
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    private function tataLetak(string $kode): array
    {
        $timpaan = $this->semuaProfil()[$kode]->tataLetakSertifikat(new CalibrationSession);
        $tambahan = array_values(array_diff(
            array_filter(array_column(array_merge(...($timpaan['kepala'] ?? [])), 'kunci')),
            TataLetakSertifikat::KUNCI_KEPALA,
        ));

        return TataLetakSertifikat::normalisasi($timpaan, $tambahan);
    }

    /** @return array<string, mixed> */
    private function snapshotBaru(string $kode): array
    {
        $snapshot = json_decode((string) file_get_contents(base_path("tests/Fixtures/sertifikat-beku/{$kode}.snapshot.json")), true, 512, JSON_THROW_ON_ERROR);
        $snapshot['tata_letak'] = $this->tataLetak($kode);
        unset($snapshot['tata_letak']['catatan']);

        return $snapshot;
    }

    private function render(array $snapshot, string $locale = 'en', ?string $qr = null): string
    {
        app()->setLocale($locale);

        $html = view('sertifikat.pdf', [
            'sertifikat' => new Certificate, 'snapshot' => $snapshot, 'logo' => null, 'kop' => null,
            'tandaTangan' => null, 'posisiTtd' => [], 'ukuranTtd' => [], 'qr' => $qr, 'keputusan' => null,
            'web' => false, 'paksaPadat' => false,
        ])->render();

        return substr($html, (int) strpos($html, '<body'));
    }

    // ---- P1: kop pracetak ---------------------------------------------------

    public function test_tabel_master_memuat_margin_dan_tanggal_per_profil_dan_tanpa_master_tidak_menebak(): void
    {
        foreach ($this->semuaProfil() as $kode => $profil) {
            $tl = $this->tataLetak($kode);

            if (FormatMasterSertifikat::PER_PROFIL[$kode] ?? null) {
                $this->assertIsFloat($tl['kop_pracetak'], "{$kode}: ruang pracetak dari margin atas master.");
                $this->assertArrayHasKey('tanggal_master', $tl, $kode);
            } else {
                // P7: master tidak ada di folder ekstraksi — tidak ada tebakan.
                $this->assertArrayNotHasKey('kop_pracetak', $tl, "{$kode}: tanpa master tidak menyatakan kop pracetak.");
                $this->assertArrayNotHasKey('tanggal_master', $tl, $kode);
            }
        }

        // Empat profil tanpa master (P7).
        foreach (['bath', 'buret', 'buret_digital', 'picnometer'] as $kode) {
            $this->assertArrayNotHasKey($kode, FormatMasterSertifikat::PER_PROFIL);
        }
    }

    public function test_ruang_pracetak_setinggi_margin_atas_master_dikurangi_margin_halaman(): void
    {
        // labu ukur: margin atas 2,10 inci (`sertifikat.json` halaman.margin.atas = 2.0991…).
        $this->assertEqualsWithDelta(2.1 * 2.54 - 0.85, $this->tataLetak('labu_ukur')['kop_pracetak'], 0.01);
        // chlorine: 0,94 inci -> ruangnya jauh lebih kecil, jangan negatif.
        $this->assertEqualsWithDelta(0.94 * 2.54 - 0.85, $this->tataLetak('chlorine_meter')['kop_pracetak'], 0.01);
        $this->assertGreaterThanOrEqual(0.0, FormatMasterSertifikat::ruangPracetakCm('utm'));
        // Margin master yang lebih lebar dari kop+judul yang dihapus dipotong
        // (kalau tidak, huruf sertifikat mengecil — Viscometer 5,9 cm).
        $this->assertSame(FormatMasterSertifikat::RUANG_PRACETAK_MAKS_CM, $this->tataLetak('viscometer')['kop_pracetak']);
        $this->assertSame(FormatMasterSertifikat::RUANG_PRACETAK_MAKS_CM, $this->tataLetak('centrifuge')['kop_pracetak']);
    }

    public function test_cetak_baru_tanpa_kop_judul_kode_dokumen_tapi_dengan_ruang_kosong_qr_dan_ttd_tetap(): void
    {
        foreach (['labu_ukur', 'micrometer', 'timbangan', 'thermocouple'] as $kode) {
            $baru = $this->render($this->snapshotBaru($kode), 'en', 'data:image/png;base64,AAAA');

            $this->assertStringNotContainsString('class="kop"', $baru, $kode);
            $this->assertStringNotContainsString('kop-gambar', $baru, $kode);
            $this->assertStringNotContainsString('CALIBRATION CERTIFICATE', $baru, $kode);
            $this->assertStringContainsString('style="display: none;" class="kode-dokumen"', $baru, $kode);
            $this->assertMatchesRegularExpression('/<div style="height: \d+(\.\d+)?cm; font-size: 0; line-height: 0;"><\/div>/', $baru, $kode);
            // QR/verifikasi TIDAK dihapus (master tanpa QR, tapi QR default dipasang sistem).
            $this->assertStringContainsString('class="qr"', $baru, "{$kode}: QR verifikasi tetap.");
        }
    }

    public function test_snapshot_lama_tanpa_kunci_baru_masih_mencetak_kop_judul_dan_kode_dokumen(): void
    {
        $snapshot = json_decode((string) file_get_contents(base_path('tests/Fixtures/sertifikat-beku/labu_ukur.snapshot.json')), true, 512, JSON_THROW_ON_ERROR);
        $lama = $this->render($snapshot);

        $this->assertStringContainsString('class="kop"', $lama);
        $this->assertStringContainsString('CALIBRATION CERTIFICATE', $lama);
        $this->assertStringContainsString('<div class="kode-dokumen">', $lama);
        $this->assertStringNotContainsString('font-size: 0; line-height: 0;', $lama);
    }

    // ---- P2: tanggal --------------------------------------------------------

    public function test_bahasa_bulan_per_lembar_bukan_locale_aplikasi_dan_format_hari(): void
    {
        // TITS: master `[$-809]d mmmm yyyy` -> Inggris tanpa nol, walau aplikasi `id`.
        $tits = $this->snapshotBaru('labu_ukur');
        $tits['tata_letak']['tanggal_master'] = ['kepala' => 'en', 'terbit' => 'en', 'tambah_hari' => 4];
        $tits['header']['calibration_date'] = '2026-05-08';
        $tits['footer']['issuance_date'] = '2026-05-12';
        $en = $this->render($tits, 'id');
        $this->assertStringContainsString('8 May 2026', $en);
        $this->assertStringContainsString('12 May 2026', $en);
        $this->assertStringNotContainsString('Mei', $en);

        // Labu ukur: `[$-421]dd mmmm yyyy` -> Indonesia, walau aplikasi `en`.
        $labu = $this->snapshotBaru('labu_ukur');
        $labu['header']['calibration_date'] = '2026-05-08';
        $labu['footer']['issuance_date'] = '2026-05-12';
        $id = $this->render($labu, 'en');
        $this->assertStringContainsString('08 Mei 2026', $id);
        $this->assertStringContainsString('12 Mei 2026', $id);
        $this->assertStringNotContainsString('May 2026', $id);
    }

    public function test_tanggal_per_lembar_load_cell_kepala_inggris_terbit_indonesia(): void
    {
        $this->assertSame(['kepala' => 'en', 'terbit' => 'id', 'tambah_hari' => 4], $this->tataLetak('load_cell')['tanggal_master']);
        $this->assertSame(['kepala' => 'en', 'terbit' => 'en', 'tambah_hari' => 4], $this->tataLetak('tits')['tanggal_master']);
        $this->assertSame(['kepala' => 'id', 'terbit' => 'id', 'tambah_hari' => 4], $this->tataLetak('gelas_ukur')['tanggal_master']);
        // `Issuance Date` yang tidak berumus +N di master (diketik / workbook >1 beda): tanggal terbit sistem.
        $this->assertNull($this->tataLetak('gas_detector')['tanggal_master']['tambah_hari']);
        $this->assertNull($this->tataLetak('thermocouple')['tanggal_master']['tambah_hari']);
    }

    public function test_tanggal_terbit_dibekukan_tanggal_kalibrasi_plus_n_hanya_bila_dinyatakan(): void
    {
        $footer = new ReflectionMethod(CertificateSnapshotBuilder::class, 'footer');
        $sesi = new CalibrationSession;
        $sesi->tanggal_kalibrasi = Carbon::parse('2026-09-22');
        $sertifikat = new Certificate;
        $sertifikat->diterbitkan_pada = Carbon::parse('2026-11-10');
        $builder = app(CertificateSnapshotBuilder::class);

        $this->assertSame('2026-09-26', $footer->invoke($builder, $sesi, $sertifikat, [], 4)['issuance_date']);
        $this->assertSame('2026-09-22', $footer->invoke($builder, $sesi, $sertifikat, [], 0)['issuance_date']);
        // Tanpa N (bentuk lama / master tak berumus): tanggal terbit sistem.
        $this->assertSame('2026-11-10', $footer->invoke($builder, $sesi, $sertifikat, [])['issuance_date']);
    }

    // ---- P3: Capacity/Graduation -------------------------------------------

    private function kapasitas(string $kode, array $alat, array $spesifikasi = []): ?string
    {
        $tl = $this->tataLetak($kode);
        $this->assertArrayHasKey('kapasitas_master', $tl, $kode);

        $sesi = new CalibrationSession;
        $sesi->spesifikasi_alat = $spesifikasi;
        $sesi->setRelation('equipment', (new Equipment)->forceFill($alat));

        $metode = new ReflectionMethod(CertificateSnapshotBuilder::class, 'kapasitasGraduasiMaster');

        return $metode->invoke(app(CertificateSnapshotBuilder::class), $sesi, $this->semuaProfil()[$kode], $tl['kapasitas_master']['graduasi']);
    }

    public function test_capacity_graduation_seperti_sel_master(): void
    {
        // Labu ukur / pipet volume: `F11` kapasitas diketik, `I11` = `-`.
        $this->assertSame('250 ml / - ml', $this->kapasitas('labu_ukur', ['satuan' => 'ml', 'range_min' => 0, 'range_max' => 250, 'resolusi' => null]));
        $this->assertSame('1 ml / - ml', $this->kapasitas('pipet_volume', ['satuan' => 'ml', 'range_min' => 0, 'range_max' => 1, 'resolusi' => 0.01]));
        // Blok sesi `kapasitas_ml` (yang diketik) menang atas rentang alat.
        $this->assertSame('500 ml / - ml', $this->kapasitas('labu_ukur', ['satuan' => 'ml', 'range_max' => 250], ['volumetric' => ['kapasitas_ml' => 500]]));
        // Gelas ukur: kapasitas / resolusi.
        $this->assertSame('100 ml / 1 ml', $this->kapasitas('gelas_ukur', ['satuan' => 'ml', 'range_min' => 0, 'range_max' => 100, 'resolusi' => 1]));
        // TITS Measure: kapasitas DIKETIK (2000), bukan rentang ukur (1000); Source: `-`.
        $this->assertSame('2000 °C / 0,1 °C', $this->kapasitas('tits', ['satuan' => '°C', 'range_min' => 0, 'range_max' => 1000, 'resolusi' => 0.1], ['kapasitas' => '2000']));
        $this->assertSame('- °C / 0,1 °C', $this->kapasitas('tits', ['satuan' => '°C', 'range_max' => 1200, 'resolusi' => 0.1]));
    }

    public function test_profil_lain_belum_menyatakan_kapasitas_master(): void
    {
        foreach (['buret', 'pipet_ukur', 'micrometer', 'timbangan'] as $kode) {
            $this->assertArrayNotHasKey('kapasitas_master', $this->tataLetak($kode), $kode);
        }
    }

    // ---- P4: minus nol ------------------------------------------------------

    public function test_minus_nol_dicetak_nol_seperti_pdf_master(): void
    {
        foreach (['centrifuge', 'dial_indicator', 'tits', 'turbidimeter', 'conductivity_meter', 'spectrophotometer'] as $kode) {
            $this->assertFalse($this->semuaProfil()[$kode]->tandaNolDicetak(), $kode);
        }
        // Yang masternya mencetak minus tidak ikut berubah.
        foreach (['micrometer', 'ph_meter', 'timbangan'] as $kode) {
            $this->assertTrue($this->semuaProfil()[$kode]->tandaNolDicetak(), $kode);
        }
    }

    // ---- Validasi kunci -----------------------------------------------------

    public function test_kunci_baru_diperiksa_waktu_terbit(): void
    {
        $this->assertSame(['kop_pracetak' => 4.48], TataLetakSertifikat::normalisasi(['kop_pracetak' => 4.48]));
        $this->assertNull(TataLetakSertifikat::normalisasi(['tanggal_master' => null])['tanggal_master']);

        foreach ([
            ['kop_pracetak' => -1],
            ['kop_pracetak' => 'tinggi'],
            ['tanggal_master' => ['kepala' => 'fr', 'terbit' => 'id']],
            ['tanggal_master' => ['kepala' => 'id']],
            ['tanggal_master' => ['kepala' => 'id', 'terbit' => 'id', 'tambah_hari' => '4']],
            ['kapasitas_master' => ['graduasi' => 'angka']],
        ] as $salah) {
            try {
                TataLetakSertifikat::normalisasi($salah);
                $this->fail('Seharusnya melempar: '.json_encode($salah));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_bawaan_lama_netral_untuk_kunci_baru(): void
    {
        foreach (['kop_pracetak', 'tanggal_master', 'kapasitas_master'] as $kunci) {
            $this->assertNull(TataLetakSertifikat::BAWAAN[$kunci], $kunci);
        }
    }
}
