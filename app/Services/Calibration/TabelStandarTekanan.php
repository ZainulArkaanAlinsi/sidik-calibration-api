<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Pembaca `database/data/tabel-standar-tekanan.json` — tabel referensi EMPAT
 * master tekanan (DRUCK07G, DRUCK13G, SPMK, Differential).
 *
 * Berkasnya DIGENERATE `docs/skrip/gen-tabel-standar-tekanan.py` dari workbook
 * master, dan skrip yang sama mengadu 962 sel perhitungan ke cache Excel
 * sebelum menulis. Jangan disunting tangan: tabel yang digeser tangan
 * menyimpang dari master diam-diam, dan itu sudah tiga kali meloloskan bug di
 * repo ini (TIDS, Timbangan, Micrometer).
 *
 * ## Kenapa tabel konversinya per VARIAN, bukan satu tabel global
 *
 * Tiap master bekerja dalam satuan internalnya sendiri — DRUCK07G kPa,
 * DRUCK13G Psi, SPMK Bar, Differential mBar — dan faktor konversinya ditulis
 * ulang per berkas, bukan diturunkan dari satu tabel. Hasilnya TIDAK saling
 * konsisten: Torr di DRUCK13G masih memakai faktor versi kPa (0,133322 —
 * temuan T-6), dan daftar satuan yang boleh dipilih pun beda (12 / 12 / 7 /
 * 10). Menyatukannya berarti menggeser angka yang sudah tercetak di
 * sertifikat salah satu varian.
 */
class TabelStandarTekanan
{
    public const DRUCK07G = 'druck07g';

    public const DRUCK13G = 'druck13g';

    public const SPMK = 'spmk';

    public const DIFFERENTIAL = 'differential';

    public const VARIAN = [self::DRUCK07G, self::DRUCK13G, self::SPMK, self::DIFFERENTIAL];

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @return array<string, mixed>|null */
    public static function varian(string $kode): ?array
    {
        $v = self::muat()['varian'][$kode] ?? null;

        return is_array($v) ? $v : null;
    }

    public static function satuanKerja(string $kode): ?string
    {
        return self::varian($kode)['satuan_kerja'] ?? null;
    }

    /**
     * Satuan yang BOLEH dipilih di varian ini, urut seperti dropdown master.
     *
     * @return list<string>
     */
    public static function satuanTersedia(string $kode): array
    {
        return array_values(array_map(
            static fn (array $k): string => (string) $k['satuan'],
            self::varian($kode)['konversi'] ?? [],
        ));
    }

    /**
     * Faktor satuan tampilan → satuan kerja varian ini, atau `null` kalau
     * satuannya tidak ada di daftar varian itu.
     *
     * Dicocokkan TANPA peka huruf besar, persis perbandingan string Excel:
     * master membandingkan `$G$7="Mpa"` padahal tabelnya menulis `MPa`, dan
     * Excel tetap menemukannya.
     */
    public static function faktor(string $kode, string $satuan): ?float
    {
        foreach (self::varian($kode)['konversi'] ?? [] as $k) {
            if (strcasecmp((string) $k['satuan'], trim($satuan)) === 0) {
                return (float) $k['faktor'];
            }
        }

        return null;
    }

    /** @return list<array{set_point: float, koreksi_up: float|null, koreksi_down: float|null, u95: float|null}> */
    public static function titikStandar(string $kode): array
    {
        return array_values(self::varian($kode)['titik_standar'] ?? []);
    }

    /**
     * Set point standar TERDEKAT ke `$nilaiKerja` — cermin
     * `INDEX(Index_titik, MATCH(MIN(ABS(Index_titik−E)), ABS(Index_titik−E), 0))`.
     *
     * Kembar jarak diambil yang PERTAMA muncul di daftar, karena `MATCH(…,0)`
     * berhenti di kecocokan pertama. Itu sebabnya pembandingnya `<`, bukan
     * `<=`: dengan `<=` titik yang berjarak sama dari dua set point pindah ke
     * set point yang kedua, dan koreksi standarnya ikut pindah tanpa error.
     * Master Differential benar-benar punya set point kembar (`0` di baris 8
     * DAN baris 19), jadi ini bukan kasus teoretis.
     */
    public static function indeksTerdekat(string $kode, float $nilaiKerja): ?float
    {
        $terbaik = null;
        $jarak = null;

        foreach (self::titikStandar($kode) as $b) {
            $d = abs((float) $b['set_point'] - $nilaiKerja);

            if ($jarak === null || $d < $jarak) {
                $jarak = $d;
                $terbaik = (float) $b['set_point'];
            }
        }

        return $terbaik;
    }

    /**
     * Baris tabel standar untuk set point PERSIS ini — `VLOOKUP(…, 0)`.
     *
     * @return array<string, mixed>|null
     */
    public static function baris(string $kode, float $setPoint): ?array
    {
        foreach (self::titikStandar($kode) as $b) {
            if ((float) $b['set_point'] === $setPoint) {
                return $b;
            }
        }

        return null;
    }

    /**
     * Rentang tabel koreksi standar dalam satuan kerja: `[min, maks]`.
     *
     * Ini yang dipakai penjaga "titik ukur di luar rentang kalibrator"
     * (temuan T-4). Master tidak punya penjaga itu: titik 1999,8 kPa di sesi
     * contoh DRUCK07G tetap "menemukan" indeks terdekat 200 kPa dan menerapkan
     * koreksinya — angka yang secara metrologi tidak berarti apa-apa.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function rentang(string $kode): ?array
    {
        $titik = array_map(static fn (array $b): float => (float) $b['set_point'], self::titikStandar($kode));

        return $titik === [] ? null : [min($titik), max($titik)];
    }

    /** @return array<string, mixed> identitas & jatuh tempo kalibrator standar */
    public static function standar(string $kode): array
    {
        return self::varian($kode)['standar'] ?? [];
    }

    /** @return array{nilai: float, sel_master: string, rumus_master: string, metode: string, catatan: string}|null */
    public static function drift(string $kode): ?array
    {
        return self::varian($kode)['drift'] ?? null;
    }

    /**
     * Massa jenis media (kg/m³) — `Media_Kalibrasi` master: 1 Water, 2 Oil,
     * 3 Air (Udara). Dipakai koreksi beda tinggi & koefisien sensitivitas beda
     * level SPMK.
     */
    public static function massaJenisMedia(string $kode, int $nomor): ?float
    {
        foreach (self::varian($kode)['media'] ?? [] as $m) {
            if ((int) $m['nomor'] === $nomor) {
                return (float) $m['massa_jenis'];
            }
        }

        return null;
    }

    /** @return list<array{nomor: int, media: string, massa_jenis: float}> */
    public static function daftarMedia(string $kode): array
    {
        return array_values(self::varian($kode)['media'] ?? []);
    }

    /**
     * Pembagi resolusi UUT untuk komponen "Daya Baca Alat": `Tabel_resolusi`
     * master. Analog dibagi rasio jarum/NST (2, 5, 10); digital dibagi 2.
     *
     * `null` kalau alat analog tanpa rasio jarum — dan pemanggil WAJIB
     * berhenti di situ. Centang ini tidak tercetak di sertifikat mana pun,
     * tapi di Differential dia menyumbang 99,99% budget; salah tebak di sini
     * menggeser U95 yang terbit.
     */
    public static function pembagiResolusi(string $tampilan, ?string $rasioJarum): ?float
    {
        $tabel = self::muat()['pembagi_resolusi'] ?? [];

        if ($tampilan === 'digital') {
            return (float) ($tabel['digital'] ?? 2.0);
        }

        if ($rasioJarum === null || ! isset($tabel[$rasioJarum])) {
            return null;
        }

        return (float) $tabel[$rasioJarum];
    }

    /** @return array{u: float, pembagi: float, vi: float|int, sumber: string} */
    public static function bedaLevel(): array
    {
        return self::muat()['beda_level'];
    }

    public static function gravitasiLokal(): float
    {
        return (float) self::muat()['gravitasi_lokal'];
    }

    /**
     * CMC yang TERTULIS di sel master (`DATABASE!S5/S6`), dalam satuan kerja.
     *
     * Bukan sumber CMC sesi — yang dipakai sesi dibaca dari baris
     * `calibration_capabilities` lampiran LK-285-IDN lalu dikonversi. Yang ini
     * cuma pembanding: test menegakkan keduanya sama, jadi kalau suatu saat
     * lampirannya berubah, bedanya ketahuan sebelum sertifikat terbit.
     */
    public static function cmcMaster(string $kode, string $kunci): ?float
    {
        $nilai = self::varian($kode)['cmc_master'][$kunci]['nilai'] ?? null;

        return $nilai === null ? null : (float) $nilai;
    }

    /**
     * Kunci baris `cmc_master` yang dipilih master untuk satu sesi — sama
     * dengan `cmc_kerja()` di `docs/skrip/gen-tabel-standar-tekanan.py`:
     * DRUCK memilih Vacum/Non Vacum dari jenis tekanan, SPMK & Differential
     * cuma punya satu baris.
     */
    public static function kunciCmcMaster(string $kode, bool $vakum): string
    {
        return match ($kode) {
            self::DRUCK07G, self::DRUCK13G => $vakum ? 'Vacum' : 'Non Vacum',
            self::SPMK => 'Pressure',
            default => '-10 mbar ~ 10 mbar',
        };
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-tekanan.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar tekanan nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi)
            || ! isset($isi['varian'], $isi['pembagi_resolusi'], $isi['beda_level'], $isi['gravitasi_lokal'])
            || array_diff(self::VARIAN, array_keys($isi['varian'])) !== []) {
            throw new RuntimeException("Tabel standar tekanan rusak: {$berkas}");
        }

        return self::$data = $isi;
    }

    /** Dipakai test yang menukar berkas tabelnya. */
    public static function lupakan(): void
    {
        self::$data = null;
    }
}
