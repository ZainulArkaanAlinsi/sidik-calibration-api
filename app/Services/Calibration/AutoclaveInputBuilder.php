<?php

namespace App\Services\Calibration;

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

        if (isset($data['tekanan'])) {
            $input['tekanan'] = [
                'uut_setting' => (float) $data['tekanan']['uut_setting'],
                'satuan' => $data['tekanan']['satuan'] ?? 'Bar',
                'display' => $data['tekanan']['display'] ?? 'Digital',
                'resolusi_alat' => $data['tekanan']['resolusi_alat'] ?? $config['resolusi_alat']['tekanan'],
                'pembacaan_standar' => $data['tekanan']['pembacaan_standar'] ?? [],
                'standar' => [
                    'resolusi' => $config['tekanan']['resolusi'],
                    'tabel' => $config['tekanan']['tabel'],
                ],
            ];
        }

        return $input;
    }
}
