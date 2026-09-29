<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sinyal "ada data yang berubah" buat realtime sync mobile ↔ desktop.
 *
 * Di-broadcast ke channel privat per organisasi. Payload sengaja TIPIS (jenis,
 * aksi, id) — klien (HP & panel desktop) cukup nge-refresh data lewat REST
 * biasa begitu dapat sinyal ini, jadi dua-duanya nunjukin data yang sama tanpa
 * nunggu di-refresh manual. Angka/isi sensitif nggak ikut di payload broadcast.
 *
 * ShouldBroadcastNow (bukan queued): sinyalnya tipis & harus sampai cepat, plus
 * biar nggak numpuk job broadcast di antrean tiap kali data berubah.
 */
class PerubahanDataOrganisasi implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $jenis  kalibrasi | sertifikat | alat | folder | penugasan | paket | ...
     * @param  string  $aksi  dibuat | diubah | disetujui | ditolak | diterbitkan | disahkan | dikembalikan | ditarik
     * @param  int|null  $id  id record yang berubah (opsional)
     */
    public function __construct(
        public int $organizationId,
        public string $jenis,
        public string $aksi,
        public ?int $id = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('organisasi.'.$this->organizationId)];
    }

    /**
     * Siarkan tanpa pernah menggagalkan permintaan yang memicunya.
     *
     * Event ini ShouldBroadcastNow — SINKRON. Kalau Reverb mati, exception-nya
     * naik sampai ke respons, dan pengesahan / serah terima / laporan progres
     * yang SUDAH tersimpan dijawab HTTP 500. Siaran cuma pemicu refresh layar
     * perangkat lain; kehilangan satu siaran berarti HP lain baru melihatnya di
     * tarikan berkala berikutnya, bukan data yang hilang. Pola yang sama dengan
     * `CalibrationController::siarkan()`.
     */
    public static function siarkanAman(int $organizationId, string $jenis, string $aksi, ?int $id = null): void
    {
        try {
            static::dispatch($organizationId, $jenis, $aksi, $id);
        } catch (\Throwable $e) {
            Log::warning('Siaran perubahan data gagal.', [
                'organization_id' => $organizationId,
                'jenis' => $jenis,
                'aksi' => $aksi,
                'id' => $id,
                'pesan' => $e->getMessage(),
            ]);
        }
    }

    public function broadcastAs(): string
    {
        return 'data.berubah';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'jenis' => $this->jenis,
            'aksi' => $this->aksi,
            'id' => $this->id,
        ];
    }
}
