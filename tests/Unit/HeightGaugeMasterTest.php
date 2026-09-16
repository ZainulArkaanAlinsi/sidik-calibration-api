<?php

namespace Tests\Unit;

use App\Services\Calibration\HeightGaugeCalculator;
use App\Services\Calibration\TabelStandarHeightGauge;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * Adu mesin hitung Height Gauge ke workbook master ber-password yang turun dari
 * lab — sel demi sel, bukan cuma U95 akhirnya.
 *
 * Fixture `database/data/sesi-master-height-gauge.json` memuat masukan mentah
 * (paralelisme, pra-evaluasi, sepuluh titik) DAN blok `_acuan_master` berisi
 * nilai yang tercetak di masternya sendiri — disalin dari sel workbook
 * (`data_only`) oleh `docs/skrip/gen-sesi-height-gauge.py`, bukan dari keluaran
 * PHP.
 *
 * ## Kenapa tiap KOMPONEN diadu, bukan cuma U95-nya
 *
 * U95 akhir bisa cocok sementara isinya salah: dua komponen yang saling
 * menutupi menghasilkan `uc` yang sama. Di alat ini bukan kekhawatiran
 * teoretis — komponen ke-9 ("Selisih suhu") NOL menurut konstruksi, jadi
 * komponen ke-4 bisa mendarat di slot yang salah tanpa menggeser U95 sama
 * sekali.
 *
 * ## Umur drift diberi SAMA dengan master, bukan tanggal sesi
 *
 * `DATABASE!X11` master berisi `=NOW()`, jadi komponen drift-nya tergantung
 * kapan berkasnya terakhir dibuka — di snapshot yang kami terima
 * 2026-06-11 15:55:46, sementara sesinya dikalibrasi 2026-05-05 (selisih
 * 153,66 vs 116 hari). Di produksi umur itu dihitung dari tanggal kalibrasi
 * SESI supaya bisa diulang; di sini test sengaja memberi saat yang sama dengan
 * master, karena yang diuji **rumusnya reproduksi**, bukan pilihan tanggalnya.
 *
 * Caranya bukan lewat parameter tembus: `tanggal_kalibrasi` disetel ke `NOW()`
 * master itu sendiri, jadi jalur umur drift yang diuji sama persis dengan yang
 * dipakai produksi. Menambah kunci "umur langsung" cuma untuk test berarti ada
 * jalur yang cuma hidup di test — dan jalur seperti itu selalu berakhir dipakai
 * produksi.
 *
 * ## Satu hal yang SENGAJA beda dari master
 *
 * Suku termal per titik. `PERHITUNGAN` kolom `M`..`T` master cuma terisi di
 * baris 35 (titik 25 mm); baris 38..62 kosong dan rumus `Y`-nya membaca sel
 * kosong sebagai nol. Hari ini tidak menggeser satu angka pun (`T35 = 0` dan
 * `R35 = 0` juga), jadi kesepuluh koreksi tetap cocok. Arahnya ditegakkan
 * [test_dengan_delta_suhu_bukan_nol_koreksi_titik_terakhir_ikut_berubah]:
 * begitu δϴ ≠ 0, koreksi titik ke-10 wajib IKUT berubah — bukan diam seperti
 * di master.
 */
class HeightGaugeMasterTest extends TestCase
{
    /** Toleransi yang diminta spesifikasi alat ke-26: 5·10⁻⁶. */
    private const TOLERANSI = 5e-6;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        static $data = null;

        return $data ??= json_decode(
            (string) file_get_contents(database_path('data/sesi-master-height-gauge.json')),
            true,
        );
    }

    /**
     * Susun argumen `hitungSesi()` dari fixture, dengan tanggal kalibrasi
     * dipatok ke `NOW()` master supaya komponen drift bisa diadu.
     *
     * @return array{list<array<string, mixed>>, array<string, mixed>}
     */
    private function masukan(): array
    {
        $f = self::fixture();

        $titik = array_map(static fn (array $t): array => [
            'titik_ke' => (int) $t['titik_ke'],
            'nominal' => [(float) $t['nominal_mm']],
            'pembacaan' => array_map('floatval', $t['pembacaan_mm']),
        ], $f['titik']);

        $konteks = [
            'resolusi_mm' => (float) $f['_sesi']['resolusi_mm'],
            'tanggal_kalibrasi' => $this->saatMaster(),
            'pra_evaluasi' => array_map('floatval', $f['pra_evaluasi_mm']),
            'paralelisme' => array_map('floatval', $f['paralelisme_mm']),
            'suhu_ruang_rata_c' => ((float) $f['_sesi']['suhu_awal'] + (float) $f['_sesi']['suhu_akhir']) / 2,
        ];

        return [$titik, $konteks];
    }

    /**
     * Saat `NOW()` master, diturunkan dari umur drift yang tersimpan di
     * fixture — bukan diketik ulang di sini.
     *
     * Dihitung dari tanggal kalibrasi Caliper Checker + umur, supaya kalau
     * sertifikat standarnya berganti tahun depan yang bergeser cuma SATU angka
     * di tabel dan test ini ikut bergeser bareng.
     */
    private function saatMaster(): DateTimeImmutable
    {
        $standar = new DateTimeImmutable((new TabelStandarHeightGauge)->standar()['tanggal_kalibrasi']);
        $umur = (float) self::fixture()['_acuan_master']['umur_drift_hari'];

        return $standar->modify('+'.(int) round($umur * 86400).' seconds');
    }

    public function test_sepuluh_nominal_pra_cetak_sama_dengan_tabel_outside(): void
    {
        $f = self::fixture();

        $this->assertSame(
            array_map(static fn (array $t): float => (float) $t['nominal_mm'], $f['titik']),
            (new TabelStandarHeightGauge)->titikPraCetak(),
            'Sepuluh titik `INPUT DATA` master harus sama persis dengan sepuluh baris tabel Outside '
            .'`Std_CaliperCek` — Instruksi Kerja yang menetapkannya, dan `titik_bisa_diubah = false` '
            .'bertumpu pada keduanya tetap satu daftar.',
        );
    }

    public function test_nilai_terkoreksi_caliper_checker_cocok_master(): void
    {
        $tabel = new TabelStandarHeightGauge;

        // Std_CaliperCek!F10:F19 — nilai terkoreksi yang dipungut
        // `VLOOKUP(nominal; Nom_Outside; 4; 0)`.
        $master = [
            25 => 25.0008, 50 => 50.0004, 100 => 100.0005, 150 => 150.0003,
            200 => 199.9993, 300 => 299.99955, 400 => 399.9982, 500 => 499.99865,
            550 => 549.9978, 600 => 599.9973,
        ];

        foreach ($master as $nominal => $terkoreksi) {
            $this->assertEqualsWithDelta(
                $terkoreksi,
                $tabel->nilaiTerkoreksi((float) $nominal),
                1e-12,
                "Nilai terkoreksi Caliper Checker di nominal {$nominal} mm meleset dari master.",
            );
        }
    }

    public function test_nominal_di_luar_tabel_balik_null_bukan_nol(): void
    {
        $tabel = new TabelStandarHeightGauge;

        // 250 mm ada di antara dua baris tabel, tapi BUKAN salah satunya.
        // Master membungkus VLOOKUP-nya `IFERROR(...; "")` sehingga titiknya
        // lenyap dari sertifikat tanpa error; di sini dia wajib `null` supaya
        // pemanggil bisa mengangkatnya jadi titik yang diblokir.
        $this->assertNull($tabel->nilaiTerkoreksi(250.0));
        $this->assertNull((new HeightGaugeCalculator)->totalNominal([250.0]));
    }

    /**
     * Paralelisme = RENTANG (ISO 1101), bukan `STDEV(Max; Min)` master.
     *
     * Lab menjawab §5 pada 16 Sep 2026. Pembagi √2 milik master membuat hasil
     * selalu 29 % lebih kecil dari rentangnya, dan itu MELULUSKAN alat yang
     * seharusnya gagal — satu-satunya penyimpangan Height Gauge yang arah
     * salahnya merugikan penerima sertifikat.
     */
    public function test_paralelisme_memakai_rentang_bukan_stdev_master(): void
    {
        $f = self::fixture();
        $hasil = (new HeightGaugeCalculator)->paralelisme(array_map('floatval', $f['paralelisme_mm']));

        $this->assertNotNull($hasil);
        $this->assertEqualsWithDelta(0.002, $hasil['maks'], 1e-12);
        $this->assertEqualsWithDelta(0.0, $hasil['min'], 1e-12);
        $this->assertEqualsWithDelta(0.002, $hasil['hasil'], self::TOLERANSI, 'Max − Min');

        // Wajib LEBIH BESAR dari master: kalau tidak, pembagi √2-nya balik.
        $this->assertGreaterThan(
            (float) $f['_acuan_master']['paralelisme_hasil_mm'],
            $hasil['hasil'],
            'Master `STDEV(Max; Min)` = 0,0014142; rentangnya 0,002.',
        );
        $this->assertTrue($hasil['lulus'], '0,002 mm masih di dalam batas 0,01 mm.');

        // Pita yang dulu lolos HANYA karena dibagi √2 sekarang gagal — itu
        // seluruh alasan perubahan ini.
        $duluLolos = (new HeightGaugeCalculator)->paralelisme([0.0, 0.012]);
        $this->assertNotNull($duluLolos);
        $this->assertFalse($duluLolos['lulus'], '0,012 mm > batas 0,01 mm, walau STDEV-nya 0,0085.');
    }

    public function test_paralelisme_kurang_dari_dua_pembacaan_balik_null(): void
    {
        // Satu pembacaan tidak punya Max dan Min yang berbeda, dan "hasil 0"
        // dari satu angka terbaca seperti paralelisme sempurna.
        $this->assertNull((new HeightGaugeCalculator)->paralelisme([0.001]));
        $this->assertNull((new HeightGaugeCalculator)->paralelisme([]));
    }

    public function test_simpangan_baku_pra_evaluasi_cocok_n30_master(): void
    {
        $f = self::fixture();

        $this->assertEqualsWithDelta(
            (float) $f['_acuan_master']['stdev_pra_evaluasi_mm'],
            (new HeightGaugeCalculator)->simpanganBaku(array_map('floatval', $f['pra_evaluasi_mm'])),
            self::TOLERANSI,
            '`PERHITUNGAN!N30` — satu-satunya sumber Repeatability seluruh sesi.',
        );
    }

    /** Kesepuluh koreksi `PERHITUNGAN!AA35..AA62`, diadu ke `SERTIFIKAT!L24:L33`. */
    public function test_sepuluh_koreksi_cocok_master(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new HeightGaugeCalculator)->hitungSesi($titik, $konteks);
        $master = self::fixture()['_acuan_master']['koreksi_mm'];

        $this->assertCount(10, $hasil['titik'], 'Kesepuluh titik harus terhitung, tidak ada yang ditolak.');

        foreach ($hasil['titik'] as $i => $t) {
            $this->assertEqualsWithDelta(
                (float) $master[$i],
                (float) $t['koreksi'],
                self::TOLERANSI,
                sprintf('Koreksi titik %d (%s mm) meleset dari master.', $i + 1, $t['nominal'][0]),
            );
        }
    }

    /**
     * Tiap komponen budget diadu ke `PERHITUNGAN U95%` master — `u`, `ci`, dan
     * `vi`, bukan cuma hasil akhirnya.
     */
    public function test_sembilan_komponen_budget_cocok_master(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new HeightGaugeCalculator)->hitungSesi($titik, $konteks);
        $master = self::fixture()['_acuan_master']['komponen'];

        $this->assertCount(9, $hasil['budget'], 'Sheet `PERHITUNGAN U95%` master punya sembilan komponen.');

        foreach ($hasil['budget'] as $i => $b) {
            $label = sprintf('Komponen %d (%s)', $i + 1, $master[$i]['nama']);

            // Drift standar SENGAJA menyimpang sejak 16 Sep 2026: umur dibagi
            // 365 HARI, bukan 12 seperti `K10` master — komponennya mm/tahun
            // dan selisih tanggalnya hari (butir 5 paket keputusan, disetujui
            // pemilik proyek). Rasionya persis 12/365.
            if ($b['sumber'] === 'drift_standar') {
                $this->assertEqualsWithDelta(
                    (float) $master[$i]['ui'] * 12.0 / 365.0,
                    (float) $b['u'],
                    self::TOLERANSI,
                    "{$label}: `ui` drift wajib = master × 12/365.",
                );
                $this->assertEqualsWithDelta((float) $master[$i]['ci'], (float) $b['ci'], self::TOLERANSI, "{$label}: ci");
                $this->assertEqualsWithDelta((float) $master[$i]['vi'], (float) $b['vi'], 1e-12, "{$label}: vi");

                continue;
            }

            $this->assertEqualsWithDelta(
                (float) $master[$i]['ui'],
                (float) $b['u'],
                self::TOLERANSI,
                "{$label}: `ui` (u / pembagi) meleset dari master.",
            );
            $this->assertEqualsWithDelta(
                (float) $master[$i]['ci'],
                (float) $b['ci'],
                self::TOLERANSI,
                "{$label}: `ci` meleset dari master.",
            );
            $this->assertEqualsWithDelta(
                (float) $master[$i]['vi'],
                (float) $b['vi'],
                1e-12,
                "{$label}: `vi` meleset dari master.",
            );
        }
    }

    /** Kelima angka agregat `PERHITUNGAN U95%` master. */
    public function test_agregat_budget_cocok_master(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new HeightGaugeCalculator)->hitungSesi($titik, $konteks);
        $m = self::fixture()['_acuan_master'];

        $jumlahKuadrat = array_sum(array_map(
            static fn (array $b): float => ($b['u'] * $b['ci']) ** 2,
            $hasil['budget'],
        ));
        $jumlahPangkat4 = array_sum(array_map(
            static fn (array $b): float => $b['vi'] > 0 ? (($b['u'] * $b['ci']) ** 4) / $b['vi'] : 0.0,
            $hasil['budget'],
        ));

        // Agregatnya bergeser TIPIS dari master karena komponen drift memakai
        // /365 (butir 5). Yang diadu arahnya — wajib lebih kecil, dan cuma
        // sebesar sumbangan drift itu, bukan lebih.
        $this->assertLessThan((float) $m['jumlah_uici_kuadrat'], $jumlahKuadrat, 'Σ(ui·ci)²');
        $this->assertGreaterThan((float) $m['jumlah_uici_kuadrat'] * 0.95, $jumlahKuadrat, 'Σ(ui·ci)² turun terlalu jauh');
        $this->assertLessThan((float) $m['uc_mm'], $hasil['ketidakpastian_gabungan'], 'uc');
        $this->assertGreaterThan((float) $m['uc_mm'] * 0.95, $hasil['ketidakpastian_gabungan'], 'uc turun terlalu jauh');
        // `k` ikut bergeser karena v_eff berubah bersama komponen drift.
        $this->assertEqualsWithDelta((float) $m['k'], $hasil['faktor_cakupan_k'], 2e-2, 'k = TINV(0,05; veff)');
        $this->assertLessThan((float) $m['u_diperluas_mm'], $hasil['ketidakpastian_diperluas'], 'U = k · uc');
        $this->assertGreaterThan((float) $m['u_diperluas_mm'] * 0.95, $hasil['ketidakpastian_diperluas'], 'U turun terlalu jauh');
    }

    /**
     * Budget-nya **mm**, bukan µm — jebakan yang paling gampang kelewat kalau
     * Micrometer dipakai sebagai contekan.
     *
     * `AF18 = I5 = "mm"`. Kalau ada yang menyeret konversi ÷1000 dari
     * `MicrometerProfile`, U95 yang terbit jadi seribu kali terlalu kecil dan
     * tidak ada lantai CMC yang menahannya.
     */
    public function test_budget_dalam_mm_bukan_mikrometer(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new HeightGaugeCalculator)->hitungSesi($titik, $konteks);

        $this->assertEqualsWithDelta(0.0156, $hasil['u95_sertifikat'], 5e-4);
        $this->assertSame('mm', $hasil['budget'][0]['satuan']);
        $this->assertSame('mm', $hasil['budget'][1]['satuan']);
    }

    /**
     * TIDAK ada lantai CMC — `U95 = U` telanjang, dan itu BENAR.
     *
     * `AA19` master kosong karena Height Gauge di luar lampiran LK-285-IDN.
     * Kalau suatu saat ada yang memungut `CMC_UTM` (`CMC 0-300mm = 15 µm`)
     * sebagai lantai, yang tercetak jadi klaim akreditasi untuk lingkup yang
     * tidak diakreditasi.
     */
    public function test_u95_sama_dengan_u_tanpa_lantai_cmc(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new HeightGaugeCalculator)->hitungSesi($titik, $konteks);

        $this->assertSame(
            $hasil['ketidakpastian_diperluas'],
            $hasil['u95_sertifikat'],
            'U95 sertifikat wajib sama persis dengan U hitung — tidak ada `max(U, CMC)` di alat ini.',
        );
    }

    /**
     * Penyimpangan §4.B yang TIDAK ditiru: suku termal wajib hidup di
     * KESEPULUH titik, bukan cuma titik pertama.
     *
     * Master mengisi kolom `M`..`T` cuma di baris 35, dan rumus `Y` baris
     * 38..62 membaca sel kosong sebagai nol. Begitu suhu UUT ≠ suhu standar,
     * di master cuma titik pertama yang terkoreksi; di sini kesepuluhnya.
     *
     * Yang diuji ARAHnya, bukan sekadar "berbeda": koreksi titik ke-10 harus
     * bergeser sebesar `H · α_avg · δϴ`, dan itu angka yang bisa dihitung
     * tangan.
     */
    public function test_dengan_delta_suhu_bukan_nol_koreksi_titik_terakhir_ikut_berubah(): void
    {
        [$titik, $konteks] = $this->masukan();
        $kalk = new HeightGaugeCalculator;

        $dasar = $kalk->hitungSesi($titik, $konteks);

        // Suhu UUT 5 °C di atas suhu Caliper Checker — δϴ = 5.
        $panas = $kalk->hitungSesi($titik, ['suhu_uut_c' => $konteks['suhu_ruang_rata_c'] + 5.0] + $konteks);

        $terakhirDasar = $dasar['titik'][9];
        $terakhirPanas = $panas['titik'][9];

        $this->assertNotEqualsWithDelta(
            (float) $terakhirDasar['koreksi'],
            (float) $terakhirPanas['koreksi'],
            1e-9,
            'Koreksi titik ke-10 TIDAK bergeser waktu δϴ ≠ 0 — itu berarti suku termal master yang '
            .'cuma terisi di baris 35 ikut tersalin, dan sembilan titik lainnya diam-diam salah.',
        );

        // Besarnya: H · α_avg · δϴ, dengan H = 599,9973 dan α_avg = 1,2e-6.
        $this->assertEqualsWithDelta(
            599.9973 * 1.2e-6 * 5.0,
            (float) $terakhirPanas['koreksi'] - (float) $terakhirDasar['koreksi'],
            1e-9,
            'Pergeseran koreksi titik ke-10 harus persis `H · α_avg · δϴ`.',
        );

        // Titik PERTAMA juga bergeser — itu yang di master memang benar, dan
        // dibandingkan supaya kalau suatu saat suku termalnya dipindah ke satu
        // titik saja, test ini tetap merah.
        $this->assertEqualsWithDelta(
            25.0008 * 1.2e-6 * 5.0,
            (float) $panas['titik'][0]['koreksi'] - (float) $dasar['titik'][0]['koreksi'],
            1e-9,
        );
    }

    /**
     * `Lmaks` datang dari nominal titik TERBESAR di sesi, bukan kapasitas alat.
     *
     * `PERHITUNGAN!C65 = MAX(C35:E64)`. Di sesi contoh keduanya kebetulan
     * 600 mm; sesi yang berhenti di 300 mm harus memungut `ci` separuhnya,
     * bukan tetap 600.
     */
    public function test_lmaks_dari_titik_terbesar_bukan_kapasitas_alat(): void
    {
        [$titik, $konteks] = $this->masukan();
        $kalk = new HeightGaugeCalculator;

        $penuh = $kalk->hitungSesi($titik, $konteks);
        // Enam titik pertama saja — titik terbesarnya jadi 300 mm.
        $separuh = $kalk->hitungSesi(array_slice($titik, 0, 6), $konteks);

        $ciSuhuPenuh = (float) $penuh['budget'][3]['ci'];
        $ciSuhuSeparuh = (float) $separuh['budget'][3]['ci'];

        $this->assertEqualsWithDelta(600.0 * 2e-6, $ciSuhuPenuh, 1e-12);
        $this->assertEqualsWithDelta(300.0 * 2e-6, $ciSuhuSeparuh, 1e-12);
        $this->assertLessThan(
            $ciSuhuPenuh,
            $ciSuhuSeparuh,
            '`Lmaks` harus mengecil bareng titik terbesarnya. Kalau dia dipatok kapasitas alat, sesi '
            .'yang berhenti di tengah rentang memungut ci dua kali lipat haknya.',
        );
    }

    /**
     * Umur drift dari TANGGAL SESI, bukan `NOW()` — dan bedanya nyata.
     *
     * Master menghitung 153,66 hari (dari `NOW()` 2026-06-11) sementara sesinya
     * dikalibrasi 2026-05-05, yaitu 116 hari. Yang terbit karena itu 0,0156260
     * mm, bukan 0,0156680 mm.
     */
    public function test_umur_drift_dari_tanggal_sesi_bukan_now(): void
    {
        [$titik, $konteks] = $this->masukan();
        $kalk = new HeightGaugeCalculator;

        $tanggalSesi = new DateTimeImmutable(self::fixture()['_sesi']['tanggal']);

        $this->assertEqualsWithDelta(116.0, $kalk->umurStandarHari($tanggalSesi), 1e-9);

        $sesi = $kalk->hitungSesi($titik, ['tanggal_kalibrasi' => $tanggalSesi] + $konteks);
        $master = $kalk->hitungSesi($titik, $konteks);

        $this->assertLessThan(
            (float) $master['ketidakpastian_diperluas'],
            (float) $sesi['ketidakpastian_diperluas'],
            'Umur drift sesi (116 hari) lebih pendek dari umur master (153,66 hari), jadi U-nya '
            .'lebih kecil. Kalau sama persis, `NOW()` master ikut tersalin.',
        );
        // 0,0156260 mm itu angka dengan pembagi umur /12 milik master. Sejak
        // butir 5 (16 Sep 2026) umurnya dibagi 365 hari, jadi komponen drift
        // menyusut dan U-nya sedikit lebih kecil — yang dijaga di sini tetap
        // hal yang sama: umur dari TANGGAL SESI, bukan `NOW()`.
        $this->assertEqualsWithDelta(0.0155, (float) $sesi['ketidakpastian_diperluas'], 5e-4);
    }

    /**
     * Umur drift negatif TIDAK menahan penerbitan — dicatat, driftnya nol.
     *
     * Sesi yang mendahului sertifikat Caliper Checker yang sekarang tersimpan
     * itu sesi HISTORIS; yang hilang catatan sertifikat lamanya, bukan
     * pengukurannya.
     */
    public function test_umur_drift_negatif_dicatat_tapi_tidak_menahan(): void
    {
        [$titik, $konteks] = $this->masukan();
        $kalk = new HeightGaugeCalculator;

        $this->assertNull($kalk->umurStandarHari(new DateTimeImmutable('2025-01-01')));

        $hasil = $kalk->hitungSesi(
            $titik,
            ['tanggal_kalibrasi' => new DateTimeImmutable('2025-01-01')] + $konteks,
        );

        $this->assertTrue($hasil['boleh_terbit'], 'Umur drift negatif tidak boleh menahan penerbitan.');
        $this->assertEqualsWithDelta(0.0, (float) $hasil['budget'][5]['u'], 1e-15, 'Drift-nya nol.');
        $this->assertNotEmpty(array_filter(
            $hasil['ditolak'],
            static fn (array $d): bool => str_contains($d['alasan'], 'umur drift'),
        ), 'Alasannya wajib tercatat walau tidak menahan.');
    }

    /**
     * Pembagi `√6` komponen muai — ditiru dari `N9` master walau `J9` menulis
     * distribusinya `rect.` (yang pembaginya `√3`).
     *
     * Diuji terpisah karena inilah satu-satunya komponen yang pembaginya tidak
     * bisa ditebak dari kolom `distribusi`-nya, dan "merapikannya" ke `√3`
     * membuat U yang terbit LEBIH BESAR — perubahan yang kelihatan aman tapi
     * tetap mengubah angka sertifikat tanpa persetujuan lab.
     */
    public function test_komponen_muai_memakai_pembagi_akar_enam(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new HeightGaugeCalculator)->hitungSesi($titik, $konteks);

        $this->assertEqualsWithDelta(
            2e-6 / sqrt(6.0),
            (float) $hasil['budget'][4]['u'],
            1e-15,
            'Komponen muai wajib dibagi √6 (N9 master), bukan √3 seperti label `rect.`-nya.',
        );
        $this->assertSame('rectangular', $hasil['budget'][4]['distribusi']);
    }

    /**
     * Pembagi drift `/12` walau selisihnya HARI — ditiru dari `K10` master.
     *
     * Angka pembandingnya ikut dijaga di sini supaya pertanyaan lab §1 bisa
     * dijawab tanpa membuka Excel: `/365` membuat U yang terbit LEBIH KECIL,
     * dan aturan proyek melarang penyimpangan yang mengecilkan ketidakpastian.
     */
    public function test_drift_memakai_pembagi_tiga_ratus_enam_puluh_lima_hari(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new HeightGaugeCalculator)->hitungSesi($titik, $konteks);
        $umur = (float) self::fixture()['_acuan_master']['umur_drift_hari'];

        $driftMaster = (0.02 + 0.00025 * 600.0) / 1000 * ($umur / 12);
        $drift365 = (0.02 + 0.00025 * 600.0) / 1000 * ($umur / 365);

        // Butir 5 paket keputusan (16 Sep 2026, disetujui pemilik proyek):
        // komponennya mm/tahun dan umurnya hari, jadi `/365`. `K10` master
        // menulis `/12`, yang cuma benar kalau selisihnya bulan.
        $this->assertEqualsWithDelta(
            $drift365 / sqrt(3.0),
            (float) $hasil['budget'][5]['u'],
            1e-9,
            'Drift wajib `/365` — satuan komponennya mm/tahun.',
        );
        $this->assertLessThan(
            $driftMaster,
            $drift365,
            '`/365` menghasilkan drift ~30× lebih kecil dari `/12` milik master. Arah itu memang '
            .'yang disetujui; kalau suatu saat kebalikannya, pembagi master balik diam-diam.',
        );
    }
}
