<?php

namespace App\Services\Ocr;

/**
 * Normalisasi sel JAM hasil OCR — baris `Time` di lembar Autoklaf
 * (`SIDIK-FM-CAL-0539_Rev.4`, kertasnya nulis `__:__:__:__`).
 *
 * Dipisah dari [NormalisasiAngka] karena jam BUKAN angka desimal, dan
 * memaksanya lewat jalur angka bukan cuma nggak rapi — itu salah diam-diam:
 * `02:00:00` yang titik duanya dibuang keluar sebagai `20000`, dan angka itu
 * lolos semua penjagaan rentang karena sel jam nggak punya rentang.
 *
 * Yang dikeluarkan bentuknya sama persis kayak [NormalisasiAngka::proses] biar
 * [ValidasiSel] nggak perlu tahu bedanya — kecuali `nilai` yang SELALU null:
 * jam nggak punya nilai numerik yang boleh disimpan sebagai desimal.
 */
class NormalisasiJam
{
    /**
     * Karakter yang sering ketuker sama pemisah jam. Titik dua tulisan tangan
     * gampang kebaca titik, titik koma, atau strip — dan ketiganya nggak pernah
     * punya arti lain di sel ini.
     */
    private const PEMISAH = ['.', ';', '-', '/', ' '];

    /**
     * @param  array<string, mixed>  $aturan
     * @return array{
     *     ok: bool, kosong: bool, nilai: float|null, teks_normal: string|null,
     *     substitusi: int, catatan: list<string>, alasan: list<string>
     * }
     */
    public function proses(?string $teks, array $aturan = []): array
    {
        $catatan = [];

        $bersih = trim((string) $teks);

        if ($bersih === '') {
            // Sel kosong itu sah — sama kayak sel angka. Teknisi sering baru
            // ngisi jam sesudah siklusnya selesai.
            return $this->hasil(true, true, null, 0, $catatan, []);
        }

        [$bersih, $substitusi, $catatanSubstitusi] = $this->tebakDigit($bersih);
        $catatan = [...$catatan, ...$catatanSubstitusi];

        if ($substitusi > (int) config('ocr.penalti.maks_substitusi', 2)) {
            return $this->hasil(false, false, null, $substitusi, $catatan, ['terlalu_banyak_substitusi']);
        }

        $bersih = str_replace(self::PEMISAH, ':', $bersih);
        $bersih = preg_replace('/:+/', ':', $bersih) ?? '';
        $bersih = trim($bersih, ':');

        if (! preg_match('/^[0-9:]+$/', $bersih)) {
            return $this->hasil(false, false, null, $substitusi, $catatan, ['karakter_di_luar_whitelist']);
        }

        $digit = str_replace(':', '', $bersih);

        if (strlen($digit) > (int) ($aturan['maks_digit'] ?? 6)) {
            return $this->hasil(false, false, null, $substitusi, $catatan, ['terlalu_banyak_digit']);
        }

        $bagian = $this->pisah($bersih, $catatan);

        if ($bagian === null) {
            return $this->hasil(false, false, null, $substitusi, $catatan, ['bentuk_jam_tidak_wajar']);
        }

        [$jam, $menit, $detik] = $bagian;

        // Jam ditulis 0–23 di lembar ini: yang dicatat waktu pengambilan data,
        // bukan lamanya siklus. Nolak di luar itu lebih baik daripada nerima
        // `52:00:00` yang jelas salah baca satu digit.
        if ($jam > 23 || $menit > 59 || $detik > 59) {
            return $this->hasil(false, false, null, $substitusi, $catatan, ['jam_di_luar_rentang']);
        }

        return $this->hasil(
            true,
            false,
            sprintf('%02d:%02d:%02d', $jam, $menit, $detik),
            $substitusi,
            $catatan,
            [],
        );
    }

    /**
     * Pecah jadi jam/menit/detik.
     *
     * Tanpa pemisah sama sekali masih diterima (`020000`, `0200`) — titik dua
     * tulisan tangan sering tipis dan hilang waktu difoto. Tapi itu TEBAKAN,
     * jadi dicatat: `ValidasiSel` menurunkan sel yang punya catatan jadi kuning,
     * dan mata teknisi tetap lewat.
     *
     * @param  list<string>  $catatan
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function pisah(string $teks, array &$catatan): ?array
    {
        if (str_contains($teks, ':')) {
            $bagian = explode(':', $teks);

            if (count($bagian) < 2 || count($bagian) > 3) {
                return null;
            }

            foreach ($bagian as $b) {
                if ($b === '' || strlen($b) > 2) {
                    return null;
                }
            }

            return [
                (int) $bagian[0],
                (int) $bagian[1],
                (int) ($bagian[2] ?? 0),
            ];
        }

        $catatan[] = 'pemisah_jam_disisipkan';

        return match (strlen($teks)) {
            6 => [(int) substr($teks, 0, 2), (int) substr($teks, 2, 2), (int) substr($teks, 4, 2)],
            4 => [(int) substr($teks, 0, 2), (int) substr($teks, 2, 2), 0],
            default => null,
        };
    }

    /**
     * Huruf yang bentuknya mirip digit. Daftarnya sengaja lebih pendek daripada
     * [NormalisasiAngka]: sel ini isinya cuma jam, jadi yang mungkin muncul
     * cuma salah baca digit — bukan satuan, bukan tanda.
     *
     * @return array{0: string, 1: int, 2: list<string>}
     */
    private function tebakDigit(string $teks): array
    {
        $peta = ['O' => '0', 'o' => '0', 'D' => '0', 'l' => '1', 'I' => '1', '|' => '1', 'S' => '5', 'B' => '8'];

        $hasil = '';
        $substitusi = 0;
        $catatan = [];

        foreach (mb_str_split($teks) as $huruf) {
            if (isset($peta[$huruf])) {
                $hasil .= $peta[$huruf];
                $substitusi++;
                $catatan[] = "substitusi:{$huruf}→{$peta[$huruf]}";

                continue;
            }

            $hasil .= $huruf;
        }

        return [$hasil, $substitusi, $catatan];
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
        ?string $teksNormal,
        int $substitusi,
        array $catatan,
        array $alasan,
    ): array {
        return [
            'ok' => $ok,
            'kosong' => $kosong,
            // Jam nggak pernah punya nilai desimal. Lihat docblock kelas.
            'nilai' => null,
            'teks_normal' => $teksNormal,
            'substitusi' => $substitusi,
            'catatan' => array_values($catatan),
            'alasan' => array_values($alasan),
        ];
    }
}
