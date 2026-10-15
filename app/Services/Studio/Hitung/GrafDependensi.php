<?php

namespace App\Services\Studio\Hitung;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Graf dependensi rumus untuk hitung "hanya sel kotor" (Studio tahap C).
 *
 * Rujukan tiap rumus dibaca dari token RPN parser PhpSpreadsheet — parser yang
 * sama dengan yang mengevaluasi, jadi tafsiran rujukan tidak bisa berbeda:
 *  - `Cell/Column/Row Reference` tunggal = satu sel; dua rujukan diikuti
 *    operator `:` = rentang (kolom/baris utuh jadi rentang 1..1048576 / A..XFD);
 *  - `Defined Name` → nilai nama diurai dengan cara yang sama (rekursif,
 *    nama lokal lembar dulu, lalu global); nama `#REF!` / tautan luar tidak
 *    punya pendahulu di workbook ini;
 *  - rujukan workbook luar (`[n]Sheet!A1`) tidak punya pendahulu — nilainya
 *    selalu nilai cache Excel;
 *  - INDIRECT/OFFSET, `:` yang operannya bukan rujukan sederhana, atau nama
 *    yang tidak terurai → rumus DINAMIS: dianggap kotor pada setiap edit;
 *  - NOW/TODAY → VOLATIL: selalu dihitung (waktu acuan).
 *
 * Penanda "kotor" mengalir maju: sel yang diedit → rumus yang merujuknya →
 * rumus yang merujuk rumus itu, dst. Rumus yang tidak kotor tidak dievaluasi;
 * mesin memakai nilai cache Excel-nya. Urutan evaluasi = urutan topologis
 * global (pendahulu dulu) supaya rekursi PhpSpreadsheet dangkal dan galat
 * berhenti di sel akarnya.
 */
final class GrafDependensi
{
    private const BARIS_MAKS = 1048576;

    private const KOLOM_MAKS = 16384;

    /**
     * @param  list<string>  $rumus  kunci sel rumus, urutan topologis
     * @param  array<string, list<string>>  $tunggal  kunci sel → rumus yang merujuknya langsung
     * @param  list<array{0: string, 1: int, 2: int, 3: int, 4: int, 5: list<string>}>  $rentang  [judul, b1, k1, b2, k2, rumus]
     * @param  array<string, list<string>>  $turunan  rumus → rumus yang merujuknya
     * @param  list<string>  $dinamis
     * @param  list<string>  $volatil
     * @param  array<string, list<string>>  $terlarang  rumus → fungsi di luar daftar putih
     */
    public function __construct(
        public readonly array $rumus,
        public readonly array $tunggal,
        public readonly array $rentang,
        public readonly array $turunan,
        public readonly array $dinamis,
        public readonly array $volatil,
        public readonly array $terlarang,
    ) {}

    public static function bangun(ModelHitung $model): self
    {
        $namaPeta = [];

        foreach ($model->nama as $n) {
            $namaPeta[strtoupper($n['nama'])][] = $n;
        }

        $judulAda = [];

        foreach ($model->lembar as $l) {
            $judulAda[strtoupper($l['judul'])] = $l['judul'];
        }

        $rumus = [];
        $tunggal = [];
        $rentangPeta = [];
        $dinamis = [];
        $volatil = [];
        $terlarang = [];
        $rumusPerLembar = [];

        foreach ($model->lembar as $l) {
            foreach ($l['sel'] as $alamat => $sel) {
                if ($sel[0] !== DataType::TYPE_FORMULA) {
                    continue;
                }

                $kunci = ModelHitung::kunci($l['judul'], (string) $alamat);
                $rumus[] = $kunci;
                [$kol, $bar] = Coordinate::coordinateFromString((string) $alamat);
                $rumusPerLembar[$l['judul']][] = [(int) $bar, Coordinate::columnIndexFromString($kol), $kunci];

                $baca = self::rujukan((string) $sel[1], $l['judul'], $namaPeta, $judulAda, 0);

                foreach ($baca['tunggal'] as $k) {
                    $tunggal[$k][] = $kunci;
                }

                foreach ($baca['rentang'] as $r) {
                    $rentangPeta[implode('|', $r)][] = $kunci;
                }

                if ($baca['dinamis']) {
                    $dinamis[] = $kunci;
                }

                if ($baca['volatil']) {
                    $volatil[] = $kunci;
                }

                if ($baca['terlarang'] !== []) {
                    $terlarang[$kunci] = $baca['terlarang'];
                }
            }
        }

        $rentang = [];

        foreach ($rentangPeta as $id => $pengguna) {
            [$judul, $b1, $k1, $b2, $k2] = explode('|', $id);
            $rentang[] = [$judul, (int) $b1, (int) $k1, (int) $b2, (int) $k2, array_values(array_unique($pengguna))];
        }

        // rumus → rumus yang merujuknya (lewat sel tunggal atau rentang).
        $turunan = [];

        foreach ($tunggal as $k => $pengguna) {
            $turunan[$k] = $pengguna;
        }

        foreach ($rentang as [$judul, $b1, $k1, $b2, $k2, $pengguna]) {
            foreach ($rumusPerLembar[$judul] ?? [] as [$b, $k, $kunci]) {
                if ($b >= $b1 && $b <= $b2 && $k >= $k1 && $k <= $k2) {
                    foreach ($pengguna as $p) {
                        $turunan[$kunci][] = $p;
                    }
                }
            }
        }

        $adaRumus = array_fill_keys($rumus, true);

        foreach ($turunan as $k => $daftar) {
            if (! isset($adaRumus[$k])) {
                unset($turunan[$k]);

                continue;
            }

            $turunan[$k] = array_values(array_unique($daftar));
        }

        return new self(self::topologis($rumus, $turunan), $tunggal, $rentang, $turunan, $dinamis, $volatil, $terlarang);
    }

    /**
     * Rumus yang harus dihitung ulang, urutan topologis.
     *
     * @param  list<string>  $selDiedit  kunci sel yang diedit (konstan maupun rumus)
     * @param  list<string>  $rumusDiedit  kunci sel yang diedit MENJADI rumus
     * @return list<string>
     */
    public function kotor(array $selDiedit, array $rumusDiedit = []): array
    {
        $kotor = [];
        $antre = [];

        $tandai = static function (string $k) use (&$kotor, &$antre): void {
            if (! isset($kotor[$k])) {
                $kotor[$k] = true;
                $antre[] = $k;
            }
        };

        foreach ($selDiedit as $k) {
            foreach ($this->pengguna($k) as $p) {
                $tandai($p);
            }
        }

        foreach ($rumusDiedit as $k) {
            $tandai($k);
        }

        if ($selDiedit !== []) {
            foreach ($this->dinamis as $k) {
                $tandai($k);
            }
        }

        foreach ($this->volatil as $k) {
            $tandai($k);
        }

        while ($antre !== []) {
            $k = array_pop($antre);

            foreach ($this->turunan[$k] ?? [] as $p) {
                $tandai($p);
            }
        }

        // Sel yang diedit menjadi konstan bukan rumus lagi — tidak dihitung.
        $konstanBaru = array_diff($selDiedit, $rumusDiedit);

        foreach ($konstanBaru as $k) {
            unset($kotor[$k]);
        }

        $hasil = [];

        foreach ($this->rumus as $k) {
            if (isset($kotor[$k])) {
                $hasil[] = $k;
                unset($kotor[$k]);
            }
        }

        // Rumus baru dari edit (belum ada di graf) di ekor.
        foreach (array_keys($kotor) as $k) {
            $hasil[] = $k;
        }

        return $hasil;
    }

    /**
     * Penutupan maju dari sekumpulan rumus (untuk "bergantung NOW" dan
     * "dihitung dari nilai lama").
     *
     * @param  list<string>  $awal
     * @return array<string, true>
     */
    public function penutupan(array $awal): array
    {
        $hasil = [];
        $antre = $awal;

        while ($antre !== []) {
            $k = array_pop($antre);

            foreach ($this->turunan[$k] ?? [] as $p) {
                if (! isset($hasil[$p])) {
                    $hasil[$p] = true;
                    $antre[] = $p;
                }
            }
        }

        return $hasil;
    }

    /** @return list<string> rumus yang merujuk sel `$kunci` langsung */
    public function pengguna(string $kunci): array
    {
        $hasil = $this->tunggal[$kunci] ?? [];
        [$judul, $alamat] = ModelHitung::urai($kunci);

        try {
            [$kol, $bar] = Coordinate::coordinateFromString($alamat);
        } catch (Throwable) {
            return $hasil;
        }

        $b = (int) $bar;
        $k = Coordinate::columnIndexFromString($kol);

        foreach ($this->rentang as [$j, $b1, $k1, $b2, $k2, $pengguna]) {
            if ($j === $judul && $b >= $b1 && $b <= $b2 && $k >= $k1 && $k <= $k2) {
                array_push($hasil, ...$pengguna);
            }
        }

        return array_values(array_unique($hasil));
    }

    /** @return array<string, mixed> */
    public function keLarik(): array
    {
        return [
            'rumus' => $this->rumus, 'tunggal' => $this->tunggal, 'rentang' => $this->rentang, 'turunan' => $this->turunan,
            'dinamis' => $this->dinamis, 'volatil' => $this->volatil, 'terlarang' => $this->terlarang,
        ];
    }

    /** @param  array<string, mixed>  $l */
    public static function dariLarik(array $l): self
    {
        return new self($l['rumus'], $l['tunggal'], $l['rentang'], $l['turunan'], $l['dinamis'], $l['volatil'], $l['terlarang']);
    }

    /**
     * Rujukan satu rumus (atau nilai nama terdefinisi).
     *
     * @param  array<string, list<array{nama: string, lembar: string|null, nilai: string, lokal: bool, cakupan: string|null}>>  $namaPeta
     * @param  array<string, string>  $judulAda  JUDUL BESAR → judul asli
     * @return array{tunggal: list<string>, rentang: list<array{0: string, 1: int, 2: int, 3: int, 4: int}>, dinamis: bool, volatil: bool, terlarang: list<string>}
     */
    private static function rujukan(string $rumus, string $judul, array $namaPeta, array $judulAda, int $kedalaman): array
    {
        $hasil = ['tunggal' => [], 'rentang' => [], 'dinamis' => false, 'volatil' => false, 'terlarang' => []];
        $rumus = str_starts_with($rumus, '{=') && str_ends_with($rumus, '}') ? substr($rumus, 1, -1) : $rumus;
        $token = DaftarPutihFungsi::token(str_starts_with($rumus, '=') ? $rumus : '='.$rumus);

        if ($token === null) {
            $hasil['dinamis'] = true;

            return $hasil;
        }

        $jenisRujukan = ['Cell Reference', 'Column Reference', 'Row Reference'];
        $terpakai = [];
        $n = count($token);

        for ($i = 0; $i < $n; $i++) {
            $t = $token[$i];
            $jenis = $t['type'] ?? '';
            $nilai = is_scalar($t['value'] ?? null) ? (string) $t['value'] : '';

            if ($jenis === 'Function') {
                $fungsi = DaftarPutihFungsi::namaDariToken($nilai);

                if (! DaftarPutihFungsi::boleh($fungsi)) {
                    $hasil['terlarang'][] = $fungsi;
                }

                if (in_array($fungsi, DaftarPutihFungsi::DINAMIS, true)) {
                    $hasil['dinamis'] = true;
                }

                if (in_array($fungsi, DaftarPutihFungsi::VOLATIL, true)) {
                    $hasil['volatil'] = true;
                }

                continue;
            }

            if ($jenis === 'Binary Operator' && $nilai === ':') {
                $a = $token[$i - 2] ?? null;
                $b = $token[$i - 1] ?? null;

                if ($a !== null && $b !== null && in_array($a['type'] ?? '', $jenisRujukan, true) && in_array($b['type'] ?? '', $jenisRujukan, true)) {
                    $terpakai[$i - 2] = $terpakai[$i - 1] = true;
                    $ra = self::sel((string) $a['value'], $judul, $judulAda);
                    $rb = self::sel((string) $b['value'], $ra[0] ?? $judul, $judulAda);

                    if ($ra !== null && $rb !== null && $ra[0] !== null) {
                        $hasil['rentang'][] = [$ra[0], min($ra[1], $rb[1]), min($ra[2], $rb[2]), max($ra[1], $rb[1]), max($ra[2], $rb[2])];
                    }
                } else {
                    // Rentang yang ujungnya hasil fungsi (INDEX(..):A5) — tidak terbaca statis.
                    $hasil['dinamis'] = true;
                }

                continue;
            }

            if ($jenis === 'Defined Name') {
                $baca = self::nama($nilai, $judul, $namaPeta, $judulAda, $kedalaman);

                if ($baca === null) {
                    $hasil['dinamis'] = true;
                } else {
                    array_push($hasil['tunggal'], ...$baca['tunggal']);
                    array_push($hasil['rentang'], ...$baca['rentang']);
                    $hasil['dinamis'] = $hasil['dinamis'] || $baca['dinamis'];
                    $hasil['volatil'] = $hasil['volatil'] || $baca['volatil'];
                    array_push($hasil['terlarang'], ...$baca['terlarang']);
                }
            }
        }

        foreach ($token as $i => $t) {
            if (isset($terpakai[$i]) || ! in_array($t['type'] ?? '', $jenisRujukan, true)) {
                continue;
            }

            $r = self::sel((string) $t['value'], $judul, $judulAda);

            if ($r === null || $r[0] === null) {
                continue;
            }

            if (($t['type'] ?? '') === 'Cell Reference') {
                $hasil['tunggal'][] = ModelHitung::kunci($r[0], Coordinate::stringFromColumnIndex($r[2]).$r[1]);
            } else {
                // Rujukan kolom/baris tunggal tanpa pasangan `:` (jarang): anggap dinamis.
                $hasil['dinamis'] = true;
            }
        }

        $hasil['tunggal'] = array_values(array_unique($hasil['tunggal']));
        $hasil['terlarang'] = array_values(array_unique($hasil['terlarang']));

        return $hasil;
    }

    /**
     * @return array{tunggal: list<string>, rentang: list<array{0: string, 1: int, 2: int, 3: int, 4: int}>, dinamis: bool, volatil: bool, terlarang: list<string>}|null
     */
    private static function nama(string $token, string $judul, array $namaPeta, array $judulAda, int $kedalaman): ?array
    {
        if ($kedalaman > 5) {
            return null;
        }

        $lembarNama = $judul;
        $nama = $token;

        if (str_contains($token, '!')) {
            [$lembarNama, $nama] = Worksheet::extractSheetTitle($token, true, true);
            $lembarNama = (string) $lembarNama;
        }

        $calon = $namaPeta[strtoupper($nama)] ?? [];
        $pilih = null;

        foreach ($calon as $c) {
            if ($c['lokal'] && strcasecmp((string) $c['cakupan'], $lembarNama) === 0) {
                $pilih = $c;
                break;
            }
        }

        if ($pilih === null) {
            foreach ($calon as $c) {
                if (! $c['lokal']) {
                    $pilih = $c;
                    break;
                }
            }
        }

        if ($pilih === null) {
            // Bukan nama terdaftar (mis. TRUE/FALSE terbaca sebagai nama) → tanpa pendahulu.
            return ['tunggal' => [], 'rentang' => [], 'dinamis' => false, 'volatil' => false, 'terlarang' => []];
        }

        $nilai = ltrim($pilih['nilai'], '=');

        if ($nilai === '' || str_contains($nilai, '#REF!') || preg_match('/^\[\d+\]|!\s*$|^\'?\[\d+\]/', $nilai) === 1 || preg_match('/\[\d+\]/', $nilai) === 1) {
            // Nama rusak atau tautan workbook luar: tidak ada pendahulu di sini.
            return ['tunggal' => [], 'rentang' => [], 'dinamis' => false, 'volatil' => false, 'terlarang' => []];
        }

        return self::rujukan('='.$nilai, $pilih['lembar'] ?? $judul, $namaPeta, $judulAda, $kedalaman + 1);
    }

    /**
     * Rujukan token → [judul, baris, kolom]. Judul null = workbook luar /
     * lembar tak dikenal (tanpa pendahulu).
     *
     * @return array{0: string|null, 1: int, 2: int}|null
     */
    private static function sel(string $ref, string $judul, array $judulAda): ?array
    {
        $ref = trim($ref);

        if (str_contains($ref, '!')) {
            [$lembar, $alamat] = Worksheet::extractSheetTitle($ref, true, true);
            $lembar = (string) $lembar;

            if (str_starts_with($lembar, '[') || ! isset($judulAda[strtoupper($lembar)])) {
                return [null, 0, 0];
            }

            $judul = $judulAda[strtoupper($lembar)];
        } else {
            $alamat = $ref;
        }

        $alamat = str_replace('$', '', (string) $alamat);

        if (preg_match('/^([A-Z]{1,3})(\d{1,7})$/i', $alamat, $m) !== 1) {
            return null;
        }

        $kol = min(Coordinate::columnIndexFromString(strtoupper($m[1])), self::KOLOM_MAKS);

        return [$judul, min((int) $m[2], self::BARIS_MAKS), $kol];
    }

    /**
     * Kahn: pendahulu dulu. Sisa siklus ditempel di ekor dengan urutan asal.
     *
     * @param  list<string>  $rumus
     * @param  array<string, list<string>>  $turunan
     * @return list<string>
     */
    private static function topologis(array $rumus, array $turunan): array
    {
        $masuk = array_fill_keys($rumus, 0);

        foreach ($turunan as $daftar) {
            foreach ($daftar as $p) {
                if (isset($masuk[$p])) {
                    $masuk[$p]++;
                }
            }
        }

        $antre = [];

        foreach ($rumus as $k) {
            if ($masuk[$k] === 0) {
                $antre[] = $k;
            }
        }

        $hasil = [];
        $kepala = 0;

        while ($kepala < count($antre)) {
            $k = $antre[$kepala++];
            $hasil[] = $k;

            foreach ($turunan[$k] ?? [] as $p) {
                if (isset($masuk[$p]) && --$masuk[$p] === 0) {
                    $antre[] = $p;
                }
            }
        }

        if (count($hasil) < count($rumus)) {
            $sudah = array_fill_keys($hasil, true);

            foreach ($rumus as $k) {
                if (! isset($sudah[$k])) {
                    $hasil[] = $k;
                }
            }
        }

        return $hasil;
    }
}
