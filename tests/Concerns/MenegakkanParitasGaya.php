<?php

namespace Tests\Concerns;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Services\Calibration\GayaCalculator;
use App\Services\Calibration\Profiles\GayaProfile;
use App\Services\Calibration\TabelStandarGaya;
use App\Services\GumCalculator;
use App\Support\GayaMentah;
use App\Support\TataLetakSertifikat;
use PHPUnit\Framework\Attributes\Group;

/**
 * Perangkat bersama tes PARITAS keluarga gaya (UTM, Load Cell) di batch 8.
 *
 * Kedua workbook gaya satu rangka: lembar `PERHITUNGAN FC` (rantai per titik, kolom
 * B..AB) dan `PERHITUNGAN U95%` (delapan komponen budget, baris 9-16). Yang beda
 * hanya data, kolom yang dicetak (UTM Z, Load Cell Y), dan pita CMC. Jadi bagian
 * yang SAMA ditulis sekali di sini, dan kelas tiap alat memegang yang khas:
 * docblock selisih, tes drift (B8-1), CMC, serta butir MENUNGGU.
 *
 * Kelas pemakai wajib mendefinisikan `namaFixture()` dan `profil()`. Fixture-nya
 * dibangun `docs/skrip/gen-fixture-paritas-batch8.py`; bentuknya
 * `workbook.{masukan, excel, rekonstruksi}` dan tiap nilai Excel `{sel, nilai}`.
 *
 * ## Yang dipakai sebagai bukti kesetaraan rumus
 *
 * Hanya sel yang di workbook berisi RUMUS. Sel yang berisi angka ketikan (`rumus:
 * false` di fixture: kolom V set point di semua titik, kolom T di titik nol, kolom
 * AB di titik nol) bukan bukti rumus — mereka diamati terpisah di
 * `test_sel_ketikan_master_diamati_bukan_bukti_rumus`, dan tidak dihitung sebagai
 * paritas.
 *
 * ## Satuan
 *
 * Seluruh hitungan hidup dalam kN. Titik dan bacaan di fixture ada dalam satuan
 * ALAT (UTM kgf, Load Cell kN); konversi ke kN ikut diadu (`PERHITUNGAN FC` C31:P31
 * dst. = bacaan x `DATABASE!S23` untuk kgf). Sertifikat mencetak dalam satuan alat
 * (`cetakDalamSatuanAlat`), jadi E/L/Q diadu dalam satuan itu.
 *
 * Toleransi mengikuti [MenegakkanParitas]; tidak ada toleransi khusus alat gaya.
 */
trait MenegakkanParitasGaya
{
    use MenegakkanParitas;

    /** @var list<array<string, mixed>>|null */
    private ?array $hitunganGaya = null;

    /** Nama fixture di `tests/Fixtures/paritas/` tanpa `.json`. */
    abstract protected static function namaFixture(): string;

    abstract protected function profil(): GayaProfile;

    /** @return array<string, mixed> */
    protected static function wb(): array
    {
        return self::fixtureParitas(static::namaFixture())['workbook'];
    }

    /** Kode standar aplikasi dari `INPUT DATA!Q23` (1 = 100 kN, 2 = 3000 kN, 3 = 5 kN). */
    protected static function kodeStandar(): string
    {
        $peta = [1 => '100kN', 2 => '3000kN', 3 => '5kN'];

        return $peta[(int) self::nilai(self::wb()['masukan']['kode_standar_Q23'])];
    }

    /**
     * Blok tingkat-sesi `spesifikasi_alat.gaya` persis seperti yang disimpan sesi.
     *
     * `resolusi_standar` dan `kapasitas_standar` memakai nilai yang dibaca workbook
     * (`PERHITUNGAN U95%!K6` dan `Z4`), bukan tabel aplikasi: yang diadu di sini
     * rumusnya, dan data masukan harus sama di kedua sisi.
     *
     * @return array<string, mixed>
     */
    protected static function blok(): array
    {
        $m = self::wb()['masukan'];
        $d = self::wb()['excel']['dasar'];

        return [
            'satuan' => (string) $m['satuan_G15']['nilai'],
            'tipe_beban' => (string) $m['tipe_beban_Z22']['nilai'],
            'standar' => self::kodeStandar(),
            'suhu_sertifikat_standar' => self::nilai($m['suhu_sertifikat_standar_H6']),
            'kapasitas' => self::nilai($m['kapasitas_E15']),
            'resolusi_uut' => self::nilai($m['resolusi_E16']),
            'resolusi_standar' => self::nilai($d['resolusi_standar_kn_K6']),
            // Sel `Z4` di workbook berisi TEKS ("5" / "100"); aplikasi menyimpannya angka.
            'kapasitas_standar' => (float) $d['kapasitas_standar_teks_Z4']['nilai'],
            'preload_zero' => array_map('floatval', $m['preload_zero']['nilai']),
            'preload_max' => array_map('floatval', $m['preload_max']['nilai']),
            'misalignment' => array_map('floatval', $m['misalignment_mm']['nilai']),
        ];
    }

    /** @return array<string, mixed> */
    protected static function konteksSesi(): array
    {
        $m = self::wb()['masukan'];

        return [
            'spesifikasi_alat' => [GayaMentah::KUNCI_SESI => self::blok()],
            'suhu_awal' => self::nilai($m['suhu_ruang_awal_E21']),
            'suhu_akhir' => self::nilai($m['suhu_ruang_akhir_F21']),
        ];
    }

    /** @return list<array<string, mixed>> masukan `hitungPerGrup`, urut titik. */
    protected static function titikProfil(): array
    {
        $titik = [];

        foreach (self::wb()['masukan']['titik'] as $t) {
            $titik[] = [
                'titik_ke' => $t['titik_ke'],
                'titik_ukur' => self::nilai($t['nominal']),
                'pembacaan' => [],
                'standard' => null,
                'konteks' => self::konteksSesi() + [
                    'bacaan' => array_map('floatval', $t['bacaan']['nilai']),
                ],
            ];
        }

        return $titik;
    }

    /**
     * Pita CMC alat ini dari `database/data/kemampuan-kalibrasi.json` (berkas yang sama
     * dengan seeder produksi), tiap pita membawa `metode` alatnya.
     *
     * @return list<array{parameter: string, min: float|int, max: float|int, satuan: string, ketidakpastian: float|int, satuan_u: string, metode: string}>
     */
    protected function lampiranPita(): array
    {
        $nama = $this->profil()->namaAlatKemampuan();
        $lampiran = json_decode((string) file_get_contents(database_path('data/kemampuan-kalibrasi.json')), true, flags: JSON_THROW_ON_ERROR);
        $pita = [];

        foreach ($lampiran['kelompok_pengukuran'] as $kelompok) {
            foreach ($kelompok['alat'] as $alat) {
                if ($alat['nama_alat'] !== $nama) {
                    continue;
                }

                foreach ($alat['rentang'] as $r) {
                    $pita[] = $r + ['metode' => (string) $alat['metode']];
                }
            }
        }

        return $pita;
    }

    /**
     * Alat + pita CMC dari lampiran akreditasi yang SAMA dengan yang dibaca seeder
     * produksi (`database/data/kemampuan-kalibrasi.json`), bukan angka yang diketik
     * ulang di tes dan bukan `CalibrationCapabilitySeeder`.
     */
    protected function alat(): Equipment
    {
        $organisasi = Organization::factory()->create();
        $kategori = EquipmentCategory::factory()->create([
            'organization_id' => $organisasi->id,
            'kode' => 'gaya',
            'nama' => 'Gaya',
        ]);

        $nama = $this->profil()->namaAlatKemampuan();
        $pita = $this->lampiranPita();

        $this->assertGreaterThan(0, count($pita), "Lampiran akreditasi harus punya pita CMC untuk {$nama}.");

        foreach ($pita as $r) {
            CalibrationCapability::factory()->create([
                'organization_id' => $organisasi->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $nama,
                'parameter' => $r['parameter'],
                'range_min' => $r['min'],
                'range_max' => $r['max'],
                'satuan' => $r['satuan'],
                'ketidakpastian_terbaik' => $r['ketidakpastian'],
                'satuan_ketidakpastian' => $r['satuan_u'],
                'faktor_cakupan' => 2,
                'metode' => $r['metode'],
            ]);
        }

        return Equipment::factory()->create([
            'organization_id' => $organisasi->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => $nama.' Uji Paritas',
            'nama_alat_kemampuan' => $nama,
            'satuan' => (string) self::wb()['masukan']['satuan_G15']['nilai'],
            'range_min' => 0,
            'range_max' => self::nilai(self::wb()['masukan']['kapasitas_E15']),
            'resolusi' => self::nilai(self::wb()['masukan']['resolusi_E16']),
        ]);
    }

    /**
     * Keluaran `hitungan` profil (jalur SAMA untuk pratinjau, simpan, dan hitung ulang).
     *
     * @return list<array<string, mixed>>
     */
    protected function hitung(): array
    {
        if ($this->hitunganGaya !== null) {
            return $this->hitunganGaya;
        }

        $hasil = $this->profil()->hitungPerGrup(self::titikProfil(), $this->alat());

        $this->assertNotNull($hasil, 'Profil tidak menghitung.');
        $this->assertSame([], $hasil['belum_dihitung'], 'Ada titik yang belum dihitung profil.');
        $this->assertCount(count(self::wb()['masukan']['titik']), $hasil['hitungan'], 'Jumlah titik keluaran profil.');

        return $this->hitunganGaya = $hasil['hitungan'];
    }

    /**
     * Satu titik lewat kalkulator langsung, dengan suhu aktual standar = `K7` workbook.
     *
     * @return array<string, mixed>
     */
    protected static function hitungTitikLangsung(int $indeks): array
    {
        $m = self::wb()['masukan'];
        $t = $m['titik'][$indeks];

        return GayaCalculator::hitungTitik(
            self::nilai($t['nominal']),
            array_map('floatval', $t['bacaan']['nilai']),
            (string) $m['satuan_G15']['nilai'],
            self::kodeStandar(),
            (string) $m['tipe_beban_Z22']['nilai'],
            self::nilai($m['suhu_sertifikat_standar_H6']),
            self::nilai($m['suhu_aktual_standar_K7']),
        );
    }

    /** Sesi dalam memori: cukup untuk hook cetak profil (tidak disimpan). */
    protected static function sesiCetak(): CalibrationSession
    {
        return (new CalibrationSession)->forceFill([
            'spesifikasi_alat' => [GayaMentah::KUNCI_SESI => self::blok()],
        ]);
    }

    /** Faktor satuan alat -> kN, dari sel `DATABASE!S2x` yang dibaca workbook. */
    protected static function faktorSatuanWorkbook(): float
    {
        return self::nilai(self::wb()['excel']['dasar']['faktor_satuan']);
    }

    /** @return array<string, mixed> `type_b_components` titik pertama (budget sama di semua titik). */
    protected function jejak(int $indeks = 0): array
    {
        return $this->hitung()[$indeks]['type_b_components'];
    }

    /** RSD terbesar antar titik di sisi aplikasi (masukan komponen pengulangan). */
    protected function rsdMaksAplikasi(): float
    {
        $maks = 0.0;

        foreach ($this->hitung() as $h) {
            $t = $h['type_b_components']['gaya_rantai']['T_rsd_persen'];

            if ($t !== null) {
                $maks = max($maks, (float) $t);
            }
        }

        return $maks;
    }

    // ------------------------------------------------------------------ tes yang sama untuk kedua alat

    public function test_konversi_bacaan_ke_kn_dan_nominal_sama_dengan_perhitungan_fc(): void
    {
        foreach (self::wb()['excel']['titik'] as $i => $ex) {
            $h = self::hitungTitikLangsung($i);
            $titik = $ex['titik_ke'];

            $this->assertCount(count($ex['bacaan_kn']['nilai']), $h['bacaan_kn'], "titik {$titik}: jumlah bacaan");

            foreach ($ex['bacaan_kn']['nilai'] as $j => $nilai) {
                $this->assertSamaExcel(
                    ['sel' => $ex['bacaan_kn']['sel'][$j], 'nilai' => $nilai],
                    $h['bacaan_kn'][$j],
                    "titik {$titik} bacaan ke-".($j + 1).' dalam kN',
                );
            }

            $this->assertSamaExcel($ex['B_nominal_kn'], $h['B'], "titik {$titik} B nominal kN");
        }
    }

    public function test_suhu_aktual_standar_aplikasi_sama_dengan_k7_workbook(): void
    {
        $m = self::wb()['masukan'];
        $rata = (self::nilai($m['suhu_ruang_awal_E21']) + self::nilai($m['suhu_ruang_akhir_F21'])) / 2;

        $this->assertSamaExcel($m['suhu_aktual_standar_K7'], $rata, 'suhu aktual standar = rata-rata suhu ruang awal/akhir (K7)');
        $this->assertSamaExcel($m['suhu_ruang_rata_H21'], $rata, 'suhu ruang rata-rata (H21)');
    }

    /**
     * Rantai per titik, dibaca dari keluaran PROFIL (jejak `gaya_rantai`) — jalur yang
     * sama dengan simpan dan hitung ulang. Hanya sel berumus yang dihitung sebagai bukti.
     */
    public function test_rantai_per_titik_sama_dengan_perhitungan_fc(): void
    {
        $hitungan = $this->hitung();

        foreach (self::wb()['excel']['titik'] as $i => $ex) {
            $h = $hitungan[$i];
            $rantai = $h['type_b_components']['gaya_rantai'];
            $titik = $ex['titik_ke'];

            $this->assertSame($titik, $h['titik_ke']);

            $this->assertSamaExcel($ex['B_nominal_kn'], $h['rata_rata'], "titik {$titik} B (rata_rata = UUT dalam kN)");
            $this->assertSamaExcel($ex['R_rata_kn'], $rantai['R_rata_kn'], "titik {$titik} R rata-rata bacaan");
            $this->assertSamaExcel($ex['S_stdev_kn'], $rantai['S_stdev_kn'], "titik {$titik} S stdev");
            $this->assertSamaExcel($ex['S_stdev_kn'], $h['standar_deviasi'], "titik {$titik} S stdev (kolom hasil)");
            $this->assertSamaExcel($ex['Y_terkoreksi_kn'], $rantai['Y_terkoreksi_kn'], "titik {$titik} Y = R + W");
            $this->assertSamaExcel($ex['Z_terkoreksi_termal_kn'], $rantai['Z_terkoreksi_termal_kn'], "titik {$titik} Z koreksi termal");
            $this->assertSamaExcel($ex['AA_correction_kn'], $rantai['AA_correction_kn'], "titik {$titik} AA = Y - B");

            foreach ([
                'T_rsd_persen' => 'T RSD %',
                'W_koreksi_standar_kn' => 'W koreksi standar',
                'AB_rrpe_persen' => 'AB RRPE %',
            ] as $kunci => $nama) {
                if (($ex[$kunci]['rumus'] ?? false) !== true) {
                    continue;
                }

                $this->assertNotNull($rantai[$kunci], "titik {$titik} {$nama}: aplikasi null padahal sel Excel berumus");
                $this->assertSamaExcel($ex[$kunci], (float) $rantai[$kunci], "titik {$titik} {$nama}");
            }

            // Kolom hasil: `error` dan `koreksi` berlawanan tanda (kesepakatan repo).
            $this->assertEqualsWithDelta(-$h['koreksi'], $h['error'], 0.0, "titik {$titik} error = -koreksi");
            $this->assertSame(12, $h['jumlah_pengulangan']);
        }
    }

    /**
     * Tujuh dari delapan komponen budget diadu ke master. Drift (`N13`) TIDAK ikut:
     * untuk kombinasi arah Tarik master membaca kolom Tekan (B8-1), dan itu diadu di
     * tes selisih kelas masing-masing — bukan disembunyikan di sini.
     */
    public function test_tujuh_komponen_budget_sama_dengan_master_kecuali_drift(): void
    {
        $komponen = $this->jejak()['komponen'];
        $master = self::wb()['excel']['budget']['komponen'];

        $this->assertSame(
            array_keys($master),
            array_column($komponen, 'sumber'),
            'Urutan dan nama komponen budget harus sama dengan baris 9-16 master.',
        );

        foreach ($komponen as $k) {
            $sumber = $k['sumber'];

            if ($sumber === 'drift_standar') {
                continue;
            }

            $m = $master[$sumber];
            $this->assertSamaExcel($m['ui_U'], (float) $k['u'], "{$sumber} ui (kolom U)");
            $this->assertSamaExcel($m['ci_X'], (float) $k['ci'], "{$sumber} ci (kolom X)");
            $this->assertSamaExcel($m['vi_S'], (float) $k['vi'], "{$sumber} vi (kolom S)");
        }
    }

    public function test_rsd_maks_dan_masukan_budget_sama_dengan_master(): void
    {
        $dasar = self::wb()['excel']['dasar'];
        $blok = self::blok();

        $this->assertSamaExcel($dasar['rsd_maks_T44'], $this->rsdMaksAplikasi(), 'RSD MAX (T44) = RSD terbesar antar titik');
        $this->assertSamaExcel(
            $dasar['kapasitas_kn_G8'],
            GayaCalculator::keKn((float) $blok['kapasitas'], (string) $blok['satuan']),
            'kapasitas dalam kN (G8)',
        );
        $this->assertSamaExcel(
            $dasar['resolusi_kn_G9'],
            GayaCalculator::keKn((float) $blok['resolusi_uut'], (string) $blok['satuan']),
            'resolusi alat dalam kN (G9)',
        );
        $this->assertSamaExcel(
            self::wb()['excel']['budget']['K4_kapasitas_kn'],
            GayaCalculator::keKn((float) $blok['kapasitas'], (string) $blok['satuan']),
            'rentang ukur K4',
        );
        $this->assertSamaExcel($dasar['faktor_satuan'], TabelStandarGaya::faktorSatuan((string) $blok['satuan']), 'faktor satuan');
        $this->assertSamaExcel(
            $dasar['misalignment_stdev_D3'],
            GayaCalculator::stdev($blok['misalignment']),
            'stdev misalignment (Misalignment!D3)',
        );
        $this->assertSamaExcel(
            $dasar['misalignment_rata_C7'],
            GayaCalculator::rata($blok['misalignment']),
            'rata-rata misalignment (Misalignment!C7)',
        );
    }

    /**
     * Penutup selisih drift. Pasang drift MASTER (`N13`) ke komponen dan agregasi
     * aplikasi, dan seluruh rantai agregat jatuh ke angka master: uc, veff, k, U%, U kN.
     * Artinya drift satu-satunya sumber selisih budget — tujuh komponen lain dan rumus
     * agregasinya (jumlah, Welch-Satterthwaite, kuantil-t) setara.
     */
    public function test_penutup_drift_master_dipasang_menghasilkan_budget_dan_u_master(): void
    {
        $blok = self::blok();
        $excel = self::wb()['excel']['budget'];
        $rentangKn = GayaCalculator::keKn((float) $blok['kapasitas'], (string) $blok['satuan']);
        $standar = TabelStandarGaya::standar((string) $blok['standar']);

        $komponen = GayaCalculator::komponenBudget(
            $blok,
            $this->rsdMaksAplikasi(),
            $rentangKn,
            (float) $standar['u95_persen'],
            self::nilai($excel['drift']['N13_drift']),
        );
        $agregat = (new GumCalculator)->agregasiBudget($komponen);

        foreach ($komponen as $k) {
            $m = $excel['komponen'][$k['sumber']];
            $this->assertSamaExcel($m['ui_U'], (float) $k['u'], "{$k['sumber']} ui dengan drift master");
            $this->assertSamaExcel($m['uici_kuadrat_AC'], (float) $k['u'] ** 2 * (float) $k['ci'] ** 2, "{$k['sumber']} (ui ci)^2 (kolom AC)");
        }

        $ag = $excel['agregat'];
        $this->assertSamaExcel($ag['uc_AC18'], (float) $agregat['ketidakpastian_gabungan'], 'uc gabungan (AC18)');
        $this->assertSamaExcel($ag['veff_AC19'], (float) $agregat['derajat_kebebasan_efektif'], 'veff (AC19)');
        $this->assertKSamaExcel($ag['k_AC20'], (float) $agregat['faktor_cakupan_k'], 'k = TINV(0,05; veff) (AC20)');
        $this->assertUSamaExcel($ag['U_persen_AC21'], (float) $agregat['ketidakpastian_diperluas'], 'U persen (AC21)');
        $this->assertUSamaExcel(
            $ag['U_kn_AH21'],
            (float) $agregat['ketidakpastian_diperluas'] / 100 * $rentangKn,
            'U dalam kN (AH21)',
        );
    }

    /**
     * Sertifikat: Standard Value (E), UUT (L), Correction (Q) dalam satuan ALAT.
     *
     * Hook yang diuji `cetakDalamSatuanAlat` — fungsi yang sama dipakai pembangun
     * snapshot sertifikat. `Standard Value` = `rata_rata + koreksi` (profil
     * `nilaiStandarDariKoreksi() = true`).
     */
    public function test_kolom_sertifikat_e_l_q_dalam_satuan_alat_sama_dengan_master(): void
    {
        $profil = $this->profil();
        $cetak = $profil->cetakDalamSatuanAlat(self::sesiCetak());

        $this->assertNotNull($cetak, 'Hook cetak satuan alat harus tersedia untuk sesi bersatuan.');
        $this->assertSame((string) self::wb()['masukan']['satuan_G15']['nilai'], $cetak['satuan']);

        foreach ($this->hitung() as $i => $h) {
            $sert = self::wb()['excel']['titik'][$i]['sertifikat'];
            $titik = $h['titik_ke'];
            $ubah = $cetak['ubah'];

            $this->assertSamaExcel($sert['L_uut'], $ubah($h['rata_rata'], $titik), "titik {$titik} L = UUT");
            $this->assertSamaExcel($sert['E_standar'], $ubah($h['rata_rata'] + $h['koreksi'], $titik), "titik {$titik} E = Standard Value");
            $this->assertSamaExcel($sert['Q_koreksi'], $ubah($h['koreksi'], $titik), "titik {$titik} Q = Correction");
        }
    }

    /**
     * ANGKA CETAK: teks yang tampil di sertifikat dari nilai aplikasi harus SAMA PERSIS
     * dengan teks dari nilai master, lewat format kolom yang sama (`formatKolomSertifikat`,
     * yang di tes alatnya sendiri diadu ke format sel workbook).
     */
    public function test_angka_cetak_e_l_q_dan_rrpe_sama_persis_dengan_master(): void
    {
        $profil = $this->profil();
        $sesi = self::sesiCetak();
        $cetak = $profil->cetakDalamSatuanAlat($sesi);
        $tandaNol = $profil->tandaNolDicetak();
        $satuan = (string) self::wb()['masukan']['satuan_G15']['nilai'];

        foreach ($this->hitung() as $i => $h) {
            $ex = self::wb()['excel']['titik'][$i];
            $sert = $ex['sertifikat'];
            $titik = $h['titik_ke'];
            $format = $profil->formatKolomSertifikat($sesi, (float) $h['titik_ukur'], $satuan);
            $teks = static fn (float $nilai, string $kolom): string => self::keTitik(TataLetakSertifikat::formatAngka($nilai, $format[$kolom], $tandaNol));

            $this->assertSame($teks(self::nilai($sert['L_uut']), 'uut'), $teks($cetak['ubah']($h['rata_rata'], $titik), 'uut'), "titik {$titik} teks UUT");
            $this->assertSame($teks(self::nilai($sert['E_standar']), 'standar'), $teks($cetak['ubah']($h['rata_rata'] + $h['koreksi'], $titik), 'standar'), "titik {$titik} teks Standard Value");
            $this->assertSame($teks(self::nilai($sert['Q_koreksi']), 'koreksi'), $teks($cetak['ubah']($h['koreksi'], $titik), 'koreksi'), "titik {$titik} teks Correction");

            $ab = $h['type_b_components']['gaya_rantai']['AB_rrpe_persen'];

            if ($ab !== null && isset($sert['V_rrpe']['nilai'])) {
                $this->assertSame($teks(self::nilai($sert['V_rrpe']), 'sebaran'), $teks((float) $ab, 'sebaran'), "titik {$titik} teks RRPE");
            }
        }
    }

    /**
     * Pita CMC aplikasi (dari lampiran akreditasi) sama dengan `DATABASE!S5:S8` master:
     * Tekan 0-500 kgf, Tekan 10-88 kN, Tekan 200+ kN, Tarik 0-500 kgf. Pita kgf dikali
     * 0,00981 (`Tabel_Satuan`), persis `=2,1*S23` di master.
     */
    public function test_pita_cmc_lampiran_sama_dengan_database_s5_s8_master(): void
    {
        $master = self::wb()['excel']['cmc']['cmc_utm_DATABASE_S5_S8'];
        $pita = [];

        foreach ($this->lampiranPita() as $r) {
            $pita["{$r['parameter']}|".(float) $r['min']] = (float) $r['ketidakpastian'] * (TabelStandarGaya::faktorSatuan((string) $r['satuan_u']) ?? 1.0);
        }

        $this->assertCount(4, $master['nilai']);

        foreach ([0 => ['Tekan', 0.0], 1 => ['Tekan', 10.0], 2 => ['Tekan', 200.0], 3 => ['Tarik', 0.0]] as $i => [$arah, $min]) {
            $this->assertArrayHasKey("{$arah}|{$min}", $pita, "pita {$arah} mulai {$min} tidak ada di lampiran");
            $this->assertSamaExcel(
                ['sel' => $master['sel'][$i], 'nilai' => $master['nilai'][$i]],
                $pita["{$arah}|{$min}"],
                "CMC pita {$arah} mulai {$min} (kN)",
            );
        }
    }

    /**
     * Teks cetak U dan k dari nilai aplikasi = teks dari nilai master, lewat format kolom
     * profil. Nilai hitungnya boleh beda (B8-1); yang diadu di sini yang TERCETAK. Tanpa
     * tes ini, selisih drift bisa dikira menggeser sertifikat padahal tidak.
     */
    public function test_teks_u_dan_k_tercetak_sama_dengan_p35_dan_t36(): void
    {
        $profil = $this->profil();
        $sesi = self::sesiCetak();
        $cetak = $profil->cetakDalamSatuanAlat($sesi);
        $cetakan = self::wb()['excel']['cetak'];
        $satuan = (string) self::wb()['masukan']['satuan_G15']['nilai'];

        foreach ($this->hitung() as $h) {
            $titik = $h['titik_ke'];
            $format = $profil->formatKolomSertifikat($sesi, (float) $h['titik_ukur'], $satuan);
            $uAplikasi = $cetak['ubah']((float) $h['ketidakpastian_diperluas'], $titik);

            $this->assertSame(
                self::keTitik(TataLetakSertifikat::formatAngka(self::nilai($cetakan['U_cetak_P35']), $format['u95'], $profil->tandaNolDicetak())),
                self::keTitik(TataLetakSertifikat::formatAngka($uAplikasi, $format['u95'], $profil->tandaNolDicetak())),
                "titik {$titik} teks U (SERTIFIKAT!P35)",
            );
            $this->assertSame(
                self::keTitik(TataLetakSertifikat::teksK(['format' => $format], self::nilai($cetakan['k_cetak_T36']))),
                self::keTitik(TataLetakSertifikat::teksK(['format' => $format], (float) $h['faktor_cakupan_k'])),
                "titik {$titik} teks k (SERTIFIKAT!T36, format `0`)",
            );
        }
    }

    /**
     * Sel ketikan master (V set point semua titik; T dan AB di titik nol) bukan bukti
     * rumus. Diamati: set point yang dipilih aplikasi (titik terdekat di tabel standar)
     * vs set point yang diketik teknisi di workbook, dan perlakuan titik nol.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_sel_ketikan_master_diamati_bukan_bukti_rumus(): void
    {
        foreach (self::wb()['excel']['titik'] as $i => $ex) {
            $rantai = $this->hitung()[$i]['type_b_components']['gaya_rantai'];
            $titik = $ex['titik_ke'];

            $this->assertFalse($ex['V_set_point_kn']['rumus'], "titik {$titik}: V di workbook diketik, bukan rumus");
            $this->assertSamaExcel($ex['V_set_point_kn'], (float) $rantai['set_point_standar_kn'], "titik {$titik} set point standar (V ketikan vs terdekat di tabel)");
        }

        $nol = self::wb()['excel']['titik'][0];
        $this->assertFalse($nol['T_rsd_persen']['rumus'], 'titik nol: T diketik 0 di workbook');
        $this->assertFalse($nol['AB_rrpe_persen']['rumus'], 'titik nol: AB diketik "-" di workbook');
        $this->assertNull($nol['AB_rrpe_persen']['nilai']);

        $rantai = $this->hitung()[0]['type_b_components']['gaya_rantai'];
        $this->assertSame(0.0, $rantai['T_rsd_persen'], 'aplikasi: RSD di beban nol = 0');
        $this->assertNull($rantai['AB_rrpe_persen'], 'aplikasi: RRPE di beban nol = null (tidak dicetak)');
    }

    /**
     * Zero error: sumber master dan sumber aplikasi BERBEDA, dan data kedua workbook
     * membuat keduanya 0, jadi data tidak bisa membedakannya.
     *
     * Master (`PERHITUNGAN FC!C44:P44`): selisih berpasangan dalam kelompok tiga dari
     * 12 bacaan di BEBAN NOL (baris titik pertama), `C45 = MAX(C44:Q44)`, dibagi
     * `C46` (beban uji terbesar) kali 100. Aplikasi: nilai mutlak terbesar `preload_zero`
     * (tiga bacaan nol sesudah preload) dibagi kapasitas. Yang mana yang dimaksud IK
     * adalah pertanyaan lab (G8) — diamati di sini, bukan diputuskan.
     */
    #[Group('menunggu-keputusan-tm')]
    public function test_menunggu_zero_error_sumber_berbeda_tidak_terbedakan_oleh_data_contoh(): void
    {
        $dasar = self::wb()['excel']['dasar'];

        foreach ($dasar['zero_error_pasangan_C44_P44']['nilai'] as $j => $selisih) {
            $this->assertSame(0.0, (float) $selisih, 'Selisih berpasangan master '.$dasar['zero_error_pasangan_C44_P44']['sel'][$j].' harus 0 di sesi contoh.');
        }

        $this->assertSame(0.0, self::nilai($dasar['zero_error_maks_C45']));
        $this->assertSame('=MAX(C44:Q44)', $dasar['zero_error_rumus_C45']);
        $this->assertSame('=C30-D30', $dasar['zero_error_rumus_C44']);

        $this->assertSame(0.0, GayaMentah::zeroErrorMaks(self::blok()['preload_zero']));

        $zeroAplikasi = array_values(array_filter(
            $this->jejak()['komponen'],
            static fn (array $k): bool => $k['sumber'] === 'zero_error',
        ));
        $this->assertCount(1, $zeroAplikasi);
        $this->assertSame(0.0, (float) $zeroAplikasi[0]['u'], 'zero_error aplikasi = 0, sama dengan master N15 = 0');
    }

    /**
     * Resolusi standar di sesi contoh aplikasi (`GayaSesiContohCocokMasterTest` dan
     * seeder) vs yang dibaca workbook: tidak dipakai di sini (blok diisi dari workbook),
     * tapi tabel standar aplikasi tidak menyimpan resolusi sehingga angka ini harus
     * dipasok tiap sesi. Ditegaskan agar pembaca tidak mengira ia berasal dari tabel.
     */
    public function test_tabel_standar_aplikasi_tidak_memuat_resolusi_standar_jadi_dipasok_sesi(): void
    {
        $standar = TabelStandarGaya::standar(self::kodeStandar());

        $this->assertNotNull($standar);
        $this->assertArrayNotHasKey('resolusi', $standar);
        $this->assertArrayNotHasKey('resolusi_standar', $standar);
        $this->assertGreaterThan(0.0, self::blok()['resolusi_standar']);
    }
}
