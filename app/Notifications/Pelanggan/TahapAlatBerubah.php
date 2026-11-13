<?php

namespace App\Notifications\Pelanggan;

use App\Notifications\NotifikasiSistem;
use App\Services\TahapPaket;

/**
 * "Alat Anda sekarang di tahap ..." -- ke anggota aktif perusahaan pemilik paket.
 *
 * Dikirim `KabarTahapAlat` setiap tahap yang DILIHAT pelanggan untuk satu alat
 * berubah (tahap fisik di meja depan, atau tahap turunan dari sesi kalibrasi).
 *
 * ## Isinya sengaja sempit
 *
 * Push mendarat di layar kunci (lihat `NotifikasiSistem::toPush`). Yang ada:
 * nama alat, merk, nomor seri (identitas yang dikenali pelanggan), label tahap
 * versi PELANGGAN (`TahapPaket::LABEL_PELANGGAN`), dan Order Number sebagai
 * pengait kedua. Yang TIDAK ada, dan tidak boleh ditambahkan di sini:
 * nama teknisi/penerima/pereview, kode internal (`menunggu_pengesahan`), nomor
 * sesi, kondisi terima, catatan, status/peringatan hitung, PASS/FAIL, nama
 * perusahaan. Nama penerima serah terima (`diserahkan_kepada`) tampil di
 * `/paket` tapi TIDAK di notifikasi.
 *
 * ## Tanda tangan
 *
 * `pelanggan.tahap_alat:<item>:<tahap>:<peristiwa>` -- dicek `KabarTahapAlat`
 * sebelum mengirim supaya peristiwa yang sama tidak dikabarkan dua kali, tapi
 * peristiwa BARU dengan tahap yang sama (pekerjaan ulang yang kedua) tetap lolos.
 */
class TahapAlatBerubah extends NotifikasiSistem
{
    private function __construct(
        private readonly int $paketId,
        private readonly string $nomorOrder,
        private readonly string $namaAlat,
        private readonly ?string $merk,
        private readonly ?string $serial,
        private readonly string $kodeTahap,
        private readonly string $tandaTangan,
    ) {}

    public static function dari(
        int $paketId,
        int $itemId,
        string $nomorOrder,
        string $namaAlat,
        ?string $merk,
        ?string $serial,
        string $kodeTahap,
        string $peristiwa,
    ): self {
        return new self(
            $paketId,
            $nomorOrder,
            $namaAlat,
            $merk,
            $serial,
            $kodeTahap,
            self::tandaTanganUntuk($itemId, $kodeTahap, $peristiwa),
        );
    }

    /** Nilai `tanda_tangan` di `notifications.data` untuk satu peristiwa tahap. */
    public static function tandaTanganUntuk(int $itemId, string $kodeTahap, string $peristiwa): string
    {
        return "pelanggan.tahap_alat:{$itemId}:{$kodeTahap}:{$peristiwa}";
    }

    protected function tandaTangan(): ?string
    {
        return $this->tandaTangan;
    }

    protected function judul(): string
    {
        return 'Status alat Anda diperbarui';
    }

    protected function isi(): string
    {
        $identitas = $this->namaAlat;

        if (filled($this->merk)) {
            $identitas .= " {$this->merk}";
        }

        if (filled($this->serial)) {
            $identitas .= " (No. seri {$this->serial})";
        }

        $label = TahapPaket::LABEL_PELANGGAN[$this->kodeTahap] ?? $this->kodeTahap;

        return "{$identitas}: {$label}. Order {$this->nomorOrder}.";
    }

    protected function kategori(): string
    {
        return 'pelanggan.tahap_alat';
    }

    /**
     * Tipe tautan BARU `pelanggan_paket` + id paket. Aplikasi pelanggan yang
     * belum memetakannya (`layarUntukTautan` menghasilkan `null`, pemanggil yang memutuskan:
     * push jatuh ke kotak masuk) tidak mendarat di paketnya; isi
     * notifikasinya tetap terbaca utuh.
     *
     * @return array<string, mixed>
     */
    protected function tautan(): array
    {
        return ['tipe' => 'pelanggan_paket', 'id' => $this->paketId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-truck';
    }

    protected function warna(): string
    {
        return $this->kodeTahap === TahapPaket::SIAP_DIAMBIL ? 'success' : 'info';
    }
}
