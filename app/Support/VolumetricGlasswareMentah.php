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
 * Suhu air Labu Ukur & Pipet Volume (workbook Rev.7) boleh ENAM baris — awal
 * & akhir tiap ulangan, `sensor_ke` 1..6 urut X1 awal, X1 akhir, X2 awal,
 * X2 akhir, X3 awal, X3 akhir (`INPUT DATA!H39:M39`). Kelas ini tidak perlu
 * tahu: deretnya diurutkan `sensor_ke` dan diteruskan utuh, jadi jalur simpan,
 * `CalibrationValidator`, dan `HitungUlangSesi` menerima enam angka yang sama.
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

    /**
     * Ulangan per deret per titik — tiga, di kedua workbook. Suhu air profil
     * Rev.7 boleh enam (lihat docblock kelas).
     */
    public const PENGULANGAN = 3;

    /**
     * Label keenam kotak suhu air lembar Rev.7, urut kotak = urut `vol_suhu`
     * yang dikirim — `INPUT DATA!H39:M39`. Satu sumber untuk bentuk lembar
     * (`pengulangan_arah`) dan pesan kotak yang kurang.
     *
     * @var list<string>
     */
    public const LABEL_KOTAK_SUHU = ['X1 Awal', 'X1 Akhir', 'X2 Awal', 'X2 Akhir', 'X3 Awal', 'X3 Akhir'];

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
     *                                                      `kapasitas_ml` = kapasitas maksimum alat. Lantai CMC master diambil dari
     *                                                      SITU (`PERHITUNGAN_U95%!C27 = INPUT DATA!E15`), bukan dari nominal tiap
     *                                                      titik — gelas ukur 100 mL yang dikalibrasi di 10 mL tetap berlantai CMC
     *                                                      100 mL.
     * @return array{kelas: string|null, toleransi_ml: float|null, resolusi_ml: float|null, kapasitas_ml: float|null, neraca: string|null}|null
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
            'kapasitas_ml' => $angka($blok['kapasitas_ml'] ?? null),
            'neraca' => $teks($blok['neraca'] ?? null),
        ];
    }

    /**
     * Tafsir ENAM kotak suhu air (lembar Rev.7) yang dikirim, posisi demi
     * posisi — kotak kosong TIDAK dirapatkan.
     *
     *  - keenamnya terisi → enam bacaan (awal & akhir tiap ulangan);
     *  - hanya kotak Awal (1, 3, 5) terisi dan ketiga kotak Akhir kosong →
     *    satu bacaan per ulangan = tiga bacaan, jalur lama persis. Nilai Akhir
     *    TIDAK dikarang;
     *  - pola lain → `bacaan` kosong, `kurang` = label kotak yang harus diisi:
     *    Awal yang kosong kalau belum satu pun Akhir terisi (isi Awal saja
     *    sudah sah), selain itu semua kotak yang kosong (lengkapi keenamnya).
     *
     * Merapatkan kotak kosong itu yang dicegah: [X1 Awal, X1 Akhir, X2 Awal,
     * null, null, null] akan terbaca sebagai tiga ulangan yang salah tempat —
     * angka yang kelihatan wajar, tanpa error.
     *
     * @param  array<int, mixed>  $kotak  tepat enam, urut [LABEL_KOTAK_SUHU]
     * @return array{bacaan: list<float>, kurang: list<string>}
     */
    public static function tafsirKotakSuhu(array $kotak): array
    {
        $kotak = array_values($kotak);
        $terisi = array_map(static fn (mixed $x): bool => is_numeric($x), $kotak);
        $kosong = static fn (array $posisi): array => array_values(array_map(
            static fn (int $i): string => self::LABEL_KOTAK_SUHU[$i],
            array_filter($posisi, static fn (int $i): bool => ! $terisi[$i]),
        ));

        if (! in_array(false, $terisi, true)) {
            return ['bacaan' => array_map('floatval', $kotak), 'kurang' => []];
        }

        $awal = [0, 2, 4];
        $akhirTerisi = array_filter([1, 3, 5], static fn (int $i): bool => $terisi[$i]);

        if ($akhirTerisi === []) {
            $kurang = $kosong($awal);

            return $kurang === []
                ? ['bacaan' => array_map(static fn (int $i): float => (float) $kotak[$i], $awal), 'kurang' => []]
                : ['bacaan' => [], 'kurang' => $kurang];
        }

        return ['bacaan' => [], 'kurang' => $kosong(range(0, count($kotak) - 1))];
    }

    /**
     * Nomor kotak tempat baris suhu SATU BACAAN PER ULANGAN disajikan di
     * lembar enam kotak: bacaan ke-k → kotak 2k − 1 (X1/X2/X3 Awal).
     *
     * Sesi Labu Ukur/PV yang tersimpan dengan tiga suhu (`sensor_ke` 1..3 —
     * sesi lama, atau kiriman Awal saja) dibuka ulang di lembar enam kotak.
     * HP menaruh tiap baris di kotak `pembacaan_ke − 1`; tanpa pemetaan ini
     * ketiganya mendarat di X1 Awal, X1 Akhir, X2 Awal, dan teknisi yang
     * melengkapi sisanya menghasilkan penempatan salah tanpa error. Barisnya
     * sendiri TIDAK diubah — ini cuma cara menyajikannya; kiriman ulang yang
     * tidak disentuh kembali jadi tiga bacaan yang sama (`tafsirKotakSuhu`).
     *
     * Hanya titik yang tepat tiga baris suhunya; titik enam bacaan disajikan
     * apa adanya.
     *
     * @param  Collection<int, object>  $baris
     * @return array<int, int> id baris → nomor kotak (1-based)
     */
    public static function kotakSajianSuhuTigaBacaan(Collection $baris): array
    {
        $peta = [];

        $baris
            ->filter(static fn ($b): bool => (string) $b->peran_sensor === self::PERAN_SUHU)
            ->groupBy(static fn ($b): string => $b->tahap.'|'.$b->titik_ke)
            ->each(function (Collection $grup) use (&$peta): void {
                if ($grup->count() !== self::PENGULANGAN) {
                    return;
                }

                $grup->sortBy(static fn ($b): int => (int) ($b->sensor_ke ?? $b->pembacaan_ke ?? 0))
                    ->values()
                    ->each(function ($b, int $k) use (&$peta): void {
                        $peta[(int) $b->id] = 2 * $k + 1;
                    });
            });

        return $peta;
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
