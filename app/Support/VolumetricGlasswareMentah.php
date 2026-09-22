<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Bentuk ulang baris mentah **Volumetric Glassware** untuk jalur hitung ulang.
 *
 * Satu titik membawa TIGA deret dengan arti yang beda, masing-masing tiga
 * ulangan: berat wadah kosong (g), berat wadah + air (g), dan suhu air suling
 * (°C). Ketiganya disimpan di `raw_measurements` yang sudah ada, dibedakan
 * lewat `peran_sensor` — **nol kolom baru**.
 *
 * Dipakai bersama kedua keluarga (Fixed & Graduated): bentuk mentahnya
 * identik, beda keluarga cuma di cara profil menghitungnya.
 *
 * Alat ini WAJIB lahir bareng kelas ini. Pola "profil baru tanpa jalur hitung
 * ulang" sudah menggigit tujuh kali di repo ini — sesinya tersimpan rapi, tapi
 * `CalibrationValidator` dan `kalibrasi:hitung-ulang` tidak bisa menghitungnya
 * ulang, jadi tidak ada yang pernah mengadu angka tersimpan ke mentahnya.
 */
final class VolumetricGlasswareMentah
{
    /** Kunci blok tingkat-sesi di `calibration_sessions.spesifikasi_alat`. */
    public const KUNCI_SESI = 'volumetric';

    public const PERAN_KOSONG = 'vol_kosong';

    public const PERAN_ISI = 'vol_isi';

    public const PERAN_SUHU = 'vol_suhu';

    /** Kunci konteks yang dibaca profil — sengaja sama dengan nama perannya. */
    public const KONTEKS_KOSONG = self::PERAN_KOSONG;

    public const KONTEKS_ISI = self::PERAN_ISI;

    public const KONTEKS_SUHU = self::PERAN_SUHU;

    public const SATUAN_MASSA = 'g';

    public const SATUAN_SUHU = '°C';

    /** Ulangan per deret per titik — tiga, di kedua workbook. */
    public const PENGULANGAN = 3;

    /**
     * Peran yang besarannya BUKAN besaran alatnya.
     *
     * `CalibrationValidator` mengadu tiap pembacaan ke rentang & resolusi alat.
     * Rentang gelas ukur dalam **mL**, sementara yang diketik teknisi **gram**
     * dan **°C** — tanpa daftar ini, tiap pembacaan sesi yang sempurna
     * dilaporkan "jauh di luar rentang ukur alat". Peringatan palsu seperti itu
     * melatih admin menekan "setujui tetap" tanpa membaca.
     *
     * Ditulis di sini, bukan sebagai literal di validator: nama peran yang
     * ditulis dua kali di dua berkas bisa menyimpang diam-diam.
     *
     * @var list<string>
     */
    public const PERAN_BUKAN_BESARAN_ALAT = [self::PERAN_KOSONG, self::PERAN_ISI, self::PERAN_SUHU];

    /**
     * Ketiga deret dari baris mentah SATU titik.
     *
     * Balik `[]` kalau tidak ada satu pun baris ber-peran Volumetric — tanda
     * bagi pemanggil bahwa ini bukan sesi Volumetric.
     *
     * @param  Collection<int, object>  $baris
     * @return array<string, list<float>>
     */
    public static function dari(Collection $baris): array
    {
        $milik = $baris->filter(static fn ($b): bool => in_array(
            (string) $b->peran_sensor,
            self::PERAN_BUKAN_BESARAN_ALAT,
            true,
        ));

        if ($milik->isEmpty()) {
            return [];
        }

        return [
            self::KONTEKS_KOSONG => self::deret($milik, self::PERAN_KOSONG),
            self::KONTEKS_ISI => self::deret($milik, self::PERAN_ISI),
            self::KONTEKS_SUHU => self::deret($milik, self::PERAN_SUHU),
        ];
    }

    /**
     * Blok tingkat-SESI, dinormalkan. Balik `null` kalau bloknya belum ada.
     *
     * `kelas` dibaca apa adanya (huruf besar, tanpa spasi) dan TIDAK diberi
     * bawaan: kelas yang kosong harus berhenti sebagai "kelas belum diisi",
     * bukan diam-diam jadi Class B. γ yang salah menggeser seluruh V20 tanpa
     * error.
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat
     * @return array{kelas: string|null, toleransi_ml: float|null, resolusi_ml: float|null, neraca: string|null}|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = is_array($spesifikasiAlat) ? ($spesifikasiAlat[self::KUNCI_SESI] ?? null) : null;

        if (! is_array($blok)) {
            return null;
        }

        $angka = static fn (mixed $x): ?float => is_numeric($x) ? (float) $x : null;
        $teks = static fn (mixed $x): ?string => is_string($x) && trim($x) !== '' ? trim($x) : null;

        $kelas = $teks($blok['kelas'] ?? null);

        return [
            'kelas' => $kelas === null ? null : strtoupper($kelas),
            'toleransi_ml' => $angka($blok['toleransi_ml'] ?? null),
            'resolusi_ml' => $angka($blok['resolusi_ml'] ?? null),
            'neraca' => $teks($blok['neraca'] ?? null),
        ];
    }

    /**
     * Satu deret, urut `sensor_ke`.
     *
     * Kolomnya `pembacaan`, BUKAN `nilai`. `raw_measurements` tidak punya kolom
     * `nilai`, jadi `$b->nilai` pulang null tanpa satu pun error dan seluruh
     * deret jadi nol — jebakan yang sudah dicatat di `HydrometerMentah`.
     *
     * @param  Collection<int, object>  $baris
     * @return list<float>
     */
    private static function deret(Collection $baris, string $peran): array
    {
        return $baris
            ->filter(static fn ($b): bool => (string) $b->peran_sensor === $peran)
            ->sortBy(static fn ($b): int => (int) ($b->sensor_ke ?? $b->pembacaan_ke ?? 0))
            ->map(static fn ($b): float => (float) $b->pembacaan)
            ->values()
            ->all();
    }
}
