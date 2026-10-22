<?php

namespace App\Services\Studio\PetaSel;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Studio\Workbook\PetaProfilWorkbook;
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
 * berisi (baris ber-kunci kosong dilewati, seperti generator). Kolom boleh
 * berbentuk `{"rentang", "tipe": angka|teks|bilangan_bulat|tanggal, "boleh_kosong",
 * "gabungan", "bukan_angka_jadi_null"}` — `gabungan: true` membaca sel kosong di dalam rentang merge
 * dari sel kiri-atasnya (xlsm; CSV tidak membawa merge):
 * `{"jalur": "balok_ukur.keping", "tipe": "daftar_objek", "kunci": "nominal_mm",
 *   "kolom": {"nominal_mm": "S!Q10:Q132", "u95_mm": "S!S10:S132"}, "min_baris": 1}`.
 *
 * Meniru generator Enclosure (`gen-tabel-kalibrator-enclosure.py`):
 * `nol_di_bawah: 1e-9` (angka/peta_angka) menjadikan derau float Excel
 * |x| < ambang = 0.0, lalu `angka_penting: 10` membulatkan ke 10 angka
 * penting (`float(f"{x:.10g}")`). `peta_angka` boleh `kunci_teks: true`
 * (kunci `25`, `-100` — bukan `25.0`) dan `lewati_galat: true` (sel bernilai
 * teks seperti `#REF!` dilewati seperti sel kosong, bukan galat).
 * `angka_atau_teks` (satu sel): angka -> float, selain itu teks apa adanya
 * (`'-'`, `'all'` Tabel_MPE Sieve = makna, bukan nol; `angka_atau_teks()` generator).
 * `daftar_kunci` menulis list kunci teks unik (urutan kemunculan) dari
 * beberapa rentang satu kolom: `{"tipe": "daftar_kunci", "rentang": [..]}`.
 *
 * Jalur: segmen dipisah titik; segmen boleh `nama[kode=X]` (elemen list yang
 * `kode`-nya X) atau `nama[N]` (indeks list). `{varian}` dan `{i}` (0-basis,
 * hanya bersama `ulang`) diganti sebelum dipakai. Jalur yang TIDAK disebut
 * peta dibawa apa adanya dari versi dasar (lihat [DrafAcuanMaster]).
 */
final class PetaSel
{
    public const FORMAT = 1;

    public const TIPE_EKSTRAK = ['teks', 'angka', 'bilangan_bulat', 'tanggal', 'peta_angka', 'daftar_angka', 'daftar_objek', 'daftar_kunci', 'angka_atau_teks'];

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

        // Kode varian yang sah untuk filter `varian` per butir (lihat [untukVarian]).
        $kodeVarian = is_array($p['varian']['pola'] ?? null) ? array_map('strval', array_keys($p['varian']['pola'])) : [];
        $varianSah = static function (mixed $butir, string $label) use ($kodeVarian): ?string {
            if (! is_array($butir) || ! array_key_exists('varian', $butir)) {
                return null;
            }

            $v = $butir['varian'];

            return is_array($v) && $v !== [] && array_is_list($v) && array_diff($v, $kodeVarian) === []
                ? null
                : "{$label}: varian harus list kode dari varian.pola (".implode(', ', $kodeVarian).')';
        };

        $lembarMentah = $p['lembar_wajib'] ?? null;
        $lembar = [];

        if (! is_array($lembarMentah) || $lembarMentah === [] || ! array_is_list($lembarMentah)) {
            $m[] = 'lembar_wajib harus list nama lembar yang tidak kosong';
        } else {
            foreach ($lembarMentah as $i => $l) {
                // String, atau {nama, varian} untuk lembar yang cuma ada di varian tertentu.
                $nama = is_array($l) ? ($l['nama'] ?? null) : $l;

                if (! is_string($nama) || $nama === '') {
                    $m[] = 'lembar_wajib harus list nama lembar yang tidak kosong';

                    continue;
                }

                if (($x = $varianSah($l, "lembar_wajib[{$i}]")) !== null) {
                    $m[] = $x;
                }

                $lembar[] = $nama;
            }
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

                if (($e = $varianSah($j, "jangkar[{$i}]")) !== null) {
                    $m[] = $e;
                }
            }
        }

        $adaVarian = isset($p['varian']);

        if ($adaVarian) {
            $v = $p['varian'];
            $pola = is_array($v) ? ($v['pola'] ?? null) : null;

            if (! is_array($v) || ($v['sumber'] ?? null) !== 'nama' || ! is_array($pola) || $pola === []
                || ! in_array($v['lainnya'] ?? 'tolak', ['tolak', 'lewati'], true)) {
                $m[] = "varian harus {sumber: 'nama', pola: {kode: regex}, lainnya?: tolak|lewati}";
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

        // `paket_lain`: data milik paket LAIN yang ikut dibawa master ini
        // (balok ukur Micrometer di master Dial). Diekstrak untuk DIADU dengan
        // versi berlaku paket itu (DataBersamaPaket), tidak pernah ditulis.
        $semuaEkstrak = [];

        foreach ($ekstrak as $i => $e) {
            $semuaEkstrak["ekstrak[{$i}]"] = $e;
        }

        $paketLain = $p['paket_lain'] ?? [];

        if (! is_array($paketLain) || ! array_is_list($paketLain)) {
            $m[] = 'paket_lain harus list {paket, ekstrak}';
            $paketLain = [];
        }

        foreach ($paketLain as $j => $pl) {
            $kode = is_array($pl) ? ($pl['paket'] ?? null) : null;

            if (! is_string($kode) || $kode === $kodeProfil || app(CalibrationProfileRegistry::class)->untukPaketAcuan($kode) === null) {
                $m[] = "paket_lain[{$j}]: paket harus kode paket ber-skema lain";
            }

            if (! is_array($pl) || ! is_array($pl['ekstrak'] ?? null) || $pl['ekstrak'] === []) {
                $m[] = "paket_lain[{$j}]: ekstrak wajib ada";

                continue;
            }

            foreach ($pl['ekstrak'] as $i => $e) {
                if (is_array($e) && str_contains((string) ($e['jalur'] ?? ''), '{varian}')) {
                    $m[] = "paket_lain[{$j}].ekstrak[{$i}]: {varian} tidak berlaku untuk paket lain";
                }

                $semuaEkstrak["paket_lain[{$j}].ekstrak[{$i}]"] = $e;
            }
        }

        foreach ($semuaEkstrak as $label => $e) {
            if (($x = $varianSah($e, $label)) !== null) {
                $m[] = $x;
            }

            if (! is_array($e) || ! in_array($e['tipe'] ?? null, self::TIPE_EKSTRAK, true)) {
                $m[] = "{$label}: tipe harus salah satu ".implode('/', self::TIPE_EKSTRAK);

                continue;
            }

            $m = [...$m, ...self::validasiJalur($label, $e['jalur'] ?? null, $adaVarian, isset($e['ulang']))];
            $m = [...$m, ...self::validasiUlang($label, $e)];

            if (isset($e['bulat']) && (! is_int($e['bulat']) || $e['bulat'] < 0 || $e['bulat'] > 15)) {
                $m[] = "{$label}: bulat harus bilangan bulat 0..15";
            }

            if (isset($e['angka_penting']) && (! in_array($e['tipe'], ['angka', 'peta_angka'], true) || ! is_int($e['angka_penting']) || $e['angka_penting'] < 1 || $e['angka_penting'] > 17)) {
                $m[] = "{$label}: angka_penting hanya untuk angka/peta_angka, bilangan bulat 1..17";
            }

            if (isset($e['nol_di_bawah']) && (! in_array($e['tipe'], ['angka', 'peta_angka'], true) || (! is_int($e['nol_di_bawah']) && ! is_float($e['nol_di_bawah'])) || $e['nol_di_bawah'] <= 0)) {
                $m[] = "{$label}: nol_di_bawah hanya untuk angka/peta_angka, angka > 0";
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
                case 'angka_atau_teks':
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

                    foreach (['kunci_teks', 'lewati_galat'] as $bendera) {
                        if (isset($e[$bendera]) && ! is_bool($e[$bendera])) {
                            $m[] = "{$label}: {$bendera} harus boolean";
                        }
                    }

                    break;
                case 'daftar_kunci':
                    $r = $e['rentang'] ?? null;

                    if (! is_array($r) || $r === [] || ! array_is_list($r)) {
                        $m[] = "{$label}: rentang harus list rentang satu kolom";

                        break;
                    }

                    foreach ($r as $i => $a) {
                        if (($x = $selSah($a, true)) !== null) {
                            $m[] = "{$label}.rentang[{$i}]: {$x}";
                        } elseif (($u = Alamat::urai($a))['k1'] !== $u['k2']) {
                            $m[] = "{$label}.rentang[{$i}]: harus satu kolom";
                        }
                    }

                    break;
                case 'daftar_objek':
                    $kolom = $e['kolom'] ?? null;

                    if (! is_array($kolom) || $kolom === [] || array_is_list($kolom)) {
                        $m[] = "{$label}: kolom harus objek {kunci_objek: rentang satu kolom}";

                        break;
                    }

                    $rentangKolom = [];
                    $jumlah = null;

                    foreach ($kolom as $nama => $spek) {
                        if (preg_match('/^[a-z0-9_]+$/', (string) $nama) !== 1) {
                            $m[] = "{$label}: nama kolom '{$nama}' tidak sah";
                        }

                        // `{"nilai_tetap": x}`: kolom yang di varian ini tidak punya sel
                        // (mis. resolusi neraca Graduated = null) — ditulis apa adanya.
                        if (is_array($spek) && array_key_exists('nilai_tetap', $spek)) {
                            if (! is_scalar($spek['nilai_tetap']) && $spek['nilai_tetap'] !== null) {
                                $m[] = "{$label}.{$nama}: nilai_tetap harus skalar atau null";
                            }

                            continue;
                        }

                        $rentang = is_array($spek) ? ($spek['rentang'] ?? null) : $spek;

                        if (is_array($spek) && ! in_array($spek['tipe'] ?? 'angka', ['angka', 'teks', 'bilangan_bulat', 'tanggal'], true)) {
                            $m[] = "{$label}.{$nama}: tipe kolom harus angka/teks/bilangan_bulat/tanggal";
                        }

                        foreach (['boleh_kosong', 'gabungan', 'bukan_angka_jadi_null'] as $bendera) {
                            if (is_array($spek) && isset($spek[$bendera]) && ! is_bool($spek[$bendera])) {
                                $m[] = "{$label}.{$nama}: {$bendera} harus boolean";
                            }
                        }

                        if (($x = $selSah($rentang, true)) !== null) {
                            $m[] = "{$label}.{$nama}: {$x}";

                            continue;
                        }

                        $r = Alamat::urai($rentang);
                        $rentangKolom[(string) $nama] = true;

                        // Tiap kolom satu kolom sel; baris SEJAJAR (jumlah sama, awal boleh beda —
                        // dua blok di lembar yang sama, mis. koreksi & U95 kalibrator).
                        if ($r['k1'] !== $r['k2'] || ($jumlah !== null && $jumlah !== $r['b2'] - $r['b1'])) {
                            $m[] = "{$label}: tiap kolom harus satu kolom dengan jumlah baris yang sama";
                        }

                        $jumlah ??= $r['b2'] - $r['b1'];
                    }

                    if (! is_string($e['kunci'] ?? null) || ! isset($rentangKolom[$e['kunci']])) {
                        $m[] = "{$label}: kunci harus salah satu nama di kolom";
                    }

                    if (isset($e['lewati_jika']) && ! in_array($e['lewati_jika'], ['kosong', 'bukan_angka'], true)) {
                        $m[] = "{$label}: lewati_jika harus kosong/bukan_angka";
                    }

                    foreach ((array) ($e['lewati_kolom'] ?? []) as $k) {
                        if (! isset($rentangKolom[$k])) {
                            $m[] = "{$label}: lewati_kolom '{$k}' bukan kolom bersel";
                        }
                    }

                    if (isset($e['urut']) && ! isset($rentangKolom[$e['urut']])) {
                        $m[] = "{$label}: urut harus nama kolom bersel";
                    }

                    if (isset($e['min_baris']) && (! is_int($e['min_baris']) || $e['min_baris'] < 0)) {
                        $m[] = "{$label}: min_baris harus bilangan bulat >= 0";
                    }

                    break;
            }
        }

        // Fase 3: number format sel SERTIFIKAT .xlsm per kolom cetak. Belum
        // dipakai render; bentuknya tetap dijaga supaya tidak diam-diam rusak.
        if (isset($p['format_sertifikat'])) {
            $entri = $p['format_sertifikat']['entri'] ?? null;

            if (! is_array($entri) || $entri === [] || ! array_is_list($entri)) {
                $m[] = 'format_sertifikat.entri harus list';
                $entri = [];
            }

            foreach ($entri as $i => $f) {
                $alamatSah = is_array($f) && is_array($f['sel'] ?? null) && $f['sel'] !== []
                    && array_filter($f['sel'], static fn ($a): bool => ! is_string($a) || Alamat::urai($a) === null) === [];

                if (! $alamatSah || ! is_string($f['kolom'] ?? null) || ! is_string($f['format'] ?? null) || $f['format'] === '') {
                    $m[] = "format_sertifikat.entri[{$i}]: harus {kolom, sel: [alamat], format}";
                } elseif (($e = $varianSah($f, "format_sertifikat.entri[{$i}]")) !== null) {
                    $m[] = $e;
                }
            }
        }

        if (isset($p['xlsm'])) {
            $x = $p['xlsm'];

            if (! is_array($x) || ! is_array($x['lembar'] ?? []) || ! is_array($x['geser'] ?? [])) {
                $m[] = 'xlsm harus {lembar: {peta: asli}, geser: [{lembar, dari, geser, varian?}]}';
            } else {
                foreach (($x['lembar'] ?? []) as $dari => $ke) {
                    if (! in_array($dari, $lembar, true) || ! is_string($ke) || $ke === '') {
                        $m[] = "xlsm.lembar.{$dari}: harus lembar_wajib -> nama lembar .xlsm";
                    }
                }

                foreach (($x['geser'] ?? []) as $i => $g) {
                    if (! is_array($g) || ! in_array($g['lembar'] ?? null, $lembar, true) || ! is_int($g['dari'] ?? null) || $g['dari'] < 1 || ! is_int($g['geser'] ?? null)) {
                        $m[] = "xlsm.geser[{$i}]: harus {lembar (lembar_wajib), dari >= 1, geser bilangan bulat}";
                    } elseif (($e = $varianSah($g, "xlsm.geser[{$i}]")) !== null) {
                        $m[] = $e;
                    }
                }
            }
        }

        foreach (($p['cek'] ?? []) as $i => $c) {
            $label = "cek[{$i}]";

            if (($x = $varianSah($c, $label)) !== null) {
                $m[] = $x;
            }

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
    /**
     * Kode varian dari nama workbook (pola `varian.pola`), atau null bila
     * tidak ada / lebih dari satu pola cocok (nama ambigu tidak ditebak).
     *
     * @param  array<string, string>  $pola
     */
    public static function varianDariPola(array $pola, string $nama): ?string
    {
        $normal = PetaProfilWorkbook::normalkan($nama);
        $cocok = [];

        foreach ($pola as $kode => $regex) {
            if (preg_match('/'.$regex.'/u', $normal) === 1) {
                $cocok[] = (string) $kode;
            }
        }

        return count($cocok) === 1 ? $cocok[0] : null;
    }

    /**
     * Peta ini TIDAK berlaku untuk master bernama `$nama`: petanya ber-varian
     * dengan `varian.lainnya = lewati` dan nama itu tidak cocok varian mana
     * pun. Dipakai untuk profil yang menerima master dari dua keluarga —
     * mis. Flowmeter: master Ultrasonic dipetakan, master Gravimetri (profil
     * sama, tabel lain) belum, dan tidak boleh ditolak gara-gara peta
     * Ultrasonic.
     */
    public function tidakBerlakuUntuk(string $nama): bool
    {
        return isset($this->isi['varian'])
            && ($this->isi['varian']['lainnya'] ?? 'tolak') === 'lewati'
            && self::varianDariPola($this->isi['varian']['pola'], $nama) === null;
    }

    /**
     * Terjemahan alamat untuk master `.xlsm`. Alamat peta adalah alamat
     * EKSPOR CSV (bukti di repo); ekspor itu membuang sebagian baris kosong
     * dan mengganti spasi nama lembar, jadi `.xlsm` asli bisa beda. Bagian
     * `xlsm` peta (diturunkan dari penyelarasan baris CSV<->.xlsm asli,
     * `docs/skrip/verifikasi-peta-xlsm.py`) memuat nama lembar asli dan geser
     * baris per rentang: `{lembar, dari, geser, varian?}` — baris peta >=
     * `dari` digeser `geser`; aturan dengan `dari` terbesar yang berlaku menang.
     *
     * @return array{lembar: array<string, string>, geser: array<string, list<array{0: int, 1: int}>>}
     */
    public function alihXlsm(string $format, ?string $varian): array
    {
        $x = $this->isi['xlsm'] ?? null;

        if ($format !== 'xlsm' || ! is_array($x)) {
            return ['lembar' => [], 'geser' => []];
        }

        $geser = [];

        foreach (self::untukVarian($x['geser'] ?? [], $varian) as $g) {
            $geser[(string) $g['lembar']][] = [(int) $g['dari'], (int) $g['geser']];
        }

        foreach ($geser as $l => $daftar) {
            usort($daftar, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $geser[$l] = $daftar;
        }

        return ['lembar' => array_map('strval', (array) ($x['lembar'] ?? [])), 'geser' => $geser];
    }

    /**
     * Butir (jangkar/ekstrak/cek) yang berlaku untuk `$varian`: butir tanpa
     * kunci `varian` berlaku untuk semua; butir ber-`varian` hanya untuk kode
     * yang disebut — mis. tabel diameter yang cuma ada di master Volumetric
     * Fixed. Varian tak dikenali (null) = hanya butir umum.
     *
     * @param  list<array<string, mixed>>  $butir
     * @return list<array<string, mixed>>
     */
    public static function untukVarian(array $butir, ?string $varian): array
    {
        return array_values(array_filter(
            $butir,
            static fn (array $b): bool => ! isset($b['varian']) || ($varian !== null && in_array($varian, $b['varian'], true)),
        ));
    }

    /**
     * Nama lembar wajib untuk `$varian` (aturan [untukVarian]).
     *
     * @param  list<string|array{nama: string, varian?: list<string>}>  $lembarWajib
     * @return list<string>
     */
    public static function lembarUntukVarian(array $lembarWajib, ?string $varian): array
    {
        $butir = array_map(static fn (string|array $l): array => is_array($l) ? $l : ['nama' => $l], $lembarWajib);

        return array_column(self::untukVarian($butir, $varian), 'nama');
    }

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
