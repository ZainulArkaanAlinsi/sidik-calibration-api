<?php

namespace App\Support;

use App\Models\RawMeasurement;
use App\Services\Calibration\ThermohygroCalculator;
use Illuminate\Support\Collection;

/**
 * Susun ulang PASANGAN standar/UUT dari baris `raw_measurements` satu titik.
 *
 * ## Kenapa ini ada
 *
 * Saudaranya [GridSensorMentah] sudah menjelaskan pola besarnya: sepuluh alat
 * menyimpan satu titik sebagai satu deret pembacaan datar, dan yang bentuknya
 * lain harus disusun ULANG tiap kali sesi tersimpan dihitung lagi.
 *
 * Ketiga alat suhu — Thermocouple, Termometer Gelas, Thermohygrometer — punya
 * bentuk ketiga: satu titik itu DUA deret sejajar, standar dan UUT, dibedakan
 * lewat `peran_sensor`. Meratakannya jadi satu deret bikin angka standar dan
 * angka UUT campur aduk, dan koreksi yang lahir dari situ tidak berarti apa-apa.
 *
 * ## Yang terjadi tanpa kelas ini
 *
 * Ketiga profil membaca `konteks.standar`, `konteks.uut`, dan kawan-kawannya.
 * Jalur SIMPAN sudah mengirimnya (`CalibrationController::susunPasanganStandarUut()`),
 * tapi dua jalur HITUNG ULANG tidak — dan efeknya bukan satu kunci yang kosong,
 * melainkan **setiap titik di setiap sesi ketiga alat itu** pulang sebagai
 * `hitung_ulang_gagal`:
 *
 *     0513-CAL-1124  titik 1,2,3   "Dryblock yang dipakai belum dipilih"
 *     0135-CAL-125   titik 1..5    "Oilbath yang dipakai belum dipilih"
 *     0312-CAL-624   titik 1..10   "baru punya 0 pembacaan standar"
 *
 * Padahal datanya ada semua di database. Itu persis pola yang sudah empat kali
 * kejadian di kelas ini — Viscometer, Gas Detector, TITS, lalu Enclosure — dan
 * yang bahayanya sudah ditulis sendiri di `CalibrationValidator`: peringatan
 * yang SELALU muncul melatih admin menekan "setujui tetap" tanpa membaca, lalu
 * peringatan yang benar-benar penting ikut tenggelam.
 *
 * Yang hilang juga bukan cuma ketenangan layar: pemeriksaan "apakah angka
 * tersimpan masih bisa direproduksi" jadi mati total buat ketiga alat. Kalau
 * tabel master bergeser sesudah sesi disimpan, tidak ada yang memberi tahu.
 *
 * ## `parameter` diturunkan dari `satuan`, bukan dari kolomnya sendiri
 *
 * Thermohygro membelah titiknya jadi grup suhu & kelembapan, dan `parameter`
 * itu yang menentukannya. `raw_measurements` tidak punya kolom `parameter` —
 * jalur simpan menuliskannya sebagai `satuan` (`%RH` buat kelembapan, satuan
 * alat buat suhu), jadi di sinilah dia dibaca balik.
 *
 * Salah menebak di sini TIDAK bikin error: `ThermohygroProfile` jatuh ke
 * `PARAMETER_SUHU` kalau kuncinya hilang, jadi sepuluh titik masuk satu grup
 * suhu dan U95 kelembapan tidak pernah lahir — salah yang rapi, tanpa keluhan.
 *
 * Balik `[]` — bukan pasangan kosong — kalau tidak ada baris ber-`peran_sensor`
 * standar/UUT. Alasannya sama seperti [GridSensorMentah]: profil alat lain tidak
 * pernah menengok kunci-kunci ini, dan kunci yang muncul kosong lebih berbahaya
 * daripada kunci yang tidak ada.
 */
final class PasanganStandarUutMentah
{
    public const PERAN_STANDAR = 'standar';

    public const PERAN_UUT = 'uut';

    /** Satuan yang menandai baris kelembapan — ditulis jalur simpan. */
    public const SATUAN_RH = '%RH';

    /**
     * @param  Collection<int, RawMeasurement>  $pembacaan  baris mentah SATU titik
     * @return array{standar?: list<float>, uut?: list<float>, no_probe?: int|null, parameter?: string}
     */
    public static function dari($pembacaan): array
    {
        $berperan = $pembacaan->filter(
            static fn (RawMeasurement $m): bool => in_array(
                $m->peran_sensor,
                [self::PERAN_STANDAR, self::PERAN_UUT],
                true,
            ) && $m->pembacaan !== null,
        );

        if ($berperan->isEmpty()) {
            return [];
        }

        $deret = static fn (string $peran): array => $berperan
            ->where('peran_sensor', $peran)
            ->sortBy('pembacaan_ke')
            ->map(static fn (RawMeasurement $m): float => (float) $m->pembacaan)
            ->values()
            ->all();

        // Nomor probe cuma menempel ke sisi STANDAR — sisi UUT memakai probe
        // bawaan alat pelanggan, yang justru sedang diukur penyimpangannya.
        // Persis aturan yang dipakai jalur simpan waktu menuliskan `sensor_ke`.
        $noProbe = $berperan
            ->where('peran_sensor', self::PERAN_STANDAR)
            ->first(static fn (RawMeasurement $m): bool => $m->sensor_ke !== null)
            ?->sensor_ke;

        return [
            'standar' => $deret(self::PERAN_STANDAR),
            'uut' => $deret(self::PERAN_UUT),
            'no_probe' => $noProbe === null ? null : (int) $noProbe,
            'parameter' => $berperan->first()?->satuan === self::SATUAN_RH
                ? ThermohygroCalculator::PARAMETER_KELEMBABAN
                : ThermohygroCalculator::PARAMETER_SUHU,
        ];
    }
}
