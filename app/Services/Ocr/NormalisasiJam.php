<?php

namespace App\Services\Ocr;

/**
 * Sel JAM hasil OCR — baris `Time` di lembar Autoklaf (`SIDIK-FM-CAL-0539_Rev.4`,
 * kertasnya mencetak `____:____:____:____`).
 *
 * Dipisah dari [NormalisasiAngka] karena jam BUKAN angka desimal, dan
 * memaksanya lewat jalur angka bukan cuma nggak rapi — itu salah diam-diam:
 * `02:00:00` yang titik duanya dibuang keluar sebagai `20000`, dan angka itu
 * lolos semua penjagaan rentang karena sel jam nggak punya rentang.
 *
 * ## Sel jam = TEKS yang dikonfirmasi teknisi (keputusan pemilik, 23 Nov 2026)
 *
 * Hasil OCR tidak boleh diubah agar cocok dengan pola. Format kertas Autoklaf
 * (empat kelompok di kertas, tiga di master) belum dipastikan pemilik lab, lihat
 * `docs/pertanyaan-lab-autoklaf-format-jam.md`. Karena itu kelas ini TIDAK
 * menormalisasi: bacaan yang hanya berisi digit dan `:` diterima APA ADANYA,
 * dan `ValidasiSel` menurunkannya ke kuning (tulisan tangan, dikonfirmasi mata
 * teknisi). Selain itu ditolak (merah) dengan teks mentah tersimpan.
 *
 * Yang SENGAJA tidak dilakukan lagi (semuanya ada sampai 23 Nov 2026):
 *  - mengganti huruf mirip digit (`O`→0, `l`→1, `S`→5, `B`→8, ...);
 *  - mengganti pemisah `. ; - / spasi` menjadi `:`;
 *  - menyisipkan titik dua pada "020000" atau "0200";
 *  - menambah detik ("12:30" menjadi "12:30:00") atau nol di depan ("2:5" menjadi "02:05").
 *
 * Yang masih dilakukan, semuanya hanya MENOLAK: karakter selain digit dan `:`,
 * bentuk yang tidak masuk akal (kelompok kosong, kelompok lebih dari dua digit,
 * lebih dari empat kelompok), dan untuk bentuk dua atau tiga kelompok batas jam
 * 0-23, menit/detik 0-59 (aturan lama; apakah ada durasi di atas 23 jam
 * ditanyakan ke pemilik lab). Bentuk empat kelompok TIDAK diberi batas karena arti
 * kelompoknya belum diketahui.
 *
 * Yang dikeluarkan bentuknya sama persis kayak [NormalisasiAngka::proses] biar
 * [ValidasiSel] nggak perlu tahu bedanya — kecuali `nilai` yang SELALU null:
 * jam nggak punya nilai numerik yang boleh disimpan sebagai desimal.
 */
class NormalisasiJam
{
    /**
     * @param  array<string, mixed>  $aturan  tidak dipakai (batas digit tidak lagi diambil dari aturan sel)
     * @return array{
     *     ok: bool, kosong: bool, nilai: float|null, teks_normal: string|null,
     *     substitusi: int, catatan: list<string>, alasan: list<string>
     * }
     */
    public function proses(?string $teks, array $aturan = []): array
    {
        // Hanya spasi di awal dan akhir yang dipangkas; isi teks tidak disentuh.
        $bersih = preg_replace('/^[\s\x{00A0}\x{2007}\x{202F}]+|[\s\x{00A0}\x{2007}\x{202F}]+$/u', '', (string) $teks) ?? '';

        if ($bersih === '') {
            // Sel kosong itu sah — sama kayak sel angka. Teknisi sering baru
            // ngisi jam sesudah siklusnya selesai.
            return $this->hasil(true, true, null, []);
        }

        if (preg_match('/[^0-9:]/u', $bersih) === 1) {
            // Huruf, simbol, titik, strip, spasi di tengah: bukan jam yang bisa
            // diterima apa adanya. Tidak ada penggantian ke digit atau `:`.
            return $this->hasil(false, false, null, ['karakter_di_luar_whitelist']);
        }

        $kelompok = explode(':', $bersih);
        $bentukSah = count($kelompok) <= 4 && ! in_array('', $kelompok, true)
            && count(array_filter($kelompok, static fn (string $g): bool => strlen($g) > 2)) === 0;

        // Deret digit tanpa titik dua ("0200", "020000") diterima apa adanya
        // sebagai teks (maksimal delapan digit); TIDAK disisipi pemisah.
        $tanpaPemisah = ! str_contains($bersih, ':') && strlen($bersih) <= 8;

        if (! $bentukSah && ! $tanpaPemisah) {
            return $this->hasil(false, false, null, ['bentuk_jam_tidak_wajar']);
        }

        // Batas hanya untuk bentuk dua atau tiga kelompok (jam:menit[:detik]).
        if (str_contains($bersih, ':') && in_array(count($kelompok), [2, 3], true)) {
            [$jam, $menit] = [(int) $kelompok[0], (int) $kelompok[1]];
            $detik = (int) ($kelompok[2] ?? 0);

            if ($jam > 23 || $menit > 59 || $detik > 59) {
                return $this->hasil(false, false, null, ['jam_di_luar_rentang']);
            }
        }

        return $this->hasil(true, false, $bersih, []);
    }

    /**
     * `substitusi` dan `catatan` dipertahankan di bentuk keluaran supaya
     * pemanggil dan kontrak tidak patah, tetapi SELALU 0 / kosong: tidak ada
     * lagi penggantian atau penyisipan.
     *
     * @param  list<string>  $alasan
     * @return array{
     *     ok: bool, kosong: bool, nilai: float|null, teks_normal: string|null,
     *     substitusi: int, catatan: list<string>, alasan: list<string>
     * }
     */
    private function hasil(bool $ok, bool $kosong, ?string $teksNormal, array $alasan): array
    {
        return [
            'ok' => $ok,
            'kosong' => $kosong,
            // Jam nggak pernah punya nilai desimal. Lihat docblock kelas.
            'nilai' => null,
            'teks_normal' => $teksNormal,
            'substitusi' => 0,
            'catatan' => [],
            'alasan' => array_values($alasan),
        ];
    }
}
