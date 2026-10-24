<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HealthLaporinAcuanTest extends TestCase
{
    #[DataProvider('sumberAcuan')]
    public function test_sumber_acuan_dilaporkan_dari_config_sebagai_string(mixed $sumber, string $dilaporkan): void
    {
        // Kontrak health adalah status konfigurasi, bukan validasi/normalisasi
        // driver. `db` di sini tidak mengaktifkan versi paket atau menulis DB.
        config(['acuan.sumber' => $sumber]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data_acuan.sumber', $dilaporkan);
    }

    public static function sumberAcuan(): array
    {
        return [
            'json' => ['json', 'json'],
            'db' => ['db', 'db'],
            'nilai tidak lazim tetap terlihat' => [' Db-uji ', ' Db-uji '],
            'null menjadi string kosong' => [null, ''],
            'angka menjadi string' => [42, '42'],
            'false menjadi string kosong' => [false, ''],
        ];
    }

    public function test_blok_acuan_hanya_memuat_sumber_tanpa_isi_konfigurasi_lain(): void
    {
        config([
            'acuan.sumber' => 'json',
            'acuan.password' => 'PENANDA-RAHASIA-UJI-ACUAN',
            'acuan.akun' => 'PENANDA-AKUN-UJI-ACUAN',
            'database.connections.mysql.password' => 'PENANDA-SANDI-UJI-DB',
        ]);

        $respons = $this->getJson('/api/health')->assertOk();

        $this->assertSame(['sumber' => 'json'], $respons->json('data_acuan'));
        foreach (['PENANDA-RAHASIA-UJI-ACUAN', 'PENANDA-AKUN-UJI-ACUAN', 'PENANDA-SANDI-UJI-DB'] as $rahasiaUji) {
            $this->assertStringNotContainsString($rahasiaUji, (string) $respons->getContent());
        }
    }

    public function test_isi_health_lama_tetap_tersedia(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('php_versi', PHP_VERSION)
            ->assertJsonStructure([
                'app',
                'time',
                'direktori_perusahaan' => ['disetel', 'driver', 'bisa_ditagih', 'lokal' => ['aktif', 'baris']],
                'deploy' => ['lingkungan', 'versi', 'arsip', 'seed_saat_boot', 'bangun_ulang_saat_boot'],
                'pelanggan' => ['fitur', 'maintenance'],
                'realtime' => ['driver', 'nyala', 'paket_terpasang'],
                'append_only_db',
                'data_acuan' => ['sumber'],
            ]);
    }
}
