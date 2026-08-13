<?php

namespace App\Services\Ocr;

/**
 * Pusat keputusan satu kali pindai: dari kiriman HP → tabel bervonis warna.
 *
 * Urutannya sengaja dari yang paling murah & paling menentukan: kalau template
 * atau geometrinya nggak bisa dipercaya, NGGAK ADA satu sel pun yang dipetakan.
 * Ini prinsip yang dipegang di seluruh file: **gagal itu menolak seluruh lembar,
 * bukan mengisi sebagian**. Lembar yang keisi separuh di posisi yang salah jauh
 * lebih mahal daripada lembar yang nggak keisi sama sekali — yang kedua
 * kelihatan, yang pertama ketahuan waktu sertifikatnya udah kekirim ke
 * pelanggan.
 *
 * Yang NGGAK dilakukan di sini: nyimpen `raw_measurements`. Hasil pindai itu
 * usulan, bukan data. Data lahir waktu teknisi nekan simpan di alur lama
 * (`POST/PUT /calibrations`).
 */
class PemrosesScanLembarKerja
{
    /** Scan yang seluruh isinya kepakai. */
    public const OK = 'ok';

    /** Kebaca, tapi ada yang harus dilihat/dibetulin teknisi. */
    public const PERLU_REVIEW = 'perlu_review';

    public const DITOLAK_KUALITAS = 'ditolak_kualitas';

    public const TEMPLATE_TIDAK_DIKENALI = 'template_tidak_dikenali';

    public const GEOMETRI_MERAGUKAN = 'geometri_meragukan';

    public const MAPPING_GAGAL = 'mapping_gagal';

    public function __construct(
        private readonly TemplateLembarKerja $template,
        private readonly ValidasiSel $validasi,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  kiriman HP (sudah lolos validasi bentuk di FormRequest)
     * @param  array<string, mixed>  $template  definisi dari `TemplateLembarKerja`
     * @return array{
     *     ok: bool,
     *     status: string,
     *     alasan_gagal: string|null,
     *     pesan: string|null,
     *     sel: list<array<string, mixed>>,
     *     tabel: list<array<string, mixed>>,
     *     ringkasan: array<string, int>
     * }
     */
    public function proses(array $payload, array $template): array
    {
        // ---- 1. Template & versi -------------------------------------------
        $penolakan = $this->periksaTemplate($payload, $template);

        if ($penolakan !== null) {
            return $penolakan;
        }

        // ---- 2. Mutu foto ---------------------------------------------------
        $mutu = $this->periksaKualitas($payload['kualitas'] ?? []);

        if ($mutu['tolak']) {
            return $this->gagal(self::DITOLAK_KUALITAS, $mutu['pesan']);
        }

        // ---- 3. Geometri ----------------------------------------------------
        $geometri = $this->periksaGeometri($payload['geometri'] ?? []);

        if ($geometri !== null) {
            return $this->gagal(self::GEOMETRI_MERAGUKAN, $geometri);
        }

        // ---- 4. Sel jangkar (label baris tercetak) --------------------------
        $jangkar = $this->periksaJangkar($payload['sel_jangkar'] ?? []);

        if ($jangkar !== null) {
            return $this->gagal(self::MAPPING_GAGAL, $jangkar);
        }

        // ---- 5. Pemetaan kunci ---------------------------------------------
        $peta = $this->petakan($payload['sel'] ?? [], $template);

        if ($peta['pesan'] !== null) {
            return $this->gagal(self::MAPPING_GAGAL, $peta['pesan']);
        }

        // ---- 6. Vonis per sel ----------------------------------------------
        $sel = [];

        foreach ($template['sel'] as $kunci => $definisi) {
            $sel[$kunci] = $this->validasi->periksa($definisi, $peta['sel'][$kunci], $mutu['penalti']);
        }

        // ---- 7. Kewajaran antar-Repeat -------------------------------------
        $sel = $this->periksaAntarRepeat($sel, $template);

        $ringkasan = $this->ringkas($sel);

        return [
            'ok' => true,
            'status' => $ringkasan['merah'] > 0 || $ringkasan['kuning'] > 0
                ? self::PERLU_REVIEW
                : self::OK,
            'alasan_gagal' => null,
            'pesan' => null,
            'sel' => array_values($sel),
            'tabel' => $this->susunPerTabel($sel, $template),
            'ringkasan' => $ringkasan,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>|null
     */
    private function periksaTemplate(array $payload, array $template): ?array
    {
        if (($payload['qr']['terbaca'] ?? false) !== true) {
            // QR itu satu-satunya cara sistem tau lembar mana yang lagi difoto.
            // Tanpa dia, satu-satunya alternatif adalah menebak dari bentuk
            // tabel — dan dua lembar alat berbeda di lab ini bentuk tabelnya
            // mirip banget.
            return $this->gagal(
                self::TEMPLATE_TIDAK_DIKENALI,
                'QR lembar kerja nggak kebaca. Pastikan kode QR di pojok lembar ikut kefoto.',
            );
        }

        if (($payload['template_id'] ?? null) !== $template['template_id']) {
            return $this->gagal(
                self::TEMPLATE_TIDAK_DIKENALI,
                'Lembar yang difoto bukan lembar kerja alat ini.',
            );
        }

        if ((int) ($payload['template_versi'] ?? 0) !== (int) $template['versi']) {
            // Beda versi = kolomnya bisa geser. Ini justru saat penjagaan paling
            // penting: formulir Rev.4 & Rev.5 mirip di mata orang, beda di mata
            // koordinat.
            return $this->gagal(
                self::TEMPLATE_TIDAK_DIKENALI,
                'Versi lembar kerja nggak cocok (foto: v'.((int) ($payload['template_versi'] ?? 0))
                    .', sistem: v'.((int) $template['versi']).'). Pakai formulir versi terbaru, '
                    .'atau perbarui aplikasi.',
            );
        }

        if (($template['siap_pindai'] ?? false) !== true) {
            return $this->gagal(
                self::TEMPLATE_TIDAK_DIKENALI,
                'Template lembar kerja ini belum siap dipindai ('
                    .(string) ($template['alasan_belum_siap'] ?? 'belum disetel').'). Isi manual dulu.',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $kualitas
     * @return array{tolak: bool, pesan: string|null, penalti: float}
     */
    private function periksaKualitas(array $kualitas): array
    {
        $blurMin = (float) config('ocr.kualitas.blur_min', 90);
        $blur = isset($kualitas['blur_laplacian']) ? (float) $kualitas['blur_laplacian'] : null;

        if ($blur !== null && $blur < $blurMin) {
            return $this->mutu(true, 'Fotonya buram. Tahan HP lebih diam, lalu foto ulang.');
        }

        $terang = isset($kualitas['kecerahan_rata']) ? (float) $kualitas['kecerahan_rata'] : null;

        if ($terang !== null && $terang < (float) config('ocr.kualitas.kecerahan_min', 60)) {
            return $this->mutu(true, 'Terlalu gelap. Cari cahaya yang lebih terang, lalu foto ulang.');
        }

        if ($terang !== null && $terang > (float) config('ocr.kualitas.kecerahan_maks', 225)) {
            return $this->mutu(true, 'Terlalu terang sampai angkanya pudar. Kurangi cahaya langsung, lalu foto ulang.');
        }

        $glare = isset($kualitas['rasio_glare']) ? (float) $kualitas['rasio_glare'] : null;

        if ($glare !== null && $glare > (float) config('ocr.kualitas.glare_maks', 0.05)) {
            return $this->mutu(true, 'Ada pantulan cahaya di lembar. Geser sedikit posisinya, lalu foto ulang.');
        }

        $miring = isset($kualitas['sudut_kemiringan_deg']) ? abs((float) $kualitas['sudut_kemiringan_deg']) : null;

        if ($miring !== null && $miring > (float) config('ocr.kualitas.kemiringan_maks_deg', 8)) {
            return $this->mutu(true, 'Lembarnya kefoto miring. Sejajarkan HP dengan lembar, lalu foto ulang.');
        }

        $pxSel = isset($kualitas['px_per_sel_tinggi']) ? (int) $kualitas['px_per_sel_tinggi'] : null;

        if ($pxSel !== null && $pxSel < (int) config('ocr.kualitas.px_per_sel_min', 24)) {
            return $this->mutu(true, 'Fotonya kejauhan — angkanya kekecilan buat dibaca. Dekatkan HP, lalu foto ulang.');
        }

        // Lolos, tapi pas-pasan (dalam 25% di atas ambang blur): semua sel turun
        // skornya. Foto begini yang paling sering ngasih hijau palsu — kebaca,
        // yakin, dan salah.
        $pasPasan = $blur !== null && $blur < $blurMin * 1.25;

        return [
            'tolak' => false,
            'pesan' => null,
            'penalti' => $pasPasan ? (float) config('ocr.penalti.kualitas_pas_pasan', 0.10) : 0.0,
        ];
    }

    /**
     * @return array{tolak: bool, pesan: string|null, penalti: float}
     */
    private function mutu(bool $tolak, ?string $pesan): array
    {
        return ['tolak' => $tolak, 'pesan' => $pesan, 'penalti' => 0.0];
    }

    /**
     * Homography yang meleset dikit = seluruh grid geser satu baris tanpa gejala
     * lain. Ini penjagaan yang paling nggak kelihatan gunanya sampai dia nyelametin.
     *
     * @param  array<string, mixed>  $geometri
     */
    private function periksaGeometri(array $geometri): ?string
    {
        $marker = is_array($geometri['marker'] ?? null) ? count($geometri['marker']) : 0;

        if ($marker < (int) config('ocr.geometri.marker_min', 3)) {
            return 'Penanda sudut lembar nggak semua kebaca ('.$marker.' dari 4). '
                .'Pastikan keempat sudut lembar masuk frame.';
        }

        $residual = isset($geometri['residual_reproyeksi_px'])
            ? (float) $geometri['residual_reproyeksi_px']
            : null;

        if ($residual === null) {
            return 'Aplikasi nggak ngirim ukuran ketepatan penyelarasan. Perbarui aplikasi.';
        }

        if ($residual > (float) config('ocr.geometri.residual_maks_px', 2.0)) {
            return 'Penyelarasan lembar nggak cukup presisi (meleset '.round($residual, 2).' px). Foto ulang.';
        }

        if (($geometri['grid_tersnap'] ?? null) !== true) {
            // Garis tabel nggak ketemu = kotak sel cuma nebak dari koordinat
            // template, tanpa dikoreksi ke garis aslinya. Skala cetak yang beda
            // tipis aja udah cukup bikin geser.
            return 'Garis tabel nggak kedeteksi. Pastikan seluruh tabel masuk frame & nggak ketutupan.';
        }

        return null;
    }

    /**
     * Sel jangkar = label baris yang TERCETAK di formulir (R1..R5) dan ikut
     * dibaca OCR.
     *
     * Ini penangkal paling ampuh buat kesalahan "geser satu baris": kalau grid
     * kegeser, label yang kebaca di posisi baris ke-2 bakal `R3`. Semua
     * penjagaan lain ngukur geometri; yang ini ngebaca isinya.
     *
     * @param  list<array<string, mixed>>  $jangkar
     */
    private function periksaJangkar(array $jangkar): ?string
    {
        if ($jangkar === []) {
            return 'Label baris (R1..R5) nggak kebaca sama sekali — posisi baris nggak bisa dipastikan.';
        }

        foreach ($jangkar as $j) {
            if (($j['cocok'] ?? false) !== true) {
                return 'Label baris di lembar nggak cocok sama urutan yang diharapkan (baris '
                    .((int) ($j['repeat_no'] ?? 0)).' kebaca "'.((string) ($j['teks_mentah'] ?? '')).'"). '
                    .'Ini tanda barisnya kegeser — hasilnya nggak dipakai.';
            }
        }

        return null;
    }

    /**
     * Kiriman HP → sel template, LEWAT KUNCI, bukan urutan.
     *
     * Tiga hal yang bikin gagal total, dan tiga-tiganya jenis bug yang kalau
     * diterima diam-diam bakal muncul sebagai angka di baris yang salah:
     *  1. kunci nggak dikenal — APK megang bentuk lembar yang beda;
     *  2. kunci dobel — satu sel dua nilai, nggak ada dasar milih;
     *  3. jumlah sel nggak sama persis — ada sel yang nggak pernah dibaca, dan
     *     sel yang hilang tanpa suara itu yang paling susah ketahuan.
     *
     * @param  list<array<string, mixed>>  $selHp
     * @param  array<string, mixed>  $template
     * @return array{sel: array<string, array<string, mixed>>, pesan: string|null}
     */
    private function petakan(array $selHp, array $template): array
    {
        $peta = [];

        foreach ($selHp as $s) {
            $kunci = $this->template->kunci(
                (string) ($s['tabel_id'] ?? ''),
                (int) ($s['baris_ke'] ?? 0),
                (int) ($s['repeat_no'] ?? 0),
                (string) ($s['field_id'] ?? ''),
            );

            if (! isset($template['sel'][$kunci])) {
                return ['sel' => [], 'pesan' => "Sel `{$kunci}` nggak ada di lembar kerja ini."];
            }

            if (isset($peta[$kunci])) {
                return ['sel' => [], 'pesan' => "Sel `{$kunci}` kekirim dua kali."];
            }

            $definisi = $template['sel'][$kunci];

            // Bukti pembanding: titik ukur & standar yang dipegang HP harus sama
            // persis sama yang dipegang server. Beda = APK-nya megang lembar
            // versi lain walau nomor versinya kebetulan sama.
            if (isset($s['titik_ukur'])
                && abs((float) $s['titik_ukur'] - (float) $definisi['titik_ukur']) > 1e-9) {
                return [
                    'sel' => [],
                    'pesan' => "Titik ukur sel `{$kunci}` beda antara aplikasi & sistem "
                        .'— jangan dipakai, perbarui aplikasi.',
                ];
            }

            if (array_key_exists('standard_id', $s)
                && $s['standard_id'] !== null
                && $definisi['standard_id'] !== null
                && (int) $s['standard_id'] !== (int) $definisi['standard_id']) {
                return [
                    'sel' => [],
                    'pesan' => "Standar acuan sel `{$kunci}` beda antara aplikasi & sistem.",
                ];
            }

            $peta[$kunci] = $s;
        }

        $kurang = array_diff(array_keys($template['sel']), array_keys($peta));

        if ($kurang !== []) {
            return [
                'sel' => [],
                'pesan' => 'Ada '.count($kurang).' sel lembar kerja yang nggak ikut kekirim. '
                    .'Pastikan seluruh tabel masuk frame, lalu foto ulang.',
            ];
        }

        return ['sel' => $peta, 'pesan' => null];
    }

    /**
     * Nilai satu Repeat yang jauh dari saudara-saudaranya ditandai KUNING, bukan
     * dibuang.
     *
     * Kenapa nggak merah: sebaran lebar bisa jadi alat pelanggannya yang emang
     * nggak stabil — dan itu justru temuan kalibrasi yang berharga. Mesin nggak
     * punya dasar buat mutusin mana anomali alat & mana salah baca; yang bisa dia
     * lakukan cuma nunjuk.
     *
     * @param  array<string, array<string, mixed>>  $sel
     * @param  array<string, mixed>  $template
     * @return array<string, array<string, mixed>>
     */
    private function periksaAntarRepeat(array $sel, array $template): array
    {
        $grup = [];

        foreach ($sel as $kunci => $s) {
            if ($s['nilai'] === null || $s['status'] === ValidasiSel::MERAH) {
                continue;
            }

            $grup[$s['tabel_id'].'|'.$s['baris_ke'].'|'.$s['field_id']][$kunci] = (float) $s['nilai'];
        }

        $minSampel = (int) config('ocr.antar_repeat.min_sampel', 3);

        foreach ($grup as $idGrup => $nilai) {
            if (count($nilai) < $minSampel) {
                // Sengaja dicatat, bukan dilewat diam-diam: "nggak ketangkep"
                // beda arti dari "nggak diuji", dan bedanya penting waktu orang
                // ngukur seberapa jauh pipeline ini bisa dipercaya.
                foreach (array_keys($nilai) as $kunci) {
                    $sel[$kunci]['alasan'][] = 'sebar_repeat_tidak_diuji';
                }

                continue;
            }

            $contoh = $sel[array_key_first($nilai)];
            $ambang = $this->ambangSebar($contoh, $template, array_values($nilai));
            $median = $this->median(array_values($nilai));

            foreach ($nilai as $kunci => $n) {
                if (abs($n - $median) <= $ambang) {
                    continue;
                }

                $sel[$kunci]['alasan'][] = 'jauh_dari_repeat_lain';
                $sel[$kunci]['status'] = $sel[$kunci]['status'] === ValidasiSel::HIJAU
                    ? ValidasiSel::KUNING
                    : $sel[$kunci]['status'];
            }

            unset($idGrup);
        }

        return $sel;
    }

    /**
     * @param  array<string, mixed>  $contoh
     * @param  array<string, mixed>  $template
     * @param  list<float>  $nilai
     */
    private function ambangSebar(array $contoh, array $template, array $nilai): float
    {
        if ($contoh['field_id'] === 'suhu') {
            // Suhu larutan punya ambangnya sendiri: dia nggak ngikut resolusi
            // alat, dan sebarannya dibatasi fisika ruangan, bukan mutu alat.
            return (float) config('ocr.suhu.delta_wajar', 2.0);
        }

        $aturan = $template['sel'][$contoh['kunci']]['aturan'] ?? [];
        $resolusi = (float) ($aturan['resolusi'] ?? 0.0);

        return max(
            $resolusi * (float) config('ocr.antar_repeat.k_resolusi', 3.0),
            $this->mad($nilai) * (float) config('ocr.antar_repeat.k_mad', 4.0),
        );
    }

    /** @param list<float> $nilai */
    private function median(array $nilai): float
    {
        sort($nilai);
        $n = count($nilai);
        $tengah = intdiv($n, 2);

        return $n % 2 === 1
            ? $nilai[$tengah]
            : ($nilai[$tengah - 1] + $nilai[$tengah]) / 2;
    }

    /**
     * Median Absolute Deviation — ukuran sebar yang nggak ikut ketarik satu
     * nilai nyasar, beda dari standar deviasi. Penting di sini: yang lagi dicari
     * justru nilai nyasarnya.
     *
     * @param  list<float>  $nilai
     */
    private function mad(array $nilai): float
    {
        $median = $this->median($nilai);
        $selisih = array_map(static fn (float $n): float => abs($n - $median), $nilai);

        return $this->median(array_values($selisih));
    }

    /**
     * @param  array<string, array<string, mixed>>  $sel
     * @return array<string, int>
     */
    private function ringkas(array $sel): array
    {
        $ringkasan = ['total_sel' => count($sel), 'hijau' => 0, 'kuning' => 0, 'merah' => 0, 'kosong' => 0];

        foreach ($sel as $s) {
            $ringkasan[$s['status']]++;
        }

        return $ringkasan;
    }

    /**
     * Bentuk yang dipakai layar review: baris lembar kerja, bukan daftar sel.
     *
     * @param  array<string, array<string, mixed>>  $sel
     * @param  array<string, mixed>  $template
     * @return list<array<string, mixed>>
     */
    private function susunPerTabel(array $sel, array $template): array
    {
        $hasil = [];

        foreach ($template['tabel'] as $tabel) {
            $baris = [];

            foreach ($tabel['baris'] as $b) {
                $pengulangan = [];

                foreach ($tabel['pengulangan'] as $repeat) {
                    $kolom = [];

                    foreach ($tabel['kolom'] as $k) {
                        $kunci = $this->template->kunci(
                            $tabel['tabel_id'],
                            (int) $b['baris_ke'],
                            (int) $repeat,
                            (string) $k['field_id'],
                        );

                        $kolom[(string) $k['field_id']] = $sel[$kunci] ?? null;
                    }

                    $pengulangan[] = ['repeat_no' => (int) $repeat, 'kolom' => $kolom];
                }

                $baris[] = [...$b, 'pengulangan' => $pengulangan];
            }

            $hasil[] = [
                'tabel_id' => $tabel['tabel_id'],
                'tahap' => $tabel['tahap'],
                'grup' => $tabel['grup'],
                'judul' => $tabel['judul'],
                'baris' => $baris,
            ];
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>
     */
    private function gagal(string $status, ?string $pesan): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'alasan_gagal' => $status,
            'pesan' => $pesan,
            'sel' => [],
            'tabel' => [],
            'ringkasan' => ['total_sel' => 0, 'hijau' => 0, 'kuning' => 0, 'merah' => 0, 'kosong' => 0],
        ];
    }
}
