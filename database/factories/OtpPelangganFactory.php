<?php

namespace Database\Factories;

use App\Models\OtpPelanggan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<OtpPelanggan>
 */
class OtpPelangganFactory extends Factory
{
    /** Kode mentah yang terakhir dibuat, supaya test bisa memasukkannya. */
    public static ?string $kodeTerakhir = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kode = str_pad((string) fake()->numberBetween(0, 999999), 6, '0', STR_PAD_LEFT);
        self::$kodeTerakhir = $kode;

        return [
            'user_id' => fn () => User::factory(),
            'tujuan' => OtpPelanggan::TUJUAN_VERIFIKASI_EMAIL,
            'kode_hash' => Hash::make($kode),
            'kedaluwarsa_pada' => now()->addMinutes(OtpPelanggan::BERLAKU_MENIT),
            'percobaan' => 0,
        ];
    }

    public function kedaluwarsa(): static
    {
        return $this->state(fn (): array => ['kedaluwarsa_pada' => now()->subMinute()]);
    }

    public function terkunci(): static
    {
        return $this->state(fn (): array => [
            'percobaan' => OtpPelanggan::MAKS_PERCOBAAN,
            'dikunci_sampai' => now()->addMinutes(OtpPelanggan::KUNCI_MENIT),
        ]);
    }
}
