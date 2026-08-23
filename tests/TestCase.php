<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Alamat penyedia AI dipatok ke alamat resminya, apa pun isi shell-nya.
        //
        // `env()` bukan cuma baca `.env`: variabel yang udah ada di lingkungan
        // shell menang duluan. Sebagian alat baris perintah (termasuk CLI
        // Claude) nyetel `ANTHROPIC_BASE_URL` ke proxy lokal
        // `http://127.0.0.1:8787` buat dirinya sendiri, dan begitu test
        // dijalanin dari terminal yang sama, `Http::fake(['api.anthropic.com/*'
        // => ...])` nggak kena — yang ditembak host lain, beneran lewat
        // jaringan.
        //
        // Gejalanya nyesatin total: sepuluh test di WorksheetExtractionTest
        // gagal 422 "Layanan AI menolak permintaan", persis kayak ada logika
        // penolakan yang rusak, padahal yang kejadian cuma permintaannya lolos
        // dari fake. Dan gagalnya cuma di mesin yang kebetulan punya variabel
        // itu, jadi orang berikutnya bakal ngeliat suite hijau dan mengira
        // temuan sebelumnya salah.
        //
        // Ditaruh di `Config`, bukan di `<env>` phpunit.xml, karena `<env>`
        // nggak bisa ngalahin nilai yang udah nangkring di `$_SERVER`.
        Config::set('services.anthropic.base_url', 'https://api.anthropic.com');
        Config::set('services.gemini.base_url', 'https://generativelanguage.googleapis.com');

        // Disk `local` dipalsuin buat SEMUA test, bukan per berkas.
        //
        // phpunit.xml nyetel `QUEUE_CONNECTION=sync`, jadi `GenerateCertificate`
        // jalan langsung di tengah test dan nulis PDF beneran ke
        // `storage/app/private/certificates/`. Nama berkasnya `qr_token` acak,
        // jadi nggak ada yang ketimpa — tiap kali suite jalan, folder itu numpuk
        // ~99 PDF (@1,3 MB) dan nggak pernah dibersihin. Kekumpul 8.647 berkas /
        // 11 GB sebelum ketahuan.
        //
        // Empat belas berkas test kena, sebagian udah manggil
        // `Storage::fake('local')` sendiri dan sebagian belum. Nambal satu-satu
        // cuma nunda masalahnya: test sertifikat berikutnya yang ditulis orang
        // lain bakal bocor lagi kecuali dia inget manggil fake-nya. Ditaruh di
        // sini biar bocornya nggak bisa balik.
        //
        // `Storage::fake()` ngarahin disk ke folder sementara dan ngosongin
        // isinya tiap test, jadi test yang emang baca-balik PDF-nya tetap jalan
        // — yang beda cuma tempat nulisnya.
        Storage::fake('local');

        // Disk `arsip` ikut dipalsuin, bukan cuma `local`.
        //
        // Di produksi dua-duanya bisa nunjuk ke tempat yang sama (selama
        // ARSIP_DRIVER masih `local`), tapi `Storage::fake()` NGGAK begitu: dia
        // ngeganti disk yang disebut dengan folder sementara sendiri. Kelewat
        // satu, berkas yang ditulis test bocor ke storage/app/private beneran —
        // persis kebocoran yang komentar di atas ada buat mencegahnya.
        Storage::fake('arsip');
    }
}
