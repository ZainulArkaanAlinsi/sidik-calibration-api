<?php

namespace App\Support;

use App\Models\Order;
use App\Models\PermintaanKalibrasi;
use App\Services\TahapPaket;

/**
 * Tahap permintaan kalibrasi yang dibaca pelanggan & lab (PL_Daftar_Permintaan,
 * §42): "Menunggu alat tiba", "Teknisi dijadwalkan", "Sedang dikalibrasi 7/12".
 *
 * Dihitung di SERVER supaya aplikasi lab & aplikasi pelanggan tidak punya dua
 * salinan aturan yang bisa berbeda versi — alasan yang sama dengan
 * `TahapPaket::garisWaktu`.
 *
 * Urutan pemeriksaan = urutan dunia nyata: selesai → sedang dikalibrasi →
 * alat sudah di lab → jadwal/pengiriman. Yang lebih maju selalu menang atas
 * yang lebih awal, jadi admin yang lupa menandai "alat tiba" tidak membuat
 * layar tertahan di "menunggu alat" sementara alatnya sudah dikalibrasi.
 */
final class TahapPermintaan
{
    public const LABEL = [
        'diajukan' => 'Diajukan',
        'ditolak' => 'Ditolak',
        'dibatalkan' => 'Dibatalkan',
        'menunggu_alat' => 'Menunggu alat tiba',
        'dalam_pengiriman' => 'Dalam pengiriman',
        'menunggu_jadwal' => 'Menunggu jadwal teknisi',
        'teknisi_dijadwalkan' => 'Teknisi dijadwalkan',
        'alat_di_lab' => 'Alat sudah di lab',
        'sedang_dikalibrasi' => 'Sedang dikalibrasi',
        'selesai' => 'Selesai',
    ];

    /** Tahap item paket yang dihitung "selesai" untuk progres x/y. */
    private const ITEM_SELESAI = [TahapPaket::SERTIFIKAT_TERBIT, TahapPaket::SIAP_DIAMBIL, TahapPaket::DISERAHKAN];

    /**
     * @return array{tahap: string, tahap_label: string, perlu_tindakan: bool, pesan_tindakan: string|null, progres: array{selesai: int, total: int}|null}
     */
    public static function untuk(PermintaanKalibrasi $p): array
    {
        $progres = self::progres($p);

        $tahap = match ($p->status) {
            PermintaanKalibrasi::STATUS_BARU => 'diajukan',
            PermintaanKalibrasi::STATUS_DITOLAK => 'ditolak',
            PermintaanKalibrasi::STATUS_DIBATALKAN => 'dibatalkan',
            default => self::tahapDiterima($p, $progres),
        };

        return [
            'tahap' => $tahap,
            'tahap_label' => self::LABEL[$tahap],
            'perlu_tindakan' => $tahap === 'menunggu_alat',
            'pesan_tindakan' => match ($tahap) {
                'menunggu_alat' => 'Antar atau kirim alatnya ke lab. Kalau lewat kurir, isi nomor resinya.',
                'diajukan' => 'Menunggu ditinjau tim lab.',
                default => null,
            },
            'progres' => $progres,
        ];
    }

    /**
     * Potongan respons yang SAMA untuk resource lab & resource pelanggan.
     * Ditaruh di sini, bukan di salah satu resource: resource pelanggan tidak
     * boleh memakai ulang resource internal (aturan Modul Pelanggan butir 2).
     * Isinya tidak memuat data internal apa pun — cuma tahap, resi yang diisi
     * pelanggan sendiri, dan jadwal yang memang untuk dia.
     *
     * @return array<string, mixed>
     */
    public static function bentuk(PermintaanKalibrasi $p): array
    {
        $tahap = self::untuk($p);

        return [
            'tahap' => $tahap['tahap'],
            'tahap_label' => $tahap['tahap_label'],
            'perlu_tindakan' => $tahap['perlu_tindakan'],
            'pesan_tindakan' => $tahap['pesan_tindakan'],
            'progres' => $tahap['progres'],
            'resi' => filled($p->nomor_resi) ? [
                'kurir' => $p->kurir,
                'nomor' => $p->nomor_resi,
                'diisi_pada' => $p->resi_diisi_pada?->toIso8601ZuluString(),
            ] : null,
            'jadwal' => $p->jadwal_pada !== null ? [
                'pada' => $p->jadwal_pada->toIso8601ZuluString(),
                'lokasi' => $p->jadwal_lokasi,
                'catatan' => $p->jadwal_catatan,
            ] : null,
            'alat_tiba_pada' => $p->alat_tiba_pada?->toIso8601ZuluString(),
        ];
    }

    /** Resi boleh diisi/diubah pelanggan cuma di tahap ini (§42 B5). */
    public static function bolehIsiResi(PermintaanKalibrasi $p): bool
    {
        return $p->status === PermintaanKalibrasi::STATUS_DITERIMA
            && $p->metode_pengantaran === PermintaanKalibrasi::METODE_DIANTAR_SENDIRI
            && in_array(self::untuk($p)['tahap'], ['menunggu_alat', 'dalam_pengiriman'], true);
    }

    /** @param  array{selesai: int, total: int}|null  $progres */
    private static function tahapDiterima(PermintaanKalibrasi $p, ?array $progres): string
    {
        $order = $p->relationLoaded('order') ? $p->order : $p->order()->with('items.sesiTerakhir.certificate')->first();

        if ($order?->status === Order::STATUS_SELESAI
            || ($progres !== null && $progres['total'] > 0 && $progres['selesai'] === $progres['total'])) {
            return 'selesai';
        }

        if ($order !== null) {
            $tahapPaket = app(TahapPaket::class);

            foreach ($order->items as $item) {
                if ($tahapPaket->untukItem($item) !== TahapPaket::DITERIMA) {
                    return 'sedang_dikalibrasi';
                }
            }
        }

        if ($p->alat_tiba_pada !== null) {
            return 'alat_di_lab';
        }

        if ($p->metode_pengantaran === PermintaanKalibrasi::METODE_DIAMBIL_LAB) {
            return $p->jadwal_pada !== null ? 'teknisi_dijadwalkan' : 'menunggu_jadwal';
        }

        return filled($p->nomor_resi) ? 'dalam_pengiriman' : 'menunggu_alat';
    }

    /** @return array{selesai: int, total: int}|null */
    private static function progres(PermintaanKalibrasi $p): ?array
    {
        if ($p->status !== PermintaanKalibrasi::STATUS_DITERIMA || ! $p->relationLoaded('order') || $p->order === null) {
            return null;
        }

        $tahapPaket = app(TahapPaket::class);
        $items = $p->order->items;

        return [
            'selesai' => $items->filter(fn ($i) => in_array($tahapPaket->untukItem($i), self::ITEM_SELESAI, true))->count(),
            'total' => $items->count(),
        ];
    }

    /**
     * Relasi yang WAJIB dimuat pemanggil supaya tahap & progres tidak jadi N+1.
     *
     * @return list<string>
     */
    public static function relasi(): array
    {
        return ['order.items.sesiTerakhir.certificate'];
    }
}
