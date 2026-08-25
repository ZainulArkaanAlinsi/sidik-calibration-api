<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lembar berbentuk GRID sensor harus menghasilkan sel, bukan nol.
 *
 * ## Kegagalan yang dijaga di sini nggak memunculkan error sama sekali
 *
 * `TemplateLembarKerja::dariProfil()` dulu cuma menyusuri `bagian.tabel`.
 * Kelima lembar Enclosure nggak punya `bagian.tabel` — bentuknya `grid_sensor`
 * (9 termokopel × 5 pembacaan per set point). Jadi yang terjadi bukan
 * pengecualian: dia memulangkan NOL sel, dengan tenang.
 *
 * Akibatnya berlapis, dan tiap lapis kelihatan wajar sendiri-sendiri:
 *
 *  1. `ocr:rangka-geometri oven` sukses, dan menulis berkas geometri yang
 *     isinya `"tabel": []`. Berkasnya ADA, ukurannya wajar, JSON-nya sah.
 *  2. `kesiapan()` mem-`array_diff` kunci sel template lawan kunci di berkas.
 *     Nol lawan nol = nggak ada yang kurang. Jadi pemeriksaan "ada sel yang
 *     nggak punya kotak?" LOLOS — karena nggak ada sel sama sekali.
 *  3. Yang menahan cuma `terverifikasi: false`. Begitu ada yang menyetelnya
 *     true — dan itu tinggal satu kata di JSON — lembar Enclosure jadi
 *     "siap pindai" yang memindai NOL sel dan memulangkan sesi kosong.
 *
 * Jadi yang dijaga bukan "fiturnya jalan", tapi **jumlah selnya masuk akal**.
 * Nol sel di lembar yang jelas-jelas punya 45 kotak angka itu bug, dan bug
 * yang nggak pernah bilang apa-apa.
 */
class TemplateOcrGridSensorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kelima lembar Enclosure. Dieja satu-satu, BUKAN dibaca dari registry:
     * kalau nanti ada yang nggak sengaja mencabut `grid_sensor` dari salah satu
     * profil, daftar yang dibaca dari registry ikut menyusut dan test-nya tetap
     * hijau buat sisanya.
     *
     * @return array<string, array{string}>
     */
    public static function profilEnclosure(): array
    {
        return [
            'oven' => ['oven'],
            'furnace' => ['furnace'],
            'bath' => ['bath'],
            'inkubator' => ['inkubator'],
            'refrigerator' => ['refrigerator'],
        ];
    }

    private function alat(string $kodeProfil): Equipment
    {
        return Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'suhu-dan-kelembapan'])->id,
            'nama_alat' => ucfirst($kodeProfil),
            'nama_alat_kemampuan' => null,
            'range_min' => 30, 'range_max' => 200,
            'satuan' => '°C', 'resolusi' => 0.1, 'toleransi' => null,
        ]);
    }

    #[DataProvider('profilEnclosure')]
    public function test_lembar_grid_menghasilkan_sel_bukan_nol(string $kodeProfil): void
    {
        Organization::factory()->create();

        $profil = app(CalibrationProfileRegistry::class)->untukKode($kodeProfil);
        $this->assertNotNull($profil, "Profil `{$kodeProfil}` nggak ada di registry.");

        $template = app(TemplateLembarKerja::class)->dariProfil($profil);

        // 9 termokopel + indikator + suhu ruang = 11 baris, × 5 pengulangan.
        $this->assertCount(
            55,
            $template['sel'],
            "Lembar `{$kodeProfil}` menghasilkan ".count($template['sel']).' sel. '
            .'Nol berarti grid-nya nggak keterjemahkan sama sekali; angka lain berarti '
            .'jumlah termokopel/pengulangannya bergeser tanpa test ini ikut diperbarui.',
        );
    }

    /**
     * Kunci selnya harus berbentuk sama dengan lembar lain.
     *
     * Kalau grid dikasih skema kunci sendiri, seluruh pipeline sesudahnya
     * (`PemrosesScanLembarKerja`, `worksheet_scan_cells.kunci`, layar review)
     * harus tahu ada dua bentuk kunci — dan yang lupa diajari nggak error,
     * cuma nggak pernah ketemu selnya.
     */
    public function test_kunci_sel_grid_sebentuk_dengan_lembar_lain(): void
    {
        Organization::factory()->create();

        $profil = app(CalibrationProfileRegistry::class)->untukKode('oven');
        $this->assertNotNull($profil);

        $sel = app(TemplateLembarKerja::class)->dariProfil($profil)['sel'];

        foreach (array_keys($sel) as $kunci) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9_]+\|\d+\|\d+\|[a-z0-9_]+$/',
                (string) $kunci,
                "Kunci `{$kunci}` nggak sebentuk `tabel|baris|repeat|field`.",
            );
        }

        // Baris pertama & terakhir memang termokopel 1 dan Suhu Ruang.
        $baris = $sel[array_key_first($sel)]['baris_ke'] ?? null;
        $this->assertSame(1, $baris);
    }

    /**
     * Lembar grid HARUS menolak jalur foto AI.
     *
     * Dua jalur pindai gerbangnya beda, dan bedanya bukan detail:
     *
     *  - `PINDAI LEMBAR KERJA` (OCR template lokal) pakai berkas geometri per
     *    sel. Sejak grid keterjemahkan, kelima lembar ini punya 55 sel yang
     *    sah — jadi jalur itu MEMANG boleh.
     *  - `FOTO TABEL INI` (AI Vision) pakai dua penanda bentuk yang cuma
     *    sanggup menggambarkan lembar "titik ukur × Repeat". Kertas grid nggak
     *    muat di situ.
     *
     * Kalau `didukung` dibiarkan `true`, yang terjadi bukan error: prompt &
     * skema JSON yang dikirim ke pembaca foto dibangun dari dua penanda itu,
     * jadi modelnya diminta membaca tabel yang nggak pernah ada di kertasnya.
     * Yang balik ke teknisi angka ngawur yang kelihatan wajar.
     */
    #[DataProvider('profilEnclosure')]
    public function test_lembar_grid_nolak_jalur_foto_ai(string $kodeProfil): void
    {
        Organization::factory()->create();

        $profil = app(CalibrationProfileRegistry::class)->untukKode($kodeProfil);
        $this->assertNotNull($profil);

        $this->assertFalse(
            $profil->bentukPindaiFoto()['didukung'] ?? true,
            "Lembar `{$kodeProfil}` bentuknya GRID tapi masih ngaku muat di jalur foto AI. "
            .'Modelnya bakal diminta baca tabel yang nggak ada di kertasnya, dan yang balik '
            .'bukan error — angka ngawur yang kelihatan wajar.',
        );
    }

    /**
     * Pita angkanya WAJIB ikut rentang kerja alat, bukan diturunkan dari
     * nominal.
     *
     * Ini yang paling gampang lolos diam-diam. Waktu lembarnya dicetak, set
     * point-nya belum ada — teknisi yang nulis tangan. Jadi `titik_ukur`-nya
     * 0.0, dan `aturanPembacaan()` yang menurunkan rentang dari nominal bakal
     * memulangkan 0–2 °C (resolusi 0,1 × 20).
     *
     * Oven yang dikalibrasi di 121 °C berarti MERAH di kelima puluh lima
     * selnya. Bukan gagal — cuma salah, dan salahnya konsisten. Teknisi yang
     * tiap hari melihat semua sel merah berhenti membaca warnanya, dan waktu
     * ada sel yang beneran meleset, warnanya sudah nggak berarti apa-apa.
     */
    public function test_pita_ikut_rentang_alat_bukan_nominal_nol(): void
    {
        Organization::factory()->create();

        $profil = app(CalibrationProfileRegistry::class)->untukKode('oven');
        $this->assertNotNull($profil);

        $sel = app(TemplateLembarKerja::class)
            ->dariProfil($profil, $this->alat('oven'))['sel'];

        $pertama = $sel[array_key_first($sel)];

        $this->assertSame(30.0, $pertama['aturan']['min']);
        $this->assertSame(200.0, $pertama['aturan']['maks']);

        // Dan bukan pita turunan nominal-nol yang bakal nolak semuanya.
        $this->assertNotSame(2.0, $pertama['aturan']['maks']);
    }
}
