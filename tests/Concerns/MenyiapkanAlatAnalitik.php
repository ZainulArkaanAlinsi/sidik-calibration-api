<?php

namespace Tests\Concerns;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Standard;
use App\Services\GumCalculator;
use App\Services\KondisiLingkungan;
use Database\Seeders\CalibrationCapabilitySeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\ThermohygroSeeder;

/**
 * Perangkat bersama tes PARITAS alat analitik batch ke-2 (Refractometer,
 * Turbidimeter, Chlorine Meter), dipakai bersama `MenegakkanParitas`.
 *
 * Tiga alat ini sama-sama punya seeder produksi untuk (a) baris CMC + UTemperature
 * (`*CapabilitySeeder`) dan (b) larutan standar (`*Seeder`). Tes memakai KEDUANYA,
 * bukan konstanta yang diketik ulang: dengan begitu tes ini ikut membuktikan bahwa
 * konstanta seeder = sel workbook (U95 larutan, CMC, UTemperature).
 *
 * ## Toleransi yang bergantung kolom `decimal(20,8)` (UTemperature)
 *
 * `calibration_capabilities.u_temperature` adalah `decimal(20,8)`: MySQL (produksi,
 * shard CI MySQL) membulatkannya, SQLite menyimpan float penuh. Galat pembulatan:
 *
 *  - Turbidimeter & Chlorine: 0,36124783736 -> 0,36124784 (+2,6e-9; relatif 7,3e-9).
 *  - Refractometer: 0,03935733731 -> 0,03935734 (+2,7e-9; relatif 6,8e-8).
 *
 * Efek ke komponen yang memakainya, dihitung dari sel (bukan dipilih agar lulus):
 *
 *  - ui = u/pembagi: relatif sama dengan galat u (<= 6,8e-8); ci = (u/400)*titik
 *    (Turbidimeter, Chlorine): relatif 7,3e-9; uici: jumlah keduanya (<= 1,5e-8).
 *    Batas tes [TOL_SIMPAN_KOMPONEN] 2e-7 = 3x terburuk, jauh di bawah galat sebuah
 *    konstanta yang salah (komponen terkecil di budget berbeda >= 1e-4 relatif).
 *  - uc: komponen suhu hanya 4e-5 .. 3e-4 dari jumlah kuadrat (terburuk: 1000 NTU
 *    3,2e-4), jadi pergeseran uc <= 3,2e-4 * 1,5e-8 ~ 5e-12 relatif; v_eff ~ uc^4
 *    jadi 4x ~ 2e-11. Batas [TOL_SIMPAN_UC] 1e-10 dan [TOL_SIMPAN_VEFF] 1e-9.
 *  - k: v_eff 200..300 jauh dari bilangan bulat terdekat (floor stabil), jadi k tidak bergeser.
 */
trait MenyiapkanAlatAnalitik
{
    private const TOL_SIMPAN_KOMPONEN = 2e-7;

    private const TOL_SIMPAN_UC = 1e-10;

    private const TOL_SIMPAN_VEFF = 1e-9;

    private ?EquipmentCategory $kategoriAnalitik = null;

    /**
     * @param  list<class-string>  $seeder  seeder kemampuan lalu seeder standar alat
     */
    private function siapkanAnalitik(array $seeder, bool $thermohygro = false): void
    {
        // Urutan sama dengan `DatabaseSeeder`: kemampuan generik dulu, baru kemampuan
        // per alat (yang menimpa baris `instrumen-analitik`), baru standar alat.
        $this->seed(OrganizationSeeder::class);
        $this->seed(CalibrationCapabilitySeeder::class);

        foreach ($seeder as $kelas) {
            $this->seed($kelas);
        }

        if ($thermohygro) {
            $this->seed(ThermohygroSeeder::class);
        }

        $this->kategoriAnalitik = EquipmentCategory::where('kode', 'instrumen-analitik')->firstOrFail();
    }

    /** Alat pelanggan sintetis; yang menentukan profil `nama_alat_kemampuan`. */
    private function alatAnalitik(string $nama, string $satuan, float $resolusi): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => 1,
            'equipment_category_id' => $this->kategoriAnalitik->id,
            'nama_alat' => $nama,
            'nama_alat_kemampuan' => $nama,
            'satuan' => $satuan,
            'resolusi' => $resolusi,
            // Toleransi TIDAK ada di workbook (konflik P-29); angka ini hanya agar
            // hitungTitik() tidak berhenti, dan tidak pernah dibandingkan ke Excel.
            'toleransi' => 1.0,
        ]);
    }

    private function standarSeeder(string $nama): Standard
    {
        return Standard::where('organization_id', 1)->where('nama', $nama)->firstOrFail();
    }

    /**
     * Satu titik lewat `GumCalculator::hitungTitik()` — fungsi yang sama dengan preview,
     * simpan (`CalibrationController`), dan hitung ulang. Masukan disusun seperti
     * controller menyusunnya: suhu larutan = rata-rata suhu baris terisi, suhu ruang =
     * rata-rata MENTAH (awal+akhir)/2 (sebelum koreksi thermohygro).
     *
     * @param  array<string, mixed>  $titik  titik fixture
     * @return array<string, mixed>
     */
    private function hitungTitikAnalitik(int $titikKe, array $titik, Equipment $alat, Standard $standar, ?float $suhuRuang = null, array $konteks = []): array
    {
        $suhu = $titik['masukan']['suhu_sesudah_c']['nilai'];

        return (new GumCalculator)->hitungTitik(
            $titikKe,
            $titik['masukan']['titik_ukur']['nilai'],
            array_map('floatval', $titik['masukan']['pembacaan_sesudah']['nilai']),
            $alat,
            $standar,
            array_sum($suhu) / count($suhu),
            $suhuRuang,
            $konteks,
        );
    }

    /**
     * Blok KONDISI LINGKUNGAN seperti `KondisiLingkungan::hitung()` menghitungnya untuk
     * sesi yang membawa thermohygro `$namaTh` dan pembacaan awal/akhir yang diberikan.
     *
     * @return array<string, mixed>|null
     */
    private function kondisiLingkungan(string $namaTh, string $parameter, float $awal, float $akhir): ?array
    {
        $sesi = new CalibrationSession;
        $sesi->forceFill($parameter === 'suhu'
            ? ['suhu_awal' => $awal, 'suhu_akhir' => $akhir]
            : ['kelembaban_awal' => $awal, 'kelembaban_akhir' => $akhir]);
        $sesi->setRelation('thermohygro', $this->standarSeeder($namaTh));

        return app(KondisiLingkungan::class)->hitung($sesi, $parameter);
    }
}
