<?php

namespace Tests\Unit;

use App\Services\Calibration\FlowmeterGravimetriCalculator;
use App\Services\Calibration\TabelStandarFlowmeter;
use Tests\TestCase;

/**
 * Gerbang penerbitan + ARAH perbaikan varian gravimetri.
 *
 * Melengkapi `FlowmeterLantaiCmcTest` (yang menjaga lantai CMC varian **UFM**)
 * dan `FlowmeterGravimetriMasterTest` (yang mengadu angkanya ke master).
 * Di sini yang diuji perilaku yang **tidak** ada di master mana pun — karena
 * masternya memang tidak pernah mengujinya, dan itu justru alasannya.
 */
class FlowmeterGravimetriGerbangTest extends TestCase
{
    private const TOL = 5e-6;

    private function kalk(): FlowmeterGravimetriCalculator
    {
        return new FlowmeterGravimetriCalculator;
    }

    private function cocok(float $harap, float $dapat, string $tag): void
    {
        $skala = max(abs($harap), 1e-9);

        $this->assertLessThanOrEqual(
            self::TOL,
            abs($harap - $dapat) / $skala,
            "{$tag}: harap {$harap}, dapat {$dapat}",
        );
    }

    /**
     * Satu titik Totalizer yang sehat, dengan wadah kosong yang bisa diatur.
     *
     * @param  list<float>  $kosong
     * @return array<string, mixed>
     */
    private function sesiTotalizer(array $kosong = [0.0, 0.0, 0.0]): array
    {
        return [
            'konteks' => [
                'mode' => TabelStandarFlowmeter::MODE_TOTALIZER,
                'satuan' => 'L',
                'resolusi' => 0.01,
                'kode_timbangan' => 1,
            ],
            'titik' => [[
                'titik_ke' => 1,
                'uut' => [140.11, 140.12, 140.09],
                'berat_isi' => [139.9, 139.8, 139.9],
                'berat_kosong' => $kosong,
                'suhu_awal' => [25.5, 25.5, 25.5],
                'suhu_akhir' => [25.4, 25.4, 25.4],
            ]],
        ];
    }

    /**
     * Flowrate titik 1 master, DISKALA 40x supaya masuk pita akreditasi.
     *
     * Sesi contoh master sendiri (2,03 Lpm) ada di luar pita 75–519,4 Lpm dan
     * karena itu diblokir — lihat
     * `FlowmeterGravimetriMasterTest::test_seluruh_sesi_flowrate_di_luar_lingkup_diblokir`.
     * Penskalaan 40x pada UUT dan penimbangan sekaligus TIDAK mengubah
     * aritmetika waktunya sama sekali: deviasi ikut berskala 40x, jadi
     * `deviasi / 40` tetap angka yang sebanding dengan master. Timbangannya ikut
     * pindah ke Dini Argeo (kode 1) karena 80,8 kg ada di luar rentang tabel
     * Mettler.
     *
     * @return array<string, mixed>
     */
    private function sesiFlowrateDiskala(): array
    {
        $skala = 40.0;

        return [
            'skala' => $skala,
            'konteks' => [
                'mode' => TabelStandarFlowmeter::MODE_FLOWRATE,
                'satuan' => 'm3/h',
                'resolusi' => 0.0001,
                'kode_timbangan' => 1,
            ],
            'titik' => [[
                'titik_ke' => 1,
                'uut' => [
                    [0.1221 * $skala, 0.1219 * $skala, 0.1220 * $skala],
                    [0.1221 * $skala, 0.1219 * $skala, 0.1220 * $skala],
                    [0.1221 * $skala, 0.1219 * $skala, 0.1220 * $skala],
                ],
                'berat_isi' => [2.0205 * $skala, 2.0207 * $skala, 2.0206 * $skala],
                'berat_kosong' => [0.0, 0.0, 0.0],
                'waktu_menit' => [1.001, 1.0, 1.0],
                'suhu_awal' => [26.5, 26.5, 26.5],
                'suhu_akhir' => [26.5, 26.6, 26.6],
            ]],
        ];
    }

    /**
     * Berat wadah kosong DIKURANGKAN dari berat isi.
     *
     * Test yang paling penting di berkas ini, dan yang paling gampang tidak
     * ditulis. Blok `Empty Container Weight` bernilai NOL di seluruh sesi kedua
     * workbook master, jadi `D41 = D38 + D40` kebetulan benar di sana dan jalur
     * pengurangannya **nol kali teruji**. Sesi pertama yang benar-benar memakai
     * wadah akan terbit dengan massa kelebihan berat wadahnya — dan tanpa satu
     * pun error, karena 152 kg air di wadah 12,5 kg tetap angka yang wajar.
     */
    public function test_wadah_kosong_dikurangkan(): void
    {
        $tanpaWadah = $this->sesiTotalizer();
        $dasar = $this->kalk()->hitungSesi($tanpaWadah['titik'], $tanpaWadah['konteks'])['titik'][0];

        // Wadah 12,5 kg, dan berat isinya dinaikkan 12,5 kg supaya massa
        // BERSIHNYA identik. Kalau pengurangannya ada, hasilnya sama persis.
        $berWadah = $this->sesiTotalizer([12.5, 12.5, 12.5]);
        $berWadah['titik'][0]['berat_isi'] = [139.9 + 12.5, 139.8 + 12.5, 139.9 + 12.5];

        $dengan = $this->kalk()->hitungSesi($berWadah['titik'], $berWadah['konteks'])['titik'][0];

        $this->cocok(12.5, (float) $dengan['berat_kosong_rata'], 'berat wadah terbaca');
        $this->cocok((float) $dasar['massa_bersih'], (float) $dengan['massa_bersih'], 'massa bersih');
        $this->cocok((float) $dasar['hasil'], (float) $dengan['hasil'], 'hasil');
        $this->cocok((float) $dasar['deviasi'], (float) $dengan['deviasi'], 'deviasi');

        // Dan kalau TIDAK dikurangkan, massanya akan 152,37 kg — 8,9 % lebih
        // besar. Angka itu ditulis di sini supaya kegagalannya terbaca sebagai
        // besaran, bukan sebagai "tidak sama".
        $this->assertLessThan(
            140.0,
            (float) $dengan['massa_bersih'],
            'Massa bersih 152 kg berarti berat wadah ikut terhitung sebagai air.',
        );
    }

    /**
     * Wadah yang lebih berat dari isinya DIBLOKIR, bukan dihitung negatif.
     *
     * Kolom isi dan kolom wadah yang tertukar di HP menghasilkan massa negatif,
     * dan `Mt/ρ` yang negatif tetap angka — deviasinya cuma jadi dua kali lipat
     * pembacaan UUT dengan tanda terbalik.
     */
    public function test_wadah_lebih_berat_dari_isi_diblokir(): void
    {
        $m = $this->sesiTotalizer([200.0, 200.0, 200.0]);
        $hasil = $this->kalk()->hitungSesi($m['titik'], $m['konteks']);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('massa bersihnya nol atau negatif', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Lantai CMC dipasang, dan ARAHNYA naik — 1,06076 -> 1,68128 L.
     *
     * Sel berlabel `CMC` ada di keempat blok master dan SEMUANYA kosong,
     * sementara tabelnya lengkap di `DATABASE!R5:S6`. Yang terbit di sertifikat
     * master titik 1: 0,75761 % pada pita terakreditasi 1,2 %.
     */
    public function test_lantai_cmc_menaikkan_u95_titik_pertama(): void
    {
        $m = $this->sesiTotalizer();
        $t = $this->kalk()->hitungSesi($m['titik'], $m['konteks'])['titik'][0];

        // Yang keluar dari budget, sebelum lantai — masih sekitar angka master.
        $this->assertGreaterThan(1.06, (float) $t['ketidakpastian_diperluas_terkonversi']);
        $this->assertLessThan(1.07, (float) $t['ketidakpastian_diperluas_terkonversi']);

        $this->assertTrue((bool) $t['lantai_cmc_dipakai'], 'Lantai CMC wajib menggigit di titik ini.');
        $this->cocok(1.6812800000000002, (float) $t['u95_sertifikat'], 'U95 berlantai');

        // ARAH, bukan sekadar "berbeda": naik, dan naiknya 59 %.
        $this->assertGreaterThan(
            (float) $t['ketidakpastian_diperluas_terkonversi'],
            (float) $t['u95_sertifikat'],
        );

        // Lantainya PERSEN dari nilai terukur, bukan angka absolut.
        $this->cocok(1.2, (float) $t['u95_persen_of_reading'], '%OR sesudah lantai');
    }

    /**
     * Koreksi timer DIPAKAI — dan deviasinya membesar 2,44 kali.
     *
     * `PERHITUNGAN FC` Flowrate menghitung rantai lengkap sampai `Standard
     * Corrected (minute)` di `D48`, lalu laju alir massa memakai `D45` — waktu
     * MENTAH. `D48` tidak dibaca satu sel pun di seluruh workbook.
     *
     * Angka master untuk titik ini −0,0041534 Lpm. Memakai koreksi timer SAJA
     * memberi −0,0112284 (2,70x). Di sini koreksi suhu ikut dibetulkan, jadi
     * yang keluar −0,0101469 (2,44x). Dua perbaikan yang bertumpuk, dan
     * dua-duanya menjauhkan deviasi dari nol.
     */
    public function test_koreksi_timer_dipakai_dan_deviasi_membesar(): void
    {
        $m = $this->sesiFlowrateDiskala();
        $t = $this->kalk()->hitungSesi($m['titik'], $m['konteks'])['titik'][0];

        $this->cocok(1.0003333333333333, (float) $t['waktu_rata'], 'waktu rata');
        $this->cocok(0.0035, (float) $t['koreksi_timer'], 'koreksi timer');
        $this->cocok(1.0038333333333334, (float) $t['waktu_terkoreksi'], 'waktu terkoreksi');

        $deviasiPerSkala = (float) $t['deviasi'] / (float) $m['skala'];

        $this->cocok(-0.010146912422787934, $deviasiPerSkala, 'deviasi per skala');

        // ARAH: lebih jauh dari nol daripada yang tercetak master.
        $this->assertLessThan(
            -0.0041534,
            $deviasiPerSkala,
            'Deviasi wajib LEBIH NEGATIF dari master; master memakai waktu mentah.',
        );

        // Dan buktinya memang waktu terkoreksi yang dipakai, bukan kebetulan:
        // laju alir massa = Mt / waktu_terkoreksi, sampai digit terakhir.
        $this->cocok(
            (float) $t['massa_terapung'] / (float) $t['waktu_terkoreksi'],
            (float) $t['laju_massa'],
            'laju massa memakai waktu terkoreksi',
        );
    }

    /**
     * Pembacaan tanpa sebaran DITAHAN — diuji `max !== min`, bukan `stdev > 0`.
     *
     * Pelajaran Height Gauge: simpangan baku sepuluh nilai identik cuma nol
     * EKSAK kalau nilainya bisa direpresentasikan persis dalam biner, dan
     * 599,95 sepuluh kali memulangkan 1,2e-13. Di sini bahayanya nyata —
     * ketiga ulangan UUT Flowrate sesi contoh identik PERSIS, jadi penjaganya
     * harus jatuh ke penimbangan.
     */
    public function test_penimbangan_tanpa_sebaran_ditahan(): void
    {
        $m = $this->sesiTotalizer();
        // 139,9 tiga kali — dan 139,9 TIDAK bisa direpresentasikan persis dalam
        // biner, jadi `stdev > 0` di sini akan lolos.
        $m['titik'][0]['berat_isi'] = [139.9, 139.9, 139.9];

        $hasil = $this->kalk()->hitungSesi($m['titik'], $m['konteks']);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('tidak punya sebaran sama sekali', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Kode timbangan yang tidak terdaftar memblokir, bukan jatuh ke yang pertama.
     *
     * Kode timbangan memilih tabel koreksi, U95, kestabilan, DAN drift
     * sekaligus. Jatuh diam-diam ke Dini Argeo berarti memakai U95 0,52 kg
     * untuk penimbangan yang sebenarnya diukur Mettler ber-U95 0,00017 kg —
     * tiga ribu kali lebih besar, dan tetap terlihat wajar.
     */
    public function test_kode_timbangan_tak_dikenal_memblokir(): void
    {
        $m = $this->sesiTotalizer();
        $m['konteks']['kode_timbangan'] = 9;

        $hasil = $this->kalk()->hitungSesi($m['titik'], $m['konteks']);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('tidak ada di tabel mode', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Resolusi kosong menahan titiknya.
     *
     * Tanpa resolusi, komponen resolusi budget bernilai nol dan U95 terbit
     * lebih kecil dari seharusnya — tanpa satu pun angka yang terlihat ganjil.
     */
    public function test_resolusi_kosong_ditahan(): void
    {
        $m = $this->sesiTotalizer();
        $m['konteks']['resolusi'] = 0.0;

        $hasil = $this->kalk()->hitungSesi($m['titik'], $m['konteks']);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('Resolusi alat belum diisi', $hasil['ditolak'][0]['alasan']);
    }

    /** Mode flowrate menuntut waktu, dan waktu nol menahan titiknya. */
    public function test_waktu_nol_ditahan(): void
    {
        $m = $this->sesiFlowrateDiskala();
        $m['titik'][0]['waktu_menit'] = [0.0, 0.0, 0.0];

        $hasil = $this->kalk()->hitungSesi($m['titik'], $m['konteks']);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('nol atau negatif', $hasil['ditolak'][0]['alasan']);
    }
}
