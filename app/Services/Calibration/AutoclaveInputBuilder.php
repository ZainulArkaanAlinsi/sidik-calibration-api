<?php

namespace App\Services\Calibration;

use InvalidArgumentException;

/**
 * Rakit input `AutoclaveCalculator` dari data ukur teknisi + tabel kalibrator &
 * CMC server-side (`config/autoclave.php`).
 *
 * Dipisah dari controller supaya jalur PREVIEW (tanpa simpan) dan jalur SIMPAN
 * (sesi tersimpan) merakit input yang SAMA PERSIS — kalau logikanya kesalin dua
 * kali, angka preview bisa diam-diam beda dari yang tersimpan, dan buat lab
 * terakreditasi dua angka beda buat satu pengukuran itu temuan audit.
 */
class AutoclaveInputBuilder
{
    /**
     * @param  array<string, mixed>  $data  data ukur tervalidasi (set_point, suhu, tekanan)
     * @return array<string, mixed>
     */
    public function dari(array $data): array
    {
        $config = config('autoclave');

        $input = [
            'set_point' => (float) $data['set_point'],
            'cmc' => $config['cmc'],
        ];

        if (isset($data['suhu'])) {
            $disk = array_values($data['suhu']['disk'] ?? []);

            $input['suhu'] = [
                'disk' => $disk,
                'indikator' => $data['suhu']['indikator'] ?? [],
                'suhu_ruang' => $data['suhu']['suhu_ruang'] ?? [],
                'resolusi_alat' => $data['suhu']['resolusi_alat'] ?? $config['resolusi_alat']['suhu'],
                // Disk ke-i pakai Temperature Calibrator ke-i (config sudah urut).
                'standar' => array_map(
                    fn (array $kal): array => ['resolusi' => $kal['resolusi'], 'tabel' => $kal['tabel']],
                    array_slice($config['suhu'], 0, count($disk)),
                ),
            ];
        }

        // Tanpa bacaan Pressure Disk Logger nggak ada yang bisa dihitung —
        // baris kertasnya tetap kesimpen lewat `dataUkur()`, cuma Section C
        // sertifikatnya belum kebentuk.
        $adaBacaanStandar = collect($data['tekanan']['pembacaan_standar'] ?? [])
            ->contains(fn ($x): bool => $x !== null && $x !== '');

        if (isset($data['tekanan']) && $adaBacaanStandar) {
            $input['tekanan'] = [
                'uut_setting' => $this->bacaanUut($data['tekanan']),
                'satuan' => $data['tekanan']['satuan'] ?? 'Bar',
                'display' => $data['tekanan']['display'] ?? 'Digital',
                'resolusi_alat' => $data['tekanan']['resolusi_alat'] ?? $config['resolusi_alat']['tekanan'],
                'pembacaan_standar' => $data['tekanan']['pembacaan_standar'] ?? [],
                // Dua baris kertas ini nggak ikut ngitung, tapi dibawa terus
                // supaya yang tersimpan sama persis kayak yang ditulis teknisi.
                'indikator_pressure' => $data['tekanan']['indikator_pressure'] ?? [],
                'tekanan_atm_awal' => $data['tekanan']['tekanan_atm_awal'] ?? null,
                'standar' => [
                    'resolusi' => $config['tekanan']['resolusi'],
                    'tabel' => $config['tekanan']['tabel'],
                ],
            ];
        }

        if (isset($data['waktu'])) {
            $input['waktu'] = $data['waktu'];
        }

        return $input;
    }

    /**
     * Angka UUT yang dipakai ngitung tekanan.
     *
     * Kertas `SIDIK-FM-CAL-0539_Rev.4` nyediain LIMA kolom "Indikator
     * Pressure" (satu per titik waktu), sementara master `INPUT_DATA` nyimpen
     * SATU "UUT Reading" — di sesi master kelima kolomnya emang sama, jadi
     * nggak pernah ketahuan bedanya.
     *
     * Kalau kelima kolom itu ternyata beda, sistem MELEMPAR, bukan ngerata-rata
     * atau ngambil yang pertama. Merata-rata bacaan manometer itu keputusan
     * metrologi yang nggak ada di metode `SIDIK-IK-CAL-0531_Rev.4`; angka
     * karangan yang kelihatan wajar jauh lebih berbahaya daripada kiriman yang
     * mental, karena dia lolos sampai ke sertifikat.
     *
     * @param  array<string, mixed>  $tekanan
     */
    private function bacaanUut(array $tekanan): float
    {
        if (isset($tekanan['uut_setting'])) {
            return (float) $tekanan['uut_setting'];
        }

        $bacaan = array_values(array_unique(array_map(
            static fn ($x): float => (float) $x,
            array_filter(
                $tekanan['indikator_pressure'] ?? [],
                static fn ($x): bool => $x !== null && $x !== '',
            ),
        )));

        if ($bacaan === []) {
            throw new InvalidArgumentException(
                'Blok tekanan kosong: butuh baris Indikator Pressure atau tekanan.uut_setting.',
            );
        }

        if (count($bacaan) > 1) {
            throw new InvalidArgumentException(
                'Indikator Pressure beda antar titik waktu ('.implode(', ', array_map(
                    static fn (float $x): string => rtrim(rtrim(number_format($x, 6, '.', ''), '0'), '.'),
                    $bacaan,
                )).'). Kirim tekanan.uut_setting yang mau dipakai — sistem nggak milih sendiri.',
            );
        }

        return $bacaan[0];
    }
}
