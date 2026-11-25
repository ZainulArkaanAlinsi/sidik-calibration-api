<?php

namespace App\Services\Vision;

use App\Models\WorksheetScan;
use App\Models\WorksheetScanCell;
use Illuminate\Support\Facades\Storage;

/**
 * Memotong sel dari citra hasil pindai yang TERSIMPAN di server (`citra_warp_path`), untuk
 * dikirim ke OCR awan oleh `OcrSelAwanController`.
 *
 * Gambar tidak pernah datang dari klien: yang keluar dari server hanya potongan sel yang
 * kotaknya dibaca dari baris `worksheet_scan_cells` milik hasil pindai itu. Kelas ini TIDAK
 * memutuskan sel mana yang boleh dikirim (itu tugas pemanggil, fail-closed); dia hanya memotong
 * sel yang diminta.
 *
 * Geometri sama dengan `WorksheetScanController::crop` (jalur cetak: kotak piksel; formulir asli:
 * kotak ternormal 0..1 × ukuran citra, dengan penjaga rasio sisi 2 % terhadap `ukuran_referensi`),
 * hanya marginnya lebih kecil (`ocr.awan_sel.margin_px`). Disalin, bukan dipakai bersama, karena
 * `crop` tidak boleh berubah dalam pekerjaan ini.
 */
final class PotongSelAngka
{
    /** Sama dengan `WorksheetScanController::TOLERANSI_RASIO_HALAMAN`. */
    private const TOLERANSI_RASIO_HALAMAN = 0.02;

    /**
     * @param  iterable<WorksheetScanCell>  $sel
     * @return array{
     *     galat: ?string,
     *     potongan: array<string, string>,
     *     gagal: array<string, string>
     * } `galat` = seluruh citra tidak bisa dipakai (`citra_tidak_tersimpan`, `gd_tidak_ada`,
     *   `citra_rusak`); `potongan` = kunci → isi JPEG; `gagal` = kunci → alasan per sel.
     */
    public function potong(WorksheetScan $scan, iterable $sel): array
    {
        $disk = Storage::disk((string) config('ocr.penyimpanan.disk', 'local'));
        $path = $scan->citra_warp_path;

        if ($path === null || $path === '' || ! $disk->exists($path)) {
            return $this->galat('citra_tidak_tersimpan');
        }

        if (! extension_loaded('gd')) {
            return $this->galat('gd_tidak_ada');
        }

        $gambar = @imagecreatefromstring((string) $disk->get($path));

        if ($gambar === false) {
            return $this->galat('citra_rusak');
        }

        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $asli = ($scan->hasil['kertas'] ?? null) === 'asli';
        $halamanUtuh = ! $asli || $this->rasioHalamanCocok($scan, $lebar, $tinggi);
        $margin = max(0, (int) config('ocr.awan_sel.margin_px', 4));

        $potongan = [];
        $gagal = [];

        foreach ($sel as $s) {
            $kunci = (string) $s->kunci;

            if (! $halamanUtuh) {
                // Citra yang bukan halaman utuh terpotong di tempat yang salah tanpa gejala, dan
                // angka dari potongan yang salah lebih buruk daripada tidak ada angka.
                $gagal[$kunci] = 'citra_bukan_halaman_utuh';

                continue;
            }

            $kotak = $this->kotakPiksel(is_array($s->kotak) ? $s->kotak : [], $asli, $lebar, $tinggi);

            if ($kotak === null) {
                $gagal[$kunci] = 'kotak_tidak_ada';

                continue;
            }

            $potong = @imagecrop($gambar, [
                'x' => max(0, (int) round($kotak['x']) - $margin),
                'y' => max(0, (int) round($kotak['y']) - $margin),
                'width' => max(1, (int) round($kotak['w']) + $margin * 2),
                'height' => max(1, (int) round($kotak['h']) + $margin * 2),
            ]);

            if ($potong === false) {
                $gagal[$kunci] = 'kotak_di_luar_citra';

                continue;
            }

            ob_start();
            imagejpeg($potong, null, 85);
            $isi = (string) ob_get_clean();
            unset($potong);

            if ($isi === '') {
                $gagal[$kunci] = 'potongan_kosong';

                continue;
            }

            $potongan[$kunci] = $isi;
        }

        unset($gambar);

        return ['galat' => null, 'potongan' => $potongan, 'gagal' => $gagal];
    }

    /**
     * @param  array<string, mixed>  $kotak
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function kotakPiksel(array $kotak, bool $ternormal, int $lebar, int $tinggi): ?array
    {
        foreach (['x', 'y', 'w', 'h'] as $k) {
            if (! isset($kotak[$k]) || ! is_numeric($kotak[$k])) {
                return null;
            }
        }

        $skalaX = $ternormal ? $lebar : 1;
        $skalaY = $ternormal ? $tinggi : 1;
        $hasil = [
            'x' => (float) $kotak['x'] * $skalaX,
            'y' => (float) $kotak['y'] * $skalaY,
            'w' => (float) $kotak['w'] * $skalaX,
            'h' => (float) $kotak['h'] * $skalaY,
        ];

        return $hasil['w'] > 0 && $hasil['h'] > 0 ? $hasil : null;
    }

    private function rasioHalamanCocok(WorksheetScan $scan, int $lebar, int $tinggi): bool
    {
        $acuan = $scan->hasil['ukuran_referensi'] ?? null;
        $rasioAcuan = is_array($acuan) && (float) ($acuan['h'] ?? 0) > 0
            ? (float) ($acuan['w'] ?? 0) / (float) $acuan['h']
            : null;

        return $rasioAcuan !== null && $rasioAcuan > 0 && $tinggi > 0
            && abs(($lebar / $tinggi) / $rasioAcuan - 1) <= self::TOLERANSI_RASIO_HALAMAN;
    }

    /** @return array{galat: string, potongan: array<string, string>, gagal: array<string, string>} */
    private function galat(string $kode): array
    {
        return ['galat' => $kode, 'potongan' => [], 'gagal' => []];
    }
}
