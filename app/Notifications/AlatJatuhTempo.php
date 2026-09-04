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
 *
 * `tandaTangan()` yang bikin ini kepakai — lihat `PenjagaNotifikasiUlang`.
 * Tanpa itu, scheduler harian ngirim baris yang persis sama tiap pagi selama
 * alatnya belum dikalibrasi ulang, tanpa batas.
 */
class AlatJatuhTempo extends NotifikasiSistem
{
    /**
     * @param  list<array<string, mixed>>  $alat  ringkasan alat yang kena, DIPOTONG
     *                                            buat ditampilkan
     * @param  list<array<string, mixed>>|null  $sumbu  seluruh alat yang kena, tanpa
     *                                                  dipotong — cuma buat tanda
     *                                                  tangan. Null = pakai [$alat]
     *                                                  (pemanggil yang tidak
     *                                                  memotong apa-apa).
     */
    public function __construct(
        private readonly int $jumlahOverdue,
        private readonly int $jumlahMendekati,
        private readonly array $alat = [],
        private readonly ?array $sumbu = null,
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

    /**
     * Harus sama persis dengan `PengingatJatuhTempo::tandaTangan()`.
     *
     * Sumbunya `id:overdue` — bukan cuma id. Alat yang bergeser dari
     * "mendekati" jadi "sudah lewat" itu kabar BARU, dan itu justru saat yang
     * paling nggak boleh ketahan masa tenang. Sama persis dengan sumbu
     * `id:status` di `StandarMauKadaluarsa`.
     *
     * Tanggal jatuh temponya sengaja NGGAK ikut: dia nggak bergerak sendiri,
     * dan kalau admin menggesernya sehari, itu bukan kabar baru buat siapa pun.
     *
     * ## Kenapa `$sumbu`, bukan `$alat`
     *
     * `$alat` sudah DIPOTONG 20 baris buat ditampilkan. Menghitung tanda tangan
     * dari daftar yang terpotong bikin perubahan pada alat ke-21 dan seterusnya
     * TIDAK KELIHATAN: `isi()`-nya berubah ("N alat sudah lewat jatuh tempo"),
     * tanda tangannya tidak, dan `PenjagaNotifikasiUlang` menahan kabar itu
     * tujuh hari.
     *
     * Arah salahnya yang paling mahal: yang ketahan justru kabar BARU, dan
     * lab dengan lebih dari 20 alat kena persis itu — yaitu lab yang paling
     * butuh pengingatnya.
     */
    protected function tandaTangan(): string
    {
        $bagian = array_map(
            fn (array $a): string => ($a['id'] ?? '?').':'.(($a['overdue'] ?? false) ? '1' : '0'),
            $this->sumbu ?? $this->alat,
        );

        sort($bagian);

        return implode('|', $bagian);
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
