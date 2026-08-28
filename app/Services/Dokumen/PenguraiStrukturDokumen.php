<?php

namespace App\Services\Dokumen;

/**
 * JSON mentah dari penyedia AI -> struktur dokumen yang bentuknya dijamin.
 *
 * ## Kenapa ada lapisan ini, bukan langsung dipakai
 *
 * Yang balik dari model itu JSON yang MIRIP yang diminta, bukan JSON yang
 * dijamin. Kolom bisa hilang, indeks baris bisa lompat, tabel bisa datang
 * sebagai array bersarang di satu jawaban dan sebagai daftar sel bernomor di
 * jawaban berikutnya — model yang sama, prompt yang sama. Kalau bentuk itu
 * dibiarkan mengalir ke UI, tiap kelemahan model berubah jadi bug tampilan
 * yang cuma muncul di sebagian foto.
 *
 * ## Aturan yang DIPAKSA di sini
 *
 * 1. **Sel yang gagal dibaca tetap ada di tempatnya.** Ini yang paling
 *    penting. Kalau sel (baris 2, kolom 3) nggak kebaca, dia jadi sel kosong
 *    berstatus PERLU_REVIEW — BUKAN dihapus. Sel yang dihapus bikin sel
 *    sesudahnya naik satu, dan angka yang mendarat di baris yang salah itu
 *    kesalahan yang paling mahal di lembar kalibrasi: kelihatan wajar,
 *    nggak ada yang merah, dan baru ketahuan waktu sertifikatnya dipakai.
 *
 * 2. **Nggak pernah mengarang.** Nilai yang nggak ada tetap `null`. Nggak ada
 *    default, nggak ada interpolasi, nggak ada "kira-kira".
 *
 * 3. **Yang nggak dikenal dicatat, bukan dibuang diam-diam.** Bagian yang
 *    bentuknya nggak masuk akal masuk ke `warnings`, supaya kegagalan
 *    kelihatan sebagai kegagalan.
 */
class PenguraiStrukturDokumen
{
    /** Sumber nilai: tercetak di formulir vs ditulis teknisi. */
    public const SUMBER_STATIS = 'static_document';

    public const SUMBER_TULISAN = 'handwriting';

    public const SUMBER_TIDAK_DIKETAHUI = 'unknown';

    private const SUMBER_SAH = [
        self::SUMBER_STATIS,
        self::SUMBER_TULISAN,
        self::SUMBER_TIDAK_DIKETAHUI,
    ];

    /** @var list<string> */
    private array $peringatan = [];

    public function __construct(private readonly AmbangKeyakinan $ambang) {}

    /**
     * @param  array<string, mixed>  $mentah
     * @return array<string, mixed>
     */
    public function urai(array $mentah): array
    {
        $this->peringatan = [];

        $dokumen = $this->dokumen($mentah['document'] ?? []);
        $bagian = $this->semuaBagian($mentah['sections'] ?? []);

        // Peringatan dari model IKUT dibawa, digabung dengan temuan pengurai.
        // Model yang bilang "halaman kedua buram" itu informasi yang cuma dia
        // punya, dan membuangnya bikin teknisi menebak kenapa hasilnya jelek.
        foreach ((array) ($mentah['warnings'] ?? []) as $p) {
            if (is_string($p) && $p !== '') {
                $this->peringatan[] = $p;
            }
        }

        return [
            'document' => $dokumen,
            'sections' => $bagian,
            'warnings' => array_values(array_unique($this->peringatan)),
        ];
    }

    /**
     * @param  mixed  $mentah
     * @return array<string, mixed>
     */
    private function dokumen($mentah): array
    {
        $m = is_array($mentah) ? $mentah : [];

        return [
            'title' => $this->teks($m['title'] ?? null),
            'equipment_name' => $this->teks($m['equipment_name'] ?? null),
            'worksheet_code' => $this->teks($m['worksheet_code'] ?? null),
            'revision' => $this->teks($m['revision'] ?? null),
            'confidence' => $this->pecahan($m['confidence'] ?? null),
        ];
    }

    /**
     * @param  mixed  $mentah
     * @return list<array<string, mixed>>
     */
    private function semuaBagian($mentah): array
    {
        if (! is_array($mentah)) {
            $this->peringatan[] = 'Bagian dokumen (`sections`) bentuknya bukan daftar — diabaikan.';

            return [];
        }

        $hasil = [];

        foreach ($mentah as $i => $b) {
            if (! is_array($b)) {
                $this->peringatan[] = "Bagian ke-{$i} bentuknya bukan objek — dilewat.";

                continue;
            }

            $hasil[] = [
                // Nama bagian TIDAK dinormalkan ke daftar tetap: bagian
                // mengikuti dokumen, bukan sebaliknya.
                'name' => $this->teks($b['name'] ?? null),
                'fields' => $this->semuaField($b['fields'] ?? []),
                'tables' => $this->semuaTabel($b['tables'] ?? []),
            ];
        }

        return $hasil;
    }

    /**
     * @param  mixed  $mentah
     * @return list<array<string, mixed>>
     */
    private function semuaField($mentah): array
    {
        if (! is_array($mentah)) {
            return [];
        }

        $hasil = [];

        foreach ($mentah as $f) {
            if (! is_array($f)) {
                continue;
            }

            $nilai = $this->nilai($f['value'] ?? null);
            $keyakinan = $this->pecahan($f['confidence'] ?? null);

            $hasil[] = [
                'label' => $this->teks($f['label'] ?? null),
                'value' => $nilai,
                'type' => $this->teks($f['type'] ?? null) ?? 'text',
                'unit' => $this->teks($f['unit'] ?? null),
                'source' => $this->sumber($f['source'] ?? null),
                'confidence' => $keyakinan,
                'confidence_level' => $this->ambang->kategori($keyakinan),
                'status' => $this->ambang->status($keyakinan, $nilai),
                'page' => $this->bilangan($f['page'] ?? null) ?? 1,
                'bbox' => $this->kotak($f['bbox'] ?? null),
            ];
        }

        return $hasil;
    }

    /**
     * @param  mixed  $mentah
     * @return list<array<string, mixed>>
     */
    private function semuaTabel($mentah): array
    {
        if (! is_array($mentah)) {
            return [];
        }

        $hasil = [];

        foreach ($mentah as $t) {
            if (! is_array($t)) {
                continue;
            }

            $hasil[] = $this->tabel($t);
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function tabel(array $t): array
    {
        $judulKolom = [];

        foreach ((array) ($t['headers'] ?? []) as $h) {
            $judulKolom[] = $this->teks(is_array($h) ? ($h['text'] ?? null) : $h);
        }

        [$peta, $barisMaks, $kolomMaks] = $this->petaSel($t);

        // Ukuran grid: yang terbesar antara jumlah judul kolom dan indeks kolom
        // tertinggi yang benar-benar dilaporkan. Model yang melaporkan sel di
        // kolom 5 tapi cuma menyebut 4 judul itu tetap punya 6 kolom data —
        // memotongnya ke 4 berarti membuang dua kolom angka.
        $lebar = max(count($judulKolom), $kolomMaks + 1);
        $tinggi = $barisMaks + 1;

        $baris = [];

        for ($r = 0; $r < $tinggi; $r++) {
            $isi = [];

            for ($k = 0; $k < $lebar; $k++) {
                // Sel yang nggak dilaporkan model TETAP dibuat, kosong dan
                // berstatus PERLU_REVIEW. Lihat aturan 1 di docblock kelas.
                $isi[] = $peta["{$r}:{$k}"] ?? $this->selKosong($r, $k);
            }

            $baris[] = $isi;
        }

        return [
            'name' => $this->teks($t['name'] ?? null),
            'headers' => $judulKolom,
            'rows' => $baris,
            'confidence' => $this->pecahan($t['confidence'] ?? null),
        ];
    }

    /**
     * Baca sel tabel dari DUA bentuk yang sama-sama dipakai model.
     *
     * Bentuk bersarang (`rows: [[sel, sel], ...]`) dan bentuk bernomor
     * (`cells: [{row, column, ...}]`) dua-duanya diterima. Model yang sama bisa
     * bergantian mengeluarkan keduanya, dan memaksa satu bentuk berarti separuh
     * jawaban yang sah terbaca sebagai tabel kosong.
     *
     * @param  array<string, mixed>  $t
     * @return array{0: array<string, array<string, mixed>>, 1: int, 2: int}
     */
    private function petaSel(array $t): array
    {
        $peta = [];
        $barisMaks = -1;
        $kolomMaks = -1;

        $simpan = function (int $r, int $k, array $sel) use (&$peta, &$barisMaks, &$kolomMaks) {
            if ($r < 0 || $k < 0) {
                $this->peringatan[] = "Sel tabel dengan indeks negatif ({$r},{$k}) — dilewat.";

                return;
            }

            $kunci = "{$r}:{$k}";

            if (isset($peta[$kunci])) {
                $this->peringatan[] = "Sel tabel ganda di ({$r},{$k}) — yang pertama dipakai.";

                return;
            }

            $peta[$kunci] = $sel;
            $barisMaks = max($barisMaks, $r);
            $kolomMaks = max($kolomMaks, $k);
        };

        foreach ((array) ($t['rows'] ?? []) as $r => $isiBaris) {
            if (! is_array($isiBaris)) {
                continue;
            }

            foreach ($isiBaris as $k => $sel) {
                if (! is_array($sel)) {
                    // Sel yang datang sebagai skalar tetap sel — dibungkus,
                    // bukan dibuang, supaya kolomnya nggak bergeser.
                    $sel = ['value' => $sel];
                }

                // Indeks eksplisit di dalam sel menang atas posisinya di array:
                // model yang melewatkan satu sel tapi menomori sisanya dengan
                // benar akan salah tempat kalau posisinya yang dipercaya.
                $rr = $this->bilangan($sel['row'] ?? null) ?? (int) $r;
                $kk = $this->bilangan($sel['column'] ?? null) ?? (int) $k;

                $simpan($rr, $kk, $this->sel($sel, $rr, $kk));
            }
        }

        foreach ((array) ($t['cells'] ?? []) as $sel) {
            if (! is_array($sel)) {
                continue;
            }

            $rr = $this->bilangan($sel['row'] ?? null);
            $kk = $this->bilangan($sel['column'] ?? null);

            if ($rr === null || $kk === null) {
                $this->peringatan[] = 'Sel tabel tanpa nomor baris/kolom — dilewat.';

                continue;
            }

            $simpan($rr, $kk, $this->sel($sel, $rr, $kk));
        }

        return [$peta, $barisMaks, $kolomMaks];
    }

    /**
     * @param  array<string, mixed>  $sel
     * @return array<string, mixed>
     */
    private function sel(array $sel, int $baris, int $kolom): array
    {
        $nilai = $this->nilai($sel['value'] ?? null);
        $keyakinan = $this->pecahan($sel['confidence'] ?? null);

        return [
            'row' => $baris,
            'column' => $kolom,
            'value' => $nilai,
            'confidence' => $keyakinan,
            'confidence_level' => $this->ambang->kategori($keyakinan),
            'status' => $this->ambang->status($keyakinan, $nilai),
            'source' => $this->sumber($sel['source'] ?? null),
            'page' => $this->bilangan($sel['page'] ?? null) ?? 1,
            'bbox' => $this->kotak($sel['bbox'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selKosong(int $baris, int $kolom): array
    {
        return [
            'row' => $baris,
            'column' => $kolom,
            'value' => null,
            'confidence' => null,
            'confidence_level' => AmbangKeyakinan::TIDAK_DIKETAHUI,
            'status' => AmbangKeyakinan::PERLU_REVIEW,
            'source' => self::SUMBER_TIDAK_DIKETAHUI,
            'page' => 1,
            'bbox' => null,
        ];
    }

    private function sumber(mixed $nilai): string
    {
        $s = is_string($nilai) ? strtolower(trim($nilai)) : '';

        return in_array($s, self::SUMBER_SAH, true) ? $s : self::SUMBER_TIDAK_DIKETAHUI;
    }

    /**
     * @return array<string, int|float>|null
     */
    private function kotak(mixed $mentah): ?array
    {
        if (! is_array($mentah)) {
            return null;
        }

        $ambil = function (string $k) use ($mentah) {
            $v = $mentah[$k] ?? null;

            return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) ? (float) $v : null;
        };

        $x = $ambil('x');
        $y = $ambil('y');
        $w = $ambil('width');
        $h = $ambil('height');

        // Kotak separuh itu nggak bisa dipakai buat menyorot apa pun, dan
        // menyimpannya berarti UI harus mengecek tiap sisi sendiri.
        if ($x === null || $y === null || $w === null || $h === null) {
            return null;
        }

        return ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
    }

    private function teks(mixed $nilai): ?string
    {
        if (is_string($nilai)) {
            $t = trim($nilai);

            return $t === '' ? null : $t;
        }

        return is_int($nilai) || is_float($nilai) ? (string) $nilai : null;
    }

    /**
     * Nilai sel dibiarkan APA ADANYA (teks/angka/bool), nggak dipaksa jadi
     * string. Checkbox yang jadi `"true"` dan angka yang jadi `"7.02"` bikin
     * lapisan berikutnya harus menebak ulang tipenya — padahal modelnya sudah
     * memberi tahu.
     */
    private function nilai(mixed $nilai): mixed
    {
        if (is_string($nilai)) {
            $t = trim($nilai);

            return $t === '' ? null : $t;
        }

        return is_int($nilai) || is_float($nilai) || is_bool($nilai) ? $nilai : null;
    }

    private function pecahan(mixed $nilai): ?float
    {
        if (is_int($nilai) || is_float($nilai) || (is_string($nilai) && is_numeric($nilai))) {
            // Di luar 0..1 itu tanda modelnya ngasih persen (94) bukan pecahan.
            $f = (float) $nilai;

            if ($f > 1.0 && $f <= 100.0) {
                $f /= 100.0;
            }

            return max(0.0, min(1.0, $f));
        }

        return null;
    }

    private function bilangan(mixed $nilai): ?int
    {
        return is_int($nilai) || (is_string($nilai) && ctype_digit($nilai)) ? (int) $nilai : null;
    }
}
