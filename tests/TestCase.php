<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;

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
    }
}
