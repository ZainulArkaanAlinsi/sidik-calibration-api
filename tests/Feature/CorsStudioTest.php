<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Studio Tahap 1 (25 Nov 2026), keputusan D6: CORS hanya mengizinkan klien
 * peramban Sidik: Studio Web (dev server lokal), bundel web Flutter di Firebase
 * Hosting (dua domain proyek `sidik-kalibrasi`, bawaan kode), dan origin
 * tambahan dari env `CORS_ALLOWED_ORIGINS`. Sebelum `config/cors.php` ada,
 * bawaan framework mengizinkan `*` untuk `api/*`.
 *
 * Yang dikunci:
 *  - dua origin dev lokal DAN dua domain Firebase Hosting lulus preflight dan
 *    respons nyata (termasuk respons galat 401, supaya klien bisa membaca
 *    pesannya) TANPA env apa pun: bundel web Flutter tidak boleh bergantung
 *    pada env yang bisa lupa diisi di produksi;
 *  - origin asing (termasuk yang mirip: skema beda, sub/superdomain, akhiran,
 *    port) tidak mendapat header `Access-Control-Allow-Origin`;
 *  - origin tambahan hanya masuk lewat env, dinormalisasi, MENAMBAH bawaan
 *    (tidak pernah menggantinya), dan `*` ditolak;
 *  - tidak ada kredensial lintas origin (token lewat header, bukan cookie).
 *
 * Tidak ada request yang menyentuh data: preflight dijawab middleware sebelum
 * routing, dan GET tanpa token berhenti di 401.
 */
class CorsStudioTest extends TestCase
{
    private const URI_STUDIO = '/api/studio/parameter-metode';

    private const ORIGIN_VITE = 'http://localhost:5173';

    private const ORIGIN_VITE_IP = 'http://127.0.0.1:5173';

    /** Bundel web Flutter (`/app`) di Firebase Hosting proyek `sidik-kalibrasi`; sumber di `config/cors.php`. */
    private const ORIGIN_WEB_APP = 'https://sidik-kalibrasi.web.app';

    private const ORIGIN_FIREBASEAPP = 'https://sidik-kalibrasi.firebaseapp.com';

    /** @var list<string> */
    private const ORIGIN_BAWAAN = [
        self::ORIGIN_VITE,
        self::ORIGIN_VITE_IP,
        self::ORIGIN_WEB_APP,
        self::ORIGIN_FIREBASEAPP,
    ];

    /** @return array<string, string> */
    private function preflight(string $origin): array
    {
        return [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type,x-klien,x-versi-klien',
        ];
    }

    /**
     * Isi `config/cors.php` untuk nilai env tertentu. `env()` dibaca saat berkas
     * dimuat, jadi berkasnya dimuat ulang di sini dan env dikembalikan sesudahnya.
     *
     * @return array<string, mixed>
     */
    private function configDenganEnv(?string $nilai): array
    {
        $awal = [
            'server' => $_SERVER['CORS_ALLOWED_ORIGINS'] ?? null,
            'env' => $_ENV['CORS_ALLOWED_ORIGINS'] ?? null,
            'getenv' => getenv('CORS_ALLOWED_ORIGINS'),
        ];

        try {
            if ($nilai === null) {
                unset($_SERVER['CORS_ALLOWED_ORIGINS'], $_ENV['CORS_ALLOWED_ORIGINS']);
                putenv('CORS_ALLOWED_ORIGINS');
            } else {
                $_SERVER['CORS_ALLOWED_ORIGINS'] = $_ENV['CORS_ALLOWED_ORIGINS'] = $nilai;
                putenv('CORS_ALLOWED_ORIGINS='.$nilai);
            }

            return require config_path('cors.php');
        } finally {
            foreach (['server' => '_SERVER', 'env' => '_ENV'] as $kunci => $nama) {
                if ($awal[$kunci] === null) {
                    unset($GLOBALS[$nama]['CORS_ALLOWED_ORIGINS']);
                } else {
                    $GLOBALS[$nama]['CORS_ALLOWED_ORIGINS'] = $awal[$kunci];
                }
            }

            $awal['getenv'] === false
                ? putenv('CORS_ALLOWED_ORIGINS')
                : putenv('CORS_ALLOWED_ORIGINS='.$awal['getenv']);
        }
    }

    public function test_preflight_dari_origin_bawaan_lulus_tanpa_env(): void
    {
        foreach (self::ORIGIN_BAWAAN as $origin) {
            $respons = $this->withHeaders($this->preflight($origin))
                ->options(self::URI_STUDIO.'/spectrophotometer.max_stdev_holmium/penetapan');

            $respons->assertNoContent();
            $respons->assertHeader('Access-Control-Allow-Origin', $origin);
            $this->assertStringContainsString('POST', (string) $respons->headers->get('Access-Control-Allow-Methods'));

            // Header khusus Studio dan Authorization harus lolos preflight.
            $diizinkan = strtolower((string) $respons->headers->get('Access-Control-Allow-Headers'));
            foreach (['authorization', 'content-type', 'x-klien', 'x-versi-klien'] as $header) {
                $this->assertStringContainsString($header, $diizinkan, "header {$header} ditolak preflight dari {$origin}");
            }
        }
    }

    public function test_respons_nyata_termasuk_galat_membawa_header_origin_yang_diizinkan(): void
    {
        foreach (self::ORIGIN_BAWAAN as $origin) {
            $respons = $this->withHeaders(['Origin' => $origin, 'Accept' => 'application/json'])
                ->getJson(self::URI_STUDIO);

            $respons->assertUnauthorized();
            $respons->assertHeader('Access-Control-Allow-Origin', $origin);
        }
    }

    public function test_origin_asing_tidak_mendapat_izin_di_preflight_maupun_respons_nyata(): void
    {
        foreach ([
            'https://situs-asing.example',
            'http://localhost:3000',
            'http://localhost:5174',
            'https://localhost:5173',
            // Mirip origin bawaan tetapi bukan: skema beda, akhiran, sub/superdomain, port.
            'http://sidik-kalibrasi.web.app',
            'https://sidik-kalibrasi.web.app.situs-asing.example',
            'https://situs-sidik-kalibrasi.web.app',
            'https://x.sidik-kalibrasi.web.app',
            'https://sidik-kalibrasi.web.app:8443',
            'https://sidik-kalibrasi.firebaseapp.com.situs-asing.example',
            'https://sidik-kalibrasi-lain.firebaseapp.com',
            'https://proyek-lain.web.app',
        ] as $origin) {
            $preflight = $this->withHeaders($this->preflight($origin))
                ->options(self::URI_STUDIO.'/spectrophotometer.max_stdev_holmium/penetapan');
            $this->assertNull($preflight->headers->get('Access-Control-Allow-Origin'), "preflight {$origin} tidak boleh diizinkan");

            $nyata = $this->withHeaders(['Origin' => $origin, 'Accept' => 'application/json'])->getJson(self::URI_STUDIO);
            $this->assertNull($nyata->headers->get('Access-Control-Allow-Origin'), "respons untuk {$origin} tidak boleh membawa izin");
        }
    }

    public function test_bawaan_framework_bintang_tidak_lagi_berlaku(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'), 'token lewat header, tanpa cookie lintas origin');
    }

    public function test_tanpa_env_hanya_origin_bawaan_dev_lokal_dan_bundel_web_flutter(): void
    {
        $this->assertSame(self::ORIGIN_BAWAAN, $this->configDenganEnv(null)['allowed_origins']);
        $this->assertSame(self::ORIGIN_BAWAAN, $this->configDenganEnv('')['allowed_origins']);
    }

    public function test_origin_tambahan_dari_env_ditambahkan_dinormalisasi_dan_tidak_menggantikan_bawaan(): void
    {
        $config = $this->configDenganEnv(' https://studio.contoh.id/ , https://studio-2.contoh.id,,http://localhost:5173,https://sidik-kalibrasi.web.app ');

        $this->assertSame(
            [...self::ORIGIN_BAWAAN, 'https://studio.contoh.id', 'https://studio-2.contoh.id'],
            $config['allowed_origins'],
            'spasi dan garis miring akhir dibuang, entri kosong dan duplikat (termasuk duplikat bawaan) dilewati, bawaan tetap ada',
        );
    }

    public function test_bintang_di_env_dibuang_dan_tidak_membuka_semua_origin(): void
    {
        $config = $this->configDenganEnv('*,https://studio.contoh.id');

        $this->assertNotContains('*', $config['allowed_origins']);
        $this->assertContains('https://studio.contoh.id', $config['allowed_origins']);
        $this->assertSame(self::ORIGIN_BAWAAN, array_slice($config['allowed_origins'], 0, count(self::ORIGIN_BAWAAN)));
    }

    public function test_origin_tambahan_dari_env_lulus_preflight_dan_bawaan_tetap_lulus(): void
    {
        config(['cors.allowed_origins' => $this->configDenganEnv('https://studio.contoh.id')['allowed_origins']]);

        $respons = $this->withHeaders($this->preflight('https://studio.contoh.id'))
            ->options(self::URI_STUDIO.'/spectrophotometer.max_stdev_holmium/penetapan');

        $respons->assertNoContent();
        $respons->assertHeader('Access-Control-Allow-Origin', 'https://studio.contoh.id');

        // Mengisi env untuk Studio Web tidak boleh memutus bundel web Flutter.
        $web = $this->withHeaders($this->preflight(self::ORIGIN_WEB_APP))
            ->options(self::URI_STUDIO.'/spectrophotometer.max_stdev_holmium/penetapan');

        $web->assertNoContent();
        $web->assertHeader('Access-Control-Allow-Origin', self::ORIGIN_WEB_APP);
    }
}
