<?php

namespace App\Services\GarisWaktu;

use App\Models\Certificate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

/**
 * Garis waktu arsip sertifikat berdasarkan TANGGAL TERBIT (`diterbitkan_pada`).
 *
 * Dipakai dua pintu dengan kueri dasar yang berbeda: lab (disaring organisasi,
 * teknisi hanya miliknya) dan pelanggan (`LingkupData::sertifikat()`). Kueri
 * dasar itu SUDAH menyaring kepemilikan; di sini hanya menambah lingkup tanggal,
 * mengelompokkan, dan memberi halaman.
 *
 * ## Satu kueri kelompok, bukan satu per kelompok
 *
 * `GROUP BY diterbitkan_pada` memberi hitungan per HARI; tahun/bulan/pekan
 * dijumlahkan di PHP oleh [PengelompokTanggal]. Cepat: kolomnya DATE, tiap hari
 * ber-dokumen satu baris (ribuan sertifikat tetap beberapa ratus baris).
 *
 * Dokumen tanpa tanggal terbit (menunggu generate / gagal) tidak ikut: belum
 * jadi dokumen arsip.
 */
final class GarisWaktuSertifikat
{
    private const KOLOM_TANGGAL = 'certificates.diterbitkan_pada';

    public function __construct(private readonly PengelompokTanggal $pengelompok) {}

    /**
     * @param  Builder<Certificate>  $dasar  kueri yang sudah menyaring kepemilikan
     * @param  list<string|array<int|string, mixed>>  $relasi  untuk memuat item (tingkat hari)
     * @param  callable(Certificate): array<string, mixed>  $petakanItem
     * @return array{data: array<string, mixed>, meta: array<string, int>}
     */
    public function jawaban(
        Builder $dasar,
        LingkupGarisWaktu $lingkup,
        int $perHalaman,
        array $relasi,
        callable $petakanItem,
    ): array {
        $jumlahPerHari = $this->jumlahPerHari($dasar, $lingkup);
        $item = [];
        $meta = ['current_page' => 1, 'last_page' => 1, 'per_page' => $perHalaman, 'total' => 0];

        if ($lingkup->tingkat === 'hari') {
            $halaman = $this->dalamLingkup(clone $dasar, $lingkup)
                ->with($relasi)
                ->orderByDesc(self::KOLOM_TANGGAL)
                ->orderByDesc('certificates.id')
                ->paginate($perHalaman);

            $item = collect($halaman->items())->map($petakanItem)->values()->all();
            $meta = [
                'current_page' => $halaman->currentPage(),
                'last_page' => $halaman->lastPage(),
                'per_page' => $halaman->perPage(),
                'total' => $halaman->total(),
            ];
        }

        return [
            'data' => [
                'tingkat' => $lingkup->tingkat,
                'induk' => (object) $lingkup->induk(),
                'total_dokumen' => array_sum($jumlahPerHari),
                'kelompok' => $this->pengelompok->kelompokkan($jumlahPerHari, $lingkup),
                'item' => $item,
            ],
            'meta' => $meta,
        ];
    }

    /**
     * @param  Builder<Certificate>  $dasar
     * @return array<string, int> 'Y-m-d' => jumlah
     */
    private function jumlahPerHari(Builder $dasar, LingkupGarisWaktu $lingkup): array
    {
        $baris = $this->dalamLingkup(clone $dasar, $lingkup)
            ->toBase()
            ->reorder()
            ->select([
                new Expression(self::KOLOM_TANGGAL.' as tanggal'),
                new Expression('count(*) as jumlah'),
            ])
            ->groupBy(self::KOLOM_TANGGAL)
            ->get();

        $hasil = [];

        foreach ($baris as $isi) {
            // SQLite menyimpan kolom date bertanda jam; MySQL tidak. Ambil 10
            // karakter tanggalnya saja, lalu jumlahkan bila terbelah dua bentuk.
            $hari = substr((string) $isi->tanggal, 0, 10);
            $hasil[$hari] = ($hasil[$hari] ?? 0) + (int) $isi->jumlah;
        }

        return $hasil;
    }

    /**
     * @param  Builder<Certificate>  $kueri
     * @return Builder<Certificate>
     */
    private function dalamLingkup(Builder $kueri, LingkupGarisWaktu $lingkup): Builder
    {
        $kueri->whereNotNull(self::KOLOM_TANGGAL);

        return $lingkup->rentang()?->batasi($kueri, self::KOLOM_TANGGAL) ?? $kueri;
    }
}
