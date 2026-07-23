<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\RawMeasurement;
use App\Models\Standard;
use Illuminate\Support\Collection;

/**
 * Lembar PERHITUNGAN — tampilan sisi ADMIN dari satu sesi kalibrasi.
 *
 * Bentuknya ngikutin sheet "PERHITUNGAN" di `Master Olah Data_pH for trial.xlsm`
 * yang selama ini dipakai lab: identitas alat & customer, blok perhitungan
 * kondisi lingkungan, lalu dua tabel hasil (sebelum & sesudah adjustment) yang
 * masing-masing ditutup baris Average, Correction, dan STDEV.
 *
 * Yang penting dipahami: SEMUA angka di sini TURUNAN dari lembar kerja teknisi.
 * Nggak ada satu pun yang diketik ulang admin. Teknisi ngisi pembacaan + suhu
 * larutan, sisanya dihitung — itu inti "Excel diganti Admin Panel": hasilnya
 * sama persis, tapi nggak ada lagi salin-tempel antar sheet.
 *
 * DUA HAL YANG GAMPANG KEBALIK, jadi ditulis di sini:
 *
 * 1. `Correction` di lembar PERHITUNGAN = Average − Standard (error alatnya).
 *    `Correction` di SERTIFIKAT = Standard − Average (angka yang harus
 *    ditambahkan ke pembacaan). Dua-duanya bener, tandanya kebalikan. Jangan
 *    dipakai silang.
 * 2. Nilai `Standard` di sini BUKAN nilai nominal buffer (4,00), tapi nilai
 *    buffer pada SUHU LARUTAN saat itu (4,0092252 di 22,2 °C) — dihitung dari
 *    persamaan di sertifikat buffer. Makanya tabel sebelum & sesudah
 *    adjustment bisa punya nilai standar yang beda dikit: suhunya beda.
 */
class PerhitunganBuilder
{
    public function __construct(private readonly KondisiLingkungan $kondisi) {}

    /**
     * @return array<string, mixed>
     */
    public function bangun(CalibrationSession $sesi): array
    {
        $sesi->loadMissing([
            'equipment.customer', 'teknisi', 'standard', 'thermohygro',
            'rawMeasurements', 'uncertaintyCalculations.standard',
        ]);

        return [
            'nomor_sesi' => $sesi->nomor_sesi,
            'identitas_alat' => $this->identitasAlat($sesi),
            'identitas_customer' => $this->identitasCustomer($sesi),
            'kondisi_lingkungan' => [
                'suhu' => $this->kondisi->hitung($sesi, 'suhu'),
                'kelembaban' => $this->kondisi->hitung($sesi, 'kelembaban'),
                'thermohygro' => $sesi->thermohygro?->nama,
                'thermohygro_serial' => $sesi->thermohygro?->serial_number,
            ],
            'hasil' => [
                $this->tabel($sesi, 'sebelum_adjustment', 'Before Adjustment Reading'),
                $this->tabel($sesi, 'sesudah_adjustment', 'After Adjustment Reading'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function identitasAlat(CalibrationSession $sesi): array
    {
        $alat = $sesi->equipment;

        return [
            'nama_alat' => $alat?->nama_alat,
            'merk' => $alat?->merk,
            'type' => $alat?->model,
            'no_seri' => $alat?->serial_number,
            'rentang_ukur' => $alat && ($alat->range_min !== null || $alat->range_max !== null)
                ? ($alat->range_min ?? 0).'-'.($alat->range_max ?? 0)
                : null,
            'kapasitas_max' => $alat?->range_max !== null ? (float) $alat->range_max : null,
            'resolusi' => $alat?->resolusi !== null ? (float) $alat->resolusi : null,
            'satuan' => $alat?->satuan,
        ];
    }

    /** @return array<string, mixed> */
    private function identitasCustomer(CalibrationSession $sesi): array
    {
        return [
            'nama' => $sesi->equipment?->customer?->nama,
            'alamat' => $sesi->equipment?->customer?->alamat,
            'tanggal_terima' => $sesi->tanggal_terima?->toDateString(),
            'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi?->toDateString(),
        ];
    }

    /**
     * Satu tabel hasil (sebelum / sesudah adjustment), lengkap dengan baris
     * Average, Correction, STDEV, dan MAX STDEV.
     *
     * @return array<string, mixed>
     */
    private function tabel(CalibrationSession $sesi, string $tahap, string $judul): array
    {
        /** @var Collection<int, Collection<int, RawMeasurement>> $perTitik */
        $perTitik = $sesi->rawMeasurements
            ->where('tahap', $tahap)
            ->sortBy([['titik_ke', 'asc'], ['pembacaan_ke', 'asc']])
            ->groupBy('titik_ke');

        $standarPerTitik = $sesi->uncertaintyCalculations->keyBy('titik_ke');

        $titik = $perTitik
            ->map(function (Collection $pembacaan, int|string $titikKe) use ($sesi, $standarPerTitik): array {
                $nilai = $pembacaan->map(fn (RawMeasurement $m): float => (float) $m->pembacaan)->values();
                $suhu = $pembacaan
                    ->map(fn (RawMeasurement $m): ?float => $m->suhu !== null ? (float) $m->suhu : null)
                    ->filter(fn (?float $s): bool => $s !== null)
                    ->values();

                $rataSuhu = $suhu->isNotEmpty() ? $suhu->avg() : null;
                $rataNilai = $nilai->isNotEmpty() ? $nilai->avg() : null;

                // Standar yang bener-bener dipakai buat titik ini. Kalau
                // hitungannya udah jalan, ambil standar dari situ — biar lembar
                // perhitungan nggak bisa beda sama angka yang tersimpan.
                $standar = $standarPerTitik->get((int) $titikKe)?->standard ?? $sesi->standard;

                $nilaiStandar = $this->nilaiStandar($standar, $rataSuhu, $pembacaan->first());

                return [
                    'titik_ke' => (int) $titikKe,
                    'standard' => $nilaiStandar,
                    'standard_nominal' => (float) $pembacaan->first()->titik_ukur,
                    'standard_dari_suhu' => $standar?->nilaiPadaSuhu($rataSuhu) !== null,
                    'satuan' => $pembacaan->first()->satuan,
                    'pembacaan' => $pembacaan
                        ->map(fn (RawMeasurement $m): array => [
                            'repeat' => (int) $m->pembacaan_ke,
                            'nilai' => (float) $m->pembacaan,
                            'suhu' => $m->suhu !== null ? (float) $m->suhu : null,
                        ])
                        ->values()
                        ->all(),
                    'average' => $rataNilai,
                    'average_suhu' => $rataSuhu,
                    // Lihat catatan tanda di docblock kelas: di lembar ini
                    // Correction = Average − Standard.
                    'correction' => ($rataNilai !== null && $nilaiStandar !== null)
                        ? $rataNilai - $nilaiStandar
                        : null,
                    'stdev' => $this->stdevSampel($nilai->all()),
                ];
            })
            ->values();

        return [
            'tahap' => $tahap,
            'judul' => $judul,
            'titik' => $titik->all(),
            // MAX STDEV dipakai lab sebagai penanda cepat titik mana yang
            // pembacaannya paling nggak stabil.
            'max_stdev' => $titik->pluck('stdev')->filter(fn (?float $s): bool => $s !== null)->max(),
        ];
    }

    /**
     * Nilai standar di titik ini. Prioritas persamaan suhu dari sertifikat
     * buffer; kalau standarnya nggak punya (atau suhu larutan nggak dicatat),
     * jatuh ke nilai nominal yang diisi teknisi.
     */
    private function nilaiStandar(?Standard $standar, ?float $rataSuhu, ?RawMeasurement $contoh): ?float
    {
        return $standar?->nilaiPadaSuhu($rataSuhu)
            ?? ($contoh !== null ? (float) $contoh->titik_ukur : null);
    }

    /**
     * Standar deviasi SAMPEL (pembagi n−1) — sama persis sama fungsi STDEV di
     * Excel yang dipakai lembar aslinya, dan sama sama `GumCalculator`.
     *
     * @param  list<float>  $nilai
     */
    private function stdevSampel(array $nilai): ?float
    {
        $n = count($nilai);

        if ($n < 2) {
            return $n === 1 ? 0.0 : null;
        }

        $rata = array_sum($nilai) / $n;
        $jumlah = array_sum(array_map(fn (float $x): float => ($x - $rata) ** 2, $nilai));

        return sqrt($jumlah / ($n - 1));
    }
}
