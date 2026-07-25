<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
     * @param  string  $jenis  kalibrasi | sertifikat | alat | folder | ...
     * @param  string  $aksi  dibuat | diubah | disetujui | ditolak | diterbitkan
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
