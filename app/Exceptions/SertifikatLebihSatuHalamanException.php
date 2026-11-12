<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Sertifikat tidak muat SATU halaman walau mode terpadat sudah dicoba.
 *
 * Header sertifikat mencetak `Page : 1 of 1` yang ditulis mati di snapshot,
 * jadi PDF dua halaman adalah dokumen terkendali yang menyatakan hal tidak
 * benar tentang dirinya sendiri. `SertifikatSatuHalaman::paling()` melempar ini
 * alih-alih mengembalikan PDF itu; pemanggil yang menerbitkan (`GenerateCertificate`,
 * `ReviseCertificate`) menandai sertifikatnya `gagal` — nomor dan snapshot
 * tetap, sesi tetap `disetujui` — sehingga admin bisa menerbitkan ulang setelah
 * isinya diperbaiki.
 *
 * Pesannya sengaja pendek dan berdiri sendiri: dia dipotong 160 karakter di
 * notifikasi `SertifikatGagal` yang dibaca admin.
 */
class SertifikatLebihSatuHalamanException extends RuntimeException
{
    public function __construct(
        public readonly int|string|null $sertifikatId,
        public readonly int $halamanNormal,
        public readonly int $halamanPadat,
        /**
         * Hasil render mode terpadat (PDF >1 halaman). TIDAK PERNAH diterbitkan
         * sebagai sertifikat baru; hanya dipakai `BerkasPdfSertifikat::pastikanAda`
         * untuk sertifikat yang SUDAH terbit dan berkasnya hilang, supaya
         * pemegangnya tidak tiba-tiba 404 (perilaku lama dipertahankan di situ).
         */
        public readonly ?string $isiPadat = null,
    ) {
        parent::__construct(sprintf(
            'Sertifikat tidak muat 1 halaman (%d halaman walau mode terpadat) — TIDAK diterbitkan. '
            .'Periksa jumlah titik/catatan/logo, lalu terbitkan ulang.',
            $halamanPadat,
        ));
    }
}
