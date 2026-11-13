<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Services\Pencarian\CariDokumenLab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Penyaring TAMBAHAN daftar sesi `GET /calibrations`: kata cari, rentang
 * tanggal kalibrasi, dan pelanggan. Dipisah dari controller supaya controller
 * tetap tipis dan aturannya bisa diuji sendiri.
 *
 * Kueri yang masuk SUDAH dikurung `organization_id` (dan `teknisi_id` untuk
 * teknisi) oleh pemanggil; di sini hanya menyempitkan.
 */
final class DaftarSesiKalibrasi
{
    /**
     * Relasi untuk bentuk ringan. Tanpa `uncertaintyCalculations`, standar,
     * metode, ruangan, reviewer, dan organisasi: yang dibaca layar daftar
     * cuma alat, PT, teknisi, dan sertifikat. Alat/PT yang sudah dihapus lunak
     * tetap dimuat -- sesinya riwayat yang sah.
     *
     * @return array<int|string, mixed>
     */
    public static function relasiRingan(): array
    {
        return [
            'equipment' => fn ($alat) => $alat->withTrashed()
                ->select('id', 'customer_id', 'nama_alat', 'nama_alat_kemampuan', 'serial_number'),
            'equipment.customer' => fn ($pt) => $pt->withTrashed()->select('id', 'nama'),
            'teknisi:id,name',
            'certificate:id,calibration_session_id,nomor,status,diterbitkan_pada',
        ];
    }

    public function __construct(private readonly CariDokumenLab $cari) {}

    /**
     * @param  Builder<CalibrationSession>  $kueri
     * @param  array{q?: ?string, dari?: ?string, sampai?: ?string, customer_id?: int|string|null}  $saring
     * @return Builder<CalibrationSession>
     */
    public function terapkan(Builder $kueri, int $organisasiId, array $saring): Builder
    {
        $q = trim((string) ($saring['q'] ?? ''));

        if ($q !== '') {
            $this->cari->sesi($kueri, $organisasiId, $q);
        }

        if (filled($saring['dari'] ?? null)) {
            $kueri->where('calibration_sessions.tanggal_kalibrasi', '>=', $saring['dari']);
        }

        if (filled($saring['sampai'] ?? null)) {
            // Setengah terbuka (`< sampai + 1 hari`): benar untuk kolom DATE di
            // MySQL maupun nilai bertanda jam di SQLite.
            $kueri->where(
                'calibration_sessions.tanggal_kalibrasi',
                '<',
                Carbon::parse($saring['sampai'])->addDay()->toDateString(),
            );
        }

        if (filled($saring['customer_id'] ?? null)) {
            // Pemilik dibaca dari alat sesinya, termasuk alat yang dihapus
            // lunak (sama dengan `LingkupData::sertifikat()` di sisi pelanggan).
            $kueri->whereHas(
                'equipment',
                fn (Builder $alat) => $alat->withTrashed()->where('customer_id', (int) $saring['customer_id']),
            );
        }

        return $kueri;
    }
}
