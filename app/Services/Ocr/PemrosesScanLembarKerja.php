<?php

namespace App\Services\Ocr;

use App\Services\CalibrationValidator;

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

    /**
     * Alasan yang ditempel waktu butir HIJAU diturunkan ke KUNING karena
     * formulir asli masih mode uji (keputusan pemilik K1, 9 Okt 2026).
     */
    public const ALASAN_MODE_UJI = 'template_belum_terverifikasi';

    /** Nama kuadran halaman untuk pesan sebaran jangkar, urut indeks [kuadran]. */
    private const NAMA_KUADRAN = ['kiri-atas', 'kanan-atas', 'kiri-bawah', 'kanan-bawah'];

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
        // Formulir ASLI lab (tanpa marker & QR) punya pengenal & geometrinya
        // sendiri. Dicabangkan di depan supaya tidak satu baris pun jalur cetak
        // di bawah ini ikut berubah.
        if (($template['kertas'] ?? 'cetak') === 'asli') {
            return $this->prosesAsli($payload, $template);
        }

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

    // =====================================================================
    // FORMULIR ASLI (`kertas: "asli"`) — PANDUAN-OCR-LEMBAR-KERJA.md §3–§4
    // =====================================================================

    /**
     * Pindai formulir SIDIK-FM-CAL asli lab: tanpa QR, tanpa marker.
     *
     * Prinsipnya sama dengan jalur cetak — gagal menolak SELURUH lembar — dan
     * tahap yang bisa dipakai ulang dipakai ulang apa adanya (mutu foto,
     * pemetaan kunci sel, `ValidasiSel`, antar-Repeat). Yang diganti cuma
     * pengenal lembar (kode FM tercetak, bukan QR) dan bukti geometri (jangkar
     * TEKS cetak, bukan marker). Ambang lama tidak ada yang diturunkan.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $template  dari `FormulirAsli::untukKode()`
     * @return array<string, mixed>
     */
    private function prosesAsli(array $payload, array $template): array
    {
        // Mode uji (K1): template belum lulus >=20 foto, tapi pindai boleh
        // dicoba — dengan tidak satu butir pun hijau. Tidak pernah menyalakan
        // `siap_pindai`; cuma membuka pintu yang `siap_pindai: false` tutup.
        $modeUji = ($template['mode_uji'] ?? false) === true;

        // ---- 1. Formulir & revisi (kode FM tercetak menggantikan QR) --------
        $penolakan = $this->periksaFormulirAsli($payload, $template, $modeUji);

        if ($penolakan !== null) {
            return $this->gagalAsli(self::TEMPLATE_TIDAK_DIKENALI, $penolakan, $modeUji);
        }

        // ---- 2. Mutu foto — sama persis dengan jalur cetak ------------------
        $mutu = $this->periksaKualitas($payload['kualitas'] ?? []);

        if ($mutu['tolak']) {
            return $this->gagalAsli(self::DITOLAK_KUALITAS, $mutu['pesan'], $modeUji);
        }

        // ---- 3. Geometri dari jangkar teks ----------------------------------
        $geometri = $this->periksaJangkarTeks(
            $payload['geometri'] ?? [],
            $template['geometri']['jangkar_teks'] ?? [],
        );

        if ($geometri['pesan'] !== null) {
            return $this->gagalAsli(self::GEOMETRI_MERAGUKAN, $geometri['pesan'], $modeUji);
        }

        // ---- 4. Label baris R1..R5 — SENGAJA DILEWATI -----------------------
        // `periksaJangkar()` menuntut label baris yang dicetak di lembar
        // CETAK kita. Formulir asli lab tidak mencetak label per baris yang
        // bisa dibaca sebagai pembanding, jadi tuntutan itu mustahil dipenuhi.
        // Penjaga "geser satu baris"-nya pindah ke tahap 3: >=8 tulisan cetak
        // yang TEKSNYA cocok, menyebar di 4 kuadran, residual <= ambang (pt).
        // Satu baris tabel pH ~22 pt; geser sebaris jauh di atas ambang itu.

        // ---- 5. Pemetaan: kotak template, sel, isian, centang ---------------
        $kotakHilang = $this->kotakTemplateHilang($template);

        if ($kotakHilang !== null) {
            return $this->gagalAsli(self::MAPPING_GAGAL, $kotakHilang, $modeUji);
        }

        $peta = $this->petakan($payload['sel'] ?? [], $template);

        if ($peta['pesan'] !== null) {
            return $this->gagalAsli(self::MAPPING_GAGAL, $peta['pesan'], $modeUji);
        }

        $petaIsian = $this->petakanIsian($payload['isian'] ?? [], $template['isian'] ?? []);

        if ($petaIsian['pesan'] !== null) {
            return $this->gagalAsli(self::MAPPING_GAGAL, $petaIsian['pesan'], $modeUji);
        }

        $petaCentang = $this->petakanCentang($payload['centang'] ?? [], $template['centang'] ?? []);

        if ($petaCentang['pesan'] !== null) {
            return $this->gagalAsli(self::MAPPING_GAGAL, $petaCentang['pesan'], $modeUji);
        }

        // ---- 6. Vonis per sel -----------------------------------------------
        $sel = [];

        foreach ($template['sel'] as $kunci => $definisi) {
            // Formulir asli SELALU diisi tangan — tidak ada angka cetak di
            // selnya. Sakelar global `ocr.tulisan_tangan.aktif` tidak berlaku
            // di sini (§6 butir 4).
            $definisi['aturan']['tulisan_tangan'] = true;

            $sel[$kunci] = [
                ...$this->validasi->periksa($definisi, $peta['sel'][$kunci], $mutu['penalti']),
                // Kotak dari GEOMETRI formulir (ternormal 0..1), bukan kiriman
                // HP — dipakai `crop` untuk memotong citra warp halaman utuh.
                'kotak' => $definisi['kotak'],
            ];
        }

        // ---- 7. Kewajaran antar-Repeat --------------------------------------
        $sel = $this->periksaAntarRepeat($sel, $template);

        // ---- 8. Isian kondisi lingkungan & centang --------------------------
        $isian = $this->vonisIsian($petaIsian['isian'], $template['isian'] ?? [], $mutu['penalti']);
        $centang = $this->vonisCentang($petaCentang['centang'], $template['centang'] ?? []);

        // ---- 9. Mode uji: tidak ada yang hijau ------------------------------
        if ($modeUji) {
            $sel = array_map(fn (array $b): array => $this->batasiModeUji($b), $sel);
            $isian = array_map(fn (array $b): array => $this->batasiModeUji($b), $isian);
            $centang = array_map(fn (array $b): array => $this->batasiModeUjiCentang($b), $centang);
        }

        $ringkasan = $this->ringkas([...array_values($sel), ...$isian, ...$centang]);

        return [
            'ok' => true,
            // Mode uji tidak pernah `ok`: pindai yang belum terverifikasi
            // selalu lewat mata teknisi, termasuk lembar yang kosong total.
            'status' => $modeUji || $ringkasan['merah'] > 0 || $ringkasan['kuning'] > 0
                ? self::PERLU_REVIEW
                : self::OK,
            'alasan_gagal' => null,
            'pesan' => null,
            'sel' => array_values($sel),
            'tabel' => $this->susunPerTabel($sel, $template),
            'ringkasan' => $ringkasan,
            'kertas' => 'asli',
            'mode_uji' => $modeUji,
            'isian' => $isian,
            'centang' => $centang,
            'geometri' => $geometri['ringkasan'],
        ];
    }

    /**
     * Pengganti `periksaTemplate()` untuk formulir asli.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $template
     */
    private function periksaFormulirAsli(array $payload, array $template, bool $modeUji): ?string
    {
        $kode = (string) ($template['kode_dokumen'] ?? '');
        $terbaca = (string) ($payload['kode_dokumen_terbaca'] ?? '');

        // Kode FM di kaki formulir = satu-satunya cara sistem tahu formulir
        // mana yang difoto (pengganti QR). Normalisasinya cuma spasi & huruf
        // besar — bukan pencocokan longgar: 0509 dan 0510 harus tetap beda.
        if ($kode === '' || $this->kodeDokumen($terbaca) !== $this->kodeDokumen($kode)) {
            return 'Formulir yang difoto bukan '.($kode === '' ? 'formulir alat ini' : $kode)
                .' (kebaca: "'.mb_substr($terbaca, 0, 40).'"). Foto formulir lembar kerja alat ini, atau isi manual.';
        }

        if (($payload['template_id'] ?? null) !== $template['template_id']) {
            return 'Lembar yang difoto bukan lembar kerja alat ini.';
        }

        if (! isset($template['revisi']) || ! is_numeric($template['revisi'])) {
            return 'Revisi formulir ini belum tercatat di sistem — pindai belum bisa dipakai. Isi manual dulu.';
        }

        $revisi = (int) $template['revisi'];
        $versi = (int) ($payload['template_versi'] ?? -1);

        // Beda revisi = kotaknya bisa geser walau kodenya sama.
        if ($versi !== $revisi) {
            return 'Revisi formulir nggak cocok (foto: Rev.'.$versi.', sistem: Rev.'.$revisi.'). '
                .'Pakai formulir revisi terbaru, atau perbarui aplikasi.';
        }

        // Teks revisi mentah (opsional) tidak boleh membantah angka yang
        // dikirim aplikasi — kalau membantah, salah satunya keliru.
        $revisiTerbaca = $payload['revisi_terbaca'] ?? null;

        if (is_string($revisiTerbaca) && preg_match('/\d+/', $revisiTerbaca, $angka) === 1 && (int) $angka[0] !== $revisi) {
            return 'Revisi yang kebaca di formulir ("'.mb_substr($revisiTerbaca, 0, 20).'") beda dari Rev.'.$revisi
                .'. Pakai formulir revisi terbaru, atau isi manual.';
        }

        if (($template['siap_pindai'] ?? false) !== true && ! $modeUji) {
            return 'Template lembar kerja ini belum siap dipindai ('
                .(string) ($template['alasan_belum_siap'] ?? 'belum disetel').'). Isi manual dulu.';
        }

        return null;
    }

    private function kodeDokumen(string $teks): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $teks));
    }

    /**
     * Pengganti `periksaGeometri()` untuk formulir asli.
     *
     * HP mencocokkan tulisan cetak hasil OCR ke `jangkar_teks` template, lalu
     * menghitung homography (RANSAC) dari pasangan itu. Server menilai bukti
     * yang dikirim: berapa jangkar, menyebar atau tidak, dan sisa errornya.
     *
     * ## Kenapa teks jangkar ikut diadu
     *
     * Server tidak melihat posisi piksel jangkar, tapi bisa memeriksa klaim
     * "di posisi jangkar #i aku membaca X" lawan teks yang tercetak di #i.
     * Jangkar yang teksnya beda TIDAK DIHITUNG (bukan menolak lembar): bisa
     * cuma salah baca OCR, bisa juga pencocokan yang salah — yang kedua
     * berbahaya, dan server tidak bisa membedakannya, jadi dua-duanya dibuang
     * dari hitungan. Syarat >=8 & 4 kuadran berlaku pada yang TERSISA.
     *
     * @param  array<string, mixed>  $geometri
     * @param  list<array<string, mixed>>  $jangkarTemplate
     * @return array{pesan: string|null, ringkasan: array<string, mixed>}
     */
    private function periksaJangkarTeks(array $geometri, array $jangkarTemplate): array
    {
        $dikirim = is_array($geometri['jangkar_cocok'] ?? null) ? array_values($geometri['jangkar_cocok']) : [];
        $dilihat = [];
        $dihitung = [];
        $beda = [];

        foreach ($dikirim as $j) {
            $indeks = (int) (is_array($j) ? ($j['indeks'] ?? -1) : -1);

            if (! isset($jangkarTemplate[$indeks])) {
                return $this->tolakGeometri('Patokan tulisan cetak #'.$indeks.' nggak ada di formulir ini — '
                    .'aplikasi memegang geometri formulir yang beda. Perbarui aplikasi.');
            }

            if (isset($dilihat[$indeks])) {
                return $this->tolakGeometri('Patokan tulisan cetak #'.$indeks.' kekirim dua kali — '
                    .'pencocokannya nggak bisa dipercaya. Foto ulang.');
            }

            $dilihat[$indeks] = true;
            $harap = $this->teksJangkar($jangkarTemplate[$indeks]['teks'] ?? null);

            if ($harap === '' || $this->teksJangkar($j['teks_mentah'] ?? null) !== $harap) {
                $beda[] = $indeks;

                continue;
            }

            $dihitung[] = $indeks;
        }

        $min = (int) config('ocr.geometri.jangkar_teks.jumlah_min', 8);

        if (count($dihitung) < $min) {
            return $this->tolakGeometri('Patokan tulisan cetak yang cocok cuma '.count($dihitung).' (minimal '.$min.'). '
                .'Pastikan seluruh lembar masuk frame & nggak ketutupan, lalu foto ulang.');
        }

        $kuadran = [0, 0, 0, 0];

        foreach ($dihitung as $i) {
            $kuadran[$this->kuadran((array) ($jangkarTemplate[$i]['kotak'] ?? []))]++;
        }

        $kosong = array_keys(array_filter($kuadran, static fn (int $n): bool => $n === 0));

        if ($kosong !== []) {
            // Homography dari patokan yang mengumpul di satu sisi benar di sisi
            // itu dan melenceng di sisi seberangnya — persis tempat tabel bisa
            // geser tanpa gejala.
            return $this->tolakGeometri('Patokan tulisan cetak kurang menyebar — nggak ada satu pun di bagian '
                .implode(', ', array_map(static fn (int $k): string => self::NAMA_KUADRAN[$k], $kosong))
                .' lembar. Pastikan keempat sisi lembar masuk frame, lalu foto ulang.');
        }

        $residual = isset($geometri['residual_reproyeksi_pt']) && is_numeric($geometri['residual_reproyeksi_pt'])
            ? (float) $geometri['residual_reproyeksi_pt']
            : null;

        if ($residual === null) {
            return $this->tolakGeometri('Aplikasi nggak ngirim ukuran ketepatan penyelarasan. Perbarui aplikasi.');
        }

        if ($residual > (float) config('ocr.geometri.jangkar_teks.residual_maks_pt', 1.5)) {
            return $this->tolakGeometri('Penyelarasan lembar nggak cukup presisi (meleset '.round($residual, 2).' pt). Foto ulang.');
        }

        return [
            'pesan' => null,
            // Ikut disimpan di `hasil` — bahan menyetel ambang dari foto mode uji.
            'ringkasan' => [
                'jangkar_dikirim' => count($dikirim),
                'jangkar_dihitung' => count($dihitung),
                'jangkar_teks_beda' => $beda,
                'kuadran' => $kuadran,
                'residual_reproyeksi_pt' => $residual,
            ],
        ];
    }

    /**
     * @return array{pesan: string, ringkasan: array<string, mixed>}
     */
    private function tolakGeometri(string $pesan): array
    {
        return ['pesan' => $pesan, 'ringkasan' => []];
    }

    /**
     * Huruf & angka saja, huruf besar: "(oC)" = "OC", ":Insitu:" = "INSITU".
     * Tanda baca & spasi paling sering beda di OCR tanpa artinya berubah.
     */
    private function teksJangkar(mixed $teks): string
    {
        return mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]+/u', '', is_string($teks) ? $teks : ''));
    }

    /**
     * Kuadran halaman dari pusat kotak ternormal: 0 kiri-atas, 1 kanan-atas,
     * 2 kiri-bawah, 3 kanan-bawah.
     *
     * @param  array<string, mixed>  $kotak
     */
    private function kuadran(array $kotak): int
    {
        $x = (float) ($kotak['x'] ?? 0) + (float) ($kotak['w'] ?? 0) / 2;
        $y = (float) ($kotak['y'] ?? 0) + (float) ($kotak['h'] ?? 0) / 2;

        return ($x >= 0.5 ? 1 : 0) + ($y >= 0.5 ? 2 : 0);
    }

    /**
     * Titik peta yang tidak jatuh di tepat satu kotak draf (`kotak: null`)
     * berarti letaknya tidak diketahui. Mode uji membuka pintu `siap_pindai`,
     * jadi penjagaan ini yang memastikan butir tanpa kotak tetap tidak dibaca.
     *
     * @param  array<string, mixed>  $template
     */
    private function kotakTemplateHilang(array $template): ?string
    {
        $hilang = [];

        foreach ($template['sel'] ?? [] as $kunci => $s) {
            if (($s['kotak'] ?? null) === null) {
                $hilang[] = 'sel `'.$kunci.'`';
            }
        }

        foreach ([...($template['isian'] ?? []), ...($template['centang'] ?? [])] as $b) {
            if (($b['kotak'] ?? null) === null) {
                $hilang[] = '`'.(string) ($b['kode'] ?? '?').'`';
            }
        }

        if ($hilang === []) {
            return null;
        }

        return count($hilang).' kotak di geometri formulir asli belum ketemu ('.$hilang[0]
            .(count($hilang) > 1 ? ', …' : '').') — lembar ini nggak dibaca. Isi manual dulu.';
    }

    /**
     * Isian Env. lewat KODE, bukan urutan: asing, dobel, atau kurang menolak
     * seluruh lembar — alasannya sama dengan `petakan()`.
     *
     * @param  list<array<string, mixed>>  $isianHp
     * @param  list<array<string, mixed>>  $isianTemplate
     * @return array{isian: array<string, array<string, mixed>>, pesan: string|null}
     */
    private function petakanIsian(array $isianHp, array $isianTemplate): array
    {
        $dikenal = array_map('strval', array_column($isianTemplate, 'kode'));
        $peta = [];

        foreach ($isianHp as $i) {
            $kode = (string) ($i['kode'] ?? '');

            // Dua pagar: yang ada di template, DAN yang dibuka tahap 1 (K2).
            // Identitas & tanggal tidak boleh masuk lewat jalur ini.
            if (! in_array($kode, $dikenal, true) || ! in_array($kode, FormulirAsli::ISIAN_TAHAP_1, true)) {
                return ['isian' => [], 'pesan' => 'Isian `'.mb_substr($kode, 0, 40).'` nggak dibuka buat pindai formulir ini.'];
            }

            if (isset($peta[$kode])) {
                return ['isian' => [], 'pesan' => "Isian `{$kode}` kekirim dua kali."];
            }

            $peta[$kode] = $i;
        }

        $kurang = array_diff($dikenal, array_keys($peta));

        if ($kurang !== []) {
            return [
                'isian' => [],
                'pesan' => 'Ada '.count($kurang).' isian kondisi lingkungan yang nggak ikut kekirim. '
                    .'Pastikan seluruh lembar masuk frame, lalu foto ulang.',
            ];
        }

        return ['isian' => $peta, 'pesan' => null];
    }

    /**
     * @param  array<string, array<string, mixed>>  $peta
     * @param  list<array<string, mixed>>  $isianTemplate
     * @return list<array<string, mixed>>
     */
    private function vonisIsian(array $peta, array $isianTemplate, float $penalti): array
    {
        $hasil = [];

        foreach ($isianTemplate as $t) {
            $kode = (string) $t['kode'];

            $vonis = $this->validasi->periksa([
                'kunci' => 'isian|'.$kode,
                'tabel_id' => 'isian',
                'tahap' => null,
                'grup' => null,
                'baris_ke' => 0,
                'repeat_no' => 0,
                'field_id' => $kode,
                'titik_ukur' => null,
                'standard_id' => null,
                'aturan' => $this->aturanIsian($kode),
            ], $peta[$kode], $penalti);

            $hasil[] = ['kode' => $kode, ...$vonis, 'kotak' => $t['kotak']];
        }

        return $hasil;
    }

    /**
     * Aturan angka isian Env. — definisi MINIMAL, tanpa angka karangan.
     *
     * Rentangnya yang sudah dipegang kode: suhu = batas ruang kerja
     * `ocr.suhu.min/maks` (5–45 °C), kelembaban = batas ruang lab
     * `CalibrationValidator::KELEMBABAN_MIN/MAKS` (20–90 %RH; di luar itu sesi
     * diblokir saat approve). Resolusi & jumlah desimal thermohygro tidak
     * tercatat di mana pun, jadi dibiarkan null — bukan ditebak.
     *
     * @return array<string, mixed>
     */
    private function aturanIsian(string $kode): array
    {
        $kelembaban = match ($kode) {
            'suhu_awal', 'suhu_akhir' => false,
            'kelembaban_awal', 'kelembaban_akhir' => true,
        };

        return [
            'tipe' => 'desimal',
            'satuan' => $kelembaban ? '%RH' : '°C',
            'nominal' => null,
            'resolusi' => null,
            'desimal' => null,
            'min' => $kelembaban ? CalibrationValidator::KELEMBABAN_MIN : (float) config('ocr.suhu.min', 5),
            'maks' => $kelembaban ? CalibrationValidator::KELEMBABAN_MAKS : (float) config('ocr.suhu.maks', 45),
            'rasio_min' => null,
            'rasio_maks' => null,
            'izinkan_minus' => false,
            // Thermohygro tidak selalu ada di lokasi pelanggan — kolom sesinya
            // pun opsional (`CalibrationRequest`).
            'boleh_kosong' => true,
            'tulisan_tangan' => true,
        ];
    }

    /**
     * Centang lewat identitas (kode + pilihan TH-n / baris_ke Usage Check),
     * bukan urutan (§6 butir 5).
     *
     * @param  list<array<string, mixed>>  $centangHp
     * @param  list<array<string, mixed>>  $centangTemplate
     * @return array{centang: array<string, array<string, mixed>>, pesan: string|null}
     */
    private function petakanCentang(array $centangHp, array $centangTemplate): array
    {
        $dikenal = [];

        foreach ($centangTemplate as $t) {
            $id = $this->idCentang($t);

            if (isset($dikenal[$id])) {
                return ['centang' => [], 'pesan' => "Kotak centang `{$id}` tercatat dua kali di geometri formulir."];
            }

            $dikenal[$id] = true;
        }

        $peta = [];

        foreach ($centangHp as $c) {
            $id = $this->idCentang($c);

            if (! isset($dikenal[$id])) {
                return ['centang' => [], 'pesan' => 'Kotak centang `'.mb_substr($id, 0, 90).'` nggak ada di formulir ini.'];
            }

            if (isset($peta[$id])) {
                return ['centang' => [], 'pesan' => "Kotak centang `{$id}` kekirim dua kali."];
            }

            $peta[$id] = $c;
        }

        $kurang = array_diff_key($dikenal, $peta);

        if ($kurang !== []) {
            return [
                'centang' => [],
                'pesan' => 'Ada '.count($kurang).' kotak centang yang nggak ikut kekirim. '
                    .'Pastikan seluruh lembar masuk frame, lalu foto ulang.',
            ];
        }

        return ['centang' => $peta, 'pesan' => null];
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function idCentang(array $c): string
    {
        $pilihan = $c['pilihan'] ?? null;
        $barisKe = $c['baris_ke'] ?? null;

        return (string) ($c['kode'] ?? '')
            .'|'.($pilihan === null || $pilihan === '' ? '' : (string) $pilihan)
            .'|'.($barisKe === null || $barisKe === '' ? '' : (string) (int) $barisKe);
    }

    /**
     * Kunci baris `worksheet_scan_cells` & rute crop — hanya karakter yang
     * diterima rute (`[A-Za-z0-9_|\-\.]`). Kode `standar_dicek.*.dipakai`
     * memuat `*`, jadi disaring.
     *
     * @param  array<string, mixed>  $t
     */
    private function kunciCentang(array $t): string
    {
        $aman = static fn (string $s): string => (string) preg_replace('/[^A-Za-z0-9_.\-]/', '_', $s);

        return 'centang|'.$aman((string) $t['kode']).'|'
            .(($t['pilihan'] ?? null) !== null ? $aman((string) $t['pilihan']) : (string) (int) ($t['baris_ke'] ?? 0));
    }

    /**
     * Rasio piksel gelap → dicentang / kosong / ragu. BUKAN OCR (§4).
     *
     * @param  array<string, array<string, mixed>>  $peta
     * @param  list<array<string, mixed>>  $centangTemplate
     * @return list<array<string, mixed>>
     */
    private function vonisCentang(array $peta, array $centangTemplate): array
    {
        $tercentang = (float) config('ocr.centang.ambang_tercentang', 0.15);
        $kosong = (float) config('ocr.centang.ambang_kosong', 0.05);
        $hasil = [];

        foreach ($centangTemplate as $t) {
            $c = $peta[$this->idCentang($t)];
            $rasio = isset($c['rasio_gelap']) && is_numeric($c['rasio_gelap']) ? (float) $c['rasio_gelap'] : null;

            [$dicentang, $status, $alasan] = match (true) {
                // Tidak terukur = tidak terbaca. Bukan dianggap kosong.
                $rasio === null => [null, ValidasiSel::MERAH, ['rasio_gelap_tidak_terbaca']],
                $rasio >= $tercentang => [true, ValidasiSel::HIJAU, []],
                $rasio <= $kosong => [false, ValidasiSel::KOSONG, []],
                default => [null, ValidasiSel::KUNING, ['centang_ragu']],
            };

            // Bentuknya sengaja sejajar dengan hasil `ValidasiSel` supaya
            // tersimpan sebagai baris `worksheet_scan_cells` (koreksi, crop,
            // `ocr:akurasi`). `nilai` 1 = dicentang, 0 = kosong, null = ragu.
            $hasil[] = [
                'kunci' => $this->kunciCentang($t),
                'kode' => (string) $t['kode'],
                'pilihan' => $t['pilihan'] ?? null,
                'baris_ke' => $t['baris_ke'] ?? null,
                'label' => $t['label'] ?? null,
                'tabel_id' => 'centang',
                'tahap' => null,
                'grup' => null,
                'repeat_no' => 0,
                'field_id' => (string) $t['kode'],
                'titik_ukur' => null,
                'standard_id' => null,
                'satuan' => null,
                'teks_mentah' => null,
                'teks_normal' => null,
                'rasio_gelap' => $rasio,
                'dicentang' => $dicentang,
                'nilai' => $dicentang === null ? null : ($dicentang ? 1.0 : 0.0),
                'normalisasi' => [],
                'confidence_ocr' => null,
                'confidence_akhir' => null,
                'status' => $status,
                'alasan' => $alasan,
                'pesan' => null,
                'kotak' => $t['kotak'],
            ];
        }

        return $this->periksaPilihanTunggal($hasil);
    }

    /**
     * Centang ber-`pilihan` (TH-n) = satu jawaban per kode. Lebih dari satu
     * yang tercentang → MERAH: tidak ada dasar memilih, dan memilih yang
     * pertama = menebak dari urutan. Nol tercentang tetap kosong.
     *
     * @param  list<array<string, mixed>>  $centang
     * @return list<array<string, mixed>>
     */
    private function periksaPilihanTunggal(array $centang): array
    {
        $grup = [];

        foreach ($centang as $i => $c) {
            if ($c['pilihan'] !== null && $c['dicentang'] === true) {
                $grup[$c['kode']][] = $i;
            }
        }

        foreach ($grup as $indeks) {
            if (count($indeks) < 2) {
                continue;
            }

            $pilihan = implode(', ', array_map(static fn (int $i): string => (string) $centang[$i]['pilihan'], $indeks));

            foreach ($indeks as $i) {
                $centang[$i]['status'] = ValidasiSel::MERAH;
                $centang[$i]['alasan'][] = 'pilihan_ganda';
                $centang[$i]['pesan'] = "Lebih dari satu pilihan tercentang ({$pilihan}) — cuma satu yang dipakai. "
                    .'Pilih yang benar secara manual.';
            }
        }

        return $centang;
    }

    /**
     * @param  array<string, mixed>  $butir
     * @return array<string, mixed>
     */
    private function batasiModeUji(array $butir): array
    {
        if ($butir['status'] === ValidasiSel::HIJAU) {
            $butir['status'] = ValidasiSel::KUNING;
            $butir['alasan'][] = self::ALASAN_MODE_UJI;
        }

        return $butir;
    }

    /**
     * Centang yang terbaca kosong ikut naik ke KUNING selama mode uji
     * (keputusan pemilik, 9 Okt 2026). Ambang rasio gelapnya masih sementara,
     * jadi centang tipis yang lolos di bawah `ambang_kosong` tidak boleh
     * tersimpan diam-diam sebagai "tidak dicentang". Bacaan mesinnya tetap
     * `dicentang: false` — yang berubah cuma kewajiban teknisi melihatnya.
     *
     * @param  array<string, mixed>  $butir
     * @return array<string, mixed>
     */
    private function batasiModeUjiCentang(array $butir): array
    {
        if ($butir['status'] === ValidasiSel::KOSONG) {
            $butir['status'] = ValidasiSel::KUNING;
            $butir['alasan'][] = self::ALASAN_MODE_UJI;

            return $butir;
        }

        return $this->batasiModeUji($butir);
    }

    /**
     * @return array<string, mixed>
     */
    private function gagalAsli(string $status, ?string $pesan, bool $modeUji): array
    {
        return [
            ...$this->gagal($status, $pesan),
            'kertas' => 'asli',
            'mode_uji' => $modeUji,
            'isian' => [],
            'centang' => [],
            'geometri' => null,
        ];
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
