<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Susun ulang lembar **Sieve Mesh** dari baris `raw_measurements`, plus blok
 * tingkat-sesinya dari `spesifikasi_alat.sieve`.
 *
 * ## Bentuk yang disimpan
 *
 *   titik_ke  peran_sensor   sensor_ke   arti
 *   1         sieve_warp     1..100      lebar lubang arah warp (x'), satuan alat
 *   2         sieve_weft     1..100      lebar lubang arah weft (y'), satuan alat
 *   3         sieve_kawat    1..100      diameter kawat, satuan alat
 *
 * Satu `titik_ke` per PARAMETER, bukan per opening, karena ketidakpastian lahir
 * per parameter (`PERHITUNGAN U95%` punya tiga budget: warp, weft, kawat) — dan
 * jalur hitung ulang mengelompokkan per `titik_ke`. Opening ke-37 yang diberi
 * `titik_ke = 37` melahirkan seratus "titik" yang masing-masing tidak bisa
 * dihitung sendirian.
 *
 * `sensor_ke` itu NOMOR OPENING di lembar, bukan urutan terisi. Bedanya
 * menentukan: komponen pengulangan master diambil dari opening 1..6
 * (`INPUT DATA!H89:N91 = G34..G39`), jadi lembar yang opening ke-3-nya kosong
 * tidak boleh diam-diam menggeser opening ke-7 ke posisi ketiga.
 *
 * ## Satuan dikonversi di TEMPAT PAKAI
 *
 * Alasannya sama persis dengan [HeightGaugeMentah::keMm]: yang tersimpan angka
 * mentah + `raw_measurements.satuan`, supaya simpan draft dua kali tidak
 * mengalikan 25,4 dua kali.
 *
 * Balik `[]` kalau tidak ada satu pun baris ber-peran milik lembar ini — profil
 * alat lain tidak pernah menengok kunci ini, dan kunci yang muncul kosong lebih
 * berbahaya daripada kunci yang tidak ada.
 */
class SieveMentah
{
    public const PERAN_WARP = 'sieve_warp';

    public const PERAN_WEFT = 'sieve_weft';

    public const PERAN_KAWAT = 'sieve_kawat';

    /** Urutan = `titik_ke` 1, 2, 3. */
    public const PERAN_URUT = [self::PERAN_WARP, self::PERAN_WEFT, self::PERAN_KAWAT];

    /** `peran_sensor` → kode parameter yang dipakai kalkulator & tabel lembar. */
    public const PARAMETER = [
        self::PERAN_WARP => 'warp',
        self::PERAN_WEFT => 'weft',
        self::PERAN_KAWAT => 'kawat',
    ];

    /** Kunci blok tingkat-sesi di `calibration_sessions.spesifikasi_alat`. */
    public const KUNCI_SESI = 'sieve';

    public const FAKTOR_KE_MM = ['mm' => 1.0, 'inch' => 25.4, 'µm' => 0.001];

    public const TIPE = ['compliance', 'inspection', 'calibration'];

    public const STANDAR = ['caliper', 'mikroskop'];

    public static function keMm(mixed $nilai, ?string $satuan): float
    {
        return (float) $nilai * (self::FAKTOR_KE_MM[(string) $satuan] ?? 1.0);
    }

    /** `titik_ke` milik satu peran (1..3), atau null untuk peran asing. */
    public static function titikKe(string $peran): ?int
    {
        $i = array_search($peran, self::PERAN_URUT, true);

        return $i === false ? null : $i + 1;
    }

    /**
     * @param  Collection<int, RawMeasurement>  $baris  baris satu `titik_ke` (atau seluruh sesi)
     * @return array{sieve_warp: array<int, float>, sieve_weft: array<int, float>, sieve_kawat: array<int, float>}|array{}
     */
    public static function dari(Collection $baris): array
    {
        $milikKita = $baris->filter(static fn ($b): bool => in_array(
            (string) $b->peran_sensor,
            self::PERAN_URUT,
            true,
        ));

        if ($milikKita->isEmpty()) {
            return [];
        }

        $hasil = [];

        foreach (self::PERAN_URUT as $peran) {
            $deret = [];

            foreach ($milikKita as $b) {
                if ((string) $b->peran_sensor !== $peran || ! is_numeric($b->pembacaan)) {
                    continue;
                }

                $deret[(int) ($b->sensor_ke ?? $b->pembacaan_ke ?? 0)] = self::keMm($b->pembacaan, $b->satuan);
            }

            ksort($deret);
            $hasil[$peran] = $deret;
        }

        return $hasil;
    }

    /**
     * Blok tingkat-SESI dari `spesifikasi_alat.sieve`, sudah dinormalkan ke
     * bentuk yang diterima `SieveCalculator::hitungSesi()`.
     *
     * Nilai pilihan di luar daftar dipulangkan `null` — BUKAN ditebak. Tipe
     * sieve menentukan jumlah minimum opening, dan standar menentukan pembagi
     * sertifikat, koefisien muai, dan lantai CMC; menebak salah satunya
     * menerbitkan budget atau vonis milik kombinasi yang tidak pernah dipakai
     * teknisi.
     *
     * Yang dioper `spesifikasi_alat`-nya, bukan model sesi — alasannya sama
     * dengan [HeightGaugeMentah::blokSesi].
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat
     * @return array{tipe: string|null, nominal: float|null, satuan: string, standar_dipakai: string|null, jumlah_opening_total: int|null, frame: list<array{diameter_mm: float|null, tinggi_mm: float|null}>}|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = is_array($spesifikasiAlat) ? ($spesifikasiAlat[self::KUNCI_SESI] ?? null) : null;

        if (! is_array($blok)) {
            return null;
        }

        $pilih = static function (mixed $x, array $sah): ?string {
            $bersih = is_string($x) ? strtolower(trim($x)) : null;

            return in_array($bersih, $sah, true) ? $bersih : null;
        };

        $satuan = (string) ($blok['satuan'] ?? 'mm');

        $frame = [];

        foreach (is_array($blok['frame'] ?? null) ? $blok['frame'] : [] as $f) {
            if (! is_array($f)) {
                continue;
            }

            $frame[] = [
                'diameter_mm' => is_numeric($f['diameter'] ?? null) ? (float) $f['diameter'] : null,
                'tinggi_mm' => is_numeric($f['tinggi'] ?? null) ? (float) $f['tinggi'] : null,
            ];
        }

        return [
            'tipe' => $pilih($blok['tipe'] ?? null, self::TIPE),
            'nominal' => is_numeric($blok['nominal'] ?? null) ? (float) $blok['nominal'] : null,
            'satuan' => array_key_exists($satuan, self::FAKTOR_KE_MM) ? $satuan : 'mm',
            'standar_dipakai' => $pilih($blok['standar_dipakai'] ?? null, self::STANDAR),
            'jumlah_opening_total' => is_numeric($blok['jumlah_opening_total'] ?? null)
                ? (int) $blok['jumlah_opening_total']
                : null,
            'frame' => $frame,
        ];
    }

    /**
     * Rata-rata suhu ruangan MENTAH — sumber `AE5`/`AE7` budget master (suhu
     * sieve & suhu standar, dua-duanya `=PERHITUNGAN!G17`).
     *
     * Ujung yang kosong dilewati, bukan dibaca nol: satu ujung kosong membuat
     * ϴ = −10 °C di ruang 20 °C, dan ci komponen muai melonjak tanpa error.
     */
    public static function rataSuhuRuang(mixed $awal, mixed $akhir): ?float
    {
        $terisi = array_values(array_filter([$awal, $akhir], static fn ($s): bool => is_numeric($s)));

        return $terisi === [] ? null : array_sum(array_map('floatval', $terisi)) / count($terisi);
    }
}
