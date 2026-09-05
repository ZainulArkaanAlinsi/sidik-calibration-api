<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentCategory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kategori alat = SEPULUH kelompok pengukuran lampiran akreditasi. Titik.
 *
 * ## Bentuk kegagalannya
 *
 * `GET /api/categories` memungut `equipment_categories` apa adanya, dan HP
 * menggambar satu kartu per baris. Seeder per-alat yang membuat kategorinya
 * sendiri melahirkan **kategori hantu**: kelompok kesebelas yang tidak ada di
 * lampiran, nol kemampuan kalibrasi di dalamnya, sementara baris CMC alat itu
 * tetap duduk di kelompok yang benar.
 *
 * Yang dilihat teknisi: kartu kategori yang kalau dibuka isinya kosong — dan
 * alat contohnya justru ada di situ, bukan di kelompok yang terakreditasi.
 * **Nol error di mana pun**, di kedua sisi.
 *
 * Sudah kejadian: `MicrometerSeeder` membuat kategori `dimensi` 4 Sep 2026,
 * padahal Micrometer baris no. 34 kelompok **Panjang**. Ketahuan waktu daftar
 * kategori HP diadu ke lampiran, bukan waktu ada yang merah.
 *
 * ## Kenapa diuji lewat seeder penuh
 *
 * Kategori hantu cuma lahir dari INTERAKSI: `CalibrationCapabilitySeeder`
 * menaruh sepuluh kelompok, lalu seeder per-alat menambah yang kesebelas.
 * Menguji keduanya terpisah memulangkan hijau dua kali.
 */
class KategoriAlatIkutLampiranTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> nama kelompok pengukuran lampiran, apa adanya */
    private function kelompokLampiran(): array
    {
        $path = database_path('data/kemampuan-kalibrasi.json');
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_map(
            static fn (array $k): string => (string) $k['kelompok'],
            $data['kelompok_pengukuran'],
        );
    }

    public function test_tidak_ada_kategori_di_luar_lampiran(): void
    {
        $this->seed(DatabaseSeeder::class);

        $lampiran = $this->kelompokLampiran();
        $terpakai = EquipmentCategory::pluck('nama')->unique()->values()->all();

        $asing = array_values(array_diff($terpakai, $lampiran));

        $this->assertSame(
            [],
            $asing,
            'Kategori alat di luar lampiran akreditasi: `'.implode('`, `', $asing).'`. '
            .'Di HP tiap baris ini jadi satu kartu kategori, dan yang bukan kelompok lampiran '
            .'selalu kosong isinya. Pakai nama kelompok lampiran yang sudah ada '
            .'(`'.implode('`, `', $lampiran).'`), jangan bikin baru.',
        );
    }

    /**
     * Kebalikannya: tiap kategori yang DIPAKAI alat ter-seed harus punya
     * kemampuan kalibrasi di dalamnya.
     *
     * Menangkap bentuk yang lolos test di atas — kategori bernama benar tapi
     * ter-seed dua kali dengan `kode` beda (mis. `panjang` vs `panjang-1`),
     * yang juga melahirkan kartu kosong di HP.
     */
    public function test_kategori_yang_dipakai_alat_punya_kemampuan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $kosong = EquipmentCategory::query()
            ->whereHas('equipments')
            ->whereDoesntHave('capabilities')
            ->pluck('nama', 'kode')
            ->all();

        $this->assertSame(
            [],
            $kosong,
            'Kategori ini dipakai alat ter-seed tapi tidak punya satu pun baris kemampuan '
            .'kalibrasi: '.json_encode($kosong, JSON_UNESCAPED_UNICODE).'. Alatnya duduk di '
            .'kelompok yang tidak terakreditasi, dan kartunya kosong waktu dibuka teknisi.',
        );
    }

    /**
     * Alat contoh Micrometer duduk di kelompok Panjang — kelompok yang sama
     * dengan baris CMC-nya.
     *
     * Ditulis eksplisit karena inilah kasus yang melahirkan test ini, dan
     * kedua sapuan di atas bakal tetap hijau kalau suatu saat `Panjang`
     * dan `Dimensi` sama-sama ada di lampiran.
     */
    public function test_micrometer_contoh_ada_di_kelompok_panjang(): void
    {
        $this->seed(DatabaseSeeder::class);

        $alat = Equipment::where('serial_number', 'ZQ-100')->firstOrFail();

        $this->assertSame('Panjang', $alat->category?->nama);
        $this->assertTrue(
            $alat->category->capabilities->contains('nama_alat', 'Micrometer'),
            'Kelompok alat contoh Micrometer tidak memuat baris kemampuan `Micrometer`.',
        );
    }
}
