<?php

namespace App\Services\Studio\PetaSel;

use App\Services\Studio\Workbook\GagalBacaWorkbook;
use App\Services\Studio\Workbook\PetaProfilWorkbook;
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
    /**
     * @param  list<array<string, mixed>>  $lembar  keluaran pembaca
     * @param  array<array-key, mixed>  $dasar  isi versi dasar (untuk cek & jalur yang tidak dipetakan)
     * @return array{varian: ?string, isi: array<array-key, mixed>, jalur: list<string>}
     *
     * @throws GagalBacaWorkbook struktur master tidak cocok dengan peta
     */
    public function periksa(PetaSel $peta, string $namaWorkbook, array $lembar, array $dasar): array
    {
        $p = $peta->isi;
        $masalah = [];
        $indeks = $this->indeksLembar($lembar, $p['lembar_wajib'], $masalah);

        foreach ($p['jangkar'] as $j) {
            $this->periksaJangkar($indeks, (string) $j['sel'], (string) $j['teks'], $masalah);
        }

        $varian = null;

        if (isset($p['varian'])) {
            $varian = $this->varian($p['varian']['pola'], $namaWorkbook);

            if ($varian === null) {
                $masalah[] = "varian tidak dikenali dari nama workbook '{$namaWorkbook}' (pola: "
                    .implode(', ', array_map(static fn (string $k, string $r): string => "{$k}={$r}", array_keys($p['varian']['pola']), $p['varian']['pola'])).')';
            }
        }

        $isi = $dasar;
        $jalurDitulis = [];

        // Ekstraksi jalan hanya bila struktur dasarnya utuh: menilai tipe sel
        // di lembar yang hilang atau sesudah jangkar bergeser cuma menambah
        // pesan turunan yang menutupi penyebabnya.
        if ($masalah === []) {
            foreach ($p['ekstrak'] as $e) {
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

            foreach (($p['cek'] ?? []) as $c) {
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
    public function ekstrakLain(PetaSel $peta, array $lembar, array $ekstrak, array $dasarLain): array
    {
        $masalah = [];
        $indeks = $this->indeksLembar($lembar, $peta->isi['lembar_wajib'], $masalah);
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
            if (! isset($per[$nama])) {
                $masalah[] = "lembar wajib '{$nama}' tidak ada (lembar yang ada: ".implode(', ', array_keys($per)).')';

                continue;
            }

            try {
                $sel = json_decode((string) ($per[$nama]['sel_kanonik'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $masalah[] = "lembar '{$nama}' tidak bisa diurai";

                continue;
            }

            $indeks[$nama] = [];

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
                    'angka' => $this->angkaWajib($sel, $alamat, $bulat, $masalah, $bagi),
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

                for ($b = $kr['b1'] + $geser; $b <= $kr['b2'] + $geser; $b++) {
                    $kunci = $this->angkaBoleh($this->sel($indeks, $kr['lembar'], $kr['k1'], $b), Alamat::sel($kr['lembar'], $kr['k1'], $b), $bulat, $masalah);
                    $nilai = $this->angkaBoleh($this->sel($indeks, $nr['lembar'], $nr['k1'], $b), Alamat::sel($nr['lembar'], $nr['k1'], $b), $bulat, $masalah);

                    // Baris ber-kunci kosong dilewati (meniru generator: slot
                    // kosong master terbaca 0 dan BUKAN balok ukur).
                    if ($kunci === null || $nilai === null) {
                        continue;
                    }

                    $k = self::kunciAngka($kunci);

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
                $rentang = array_map(static fn (string $r): array => Alamat::urai($r), $e['kolom']);
                $rk = $rentang[$e['kunci']];
                $daftar = [];

                for ($b = $rk['b1'] + $geser; $b <= $rk['b2'] + $geser; $b++) {
                    $alamatKunci = Alamat::sel($rk['lembar'], $rk['k1'], $b);

                    // Baris ber-kunci kosong dilewati (generator: `if n == "": continue`).
                    if ($this->angkaBoleh($this->sel($indeks, $rk['lembar'], $rk['k1'], $b), $alamatKunci, $bulat, $masalah, $bagi) === null) {
                        continue;
                    }

                    $baris = [];

                    foreach ($rentang as $nama => $r) {
                        $baris[$nama] = $this->angkaWajib($this->sel($indeks, $r['lembar'], $r['k1'], $b), Alamat::sel($r['lembar'], $r['k1'], $b), $bulat, $masalah, $bagi);
                    }

                    $daftar[] = $baris;
                }

                $min = (int) ($e['min_baris'] ?? 1);

                if (count($daftar) < $min) {
                    $masalah[] = "{$e['jalur']}: hanya ".count($daftar)." baris berisi, minimal {$min}";
                }

                return $daftar;
        }

        return null;
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

    /** @param  array<string, string>  $pola */
    private function varian(array $pola, string $nama): ?string
    {
        $normal = PetaProfilWorkbook::normalkan($nama);
        $cocok = [];

        foreach ($pola as $kode => $regex) {
            if (preg_match('/'.$regex.'/u', $normal) === 1) {
                $cocok[] = (string) $kode;
            }
        }

        // Dua pola cocok = nama ambigu; tidak ditebak.
        return count($cocok) === 1 ? $cocok[0] : null;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $indeks
     * @return array<string, mixed>|null
     */
    private function sel(array $indeks, string $lembar, int $kolom, int $baris): ?array
    {
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

        if (trim($s) === '') {
            $masalah[] = "{$alamat} kosong, padahal wajib berisi teks";

            return null;
        }

        return $s;
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

    /** Kunci angka seperti `repr(float)` Python generator: `1.0`, `76.2`, `200.0`. */
    public static function kunciAngka(float $x): string
    {
        return (string) json_encode($x, JSON_PRESERVE_ZERO_FRACTION);
    }
}
