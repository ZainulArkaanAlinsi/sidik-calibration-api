<?php

namespace Database\Factories;

use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => fn () => Order::query()->value('id') ?? Order::factory(),
            // Selalu bikin alat BARU, nggak nempel ke yang udah ada seperti
            // factory lain: satu order nggak boleh punya alat yang sama dua
            // kali (ada unique-nya), jadi `count(2)` bakal langsung nabrak.
            'equipment_id' => Equipment::factory(),
            'kondisi_terima' => fake()->randomElement(['Baik', 'Bodi lecet ringan', 'Tanpa tutup pelindung']),
        ];
    }
}
