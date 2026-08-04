<?php

namespace App\Services;

use App\Models\CalibrationSession;

/**
 * Blok "PERHITUNGAN KONDISI LINGKUNGAN" di lembar olah data lab.
 *
 * Teknisi cuma nyatet dua angka per parameter — pembacaan thermohygro di AWAL
 * dan di AKHIR kerja. Sisanya dihitung:
 *
 *   Average          = (Awal + Akhir) / 2
 *   Δ                = |Akhir − Awal|          ← seberapa jauh ruangan bergeser
 *   Correction (C)   = dari sertifikat thermohygro (bukan dihitung di sini)
 *   Nilai terkoreksi = Average + C             ← ini yang dicetak di sertifikat
 *   U95% Sertifikat  = akar(U95%_stdTH² + Δ²)
 *
 * Rumus U95%-nya itu yang bikin pergeseran ruangan ikut kebawa: kalau suhu
 * bergerak 3 °C selama kalibrasi, ketidakpastian yang dilaporkan naik, bukan
 * pura-pura tetap. Angka ini dicocokin sama lembar olah data asli PT Sidik
 * (Master Olah Data_pH) dan hasilnya sama persis sampai belasan desimal —
 * lihat `PerhitunganTest`.
 */
class KondisiLingkungan
{
    /**
     * @return array{
     *     awal: float|null, akhir: float|null, average: float|null,
     *     indexed_value: float|null, correction: float|null, delta: float|null,
     *     u95_std_th: float|null, u95_sertifikat: float|null,
     *     nilai_terkoreksi: float|null, satuan: string,
     * }|null
     */
    public function hitung(CalibrationSession $sesi, string $parameter): ?array
    {
        [$awal, $akhir, $satuan] = match ($parameter) {
            'suhu' => [$sesi->suhu_awal, $sesi->suhu_akhir, '°C'],
            'kelembaban' => [$sesi->kelembaban_awal, $sesi->kelembaban_akhir, '%RH'],
            default => [null, null, ''],
        };

        // Belum ada satu pun pembacaan → nggak ada yang bisa dihitung. Balikin
        // null, bukan nol: "belum diukur" beda dari "diukur, hasilnya nol".
        $terisi = array_values(array_filter([$awal, $akhir], fn ($n): bool => $n !== null));

        if ($terisi === []) {
            return null;
        }

        $average = array_sum($terisi) / count($terisi);
        // Δ cuma ada artinya kalau DUA-DUANYA keukur. Satu pembacaan doang
        // nggak bisa bilang ruangannya bergeser apa nggak.
        $delta = ($awal !== null && $akhir !== null) ? abs($akhir - $awal) : null;

        // Titik koreksi dipilih menurut RATA-RATA pembacaan, bukan titik
        // pertama yang kebetulan kesimpen. Lihat `Standard::parameterKondisi`.
        $sertifikat = $sesi->thermohygro?->parameterKondisi($parameter, $average);
        $correction = $sertifikat['correction'] ?? null;
        $u95StdTh = $sertifikat['u95'] ?? null;

        return [
            'awal' => $awal,
            'akhir' => $akhir,
            'average' => $average,
            'indexed_value' => $sertifikat['indexed_value'] ?? null,
            'correction' => $correction,
            'delta' => $delta,
            'u95_std_th' => $u95StdTh,
            'u95_sertifikat' => $this->u95Sertifikat($u95StdTh, $delta),
            // Yang dicetak di "Env. Condition" sertifikat. Tanpa data koreksi
            // dari sertifikat thermohygro, yang dilaporkan pembacaan apa adanya.
            'nilai_terkoreksi' => $average + ($correction ?? 0.0),
            'satuan' => $satuan,
        ];
    }

    /**
     * Terapin hasil hitungan ke kolom yang dicetak di sertifikat. Dipanggil tiap
     * kali sesi disimpen, biar `suhu_ruang` & ketidakpastiannya nggak pernah
     * ketinggalan dari pembacaan awal/akhir yang baru diubah.
     */
    public function terapkan(CalibrationSession $sesi): void
    {
        $suhu = $this->hitung($sesi, 'suhu');
        $kelembaban = $this->hitung($sesi, 'kelembaban');

        $sesi->update(array_filter([
            'suhu_ruang' => $suhu['nilai_terkoreksi'] ?? null,
            'suhu_ketidakpastian' => $suhu['u95_sertifikat'] ?? null,
            'kelembaban' => $kelembaban['nilai_terkoreksi'] ?? null,
            'kelembaban_ketidakpastian' => $kelembaban['u95_sertifikat'] ?? null,
        ], fn ($nilai): bool => $nilai !== null));
    }

    /**
     * Ketidakpastian yang dilaporkan = ketidakpastian alat ukurnya sendiri,
     * digabung sama pergeseran ruangan selama kerja (akar jumlah kuadrat).
     */
    private function u95Sertifikat(?float $u95StdTh, ?float $delta): ?float
    {
        if ($u95StdTh === null) {
            return null;
        }

        return sqrt($u95StdTh ** 2 + ($delta ?? 0.0) ** 2);
    }
}
