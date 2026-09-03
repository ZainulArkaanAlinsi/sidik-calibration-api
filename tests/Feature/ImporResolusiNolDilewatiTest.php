<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Baris impor dengan `Resolusi` nol DILEWATI berikut alasannya — tidak masuk
 * diam-diam, dan tidak juga dibetulkan diam-diam.
 *
 * ## Kenapa berkas ini ada
 *
 * `gt:0` di `EquipmentRequest` (BUG-002) menutup jalur API. Impor Excel tidak
 * lewat FormRequest sama sekali, jadi pintunya masih terbuka:
 *
 *     'resolusi' => $this->angka($nilai['resolusi'] ?? null),   // 0 → 0.0
 *     ...
 *     ], fn ($v): bool => filled($v));                          // filled(0.0) === true
 *
 * `filled()` di Laravel cuma menganggap kosong itu `null`, string kosong, dan
 * array kosong — **angka nol lolos**. Jadi sel Excel berisi `0` mendarat
 * sebagai `resolusi = 0.0`, dan `Angka::desimalDariResolusi()` menyamakannya
 * dengan null: sertifikatnya kembali mencetak empat desimal untuk alat yang
 * tidak punya presisi itu.
 *
 * ## Kenapa DILEWATI, bukan dikosongkan diam-diam
 *
 * Bukan kebijakan baru — ini pola yang sudah dipakai berkas yang sama, dua
 * puluh baris di atas tempat bug-nya, buat masalah sekelas:
 *
 * > *"Nebak kategori dari nama alat gampang meleset dan hasilnya ketidakpastian
 * > yang salah di sertifikat — mending dilewati & dibenerin filenya."*
 *
 * Penalarannya identik: nilai yang menghasilkan angka salah di sertifikat
 * dikembalikan ke pemilik berkas, bukan ditebak. Dan aturan repo ini tegas soal
 * arah sebaliknya — sel yang dibaca nol *"jangan pernah ditiru; blokir titiknya
 * dengan alasan yang kebaca"*.
 *
 * Melewatkan baris juga tidak menghilangkan apa pun: importer punya mode
 * `uji_coba` yang menampilkan baris terlewat berikut alasannya SEBELUM ada yang
 * ditulis ke database. Dijaga test paling bawah.
 */
class ImporResolusiNolDilewatiTest extends TestCase
{
    use RefreshDatabase;

    private const ALASAN = 'Resolusi alat "pH Meter" nol atau negatif. '
        .'Kosongin selnya kalau resolusinya nggak diketahui — nol bikin sertifikatnya '
        .'ngaku empat desimal yang alatnya nggak punya.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        Customer::factory()->create(['nama' => 'PT Tirta Gracia']);
        EquipmentCategory::factory()->create(['kode' => 'ph', 'nama' => 'Derajat Keasaman']);
    }

    private function csv(string $isi): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-').'.csv';
        file_put_contents($path, $isi);

        return new UploadedFile($path, 'data.csv', 'text/csv', null, true);
    }

    private function berkasResolusi(string $resolusi): UploadedFile
    {
        return $this->csv(
            "Nama Alat,Pemilik,Kategori,Serial Number,Merk,Resolusi\n"
            ."pH Meter,PT Tirta Gracia,Derajat Keasaman,B628755900,Mettler Toledo,{$resolusi}\n"
        );
    }

    public function test_resolusi_nol_dilewati_dengan_alasan_yang_kebaca(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/imports/excel', [
                'file' => $this->berkasResolusi('0'),
                'tipe' => 'equipments',
                'uji_coba' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dilewati', 1)
            ->assertJsonPath('data.ringkasan.dibuat', 0)
            ->assertJsonPath('data.baris.0.alasan', self::ALASAN);

        $this->assertSame(0, Equipment::count(), 'Alatnya tetap kebuat padahal barisnya dilewati.');
    }

    /**
     * Negatif juga — `gt:0`, bukan sekadar "bukan nol".
     */
    public function test_resolusi_negatif_ikut_dilewati(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/imports/excel', [
                'file' => $this->berkasResolusi('-0.5'),
                'tipe' => 'equipments',
                'uji_coba' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dilewati', 1);

        $this->assertSame(0, Equipment::count());
    }

    /**
     * Minus "cantik" tetap minus — dan ini yang paling mudah lolos.
     *
     * `−` (U+2212, dari salin-tempel PDF), `–`/`—` (autocorrect Excel), dan
     * `－` (papan ketik non-latin) semuanya dulu DIBUANG oleh regex pembersih
     * angka, dan yang tersisa bilangan POSITIF. Jadi penjaga di atas tidak
     * pernah kena: `−0,5` mendarat di database sebagai resolusi `0,5` yang
     * kelihatan sah sepenuhnya, dan sertifikatnya mencetak presisi yang
     * alatnya tidak punya.
     *
     * Tidak ada yang error waktu itu terjadi — persis kelas kegagalan yang
     * seluruh audit ini kejar.
     *
     * @param  string  $resolusi  ditulis pakai varian minus yang berbeda-beda
     */
    #[DataProvider('variasiMinus')]
    public function test_minus_bukan_hyphen_ascii_juga_dilewati(string $resolusi): void
    {
        $this->actingAs($this->admin)
            ->post('/api/imports/excel', [
                'file' => $this->berkasResolusi($resolusi),
                'tipe' => 'equipments',
                'uji_coba' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dilewati', 1)
            ->assertJsonPath('data.baris.0.alasan', self::ALASAN);

        $this->assertSame(
            0,
            Equipment::count(),
            "Resolusi {$resolusi} mendarat di database — minusnya kebuang, angkanya jadi positif.",
        );
    }

    /** @return array<string, array{string}> */
    public static function variasiMinus(): array
    {
        return [
            'minus sign U+2212' => ["\u{2212}0.5"],
            'en dash U+2013' => ["\u{2013}0.5"],
            'em dash U+2014' => ["\u{2014}0.5"],
            'fullwidth hyphen U+FF0D' => ["\u{FF0D}0.5"],
        ];
    }

    /**
     * JANGAN kebablasan: sel KOSONG itu "belum diketahui", dan itu sah.
     *
     * Kalau test ini merah, perbaikannya memblokir impor yang selama ini benar —
     * dan berkas lab lama memang banyak yang kolom resolusinya belum terisi.
     */
    public function test_sel_resolusi_kosong_tetap_diimpor(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/imports/excel', [
                'file' => $this->berkasResolusi(''),
                'tipe' => 'equipments',
                'uji_coba' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dibuat', 1);

        $this->assertNull(Equipment::firstOrFail()->resolusi);
    }

    public function test_resolusi_wajar_tetap_diimpor(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/imports/excel', [
                'file' => $this->berkasResolusi('0.01'),
                'tipe' => 'equipments',
                'uji_coba' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dibuat', 1);

        $this->assertEqualsWithDelta(0.01, (float) Equipment::firstOrFail()->resolusi, 1e-9);
    }

    /**
     * Yang bikin "dilewati" aman: operator melihatnya DULU.
     *
     * Mode `uji_coba` melaporkan baris terlewat berikut alasannya tanpa menulis
     * apa pun. Jadi barisnya tidak hilang diam-diam — dia dikembalikan ke
     * pemilik berkasnya dengan keterangan yang bisa dibaca sebelum impor
     * sungguhan dijalankan.
     */
    public function test_uji_coba_sudah_menunjukkan_barisnya_sebelum_apa_pun_ditulis(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/imports/excel', [
                'file' => $this->berkasResolusi('0'),
                'tipe' => 'equipments',
            ])
            ->assertOk()
            ->assertJsonPath('data.uji_coba', true)
            ->assertJsonPath('data.ringkasan.dilewati', 1)
            ->assertJsonPath('data.baris.0.alasan', self::ALASAN);

        $this->assertSame(0, Equipment::count());
    }
}
