<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Studio Tahap 1 (25 Nov 2026), keputusan D6: CORS hanya mengizinkan origin
 * Studio Web (dev server lokal) ditambah origin produksi dari env
 * `CORS_ALLOWED_ORIGINS`. Sebelum `config/cors.php` ada, bawaan framework
 * mengizinkan `*` untuk `api/*`.
 *
 * Yang dikunci:
 *  - dua origin dev lokal lulus preflight dan respons nyata (termasuk respons
 *    galat 401, supaya klien Studio bisa membaca pesannya);
 *  - origin asing tidak mendapat header `Access-Control-Allow-Origin`;
 *  - origin produksi hanya masuk lewat env, dinormalisasi, dan `*` ditolak;
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

    public function test_preflight_dari_origin_vite_lokal_lulus(): void
    {
        foreach ([self::ORIGIN_VITE, self::ORIGIN_VITE_IP] as $origin) {
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
        $respons = $this->withHeaders(['Origin' => self::ORIGIN_VITE, 'Accept' => 'application/json'])
            ->getJson(self::URI_STUDIO);

        $respons->assertUnauthorized();
        $respons->assertHeader('Access-Control-Allow-Origin', self::ORIGIN_VITE);
    }

    public function test_origin_asing_tidak_mendapat_izin_di_preflight_maupun_respons_nyata(): void
    {
        foreach (['https://situs-asing.example', 'http://localhost:3000', 'http://localhost:5174', 'https://localhost:5173'] as $origin) {
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

    public function test_tanpa_env_hanya_dua_origin_lokal(): void
    {
        $this->assertSame([self::ORIGIN_VITE, self::ORIGIN_VITE_IP], $this->configDenganEnv(null)['allowed_origins']);
        $this->assertSame([self::ORIGIN_VITE, self::ORIGIN_VITE_IP], $this->configDenganEnv('')['allowed_origins']);
    }

    public function test_origin_produksi_dari_env_ditambahkan_dan_dinormalisasi(): void
    {
        $config = $this->configDenganEnv(' https://studio.contoh.id/ , https://studio-2.contoh.id,,http://localhost:5173 ');

        $this->assertSame(
            [self::ORIGIN_VITE, self::ORIGIN_VITE_IP, 'https://studio.contoh.id', 'https://studio-2.contoh.id'],
            $config['allowed_origins'],
            'spasi dan garis miring akhir dibuang, entri kosong dan duplikat dilewati',
        );
    }

    public function test_bintang_di_env_dibuang_dan_tidak_membuka_semua_origin(): void
    {
        $config = $this->configDenganEnv('*,https://studio.contoh.id');

        $this->assertNotContains('*', $config['allowed_origins']);
        $this->assertContains('https://studio.contoh.id', $config['allowed_origins']);
    }

    public function test_origin_produksi_dari_env_lulus_preflight(): void
    {
        config(['cors.allowed_origins' => $this->configDenganEnv('https://studio.contoh.id')['allowed_origins']]);

        $respons = $this->withHeaders($this->preflight('https://studio.contoh.id'))
            ->options(self::URI_STUDIO.'/spectrophotometer.max_stdev_holmium/penetapan');

        $respons->assertNoContent();
        $respons->assertHeader('Access-Control-Allow-Origin', 'https://studio.contoh.id');
    }
}
