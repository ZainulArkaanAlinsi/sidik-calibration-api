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
 * KELIMA TITIK DISEED, bukan satu.
 *
 * Versi pertama seeder ini sengaja cuma nyeed satu titik (~20 °C / ~50 %RH)
 * karena `Standard::parameterKondisi()` waktu itu cuma bisa megang satu, dan
 * docblock-nya udah mewanti: "kalau nanti ada sesi di luar rentang itu,
 * pemilihan titiknya yang harus dibikin otomatis". Itu kejadian — sesi
 * turbidimeter di ruangan 25,7 °C kena koreksi titik 19,6 °C (-0,23) dan
 * kecetak 25,47, padahal Excel lab pakai titik 29,4 °C (+0,35) dan dapat
 * 26,05. Selisih 0,58 °C di dokumen terakreditasi.
 *
 * Sekarang `parameterKondisi()` milih titik terdekat sendiri lewat Instrument
 * Indication, jadi yang diseed titik lengkapnya. Untungnya `titik_kalibrasi`
 * emang udah diarsipkan di JSON dari dulu buat momen ini — nggak ada yang
 * perlu diketik ulang dari CSV.
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
                    'parameter_kondisi' => $this->parameterKondisi($th),
                ],
            );
        }
    }

    /**
     * Susun `parameter_kondisi` dari KELIMA titik kalibrasi.
     *
     * `titik_kalibrasi` udah lama diarsipkan di JSON-nya buat kepentingan ini
     * — waktu itu belum kepakai karena `Standard::parameterKondisi()` cuma
     * bisa megang satu titik. Sekarang dia milih titik terdekat sendiri, jadi
     * yang diseed titik lengkapnya.
     *
     * `u95` tingkat-parameter ikut ditulis sebagai cadangan buat pemanggil
     * lama yang baca `parameter_kondisi[p]['u95']` langsung.
     *
     * @param  array<string, mixed>  $th
     * @return array<string, mixed>
     */
    private function parameterKondisi(array $th): array
    {
        $titik = $th['titik_kalibrasi'] ?? null;

        // Unit tanpa arsip titik lengkap tetap keseed satu titik seperti dulu —
        // lebih baik koreksinya kasar daripada kosong sama sekali.
        if (! is_array($titik)) {
            return $th['parameter_kondisi'];
        }

        $hasil = [];
        // `tekanan` cuma dipunyai Thermobarometer Lutron — TH-1..TH-7 nggak
        // ngukur tekanan sama sekali. Unit tanpa parameter itu dilewati di
        // dalam loop (daftar kosong + `parameter_kondisi` kosong = key-nya
        // nggak ditulis), jadi nggak ada baris `tekanan: []` palsu yang bikin
        // `Standard::parameterKondisi('tekanan')` balik nilai kosong padahal
        // mestinya null.
        foreach (['suhu', 'kelembaban', 'tekanan'] as $parameter) {
            $daftar = $titik[$parameter] ?? null;

            if (! is_array($daftar) || $daftar === []) {
                $cadangan = $th['parameter_kondisi'][$parameter] ?? null;

                if (is_array($cadangan) && $cadangan !== []) {
                    $hasil[$parameter] = $cadangan;
                }

                continue;
            }

            $hasil[$parameter] = [
                'u95' => $th['parameter_kondisi'][$parameter]['u95'] ?? ($daftar[0]['u95'] ?? null),
                'titik' => array_values($daftar),
            ];
        }

        return $hasil;
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
