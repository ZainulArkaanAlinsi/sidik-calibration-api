<?php

namespace Database\Seeders;

use App\Models\CalibrationMethod;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Metode Kalibrasi (IK) — dibaca LANGSUNG dari lembar master lab.
 *
 * Sumbernya `CATATAN/ini-yang-dari-karywan-manual/DATABASE.csv`, tabel
 * "Jenis Pengukuran / Metode Kalibrasi (Latest IK)". Nomor IK **beserta
 * revisinya** dipakai apa adanya, karena itu yang kecetak di sertifikat dan
 * yang dicocokkan waktu audit.
 *
 * Kenapa dibaca dari CSV, bukan disalin jadi array PHP: 26 baris disalin
 * tangan itu 26 peluang salah ketik di nomor dokumen mutu, dan revisinya
 * berubah tiap IK direvisi. Baca dari berkasnya bikin "sinkron sama lembar
 * lab" jadi otomatis, bukan janji.
 *
 * `updateOrCreate` dikunci ke `kode` (tanpa revisi), jadi IK yang direvisi
 * NAIK revisinya di baris yang sama — bukan bikin baris kedua yang bikin
 * orang bingung mana yang berlaku.
 */
class MetodeKalibrasiSeeder extends Seeder
{
    private const CSV = 'CATATAN/ini-yang-dari-karywan-manual/DATABASE.csv';

    /** Baris tabelnya: `<no>,<jenis pengukuran>,<KODE_Rev.N>` */
    private const POLA = '/^\s*(\d+)\s*,\s*("?)(.+?)\2\s*,\s*(SIDIK-IK-CAL-\d+)[_-]?Rev\.?\s*(\d+)/i';

    public function run(): void
    {
        $path = base_path(self::CSV);

        if (! is_file($path)) {
            $this->command?->warn("Lembar master nggak ketemu: {$path} — metode dilewati.");

            return;
        }

        $organisasi = Organization::first();

        if ($organisasi === null) {
            $this->command?->warn('Belum ada organisasi — jalankan OrganizationSeeder dulu.');

            return;
        }

        $jumlah = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $baris) {
            if (! preg_match(self::POLA, $baris, $cocok)) {
                continue;
            }

            [, , , $jenis, $kode, $revisi] = $cocok;

            CalibrationMethod::updateOrCreate(
                [
                    'organization_id' => $organisasi->id,
                    'kode' => $kode,
                ],
                [
                    // Nama = jenis pengukurannya, persis kolom "Jenis
                    // Pengukuran" di lembar. Itu yang dicari orang waktu milih
                    // metode ("pH Meter"), bukan nomor dokumennya.
                    'nama' => trim($jenis),
                    'revisi' => $revisi,
                    'aktif' => true,
                ],
            );

            $jumlah++;
        }

        $this->command?->info("Metode kalibrasi dari lembar master: {$jumlah} baris.");
    }
}
