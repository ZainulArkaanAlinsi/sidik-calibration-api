<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PHP 8.4.27 menutup CVE-2026-109618 dan CVE-2026-109619 (RCE tanpa login,
 * php.net 21 Okt 2026). Test ini menjaga dua hal yang tidak menghasilkan error
 * kalau dilanggar: image produksi tetap terkunci ke rilis tambalan beserta
 * penjaga build-nya, dan /api/health melaporkan versi PHP yang jalan.
 */
class PhpTambalanKeamananTest extends TestCase
{
    public function test_dockerfile_mengunci_image_tambalan_dan_menolak_php_lama(): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression(
            '/^FROM dunglas\/frankenphp:\d+\.\d+\.\d+-php8\.4\.(\d+)-bookworm AS php-dasar$/m',
            $dockerfile,
            'Image dasar wajib tag terkunci (versi FrankenPHP + patch PHP), bukan tag mengambang.',
        );
        preg_match('/frankenphp:\d+\.\d+\.\d+-php8\.4\.(\d+)-bookworm/', $dockerfile, $m);
        $this->assertGreaterThanOrEqual(27, (int) $m[1], 'PHP 8.4 di bawah 8.4.27 rentan CVE-2026-109618/109619.');

        $this->assertStringContainsString(
            "RUN php -r 'exit(version_compare(PHP_VERSION, \"8.4.27\", \"<\") ? 1 : 0);'",
            $dockerfile,
            'Penjaga build yang menolak PHP lebih tua dari rilis tambalan hilang.',
        );
    }

    public function test_health_melaporkan_versi_php(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('php_versi', PHP_VERSION);
    }
}
