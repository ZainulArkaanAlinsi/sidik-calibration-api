<?php

namespace App\Services\GarisWaktu;

use Carbon\CarbonImmutable;

/**
 * Menjumlahkan hitungan per hari menjadi kelompok tahun/bulan/pekan/hari.
 *
 * Masukannya hitungan per HARI (`'2026-11-12' => 3`) dari satu kueri
 * `GROUP BY` pada kolom tanggal; pengelompokannya dikerjakan di PHP supaya
 * tidak memakai fungsi tanggal khusus satu DB (`YEARWEEK` MySQL, `strftime`
 * SQLite). Jumlah baris masukan paling banyak satu per hari ber-dokumen.
 *
 * Keluaran terbaru dulu. `kunci` urut leksikografis = urut kronologis di
 * dalam satu lingkup.
 */
final class PengelompokTanggal
{
    /**
     * @param  array<string, int>  $jumlahPerHari  'Y-m-d' => jumlah dokumen
     * @return list<array{kunci: string, label: string, jumlah: int, mulai: string, selesai: string, telusuri: array<string, int|string>}>
     */
    public function kelompokkan(array $jumlahPerHari, LingkupGarisWaktu $lingkup): array
    {
        /** @var array<string, array{jumlah: int, contoh: CarbonImmutable}> $per */
        $per = [];

        foreach ($jumlahPerHari as $ymd => $jumlah) {
            $tanggal = RentangTanggal::tanggal((string) $ymd);
            $kunci = $this->kunci($tanggal, $lingkup->tingkat);

            $per[$kunci]['jumlah'] = ($per[$kunci]['jumlah'] ?? 0) + $jumlah;
            $per[$kunci]['contoh'] = $tanggal;
        }

        krsort($per);

        $hasil = [];

        foreach ($per as $kunci => $isi) {
            $hasil[] = $this->susun((string) $kunci, $isi['jumlah'], $isi['contoh'], $lingkup);
        }

        return $hasil;
    }

    private function kunci(CarbonImmutable $tanggal, string $tingkat): string
    {
        return match ($tingkat) {
            'tahun' => sprintf('%04d', $tanggal->year),
            'bulan' => $tanggal->format('Y-m'),
            'pekan' => sprintf('%04d-W%02d', $tanggal->isoWeekYear, $tanggal->isoWeek),
            default => $tanggal->toDateString(),
        };
    }

    /**
     * @return array{kunci: string, label: string, jumlah: int, mulai: string, selesai: string, telusuri: array<string, int|string>}
     */
    private function susun(string $kunci, int $jumlah, CarbonImmutable $contoh, LingkupGarisWaktu $lingkup): array
    {
        [$label, $mulai, $selesai, $telusuri] = match ($lingkup->tingkat) {
            'tahun' => [
                LabelWaktu::tahun($contoh),
                RentangTanggal::dari($contoh->year),
                RentangTanggal::dari($contoh->year, 12, 31),
                ['tingkat' => 'bulan', 'tahun' => $contoh->year],
            ],
            'bulan' => [
                LabelWaktu::bulan($contoh),
                RentangTanggal::dari($contoh->year, $contoh->month),
                RentangTanggal::dari($contoh->year, $contoh->month)->endOfMonth()->startOfDay(),
                ['tingkat' => 'pekan', 'tahun' => $contoh->year, 'bulan' => $contoh->month],
            ],
            'pekan' => $this->pekan($contoh, $lingkup),
            default => [
                LabelWaktu::hari($contoh),
                $contoh,
                $contoh,
                [...$lingkup->induk(), 'tingkat' => 'hari', 'tanggal' => $contoh->toDateString()],
            ],
        };

        return [
            'kunci' => $kunci,
            'label' => $label,
            'jumlah' => $jumlah,
            'mulai' => $mulai->toDateString(),
            'selesai' => $selesai->toDateString(),
            'telusuri' => $telusuri,
        ];
    }

    /**
     * Pekan dalam bulan lingkupnya: rentang dipotong ke bulan itu.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable, 3: array<string, int|string>}
     */
    private function pekan(CarbonImmutable $contoh, LingkupGarisWaktu $lingkup): array
    {
        $rentang = LingkupGarisWaktu::rentangPekanDalamBulan($lingkup->tahun, $lingkup->bulan, $contoh->isoWeek);

        return [
            LabelWaktu::pekan($contoh->isoWeek, $rentang->mulai, $rentang->selesai),
            $rentang->mulai,
            $rentang->selesai,
            ['tingkat' => 'hari', 'tahun' => $lingkup->tahun, 'bulan' => $lingkup->bulan, 'pekan' => $contoh->isoWeek],
        ];
    }
}
