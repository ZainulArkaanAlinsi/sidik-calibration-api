<?php

namespace App\Services\Vision;

use App\Support\PengamanCitra;

/**
 * Menentukan potongan sel unggahan mana yang BOLEH dikirim ke Google, untuk `OcrSelUnggahController`
 * (`POST /api/ocr/sel-awan`). Logika murni: tidak menyentuh database, cache, atau jaringan.
 *
 * ## Kunci (gagal-tutup)
 *
 * Klien menyebut kunci sel; server mencocokkannya ke templat yang DISUSUN SERVER. Sebuah potongan hanya
 * lolos bila kuncinya ada di `template['sel']` DAN barisnya bertipe `angka` di `template['tabel']`.
 * Kunci `isian|...`, `centang|...`, baris `jam`, kunci yang tidak ada di templat, dan baris tanpa tipe
 * yang dikenal TIDAK lolos. Identitas, alamat, nomor seri, tanda tangan, dan tanggal bukan kunci sel
 * templat sama sekali, jadi tidak bisa dipilih lewat jalur ini.
 *
 * ## Isi potongan
 *
 * Ukuran berkas dan piksel dibatasi (`config('ocr.awan_sel')`), supaya "potongan sel" tidak bisa berupa
 * foto halaman. Piksel dibaca dari HEADER lewat `PengamanCitra` (satu-satunya tempat decode); potongan
 * TIDAK didekode di sini. Hanya JPEG dan PNG (dicek dari byte awal berkas, bukan dari nama atau tipe MIME
 * kiriman klien).
 */
final class PemilahPotonganSel
{
    /**
     * `tabel_id|baris_ke` bertipe `angka` ada di `angka`; semua kunci sel templat ada di `ada`.
     *
     * @param  array<string, mixed>  $template  keluaran `TemplateLembarKerja` / `FormulirAsli`
     * @return array{angka: array<string, true>, ada: array<string, true>}
     */
    public function petaKunci(array $template): array
    {
        $tipeBaris = [];

        foreach ((array) ($template['tabel'] ?? []) as $tabel) {
            if (! is_array($tabel) || ! isset($tabel['tabel_id'])) {
                continue;
            }

            foreach ((array) ($tabel['baris'] ?? []) as $baris) {
                if (is_array($baris) && isset($baris['baris_ke'], $baris['tipe']) && is_string($baris['tipe'])) {
                    $tipeBaris[(string) $tabel['tabel_id'].'|'.(int) $baris['baris_ke']] = $baris['tipe'];
                }
            }
        }

        $angka = [];
        $ada = [];

        foreach ((array) ($template['sel'] ?? []) as $kunci => $sel) {
            if (! is_array($sel)) {
                continue;
            }

            $kunciSel = (string) ($sel['kunci'] ?? $kunci);
            $ada[$kunciSel] = true;

            $tipe = $tipeBaris[(string) ($sel['tabel_id'] ?? '').'|'.(int) ($sel['baris_ke'] ?? 0)] ?? null;

            if ($tipe === 'angka') {
                $angka[$kunciSel] = true;
            }
        }

        return ['angka' => $angka, 'ada' => $ada];
    }

    /**
     * Kenapa kunci ini TIDAK boleh dikirim; null = boleh.
     *
     * @param  array{angka: array<string, true>, ada: array<string, true>}  $peta
     */
    public function alasanKunci(string $kunci, array $peta): ?string
    {
        $kepala = explode('|', $kunci, 2)[0];

        if ($kepala === 'isian') {
            return 'isian_tidak_dikirim';
        }

        if ($kepala === 'centang') {
            return 'centang_tidak_dikirim';
        }

        if (! isset($peta['ada'][$kunci])) {
            return 'tidak_ada_di_templat';
        }

        return isset($peta['angka'][$kunci]) ? null : 'bukan_sel_angka';
    }

    /** Dicek SEBELUM berkasnya dibaca, supaya unggahan raksasa tidak dimuat ke memori. */
    public function alasanUkuran(int $byte): ?string
    {
        if ($byte <= 0) {
            return 'potongan_bukan_citra';
        }

        return $byte > $this->maksByte() ? 'potongan_kebesaran_byte' : null;
    }

    /** Kenapa isi berkas ini tidak boleh dikirim; null = boleh. Hanya membaca byte awal dan header. */
    public function alasanIsi(string $isi): ?string
    {
        if (! $this->jpegAtauPng($isi)) {
            return 'potongan_bukan_citra';
        }

        return match (PengamanCitra::tolak($isi, $this->maksPiksel())) {
            null => null,
            PengamanCitra::TERLALU_BESAR => 'potongan_kebesaran_piksel',
            default => 'potongan_bukan_citra',
        };
    }

    public function maksByte(): int
    {
        return max(1, (int) config('ocr.awan_sel.maks_byte_potongan', 307_200));
    }

    public function maksPiksel(): int
    {
        return max(1, (int) config('ocr.awan_sel.maks_piksel_potongan', 500_000));
    }

    private function jpegAtauPng(string $isi): bool
    {
        return str_starts_with($isi, "\xFF\xD8\xFF") || str_starts_with($isi, "\x89PNG\r\n\x1A\n");
    }
}
