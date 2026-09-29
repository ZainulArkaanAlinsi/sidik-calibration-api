<?php

namespace App\Notifications\Pelanggan;

use App\Models\Customer;
use App\Models\Equipment;
use App\Notifications\NotifikasiSistem;
use Illuminate\Support\Collection;

/**
 * "Alat Anda mendekati / sudah lewat jatuh tempo kalibrasi" — ke HP pelanggan.
 *
 * Kembaran `App\Notifications\AlatJatuhTempo` yang ke admin. Yang berbeda bukan
 * cuma penerimanya:
 *
 * - **Isinya cuma alat pelanggan itu.** Satu kueri yang lupa `where customer_id`
 *   di service-nya membuat notifikasi ini membawa nomor seri alat pelanggan lain.
 *   Kerahasiaan antar pelanggan, ISO/IEC 17025 klausul 4.2.
 * - **Bahasanya bukan bahasa lab.** Pelanggan tidak tahu apa itu "overdue" atau
 *   "interval kalibrasi". Yang dia perlu tahu: alat mana, kapan batasnya, dan
 *   apa yang harus dilakukan.
 * - **Ada ajakan bertindak.** Notifikasi yang cuma memberitahu berakhir sebagai
 *   notifikasi yang dibaca lalu dilupakan. Yang membuat pelanggan bergerak
 *   adalah tautan ke layar "ajukan kalibrasi" dengan alatnya sudah terpilih.
 */
class AlatAndaJatuhTempo extends NotifikasiSistem
{
    /**
     * @param  list<array{nama: string, serial: string|null, tanggal: string|null, lewat: bool}>  $rincian
     *                                                                                                      dipotong untuk tampilan
     * @param  string  $sumbuTandaTangan  dihitung dari daftar UTUH
     */
    public function __construct(
        private readonly int $customerId,
        private readonly array $rincian,
        private readonly int $jumlahTotal,
        private readonly int $jumlahLewat,
        private readonly ?int $hariTerdekat,
        private readonly string $sumbuTandaTangan,
    ) {}

    /**
     * @param  Collection<int, Equipment>  $alat
     */
    public static function dariAlat(Customer $pelanggan, Collection $alat): self
    {
        // DUA daftar, dan bedanya menentukan — pola ini disalin dari versi admin
        // yang sudah diperbaiki:
        //
        //  - `$rincian` dipotong 10: itu yang ditampilkan, dan payload notifikasi
        //    bukan tempat menaruh ratusan baris;
        //  - `$sumbu` UTUH: itu yang jadi tanda tangan.
        //
        // Waktu tanda tangannya ikut dihitung dari daftar yang terpotong,
        // perubahan pada alat ke-11 dan seterusnya tidak kelihatan sama sekali:
        // isinya berubah, tanda tangannya tidak, dan penjaga masa tenang menahan
        // kabar itu. Yang ketahan justru kabar BARU — dan yang kena cuma
        // pelanggan dengan lebih dari 10 alat, yaitu yang paling butuh
        // pengingatnya.
        $sumbu = $alat->map(fn (Equipment $a): string => $a->id.':'.($a->tanggal_jatuh_tempo?->toDateString() ?? '-'))
            ->sort()
            ->implode('|');

        $rincian = $alat
            // Yang paling mendesak duluan — kalau daftarnya dipotong, yang
            // terpotong harus yang paling tidak mendesak.
            ->sortBy(fn (Equipment $a): string => $a->tanggal_jatuh_tempo?->toDateString() ?? '9999-12-31')
            ->take(10)
            ->map(fn (Equipment $a): array => [
                'nama' => (string) $a->nama_alat,
                'serial' => $a->serial_number,
                'tanggal' => $a->tanggal_jatuh_tempo?->toDateString(),
                'lewat' => $a->tanggal_jatuh_tempo?->startOfDay()->isPast() ?? false,
            ])
            ->values()
            ->all();

        $terdekat = $alat
            ->map(fn (Equipment $a): ?int => $a->tanggal_jatuh_tempo === null
                ? null
                : (int) now()->startOfDay()->diffInDays($a->tanggal_jatuh_tempo->startOfDay(), false))
            ->filter(fn (?int $h): bool => $h !== null)
            ->min();

        return new self(
            $pelanggan->id,
            $rincian,
            $alat->count(),
            $alat->filter(fn (Equipment $a): bool => $a->tanggal_jatuh_tempo?->startOfDay()->isPast() ?? false)->count(),
            $terdekat,
            hash('sha256', $sumbu),
        );
    }

    /**
     * Dipakai `PengingatJatuhTempoPelanggan` sebelum mengirim.
     *
     * Publik karena penjaganya butuh tanda tangan yang SAMA dengan yang tersimpan
     * di payload notifikasi. Kalau service menghitungnya sendiri dengan cara lain,
     * dua nilai itu akan berbeda dan masa tenangnya tidak pernah menahan apa pun —
     * kegagalan yang bentuknya "kok pelanggan dispam tiap hari".
     */
    public function tandaTanganUntukPenjaga(): string
    {
        return $this->sumbuTandaTangan;
    }

    protected function tandaTangan(): ?string
    {
        return $this->sumbuTandaTangan;
    }

    protected function judul(): string
    {
        if ($this->jumlahLewat > 0) {
            return $this->jumlahLewat === $this->jumlahTotal
                ? 'Kalibrasi alat Anda sudah lewat jadwal'
                : 'Ada alat yang sudah lewat jadwal kalibrasi';
        }

        // Bahasa pelanggan, bukan bahasa lab. "Interval kalibrasi terlampaui"
        // benar secara teknis dan tidak dimengerti siapa pun yang bukan orang lab.
        return $this->hariTerdekat !== null && $this->hariTerdekat <= 7
            ? 'Jadwal kalibrasi alat Anda sudah dekat'
            : 'Pengingat jadwal kalibrasi alat Anda';
    }

    protected function isi(): string
    {
        $pertama = $this->rincian[0] ?? null;

        if ($pertama === null) {
            return 'Ada alat Anda yang perlu dikalibrasi ulang.';
        }

        $nama = $pertama['serial'] !== null
            ? "{$pertama['nama']} ({$pertama['serial']})"
            : $pertama['nama'];

        $lain = $this->jumlahTotal > 1
            ? ' dan '.($this->jumlahTotal - 1).' alat lain'
            : '';

        // Tanggalnya disebut, bukan cuma "segera". "Segera" tidak bisa
        // dimasukkan ke kalender; tanggal bisa.
        $kapan = match (true) {
            $pertama['lewat'] => 'sudah lewat jadwal ('.$pertama['tanggal'].')',
            $this->hariTerdekat === 0 => 'jatuh tempo hari ini',
            $this->hariTerdekat === 1 => 'jatuh tempo besok',
            default => 'jatuh tempo '.$pertama['tanggal'],
        };

        return "{$nama}{$lain} {$kapan}. Ajukan kalibrasi dari aplikasi biar jadwalnya kekunci.";
    }

    protected function kategori(): string
    {
        return 'pelanggan_alat_jatuh_tempo';
    }

    /**
     * Tautan ke layar "ajukan kalibrasi", bukan ke daftar alat.
     *
     * Bedanya nyata: daftar alat memberi tahu, layar pengajuan menyelesaikan.
     * Notifikasi yang berhenti di "memberitahu" adalah notifikasi yang dibaca
     * lalu dilupakan — dan pelanggan yang lupa mengajukan tetap datang
     * terlambat, jadi pengingatnya tidak mengubah apa pun.
     *
     * @return array<string, mixed>
     */
    protected function tautan(): array
    {
        return [
            'tipe' => 'pelanggan_ajukan_kalibrasi',
            'id' => $this->customerId,
            // Alat yang sudah terpilih di layar pengajuan. Pelanggan tidak perlu
            // mencari ulang alat yang baru saja disebut notifikasinya.
            'alat' => array_column($this->rincian, 'serial'),
        ];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    protected function warna(): string
    {
        return $this->jumlahLewat > 0 ? 'danger' : 'warning';
    }
}
