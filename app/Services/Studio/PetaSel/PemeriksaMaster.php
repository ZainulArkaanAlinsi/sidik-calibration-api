<?php

namespace App\Services\Studio\PetaSel;

use App\Services\Studio\Workbook\GagalBacaWorkbook;
use JsonException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use Throwable;

/**
 * Validasi struktur + ekstraksi master Studio menurut [PetaSel] (rencana
 * studio-kendali-olah-data fase 1-2, 22 Okt 2026).
 *
 * Masukannya keluaran pembaca workbook (`PembacaCsvWorkbook` /
 * `PembacaXlsmWorkbook`: tiap lembar membawa `nama` + `sel_kanonik`), jadi
 * csv dan xlsm diperiksa dengan aturan yang sama. Semua masalah dikumpulkan
 * lalu dilempar SEKALIGUS sebagai `GagalBacaWorkbook::STRUKTUR_BERUBAH`
 * dengan pesan yang menyebut persis apa yang hilang/bergeser — master yang
 * strukturnya berubah DITOLAK, tidak pernah diekstrak sebagian.
 *
 * Nilai tidak dibulatkan kecuali peta memintanya (`bulat`), dan itu hanya
 * untuk meniru generator JSON yang sudah divalidasi (mis.
 * `gen-tabel-standar-micrometer.py` membulatkan 10 desimal untuk membuang
 * derau float Excel) — supaya master yang sama menghasilkan byte isi yang sama.
 */
final class PemeriksaMaster
{
    public const FORMAT_CSV = 'csv';

    public const FORMAT_XLSM = 'xlsm';

    /**
     * Terjemahan alamat CSV peta -> master yang sedang diperiksa
     * ([PetaSel::alihXlsm]); kosong = CSV apa adanya.
     *
     * @var array{lembar: array<string, string>, geser: array<string, list<array{0: int, 1: int}>>}
     */
    private array $alih = ['lembar' => [], 'geser' => []];

    /**
     * Rentang sel gabungan (merge) per lembar PETA, alamat lembar asli:
     * `[k1, b1, k2, b2]`. Dari `tata_letak.merge` pembaca (CSV: kosong).
     *
     * @var array<string, list<array{0: int, 1: int, 2: int, 3: int}>>
     */
    private array $gabung = [];

    /** Geser baris untuk sel baris `$baris` (alamat peta) di lembar peta `$lembar`. */
    private function geserBaris(string $lembar, int $baris): int
    {
        $geser = 0;

        foreach ($this->alih['geser'][$lembar] ?? [] as [$dari, $g]) {
            if ($baris >= $dari) {
                $geser = $g;
            }
        }

        return $geser;
    }

    /**
     * @param  list<array<string, mixed>>  $lembar  keluaran pembaca
     * @param  array<array-key, mixed>  $dasar  isi versi dasar (untuk cek & jalur yang tidak dipetakan)
     * @return array{varian: ?string, isi: array<array-key, mixed>, jalur: list<string>}
     *
     * @throws GagalBacaWorkbook struktur master tidak cocok dengan peta
     */
    public function periksa(PetaSel $peta, string $namaWorkbook, array $lembar, array $dasar, string $format = self::FORMAT_CSV): array
    {
        $p = $peta->isi;
        $masalah = [];
        $varian = null;

        if (isset($p['varian'])) {
            $varian = $this->varian($p['varian']['pola'], $namaWorkbook);

            if ($varian === null) {
                $masalah[] = "varian tidak dikenali dari nama workbook '{$namaWorkbook}' (pola: "
                    .implode(', ', array_map(static fn (string $k, string $r): string => "{$k}={$r}", array_keys($p['varian']['pola']), $p['varian']['pola'])).')';
            }
        }

        // Alamat peta = alamat ekspor CSV; master .xlsm diterjemahkan lewat
        // `xlsm` peta (nama lembar + geser baris per rentang, per varian).
        $this->alih = $peta->alihXlsm($format, $varian);

        // Butir ber-`varian` (lembar/jangkar/ekstrak/cek yang cuma ada di satu
        // varian master) disaring dulu; varian tak dikenali = butir umum saja.
        $indeks = $this->indeksLembar($lembar, PetaSel::lembarUntukVarian($p['lembar_wajib'], $varian), $masalah);

        foreach (PetaSel::untukVarian($p['jangkar'], $varian) as $j) {
            $this->periksaJangkar($indeks, (string) $j['sel'], (string) $j['teks'], $masalah);
        }

        $isi = $dasar;
        $jalurDitulis = [];

        // Ekstraksi jalan hanya bila struktur dasarnya utuh: menilai tipe sel
        // di lembar yang hilang atau sesudah jangkar bergeser cuma menambah
        // pesan turunan yang menutupi penyebabnya.
        if ($masalah === []) {
            foreach (PetaSel::untukVarian($p['ekstrak'], $varian) as $e) {
                foreach ($this->ulangan($e) as [$i, $geser]) {
                    $jalur = PetaSel::isiJalur((string) $e['jalur'], $varian, $i);

                    try {
                        $nilai = $this->nilai($indeks, $e, $geser, $masalah);

                        if ($nilai === null) {
                            continue;
                        }

                        JalurIsi::tulis($isi, $jalur, $nilai);
                        $jalurDitulis[] = $jalur;
                    } catch (RuntimeException $ex) {
                        $masalah[] = $ex->getMessage();
                    }
                }
            }

            foreach (PetaSel::untukVarian($p['cek'] ?? [], $varian) as $c) {
                foreach ($this->ulangan($c) as [$i, $geser]) {
                    $this->cekSelisih($indeks, $c, $geser, PetaSel::isiJalur((string) $c['banding'], $varian, $i), $isi, $masalah);
                }
            }
        }

        if ($masalah !== []) {
            throw new GagalBacaWorkbook(
                GagalBacaWorkbook::STRUKTUR_BERUBAH,
                "Master ditolak: struktur tidak cocok dengan peta sel '{$peta->kodeProfil}' "
                .'(database/data/peta-sel/'.$peta->kodeProfil.'.json). '
                .implode(' ', array_map(static fn (string $m, int $n): string => ($n + 1).') '.$m.'.', $masalah, array_keys($masalah))),
            );
        }

        return ['varian' => $varian, 'isi' => $isi, 'jalur' => $jalurDitulis];
    }

    /**
     * Ekstraksi `paket_lain[]` peta (data milik PAKET LAIN yang ikut dibawa
     * master ini, mis. balok ukur Micrometer di master Dial Indicator) di atas
     * isi paket lain itu — untuk diadu, TIDAK pernah ditulis ke paket lain.
     * Struktur master sudah divalidasi [periksa] lebih dulu.
     *
     * @param  list<array<string, mixed>>  $lembar
     * @param  list<array<string, mixed>>  $ekstrak
     * @param  array<array-key, mixed>  $dasarLain
     * @return array{isi: array<array-key, mixed>, jalur: list<string>}
     *
     * @throws GagalBacaWorkbook sel tidak sesuai tipe
     */
    public function ekstrakLain(PetaSel $peta, array $lembar, array $ekstrak, array $dasarLain, string $format = self::FORMAT_CSV): array
    {
        $masalah = [];
        $this->alih = $peta->alihXlsm($format, null);
        $indeks = $this->indeksLembar($lembar, PetaSel::lembarUntukVarian($peta->isi['lembar_wajib'], null), $masalah);
        $isi = $dasarLain;
        $jalurDitulis = [];

        foreach ($ekstrak as $e) {
            foreach ($this->ulangan($e) as [$i, $geser]) {
                $jalur = PetaSel::isiJalur((string) $e['jalur'], null, $i);

                try {
                    $nilai = $this->nilai($indeks, $e, $geser, $masalah);

                    if ($nilai !== null) {
                        JalurIsi::tulis($isi, $jalur, $nilai);
                        $jalurDitulis[] = $jalur;
                    }
                } catch (RuntimeException $ex) {
                    $masalah[] = $ex->getMessage();
                }
            }
        }

        if ($masalah !== []) {
            throw new GagalBacaWorkbook(
                GagalBacaWorkbook::STRUKTUR_BERUBAH,
                "Master ditolak: data paket lain di peta sel '{$peta->kodeProfil}' tidak bisa dibaca. ".implode(' ', $masalah),
            );
        }

        return ['isi' => $isi, 'jalur' => $jalurDitulis];
    }

    /**
     * @param  list<array<string, mixed>>  $lembar
     * @param  list<string>  $wajib
     * @param  list<string>  $masalah
     * @return array<string, array<string, array<string, mixed>>> nama lembar => alamat => sel
     */
    private function indeksLembar(array $lembar, array $wajib, array &$masalah): array
    {
        $per = [];

        foreach ($lembar as $l) {
            $per[(string) $l['nama']] = $l;
        }

        $indeks = [];

        foreach ($wajib as $nama) {
            // Indeks tetap berkunci nama lembar PETA (CSV); isinya lembar asli.
            $asli = $this->alih['lembar'][$nama] ?? $nama;

            if (! isset($per[$asli])) {
                $masalah[] = "lembar wajib '{$asli}'".($asli !== $nama ? " (peta: {$nama})" : '').' tidak ada (lembar yang ada: '.implode(', ', array_keys($per)).')';

                continue;
            }

            try {
                $sel = json_decode((string) ($per[$asli]['sel_kanonik'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $masalah[] = "lembar '{$nama}' tidak bisa diurai";

                continue;
            }

            $indeks[$nama] = [];
            $this->gabung[$nama] = [];

            foreach ((array) ($per[$asli]['tata_letak']['merge'] ?? []) as $m) {
                $r = Alamat::urai('x!'.$m);

                if ($r !== null) {
                    $this->gabung[$nama][] = [$r['k1'], $r['b1'], $r['k2'], $r['b2']];
                }
            }

            foreach ((array) $sel as $s) {
                $indeks[$nama][(string) $s['a']] = $s;
            }
        }

        return $indeks;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $indeks
     * @param  list<string>  $masalah
     */
    private function periksaJangkar(array $indeks, string $alamat, string $teks, array &$masalah): void
    {
        $a = Alamat::urai($alamat);

        if (! isset($indeks[$a['lembar']])) {
            return; // sudah dilaporkan sebagai lembar hilang
        }

        $sel = $this->sel($indeks, $a['lembar'], $a['k1'], $a['b1']);
        $ada = self::normalTeks((string) ($sel['s'] ?? ''));

        if ($ada === self::normalTeks($teks)) {
            return;
        }

        $pindah = [];

        foreach ($indeks[$a['lembar']] as $s) {
            if (self::normalTeks((string) ($s['s'] ?? '')) === self::normalTeks($teks)) {
                $pindah[] = $a['lembar'].'!'.$s['a'];
            }
        }

        $masalah[] = "jangkar {$alamat} seharusnya '{$teks}', ditemukan "
            .($ada === '' ? 'sel kosong' : "'{$ada}'")
            .($pindah !== [] ? '; teks itu sekarang ada di '.implode(', ', array_slice($pindah, 0, 3)).' (struktur bergeser)' : '; teks itu tidak ada di lembar '.$a['lembar']);
    }

    /**
     * @param  array<string, mixed>  $e
     * @return list<array{0: ?int, 1: int}> [i, geser baris]
     */
    private function ulangan(array $e): array
    {
        if (! isset($e['ulang'])) {
            return [[null, 0]];
        }

        $hasil = [];

        for ($i = 0; $i < (int) $e['ulang']; $i++) {
            $hasil[] = [$i, $i * (int) $e['langkah_baris']];
        }

        return $hasil;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $indeks
     * @param  array<string, mixed>  $e
     * @param  list<string>  $masalah
     */
    private function nilai(array $indeks, array $e, int $geser, array &$masalah): mixed
    {
        $bulat = isset($e['bulat']) ? (int) $e['bulat'] : null;
        $bagi = isset($e['bagi']) ? (float) $e['bagi'] : null;

        switch ($e['tipe']) {
            case 'teks':
            case 'angka':
            case 'bilangan_bulat':
            case 'tanggal':
                $a = Alamat::urai((string) $e['sel']);
                $alamat = Alamat::sel($a['lembar'], $a['k1'], $a['b1'] + $geser);
                $sel = $this->sel($indeks, $a['lembar'], $a['k1'], $a['b1'] + $geser);

                return match ($e['tipe']) {
                    'teks' => $this->teks($sel, $alamat, $masalah),
                    'angka' => self::transformasi($this->angkaWajib($sel, $alamat, $bulat, $masalah, $bagi), $e),
                    'bilangan_bulat' => $this->bilanganBulat($sel, $alamat, $masalah),
                    'tanggal' => $this->tanggal($sel, $alamat, $masalah),
                };

            case 'daftar_angka':
                $r = Alamat::urai((string) $e['rentang']);
                $daftar = [];

                for ($b = $r['b1'] + $geser; $b <= $r['b2'] + $geser; $b++) {
                    for ($k = $r['k1']; $k <= $r['k2']; $k++) {
                        $x = $this->angkaBoleh($this->sel($indeks, $r['lembar'], $k, $b), Alamat::sel($r['lembar'], $k, $b), $bulat, $masalah, $bagi);

                        if ($x !== null && ! (($e['buang_nol'] ?? false) && $x == 0.0)) {
                            $daftar[] = $x;
                        }
                    }
                }

                return $daftar;

            case 'peta_angka':
                $kr = Alamat::urai((string) $e['kunci']);
                $nr = Alamat::urai((string) $e['nilai']);
                $peta = [];

                $lewatiGalat = (bool) ($e['lewati_galat'] ?? false);
                $baca = function (string $lembar, int $kolom, int $b) use ($indeks, $bulat, $lewatiGalat, &$masalah): ?float {
                    $c = $this->sel($indeks, $lembar, $kolom, $b);

                    // `lewati_galat`: sel teks (`#REF!`) = kosong, seperti `ambil_nilai()` generator Enclosure.
                    if ($lewatiGalat && ! is_int($c['n'] ?? null) && ! is_float($c['n'] ?? null)) {
                        return null;
                    }

                    return $this->angkaBoleh($c, Alamat::sel($lembar, $kolom, $b), $bulat, $masalah);
                };

                for ($b = $kr['b1'] + $geser; $b <= $kr['b2'] + $geser; $b++) {
                    $kunci = $baca($kr['lembar'], $kr['k1'], $b);
                    $nilai = self::transformasi($baca($nr['lembar'], $nr['k1'], $b), $e);

                    // Baris ber-kunci kosong dilewati (meniru generator: slot
                    // kosong master terbaca 0 dan BUKAN balok ukur).
                    if ($kunci === null || $nilai === null) {
                        continue;
                    }

                    $k = ($e['kunci_teks'] ?? false) ? self::kunciTeks($kunci) : self::kunciAngka($kunci);

                    if (array_key_exists($k, $peta)) {
                        $masalah[] = "kunci {$k} ganda di ".Alamat::sel($kr['lembar'], $kr['k1'], $b);

                        continue;
                    }

                    $peta[$k] = $nilai;
                }

                $min = (int) ($e['min_baris'] ?? 1);

                if (count($peta) < $min) {
                    $masalah[] = "{$e['kunci']}: hanya ".count($peta)." baris berisi, minimal {$min}";
                }

                return $peta;

            case 'daftar_objek':
                return $this->daftarObjek($indeks, $e, $geser, $masalah);

            case 'daftar_kunci':
                $daftar = [];

                foreach ((array) $e['rentang'] as $rentang) {
                    $r = Alamat::urai((string) $rentang);

                    for ($b = $r['b1'] + $geser; $b <= $r['b2'] + $geser; $b++) {
                        $n = $this->sel($indeks, $r['lembar'], $r['k1'], $b)['n'] ?? null;

                        if (is_int($n) || is_float($n)) {
                            $daftar[self::kunciTeks((float) $n)] = true;
                        }
                    }
                }

                return array_map('strval', array_keys($daftar));
        }

        return null;
    }

    /**
     * List objek dari baris rentang ([PetaSel] `daftar_objek`). Kolom sejajar
     * per POSISI baris (awal boleh beda, jumlah sama). Baris dilewati bila
     * kolom `lewati_kolom` (bawaan: kunci) kosong — atau, dengan
     * `lewati_jika: bukan_angka`, bukan angka (meniru `angka(..) is None`
     * generator, mis. label "Now date :" di tabel CMC Volumetric). Kolom lain
     * wajib terisi sesuai tipenya. `urut` mengurutkan naik (stabil).
     *
     * @param  array<string, array<string, array<string, mixed>>>  $indeks
     * @param  array<string, mixed>  $e
     * @param  list<string>  $masalah
     * @return list<array<string, mixed>>
     */
    private function daftarObjek(array $indeks, array $e, int $geser, array &$masalah): array
    {
        $kolom = [];

        foreach ($e['kolom'] as $nama => $spek) {
            if (is_array($spek) && array_key_exists('nilai_tetap', $spek)) {
                $kolom[$nama] = ['tetap' => true, 'nilai' => $spek['nilai_tetap']];

                continue;
            }

            $kolom[$nama] = [
                'tetap' => false,
                'r' => Alamat::urai((string) (is_array($spek) ? $spek['rentang'] : $spek)),
                'tipe' => is_array($spek) ? ($spek['tipe'] ?? 'angka') : 'angka',
                // Sel kosong = null (mis. U95 sertifikat standar yang cuma
                // tercetak di sebagian titik), bukan galat.
                'boleh_kosong' => is_array($spek) && ($spek['boleh_kosong'] ?? false),
                // Sel kosong di dalam rentang gabungan (merge) = nilai sel
                // kiri-atasnya, seperti generator (U95 DRUCK07G G10:G17).
                'gabungan' => is_array($spek) && ($spek['gabungan'] ?? false),
                // Sel bukan angka (penanda '-' MPE Dispensette single stroke) =
                // null, meniru `float(s) if isinstance(s, (int, float)) else None`.
                'bukan_angka_null' => is_array($spek) && ($spek['bukan_angka_jadi_null'] ?? false),
            ];
        }

        $lewati = (array) ($e['lewati_kolom'] ?? [$e['kunci']]);
        $bukanAngka = ($e['lewati_jika'] ?? 'kosong') === 'bukan_angka';
        $jumlah = $kolom[$e['kunci']]['r']['b2'] - $kolom[$e['kunci']]['r']['b1'];
        $daftar = [];

        for ($n = 0; $n <= $jumlah; $n++) {
            $sel = static fn (array $k): array => [
                $k['r']['lembar'],
                $k['r']['k1'],
                $k['r']['b1'] + $geser + $n,
            ];

            foreach ($lewati as $l) {
                [$lb, $kk, $bb] = $sel($kolom[$l]);
                $c = $this->sel($indeks, $lb, $kk, $bb);
                $angka = is_int($c['n'] ?? null) || is_float($c['n'] ?? null);

                if ($bukanAngka ? ! $angka : ($c === null || (! $angka && trim((string) ($c['s'] ?? '')) === ''))) {
                    continue 2;
                }
            }

            $baris = [];

            foreach ($kolom as $nama => $k) {
                if ($k['tetap']) {
                    $baris[$nama] = $k['nilai'];

                    continue;
                }

                [$lb, $kk, $bb] = $sel($k);
                $c = $this->sel($indeks, $lb, $kk, $bb);
                $alamat = Alamat::sel($lb, $kk, $bb);

                if ($k['gabungan'] && self::kosong($c)) {
                    $c = $this->selGabungan($indeks, $lb, $kk, $bb) ?? $c;
                }

                if (($k['boleh_kosong'] && self::kosong($c)) || ($k['bukan_angka_null'] && ! is_int($c['n'] ?? null) && ! is_float($c['n'] ?? null))) {
                    $baris[$nama] = null;

                    continue;
                }

                $baris[$nama] = match ($k['tipe']) {
                    'teks' => $this->teks($c, $alamat, $masalah),
                    'bilangan_bulat' => $this->bilanganBulat($c, $alamat, $masalah),
                    'tanggal' => $this->tanggal($c, $alamat, $masalah),
                    default => $this->angkaWajib($c, $alamat, isset($e['bulat']) ? (int) $e['bulat'] : null, $masalah),
                };
            }

            $daftar[] = $baris;
        }

        if (isset($e['urut'])) {
            $u = (string) $e['urut'];
            // usort PHP 8 stabil: baris bernilai sama mempertahankan urutan sel.
            usort($daftar, static fn (array $a, array $b): int => ($a[$u] ?? 0) <=> ($b[$u] ?? 0));
        }

        $min = (int) ($e['min_baris'] ?? 1);

        if (count($daftar) < $min) {
            $masalah[] = "{$e['jalur']}: hanya ".count($daftar)." baris berisi, minimal {$min}";
        }

        return $daftar;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $indeks
     * @param  array<string, mixed>  $c
     * @param  array<array-key, mixed>  $isi
     * @param  list<string>  $masalah
     */
    private function cekSelisih(array $indeks, array $c, int $geser, string $jalur, array $isi, array &$masalah): void
    {
        $a = Alamat::urai((string) $c['sel']);
        $alamat = Alamat::sel($a['lembar'], $a['k1'], $a['b1'] + $geser);
        $x = $this->angkaBoleh($this->sel($indeks, $a['lembar'], $a['k1'], $a['b1'] + $geser), $alamat, null, $masalah);

        if ($x === null) {
            if (! ($c['boleh_kosong'] ?? false)) {
                $masalah[] = "{$alamat} kosong, padahal wajib diadu dengan {$jalur}";
            }

            return;
        }

        try {
            $banding = JalurIsi::ambil($isi, $jalur);
        } catch (RuntimeException $e) {
            $masalah[] = $e->getMessage();

            return;
        }

        if (! is_int($banding) && ! is_float($banding)) {
            $masalah[] = "{$jalur} di dasar bukan angka";

            return;
        }

        if (abs($x - (float) $banding) > (float) $c['toleransi']) {
            $masalah[] = "{$alamat} = {$x} menyimpang dari {$jalur} = {$banding} (toleransi {$c['toleransi']})";
        }
    }

    /** @param  array<string, mixed>|null  $c */
    private static function kosong(?array $c): bool
    {
        return $c === null || (! is_int($c['n'] ?? null) && ! is_float($c['n'] ?? null) && trim((string) ($c['s'] ?? '')) === '');
    }

    /**
     * Sel kiri-atas rentang gabungan yang memuat sel peta ini (alamat peta,
     * geser `xlsm` diterapkan), atau null bila sel itu tidak di dalam
     * gabungan / sudah sel kiri-atasnya.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $indeks
     * @return array<string, mixed>|null
     */
    private function selGabungan(array $indeks, string $lembar, int $kolom, int $baris): ?array
    {
        $baris += $this->geserBaris($lembar, $baris);

        foreach ($this->gabung[$lembar] ?? [] as [$k1, $b1, $k2, $b2]) {
            if ($kolom >= $k1 && $kolom <= $k2 && $baris >= $b1 && $baris <= $b2 && ($kolom !== $k1 || $baris !== $b1)) {
                $alamat = substr(Alamat::sel($lembar, $k1, $b1), strlen($lembar) + 1);

                return $indeks[$lembar][$alamat] ?? null;
            }
        }

        return null;
    }

    /** @param  array<string, string>  $pola */
    private function varian(array $pola, string $nama): ?string
    {
        return PetaSel::varianDariPola($pola, $nama);
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $indeks
     * @return array<string, mixed>|null
     */
    private function sel(array $indeks, string $lembar, int $kolom, int $baris): ?array
    {
        $baris += $this->geserBaris($lembar, $baris);
        $alamat = substr(Alamat::sel($lembar, $kolom, $baris), strlen($lembar) + 1);

        return $indeks[$lembar][$alamat] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $sel
     * @param  list<string>  $masalah
     */
    private function teks(?array $sel, string $alamat, array &$masalah): ?string
    {
        $n = $sel['n'] ?? null;

        // Angka bulat (nomor seri 160006) ditulis tanpa ".0", seperti
        // `str(cell.value)` generator untuk int.
        if (is_int($n) || (is_float($n) && floor($n) === $n && abs($n) < 1e15)) {
            return (string) (int) $n;
        }

        $s = (string) ($sel['s'] ?? '');

        // Galat Excel (`#REF!`, `#N/A`) bukan teks data — identitas standar
        // Proving Ring yang rusak tidak boleh masuk acuan sebagai '#REF!'.
        if (($sel['t'] ?? null) === 'e') {
            $masalah[] = "{$alamat} berisi galat '{$s}', padahal wajib berisi teks";

            return null;
        }

        if (trim($s) === '') {
            $masalah[] = "{$alamat} kosong, padahal wajib berisi teks";

            return null;
        }

        // Spasi tepi dibuang, seperti `.strip()` semua generator
        // (`'SIDIK/134/2024   '` di neraca Volumetric).
        return trim($s);
    }

    /**
     * @param  array<string, mixed>|null  $sel
     * @param  list<string>  $masalah
     */
    private function angkaWajib(?array $sel, string $alamat, ?int $bulat, array &$masalah, ?float $bagi = null): ?float
    {
        $x = $this->angkaBoleh($sel, $alamat, $bulat, $masalah, $bagi);

        if ($x === null) {
            $masalah[] = "{$alamat} kosong, padahal wajib angka";
        }

        return $x;
    }

    /**
     * Angka sel, `null` bila kosong (termasuk rumus yang memulangkan ""). Teks
     * tidak kosong di sel angka = masalah struktur.
     *
     * @param  array<string, mixed>|null  $sel
     * @param  list<string>  $masalah
     */
    private function angkaBoleh(?array $sel, string $alamat, ?int $bulat, array &$masalah, ?float $bagi = null): ?float
    {
        if ($sel === null) {
            return null;
        }

        $n = $sel['n'] ?? null;

        if (is_int($n) || is_float($n)) {
            // Urutan generator: bagi dulu (`float(T5) / 1000`), baru bulat.
            $x = $bagi !== null ? (float) $n / $bagi : (float) $n;

            return $bulat !== null ? round($x, $bulat) : $x;
        }

        $s = trim((string) ($sel['s'] ?? ''));

        if ($s !== '') {
            $masalah[] = "{$alamat} berisi '{$s}', bukan angka";
        }

        return null;
    }

    /**
     * Bilangan bulat (`int(float(sel))` generator) — sel pecahan ditolak,
     * bukan dipotong diam-diam.
     *
     * @param  array<string, mixed>|null  $sel
     * @param  list<string>  $masalah
     */
    private function bilanganBulat(?array $sel, string $alamat, array &$masalah): ?int
    {
        $x = $this->angkaWajib($sel, $alamat, null, $masalah);

        if ($x === null) {
            return null;
        }

        if (floor($x) !== $x || abs($x) >= 1e15) {
            $masalah[] = "{$alamat} = {$x}, bukan bilangan bulat";

            return null;
        }

        return (int) $x;
    }

    /**
     * @param  array<string, mixed>|null  $sel
     * @param  list<string>  $masalah
     */
    private function tanggal(?array $sel, string $alamat, array &$masalah): ?string
    {
        $s = trim((string) ($sel['s'] ?? ''));

        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ]00:00(?::00(?:\.0+)?)?)?$/', $s, $m) === 1) {
            return $m[1];
        }

        $n = $sel['n'] ?? null;

        if ((is_int($n) || is_float($n)) && $n > 0 && floor((float) $n) === (float) $n) {
            try {
                return Date::excelToDateTimeObject((float) $n)->format('Y-m-d');
            } catch (Throwable) {
                // jatuh ke pesan di bawah
            }
        }

        $masalah[] = "{$alamat} = '".($s === '' ? 'kosong' : $s)."', bukan tanggal";

        return null;
    }

    private static function normalTeks(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * `nol_di_bawah` lalu `angka_penting` (generator Enclosure: derau |x| < 1e-9
     * = 0, lalu `float(f"{x:.10g}")`). Tanpa opsi itu nilai tidak disentuh.
     *
     * @param  array<string, mixed>  $e
     */
    private static function transformasi(?float $x, array $e): ?float
    {
        if ($x === null) {
            return null;
        }

        if (isset($e['nol_di_bawah']) && abs($x) < (float) $e['nol_di_bawah']) {
            return 0.0;
        }

        return isset($e['angka_penting']) ? (float) sprintf('%.'.(int) $e['angka_penting'].'g', $x) : $x;
    }

    /** Kunci teks suhu seperti `kunci_suhu()` generator: `25`, `-100`, `0.5` (bukan `25.0`). */
    public static function kunciTeks(float $x): string
    {
        return floor($x) === $x && abs($x) < 1e15 ? (string) (int) $x : (string) json_encode($x);
    }

    /** Kunci angka seperti `repr(float)` Python generator: `1.0`, `76.2`, `200.0`. */
    public static function kunciAngka(float $x): string
    {
        return (string) json_encode($x, JSON_PRESERVE_ZERO_FRACTION);
    }
}
