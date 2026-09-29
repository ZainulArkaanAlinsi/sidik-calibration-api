<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\TahapPaket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu paket di layar pelacakan (artboard `SA_Pelacakan` & `PL_Detail_Alat`).
 *
 * Satu resource dipakai dua dunia — lab dan pelanggan — dan yang membedakan
 * keluarannya cuma satu: label tahapnya. `?untuk=pelanggan` (atau rute pelanggan
 * yang menyetel atributnya) memakai `LABEL_PELANGGAN`, yang tidak menyebut
 * "pengesahan" dan tidak pernah menulis "ditolak".
 *
 * Kenapa tidak dua resource: dua salinan bentuk JSON untuk data yang sama akan
 * berbeda diam-diam — satu ditambah field, yang lain lupa — dan yang menemukannya
 * adalah pelanggan yang layarnya kosong di satu kolom.
 *
 * @mixin Order
 */
class PaketLacakResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tahap = app(TahapPaket::class);
        $untukPelanggan = $request->attributes->get('untuk_pelanggan') === true
            || $request->query('untuk') === 'pelanggan';

        $label = $untukPelanggan ? TahapPaket::LABEL_PELANGGAN : TahapPaket::LABEL_INTERNAL;
        $tahapPaket = $tahap->untukPaket($this->items);

        return [
            'id' => $this->id,
            'nomor' => $this->nomor,
            'pelanggan' => $untukPelanggan ? null : $this->customer?->nama,

            'tanggal_masuk' => $this->tanggal_masuk?->toDateString(),
            'tanggal_janji_selesai' => $this->tanggal_janji_selesai?->toDateString(),

            // Dihitung di SERVER. Jam HP yang meleset — dan di lapangan itu biasa
            // — membuat "terlambat 2 hari" salah, dan angka itulah yang dipakai
            // memutuskan urutan kerja.
            'terlambat_hari' => $this->hitungTerlambat($tahapPaket),

            'tahap' => $tahapPaket,
            'tahap_label' => $label[$tahapPaket] ?? $tahapPaket,

            'jumlah_alat' => $this->items->count(),
            // Yang dilihat pertama di kartu daftar: berapa yang sudah tuntas.
            // "8/12 selesai" menjawab lebih banyak daripada nama tahapnya
            // sendiri, karena tahap paket selalu tahap yang paling tertinggal.
            'jumlah_selesai' => $this->items
                ->filter(fn (OrderItem $i): bool => in_array(
                    $tahap->untukItem($i),
                    [TahapPaket::SERTIFIKAT_TERBIT, TahapPaket::SIAP_DIAMBIL, TahapPaket::DISERAHKAN],
                    true,
                ))
                ->count(),

            'alat' => $this->items->map(fn (OrderItem $item): array => [
                'item_id' => $item->id,
                'nama' => $item->equipment?->nama_alat,
                'merk' => $item->equipment?->merk,
                'serial_number' => $item->equipment?->serial_number,

                // Inisial, bukan nama lengkap — sama dengan yang tercetak di
                // sertifikat. Di dunia pelanggan teknisinya tidak disebut sama
                // sekali: nama orang yang mengerjakan bukan informasi yang
                // pelanggan butuhkan, dan menampilkannya mengundang telepon
                // langsung ke teknisi di luar jalur.
                'teknisi' => $untukPelanggan ? null : $item->teknisi?->kode_teknisi,

                'tahap' => $tahap->untukItem($item),
                'tahap_label' => $label[$tahap->untukItem($item)] ?? null,

                'nomor_sesi' => $untukPelanggan ? null : $item->sesiTerakhir?->nomor_sesi,
                'sertifikat' => $item->sesiTerakhir?->certificate === null ? null : [
                    'id' => $item->sesiTerakhir->certificate->id,
                    'nomor' => $item->sesiTerakhir->certificate->nomor,
                    'berlaku_sampai' => $item->sesiTerakhir->certificate->berlaku_sampai?->toDateString(),
                ],

                // Keputusan PASS/FAIL disembunyikan dari pelanggan SELAMA
                // sertifikatnya belum terbit. FAIL itu temuan yang sah dan
                // sertifikatnya tetap keluar — tapi pelanggan yang melihat "FAIL"
                // sebelum dokumen resminya ada akan menelepon menanyakan sesuatu
                // yang belum bisa dijawab dengan angka.
                'keputusan' => $untukPelanggan && $tahap->untukItem($item) !== TahapPaket::SERTIFIKAT_TERBIT
                    ? null
                    : $item->sesiTerakhir?->keputusan,

                'diserahkan_kepada' => $item->diserahkan_kepada,
                'tahap_fisik_pada' => $item->tahap_fisik_pada?->toIso8601String(),
                'kondisi_terima' => $item->kondisi_terima,
                'catatan' => $item->catatan,
            ])->values(),
        ];
    }

    /**
     * Terlambat dihitung dari janji selesai, dan berhenti dihitung begitu
     * alatnya diserahkan.
     *
     * Kalau tidak berhenti, paket yang diserahkan terlambat sebulan lalu akan
     * terus menampilkan "terlambat 34 hari" selamanya — dan daftar "terlambat"
     * jadi penuh pekerjaan yang sudah selesai, sampai tidak ada yang membukanya
     * lagi.
     */
    private function hitungTerlambat(string $tahapPaket): ?int
    {
        if ($this->tanggal_janji_selesai === null || $tahapPaket === TahapPaket::DISERAHKAN) {
            return null;
        }

        $selisih = $this->tanggal_janji_selesai->diffInDays(now(), false);

        return $selisih > 0 ? (int) $selisih : null;
    }
}
