<?php

namespace Tests\Unit;

use App\Services\Calibration\GayaCalculator as G;
use App\Services\Calibration\TabelStandarGaya;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tahap 1 olah data GAYA: fungsi inti diadu ke master, lalu ke kasus tepi.
 *
 * Panduan master membagi pembuktiannya jadi dua blok, dan menegaskan **keduanya
 * wajib**:
 *
 *   Blok A — rekonsiliasi ke sesi contoh master. Ini yang membuktikan rumusnya
 *            benar.
 *   Blok B — data lapangan sintetis & kasus tepi. Ini yang membuktikan kodenya
 *            tidak pecah saat datanya tidak rapi.
 *
 * Blok A saja tidak cukup, dan alasannya terukur: dua dari lima titik di sesi
 * contoh punya 12 pembacaan IDENTIK sampai digit terakhir, tiga lainnya cuma
 * punya dua nilai unik. Mesin uji nyata tidak pernah begitu — load cell punya
 * noise, mesin punya gesekan. Artinya cabang "STDEV kecil tapi bukan nol",
 * "nearest-match di antara dua set point", dan "MAX/MIN di antara banyak nilai
 * berbeda" tidak pernah dijalankan kalau testnya cuma menyalin sesi contoh.
 * Test bisa hijau semua, lalu jebol di sesi nyata pertama.
 */
class GayaCalculatorTest extends TestCase
{
    /** Sesi contoh UTM `0169-CAL-324`, titik nominal 200 kgf. */
    private const SATUAN = 'kgf';

    private const STANDAR = '5kN';

    private const ARAH = TabelStandarGaya::ARAH_PULL;

    private const SUHU_SERTIFIKAT = 23.15;

    private const SUHU_AKTUAL = 24.4;

    /** @return array<int, float> 12 bacaan: satu 200,6 di antara sebelas 200,538 */
    private function bacaanContoh(): array
    {
        return [200.538, 200.6, 200.538, 200.538, 200.538, 200.538,
            200.538, 200.538, 200.538, 200.538, 200.538, 200.538];
    }

    /** @return array<string, mixed> */
    private function titikContoh(): array
    {
        return G::hitungTitik(
            200.0,
            $this->bacaanContoh(),
            self::SATUAN,
            self::STANDAR,
            self::ARAH,
            self::SUHU_SERTIFIKAT,
            self::SUHU_AKTUAL,
        );
    }

    /*
    |---------------------------------------------------------------------------
    | BLOK A — rekonsiliasi ke master
    |---------------------------------------------------------------------------
    */

    /**
     * Enam angka rantai hitung, toleransi 1e-12 seperti yang diminta panduan.
     *
     * Angka harapannya diambil dari sel master `PERHITUNGAN FC` baris titik
     * 200 kgf, bukan dari hitungan ulang siapa pun.
     */
    public function test_rantai_hitung_cocok_master(): void
    {
        $t = $this->titikContoh();

        $harap = [
            'B' => 1.9619999999999997,
            'R' => 1.9673284649999998,
            'Y' => 1.9703685264999997,
            'Z' => 1.969703527122306,
            'AA' => 0.008368526499999973,
            'AB' => 0.0309999999999968,
        ];

        foreach ($harap as $kunci => $nilai) {
            $this->assertEqualsWithDelta(
                $nilai,
                $t[$kunci],
                1e-12,
                "Kolom {$kunci} meleset dari master.",
            );
        }
    }

    /** `S` & `T` dibedakan dari `AB`: simpangan baku lawan rentang penuh. */
    public function test_stdev_dan_rsd_cocok_master(): void
    {
        $t = $this->titikContoh();

        $this->assertEqualsWithDelta(0.00017557799036323893, $t['S'], 1e-18);
        $this->assertEqualsWithDelta(0.008924691198591433, $t['T'], 1e-15);

        $this->assertNotEquals(
            round((float) $t['T'], 9),
            round((float) $t['AB'], 9),
            'RSD dan RRPE ketuker — yang dicetak sertifikat RRPE, yang masuk budget RSD.',
        );
    }

    /**
     * Nearest-match memilih set point 200 kg (1,96133 kN), bukan tetangganya.
     *
     * Kalau suatu saat ada yang mengganti ini jadi interpolasi linier, `W`
     * bergeser dan seluruh kolom sesudahnya ikut — test ini yang merah duluan.
     */
    public function test_koreksi_standar_nearest_match(): void
    {
        $t = $this->titikContoh();

        $this->assertSame(0.0030400615, $t['W']);
        $this->assertEqualsWithDelta(1.96133, $t['set_point_standar_kn'], 1e-12);
        $this->assertFalse($t['di_luar_rentang_tabel']);
    }

    /**
     * `AA` memakai Y, bukan Z — anomali master yang sengaja direplikasi.
     *
     * Kalau ada yang "membetulkannya" jadi `Z - B`, angkanya berubah 0,00066 kN
     * dan sertifikat yang terbit tidak lagi sama dengan yang pernah dicetak
     * lab. Selisihnya dikunci di sini supaya perubahannya kelihatan sebagai
     * keputusan, bukan kelalaian.
     */
    public function test_correction_memakai_y_bukan_z(): void
    {
        $t = $this->titikContoh();

        $this->assertEqualsWithDelta($t['Y'] - $t['B'], $t['AA'], 1e-15);
        $this->assertEqualsWithDelta(0.000665, $t['AA'] - ($t['Z'] - $t['B']), 1e-6);
    }

    /*
    |---------------------------------------------------------------------------
    | BLOK B — data lapangan & kasus tepi
    |---------------------------------------------------------------------------
    */

    /** Dua belas bacaan yang semuanya berbeda — bentuk data lapangan sebenarnya. */
    public function test_data_lapangan_semua_bacaan_berbeda(): void
    {
        $bacaan = [];

        for ($i = 0; $i < 12; $i++) {
            $bacaan[] = 200.0 + ($i - 6) * 0.017;
        }

        $t = G::hitungTitik(200.0, $bacaan, self::SATUAN, self::STANDAR, self::ARAH,
            self::SUHU_SERTIFIKAT, self::SUHU_AKTUAL);

        $this->assertGreaterThan(0.0, $t['S'], 'STDEV nol padahal bacaannya berbeda semua.');
        $this->assertGreaterThan(0.0, $t['T']);
        $this->assertGreaterThan(0.0, $t['AB']);
        $this->assertSame([], $t['temuan'], 'Data lapangan yang wajar nggak boleh memicu temuan.');
    }

    /** Titik nol: RSD 0 dan RRPE null — bukan error, bukan pembagian nol. */
    public function test_titik_nol_tidak_meledak(): void
    {
        $t = G::hitungTitik(0.0, array_fill(0, 12, 0.0), self::SATUAN, self::STANDAR,
            self::ARAH, self::SUHU_SERTIFIKAT, self::SUHU_AKTUAL);

        $this->assertSame(0.0, $t['T'], 'Titik nol memang nggak punya RSD.');
        $this->assertNull($t['AB'], 'RRPE titik nol dicetak "-" di sertifikat.');
        $this->assertSame(0.0, $t['B']);
    }

    /**
     * Pembacaan nol pada beban NON-nol itu temuan, bukan angka nol yang wajar.
     *
     * Ini beda yang paling gampang hilang kalau ditulis `$rata ?: 0`: yang satu
     * titik nol yang normal, yang satu alat yang tidak membaca beban 500 kgf.
     */
    public function test_pembacaan_nol_pada_beban_non_nol_jadi_temuan(): void
    {
        $t = G::hitungTitik(500.0, array_fill(0, 12, 0.0), self::SATUAN, self::STANDAR,
            self::ARAH, self::SUHU_SERTIFIKAT, self::SUHU_AKTUAL);

        $this->assertNull($t['T'], 'RSD dibulatkan diam-diam jadi nol.');
        $this->assertNotEmpty($t['temuan']);
        $this->assertStringContainsString('Pembacaan nol', implode(' ', $t['temuan']));
    }

    /** Dua belas bacaan identik: tetap dihitung, tapi wajib memunculkan peringatan. */
    public function test_semua_bacaan_identik_memunculkan_peringatan(): void
    {
        $t = G::hitungTitik(200.0, array_fill(0, 12, 200.538), self::SATUAN, self::STANDAR,
            self::ARAH, self::SUHU_SERTIFIKAT, self::SUHU_AKTUAL);

        $this->assertSame(0.0, $t['S']);
        $this->assertNotEmpty($t['temuan'], 'Pembacaan identik lolos tanpa tanda.');
        $this->assertStringContainsString('identik', implode(' ', $t['temuan']));
    }

    /** Beban di luar rentang tabel tetap dihitung, tapi ditandai ekstrapolasi. */
    public function test_beban_di_luar_rentang_tabel_ditandai(): void
    {
        $t = G::hitungTitik(900.0, array_fill(0, 12, 900.0), self::SATUAN, self::STANDAR,
            self::ARAH, self::SUHU_SERTIFIKAT, self::SUHU_AKTUAL);

        $this->assertTrue($t['di_luar_rentang_tabel']);
        $this->assertStringContainsString('di luar rentang', implode(' ', $t['temuan']));
        $this->assertNotNull($t['W'], 'Tetap menghitung — yang diminta peringatan, bukan blokir.');
    }

    /**
     * Kombinasi standar x arah yang tidak punya tabel: berhenti, bukan nol.
     *
     * `Load Cell 3000 kN` cuma dikalibrasi arah tekan. Membaca ketiadaan tabel
     * sebagai koreksi nol berarti sesi tetap terbit dengan angka yang tidak
     * pernah ditelusuri ke sertifikat standar mana pun.
     */
    public function test_kombinasi_standar_arah_tanpa_tabel_ditolak(): void
    {
        $t = G::hitungTitik(1000.0, array_fill(0, 12, 1000.0), 'kN', '3000kN',
            TabelStandarGaya::ARAH_PULL, 28.6, 24.4);

        $this->assertNull($t['W'], 'Ketiadaan tabel dibaca sebagai koreksi nol.');
        $this->assertNull($t['AA']);
        $this->assertStringContainsString('arah', implode(' ', $t['temuan']));
    }

    /** Satuan kosong/ngawur dilempar — master membiarkannya jadi string "PILIH SATUAN". */
    public function test_satuan_tidak_dikenal_dilempar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        G::keKn(200.0, '');
    }

    /** Seri nearest-match: yang diambil set point yang lebih dulu, meniru MATCH Excel. */
    public function test_seri_nearest_match_ambil_yang_pertama(): void
    {
        // Tabel 5 kN arah Pull punya set point 150 kg (1,4709975 kN) dan
        // 200 kg (1,96133 kN). Titik tengahnya persis di 1,71616375 kN.
        $tengah = (1.4709975 + 1.96133) / 2;

        $hasil = TabelStandarGaya::koreksi($tengah, self::STANDAR, self::ARAH);

        $this->assertNotNull($hasil);
        $this->assertEqualsWithDelta(
            1.4709975,
            $hasil['set_point_kn'],
            1e-12,
            'Yang seri harus ambil set point yang lebih dulu di tabel.',
        );
    }

    /** Faktor satuan dibaca dari tabel master, bukan diketik ulang di kode. */
    public function test_faktor_satuan_dari_tabel_master(): void
    {
        $this->assertSame(0.00981, TabelStandarGaya::faktorSatuan('kgf'));
        $this->assertSame(1.0, TabelStandarGaya::faktorSatuan('kN'));
        $this->assertSame(0.001, TabelStandarGaya::faktorSatuan('N'));
        $this->assertNull(TabelStandarGaya::faktorSatuan('psi'));
    }

    /**
     * Drift standar yang sama berbeda antar workbook — ketiganya disimpan.
     *
     * `Load Cell 5 kN` arah Tarik: workbook UTM menulis 0, dua workbook lain
     * menulis 0,04. Memilih salah satu sebagai "yang benar" menggeser U95% yang
     * terbit, jadi yang memilih profil alatnya — sambil menunggu jawaban lab.
     */
    public function test_drift_tersimpan_per_workbook(): void
    {
        $semua = TabelStandarGaya::driftSemuaSumber('5kN', TabelStandarGaya::ARAH_PULL);

        $this->assertSame(0.0, $semua['utm'] ?? null);
        $this->assertEqualsWithDelta(0.04, $semua['load_cell'] ?? -1, 1e-12);
        $this->assertEqualsWithDelta(0.04, $semua['proving_ring'] ?? -1, 1e-12);
    }
}
