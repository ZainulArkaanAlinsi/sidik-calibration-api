<?php

namespace App\Services\Ocr;

use App\Models\Equipment;
use App\Services\Calibration\CalibrationProfileRegistry;

/**
 * Template pindai untuk formulir ASLI lab (`kertas: "asli"`) — kertas
 * SIDIK-FM-CAL yang memang dipakai lab, tanpa marker & tanpa QR.
 * `docs/PANDUAN-OCR-LEMBAR-KERJA.md` §3.
 *
 * Sumbernya dua berkas per formulir di `{folder_template}/asli/`:
 *
 * - `{template}-{nnnn}.draf.json` — keluaran `docs/skrip/draf-geometri-dari-pdf.py`
 *   apa adanya: kotak ternormal 0..1 (x, y = pojok kiri-atas, w, h).
 * - `{template}-{nnnn}.peta.json` — keputusan manusia: TITIK TENGAH mana jadi
 *   kunci sel / isian / centang yang mana.
 *
 * Kelas ini cuma MENERJEMAHKAN titik peta jadi kotak draf. Dia tidak pernah
 * menebak kotak: titik yang jatuh di nol atau dua kotak memulangkan `kotak:
 * null`, dan formulir yang punya kotak null tidak pernah `siap_pindai`.
 *
 * Bagian yang diturunkan dari profil (tabel, aturan angka per sel) dipinjam
 * utuh dari [TemplateLembarKerja] — satu sumber kebenaran. Jalur cetak
 * bermarker (`{kode}-v{n}.json`) tidak disentuh sama sekali.
 *
 * ## Generik untuk formulir mana pun (G0)
 *
 * Tidak ada daftar alat atau kode isian di kelas ini — semuanya dari peta:
 *
 * - `sel` = IRISAN kunci peta dan kunci template profil untuk alat ini. Kunci
 *   profil yang tidak tercetak di kertas tidak ikut (teknisi mengisinya
 *   manual); kunci peta yang tidak ada di konfigurasi alat ini dibuang.
 * - `isian` = isian peta ber-`"tahap": 1` (kondisi lingkungan); kotaknya dari
 *   `isian_berlabel` draf, atau dari sel draf kalau `"sumber_kotak": "sel"`.
 *   Kebalikannya juga ada: sel tabel yang kotaknya `isian_berlabel` draf
 *   (`"sumber_kotak": "isian"` di entri `sel`).
 * - Satu template bisa punya beberapa peta (Timbangan 0508/0508.A, Micrometer
 *   0522.A–D, piston 0528/0529, flowmeter 0538 + .A/.B): yang dipakai peta
 *   yang `kode_dokumen`-nya sama dengan kode FM yang dibaca dari kertas, atau
 *   — tanpa itu — dengan kode dokumen template profil untuk alat itu. Lihat
 *   [pilihPeta].
 */
class FormulirAsli
{
    /** Subfolder di bawah `TemplateLembarKerja::folderTemplate()`. */
    public const FOLDER = 'asli';

    /**
     * Nilai `tahap` isian peta yang DIBUKA untuk pindai (keputusan pemilik
     * 9 Okt 2026: tabel + kondisi lingkungan + centang). Identitas & tanggal
     * sengaja belum: identitas diisi dari order (§4 "banding"), tanggal butuh
     * parser ketat yang belum ada. Isian peta tanpa `tahap` tetap tercatat di
     * peta (supaya kotaknya punya nasib) tapi tidak dikirim ke HP.
     *
     * Bukan `tahap` tabel (`sebelum_adjustment`/…): ini tahap PEMBUKAAN pindai.
     */
    public const TAHAP_DIBUKA = 1;

    /** Panjang kolom `worksheet_scans.pesan` — lihat [batasiPesan]. */
    public const PANJANG_PESAN = 255;

    public function __construct(
        private readonly CalibrationProfileRegistry $registry,
        private readonly TemplateLembarKerja $template,
    ) {}

    /**
     * @return array<string, mixed>|null null = kode tidak dikenal, alat ini
     *                                   belum punya formulir asli yang dipetakan,
     *                                   atau petanya tidak bisa dipilih ([cari])
     */
    public function untukKode(
        string $kode,
        ?Equipment $alat = null,
        ?int $jumlahPengulangan = null,
        ?string $kodeDokumen = null,
    ): ?array {
        $hasil = $this->cari($kode, $alat, $jumlahPengulangan, $kodeDokumen);

        return is_array($hasil) ? $hasil : null;
    }

    /**
     * Template formulir asli, atau alasan kenapa tidak ada.
     *
     * @param  string|null  $kodeDokumen  formulir yang DIMINTA — kode FM yang
     *                                    dibaca HP dari kaki kertas, dengan atau tanpa `_Rev.n`. Inilah
     *                                    pemilih varian yang ditentukan per sesi (piston 0528/0529,
     *                                    flowmeter UFM/gravimetri). Kosong = formulir bawaan alat ini.
     * @return array<string, mixed>|string|null array = template; string = alat
     *                                          ini PUNYA peta tapi tidak ada yang bisa dipilih untuknya (pesan
     *                                          siap tampil); null = kode tidak dikenal / belum dipetakan sama sekali
     */
    public function cari(
        string $kode,
        ?Equipment $alat = null,
        ?int $jumlahPengulangan = null,
        ?string $kodeDokumen = null,
    ): array|string|null {
        // Profil dulu, baru glob. Kode dari URL masuk pola glob, jadi `*` atau
        // `ph_*` yang lolos ke sini akan menangkap berkas alat lain.
        $profil = $this->registry->untukKode($kode);

        if ($profil === null) {
            return null;
        }

        $semuaPeta = $this->semuaPeta($profil->kode());

        if ($semuaPeta === []) {
            return null;
        }

        $cetak = $this->template->dariProfil($profil, $alat, $jumlahPengulangan);
        $peta = $this->pilihPeta($semuaPeta, $cetak['kode_dokumen'] ?? null, $kodeDokumen);

        if (is_string($peta)) {
            return self::batasiPesan($peta);
        }

        $draf = $this->muatDraf($peta);

        if ($draf === null) {
            return null;
        }

        $calonIsian = array_values(array_filter(
            $draf['sel'] ?? [],
            static fn (array $s): bool => ($s['jenis'] ?? null) === 'calon_isian',
        ));
        $titikSel = [];

        foreach ($peta['sel'] ?? [] as $e) {
            $titikSel[(string) $e['kunci']] = $e;
        }

        // Irisan, urut profil. Kunci profil tanpa titik peta = tidak tercetak
        // di kertas ini → tidak dikirim (bukan `kotak: null`); titik peta yang
        // kuncinya tidak ada di konfigurasi alat ini (mis. Repeat 4–5 waktu
        // `jumlah_pengulangan` 3) → dibuang.
        $sel = [];

        foreach ($cetak['sel'] as $kunci => $satu) {
            if (! isset($titikSel[$kunci])) {
                continue;
            }

            // Sel tabel yang di draf terbaca sebagai kotak BERLABEL (Accuracy
            // Timbangan 0508/0508.A: kotak z1, m1, m1', z1') dirujuk peta lewat
            // `sumber_kotak: "isian"`. Nilai lain = tidak dicari → kotak null
            // → formulir tidak siap.
            $satu['kotak'] = $this->kotakTunggal(match ($titikSel[$kunci]['sumber_kotak'] ?? 'sel') {
                'sel' => $calonIsian,
                'isian' => $draf['isian_berlabel'] ?? [],
                default => [],
            }, $titikSel[$kunci]);
            $sel[$kunci] = $satu;
        }

        $isian = [];

        foreach ($peta['isian'] ?? [] as $e) {
            if (($e['tahap'] ?? null) !== self::TAHAP_DIBUKA) {
                continue;
            }

            $isian[] = [
                'kode' => (string) $e['kode'],
                'cara' => $e['cara'] ?? null,
                // Kotak Env. yang di draf terbaca sebagai SEL tabel (0513, 0514,
                // 0538…) dirujuk peta lewat `sumber_kotak: "sel"`. Nilai lain
                // yang tak dikenal = tidak dicari di mana pun → kotak null →
                // formulir tidak siap, lembar tidak dibaca.
                'kotak' => $this->kotakTunggal(match ($e['sumber_kotak'] ?? 'isian') {
                    'isian' => $draf['isian_berlabel'] ?? [],
                    'sel' => $calonIsian,
                    default => [],
                }, $e),
            ];
        }

        $centang = [];

        foreach ($peta['centang'] ?? [] as $e) {
            $centang[] = [
                'kode' => (string) $e['kode'],
                // TH-n: satu nilai yang dipilih.
                'pilihan' => $e['pilihan'] ?? null,
                // Usage Check: baris tercetak ke-n beserta tulisannya. `baris_ke`
                // ikut dikirim supaya HP tidak menebak baris dari urutan (§6 butir 5).
                'baris_ke' => isset($e['baris_ke']) ? (int) $e['baris_ke'] : null,
                'label' => $e['label'] ?? null,
                'kotak' => $this->kotakTunggal($draf['kotak_centang'] ?? [], $e),
            ];
        }

        [$siap, $alasan] = $this->kesiapan($draf, $peta, [
            ...array_column($sel, 'kotak'),
            ...array_column($isian, 'kotak'),
            ...array_column($centang, 'kotak'),
        ], count($sel) + count($isian) + count($centang), count($sel));

        return [
            'template_id' => $profil->kode(),
            'kertas' => 'asli',
            'kode_dokumen' => $draf['kode_dokumen'] ?? null,
            'revisi' => $this->revisi($draf, $peta),
            'sumber_sha256' => $peta['sumber_sha256'] ?? null,
            'judul' => $cetak['judul'],
            'satuan' => $cetak['satuan'],
            'jumlah_pengulangan' => $cetak['jumlah_pengulangan'],
            'tabel' => $cetak['tabel'],
            'geometri' => [
                'koordinat' => $draf['koordinat'] ?? null,
                'ukuran_referensi' => [
                    'w' => $draf['sumber']['ukuran_pt']['w'] ?? null,
                    'h' => $draf['sumber']['ukuran_pt']['h'] ?? null,
                    'satuan' => 'pt',
                    'orientasi' => $draf['sumber']['orientasi'] ?? null,
                ],
                'jangkar_teks' => array_map(
                    fn (array $j): array => ['teks' => (string) $j['teks'], 'kotak' => $this->kotak($j)],
                    $draf['jangkar_teks'] ?? [],
                ),
            ],
            'sel' => $sel,
            'isian' => $isian,
            'centang' => $centang,
            'siap_pindai' => $siap,
            'alasan_belum_siap' => $alasan,
            // Peta yang masih berisi ASUMSI POSISIONAL (nominal kertas beda dari
            // titik profil; kolom kertas -> baris profil ditebak urutannya).
            // HP menyembunyikan pintu "Foto lembar kerja" dan pemroses menolak
            // pindainya, SAMPAI lab mengonfirmasi dan flag ini dihapus dari peta.
            // Formulir yang TIDAK mencetak teks "Revise" (0538) menyatakannya
            // eksplisit di peta; HP hanya melewati syarat baca revisi untuk
            // yang `false`. Selain itu revisi wajib terbaca.
            'revisi_tercetak' => ($peta['revisi_tercetak'] ?? true) !== false,
            'perlu_konfirmasi_lab' => ($peta['perlu_konfirmasi_lab'] ?? false) === true,
            // Keputusan pemilik (K1, 9 Okt 2026): sebelum >=20 foto, pindai
            // boleh dipakai dalam mode uji — semua sel maks kuning. Penegakan
            // vonisnya di server pemroses pindai, bukan di sini.
            'mode_uji' => (bool) config('ocr.formulir_asli.mode_uji', false),
            'pipeline_versi' => $cetak['pipeline_versi'],
            // Bukan versi jalur cetak: vonis formulir asli juga bergantung pada
            // ambang jangkar teks & centang. Nilai yang SAMA dicatat tiap pindai
            // asli (`WorksheetScanController::simpan()`).
            'aturan_versi' => self::aturanVersi(),
        ];
    }

    /**
     * Versi aturan yang menentukan vonis pindai formulir asli: ambang sel lama
     * (`ocr.aturan_versi`) + ambang khusus asli (`ocr.formulir_asli.aturan_versi`).
     * Dua-duanya ikut karena geser salah satunya mengubah vonis.
     */
    public static function aturanVersi(): string
    {
        return config('ocr.aturan_versi').'+'.config('ocr.formulir_asli.aturan_versi');
    }

    /**
     * Semua peta milik satu template: `{template}-*.peta.json` yang
     * `template_id`-nya memang template itu dan menyebut berkas drafnya.
     *
     * `template_id` diperiksa lagi karena glob cuma mencocokkan awalan nama
     * berkas — nama berkas bukan bukti isi.
     *
     * @return list<array<string, mixed>>
     */
    private function semuaPeta(string $templateId): array
    {
        $berkas = glob(self::folder().'/'.$templateId.'-*.peta.json') ?: [];
        sort($berkas, SORT_NATURAL);
        $hasil = [];

        foreach ($berkas as $jalur) {
            $peta = json_decode((string) file_get_contents($jalur), true);

            if (is_array($peta) && ($peta['template_id'] ?? null) === $templateId && is_string($peta['draf'] ?? null)) {
                $hasil[] = $peta;
            }
        }

        return $hasil;
    }

    /**
     * Peta untuk alat ini.
     *
     * Formulir DIMINTA (kode FM yang dibaca dari kertas) menang: kertas yang
     * difoto itulah yang diisi, dan varian per sesi (piston One Mark lawan
     * Graduated, flowmeter UFM lawan gravimetri) tidak bisa diturunkan dari
     * alat. Dicocokkan ke `kode_dokumen` peta dengan atau tanpa `_Rev.n`; nol
     * yang cocok → tidak dipilih (bukan jatuh ke bawaan), karena yang difoto
     * memang bukan formulir yang dipetakan untuk alat ini.
     *
     * Tanpa permintaan, bawaan alat ini — `kode_dokumen` template profil untuk
     * alat/varian itu (`SIDIK-FM-CAL-0508.A_Rev.4`, …):
     *
     * - Tepat satu yang cocok → itu.
     * - Profil tidak menyebut kode untuk alat ini (`null`) dan petanya cuma
     *   satu → yang satu itu. Dua atau lebih → tidak dipilih: memilih salah
     *   satunya sama dengan menebak formulir.
     * - Profil menyebut kode, tapi tidak ada peta yang cocok → tidak dipilih,
     *   WALAU petanya cuma satu. Peta itu milik varian lain; tanpa kode yang
     *   dibaca dari kertas, tidak ada bukti kertas itu yang dipakai.
     *
     * @param  non-empty-list<array<string, mixed>>  $semua
     * @return array<string, mixed>|string peta, atau pesan siap tampil
     */
    private function pilihPeta(array $semua, ?string $kodeDokumen, ?string $diminta = null): array|string
    {
        $kode = $kodeDokumen === null || trim($kodeDokumen) === '' ? null : self::bakukanKode($kodeDokumen);
        $daftar = implode(', ', array_map(static fn (array $p): string => (string) ($p['kode_dokumen'] ?? '?'), $semua));

        if ($diminta !== null && trim($diminta) !== '') {
            $minta = self::bakukanKode($diminta);
            $cocok = array_values(array_filter(
                $semua,
                static fn (array $p): bool => in_array($minta, [
                    self::bakukanKode((string) ($p['kode_dokumen'] ?? '')),
                    self::tanpaRevisi((string) ($p['kode_dokumen'] ?? '')),
                ], true),
            ));

            return match (count($cocok)) {
                1 => $cocok[0],
                0 => 'Formulir "'.mb_substr($diminta, 0, 40).'" belum dipetakan untuk alat ini (yang sudah: '.$daftar.'). '
                    .'Foto formulir lembar kerja alat ini, pakai lembar cetak, atau isi manual.',
                default => 'Formulir "'.mb_substr($diminta, 0, 40).'" dipetakan lebih dari sekali — sistem tidak memilih salah satunya. '
                    .'Isi manual dulu.',
            };
        }

        if ($kode === null) {
            return count($semua) === 1
                ? $semua[0]
                : 'Formulir asli alat ini ada beberapa ('.$daftar.') dan alatnya belum cukup dikenal untuk memilih '
                    .'salah satunya. Pilih alatnya dulu, atau isi manual.';
        }

        $cocok = array_values(array_filter(
            $semua,
            static fn (array $p): bool => self::bakukanKode((string) ($p['kode_dokumen'] ?? '')) === $kode,
        ));

        return match (count($cocok)) {
            1 => $cocok[0],
            0 => 'Formulir asli lab buat '.$kodeDokumen.' belum dipetakan (yang sudah: '.$daftar.'). '
                .'Pakai lembar cetak atau isi manual dulu.',
            default => 'Formulir asli '.$kodeDokumen.' dipetakan lebih dari sekali — sistem tidak memilih salah satunya. '
                .'Isi manual dulu.',
        };
    }

    /**
     * Pesan pemilihan peta muat di `worksheet_scans.pesan` (varchar 255) —
     * MySQL strict menolak yang lebih panjang, dan pindainya jadi 500 alih-alih
     * 422 berpesan. Micrometer (empat peta) + kode diminta 40 karakter = 278.
     * Pertama awalan `SIDIK-FM-CAL-` dibuang (kodenya tetap terbaca), baru
     * dipotong kalau masih kepanjangan.
     */
    private static function batasiPesan(string $pesan): string
    {
        if (mb_strlen($pesan) <= self::PANJANG_PESAN) {
            return $pesan;
        }

        $pendek = (string) preg_replace('/SIDIK-FM-CAL-/i', '', $pesan);

        return mb_strlen($pendek) <= self::PANJANG_PESAN
            ? $pendek
            : mb_substr($pendek, 0, self::PANJANG_PESAN - 1).'…';
    }

    /**
     * Draf yang dirujuk peta, atau null.
     *
     * @param  array<string, mixed>  $peta
     * @return array<string, mixed>|null
     */
    private function muatDraf(array $peta): ?array
    {
        // `basename` — nama draf datang dari berkas, tapi tetap tidak boleh
        // menunjuk keluar folder `asli/`. Satu draf boleh dipakai banyak peta
        // (lima Enclosure di satu kertas 0504).
        $jalurDraf = self::folder().'/'.basename((string) $peta['draf']);

        if (! is_file($jalurDraf)) {
            return null;
        }

        $draf = json_decode((string) file_get_contents($jalurDraf), true);

        return is_array($draf) ? $draf : null;
    }

    /**
     * Revisi formulir: yang tercetak di draf; kalau skrip draf belum bisa
     * membacanya (kaki halaman 0513, 0514, 0538… — utang skrip), revisi di
     * `kode_dokumen` peta, yang diadu manusia ke profil & nama PDF
     * (`GeometriFormulirAsliSemuaTest`). Tidak ada keduanya → null, dan pindai
     * formulir itu ditolak pemroses ("revisi belum tercatat").
     *
     * @param  array<string, mixed>  $draf
     * @param  array<string, mixed>  $peta
     */
    private function revisi(array $draf, array $peta): ?string
    {
        $tercetak = $draf['revisi_tercetak'] ?? null;

        if ($tercetak !== null && $tercetak !== '') {
            return (string) $tercetak;
        }

        return preg_match('/_Rev\.(\d+)$/i', trim((string) ($peta['kode_dokumen'] ?? '')), $angka) === 1 ? $angka[1] : null;
    }

    private static function folder(): string
    {
        return TemplateLembarKerja::folderTemplate().'/'.self::FOLDER;
    }

    /**
     * Spasi dibuang & huruf besar — sama dengan pembanding kode FM terbaca di
     * pemroses pindai. Bukan pencocokan longgar: `0508` dan `0508.A` tetap beda.
     */
    private static function bakukanKode(string $kode): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $kode));
    }

    /** `SIDIK-FM-CAL-0538.A_Rev.3` → `SIDIK-FM-CAL-0538.A` (dibakukan) — bentuk yang tercetak di kaki kertas. */
    private static function tanpaRevisi(string $kode): string
    {
        return (string) preg_replace('/_REV\..*$/u', '', self::bakukanKode($kode));
    }

    /**
     * Kotak draf yang memuat titik peta — hanya kalau tepat SATU. Nol berarti
     * drafnya bergeser, dua berarti kotak kembar; dua-duanya bukan tempat
     * memotong angka.
     *
     * @param  iterable<array<string, mixed>>  $daftar
     * @param  array<string, mixed>  $titik
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function kotakTunggal(iterable $daftar, array $titik): ?array
    {
        $x = (float) ($titik['x'] ?? -1);
        $y = (float) ($titik['y'] ?? -1);
        $kena = [];

        foreach ($daftar as $k) {
            $kotak = $this->kotak($k);

            if ($x >= $kotak['x'] && $x <= $kotak['x'] + $kotak['w']
                && $y >= $kotak['y'] && $y <= $kotak['y'] + $kotak['h']) {
                $kena[] = $kotak;
            }
        }

        return count($kena) === 1 ? $kena[0] : null;
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array{x: float, y: float, w: float, h: float}
     */
    private function kotak(array $o): array
    {
        return [
            'x' => (float) ($o['x'] ?? 0),
            'y' => (float) ($o['y'] ?? 0),
            'w' => (float) ($o['w'] ?? 0),
            'h' => (float) ($o['h'] ?? 0),
        ];
    }

    /**
     * Kosakatanya sama dengan jalur cetak (`TemplateLembarKerja::kesiapan()`).
     *
     * Siap = peta DAN draf terverifikasi ke foto nyata (§5 langkah 5), peta
     * ditulis untuk PDF yang sama dengan drafnya, ada minimal satu sel tabel
     * yang tercetak di kertas untuk alat ini, dan tiap titik punya tepat satu
     * kotak.
     *
     * @param  array<string, mixed>  $draf
     * @param  array<string, mixed>  $peta
     * @param  list<array<string, float>|null>  $kotak
     * @return array{0: bool, 1: string|null}
     */
    private function kesiapan(array $draf, array $peta, array $kotak, int $jumlah, int $jumlahSel): array
    {
        $shaSama = is_string($peta['sumber_sha256'] ?? null)
            && $peta['sumber_sha256'] === ($draf['sumber']['sha256'] ?? null);

        if (($peta['terverifikasi'] ?? false) !== true || ($draf['terverifikasi'] ?? false) !== true || ! $shaSama) {
            return [false, 'geometri_belum_diverifikasi'];
        }

        // Irisan kosong: kertasnya tidak memuat satu pun sel tabel lembar alat
        // ini (konfigurasi alat beda dari yang dipetakan). Isian & centang saja
        // bukan alasan menyalakan kamera.
        if ($jumlahSel === 0) {
            return [false, 'geometri_tanpa_sel'];
        }

        $kurang = $jumlah - count(array_filter($kotak, static fn (?array $k): bool => $k !== null));

        if ($kurang > 0) {
            return [false, 'geometri_kurang_'.$kurang.'_sel'];
        }

        return [true, null];
    }
}
