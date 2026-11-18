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

    /**
     * Aturan yang sama (`pic_utama_terakhir`), kalimat untuk ADMIN LAB yang
     * mencabut. Pesan di atas memakai "Anda" — benar buat PIC utama yang
     * menonaktifkan dirinya, keliru dibaca admin lab yang mencabut orang lain.
     * `kode`-nya sengaja sama supaya klien bercabang pada satu kode.
     */
    public static function picUtamaTerakhirOlehLab(): self
    {
        return new self(
            'pic_utama_terakhir',
            'Dia satu-satunya PIC utama aktif perusahaan ini. Undang atau angkat PIC utama lain dulu sebelum mencabutnya.',
            422,
        );
    }

    /** `POST /customers/{c}/pindahkan-alat` — tujuan sama dengan asal. */
    public static function ptTujuanSama(): self
    {
        return new self('pt_tujuan_sama', 'PT tujuan harus berbeda dari PT asal.', 422);
    }

    /**
     * Sebagian `alat_ids` alat lab ini tetapi milik PT lain. `data.alat_ids`
     * memuat id yang salah (id milik lab SENDIRI, jadi tidak membocorkan apa pun).
     *
     * @param  list<int>  $idSalah
     */
    public static function alatBukanMilikPtAsal(array $idSalah): self
    {
        return new self(
            'alat_bukan_milik_pt_asal',
            'Sebagian alat bukan milik PT asal. Muat ulang daftar alat PT asal.',
            422,
            ['alat_ids' => $idSalah],
        );
    }

    public static function konfirmasiWajib(): self
    {
        return new self(
            'konfirmasi_wajib',
            'Pemindahan alat harus dikonfirmasi (konfirmasi: true). Lihat pratinjau dulu dengan dry_run.',
            422,
        );
    }

    /** Alat berpindah PT di antara pratinjau dan eksekusi (admin lain). */
    public static function alatSudahPindah(): self
    {
        return new self(
            'alat_sudah_pindah',
            'Alat sudah berpindah PT sejak pratinjau. Ulangi pratinjau.',
            409,
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
