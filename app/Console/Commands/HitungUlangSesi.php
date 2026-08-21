<?php

namespace App\Console\Commands;

use App\Models\CalibrationSession;
use App\Models\RawMeasurement;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CalibrationValidator;
use App\Services\GumCalculator;
use App\Services\RumusKalibrasi;
use App\Support\Angka;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hitung ulang satu sesi dari PEMBACAAN MENTAH yang tersimpan.
 *
 * ## Kenapa perlu, dan kenapa bukan lewat API
 *
 * Sesi yang sudah disetujui tidak bisa ditolak lagi (`reject` menolak apa pun
 * di luar `menunggu_approval`) dan tidak bisa di-`PUT` — dua-duanya penjagaan
 * yang benar: sertifikatnya sudah terbit dan sudah bisa dipegang pelanggan.
 *
 * Tapi itu meninggalkan lubang: **kalau angkanya ternyata salah, tidak ada
 * jalan membetulkannya sama sekali.** Kejadian nyata `CAL/2026/08/0043` — satu
 * pembacaan salah ketik, U95 tercetak 212x CMC lab, dan satu-satunya jalan
 * yang tersisa adalah menyunting database dengan tangan.
 *
 * Perintah ini jalan itu, dengan tiga pembatas:
 *
 *  1. **Nggak mengarang angka.** Yang dihitung ulang cuma
 *     `uncertainty_calculations`, dari `raw_measurements` yang SUDAH ada. Kalau
 *     pembacaannya sendiri yang salah, betulkan dulu pembacaannya — perintah
 *     ini nggak akan menutupi itu.
 *  2. **Lewat jalur hitung yang SAMA** dengan `POST /calibrations`, bukan
 *     salinan rumus kedua. Dua implementasi berarti dua jawaban yang bisa
 *     berbeda diam-diam, dan buat lab terakreditasi itu temuan audit.
 *  3. **Nggak menyentuh sertifikat.** Sesudah ini jalankan
 *     `sertifikat:bangun-ulang` supaya snapshot & PDF-nya ikut — dipisah
 *     sengaja: menghitung ulang itu keputusan teknis, menerbitkan ulang
 *     dokumen itu keputusan mutu.
 */
class HitungUlangSesi extends Command
{
    protected $signature = 'kalibrasi:hitung-ulang
        {sesi* : id atau nomor sesi}
        {--dry-run : cuma tampilkan yang bakal berubah}';

    protected $description = 'Hitung ulang hasil GUM satu sesi dari pembacaan mentahnya';

    public function handle(
        CalibrationProfileRegistry $profil,
        GumCalculator $gum,
        RumusKalibrasi $rumus,
        CalibrationValidator $validator,
    ): int {
        $kering = (bool) $this->option('dry-run');
        $gagal = 0;

        foreach ((array) $this->argument('sesi') as $kunci) {
            $sesi = CalibrationSession::with([
                'equipment', 'rawMeasurements.standard', 'uncertaintyCalculations',
            ])->where('id', $kunci)->orWhere('nomor_sesi', $kunci)->first();

            if ($sesi === null) {
                $this->error("Sesi `{$kunci}` nggak ketemu.");
                $gagal++;

                continue;
            }

            $alat = $sesi->equipment;

            if ($alat === null) {
                $this->error("{$sesi->nomor_sesi}: sesinya nggak punya alat, nggak bisa dihitung.");
                $gagal++;

                continue;
            }

            // Pembacaan mentah → bentuk yang dimengerti profil, persis seperti
            // yang disusun `CalibrationController::susunPengukuran()` dari
            // payload. Yang dipakai cuma tahap SESUDAH adjustment: as-found itu
            // dokumentasi kondisi awal alat, bukan dasar sertifikat.
            $siapHitung = [];

            foreach ($sesi->rawMeasurements->where('tahap', 'sesudah_adjustment')
                ->groupBy('titik_ke') as $titikKe => $baris) {
                $nilai = $baris
                    ->sortBy('pembacaan_ke')
                    ->pluck('pembacaan')
                    ->filter(fn ($n): bool => $n !== null)
                    ->map(fn ($n): float => (float) $n)
                    ->values()
                    ->all();

                if (count($nilai) < 2) {
                    continue;
                }

                /** @var RawMeasurement $pertama */
                $pertama = $baris->first();

                // `standard` dikirim sebagai OBJEK, bukan id: profilnya baca
                // ketidakpastian & faktor cakupan sertifikat standarnya
                // langsung dari situ, sama seperti waktu sesi ini pertama
                // dihitung dari payload.
                $siapHitung[] = [
                    'titik_ke' => (int) $titikKe,
                    'titik_ukur' => (float) $pertama->titik_ukur,
                    'pembacaan' => $nilai,
                    'standard' => $pertama->standard,
                    'standard_id' => $pertama->standard_id,
                    'satuan' => $pertama->satuan,
                    'suhu' => null,
                    // Mode & tipe sensor TITS, dibaca balik dari kolom sesi.
                    // Tanpa ini hitung ulang sesi TITS nggak bisa nentuin arah
                    // koreksi maupun tabel kalibrator, dan seluruh titiknya
                    // pulang tanpa angka — bukan salah, tapi juga nggak berguna.
                    'konteks' => [
                        'mode_tits' => $sesi->mode_kalibrasi,
                        'tipe_sensor' => $sesi->tipe_sensor,
                    ],
                ];
            }

            if ($siapHitung === []) {
                $this->warn("{$sesi->nomor_sesi}: nggak ada titik yang bisa dihitung, dilewat.");

                continue;
            }

            $perGrup = $profil->untukAlat($alat)->hitungPerGrup($siapHitung, $alat);

            if ($perGrup === null) {
                $this->error(
                    "{$sesi->nomor_sesi}: alat ini pakai jalur hitung per-TITIK, dan perintah ini "
                    .'baru mendukung alat yang hitungnya per-kelompok (Spectrophotometer & TITS). '
                    .'Buat alat lain, betulin lewat jalur revisi biasa.'
                );
                $gagal++;

                continue;
            }

            $lama = $sesi->uncertaintyCalculations
                ->sortBy('titik_ke')
                ->mapWithKeys(fn ($t): array => [
                    (int) $t->titik_ke => (float) $t->ketidakpastian_diperluas,
                ])
                ->all();

            $this->line("{$sesi->nomor_sesi}:");

            foreach ($perGrup['hitungan'] as $h) {
                $ke = (int) $h['titik_ke'];
                $sebelum = $lama[$ke] ?? null;
                $sesudah = (float) $h['ketidakpastian_diperluas'];

                if ($sebelum !== null && abs($sebelum - $sesudah) > 1e-8) {
                    $this->line(sprintf(
                        '  titik %2d: U95 %s → %s',
                        $ke,
                        Angka::idRingkas($sebelum, 6),
                        Angka::idRingkas($sesudah, 6),
                    ));
                }
            }

            if ($kering) {
                $this->comment('  (dry-run, nggak ada yang ditulis)');

                continue;
            }

            $versi = $rumus->versiUntukSesi($sesi);

            DB::transaction(function () use ($sesi, $perGrup, $versi): void {
                $sesi->uncertaintyCalculations()->delete();

                foreach ($perGrup['hitungan'] as $hitungan) {
                    $sesi->uncertaintyCalculations()->create([
                        ...$hitungan,
                        'formula_version_id' => $versi?->id,
                    ]);
                }
            });

            $segar = $sesi->fresh(['equipment', 'rawMeasurements', 'uncertaintyCalculations']);
            $hasil = $validator->periksa($segar);

            $this->info('  hasil hitung diperbarui.');

            if (! $hasil['boleh_terbit']) {
                $this->warn('  MASIH ADA ERROR — sertifikatnya jangan diterbitkan ulang dulu:');

                foreach ($hasil['temuan'] as $t) {
                    if (($t['tingkat'] ?? null) === CalibrationValidator::ERROR) {
                        $this->line('    • '.$t['pesan']);
                    }
                }

                continue;
            }

            $this->line('  pemeriksaan bersih. Terbitkan ulang dokumennya:');
            $this->line("    php artisan sertifikat:bangun-ulang {$sesi->nomor_sesi}");
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }
}
