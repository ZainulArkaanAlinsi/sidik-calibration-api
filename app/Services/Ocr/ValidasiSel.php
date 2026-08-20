<?php

namespace App\Services\Ocr;

/**
 * Vonis satu sel: angkanya berapa, boleh dipercaya sampai mana, dan kalau nggak
 * — kenapa.
 *
 * Tiga warna, dan artinya soal SIAPA YANG BERTANGGUNG JAWAB, bukan soal cantik:
 *
 *  - hijau  : keisi otomatis. Mesin berani menanggung angkanya.
 *  - kuning : keisi, tapi mata teknisi wajib lewat. Ini status bawaan buat
 *             tulisan tangan — dan lembar kerja lab ini ditulis tangan.
 *  - merah  : kosong. Teknisi yang ngetik. Mesin ngaku nggak bisa baca.
 *
 * Yang bikin merah dipisah dari yang bikin kuning atas dasar satu pertanyaan:
 * "kalau tebakan ini salah, apa yang kejadian?" Salah baca angka yang lolos jadi
 * hijau mendarat di sertifikat terakreditasi tanpa ada yang lihat. Sel merah
 * yang sebenarnya kebaca cuma bikin teknisi ngetik satu angka. Ongkos dua
 * kesalahan itu nggak sebanding, jadi ambangnya sengaja nggak simetris.
 */
class ValidasiSel
{
    public const HIJAU = 'hijau';

    public const KUNING = 'kuning';

    public const MERAH = 'merah';

    public const KOSONG = 'kosong';

    public function __construct(
        private readonly NormalisasiAngka $normalisasi,
        private readonly NormalisasiJam $jam = new NormalisasiJam,
    ) {}

    /**
     * @param  array<string, mixed>  $template  definisi sel dari `TemplateLembarKerja`
     * @param  array<string, mixed>  $dariHp  hasil baca HP: teks_mentah, confidence_ocr, kotak, ...
     * @param  float  $penaltiKualitas  potongan skor dari mutu foto (berlaku sama buat semua sel)
     * @return array<string, mixed>
     */
    public function periksa(array $template, array $dariHp, float $penaltiKualitas = 0.0): array
    {
        $aturan = $template['aturan'];
        $alasan = [];

        // Sel JAM (baris `Time` lembar Autoklaf) punya jalurnya sendiri: dia
        // nggak punya nilai desimal, jadi seluruh mesin rentang/rasio/kelipatan
        // di bawah nggak berlaku buat dia.
        if (($aturan['tipe'] ?? null) === 'jam') {
            return $this->periksaJam($template, $dariHp, $aturan, $penaltiKualitas);
        }

        $hasil = $this->normalisasi->proses($dariHp['teks_mentah'] ?? null, $aturan);

        $confidenceOcr = $this->angkaConfidence($dariHp['confidence_ocr'] ?? null);
        $confidence = max(0.0, min(
            1.0,
            $confidenceOcr
                - ($hasil['substitusi'] * (float) config('ocr.penalti.substitusi', 0.15))
                - $penaltiKualitas,
        ));

        // ---- Sel kosong -----------------------------------------------------
        if ($hasil['kosong']) {
            return $this->hasil(
                $template,
                $dariHp,
                $hasil,
                null,
                $aturan['boleh_kosong'] ? self::KOSONG : self::MERAH,
                $aturan['boleh_kosong'] ? [] : ['wajib_diisi'],
                // Sel kosong nggak punya skor: nggak ada yang dibaca. Ngasih
                // angka di sini bikin ringkasan "rata-rata confidence" bohong.
                null,
            );
        }

        // ---- Gagal jadi angka sama sekali -----------------------------------
        if (! $hasil['ok']) {
            return $this->hasil($template, $dariHp, $hasil, null, self::MERAH, $hasil['alasan'], $confidence);
        }

        $nilai = (float) $hasil['nilai'];

        // ---- Vonis keras: kalau ini kena, angkanya nggak dipakai ------------
        $keras = $this->periksaKeras($nilai, $aturan, $dariHp);

        if ($keras !== []) {
            return $this->hasil($template, $dariHp, $hasil, $nilai, self::MERAH, $keras, $confidence);
        }

        // ---- Vonis lunak: angkanya dipakai, tapi mentok kuning --------------
        $alasan = $this->periksaLunak($nilai, $hasil, $aturan);

        $status = $this->status($confidence, $alasan, $aturan);

        return $this->hasil($template, $dariHp, $hasil, $nilai, $status, $alasan, $confidence);
    }

    /**
     * Sel jam: `02:00:00`, bukan angka.
     *
     * Penjagaan yang tetap berlaku cuma dua — kotak teksnya harus duduk di
     * dalam selnya (itu penjaga "angka nggak nyebrang sel", dan jam pun nggak
     * boleh nyebrang), dan substitusi karakter menurunkan statusnya. Rentang,
     * rasio nominal, dan kelipatan resolusi nggak punya arti di sini.
     *
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $dariHp
     * @param  array<string, mixed>  $aturan
     * @return array<string, mixed>
     */
    private function periksaJam(
        array $template,
        array $dariHp,
        array $aturan,
        float $penaltiKualitas,
    ): array {
        $hasil = $this->jam->proses($dariHp['teks_mentah'] ?? null, $aturan);

        $confidence = max(0.0, min(
            1.0,
            $this->angkaConfidence($dariHp['confidence_ocr'] ?? null)
                - ($hasil['substitusi'] * (float) config('ocr.penalti.substitusi', 0.15))
                - $penaltiKualitas,
        ));

        if ($hasil['kosong']) {
            return $this->hasil(
                $template,
                $dariHp,
                $hasil,
                null,
                $aturan['boleh_kosong'] ? self::KOSONG : self::MERAH,
                $aturan['boleh_kosong'] ? [] : ['wajib_diisi'],
                null,
            );
        }

        if (! $hasil['ok']) {
            return $this->hasil($template, $dariHp, $hasil, null, self::MERAH, $hasil['alasan'], $confidence);
        }

        if (($dariHp['kotak_teks_di_dalam_sel'] ?? null) === false) {
            return $this->hasil($template, $dariHp, $hasil, null, self::MERAH, ['teks_meluber_dari_sel'], $confidence);
        }

        $alasan = [];

        if ($hasil['substitusi'] > 0) {
            $alasan[] = 'ada_koreksi_karakter';
        }

        if (in_array('pemisah_jam_disisipkan', $hasil['catatan'], true)) {
            // Titik duanya nggak kebaca dan disisipkan mesin. Masuk akal, tapi
            // tetap tebakan — `0200` bisa 02:00 dan bisa juga 20:0x yang satu
            // digitnya hilang.
            $alasan[] = 'pemisah_jam_disisipkan';
        }

        return $this->hasil(
            $template,
            $dariHp,
            $hasil,
            null,
            $this->status($confidence, $alasan, $aturan),
            $alasan,
            $confidence,
        );
    }

    /**
     * Alasan yang bikin angkanya DIBUANG.
     *
     * @param  array<string, mixed>  $aturan
     * @param  array<string, mixed>  $dariHp
     * @return list<string>
     */
    private function periksaKeras(float $nilai, array $aturan, array $dariHp): array
    {
        $alasan = [];

        // Kotak teks hasil OCR harus duduk di dalam poligon selnya. Ini penjaga
        // "angka nggak boleh nyebrang sel" di tingkat piksel — HP yang ngukur,
        // server yang mutusin. `null` = HP versi lama yang belum ngirim; jangan
        // dianggap lulus diam-diam, tapi juga jangan dianggap gagal — dia turun
        // jadi alasan lunak di bawah.
        if (($dariHp['kotak_teks_di_dalam_sel'] ?? null) === false) {
            $alasan[] = 'teks_meluber_dari_sel';
        }

        $min = $aturan['min'] ?? null;
        $maks = $aturan['maks'] ?? null;

        if (($min !== null && $nilai < $min) || ($maks !== null && $nilai > $maks)) {
            $alasan[] = 'di_luar_rentang';
        }

        // Rasio ke nilai nominal — penangkap KOMA KEGESER. Dipisah dari rentang
        // karena gejalanya beda: rentang nangkep "agak meleset", rasio nangkep
        // "meleset sepuluh kali lipat", dan yang kedua itu selalu salah baca,
        // nggak pernah alat rusak.
        $nominal = $aturan['nominal'] ?? null;

        if ($nominal !== null && abs((float) $nominal) > 0.0 && $nilai != 0.0) {
            $rasio = abs($nilai / (float) $nominal);
            $rasioMin = $aturan['rasio_min'] ?? null;
            $rasioMaks = $aturan['rasio_maks'] ?? null;

            if (($rasioMin !== null && $rasio < $rasioMin) || ($rasioMaks !== null && $rasio > $rasioMaks)) {
                $alasan[] = 'magnitudo_meleset';
            }
        }

        return array_values(array_unique($alasan));
    }

    /**
     * Alasan yang bikin sel WAJIB DILIHAT tapi angkanya tetap dipakai.
     *
     * @param  array{catatan: list<string>, substitusi: int}  $hasil
     * @param  array<string, mixed>  $aturan
     * @return list<string>
     */
    private function periksaLunak(float $nilai, array $hasil, array $aturan): array
    {
        $alasan = [];

        if ($hasil['substitusi'] > 0) {
            $alasan[] = 'ada_koreksi_karakter';
        }

        $desimal = $aturan['desimal'] ?? null;

        if ($desimal !== null && $hasil['teks_normal'] !== null) {
            $pecahan = strpos($hasil['teks_normal'], ',');
            $panjang = $pecahan === false ? 0 : strlen(substr($hasil['teks_normal'], $pecahan + 1));

            if ($panjang > $desimal) {
                // Lebih presisi dari yang bisa ditampilin alatnya — biasanya
                // satu digit nyasar dari sel sebelah. Nilainya nggak dibulatin
                // di sini: yang dibulatin diam-diam nggak bisa diperiksa lagi.
                $alasan[] = 'desimal_kebanyakan';
            }
        }

        $resolusi = $aturan['resolusi'] ?? null;

        if ($resolusi !== null && (float) $resolusi > 0.0 && ! $this->kelipatanDari($nilai, (float) $resolusi)) {
            // Layar alatnya nggak mungkin nunjukin angka ini. Sejalan sama
            // `CalibrationValidator::periksaPembacaanMustahil()` — di sana
            // peringatan, di sini kuning. Sama-sama nggak nolak, sama-sama nunjuk.
            $alasan[] = 'bukan_kelipatan_resolusi';
        }

        return array_values(array_unique($alasan));
    }

    /**
     * @param  list<string>  $alasan
     * @param  array<string, mixed>  $aturan
     */
    private function status(float $confidence, array $alasan, array $aturan): string
    {
        if ($confidence < (float) config('ocr.ambang.kuning', 0.60)) {
            return self::MERAH;
        }

        if ($alasan !== []) {
            return self::KUNING;
        }

        if ($confidence < (float) config('ocr.ambang.hijau', 0.85)) {
            return self::KUNING;
        }

        // TULISAN TANGAN NGGAK PERNAH HIJAU.
        //
        // OCR on-device dilatih buat teks cetak. Angka tulisan tangan kebaca
        // jauh lebih meleset, dan yang paling bahaya: skor confidence-nya tetap
        // tinggi waktu salah — model yakin, cuma yakin sama bentuk yang salah.
        // Jadi ambang skor nggak bisa jadi penjaga di sini; yang bisa cuma mata
        // teknisi.
        if (($aturan['tulisan_tangan'] ?? false) === true) {
            return self::KUNING;
        }

        return self::HIJAU;
    }

    /**
     * Skor mentah dari OCR. Nggak ada skor = dianggap nol, bukan dianggap bagus.
     */
    private function angkaConfidence(mixed $nilai): float
    {
        if (! is_numeric($nilai)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $nilai));
    }

    /**
     * Sama persis sama `CalibrationValidator::kelipatanDari()` — ditulis ulang
     * di sini biar jalur OCR nggak narik kelas validator sesi yang butuh model
     * & DB. Toleransinya longgar dikit buat sisa pembulatan float.
     */
    private function kelipatanDari(float $nilai, float $resolusi): bool
    {
        if ($resolusi <= 0.0) {
            return true;
        }

        $bagi = $nilai / $resolusi;
        $bulat = round($bagi);

        return abs($bagi - $bulat) < 1e-6;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $dariHp
     * @param  array<string, mixed>  $normal
     * @param  list<string>  $alasan
     * @return array<string, mixed>
     */
    private function hasil(
        array $template,
        array $dariHp,
        array $normal,
        ?float $nilai,
        string $status,
        array $alasan,
        ?float $confidence,
    ): array {
        return [
            'kunci' => $template['kunci'],
            'tabel_id' => $template['tabel_id'],
            'tahap' => $template['tahap'],
            'grup' => $template['grup'],
            'baris_ke' => $template['baris_ke'],
            'repeat_no' => $template['repeat_no'],
            'field_id' => $template['field_id'],
            'titik_ukur' => $template['titik_ukur'],
            'standard_id' => $template['standard_id'],
            'satuan' => $template['aturan']['satuan'] ?? null,
            'teks_mentah' => $dariHp['teks_mentah'] ?? null,
            'teks_normal' => $normal['teks_normal'] ?? null,
            'nilai' => $nilai,
            'normalisasi' => $normal['catatan'] ?? [],
            'confidence_ocr' => $this->angkaConfidence($dariHp['confidence_ocr'] ?? null),
            'confidence_akhir' => $confidence,
            'status' => $status,
            'alasan' => array_values($alasan),
            'kotak' => $dariHp['kotak'] ?? null,
        ];
    }
}
