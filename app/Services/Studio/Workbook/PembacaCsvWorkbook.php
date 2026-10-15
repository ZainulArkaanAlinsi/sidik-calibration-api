<?php

namespace App\Services\Studio\Workbook;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

/**
 * Satu folder CSV ekspor workbook master → daftar lembar (Studio S1b).
 *
 * Satu CSV = satu sheet. Sumbernya `Project-PT-Sidik/alat-alat-Pt-Sidik/`
 * (ter-track git, sudah diredaksi c0645f6). Isinya NILAI tersimpan; 13 folder
 * juga membawa rumus dalam bentuk ekspor `nilai [=rumus]` / `nilai [ARRAY:=rumus]`.
 *
 * ## Posisi sel
 *
 * Baris 1 = rekaman CSV pertama, kolom A = kolom pertama — apa adanya, tanpa
 * menebak geseran. Riset D mencatat alamat rujukan di rumus bisa bergeser
 * 1–3 baris dari `.xlsm` asli untuk sebagian folder; peta sel final tetap dari
 * `.xlsm` (impor `--xlsm`).
 *
 * ## Nilai
 *
 *  - BOM UTF-8 di awal berkas dibuang. Teks yang bukan UTF-8 sah dibaca sebagai
 *    Windows-1252 (ekspor Excel lama) supaya tidak menggagalkan kanonik.
 *  - Sel kosong (`""`) tidak disimpan. Spasi tetap teks.
 *  - `s` = teks CSV apa adanya (tanpa bagian `[=rumus]`); `n` = float bila
 *    teksnya angka desimal biasa (round-trip, tidak dibulatkan), selain itu null.
 *  - `#REF!`, `#N/A`, … → tipe `e`; `TRUE`/`FALSE` → `b`.
 *
 * ## Urutan lembar
 *
 * CSV tidak menyimpan urutan sheet. Aturan yang dipakai: lembar yang namanya
 * (ternormal) `INPUT DATA` lebih dulu, sisanya urut nama berkas secara alami
 * tanpa peka huruf (`strnatcasecmp`), seri diputus `strcmp`. Urutan Excel yang
 * sebenarnya baru didapat dari impor `.xlsm`.
 *
 * ## Semua lembar dibaca (K-49-12)
 *
 * DATABASE / SERTIFIKAT / konsep ikut dibaca apa adanya; lembarnya hanya
 * ditandai `tautan_tabel` (`PengecualianLembar::tautan`). CSV di repo sudah
 * diredaksi (c0645f6) — yang dibaca isi berkasnya, bukan tebakan.
 *
 * ## Lebar kolom perkiraan
 *
 * CSV tidak membawa lebar kolom. Supaya grid tidak menyamaratakan kolom,
 * `lebar_kolom` diisi PERKIRAAN: panjang teks terpanjang di kolom itu (huruf,
 * per baris untuk teks bergaris baru) + 1 sebagai ruang tepi sel, dibatasi
 * 4..60 satuan lebar kolom Excel. Kolom tanpa satu sel pun tidak dicantumkan
 * (klien memakai lebar bawaannya). Lembar CSV selalu `lebar_perkiraan = true`
 * supaya klien tahu angka itu bukan dari Excel.
 */
final class PembacaCsvWorkbook
{
    /** Batas lebar kolom perkiraan, satuan lebar kolom Excel. */
    public const LEBAR_MIN = 4.0;

    public const LEBAR_MAKS = 60.0;

    private const POLA_RUMUS = '/^(.*?) ?\[(ARRAY:)?=(.*)\]$/s';

    private const POLA_ANGKA = '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/';

    private const POLA_GALAT = '/^#(?:REF!|N\/A|DIV\/0!|VALUE!|NAME\?|NUM!|NULL!|SPILL!|CALC!|GETTING_DATA)$/';

    public function __construct(private readonly PengecualianLembar $pengecualian) {}

    /**
     * @return array{lembar: list<array<string, mixed>>, ukuran: int}
     */
    public function baca(string $folder): array
    {
        if (! is_dir($folder)) {
            throw new RuntimeException("Folder CSV tidak ada: {$folder}");
        }

        $berkas = glob(rtrim($folder, '/\\').DIRECTORY_SEPARATOR.'*.csv') ?: [];
        $berkas = array_values(array_filter($berkas, is_file(...)));

        if ($berkas === []) {
            throw new RuntimeException("Folder tanpa CSV: {$folder}");
        }

        usort($berkas, self::bandingUrutan(...));

        $lembar = [];
        $ukuran = 0;

        foreach ($berkas as $i => $jalur) {
            $ukuran += (int) filesize($jalur);
            $nama = pathinfo($jalur, PATHINFO_FILENAME);

            $lembar[] = $this->bacaLembar($jalur, $i + 1, $nama);
        }

        return ['lembar' => $lembar, 'ukuran' => $ukuran];
    }

    /** @return array<string, mixed> */
    private function bacaLembar(string $jalur, int $urutan, string $nama): array
    {
        $h = fopen($jalur, 'rb');

        if ($h === false) {
            throw new RuntimeException("CSV tidak bisa dibuka: {$jalur}");
        }

        $sel = [];
        $baris = 0;
        $maksKolom = 0;
        $rumus = 0;
        /** @var array<int, int> $panjang kolom (1-basis) → panjang teks terpanjang */
        $panjang = [];

        try {
            // escape '' = RFC 4180 murni; bawaan "\\" usang di PHP 8.4 dan
            // salah membaca teks yang berakhir garis miring terbalik.
            while (($rekaman = fgetcsv($h, null, ',', '"', '')) !== false) {
                $baris++;

                if ($baris === 1 && isset($rekaman[0]) && str_starts_with($rekaman[0], "\xEF\xBB\xBF")) {
                    $rekaman[0] = substr($rekaman[0], 3);
                }

                $maksKolom = max($maksKolom, count($rekaman));

                foreach ($rekaman as $i => $teks) {
                    if ($teks === null || $teks === '') {
                        continue;
                    }

                    $s = self::sel($baris, $i + 1, $teks);
                    $sel[] = $s;

                    if ($s['r'] !== null) {
                        $rumus++;
                    }

                    $panjang[$i + 1] = max($panjang[$i + 1] ?? 0, self::panjangTeks($s['s']));
                }
            }
        } finally {
            fclose($h);
        }

        $tata = KanonikWorkbook::tataLetakKosong();
        $tata['lebar_kolom'] = self::lebarPerkiraan($panjang);
        $tata['lebar_perkiraan'] = true;

        return [
            'urutan' => $urutan,
            'nama' => $nama,
            'tersembunyi' => 'tidak',
            'dikecualikan' => false,
            'alasan_dikecualikan' => null,
            'tautan_tabel' => $this->pengecualian->tautan($nama),
            'jumlah_baris' => $baris,
            'jumlah_kolom' => $maksKolom,
            'jumlah_sel' => count($sel),
            'jumlah_rumus' => $rumus,
            'tata_letak' => $tata,
            'sel_kanonik' => KanonikWorkbook::sel($sel),
        ];
    }

    /** Panjang (huruf) baris terpanjang dalam teks sel. */
    private static function panjangTeks(string $teks): int
    {
        $maks = 0;

        foreach (preg_split('/\R/u', $teks) ?: [$teks] as $baris) {
            $maks = max($maks, mb_strlen($baris, 'UTF-8'));
        }

        return $maks;
    }

    /**
     * Kolom → lebar perkiraan (huruf kolom → float), urut kolom.
     *
     * @param  array<int, int>  $panjang
     * @return array<string, float>
     */
    private static function lebarPerkiraan(array $panjang): array
    {
        ksort($panjang, SORT_NUMERIC);
        $lebar = [];

        foreach ($panjang as $kolom => $huruf) {
            $lebar[Coordinate::stringFromColumnIndex($kolom)] = min(self::LEBAR_MAKS, max(self::LEBAR_MIN, (float) ($huruf + 1)));
        }

        return $lebar;
    }

    /** @return array<string, mixed> */
    public static function sel(int $baris, int $kolom, string $teks): array
    {
        if (! mb_check_encoding($teks, 'UTF-8')) {
            $teks = mb_convert_encoding($teks, 'UTF-8', 'Windows-1252');
        }

        $rumus = null;
        $nilai = $teks;

        if (preg_match(self::POLA_RUMUS, $teks, $m) === 1) {
            $nilai = $m[1];
            $rumus = $m[2] !== '' ? '{='.$m[3].'}' : '='.$m[3];
        }

        $angka = self::angka($nilai);

        $tipe = match (true) {
            $rumus !== null => 'f',
            preg_match(self::POLA_GALAT, $nilai) === 1 => 'e',
            $nilai === 'TRUE' || $nilai === 'FALSE' => 'b',
            $angka !== null => 'n',
            default => 's',
        };

        return [
            'a' => Coordinate::stringFromColumnIndex($kolom).$baris,
            'b' => $baris,
            'k' => $kolom,
            't' => $tipe,
            'n' => in_array($tipe, ['n', 'f'], true) ? $angka : null,
            's' => $nilai,
            'r' => $rumus,
            'f' => null,
            'g' => null,
        ];
    }

    private static function angka(string $teks): ?float
    {
        if (preg_match(self::POLA_ANGKA, $teks) !== 1) {
            return null;
        }

        $f = (float) $teks;

        return is_finite($f) ? $f : null;
    }

    private static function bandingUrutan(string $a, string $b): int
    {
        $na = pathinfo($a, PATHINFO_FILENAME);
        $nb = pathinfo($b, PATHINFO_FILENAME);
        $ia = PengecualianLembar::normalkan($na) === 'INPUT DATA' ? 0 : 1;
        $ib = PengecualianLembar::normalkan($nb) === 'INPUT DATA' ? 0 : 1;

        if ($ia !== $ib) {
            return $ia <=> $ib;
        }

        return strnatcasecmp($na, $nb) ?: strcmp($na, $nb);
    }
}
