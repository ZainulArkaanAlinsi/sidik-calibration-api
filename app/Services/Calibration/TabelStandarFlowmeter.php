<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Tabel standar **Flowmeter Ultrasonic**, dibaca dari
 * `database/data/tabel-standar-flowmeter.json`.
 *
 * Satu berkas melayani DUA varian karena standarnya satu keping fisik: UFM
 * Krohne UFC300 S/N `A18P045140`. Sertifikatnya memuat dua tabel — `Totalizer`
 * (satuan L, 4 titik) dan `Flowrate` (satuan Lpm, 3 titik) — dan kedua master
 * lab menyimpan KEDUANYA. Generatornya mengadu keduanya baris demi baris dan
 * menolak menulis kalau berbeda; lihat `docs/skrip/gen-tabel-standar-flowmeter.py`.
 *
 * ## Pencocokan TERDEKAT, bukan interpolasi — dan tabelnya jarang
 *
 * Master memungut baris tabel lewat `INDEX(MATCH(MIN(ABS(tabel − nilai))))`,
 * lalu koreksinya dipakai UTUH. Itu ditiru di [cocokTerdekat] — mengubahnya ke
 * interpolasi menggeser angka yang sudah tercetak di sertifikat pelanggan, dan
 * itu keputusan manajer teknis.
 *
 * Tapi ditirunya sambil bersuara. Di Totalizer tabelnya rapat dan pergeserannya
 * kecil; di Flowrate tidak: bacaan **309,739 Lpm** memungut titik tabel
 * **236,147 Lpm** — jaraknya 73,6 Lpm. Koreksi −2,911 Lpm dipakai apa adanya,
 * sementara interpolasi linear antara (236,147; −2,911) dan (506,822; −6,037)
 * memberi −3,761 Lpm. Selisih 0,85 Lpm itu **22 % dari deviasi yang
 * dilaporkan**. [jarakRelatif] menyediakan angkanya supaya profil bisa
 * mengangkatnya jadi peringatan sesi; lihat `docs/pertanyaan-lab-flowmeter.md` §4.
 *
 * ## Baris kosong TIDAK ada di sini, dan itu inti keamanannya
 *
 * `std_totalizer` di master menyapu 12 baris (4 terisi), `std_flowrate` 9 baris
 * (3 terisi). Sel kosong dibaca `0` oleh `MIN(ABS(...))`, jadi ada lantai
 * diam-diam: bacaan Totalizer di bawah ~50 L lebih dekat ke `0` daripada ke
 * 100,168, `INDEX` memulangkan 0, `VLOOKUP(0)` memulangkan `#N/A`, dan
 * sertifikat mencetak `#N/A` ke pelanggan. Generatornya membuang baris kosong,
 * dan [dalamJangkauan] memulangkan `false` di luar rentang — yang WAJIB
 * diangkat pemanggil jadi titik yang diblokir dengan alasan kebaca, bukan
 * dibiarkan jadi nol.
 *
 * ## Pita CMC dibandingkan dalam PERSEN
 *
 * Lampiran akreditasi menulis CMC-nya `% of reading`, bukan satuan absolut.
 * Membandingkan lantai dalam angka absolut akan salah di kedua arah, jadi
 * [pitaCmc] memulangkan persennya dan pemanggil membandingkan
 * `U95_%OR >= CMC_%`.
 */
class TabelStandarFlowmeter
{
    public const MODE_TOTALIZER = 'totalizer';

    public const MODE_FLOWRATE = 'flowrate';

    /**
     * Ambang peringatan jarak ke titik tabel terdekat, sebagai pecahan dari
     * bacaan standar.
     *
     * Diusulkan 10 % dan BUKAN keputusan lab — `docs/pertanyaan-lab-flowmeter.md`
     * §4 menanyakan angka yang benar. Dipatok di sini, bukan disebar di profil,
     * supaya jawabannya cukup diubah di satu tempat.
     */
    public const AMBANG_JARAK_TABEL = 0.10;

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * Baris tabel standar yang PALING DEKAT ke `$nilai`, atau `null` kalau
     * tabelnya kosong atau `$nilai` bukan angka berhingga.
     *
     * @return array{standard: float, uut: float, koreksi: float, persen_of_reading: float, u: float}|null
     */
    public function cocokTerdekat(string $mode, float $nilai): ?array
    {
        if (! is_finite($nilai)) {
            return null;
        }

        $terdekat = null;
        $jarak = INF;

        foreach ($this->baris($mode) as $baris) {
            $d = abs((float) $baris['standard'] - $nilai);

            if ($d < $jarak) {
                $jarak = $d;
                $terdekat = $baris;
            }
        }

        return $terdekat === null ? null : array_map('floatval', $terdekat);
    }

    /**
     * Jarak ke titik tabel terdekat sebagai PECAHAN dari bacaan — bahan
     * peringatan sesi, bukan pemblokir.
     *
     * Balik `null` kalau tabelnya kosong atau bacaannya nol; keduanya sudah
     * ditangani gerbang lain, dan membaginya di sini cuma melahirkan `INF` yang
     * terbaca seperti temuan.
     */
    public function jarakRelatif(string $mode, float $nilai): ?float
    {
        $baris = $this->cocokTerdekat($mode, $nilai);

        if ($baris === null || $nilai == 0.0) {
            return null;
        }

        return abs($baris['standard'] - $nilai) / abs($nilai);
    }

    /**
     * Apakah `$nilai` masih di dalam jangkauan tabel — antara titik terkecil
     * dan terbesar, inklusif.
     *
     * Dipisah dari [cocokTerdekat] dengan sengaja: pencocokan terdekat SELALU
     * berhasil selama tabelnya tidak kosong, termasuk untuk bacaan 5 L yang
     * memungut baris 100,168 L. Itu persis "sel kosong dibaca nol" versi lain —
     * koreksi titik yang jauh dipakai utuh dan tidak ada yang memprotes.
     */
    public function dalamJangkauan(string $mode, float $nilai): bool
    {
        $baris = $this->baris($mode);

        if ($baris === []) {
            return false;
        }

        $standard = array_map(static fn (array $b): float => (float) $b['standard'], $baris);

        return $nilai >= min($standard) && $nilai <= max($standard);
    }

    /**
     * Pita CMC yang memuat `$nilai`, atau `null` kalau di luar KEDUA pita.
     *
     * `null` itu pemblokir, bukan izin terbit tanpa lantai: titik di luar
     * lampiran akreditasi tidak boleh terbit membawa nomor lingkup LK-285-IDN.
     * Aturannya disamakan dengan `TabelStandarMicrometer::pitaCmc()`.
     *
     * @return array{label: string, min: float, maks: float, satuan: string, cmc_persen_of_reading: float}|null
     */
    public function pitaCmc(string $mode, float $nilai): ?array
    {
        foreach (self::muat()['cmc'][$this->mode($mode)] as $pita) {
            if ($nilai >= (float) $pita['min'] && $nilai <= (float) $pita['maks']) {
                return $pita;
            }
        }

        return null;
    }

    /** @return list<array{label: string, min: float, maks: float, satuan: string, cmc_persen_of_reading: float}> */
    public function semuaPitaCmc(string $mode): array
    {
        return self::muat()['cmc'][$this->mode($mode)];
    }

    /**
     * Faktor pengali satuan `$satuan` ke satuan budget (L atau LPM), atau
     * `null` kalau satuan itu berbasis MASSA / tidak dikenal.
     *
     * `null` bukan "belum sempat": master memang tidak punya faktornya. `kg`
     * berisi teks `'perlu dibagi densitas'`, `kg/h` berisi `=S23/1000`
     * (0,0166667 — salah dimensi, itu m³/h dibagi seribu), dan `kg/min` berisi
     * `#REF!`. Satuan massa butuh densitas UUT yang diketik teknisi; tanpa itu
     * titiknya DIBLOKIR, bukan dikonversi menebak.
     */
    public function faktorSatuan(string $mode, string $satuan): ?float
    {
        $faktor = self::muat()['satuan'][$this->mode($mode)][$satuan] ?? null;

        return $faktor === null ? null : (float) $faktor;
    }

    /**
     * Apakah satuan ini berbasis massa — yaitu butuh densitas UUT sebelum bisa
     * dipakai sama sekali. Satuan yang tidak dikenal sama sekali balik `false`;
     * yang itu ditangani [faktorSatuan] dengan pesan yang berbeda.
     */
    public function satuanBerbasisMassa(string $mode, string $satuan): bool
    {
        $daftar = self::muat()['satuan'][$this->mode($mode)];

        return array_key_exists($satuan, $daftar) && $daftar[$satuan] === null;
    }

    /** @return list<string> */
    public function satuanDikenal(string $mode): array
    {
        return array_keys(self::muat()['satuan'][$this->mode($mode)]);
    }

    /**
     * Tetapan budget yang di master hidup sebagai angka telanjang di dalam
     * rumus (π = 3,14, pembagi 1,73, vi, 0,11 %, 0,3 %, 0,00021, koefisien
     * densitas Tanaka).
     *
     * @return array<string, float>
     */
    public function konstanta(): array
    {
        return array_map('floatval', self::muat()['konstanta']);
    }

    /**
     * Identitas standar — UFM dan kelima standar pendukung.
     *
     * @return array<string, array<string, mixed>>
     */
    public function standar(): array
    {
        return self::muat()['standar'];
    }

    /**
     * Standar yang berlaku untuk `$mode`. Timer cuma dipakai varian Flowrate
     * (durasi 20/40/60 detik); memasukkannya ke Totalizer berarti sesi
     * Totalizer memperingatkan standar kedaluwarsa yang tidak pernah dia pakai.
     *
     * @return array<string, array<string, mixed>>
     */
    public function standarUntuk(string $mode): array
    {
        $flowrate = $this->mode($mode) === self::MODE_FLOWRATE;

        return array_filter(
            self::muat()['standar'],
            static fn (array $s): bool => $flowrate || ($s['hanya_flowrate'] ?? false) !== true,
        );
    }

    /** @return list<array{standard: float, uut: float, koreksi: float, persen_of_reading: float, u: float}> */
    public function baris(string $mode): array
    {
        return self::muat()[$this->mode($mode) === self::MODE_TOTALIZER ? 'std_totalizer' : 'std_flowrate'];
    }

    private function mode(string $mode): string
    {
        if ($mode !== self::MODE_TOTALIZER && $mode !== self::MODE_FLOWRATE) {
            throw new RuntimeException("Mode flowmeter nggak dikenal: {$mode}");
        }

        return $mode;
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-flowmeter.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar flowmeter nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        // Kelimanya diperiksa di sini, bukan di tempat pakai: berkas yang
        // kehilangan salah satu blok meledak di tengah perhitungan satu sesi —
        // jauh dari sebabnya, dan pesannya bicara soal indeks larik.
        if (! is_array($isi)
            || ! isset($isi['std_totalizer'], $isi['std_flowrate'], $isi['konstanta'], $isi['satuan'])
            || ! isset($isi['cmc'][self::MODE_TOTALIZER], $isi['cmc'][self::MODE_FLOWRATE])
            || ! isset($isi['standar']['ufm'])) {
            throw new RuntimeException("Tabel standar flowmeter rusak: {$berkas}");
        }

        return self::$data = $isi;
    }
}
