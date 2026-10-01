<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\FotoPelanggan;
use App\Models\KoreksiPelanggan;
use App\Models\PermintaanKalibrasiItem;
use App\Services\Pelanggan\FotoPelangganLayanan;
use App\Support\Pelanggan\Konteks;
use App\Support\Pelanggan\LingkupData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto pelat nama (§42 B3) — unggah, lihat, hapus.
 *
 * Tiap ID dicari DI DALAM perusahaan pemanggil (`customer_id` dari `Konteks`,
 * tidak pernah dari request) sebelum validasi berkas, jadi foto & pemilik
 * milik perusahaan lain dijawab 404, bukan 422 (aturan Modul Pelanggan 3 & 4).
 *
 * Unggah untuk item permintaan ada di `PermintaanController::unggahFotoItem`
 * supaya rutenya duduk di samping rute permintaan lain.
 */
class FotoController extends Controller
{
    /**
     * Satu aturan berkas untuk semua pintu unggah: gambar jpg/png/webp ≤ 5 MB.
     * HP mengompres dulu & membuang EXIF/GPS; server tetap membatasi ukuran.
     *
     * @return list<string>
     */
    public static function aturanBerkas(): array
    {
        return ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.FotoPelanggan::UKURAN_MAKS_KB];
    }

    public function unggahAlat(Request $request, Konteks $konteks, FotoPelangganLayanan $layanan, string $alat): JsonResponse
    {
        $lingkup = new LingkupData($konteks);
        /** @var Equipment $baris */
        $baris = $lingkup->alat()->whereKey($alat)->firstOrFail();

        $request->validate(['foto' => self::aturanBerkas()]);

        $foto = $layanan->simpan(
            $baris, $baris->fotoPelat(), (int) $baris->organization_id, (int) $konteks->customerId,
            $request->file('foto'), $request->user(),
        );

        return response()->json(['data' => FotoPelangganLayanan::bentukPelanggan($foto)], 201);
    }

    public function unggahKoreksi(Request $request, Konteks $konteks, FotoPelangganLayanan $layanan, string $koreksi): JsonResponse
    {
        /** @var KoreksiPelanggan $baris */
        $baris = KoreksiPelanggan::query()
            ->where('customer_id', $konteks->customerId)
            ->whereKey($koreksi)
            ->firstOrFail();

        $request->validate(['foto' => self::aturanBerkas()]);

        if (! $baris->masihMenunggu()) {
            return response()->json(['message' => 'Foto cuma bisa ditambahkan selama koreksinya masih menunggu ditinjau.'], 422);
        }

        $foto = $layanan->simpan(
            $baris, $baris->foto(), (int) $baris->organization_id, (int) $konteks->customerId,
            $request->file('foto'), $request->user(),
        );

        return response()->json(['data' => FotoPelangganLayanan::bentukPelanggan($foto)], 201);
    }

    public function tampil(Konteks $konteks, string $foto): StreamedResponse
    {
        $baris = $this->milikSendiri($konteks, $foto);

        abort_unless(Storage::disk('arsip')->exists($baris->path), 404);

        return Storage::disk('arsip')->response($baris->path, null, [
            'Content-Type' => $baris->mime,
            // Privat: jangan disimpan cache bersama (proxy kantor, CDN).
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function hapus(Konteks $konteks, FotoPelangganLayanan $layanan, string $foto): Response|JsonResponse
    {
        $baris = $this->milikSendiri($konteks, $foto);
        $pemilik = $baris->pemilik;

        $boleh = match (true) {
            $pemilik instanceof Equipment => true,
            $pemilik instanceof PermintaanKalibrasiItem => (bool) $pemilik->permintaan?->masihBaru(),
            $pemilik instanceof KoreksiPelanggan => $pemilik->masihMenunggu(),
            default => false,
        };

        if (! $boleh) {
            return response()->json([
                'message' => 'Foto ini sudah jadi bagian dari data yang sedang/sudah diproses lab, jadi tidak bisa dihapus.',
            ], 422);
        }

        $layanan->hapus($baris);

        return response()->noContent();
    }

    private function milikSendiri(Konteks $konteks, string $id): FotoPelanggan
    {
        return FotoPelanggan::query()
            ->where('customer_id', $konteks->customerId)
            ->where('organization_id', (new LingkupData($konteks))->perusahaan()->organization_id)
            ->whereKey($id)
            ->firstOrFail();
    }
}
