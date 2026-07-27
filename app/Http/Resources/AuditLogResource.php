<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris riwayat perubahan (Keputusan 4).
 *
 * Bentuknya dibikin buat dibaca MANUSIA, bukan buat rekonstruksi mesin: yang
 * dicari asesor itu "siapa ngubah apa, kapan, dari berapa ke berapa" — bukan
 * dump baris database. Makanya `perubahan` disusun per-kolom (`lama` → `baru`)
 * ketimbang dua objek utuh yang harus dibandingin sendiri di layar.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entitas' => [
                // Nama TABEL (mis. `standards`), bukan nama kelas PHP — nama kelas
                // bisa berubah kalau kodenya di-refactor, dan riwayat lama nggak
                // boleh ikut basi gara-gara itu.
                'tipe' => $this->entity_type,
                'id' => $this->entity_id,
            ],
            'aksi' => $this->action,
            'perubahan' => $this->perubahanPerKolom(),
            'pelaku' => $this->pelaku ? [
                'id' => $this->pelaku->id,
                'nama' => $this->pelaku->name,
                'role' => $this->pelaku->role,
            ] : null,
            // `null` artinya perubahannya BUKAN dari orang yang login — job di
            // queue, command artisan, atau seeder. Bukan data hilang.
            'oleh_sistem' => $this->changed_by === null,
            'catatan' => $this->note,
            'dicatat_pada' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Perubahan disusun per kolom: `{ nama: { lama: …, baru: … } }`.
     *
     * @return array<string, array{lama: mixed, baru: mixed}>
     */
    private function perubahanPerKolom(): array
    {
        $lama = $this->old_data ?? [];
        $baru = $this->new_data ?? [];

        $kolom = array_unique([...array_keys($lama), ...array_keys($baru)]);
        $hasil = [];

        foreach ($kolom as $k) {
            $hasil[$k] = [
                'lama' => $lama[$k] ?? null,
                'baru' => $baru[$k] ?? null,
            ];
        }

        return $hasil;
    }
}
