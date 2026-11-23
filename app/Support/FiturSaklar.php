<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Daftar fitur yang BOLEH dimatikan Super Admin tanpa deploy (paket OPS-KODE
 * butir 3), dan pembaca keadaannya.
 *
 * ## Kenapa ada
 *
 * Saklar yang sudah ada (`FITUR_PELANGGAN`, `VISION_AKTIF`, ...) hidup di env
 * Render: mengubahnya butuh izin pemilik per kejadian dan deploy ulang, dan
 * nilainya bisa tertimpa sinkron blueprint. Ketika satu fitur opsional rusak di
 * lapangan (pindai salah baca terus, surel sertifikat meluap), yang dibutuhkan
 * adalah mematikannya SAAT ITU JUGA tanpa mematikan lab. Keadaannya disimpan di
 * `organizations.settings['fitur_nonaktif']` — tanpa migrasi — dan hanya
 * Super Admin yang bisa mengubahnya (`PUT /api/organization/fitur`).
 *
 * ## Yang TIDAK pernah boleh masuk daftar ini
 *
 * Pengisian lembar, perhitungan, persetujuan, dan penerbitan sertifikat. Itu
 * inti lab terakreditasi: fitur yang dimatikan diam-diam di sini menghentikan
 * sertifikat tanpa satu pun galat di server. Daftarnya TERTUTUP dan kecil
 * (mulai dari dua), dan menambah satu kode butuh PR yang kelihatan:
 *
 *  1. daftarkan di [DAFTAR];
 *  2. pasang `fitur:<kode>` di rute yang BENAR-BENAR opsional (jangan di rute
 *     inti — `SaklarFiturTest` menyapu rute dan menolaknya);
 *  3. tambahkan ke kontrak (`docs/kontrak-api.md`).
 *
 * ## Bentuk penyimpanan
 *
 * Daftar KODE yang NONAKTIF (bukan yang aktif): organisasi baru dan baris lama
 * otomatis berarti "semua nyala" tanpa menulis apa pun. Kode yang tidak
 * dikenal di data (mis. fitur yang sudah dicabut dari kode) diabaikan saat
 * membaca, bukan membuat galat.
 */
final class FiturSaklar
{
    /** Kunci di `organizations.settings`. */
    public const KUNCI_SETTINGS = 'fitur_nonaktif';

    public const PINDAI_LEMBAR = 'pindai_lembar';

    public const KIRIM_EMAIL_SERTIFIKAT = 'kirim_email_sertifikat';

    public const OCR_CLOUD = 'ocr_cloud';

    /**
     * Kode → keterangan untuk layar pengaturan. Urutan di sini = urutan
     * tampil dan urutan `fitur_nonaktif` yang dikembalikan.
     *
     * (`arsip_unduh_massal` dari rencana awal TIDAK ada: rute unduh massal
     * belum ada di API; yang ada hanya unduh per berkas.)
     *
     * @var array<string, array{label: string, deskripsi: string}>
     */
    public const DAFTAR = [
        self::PINDAI_LEMBAR => [
            'label' => 'Pindai lembar kerja',
            'deskripsi' => 'Pembacaan foto lembar kerja di server (templat OCR dan hasil pindai). '
                .'Pengisian manual tetap jalan.',
        ],
        self::KIRIM_EMAIL_SERTIFIKAT => [
            'label' => 'Kirim sertifikat lewat surel',
            'deskripsi' => 'Pengiriman sertifikat ke surel pelanggan dari aplikasi. '
                .'Unduh dan penerbitan sertifikat tidak terpengaruh.',
        ],
        // Kill switch OCR awan. BUKAN pengganti env `GOOGLE_VISION_AKTIF`
        // (bawaan mati): env menyalakan fitur di server, kode ini mematikannya
        // seketika di lapangan tanpa deploy. Pengisian manual dan pindai di HP
        // tidak terpengaruh.
        self::OCR_CLOUD => [
            'label' => 'OCR awan (Google Vision)',
            'deskripsi' => 'Pengiriman foto halaman lembar kerja ke Google Cloud Vision lewat server. '
                .'Bawaan server MATI (env GOOGLE_VISION_AKTIF): saklar ini hanya bisa mematikan, '
                .'tidak menyalakan. Pengisian manual dan pembacaan di HP tetap jalan.',
        ],
    ];

    /**
     * Nama fitur inti yang SENGAJA tidak ada di [DAFTAR]. Dipakai hanya supaya
     * percobaan mematikannya dijawab dengan alasan yang jelas, bukan "kode
     * tidak dikenal". Bukan daftar lengkap — yang tidak ada di [DAFTAR] pasti
     * ditolak, apa pun namanya.
     *
     * @var list<string>
     */
    public const INTI = [
        'isi_lembar', 'pengisian', 'hitung', 'perhitungan', 'approve', 'persetujuan',
        'terbit', 'penerbitan', 'sertifikat', 'login',
    ];

    /** @return list<string> */
    public static function kode(): array
    {
        return array_keys(self::DAFTAR);
    }

    /**
     * Kode fitur yang sedang MATI untuk organisasi ini, urut seperti [DAFTAR].
     *
     * @return list<string>
     */
    public static function nonaktif(?Organization $organisasi): array
    {
        $mentah = $organisasi?->settings[self::KUNCI_SETTINGS] ?? [];

        if (! is_array($mentah)) {
            return [];
        }

        return array_values(array_filter(
            self::kode(),
            fn (string $kode): bool => in_array($kode, $mentah, true),
        ));
    }

    public static function aktif(?Organization $organisasi, string $kode): bool
    {
        return ! in_array($kode, self::nonaktif($organisasi), true);
    }

    public static function label(string $kode): string
    {
        return self::DAFTAR[$kode]['label'] ?? $kode;
    }
}
