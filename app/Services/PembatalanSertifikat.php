<?php

namespace App\Services;

use App\Events\PerubahanDataOrganisasi;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\User;
use App\Notifications\Pelanggan\SertifikatBerubah;
use App\Services\Pelanggan\PreferensiNotifikasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * BATALKAN sertifikat (§38, D1/D3/D4). Final — tidak ada "batalkan pembatalan"
 * (K38-3); kalau keliru, terbitkan dari kalibrasi baru.
 *
 * Tidak ada yang dihapus: baris & PDF-nya tetap diarsip untuk lab. Yang
 * berubah cuma status + siapa/kapan/kenapa, dan jadwal alatnya — lewat jalur
 * khusus `SinkronJadwalAlat::sesudahPembatalan()` yang boleh mengosongkan.
 */
class PembatalanSertifikat
{
    public function __construct(
        private readonly SinkronJadwalAlat $sinkron,
        private readonly PreferensiNotifikasi $preferensi,
    ) {}

    /**
     * Apa yang terjadi pada jadwal alat KALAU sertifikat ini dibatalkan —
     * untuk peringatan di modal konfirmasi (D3). Tidak menulis apa pun.
     *
     * @return array{jadwal_dikosongkan: bool, jatuh_ke: array{id: int, nomor: string|null, berlaku_sampai: string|null}|null}|null
     */
    public function dampak(Certificate $sertifikat): ?array
    {
        $alat = $sertifikat->session?->equipment;

        if (! $alat instanceof Equipment || ! $sertifikat->bisaDiubahStatusnya()) {
            return null;
        }

        $aktif = $this->sinkron->sertifikatAktif($alat);

        // Yang dibatalkan BUKAN sumber jadwal alat (ada sertifikat lain yang
        // lebih baru) — jadwalnya tidak tersentuh sama sekali.
        if ($aktif !== null && $aktif->id !== $sertifikat->id) {
            return ['jadwal_dikosongkan' => false, 'jatuh_ke' => null];
        }

        $sebelumnya = Certificate::query()
            ->where('organization_id', $alat->organization_id)
            ->where('status', Certificate::STATUS_TERBIT)
            ->whereKeyNot($sertifikat->id)
            ->whereNotNull('berlaku_sampai')
            ->whereHas('session', fn ($s) => $s->where('equipment_id', $alat->getKey()))
            ->belumDigantikan()
            ->orderByDesc('diterbitkan_pada')
            ->orderByDesc('id')
            ->first();

        return [
            'jadwal_dikosongkan' => $sebelumnya === null,
            'jatuh_ke' => $sebelumnya === null ? null : [
                'id' => $sebelumnya->id,
                'nomor' => $sebelumnya->nomor,
                'berlaku_sampai' => $sebelumnya->berlaku_sampai?->toDateString(),
            ],
        ];
    }

    /**
     * @throws ValidationException kalau bukan `terbit` atau sudah punya revisi
     */
    public function batalkan(Certificate $sertifikat, string $alasan, ?string $catatanPelanggan, User $oleh): Certificate
    {
        $sertifikat = DB::transaction(function () use ($sertifikat, $alasan, $catatanPelanggan, $oleh): Certificate {
            /** @var Certificate $baris */
            $baris = Certificate::query()->whereKey($sertifikat->getKey())->lockForUpdate()->firstOrFail();

            if (! $baris->bisaDiubahStatusnya()) {
                throw ValidationException::withMessages([
                    'sertifikat' => match (true) {
                        $baris->status === Certificate::STATUS_DIBATALKAN => 'Sertifikat ini sudah dibatalkan.',
                        $baris->status !== Certificate::STATUS_TERBIT => 'Sertifikat ini belum terbit.',
                        default => 'Sertifikat ini sudah punya revisi. Yang dibatalkan harus revisi terakhirnya.',
                    },
                ]);
            }

            $baris->update([
                'status' => Certificate::STATUS_DIBATALKAN,
                'dibatalkan_pada' => now(),
                'dibatalkan_oleh' => $oleh->id,
                'alasan_pembatalan' => $alasan,
                'catatan_pelanggan' => filled($catatanPelanggan) ? $catatanPelanggan : null,
            ]);

            // Di transaksi yang sama: sertifikat batal yang jadwal alatnya masih
            // menunjuk dia adalah persis keadaan yang D3 larang.
            $alat = $baris->session?->equipment?->fresh();

            if ($alat !== null) {
                $this->sinkron->sesudahPembatalan($alat);
            }

            return $baris;
        });

        PerubahanDataOrganisasi::siarkanAman($sertifikat->organization_id, 'sertifikat', 'dibatalkan', $sertifikat->id);

        $this->kabarinPelanggan($sertifikat);

        return $sertifikat;
    }

    private function kabarinPelanggan(Certificate $sertifikat): void
    {
        try {
            $pelanggan = $sertifikat->session?->equipment?->customer;

            if ($pelanggan === null) {
                return;
            }

            $penerima = $this->preferensi->anggotaAktif($pelanggan);

            if ($penerima->isNotEmpty()) {
                Notification::send($penerima, SertifikatBerubah::dibatalkan($sertifikat));
            }
        } catch (\Throwable $e) {
            Log::warning('Sertifikat dibatalkan, tapi ngabarin pelanggan gagal.', [
                'certificate_id' => $sertifikat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
