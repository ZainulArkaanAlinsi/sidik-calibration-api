<?php

namespace App\Services\Calibration;

use App\Services\StudentTDistribution;
use InvalidArgumentException;

/**
 * Mesin "olah data" Autoklaf — dua besaran sekaligus (Suhu & Tekanan) dalam SATU
 * sesi, persis master `Master Olah Data_Autoclave.xlsm` (LK-285-IDN,
 * SIDIK-IK-CAL-0531_Rev.4).
 *
 * Kenapa kelas sendiri, BUKAN lewat `GumCalculator::hitungTitik()` kayak enam
 * alat lain: bentuk data Autoklaf nggak muat di model "titik ukur + pengulangan".
 * Satu sesi = 3 sensor (disk) suhu × beberapa titik waktu pada SATU set point,
 * ditambah indikator enclosure & suhu ruang, ditambah satu titik tekanan dengan
 * konversi satuan. Budget ketidakpastiannya juga lahir sekali per besaran
 * (bukan per titik), dengan pembagi & derajat kebebasan yang beda dari jalur
 * generik. Maka dihitung utuh di sini — pola yang sama kayak
 * `SpectrophotometerCalculator`.
 *
 * Angka diadu ke master: Uc suhu 0,2241822 · v_eff 209,3886 · k 1,9713602 · U
 * 0,4419439; Uc tekanan 0,0044283782 · v_eff 20,3807569. Lihat
 * `tests/Unit/AutoclaveCalculatorTest`.
 *
 * ## Satu koreksi yang SENGAJA beda dari master (keputusan final, disetujui)
 *
 * Baris disk di sheet `PERHITUNGAN FC` (D23:I25) salah tarik sel: kolom titik
 * waktu di `INPUT DATA` itu D,F,H,I,K (5 titik) tapi rumusnya baca D,F,H,H,I —
 * titik ke-5 (K) kebuang, titik ke-3 (H) kehitung dua kali. Baris Indikator
 * (D26:I26) nariknya BENAR (D,F,H,I,K), jadi ini kelalaian drag.
 *
 * Lebih dari itu: master-nya KONTRADIKSI sama dirinya sendiri. Nilai cache
 * disk 1 & 2 (J23=121,262 · J24=121,266) cuma cocok kalau titik ke-5 dibuang,
 * TAPI disk 3 (J25=121,286) cuma cocok kalau ke-5 IKUT. Nggak ada satu rumus
 * konsisten yang ngasih ketiga angka itu — workbook-nya setengah-diedit, cache
 * basi sebagian. Jadi Keseragaman 0,466 di sertifikat lama lahir dari sel yang
 * saling bertentangan, bukan dari satu prosedur.
 *
 * Kelas ini merata-rata SEMUA titik waktu yang keisi (D,F,H,I,K) — satu-satunya
 * nilai yang KONSISTEN dan cocok sama formulir prosedur `SIDIK-FM-CAL-0539_Rev.4`
 * (5 kolom waktu × 3 disk). Hasilnya Keseragaman 0,464 (bukan 0,466); Kestabilan,
 * Variasi & U95 tetap identik master. Ini keputusan final yang sudah disetujui
 * pemilik repo — jangan "dibenarkan" balik ke 0,466 tanpa arahan baru.
 */
class AutoclaveCalculator
{
    /**
     * Pembagi tetap dari master (BUKAN diturunkan dari nalar distribusi — diambil
     * apa adanya dari sel `PERHITUNGAN U95%` biar hasilnya identik dokumen lab).
     * `drift` suhu pakai 1,73 (literal di sel, bukan √3), dan komponen
     * pengulangan suhu dibagi √(√3) (sel-nya `=N/SQRT(SQRT(3))`).
     */
    private const AKAR3 = 1.7320508075688772;

    public function __construct(
        private readonly StudentTDistribution $t = new StudentTDistribution,
    ) {}

    /**
     * @param  array<string, mixed>  $input  lihat docs/perintah-frontend-autoclave.md
     * @return array<string, mixed>
     */
    public function hitung(array $input): array
    {
        $setPoint = $this->wajibFloat($input, 'set_point');
        $cmcSuhu = (float) ($input['cmc']['suhu'] ?? 0.34);
        $cmcTekananBar = (float) ($input['cmc']['tekanan'] ?? 0.059);

        return [
            'set_point' => $setPoint,
            // `null` kalau blok suhunya nggak dikirim sama sekali — SIMETRIS
            // sama `hitungTekanan` di bawah. Sesi tekanan-saja itu skenario
            // nyata: `AutoclaveCalculationRequest` mengizinkannya, handoff
            // frontend menjanjikannya ("kirim blok suhu saja atau tekanan saja
            // boleh"), dan layar mobile memang cuma mengirim blok yang keisi.
            // Sebelum ini jalur itu melempar `Data suhu kosong` dan nyampe ke
            // teknisi sebagai 500 — bukan pesan yang bisa dia tindaklanjuti.
            'suhu' => ($input['suhu'] ?? []) === [] ? null : $this->hitungSuhu($input['suhu'], $setPoint, $cmcSuhu),
            'tekanan' => $this->hitungTekanan($input['tekanan'] ?? [], $cmcTekananBar),
        ];
    }

    /**
     * Section A & B sertifikat: sebaran suhu per sensor + kinerja (Kestabilan,
     * Keseragaman, Variasi) + budget ketidakpastian suhu.
     *
     * @param  array<string, mixed>  $suhu
     * @return array<string, mixed>
     */
    private function hitungSuhu(array $suhu, float $setPoint, float $cmc): array
    {
        // Blok suhu DIKIRIM tapi isinya kosong tetap dilempar (bukan di-null-in
        // diam-diam): teknisi yang ngirim blok suhu bermaksud ngukur suhu, dan
        // hasil yang hilang tanpa keterangan lebih buruk daripada penolakan.
        // Yang di-null-in cuma blok yang memang NGGAK dikirim — lihat `hitung`.
        $disk = $suhu['disk'] ?? [];
        $standar = $suhu['standar'] ?? [];
        $indikator = $this->angkaSaja($suhu['indikator'] ?? []);

        if ($disk === [] || $indikator === []) {
            throw new InvalidArgumentException('Data suhu kosong: butuh minimal 1 disk & indikator.');
        }

        $indikatorRata = $this->rata($indikator);
        // STDEV sampel — master `PERHITUNGAN FC!D30` pakai STDEV (n-1), bukan
        // populasi. Satu titik waktu -> STDEV nggak terdefinisi -> 0.
        $stdevIndikator = $this->stdevSampel($indikator);

        $sensor = [];
        $deltaTMaks = 0.0;
        $simpanganMaks = null;   // MAX(N - indikatorRata) — buat Keseragaman
        $tMaks = null;           // MAX max-per-disk — buat Variasi
        $tMin = null;            // MIN min-per-disk

        foreach ($disk as $i => $bacaanMentah) {
            $bacaan = $this->angkaSaja($bacaanMentah);

            if ($bacaan === []) {
                continue;
            }

            $rata = $this->rata($bacaan);
            $maks = max($bacaan);
            $min = min($bacaan);
            $deltaT = $maks - $min;

            $tabel = $standar[$i]['tabel'] ?? [];
            $koreksiStandar = $this->koreksiTerdekat($tabel, $rata);
            $standarTerkoreksi = $rata + $koreksiStandar;
            $simpangan = $standarTerkoreksi - $indikatorRata;

            $sensor[] = [
                'no' => $i + 1,
                'rata' => $rata,
                'koreksi_standar' => $koreksiStandar,
                'standar_terkoreksi' => $standarTerkoreksi,
                'koreksi' => $simpangan,
                'delta_t' => $deltaT,
                'maks' => $maks,
                'min' => $min,
            ];

            $deltaTMaks = max($deltaTMaks, $deltaT);
            $simpanganMaks = $simpanganMaks === null ? $simpangan : max($simpanganMaks, $simpangan);
            $tMaks = $tMaks === null ? $maks : max($tMaks, $maks);
            $tMin = $tMin === null ? $min : min($tMin, $min);
        }

        // Kestabilan Suhu (SS) = ½·max(ΔT) ; Keseragaman (KS) = |max simpangan| ;
        // Variasi Keseluruhan (VK) = (Tmax)max − (Tmin)min. `PERHITUNGAN FC`
        // R30/R31/R32.
        $kestabilan = 0.5 * $deltaTMaks;
        $keseragaman = abs($simpanganMaks ?? 0.0);
        $variasi = ($tMaks ?? 0.0) - ($tMin ?? 0.0);

        $budget = $this->budgetSuhu(
            u95Standar: $this->maksU95($standar),
            resolusiStandar: (float) ($standar[0]['resolusi'] ?? 0.0),
            resolusiAlat: (float) ($suhu['resolusi_alat'] ?? 0.0),
            kestabilan: $kestabilan,
            stdevIndikator: $stdevIndikator,
        );

        $uBentangan = $budget['k'] * $budget['uc'];
        $u95 = max($uBentangan, $cmc);

        return [
            'indikator_rata' => $indikatorRata,
            'stdev_indikator' => $stdevIndikator,
            'sensor' => $sensor,
            'kestabilan' => $kestabilan,
            'keseragaman' => $keseragaman,
            'variasi' => $variasi,
            'budget' => $budget['komponen'],
            'uc' => $budget['uc'],
            'v_eff' => $budget['v_eff'],
            'k' => $budget['k'],
            'u_bentangan' => $uBentangan,
            'cmc' => $cmc,
            'u95' => $u95,
        ];
    }

    /**
     * Budget suhu — 6 komponen master `PERHITUNGAN U95%` baris 9–14. `k`
     * dihitung dari polinomial pendekatan t-student di sel AC18 (BUKAN v_eff
     * dibulatkan lalu TINV — master suhu memang pakai polinomial ini).
     *
     * @return array{komponen: list<array<string,mixed>>, uc: float, v_eff: float, k: float}
     */
    private function budgetSuhu(
        float $u95Standar,
        float $resolusiStandar,
        float $resolusiAlat,
        float $kestabilan,
        float $stdevIndikator,
    ): array {
        $akarAkar3 = sqrt(self::AKAR3);

        // [nama, U, pembagi, vi, ci]
        $baris = [
            ['Ketidakpastian Baku Temperature Standar', $u95Standar, 2.0, 200, 1.0],
            ['Ketidakpastian Baku Resolusi Standar', $resolusiStandar / 2, self::AKAR3, 200, 1.0],
            ['Ketidakpastian Baku Resolusi Alat', $resolusiAlat / 2, self::AKAR3, 201, 2.0],
            ['Ketidakpastian Baku Drift Standar', $u95Standar / 10, 1.73, 50, 1.0],
            ['Ketidakpastian Baku Pengulangan Pembacaan Standar', $kestabilan, $akarAkar3, 4, 1.0],
            ['Ketidakpastian Baku Pengulangan Pembacaan Alat', $stdevIndikator, $akarAkar3, 4, 1.0],
        ];

        $agregat = $this->agregasi($baris);

        $v = $agregat['v_eff'];
        $k = 1.95996 + 2.37356 / $v + 2.818745 / $v ** 2 + 2.546662 / $v ** 3
            + 1.761829 / $v ** 4 + 0.245458 / $v ** 5 + 1.000764 / $v ** 6;

        return ['komponen' => $agregat['komponen'], 'uc' => $agregat['uc'], 'v_eff' => $v, 'k' => $k];
    }

    /**
     * Section C sertifikat: satu titik tekanan. Semua hitung DI dalam bar (satuan
     * kanonik master), hasil diturunkan lagi ke satuan display alat di akhir.
     *
     * @param  array<string, mixed>  $tekanan
     * @return array<string, mixed>
     */
    private function hitungTekanan(array $tekanan, float $cmcBar): array
    {
        if ($tekanan === []) {
            return [];
        }

        $satuan = (string) ($tekanan['satuan'] ?? 'Bar');
        $keBar = $this->faktorKeBar($satuan);

        $uutSetting = $this->wajibFloat($tekanan, 'uut_setting');
        $uutSettingBar = $uutSetting * $keBar;

        $bacaan = $this->angkaSaja($tekanan['pembacaan_standar'] ?? []);

        if ($bacaan === []) {
            throw new InvalidArgumentException('Data tekanan kosong: butuh pembacaan standar.');
        }

        $standarRata = $this->rata($bacaan);
        $stdev = $this->stdevSampel($bacaan);

        $tabel = $tekanan['standar']['tabel'] ?? [];
        // Index & koreksi diambil pada SET POINT UUT (bukan pembacaan) — master
        // `PERHITUNGAN FC!Y41` MATCH ke E41. U95 sertifikat standar & koreksi UP
        // dibaca di titik terdekat itu.
        $terdekat = $this->barisTerdekat($tabel, $uutSettingBar);
        $indexBar = $terdekat['set'] ?? $uutSettingBar;
        $koreksiStandarBar = (float) ($terdekat['koreksi_up'] ?? 0.0);
        $u95StandarBar = (float) ($terdekat['u95'] ?? 0.0);
        $standarTerkoreksiBar = $standarRata + $koreksiStandarBar;

        // Koreksi yang dilaporkan = Standard − UUT (master sertifikat K39).
        $koreksiBar = $standarTerkoreksiBar - $uutSettingBar;

        $resolusiAlatBar = (float) ($tekanan['resolusi_alat'] ?? 0.0) * $keBar;
        $resolusiStandarBar = (float) ($tekanan['standar']['resolusi'] ?? 0.0);
        $dayaBacaAlat = $this->dayaBacaTekanan((string) ($tekanan['display'] ?? 'Digital'), $resolusiAlatBar);

        $budget = $this->budgetTekanan(
            u95StandarBar: $u95StandarBar,
            dayaBacaAlat: $dayaBacaAlat,
            resolusiStandarBar: $resolusiStandarBar,
            stdev: $stdev,
        );

        $uBentanganBar = $budget['k'] * $budget['uc'];
        $u95Bar = max($uBentanganBar, $cmcBar);

        return [
            'satuan' => $satuan,
            'uut_setting' => $uutSetting,
            'uut_setting_bar' => $uutSettingBar,
            'standar_rata_bar' => $standarRata,
            'index_bar' => $indexBar,
            'koreksi_standar_bar' => $koreksiStandarBar,
            'standar_terkoreksi_bar' => $standarTerkoreksiBar,
            'koreksi_bar' => $koreksiBar,
            'stdev_bar' => $stdev,
            'budget' => $budget['komponen'],
            'uc' => $budget['uc'],
            'v_eff' => $budget['v_eff'],
            'k' => $budget['k'],
            'u_bentangan_bar' => $uBentanganBar,
            'cmc_bar' => $cmcBar,
            'u95_bar' => $u95Bar,
            // Diturunkan ke satuan display buat dicetak di sertifikat.
            'standar_terkoreksi' => $standarTerkoreksiBar / $keBar,
            'koreksi' => $koreksiBar / $keBar,
            'u95' => $u95Bar / $keBar,
        ];
    }

    /**
     * Budget tekanan — 5 komponen master `PERHITUNGAN U95%` baris 31–35. `k` =
     * TINV(0,05, v_eff) = t-student dua-sisi 95 %. Excel `TINV` MEMOTONG argumen
     * derajat kebebasan ke bilangan bulat sebelum lookup (20,38 -> 20), jadi `k`
     * diambil di floor(v_eff) walau v_eff mentahnya tetap dilaporkan apa adanya.
     * Tanpa pemotongan ini `k` jadi 2,0835, bukan 2,0860 yang tercetak di
     * sertifikat master.
     *
     * @return array{komponen: list<array<string,mixed>>, uc: float, v_eff: float, k: float}
     */
    private function budgetTekanan(
        float $u95StandarBar,
        float $dayaBacaAlat,
        float $resolusiStandarBar,
        float $stdev,
    ): array {
        $baris = [
            ['Ketidakpastian Baku Sertifikat Kalibrasi Calibrator', $u95StandarBar, 2.0, 60, 1.0],
            ['Ketidakpastian Baku Daya Baca Alat yang dikalibrasi', $dayaBacaAlat, self::AKAR3, 50, 1.0],
            ['Ketidakpastian Baku Daya Baca Standar', $resolusiStandarBar / 2, self::AKAR3, 50, 1.0],
            ['Ketidakpastian Drift Standard', 0.10 * $u95StandarBar, self::AKAR3, 50, 1.0],
            ['Ketidakpastian Baku Pengulangan Pembacaan', $stdev, 3.0, 2, 1.0],
        ];

        $agregat = $this->agregasi($baris);
        $v = $agregat['v_eff'];
        $k = $this->t->quantile(0.975, max(1.0, floor($v)));

        return ['komponen' => $agregat['komponen'], 'uc' => $agregat['uc'], 'v_eff' => $v, 'k' => $k];
    }

    /**
     * Agregasi Welch–Satterthwaite umum buat kedua budget: ui=U/pembagi,
     * uici=ui·ci, Uc=√Σ(uici²), v_eff=Uc⁴/Σ(uici⁴/vi).
     *
     * @param  list<array{0:string,1:float,2:float,3:int,4:float}>  $baris
     * @return array{komponen: list<array<string,mixed>>, uc: float, v_eff: float}
     */
    private function agregasi(array $baris): array
    {
        $komponen = [];
        $jumlahKuadrat = 0.0;
        $penyebutWs = 0.0;

        foreach ($baris as [$nama, $u, $pembagi, $vi, $ci]) {
            $ui = $pembagi != 0.0 ? $u / $pembagi : 0.0;
            $uici = $ui * $ci;
            $kuadrat = $uici ** 2;
            $jumlahKuadrat += $kuadrat;
            $penyebutWs += $vi > 0 ? ($uici ** 4) / $vi : 0.0;

            $komponen[] = [
                'komponen' => $nama,
                'u' => $u,
                'pembagi' => $pembagi,
                'vi' => $vi,
                'ci' => $ci,
                'ui' => $ui,
                'uici' => $uici,
            ];
        }

        $uc = sqrt($jumlahKuadrat);
        $vEff = $penyebutWs > 0.0 ? ($jumlahKuadrat ** 2) / $penyebutWs : INF;

        return ['komponen' => $komponen, 'uc' => $uc, 'v_eff' => $vEff];
    }

    /**
     * Daya baca alat tekanan sesuai tipe display — master tabel `Tabel_Res`
     * (`PERHITUNGAN U95%!X25:Z28`). Analog dibagi rasio jarum:NST, Digital ½.
     */
    private function dayaBacaTekanan(string $display, float $resolusiBar): float
    {
        return match (mb_strtolower(trim($display))) {
            'analog 1' => $resolusiBar / 2,
            'analog 2' => $resolusiBar / 5,
            'analog 3' => $resolusiBar / 10,
            default => $resolusiBar / 2,   // Digital
        };
    }

    /** Faktor konversi satuan tekanan ke Bar — master `DATABASE!S20:S27`. */
    private function faktorKeBar(string $satuan): float
    {
        return match (mb_strtolower(trim($satuan))) {
            'bar' => 1.0,
            'psi' => 0.0689475729,
            'kg/cm2' => 0.980665,
            'kpa' => 0.01,
            'mpa' => 10.0,
            'inhg' => 0.033863886666667,
            'mmhg' => 0.0013332239,
            'pa' => 0.00001,
            default => throw new InvalidArgumentException("Satuan tekanan tidak dikenal: {$satuan}"),
        };
    }

    /**
     * Koreksi standar pada set point TERDEKAT (min |set − nilai|) — master array
     * `INDEX(...,MATCH(MIN(ABS(...))))`.
     *
     * @param  list<array{set: float, koreksi: float}>  $tabel
     */
    private function koreksiTerdekat(array $tabel, float $nilai): float
    {
        $baris = $this->barisTerdekat($tabel, $nilai);

        return (float) ($baris['koreksi'] ?? 0.0);
    }

    /**
     * Baris tabel dengan set point terdekat ke `$nilai`.
     *
     * @param  list<array<string, float>>  $tabel
     * @return array<string, float>|null
     */
    private function barisTerdekat(array $tabel, float $nilai): ?array
    {
        $terpilih = null;
        $jarakMin = INF;

        foreach ($tabel as $baris) {
            $jarak = abs(((float) ($baris['set'] ?? 0.0)) - $nilai);

            if ($jarak < $jarakMin) {
                $jarakMin = $jarak;
                $terpilih = $baris;
            }
        }

        return $terpilih;
    }

    /**
     * U95 standar terbesar dari seluruh disk kalibrator suhu — master
     * `MAX(F28:F33,F42:F47,F56:F61)`.
     *
     * @param  list<array{tabel?: list<array{u95?: float}>}>  $standar
     */
    private function maksU95(array $standar): float
    {
        $maks = 0.0;

        foreach ($standar as $s) {
            foreach ($s['tabel'] ?? [] as $baris) {
                $maks = max($maks, (float) ($baris['u95'] ?? 0.0));
            }
        }

        return $maks;
    }

    /** @param array<int, mixed> $nilai */
    private function angkaSaja(array $nilai): array
    {
        $keluar = [];

        foreach ($nilai as $v) {
            if ($v === null || $v === '') {
                continue;
            }

            if (is_numeric($v)) {
                $keluar[] = (float) $v;
            }
        }

        return $keluar;
    }

    /** @param list<float> $nilai */
    private function rata(array $nilai): float
    {
        return array_sum($nilai) / count($nilai);
    }

    /** STDEV sampel (n−1). Kurang dari 2 data -> 0. @param list<float> $nilai */
    private function stdevSampel(array $nilai): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $rata = $this->rata($nilai);
        $jumlah = 0.0;

        foreach ($nilai as $v) {
            $jumlah += ($v - $rata) ** 2;
        }

        return sqrt($jumlah / ($n - 1));
    }

    /** @param array<string, mixed> $arr */
    private function wajibFloat(array $arr, string $kunci): float
    {
        if (! isset($arr[$kunci]) || ! is_numeric($arr[$kunci])) {
            throw new InvalidArgumentException("Field wajib '{$kunci}' kosong atau bukan angka.");
        }

        return (float) $arr[$kunci];
    }
}
