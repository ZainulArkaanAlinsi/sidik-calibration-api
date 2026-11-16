<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /api/pelanggan/v1/app/info` — layar Bantuan aplikasi pelanggan.
 *
 * Aplikasi memanggilnya (`InfoAplikasi.dariJson`) dan sebelum endpoint ini ada,
 * Bantuan selalu galat. Yang dijaga di sini: publik tanpa token, di luar gerbang
 * fitur, nol query, nilai env tercermin, dan — paling penting — yang belum diisi
 * dijawab `null` (aplikasi menyembunyikan tombolnya), bukan "" atau galat.
 */
class InfoAplikasiPelangganTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/pelanggan/v1/app/info';

    /** Kunci `config('pelanggan.info')` → nama env-nya. */
    private const ENV = [
        'nama_organisasi' => 'PELANGGAN_INFO_NAMA_ORGANISASI',
        'alamat_organisasi' => 'PELANGGAN_INFO_ALAMAT_ORGANISASI',
        'telepon_organisasi' => 'PELANGGAN_INFO_TELEPON_ORGANISASI',
        'whatsapp' => 'PELANGGAN_INFO_WHATSAPP',
        'whatsapp_url' => 'PELANGGAN_INFO_WHATSAPP_URL',
        'email' => 'PELANGGAN_INFO_EMAIL',
        'jam_layanan' => 'PELANGGAN_INFO_JAM_LAYANAN',
        'kebijakan_privasi_url' => 'PELANGGAN_INFO_KEBIJAKAN_PRIVASI_URL',
        'syarat_url' => 'PELANGGAN_INFO_SYARAT_URL',
        'hapus_akun_url' => 'PELANGGAN_INFO_HAPUS_AKUN_URL',
        'petunjuk_pengemasan' => 'PELANGGAN_INFO_PETUNJUK_PENGEMASAN',
    ];

    /** Kunci `config('pelanggan.info')` tanpa env (tautan sekunder situs resmi; berubah = ikut deploy). */
    private const TETAP = ['email_pemasaran', 'instagram_url', 'linkedin_url', 'ruang_lingkup_url'];

    /**
     * Bawaan kontak resmi (17 Nov 2026), disalin PERSIS dari situs
     * https://pt-sidik.com/calibration/ atas perintah pemilik. Env menimpa.
     * Yang tidak tertulis di situs (`jam_layanan`) TIDAK ada di sini: tetap null.
     */
    private const BAWAAN_RESMI = [
        'nama_organisasi' => 'PT Sistem Dirgantara Inovasi Teknologi (SIDIK)',
        'alamat_organisasi' => 'Komplek Nuansa Mas Estate, Blk. E No.2, Cipamokolan, Rancasari, Bandung 40292',
        'telepon_organisasi' => '082260060037',
        'whatsapp' => '+62 822-6006-0037',
        'whatsapp_url' => 'https://wa.me/6282260060037',
        'email' => 'sidikkalibrasi@pt-sidik.com',
    ];

    private const TETAP_RESMI = [
        'email_pemasaran' => 'marketingkalibrasi@pt-sidik.com',
        'instagram_url' => 'https://www.instagram.com/ptsidikbdg/',
        'linkedin_url' => 'https://www.linkedin.com/company/pt-sistem-dirgantara-inovasi-teknologi-sidik',
        'ruang_lingkup_url' => 'https://pt-sidik.com/wp-content/uploads/2026/11/Ruang-Lingkup-SIDIK-ILK-285-IDN-L-Amd-1-30Sep2026.pdf',
    ];

    /** @param array<string, ?string> $isi */
    private function isiInfo(array $isi = []): void
    {
        config(['pelanggan.info' => $isi + array_fill_keys([...array_keys(self::ENV), ...self::TETAP], null)]);
    }

    public function test_semua_kosong_dijawab_null_bukan_galat_bukan_string_kosong(): void
    {
        $this->isiInfo();

        $respons = $this->getJson(self::URI)->assertOk();

        $this->assertSame([
            'organisasi' => ['nama' => null, 'alamat' => null, 'telepon' => null],
            'kontak' => [
                'whatsapp' => null,
                'whatsapp_url' => null,
                'email' => null,
                'jam_layanan' => null,
                'email_pemasaran' => null,
                'instagram_url' => null,
                'linkedin_url' => null,
            ],
            'dokumen' => [
                'kebijakan_privasi' => ['url' => null, 'versi' => null],
                'syarat_ketentuan' => ['url' => null, 'versi' => null],
                'hapus_akun_url' => null,
                'ruang_lingkup_url' => null,
            ],
            'petunjuk_pengemasan' => null,
        ], $respons->json('data'));
    }

    public function test_tautan_sekunder_situs_resmi_tercermin(): void
    {
        $this->isiInfo([
            'email_pemasaran' => 'pemasaran@contoh.test',
            'instagram_url' => 'https://contoh.test/ig',
            'linkedin_url' => 'https://contoh.test/in',
            'ruang_lingkup_url' => 'https://contoh.test/ruang-lingkup.pdf',
        ]);

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.kontak.email_pemasaran', 'pemasaran@contoh.test')
            ->assertJsonPath('data.kontak.instagram_url', 'https://contoh.test/ig')
            ->assertJsonPath('data.kontak.linkedin_url', 'https://contoh.test/in')
            ->assertJsonPath('data.dokumen.ruang_lingkup_url', 'https://contoh.test/ruang-lingkup.pdf');
    }

    public function test_nilai_config_tercermin_ke_field_yang_benar(): void
    {
        $this->isiInfo([
            'nama_organisasi' => 'PT Contoh Kalibrasi',
            'alamat_organisasi' => 'Jl. Contoh No. 1, Kota Contoh',
            'telepon_organisasi' => '021-5550100',
            'whatsapp' => '+62 812-0000-0000',
            'whatsapp_url' => 'https://wa.me/628120000000',
            'email' => 'lab@contoh.test',
            'jam_layanan' => 'Senin-Jumat 08.00-16.00 WIB',
            'kebijakan_privasi_url' => 'https://contoh.test/privasi',
            'syarat_url' => 'https://contoh.test/syarat',
            'hapus_akun_url' => 'https://contoh.test/hapus-akun',
            'petunjuk_pengemasan' => 'Bungkus dengan bubble wrap.',
        ]);
        config(['pelanggan.versi_dokumen' => ['kebijakan_privasi' => '2.1', 'syarat_ketentuan' => '3.0']]);

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.organisasi.nama', 'PT Contoh Kalibrasi')
            ->assertJsonPath('data.organisasi.alamat', 'Jl. Contoh No. 1, Kota Contoh')
            ->assertJsonPath('data.organisasi.telepon', '021-5550100')
            ->assertJsonPath('data.kontak.whatsapp', '+62 812-0000-0000')
            ->assertJsonPath('data.kontak.whatsapp_url', 'https://wa.me/628120000000')
            ->assertJsonPath('data.kontak.email', 'lab@contoh.test')
            ->assertJsonPath('data.kontak.jam_layanan', 'Senin-Jumat 08.00-16.00 WIB')
            ->assertJsonPath('data.dokumen.kebijakan_privasi.url', 'https://contoh.test/privasi')
            ->assertJsonPath('data.dokumen.kebijakan_privasi.versi', '2.1')
            ->assertJsonPath('data.dokumen.syarat_ketentuan.url', 'https://contoh.test/syarat')
            ->assertJsonPath('data.dokumen.syarat_ketentuan.versi', '3.0')
            ->assertJsonPath('data.dokumen.hapus_akun_url', 'https://contoh.test/hapus-akun')
            ->assertJsonPath('data.petunjuk_pengemasan', 'Bungkus dengan bubble wrap.');
    }

    /** Env yang disetel kosong di dashboard ("" atau spasi) = belum diisi = null. */
    public function test_string_kosong_dan_spasi_dibaca_null(): void
    {
        $this->isiInfo(['email' => '', 'whatsapp' => '   ', 'jam_layanan' => "\n", 'kebijakan_privasi_url' => '']);

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.kontak.email', null)
            ->assertJsonPath('data.kontak.whatsapp', null)
            ->assertJsonPath('data.kontak.jam_layanan', null)
            ->assertJsonPath('data.dokumen.kebijakan_privasi.url', null);
    }

    /** Server tidak mengarang tautan: `whatsapp_url` tidak diturunkan dari nomornya. */
    public function test_whatsapp_url_tidak_diturunkan_dari_nomor(): void
    {
        $this->isiInfo(['whatsapp' => '+62 812-0000-0000']);

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.kontak.whatsapp', '+62 812-0000-0000')
            ->assertJsonPath('data.kontak.whatsapp_url', null);
    }

    /** "Versi 1.0" untuk dokumen yang tidak bisa dibuka menyesatkan — versi menyertai URL. */
    public function test_versi_dokumen_hanya_tampil_bila_url_terisi(): void
    {
        $this->isiInfo(['syarat_url' => 'https://contoh.test/syarat']);
        config(['pelanggan.versi_dokumen' => ['kebijakan_privasi' => '1.0', 'syarat_ketentuan' => '1.4']]);

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.dokumen.kebijakan_privasi.url', null)
            ->assertJsonPath('data.dokumen.kebijakan_privasi.versi', null)
            ->assertJsonPath('data.dokumen.syarat_ketentuan.versi', '1.4');
    }

    public function test_publik_tanpa_token_dan_tetap_jalan_walau_fitur_pelanggan_mati(): void
    {
        $this->isiInfo(['email' => 'lab@contoh.test']);

        foreach ([false, true] as $fitur) {
            config(['pelanggan.fitur' => $fitur]);

            // Tanpa header Authorization sama sekali.
            $this->getJson(self::URI)
                ->assertOk()
                ->assertJsonPath('data.kontak.email', 'lab@contoh.test');
        }
    }

    public function test_tidak_menyentuh_database(): void
    {
        DB::enableQueryLog();

        $this->getJson(self::URI)->assertOk();

        $query = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $query, 'app/info menembak query database: '.implode(' | ', array_column($query, 'query')));
    }

    /**
     * Default config: HANYA kontak resmi yang disalin persis dari situs
     * pt-sidik.com ([BAWAAN_RESMI] / [TETAP_RESMI]); semua lain (jam layanan,
     * dokumen hukum, petunjuk pengemasan, hapus akun) tetap null — bukan nilai
     * tebakan. Tiap env terdaftar di `.env.example` DAN `render.yaml` (AGENTS.md:
     * key baru ikut ke keduanya). Dibaca dari teks berkasnya, supaya hijau/merah
     * tidak bergantung `.env` mesin yang menjalankan test.
     */
    public function test_default_config_null_dan_env_terdaftar_di_contoh_dan_blueprint(): void
    {
        $config = (string) file_get_contents(config_path('pelanggan.php'));
        $contoh = (string) file_get_contents(base_path('.env.example'));
        $blueprint = (string) file_get_contents(base_path('render.yaml'));

        foreach (self::ENV as $kunci => $env) {
            if (array_key_exists($kunci, self::BAWAAN_RESMI)) {
                $this->assertStringContainsString(
                    "'{$kunci}' => env('{$env}', '".self::BAWAAN_RESMI[$kunci]."'),",
                    $config,
                    "config/pelanggan.php: bawaan `{$kunci}` harus PERSIS data situs resmi.",
                );
            } else {
                $this->assertMatchesRegularExpression(
                    "/'{$kunci}' => env\\('{$env}'\\),/",
                    $config,
                    "config/pelanggan.php: `{$kunci}` harus env('{$env}') TANPA nilai bawaan (default null).",
                );
            }
            $this->assertMatchesRegularExpression("/^{$env}=\$/m", $contoh, "{$env} belum ada (kosong) di .env.example.");
            $this->assertMatchesRegularExpression(
                "/- key: {$env}\\R\\s+sync: false/",
                $blueprint,
                "{$env} belum ada di render.yaml sebagai `sync: false`.",
            );
        }

        foreach (self::TETAP_RESMI as $kunci => $nilai) {
            $this->assertStringContainsString(
                "'{$kunci}' => '{$nilai}',",
                $config,
                "config/pelanggan.php: `{$kunci}` harus PERSIS tautan situs resmi.",
            );
        }
    }

    /**
     * Salah ketik satu angka di nomor = pelanggan menelepon orang lain. Tiga
     * penulisan nomor yang sama (telepon, tampilan WhatsApp, tautan wa.me) harus
     * mengarah ke nomor yang SAMA, dan semua tautan memakai https.
     */
    public function test_bawaan_resmi_konsisten_satu_nomor_dan_tautan_aman(): void
    {
        $telepon = preg_replace('/\D/', '', self::BAWAAN_RESMI['telepon_organisasi']);
        $wa = preg_replace('/\D/', '', self::BAWAAN_RESMI['whatsapp']);

        $this->assertStringStartsWith('0', $telepon);
        $this->assertSame('62'.substr($telepon, 1), $wa, 'Tampilan WhatsApp beda nomor dari telepon.');
        $this->assertSame('https://wa.me/'.$wa, self::BAWAAN_RESMI['whatsapp_url'], 'Tautan wa.me beda nomor dari tampilan.');
        $this->assertMatchesRegularExpression('/^[a-z0-9._-]+@pt-sidik\.com$/', self::BAWAAN_RESMI['email']);
        $this->assertMatchesRegularExpression('/^[a-z0-9._-]+@pt-sidik\.com$/', self::TETAP_RESMI['email_pemasaran']);

        foreach ([self::BAWAAN_RESMI['whatsapp_url'], self::TETAP_RESMI['instagram_url'], self::TETAP_RESMI['linkedin_url'], self::TETAP_RESMI['ruang_lingkup_url']] as $tautan) {
            $this->assertStringStartsWith('https://', $tautan);
        }
    }

    /** Tanpa env sama sekali, yang tertulis di config adalah data situs resmi — kecuali yang memang tidak tertulis di sana. */
    public function test_tanpa_env_jam_layanan_dan_dokumen_hukum_tetap_null(): void
    {
        $config = (string) file_get_contents(config_path('pelanggan.php'));

        foreach (['jam_layanan', 'kebijakan_privasi_url', 'syarat_url', 'hapus_akun_url', 'petunjuk_pengemasan'] as $kunci) {
            $this->assertMatchesRegularExpression("/'{$kunci}' => env\\('[A-Z_]+'\\),/", $config, "`{$kunci}` tidak boleh punya bawaan (tidak tertulis di situs resmi).");
        }
    }
}
