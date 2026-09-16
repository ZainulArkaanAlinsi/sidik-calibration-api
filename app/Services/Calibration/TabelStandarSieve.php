<?php

namespace App\Services\Calibration;

use DateTimeInterface;
use RuntimeException;

/**
 * Tabel standar **Sieve Mesh**, dibaca dari `database/data/tabel-standar-sieve.json`.
 *
 * ## Nominal sieve itu KUNCI PASTI — master menjepretnya diam-diam
 *
 * `INPUT DATA!Z16` master mencari ukuran Tabel_MPE TERDEKAT ke nominal
 * (`INDEX(...; MATCH(MIN(ABS(r−x)); ABS(r−x); 0))`), lalu seluruh hitungan dan
 * vonis memakai ukuran jepretan itu. Nominal 19,5 mm dinilai dengan batas
 * 20 mm, 150 mm dengan batas 125 mm — tanpa satu pun pesan. [barisMpe]
 * mencocokkan PERSIS dan memulangkan `null` untuk yang tidak ada.
 *
 * Nominal inch dicocokkan ke KOLOM INCH, bukan dikalikan 25,4 lalu dicari di
 * kolom mm: 3/4" adalah penanda alternatif ASTM untuk sieve 19,0 mm, dan
 * 0,75 × 25,4 = 19,05 tidak ada di kolom mm. Batas toleransinya milik ukuran
 * standar 19,0 mm.
 *
 * ## Baris yang DITANDAI tidak dipakai
 *
 * Generator menandai kolom yang bertentangan dengan barisnya sendiri (0,080 mm
 * Ø kawat preferred 0,56 di luar pita 0,048–0,064; 1 mm tertulis 18000 µm;
 * 10 mm tertulis 0,279 inch) — tidak dibetulkan, karena angka yang benar
 * keputusan lab. [kolomJanggalTerpakai] memulangkan kolom janggal yang benar-
 * benar dipakai hitungan untuk satuan itu; pemanggil memblokir.
 *
 * ## Kolom koreksi yang BENAR
 *
 * `koreksi.*` di JSON dibaca dari kolom `Koreksi` (N / F). Master memakai
 * `VLOOKUP(indeks; Koreksi_*; 3; 0)` yang menunjuk kolom M / E — KOSONG — jadi
 * koreksi standar di workbook selalu 0. Lihat `SieveCalculator`.
 */
class TabelStandarSieve
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** Kolom tampilan yang dipakai pencocokan per satuan; kolom kawat selalu dipakai. */
    private const KOLOM_TERPAKAI = [
        'mm' => ['kawat_preferred_mm', 'kawat_min_max'],
        'µm' => ['kawat_preferred_mm', 'kawat_min_max'],
        'inch' => ['ukuran_inch', 'kawat_preferred_mm', 'kawat_min_max'],
    ];

    /**
     * Baris Tabel_MPE untuk nominal dalam satuan alat, atau `null` kalau tidak
     * ada yang cocok PERSIS.
     *
     * @return array<string, mixed>|null
     */
    public function barisMpe(float $nominal, string $satuan): ?array
    {
        foreach (self::muat()['mpe'] as $baris) {
            if ($satuan === 'inch') {
                if (is_numeric($baris['ukuran_inch']) && abs((float) $baris['ukuran_inch'] - $nominal) < 1e-9) {
                    return $baris;
                }

                continue;
            }

            $mm = $nominal * ($satuan === 'µm' ? 0.001 : 1.0);

            // Toleransi 1e-9 mm: nominal µm dikalikan 0,001 dalam biner (45 µm
            // → 0.045000000000000005), dan `===` menolak sieve yang sah.
            if (abs((float) $baris['ukuran_mm'] - $mm) < 1e-9) {
                return $baris;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $baris
     * @return list<string>
     */
    public function kolomJanggalTerpakai(array $baris, string $satuan): array
    {
        return array_values(array_intersect(
            (array) ($baris['tanda'] ?? []),
            self::KOLOM_TERPAKAI[$satuan] ?? self::KOLOM_TERPAKAI['mm'],
        ));
    }

    /**
     * Titik tabel koreksi standar yang nilai standarnya TERDEKAT ke rata-rata
     * (mm), meniru array formula `PERHITUNGAN!G81` — seri jarak dimenangkan
     * kemunculan pertama, persis `MATCH(...; 0)`.
     *
     * Dua hal yang tidak ditiru: cabang mikroskop master memakai konstanta
     * 0,0102 untuk semua ukuran (bukan titik terdekat), dan `L81` (kawat)
     * menyapu cuma sembilan dari sepuluh baris caliper. Keduanya salin-tempel.
     *
     * @return array{nilai_standar_mm: float, penunjukan_mm: float, koreksi_mm: float}
     */
    public function titikKoreksi(string $standar, string $parameter, float $rataMm): array
    {
        $tabel = self::muat()['koreksi'][$this->kunciKoreksi($standar, $parameter)];
        $terbaik = null;

        foreach ($tabel as $titik) {
            if ($terbaik === null
                || abs((float) $titik['nilai_standar_mm'] - $rataMm) < abs((float) $terbaik['nilai_standar_mm'] - $rataMm)) {
                $terbaik = $titik;
            }
        }

        return $terbaik;
    }

    /** U95 sertifikat standar (mm) untuk parameter itu — `N11` budget master. */
    public function u95Sertifikat(string $standar, string $parameter): float
    {
        return (float) self::muat()['u95_sertifikat_mm'][$this->kunciKoreksi($standar, $parameter)];
    }

    /**
     * Lantai CMC (mm) — `max(pita lampiran, pita master)`, atau `null` kalau
     * salah satunya tidak ada.
     *
     * Dua pita karena keduanya tidak sama: master memisah di 2 mm berdasar
     * STANDAR (mikroskop ≤ 2 mm → 4,33 µm; caliper > 2 mm → 0,02 mm), lampiran
     * LK-285-IDN memisah di 4 mm berdasar UKURAN (45–4000 µm → 4,33 µm;
     * 4–100 mm → 0,02 mm). Yang lebih besar dipakai supaya lantai tidak pernah
     * diam-diam lebih kecil dari salah satu yang sah (caliper 3 mm: lampiran
     * 4,33 µm, master 0,02 mm → 0,02 mm).
     *
     * `null` bila di luar lampiran (ukuran < 45 µm atau > 100 mm), ATAU master
     * memulangkan `"cek range"` (mikroskop > 2 mm, caliper ≤ 2 mm). Master
     * mengabaikan teks itu di `MAX()` dan menerbitkan U TANPA lantai; pemanggil
     * wajib memblokir.
     *
     * @return array{lampiran: array<string, mixed>|null, master: array<string, mixed>|null, u95_mm: float|null}
     */
    public function lantaiCmc(float $ukuranMm, string $standar): array
    {
        $lampiran = null;

        foreach (self::muat()['cmc_lampiran'] as $pita) {
            if ($ukuranMm >= (float) $pita['ukuran_min_mm'] - 1e-12 && $ukuranMm <= (float) $pita['ukuran_maks_mm'] + 1e-12) {
                $lampiran = $pita;

                break;
            }
        }

        $master = null;

        foreach (self::muat()['cmc_master'] as $pita) {
            if ($pita['standar'] !== $standar) {
                continue;
            }

            $cocok = $standar === 'mikroskop'
                ? $ukuranMm <= (float) $pita['ukuran_maks_mm']
                : $ukuranMm > (float) $pita['ukuran_min_mm_eksklusif'];

            if ($cocok) {
                $master = $pita;
            }
        }

        return [
            'lampiran' => $lampiran,
            'master' => $master,
            'u95_mm' => $lampiran !== null && $master !== null
                ? max((float) $lampiran['u95_mm'], (float) $master['u95_mm'])
                : null,
        ];
    }

    /** @return array{nama: string, merk_tipe: string, seri: string, traceability: string, tanggal_kalibrasi: string, interval_tahun: int, berlaku_sampai: string, resolusi_mm: float} */
    public function standar(string $standar): array
    {
        return self::muat()['standar'][$standar];
    }

    /**
     * Apakah standar masih berlaku pada tanggal kalibrasi SESI — bukan `NOW()`
     * seperti `DATABASE!Z13` master, yang membuat status sesi lama berubah
     * sendiri tiap kali berkasnya dibuka.
     */
    public function standarBerlaku(string $standar, DateTimeInterface $tanggal): bool
    {
        return $tanggal->format('Y-m-d') <= $this->standar($standar)['berlaku_sampai'];
    }

    /** @return array<string, mixed> */
    public function konstanta(): array
    {
        return self::muat()['konstanta'];
    }

    /** @return list<array<string, mixed>> */
    public function semuaMpe(): array
    {
        return self::muat()['mpe'];
    }

    private function kunciKoreksi(string $standar, string $parameter): string
    {
        if ($standar === 'caliper') {
            return 'caliper';
        }

        // Mikroskop: weft (y') dibaca di sumbu Y, warp & kawat di sumbu X —
        // `PERHITUNGAN!J82` memakai `Koreksi_MikroskopY`, `G82`/`L82` X.
        return $parameter === 'weft' ? 'mikroskop_y' : 'mikroskop_x';
    }

    /**
     * Tiga sel Tabel_MPE yang menyimpang dari ASTM E11, dibetulkan saat DIBACA.
     *
     * Berkas JSON-nya sengaja tetap cermin master (digenerate skrip, jangan
     * diketik tangan) — koreksinya hidup di sini supaya selisihnya kelihatan,
     * bukan hilang ke dalam data. Lab menjawab §14.9 pada 16 Sep 2026.
     *
     *   0,080 mm  Ø kawat preferred  0,56  → 0,056  (salah besar 10×)
     *   1,000 mm  kolom µm           18000 → 1000   (salin-tempel baris 18 mm)
     *   10,00 mm  kolom inch         0,279 → 0,394  (0,279 itu kolom y mm)
     *
     * Yang pertama MENGGESER ANGKA: nominal Ø kawat 0,56 mm untuk sieve 80 µm
     * mustahil — kawatnya jadi tujuh kali lebih tebal dari lubangnya.
     *
     * @var list<array{ukuran_mm: float, kolom: string, master: float, astm: float}>
     */
    private const KOREKSI_ASTM = [
        ['ukuran_mm' => 0.08, 'kolom' => 'kawat_preferred_mm', 'master' => 0.56, 'astm' => 0.056],
        ['ukuran_mm' => 1.0, 'kolom' => 'ukuran_um', 'master' => 18000.0, 'astm' => 1000.0],
        ['ukuran_mm' => 10.0, 'kolom' => 'ukuran_inch', 'master' => 0.279, 'astm' => 0.394],
    ];

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-sieve.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar sieve nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi)
            || ! isset($isi['mpe'], $isi['koreksi'], $isi['konstanta'], $isi['cmc_lampiran'], $isi['cmc_master'])
            || ! isset($isi['standar']['caliper']['berlaku_sampai'], $isi['standar']['mikroskop']['berlaku_sampai'])) {
            throw new RuntimeException("Tabel standar sieve rusak: {$berkas}");
        }

        $isi['mpe'] = array_map(static function (array $baris): array {
            foreach (self::KOREKSI_ASTM as $k) {
                $cocok = abs((float) $baris['ukuran_mm'] - $k['ukuran_mm']) < 1e-9
                    && is_numeric($baris[$k['kolom']] ?? null)
                    && abs((float) $baris[$k['kolom']] - $k['master']) < 1e-9;

                if ($cocok) {
                    $baris[$k['kolom']] = $k['astm'];
                    // Jejak, bukan penggantian diam-diam: yang membaca barisnya
                    // tetap bisa melihat angka master yang digantikan.
                    $baris['dikoreksi_astm'][$k['kolom']] = $k['master'];
                }
            }

            return $baris;
        }, $isi['mpe']);

        return self::$data = $isi;
    }
}
