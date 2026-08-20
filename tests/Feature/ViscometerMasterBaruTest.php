<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Standard;
use App\Services\Calibration\Profiles\ViscometerProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Master Viscometer 20 Agustus 2026 (`5. Viscometer 86068360 terbaru .xlsm`,
 * sesi **0817-CAL-726**) — yang BERUBAH dari master sebelumnya, dan yang
 * SENGAJA tetap berbeda darinya.
 *
 * `ViscometerBudgetTest` & `ViscometerApiTest` sudah menjaga mesin hitungnya.
 * Yang dijaga di sini keputusan-keputusan yang diambil waktu membaca master
 * baru — masing-masing bisa "dibetulkan" oleh orang berikutnya yang membuka
 * workbook itu dan melihat angka lain di selnya, dan tiap pembetulan seperti
 * itu menggeser sertifikat tanpa satu pun test lain yang merah.
 */
class ViscometerMasterBaruTest extends TestCase
{
    use RefreshDatabase;

    private function sesi(): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', '2607.59.W')
            ->with(['uncertaintyCalculations', 'rawMeasurements'])
            ->firstOrFail();
    }

    /**
     * LIMA titik, dan larutan yang dulu ditulis "30000 cP" ternyata 3000 cP.
     *
     * Kertas Rev.3 masih mencetak 30000, dan sampai 19 Agt 2026 blok itu
     * `#DIV/0!` seluruhnya di master sehingga tidak bisa dibedakan dari blok
     * mati. Master baru menghidupkannya dengan larutan Paragon/N1400 yang
     * nilainya 3987 cP pada 25 °C — sepuluh kali lebih kecil dari yang tertulis
     * di kertas.
     */
    public function test_lima_titik_dan_yang_dulu_30000_ternyata_3000(): void
    {
        $nilai = array_column(ViscometerProfile::TITIK, 'nilai');
        $label = array_column(ViscometerProfile::TITIK, 'label');

        $this->assertSame([99.65, 1018.0, 3987.0, 59003.0, 99613.0], $nilai);
        $this->assertSame(['100', '1000', '3000', '60000', '100000'], $label);

        // Yang paling gampang "dibetulkan" balik oleh yang cuma membaca kertas.
        $this->assertNotContains('30000', $label);
    }

    /**
     * Larutan 3000 & 100000 cP TIDAK punya CMC, dan itu keadaan yang sah.
     *
     * `DATABASE!S5:S7` cuma berisi tiga angka; baris ke-4 & ke-5 punya nomor
     * dengan kolom CMC kosong, dan sel `CMC Laboratory` di blok U95 kedua
     * larutan itu kosong juga. Lab mengukur dua titik itu tanpa mengklaim
     * kemampuan di situ.
     */
    public function test_dua_larutan_baru_tanpa_klaim_cmc(): void
    {
        $tanpaCmc = collect(ViscometerProfile::TITIK)
            ->filter(fn (array $t): bool => $t['cmc_parameter'] === null)
            ->pluck('label')
            ->values()
            ->all();

        $this->assertSame(ViscometerProfile::TITIK_TANPA_CMC, $tanpaCmc);
        $this->assertSame(['3000', '100000'], $tanpaCmc);
    }

    /**
     * Kolom ketidakpastian larutan 100 cP TETAP 0,17 %, walau master terbaru
     * menulis 0,13 %.
     *
     * ## Kenapa berkas terbaru justru tidak diikuti di satu kolom ini
     *
     * Perubahan itu sempat dibaca sebagai lot botol yang diganti, dan
     * anggapan itu keliru. Lima bukti bahwa yang menyimpang berkas barunya:
     *
     *  1. `DATABASE!T13` di KEDUA master menulis S/N yang sama, 1241202088 —
     *     botolnya tidak diganti.
     *  2. Nilai viskositas & densitasnya identik di kedua berkas. Sertifikat
     *     larutan yang direvisi tidak mengubah satu kolom dan membiarkan dua
     *     kolom lain sama persis.
     *  3. Arahnya melawan keempat larutan lain di workbook yang SAMA: 1000,
     *     3000, dan 60000 cP semuanya menurun dengan suhu; cuma 100 cP yang
     *     menaik.
     *  4. Header sheet `Tabel Pengaruh Temperature` di master baru justru
     *     mundur ke S/N 1220905085 — lebih tua dari yang ada di `DATABASE`-nya
     *     sendiri.
     *  5. Sertifikat yang SUDAH TERBIT (`CAL/2026/08/0047`) mencetak U95 titik
     *     100 cP = 0,49299154 cP, dan angka itu cuma lahir dari 0,17 %.
     *
     * Ditanyakan ke lab di butir 10 `docs/pertanyaan-lab-viscometer.md`.
     * Begitu lab menjawab lain, test ini yang merah lebih dulu.
     */
    public function test_u95_larutan_100_cp_tetap_nol_koma_17_persen(): void
    {
        $this->seed(DatabaseSeeder::class);

        $std = Standard::where('nama', 'Viscosity Standard Solution 100 cP')->firstOrFail();

        $this->assertSame('1241202088', $std->serial_number);
        // 0,17 % x 99,65 cP.
        $this->assertEqualsWithDelta(0.169405, (float) $std->ketidakpastian, 1e-9);

        $baris = $std->barisTabelSuhu(ViscometerProfile::SUHU_ACUAN);
        $this->assertEqualsWithDelta(0.17, $baris['u_persen'], 1e-9);
        $this->assertEqualsWithDelta(99.65, $baris['nilai'], 1e-9);

        // Kolomnya MENURUN dengan suhu, sama seperti keempat larutan lain.
        $tabel = collect($std->koefisien_suhu['tabel']);
        $this->assertGreaterThan(
            (float) $tabel->last()['u_persen'],
            (float) $tabel->first()['u_persen'],
            'Ketidakpastian larutan 100 cP mestinya menurun dengan suhu, seperti empat larutan lain.',
        );
    }

    /**
     * Desimal cetak sertifikat PER BARIS, dari format sel `SERTIFIKAT` C23:R27.
     *
     * Ini yang menjawab butir 5 `docs/pertanyaan-lab-viscometer.md` — dan
     * jawabannya bukan angka seragam. Sampai berkas `.xlsm`-nya ada, yang
     * dipakai dua desimal untuk semua baris karena CSV tidak menyimpan format
     * sel; sekarang buktinya ada dan bunyinya `0.00` · `0.0` · `0` · `0.0` ·
     * `0.0`.
     */
    public function test_desimal_cetak_dibaca_dari_format_sel_per_baris(): void
    {
        $profil = new ViscometerProfile;

        $harapan = [
            99.65 => 2,
            1018.0 => 1,
            3987.0 => 0,
            59003.0 => 1,
            99613.0 => 1,
        ];

        foreach ($harapan as $titik => $desimal) {
            $this->assertSame(
                $desimal,
                $profil->desimalSertifikatTitik((float) $titik),
                "Desimal cetak titik {$titik} cP bergeser dari format sel masternya.",
            );
        }

        // Dicocokkan ke nilai yang SUDAH digeser suhu juga — itu yang beneran
        // sampai ke `CertificateSnapshotBuilder`, bukan nominalnya.
        $this->assertSame(2, $profil->desimalSertifikatTitik(79.895696400626));
        $this->assertSame(1, $profil->desimalSertifikatTitik(755.7464788732395));
        $this->assertSame(0, $profil->desimalSertifikatTitik(2891.0219092331767));
    }

    /**
     * Nilai acuan titik 3000 cP dari TABEL SERTIFIKAT larutannya, bukan dari
     * dua angka ketikan di sheet `PERHITUNGAN`.
     *
     * ## Kejanggalan yang dijaga di sini
     *
     * Blok interpolasi 3000 cP (`PERHITUNGAN!T33`/`T34`) berisi angka mati
     * 3437 & 1398 yang MENIMPA formulanya. Baris pertama blok yang sama
     * (`T32`) masih formula, dan keempat blok larutan lain seluruhnya formula
     * ke `Tabel Pengaruh Temperature` — jadi ini dua sel yang ditimpa, bukan
     * pola.
     *
     * Tabel sertifikatnya berbunyi 3987 @25 °C dan 1613 @37,78 °C. Sheet
     * `INPUT DATA` (`K34`) dan seluruh budget U95 titik itu juga membaca tabel
     * (0,25 % × 3987 = 9,9675). Yang dipakai tabelnya: itu satu-satunya sumber
     * yang punya identitas (Paragon Scientific/N1400, S/N 2241502068), dan
     * sertifikat yang memakai dua nilai berbeda untuk satu larutan tidak bisa
     * dipertahankan di depan asesor.
     *
     * Akibatnya nyata dan besar: nilai acuannya 2891,02 cP di sini, 2495,68 cP
     * di master — dan koreksi titik itu **berbalik tanda**.
     */
    public function test_titik_3000_pakai_tabel_sertifikat_bukan_sel_ketikan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $std = Standard::where('nama', 'Viscosity Standard Solution 3000 cP')->firstOrFail();

        $this->assertSame('2241502068', $std->serial_number);
        $this->assertEqualsWithDelta(3987.0, $std->nilaiPadaSuhu(25.0), 1e-9);
        $this->assertEqualsWithDelta(1613.0, $std->nilaiPadaSuhu(37.78), 1e-9);

        // Angka ketikan yang TIDAK dipakai. Kalau salah satu muncul di tabel,
        // berarti ada yang menyalinnya dari sel yang ditimpa itu.
        $tabel = collect($std->koefisien_suhu['tabel'])->pluck('nilai');
        $this->assertNotContains(3437.0, $tabel->all());
        $this->assertNotContains(1398.0, $tabel->all());

        // Nilai acuan pada suhu sesi master (30,9 °C).
        $this->assertEqualsWithDelta(2891.0219092331767, $std->nilaiPadaSuhu(30.9), 1e-9);
        // Yang dihasilkan dua sel ketikan itu — sengaja TIDAK direproduksi.
        $this->assertNotEqualsWithDelta(2495.6776212832556, $std->nilaiPadaSuhu(30.9), 1.0);
    }

    /**
     * Sesi master baru: nilai acuan kedua titik pertama, dan `uc` titik
     * 3000 cP yang cocok PERSIS ke sel `PERHITUNGAN U95%`.
     *
     * Titik 1000 & 100 cP sengaja TIDAK cocok — dua sebab yang berbeda, dua-
     * duanya dijaga sebagai angka di test di bawahnya.
     */
    public function test_sesi_master_baru_reproduksi_uc_titik_100_dan_3000(): void
    {
        $titik = $this->sesi()->uncertaintyCalculations
            ->keyBy(fn ($t): int => (int) $t->titik_ke);

        $this->assertCount(3, $titik, 'Sesi 0817-CAL-726 mengukur tiga titik; blok 60000 & 100000 kosong.');

        // Nilai acuan = interpolasi linier tabel larutan pada suhu titik itu.
        $this->assertEqualsWithDelta(79.895696400626, (float) $titik[1]->titik_ukur, 1e-7);
        $this->assertEqualsWithDelta(755.7464788732395, (float) $titik[2]->titik_ukur, 1e-7);

        // `Ketidakpastian Baku Gabungan, Uc` & `Derajat Kebebasan, v eff` —
        // sel AC59 & AC60, blok 3000 cP.
        $this->assertEqualsWithDelta(5.044064680830383, (float) $titik[3]->ketidakpastian_gabungan, 1e-7);
        $this->assertEqualsWithDelta(209.3999674977603, (float) $titik[3]->derajat_kebebasan_efektif, 1e-6);

        // Titik 100 cP: `uc` kita 0,0947 sementara sel AC21 berbunyi 0,0773 —
        // selisihnya dari kolom ketidakpastian larutan yang sengaja tidak
        // diikuti (0,17 % vs 0,13 %, lihat test di atas).
        //
        // Yang DILAPORKAN tetap sama persis: lantai CMC 0,2 cP menang di titik
        // ini pada kedua angka (U hitung 0,1893 dengan 0,17 % dan 0,1547
        // dengan 0,13 %), jadi sel AC26 master tetap direproduksi.
        $this->assertEqualsWithDelta(0.09465814535854902, (float) $titik[1]->ketidakpastian_gabungan, 1e-7);
        $this->assertEqualsWithDelta(0.2, (float) $titik[1]->ketidakpastian_diperluas, 1e-7);
    }

    /**
     * Titik 3000 cP terbit TANPA vonis — lembar masternya tidak mengisi
     * spindle & RPM untuk titik itu.
     *
     * Tanpa dua itu Fullscale tidak punya arti dan MPE tidak bisa dihitung.
     * Yang kosong dilaporkan sebagai kosong; PASS terhadap batas yang tidak
     * pernah ada jauh lebih berbahaya daripada kolom vonis yang kosong.
     */
    public function test_titik_tanpa_spindle_terbit_tanpa_vonis(): void
    {
        $titik = $this->sesi()->uncertaintyCalculations
            ->keyBy(fn ($t): int => (int) $t->titik_ke);

        $this->assertNotNull($titik[1]->toleransi);
        $this->assertNotNull($titik[2]->toleransi);

        $this->assertNull($titik[3]->toleransi, 'Tanpa spindle & RPM, MPE nggak bisa dihitung.');
        $this->assertNull($titik[3]->keputusan, 'Tanpa batas, nggak ada vonis — bukan PASS diam-diam.');
    }

    /**
     * MPE titik 1 & 2 dari spindle/RPM sesi ini, dengan TK model DV2TRV (= 1).
     *
     * Master lama memakai DV2THA (TK 2); kalau ada yang tertinggal dari situ,
     * kedua angka di bawah meleset dua kali lipat.
     */
    public function test_mpe_pakai_tk_model_dv2trv(): void
    {
        $titik = $this->sesi()->uncertaintyCalculations
            ->keyBy(fn ($t): int => (int) $t->titik_ke);

        // Fullscale = 1 x 1 x 10000/60 = 166,667 ; MPE = 1% x 79,64 + 1% x Fullscale.
        $this->assertEqualsWithDelta(2.4630666666666667, (float) $titik[1]->toleransi, 1e-7);
        // Fullscale = 1 x 10 x 10000/61 = 1639,344 ; MPE = 1% x 779,5 + 1% x Fullscale.
        $this->assertEqualsWithDelta(24.188442622950824, (float) $titik[2]->toleransi, 1e-7);
    }

    /**
     * TIGA selisih yang disengaja terhadap sel master, dijaga sebagai ANGKA —
     * bukan sebagai komentar yang bisa jadi basi tanpa ada yang tahu.
     *
     *  1. **Titik 1000 cP, resolusi.** Master membaca `E16` (= 1) HANYA di
     *     blok ini; empat blok lain mematok 0,1, dan pembacaan lembarnya
     *     sendiri (779,5) cuma mungkin dari alat 0,1 cP. `uc` kita 1,1867;
     *     sel masternya 1,2209.
     *  2. **Titik 3000 cP, faktor cakupan.** Master memakai pendekatan
     *     t-student di tiga blok baru dan `2` di dua blok lama, sementara
     *     sertifikatnya mencetak `Coverage Factor ( k ) = 2` dan memberi judul
     *     kolom `U95%, k=2`. Yang diikuti dokumennya: `U` kita 10,088 cP; sel
     *     masternya 9,949 cP — 1,4 % lebih besar, arah yang aman.
     *
     *  3. **Titik 100 cP, kolom ketidakpastian larutan.** Master menulis
     *     0,13 % di kolom itu; yang dipakai 0,17 % karena serial botolnya sama
     *     di kedua berkas dan sertifikat terbit cuma bisa direproduksi dengan
     *     0,17 %. `uc` kita 0,0947; sel masternya 0,0773. Yang DILAPORKAN
     *     tidak bergeser — lantai CMC 0,2 cP menang pada kedua angka.
     *
     * Ketiganya ditanyakan di `docs/pertanyaan-lab-viscometer.md`. Begitu lab
     * menjawab, test ini yang merah lebih dulu.
     */
    public function test_tiga_selisih_yang_disengaja_dari_master(): void
    {
        $titik = $this->sesi()->uncertaintyCalculations
            ->keyBy(fn ($t): int => (int) $t->titik_ke);

        // 1. Resolusi 0,1 — bukan 1 seperti sel blok 1000 cP.
        $this->assertEqualsWithDelta(1.1866508648301305, (float) $titik[2]->ketidakpastian_gabungan, 1e-7);
        $this->assertNotEqualsWithDelta(1.2209178002642453, (float) $titik[2]->ketidakpastian_gabungan, 1e-4);

        // 2. k = 2 di SEMUA titik, termasuk yang masternya pakai rumus.
        $this->assertEqualsWithDelta(2.0, (float) $titik[3]->faktor_cakupan_k, 1e-9);
        $this->assertEqualsWithDelta(10.088129361660766, (float) $titik[3]->ketidakpastian_diperluas, 1e-7);
        $this->assertNotEqualsWithDelta(9.948775103079448, (float) $titik[3]->ketidakpastian_diperluas, 1e-3);

        $this->assertSame(2.0, (new ViscometerProfile)->faktorCakupanTetap());

        // 3. Kolom ketidakpastian larutan 100 cP 0,17 %, bukan 0,13 %.
        $this->assertEqualsWithDelta(0.09465814535854902, (float) $titik[1]->ketidakpastian_gabungan, 1e-7);
        $this->assertNotEqualsWithDelta(0.07733775101928006, (float) $titik[1]->ketidakpastian_gabungan, 1e-4);
        // Tapi angka yang DILAPORKAN tetap sama dengan master.
        $this->assertEqualsWithDelta(0.2, (float) $titik[1]->ketidakpastian_diperluas, 1e-7);
    }

    /**
     * Blok mati `standar_30000` SUDAH TIDAK dikirim lembar kerja.
     *
     * Sampai 19 Agt 2026 blok itu ada sebagai bagian ber-status
     * `sumber_belum_ada` tanpa field input, karena seluruh budgetnya `#DIV/0!`
     * di master lama dan tidak bisa dibedakan dari blok mati. Master baru
     * menjawabnya — larutannya 3000 cP, dan sekarang jadi baris ketiga tabel
     * biasa.
     *
     * Membiarkan bloknya tinggal berarti lembar kerja mencetak dua tempat
     * untuk satu larutan: satu baris hidup di tabel, satu blok mati bernama
     * "30000 cP" di bawahnya. Teknisi yang melihat keduanya wajar mengira ada
     * titik yang belum diisi.
     */
    public function test_blok_mati_30000_sudah_hilang_dari_lembar_kerja(): void
    {
        $bentuk = (new ViscometerProfile)->bentukLembarKerja();

        $this->assertNotContains('standar_30000', array_column($bentuk['bagian'], 'kode'));

        $status = array_filter(array_column($bentuk['bagian'], 'status'));
        $this->assertNotContains('sumber_belum_ada', $status);
    }

    /**
     * Kelima larutan diseed dan tertaut ke baris STANDARD lembar kerja —
     * teknisi membawanya semua, dan sertifikat mencetak seluruh baris
     * "Standard used" walau sesi ini cuma memakai tiga.
     */
    public function test_kelima_larutan_terdaftar_di_master_standar(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (ViscometerProfile::TITIK as $t) {
            $nama = $t['standar'][0];
            $serial = $t['standar'][1];

            $std = Standard::where('nama', $nama)->first();

            $this->assertNotNull($std, "Larutan {$nama} belum diseed.");
            $this->assertSame($serial, $std->serial_number, "S/N {$nama} nggak cocok master.");
            $this->assertNotNull($std->koefisien_suhu['tabel'] ?? null, "Tabel suhu {$nama} kosong.");
        }
    }
}
