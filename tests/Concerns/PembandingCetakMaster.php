<?php

namespace Tests\Concerns;

/**
 * Pembanding ANGKA CETAK sertifikat (HTML renderer asli `sertifikat.pdf`) ke
 * angka yang dicetak lembar `SERTIFIKAT` workbook master (PK-4, 17 Nov 2026).
 *
 * ## Bahan pembanding
 *
 * `tests/Fixtures/cetak-master/massa-volume.json`, keluaran
 * `docs/skrip/gen-cetak-master-massa-volume.py`: per baris lembar master, tiap
 * sel angka beserta teks cetaknya (nilai cache master + number_format, dibulatkan
 * ala Excel). Yang diadu ke teks itu adalah TEKS yang tercetak di sertifikat —
 * bukan nilai antara, bukan kolom `uncertainty_calculations` — karena pelanggan
 * hanya memegang yang tercetak.
 *
 * ## Cara mencocokkan
 *
 * Renderer menulis HTML; master menulis sel. Keduanya diubah ke "baris": deret
 * token angka (pemisah desimal diseragamkan ke titik; penomoran bagian "3." di
 * awal baris dibuang). Baris master dengan >= 2 angka harus sama persis dengan
 * deret token SATU baris cetak; baris master berangka tunggal (mis. `k = 2`,
 * `Weight mass 20 kg`) harus sama dengan baris cetak yang HANYA memuat angka itu
 * (kalimat `confidence level 95 % ... (K) = 2` dikecualikan). Urutan baris dijaga:
 * pencarian baris berikutnya dimulai sesudah baris yang baru cocok, jadi dua
 * baris kembar tidak bisa saling menutupi.
 *
 * ## Yang dikembalikan
 *
 * Tiap baris master berstatus `cocok`, `beda` (angka yang SAMA tidak ada di
 * cetak; membawa kandidat terdekat), `galat_master` (master sendiri mencetak
 * `#VALUE!` dll.), atau `tak_pasti` (sel `General` berpecahan — Excel memilih
 * jumlah digit menurut lebar kolom, jadi teksnya tidak ditebak). Memutuskan
 * mana yang temuan, mana yang sengaja, adalah urusan test pemanggil: pembanding
 * ini tidak punya daftar maklum.
 */
final class PembandingCetakMaster
{
    /** @var array<string, mixed>|null */
    private static ?array $fixture = null;

    /** @return array<string, mixed> */
    public static function master(string $kunci): array
    {
        self::$fixture ??= json_decode(
            (string) file_get_contents(__DIR__.'/../Fixtures/cetak-master/massa-volume.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return self::$fixture['master'][$kunci]
            ?? throw new \InvalidArgumentException("Master '{$kunci}' tidak ada di fixture cetak-master.");
    }

    /** Nilai mentah (cache master, G17) sebuah sel lembar `SERTIFIKAT`, mis. `Q22`. */
    public static function nilaiSel(string $kunci, string $sel): float
    {
        foreach (self::master($kunci)['baris'] as $b) {
            foreach ($b['sel'] as $s) {
                if ($s['sel'] === $sel && isset($s['nilai'])) {
                    return (float) $s['nilai'];
                }
            }
        }

        throw new \InvalidArgumentException("Sel {$sel} tidak ada (atau bukan angka) di master '{$kunci}'.");
    }

    /**
     * Baris master [baris => token], sudah dipilah per status.
     *
     * @param  list<int>  $barisDibuang  nomor baris lembar yang tidak ikut diadu (blok yang bukan hasil)
     * @return list<array{baris: int, sel: list<string>, token: list<string>, status: string}>
     */
    public static function barisMaster(string $kunci, array $barisDibuang = []): array
    {
        $hasil = [];

        foreach (self::master($kunci)['baris'] as $b) {
            if (in_array($b['baris'], $barisDibuang, true)) {
                continue;
            }

            $token = [];
            $status = 'ada';
            $sel = [];

            foreach ($b['sel'] as $s) {
                $sel[] = $s['sel'];

                if (isset($s['galat'])) {
                    $status = 'galat_master';

                    continue;
                }

                if ($s['cetak'] === null) {
                    $status = $status === 'galat_master' ? $status : 'tak_pasti';

                    continue;
                }

                $token[] = (string) $s['cetak'];
            }

            $hasil[] = ['baris' => $b['baris'], 'sel' => $sel, 'token' => $token, 'status' => $status];
        }

        return $hasil;
    }

    /**
     * HTML sertifikat -> baris teks beserta deret token angkanya.
     *
     * @return list<array{teks: string, token: list<string>}>
     */
    public static function barisCetak(string $html): array
    {
        $html = (string) preg_replace('#<(style|script|head)\b.*?</\1>#si', ' ', $html);
        // Pemformatan Blade menyisipkan baris baru di antara sel; satu baris cetak
        // = satu elemen blok, jadi baris baru sumber dibuang dulu.
        $html = (string) preg_replace('/\s+/u', ' ', $html);
        $html = (string) preg_replace('#</t[dh]\s*>#i', "\t", $html);
        $html = (string) preg_replace('#<br\s*/?>|</?(?:tr|div|p|li|table|thead|tbody|h[1-6]|section)\b[^>]*>#i', "\n", $html);
        $teks = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $teks = str_replace(["\u{2212}", "\u{2013}"], '-', $teks);

        $baris = [];

        foreach (preg_split('/\R/u', $teks) ?: [] as $mentah) {
            $b = trim((string) preg_replace('/\s+/u', ' ', $mentah));

            if ($b === '') {
                continue;
            }

            $baris[] = ['teks' => $b, 'token' => self::token($b)];
        }

        return $baris;
    }

    /** @return list<string> */
    public static function token(string $baris): array
    {
        // "3. ACCURACY" -> penomoran bagian, bukan angka hasil.
        $baris = (string) preg_replace('/^\d{1,2}\.\s+/u', '', $baris);
        // Pemisah desimal Indonesia -> titik; pemisah ribuan tidak dipakai di sertifikat.
        $baris = (string) preg_replace('/(?<=\d),(?=\d)/', '.', $baris);

        preg_match_all('/(?<![\p{L}\d_.])-?\d+(?:\.\d+)?(?![\p{L}\d_])/u', $baris, $m);

        return $m[0];
    }

    /**
     * @param  list<array{baris: int, sel: list<string>, token: list<string>, status: string}>  $master
     * @param  list<array{teks: string, token: list<string>}>  $cetak
     * @param  bool  $akhiran  deret angka master cukup berada di UJUNG baris cetak (kolom ID di kiri)
     * @return list<array{baris: int, sel: list<string>, token: list<string>, status: string, kandidat: ?string, indeks: ?int}>
     */
    public static function cocokkan(array $master, array $cetak, bool $akhiran = false): array
    {
        $hasil = [];
        $dari = 0;

        foreach ($master as $b) {
            $b['kandidat'] = null;
            $b['indeks'] = null;

            if ($b['status'] !== 'ada' || $b['token'] === []) {
                $hasil[] = $b;

                continue;
            }

            $ketemu = null;

            for ($i = $dari; $i < count($cetak); $i++) {
                if (self::sama($b['token'], $cetak[$i]['token'], $akhiran)) {
                    $ketemu = $i;
                    break;
                }
            }

            if ($ketemu !== null) {
                $b['status'] = 'cocok';
                $b['indeks'] = $ketemu;
                $dari = $ketemu + 1;
            } else {
                $b['status'] = 'beda';
                $b['kandidat'] = self::kandidat($b['token'], $cetak);
            }

            $hasil[] = $b;
        }

        return $hasil;
    }

    /**
     * @param  list<string>  $master
     * @param  list<string>  $cetak
     */
    private static function sama(array $master, array $cetak, bool $akhiran): bool
    {
        if (count($master) === 1) {
            // Angka tunggal (tare, LOP, beban eksentrisitas, `k`): barisnya harus
            // memuat HANYA angka itu — kalau tidak, `0,0001` di baris lain yang
            // kebetulan berujung sama bisa mengambil tempatnya dan menggeser
            // urutan pencarian. Satu pengecualian: kalimat "confidence level
            // 95 % ... (K) = 2" memuat `95` di depan angka `k`-nya.
            return $cetak === [$master[0]] || (count($cetak) === 2 && $cetak[0] === '95' && $cetak[1] === $master[0]);
        }

        if ($akhiran) {
            // Kolom di kiri angka hasil (mis. `ID Number` "AT-100-1") boleh
            // membawa angkanya sendiri; yang dituntut: deret angka hasil
            // berada PERSIS di ujung baris.
            return count($cetak) >= count($master) && array_slice($cetak, -count($master)) === $master;
        }

        return $master === $cetak;
    }

    /**
     * Baris cetak yang paling banyak berbagi angka dengan baris master — supaya
     * pesan gagalnya menunjuk selisihnya, bukan cuma "tidak ketemu".
     *
     * @param  list<string>  $token
     * @param  list<array{teks: string, token: list<string>}>  $cetak
     */
    private static function kandidat(array $token, array $cetak): ?string
    {
        $terbaik = null;
        $skorTerbaik = 0;

        foreach ($cetak as $c) {
            $skor = count(array_intersect($token, $c['token']));

            if ($skor > $skorTerbaik) {
                $skorTerbaik = $skor;
                $terbaik = $c['teks'];
            }
        }

        return $terbaik;
    }

    /**
     * Ringkas hasil pencocokan jadi teks yang enak dibaca di pesan gagal.
     *
     * @param  list<array{baris: int, sel: list<string>, token: list<string>, status: string, kandidat: ?string}>  $hasil
     * @param  list<string>  $status
     */
    public static function uraikan(array $hasil, array $status): string
    {
        $baris = [];

        foreach ($hasil as $b) {
            if (! in_array($b['status'], $status, true)) {
                continue;
            }

            $baris[] = sprintf(
                'baris %d [%s] master: %s | cetak terdekat: %s',
                $b['baris'],
                implode(',', $b['sel']),
                implode(' ', $b['token']),
                $b['kandidat'] ?? '-',
            );
        }

        return implode("\n", $baris);
    }
}
