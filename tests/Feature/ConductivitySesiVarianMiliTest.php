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

        foreach ([
            'titik_ukur',
            'rata_rata',
            'koreksi',
            'standar_deviasi',
            'type_a',
            'type_b',
            'ketidakpastian_gabungan',
            'ketidakpastian_diperluas',
        ] as $kolom) {
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
     * Titik tengah varian mS/cm harus lewat jalur budget terakreditasi yang
     * SAMA dengan jalur µS/cm — bukan jatuh ke jalur generik.
     *
     * Sebelum 11 Agt 2026 dia jatuh: `v_eff` NULL, U95 keluar 8,0000 (harusnya
     * ~0,008109), dan angkanya bukan turunan CMC lab sama sekali. Sebabnya CMC
     * kedaftar di 25 & 1412 (µS/cm) dan 111 (mS/cm) — nggak ada baris di 1,412,
     * jadi `kemampuanUntukTitik()` nggak nemu apa-apa.
     *
     * Perbaikannya bukan nambah baris CMC (itu klaim akreditasi yang lab-nya
     * belum pernah nulis), tapi ngitung titik ini di satuan kanoniknya lewat
     * `faktorKanonik()`. Yang dijaga di sini: v_eff & k IDENTIK sama sesi µS/cm
     * — dua-duanya nirsatuan, jadi kalau beda artinya titiknya lewat jalur lain
     * atau kena skala yang nggak boleh kena.
     */
    public function test_titik_tengah_mili_lewat_jalur_budget_yang_sama(): void
    {
        $mikro = $this->sesi(self::SESI_ASLI)->uncertaintyCalculations
            ->firstWhere('titik_ke', 2);
        $mili = $this->sesi(self::SESI_FIXTURE)->uncertaintyCalculations
            ->firstWhere('titik_ke', 2);

        $this->assertNotNull(
            $mili->derajat_kebebasan_efektif,
            'v_eff kosong — titiknya jatuh ke jalur generik, lantai CMC nggak kepasang.',
        );

        foreach (['derajat_kebebasan_efektif', 'faktor_cakupan_k'] as $nirsatuan) {
            $this->assertEqualsWithDelta(
                (float) $mikro->{$nirsatuan},
                (float) $mili->{$nirsatuan},
                1e-9,
                "Kolom {$nirsatuan} nirsatuan, harusnya identik di dua sesi.",
            );
        }
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
