<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PengajuanAkunPelanggan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PengajuanAkunPelanggan>
 */
class PengajuanAkunPelangganFactory extends Factory
{
    /**
     * Nama perusahaan SELALU fiktif ("PT Contoh ..."), bukan `fake()->company()`.
     * `fake()` bisa memulangkan nama yang kebetulan sama dengan pelanggan
     * sungguhan, dan fixture yang memuat nama pelanggan asli itu persis yang
     * dilarang aturan proyek.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'user_id' => fn () => User::factory(),
            'nama_perusahaan' => 'PT Contoh '.fake()->unique()->numberBetween(1, 9999),
            'alamat_perusahaan' => 'Jalan Contoh No. '.fake()->numberBetween(1, 200),
            'jabatan' => 'Supervisor QA',
            'status' => PengajuanAkunPelanggan::STATUS_MENUNGGU,
        ];
    }

    public function disetujui(): static
    {
        return $this->state(fn (): array => [
            'status' => PengajuanAkunPelanggan::STATUS_DISETUJUI,
            'diputus_pada' => now(),
            // Siapa yang memutus ikut diisi — lihat alasannya di [ditolak].
            'diputus_oleh' => fn () => User::factory(),
        ]);
    }

    public function ditolak(): static
    {
        return $this->state(fn (): array => [
            'status' => PengajuanAkunPelanggan::STATUS_DITOLAK,
            'diputus_pada' => now(),
            // `diputus_oleh` ikut diisi, bukan dibiarkan null: pengajuan yang
            // sudah diputus TAPI tanpa pemutusnya adalah bentuk yang jalur
            // aplikasi sendiri tidak pernah hasilkan, dan itu yang paling mahal
            // hilang di sini — kolom ini jejak audit "siapa menyetujui akun
            // pelanggan mana".
            'diputus_oleh' => fn () => User::factory(),
            'alasan_tolak' => 'Data perusahaan tidak bisa diverifikasi.',
        ]);
    }
}
