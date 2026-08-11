<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CalibrationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sesi contoh `DEMO-COND-MSCM` — titik tengah dibaca dalam mS/cm.
 *
 * Kenapa fixture ini ada: jalur varian mS/cm punya DUA perilaku yang selama ini
 * cuma kebukti lewat unit test dan nggak pernah bisa dibuka orang di layar —
 * peringatan `conductivity_titik_tengah_mili` dan sertifikat style 2. Tanpa
 * satu sesi yang beneran ada di database, dua-duanya nggak bisa di-QA visual,
 * dan bisa mati diam-diam tanpa ada yang sadar.
 *
 * Yang diuji di sini BUKAN kebenaran angkanya lawan master — jalur mS/cm justru
 * yang di master nggak pernah keisi, dan itu isi peringatannya. Yang diuji:
 * sesinya kebentuk, jalurnya kepancing, dan sesi job ASLI nggak ketularan.
 */
class ConductivitySesiVarianMiliTest extends TestCase
{
    use RefreshDatabase;

    /** Job lab beneran — angkanya diadu ke master di `ConductivityBudgetTest`. */
    private const SESI_ASLI = '2405.32.A.NK';

    /** Fixture. Sengaja bukan format nomor job, biar nggak dikira rekaman nyata. */
    private const SESI_FIXTURE = 'DEMO-COND-MSCM';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function sesi(string $nomor): CalibrationSession
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomor)->first();

        $this->assertNotNull($sesi, "Sesi {$nomor} nggak keseed.");

        return $sesi;
    }

    /** @return list<string> */
    private function kodeTemuan(CalibrationSession $sesi, string $tingkat): array
    {
        $hasil = app(CalibrationValidator::class)->periksa($sesi);

        return array_values(array_map(
            fn (array $t): string => $t['kode'],
            array_filter(
                $hasil['temuan'],
                fn (array $t): bool => $t['tingkat'] === $tingkat,
            ),
        ));
    }

    public function test_titik_tengahnya_kecatat_mili_bukan_mikro(): void
    {
        $sesi = $this->sesi(self::SESI_FIXTURE);

        $titik = $sesi->uncertaintyCalculations
            ->sortBy('titik_ke')
            ->map(fn ($t): float => (float) $t->titik_ukur)
            ->values()
            ->all();

        $this->assertCount(3, $titik);

        // Titik tengah ~1,412 (mS/cm), BUKAN ~1412 (µS/cm). Pakai delta karena
        // nilai acuannya digeser koreksi suhu sebelum disimpan.
        $this->assertEqualsWithDelta(1.412, $titik[1], 0.05);

        // Dua titik lain tetap sama kayak sesi asli — yang beda cuma satuan
        // titik tengah, bukan alatnya berubah.
        $this->assertEqualsWithDelta(25.0, $titik[0], 1.0);
        $this->assertEqualsWithDelta(111.0, $titik[2], 1.0);
    }

    /**
     * Botolnya SATU. Sesi µS/cm dan sesi mS/cm ngukur benda fisik yang sama,
     * jadi jawabannya harus sama juga — cuma beda skala 1000.
     *
     * Ini penjaga yang paling tajam di berkas ini: dia nangkep kalau salah satu
     * dari dua sisi konversi (nilai acuan ATAU ketidakpastian botol) kelewat
     * atau kebagi dua kali. Sebelum perbaikan hari ini, koreksi titik tengah
     * sesi mS/cm keluar +1410,587 — tes ini bakal teriak.
     */
    public function test_titik_tengah_mili_setara_sesi_mikro_dibagi_1000(): void
    {
        $mikro = $this->sesi(self::SESI_ASLI)->uncertaintyCalculations
            ->firstWhere('titik_ke', 2);
        $mili = $this->sesi(self::SESI_FIXTURE)->uncertaintyCalculations
            ->firstWhere('titik_ke', 2);

        foreach (['titik_ukur', 'rata_rata', 'koreksi'] as $kolom) {
            $harapan = (float) $mikro->{$kolom} / 1000.0;

            $this->assertEqualsWithDelta(
                $harapan,
                (float) $mili->{$kolom},
                max(abs($harapan) * 1e-9, 1e-12),
                "Kolom {$kolom} sesi mS/cm nggak setara sesi µS/cm dibagi 1000.",
            );
        }
    }

    /**
     * CACAT TERBUKA — ketidakpastian titik tengah varian mS/cm masih salah.
     *
     * Ditemukan 11 Agt 2026 waktu bikin sesi contoh ini. Angkanya:
     *
     *   sesi µS/cm  titik 2: uc=4,1149  v_eff=223,35  U95=8,1090
     *   sesi mS/cm  titik 2: uc=4,0000  v_eff=NULL    U95=8,0000
     *
     * `v_eff` NULL itu tandanya: titik ini nggak lewat jalur budget sama sekali,
     * dia jatuh ke `GumCalculator::hitungDariStandarDanResolusi()`. Sebabnya CMC
     * lab kedaftar di titik 25 & 1412 (µS/cm) dan 111 (mS/cm) — nggak ada baris
     * di 1,412, jadi `kemampuanUntukTitik()` nggak nemu apa-apa dan lantai CMC
     * plus Welch–Satterthwaite nggak kepasang.
     *
     * Akibatnya U95 kecetak 8,0000 mS/cm, sekitar 1000× kegedean, DAN bukan
     * turunan dari kemampuan terakreditasi lab. Nilai bacaannya sendiri
     * (`titik_ukur`/`rata_rata`/`koreksi`) udah bener — lihat tes di atas.
     *
     * Kenapa belum dibetulin di sini: perbaikannya nyentuh mesin budget yang
     * dipakai SEMUA alat, dan angkanya masuk sertifikat terakreditasi. Itu
     * keputusan yang bukan punya saya. Dua jalan yang kelihatan:
     *
     *  1. Hitung titik ini di satuan kanonik (µS/cm) lalu skalakan hasilnya —
     *     bikin jalur mS/cm terbukti identik sama jalur yang udah diadu master.
     *  2. Tambah baris CMC 1,412 mS/cm di lampiran kemampuan — tapi itu klaim
     *     akreditasi yang lab-nya sendiri belum pernah nulis.
     *
     * Sampai itu diputuskan, sesi varian mS/cm JANGAN diterbitkan sertifikatnya.
     * Peringatan `conductivity_titik_tengah_mili` udah nahan mata admin di sana.
     */
    public function test_ketidakpastian_titik_tengah_mili_belum_benar(): void
    {
        $mili = $this->sesi(self::SESI_FIXTURE)->uncertaintyCalculations
            ->firstWhere('titik_ke', 2);

        // Dijaga supaya cacatnya nggak diam-diam berubah bentuk tanpa ketahuan.
        $this->assertNull(
            $mili->derajat_kebebasan_efektif,
            'v_eff udah keisi — jalur budget kepasang, tes ini pantas dinaikin jadi assertion beneran.',
        );

        $this->markTestIncomplete(
            'U95 varian mS/cm = '.$mili->ketidakpastian_diperluas
            .', harusnya ~0,0081090119478521 (U95 sesi µS/cm ÷ 1000). '
            .'Butuh keputusan soal jalur CMC — lihat docblock.',
        );
    }

    public function test_peringatan_varian_mili_nyampe_ke_dialog_approve(): void
    {
        $kode = $this->kodeTemuan($this->sesi(self::SESI_FIXTURE), 'peringatan');

        $this->assertContains('conductivity_titik_tengah_mili', $kode);
    }

    /**
     * Inti dari kenapa nomor 2 dikerjain duluan: peringatan varian ini harus
     * BERDIRI SENDIRI di dialog. Kalau `pembacaan_di_luar_rentang` balik lagi
     * (110,67 di titik 111 lawan rentang alat 0–100), peringatan yang penting
     * tenggelam lagi di antara empat baris yang udah dianggap bising.
     */
    public function test_peringatan_varian_mili_nggak_ketimbun_peringatan_lain(): void
    {
        $kode = $this->kodeTemuan($this->sesi(self::SESI_FIXTURE), 'peringatan');

        $this->assertNotContains('pembacaan_di_luar_rentang', $kode);
        $this->assertSame(['conductivity_titik_tengah_mili'], $kode);
    }

    public function test_sertifikatnya_keluar_style_2(): void
    {
        $sesi = $this->sesi(self::SESI_FIXTURE);

        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        $titik = $sesi->uncertaintyCalculations
            ->sortBy('titik_ke')
            ->map(fn ($t): float => (float) $t->titik_ukur)
            ->values()
            ->all();

        $this->assertSame(2, $profil->styleSertifikat($titik, $sesi->equipment));
    }

    /**
     * Penjaga arah sebaliknya: fixture ini nggak boleh ngotorin job asli.
     * `isiTitikUkur()` dipakai dua sesi sekarang, dan dia ngehapus baris punya
     * sesi yang lagi diisi — kalau salah sesi yang dilewatin, job asli yang
     * angkanya diadu ke master bisa ikut kegusur tanpa ada yang teriak.
     */
    public function test_sesi_job_asli_nggak_ketularan(): void
    {
        $sesi = $this->sesi(self::SESI_ASLI);

        $this->assertCount(3, $sesi->uncertaintyCalculations);

        $kode = $this->kodeTemuan($sesi, 'peringatan');

        $this->assertNotContains('conductivity_titik_tengah_mili', $kode);
        $this->assertSame([], $kode, 'Sesi job asli harus nol peringatan (hasil nomor 2).');

        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        $titik = $sesi->uncertaintyCalculations
            ->sortBy('titik_ke')
            ->map(fn ($t): float => (float) $t->titik_ukur)
            ->values()
            ->all();

        $this->assertSame(1, $profil->styleSertifikat($titik, $sesi->equipment));
    }
}
