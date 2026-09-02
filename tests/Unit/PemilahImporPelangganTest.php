<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Support\ImporPelanggan\BarisMasukan;
use App\Support\ImporPelanggan\Pemilah;
use PHPUnit\Framework\TestCase;

/**
 * Pemilah kembar impor pelanggan.
 *
 * Dua arah salahnya tidak setara, dan test di sini menjaga dua-duanya:
 *
 *   - **Kembar yang lolos jadi baris baru** → lab bangun dua folder arsip untuk
 *     satu PT, dan riwayat kalibrasinya terbelah. Ketahuan berbulan kemudian.
 *   - **Badan hukum berbeda yang digabung** → sertifikat mendarat ke perusahaan
 *     yang salah. `PT Maju` dan `CV Maju` punya NPWP berbeda.
 *
 * Yang kedua lebih mahal, dan justru itu yang paling gampang terjadi: jarak
 * `pt maju` ke `cv maju` cuma 2, jadi tanpa penjaga khusus keduanya muncul
 * berpasangan di layar tinjauan sebagai "nyaris sama".
 */
class PemilahImporPelangganTest extends TestCase
{
    /** @return list<array{id: int, nama: string, nama_normal: string}> */
    private function sudahAda(string ...$nama): array
    {
        return array_map(
            static fn (string $n, int $i) => [
                'id' => $i + 1,
                'nama' => $n,
                'nama_normal' => Customer::normalkanNama($n),
            ],
            $nama,
            array_keys($nama),
        );
    }

    /** @return list<BarisMasukan> */
    private function baris(string ...$nama): array
    {
        return array_map(
            static fn (string $n, int $i) => new BarisMasukan(nomorBaris: $i + 2, nama: $n),
            $nama,
            array_keys($nama),
        );
    }

    public function test_nama_persis_sama_jadi_kembar_pasti(): void
    {
        $hasil = Pemilah::pilah($this->baris('PT Maju Jaya'), $this->sudahAda('PT Maju Jaya'));

        $this->assertSame([], $hasil['baru']);
        $this->assertCount(1, $hasil['kembar_pasti']);
        $this->assertStringContainsString('persis sama', $hasil['kembar_pasti'][0]['sebab']);
    }

    public function test_beda_tanda_baca_saja_jadi_kembar_pasti(): void
    {
        // Unique index database jalan di teks MENTAH, jadi ini lolos ke sana.
        // Yang menahannya cuma `nama_normal`, dan itu penjagaan aplikasi.
        $hasil = Pemilah::pilah($this->baris('PT. Maju  Jaya'), $this->sudahAda('PT Maju Jaya'));

        $this->assertSame([], $hasil['baru']);
        $this->assertCount(1, $hasil['kembar_pasti']);
        $this->assertSame('PT Maju Jaya', $hasil['kembar_pasti'][0]['lawan']);
    }

    public function test_pt_dan_cv_dengan_nama_sama_tidak_pernah_dianggap_mirip(): void
    {
        // Jaraknya 2 — persis di ambang. Tanpa penjaga badan usaha, ini masuk
        // keranjang tinjau, dan tinjauan yang menampilkan dua nama nyaris sama
        // itu yang paling gampang di-"gabung saja".
        $hasil = Pemilah::pilah($this->baris('CV Maju Jaya'), $this->sudahAda('PT Maju Jaya'));

        $this->assertCount(1, $hasil['baru']);
        $this->assertSame([], $hasil['perlu_tinjau']);
        $this->assertSame([], $hasil['kembar_pasti']);
    }

    public function test_ud_dan_pd_dengan_nama_sama_juga_tidak_digabung(): void
    {
        // Jaraknya cuma 1 — lebih rawan lagi dari PT/CV.
        $hasil = Pemilah::pilah($this->baris('PD Sumber Rejeki'), $this->sudahAda('UD Sumber Rejeki'));

        $this->assertCount(1, $hasil['baru']);
        $this->assertSame([], $hasil['perlu_tinjau']);
    }

    public function test_nama_mirip_satu_huruf_masuk_keranjang_tinjau(): void
    {
        $hasil = Pemilah::pilah($this->baris('PT Maju Raya'), $this->sudahAda('PT Maju Jaya'));

        $this->assertSame([], $hasil['baru']);
        $this->assertCount(1, $hasil['perlu_tinjau']);
        $this->assertStringContainsString('jarak 1', $hasil['perlu_tinjau'][0]['sebab']);
    }

    public function test_nama_yang_cukup_beda_lolos_jadi_baru(): void
    {
        $hasil = Pemilah::pilah($this->baris('PT Sinar Abadi'), $this->sudahAda('PT Maju Jaya'));

        $this->assertCount(1, $hasil['baru']);
        $this->assertSame([], $hasil['perlu_tinjau']);
    }

    public function test_kembar_di_dalam_berkas_yang_sama_ikut_ketahan(): void
    {
        // Tanpa ini satu berkas yang memuat PT yang sama dua kali menabrak
        // unique index di TENGAH jalan — sebagian masuk, sebagian tidak.
        $hasil = Pemilah::pilah($this->baris('PT Maju Jaya', 'PT. Maju Jaya'), []);

        $this->assertCount(1, $hasil['baru']);
        $this->assertCount(1, $hasil['kembar_pasti']);
        $this->assertStringContainsString('berkas ini', $hasil['kembar_pasti'][0]['sebab']);
    }

    public function test_mirip_di_dalam_berkas_yang_sama_juga_ketahan(): void
    {
        $hasil = Pemilah::pilah($this->baris('PT Maju Jaya', 'PT Maju Raya'), []);

        $this->assertCount(1, $hasil['baru']);
        $this->assertCount(1, $hasil['perlu_tinjau']);
    }

    public function test_nama_sangat_panjang_tidak_bikin_semuanya_mirip(): void
    {
        // `levenshtein()` PHP menyerah di atas 255 byte dan mengembalikan -1.
        // Dan -1 <= 2 itu BENAR — tanpa penjaga, tiap nama panjang jadi mirip
        // dengan tiap nama panjang lain, dan semuanya berhenti di tinjauan.
        $a = 'PT '.str_repeat('A', 250);
        $b = 'PT '.str_repeat('B', 250);

        $hasil = Pemilah::pilah($this->baris($b), $this->sudahAda($a));

        $this->assertCount(1, $hasil['baru']);
        $this->assertSame([], $hasil['perlu_tinjau']);
    }

    public function test_nama_tanpa_bentuk_badan_usaha_tetap_bisa_dibandingkan(): void
    {
        $hasil = Pemilah::pilah($this->baris('Maju Raya'), $this->sudahAda('Maju Jaya'));

        $this->assertCount(1, $hasil['perlu_tinjau']);
    }

    public function test_berkas_kosong_menghasilkan_tiga_keranjang_kosong(): void
    {
        $hasil = Pemilah::pilah([], $this->sudahAda('PT Maju Jaya'));

        $this->assertSame(['baru' => [], 'kembar_pasti' => [], 'perlu_tinjau' => []], $hasil);
    }
}
