<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\AlatPelangganResource;
use App\Http\Resources\Pelanggan\PaketPelangganResource;
use App\Http\Resources\Pelanggan\SertifikatPelangganResource;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\Order;
use App\Services\Pelanggan\PengingatJatuhTempoPelanggan;
use App\Support\Pelanggan\Konteks;
use App\Support\Pelanggan\LingkupData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /beranda` — SATU panggilan untuk layar pertama aplikasi pelanggan.
 *
 * Satu, bukan lima: layar pertama di HP dengan sinyal lapangan yang lemah
 * tidak boleh menunggu lima perjalanan bolak-balik sebelum menampilkan apa
 * pun. Tiap bagian dibatasi jumlahnya; daftar lengkapnya ada di rutenya
 * masing-masing (`/alat`, `/sertifikat`, `/paket`).
 *
 * Urutan bagian = urutan kepentingan di layar: yang PERLU TINDAKAN (alat
 * lewat/segera jatuh tempo) lebih dulu dari yang sekadar kabar (sertifikat
 * terbaru).
 */
class BerandaController extends Controller
{
    private const BATAS = 5;

    public function __invoke(Request $request, Konteks $konteks): JsonResponse
    {
        $lingkup = new LingkupData($konteks);
        $jendela = PengingatJatuhTempoPelanggan::TANGGA_HARI[0];
        $perusahaan = $lingkup->perusahaan();

        $alatAktif = $lingkup->alat()->where('status', Equipment::STATUS_AKTIF);

        $jumlahLewat = (clone $alatAktif)->overdue()->count();
        $jumlahSegera = (clone $alatAktif)
            ->whereNotNull('tanggal_jatuh_tempo')
            ->whereDate('tanggal_jatuh_tempo', '>=', now()->startOfDay())
            ->whereDate('tanggal_jatuh_tempo', '<=', now()->addDays($jendela))
            ->count();

        $perluPerhatian = (clone $alatAktif)
            ->whereNotNull('tanggal_jatuh_tempo')
            ->whereDate('tanggal_jatuh_tempo', '<=', now()->addDays($jendela))
            ->orderBy('tanggal_jatuh_tempo')
            ->limit(self::BATAS)
            ->get();

        $sertifikatTerbaru = $lingkup->sertifikat()
            ->with(['session:id,equipment_id', 'revisionOf:id,nomor'])
            ->orderByDesc('diterbitkan_pada')
            ->orderByDesc('id')
            ->limit(self::BATAS)
            ->get();

        $paketBerjalan = $lingkup->paket()
            ->where('status', '!=', Order::STATUS_SELESAI)
            ->with([
                'items' => fn ($q) => $q->select(['id', 'order_id', 'equipment_id', 'tahap_fisik', 'tahap_fisik_pada', 'diserahkan_kepada']),
                'items.equipment' => fn ($q) => $q->withTrashed()->select(['id', 'nama_alat', 'merk', 'serial_number']),
                'items.sesiTerakhir',
                'items.sesiTerakhir.certificate',
            ])
            ->orderByDesc('tanggal_masuk')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        return response()->json([
            'data' => [
                'perusahaan' => [
                    'id' => $perusahaan->id,
                    'nama' => $perusahaan->nama,
                ],
                'ringkasan' => [
                    'jumlah_alat' => (clone $alatAktif)->count(),
                    'lewat_jatuh_tempo' => $jumlahLewat,
                    'segera_jatuh_tempo' => $jumlahSegera,
                    'paket_berjalan' => $lingkup->paket()->where('status', '!=', Order::STATUS_SELESAI)->count(),
                    'notifikasi_belum_dibaca' => $request->user()->unreadNotifications()->count(),
                ],
                'perlu_perhatian' => $perluPerhatian
                    ->map(fn (Equipment $a) => (new AlatPelangganResource($a))->resolve($request))
                    ->values(),
                'sertifikat_terbaru' => $sertifikatTerbaru
                    ->map(fn (Certificate $c) => (new SertifikatPelangganResource($c))->resolve($request))
                    ->values(),
                'paket_berjalan' => $paketBerjalan
                    ->map(fn (Order $o) => (new PaketPelangganResource($o))->resolve($request))
                    ->values(),
            ],
            'meta' => ['jendela_segera_hari' => $jendela],
        ]);
    }
}
