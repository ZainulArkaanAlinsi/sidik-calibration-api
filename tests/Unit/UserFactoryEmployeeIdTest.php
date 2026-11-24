<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

/**
 * Penjaga tabrakan `users.employee_id`: banyak test menulis ID tetap `SDK-nnnn`
 * (empat digit) sambil membuat user lain lewat factory. ID acak factory harus
 * selalu di luar rentang itu, kalau tidak CI merah sesekali tanpa kode yang berubah.
 */
class UserFactoryEmployeeIdTest extends TestCase
{
    public function test_employee_id_acak_factory_tidak_pernah_empat_digit(): void
    {
        for ($i = 0; $i < 3000; $i++) {
            $id = User::factory()->make(['organization_id' => 1])->employee_id;

            $this->assertMatchesRegularExpression('/^SDK-\d{5}$/', $id, "employee_id factory harus lima digit, dapat {$id}");
        }
    }
}
