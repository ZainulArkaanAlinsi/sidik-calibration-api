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
     * ## Viscometer
     *
     * Dua alasan, dan yang kedua bikin baris JSON-nya nggak bisa dipakai
     * ngitung sama sekali:
     *
     *  1. **Satuannya campur.** Dua baris pertama `cP`, baris ketiga `1.4 P`
     *     (Poise). `1 P = 100 cP`, jadi angkanya sebenernya sama dengan 140 cP
     *     yang ditulis master — tapi dibaca mentah, lantai CMC titik 60000 cP
     *     jadi 1,4 cP, seratus kali lebih longgar dari yang diakreditasi.
     *  2. **Titiknya nggak pernah kena.** JSON nulis titik tunggal 102 / 1028 /
     *     58021 cP. Nilai acuan viscometer bergeser ikut suhu larutan — sesi
     *     master jatuh di 93,88 / 910,29 / 61898,12 cP — dan ambang pencocokan
     *     titik tunggal `GumCalculator::kemampuanUntukTitik()` itu
     *     `max(0,1 ; 0,5 %)`. Jarak 93,88 ke 102 aja 8,12. Ketiga titik bakal
     *     diam-diam jatuh ke jalur generik tanpa lantai CMC.
     *
     * `ViscometerCapabilitySeeder` nulisnya sebagai RENTANG, seluruhnya dalam
     * cP.
     *
     * ## Enam alat sisanya
     *
     * pH Meter, Turbidimeter, Chlorin Meter, Refractometer, Conductivitymeter,
     * dan DO Meter dulunya NGGAK ada di daftar ini, dan akibatnya dua:
     *
     *  1. **Barisnya dobel.** Seeder per-alat nyocokin baris pakai kunci
     *     `range_min = range_max = titik`; JSON lampiran nulis titik tunggal
     *     sebagai `range_min = NULL`. Dua kunci itu nggak pernah ketemu, jadi
     *     `updateOrCreate`-nya selalu bikin baris KEDUA. Yang satu punya
     *     `u_temperature`, yang satu NULL — dan `kemampuanUntukTitik()` milih
     *     salah satunya tanpa aturan yang bisa ditebak.
     *  2. **Konstanta budgetnya kehapus.** Seeder ini mulai dengan
     *     `capabilities()->delete()` se-KATEGORI. Dijalanin sendirian (mis.
     *     `db:seed --class=CalibrationCapabilitySeeder`) dia ngosongin
     *     `u_temperature` sembilan alat sekaligus. Sesudah itu
     *     `CalibrationProfile::komponenBudget()` balikin null, tiap sesi baru
     *     diam-diam turun ke jalur satu komponen (CMC/2), dan sertifikatnya
     *     terbit dengan Uc yang beda dari master — tanpa error di mana pun.
     *     Ditemukan di DB lokal 20 Agu 2026: delapan dari sembilan alat
     *     `u_temperature`-nya NULL.
     *
     * Angka JSON-nya tetap kejaga sebagai catatan akreditasi lewat berkas
     * `database/data/kemampuan-kalibrasi.json` — yang berubah cuma: baris DB
     * buat alat ini punya SATU sumber, yaitu seeder alatnya sendiri.
     *
     * Autoklaf sengaja NGGAK ikut: dia belum punya seeder kemampuan sendiri,
     * dan `AutoclaveCalculator` nggak lewat `komponenBudget()` sama sekali
     * (konstanta budgetnya ada di kalkulatornya), jadi baris JSON-nya udah
     * cukup dan satu-satunya.
     *
     * @var list<string>
     */
    private const DISEED_TERPISAH = [
        'Spektrofotometer',
        'Viscometer',
        'pH Meter',
        'Turbidimeter',
        'Chlorin Meter',
        'Refractometer',
        'Conductivitymeter',
        'DO Meter',
    ];

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

            // Di-seed ulang dari nol biar nggak numpuk kalau seeder dijalanin
            // lagi — TAPI cuma baris yang emang milik seeder ini.
            //
            // Dulu ini ngehapus se-kategori tanpa syarat, dan itu bikin seeder
            // ini jadi granat: dijalanin sendirian, dia ngosongin baris CMC
            // milik delapan seeder per-alat berikut `u_temperature`-nya, dan
            // seluruh jalur budget master mati diam-diam sampai ada yang
            // kepikiran nyeed ulang satu-satu. Lihat [DISEED_TERPISAH].
            $category->capabilities()
                ->whereNotIn('nama_alat', self::DISEED_TERPISAH)
                ->delete();

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
