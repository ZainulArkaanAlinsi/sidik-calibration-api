<?php

namespace App\Support;

use App\Models\User;

/**
 * Daftar kemampuan (ability) per role — satu sumber kebenaran buat mobile
 * nyembunyiin tombol yang bakal ditolak 403, bukan nampilin tombol lalu user
 * kena error.
 *
 * Daftarnya CERMINAN dari middleware `role:` di `routes/api.php` (lihat
 * MATRIKS-PERAN.md). Kalau route di sana diubah, daftar ini WAJIB ikut diubah.
 *
 * `tests/Feature/PermissionsTest.php` njaga sebagian: dia mastiin ability
 * bertingkat (viewer ⊂ teknisi ⊂ admin), viewer nggak kebagian ability nulis,
 * dan — yang paling penting — sederet ability PERWAKILAN yang nggak dipunyai
 * satu role beneran ditolak 403 sama route-nya. Bukan pengecekan menyeluruh
 * tiap ability, jadi kalau nambah ability baru, tambahin juga barisnya di
 * data provider `abilityYangDitolak()`.
 *
 * Penting: ability itu izin MEMANGGIL endpoint, bukan jaminan lihat semua data.
 * Contoh: teknisi punya `kalibrasi.lihat`, tapi isinya cuma sesi miliknya
 * sendiri (disaring di CalibrationController). Penyaringan per-baris kayak gitu
 * tetap urusan backend, mobile nggak usah ikut mikir.
 */
class Permissions
{
    /** Baca-baca: dipunyai semua role yang udah login, termasuk viewer. */
    private const BACA = [
        'dashboard.lihat',
        'alat.lihat',
        'kategori.lihat',
        'standar.lihat',
        'ruangan.lihat',
        'order.lihat',
        'kalibrasi.lihat',
        'sertifikat.lihat',
        'sertifikat.unduh',
        'arsip.lihat',
    ];

    /** Tambahan buat teknisi: input kerjaan lapangan + nyusun arsip. */
    private const TEKNISI = [
        'alat.tambah',
        'alat.ubah',
        'alat.hapus',
        'kalibrasi.buat',
        'kalibrasi.ubah',
        'kalibrasi.unggah-foto',
        'kalibrasi.verifikasi-ocr',
        'arsip.folder.buat',
        'arsip.folder.ubah',
        'arsip.folder.hapus',
        'arsip.folder.pindah',
        'arsip.berkas.pindah',
    ];

    /** Tambahan buat admin: persetujuan, master data, dan kelola pengguna. */
    private const ADMIN = [
        'kalibrasi.approve',
        'kalibrasi.reject',
        'sertifikat.terbitkan-ulang',
        'pelanggan.lihat',
        'pelanggan.tambah',
        'pelanggan.ubah',
        'pelanggan.hapus',
        'order.tambah',
        'order.ubah',
        'order.hapus',
        'order.penugasan',
        'ruangan.tambah',
        'ruangan.ubah',
        'ruangan.hapus',
        'standar.tambah',
        'standar.ubah',
        'standar.hapus',
        'teknisi.kelola',
        'pengguna.kelola',
        'organisasi.lihat',
        'organisasi.ubah',
    ];

    /**
     * Ability yang dipunyai satu role. Bertingkat: admin dapat semua punya
     * teknisi, teknisi dapat semua punya viewer.
     *
     * @return list<string>
     */
    public static function untukRole(string $role): array
    {
        $ability = match ($role) {
            User::ROLE_ADMIN => [...self::BACA, ...self::TEKNISI, ...self::ADMIN],
            User::ROLE_TEKNISI => [...self::BACA, ...self::TEKNISI],
            default => self::BACA,
        };

        sort($ability);

        return $ability;
    }
}
