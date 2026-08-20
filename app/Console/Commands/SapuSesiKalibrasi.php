<?php

namespace App\Console\Commands;

use App\Models\CalibrationSession;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CalibrationValidator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Jalankan `CalibrationValidator` ke SELURUH sesi di database, lalu laporkan
 * yang bermasalah.
 *
 * ## Kenapa ini perlu ada sebagai perintah, bukan test
 *
 * Suite test jalan di database KOSONG yang diisi seeder — dia membuktikan
 * mesinnya benar, bukan bahwa data yang beneran ada di database kerja sehat.
 * Dua hal yang cuma ketahuan di sini:
 *
 *  - **Sesi lama yang jadi tidak konsisten karena master berubah.** Lab
 *    mengganti lot larutan atau membetulkan angka sertifikat kalibrator, dan
 *    sesi yang sudah tersimpan tiba-tiba tidak bisa dihitung ulang sama. Itu
 *    bukan bug — tapi harus KETAHUAN sebelum ada yang menerbitkan sertifikat
 *    di atasnya.
 *  - **Sesi yang datanya setengah jadi.** Draft tanpa standar acuan, misalnya,
 *    tidak menghasilkan satu titik pun — dan diam-diam, karena draft memang
 *    boleh setengah jadi.
 *
 * Dipakai sebelum sesi uji lapangan atau audit: satu perintah, satu tabel,
 * ketahuan mana yang perlu dilihat manusia.
 *
 *   php artisan kalibrasi:sapu-sesi
 *   php artisan kalibrasi:sapu-sesi --semua      # termasuk peringatan & info
 *   php artisan kalibrasi:sapu-sesi --profil=gas_detector
 *
 * Keluar dengan kode 1 kalau ada ERROR atau crash — jadi bisa dipasang di CI
 * atau cron tanpa ada yang perlu membaca keluarannya tiap hari.
 */
class SapuSesiKalibrasi extends Command
{
    protected $signature = 'kalibrasi:sapu-sesi
        {--profil= : batasi ke satu kode profil, mis. gas_detector}
        {--semua : tampilkan juga temuan tingkat peringatan & info}';

    protected $description = 'Periksa SEMUA sesi kalibrasi di database lewat CalibrationValidator';

    public function handle(CalibrationValidator $validator, CalibrationProfileRegistry $registry): int
    {
        $sesi = CalibrationSession::with(['equipment', 'uncertaintyCalculations', 'rawMeasurements', 'certificate'])
            ->orderBy('id')
            ->get();

        $filterProfil = $this->option('profil');
        $semua = (bool) $this->option('semua');

        if ($sesi->isEmpty()) {
            $this->components->warn('Tidak ada sesi kalibrasi di database.');

            return self::SUCCESS;
        }

        $baris = [];
        $jumlahError = 0;
        $jumlahCrash = 0;
        $diperiksa = 0;

        foreach ($sesi as $s) {
            $kode = $s->equipment !== null
                ? $registry->untukAlat($s->equipment)->kode()
                : '(tanpa alat)';

            if ($filterProfil !== null && $kode !== $filterProfil) {
                continue;
            }

            $diperiksa++;

            try {
                $hasil = $validator->periksa($s);
            } catch (Throwable $e) {
                $jumlahCrash++;
                $baris[] = ['CRASH', $s->id, $s->nomor_sesi, $kode, class_basename($e).': '.$e->getMessage()];

                continue;
            }

            $temuan = collect($hasil['temuan']);
            $error = $temuan->where('tingkat', 'error');

            if ($error->isNotEmpty()) {
                $jumlahError++;
                $baris[] = ['ERROR', $s->id, $s->nomor_sesi, $kode, $error->pluck('kode')->implode(', ')];
            }

            if ($semua) {
                foreach (['peringatan', 'info'] as $tingkat) {
                    $lain = $temuan->where('tingkat', $tingkat);
                    if ($lain->isNotEmpty()) {
                        $baris[] = [strtoupper($tingkat), $s->id, $s->nomor_sesi, $kode, $lain->pluck('kode')->implode(', ')];
                    }
                }
            }
        }

        $this->newLine();
        $this->components->info("Diperiksa {$diperiksa} sesi.");

        if ($baris === []) {
            $this->components->info('Bersih — tidak ada temuan.');

            return self::SUCCESS;
        }

        $this->table(['Tingkat', 'ID', 'Nomor sesi', 'Profil', 'Kode temuan'], $baris);

        // Cuma ERROR & CRASH yang bikin gagal. Peringatan sengaja tidak:
        // "data standar berubah sesudah sesi disimpan" itu jejak audit yang
        // benar, bukan sesuatu yang harus dibereskan sebelum kerja lanjut.
        if ($jumlahError > 0 || $jumlahCrash > 0) {
            $this->components->error("{$jumlahError} sesi ber-ERROR, {$jumlahCrash} crash.");

            return self::FAILURE;
        }

        $this->components->info('Tidak ada ERROR maupun crash.');

        return self::SUCCESS;
    }
}
