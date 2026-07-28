<?php

namespace App\Notifications;

/**
 * Sertifikat standar acuan mendekati / udah kadaluarsa (fase-2 §2).
 *
 * Kenapa ini yang paling mahal kalau kelewat: standar yang sertifikatnya habis
 * bikin ketertelusuran putus, dan `POST /calibrations` nolaknya `422`. Jadi
 * akibatnya bukan cuma "data kurang rapi" — teknisi berangkat ke lapangan, ngisi
 * lembar kerja, terus mentok waktu nyimpen. Kerjaannya beneran hangus.
 *
 * Diringkas per organisasi, bukan satu notifikasi per standar: lab pH punya 4
 * buffer + termometer yang sering habis di bulan yang sama. Daftarnya tetap
 * dibawa di `tautan` biar mobile bisa nampilin rinciannya waktu diketuk.
 *
 * `tandaTangan()` yang bikin ini kepakai — lihat `PenjagaNotifikasiUlang`. Tanpa
 * itu, scheduler harian ngirim baris yang persis sama 30 pagi berturut-turut.
 */
class StandarMauKadaluarsa extends NotifikasiSistem
{
    /**
     * @param  list<array<string, mixed>>  $standar  sudah urut & sudah ada `status`-nya
     */
    public function __construct(
        private readonly int $jumlahKadaluarsa,
        private readonly int $jumlahMendekati,
        private readonly array $standar,
    ) {}

    protected function judul(): string
    {
        return $this->jumlahKadaluarsa > 0
            ? 'Standar acuan KADALUARSA'
            : 'Standar acuan mendekati kadaluarsa';
    }

    protected function isi(): string
    {
        $bagian = array_filter([
            $this->jumlahKadaluarsa > 0
                ? "{$this->jumlahKadaluarsa} standar sertifikatnya udah habis"
                : null,
            $this->jumlahMendekati > 0
                ? "{$this->jumlahMendekati} standar mendekati habis"
                : null,
        ]);

        $pesan = ucfirst(implode(', ', $bagian)).'.';

        // Akibatnya disebut eksplisit. "Standar kadaluarsa" doang gampang dibaca
        // sebagai urusan administrasi yang bisa nanti — padahal ini nahan
        // kalibrasi jalan.
        return $this->jumlahKadaluarsa > 0
            ? $pesan.' Sesi kalibrasi yang pakai standar itu bakal ditolak.'
            : $pesan;
    }

    protected function kategori(): string
    {
        return 'standar.kadaluarsa';
    }

    /**
     * Tanda tangan = kumpulan standar + statusnya.
     *
     * Begitu ada standar baru masuk jendela peringatan, atau satu berubah dari
     * `warning` jadi `expired`, tanda tangannya beda dan notifikasinya dikirim
     * SAAT ITU — nggak nunggu masa tenang habis. Itu justru kabar yang paling
     * nggak boleh telat.
     */
    protected function tandaTangan(): string
    {
        $bagian = array_map(
            fn (array $s): string => ($s['id'] ?? '?').':'.($s['status'] ?? '?'),
            $this->standar,
        );

        sort($bagian);

        return implode('|', $bagian);
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return [
            'tipe' => 'standards',
            'filter' => $this->jumlahKadaluarsa > 0 ? 'expired' : 'warning',
            'standar' => $this->standar,
        ];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-beaker';
    }

    protected function warna(): string
    {
        return $this->jumlahKadaluarsa > 0 ? 'danger' : 'warning';
    }
}
