<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `arsip:pindah` menyalin isi disk arsip ke disk lain dengan KUNCI YANG SAMA.
 *
 * Yang dijaga di sini satu hal, dan itu yang bikin perintahnya ada: kolom di
 * database (`pdf_path`, `tanda_tangan_path`, `path`) menyimpan KUNCI, bukan
 * URL. Begitu `ARSIP_DRIVER` digeser ke R2, aplikasi mencari kunci yang sama
 * persis di bucket. Kunci yang bergeser sedikit saja waktu disalin bikin
 * SELURUH berkas lama tidak ketemu — dan gejalanya identik dengan disk yang
 * kehapus: 404 di mana-mana, tanpa satu pun error yang menyebut sebabnya.
 */
class PindahArsipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('arsip');
        Storage::fake('s3');
    }

    private function isiArsip(): void
    {
        Storage::disk('arsip')->put('certificates/abc.pdf', '%PDF-1.7 isi sertifikat');
        Storage::disk('arsip')->put('tanda-tangan/1/ttd.png', 'png palsu');
        Storage::disk('arsip')->put('lembar/2026/07/scan.jpg', 'jpg palsu');
    }

    /** Coba kering nggak menyentuh apa pun. */
    public function test_coba_kering_nggak_menyalin(): void
    {
        $this->isiArsip();

        $this->artisan('arsip:pindah')
            ->expectsOutputToContain('[COBA KERING]')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    /** Kunci mendarat SAMA PERSIS — ini inti perintahnya. */
    public function test_kunci_disalin_apa_adanya(): void
    {
        $this->isiArsip();

        $this->artisan('arsip:pindah --jalankan')->assertSuccessful();

        $asal = Storage::disk('arsip')->allFiles();
        sort($asal);
        $tujuan = Storage::disk('s3')->allFiles();
        sort($tujuan);

        $this->assertSame($asal, $tujuan, 'Kunci di tujuan bergeser dari aslinya.');

        $this->assertSame(
            Storage::disk('arsip')->get('certificates/abc.pdf'),
            Storage::disk('s3')->get('certificates/abc.pdf'),
            'Isinya berubah waktu disalin.',
        );
    }

    /**
     * Berkas yang isinya beda di tujuan nggak ditimpa diam-diam — DAN nggak
     * bikin perintahnya bilang beres.
     *
     * Dua-duanya penting, dan yang kedua yang dulu bolong. Menjaga berkasnya
     * itu benar: perintah ini nggak boleh menghancurkan data yang nggak dia
     * kenal. Tapi menjaganya sambil keluar dengan kode 0 dan menutup dengan
     * "Sekarang aman menyetel ARSIP_DRIVER" itu bohong — dan bohongnya persis
     * di titik yang dipercaya operator buat memutuskan.
     */
    public function test_yang_sudah_ada_dilewat_bukan_ditimpa(): void
    {
        $this->isiArsip();
        Storage::disk('s3')->put('certificates/abc.pdf', 'versi lama yang nggak boleh hilang');

        $this->artisan('arsip:pindah --jalankan')->assertFailed();

        $this->assertSame(
            'versi lama yang nggak boleh hilang',
            Storage::disk('s3')->get('certificates/abc.pdf'),
        );

        // Yang belum ada tetap disalin.
        $this->assertTrue(Storage::disk('s3')->exists('tanda-tangan/1/ttd.png'));
    }

    /** `--timpa` memang menimpa, tapi harus diminta. */
    public function test_timpa_kalau_diminta(): void
    {
        $this->isiArsip();
        Storage::disk('s3')->put('certificates/abc.pdf', 'versi lama');

        $this->artisan('arsip:pindah --jalankan --timpa')->assertSuccessful();

        $this->assertSame(
            '%PDF-1.7 isi sertifikat',
            Storage::disk('s3')->get('certificates/abc.pdf'),
        );
    }

    /** Disk tujuan yang nggak ada ditolak sebelum menyentuh apa pun. */
    public function test_disk_tujuan_ngawur_ditolak(): void
    {
        $this->isiArsip();

        $this->artisan('arsip:pindah --tujuan=nggak-ada --jalankan')->assertFailed();
    }

    /** Menyalin ke disk arsip itu sendiri ditolak. */
    public function test_tujuan_arsip_sendiri_ditolak(): void
    {
        $this->artisan('arsip:pindah --tujuan=arsip --jalankan')->assertFailed();
    }

    /**
     * Pindah yang terputus di tengah harus KETAHUAN, bukan dilewat.
     *
     * Ini skenario yang wajar, bukan yang aneh: jaringan putus, proses kena
     * OOM, jatah 512 MB Render kehabisan. Yang ditinggalkan bukan berkas yang
     * HILANG, tapi berkas yang KEPOTONG.
     *
     * Dibaca sebagai "sudah ada, berarti sudah beres", jalan ulang bakal
     * melewatinya, melaporkan `0 gagal`, dan menutup dengan "Sekarang aman
     * menyetel ARSIP_DRIVER". Operator menggeser saklarnya sambil merasa sudah
     * memverifikasi, dan yang diunduh pelanggan PDF yang kepotong — persis
     * kerusakan yang perintah ini dibikin buat mencegah.
     *
     * Jadi yang bikin sebuah berkas boleh dilewat bukan keberadaannya, tapi
     * ukurannya yang sama dengan sumber.
     */
    public function test_berkas_tujuan_yang_kepotong_bikin_gagal(): void
    {
        $this->isiArsip();

        // Sisa pindah sebelumnya yang mati di tengah.
        Storage::disk('s3')->put('certificates/abc.pdf', '%PDF-1.7 is');

        $this->artisan('arsip:pindah --jalankan')
            ->expectsOutputToContain('BENTROK: certificates/abc.pdf')
            ->assertFailed();

        // Nggak dihancurkan diam-diam: operator yang memutuskan.
        $this->assertSame('%PDF-1.7 is', Storage::disk('s3')->get('certificates/abc.pdf'));
    }

    /** Sesudah bentroknya diperiksa, `--timpa` yang membereskan. */
    public function test_timpa_membereskan_berkas_yang_kepotong(): void
    {
        $this->isiArsip();
        Storage::disk('s3')->put('certificates/abc.pdf', '%PDF-1.7 is');

        $this->artisan('arsip:pindah --jalankan --timpa')->assertSuccessful();

        $this->assertSame(
            Storage::disk('arsip')->get('certificates/abc.pdf'),
            Storage::disk('s3')->get('certificates/abc.pdf'),
        );
    }

    /** Berkas yang sudah sama persis tetap dilewat — jalan ulang nggak mahal. */
    public function test_berkas_tujuan_yang_sudah_sama_tetap_dilewat(): void
    {
        $this->isiArsip();

        $this->artisan('arsip:pindah --jalankan')->assertSuccessful();

        $this->artisan('arsip:pindah --jalankan')
            ->expectsOutputToContain('lewat (sudah sama): certificates/abc.pdf')
            ->assertSuccessful();
    }
}
