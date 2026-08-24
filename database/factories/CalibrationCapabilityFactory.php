<?php

namespace Database\Factories;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalibrationCapability>
 */
class CalibrationCapabilityFactory extends Factory
{
    /**
     * `sumber` dipasang lewat `afterMaking`, BUKAN lewat `definition()`.
     *
     * Kolomnya sengaja nggak mass-assignable di modelnya (lihat komentar
     * `#[Fillable]` di situ), dan pabrik data lewat konstruktor model — jadi
     * nilai yang ditaruh di `definition()` bakal dibuang diam-diam dan tiap
     * baris pabrik keluar dengan `sumber` bawaan DB. State `tanpaCmc()` yang
     * gunanya justru nandain asal-usul jadi nggak ada artinya.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (CalibrationCapability $kemampuan): void {
            $kemampuan->sumber ??= CalibrationCapability::SUMBER_AKREDITASI;
        });
    }

    /**
     * Bawaannya baris LENGKAP ala lampiran akreditasi — rentang + CMC keisi.
     *
     * Sengaja gitu: baris tanpa CMC itu keadaan istimewa yang harus disebut
     * eksplisit (`->tanpaCmc()`), bukan bawaan yang kebawa diam-diam ke test
     * yang sebenernya lagi nguji hal lain.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'equipment_category_id' => fn () => EquipmentCategory::query()->value('id') ?? EquipmentCategory::factory(),
            'nama_alat' => fake()->unique()->randomElement([
                'Vernier Caliper', 'Micrometer', 'Sieve', 'Oven', 'Inkubator', 'Waterbath',
            ]),
            'parameter' => null,
            'range_min' => 0,
            'range_max' => 100,
            'satuan' => 'mm',
            'ketidakpastian_terbaik' => 0.05,
            'satuan_ketidakpastian' => 'mm',
            'faktor_cakupan' => 2,
        ];
    }

    /** Baris hasil tambahan orang: cuma punya nama, belum punya rentang & CMC. */
    public function tanpaCmc(string $sumber = CalibrationCapability::SUMBER_TEKNISI): static
    {
        return $this->state(fn (): array => [
            'range_min' => null,
            'range_max' => null,
            'range_note' => null,
            'satuan' => null,
            'ketidakpastian_terbaik' => null,
            'satuan_ketidakpastian' => null,
            'metode' => null,
        ])->afterMaking(function (CalibrationCapability $kemampuan) use ($sumber): void {
            $kemampuan->sumber = $sumber;
        });
    }
}
