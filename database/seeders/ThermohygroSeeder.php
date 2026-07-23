<?php

namespace Database\Seeders;

use App\Models\Standard;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Thermohygro lab TH-1..TH-7 — data ASLI dari sheet `DATABASE` lembar olah
 * data (`Master Olah Data_pH for trial_CSV/DATABASE.csv`, blok
 * "Environmental Meter"), dikanonikalkan ke
 * `database/data/thermohygro-lab.json`.
 *
 * Kenapa ini perlu diseed, bukan diketik admin satu-satu: blok "Perhitungan
 * Kondisi Lingkungan" di lembar perhitungan baru bisa ngasih angka yang sama
 * kayak Excel kalau `parameter_kondisi`-nya keisi. Tanpa itu sistem tetap
 * jalan, cuma yang dilaporin pembacaan mentah tanpa koreksi dan U95%-nya
 * kosong — diam-diam beda dari sertifikat yang selama ini terbit.
 *
 * BATASAN YANG DISENGAJA: tiap unit sebenernya dikalibrasi di LIMA titik suhu
 * & lima titik kelembaban, dan Excel-nya milih titik yang paling dekat sama
 * kondisi ruangan waktu itu. `parameter_kondisi` cuma muat satu titik per
 * parameter, jadi yang diseed titik ~20 °C / ~50 %RH — kondisi ruang lab
 * sehari-hari, dan persis titik yang dipakai sheet PERHITUNGAN buat TH-3
 * (19,83 °C / 47,05 %RH). Selama kalibrasi dikerjain di ruang ber-AC angkanya
 * sama persis; kalau nanti ada sesi di luar rentang itu, pemilihan titiknya
 * yang harus dibikin otomatis — titik lengkapnya udah ikut diarsipkan di
 * `titik_kalibrasi` dalam JSON-nya supaya nggak perlu ngetik ulang dari CSV.
 */
class ThermohygroSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->muatData() as $th) {
            Standard::updateOrCreate(
                ['organization_id' => 1, 'serial_number' => $th['serial_number']],
                [
                    'nama' => $th['nama'],
                    // Merk sengaja dikosongin: FORM VALIDASI 2025-11-26 nyebut
                    // "Change to Xiaomi" tapi nggak per-unit, jadi nebak di sini
                    // malah bikin data master salah. Admin yang isi dari fisik alatnya.
                    'no_sertifikat' => $th['tertelusur_ke'],
                    'tertelusur_ke' => $th['tertelusur_ke'],
                    'berlaku_sampai' => $th['berlaku_sampai'],
                    // Thermohygro punya DUA parameter dengan U95% beda-beda, jadi
                    // kolom `ketidakpastian` yang cuma muat satu angka nggak dipakai
                    // — yang kepakai `parameter_kondisi` di bawah.
                    'faktor_cakupan' => 2,
                    'parameter_kondisi' => $th['parameter_kondisi'],
                ],
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function muatData(): array
    {
        $path = database_path('data/thermohygro-lab.json');

        if (! is_file($path)) {
            throw new RuntimeException("Data thermohygro nggak ketemu di $path");
        }

        /** @var array{thermohygro: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data['thermohygro'];
    }
}
