<?php

namespace Tests\Unit;

use App\Support\ImporPelanggan\BacaBerkas;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pembaca CSV impor pelanggan.
 *
 * Yang dijaga di sini semuanya kelas kegagalan yang **tidak melempar error**:
 * berkas terbaca, perintahnya bilang sukses, dan yang lahir 300 pelanggan
 * bernama sampah. Tidak ada satu pun test di sini yang menjaga "kode meledak
 * di tempat yang benar" — yang dijaga "kode tidak diam waktu salah".
 */
class BacaBerkasImporPelangganTest extends TestCase
{
    /** @var list<string> */
    private array $sampah = [];

    protected function tearDown(): void
    {
        foreach ($this->sampah as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function berkas(string $isi): string
    {
        $path = tempnam(sys_get_temp_dir(), 'impor').'.csv';
        file_put_contents($path, $isi);
        $this->sampah[] = $path;

        return $path;
    }

    public function test_pemisah_titik_koma_dikenali(): void
    {
        // Excel berlokal Indonesia menulis begini. Dibaca dengan `,`, seluruh
        // berkas jadi satu kolom dan tiap nama berisi seluruh barisnya.
        $hasil = BacaBerkas::baca($this->berkas("nama;alamat\nPT Maju Jaya;Jl. Industri 5\n"));

        $this->assertSame(';', $hasil['pemisah']);
        $this->assertCount(1, $hasil['baris']);
        $this->assertSame('PT Maju Jaya', $hasil['baris'][0]->nama);
        $this->assertSame('Jl. Industri 5', $hasil['baris'][0]->alamat);
    }

    public function test_pemisah_ditebak_dari_header_bukan_dari_alamat_penuh_koma(): void
    {
        // Ini yang bikin penebakan naif salah: alamatnya punya 3 koma, jadi
        // di seluruh berkas `,` menang telak walau pemisah sebenarnya `;`.
        $isi = "nama;alamat\n"
            ."PT Maju Jaya;Jl. Raya No. 5, Kawasan Industri, Cikarang, Bekasi\n"
            ."PT Sinar Abadi;Jl. Melati No. 1, Blok C, Bandung\n";

        $hasil = BacaBerkas::baca($this->berkas($isi));

        $this->assertSame(';', $hasil['pemisah']);
        $this->assertSame('Jl. Raya No. 5, Kawasan Industri, Cikarang, Bekasi', $hasil['baris'][0]->alamat);
    }

    public function test_alamat_berakhir_backslash_tidak_melebur_dengan_kolom_sesudahnya(): void
    {
        // Dengan escape `\` bawaan lama PHP, tanda kutip penutup alamat ini
        // ketelan, lalu alamat + kolom sesudahnya MELEBUR jadi satu nilai dan
        // seluruh kolom setelahnya bergeser. Tidak ada error sama sekali —
        // yang tersimpan cuma alamat aneh dan satu kolom yang hilang.
        $isi = "nama,alamat,email\n\"PT Maju\",\"Jl. Raya Blok C\\\",\"info@maju.co.id\"\n";

        $hasil = BacaBerkas::baca($this->berkas($isi));
        $baris = $hasil['baris'][0];

        $this->assertSame('PT Maju', $baris->nama);
        $this->assertSame('Jl. Raya Blok C\\', $baris->alamat);
        $this->assertSame('info@maju.co.id', $baris->email);
    }

    public function test_bom_dibuang_sehingga_kolom_nama_tetap_dikenali(): void
    {
        $hasil = BacaBerkas::baca($this->berkas("\xEF\xBB\xBFnama,alamat\nPT Maju,Jl. A\n"));

        $this->assertSame('PT Maju', $hasil['baris'][0]->nama);
    }

    public function test_berkas_windows_1252_dikonversi_ke_utf8(): void
    {
        $isi = "nama,alamat\nPT Maju,".mb_convert_encoding('Jl. Café No. 3', 'Windows-1252', 'UTF-8')."\n";

        $hasil = BacaBerkas::baca($this->berkas($isi));

        $this->assertSame('Jl. Café No. 3', $hasil['baris'][0]->alamat);
        $this->assertTrue(mb_check_encoding($hasil['baris'][0]->alamat ?? '', 'UTF-8'));
    }

    public function test_header_sinonim_dikenali(): void
    {
        $isi = "Nama PT.,Alamat Perusahaan,No. Telp,E-Mail,PIC\n"
            ."PT Maju,Jl. A,021-555,info@maju.co.id,Budi\n";

        $hasil = BacaBerkas::baca($this->berkas($isi));
        $baris = $hasil['baris'][0];

        $this->assertSame('PT Maju', $baris->nama);
        $this->assertSame('Jl. A', $baris->alamat);
        $this->assertSame('021-555', $baris->telepon);
        $this->assertSame('info@maju.co.id', $baris->email);
        $this->assertSame('Budi', $baris->contactPerson);
    }

    public function test_berkas_tanpa_kolom_nama_ditolak_keras(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak punya kolom nama/');

        BacaBerkas::baca($this->berkas("kode,alamat\nA1,Jl. A\n"));
    }

    public function test_telepon_notasi_ilmiah_dikosongkan_bukan_disimpan(): void
    {
        // Excel memperlakukan 081234567890 sebagai angka. Yang tersimpan
        // kelihatan wajar di kolom sempit, tapi tidak ada nomor di baliknya.
        $hasil = BacaBerkas::baca($this->berkas("nama,telepon\nPT Maju,8.1234567890E+11\n"));
        $baris = $hasil['baris'][0];

        $this->assertNull($baris->telepon);
        $this->assertNotEmpty($baris->peringatan);
        $this->assertStringContainsString('8.1234567890E+11', $baris->peringatan[0]);
    }

    public function test_email_tidak_sah_dikosongkan(): void
    {
        $hasil = BacaBerkas::baca($this->berkas("nama,email\nPT Maju,bukan email\n"));

        $this->assertNull($hasil['baris'][0]->email);
        $this->assertNotEmpty($hasil['baris'][0]->peringatan);
    }

    public function test_spasi_tanpa_putus_dirapikan(): void
    {
        // `\xC2\xA0` lolos `trim()` biasa, jadi nama yang KELIHATAN sama persis
        // punya `nama_normal` berbeda dan penjaga kembarnya diam.
        $hasil = BacaBerkas::baca($this->berkas("nama\n\xC2\xA0PT Maju\xC2\xA0Jaya\xC2\xA0\n"));

        $this->assertSame('PT Maju Jaya', $hasil['baris'][0]->nama);
    }

    public function test_baris_tanpa_nama_ditolak_bukan_diimpor(): void
    {
        $hasil = BacaBerkas::baca($this->berkas("nama,alamat\n,Jl. A\nPT Maju,Jl. B\n"));

        $this->assertCount(1, $hasil['baris']);
        $this->assertCount(1, $hasil['ditolak']);
        $this->assertSame(2, $hasil['ditolak'][0]['baris']);
    }

    public function test_baris_kosong_dilewati_tanpa_jadi_sampah_laporan(): void
    {
        $hasil = BacaBerkas::baca($this->berkas("nama\nPT Maju\n\n\n"));

        $this->assertCount(1, $hasil['baris']);
        $this->assertSame([], $hasil['ditolak']);
    }

    public function test_alamat_kepanjangan_dikosongkan_bukan_dipotong(): void
    {
        // Alamat yang dipotong di huruf ke-255 tetap kelihatan lengkap. Itu
        // bentuk salah yang paling sulit ketahuan.
        $panjang = str_repeat('A', 300);
        $hasil = BacaBerkas::baca($this->berkas("nama,alamat\nPT Maju,{$panjang}\n"));

        $this->assertNull($hasil['baris'][0]->alamat);
        $this->assertStringContainsString('dikosongkan', implode(' ', $hasil['baris'][0]->peringatan));
    }

    public function test_nama_kepanjangan_menolak_barisnya(): void
    {
        $hasil = BacaBerkas::baca($this->berkas("nama\n".str_repeat('B', 300)."\n"));

        $this->assertSame([], $hasil['baris']);
        $this->assertCount(1, $hasil['ditolak']);
    }

    public function test_satu_kolom_tanpa_pemisah_tetap_sah(): void
    {
        $hasil = BacaBerkas::baca($this->berkas("nama\nPT Maju\nPT Sinar\n"));

        $this->assertCount(2, $hasil['baris']);
        $this->assertNull($hasil['baris'][0]->alamat);
    }

    public function test_nomor_baris_menunjuk_ke_berkas_bukan_indeks_array(): void
    {
        // Laporan yang bilang "baris 0" tidak bisa dicari di Excel.
        $hasil = BacaBerkas::baca($this->berkas("nama\nPT A\nPT B\n"));

        $this->assertSame(2, $hasil['baris'][0]->nomorBaris);
        $this->assertSame(3, $hasil['baris'][1]->nomorBaris);
    }
}
