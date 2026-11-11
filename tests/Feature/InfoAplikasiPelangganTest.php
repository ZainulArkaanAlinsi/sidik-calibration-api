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

    /** @param array<string, ?string> $isi */
    private function isiInfo(array $isi = []): void
    {
        config(['pelanggan.info' => $isi + array_fill_keys(array_keys(self::ENV), null)]);
    }

    public function test_semua_kosong_dijawab_null_bukan_galat_bukan_string_kosong(): void
    {
        $this->isiInfo();

        $respons = $this->getJson(self::URI)->assertOk();

        $this->assertSame([
            'organisasi' => ['nama' => null, 'alamat' => null, 'telepon' => null],
            'kontak' => ['whatsapp' => null, 'whatsapp_url' => null, 'email' => null, 'jam_layanan' => null],
            'dokumen' => [
                'kebijakan_privasi' => ['url' => null, 'versi' => null],
                'syarat_ketentuan' => ['url' => null, 'versi' => null],
                'hapus_akun_url' => null,
            ],
            'petunjuk_pengemasan' => null,
        ], $respons->json('data'));
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
     * Default config HARUS null (bukan nilai tebakan) dan tiap env terdaftar di
     * `.env.example` DAN `render.yaml` (AGENTS.md: key baru ikut ke keduanya).
     * Dibaca dari teks berkasnya, supaya hijau/merah tidak bergantung `.env`
     * mesin yang menjalankan test.
     */
    public function test_default_config_null_dan_env_terdaftar_di_contoh_dan_blueprint(): void
    {
        $config = (string) file_get_contents(config_path('pelanggan.php'));
        $contoh = (string) file_get_contents(base_path('.env.example'));
        $blueprint = (string) file_get_contents(base_path('render.yaml'));

        foreach (self::ENV as $kunci => $env) {
            $this->assertMatchesRegularExpression(
                "/'{$kunci}' => env\\('{$env}'\\),/",
                $config,
                "config/pelanggan.php: `{$kunci}` harus env('{$env}') TANPA nilai bawaan (default null).",
            );
            $this->assertMatchesRegularExpression("/^{$env}=\$/m", $contoh, "{$env} belum ada (kosong) di .env.example.");
            $this->assertMatchesRegularExpression(
                "/- key: {$env}\\R\\s+sync: false/",
                $blueprint,
                "{$env} belum ada di render.yaml sebagai `sync: false`.",
            );
        }
    }
}
