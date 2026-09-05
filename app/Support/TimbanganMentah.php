<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Susun ulang blok lembar **Timbangan** dari baris `raw_measurements` satu
 * titik akurasi.
 *
 * ## Kenapa ini ada — kejadian ketujuh dengan pola yang sama
 *
 * Saudaranya [GridSensorMentah] dan [PasanganStandarUutMentah] sudah
 * menjelaskan pola besarnya: sepuluh alat menyimpan satu titik sebagai satu
 * deret pembacaan datar, dan yang bentuknya lain harus disusun ULANG tiap kali
 * sesi tersimpan dihitung lagi. Lupa melakukannya tidak menghasilkan error —
 * yang muncul `hitung_ulang_gagal` di setiap titik, tiap kali, sampai admin
 * belajar menekan "setujui tetap" tanpa membaca.
 *
 * Pola itu sudah menggigit **enam kali**: Viscometer, Gas Detector, TITS,
 * Enclosure, ketiga alat suhu, lalu perintah `kalibrasi:hitung-ulang` yang
 * "sukses" tanpa menghitung apa pun. Timbangan bentuk ketujuh, dan kelas ini
 * dibuat BERSAMAAN dengan profilnya supaya tidak jadi kejadian ketujuh yang
 * ditemukan belakangan.
 *
 * ## Bentuk yang disusun ulang
 *
 * Satu titik akurasi Timbangan itu **empat pembacaan + sampai enam nominal**,
 * bukan satu deret:
 *
 *   peran_sensor  sensor_ke  arti
 *   nominal       1–6        nominal anak timbangan slot Mass 1..6
 *   z1            null       penunjukan nol SEBELUM beban
 *   m             null       penunjukan berbeban
 *   m_aksen       null       penunjukan berbeban, ulangan
 *   z2            null       penunjukan nol SESUDAH beban
 *
 * Meratakannya jadi satu deret bikin nol dan berbeban campur aduk, dan
 * `Correction = ΣCN − (m̄ − z̄)` yang lahir dari situ tidak berarti apa-apa.
 *
 * ## Urutan slot nominal MENGIKAT
 *
 * `sensor_ke` menyimpan nomor slot Mass 1..6, dan slotnya diurut ulang di sini
 * — bukan diandalkan urutan baris dari database. Slot pertama punya `ci` = 10
 * di varian substitusi dan jadi satu-satunya sumber `u` standar; keping yang
 * mendarat di slot yang salah menggeser budget tanpa satu pun error. Urutan
 * baris `raw_measurements` sendiri tidak dijamin tanpa `ORDER BY`.
 *
 * ## Blok tingkat-SESI tidak lewat sini
 *
 * Repeatability, Loading Influence, Hysterisis, Scale Observation, dan Effect
 * of Tare tidak punya `titik_ke` — semuanya satu per sesi, bukan per titik.
 * Kelima blok itu hidup di `calibration_sessions.spesifikasi_alat`, dan jalur
 * hitung ulang sudah meneruskannya apa adanya (`'spesifikasi_alat' =>
 * $sesi->spesifikasi_alat`). Yang disusun ulang di sini cuma yang per titik.
 *
 * > **Batas yang diketahui, bukan kelupaan.** Karena kelima blok itu JSON,
 * > dua puluh pembacaan keterulangan tidak punya baris sendiri — jadi tidak
 * > punya `ocr_confidence`, `photo_path`, maupun `is_verified` per angka,
 * > tidak seperti pembacaan akurasi. Selama lembar ini belum punya jalur
 * > kamera (`bentukPindaiFoto()['didukung'] === false`) itu tidak menghilangkan
 * > apa pun; begitu kameranya dibangun, kelima blok wajib pindah ke
 * > `raw_measurements` lebih dulu.
 *
 * Balik `[]` — bukan blok kosong — kalau tidak ada baris ber-`peran_sensor`
 * milik lembar ini. Alasannya sama seperti dua saudaranya: profil alat lain
 * tidak pernah menengok kunci-kunci ini, dan kunci yang muncul kosong lebih
 * berbahaya daripada kunci yang tidak ada.
 */
class TimbanganMentah
{
    /** Peran pembacaan tunggal satu titik akurasi. */
    public const PERAN_PEMBACAAN = ['z1', 'm', 'm_aksen', 'z2'];

    /** Peran baris nominal anak timbangan. */
    public const PERAN_NOMINAL = 'nominal';

    /**
     * @param  Collection<int, RawMeasurement>  $baris  baris satu `titik_ke`
     * @return array{nominal: list<float>, z1: float|null, m: float|null, m_aksen: float|null, z2: float|null}|array{}
     */
    public static function dari(Collection $baris): array
    {
        $milikKita = $baris->filter(static fn ($b): bool => in_array(
            (string) $b->peran_sensor,
            [...self::PERAN_PEMBACAAN, self::PERAN_NOMINAL],
            true,
        ));

        if ($milikKita->isEmpty()) {
            return [];
        }

        $nominal = $milikKita
            ->filter(static fn ($b): bool => (string) $b->peran_sensor === self::PERAN_NOMINAL)
            // Diurut EKSPLISIT — lihat docblock kelas.
            ->sortBy(static fn ($b): int => (int) ($b->sensor_ke ?? 0))
            ->map(static fn ($b): float => (float) $b->pembacaan)
            ->values()
            ->all();

        $hasil = ['nominal' => $nominal];

        foreach (self::PERAN_PEMBACAAN as $peran) {
            $cocok = $milikKita->first(
                static fn ($b): bool => (string) $b->peran_sensor === $peran,
            );

            $hasil[$peran] = $cocok === null ? null : (float) $cocok->pembacaan;
        }

        return $hasil;
    }
}
