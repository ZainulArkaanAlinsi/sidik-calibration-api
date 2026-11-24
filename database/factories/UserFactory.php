<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Nempel ke organisasi yang udah ada kalau ada — biar test nggak
            // kebanjiran organisasi baru tiap bikin 1 user.
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            // Lima digit, SENGAJA di luar rentang 1000-9999 yang dipakai test untuk
            // ID tetap (mis. 'SDK-2001'). Empat digit pernah menabrak ID tetap itu
            // sekali dalam ~9000 jalan (`UNIQUE constraint failed: users.employee_id`
            // di DetailSesiLengkapTest, CI 24 Nov 2026), dan test yang merah tanpa
            // kode yang berubah melatih orang mengabaikan CI.
            'employee_id' => 'SDK-'.fake()->unique()->numberBetween(10000, 99999),
            'name' => fake()->name(),
            'department' => fake()->randomElement(['Kalibrasi', 'Quality Control']),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_TEKNISI,
            // Default kolomnya di DB itu `pending` (buat pendaftar baru), tapi user
            // hasil factory dipakai buat nyoba alur yang udah login — jadi aktif.
            'status' => User::STATUS_AKTIF,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Akun hasil daftar mandiri yang belum disetujui admin. */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::STATUS_PENDING,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMIN,
        ]);
    }
}
