<?php

namespace App\Notifications;

/**
 * Pengingat alat yang lewat / mendekati jatuh tempo kalibrasi
 * (spesifikasi poin 6).
 *
 * Sengaja RINGKASAN per organisasi, bukan satu notifikasi per alat: lab dengan
 * 80 alat bakal ngirim 80 notifikasi tiap pagi, dan seminggu kemudian nggak ada
 * yang buka loncengnya lagi. Daftar alatnya tetap dibawa di payload biar mobile
 * bisa nampilin rinciannya waktu diketuk.
 */
class AlatJatuhTempo extends NotifikasiSistem
{
    /**
     * @param  list<array<string, mixed>>  $alat  ringkasan alat yang kena
     */
    public function __construct(
        private readonly int $jumlahOverdue,
        private readonly int $jumlahMendekati,
        private readonly array $alat = [],
    ) {}

    protected function judul(): string
    {
        return 'Pengingat kalibrasi';
    }

    protected function isi(): string
    {
        $bagian = array_filter([
            $this->jumlahOverdue > 0 ? "{$this->jumlahOverdue} alat sudah lewat jatuh tempo" : null,
            $this->jumlahMendekati > 0 ? "{$this->jumlahMendekati} alat mendekati jatuh tempo" : null,
        ]);

        return ucfirst(implode(', ', $bagian)).'.';
    }

    protected function kategori(): string
    {
        return 'jatuh_tempo';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return [
            'tipe' => 'equipments',
            'filter' => $this->jumlahOverdue > 0 ? 'overdue' : 'mendekati',
            'alat' => $this->alat,
        ];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }

    protected function warna(): string
    {
        return $this->jumlahOverdue > 0 ? 'danger' : 'warning';
    }
}
