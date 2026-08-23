<?php

namespace App\Console\Commands;

use App\Models\CalibrationSession;
use App\Models\RawMeasurement;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CalibrationValidator;
use App\Services\GumCalculator;
use App\Services\RumusKalibrasi;
use App\Support\Angka;
use App\Support\GridSensorMentah;
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
 * Perintah ini jalan itu, dengan empat pembatas:
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
 *  4. **Nggak pernah mengganti hasil dengan kekosongan.** Penulisannya hapus
 *     lalu tulis ulang, jadi hitung ulang yang pulang tanpa titik sama sekali
 *     bakal mengosongkan sesi yang sertifikatnya sudah terbit. Kalau itu yang
 *     terjadi perintah ini berhenti sebelum menghapus; kalau cuma sebagian
 *     titik yang hilang, dia menyebut titiknya dan minta jawaban dulu.
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
                // Grid enclosure, disusun ulang dari `sensor_ke`/`peran_sensor`/
                // `channel`. Kosong buat sepuluh alat lain — profilnya nggak
                // pernah menengok kunci ini.
                $grid = GridSensorMentah::dari($baris);

                if ($grid === []) {
                    // Alat single-channel biasa: satu titik = satu deret
                    // pembacaan datar. Minimal dua, karena satu pembacaan nggak
                    // punya simpangan baku.
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
                } else {
                    // Enclosure nggak punya deret datar: satu set point itu 9
                    // termokopel × 5 pembacaan + Indikator, dan yang dibaca
                    // profilnya `konteks.sensor_grid`. Meratakan semuanya jadi
                    // satu deret bikin `pembacaan` campur aduk lintas sensor,
                    // jadi dikosongin — persis seperti jalur simpan di
                    // `CalibrationController::susunGridEnclosure()`.
                    $nilai = [];

                    if ($grid['sensor_grid'] === [] && $grid['indikator'] === []) {
                        continue;
                    }
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
                        ...$grid,
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

            $baru = $perGrup['hitungan'] ?? [];

            $lama = $sesi->uncertaintyCalculations
                ->sortBy('titik_ke')
                ->mapWithKeys(fn ($t): array => [
                    (int) $t->titik_ke => (float) $t->ketidakpastian_diperluas,
                ])
                ->all();

            // Penjagaan yang paling penting di perintah ini: hasil kosong
            // NGGAK BOLEH menimpa hasil yang sudah ada.
            //
            // Penulisannya `delete()` lalu `create()` per titik, jadi kalau
            // hitung ulangnya pulang tanpa satu pun baris, yang terjadi bukan
            // "nggak ada yang berubah" tapi **seluruh hasil sesi terhapus** —
            // dan `raw_measurements`-nya masih utuh, jadi sertifikat yang sudah
            // terbit tinggal jadi sesi tanpa angka sama sekali. Kejadian nyata:
            // sesi enclosure yang tiap titiknya masuk `belum_dihitung` (mis.
            // nomor termokopelnya nggak ada di tabel kalibrator) turun dari 4
            // baris jadi 0, dan `--dry-run` pun nggak ngasih tanda apa-apa.
            //
            // Sesi yang MEMANG belum pernah punya hasil tetap boleh lewat:
            // nggak ada yang bisa hilang di situ.
            if ($baru === [] && $lama !== []) {
                $this->error(
                    "{$sesi->nomor_sesi}: hitung ulang nggak menghasilkan satu titik pun, "
                    .'padahal sesi ini punya '.count($lama).' hasil tersimpan. '
                    .'DIBATALKAN — kalau diteruskan hasil yang lama kehapus dan nggak ada gantinya.'
                );

                foreach ($perGrup['belum_dihitung'] ?? [] as $b) {
                    $this->line('    • titik '.($b['titik_ke'] ?? '?').': '.($b['alasan'] ?? '-'));
                }

                $gagal++;

                continue;
            }

            // Titik yang dulu punya hasil tapi sekarang nggak — hasil lamanya
            // bakal ikut kehapus tanpa pengganti. Bukan alasan buat berhenti
            // (sisanya masih benar), tapi harus kelihatan sebelum ditulis.
            $hilang = array_values(array_diff(
                array_keys($lama),
                array_map(static fn (array $h): int => (int) $h['titik_ke'], $baru),
            ));

            $this->line("{$sesi->nomor_sesi}:");

            if ($hilang !== []) {
                $this->warn('  titik '.implode(', ', $hilang).' punya hasil tersimpan tapi nggak kehitung ulang — hasil lamanya bakal hilang:');

                foreach ($perGrup['belum_dihitung'] ?? [] as $b) {
                    if (in_array((int) ($b['titik_ke'] ?? 0), $hilang, true)) {
                        $this->line('    • titik '.$b['titik_ke'].': '.($b['alasan'] ?? '-'));
                    }
                }
            }

            foreach ($baru as $h) {
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

            // Kehilangan SEBAGIAN nggak dibatalkan otomatis — bisa jadi memang
            // itu maunya (mis. titik yang datanya terbukti salah). Tapi harus
            // dijawab manusia, dan defaultnya TIDAK: dijalankan tanpa terminal
            // (`--no-interaction`, cron, deploy) ini berhenti sendiri ketimbang
            // menghapus diam-diam.
            if ($hilang !== [] && ! $this->confirm('  Terusin dan hapus hasil titik '.implode(', ', $hilang).'?', false)) {
                $this->comment('  dibatalkan, nggak ada yang ditulis.');
                $gagal++;

                continue;
            }

            $versi = $rumus->versiUntukSesi($sesi);

            DB::transaction(function () use ($sesi, $baru, $versi): void {
                $sesi->uncertaintyCalculations()->delete();

                foreach ($baru as $hitungan) {
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
