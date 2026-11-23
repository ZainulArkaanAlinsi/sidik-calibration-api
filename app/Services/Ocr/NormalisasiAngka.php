<?php

namespace App\Services\Ocr;

/**
 * Teks mentah satu sel → angka, TANPA mengubah apa yang terbaca.
 *
 * Aturan pokoknya (keputusan pemilik proyek, 23 Nov 2026): **hasil OCR tidak
 * boleh diubah agar cocok dengan templat, rentang yang diharapkan, atau pola
 * angka tertentu.** Kelas ini hanya boleh MENERIMA teks yang memang sudah berupa
 * angka, atau MENOLAK dan menandainya ragu. Teks mentah tidak pernah ditimpa;
 * penolakan membawa alasan, dan pemanggil menyimpan `teks_mentah` apa adanya.
 *
 * Yang SENGAJA tidak dilakukan (semuanya pernah ada sampai 23 Nov 2026 dan
 * dicabut, lihat `docs/ocr-tanpa-koreksi-otomatis.md`; audit lengkapnya
 * `docs/ocr-audit-koreksi-otomatis.md`):
 *  - mengganti huruf atau simbol yang mirip digit (`O`→0, `S`→5, `|`→1, ...):
 *    itu tebakan, dan menghasilkan angka yang terlihat benar padahal mungkin
 *    salah; garis tabel (`|`, `/`) pernah menjadi angka 1;
 *  - memilih antara titik desimal dan pemisah ribuan memakai rentang atau jumlah
 *    desimal dari templat: itu menjadikan nilai yang diharapkan sebagai patokan
 *    pemilihan hasil;
 *  - membuang pemisah ribuan, dan membuang spasi di tengah angka ("12 .5"):
 *    menghapus karakter agar teks menjadi angka;
 *  - menyisipkan atau menggeser koma agar angkanya "masuk akal" ("133659" di
 *    titik 1,33659 adalah tanda salah baca, bukan izin menaruh koma).
 *
 * Yang masih dilakukan, dan alasannya: (1) spasi di AWAL/AKHIR teks dipangkas
 * (tidak mengubah isinya); (2) teks yang sudah berupa angka murni dibaca apa
 * adanya; satu pemisah desimal (`,` atau `.`) diterima sebagai desimal, kecuali
 * bentuknya memang ambigu secara leksikal ("1,413": desimal atau ribuan), yang
 * ditolak sebagai ambigu, bukan dipilihkan. Semua penolakan hanya MENOLAK.
 *
 * Titik vs koma: bagian utuh yang diawali 0 ("0,112") tidak mungkin kelompok
 * ribuan, jadi jelas desimal. Itu aturan bentuk teks, bukan bukti dari templat.
 */
class NormalisasiAngka
{
    /**
     * @param  array{izinkan_minus?: bool, maks_digit?: int}  $aturan  kunci lain (desimal, min, maks, ...) tidak dipakai di sini
     * @return array{
     *     ok: bool,
     *     kosong: bool,
     *     nilai: float|null,
     *     teks_normal: string|null,
     *     substitusi: int,
     *     catatan: list<string>,
     *     alasan: list<string>
     * }
     */
    public function proses(?string $teks, array $aturan = []): array
    {
        $bersih = $this->pangkas((string) $teks);

        if ($bersih === '') {
            // Sel kosong itu SAH di lembar kerja ini — teknisi sering nggak bisa
            // ngisi satu-dua kolom di lapangan. Kosong bukan kegagalan baca.
            return $this->hasil(true, true, null, null, []);
        }

        if (preg_match('/[\s\x{00A0}\x{2007}\x{202F}]/u', $bersih) === 1) {
            // Spasi di tengah = dua pembacaan nyambung, atau OCR menyisipkan
            // spasi. Menyambungnya diam-diam menghapus karakter, jadi ragu.
            return $this->hasil(false, false, null, null, ['spasi_di_dalam_angka']);
        }

        $whitelist = (string) config('ocr.angka.whitelist', '0123456789,.-');

        if (! $this->hanyaKarakter($bersih, $whitelist)) {
            // Huruf/simbol BUKAN angka. Tidak ada penggantian ke digit apa pun.
            return $this->hasil(false, false, null, null, ['karakter_di_luar_whitelist']);
        }

        [$bersih, $negatif, $alasanTanda] = $this->pisahkanTanda($bersih, (bool) ($aturan['izinkan_minus'] ?? false));

        if ($alasanTanda !== null) {
            return $this->hasil(false, false, null, null, [$alasanTanda]);
        }

        if (! preg_match('/^[0-9.,]+$/', $bersih)) {
            return $this->hasil(false, false, null, null, ['bentuk_angka_tidak_wajar']);
        }

        $maksDigit = (int) ($aturan['maks_digit'] ?? config('ocr.angka.maks_digit', 9));

        if (strlen(preg_replace('/[^0-9]/', '', $bersih) ?? '') > $maksDigit) {
            // Kepanjangan buat lembar kerja mana pun di lab ini — hampir selalu
            // dua sel yang kebaca nyambung jadi satu.
            return $this->hasil(false, false, null, null, ['digit_kebanyakan']);
        }

        [$terpilih, $alasanTafsir] = $this->tafsirkan($bersih);

        if ($terpilih === null) {
            return $this->hasil(false, false, null, null, [$alasanTafsir ?? 'pemisah_desimal_tidak_wajar']);
        }

        $nilai = (float) $terpilih['nilai'];

        return $this->hasil(
            true,
            false,
            $negatif ? -$nilai : $nilai,
            ($negatif ? '-' : '').$terpilih['teks'],
            [],
        );
    }

    /** Hanya spasi di awal dan akhir yang dipangkas (termasuk NBSP); isi teks tidak disentuh. */
    private function pangkas(string $teks): string
    {
        return preg_replace('/^[\s\x{00A0}\x{2007}\x{202F}]+|[\s\x{00A0}\x{2007}\x{202F}]+$/u', '', $teks) ?? '';
    }

    private function hanyaKarakter(string $teks, string $whitelist): bool
    {
        $izin = mb_str_split($whitelist);

        foreach (mb_str_split($teks) as $karakter) {
            if (! in_array($karakter, $izin, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Minus cuma sah di depan, dan cuma buat kolom yang emang boleh negatif.
     *
     * @return array{0: string, 1: bool, 2: string|null}
     */
    private function pisahkanTanda(string $teks, bool $izinkanMinus): array
    {
        $negatif = str_starts_with($teks, '-');

        if ($negatif) {
            $teks = substr($teks, 1);
        }

        if (str_contains($teks, '-')) {
            // Minus di tengah = dua sel kebaca nyambung ("4,01-4,02") atau garis
            // tabel kebaca sebagai karakter.
            return ['', false, 'minus_di_tengah'];
        }

        if ($negatif && ! $izinkanMinus) {
            return ['', false, 'minus_tidak_diizinkan'];
        }

        return [$teks, $negatif, null];
    }

    /**
     * Tafsiran angka dari susunan titik/koma, HANYA dari bentuk teksnya.
     *
     *  - tanpa pemisah: bilangan bulat;
     *  - satu pemisah: desimal. Kecuali tepat tiga digit sesudahnya DAN bagian
     *    utuh bisa berupa kelompok ribuan (1-3 digit, tidak diawali 0): "1,413"
     *    bisa 1,413 atau 1413. Itu `desimal_ambigu` dan TIDAK dipilihkan;
     *  - lebih dari satu pemisah yang bentuknya pemisah ribuan ("1.234,56",
     *    "1.234.567"): `pemisah_ribuan_tidak_didukung` — pemisah tidak dibuang;
     *  - bentuk lain ("4..01", "1.2.3"): `pemisah_desimal_tidak_wajar`.
     *
     * @return array{0: array{nilai: string, teks: string, desimal: int}|null, 1: string|null}
     */
    private function tafsirkan(string $teks): array
    {
        $jumlahPemisah = (int) preg_match_all('/[.,]/', $teks);

        if ($jumlahPemisah === 0) {
            return [$this->kandidat($teks, ''), null];
        }

        if ($jumlahPemisah === 1) {
            [$utuh, $pecahan] = preg_split('/[.,]/', $teks, 2);

            if ($utuh === '' || $pecahan === '') {
                return [null, 'pemisah_desimal_tidak_wajar'];
            }

            if (strlen($pecahan) === 3 && preg_match('/^[1-9][0-9]{0,2}$/', $utuh) === 1) {
                return [null, 'desimal_ambigu'];
            }

            return [$this->kandidat($utuh, $pecahan), null];
        }

        if (preg_match('/^[1-9][0-9]{0,2}(?:[.,][0-9]{3})+(?:[.,][0-9]+)?$/', $teks) === 1) {
            return [null, 'pemisah_ribuan_tidak_didukung'];
        }

        return [null, 'pemisah_desimal_tidak_wajar'];
    }

    /**
     * @return array{nilai: string, teks: string, desimal: int}
     */
    private function kandidat(string $utuh, string $pecahan): array
    {
        $utuh = $utuh === '' ? '0' : $utuh;

        return [
            'nilai' => $pecahan === '' ? $utuh : $utuh.'.'.$pecahan,
            // Ditulis balik pakai koma — itu yang dilihat teknisi di kertas,
            // jadi itu yang ditampilin waktu dia bandingin sama crop aslinya.
            'teks' => $pecahan === '' ? $utuh : $utuh.','.$pecahan,
            'desimal' => strlen($pecahan),
        ];
    }

    /**
     * `substitusi` dan `catatan` dipertahankan di bentuk keluaran supaya
     * pemanggil dan kontrak tidak patah, tetapi SELALU 0 / kosong: tidak ada
     * lagi penggantian karakter.
     *
     * @param  list<string>  $alasan
     * @return array{
     *     ok: bool, kosong: bool, nilai: float|null, teks_normal: string|null,
     *     substitusi: int, catatan: list<string>, alasan: list<string>
     * }
     */
    private function hasil(bool $ok, bool $kosong, ?float $nilai, ?string $teksNormal, array $alasan): array
    {
        return [
            'ok' => $ok,
            'kosong' => $kosong,
            'nilai' => $nilai,
            'teks_normal' => $teksNormal,
            'substitusi' => 0,
            'catatan' => [],
            'alasan' => array_values($alasan),
        ];
    }
}
