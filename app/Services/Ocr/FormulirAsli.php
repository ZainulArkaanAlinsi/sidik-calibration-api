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
 */
class FormulirAsli
{
    /** Subfolder di bawah `TemplateLembarKerja::folderTemplate()`. */
    public const FOLDER = 'asli';

    /**
     * Isian di luar tabel yang DIBUKA untuk pindai, tahap 1 (keputusan pemilik
     * 9 Okt 2026: tabel + kondisi lingkungan + centang). Identitas & tanggal
     * sengaja belum: identitas diisi dari order (§4 "banding"), tanggal butuh
     * parser ketat yang belum ada. Kodenya kolom sesi, sama untuk semua alat.
     */
    public const ISIAN_TAHAP_1 = ['suhu_awal', 'kelembaban_awal', 'suhu_akhir', 'kelembaban_akhir'];

    public function __construct(
        private readonly CalibrationProfileRegistry $registry,
        private readonly TemplateLembarKerja $template,
    ) {}

    /**
     * @return array<string, mixed>|null null = kode tidak dikenal, atau alat ini
     *                                   belum punya formulir asli yang dipetakan
     */
    public function untukKode(string $kode, ?Equipment $alat = null, ?int $jumlahPengulangan = null): ?array
    {
        // Profil dulu, baru glob. Kode dari URL masuk pola glob, jadi `*` atau
        // `ph_*` yang lolos ke sini akan menangkap berkas alat lain.
        $profil = $this->registry->untukKode($kode);

        if ($profil === null) {
            return null;
        }

        $berkas = $this->muat($profil->kode());

        if ($berkas === null) {
            return null;
        }

        [$draf, $peta] = $berkas;
        $cetak = $this->template->dariProfil($profil, $alat, $jumlahPengulangan);

        $calonIsian = array_values(array_filter(
            $draf['sel'] ?? [],
            static fn (array $s): bool => ($s['jenis'] ?? null) === 'calon_isian',
        ));
        $titikSel = [];

        foreach ($peta['sel'] ?? [] as $e) {
            $titikSel[(string) $e['kunci']] = $e;
        }

        $sel = [];

        foreach ($cetak['sel'] as $kunci => $satu) {
            $e = $titikSel[$kunci] ?? null;
            $satu['kotak'] = $e === null ? null : $this->kotakTunggal($calonIsian, $e);
            $sel[$kunci] = $satu;
        }

        $isian = [];

        foreach ($peta['isian'] ?? [] as $e) {
            if (! in_array($e['kode'] ?? null, self::ISIAN_TAHAP_1, true)) {
                continue;
            }

            $isian[] = [
                'kode' => (string) $e['kode'],
                'cara' => $e['cara'] ?? null,
                'kotak' => $this->kotakTunggal($draf['isian_berlabel'] ?? [], $e),
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
        ], count($sel) + count($isian) + count($centang));

        return [
            'template_id' => $profil->kode(),
            'kertas' => 'asli',
            'kode_dokumen' => $draf['kode_dokumen'] ?? null,
            'revisi' => isset($draf['revisi_tercetak']) ? (string) $draf['revisi_tercetak'] : null,
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
     * Pasangan `[draf, peta]` untuk satu template, atau null.
     *
     * Lebih dari satu peta (revisi formulir berikutnya) → yang terbesar secara
     * urutan alami, sama seperti `TemplateLembarKerja::geometri()`.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function muat(string $templateId): ?array
    {
        $folder = TemplateLembarKerja::folderTemplate().'/'.self::FOLDER;
        $berkas = glob($folder.'/'.$templateId.'-*.peta.json') ?: [];

        if ($berkas === []) {
            return null;
        }

        rsort($berkas, SORT_NATURAL);

        $peta = json_decode((string) file_get_contents($berkas[0]), true);

        if (! is_array($peta) || ($peta['template_id'] ?? null) !== $templateId || ! is_string($peta['draf'] ?? null)) {
            return null;
        }

        // `basename` — nama draf datang dari berkas, tapi tetap tidak boleh
        // menunjuk keluar folder `asli/`.
        $jalurDraf = $folder.'/'.basename($peta['draf']);

        if (! is_file($jalurDraf)) {
            return null;
        }

        $draf = json_decode((string) file_get_contents($jalurDraf), true);

        return is_array($draf) ? [$draf, $peta] : null;
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
     * ditulis untuk PDF yang sama dengan drafnya, dan tiap titik punya tepat
     * satu kotak.
     *
     * @param  array<string, mixed>  $draf
     * @param  array<string, mixed>  $peta
     * @param  list<array<string, float>|null>  $kotak
     * @return array{0: bool, 1: string|null}
     */
    private function kesiapan(array $draf, array $peta, array $kotak, int $jumlah): array
    {
        $shaSama = is_string($peta['sumber_sha256'] ?? null)
            && $peta['sumber_sha256'] === ($draf['sumber']['sha256'] ?? null);

        if (($peta['terverifikasi'] ?? false) !== true || ($draf['terverifikasi'] ?? false) !== true || ! $shaSama) {
            return [false, 'geometri_belum_diverifikasi'];
        }

        $kurang = $jumlah - count(array_filter($kotak, static fn (?array $k): bool => $k !== null));

        if ($kurang > 0) {
            return [false, 'geometri_kurang_'.$kurang.'_sel'];
        }

        return [true, null];
    }
}
