<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\CalibrationSession;

/**
 * Riwayat persetujuan satu sesi — tiap penolakan (beserta alasan dan kolom yang
 * ditandai), pengajuan ulang, persetujuan, dan pengesahan — dibaca dari
 * `audit_logs`.
 *
 * ## Kenapa dari jejak audit, bukan tabel baru
 *
 * Sesi cuma menyimpan alasan penolakan TERAKHIR (`catatan_revisi`); begitu
 * ditolak lagi, kolom itu ditimpa. Tapi setiap `update()` lewat model sudah
 * dicatat trait `Diaudit` — termasuk penolakan, yang sengaja lewat model (lihat
 * `CalibrationController::reject()`). Jadi riwayatnya SUDAH ADA dan lengkap;
 * yang belum ada cuma pembacanya. Tabel baru berarti dua sumber untuk satu
 * kebenaran, dan yang satu bisa tertinggal tanpa error.
 *
 * ## Cara baca: diputar ulang, bukan dibaca baris per baris
 *
 * `Diaudit` hanya menyimpan kolom yang BERUBAH. Penolakan kedua dengan alasan
 * yang kebetulan sama persis tidak membawa `catatan_revisi` di `new_data`-nya.
 * Karena itu baris-baris audit diputar ulang menjadi keadaan sesi, dan setiap
 * perubahan status dibaca dari keadaan saat itu.
 *
 * Akses: hanya admin & super admin (keputusan pemilik proyek 6 Okt 2026) —
 * penjaganya di rute, bukan di sini.
 */
final class RiwayatPersetujuanSesi
{
    /** Jenis peristiwa per status tujuan. */
    private const JENIS = [
        CalibrationSession::STATUS_MENUNGGU_APPROVAL => 'diajukan',
        CalibrationSession::STATUS_PERLU_REVISI => 'ditolak',
        CalibrationSession::STATUS_MENUNGGU_PENGESAHAN => 'menunggu_pengesahan',
        CalibrationSession::STATUS_DISETUJUI => 'disetujui',
        CalibrationSession::STATUS_DRAFT => 'kembali_ke_draft',
    ];

    /**
     * @return list<array{jenis: string, status: string, status_sebelumnya: string|null, waktu: string|null, oleh: array{id: int, nama: string}|null, alasan?: string|null, kolom?: list<string>}>
     */
    public static function untuk(CalibrationSession $sesi): array
    {
        $baris = AuditLog::query()
            ->where('entity_type', $sesi->getTable())
            ->where('entity_id', $sesi->getKey())
            ->whereIn('action', [AuditLog::ACTION_DIBIKIN, AuditLog::ACTION_DIUBAH])
            ->with('pelaku:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $keadaan = [];
        $hasil = [];

        foreach ($baris as $b) {
            $baru = is_array($b->new_data) ? $b->new_data : [];
            $statusLama = $keadaan['status'] ?? null;
            $keadaan = array_replace($keadaan, $baru);

            $status = $baru['status'] ?? null;
            if (! is_string($status) || $status === $statusLama || ! isset(self::JENIS[$status])) {
                continue;
            }

            // Sesi asli LAHIR sebagai draft (`CalibrationController::store`):
            // baris `dibikin` itu kelahiran, bukan "kembali ke draft". Tanpa
            // penjaga ini tiap riwayat diawali peristiwa palsu (tinjauan 6 Okt).
            if ($status === CalibrationSession::STATUS_DRAFT && $statusLama === null) {
                continue;
            }

            $jenis = self::JENIS[$status];
            if ($jenis === 'diajukan' && $statusLama === CalibrationSession::STATUS_PERLU_REVISI) {
                $jenis = 'diajukan_ulang';
            }

            $peristiwa = [
                'jenis' => $jenis,
                'status' => $status,
                'status_sebelumnya' => $statusLama,
                'waktu' => $b->created_at?->toIso8601String(),
                // Nama saja — tanpa email/ID pegawai. Null = perubahan dari
                // sistem (queue/command), bukan orang.
                'oleh' => $b->pelaku === null ? null : ['id' => (int) $b->pelaku->id, 'nama' => (string) $b->pelaku->name],
            ];

            if ($status === CalibrationSession::STATUS_PERLU_REVISI) {
                $alasan = $keadaan['catatan_revisi'] ?? null;
                $peristiwa['alasan'] = is_string($alasan) ? $alasan : null;
                $peristiwa['kolom'] = self::kolom($keadaan['revisi_field'] ?? null);
            }

            $hasil[] = $peristiwa;
        }

        return $hasil;
    }

    /**
     * `revisi_field` tersimpan dalam dua bentuk di jejak audit: string JSON di
     * `new_data` (nilai mentah `getChanges()`) dan array di `old_data` (nilai
     * ber-cast). Dibakukan jadi daftar kode.
     *
     * @return list<string>
     */
    public static function kolom(mixed $nilai): array
    {
        if (is_string($nilai)) {
            $nilai = json_decode($nilai, true);
        }

        if (! is_array($nilai)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $k): ?string => is_scalar($k) && (string) $k !== '' ? (string) $k : null,
            $nilai,
        )));
    }
}
