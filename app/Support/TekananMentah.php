<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Bentuk ulang baris `raw_measurements` keluarga TEKANAN jadi masukan
 * kalkulator.
 *
 * Dipakai DUA jalur yang menghitung hal yang sama: `CalibrationValidator`
 * (sebelum sertifikat terbit) dan `HitungUlangSesi` (perintah artisan). Alat
 * baru yang cuma disambung ke salah satunya lolos tanpa error — dan itu sudah
 * menggigit tujuh kali di repo ini.
 *
 * ## Nol kolom baru
 *
 * Panduan eksternal mengusulkan tabel `pressure_points` sendiri. Repo ini
 * menempuh jalan yang tertulis (AGENTS.md §Alur Kerja poin 4): sumbu yang
 * sudah ada cukup.
 *
 *   - `peran_sensor` = arah (`tekanan_up` / `tekanan_down`);
 *   - `sensor_ke`    = pengulangan 1..3;
 *   - `titik_ukur`   = SETELAN UUT, dalam satuan pilihan teknisi.
 *
 * Yang tidak punya titik — varian kalibrator, satuan, tipe tampilan, rasio
 * jarum, media, beda tinggi — masuk `spesifikasi_alat.tekanan`.
 *
 * ## Kenapa kuncinya BUKAN `bacaan`
 *
 * Keluaran [dari] disebar (`...`) ke konteks yang sama dengan sepuluh alat
 * lain. `GayaMentah::dari()` memakai kunci `bacaan`; memakai kunci yang sama
 * di sini berarti satu jalur bisa menimpa jalur lain tanpa error.
 */
class TekananMentah
{
    public const KUNCI_SESI = 'tekanan';

    public const PERAN_UP = 'tekanan_up';

    public const PERAN_DOWN = 'tekanan_down';

    public const PERAN_SEMUA = [self::PERAN_UP, self::PERAN_DOWN];

    /**
     * Peran di sini BUKAN besaran alat — `GridSensorMentah::dari()` cuma
     * memulangkan `[]` kalau tidak ada `peran_sensor` sama sekali. Tanpa daftar
     * ini sesi tekanan nyasar ke jalur grid sensor dan dihitung dengan aturan
     * alat lain.
     */
    public const PERAN_BUKAN_BESARAN_ALAT = self::PERAN_SEMUA;

    public const KONTEKS_UP = 'tekanan_up';

    public const KONTEKS_DOWN = 'tekanan_down';

    public const TAMPILAN_ANALOG = 'analog';

    public const TAMPILAN_DIGITAL = 'digital';

    public const RASIO_JARUM = ['1/2', '1/5', '1/10'];

    public const JENIS_VAKUM = 'vakum';

    public const JENIS_NON_VAKUM = 'non_vakum';

    /**
     * Deret UP & DOWN SATU titik, urut pengulangan.
     *
     * Yang dioper pemanggil baris satu titik (keduanya memakai
     * `groupBy('titik_ke')`). Diurutkan per `sensor_ke` karena urutan baris
     * dari database tidak dijamin — dan di alat ini urutan BUKAN kosmetik:
     * histeresis per pengulangan adalah `up[i] − down[i]`, jadi pasangan yang
     * tertukar menerbitkan histeresis yang salah tanpa satu pun error.
     *
     * @param  Collection<int, RawMeasurement>  $baris
     * @return array<string, mixed>
     */
    public static function dari(Collection $baris): array
    {
        $tekanan = $baris->filter(
            static fn ($b): bool => in_array((string) $b->peran_sensor, self::PERAN_SEMUA, true)
        );

        if ($tekanan->isEmpty()) {
            return [];
        }

        $deret = [self::PERAN_UP => [], self::PERAN_DOWN => []];

        foreach ($tekanan as $b) {
            $deret[(string) $b->peran_sensor][(int) $b->sensor_ke] = (float) $b->pembacaan;
        }

        foreach ($deret as $peran => $nilai) {
            ksort($nilai);
            $deret[$peran] = array_values($nilai);
        }

        return [
            self::KONTEKS_UP => $deret[self::PERAN_UP],
            self::KONTEKS_DOWN => $deret[self::PERAN_DOWN],
        ];
    }

    /**
     * Blok tingkat-sesi, atau `null` kalau belum ada — pemanggil WAJIB
     * berhenti, bukan melanjutkan dengan nilai bawaan. Satuan yang tidak
     * dipilih dan varian kalibrator yang tidak ditentukan masing-masing
     * menghasilkan angka yang kelihatan wajar tapi tidak pernah bisa
     * ditelusuri.
     *
     * Angka dibaca lewat [angka] — koma desimal Indonesia (`99,6`) diterima
     * dan dibakukan ke titik, bukan dibuang jadi `99` atau dibaca `996`.
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat
     * @return array<string, mixed>|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = $spesifikasiAlat[self::KUNCI_SESI] ?? null;

        if (! is_array($blok) || $blok === []) {
            return null;
        }

        $teks = static fn (string $kunci): ?string => isset($blok[$kunci]) && trim((string) $blok[$kunci]) !== ''
            ? trim((string) $blok[$kunci])
            : null;

        return [
            'varian' => $teks('varian'),
            'satuan' => $teks('satuan'),
            'tampilan' => $teks('tampilan'),
            'rasio_jarum' => $teks('rasio_jarum'),
            'resolusi' => self::angka($blok['resolusi'] ?? null),
            'kapasitas' => self::angka($blok['kapasitas'] ?? null),
            'media' => ($m = self::angka($blok['media'] ?? null)) === null ? null : (int) $m,
            'tinggi_standar' => self::angka($blok['tinggi_standar'] ?? null),
            'tinggi_uut' => self::angka($blok['tinggi_uut'] ?? null),
            'beda_tinggi' => self::angka($blok['beda_tinggi'] ?? null),
        ];
    }

    /**
     * Angka dari isian, sadar koma desimal — lewat `AngkaDesimal`, satu-satunya
     * pembaku koma di repo ini (jangan bikin yang kedua).
     *
     * `"99,6"` → `99.6`. Dua pemisah (`"1.234,5"`, `"1,2,3"`) dibiarkan apa
     * adanya oleh `AngkaDesimal` dan gagal `is_numeric` di sini → `null`:
     * menebak mana pemisah ribuan menggeser angka seribu kali tanpa error.
     * `null` itu ditolak pemanggilnya dengan alasan yang kebaca.
     */
    public static function angka(mixed $isi): ?float
    {
        if ($isi === null || $isi === '') {
            return null;
        }

        $baku = AngkaDesimal::bakukan(is_string($isi) ? trim($isi) : $isi);

        return is_numeric($baku) ? (float) $baku : null;
    }
}
