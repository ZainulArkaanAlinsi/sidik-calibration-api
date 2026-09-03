<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Impor alat tidak menanyakan PT & kategori yang sama berulang-ulang.
 *
 * ## Kenapa berkas ini ada
 *
 * Tiap baris `equipments` menembak DUA query pencarian — satu untuk PT pemilik,
 * satu untuk kategori — dan dua-duanya menanyakan hal yang sama berkali-kali:
 * satu berkas 500 alat biasanya cuma menyebut belasan PT dan segelintir
 * kategori. Dengan `MAX_BARIS` 5000 itu sampai 10.000 round-trip berurutan,
 * seluruhnya di dalam SATU `DB::transaction()` yang ditahan terbuka sepanjang
 * loop.
 *
 * Risikonya memang teredam — dibatasi 5000 baris, cuma admin yang bisa memicu,
 * dan jarang dijalankan. Tapi transaksi panjang itu menahan lock, dan yang
 * menahan lock paling lama di aplikasi ini adalah jalur yang paling jarang
 * diperhatikan.
 *
 * ## Kenapa memo, bukan memuat seluruh tabel di depan
 *
 * Memuat semua PT ke memori sekali di awal lebih cepat lagi, tapi menuntut
 * kuncinya menirukan collation database — dan MySQL (produksi) tidak sama
 * dengan SQLite (test). Menirunya salah berarti impor diam-diam bikin PT
 * kembar, yang justru bencana yang penjaga "PT belum ada, import dulu" di
 * `prosesAlat` dibangun untuk mencegahnya.
 *
 * Memo per-nilai tidak punya masalah itu: pencarian PERTAMA tiap nilai tetap
 * query yang sama persis seperti sebelumnya, jadi tidak ada pencocokan baru
 * yang lahir dan tidak ada yang hilang.
 */
class ImporAlatTidakNanyaBerulangTest extends TestCase
{
    use RefreshDatabase;

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

    /** Berkas berisi [$jumlah] alat, semuanya milik PT & kategori yang sama. */
    private function berkas(int $jumlah, string $pt = 'PT Tirta Gracia'): UploadedFile
    {
        $baris = "Nama Alat,Pemilik,Kategori,Serial Number,Merk\n";

        for ($i = 1; $i <= $jumlah; $i++) {
            $baris .= "pH Meter {$i},{$pt},Derajat Keasaman,SN-{$i},Mettler Toledo\n";
        }

        return $this->csv($baris);
    }

    /** @return list<string> SQL yang benar-benar dijalankan selama impor. */
    private function sqlSelamaImpor(UploadedFile $berkas): array
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = $q->sql;
        });

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', [
                'file' => $berkas,
                'tipe' => 'equipments',
                'uji_coba' => false,
            ])
            ->assertOk();

        return $sql;
    }

    private function hitung(array $sql, string $tabel): int
    {
        return count(array_filter(
            $sql,
            fn (string $s): bool => str_starts_with($s, 'select') && str_contains($s, "\"{$tabel}\""),
        ));
    }

    /**
     * INTI-nya. Dua puluh alat milik satu PT yang sama = SATU pencarian PT,
     * bukan dua puluh.
     */
    public function test_pt_yang_sama_cuma_dicari_sekali(): void
    {
        $sql = $this->sqlSelamaImpor($this->berkas(20));

        $this->assertSame(
            20,
            Equipment::count(),
            'Impornya sendiri nggak jalan — testnya jadi nggak nguji apa-apa.'
        );

        $this->assertLessThanOrEqual(
            2,
            $this->hitung($sql, 'customers'),
            'PT yang sama masih dicari ulang tiap baris.'
        );
    }

    /** Kategori sama saja — dan dia yang paling sering berulang. */
    public function test_kategori_yang_sama_cuma_dicari_sekali(): void
    {
        $sql = $this->sqlSelamaImpor($this->berkas(20));

        $this->assertLessThanOrEqual(
            2,
            $this->hitung($sql, 'equipment_categories'),
            'Kategori yang sama masih dicari ulang tiap baris.'
        );
    }

    /**
     * Hasil KOSONG juga diingat — dan itu yang paling banyak menolong.
     *
     * Berkas yang salah ketik satu nama PT mengulang pencarian yang PASTI gagal
     * itu di tiap barisnya. Tanpa mengingat hasil kosong, kasus terburuknya
     * justru berkas yang paling salah.
     */
    public function test_pt_yang_nggak_ada_juga_cuma_dicari_sekali(): void
    {
        $sql = $this->sqlSelamaImpor($this->berkas(20, 'PT Yang Salah Ketik'));

        $this->assertSame(0, Equipment::count(), 'Alat mendarat padahal PT-nya nggak ada.');

        $this->assertLessThanOrEqual(
            2,
            $this->hitung($sql, 'customers'),
            'Pencarian yang pasti gagal masih diulang tiap baris.'
        );
    }

    /**
     * JANGAN kebablasan #1: dua PT berbeda tetap dicari sendiri-sendiri, dan
     * alatnya mendarat di pemiliknya masing-masing.
     *
     * Kalau test ini merah, memonya nyampur — dan seluruh berkas mendarat di
     * satu PT yang salah, diam-diam.
     */
    public function test_dua_pt_berbeda_mendarat_di_pemiliknya_masing_masing(): void
    {
        Customer::factory()->create(['nama' => 'PT Sumber Jaya']);

        $this->actingAs($this->admin)
            ->post('/api/imports/excel', [
                'file' => $this->csv(
                    "Nama Alat,Pemilik,Kategori,Serial Number\n"
                    ."pH Meter A,PT Tirta Gracia,Derajat Keasaman,SN-A\n"
                    ."pH Meter B,PT Sumber Jaya,Derajat Keasaman,SN-B\n"
                    ."pH Meter C,PT Tirta Gracia,Derajat Keasaman,SN-C\n"
                ),
                'tipe' => 'equipments',
                'uji_coba' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dibuat', 3);

        $tirta = Customer::where('nama', 'PT Tirta Gracia')->firstOrFail();
        $sumber = Customer::where('nama', 'PT Sumber Jaya')->firstOrFail();

        $this->assertSame(2, Equipment::where('customer_id', $tirta->id)->count());
        $this->assertSame(1, Equipment::where('customer_id', $sumber->id)->count());
    }

    /**
     * JANGAN kebablasan #2: PT yang belum ada tetap DILEWATI dengan alasannya,
     * bukan dibikin diam-diam.
     *
     * Penjagaan itu ada lebih dulu daripada memonya, dan alasannya ditulis di
     * tempat: salah ketik nama PT di satu baris bikin pelanggan kembar yang
     * susah digabung lagi.
     */
    public function test_pt_yang_belum_ada_tetap_dilewati_dengan_alasan(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/imports/excel', [
                'file' => $this->berkas(2, 'PT Belum Terdaftar'),
                'tipe' => 'equipments',
                'uji_coba' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.dilewati', 2)
            ->assertJsonPath('data.ringkasan.dibuat', 0)
            ->assertJsonPath(
                'data.baris.0.alasan',
                'PT "PT Belum Terdaftar" belum ada. Import data pelanggan dulu.',
            );

        $this->assertSame(0, Customer::where('nama', 'PT Belum Terdaftar')->count());
    }
}
