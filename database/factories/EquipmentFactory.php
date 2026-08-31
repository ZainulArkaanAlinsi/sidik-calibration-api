<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'customer_id' => fn () => Customer::query()->value('id') ?? Customer::factory(),
            'equipment_category_id' => fn () => EquipmentCategory::query()->value('id') ?? EquipmentCategory::factory(),
            // Nama generik untuk fixture panjang/massa. JANGAN pakai nama yang
            // sama dengan `namaAlatKemampuan()` profil mana pun (mis. "Oven",
            // yang sekarang milik EnclosureProfile) — `untukAlat()` jatuh ke
            // `nama_alat` waktu `nama_alat_kemampuan` null, jadi alat generik
            // bakal ketarik ke profil enclosure & nggak kehitung.
            //
            // "Timbangan" DICABUT 31 Agt 2026 karena persis kena aturan itu:
            // begitu `TimbanganProfile` lahir, satu dari empat fixture acak
            // mendarat di lembar Timbangan — dan karena namanya diundi, yang
            // merah bukan test Timbangan melainkan test Sertifikat, Masa
            // Berlaku, dan Pembacaan Mustahil, bergantian tiap jalan. Daftar
            // ini WAJIB berisi nama yang tidak diklaim profil mana pun;
            // `ProfilDariNamaAlatTest::test_nama_alat_generik_balik_null`
            // memelihara daftar resminya.
            'nama_alat' => fake()->randomElement(['Jangka Sorong', 'Micrometer', 'Dial Indicator', 'Height Gauge']),
            'merk' => fake()->randomElement(['Mitutoyo', 'Ohaus', 'Memmert']),
            'serial_number' => strtoupper(fake()->unique()->bothify('??-####-##')),
            'satuan' => 'mm',
            'tanggal_kalibrasi_terakhir' => now()->subMonths(6),
            'tanggal_jatuh_tempo' => now()->addMonths(6),
            'status' => Equipment::STATUS_AKTIF,
        ];
    }

    /** Alat yang udah lewat jatuh tempo — di API statusnya jadi `overdue`. */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'tanggal_jatuh_tempo' => now()->subMonth(),
            'status' => Equipment::STATUS_AKTIF,
        ]);
    }
}
