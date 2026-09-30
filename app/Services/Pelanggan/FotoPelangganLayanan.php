<?php

namespace App\Services\Pelanggan;

use App\Models\FotoPelanggan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Simpan & hapus foto pelat nama dari aplikasi pelanggan.
 *
 * Satu tempat untuk tiga pemilik (alat, item permintaan, koreksi) supaya
 * aturannya — maks 3 per pemilik, disk arsip privat, berkas dibuang cuma
 * kalau tidak ada baris lain yang memakainya — tidak pernah punya tiga versi.
 */
class FotoPelangganLayanan
{
    /**
     * @param  MorphMany<FotoPelanggan, Model>  $relasi  relasi foto milik `$pemilik`
     *
     * @throws ValidationException kalau sudah 3 foto
     */
    public function simpan(
        Model $pemilik,
        MorphMany $relasi,
        int $organizationId,
        int $customerId,
        UploadedFile $berkas,
        User $oleh,
    ): FotoPelanggan {
        $ekstensi = strtolower($berkas->extension() ?: 'jpg');
        $path = "foto-pelanggan/{$customerId}/".Str::uuid()->toString().".{$ekstensi}";

        return DB::transaction(function () use ($pemilik, $relasi, $organizationId, $customerId, $berkas, $oleh, $path): FotoPelanggan {
            // Dikunci di baris pemiliknya: dua unggahan berbarengan dari dua HP
            // tidak boleh sama-sama melihat "baru 2" lalu menjadikannya 4.
            $pemilik->newQuery()->whereKey($pemilik->getKey())->lockForUpdate()->first();

            if ($relasi->count() >= FotoPelanggan::BATAS_PER_PEMILIK) {
                throw ValidationException::withMessages([
                    'foto' => 'Maksimal '.FotoPelanggan::BATAS_PER_PEMILIK.' foto. Hapus salah satu dulu kalau mau mengganti.',
                ]);
            }

            // `put()` diperiksa — disk di project ini `throw => false`.
            if (Storage::disk('arsip')->put($path, (string) file_get_contents($berkas->getRealPath())) === false) {
                throw new RuntimeException("Gagal menyimpan foto ke {$path}.");
            }

            /** @var FotoPelanggan */
            return $relasi->create([
                'organization_id' => $organizationId,
                'customer_id' => $customerId,
                'path' => $path,
                'mime' => $berkas->getMimeType() ?: 'image/jpeg',
                'ukuran' => (int) $berkas->getSize(),
                'diunggah_oleh' => $oleh->id,
            ]);
        });
    }

    /**
     * Hapus baris; berkasnya ikut dibuang HANYA kalau tidak ada baris lain
     * yang menunjuk path yang sama (foto alat baru disalin ke alat yang lahir
     * waktu permintaan diterima — lihat `AlurPermintaan::terima`).
     */
    public function hapus(FotoPelanggan $foto): void
    {
        $path = $foto->path;
        $foto->delete();

        if (! FotoPelanggan::where('path', $path)->exists()) {
            Storage::disk('arsip')->delete($path);
        }
    }

    /**
     * Bentuk yang dikirim ke klien pelanggan. `url` absolut, tapi BUTUH token —
     * foto tidak pernah punya URL publik.
     *
     * @return array{id: int, url: string}
     */
    public static function bentukPelanggan(FotoPelanggan $foto): array
    {
        return ['id' => $foto->id, 'url' => route('pelanggan.foto.tampil', $foto->id)];
    }

    /** @return array{id: int, url: string} */
    public static function bentukLab(FotoPelanggan $foto): array
    {
        return ['id' => $foto->id, 'url' => route('foto-pelanggan.tampil', $foto->id)];
    }
}
