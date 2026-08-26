<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/equipments?profil=` — kotak pilih alat cuma menyodorkan alat yang
 * memang milik lembar kerja yang lagi dibuka.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * Sebelum 26 Agt 2026 penyaring yang ada cuma `?category=`, dan kategori jauh
 * lebih kasar daripada lembar kerja: "Suhu dan Kelembapan" memuat 11 jenis alat
 * yang memetakan ke TUJUH lembar berbeda. Teknisi yang membuka lembar TITS juga
 * disodori Oven, Bath, Inkubator, Furnace, Refrigerator, dan TIDS.
 *
 * Salah pilih di situ nggak bikin error di mana pun: sesinya tersimpan, dan
 * `CalibrationProfileRegistry::untukAlat()` diam-diam menghitungnya pakai aturan
 * alat lain. Itu bentuk kegagalan yang paling mahal di repo ini — jalurnya
 * berhasil, angkanya salah.
 *
 * ## Yang paling penting di sini test ALIAS
 *
 * Penyaringan ini gampang "diperbaiki" jadi `WHERE nama_alat_kemampuan = ?`.
 * Kalau itu terjadi, alat pelanggan yang namanya nggak byte-exact —
 * "Turbidimeter Hach", "pH Meter Mettler Toledo" — hilang dari daftar padahal
 * terdaftar. Teknisi mengira alatnya belum ada, lalu menambah duplikat yang
 * nggak punya baris CMC, dan sertifikatnya terbit lewat jalur generik dengan
 * U95 lebih kecil daripada yang diakreditasi.
 *
 * Jadi `test_alat_berejaan_alias_tetap_muncul` bukan pelengkap — dia yang
 * menahan penyaringan ini berubah jadi perbandingan teks.
 */
class DaftarAlatPerLembarTest extends TestCase
{
    use RefreshDatabase;

    private function teknisi(): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'role' => 'teknisi',
            'status' => 'aktif',
        ]);
    }

    private function alat(User $pemilik, ?string $namaKemampuan, ?string $namaAlat = null): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'nama_alat_kemampuan' => $namaKemampuan,
            'nama_alat' => $namaAlat ?? ($namaKemampuan ?? 'Tanpa Nama'),
        ]);
    }

    /** @return list<string> */
    private function namaYangMuncul(User $sebagai, string $kueri): array
    {
        $respons = $this->actingAs($sebagai)->getJson('/api/equipments?'.$kueri);
        $respons->assertOk();

        return array_map(
            static fn (array $baris): string => (string) ($baris['nama_alat'] ?? ''),
            $respons->json('data'),
        );
    }

    /**
     * INTI: lembar TITS nggak boleh menyodorkan Oven.
     *
     * Dua-duanya di kategori "Suhu dan Kelembapan" yang sama, jadi `?category=`
     * nggak bisa memisahkan mereka — cuma profil yang bisa.
     */
    public function test_lembar_tits_nggak_memunculkan_alat_suhu_lain(): void
    {
        $teknisi = $this->teknisi();

        $this->alat($teknisi, 'Temperature Indicator tanpa Sensor', 'Graphtech GL840');
        $this->alat($teknisi, 'Oven', 'Memmert UF110');
        $this->alat($teknisi, 'Bath', 'Julabo CORIO');
        $this->alat($teknisi, 'Temperatur Indikator dengan Sensor', 'Fluke 1523');

        $muncul = $this->namaYangMuncul($teknisi, 'profil=tits');

        $this->assertSame(
            ['Graphtech GL840'],
            $muncul,
            "Kotak pilih alat lembar TITS menyodorkan alat suhu yang bukan miliknya.\n\n"
            .'Salah pilih di situ nggak bikin error: sesinya tersimpan, dan `untukAlat()` '
            .'menghitungnya pakai aturan alat lain.',
        );
    }

    /**
     * Alat yang namanya nggak byte-exact TETAP muncul.
     *
     * Ini yang menahan penyaringan berubah jadi `WHERE nama_alat_kemampuan = ?`.
     * Lihat docblock kelas — kegagalannya bikin teknisi menambah duplikat tanpa
     * baris CMC.
     */
    public function test_alat_berejaan_alias_tetap_muncul(): void
    {
        $teknisi = $this->teknisi();

        $this->alat($teknisi, 'Turbidimeter Hach', 'Hach 2100Q');
        $this->alat($teknisi, 'Oven', 'Memmert UF110');

        $muncul = $this->namaYangMuncul($teknisi, 'profil=turbidimeter');

        $this->assertSame(
            ['Hach 2100Q'],
            $muncul,
            'Alat yang nama kemampuannya "Turbidimeter Hach" nggak muncul di lembar turbidimeter. '
            .'Penyaringnya kemungkinan besar sudah jadi perbandingan teks — padahal `cocokkanNama()` '
            .'sengaja menerima kunci yang nempel di tengah nama.',
        );
    }

    /**
     * Kolom cadangan `nama_alat` ikut dibaca, sama seperti `untukAlat()`.
     *
     * Alat lama bisa punya `nama_alat_kemampuan` null. `untukAlat()` jatuh ke
     * `nama_alat`, jadi penyaring ini harus juga — kalau nggak, daftar dan
     * hitungan nggak sepakat soal alat yang sama.
     */
    public function test_nama_alat_dipakai_waktu_nama_kemampuan_kosong(): void
    {
        $teknisi = $this->teknisi();

        $this->alat($teknisi, null, 'Refractometer Atago');
        $this->alat($teknisi, null, 'Oven Memmert');

        $muncul = $this->namaYangMuncul($teknisi, 'profil=refractometer');

        $this->assertSame(['Refractometer Atago'], $muncul);
    }

    /**
     * Kode lembar yang salah ketik DITOLAK, bukan diam-diam memulangkan semua.
     *
     * `when()` yang jatuh ke "nggak nyaring apa-apa" bikin `?profil=tit`
     * memulangkan SELURUH alat lab — persis daftar tak tersaring yang mau
     * dihilangkan, tapi sekarang kelihatan seperti jawaban yang benar.
     */
    public function test_kode_lembar_ngaco_ditolak(): void
    {
        $teknisi = $this->teknisi();
        $this->alat($teknisi, 'Oven', 'Memmert UF110');

        $this->actingAs($teknisi)
            ->getJson('/api/equipments?profil=tit')
            ->assertStatus(422);
    }

    /**
     * Penyaringan TETAP terkunci organisasi.
     *
     * Penyaring baru gampang jadi pintu belakang: dia menyusun daftar nama
     * duluan, dan daftar yang nggak disaring per lab bikin `whereIn` memuat
     * nama milik lab lain. Alatnya sendiri tetap kesaring, tapi kalau `whereIn`
     * itu suatu saat dipakai tanpa penjaga organisasi, kebocorannya diam.
     */
    public function test_alat_lab_lain_nggak_ikut_muncul(): void
    {
        $labA = $this->teknisi();
        $labB = $this->teknisi();

        $this->alat($labA, 'Oven', 'Oven Lab A');
        $this->alat($labB, 'Oven', 'Oven Lab B');

        $this->assertSame(['Oven Lab A'], $this->namaYangMuncul($labA, 'profil=oven'));
        $this->assertSame(['Oven Lab B'], $this->namaYangMuncul($labB, 'profil=oven'));
    }

    /**
     * Tanpa `?profil=`, perilakunya persis seperti sebelum ini ada.
     *
     * Penyaring baru nggak boleh diam-diam jadi wajib — panel admin dan layar
     * daftar alat memanggil endpoint yang sama tanpa parameter itu.
     */
    public function test_tanpa_profil_semua_alat_tetap_muncul(): void
    {
        $teknisi = $this->teknisi();

        $this->alat($teknisi, 'Oven', 'Memmert UF110');
        $this->alat($teknisi, 'Temperature Indicator tanpa Sensor', 'Graphtech GL840');

        $this->assertCount(2, $this->namaYangMuncul($teknisi, 'status=aktif'));
    }
}
