<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migrasi indeks garis waktu: indeks ada sesudah migrasi, `down()` menghapus
 * keduanya, `up()` memulihkannya. Berkas migrasi dipanggil LANGSUNG (alasan
 * tidak memakai `DatabaseMigrations` ada di `MigrasiIdentitasPelangganTest`).
 *
 * Yang SENGAJA tidak dibuat: `orders (organization_id, nomor)` (sudah UNIQUE)
 * dan `calibration_sessions (organization_id, nomor_order)` (tidak dibuktikan
 * perlu) -- lihat docblok migrasinya.
 */
class MigrasiIndeksGarisWaktuTest extends TestCase
{
    use RefreshDatabase;

    private const BERKAS = '2026_11_12_100000_tambah_indeks_garis_waktu_arsip.php';

    /** Tabel -> [nama indeks, kolom]. */
    private const INDEKS = [
        'certificates' => ['sertifikat_org_terbit_idx', ['organization_id', 'diterbitkan_pada']],
        'calibration_sessions' => ['sesi_org_tanggal_idx', ['organization_id', 'tanggal_kalibrasi']],
    ];

    private function migrasi(): Migration
    {
        return require database_path('migrations/'.self::BERKAS);
    }

    public function test_kedua_indeks_ada_dengan_kolom_yang_benar(): void
    {
        foreach (self::INDEKS as $tabel => [$nama, $kolom]) {
            $this->assertTrue(Schema::hasIndex($tabel, $nama), "Indeks {$nama} tidak ada di {$tabel}.");
            $this->assertTrue(Schema::hasIndex($tabel, $kolom), "Indeks {$tabel}(".implode(', ', $kolom).') tidak ada.');
        }
    }

    public function test_down_menghapus_dan_up_memulihkan(): void
    {
        $migrasi = $this->migrasi();

        $migrasi->down();
        foreach (self::INDEKS as $tabel => [$nama]) {
            $this->assertFalse(Schema::hasIndex($tabel, $nama), "down() tidak menghapus {$nama}.");
        }

        $migrasi->up();
        foreach (self::INDEKS as $tabel => [$nama]) {
            $this->assertTrue(Schema::hasIndex($tabel, $nama), "up() tidak memulihkan {$nama}.");
        }
    }

    public function test_nomor_order_sudah_berindeks_unik_jadi_tidak_ditambah_lagi(): void
    {
        // Alasan `orders (organization_id, nomor)` tidak dibuat ulang.
        $this->assertTrue(Schema::hasIndex('orders', ['organization_id', 'nomor'], 'unique'));
    }
}
