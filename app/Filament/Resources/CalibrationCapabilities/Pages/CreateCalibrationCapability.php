<?php

namespace App\Filament\Resources\CalibrationCapabilities\Pages;

use App\Filament\Resources\CalibrationCapabilities\CalibrationCapabilityResource;
use App\Models\CalibrationCapability;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCalibrationCapability extends CreateRecord
{
    protected static string $resource = CalibrationCapabilityResource::class;

    /**
     * Baris yang lahir dari layar ini SELALU `sumber = admin`, dan itu diisi di
     * sini — bukan lewat form.
     *
     * Dua alasan yang nggak boleh ketuker:
     *
     *  1. `sumber` sengaja nggak mass-assignable di modelnya, jadi
     *     `$this->getModel()::create($data)` bawaan Filament nggak akan pernah
     *     ngisinya dan tiap baris baru bakal jatuh ke default kolom —
     *     `akreditasi`. Nama alat yang baru diketik admin lima detik lalu bakal
     *     ngaku-ngaku salinan lampiran KAN LK-285-IDN, dan sesudah itu perisai
     *     di `CalibrationCapabilitySeeder` malah ngelindungin baris yang salah.
     *  2. Kalaupun `sumber` dijadiin field form, itu berarti ada dropdown yang
     *     nawarin "akreditasi" ke orang yang lagi ngetik data baru. Pilihan yang
     *     nggak boleh dipilih sebaiknya nggak dipajang.
     *
     * Ngubah asal baris JADI `akreditasi` itu keputusan dokumen, bukan
     * keputusan layar: yang boleh nulisnya cuma seeder yang bacanya dari
     * `database/data/kemampuan-kalibrasi.json`.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $kemampuan = new CalibrationCapability;
        $kemampuan->fill($data);
        $kemampuan->sumber = CalibrationCapability::SUMBER_ADMIN;
        $kemampuan->dibuat_oleh_user_id = User::yangLogin()?->id;
        $kemampuan->save();

        return $kemampuan;
    }
}
