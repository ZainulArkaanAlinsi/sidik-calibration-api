<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Log;

/**
 * Menautkan sesi kalibrasi BARU ke item order alatnya (`calibration_sessions.order_item_id`).
 *
 * ## Kenapa ada
 *
 * Sampai 13 Nov 2026 kolom itu tidak ditulis jalur mana pun di `app/`. Akibatnya
 * `TahapPaket` selalu menurunkan "Alat diterima lab" untuk sesi buatan aplikasi,
 * kabar tahap (`KabarTahapAlat`) tidak pernah terkirim untuk tahap turunan, dan dua
 * penjaga order lab yang bergantung pada `has('calibrationSessions')` ikut mati
 * (`OrderController::destroy` yang menolak menghapus order bersesi, dan
 * `gantiItems` yang mempertahankan item bersesi).
 *
 * ## Aturan
 *
 * - **Pembuatan sesi TIDAK BOLEH gagal karena penautan.** [tentukan] dan [catat]
 *   menelan semua galat (log peringatan: `equipment_id` + kelas galat, tanpa pesan
 *   galat) dan jatuh ke "tanpa tautan". Penautan itu pelengkap; lembar kerja teknisi
 *   yang sudah diisi di lapangan tidak boleh hilang karenanya.
 * - Hanya saat sesi DIBUAT (`store`, `simpanAutoclave`). Sesi lama TIDAK di-backfill
 *   (itu tulis ke produksi -- keputusan terpisah, lihat `docs/permintaan-user-7.md` §57).
 * - Nilai yang DIKIRIM klien (`order_item_id`, sudah divalidasi `ItemOrderMilikAlat`)
 *   dipakai apa adanya dan tidak pernah ditimpa pilihan otomatis.
 * - Otomatis: item order AKTIF untuk alat itu. Aktif =
 *     - item belum `diserahkan` (satu-satunya tahap yang disimpan dan mengakhiri
 *       perjalanan di `TahapPaket`), dan
 *     - order belum `selesai` dan tidak `dibatalkan` (sama dengan `LingkupData::paketAktif()`),
 *       dan tidak dihapus lunak, dan
 *     - pelanggan order = pelanggan alat, organisasi order = organisasi sesi.
 * - **Ditautkan HANYA bila TEPAT SATU kandidat aktif.** Dua atau lebih = TIDAK ditautkan
 *   (`null`, perilaku lama) dan semua kandidat dicatat di jejak audit sesi, supaya admin
 *   bisa menautkannya kemudian. Alasannya: kode tidak pernah menulis `selesai` dan
 *   `diserahkan` jarang dicatat, jadi alat yang dikalibrasi ulang tahun depan punya order
 *   lama yang masih "aktif"; memilih yang "terlama" (atau terbaru) berarti menebak, dan tebakan
 *   yang salah mengirim kabar ke pelanggan untuk order yang salah.
 * - Tidak ada kandidat: `null` (perilaku lama). Tidak ada migrasi.
 */
class PenautanSesiKeOrder
{
    public const CATATAN_OTOMATIS = 'Tertaut otomatis ke item order ';

    public const CATATAN_KLIEN = 'Tertaut atas kiriman klien ke item order ';

    public const CATATAN_AMBIGU = 'Tidak tertaut otomatis: ';

    /** Berapa nomor kandidat yang disebut di catatan (sisanya cukup dihitung). */
    private const BATAS_NOMOR_DI_CATATAN = 10;

    /**
     * Tidak pernah melempar: galat apa pun = tanpa tautan.
     *
     * @return array{order_item_id: int|null, catatan: string|null}
     */
    public function tentukan(int $organizationId, int $equipmentId, ?int $dariKlien = null): array
    {
        try {
            return $this->cari($organizationId, $equipmentId, $dariKlien);
        } catch (\Throwable $e) {
            $this->catatGagal($equipmentId, $e);

            return ['order_item_id' => null, 'catatan' => null];
        }
    }

    /**
     * Tulis catatan tautan di jejak audit sesi. Tidak pernah melempar. Tiga bentuk:
     * tertaut (satu baris `diubah`, nilai lama kosong -> item), ambigu (baris `diubah`
     * hanya berisi catatan: semua kandidat), atau tidak ada apa pun (no-op).
     *
     * @param  array{order_item_id: int|null, catatan: string|null}  $tautan
     */
    public function catat(CalibrationSession $sesi, array $tautan): void
    {
        try {
            $this->tulisCatatan($sesi, $tautan);
        } catch (\Throwable $e) {
            $this->catatGagal((int) $sesi->equipment_id, $e);
        }
    }

    /**
     * @return array{order_item_id: int|null, catatan: string|null}
     */
    protected function cari(int $organizationId, int $equipmentId, ?int $dariKlien): array
    {
        if ($dariKlien !== null) {
            $nomor = OrderItem::query()->with('order:id,nomor')->find($dariKlien)?->order?->nomor;

            return [
                'order_item_id' => $dariKlien,
                'catatan' => self::CATATAN_KLIEN.($nomor !== null ? "{$nomor} " : '')."(item #{$dariKlien}).",
            ];
        }

        $alat = Equipment::query()
            ->where('organization_id', $organizationId)
            ->whereKey($equipmentId)
            ->first(['id', 'customer_id']);

        if ($alat === null) {
            return ['order_item_id' => null, 'catatan' => null];
        }

        $kandidat = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.equipment_id', $alat->id)
            ->where(fn ($q) => $q
                ->whereNull('order_items.tahap_fisik')
                ->orWhere('order_items.tahap_fisik', '!=', TahapPaket::DISERAHKAN))
            ->where('orders.organization_id', $organizationId)
            ->where('orders.customer_id', $alat->customer_id)
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', [Order::STATUS_SELESAI, Order::STATUS_DIBATALKAN])
            // Urutan hanya supaya catatan ambigu stabil dibaca; TIDAK dipakai memilih.
            ->orderBy('orders.tanggal_masuk')
            ->orderBy('orders.id')
            ->orderBy('order_items.id')
            ->get(['order_items.id as item_id', 'orders.nomor as nomor_order']);

        if ($kandidat->isEmpty()) {
            return ['order_item_id' => null, 'catatan' => null];
        }

        if ($kandidat->count() > 1) {
            $daftar = $kandidat
                ->take(self::BATAS_NOMOR_DI_CATATAN)
                ->map(fn ($k): string => "{$k->nomor_order} (item #{$k->item_id})")
                ->implode(', ');
            $lebih = $kandidat->count() > self::BATAS_NOMOR_DI_CATATAN ? ' dan lainnya' : '';

            return [
                'order_item_id' => null,
                'catatan' => self::CATATAN_AMBIGU."ada {$kandidat->count()} item order aktif untuk alat ini: {$daftar}{$lebih}."
                    .' Tautkan manual setelah dipastikan order mana yang sedang dikerjakan.',
            ];
        }

        $pilihan = $kandidat->first();

        return [
            'order_item_id' => (int) $pilihan->item_id,
            'catatan' => self::CATATAN_OTOMATIS."{$pilihan->nomor_order} (item #{$pilihan->item_id}).",
        ];
    }

    /**
     * @param  array{order_item_id: int|null, catatan: string|null}  $tautan
     */
    protected function tulisCatatan(CalibrationSession $sesi, array $tautan): void
    {
        if ($tautan['catatan'] === null) {
            return;
        }

        $sesi->catatAudit(
            AuditLog::ACTION_DIUBAH,
            $tautan['order_item_id'] === null ? null : ['order_item_id' => null],
            $tautan['order_item_id'] === null ? null : ['order_item_id' => $tautan['order_item_id']],
            $tautan['catatan'],
        );
    }

    private function catatGagal(int $equipmentId, \Throwable $e): void
    {
        // Sengaja BUKAN `$e->getMessage()`: pesan galat database bisa memuat data pelanggan.
        Log::warning('Penautan sesi ke item order gagal; sesi tetap dibuat tanpa tautan.', [
            'equipment_id' => $equipmentId,
            'galat' => $e::class,
        ]);
    }
}
