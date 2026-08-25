<?php

namespace Database\Factories;

use App\Models\Folder;
use App\Models\FolderFile;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FolderFile>
 */
class FolderFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'folder_id' => fn () => Folder::factory(),

            // `unggahan`, bukan `sertifikat`: baris bersumber sertifikat kena
            // `unique(folder_id, certificate_id)`, jadi dua berkas contoh di
            // folder yang sama bakal tabrakan. Yang bersumber sertifikat dibikin
            // lewat state [dariSertifikat] kalau memang itu yang diuji.
            'sumber' => FolderFile::SUMBER_UNGGAHAN,
            'nama' => fake()->unique()->word().'.pdf',
            'path' => 'folder-files/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'ukuran' => fake()->numberBetween(1_024, 5_000_000),
        ];
    }
}
