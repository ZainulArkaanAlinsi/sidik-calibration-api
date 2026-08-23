<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;
use App\Services\StudentTDistribution;

/**
 * Mesin hitung kalibrasi ENCLOSURE (Oven/Furnace/Bath/Inkubator/Refrigerator) —
 * MURNI: masuk array, keluar array, tidak menyentuh DB/request/Eloquent.
 *
 * Master: `Master Olah Data_Suhu_Enclosure_Constant_Yokogawa.xlsm` (sesi contoh
 * `0123-CAL-524`, Incubator-02, Yokogawa CA150 + Type N) dan
 * `Master Olah Data_Suhu_Enclosure_Recorder.xlsm` (sesi `0304-CAL-624`, Oven,
 * Graphtech GL840 + Type K). Sheet `PERHITUNGAN FC` + `PERHITUNGAN U95%`.
 *
 * ## Kenapa berdiri sendiri, bukan lewat `GumCalculator::hitungTitik()`
 *
 * Tiap komponen "Type-A"-nya STATISTIK GRID, bukan STDEV satu deret pembacaan:
 *
 *  - **Pengulangan Standar** = ½ × spread terbesar (max−min per sensor) dari 9
 *    termokopel.
 *  - **Pengulangan Indikator** = STDEV pembacaan Indikator enclosure (kanal lain,
 *    bukan termokopel).
 *  - **Keseragaman/Pembebanan** = deviasi maksimum antar sensor vs sensor acuan.
 *
 * `komponenBudget()` cuma nerima satu `$typeA` skalar dari satu deret pembacaan —
 * nggak cukup. Karena itu profil enclosure ambil alih `hitungPerGrup()` dan
 * manggil kalkulator ini per SET POINT (tiap set point punya U95 sendiri, beda
 * dari TITS yang satu U95 untuk seluruh sesi).
 *
 * ## Dua template budget: Constant/Yokogawa (11 komponen) vs Recorder (10)
 *
 * Kedua master beda budget-nya — bukan cuma angka, tapi struktur:
 *
 *   komponen                     Constant/Yokogawa      Recorder
 *   Efek Radiasi                 0,6 °C                 0,1 °C
 *   Efek Pembebanan              20% × deviasi maks     0,1 °C (konstan)
 *   Konduksi Panas               0,1 °C                 (tidak ada)
 *   pembagi drift kalibrator     1,73 (literal)         1,73 (literal)
 *   pembagi Pengulangan Standar  √3                     √(√3)  ← kuirk
 *   vi drift / radiasi / dst.    1.000.000              50 / 8 (kecil)
 *
 * ## Penyimpangan master yang SENGAJA ditiru (dengan catatan audit)
 *
 *  1. **`v_eff` tidak dipotong ke bawah** sebelum dicari `k` — sama seperti TITS
 *     & 10 alat lain di GUM G.4.1 justru MEMOTONG. Lihat [FLOOR_V_EFF].
 *  2. **Pembagi drift kalibrator `1,73` literal**, bukan √3 = 1,7320508. Kedua
 *     komponen drift (kalibrator & sensor). Lihat [PEMBAGI_DRIFT].
 *  3. **Pengulangan Standar Recorder dibagi √(√3)**, bukan √3 — sel
 *     `U29 = N29/SQRT(Q29)` dengan `Q29 = SQRT(3)`, akar diambil dua kali. Sama
 *     kelas bug dengan AC Pick Up di TITS. Lihat [PEMBAGI_PENGULANGAN_RECORDER].
 *  4. **Baris termokopel FC salin kolom**: pembacaan ke-5 DIBUANG dan pembacaan
 *     ke-3 DIGANDAKAN (`G←H, H←H, I←I`) — baris Indikator justru baca kelimanya
 *     benar. Lihat [PETA_KOLOM_PEMBACAAN].
 *
 * Semua penyimpangan mencetak `catatan_audit` yang menyebut hasil versi
 * dibetulkannya, biar manajer teknis lab yang memutuskan — bukan diam-diam kode.
 *
 * ## Yang TIDAK ditebak: dua hal yang dilaporkan, bukan ditambal
 *
 * Master selalu 9 termokopel × 5 pembacaan, jadi berkasnya nggak pernah menjawab
 * apa yang harus terjadi kalau lembarnya terisi sebagian. Dua lubangnya dijawab
 * dengan MELAPOR, bukan menebak — pemanggil (`EnclosureProfileBase`) memindahkan
 * set point-nya ke `belum_dihitung`:
 *
 *  - `koreksi_hilang` — sensor/kanal yang koreksinya nggak ada di tabel. Koreksi
 *    nol itu pernyataan, bukan ketiadaan data.
 *  - `pembacaan_kurang` — sensor berpembacaan di bawah [MIN_PEMBACAAN].
 *
 * Alasannya sama buat dua-duanya, dan lebih keras dari sekadar U95 meleset:
 * yang salah ikut kolom **Sebaran Suhu, Keseragaman, & Kestabilan** yang
 * TERCETAK di sertifikat — angka yang dibaca pelanggan sebagai hasil pengukuran,
 * dan yang nggak ketutup lantai CMC.
 *
 * @see TabelKalibratorEnclosure
 * @see docs/pertanyaan-lab-enclosure.md
 */
class EnclosureCalculator
{
    /**
     * Peta kolom pembacaan termokopel di sheet `PERHITUNGAN FC` master.
     *
     * INPUT punya 5 kolom pembacaan (1–5). Baris termokopel FC menyalinnya jadi
     * `[1,2,3,3,4]` — kolom G & H sama-sama menunjuk pembacaan ke-3, dan
     * pembacaan ke-5 tidak pernah dibaca (`G23=H23='INPUT'!H##`, `I23='INPUT'!I##`).
     * Baris Indikator (`D37:I37`) justru menyalin kelima kolom dengan benar.
     *
     * Ditiru supaya `AVG Terkoreksi`, `ΔT`, dan deviasi antar-sensor sama persis
     * dengan yang tercetak di sertifikat 0123-CAL-524. Berpengaruh cuma waktu
     * pembacaan ke-5 ≠ ke-3; U95 yang dilaporkan tetap sama (lantai CMC menang).
     *
     * @var list<int> index 0-based ke daftar 5 pembacaan mentah
     */
    public const PETA_KOLOM_PEMBACAAN = [0, 1, 2, 2, 3];

    /**
     * Pembacaan minimum per termokopel supaya [PETA_KOLOM_PEMBACAAN] bisa
     * dijalankan tanpa menebak: indeks tertinggi yang dibacanya `3`, jadi 4.
     *
     * Master sendiri selalu 5 kolom, dan kolom ke-5 memang dibuang — jadi grid
     * 4 pembacaan menghasilkan angka yang PERSIS SAMA dengan grid 5 pembacaan
     * yang kolom kelimanya berapa pun. Yang di bawah 4 tidak bisa: tidak ada
     * nilai buat kolom yang hilang selain menebaknya.
     */
    public const MIN_PEMBACAAN = 4;

    /** Jumlah termokopel yang dipakai master per set point (`INPUT DATA` baris 23–36). */
    public const SENSOR_MASTER = 9;

    /**
     * `v_eff` TIDAK dipotong ke bawah sebelum dicari `k` — master pakai
     * aproksimasi polinomial t-student atas `v_eff` pecahan apa adanya
     * (`AC## = 1.95996 + 2.37356/v + …`). Untuk enclosure `v_eff`-nya besar
     * (ratusan sampai ribuan), jadi selisih potong/tidak praktis nol — beda dari
     * TITS yang `v_eff`-nya kecil. Tetap ditiru & dicatat audit untuk konsistensi.
     */
    public const FLOOR_V_EFF = false;

    /**
     * Pembagi komponen drift kalibrator & drift sensor: `1,73` literal, bukan
     * √3 = 1,7320508. Muncul identik di kedua master (`Q26/Q27 = 1.73`).
     */
    public const PEMBAGI_DRIFT = 1.73;

    /**
     * Pembagi Pengulangan Pembacaan Standar di master RECORDER: `√(√3) ≈ 1,3161`.
     * Selnya `U29 = N29/SQRT(Q29)` dengan `Q29 = SQRT(3)` — akar diambil dua kali.
     * Constant/Yokogawa memakai √3 yang benar di komponen yang sama.
     */
    public const PEMBAGI_PENGULANGAN_RECORDER = 1.3160740129524924;

    /** Resolusi STD Meter (`DATABASE!V13`/`W13` = 0,1 °C), dibagi 2 di budget. */
    public const RESOLUSI_STANDAR = 0.1;

    /** Efek Radiasi — Constant/Yokogawa `N31 = 0.6`. */
    public const RADIASI_YOKO = 0.6;

    /** Efek Radiasi — Recorder `N31 = 0.1` (literal). */
    public const RADIASI_RECORDER = 0.1;

    /** Efek Pembebanan — Recorder `N32 = 0.1` (literal, tak bergantung data). */
    public const PEMBEBANAN_RECORDER = 0.1;

    /** Pengaruh Konduksi Panas — Constant/Yokogawa `N33 = 0.1` (Recorder tak punya). */
    public const KONDUKSI_PANAS = 0.1;

    /** Fraksi Efek Pembebanan dari deviasi maksimum — Constant/Yokogawa `20/100`. */
    public const FRAKSI_PEMBEBANAN = 0.2;

    /** Faktor cakupan sertifikat kalibrator & sensor — `Q23/Q24 = 2`. */
    public const K_STANDAR = 2.0;

    private ?GumCalculator $gum = null;

    private ?StudentTDistribution $t = null;

    public function __construct(private readonly TabelKalibratorEnclosure $tabel = new TabelKalibratorEnclosure) {}

    /**
     * Hitung seluruh sesi: satu hasil per set point.
     *
     * @param  list<array{setpoint: float, titik_ke?: int, sensors: list<array{no: int, channel?: int, pembacaan: list<float>}>, indikator: list<float>}>  $setpoints
     * @param  array{merk: string, tipe_sensor: string, cmc: float, resolusi_alat: float, resolusi_standar?: float}  $spek
     * @return list<array<string, mixed>>
     */
    public function hitungSesi(array $setpoints, array $spek): array
    {
        return array_values(array_map(
            fn (array $sp): array => $this->hitungSetpoint(
                $sp['sensors'],
                $sp['indikator'],
                (float) $sp['setpoint'],
                $spek,
                (int) ($sp['titik_ke'] ?? 0),
            ),
            $setpoints,
        ));
    }

    /**
     * Hitung satu SET POINT: rantai FC + budget + rollup + sebaran sensor.
     *
     * @param  list<array{no: int, channel?: int, pembacaan: list<float>}>  $sensors
     * @param  list<float>  $indikator
     * @param  array{merk: string, tipe_sensor: string, cmc: float, resolusi_alat: float, resolusi_standar?: float}  $spek
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public function hitungSetpoint(array $sensors, array $indikator, float $setpoint, array $spek, int $titikKe = 0): array
    {
        $merk = $spek['merk'];
        $tipe = $spek['tipe_sensor'];

        if (! in_array($merk, TabelKalibratorEnclosure::MERK, true)) {
            throw new \InvalidArgumentException("Merk kalibrator enclosure `{$merk}` nggak punya tabel.");
        }

        if (! in_array($tipe, TabelKalibratorEnclosure::TIPE_SENSOR, true)) {
            throw new \InvalidArgumentException("Tipe sensor enclosure `{$tipe}` nggak dikenal.");
        }

        $sensors = array_values($sensors);

        if ($sensors === []) {
            throw new \InvalidArgumentException("Set point {$setpoint} °C nggak punya satu pun sensor terisi.");
        }

        // Grid DIURUTKAN nomor termokopel di sini, bukan dipakai apa adanya.
        //
        // Sensor Acuan = sensor pertama (lihat di bawah), jadi urutan grid
        // menentukan ANGKA — dan sebelumnya urutannya beda per jalur: jalur
        // simpan memakai urutan array dari request, sementara jalur validasi &
        // `kalibrasi:hitung-ulang` menyusun ulang dari `raw_measurements` yang
        // dikelompokkan per `sensor_ke` (terurut nomor). Satu data mentah, dua
        // jawaban: pada grid contoh yang urutannya dibalik, Keseragaman jadi
        // 0,4 °C bukan 0,2 °C — DUA KALI LIPAT — dan U95 ikut bergeser.
        //
        // Akibatnya bukan cuma angka beda: `CalibrationValidator` membandingkan
        // hasil tersimpan vs hitung ulang, jadi tiap sesi yang gridnya nggak
        // dikirim urut nomor bakal ke-flag "data berubah sesudah submit" padahal
        // tidak ada yang berubah — dan `kalibrasi:hitung-ulang` diam-diam
        // menulis angka yang beda dari yang tercetak di sertifikat.
        //
        // Diurutkan DI SINI, di titik yang dilewati ketiga jalur, supaya nggak
        // ada pemanggil yang bisa salah. Nomor terkecil jadi acuan — sama
        // dengan kedua master (Type N mulai no. 3, Type K mulai no. 1, dan di
        // dua-duanya baris "Sensor Acuan" itu nomor terkecil).
        usort($sensors, static fn (array $a, array $b): int => (int) $a['no'] <=> (int) $b['no']);

        $berkanal = $merk === TabelKalibratorEnclosure::MERK_BERKANAL;
        $hasilSensor = [];

        foreach ($sensors as $s) {
            $hasilSensor[] = $this->hitungSensor($s, $merk, $tipe, $berkanal);
        }

        // Sensor Acuan = sensor pertama SESUDAH diurutkan di atas, alias
        // termokopel bernomor TERKECIL di set point ini (baris 23 master).
        //
        // Yang masih bisa menggeser acuan: sensor kosong dibuang di controller
        // sebelum sampai sini, jadi kalau termokopel bernomor terkecil kebetulan
        // nggak keisi, nomor berikutnya yang jadi acuan. Itu nggak bisa
        // diputuskan di sini — makanya nomornya ikut dipulangkan & dicetak di
        // jejak audit, supaya kegeserannya kelihatan waktu diperiksa.
        $acuan = $hasilSensor[0]['terkoreksi'];
        $noAcuan = $hasilSensor[0]['no'];

        // Stabilitas Suhu (R41) = ½ × spread terbesar antar sensor.
        $spreadMaks = max(array_map(static fn (array $h): float => $h['spread'], $hasilSensor));
        $r41 = 0.5 * $spreadMaks;

        // Keseragaman Suhu (R42) = deviasi absolut terbesar sensor NON-acuan vs
        // acuan, per pembacaan (master `MAX(AE39,AC40)` atas `AC24:AG36`).
        $r42 = $this->keseragaman($hasilSensor, $acuan);

        // Pengulangan Indikator (D41) = STDEV pembacaan Indikator enclosure.
        $indikator = array_values(array_map('floatval', $indikator));
        $rataIndikator = $this->rataRata($indikator);
        $d41 = $this->standarDeviasiSampel($indikator);

        // Sensor mana saja yang koreksinya nggak ketemu — dikumpulkan sekali,
        // dipakai dua kali (hasil + catatan audit).
        $koreksiHilang = [];

        // Sensor yang pembacaannya kurang dari [MIN_PEMBACAAN]. Sama sikapnya
        // dengan koreksi yang hilang: bukan dibulatkan atau ditambal, tapi
        // dilaporkan supaya set point-nya nggak diterbitkan.
        $pembacaanKurang = [];

        foreach ($hasilSensor as $h) {
            if ($h['koreksi_hilang'] !== []) {
                $koreksiHilang[] = [
                    'no' => $h['no'],
                    'channel' => $h['channel'],
                    'hilang' => $h['koreksi_hilang'],
                ];
            }

            if ($h['pembacaan_kurang'] !== null) {
                $pembacaanKurang[] = ['no' => $h['no'], 'jumlah' => $h['pembacaan_kurang']];
            }
        }

        // Sensor U95 terbesar (L37/N37) & index tertinggi (M37) untuk lookup U95
        // kalibrator.
        $sensorU95Maks = max(array_map(static fn (array $h): float => $h['u95_sensor'] ?? 0.0, $hasilSensor));
        $indexTertinggi = max(array_map(static fn (array $h): float => $h['index'], $hasilSensor));
        $kanalTertinggi = $this->kanalDiIndex($hasilSensor, $indexTertinggi);
        $calU95 = $this->tabel->u95Meter($merk, $tipe, $indexTertinggi, $kanalTertinggi);

        $resolusiAlat = (float) $spek['resolusi_alat'];
        $resolusiStandar = (float) ($spek['resolusi_standar'] ?? self::RESOLUSI_STANDAR);

        $budget = $this->budget(
            $merk, $tipe, $calU95, $sensorU95Maks, $r41, $d41, $r42, $resolusiAlat, $resolusiStandar,
        );

        $dipakai = array_values(array_filter($budget, static fn (array $k): bool => $k['disertakan']));
        $agg = $this->agregasi($dipakai);

        $cmc = (float) $spek['cmc'];
        $uHitung = $agg['ketidakpastian_diperluas'];

        return [
            'titik_ke' => $titikKe,
            'setpoint' => $setpoint,
            'merk_kalibrator' => $merk,
            'tipe_sensor' => $tipe,
            'sensor' => array_map(fn (array $h): array => [
                'no' => $h['no'],
                'channel' => $h['channel'],
                'rata_rata_terkoreksi' => $h['rata_rata'],
                // Koreksi sebaran = rata-rata sensor − suhu Indikator enclosure
                // (`SERTIFIKAT!F26 = F25 − B25`).
                'koreksi_vs_indikator' => $h['rata_rata'] - $rataIndikator,
                'index' => $h['index'],
            ], $hasilSensor),
            'indikator_enclosure' => $rataIndikator,
            // Nomor termokopel yang jadi Sensor Acuan — lihat [$acuan] di atas.
            'sensor_acuan' => $noAcuan,
            'jumlah_sensor' => count($hasilSensor),
            'kestabilan' => $r41,          // Stabilitas Suhu (SS)
            'keseragaman' => $r42,         // Keseragaman Suhu (KS)
            'variasi_keseluruhan' => $this->variasi($hasilSensor),
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agg['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agg['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agg['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $uHitung,
            'cmc' => $cmc,
            // Lantai CMC (ILAC-P14): U dilaporkan = MAX(U hitung, CMC).
            'u95_sertifikat' => max($uHitung, $cmc),
            'sumber_u95' => $cmc > $uHitung ? 'cmc' : 'hitung',
            // Sensor yang koreksinya nggak ketemu di tabel — lihat [hitungSensor].
            // Kosong = semua koreksi lengkap. Pemanggil WAJIB memeriksanya: set
            // point yang isinya nggak kosong nggak boleh diterbitkan.
            'koreksi_hilang' => $koreksiHilang,
            // Sensor yang pembacaannya kurang dari [MIN_PEMBACAAN]. Kosong =
            // semua sensor cukup. Alasan & kewajiban pemanggilnya sama dengan
            // `koreksi_hilang` di atas.
            'pembacaan_kurang' => $pembacaanKurang,
            'catatan_audit' => $this->catatanAudit(
                $budget, $dipakai, $uHitung, $cmc, $merk, $koreksiHilang, count($hasilSensor), $noAcuan,
            ),
        ];
    }

    /**
     * Rantai FC satu sensor: peta kolom → rata-rata → index → koreksi meter +
     * sensor → pembacaan terkoreksi.
     *
     * @param  array{no: int, channel?: int, pembacaan: list<float>}  $s
     * @return array<string, mixed>
     */
    private function hitungSensor(array $s, string $merk, string $tipe, bool $berkanal): array
    {
        $no = (int) $s['no'];
        $kanal = isset($s['channel']) ? (int) $s['channel'] : null;
        $mentah = array_values(array_map('floatval', $s['pembacaan']));

        // Peta kolom master menyalin `[1,2,3,3,4]`, jadi indeks tertinggi yang
        // pernah dibaca `3` — butuh MINIMAL 4 pembacaan.
        //
        // Yang kurang dari itu dulu ditambal diam-diam dengan mengulang
        // pembacaan terakhir. Tambalan itu bukan cuma menggeser U95: dia masuk
        // ke `AVG Terkoreksi` yang TERCETAK di kolom Sebaran Suhu sertifikat,
        // ke Keseragaman, dan ke Kestabilan — angka yang dibaca pelanggan
        // sebagai hasil pengukuran. Pada grid 3 pembacaan pergeserannya di
        // orde 0,06 °C, cukup buat mengubah kolom satu desimal.
        //
        // Sekarang: pembacaan dipakai apa adanya (nggak ditambal), dan
        // sensornya ditandai `pembacaan_kurang` supaya pemanggil memindahkan
        // set point-nya ke `belum_dihitung`. Teknisi mengisi kolom yang kurang,
        // bukan sistem menebak isinya.
        $cukup = count($mentah) >= self::MIN_PEMBACAAN;

        $dipetakan = $cukup
            ? array_map(fn (int $i): float => $mentah[$i], self::PETA_KOLOM_PEMBACAAN)
            : $mentah;

        $rataMentah = $this->rataRata($dipetakan);
        $index = $this->tabel->index($merk, $rataMentah) ?? $rataMentah;

        // Koreksi yang TIDAK ketemu di tabel dibiarkan `null`, bukan dijadikan 0.
        //
        // Koreksi nol itu PERNYATAAN ("standarnya tepat di titik ini"), bukan
        // ketiadaan data — sama sikapnya dengan TITS. Bedanya di sini lebih
        // mahal: `terkoreksi` yang salah tidak cuma menggeser U95, tapi juga
        // kolom Sebaran Suhu, Keseragaman, dan Kestabilan yang TERCETAK di
        // sertifikat. Dua kasus yang benar-benar bisa terjadi:
        //
        //  1. sesi Recorder yang termokopelnya nggak bawa nomor Channel —
        //     koreksi meter recorder dibaca per kanal;
        //  2. grid Type N yang dinomori mulai 1, padahal sertifikat sensor Type N
        //     lab mulai dari TCN3 (master sendiri menulis "If using Thermocouple
        //     Type N, No. Thermocouple START FROM 3").
        //
        // Keduanya salah input, dan yang benar menolak menghitung — bukan
        // menerbitkan sertifikat yang kelihatan sah. Pemanggil membaca
        // `koreksi_hilang` dan memindahkan set point-nya ke `belum_dihitung`.
        $koreksiMeter = $this->tabel->koreksiMeter($merk, $tipe, $index, $berkanal ? $kanal : null);
        $koreksiSensor = $this->tabel->koreksiSensor($merk, $tipe, $no, $index);
        $koreksi = ($koreksiMeter ?? 0.0) + ($koreksiSensor ?? 0.0);

        $terkoreksi = array_map(static fn (float $x): float => $x + $koreksi, $dipetakan);

        return [
            'no' => $no,
            'channel' => $kanal,
            'index' => $index,
            'terkoreksi' => $terkoreksi,
            'rata_rata' => $this->rataRata($terkoreksi),
            // Sensor tanpa satu pun pembacaan nggak punya spread — `max()`/`min()`
            // atas array kosong itu ValueError, dan sensor kosong memang bakal
            // ditolak lewat `pembacaan_kurang` sebelum angkanya kepakai.
            'spread' => $terkoreksi === [] ? 0.0 : max($terkoreksi) - min($terkoreksi),
            'u95_sensor' => $this->tabel->u95Sensor($merk, $tipe, $no, $index),
            'koreksi_hilang' => array_values(array_filter([
                $koreksiMeter === null ? ($berkanal && $kanal === null ? 'meter (kanal kosong)' : 'meter') : null,
                $koreksiSensor === null ? 'sensor' : null,
            ])),
            'pembacaan_kurang' => $cukup ? null : count($mentah),
        ];
    }

    /**
     * Deviasi absolut terbesar pembacaan sensor NON-acuan vs pembacaan acuan yang
     * sebersesuaian — `R42 = MAX(AE39, AC40)` atas rentang `AC24:AG36` master.
     *
     * @param  list<array<string, mixed>>  $hasilSensor
     * @param  list<float>  $acuan
     */
    private function keseragaman(array $hasilSensor, array $acuan): float
    {
        $maks = 0.0;

        foreach (array_slice($hasilSensor, 1) as $h) {
            foreach ($h['terkoreksi'] as $i => $nilai) {
                $maks = max($maks, abs($nilai - ($acuan[$i] ?? $nilai)));
            }
        }

        return $maks;
    }

    /**
     * Variasi Keseluruhan (VK) = `MAX − MIN` seluruh pembacaan terkoreksi semua
     * sensor (`R43 = MAX(R23:V36) − MIN(R23:V36)`).
     *
     * @param  list<array<string, mixed>>  $hasilSensor
     */
    private function variasi(array $hasilSensor): float
    {
        $semua = [];

        foreach ($hasilSensor as $h) {
            foreach ($h['terkoreksi'] as $nilai) {
                $semua[] = $nilai;
            }
        }

        return $semua === [] ? 0.0 : max($semua) - min($semua);
    }

    /** Kanal sensor yang index-nya sama dengan `$index` (untuk U95 kalibrator Recorder). */
    private function kanalDiIndex(array $hasilSensor, float $index): ?int
    {
        foreach ($hasilSensor as $h) {
            if ($h['index'] === $index && $h['channel'] !== null) {
                return $h['channel'];
            }
        }

        return null;
    }

    /**
     * Daftar komponen budget, sesuai template merk-nya.
     *
     * @return list<array<string, mixed>>
     */
    private function budget(
        string $merk,
        string $tipe,
        ?float $calU95,
        float $sensorU95Maks,
        float $r41,
        float $d41,
        float $r42,
        float $resolusiAlat,
        float $resolusiStandar,
    ): array {
        $sqrt3 = sqrt(3.0);
        $recorder = $merk === TabelKalibratorEnclosure::MERK_BERKANAL;
        $merkCetak = TabelKalibratorEnclosure::MERK_TERCETAK[$merk] ?? $merk;

        $driftMeter = $this->tabel->driftMeter($merk, $tipe);
        $driftSensor = $this->tabel->driftSensor($merk, $tipe);

        // vi berbeda antara dua master (Recorder derajat kebebasannya kecil).
        $viStandar = 200;
        $viDrift = $recorder ? 50 : 1_000_000;
        $viResStandar = $recorder ? 200 : 1_000_000;
        $viRadiasi = $recorder ? 8 : 1_000_000;
        $viPembebanan = $recorder ? 8 : 1_000_000;
        $viResAlat = 1_000_000;
        $viPengulanganStd = $recorder ? 4 : 1_000_000;
        $viIndikator = $recorder ? 5 : 4;

        $komponen = [
            [
                'sumber' => 'ketidakpastian_kalibrator',
                'keterangan' => $calU95 === null
                    ? sprintf('Sertifikat kalibrator %s %s — U95-nya kosong', $merkCetak, $tipe)
                    : sprintf('Sertifikat kalibrator %s %s (U=%s °C, k=2)', $merkCetak, $tipe, $this->angka($calU95)),
                'distribusi' => 'normal',
                'u' => ($calU95 ?? 0.0) / self::K_STANDAR,
                'ci' => 1.0,
                'vi' => $viStandar,
                'disertakan' => $calU95 !== null,
            ],
            [
                'sumber' => 'ketidakpastian_sensor',
                'keterangan' => sprintf('Sertifikat sensor/termokopel %s (U=%s °C, k=2)', $tipe, $this->angka($sensorU95Maks)),
                'distribusi' => 'normal',
                'u' => $sensorU95Maks / self::K_STANDAR,
                'ci' => 1.0,
                'vi' => $viStandar,
                'disertakan' => $sensorU95Maks > 0.0,
            ],
            [
                'sumber' => 'resolusi_standar',
                'keterangan' => sprintf('Daya baca STD Meter %s °C (÷2, ÷√3)', $this->angka($resolusiStandar)),
                'distribusi' => 'persegi',
                'u' => ($resolusiStandar / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => $viResStandar,
                'disertakan' => true,
            ],
            [
                'sumber' => 'drift_kalibrator',
                'keterangan' => $driftMeter === null
                    ? sprintf('Drift kalibrator %s %s — nggak ada di tabel', $merkCetak, $tipe)
                    : sprintf('Drift kalibrator %s %s (%s °C ÷1,73)', $merkCetak, $tipe, $this->angka($driftMeter)),
                'distribusi' => 'persegi',
                'u' => ($driftMeter ?? 0.0) / self::PEMBAGI_DRIFT,
                'ci' => 1.0,
                'vi' => $viDrift,
                'disertakan' => $driftMeter !== null,
            ],
            [
                'sumber' => 'drift_sensor',
                'keterangan' => $driftSensor === null
                    ? sprintf('Drift sensor %s — nggak ada di tabel', $tipe)
                    : sprintf('Drift sensor %s (%s °C ÷1,73)', $tipe, $this->angka($driftSensor)),
                'distribusi' => 'persegi',
                'u' => ($driftSensor ?? 0.0) / self::PEMBAGI_DRIFT,
                'ci' => 1.0,
                'vi' => $viDrift,
                'disertakan' => $driftSensor !== null,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat %s °C (÷2, ÷√3)', $this->angka($resolusiAlat)),
                'distribusi' => 'persegi',
                'u' => ($resolusiAlat / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => $viResAlat,
                'disertakan' => true,
            ],
            [
                'sumber' => 'pengulangan_standar',
                'keterangan' => $recorder
                    ? sprintf('Pengulangan pembacaan standar %s °C (½·spread, ÷√(√3) mengikuti master)', $this->angka($r41))
                    : sprintf('Pengulangan pembacaan standar %s °C (½·spread, ÷√3)', $this->angka($r41)),
                'distribusi' => 'persegi',
                'u' => $r41 / ($recorder ? self::PEMBAGI_PENGULANGAN_RECORDER : $sqrt3),
                'ci' => 1.0,
                'vi' => $viPengulanganStd,
                'disertakan' => true,
            ],
            [
                'sumber' => 'pengulangan_indikator',
                'keterangan' => sprintf('Pengulangan pembacaan Indikator enclosure %s °C (STDEV%s)', $this->angka($d41), $recorder ? ', ÷2' : ''),
                'distribusi' => $recorder ? 'normal' : 'persegi',
                'u' => $d41 / ($recorder ? 2.0 : 1.0),
                'ci' => 1.0,
                'vi' => $viIndikator,
                'disertakan' => true,
            ],
            [
                'sumber' => 'efek_radiasi',
                'keterangan' => sprintf('Efek radiasi %s °C (÷√3)', $this->angka($recorder ? self::RADIASI_RECORDER : self::RADIASI_YOKO)),
                'distribusi' => 'persegi',
                'u' => ($recorder ? self::RADIASI_RECORDER : self::RADIASI_YOKO) / $sqrt3,
                'ci' => 1.0,
                'vi' => $viRadiasi,
                'disertakan' => true,
            ],
            [
                'sumber' => 'efek_pembebanan',
                'keterangan' => $recorder
                    ? sprintf('Efek pembebanan %s °C (literal, ÷√3)', $this->angka(self::PEMBEBANAN_RECORDER))
                    : sprintf('Efek pembebanan 20%% × %s °C (÷√3)', $this->angka($r42)),
                'distribusi' => 'persegi',
                'u' => ($recorder ? self::PEMBEBANAN_RECORDER : self::FRAKSI_PEMBEBANAN * $r42) / $sqrt3,
                'ci' => 1.0,
                'vi' => $viPembebanan,
                'disertakan' => true,
            ],
        ];

        // Konduksi Panas cuma ada di master Constant/Yokogawa.
        if (! $recorder) {
            $komponen[] = [
                'sumber' => 'konduksi_panas',
                'keterangan' => sprintf('Pengaruh konduksi panas %s °C (÷√3)', $this->angka(self::KONDUKSI_PANAS)),
                'distribusi' => 'persegi',
                'u' => self::KONDUKSI_PANAS / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000,
                'disertakan' => true,
            ];
        }

        return $komponen;
    }

    /**
     * Agregasi GUM dengan `v_eff` TIDAK dipotong — sama pola dengan
     * [TitsCalculator::agregasi]: dijalankan dua kali, sekali untuk dapat `v_eff`,
     * sekali lagi dengan `k` dari `v_eff` pecahan itu.
     *
     * @param  list<array<string, mixed>>  $dipakai
     * @return array{ketidakpastian_gabungan: float, derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float, ketidakpastian_diperluas: float}
     */
    private function agregasi(array $dipakai): array
    {
        $gum = $this->gum ??= new GumCalculator;
        $komponen = array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $dipakai,
        );

        $agg = $gum->agregasiBudget($komponen);

        if (self::FLOOR_V_EFF || $agg['derajat_kebebasan_efektif'] === null) {
            return $agg;
        }

        $k = ($this->t ??= new StudentTDistribution)
            ->quantile(0.975, max(1.0, $agg['derajat_kebebasan_efektif']));

        return $gum->agregasiBudget($komponen, $k);
    }

    /**
     * Catatan audit: tiap penyimpangan master yang ditiru menyebut hasil versi
     * dibetulkannya.
     *
     * @param  list<array<string, mixed>>  $budget
     * @param  list<array<string, mixed>>  $dipakai
     * @return list<array<string, mixed>>
     */
    private function catatanAudit(
        array $budget,
        array $dipakai,
        float $uHitung,
        float $cmc,
        string $merk,
        array $koreksiHilang = [],
        int $jumlahSensor = self::SENSOR_MASTER,
        ?int $noAcuan = null,
    ): array {
        $gum = $this->gum ??= new GumCalculator;
        $catatan = [];
        $sqrt3 = sqrt(3.0);

        $uDengan = function (array $komponen) use ($gum): float {
            return $gum->agregasiBudget(array_map(
                static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
                $komponen,
            ))['ketidakpastian_diperluas'];
        };

        // 1. Pembagi drift 1,73 vs √3.
        $driftBenar = array_map(
            static fn (array $k): array => in_array($k['sumber'], ['drift_kalibrator', 'drift_sensor'], true)
                ? [...$k, 'u' => $k['u'] * self::PEMBAGI_DRIFT / sqrt(3.0)]
                : $k,
            $dipakai,
        );
        $catatan[] = [
            'kode' => 'pembagi_drift_173',
            'pesan' => sprintf(
                'Komponen drift dibagi 1,73 literal mengikuti master; dengan √3=%s U95 hitung jadi %s °C, bukan %s °C.',
                $this->angka($sqrt3),
                $this->angka($uDengan($driftBenar)),
                $this->angka($uHitung),
            ),
        ];

        // 2. v_eff tidak dipotong.
        $dipotong = $gum->agregasiBudget(array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $dipakai,
        ));
        $catatan[] = [
            'kode' => 'v_eff_tidak_dipotong',
            'pesan' => sprintf(
                'v_eff dipakai apa adanya mengikuti master (k=%s); dipotong ke bawah sesuai GUM G.4.1 k=%s, U=%s °C.',
                $this->angka($this->agregasi($dipakai)['faktor_cakupan_k']),
                $this->angka($dipotong['faktor_cakupan_k']),
                $this->angka($dipotong['ketidakpastian_diperluas']),
            ),
        ];

        // 3. Pengulangan Standar Recorder ÷√(√3).
        if ($merk === TabelKalibratorEnclosure::MERK_BERKANAL) {
            $benar = array_map(
                static fn (array $k): array => $k['sumber'] === 'pengulangan_standar'
                    ? [...$k, 'u' => $k['u'] * self::PEMBAGI_PENGULANGAN_RECORDER / sqrt(3.0)]
                    : $k,
                $dipakai,
            );
            $catatan[] = [
                'kode' => 'pembagi_pengulangan_sqrtsqrt3',
                'pesan' => sprintf(
                    'Pengulangan Standar dibagi √(√3) mengikuti master Recorder (sel U29=N29/SQRT(Q29)); '
                    .'dengan √3 U95 hitung jadi %s °C, bukan %s °C.',
                    $this->angka($uDengan($benar)),
                    $this->angka($uHitung),
                ),
            ];
        }

        // 4. Peta kolom pembacaan termokopel.
        $catatan[] = [
            'kode' => 'peta_kolom_pembacaan',
            'pesan' => 'Baris termokopel FC master menyalin pembacaan ke-3 dua kali dan membuang pembacaan ke-5 '
                .'(G←H, H←H, I←I); baris Indikator menyalin kelimanya benar. Ditiru supaya sebaran sensor sama '
                .'dengan sertifikat yang terbit.',
        ];

        // 5. Koreksi sensor/meter yang nggak ketemu di tabel — set point ini
        // TIDAK boleh diterbitkan; pemanggil memindahkannya ke `belum_dihitung`.
        foreach ($koreksiHilang as $h) {
            $catatan[] = [
                'kode' => 'koreksi_hilang',
                'pesan' => sprintf(
                    'Sensor no. %d%s nggak punya koreksi %s di tabel kalibrator — set point ini nggak dihitung. '
                    .'Koreksi yang hilang nggak boleh dianggap nol: sebaran suhu, keseragaman, & kestabilan yang '
                    .'tercetak ikut salah, bukan cuma U95.',
                    $h['no'],
                    $h['channel'] !== null ? sprintf(' (kanal %d)', $h['channel']) : '',
                    implode(' & ', $h['hilang']),
                ),
            ];
        }

        // 6. Grid lebih tipis dari master. Bukan penolakan — chamber kecil
        // kadang dipetakan dengan titik lebih sedikit — tapi Keseragaman &
        // Variasi turun artinya, dan itu harus kelihatan di jejak audit
        // ketimbang cuma jadi angka yang lebih kecil di sertifikat.
        if ($jumlahSensor < self::SENSOR_MASTER) {
            $catatan[] = [
                'kode' => 'sensor_kurang_dari_master',
                'pesan' => sprintf(
                    'Set point ini dipetakan dengan %d termokopel; master pakai %d. Keseragaman & Variasi '
                    .'cuma mewakili posisi yang benar-benar diukur — bukan seluruh ruang chamber.',
                    $jumlahSensor,
                    self::SENSOR_MASTER,
                ),
            ];
        }

        // 7. Sensor acuan yang dipakai. Dicatat karena sensor yang kosong dibuang
        // sebelum sampai sini, jadi acuannya bisa bergeser ke nomor berikutnya.
        if ($noAcuan !== null) {
            $catatan[] = [
                'kode' => 'sensor_acuan',
                'pesan' => sprintf(
                    'Keseragaman diukur relatif ke termokopel no. %d (nomor terkecil yang terisi di set point '
                    .'ini). Pastikan itu memang Sensor Acuan yang dimaksud di lembar kerja.',
                    $noAcuan,
                ),
            ];
        }

        // 8. Komponen tanpa data.
        foreach ($budget as $k) {
            if (! $k['disertakan']) {
                $catatan[] = [
                    'kode' => 'komponen_tanpa_data',
                    'pesan' => sprintf('Komponen `%s` nggak ikut dihitung: %s.', $k['sumber'], $k['keterangan']),
                ];
            }
        }

        if ($cmc > $uHitung) {
            $catatan[] = [
                'kode' => 'lantai_cmc',
                'pesan' => sprintf(
                    'U95 hitung %s °C di bawah CMC lab %s °C — yang dilaporkan CMC (ILAC-P14).',
                    $this->angka($uHitung),
                    $this->angka($cmc),
                ),
            ];
        }

        return $catatan;
    }

    /**
     * STDEV sampel (Excel `STDEV`, penyebut n−1).
     *
     * @param  list<float>  $nilai
     */
    private function standarDeviasiSampel(array $nilai): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $rata = $this->rataRata($nilai);
        $jumlahKuadrat = array_sum(array_map(static fn (float $x): float => ($x - $rata) ** 2, $nilai));

        return sqrt($jumlahKuadrat / ($n - 1));
    }

    /** @param  list<float>  $nilai */
    private function rataRata(array $nilai): float
    {
        return $nilai === [] ? 0.0 : array_sum($nilai) / count($nilai);
    }

    /** Angka buat teks keterangan: presisi cukup, tanpa nol belakang. */
    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 8, '.', ''), '0'), '.') ?: '0';
    }
}
