<?php

namespace App\Services\Vision;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jatah panggilan OCR awan per lembar (sesi kerja) dan sinyal untuk admin, dipakai
 * `OcrSelAwanController`.
 *
 * Isinya SALINAN logika privat `OcrHalamanController` (`sudahMencapaiBatas`, `terlaluBanyakGagal`,
 * `catatPenghentian`, `laporkanKeAdmin`), sengaja disalin dan bukan dipindah: jalur foto utuh
 * (`POST /ocr/halaman`) tidak boleh berubah dalam pekerjaan ini. Kedua jalur memakai baris log yang
 * sama (`GoogleVisionOcr::MODEL`), jadi jatahnya SATU kolam per lembar, dan pemantau admin
 * (`GET /api/admin/ocr-cloud`) melihat keduanya. Kalau salah satu berubah, ubah keduanya, atau
 * pindahkan `OcrHalamanController` ke kelas ini sebagai pekerjaan tersendiri.
 *
 * Penghitungan TANPA kunci: permintaan yang masuk bersamaan pada sesi yang sama bisa sama-sama
 * membaca "belum penuh". Kelebihannya sebanyak permintaan yang berjalan bersamaan per akun
 * (limiter `ocr-sel-awan` membatasi laju, bukan kelebihan). Diterima sadar, sama seperti jalur foto
 * utuh: taruhannya beberapa unit tagihan, bukan data.
 */
final class JatahOcrAwan
{
    /** Panggilan yang menghasilkan unit tertagih sudah sebanyak `maks_per_sesi`? Di bawah 1 dipaksa 1. */
    public function mencapaiBatas(CalibrationSession $sesi): bool
    {
        return $this->sisaPanggilan($sesi) === 0;
    }

    /**
     * Berapa panggilan Google lagi yang masih boleh untuk lembar ini (`maks_per_sesi` dikurangi baris log
     * ber-`unit_vision` > 0). Satu panggilan batch = satu baris log, jadi satu permintaan yang berisi
     * banyak sel bisa memakai beberapa panggilan dan pemanggil perlu tahu sisanya SEBELUM mengirim.
     */
    public function sisaPanggilan(CalibrationSession $sesi): int
    {
        $maks = max(1, (int) config('services.google_vision.maks_per_sesi'));

        $terpakai = WorksheetExtractionLog::query()
            ->where('calibration_session_id', $sesi->id)
            ->where('model', GoogleVisionOcr::MODEL)
            // Galat Google bernilai 0 dan tidak boleh mengunci lembar dengan pesan "jatah habis".
            ->where('unit_vision', '>', 0)
            ->count();

        return max(0, $maks - $terpakai);
    }

    /** Kegagalan Google (`unit_vision` = 0) pada lembar ini sudah sebanyak `maks_gagal_per_sesi`? */
    public function terlaluBanyakGagal(CalibrationSession $sesi): bool
    {
        return WorksheetExtractionLog::query()
            ->where('calibration_session_id', $sesi->id)
            ->where('model', GoogleVisionOcr::MODEL)
            ->where('unit_vision', 0)
            ->count() >= $this->batasGagal();
    }

    /** Di bawah 1 (env kosong atau 0) dipaksa 1: `count >= 0` selalu benar dan menolak semuanya. */
    public function batasGagal(): int
    {
        return max(1, (int) config('services.google_vision.maks_gagal_per_sesi'));
    }

    /**
     * Sinyal untuk admin bahwa konfigurasi OCR awan kemungkinan salah. Hanya teks yang sudah ada di
     * log (kalimat tetap, atau status HTTP + kode status Google), tidak pernah pesan Google, gambar,
     * atau teks hasil. Gagal melapor TIDAK boleh mengubah jawaban ke teknisi.
     *
     * @param  array{ok: bool, status: string, error: ?string}  $hasil
     */
    public function laporkanGagal(array $hasil, ?CalibrationSession $sesi, User $user): void
    {
        try {
            if ($hasil['ok']) {
                return;
            }

            if ($hasil['status'] === 'belum_disetel') {
                // Kunci yang SAMA dengan jalur foto utuh: satu galat konfigurasi, satu baris log.
                if (Cache::add('ocr_cloud.log_belum_disetel', 1, 600)) {
                    Log::error('OCR awan aktif tetapi belum disetel benar di server.', [
                        'sebab' => $hasil['error'],
                        'organization_id' => $user->organization_id,
                    ]);
                }

                return;
            }

            if ($sesi === null) {
                if (Cache::add('ocr_cloud.log_gagal_tanpa_sesi.'.$user->organization_id, 1, 600)) {
                    Log::warning('OCR awan (sel angka) gagal pada hasil pindai tanpa sesi kerja.', [
                        'sebab' => $hasil['error'],
                        'organization_id' => $user->organization_id,
                    ]);
                }

                return;
            }

            $this->catatPenghentian($sesi, $user);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Tulis sinyal "OCR awan dihentikan untuk lembar ini" bila batas kegagalan tercapai. IDEMPOTEN
     * (satu baris `audit_logs` per sesi, entitas yang sama dengan jalur foto utuh) dan tidak
     * pernah melempar.
     */
    public function catatPenghentian(CalibrationSession $sesi, User $user): void
    {
        try {
            $sudahTercatat = AuditLog::query()
                ->where('entity_type', 'ocr_cloud_sesi')
                ->where('entity_id', $sesi->id)
                ->where('action', AuditLog::ACTION_OCR_DIHENTIKAN)
                ->exists();
            if ($sudahTercatat) {
                return;
            }

            $sebab = [];
            $perJenis = [];
            $baris = WorksheetExtractionLog::query()
                ->where('calibration_session_id', $sesi->id)
                ->where('model', GoogleVisionOcr::MODEL)
                ->where('unit_vision', 0)
                ->selectRaw('status, error, count(*) as jumlah')
                ->groupBy('status', 'error')
                ->get();
            foreach ($baris as $b) {
                $n = (int) $b->jumlah;
                $sebab[(string) $b->error] = ($sebab[(string) $b->error] ?? 0) + $n;
                $perJenis[(string) $b->status] = ($perJenis[(string) $b->status] ?? 0) + $n;
            }

            $maks = $this->batasGagal();
            if (array_sum($perJenis) < $maks) {
                return;
            }

            // `ditolak` = Google tidak bisa membaca gambarnya (kualitas), bukan konfigurasi salah.
            $note = ($perJenis['gagal'] ?? 0) > 0
                ? "OCR awan dihentikan untuk lembar ini setelah {$maks} kegagalan Google. "
                    .'Periksa kredensial, izin API Vision, dan jaringan server (lihat GET /api/admin/ocr-cloud).'
                : "OCR awan dihentikan untuk lembar ini karena Google tidak bisa membaca gambarnya {$maks} kali. "
                    .'Kemungkinan kualitas potongan, bukan konfigurasi.';

            AuditLog::create([
                'organization_id' => $user->organization_id,
                'entity_type' => 'ocr_cloud_sesi',
                'entity_id' => $sesi->id,
                'action' => AuditLog::ACTION_OCR_DIHENTIKAN,
                'new_data' => ['batas' => $maks, 'jenis' => $perJenis, 'sebab' => $sebab],
                'changed_by' => $user->id,
                'note' => $note,
            ]);

            Log::warning('OCR awan dihentikan untuk satu lembar karena kegagalan Google berulang.', [
                'calibration_session_id' => $sesi->id,
                'organization_id' => $user->organization_id,
                'batas' => $maks,
                'jenis' => $perJenis,
                'sebab' => $sebab,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
