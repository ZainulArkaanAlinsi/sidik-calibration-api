<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;
use DateTimeInterface;

/**
 * Mesin hitung **Sieve Mesh** (lampiran akreditasi LK-285-IDN no. 33 "Sieve",
 * metode SIDIK-IK-CAL-0526, ASTM E11 / ISO 3310-1).
 *
 * Satu workbook master (`Master Olah Data_Sieve Mesh.xlsm`, password
 * `spirit285`). Rumusnya dibuktikan di Python lebih dulu — 189 sel diadu,
 * 189 cocok — lalu dijaga `SieveMasterTest` komponen demi komponen.
 *
 * ## Bentuknya: TIGA budget per sesi, bukan satu per titik
 *
 * Satu sesi = sampai 100 opening, masing-masing tiga angka (warp x', weft y',
 * Ø kawat). `PERHITUNGAN U95%` punya tiga blok yang strukturnya identik — satu
 * per parameter — dan sertifikat mencetak tiga baris hasil. Jadi tiga grup
 * `uncertainty_calculations` (`titik_ke` 1/2/3), bukan seratus.
 *
 *     rata-rata   = AVERAGE(opening terisi)           — dari nilai MENTAH
 *     terkoreksi  = rata-rata + koreksi standar di titik tabel terdekat
 *     deviasi     = terkoreksi − nominal parameter    (warp/weft: nominal sieve;
 *                                                      kawat: Ø kawat preferred)
 *
 * ## Yang DITIRU walau janggal — pertanyaan lab
 *
 * Kejanggalan METODE ditiru apa adanya (`docs/pertanyaan-lab-sieve.md`):
 *
 *  - **Pengulangan dibagi 6, bukan √6** (`Q16 = 6`, vi 5). Dibetulkan, U naik
 *    ≈ 2× (weft sesi contoh 0,0463 → 0,1075 mm). Tidak dibetulkan diam-diam
 *    justru KARENA arahnya membesar: itu mengubah U yang sudah terbit.
 *  - **k dipatok 2** walau veff 6–15 (`AC20`). Dengan TINV, weft k = 2,447.
 *  - **"Pengulangan pada 1 opening" = salinan opening 1..6** (`H89 = G34`).
 *    Diturunkan dari opening 1..6 persis seperti master, TIDAK diminta dari
 *    teknisi — kertasnya pun tidak punya blok itu.
 *  - ci komponen selisih suhu = L × Uα (bukan L × Δα), dan komponennya nol
 *    menurut konstruksi (`AE5` dan `AE7` menunjuk sel yang sama).
 *
 * ## Yang dihitung BENAR — kerusakan salin-tempel
 *
 *  1. **Koreksi standar selalu 0 di master.** `VLOOKUP(indeks; Koreksi_*; 3; 0)`
 *     menunjuk kolom M/E yang kosong; kolom `Koreksi` ada di kolom 4. Di sini
 *     dari kolom yang benar — sesi contoh: warp & weft +0,01 mm.
 *  2. Cabang mikroskop master memakai indeks konstanta 0,0102 untuk semua
 *     ukuran; `L81` (kawat) menyapu sembilan dari sepuluh baris caliper. Di
 *     sini titik terdekat dari seluruh tabel.
 *  3. Minimum opening tipe Calibration diambil master dari kolom Inspection
 *     (`F16`). Di sini kolom Calibration.
 *  4. Status standar dari `NOW()` — di sini tanggal kalibrasi sesi.
 *
 * ## Yang DIBLOKIR — sel kosong / teks yang lolos diam-diam di master
 *
 * Nominal yang tidak persis ada di Tabel_MPE (master menjepret ke terdekat),
 * baris Tabel_MPE yang kolom janggalnya dipakai, jumlah opening di bawah
 * minimum (master tidak menegakkannya sama sekali), opening 1..6 yang kosong
 * (master membaca `=G34` kosong sebagai 0), pita CMC `"cek range"`, dan standar
 * kedaluwarsa. Lihat [hitungSesi].
 *
 * ## Vonis
 *
 * Master memvonis tanpa U (`−Y ≤ deviasi ≤ Y`). Keputusan proyek 14 Jul adalah
 * guarded acceptance: `|deviasi| + U ≤ Y`. Dipakai yang kedua; vonis versi
 * master tetap dicatat di jejak audit supaya bedanya kelihatan.
 */
class SieveCalculator
{
    public const PARAMETER = ['warp', 'weft', 'kawat'];

    private ?GumCalculator $gum = null;

    private ?TabelStandarSieve $tabel = null;

    /** Simpangan baku contoh (n-1), sama dengan `STDEV()` Excel. */
    public function simpanganBaku(array $nilai): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $rata = array_sum($nilai) / $n;

        return sqrt(array_sum(array_map(static fn (float $x): float => ($x - $rata) ** 2, $nilai)) / ($n - 1));
    }

    /**
     * Hitung SATU sesi.
     *
     * @param  array<string, array<int, float>>  $opening  parameter => [nomor opening => nilai mm]
     * @param  array{tipe: string|null, nominal: float|null, satuan: string, standar_dipakai: string|null, jumlah_opening_total?: int|null, suhu_ruang_rata_c: float|null, tanggal_kalibrasi: DateTimeInterface}  $konteks
     * @return array{mpe: array<string, mixed>|null, nominal_mm: float|null, minimum_opening: int|null, lantai_cmc: array<string, mixed>|null, parameter: array<string, array<string, mixed>>, boleh_terbit: bool, ditolak: list<array{titik_ke: int, alasan: string}>, catatan: list<string>}
     */
    public function hitungSesi(array $opening, array $konteks): array
    {
        $ditolak = [];
        $catatan = [];
        $tahan = false;
        $tolakSesi = static function (string $alasan) use (&$ditolak, &$tahan): void {
            $ditolak[] = ['titik_ke' => 0, 'alasan' => $alasan];
            $tahan = true;
        };

        $kosong = [
            'mpe' => null, 'nominal_mm' => null, 'minimum_opening' => null, 'lantai_cmc' => null,
            'parameter' => [], 'boleh_terbit' => false, 'catatan' => [],
        ];

        $tipe = $konteks['tipe'] ?? null;
        $standar = $konteks['standar_dipakai'] ?? null;
        $satuan = (string) ($konteks['satuan'] ?? 'mm');
        $nominal = $konteks['nominal'] ?? null;

        if ($tipe === null || $standar === null || $nominal === null || (float) $nominal <= 0.0) {
            $tolakSesi('Blok sesi Sieve belum lengkap: tipe sieve (compliance/inspection/calibration), nominal '
                .'sieve, dan standar yang dipakai (caliper/mikroskop) wajib diisi. Ketiganya menentukan batas '
                .'MPE, jumlah minimum opening, pembagi sertifikat, dan lantai CMC — tidak ada yang bisa ditebak.');

            return [...$kosong, 'ditolak' => $ditolak];
        }

        $mpe = $this->tabel()->barisMpe((float) $nominal, $satuan);

        // Master MENJEPRET ke ukuran terdekat (`INPUT DATA!Z16`) — 19,5 mm
        // dinilai dengan batas 20 mm tanpa satu pun pesan. Di sini ditolak.
        if ($mpe === null) {
            $tolakSesi(sprintf(
                'Nominal %s %s tidak ada PERSIS di Tabel MPE ASTM E11. Master menjepretnya ke ukuran terdekat '
                .'dan menilai sieve dengan batas milik ukuran lain tanpa pesan; di sini ditolak. Periksa '
                .'nominal dan satuannya (penanda inch dicocokkan ke kolom inch, mis. 0,75 untuk 19,0 mm).',
                self::angka((float) $nominal), $satuan,
            ));

            return [...$kosong, 'ditolak' => $ditolak];
        }

        $nominalMm = (float) $mpe['ukuran_mm'];

        $janggal = $this->tabel()->kolomJanggalTerpakai($mpe, $satuan);

        if ($janggal !== []) {
            $tolakSesi(sprintf(
                'Baris Tabel MPE ukuran %s mm memuat nilai yang bertentangan dengan barisnya sendiri pada '
                .'kolom %s (mis. Ø kawat preferred di luar pita min–max miliknya). Master memakainya apa adanya '
                .'dan koreksi kawat yang tercetak ikut salah; di sini ditahan sampai lab membetulkan tabelnya '
                .'(docs/pertanyaan-lab-sieve.md).',
                self::angka($nominalMm), implode(', ', $janggal),
            ));
        }

        $tanggal = $konteks['tanggal_kalibrasi'];

        if (! $this->tabel()->standarBerlaku($standar, $tanggal)) {
            $tolakSesi(sprintf(
                'Standar %s berlaku sampai %s, sesi dikalibrasi %s — kedaluwarsa pada tanggal kalibrasi.',
                $this->tabel()->standar($standar)['nama'],
                $this->tabel()->standar($standar)['berlaku_sampai'],
                $tanggal->format('Y-m-d'),
            ));
        }

        $lantai = $this->tabel()->lantaiCmc($nominalMm, $standar);

        if ($lantai['u95_mm'] === null) {
            $tolakSesi(sprintf(
                'Ukuran %s mm dengan standar %s tidak punya lantai CMC: %s. Master memulangkan "cek range" dan '
                .'MAX() mengabaikannya, jadi U terbit tanpa lantai; di sini ditahan.',
                self::angka($nominalMm), $standar,
                $lantai['lampiran'] === null
                    ? 'di luar lampiran LK-285-IDN (45 µm – 100 mm)'
                    : 'master cuma memberi pita untuk mikroskop ≤ 2 mm dan caliper > 2 mm',
            ));
        }

        $suhu = $konteks['suhu_ruang_rata_c'] ?? null;

        if ($suhu === null) {
            $tolakSesi('Suhu ruangan awal/akhir belum diisi. ci komponen muai termal = L × (T − 20); tanpa suhu, '
                .'ϴ terbaca −20 °C dan komponennya melonjak tanpa error.');
        }

        // Kolom yang BENAR per tipe — master mengambil kolom Inspection untuk
        // tipe Calibration (`F16`). Lihat docblock kelas no. 3.
        $minimumMentah = $mpe['min_opening'][$tipe] ?? null;
        $minimum = is_numeric($minimumMentah) ? (int) $minimumMentah : null;

        if ($minimumMentah === 'all') {
            $total = $konteks['jumlah_opening_total'] ?? null;

            if ($total === null || $total <= 0) {
                $tolakSesi(sprintf(
                    'Untuk ukuran %s mm tipe %s, Tabel MPE mensyaratkan SELURUH opening diperiksa ("all"). '
                    .'Isi jumlah total opening sieve di blok sesi supaya kelengkapannya bisa dinilai.',
                    self::angka($nominalMm), $tipe,
                ));
            } else {
                $minimum = (int) $total;
            }
        }

        $k = $this->tabel()->konstanta();
        $nPengulangan = (int) $k['jumlah_opening_pengulangan'];
        $hasilParameter = [];

        foreach (self::PARAMETER as $i => $parameter) {
            $titikKe = $i + 1;
            $deret = array_map('floatval', $opening[$parameter] ?? []);
            ksort($deret);

            if ($deret === []) {
                $ditolak[] = ['titik_ke' => $titikKe, 'alasan' => sprintf('Tidak ada opening %s yang terisi.', $parameter)];
                $tahan = true;

                continue;
            }

            if ($minimum !== null && count($deret) < $minimum) {
                $tolakSesi(sprintf(
                    'Opening %s terisi %d, di bawah minimum %d untuk ukuran %s mm tipe %s (Tabel MPE ASTM E11). '
                    .'Master tidak menegakkan minimum ini sama sekali; hasil di bawah jumlah minimum tidak sah '
                    .'menurut metode.',
                    $parameter, count($deret), $minimum, self::angka($nominalMm), $tipe,
                ));
            }

            // Pengulangan = opening NOMOR 1..6, bukan enam yang pertama terisi.
            // Master membaca `=G34` yang kosong sebagai 0, dan satu nol di antara
            // enam bukaan 19 mm membuat simpangan bakunya ratusan kali lipat.
            $pengulangan = [];

            foreach (range(1, $nPengulangan) as $no) {
                if (array_key_exists($no, $deret)) {
                    $pengulangan[] = $deret[$no];
                }
            }

            if (count($pengulangan) < $nPengulangan) {
                $tolakSesi(sprintf(
                    'Opening %s nomor 1..%d wajib terisi semua — komponen pengulangan budget diambil dari keenamnya '
                    .'(master INPUT DATA!H89:N91). Yang terisi %d.',
                    $parameter, $nPengulangan, count($pengulangan),
                ));
            } elseif (max($pengulangan) === min($pengulangan)) {
                $catatan[] = sprintf(
                    'Keenam opening %s nomor 1..6 bernilai sama, jadi komponen pengulangan nol dan U %s bertumpu '
                    .'pada komponen lain & lantai CMC. Wajar pada resolusi caliper, tapi pastikan bukan salin-tempel.',
                    $parameter, $parameter,
                );
            }

            $hasilParameter[$parameter] = $this->hitungParameter(
                $titikKe, $parameter, $deret, $pengulangan, $mpe, $nominalMm, (string) $standar,
                (float) ($suhu ?? 20.0), $lantai['u95_mm'],
            );
        }

        if ($mpe['max_stdev_mm'] === '-') {
            $catatan[] = sprintf(
                'Tabel MPE ukuran %s mm tidak mencantumkan batas simpangan baku ("-"), jadi pemeriksaan stdev '
                .'warp/weft TIDAK DINILAI. Master membandingkan angka dengan teks "-" dan Excel menilainya selalu '
                .'PASS; di sini dicatat tidak berlaku, bukan lulus.',
                self::angka($nominalMm),
            );
        }

        $catatan[] = 'Simpangan baku Ø kawat dan batas +X (opening individu terbesar) tidak dinilai — master pun '
            .'tidak menilainya. Lihat docs/pertanyaan-lab-sieve.md.';

        return [
            'mpe' => $mpe,
            'nominal_mm' => $nominalMm,
            'minimum_opening' => $minimum,
            'lantai_cmc' => $lantai,
            'parameter' => $hasilParameter,
            'boleh_terbit' => ! $tahan && count($hasilParameter) === count(self::PARAMETER),
            'ditolak' => $ditolak,
            'catatan' => $catatan,
        ];
    }

    /**
     * Enam komponen budget satu parameter (mm), urutan `PERHITUNGAN U95%`
     * master baris 11–16 / 28–33 / 46–51.
     *
     * `L` (`D4`) = ukuran sieve dalam mm untuk KETIGA parameter, termasuk kawat
     * — ditiru dari master.
     *
     * @param  list<float>  $pengulangan
     * @return list<array{sumber: string, keterangan: string, distribusi: string, nilai_asal: float, pembagi: float, u: float, ci: float, vi: float}>
     */
    public function budget(string $parameter, string $standar, float $lMm, float $suhuC, array $pengulangan): array
    {
        $k = $this->tabel()->konstanta();
        $akar3 = sqrt(3.0);
        $uAlpha = (float) $k['delta_alpha_per_c'] * (float) $k['u_alpha_pengali'][$standar];

        $baris = static fn (string $sumber, string $ket, string $dist, float $asal, float $pembagi, float $ci, float $vi): array => [
            'sumber' => $sumber,
            'keterangan' => $ket,
            'distribusi' => $dist,
            'nilai_asal' => $asal,
            'pembagi' => $pembagi,
            'u' => $asal / $pembagi,
            'ci' => $ci,
            'vi' => $vi,
        ];

        return [
            $baris('ketidakpastian_standar', 'Sertifikat kalibrasi standar', 'normal',
                $this->tabel()->u95Sertifikat($standar, $parameter),
                (float) $k['pembagi_sertifikat'][$standar], 1.0, (float) $k['vi_sertifikat']),
            $baris('resolusi_standar', 'Daya baca alat standar', 'rectangular',
                (float) $k['daya_baca_pengali'] * $this->tabel()->standar($standar)['resolusi_mm'],
                $akar3, 1.0, (float) $k['vi_daya_baca']),
            $baris('geometri', 'Kesalahan geometri', 'rectangular',
                (float) $k['geometri_mm'], $akar3, 1.0, (float) $k['vi_geometri']),
            // Suhu sieve = suhu standar = rata-rata ruang (`AE5 = AE7 =
            // PERHITUNGAN!G17`), jadi nol menurut konstruksi; ci L × Uα ditiru.
            $baris('selisih_suhu', 'Selisih suhu standar dengan test sieve', 'rectangular',
                0.0, $akar3, $lMm * $uAlpha, (float) $k['vi_suhu']),
            $baris('koefisien_muai', 'Koefisien muai termal', 'rectangular',
                $uAlpha, $akar3, $lMm * ($suhuC - (float) $k['suhu_acuan_c']), (float) $k['vi_suhu']),
            // Dibagi 6, bukan √6 — ditiru, pertanyaan lab.
            $baris('pengulangan', 'Ketidakpastian baku pengulangan pembacaan', 't-student',
                $this->simpanganBaku($pengulangan), (float) $k['pembagi_pengulangan'], 1.0, (float) $k['vi_pengulangan']),
        ];
    }

    /**
     * @param  array<int, float>  $deret
     * @param  list<float>  $pengulangan
     * @param  array<string, mixed>  $mpe
     * @return array<string, mixed>
     */
    private function hitungParameter(
        int $titikKe,
        string $parameter,
        array $deret,
        array $pengulangan,
        array $mpe,
        float $nominalMm,
        string $standar,
        float $suhuC,
        ?float $lantaiMm,
    ): array {
        $nilai = array_values($deret);
        $rata = array_sum($nilai) / count($nilai);
        $titikKoreksi = $this->tabel()->titikKoreksi($standar, $parameter, $rata);
        $terkoreksi = $rata + (float) $titikKoreksi['koreksi_mm'];

        $nominalParameter = $parameter === 'kawat' && is_numeric($mpe['kawat_preferred_mm'])
            ? (float) $mpe['kawat_preferred_mm']
            : $nominalMm;

        $budget = $this->budget($parameter, $standar, $nominalMm, $suhuC, $pengulangan);
        $agregat = $this->gum()->agregasiBudget(
            array_map(static fn (array $b): array => ['u' => $b['u'], 'ci' => $b['ci'], 'vi' => $b['vi']], $budget),
            (float) $this->tabel()->konstanta()['k'],
        );

        $u95 = $lantaiMm === null
            ? $agregat['ketidakpastian_diperluas']
            : max($agregat['ketidakpastian_diperluas'], $lantaiMm);

        $simpanganBaku = $this->simpanganBaku($nilai);
        $deviasi = $terkoreksi - $nominalParameter;

        if ($parameter === 'kawat') {
            $min = (float) $mpe['kawat_min_mm'];
            $max = (float) $mpe['kawat_max_mm'];
            $lulus = $terkoreksi >= $min && $terkoreksi <= $max;
            $vonis = ['batas' => ['min_mm' => $min, 'max_mm' => $max], 'lulus' => $lulus, 'lulus_master' => $rata >= $min && $rata <= $max];
            $stdevCek = null;
        } else {
            $y = (float) $mpe['y_mm'];
            $maxStdev = is_numeric($mpe['max_stdev_mm']) ? (float) $mpe['max_stdev_mm'] : null;
            $stdevCek = ['max_mm' => $maxStdev, 'lulus' => $maxStdev === null ? null : $simpanganBaku <= $maxStdev];
            $lulus = abs($deviasi) + $u95 <= $y && ($stdevCek['lulus'] ?? true);
            // Versi master: tanpa U, dari rata-rata TANPA koreksi standar
            // (kolom koreksi kosong), dan stdev dinilai terpisah.
            $devMaster = $rata - $nominalMm;
            $vonis = [
                'batas' => ['y_mm' => $y],
                'lulus' => $lulus,
                'lulus_master' => $devMaster >= -$y && $devMaster <= $y,
            ];
        }

        return [
            'titik_ke' => $titikKe,
            'parameter' => $parameter,
            'jumlah' => count($nilai),
            'rata_rata' => $rata,
            'simpangan_baku' => $simpanganBaku,
            'titik_koreksi' => $titikKoreksi,
            'koreksi_standar' => (float) $titikKoreksi['koreksi_mm'],
            'terkoreksi' => $terkoreksi,
            'nominal_parameter_mm' => $nominalParameter,
            'deviasi' => $deviasi,
            'pengulangan' => $pengulangan,
            'simpangan_baku_pengulangan' => $this->simpanganBaku($pengulangan),
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agregat['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $agregat['ketidakpastian_diperluas'],
            'u95_sertifikat' => $u95,
            'type_b' => sqrt(array_sum(array_map(
                static fn (array $b): float => ($b['u'] * $b['ci']) ** 2,
                array_filter($budget, static fn (array $b): bool => $b['distribusi'] !== 't-student'),
            ))),
            'vonis' => $vonis,
            'stdev' => $stdevCek,
            'keputusan' => $lulus ? 'PASS' : 'FAIL',
        ];
    }

    private static function angka(float $x): string
    {
        return rtrim(rtrim(number_format($x, 6, ',', '.'), '0'), ',');
    }

    private function tabel(): TabelStandarSieve
    {
        // Malas, bukan di parameter bawaan konstruktor — lingkaran profil →
        // kalkulator → GumCalculator → registry → profil.
        return $this->tabel ??= new TabelStandarSieve;
    }

    private function gum(): GumCalculator
    {
        return $this->gum ??= new GumCalculator;
    }
}
