<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Mesin hitung **Hydrometer** — lampiran akreditasi LK-285-IDN baris no. 25,
 * metode `SIDIK-IK-CAL-0525_Rev.3`, Lab. Volumetrik.
 *
 * Dua workbook master (password `spirit285`) jadi sumbernya, dan rantainya
 * DIBUKTIKAN lebih dulu di Python sebelum satu baris PHP ditulis: keenam
 * densitas terbitnya, keempat belas koefisien sensitivitasnya, serta `uc`,
 * `v_eff`, `k`, dan `U` tiap skala cocok sampai ≤3·10⁻¹⁷. Test yang mengulang
 * adu itu: `tests/Unit/Calibration/HydrometerRekonsiliasiTest.php`.
 *
 * ## Yang diinput teknisi BUKAN pembacaan densitas
 *
 * Alat lain mengirim "standar lawan pembacaan". Hydrometer tidak punya bentuk
 * itu sama sekali: yang dipungut lembar kerja adalah **massa hasil timbang
 * (gram)** dan **suhu air (°C)**, masing-masing tiga ulangan per titik skala.
 * Densitasnya HASIL OLAHAN metode Cuckow (penimbangan hidrostatis) — jadi
 * kolom `Actual Value` sertifikat tidak pernah diketik siapa pun.
 *
 * ## DUA VARIAN RUMUS dalam satu profil
 *
 * Hydrometer rentang ringan mengambang di air, jadi dipakaikan **beban
 * tambahan (sinker)**; rentang berat tenggelam sendiri dan tidak. Massa di
 * cairan dihitung beda: varian sinker mengurangi berat semu sinker `Q`, varian
 * non-sinker tidak.
 *
 * Penentunya **toggle eksplisit teknisi** ([$blok]`['pakai_beban_tambahan']`),
 * BUKAN angka rentang ukurnya. "Rentang < 1 → pakai sinker" tampak benar di
 * dua file contoh dan salah begitu lab memegang hydrometer ketiga; dan tanpa
 * toggle, `Sl` kosong karena tidak perlu tidak bisa dibedakan dari `Sl` kosong
 * karena teknisi lupa — yang pertama harus jalan, yang kedua harus ditolak.
 *
 * ## Yang DITIRU apa adanya walau janggal
 *
 * Tujuannya satu: hasil aplikasi identik dengan Excel, jadi sertifikat yang
 * SUDAH terbit tidak berubah angkanya kalau dicetak ulang dari sini. Keputusan
 * mengubah salah satunya milik Technical Manager, bukan developer — kalau lab
 * memutuskan berubah, itu **revisi metode** dengan catatan perubahan, bukan
 * patch diam-diam. Semuanya terdaftar di `docs/pertanyaan-lab-hydrometer.md`.
 *
 *  1. **π = 3,14**, bukan `M_PI` ([TabelStandarHydrometer::PHI]). Nilainya
 *     masuk ke `πDγ/g` yang ikut menentukan densitas akhir — `M_PI` menggeser
 *     hasil di digit yang justru dicetak sertifikat.
 *  2. **Satuan tekanan campur.** `κ` bersatuan 1/Pa, tapi faktor koreksi
 *     tekanan mengurangkan dua angka ber-**hPa** (`933,15 − 1013,25`). Faktor
 *     yang keluar 1,0000000020025; kalau Pa dipakai konsisten hasilnya
 *     0,999999999375.
 *  3. **`fta`/`ftl` di budget memakai FAKTOR TEKANAN, bukan α.** Sel
 *     `NILAI U95%!Q49` berbunyi `1+(J85*(G15−20))` dan `J85` itu faktor
 *     koreksi tekanan (≈1,000000002), bukan `J83` = α = 1·10⁻⁵. Akibatnya
 *     `fta` ≈ 1,45 — lima setengah digit lebih besar dari faktor suhu yang
 *     sebenarnya. Dipakai di `H44`/`H45` yang masuk ketujuh koefisien
 *     sensitivitas.
 *  4. **Faktor koreksi suhu diambil dari titik PERTAMA saja**, lalu dipakai
 *     semua titik (`AD38` = suhu rata-rata titik 1). Titik lain yang suhunya
 *     beda tetap memakai faktor titik 1.
 *  5. **`TINV` memotong derajat kebebasan ke bilangan bulat.** `v_eff`
 *     125,2997 memberi `k` 1,9791241 di master; kuantil-t yang tepat pada
 *     125,2997 adalah 1,9790778. Selisihnya masuk desimal ke-5 `U95%` — yang
 *     memang dicetak. Kebetulan ini juga yang diminta GUM G.4.1, dan
 *     [GumCalculator::agregasiBudget] sudah memotong sejak awal.
 *  6. **Suhu acuan faktor koreksi bukan `tr`.** `AD39` menunjuk kotak
 *     `Temperature` (20 °C) walau `tr` file ringan 15 °C.
 *  7. **Koefisien 7.9, 7.10b, dan 7.12 memakai penyebut SKALA 1** (`$O$55`
 *     absolut) untuk semua skala, sedangkan 7.10a dan 7.11 memakai penyebut
 *     skalanya sendiri. Master rentang berat memperbaiki 7.12 jadi per-skala;
 *     master ringan belum. Di sini dipakai bentuk master RINGAN — itu yang
 *     mereproduksi KEDUA sertifikat terbit pada presisi penuh, karena di file
 *     berat sebaran tegangan permukaannya nol sehingga komponen itu tidak
 *     menyumbang apa pun. Pertanyaan §8.
 *  8. **`(−E44)` lawan `(−L45)` pada koefisien 7.11**: skala 1 memakai `E44`,
 *     skala 2 dan seterusnya `L45`. Sama di kedua master.
 *
 * ## Yang TIDAK ditiru
 *
 * Monitor `VALID`/`WARNING`/`EXPIRED` master cuma label — sel `K3` menulis
 * "ONE OR MORE STANDARD EXPIRED" dan lembarnya tetap menghitung sampai
 * selesai. Kedua file contoh terbit dengan status itu menyala. Di sini standar
 * lewat tanggal due **memblokir** sesi (`CalibrationValidator`), bukan jadi
 * catatan.
 */
class HydrometerCalculator
{
    /**
     * Suhu acuan yang diketik LITERAL di rumus budget (`NILAI U95%!Q49`,
     * `F65`, `F66`). Sengaja dipisah dari `$blok['suhu_acuan_faktor']` walau
     * di kedua master isinya sama-sama 20: yang satu kotak isian di `INPUT
     * DATA`, yang satu angka yang diketik ke dalam rumus. Menyatukannya
     * membuat sesi ber-`Temperature` 27,5 diam-diam menggeser budget.
     */
    public const SUHU_ACUAN_BUDGET = 20.0;

    /** Derajat kebebasan komponen Type B, seperti kolom `vi` master. */
    private const VI_NORMAL = 60.0;

    private const VI_RECTANGULAR = 50.0;

    private ?GumCalculator $gum = null;

    /**
     * @param  list<array{titik_ke: int, titik_ukur: float, massa: list<float>, suhu: list<float>}>  $titik
     * @param  array<string, mixed>  $blok  blok Pre Condition + kondisi lingkungan satu sesi
     * @return array{titik: list<array<string, mixed>>, ditolak: list<array{titik_ke: int, alasan: string}>, boleh_terbit: bool, praolah: array<string, mixed>}
     */
    public function hitungSesi(array $titik, array $blok): array
    {
        $ditolak = [];

        $pakaiSinker = (bool) ($blok['pakai_beban_tambahan'] ?? false);
        $sinker = $blok['beban_tambahan'] ?? null;
        $sinker = ($sinker === null || $sinker === '') ? null : (float) $sinker;

        // Toggle menyala tapi angkanya kosong = teknisi lupa, bukan "tidak
        // perlu". Ditolak di sini, bukan dihitung sebagai non-sinker: rumus
        // non-sinker atas hydrometer yang BUTUH sinker memulangkan densitas
        // yang tampak wajar dan salah beberapa persen — tidak ada satu pun
        // gejala yang kelihatan di sertifikat.
        if ($pakaiSinker && ($sinker === null || $sinker <= 0.0)) {
            return $this->gagalSesi(
                $titik,
                'Varian beban tambahan dipilih tapi massa beban tambahan (`Sl`) belum diisi.',
            );
        }

        if (! $pakaiSinker) {
            $sinker = null;
        }

        $diameter = array_values(array_map('floatval', array_filter(
            (array) ($blok['diameter_stem'] ?? []),
            static fn ($d): bool => $d !== null && $d !== '',
        )));

        if (count($diameter) !== TabelStandarHydrometer::UKUR_DIAMETER_STEM) {
            return $this->gagalSesi($titik, sprintf(
                'Diameter stem harus diukur tepat %d kali; yang terkirim %d.',
                TabelStandarHydrometer::UKUR_DIAMETER_STEM,
                count($diameter),
            ));
        }

        $massaUdaraMentah = (float) ($blok['massa_udara'] ?? 0.0);

        if ($massaUdaraMentah <= 0.0) {
            return $this->gagalSesi($titik, 'Massa hydrometer di udara (`Ma`) belum diisi.');
        }

        $tekanan = $this->rata($blok['tekanan_awal'] ?? null, $blok['tekanan_akhir'] ?? null);

        if ($tekanan === null) {
            return $this->gagalSesi(
                $titik,
                'Tekanan udara ruangan (hPa) belum diisi — densitas udara tidak bisa dihitung tanpa itu.',
            );
        }

        $suhuRuang = $this->rata($blok['suhu_awal'] ?? null, $blok['suhu_akhir'] ?? null);
        $kelembaban = $this->rata($blok['kelembaban_awal'] ?? null, $blok['kelembaban_akhir'] ?? null);

        if ($suhuRuang === null || $kelembaban === null) {
            return $this->gagalSesi($titik, 'Suhu ruangan / kelembaban belum lengkap.');
        }

        // Titik disortir lebih dulu. "Titik pertama" menentukan faktor koreksi
        // suhu SELURUH sesi (temuan 4) dan penyebut ketiga koefisien ber-`O55`
        // (temuan 7) — kalau urutannya ikut urutan array yang datang dari
        // `groupBy`, sesi yang sama bisa keluar angka beda tiap kali dihitung
        // ulang, dan bedanya muncul sebagai `hitung_ulang_beda` di tiap approve.
        $titik = array_values($titik);
        usort($titik, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        $siap = [];

        foreach ($titik as $t) {
            $massa = $this->deret($t['massa'] ?? []);
            $suhu = $this->deret($t['suhu'] ?? []);
            $n = TabelStandarHydrometer::PENGULANGAN;

            if (count($massa) !== $n || count($suhu) !== $n) {
                $ditolak[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d butuh tepat %d ulangan massa dan %d ulangan suhu; yang terkirim %d massa & %d suhu.',
                        $t['titik_ke'], $n, $n, count($massa), count($suhu),
                    ),
                ];

                continue;
            }

            $siap[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'titik_ukur' => (float) $t['titik_ukur'],
                'massa' => $massa,
                'suhu' => $suhu,
            ];
        }

        if ($siap === []) {
            return ['titik' => [], 'ditolak' => $ditolak, 'boleh_terbit' => false, 'praolah' => []];
        }

        $praolah = $this->praolah($siap, $blok, $diameter, $tekanan, $suhuRuang, $kelembaban, $massaUdaraMentah, $sinker);
        $hasil = [];

        foreach ($siap as $i => $t) {
            $hasil[] = $this->hitungTitik($i, $t, $praolah, $blok, $ditolak);
        }

        $hasil = array_values(array_filter($hasil));

        // Lantai CMC TIDAK diputuskan di sini — dia datang dari baris
        // `calibration_capabilities` lampiran akreditasi, dan kelas ini sengaja
        // tidak menyentuh database. Yang memasangnya
        // `HydrometerProfile::hitungPerGrup()`.
        $bolehTerbit = $hasil !== [];

        usort($ditolak, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return [
            'titik' => $hasil,
            'ditolak' => $ditolak,
            'boleh_terbit' => $bolehTerbit,
            'praolah' => $praolah,
        ];
    }

    /**
     * Langkah 1-7 & 11-12 §3: semua yang dihitung SEKALI per sesi, dipisah dari
     * yang per titik supaya urutannya tidak bisa tertukar.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, massa: list<float>, suhu: list<float>}>  $siap
     * @param  array<string, mixed>  $blok
     * @param  list<float>  $diameter
     * @return array<string, mixed>
     */
    private function praolah(
        array $siap,
        array $blok,
        array $diameter,
        float $tekanan,
        float $suhuRuang,
        float $kelembaban,
        float $massaUdara,
        ?float $sinker,
    ): array {
        $T = TabelStandarHydrometer::class;

        // (1) Densitas udara. Pembagian /1000 DI UJUNG, persis `PERHITUNGAN!J78`
        //     — bukan konstanta yang sudah dibagi, karena urutan operasi float
        //     ikut menentukan digit terakhir yang diadu test rekonsiliasi.
        $rhoUdara = ((0.34848 * $tekanan) - (0.009 * $kelembaban) * exp(0.061 * $suhuRuang))
            / ($suhuRuang + 273.15) / 1000;

        // (2) Faktor koreksi densitas cairan terhadap tekanan. Satuan campur —
        //     temuan 2.
        $fPress = 1 - ($T::KAPPA * ($tekanan - $T::TEKANAN_ACUAN_HPA));

        // (3) Faktor koreksi suhu — dari titik PERTAMA saja, temuan 4.
        $suhuAcuanFaktor = (float) ($blok['suhu_acuan_faktor'] ?? $T::SUHU_ACUAN_FAKTOR_BAWAAN);
        $suhuTitik1 = array_sum($siap[0]['suhu']) / count($siap[0]['suhu']);
        $fTemp = 1 + ($T::ALPHA * ($suhuTitik1 - $suhuAcuanFaktor));

        // (11) Diameter stem rata-rata & tegangan permukaan.
        $d = array_sum($diameter) / count($diameter);
        $yx = (float) ($blok['tegangan_permukaan'] ?? 0.0)
            * ($T::FAKTOR_TEGANGAN_KE_DYNE[(string) ($blok['satuan_tegangan'] ?? 'dyne/cm')] ?? 1.0);

        $suhuRataTitik = array_map(
            static fn (array $t): float => array_sum($t['suhu']) / count($t['suhu']),
            $siap,
        );
        $stPerTitik = array_map(static fn (float $s): float => $T::teganganPermukaan($s), $suhuRataTitik);

        // (12) γ_L memakai tegangan permukaan TERBESAR lintas titik
        //      (`PERHITUNGAN!L53`), bukan milik titiknya sendiri.
        $pidYx = ($T::PHI * $d * $yx) / $T::GRAVITASI_CM_S2;
        $pidYl = ($T::PHI * $d * max($stPerTitik)) / $T::GRAVITASI_CM_S2;

        // (7) Massa konvensional hydrometer di udara. Dua suku nol master
        //     (`AF86`, `AF87 − AF88`) ditulis apa adanya supaya bentuknya tetap
        //     kebaca sebagai rumus yang sama.
        $mAir = (($massaUdara - $T::ERR_TIMBANG) * (1 - ($rhoUdara / $T::DENSITAS_BEBAN_STANDAR))) - 0.0 - (0.0 - 0.0);

        $rhoAirPerTitik = array_map(
            static fn (array $t): float => array_sum(array_map(
                static fn (float $s): float => $T::densitasAirSuling($s),
                $t['suhu'],
            )) / count($t['suhu']),
            $siap,
        );

        // Massa terkoreksi di cairan, rata-rata per titik. Dihitung DI SINI
        // walau dipakai per titik, karena penyebut ketiga koefisien ber-`O55`
        // butuh milik SKALA 1 sementara koefisien itu lahir di skala mana pun
        // — termasuk skala 3 yang dihitung sebelum skala 1 kalau urutan
        // arraynya tidak dijaga.
        $mLiqPerTitik = array_map(
            fn (array $t): float => array_sum($this->massaDiCairan($t, $rhoUdara, $fTemp, $fPress, $sinker))
                / count($t['massa']),
            $siap,
        );

        return [
            'm_liq_per_titik' => $mLiqPerTitik,
            'rho_udara' => $rhoUdara,
            'f_press' => $fPress,
            'f_temp' => $fTemp,
            'suhu_ruang' => $suhuRuang,
            'kelembaban' => $kelembaban,
            'tekanan' => $tekanan,
            'diameter' => $d,
            'diameter_maks' => max($diameter),
            'diameter_min' => min($diameter),
            'tegangan_hydrometer' => $yx,
            'st_per_titik' => $stPerTitik,
            'st_maks' => max($stPerTitik),
            'st_min' => min($stPerTitik),
            'pid_yx' => $pidYx,
            'pid_yl' => $pidYl,
            'm_air' => $mAir,
            'sinker' => $sinker,
            'suhu_rata_titik' => $suhuRataTitik,
            'rho_air_per_titik' => $rhoAirPerTitik,
            'rho_air_titik1' => $rhoAirPerTitik[0],
            'rho_air_maks' => max($rhoAirPerTitik),
        ];
    }

    /**
     * Langkah 4-6 & 8-13 §3 untuk satu titik, plus budget §4-nya.
     *
     * Yang penting dan gampang kelewat: densitas dihitung **per ulangan**, lalu
     * ketiganya dirata-rata. Menghitung sekali dari massa & suhu yang sudah
     * dirata-rata memberi angka yang mirip tapi tidak sama — dan simpangan
     * bakunya, yang jadi komponen budget pertama, hilang sama sekali.
     *
     * @param  array{titik_ke: int, titik_ukur: float, massa: list<float>, suhu: list<float>}  $t
     * @param  array<string, mixed>  $praolah
     * @param  array<string, mixed>  $blok
     * @param  list<array{titik_ke: int, alasan: string}>  $ditolak
     * @return array<string, mixed>
     */
    private function hitungTitik(int $indeks, array $t, array $praolah, array $blok, array &$ditolak): array
    {
        $T = TabelStandarHydrometer::class;

        $rhoUdara = $praolah['rho_udara'];
        $fTemp = $praolah['f_temp'];
        $fPress = $praolah['f_press'];
        $mAir = $praolah['m_air'];
        $sinker = $praolah['sinker'];

        $mLiq = $this->massaDiCairan($t, $rhoUdara, $fTemp, $fPress, $sinker);
        $densitas = [];

        foreach ($t['massa'] as $u => $_) {
            // (4-5) Densitas air suling & densitas cairan terkoreksi ulangan ini.
            $rhoLiq = ($T::densitasAirSuling($t['suhu'][$u]) / ($fTemp * $fPress)) - $T::ERR_DENSITAS;

            // (13) Rumus Cuckow.
            $densitas[] = ((($rhoLiq * $fTemp) - ($rhoUdara * $fTemp))
                * (($mAir + $praolah['pid_yx']) / ($mAir - $mLiq[$u] + $praolah['pid_yl'])))
                + ($rhoUdara * $fTemp);
        }

        $rata = array_sum($densitas) / count($densitas);
        $stdev = $this->simpanganBaku($densitas);
        $mLiqRata = array_sum($mLiq) / count($mLiq);

        return [
            'titik_ke' => $t['titik_ke'],
            'titik_ukur' => $t['titik_ukur'],
            'densitas_per_ulangan' => $densitas,
            'densitas' => $rata,
            'simpangan_baku' => $stdev,
            'jumlah_pengulangan' => count($densitas),
            // Correction sertifikat = Actual − Nominal (`SERTIFIKAT!O17`).
            'koreksi' => $rata - $t['titik_ukur'],
            'massa_di_cairan' => $mLiqRata,
        ] + $this->budget($indeks, $t, $praolah, $blok, $stdev, $mLiqRata);
    }

    /**
     * Budget §4 — SEBELAS komponen untuk SATU titik skala.
     *
     * Ketujuh koefisien sensitivitasnya disalin VERBATIM dari sel masternya
     * (`NILAI U95%!C55`, `C57`, `C58`, `F55`, `F57`, `I55`, `I57`), bukan
     * diturunkan ulang secara simbolik. Bentuknya memang tidak menyederhana —
     * suku `(den·ΔH) − (ΔH·(M_air+E45))` misalnya bisa difaktorkan, dan hasil
     * faktorannya BEDA di digit ke-15 karena urutan float berubah. Itu digit
     * yang diadu test rekonsiliasi.
     *
     * @param  array{titik_ke: int, titik_ukur: float, massa: list<float>, suhu: list<float>}  $t
     * @param  array<string, mixed>  $praolah
     * @param  array<string, mixed>  $blok
     * @return array<string, mixed>
     */
    private function budget(int $indeks, array $t, array $praolah, array $blok, float $stdev, float $mLiqRata): array
    {
        $T = TabelStandarHydrometer::class;

        $rhoUdara = $praolah['rho_udara'];
        $fTemp = $praolah['f_temp'];
        $fPress = $praolah['f_press'];
        $mAir = $praolah['m_air'];
        $rhoAir1 = $praolah['rho_air_titik1'];
        $d = $praolah['diameter'];
        $yx = $praolah['tegangan_hydrometer'];
        $suhuRuang = $praolah['suhu_ruang'];
        $suhuTitik = $praolah['suhu_rata_titik'][$indeks];
        $ref = self::SUHU_ACUAN_BUDGET;

        // Ketidakpastian standar (`NILAI U95%!N43` & `PERHITUNGAN!P15`).
        $uThermo = $T::U95_THERMOMETER / $T::K_THERMOMETER;
        $uSensor = $T::U95_SENSOR / $T::K_SENSOR;
        $uTwater = (max($praolah['suhu_rata_titik']) - min($praolah['suhu_rata_titik'])) / (2 * sqrt(3));
        $ut = sqrt($uThermo ** 2 + $uSensor ** 2 + $uTwater ** 2);

        $deltaSuhu = abs((float) ($blok['suhu_akhir'] ?? 0.0) - (float) ($blok['suhu_awal'] ?? 0.0));
        $uth = sqrt($deltaSuhu ** 2 + $T::U95_TH_SUHU ** 2);

        // Suku antara `NILAI U95%` D43-D45 & J45/L45.
        $e43 = ($rhoAir1 * $d) / $fTemp;
        $e44 = ($rhoAir1 * $d * $T::ALPHA) / $fTemp;
        $e45 = ($rhoAir1 * $d * $yx) / $fTemp;
        $j45 = $e45 / $fTemp;
        $l45 = $e44 / $fTemp;

        // `fta`/`ftl` memakai faktor TEKANAN — temuan 3.
        $fta = 1 + ($fPress * ($suhuRuang - $ref));
        $ftl1 = 1 + ($fPress * ($praolah['suhu_rata_titik'][0] - $ref));
        $h44 = $rhoAir1 * $ftl1;
        $h45 = $rhoUdara * $fta;
        $dh = $h44 - $h45;

        // Penyebut SKALA 1 (`$O$55` absolut) dipakai koefisien 7.9, 7.10b &
        // 7.12 di semua skala — temuan 7. `+ $e44` ikut, persis sel masternya.
        $o55 = pow($mAir - $praolah['m_liq_per_titik'][0] + $e44, -2);

        $den = $mAir - $mLiqRata + $e44;
        $den2 = pow($den, -2);

        // Skala 1 memakai `(−E44)`, skala 2 dst `(−L45)` — temuan 8.
        $neg = $indeks === 0 ? $e44 : $l45;

        $cMassaUdara = (($den * $dh) - ($dh * ($mAir + $e45))) * $o55;
        $cStem = (($den * ($dh * $e45)) - ($dh * ($mAir + $e45) * $e44)) * $den2;
        $cMassaCair = ($dh * ($mAir + $e45)) * $o55;
        $cGravitasi = (($den * $dh * (-$j45)) - ($dh * ($mAir + $e45) * (-$neg))) * $den2;
        $cTegangan = ($dh * ($mAir + $e45) * $e43) * $o55;
        $cSuhuAir = $praolah['rho_air_maks'] * ($mAir + $e45) / $den;
        $cSuhuUdara = $rhoUdara - ($rhoUdara * ($mAir + $e45) / $den);

        $a = $T::ALPHA;
        $resolusi = (float) ($blok['resolusi'] ?? 0.0);

        $komponen = [
            $this->komponen('Hydrometer Indication', 'g/ml', 'T-Student', $stdev, sqrt(3), 2.0, 1.0),
            $this->komponen('Resolution of Hydrometer', 'g/ml', 'Rectangular', $resolusi, sqrt(12), self::VI_RECTANGULAR, 1.0),
            $this->komponen('Liquid Temperature', '°C', 'Normal', ($a ** 2 * $ut ** 2) + (($suhuTitik - $ref) ** 2 * $a ** 2), 2.0, self::VI_NORMAL, $cSuhuAir),
            $this->komponen('Air Temperature', '°C', 'Normal', ($a ** 2 * $uth ** 2) + (($suhuRuang - $ref) ** 2 * $a ** 2), 2.0, self::VI_NORMAL, $cSuhuUdara),
            $this->komponen('Density of Liquid Reference', 'g/ml', 'Normal', $T::U_DENSITAS_ACUAN, 2.0, self::VI_NORMAL, 1.0),
            $this->komponen('Density of Air', 'g/ml', 'Rectangular', 0.10 * $rhoUdara, sqrt(12), self::VI_RECTANGULAR, 1.0),
            $this->komponen('Mass of Hydrometer in the air', 'g', 'Normal', $T::uMassaAquadest(), 2.0, self::VI_NORMAL, $cMassaUdara),
            $this->komponen('Mass of Hydrometer in Liquid Ref', 'g', 'Normal', $T::uMassaAquadest(), 2.0, self::VI_NORMAL, $cMassaCair),
            $this->komponen('Stem Diameter', 'cm', 'Rectangular', $praolah['diameter_maks'] - $praolah['diameter_min'], sqrt(12), self::VI_RECTANGULAR, $cStem),
            $this->komponen('Local Gravity', 'm/s2', 'Rectangular', $T::U_GRAVITASI, sqrt(12), self::VI_RECTANGULAR, $cGravitasi),
            $this->komponen('Surface Tension of liquid reference', 'dyne/cm', 'Rectangular', $praolah['st_maks'] - $praolah['st_min'], sqrt(12), self::VI_RECTANGULAR, $cTegangan),
        ];

        // Agregasi GUM dipakai bersama — `v_eff` sudah DIPOTONG ke bawah di
        // sana (temuan 5), jadi `k`-nya sudah sama dengan `TINV` master.
        $agregat = ($this->gum ??= new GumCalculator)->agregasiBudget($komponen);

        // Type A & Type B sesi ini, buat kolom ringkas `uncertainty_calculations`.
        // Type A = komponen PERTAMA (`Hydrometer Indication`, simpangan baku
        // ketiga densitas dibagi √3); Type B = RSS sepuluh sisanya. Keduanya
        // diturunkan DARI daftar komponen yang sama, bukan dihitung ulang
        // sendiri — dua jalur yang menghitung hal yang sama pelan-pelan
        // berbeda, dan yang satu cuma dipakai kolom ringkas sehingga bedanya
        // tidak pernah kelihatan.
        $typeA = $komponen[0]['u'] * $komponen[0]['ci'];
        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_slice($komponen, 1),
        )));

        return [
            'komponen_budget' => $komponen,
            'type_a' => $typeA,
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $agregat['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $agregat['ketidakpastian_diperluas'],
            'koefisien_sensitivitas' => [
                'massa_di_udara' => $cMassaUdara,
                'diameter_stem' => $cStem,
                'massa_di_cairan' => $cMassaCair,
                'gravitasi_lokal' => $cGravitasi,
                'tegangan_permukaan' => $cTegangan,
                'suhu_air' => $cSuhuAir,
                'suhu_udara' => $cSuhuUdara,
            ],
        ];
    }

    /**
     * Langkah 6 & 8-9 §3 — massa terkoreksi hydrometer di cairan, per ulangan.
     *
     * Satu-satunya tempat rumus DUA VARIAN itu ditulis. Menyalinnya ke tempat
     * kedua adalah cara paling gampang bikin praolah dan hitung-titik pelan-pelan
     * berbeda: yang satu dipakai penyebut koefisien sensitivitas, yang satu
     * dipakai densitas terbit — dan selisihnya tidak muncul sebagai error.
     *
     * @param  array{massa: list<float>, suhu: list<float>}  $t
     * @return list<float>
     */
    private function massaDiCairan(array $t, float $rhoUdara, float $fTemp, float $fPress, ?float $sinker): array
    {
        $T = TabelStandarHydrometer::class;
        $hasil = [];

        foreach ($t['massa'] as $u => $massa) {
            $rhoLiq = ($T::densitasAirSuling($t['suhu'][$u]) / ($fTemp * $fPress)) - $T::ERR_DENSITAS;

            // Dua suku nol master (`AF86`, `AF87 − AF88`) ditulis apa adanya.
            $semu = (($massa - $T::ERR_TIMBANG) * (1 - ($rhoUdara / $T::DENSITAS_BEBAN_STANDAR))) - 0.0 - (0.0 - 0.0);

            $hasil[] = $sinker === null
                ? $semu
                : $semu - ($sinker * (1 - ($rhoLiq / $T::DENSITAS_SINKER)));
        }

        return $hasil;
    }

    /** @return array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float} */
    private function komponen(string $sumber, string $satuan, string $distribusi, float $u, float $pembagi, float $vi, float $ci): array
    {
        return [
            'sumber' => $sumber,
            'keterangan' => $sumber.' ('.$satuan.')',
            'distribusi' => $distribusi,
            'u' => $u / $pembagi,
            'ci' => $ci,
            'vi' => $vi,
        ];
    }

    /** Simpangan baku SAMPEL (pembagi n−1), sama dengan `STDEV()` Excel. */
    private function simpanganBaku(array $nilai): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $rata = array_sum($nilai) / $n;
        $jumlah = array_sum(array_map(static fn (float $x): float => ($x - $rata) ** 2, $nilai));

        return sqrt($jumlah / ($n - 1));
    }

    /** Rata-rata awal & akhir; `null` kalau salah satu ujungnya belum diisi. */
    private function rata(mixed $awal, mixed $akhir): ?float
    {
        $terisi = array_values(array_filter(
            [$awal, $akhir],
            static fn ($x): bool => $x !== null && $x !== '',
        ));

        if ($terisi === []) {
            return null;
        }

        return array_sum(array_map('floatval', $terisi)) / count($terisi);
    }

    /** @return list<float> */
    private function deret(mixed $nilai): array
    {
        return array_values(array_map('floatval', array_filter(
            (array) $nilai,
            static fn ($x): bool => $x !== null && $x !== '' && is_numeric($x),
        )));
    }

    /**
     * @param  list<array{titik_ke: int, ...}>  $titik
     * @return array{titik: list<array<string, mixed>>, ditolak: list<array{titik_ke: int, alasan: string}>, boleh_terbit: false, praolah: array<string, mixed>}
     */
    private function gagalSesi(array $titik, string $alasan): array
    {
        $ditolak = [['titik_ke' => 0, 'alasan' => $alasan]];

        foreach ($titik as $t) {
            $ditolak[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'alasan' => 'Blok Pre Condition sesi belum lengkap, jadi titik ini tidak dihitung. '.$alasan,
            ];
        }

        return ['titik' => [], 'ditolak' => $ditolak, 'boleh_terbit' => false, 'praolah' => []];
    }
}
