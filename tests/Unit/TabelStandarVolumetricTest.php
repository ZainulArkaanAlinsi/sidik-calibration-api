<?php

namespace Tests\Unit;

use App\Services\Calibration\TabelStandarVolumetric;
use Tests\TestCase;

/**
 * Penjaga pembaca tabel referensi Volumetric.
 *
 * Angka harapan dibaca dari workbook master: CMC Pipet Volume 1 mL = 0,003
 * (`PERHITUNGAN_U95%` Fixed, "CMC"), Gelas Ukur 100 mL = 0,34 (Graduated),
 * diameter untuk toleransi 0,008 mL = 4,7 mm (Fixed, "ø").
 */
class TabelStandarVolumetricTest extends TestCase
{
    private TabelStandarVolumetric $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = new TabelStandarVolumetric;
    }

    /** Dua CMC yang benar-benar tercetak di contoh workbook master. */
    public function test_cmc_cocok_dengan_contoh_master(): void
    {
        $this->assertSame(0.003, $this->t->cmc('Pipet Volume', 1.0));
        $this->assertSame(0.34, $this->t->cmc('Gelas Ukur', 100.0));
    }

    /**
     * Nominal di antara dua rentang memakai yang TERDEKAT, bukan ditolak.
     *
     * Gelas Ukur 30 mL: rentang 25 (jarak 5) lawan 50 (jarak 20) → 25 → 0,17.
     * Gelas Ukur 90 mL: rentang 100 (jarak 10) lawan 50 (jarak 40) → 100 → 0,34.
     */
    public function test_cmc_memakai_nominal_terdekat(): void
    {
        $this->assertSame(25.0, $this->t->nominalTerindeks('Gelas Ukur', 30.0));
        $this->assertSame(0.17, $this->t->cmc('Gelas Ukur', 30.0));

        $this->assertSame(100.0, $this->t->nominalTerindeks('Gelas Ukur', 90.0));
        $this->assertSame(0.34, $this->t->cmc('Gelas Ukur', 90.0));
    }

    /**
     * Seri → baris PERTAMA di tabel, seperti `MATCH(..., 0)` Excel.
     *
     * Gelas Ukur 17,5 mL persis di tengah 10 dan 25. Master memilih 10.
     * Kalau suatu saat perbandingannya diubah jadi `<=`, yang terpilih jadi 25
     * dan CMC-nya naik dari 0,067 ke 0,17 — tanpa error apa pun.
     */
    public function test_cmc_seri_memilih_baris_pertama(): void
    {
        $this->assertSame(10.0, $this->t->nominalTerindeks('Gelas Ukur', 17.5));
        $this->assertSame(0.067, $this->t->cmc('Gelas Ukur', 17.5));
    }

    public function test_jenis_alat_tak_dikenal_memulangkan_null(): void
    {
        $this->assertNull($this->t->cmc('Gelas Kimia', 100.0));
        $this->assertNull($this->t->nominalTerindeks('Gelas Kimia', 100.0));
    }

    /** Keenam jenis alat lampiran akreditasi untuk metode ini ada semua. */
    public function test_enam_jenis_alat_lengkap(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Gelas Ukur', 'Labu Ukur', 'Buret', 'Picnometer', 'Pipet Volume', 'Pipet Ukur'],
            $this->t->jenisAlat(),
        );
    }

    /**
     * Diameter dicari PERSIS — berbeda dari CMC, dan itu disengaja.
     *
     * Master memakai `VLOOKUP(..., 0)`. Toleransi yang tidak ada di tabel
     * ISO 4787 harus `null`, bukan diameter baris tetangga.
     */
    public function test_diameter_dicari_persis(): void
    {
        $this->assertSame(4.7, $this->t->diameterMaksimum(0.008));
        $this->assertNull($this->t->diameterMaksimum(0.0079));
        $this->assertNull($this->t->diameterMaksimum(0.0085));
    }

    /** Neraca ke-3 beda fisik antar keluarga, dan tidak boleh tertukar. */
    public function test_neraca_tidak_tertukar_antar_keluarga(): void
    {
        $this->assertNotNull($this->t->neraca('fixed', 'Electronic Balance Fujitsu'));
        $this->assertNull($this->t->neraca('fixed', 'Electronic Balance Precisa'));

        $this->assertNotNull($this->t->neraca('graduated', 'Electronic Balance Precisa'));
        $this->assertNull($this->t->neraca('graduated', 'Electronic Balance Fujitsu'));

        $this->assertSame(0.0001, $this->t->neraca('fixed', 'Analytical Balance')['resolusi_g']);
    }

    /**
     * Graduated TIDAK punya kolom Res. Versi pertama generator membaca indeks
     * tetap dan menulis stdev Graduated sebagai resolusi — dijaga di sini.
     */
    public function test_neraca_graduated_tidak_punya_resolusi_dan_u95_terbaca(): void
    {
        $precisa = $this->t->neraca('graduated', 'Electronic Balance Precisa');

        $this->assertNull($precisa['resolusi_g']);
        $this->assertSame(0.0019, $precisa['u95_g']);
        $this->assertSame(0.0, $precisa['stdev_g']);
        $this->assertSame(0.0019, $this->t->neraca('fixed', 'Electronic Balance Fujitsu')['u95_g']);
    }

    /**
     * Suhu terkoreksi diadu ke master: Fixed `PERHITUNGAN` 27,0 °C →
     * 27,32502900705911 (dipakai sebagai `suhu_air` budget Fixed).
     */
    public function test_koreksi_suhu_memakai_titik_kalibrator_terdekat(): void
    {
        $k = $this->t->koreksiSuhu(27.0);

        $this->assertSame(25.0, $k['titik_c']);
        $this->assertEqualsWithDelta(0.3249999999999979, $k['koreksi_kalibrator_c'], 1e-15);
        $this->assertEqualsWithDelta(2.9007059112018396e-05, $k['koreksi_sensor_c'], 1e-18);
        $this->assertEqualsWithDelta(27.32502900705911, $k['terkoreksi_c'], 1e-12);

        // 38 °C lebih dekat ke 50 daripada ke 25 — koreksi titik 50 yang dipakai.
        $this->assertSame(50.0, $this->t->koreksiSuhu(38.0)['titik_c']);
        $this->assertEqualsWithDelta(38.0 + 0.35 + 5.956705609122537e-05, $this->t->koreksiSuhu(38.0)['terkoreksi_c'], 1e-12);
    }

    public function test_u95_suhu_satu_angka_dari_database(): void
    {
        $this->assertSame(['termometer_c' => 0.72, 'sensor_c' => 0.08], $this->t->u95Suhu());
    }
}
