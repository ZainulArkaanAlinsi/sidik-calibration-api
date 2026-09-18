<?php

namespace Tests\Unit;

use App\Support\NomorTelepon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Normalisasi nomor HP ke `+62…` (02-SRS §11). */
class NomorTeleponTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function nomorSah(): array
    {
        return [
            'nol di depan' => ['081234567890', '+6281234567890'],
            'pakai strip' => ['0812-3456-7890', '+6281234567890'],
            'pakai spasi' => ['0812 3456 7890', '+6281234567890'],
            'sudah +62' => ['+62 812 3456 7890', '+6281234567890'],
            '62 tanpa plus' => ['6281234567890', '+6281234567890'],
            'tanpa nol depan' => ['81234567890', '+6281234567890'],
            'sembilan digit' => ['08123456789', '+628123456789'],
            'kurung wilayah' => ['(0812) 3456-7890', '+6281234567890'],
        ];
    }

    #[DataProvider('nomorSah')]
    public function test_nomor_sah_dinormalisasi(string $masuk, string $harap): void
    {
        $this->assertSame($harap, NomorTelepon::normal($masuk));
        $this->assertTrue(NomorTelepon::sah($masuk));
    }

    /** @return array<string, array{0: string|null}> */
    public static function nomorNgawur(): array
    {
        return [
            'null' => [null],
            'kosong' => [''],
            'huruf saja' => ['hubungi saya'],
            'kependekan' => ['12345'],
            'telepon rumah' => ['0224567890'],
            'kepanjangan' => ['0812345678901234'],
            'nomor luar negeri' => ['+1 415 555 0132'],
        ];
    }

    #[DataProvider('nomorNgawur')]
    public function test_nomor_ngawur_ditolak(?string $masuk): void
    {
        $this->assertNull(NomorTelepon::normal($masuk));
        $this->assertFalse(NomorTelepon::sah($masuk));
    }
}
