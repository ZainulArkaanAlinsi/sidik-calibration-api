<?php

namespace App\Services\Ocr;

/**
 * Teks mentah satu sel → angka, plus catatan APA yang diubah buat sampai ke
 * situ.
 *
 * Aturan pokoknya satu: **nggak boleh ngubah nilai tanpa bukti**. Yang boleh
 * dilakukan di sini cuma dua hal — (1) buang yang jelas bukan bagian dari angka
 * (spasi), (2) ganti karakter yang MUSTAHIL ada di sel angka dengan digit yang
 * bentuknya sama (O→0, S→5). Tiap penggantian dicatat dan nurunin skor, karena
 * penggantian itu tebakan, bukan bacaan.
 *
 * Yang SENGAJA nggak dilakukan: nyisipin/mindahin koma biar angkanya "masuk
 * akal". "133659" di titik 1,33659 itu tanda salah baca — bukan izin naruh koma
 * di tempat yang kelihatannya pas. Yang begitu jadi sel merah, dan teknisi yang
 * ngetik.
 *
 * Titik vs koma memang ambigu ("1.413" bisa 1,413 atau 1413). Yang dilakukan di
 * sini: SEMUA tafsiran yang mungkin disusun, lalu disaring pakai bukti dari
 * template (jumlah desimal & rentang titiknya). Lolos satu → dipakai. Lolos nol
 * atau lebih dari satu → dinyatakan ambigu, biar orang yang mutusin.
 */
class NormalisasiAngka
{
    /**
     * Karakter yang bentuknya mirip digit. Cuma ini yang boleh ditebak.
     *
     * Dipisah huruf besar/kecil karena bentuknya beda: `Q` bulat (mirip 0),
     * `q` berekor ke bawah (mirip 9). Nyampur dua-duanya bikin tebakan yang
     * kelihatan pintar tapi salah separuh waktu.
     */
    private const MIRIP_DIGIT = [
        'O' => '0', 'o' => '0', 'Q' => '0', 'D' => '0',
        'I' => '1', 'l' => '1', 'i' => '1', '|' => '1', '!' => '1', '/' => '1',
        'Z' => '2', 'z' => '2',
        'A' => '4',
        'S' => '5', 's' => '5',
        'G' => '6', 'b' => '6',
        'T' => '7',
        'B' => '8',
        'g' => '9', 'q' => '9',
    ];

    /**
     * @param  array{desimal?: int|null, min?: float|null, maks?: float|null, izinkan_minus?: bool, maks_digit?: int}  $aturan
     * @return array{
     *     ok: bool,
     *     kosong: bool,
     *     nilai: float|null,
     *     teks_normal: string|null,
     *     substitusi: int,
     *     catatan: list<string>,
     *     alasan: list<string>
     * }
     */
    public function proses(?string $teks, array $aturan = []): array
    {
        $catatan = [];
        $alasan = [];

        $bersih = $this->buangSpasi((string) $teks);

        if ($bersih === '') {
            // Sel kosong itu SAH di lembar kerja ini — teknisi sering nggak bisa
            // ngisi satu-dua kolom di lapangan (lihat docblock
            // `LembarKerjaTemplate`). Kosong bukan kegagalan baca.
            return $this->hasil(true, true, null, null, 0, $catatan, $alasan);
        }

        [$bersih, $substitusi, $catatanSubstitusi] = $this->tebakKarakterMiripDigit($bersih);
        $catatan = [...$catatan, ...$catatanSubstitusi];

        $maksSubstitusi = (int) config('ocr.penalti.maks_substitusi', 2);

        if ($substitusi > $maksSubstitusi) {
            // Sel yang butuh tiga tebakan buat jadi angka bukan angka yang
            // kebaca — itu tebakan yang kebetulan berbentuk angka.
            $alasan[] = 'terlalu_banyak_substitusi';

            return $this->hasil(false, false, null, null, $substitusi, $catatan, $alasan);
        }

        $whitelist = (string) config('ocr.angka.whitelist', '0123456789,.-');

        if (! $this->hanyaKarakter($bersih, $whitelist)) {
            $alasan[] = 'karakter_di_luar_whitelist';

            return $this->hasil(false, false, null, null, $substitusi, $catatan, $alasan);
        }

        [$bersih, $negatif, $alasanTanda] = $this->pisahkanTanda($bersih, (bool) ($aturan['izinkan_minus'] ?? false));

        if ($alasanTanda !== null) {
            $alasan[] = $alasanTanda;

            return $this->hasil(false, false, null, null, $substitusi, $catatan, $alasan);
        }

        if (! preg_match('/^[0-9.,]+$/', $bersih)) {
            $alasan[] = 'bentuk_angka_tidak_wajar';

            return $this->hasil(false, false, null, null, $substitusi, $catatan, $alasan);
        }

        $maksDigit = (int) ($aturan['maks_digit'] ?? config('ocr.angka.maks_digit', 9));

        if (strlen(preg_replace('/[^0-9]/', '', $bersih) ?? '') > $maksDigit) {
            // Kepanjangan buat lembar kerja mana pun di lab ini — hampir selalu
            // dua sel yang kebaca nyambung jadi satu.
            $alasan[] = 'digit_kebanyakan';

            return $this->hasil(false, false, null, null, $substitusi, $catatan, $alasan);
        }

        $kandidat = $this->kandidatTafsiran($bersih);

        if ($kandidat === []) {
            $alasan[] = 'pemisah_desimal_tidak_wajar';

            return $this->hasil(false, false, null, null, $substitusi, $catatan, $alasan);
        }

        if (count($kandidat) > 1) {
            $tersaring = $this->saringPakaiBukti($kandidat, $aturan);

            if (count($tersaring) !== 1) {
                // Nol yang cocok = dua-duanya di luar rentang; lebih dari satu =
                // dua-duanya masuk akal. Dua-duanya nggak boleh diputus mesin.
                $alasan[] = 'desimal_ambigu';

                return $this->hasil(false, false, null, null, $substitusi, $catatan, $alasan);
            }

            $catatan[] = 'pemisah ribuan dibuang (dipilih tafsiran yang cocok jumlah desimal & rentang titik)';
            $kandidat = $tersaring;
        }

        $terpilih = $kandidat[array_key_first($kandidat)];
        $nilai = (float) $terpilih['nilai'];

        return $this->hasil(
            true,
            false,
            $negatif ? -$nilai : $nilai,
            ($negatif ? '-' : '').$terpilih['teks'],
            $substitusi,
            $catatan,
            $alasan,
        );
    }

    /**
     * Spasi (termasuk NBSP yang sering nyelip dari OCR) dibuang tanpa catatan —
     * spasi nggak pernah jadi bagian dari angka di lembar kerja.
     */
    private function buangSpasi(string $teks): string
    {
        return trim(preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', '', $teks) ?? '');
    }

    /**
     * @return array{0: string, 1: int, 2: list<string>}
     */
    private function tebakKarakterMiripDigit(string $teks): array
    {
        $hasil = '';
        $jumlah = 0;
        $catatan = [];

        foreach (mb_str_split($teks) as $karakter) {
            if (isset(self::MIRIP_DIGIT[$karakter])) {
                $ganti = self::MIRIP_DIGIT[$karakter];
                $hasil .= $ganti;
                $jumlah++;
                $catatan[] = "{$karakter}→{$ganti}";

                continue;
            }

            $hasil .= $karakter;
        }

        return [$hasil, $jumlah, $catatan];
    }

    private function hanyaKarakter(string $teks, string $whitelist): bool
    {
        $izin = mb_str_split($whitelist);

        foreach (mb_str_split($teks) as $karakter) {
            if (! in_array($karakter, $izin, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Minus cuma sah di depan, dan cuma buat kolom yang emang boleh negatif.
     *
     * @return array{0: string, 1: bool, 2: string|null}
     */
    private function pisahkanTanda(string $teks, bool $izinkanMinus): array
    {
        $negatif = str_starts_with($teks, '-');

        if ($negatif) {
            $teks = substr($teks, 1);
        }

        if (str_contains($teks, '-')) {
            // Minus di tengah = dua sel kebaca nyambung ("4,01-4,02") atau garis
            // tabel kebaca sebagai karakter.
            return ['', false, 'minus_di_tengah'];
        }

        if ($negatif && ! $izinkanMinus) {
            return ['', false, 'minus_tidak_diizinkan'];
        }

        return [$teks, $negatif, null];
    }

    /**
     * Semua tafsiran yang mungkin dari susunan titik/koma.
     *
     * - Satu pemisah, dan sesudahnya BUKAN tepat 3 digit → jelas desimal.
     * - Satu pemisah dengan tepat 3 digit sesudahnya ("1.413") → AMBIGU: bisa
     *   desimal, bisa pemisah ribuan. Dua-duanya dikembalikan, penyaringnya
     *   yang mutusin pakai bukti template.
     * - Dua jenis pemisah ("1.234,56") → yang terakhir desimal, yang lain
     *   ribuan. Ini nggak ambigu.
     *
     * @return list<array{nilai: string, teks: string, desimal: int}>
     */
    private function kandidatTafsiran(string $teks): array
    {
        $adaTitik = str_contains($teks, '.');
        $adaKoma = str_contains($teks, ',');

        if (! $adaTitik && ! $adaKoma) {
            return [$this->kandidat($teks, '')];
        }

        if ($adaTitik && $adaKoma) {
            $pemisahDesimal = strrpos($teks, ',') > strrpos($teks, '.') ? ',' : '.';
            $ribuan = $pemisahDesimal === ',' ? '.' : ',';

            $tanpaRibuan = str_replace($ribuan, '', $teks);

            if (substr_count($tanpaRibuan, $pemisahDesimal) !== 1) {
                return [];
            }

            [$utuh, $pecahan] = explode($pemisahDesimal, $tanpaRibuan);

            return [$this->kandidat($utuh, $pecahan)];
        }

        $pemisah = $adaKoma ? ',' : '.';
        $bagian = explode($pemisah, $teks);

        if (count($bagian) === 2) {
            [$utuh, $pecahan] = $bagian;

            if ($utuh === '' || $pecahan === '') {
                return [];
            }

            $kandidat = [$this->kandidat($utuh, $pecahan)];

            // "1.413" / "1,413": bisa seribu empat ratus tiga belas, bisa satu
            // koma empat satu tiga. Conductivity punya dua-duanya di satu lab.
            if (strlen($pecahan) === 3) {
                $kandidat[] = $this->kandidat($utuh.$pecahan, '');
            }

            return $kandidat;
        }

        // Lebih dari satu pemisah sejenis ("1.234.567") — cuma masuk akal
        // sebagai pemisah ribuan, dan itupun harus rapi 3-3.
        $utuh = array_shift($bagian);

        foreach ($bagian as $blok) {
            if (strlen($blok) !== 3) {
                return [];
            }

            $utuh .= $blok;
        }

        return [$this->kandidat($utuh, '')];
    }

    /**
     * @return array{nilai: string, teks: string, desimal: int}
     */
    private function kandidat(string $utuh, string $pecahan): array
    {
        $utuh = $utuh === '' ? '0' : $utuh;

        return [
            'nilai' => $pecahan === '' ? $utuh : $utuh.'.'.$pecahan,
            // Ditulis balik pakai koma — itu yang dilihat teknisi di kertas,
            // jadi itu yang ditampilin waktu dia bandingin sama crop aslinya.
            'teks' => $pecahan === '' ? $utuh : $utuh.','.$pecahan,
            'desimal' => strlen($pecahan),
        ];
    }

    /**
     * Saring tafsiran ambigu pakai BUKTI dari template: jumlah desimal yang
     * diharapkan kolom itu, dan rentang wajar di sekitar titik ukurnya.
     *
     * Ini satu-satunya tempat nilai boleh "dipilih" — dan pilihannya cuma antara
     * dua bacaan yang sama-sama sah dari teks yang sama, bukan ngarang digit.
     *
     * @param  list<array{nilai: string, teks: string, desimal: int}>  $kandidat
     * @param  array{desimal?: int|null, min?: float|null, maks?: float|null}  $aturan
     * @return list<array{nilai: string, teks: string, desimal: int}>
     */
    private function saringPakaiBukti(array $kandidat, array $aturan): array
    {
        $desimal = $aturan['desimal'] ?? null;
        $min = $aturan['min'] ?? null;
        $maks = $aturan['maks'] ?? null;

        $lolos = array_values(array_filter($kandidat, static function (array $k) use ($desimal, $min, $maks): bool {
            if ($desimal !== null && $k['desimal'] !== $desimal) {
                return false;
            }

            $nilai = (float) $k['nilai'];

            if ($min !== null && $nilai < $min) {
                return false;
            }

            return ! ($maks !== null && $nilai > $maks);
        }));

        if (count($lolos) === 1) {
            return $lolos;
        }

        // Jumlah desimalnya sama-sama nggak cocok (mis. kolom minta 2 desimal,
        // dua-duanya 3) — coba lagi cuma pakai rentang. Rentang bukti yang lebih
        // kuat daripada jumlah desimal: teknisi kadang nulis "4,0" buat 4,00.
        if ($lolos === [] && $desimal !== null) {
            return $this->saringPakaiBukti($kandidat, ['min' => $min, 'maks' => $maks]);
        }

        return $lolos;
    }

    /**
     * @param  list<string>  $catatan
     * @param  list<string>  $alasan
     * @return array{
     *     ok: bool, kosong: bool, nilai: float|null, teks_normal: string|null,
     *     substitusi: int, catatan: list<string>, alasan: list<string>
     * }
     */
    private function hasil(
        bool $ok,
        bool $kosong,
        ?float $nilai,
        ?string $teksNormal,
        int $substitusi,
        array $catatan,
        array $alasan,
    ): array {
        return [
            'ok' => $ok,
            'kosong' => $kosong,
            'nilai' => $nilai,
            'teks_normal' => $teksNormal,
            'substitusi' => $substitusi,
            'catatan' => array_values($catatan),
            'alasan' => array_values($alasan),
        ];
    }
}
