<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DirektoriLokal;
use App\Models\Organization;
use App\Models\User;
use App\Services\Direktori\DirektoriGagal;
use App\Services\Direktori\DirektoriLokalDb;
use App\Services\Direktori\DirektoriPerusahaan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Direktori rujukan lokal — sumber petunjuk nama & alamat PT.
 *
 * Yang dijaga di sini bukan "pencariannya jalan", tapi empat batas yang kalau
 * dilanggar **tidak menghasilkan error sama sekali**:
 *
 *  1. Isinya tidak pernah bocor jadi `customers`. Kalau bocor, sepuluh ribu
 *     baris tanpa penanggung jawab ikut tersalin ke HP tiap teknisi lewat
 *     `SimpananPelanggan`, dan panel admin jadi sepuluh ribu baris.
 *  2. Tabelnya tidak bersandar `organization_id`, jadi tidak boleh pernah
 *     dipakai menyimpan data milik lab.
 *  3. Tabel kosong = jalur ini mati, dan perilakunya harus SAMA PERSIS seperti
 *     sebelum fitur ini ada.
 *  4. Kata kunci yang turun jadi kosong tidak boleh memulangkan daftar acak.
 */
class DirektoriLokalTest extends TestCase
{
    use RefreshDatabase;

    private function baris(string $nama, array $ubah = []): DirektoriLokal
    {
        $b = new DirektoriLokal;
        $b->fill([
            'sumber' => DirektoriLokal::SUMBER_JABABEKA,
            'ref' => 'jbk-'.md5($nama),
            'nama' => $nama,
            'alamat' => 'Jl. Industri Selatan 2 Blok MM 15',
            ...$ubah,
        ]);
        $b->save();

        return $b;
    }

    public function test_nama_normal_diturunkan_lewat_aturan_yang_sama_dengan_customers(): void
    {
        // Dua aturan berbeda di kolom yang sama bikin pencarian di sini berhenti
        // cocok dengan penjaga kembar `customers`, tanpa satu pun error.
        $baris = $this->baris('PT. Maju  Jaya');

        $this->assertSame('pt maju jaya', $baris->nama_normal);
        $this->assertSame(Customer::normalkanNama('PT. Maju  Jaya'), $baris->nama_normal);
    }

    public function test_pencarian_menemukan_walau_tanda_bacanya_beda(): void
    {
        $this->baris('PT. Maju Jaya');

        $hasil = (new DirektoriLokalDb)->cari('pt maju jaya');

        $this->assertCount(1, $hasil);
        $this->assertSame('PT. Maju Jaya', $hasil[0]->nama);
        $this->assertStringStartsWith('lokal:jababeka:', $hasil[0]->ref);
    }

    public function test_yang_diawali_kata_kunci_naik_duluan(): void
    {
        // Tanpa pengurutan ini, teknisi yang mengetik nama depan PT-nya harus
        // memindai daftar buat menemukan yang paling jelas.
        $this->baris('PT Sinar Maju Abadi');
        $this->baris('Maju Jaya Sentosa');

        $hasil = (new DirektoriLokalDb)->cari('maju');

        $this->assertSame('Maju Jaya Sentosa', $hasil[0]->nama);
    }

    public function test_kata_kunci_yang_isinya_tanda_baca_tidak_memulangkan_daftar_acak(): void
    {
        // `normalkanNama('...')` turun jadi string KOSONG, dan `LIKE '%%'`
        // mencocoki SEMUA baris. Jebakan yang sama pernah menggigit
        // `CustomerController::lookup`.
        $this->baris('PT Maju Jaya');
        $this->baris('PT Sinar Abadi');

        $this->assertSame([], (new DirektoriLokalDb)->cari('...'));
        $this->assertSame([], (new DirektoriLokalDb)->cari('   '));
    }

    public function test_tabel_kosong_bikin_jalurnya_mati_bukan_mengaku_hidup(): void
    {
        // Kalau dia mengaku tersedia padahal kosong, `GET /api/health`
        // melaporkan jalur mati sebagai hidup.
        $this->assertFalse((new DirektoriLokalDb)->tersedia());

        $this->baris('PT Maju Jaya');

        $this->assertTrue((new DirektoriLokalDb)->tersedia());
    }

    public function test_atribusi_menyebut_datanya_belum_terverifikasi(): void
    {
        // Tanpa kalimat ini hasil impor kelihatan sama persis dengan pelanggan
        // yang beneran pernah dilayani, dan bedanya baru ketahuan waktu alamat
        // 2020 tercetak di sertifikat.
        $atribusi = (new DirektoriLokalDb)->atribusi();

        $this->assertNotNull($atribusi);
        $this->assertStringContainsString('Belum diverifikasi', $atribusi);
    }

    public function test_impor_direktori_tidak_menambah_satu_pun_baris_customers(): void
    {
        // Batas yang paling menentukan di seluruh fitur.
        Organization::factory()->create();
        $sebelum = Customer::count();

        $this->baris('PT Maju Jaya');
        $this->baris('PT Sinar Abadi');

        $this->assertSame($sebelum, Customer::count());
    }

    public function test_lapis_lokal_dipakai_duluan_waktu_tabelnya_terisi(): void
    {
        $this->baris('PT Maju Jaya');

        $direktori = $this->app->make(DirektoriPerusahaan::class);
        $hasil = $direktori->cari('pt maju jaya');

        // Ketemu tanpa menyentuh jaringan sama sekali — tidak ada Http::fake
        // di test ini, jadi kalau dia menembak ke luar, test-nya yang gagal.
        $this->assertCount(1, $hasil);
        $this->assertSame('PT Maju Jaya', $hasil[0]->nama);
    }

    public function test_health_melaporkan_lapis_lokal_berikut_jumlah_barisnya(): void
    {
        // Tanpa `baris`, satu-satunya cara memeriksa "impornya sudah jalan di
        // container ini belum" masuk ke database produksi.
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('direktori_perusahaan.lokal.aktif', false)
            ->assertJsonPath('direktori_perusahaan.lokal.baris', 0);

        $this->baris('PT Maju Jaya');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('direktori_perusahaan.lokal.aktif', true)
            ->assertJsonPath('direktori_perusahaan.lokal.baris', 1);
    }

    public function test_tabel_belum_ada_tidak_bikin_health_membalas_500(): void
    {
        // Ini bug nyata yang ketemu waktu fitur ini dibangun, dan bentuk
        // gagalnya paling buruk: `AppServiceProvider` memanggil `tersedia()`
        // waktu membangun `DirektoriPerusahaan`, dan `GET /api/health`
        // menyelesaikan `DirektoriPerusahaan`. Sebelum diperbaiki, pemasangan
        // yang migrasinya belum jalan bikin health membalas **500** — endpoint
        // yang justru dipakai buat mendiagnosis kenapa pemasangannya belum
        // benar.
        Schema::drop('direktori_lokal');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('direktori_perusahaan.lokal.aktif', false)
            ->assertJsonPath('direktori_perusahaan.lokal.baris', 0);
    }

    public function test_tabel_belum_ada_bikin_jalurnya_diam_bukan_meledak(): void
    {
        Schema::drop('direktori_lokal');

        $this->assertFalse((new DirektoriLokalDb)->tersedia());
        $this->assertSame(0, DirektoriLokalDb::jumlah());

        // `cari()` tetap MELEMPAR, dan tipenya yang dikenali kontraknya —
        // `DirektoriBerlapis` cuma menangkap `DirektoriGagal`, jadi
        // `PDOException` mentah bakal lolos dan menjatuhkan seluruh pencarian
        // padahal lapis berikutnya masih bisa menjawab.
        $this->expectException(DirektoriGagal::class);
        (new DirektoriLokalDb)->cari('pt maju jaya');
    }

    public function test_pencarian_direktori_dari_hp_memulangkan_hasil_lokal(): void
    {
        // Endpoint yang SUDAH dipanggil `cariDirektori()` di Flutter. Kalau ini
        // hijau, fitur mendarat tanpa satu baris pun berubah di sisi HP.
        Organization::factory()->create();
        $this->baris('PT Maju Jaya');

        $this->actingAs(User::factory()->create())
            ->getJson('/api/customers/direktori?search=pt+maju+jaya')
            ->assertOk()
            ->assertJsonPath('data.0.nama', 'PT Maju Jaya');
    }
}
