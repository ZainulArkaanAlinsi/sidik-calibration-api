<?php

namespace App\Console\Commands;

use App\Models\CalibrationCapability;
use App\Models\Organization;
use App\Services\Calibration\CalibrationProfileRegistry;
use Database\Seeders\KemampuanKalibrasiSeeder;
use Illuminate\Console\Command;

/**
 * Pastikan tiap profil terdaftar punya baris `calibration_capabilities`.
 *
 * ## Masalah yang dipecahkan
 *
 * Paket gratis Render tidak punya Shell. Sampai 8 Sep 2026, satu-satunya cara
 * menaruh baris kemampuan alat BARU ke produksi adalah menyalakan
 * `SEED_ON_BOOT=true`, redeploy, lalu mematikannya lagi.
 *
 * Ritual itu punya dua cacat, dan dua-duanya sudah terukur:
 *
 *  1. Dia menjalankan `db:seed` **penuh** — 4,5 menit, dan ikut menanam
 *     `DemoDataSeeder` plus sesi contoh 26 alat ke produksi.
 *  2. Langkah "matikan lagi" tidak menerbitkan error kalau terlupa. Render
 *     menidurkan container yang nganggur 15 menit, jadi "tiap container
 *     bangun" itu sering — dan tiap kali itu seluruh sesi demo ditulis ulang,
 *     menimpa data yang sedang diuji teknisi.
 *
 * Perintah ini menggantikan ritualnya: dia menanam HANYA baris kemampuan,
 * hanya kalau ada yang kurang, dan aman dijalankan tiap boot.
 *
 * ## Kenapa "cek dulu" bukan "seed saja tiap boot"
 *
 * `CalibrationCapabilitySeeder` sendiri makan **24,5 detik** di produksi
 * (terukur di log deploy 7 Sep 2026; MySQL-nya di Aiven jadi tiap tulis satu
 * round-trip). Menanam tanpa syarat berarti tiap cold start membayar ongkos itu
 * — dan cold start sering, karena Render menidurkan container yang nganggur.
 *
 * Pemeriksaannya sendiri SATU query. Kalau lengkap, perintah ini selesai dalam
 * milidetik. Polanya disalin dari `direktori:impor-lokal --lewati-kalau-terisi`
 * yang sudah jalan di entrypoint sejak alat ke-25.
 *
 * ## Yang TIDAK dilakukan perintah ini
 *
 * Dia tidak menyentuh `OrganizationSeeder`, `MetodeKalibrasiSeeder`, maupun
 * `ThermohygroSeeder` — ketiganya menulis data yang boleh disunting admin/lab,
 * dan menimpanya tiap boot mengembalikannya ke bawaan. Alasan per seeder ada di
 * docblock [KemampuanKalibrasiSeeder].
 *
 * Dia juga tidak MEMPERBAIKI baris yang sudah ada tapi angkanya salah — yang
 * diperiksa cuma "namanya ada atau tidak". Baris yang ada tapi CMC-nya keliru
 * urusan `CmcSemuaProfilTest`, bukan urusan boot.
 */
class PastikanKemampuanKalibrasi extends Command
{
    protected $signature = 'kemampuan:pastikan
        {--uji-coba : Laporkan yang kurang tanpa menanam apa pun}';

    protected $description = 'Pastikan tiap profil kalibrasi punya baris kemampuan; menanam cuma kalau ada yang kurang';

    /**
     * Organisasi yang dituju seeder kemampuan.
     *
     * SEMUA seeder kemampuan mematok `organization_id => 1` (lihat
     * `CalibrationCapabilitySeeder` dan kesepuluh saudaranya), jadi
     * pemeriksaannya harus dipatok ke organisasi yang sama.
     *
     * Kalau tidak dipatok, lubangnya begini: lab kedua yang admin-nya menambah
     * kemampuan bernama "Height Gauge" sendiri membuat pemeriksaan ini LULUS,
     * sementara organisasi 1 — yang dipakai seluruh sesi ter-seed — tetap tidak
     * punya barisnya. Tidak ada error; alatnya cuma tidak pernah muncul.
     */
    private const ORGANISASI = 1;

    public function handle(CalibrationProfileRegistry $registry): int
    {
        // Organisasi 1 belum ada = database ini belum pernah di-seed sama
        // sekali. Seeder kemampuan bakal gagal di foreign key-nya, dan pesan
        // yang muncul (`equipment_categories.organization_id`) tidak menunjuk
        // ke mana-mana buat yang membacanya di log deploy.
        //
        // Balik SUCCESS, bukan FAILURE: database kosong itu keadaan yang SAH
        // untuk deploy pertama, dan menjatuhkan boot karenanya menukar satu
        // langkah setup dengan seluruh server.
        if (! Organization::whereKey(self::ORGANISASI)->exists()) {
            $this->warn(sprintf(
                'Organisasi %d belum ada — database sepertinya belum pernah di-seed. '
                .'Jalankan `db:seed` penuh sekali (di Render: SEED_ON_BOOT=true, redeploy, '
                .'lalu matikan lagi), baru perintah ini ada gunanya.',
                self::ORGANISASI,
            ));

            return self::SUCCESS;
        }

        $diharapkan = $this->namaProfil($registry);
        $kurang = $this->yangKurang($diharapkan);
        $jumlah = count($diharapkan);

        if ($kurang === []) {
            $this->info("Kemampuan kalibrasi: {$jumlah}/{$jumlah} profil sudah punya barisnya — seeding dilewati.");

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Kemampuan kalibrasi kurang %d dari %d: %s',
            count($kurang),
            $jumlah,
            implode(', ', $kurang),
        ));

        if ($this->option('uji-coba')) {
            $this->line('(--uji-coba: tidak ada yang ditanam)');

            return self::SUCCESS;
        }

        $this->call('db:seed', [
            '--class' => KemampuanKalibrasiSeeder::class,
            '--force' => true,
        ]);

        // Diperiksa ULANG, bukan dianggap berhasil karena seeder-nya tidak
        // melempar exception. Seeder per-alat memakai `updateOrCreate` yang
        // kuncinya kategori + nama + rentang — nama alat yang meleset satu huruf
        // dari `namaAlatKemampuan()` tetap membuat baris, cuma barisnya tidak
        // pernah cocok dengan profil mana pun. Tanpa pemeriksaan kedua, log
        // melapor sukses untuk keadaan yang sama sekali belum beres.
        $sisa = $this->yangKurang($diharapkan);

        if ($sisa !== []) {
            $this->error(sprintf(
                'Masih kurang sesudah seeding: %s. Periksa apakah `namaAlatKemampuan()` profilnya '
                .'sama PERSIS dengan `nama_alat` yang ditulis seeder-nya.',
                implode(', ', $sisa),
            ));

            return self::FAILURE;
        }

        $this->info("Kemampuan kalibrasi: {$jumlah}/{$jumlah} lengkap.");

        return self::SUCCESS;
    }

    /**
     * Nama alat yang WAJIB punya baris kemampuan — satu per profil terdaftar.
     *
     * `ProfilGenerik` tidak ikut, dan itu benar: dia bukan anggota
     * `daftarProfil()`, cuma jawaban cadangan `untukNamaAlat()` untuk nama yang
     * tidak dikenali. Kalau dia ikut, pemeriksaan ini tidak akan pernah lengkap
     * dan perintahnya menyeed di TIAP boot.
     *
     * Dikunci lewat kunci array, bukan `array_unique` di akhir: lima profil
     * Enclosure berbagi satu kelompok tapi namanya beda-beda, dan kalau suatu
     * saat dua profil memakai `namaAlatKemampuan()` yang sama, hitungan
     * "{$jumlah}/{$jumlah}" di log tetap jujur.
     *
     * @return list<string>
     */
    private function namaProfil(CalibrationProfileRegistry $registry): array
    {
        $nama = [];

        foreach ($registry->semua() as $profil) {
            $n = trim($profil->namaAlatKemampuan());

            if ($n !== '') {
                $nama[$n] = true;
            }
        }

        return array_keys($nama);
    }

    /**
     * Nama yang belum punya satu pun baris.
     *
     * Dibandingkan case-INSENSITIVE. MySQL mencocokkan string tanpa peduli
     * besar-kecil huruf pada collation bawaannya, jadi `array_diff` yang
     * case-sensitive bisa melaporkan "kurang" untuk baris yang sebenarnya sudah
     * ada — dan perintah ini bakal menyeed ulang tiap boot tanpa pernah selesai.
     *
     * @param  list<string>  $diharapkan
     * @return list<string>
     */
    private function yangKurang(array $diharapkan): array
    {
        $ada = CalibrationCapability::query()
            ->where('organization_id', self::ORGANISASI)
            ->distinct()
            ->pluck('nama_alat')
            ->map(static fn ($n): string => mb_strtolower(trim((string) $n)))
            ->all();

        return array_values(array_filter(
            $diharapkan,
            static fn (string $n): bool => ! in_array(mb_strtolower($n), $ada, true),
        ));
    }
}
