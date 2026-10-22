<?php

namespace App\Services\Studio\PetaSel;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Support\JsonKanonik;
use JsonException;

/**
 * Peta sel per profil: `database/data/peta-sel/<kode_profil>.json` (rencana
 * studio-kendali-olah-data fase 1, 22 Okt 2026).
 *
 * Satu peta menjawab, untuk master Studio profil itu: lembar mana yang wajib
 * ada, sel jangkar mana yang wajib berisi teks apa, dan sel/rentang mana yang
 * diekstrak ke jalur mana di isi data acuan (`skemaAcuan()['sumber_json']`).
 * Peta yang rusak GAGAL KERAS ([PetaSelRusak]) — peta yang diam-diam salah
 * menghasilkan angka acuan yang salah, jadi bentuknya sendiri divalidasi
 * sebelum dipakai.
 *
 * ## Bentuk (format 1)
 *
 *     {
 *       "format": 1,
 *       "kode_profil": "micrometer",
 *       "sumber_json": "tabel-standar-micrometer.json",
 *       "lembar_wajib": ["DATABASE", ...],
 *       "jangkar": [{"sel": "DATABASE!S3", "teks": "Jenis/Rentang"}, ...],
 *       "varian": {"sumber": "nama", "pola": {"A": "regex", ...}},     // opsional
 *       "ekstrak": [
 *         {"jalur": "standar.nama", "tipe": "teks", "sel": "DATABASE!S13"},
 *         {"jalur": "cmc[kode=A].u95_um", "tipe": "angka", "sel": "DATABASE!T5", "bulat": 10},
 *         {"jalur": "cmc[kode=A].u95_mm", "tipe": "angka", "sel": "DATABASE!T5", "bagi": 1000},
 *         {"jalur": "standar.interval_tahun", "tipe": "bilangan_bulat", "sel": "DATABASE!X13"},
 *         {"jalur": "standar.tanggal_kalibrasi", "tipe": "tanggal", "sel": "DATABASE!W13"},
 *         {"jalur": "balok_ukur", "tipe": "peta_angka", "kunci": "S!Q10:Q132", "nilai": "S!R10:R132", "bulat": 10, "min_baris": 1},
 *         {"jalur": "cmc[kode={varian}].titik[{i}].tumpukan_mm", "tipe": "daftar_angka",
 *          "rentang": "P!C31:E33", "ulang": 11, "langkah_baris": 3, "buang_nol": true, "bulat": 10}
 *       ],
 *       "cek": [{"tipe": "selisih_maks", "sel": "P!H31", "banding": "<jalur>", "toleransi": 0.06,
 *                "ulang": 11, "langkah_baris": 3, "boleh_kosong": true}],
 *       "perlu_berkas_asli": ["..."], "bukti": ["..."]                   // dokumentasi
 *     }
 *
 * `bagi` (angka > 0, hanya `angka`/`daftar_angka`) membagi nilai sel SEBELUM
 * `bulat` — meniru generator yang menyimpan `float(sel) / 1000` (µm → mm).
 * `bilangan_bulat` memulangkan int (JSON `3`, bukan `3.0`); sel pecahan ditolak.
 * `daftar_objek` menulis list objek dari baris rentang yang sel `kunci`-nya
 * berisi (baris ber-kunci kosong dilewati, seperti generator):
 * `{"jalur": "balok_ukur.keping", "tipe": "daftar_objek", "kunci": "nominal_mm",
 *   "kolom": {"nominal_mm": "S!Q10:Q132", "u95_mm": "S!S10:S132"}, "min_baris": 1}`.
 *
 * Jalur: segmen dipisah titik; segmen boleh `nama[kode=X]` (elemen list yang
 * `kode`-nya X) atau `nama[N]` (indeks list). `{varian}` dan `{i}` (0-basis,
 * hanya bersama `ulang`) diganti sebelum dipakai. Jalur yang TIDAK disebut
 * peta dibawa apa adanya dari versi dasar (lihat [DrafAcuanMaster]).
 */
final class PetaSel
{
    public const FORMAT = 1;

    public const TIPE_EKSTRAK = ['teks', 'angka', 'bilangan_bulat', 'tanggal', 'peta_angka', 'daftar_angka', 'daftar_objek'];

    /** @param  array<string, mixed>  $isi */
    private function __construct(
        public readonly string $kodeProfil,
        public readonly string $berkas,
        public readonly string $sha256,
        public readonly array $isi,
    ) {}

    public static function direktori(): string
    {
        return (string) config('studio.peta_sel.direktori', database_path('data/peta-sel'));
    }

    /** Jalur berkas peta profil ini (ada atau tidak). */
    public static function jalur(string $kodeProfil): string
    {
        return rtrim(self::direktori(), '/\\').DIRECTORY_SEPARATOR.$kodeProfil.'.json';
    }

    /**
     * Peta profil ini, atau `null` bila profil belum terpetakan.
     *
     * @throws PetaSelRusak berkas ada tapi bentuknya tidak sah
     */
    public static function untuk(?string $kodeProfil): ?self
    {
        if ($kodeProfil === null || preg_match('/^[a-z0-9_]+$/', $kodeProfil) !== 1) {
            return null;
        }

        $berkas = self::jalur($kodeProfil);

        if (! is_file($berkas)) {
            return null;
        }

        $teks = (string) file_get_contents($berkas);

        try {
            $isi = json_decode($teks, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PetaSelRusak("Peta sel {$kodeProfil}: bukan JSON sah ({$e->getMessage()}).");
        }

        if (! is_array($isi)) {
            throw new PetaSelRusak("Peta sel {$kodeProfil}: isi bukan objek JSON.");
        }

        $masalah = self::validasi($kodeProfil, $isi);

        if ($masalah !== []) {
            throw new PetaSelRusak("Peta sel {$kodeProfil} tidak sah: ".implode('; ', $masalah));
        }

        return new self($kodeProfil, $berkas, JsonKanonik::sha256(JsonKanonik::dariTeks($teks)), $isi);
    }

    /**
     * Masalah bentuk peta (kosong = sah). Publik supaya test bisa mengunci
     * tiap aturannya tanpa menulis berkas.
     *
     * @param  array<string, mixed>  $p
     * @return list<string>
     */
    public static function validasi(string $kodeProfil, array $p): array
    {
        $m = [];

        if (($p['format'] ?? null) !== self::FORMAT) {
            $m[] = 'format harus '.self::FORMAT;
        }

        if (($p['kode_profil'] ?? null) !== $kodeProfil) {
            $m[] = "kode_profil harus '{$kodeProfil}' (sama dengan nama berkas)";
        }

        $profil = app(CalibrationProfileRegistry::class)->untukPaketAcuan($kodeProfil);
        $skema = $profil?->skemaAcuan();

        if ($skema === null) {
            $m[] = "profil '{$kodeProfil}' tidak ada atau belum punya skemaAcuan()";
        } elseif (($p['sumber_json'] ?? null) !== $skema['sumber_json']) {
            $m[] = "sumber_json harus '{$skema['sumber_json']}' (skemaAcuan profil)";
        }

        $lembar = $p['lembar_wajib'] ?? null;

        if (! is_array($lembar) || $lembar === [] || ! array_is_list($lembar)
            || array_filter($lembar, static fn ($l): bool => ! is_string($l) || $l === '') !== []) {
            $m[] = 'lembar_wajib harus list nama lembar yang tidak kosong';
            $lembar = [];
        }

        $selSah = static function (mixed $alamat, bool $rentang = false) use ($lembar): ?string {
            if (! is_string($alamat)) {
                return 'alamat bukan teks';
            }

            $a = Alamat::urai($alamat);

            if ($a === null || ($a['rentang'] && ! $rentang)) {
                return "alamat '{$alamat}' tidak sah".($rentang ? '' : ' (harus satu sel)');
            }

            return in_array($a['lembar'], $lembar, true) ? null : "lembar '{$a['lembar']}' di '{$alamat}' tidak ada di lembar_wajib";
        };

        $jangkar = $p['jangkar'] ?? null;

        if (! is_array($jangkar) || $jangkar === []) {
            $m[] = 'jangkar wajib ada (minimal satu sel berlabel per peta)';
        } else {
            foreach ($jangkar as $i => $j) {
                if (! is_array($j) || ! is_string($j['teks'] ?? null) || trim($j['teks']) === '') {
                    $m[] = "jangkar[{$i}]: teks wajib";

                    continue;
                }

                if (($e = $selSah($j['sel'] ?? null)) !== null) {
                    $m[] = "jangkar[{$i}]: {$e}";
                }
            }
        }

        $adaVarian = isset($p['varian']);

        if ($adaVarian) {
            $v = $p['varian'];
            $pola = is_array($v) ? ($v['pola'] ?? null) : null;

            if (! is_array($v) || ($v['sumber'] ?? null) !== 'nama' || ! is_array($pola) || $pola === []) {
                $m[] = "varian harus {sumber: 'nama', pola: {kode: regex}}";
            } else {
                foreach ($pola as $kode => $regex) {
                    if (! is_string($regex) || @preg_match('/'.$regex.'/u', '') === false) {
                        $m[] = "varian.pola.{$kode}: regex tidak sah";
                    }
                }
            }
        }

        $ekstrak = $p['ekstrak'] ?? null;

        if (! is_array($ekstrak) || $ekstrak === []) {
            $m[] = 'ekstrak wajib ada';
            $ekstrak = [];
        }

        foreach ($ekstrak as $i => $e) {
            $label = "ekstrak[{$i}]";

            if (! is_array($e) || ! in_array($e['tipe'] ?? null, self::TIPE_EKSTRAK, true)) {
                $m[] = "{$label}: tipe harus salah satu ".implode('/', self::TIPE_EKSTRAK);

                continue;
            }

            $m = [...$m, ...self::validasiJalur($label, $e['jalur'] ?? null, $adaVarian, isset($e['ulang']))];
            $m = [...$m, ...self::validasiUlang($label, $e)];

            if (isset($e['bulat']) && (! is_int($e['bulat']) || $e['bulat'] < 0 || $e['bulat'] > 15)) {
                $m[] = "{$label}: bulat harus bilangan bulat 0..15";
            }

            if (isset($e['bagi'])) {
                if (! in_array($e['tipe'], ['angka', 'daftar_angka'], true)) {
                    $m[] = "{$label}: bagi hanya untuk tipe angka/daftar_angka";
                } elseif ((! is_int($e['bagi']) && ! is_float($e['bagi'])) || $e['bagi'] <= 0) {
                    $m[] = "{$label}: bagi harus angka > 0";
                }
            }

            switch ($e['tipe']) {
                case 'teks':
                case 'angka':
                case 'bilangan_bulat':
                case 'tanggal':
                    if (($x = $selSah($e['sel'] ?? null)) !== null) {
                        $m[] = "{$label}: {$x}";
                    }

                    break;
                case 'daftar_angka':
                    if (($x = $selSah($e['rentang'] ?? null, true)) !== null) {
                        $m[] = "{$label}: {$x}";
                    }

                    break;
                case 'peta_angka':
                    $xk = $selSah($e['kunci'] ?? null, true);
                    $xn = $selSah($e['nilai'] ?? null, true);

                    if ($xk !== null || $xn !== null) {
                        $m[] = "{$label}: ".($xk ?? $xn);

                        break;
                    }

                    $k = Alamat::urai($e['kunci']);
                    $n = Alamat::urai($e['nilai']);

                    if ($k['b1'] !== $n['b1'] || $k['b2'] !== $n['b2'] || $k['k1'] !== $k['k2'] || $n['k1'] !== $n['k2']) {
                        $m[] = "{$label}: kunci & nilai harus satu kolom masing-masing dengan baris yang sama";
                    }

                    if (isset($e['min_baris']) && (! is_int($e['min_baris']) || $e['min_baris'] < 0)) {
                        $m[] = "{$label}: min_baris harus bilangan bulat >= 0";
                    }

                    break;
                case 'daftar_objek':
                    $kolom = $e['kolom'] ?? null;

                    if (! is_array($kolom) || $kolom === [] || array_is_list($kolom)) {
                        $m[] = "{$label}: kolom harus objek {kunci_objek: rentang satu kolom}";

                        break;
                    }

                    if (! is_string($e['kunci'] ?? null) || ! array_key_exists($e['kunci'], $kolom)) {
                        $m[] = "{$label}: kunci harus salah satu nama di kolom";
                    }

                    $baris = null;

                    foreach ($kolom as $nama => $rentang) {
                        if (preg_match('/^[a-z0-9_]+$/', (string) $nama) !== 1) {
                            $m[] = "{$label}: nama kolom '{$nama}' tidak sah";
                        }

                        if (($x = $selSah($rentang, true)) !== null) {
                            $m[] = "{$label}.{$nama}: {$x}";

                            continue;
                        }

                        $r = Alamat::urai($rentang);

                        if ($r['k1'] !== $r['k2'] || ($baris !== null && $baris !== [$r['b1'], $r['b2']])) {
                            $m[] = "{$label}: tiap kolom harus satu kolom dengan baris yang sama";
                        }

                        $baris ??= [$r['b1'], $r['b2']];
                    }

                    if (isset($e['min_baris']) && (! is_int($e['min_baris']) || $e['min_baris'] < 0)) {
                        $m[] = "{$label}: min_baris harus bilangan bulat >= 0";
                    }

                    break;
            }
        }

        foreach (($p['cek'] ?? []) as $i => $c) {
            $label = "cek[{$i}]";

            if (! is_array($c) || ($c['tipe'] ?? null) !== 'selisih_maks') {
                $m[] = "{$label}: tipe harus selisih_maks";

                continue;
            }

            if (($x = $selSah($c['sel'] ?? null)) !== null) {
                $m[] = "{$label}: {$x}";
            }

            if (! is_int($c['toleransi'] ?? null) && ! is_float($c['toleransi'] ?? null) || ($c['toleransi'] ?? 0) <= 0) {
                $m[] = "{$label}: toleransi harus angka > 0";
            }

            $m = [...$m, ...self::validasiJalur($label.'.banding', $c['banding'] ?? null, $adaVarian, isset($c['ulang']))];
            $m = [...$m, ...self::validasiUlang($label, $c)];
        }

        return $m;
    }

    /**
     * Ganti `{varian}`/`{i}` di jalur.
     */
    public static function isiJalur(string $jalur, ?string $varian, ?int $i): string
    {
        return str_replace(['{varian}', '{i}'], [(string) $varian, (string) $i], $jalur);
    }

    /** @return list<string> */
    private static function validasiJalur(string $label, mixed $jalur, bool $adaVarian, bool $adaUlang): array
    {
        if (! is_string($jalur) || $jalur === '') {
            return ["{$label}: jalur wajib"];
        }

        $m = [];

        if (str_contains($jalur, '{varian}') && ! $adaVarian) {
            $m[] = "{$label}: jalur memakai {varian} tapi peta tidak punya varian";
        }

        if (str_contains($jalur, '{i}') !== $adaUlang) {
            $m[] = "{$label}: {i} di jalur wajib berpasangan dengan ulang";
        }

        if (JalurIsi::urai(self::isiJalur($jalur, 'X', 0)) === null) {
            $m[] = "{$label}: jalur '{$jalur}' tidak sah";
        }

        return $m;
    }

    /**
     * @param  array<string, mixed>  $e
     * @return list<string>
     */
    private static function validasiUlang(string $label, array $e): array
    {
        if (! isset($e['ulang'])) {
            return isset($e['langkah_baris']) ? ["{$label}: langkah_baris tanpa ulang"] : [];
        }

        return is_int($e['ulang']) && $e['ulang'] >= 1 && is_int($e['langkah_baris'] ?? null) && $e['langkah_baris'] >= 1
            ? []
            : ["{$label}: ulang & langkah_baris harus bilangan bulat >= 1"];
    }
}
