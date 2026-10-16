<?php

namespace Tests\Concerns;

use App\Services\Ocr\FormulirAsli;
use Illuminate\Support\Facades\Config;

/**
 * Pasangan draf + peta formulir asli SINTETIS untuk test `FormulirAsli` &
 * pindai formulir asli — supaya perilaku generik (irisan kunci, peta ganda per
 * template, isian ber-`tahap`) bisa diuji tanpa menunggu peta formulir lain.
 *
 * Kotaknya disusun di grid tetap, titik peta = pusat kotak, jadi tiap titik
 * jatuh di tepat satu kotak sejenisnya. Delapan jangkar teks, dua per kuadran,
 * cukup untuk lolos syarat >=8 & 4 kuadran pemroses pindai.
 *
 * Berkasnya ditulis di folder sementara yang ditunjuk `ocr.folder_template`;
 * berkas produksi `database/ocr-templates/asli/` tidak pernah disentuh.
 * Panggil [hapusFormulirSintetis] di `tearDown()`.
 */
trait FormulirAsliSintetis
{
    private ?string $folderSintetis = null;

    /** sha256 PDF semu — sama di draf & peta. */
    private const SHA_SINTETIS = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * Kode & revisi yang TERCETAK di draf bawaannya diturunkan dari kode peta
     * (`SIDIK-FM-CAL-0509_Rev.4` → `SIDIK-FM-CAL-0509`, `4`) — invarian yang
     * sama dengan peta nyata (`GeometriFormulirAsliSemuaTest::
     * test_kode_dokumen_peta_milik_profil_dan_tercetak_di_draf`). Server
     * memilih peta dari kode FM yang terbaca di kertas, jadi formulir sintetis
     * yang kode tercetaknya beda dari kode petanya tidak pernah terpilih.
     *
     * @param  list<string>  $kunciSel  kunci `{tabel}|{baris}|{repeat}|{field}` yang tercetak
     * @param  list<array{kode: string, cara?: string, tahap?: int}>  $isian
     * @param  list<array{kode: string, pilihan?: string|null, baris_ke?: int|null, label?: string|null}>  $centang
     * @param  list<string>  $kunciSelBerlabel  kunci sel yang kotaknya KOTAK BERLABEL di draf
     *                                          (`isian_berlabel`), dirujuk peta dengan `sumber_kotak: "isian"` — pola
     *                                          Accuracy Timbangan 0508
     */
    protected function tulisFormulirSintetis(
        string $berkasPeta,
        string $templateId,
        string $kodeDokumenPeta,
        array $kunciSel,
        array $isian = [],
        array $centang = [],
        ?string $kodeTercetak = null,
        ?string $revisi = null,
        bool $terverifikasi = false,
        ?string $berkasDraf = null,
        array $kunciSelBerlabel = [],
    ): void {
        $folder = $this->folderFormulirSintetis();
        $berkasDraf ??= str_replace('.peta.json', '.draf.json', $berkasPeta);
        $kodeTercetak ??= (string) preg_replace('/_Rev\..*$/i', '', $kodeDokumenPeta);
        $revisi ??= preg_match('/_Rev\.(\d+)$/i', $kodeDokumenPeta, $angka) === 1 ? $angka[1] : '1';

        $kotakSel = [];
        $titikSel = [];

        foreach (array_values($kunciSel) as $i => $kunci) {
            $kotak = ['x' => 0.10 + ($i % 16) * 0.05, 'y' => 0.10 + intdiv($i, 16) * 0.03, 'w' => 0.045, 'h' => 0.025];
            $kotakSel[] = ['id' => sprintf('s%03d', $i), 'tabel_ke' => 0, 'jenis' => 'calon_isian', ...$kotak];
            $titikSel[] = ['kunci' => $kunci, ...$this->pusat($kotak)];
        }

        $kotakIsian = [];
        $titikIsian = [];

        foreach (array_values($isian) as $i => $e) {
            $kotak = ['x' => 0.05 + $i * 0.1, 'y' => 0.90, 'w' => 0.09, 'h' => 0.02];
            $kotakIsian[] = ['id' => sprintf('f%02d', $i), 'label' => $e['kode'], 'tercetak_sesudah_titik_dua' => null, ...$kotak];
            $titikIsian[] = ['kode' => $e['kode'], 'cara' => $e['cara'] ?? 'angka', ...(isset($e['tahap']) ? ['tahap' => $e['tahap']] : []), ...$this->pusat($kotak)];
        }

        foreach (array_values($kunciSelBerlabel) as $i => $kunci) {
            $kotak = ['x' => 0.05 + $i * 0.06, 'y' => 0.85, 'w' => 0.05, 'h' => 0.02];
            $kotakIsian[] = ['id' => sprintf('b%02d', $i), 'label' => 'z'.($i + 1), 'tercetak_sesudah_titik_dua' => null, ...$kotak];
            $titikSel[] = ['kunci' => $kunci, 'sumber_kotak' => 'isian', ...$this->pusat($kotak)];
        }

        $kotakCentang = [];
        $titikCentang = [];

        foreach (array_values($centang) as $i => $e) {
            $kotak = ['x' => 0.05 + $i * 0.03, 'y' => 0.95, 'w' => 0.01, 'h' => 0.013];
            $kotakCentang[] = ['id' => sprintf('c%02d', $i), 'label_dekat' => $e['pilihan'] ?? null, 'asal' => 'gambar', ...$kotak];
            $titikCentang[] = [
                'kode' => $e['kode'],
                'cara' => 'centang',
                ...(array_key_exists('pilihan', $e) ? ['pilihan' => $e['pilihan']] : []),
                ...(array_key_exists('baris_ke', $e) ? ['baris_ke' => $e['baris_ke']] : []),
                ...(array_key_exists('label', $e) ? ['label' => $e['label']] : []),
                ...$this->pusat($kotak),
            ];
        }

        $draf = [
            'sumber' => [
                'pdf' => 'sintetis.pdf',
                'sha256' => self::SHA_SINTETIS,
                'halaman' => 1,
                'ukuran_pt' => ['w' => 792.0, 'h' => 612.0],
                'orientasi' => 'lanskap',
            ],
            'kode_dokumen' => $kodeTercetak,
            'revisi_tercetak' => $revisi,
            'terverifikasi' => $terverifikasi,
            'koordinat' => 'ternormal_0_1',
            'sel' => $kotakSel,
            'kotak_centang' => $kotakCentang,
            'isian_berlabel' => $kotakIsian,
            'jangkar_teks' => $this->jangkarSintetis(),
        ];

        $peta = [
            'template_id' => $templateId,
            'kode_dokumen' => $kodeDokumenPeta,
            'draf' => $berkasDraf,
            'sumber_sha256' => self::SHA_SINTETIS,
            'terverifikasi' => $terverifikasi,
            'koordinat' => 'ternormal_0_1',
            'sel' => $titikSel,
            'isian' => $titikIsian,
            'centang' => $titikCentang,
            'abaikan' => [],
        ];

        file_put_contents($folder.'/'.$berkasDraf, json_encode($draf));
        file_put_contents($folder.'/'.$berkasPeta, json_encode($peta));
    }

    /**
     * Salin draf & peta pH ASLI ke folder sementara (berdampingan dengan peta
     * sintetis), dengan `$ubahPeta` opsional.
     */
    protected function salinFormulirAsliPh(?callable $ubahPeta = null): void
    {
        $folder = $this->folderFormulirSintetis();
        $peta = json_decode((string) file_get_contents(database_path('ocr-templates/asli/ph_meter-0509.peta.json')), true);

        copy(database_path('ocr-templates/asli/ph_meter-0509.draf.json'), $folder.'/ph_meter-0509.draf.json');
        file_put_contents($folder.'/ph_meter-0509.peta.json', json_encode($ubahPeta !== null ? $ubahPeta($peta) : $peta));
    }

    protected function hapusFormulirSintetis(): void
    {
        if ($this->folderSintetis === null) {
            return;
        }

        foreach (glob($this->folderSintetis.'/'.FormulirAsli::FOLDER.'/*.json') ?: [] as $berkas) {
            @unlink($berkas);
        }

        @rmdir($this->folderSintetis.'/'.FormulirAsli::FOLDER);
        @rmdir($this->folderSintetis);
        $this->folderSintetis = null;
    }

    /**
     * Folder `asli/` sementara; `ocr.folder_template` ikut dialihkan.
     */
    private function folderFormulirSintetis(): string
    {
        $this->folderSintetis ??= storage_path('framework/testing/ocr-asli-sintetis-'.getmypid());
        $asli = $this->folderSintetis.'/'.FormulirAsli::FOLDER;
        @mkdir($asli, 0755, true);
        Config::set('ocr.folder_template', $this->folderSintetis);

        return $asli;
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $kotak
     * @return array{x: float, y: float}
     */
    private function pusat(array $kotak): array
    {
        return ['x' => round($kotak['x'] + $kotak['w'] / 2, 5), 'y' => round($kotak['y'] + $kotak['h'] / 2, 5)];
    }

    /**
     * @return list<array{teks: string, x: float, y: float, w: float, h: float}>
     */
    private function jangkarSintetis(): array
    {
        $titik = [[0.05, 0.02], [0.30, 0.04], [0.60, 0.02], [0.85, 0.04], [0.05, 0.60], [0.30, 0.70], [0.60, 0.60], [0.85, 0.70]];

        return array_map(
            static fn (array $t, int $i): array => ['teks' => 'JANGKAR'.($i + 1), 'x' => $t[0], 'y' => $t[1], 'w' => 0.05, 'h' => 0.02],
            $titik,
            array_keys($titik),
        );
    }
}
