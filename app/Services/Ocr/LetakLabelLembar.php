<?php

namespace App\Services\Ocr;

/**
 * Di mana label tercetak duduk di lembar kerja — SATU sumber buat dua
 * pemakainya.
 *
 * `CetakLembarKerjaOcr` menggambar labelnya di kertas; `BuatRangkaGeometriOcr`
 * menulis kotak jangkar ke berkas geometri, dan kotak itu yang dipotong HP buat
 * dibaca ulang. Dua tempat yang menghitung sendiri-sendiri berarti dua
 * kesempatan geser: begitu salah satunya diubah, kotak yang dipotong HP nggak
 * lagi menaungi tulisan yang tercetak, dan jangkarnya berubah dari penjaga
 * jadi sumber penolakan.
 *
 * Yang dihitung di sini cuma LETAKNYA. Teksnya milik pemanggil — di lembar
 * bentuk pH label kirinya nilai standar (`4,00`) dan label atasnya nomor
 * Repeat (`X1`); di lembar Conductivity peran keduanya persis kebalikan.
 */
class LetakLabelLembar
{
    /**
     * Tinggi satu baris label di 200 dpi.
     *
     * Dipakai dua-duanya: menaruh label kiri persis di tengah selnya, DAN
     * menentukan tinggi kotak jangkar yang dipotong HP.
     *
     * Ukurannya ikut label JANGKAR (16 pt), bukan label biasa (8 pt) — dan
     * bukan buat gaya. Jangkar satu-satunya tulisan di lembar ini yang dibaca
     * mesin, dan ukurannya diadu ke HP fisik (14 Agt 2026):
     *
     *   8 pt          → `X-` `X?` `X` `Xe` `XE`  (angkanya hilang semua)
     *   14 pt tebal   → `X1` `X2` `X3` `X4` `XE` (tinggal `5` yang jatuh)
     *
     * 16 pt = 16/72 inci × 200 dpi = 44,4 px badan huruf; dibulatkan ke atas
     * plus jarak antar baris. **Kotak yang lebih pendek dari tulisannya bikin
     * hurufnya kepotong pas dibaca**, jadi angka ini WAJIB ikut kalau
     * `.label-jangkar` di tampilannya diubah.
     */
    public const TINGGI_TEKS = 52;

    /**
     * Napas di sekeliling kotak jangkar.
     *
     * Kotak yang dipotong HP dikasih margin ke DALAM (`PindaiLembar.potongSel`,
     * 6%) supaya garis kotak sel nggak ikut kebaca sebagai angka. Kalau kotak
     * jangkarnya dibikin sepersis tulisannya, margin itu memotong badan
     * hurufnya — `X1` kepotong jadi `1`, dan jangkarnya menolak lembar yang
     * sebenarnya benar.
     */
    private const NAPAS = 6;

    /**
     * Label di KIRI tiap baris, rata kanan menempel ke grid.
     *
     * @param  array<string, array<string, mixed>>  $sel  kunci sel → kotaknya
     * @param  int  $indeks  bagian kunci yang jadi nomor baris (1 = titik ukur, 2 = Repeat)
     * @param  int  $kiri  margin kiri halaman
     * @param  int  $jarak  jarak label ke garis sel di kanannya
     * @return array<int, array{x: int, y: int, w: int, h: int}> nomor baris → kotak
     */
    public function perBaris(array $sel, int $indeks, int $kiri, int $jarak): array
    {
        $perBaris = [];

        foreach ($this->kotakPerNomor($sel, $indeks) as $nomor => $kotak) {
            $perBaris[$nomor] = [
                'x' => $kiri,
                // Ketengahin beneran, bukan `h / 3`: `h / 3` kebetulan pas
                // waktu selnya 81 px dan makin melenceng makin tinggi selnya.
                'y' => (int) round($kotak['y'] + max(0, ($kotak['h'] - self::TINGGI_TEKS) / 2)),
                // Berhenti sebelum sel pertama.
                'w' => max(0, (int) $kotak['x'] - $kiri - $jarak),
                'h' => self::TINGGI_TEKS,
            ];
        }

        return $perBaris;
    }

    /**
     * Label yang MEMAYUNGI satu kelompok kolom, di atas gridnya.
     *
     * Naik dua baris kalau di bawahnya masih ada baris nama field (pH / °C);
     * kalau satu kelompok cuma punya satu kolom, barisnya kosong dan labelnya
     * kelihatan menggantung jauh di atas grid.
     *
     * @param  array<string, array<string, mixed>>  $sel  kunci sel → kotaknya
     * @param  int  $indeks  bagian kunci yang jadi kelompok kolom
     * @param  int  $jarakBaris  tinggi satu baris kepala tabel
     * @return array<int, array{x: int, y: int, w: int, h: int}> nomor kelompok → kotak
     */
    public function perKelompokKolom(array $sel, int $indeks, int $jarakBaris): array
    {
        $kelompok = [];

        foreach ($sel as $kunci => $kotak) {
            $bagian = $this->bagian($kunci);

            if ($bagian === null) {
                continue;
            }

            $nomor = (int) $bagian[$indeks];

            $kelompok[$nomor]['kiri'] = min($kelompok[$nomor]['kiri'] ?? PHP_INT_MAX, $kotak['x']);
            $kelompok[$nomor]['kanan'] = max($kelompok[$nomor]['kanan'] ?? 0, $kotak['x'] + $kotak['w']);
            $kelompok[$nomor]['atas'] = min($kelompok[$nomor]['atas'] ?? PHP_INT_MAX, $kotak['y']);
            $kelompok[$nomor]['field'][$bagian[3]] = true;
        }

        ksort($kelompok);

        $hasil = [];

        foreach ($kelompok as $nomor => $k) {
            $baris = count($k['field']) >= 2 ? 2 : 1;

            // Naiknya paling sedikit setinggi TULISANNYA, bukan cuma satu
            // jarak baris. Jarak baris (45 px) lebih pendek dari badan huruf
            // jangkar (52 px), jadi di lembar yang satu kelompoknya cuma punya
            // satu kolom — Spectrophotometer — garis atas grid lewat di tengah
            // `X1`. Kelompok berkolom dua naik dua baris, jauh di atas ambang
            // ini, jadi lembar bentuk pH nggak bergeser sepiksel pun.
            $naik = max($jarakBaris * $baris, self::TINGGI_TEKS);

            $hasil[$nomor] = [
                'x' => (int) $k['kiri'],
                'y' => (int) $k['atas'] - $naik,
                'w' => (int) ($k['kanan'] - $k['kiri']),
                'h' => self::TINGGI_TEKS,
            ];
        }

        return $hasil;
    }

    /**
     * Kotak label + napasnya — bentuk yang ditulis ke berkas geometri.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $kotak
     * @return array{x: int, y: int, w: int, h: int}
     */
    public function berNapas(array $kotak): array
    {
        return [
            'x' => max(0, $kotak['x'] - self::NAPAS),
            'y' => max(0, $kotak['y'] - self::NAPAS),
            'w' => $kotak['w'] + self::NAPAS * 2,
            'h' => $kotak['h'] + self::NAPAS * 2,
        ];
    }

    /**
     * Kotak paling kiri per nomor baris.
     *
     * @param  array<string, array<string, mixed>>  $sel
     * @return array<int, array<string, mixed>>
     */
    private function kotakPerNomor(array $sel, int $indeks): array
    {
        $hasil = [];

        foreach ($sel as $kunci => $kotak) {
            $bagian = $this->bagian($kunci);

            if ($bagian === null) {
                continue;
            }

            $nomor = (int) $bagian[$indeks];

            if (! isset($hasil[$nomor]) || $kotak['x'] < $hasil[$nomor]['x']) {
                $hasil[$nomor] = $kotak;
            }
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * @return list<string>|null
     */
    private function bagian(int|string $kunci): ?array
    {
        $bagian = explode('|', (string) $kunci);

        return count($bagian) === 4 ? $bagian : null;
    }
}
