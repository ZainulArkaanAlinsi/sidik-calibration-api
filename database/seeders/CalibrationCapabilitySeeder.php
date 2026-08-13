<?php

namespace Database\Seeders;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Nyalin lampiran akreditasi (10 kelompok pengukuran, 48 alat, 151 rentang) jadi
 * kategori + kemampuan kalibrasi (CMC). Ini yang jadi batas: alat di luar rentang
 * ini nggak boleh dikalibrasi.
 *
 * Datanya dulu dibaca dari `Project-PT-ASMO/` (vault catatan, di luar repo
 * kode), sampai folder itu kehapus dan seeder ini gagal total. Sekarang
 * disalin ke `database/data/` — bagian dari kodebase, ikut ke-commit, nggak
 * gantung ke folder catatan pribadi yang bisa berubah/kehapus kapan aja.
 */
class CalibrationCapabilitySeeder extends Seeder
{
    /**
     * Alat yang baris CMC-nya PUNYA seeder sendiri, jadi jangan ikut ditulis
     * dari JSON lampiran akreditasi.
     *
     * Tanpa ini alatnya kedaftar DUA KALI di kategori yang sama dengan ejaan
     * yang beda — `Spektrofotometer` (JSON) dan `Spectrophotometer` (master
     * Excel) — dan di layar teknisi keduanya muncul sebagai dua kartu berbeda
     * di Instrumen Analitik. Teknisi nggak punya cara tahu yang mana yang
     * bener.
     *
     * Baris JSON-nya juga NGGAK bisa dipakai jalur hitung: dua rentang panjang
     * gelombangnya sama-sama berparameter "Panjang Gelombang", jadi Holmium
     * (283–641 nm) dan Didynium (474–810 nm) nggak bisa dibedain dari situ —
     * padahal keduanya punya U95 kelompok sendiri. `SpectrophotometerCapability
     * Seeder` nulis label parameter yang eksplisit buat itu.
     *
     * Angka di JSON tetap dipertahankan sebagai catatan akreditasi (KAN
     * LK-285-IDN no. 47), termasuk CMC Didynium 0,38 nm yang beda dari master
     * Excel (0,40 nm) — lihat `docs/handoff-backend-spectrophotometer.md` §10.2
     * soal kenapa yang dipakai angka master.
     *
     * @var list<string>
     */
    private const DISEED_TERPISAH = ['Spektrofotometer'];
    public function run(): void
    {
        $path = database_path('data/kemampuan-kalibrasi.json');

        if (! is_file($path)) {
            throw new RuntimeException("File kemampuan kalibrasi nggak ketemu di: {$path}");
        }

        /** @var array{kelompok_pengukuran: array<int, array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($data['kelompok_pengukuran'] as $kelompok) {
            $category = EquipmentCategory::updateOrCreate(
                ['organization_id' => 1, 'kode' => Str::slug($kelompok['kelompok'])],
                ['nama' => $kelompok['kelompok']],
            );

            // Di-seed ulang dari nol biar nggak numpuk kalau seeder dijalanin lagi.
            $category->capabilities()->delete();

            foreach ($kelompok['alat'] as $alat) {
                if (in_array($alat['nama_alat'], self::DISEED_TERPISAH, true)) {
                    continue;
                }

                foreach ($alat['rentang'] as $rentang) {
                    CalibrationCapability::create([
                        'equipment_category_id' => $category->id,
                        'nama_alat' => $alat['nama_alat'],
                        'parameter' => $rentang['parameter'] ?? null,
                        // Batas bawah kadang kosong (kemampuan titik tunggal, misal
                        // buret "25 mL") atau non-numerik ("ambient" buat oven).
                        'range_min' => is_numeric($rentang['min'] ?? null) ? $rentang['min'] : null,
                        'range_max' => is_numeric($rentang['max'] ?? null) ? $rentang['max'] : null,
                        'range_note' => is_string($rentang['min'] ?? null) ? $rentang['min'] : null,
                        'satuan' => $rentang['satuan'],
                        'ketidakpastian_terbaik' => $rentang['ketidakpastian'],
                        'satuan_ketidakpastian' => $rentang['satuan_u'] ?? $rentang['satuan'],
                        'metode' => $alat['metode'] ?? null,
                        'keterangan' => $alat['keterangan'] ?? null,
                    ]);
                }
            }
        }
    }
}
