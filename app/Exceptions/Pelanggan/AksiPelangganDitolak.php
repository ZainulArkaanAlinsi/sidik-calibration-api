<?php

namespace App\Exceptions\Pelanggan;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Aturan domain modul pelanggan yang dilanggar, lengkap dengan `kode` stabilnya.
 *
 * ## Kenapa exception, bukan `if` di controller
 *
 * Aturan seperti "maksimal 50 anggota aktif" (REQ-ANG-01) punya DUA pintu:
 * admin menyetujui pengajuan, dan orang menukar kode undangan. Kalau
 * pemeriksaannya ditulis di controller, cukup satu pintu yang lupa buat
 * membuat batasnya bocor — dan bocornya tidak memunculkan error, cuma
 * perusahaan dengan 53 anggota.
 *
 * Dilempar dari `Keanggotaan`, yaitu satu-satunya jalan baris
 * `customer_members` lahir. Tidak ada pintu yang bisa melewatinya.
 *
 * ## `render()` di kelas exception-nya sendiri
 *
 * Laravel memanggil `render()` kalau exception-nya punya. Jadi tidak perlu
 * `try/catch` di controller mana pun DAN tidak perlu pendaftaran di
 * `bootstrap/app.php` — dua tempat yang sama-sama bisa terlupa. Bentuk
 * balasannya otomatis ikut aturan 03-SDD §7: tiap error non-422 punya `kode`.
 */
class AksiPelangganDitolak extends RuntimeException
{
    public function __construct(
        public readonly string $kode,
        string $pesan,
        public readonly int $status = 422,
        public readonly array $data = [],
    ) {
        parent::__construct($pesan);
    }

    public static function batasAnggota(int $maks): self
    {
        return new self(
            'batas_anggota',
            "Perusahaan ini sudah mencapai batas {$maks} anggota aktif. Hubungi PT Sidik untuk menaikkannya.",
            422,
            ['maks_anggota' => $maks],
        );
    }

    /**
     * REQ-ANG-02 — PIC utama terakhir tidak boleh menghilangkan dirinya sendiri.
     *
     * Kalau boleh, perusahaannya berdiri tanpa satu pun orang yang bisa
     * mengundang anggota baru, dan satu-satunya jalan keluar menelepon lab.
     */
    public static function picUtamaTerakhir(): self
    {
        return new self(
            'pic_utama_terakhir',
            'Anda satu-satunya PIC utama aktif. Angkat PIC utama lain dulu sebelum menonaktifkan akun ini.',
            422,
        );
    }

    public static function sudahDiputus(?string $olehSiapa): self
    {
        return new self(
            'sudah_diputus',
            $olehSiapa === null
                ? 'Pengajuan ini sudah diputus admin lain.'
                : "Pengajuan ini sudah diputus {$olehSiapa}.",
            409,
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(array_filter([
            'kode' => $this->kode,
            'message' => $this->getMessage(),
            'data' => $this->data === [] ? null : $this->data,
        ], fn ($nilai) => $nilai !== null), $this->status);
    }
}
