<?php

namespace Database\Seeders;

use App\Models\Equipment;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Dua potong data yang cuma hidup di DATABASE, bukan di kode — dan karena itu
 * gampang ketinggalan waktu deploy ke server baru.
 *
 * Dua-duanya bikin sertifikat SALAH secara diam-diam kalau kosong: dokumennya
 * tetap terbit, nggak ada error, cuma isinya nggak sesuai lembar resmi lab.
 * Itu jenis kesalahan yang paling lama ketahuannya.
 *
 * 1. PENANDA TANGAN. `CertificateSnapshotBuilder::footer()` jatuh ke nama admin
 *    YANG KEBETULAN NYETUJUIN kalau pengaturan ini kosong. Di lab terakreditasi
 *    penanda tangan itu jabatan tetap, bukan siapa pun yang lagi piket — dan
 *    `DATABASE.csv` nyebut dua orang: IPD Wiska Susetio (Laboratory Director)
 *    dan Alex Misramto (Technical Manager).
 *
 * 2. RESOLUSI BERTINGKAT TURBIDIMETER. Tanpa `resolusi_rentang`, seluruh tabel
 *    hasil kepaksa ikut satu angka `resolusi` (0,01) dan titik 100 NTU kecetak
 *    `101,00` — dua digit yang alatnya nggak bisa tampilkan. Angkanya dari
 *    Capacity/Graduation sertifikat asli HACH 2100Q (0189-CAL-624).
 *
 * Aman dijalankan berulang: dua-duanya `updateOrCreate`/patch, nggak nambah
 * baris baru.
 */
class PengaturanSertifikatSeeder extends Seeder
{
    /**
     * Resolusi Turbidimeter per rentang ukur — `maks` EKSKLUSIF.
     *
     * Batas 100 sengaja masuk golongan teratas (resolusi 1), bukan golongan
     * 10–100. Itu yang bikin titik 100 NTU kecetak `100`/`101` persis kayak
     * Excel master lab, bukan `100,0`/`101,0`.
     */
    private const RESOLUSI_TURBIDIMETER = [
        ['maks' => 10, 'resolusi' => 0.01],
        ['maks' => 100, 'resolusi' => 0.1],
        ['maks' => null, 'resolusi' => 1],
    ];

    public function run(): void
    {
        foreach (Organization::all() as $organisasi) {
            $settings = $organisasi->settings ?? [];

            // `??=` — pengaturan yang UDAH diisi admin nggak ditimpa. Seeder
            // ini ngisi yang kosong, bukan maksa balik ke bawaan.
            $settings['penandatangan_nama'] ??= 'Alex Misramto';
            $settings['penandatangan_jabatan'] ??= 'Technical Manager';

            $organisasi->settings = $settings;
            $organisasi->save();
        }

        // Dicocokin lewat nama alat, bukan id: id-nya beda tiap database.
        Equipment::where('nama_alat', 'like', '%urbidimeter%')
            ->whereNull('resolusi_rentang')
            ->each(function (Equipment $alat): void {
                $alat->resolusi_rentang = self::RESOLUSI_TURBIDIMETER;
                $alat->save();
            });
    }
}
