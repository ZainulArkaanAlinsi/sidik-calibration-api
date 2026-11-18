<?php

namespace App\Services;

use App\Models\AuditLog;

/**
 * Membaca alasan, pelaku, dan waktu SUATU aksi dari `audit_logs`.
 *
 * Arsip draf dan pembatalan penugasan tidak punya kolom `alasan_*` sendiri
 * (AGENTS.md: kolom baru pilihan terakhir). Alasannya hidup di jejak audit --
 * tempat yang memang dibaca asesor -- dan aplikasi membacanya lewat sini.
 * Satu sumber: tidak ada salinan kedua yang bisa berbeda dari jejaknya.
 *
 * Kueri SELALU dikurung `organization_id` pemanggil.
 */
final class JejakAlasan
{
    /**
     * Baris AKSI TERAKHIR per entitas.
     *
     * @param  list<int>  $ids
     * @return array<int, array{alasan: string|null, oleh: array{id: int|null, nama: string|null}, pada: string|null}>
     */
    public static function terakhir(int $organizationId, string $entityType, array $ids, string $aksi): array
    {
        if ($ids === []) {
            return [];
        }

        $hasil = [];

        // Terbaru dulu; baris pertama tiap entitas yang menang. Satu kueri
        // untuk satu halaman daftar (maks 50 id), bukan satu per baris.
        $baris = AuditLog::query()
            ->with('pelaku:id,name')
            ->where('organization_id', $organizationId)
            ->where('entity_type', $entityType)
            ->where('action', $aksi)
            ->whereIn('entity_id', $ids)
            ->orderByDesc('id')
            ->get();

        foreach ($baris as $log) {
            $id = (int) $log->entity_id;

            if (isset($hasil[$id])) {
                continue;
            }

            $hasil[$id] = [
                'alasan' => is_array($log->new_data) ? ($log->new_data['alasan'] ?? null) : null,
                'oleh' => ['id' => $log->pelaku?->id, 'nama' => $log->pelaku?->name],
                'pada' => $log->created_at?->toIso8601String(),
            ];
        }

        return $hasil;
    }
}
