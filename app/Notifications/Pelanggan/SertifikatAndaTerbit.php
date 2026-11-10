<?php

namespace App\Notifications\Pelanggan;

use App\Models\Certificate;
use App\Notifications\NotifikasiSistem;

/**
 * "Sertifikat alat Anda sudah terbit" — ke anggota aktif perusahaan pemilik alat.
 *
 * Kembaran `App\Notifications\SertifikatTerbit` yang ke teknisi & admin
 * penerbit, dan bedanya sengaja:
 *
 * - **Isinya netral.** Cuma nomor sertifikat, nama alat, dan tanggal terbit.
 *   Push mendarat di layar kunci (lihat `NotifikasiSistem::toPush`), jadi
 *   tidak ada nomor seri, nama perusahaan, hasil kalibrasi, PASS/FAIL, nama
 *   teknisi/penerbit, atau catatan internal di sini. Rinciannya ditarik app
 *   lewat REST ber-otorisasi setelah notifikasinya diketuk.
 * - **Tautannya `pelanggan_sertifikat`**, bukan `certificate`: itu tipe yang
 *   dipetakan aplikasi pelanggan ke layar detail sertifikat
 *   (`lib/screens/tautan.dart`). Tipe internal tidak dikenalnya.
 * - **Tanda tangan per sertifikat** — dipakai `KabarSertifikatTerbit` untuk
 *   tidak mengirim dua kali kabar tentang sertifikat yang sama.
 */
class SertifikatAndaTerbit extends NotifikasiSistem
{
    private function __construct(
        private readonly int $sertifikatId,
        private readonly string $nomor,
        private readonly string $namaAlat,
        private readonly ?string $tanggalTerbit,
    ) {}

    public static function dariSertifikat(Certificate $sertifikat): self
    {
        $sertifikat->loadMissing('session.equipment:id,nama_alat');

        return new self(
            $sertifikat->id,
            (string) $sertifikat->nomor,
            (string) ($sertifikat->session?->equipment?->nama_alat ?? 'alat'),
            $sertifikat->diterbitkan_pada?->format('d-m-Y'),
        );
    }

    /** Nilai `tanda_tangan` di `notifications.data` untuk kabar tentang sertifikat ini. */
    public static function tandaTanganUntuk(int $sertifikatId): string
    {
        return 'pelanggan.sertifikat_terbit:'.$sertifikatId;
    }

    protected function tandaTangan(): ?string
    {
        return self::tandaTanganUntuk($this->sertifikatId);
    }

    protected function judul(): string
    {
        return 'Sertifikat kalibrasi Anda sudah terbit';
    }

    protected function isi(): string
    {
        $kapan = $this->tanggalTerbit !== null ? " (terbit {$this->tanggalTerbit})" : '';

        return "Sertifikat {$this->nomor} untuk {$this->namaAlat}{$kapan} sudah bisa dibuka di aplikasi.";
    }

    protected function kategori(): string
    {
        return 'pelanggan.sertifikat_terbit';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'pelanggan_sertifikat', 'id' => $this->sertifikatId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-document-check';
    }

    protected function warna(): string
    {
        return 'success';
    }
}
