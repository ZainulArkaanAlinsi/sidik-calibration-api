<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\AlatPelangganResource;
use App\Http\Resources\Pelanggan\PaketPelangganResource;
use App\Http\Resources\Pelanggan\SertifikatPelangganResource;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\Order;
use App\Services\Pelanggan\PaketAktifAlat;
use App\Services\Pelanggan\PengingatJatuhTempoPelanggan;
use App\Services\Pelanggan\RingkasanBerandaPelanggan;
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
 * masing-masing (`/alat`, `/sertifikat`, `/paket`). Pembatasan itu hanya untuk
 * DAFTAR: hitungan di `ringkasan` tidak ikut terpotong.
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
        $konteksStatus = AlatPelangganResource::konteks($perluPerhatian);
        $paketAktif = app(PaketAktifAlat::class)->untuk($lingkup, $perluPerhatian);

        // Yang berlaku saja — beranda bukan tempat riwayat.
        $sertifikatTerbaru = $lingkup->sertifikat()
            ->where('status', Certificate::STATUS_TERBIT)
            ->belumDigantikan()
            ->with(['session:id,equipment_id', 'revisionOf:id,nomor', 'revisiTerakhir'])
            ->orderByDesc('diterbitkan_pada')
            ->orderByDesc('id')
            ->limit(self::BATAS)
            ->get();

        $paketBerjalan = $lingkup->paketAktif()
            ->with($lingkup->relasiPaket())
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
                    // Additive (14 Nov 2026): hitungan PASTI, tidak ikut batas daftar di bawah
                    // (5 sertifikat, 3 paket). Definisi di `RingkasanBerandaPelanggan`.
                    ...app(RingkasanBerandaPelanggan::class)->hitung($lingkup),
                ],
                'perlu_perhatian' => $perluPerhatian
                    ->map(fn (Equipment $a) => (new AlatPelangganResource($a))
                        ->denganKonteks($konteksStatus[$a->id])
                        ->denganPaketAktif($paketAktif[$a->id])
                        ->resolve($request))
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
