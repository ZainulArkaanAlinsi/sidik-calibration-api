<?php

namespace App\Services\Vision;

use App\Models\CalibrationSession;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Hitungan LUNAK sel unggahan yang sudah sampai ke Google, untuk `OcrSelUnggahController`.
 *
 * Satu hitungan per sesi kerja (`maks_sel_unggah_per_lembar`, TTL 30 hari) atau, tanpa sesi, per akun per
 * hari (`maks_sel_unggah_tanpa_sesi_per_hari`). Disimpan di `Cache`: tidak dikunci, bisa melewati batas
 * sebanyak permintaan yang berjalan bersamaan, dan hilang bila cache dibersihkan. Ia pagar biaya, BUKAN
 * catatan resmi; catatan resminya baris `worksheet_extraction_logs` (jatah panggilan per sesi di
 * `JatahOcrAwan`, pagar yang keras). Hanya sel yang benar-benar terbaca Google yang dihitung.
 */
final class HitunganSelUnggah
{
    private const HARI_SESI = 30;

    public function batas(?CalibrationSession $sesi): int
    {
        return max(0, $sesi !== null
            ? (int) config('ocr.awan_sel.maks_sel_unggah_per_lembar', 160)
            : (int) config('ocr.awan_sel.maks_sel_unggah_tanpa_sesi_per_hari', 240));
    }

    public function terpakai(?CalibrationSession $sesi, User $user): int
    {
        return max(0, (int) Cache::get($this->kunci($sesi, $user), 0));
    }

    public function sisa(?CalibrationSession $sesi, User $user): int
    {
        return max(0, $this->batas($sesi) - $this->terpakai($sesi, $user));
    }

    /** Gagal mencatat TIDAK boleh menghilangkan hasil yang sudah dibayar: dilaporkan, tidak dilempar. */
    public function catat(?CalibrationSession $sesi, User $user, int $jumlah): void
    {
        if ($jumlah < 1) {
            return;
        }

        try {
            $kunci = $this->kunci($sesi, $user);

            Cache::add($kunci, 0, $sesi !== null ? now()->addDays(self::HARI_SESI) : now()->addDays(2));
            Cache::increment($kunci, $jumlah);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function kunci(?CalibrationSession $sesi, User $user): string
    {
        return $sesi !== null
            ? 'ocr_sel_unggah.sesi.'.$sesi->id
            : 'ocr_sel_unggah.hari.'.$user->id.'.'.now()->format('Ymd');
    }
}
