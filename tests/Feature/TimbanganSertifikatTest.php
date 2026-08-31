<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sertifikat Timbangan: DELAPAN bagian sampai ke lembar yang dipegang
 * pelanggan, bukan tabel empat kolom.
 *
 * ## Kegagalan yang dijaga di sini
 *
 * Sampai sebelum berkas ini ada, sesi Timbangan terbit lewat jalur generik:
 * satu tabel `Standard | Unit Under Test | Correction` berisi sepuluh baris
 * titik akurasi, dan **tujuh dari delapan bagian master hilang** — Repeatability,
 * Effect of Tare, Loading Influence, Hysterisis, Limit of Performance, Weighing
 * Uncertainty, dan kolom `Nominal Standard` yang bukan `Standard Value`.
 * Bentuk gagalnya paling jahat: lembarnya tetap terbit rapi, bernomor,
 * ber-QR, dan nggak ada satu pun error yang bilang isinya tinggal seperdelapan.
 *
 * Diadu ke HTML yang BENERAN dirender, bukan cuma ke snapshot — jarak antara
 * "angkanya benar di snapshot" dan "angkanya kecetak" itu persis tempat dua bug
 * sertifikat sebelumnya di repo ini sembunyi.
 *
 * ## Angka acuannya
 *
 * Sheet `SERTIFIKAT` workbook master substitusi (`TERBARU_Master_Olda_Timbangan_
 * Subtitusi_291025.xlsm`, sesi `0136-CAL-123`), sel demi sel:
 *
 *   §1 C19/L19/W19 & C20/L20/W20   Half & Full Capacity
 *   §3 C28..C37 / L28..L37         Nominal Standard & Correction
 *   §4 G42..S42 / W42              lima posisi & Maximum Difference
 *   §6 O46                         Limit of Performance
 *   §7 H49..W53 / Y54              U95 of Weighing & K
 */
class TimbanganSertifikatTest extends TestCase
{
    use RefreshDatabase;

    /** Sama dengan `TimbanganMasterTest` — sehalus yang bisa dijanjikan kolomnya. */
    private const TOLERANSI = 5e-6;

    /** Sesi master substitusi; ketiganya ditanam `TimbanganSeeder`. */
    private const SESI_SUB = '0136-CAL-123';

    private function sesi(string $nomor): CalibrationSession
    {
        if (CalibrationSession::query()->doesntExist()) {
            $this->seed(DatabaseSeeder::class);
        }

        return CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
    }

    private function terbitkan(string $nomor): Certificate
    {
        $sesi = $this->sesi($nomor);
        $ada = $sesi->certificate()->first();

        if ($ada !== null) {
            return $ada;
        }

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function blok(string $nomor): array
    {
        $blok = $this->terbitkan($nomor)->snapshot['timbangan'] ?? null;

        $this->assertIsArray($blok, 'Snapshot Timbangan nggak punya blok delapan bagiannya.');

        return $blok;
    }

    /** Bagian 1 — dua kapasitas, STDEV, dan maksimum beda dengan pembacaan berikutnya. */
    public function test_bagian_1_keterulangan_cocok_master(): void
    {
        $rpt = $this->blok(self::SESI_SUB)['keterulangan'];

        $this->assertCount(2, $rpt, 'Baris `Penuh` master itu kerusakan salin-tempel — jangan dicetak.');

        $this->assertSame('Half Capacity', $rpt[0]['label']);
        $this->assertEqualsWithDelta(1000.0, $rpt[0]['kapasitas'], self::TOLERANSI);
        $this->assertEqualsWithDelta(1.198364490607514e-13, $rpt[0]['stdev'], 1e-15);
        $this->assertEqualsWithDelta(0.0, $rpt[0]['maks_beda'], self::TOLERANSI);

        $this->assertSame('Full Capacity', $rpt[1]['label']);
        $this->assertEqualsWithDelta(2000.0, $rpt[1]['kapasitas'], self::TOLERANSI);
        $this->assertEqualsWithDelta(0.03162277660165503, $rpt[1]['stdev'], self::TOLERANSI);
        $this->assertEqualsWithDelta(0.09999999999990905, $rpt[1]['maks_beda'], self::TOLERANSI);
    }

    /**
     * Bagian 2 — kosong itu `null`, bukan nol.
     *
     * Ketiga workbook master nggak memuat pembacaan tare-nya, jadi yang benar
     * di sini tanda pisah. Nol kebaca sebagai "tare-nya sempurna" — pernyataan
     * yang beda artinya, dan di sertifikat terakreditasi bedanya mahal.
     */
    public function test_bagian_2_tare_kosong_bukan_nol(): void
    {
        $this->assertNull($this->blok(self::SESI_SUB)['effect_of_tare']);
    }

    /**
     * Bagian 2 — `|m1 − m2|`, bukan rumus yang tertulis di petunjuk lembarnya.
     *
     * Sel masternya `PERHITUNGAN FC!F42 = ABS(E42−E43)`. Petunjuk di kertas
     * kerja nulis `C = Ms − (M − z)`; kalau itu yang ditiru, angkanya meleset
     * sebesar massa standarnya (di sini 200 kg, bukan 0,3 kg).
     */
    public function test_bagian_2_tare_selisih_mutlak_dua_pembacaan(): void
    {
        $sesi = $this->sesi(self::SESI_SUB);
        $spek = $sesi->spesifikasi_alat;
        $spek['effect_of_tare'] = ['standar' => 200, 'm1' => 200.2, 'm2' => 199.9];
        $sesi->update(['spesifikasi_alat' => $spek]);

        $this->assertEqualsWithDelta(
            0.3,
            $this->blok(self::SESI_SUB)['effect_of_tare'],
            self::TOLERANSI,
        );
    }

    /**
     * Bagian 3 — kolom `Correction` varian substitusi itu KUMULATIF.
     *
     * Master mencetak `PERHITUNGAN FC!T50..T86` (0,0059 · 1,5118 · 2,4177 …
     * 13,309), bukan `ΔI` per langkah (0,0059 · 1,5059 · 0,9059 … 1,4559).
     * Menyimpan `ΔI` di kolom yang dibaca sertifikat bikin koreksi titik
     * terakhir terbit 1,4559 kg di tempat masternya menulis 13,309 kg — tanpa
     * satu pun error.
     */
    public function test_bagian_3_koreksi_substitusi_kumulatif(): void
    {
        $titik = $this->blok(self::SESI_SUB)['titik'];

        $this->assertCount(10, $titik);
        $this->assertEqualsWithDelta(200.00590000000003, $titik[0]['titik_ukur'], self::TOLERANSI);
        $this->assertEqualsWithDelta(0.005900000000025329, $titik[0]['koreksi'], self::TOLERANSI);
        $this->assertEqualsWithDelta(1.511800000000079, $titik[1]['koreksi'], self::TOLERANSI);
        $this->assertEqualsWithDelta(2002.5059, $titik[9]['titik_ukur'], self::TOLERANSI);
        $this->assertEqualsWithDelta(13.309000000000225, $titik[9]['koreksi'], self::TOLERANSI);
    }

    /**
     * Bagian 3 blok sertifikat == `uncertainty_calculations`.
     *
     * Blok delapan bagian lahir dari HITUNG ULANG waktu sertifikat terbit,
     * sementara tabel `hasil` di snapshot yang sama (dan Excel, dan API) dibaca
     * dari database. Begitu keduanya berpisah, satu sesi punya dua kebenaran
     * dan nggak ada yang bisa lihat bedanya dari lembarnya. Ini penjaganya.
     */
    public function test_bagian_3_sama_dengan_yang_tersimpan(): void
    {
        $sertifikat = $this->terbitkan(self::SESI_SUB);
        $blok = $sertifikat->snapshot['timbangan'];
        $tersimpan = $sertifikat->session->uncertaintyCalculations->sortBy('titik_ke')->values();

        $this->assertCount($tersimpan->count(), $blok['titik']);

        foreach ($blok['titik'] as $i => $t) {
            $baris = $tersimpan[$i];

            $this->assertSame((int) $baris->titik_ke, $t['titik_ke']);
            $this->assertEqualsWithDelta((float) $baris->titik_ukur, $t['titik_ukur'], 1e-8);
            $this->assertEqualsWithDelta((float) $baris->koreksi, $t['koreksi'], 1e-8);
            $this->assertEqualsWithDelta(
                (float) $baris->ketidakpastian_diperluas,
                $t['u95_koreksi'],
                1e-8,
            );
        }
    }

    /**
     * Bagian 4 — lima posisi urut kertas + `Maximum Difference` = MAX − MIN.
     *
     * Yang dicetak SELISIH, bukan pembacaan: master `FC!D140..D144` =
     * `beban − pembacaan`. Center 0 · Front −0,1 · Back 0,1 · Left 0 ·
     * Right −0,1, dan bedanya 0,2.
     */
    public function test_bagian_4_eksentrisitas_selisih_bukan_pembacaan(): void
    {
        $ekc = $this->blok(self::SESI_SUB)['eksentrisitas'];

        $this->assertSame(
            ['Center', 'Front', 'Back', 'Left', 'Right'],
            array_column($ekc['posisi'], 'label'),
        );

        $harap = [0.0, -0.09999999999999432, 0.09999999999999432, 0.0, -0.09999999999999432];

        foreach ($harap as $i => $nilai) {
            $this->assertEqualsWithDelta($nilai, $ekc['posisi'][$i]['selisih'], self::TOLERANSI);
        }

        $this->assertEqualsWithDelta(0.19999999999998863, $ekc['maks_beda'], self::TOLERANSI);
    }

    /**
     * Bagian 5 — yang terbit PERBANDINGAN ke resolusi, bukan nilai histeresisnya.
     *
     * `IF(hys <= resolusi, "<", ">")` lalu memajang resolusinya. Sesi master
     * ber-histeresis 0 dan resolusi 0,1 kg, jadi yang benar `< 0,1`.
     */
    public function test_bagian_5_histeresis_perbandingan_bukan_nilai(): void
    {
        $hys = $this->blok(self::SESI_SUB)['histeresis'];

        $this->assertSame('<', $hys['pembanding']);
        $this->assertEqualsWithDelta(0.1, $hys['batas'], self::TOLERANSI);
        // `M` blok histeresis — beban ujinya, bukan kapasitas alatnya.
        $this->assertEqualsWithDelta(200.0, $hys['beban'], self::TOLERANSI);
    }

    /** Bagian 6 & 7 — LOP dan U95 of Weighing, dua angka yang beda dari U95 kolom Correction. */
    public function test_bagian_6_dan_7_cocok_master(): void
    {
        $blok = $this->blok(self::SESI_SUB);

        $this->assertEqualsWithDelta(13.660610503200731, $blok['lop'], self::TOLERANSI);

        // Lantai CMC 0,52 kg menang di kedua budget — itu memang yang tercetak
        // di master (`§3` dan `§7` sama-sama 0,52).
        foreach ($blok['titik'] as $t) {
            $this->assertEqualsWithDelta(0.52, $t['u95_koreksi'], self::TOLERANSI);
            $this->assertEqualsWithDelta(0.52, $t['u95_penimbangan'], self::TOLERANSI);
        }

        $this->assertEqualsWithDelta(1.996564418952312, $blok['k_penimbangan'], 1e-3);
    }

    /**
     * Kolom `Calibration Method` = nomor IK lab, apa pun nama alatnya.
     *
     * `DATABASE` baris 5 ketiga workbook: `Timbangan -> SIDIK-IK-CAL-0505-Rev.7`,
     * dan ketiga sertifikat master mencetaknya.
     *
     * Yang dijaga di sini timbangan yang namanya DI LUAR kosakata master.
     * Cadangan pencocokan nama di `CertificateSnapshotBuilder` mencari kata
     * "Timbangan" di dalam nama alat; sesi master gram alatnya bernama
     * "Moisture Analyzer", dan sebelum `kodeMetode()` ada, kolom metodenya
     * terbit berisi `NMI Monograph 4 (CSIRO 2010)` — rujukan pustaka di tempat
     * dokumen terakreditasi harus menyebut instruksi kerja lab. Tidak ada error
     * di mana pun, karena kolomnya memang terisi.
     */
    #[DataProvider('sesiTimbangan')]
    public function test_metode_kalibrasi_selalu_nomor_ik_master(string $nomorSesi): void
    {
        $this->assertSame(
            'SIDIK-IK-CAL-0505_Rev.7',
            $this->terbitkan($nomorSesi)->snapshot['header']['calibration_method'],
        );
    }

    /** @return array<string, array{string}> */
    public static function sesiTimbangan(): array
    {
        return [
            'kg — alat bernama "Timbangan"' => ['011-CAL-525'],
            'gram — alat bernama "Moisture Analyzer"' => ['019-CAL-425'],
            'substitusi — "Timbangan Elektronik"' => [self::SESI_SUB],
        ];
    }

    /** Kedelapan judul bagian beneran kecetak di HTML yang dirender. */
    public function test_lembar_cetak_bawa_delapan_bagian(): void
    {
        $html = $this->htmlSertifikat(self::SESI_SUB);

        $judul = [
            '1. REPEATABILITY',
            '2. EFFECT OF TARE',
            '3. ACCURACY',
            '4. LOADING INFLUENCE ON SEVERAL POSITION',
            '5. HYSTERISIS',
            '6. LIMIT OF PERFORMANCE',
            '7. WEIGHING UNCERTAINTY',
            'STANDARD USED',
        ];

        foreach ($judul as $bagian) {
            $this->assertStringContainsString($bagian, $html, "Bagian `{$bagian}` nggak kecetak.");
        }

        // Judul kolom master, bukan `Standard Value | Unit Under Test`.
        $this->assertStringContainsString('Nominal Standard', $html);
        $this->assertStringContainsString('Maximum Deviation With the Next Reading', $html);
        $this->assertStringContainsString('Maximum Difference', $html);
        $this->assertStringNotContainsString('Unit Under Test', $html);
    }

    /**
     * Angkanya beneran nyampe ke lembarnya — bukan cuma judulnya.
     *
     * Desimalnya: `d` = 1 (resolusi 0,1 kg) buat nilai & koreksi, `max(d,2)`
     * buat STDEV, `d+1` buat U95 & LOP. Lihat T14 soal kenapa nggak bisa
     * meniru ketiga master sekaligus.
     */
    public function test_lembar_cetak_bawa_angkanya(): void
    {
        $html = $this->htmlSertifikat(self::SESI_SUB);

        // §1 STDEV Full Capacity 0,0316 -> 2 desimal.
        $this->assertStringContainsString('0,03', $html);
        // §3 koreksi kumulatif titik terakhir — 13,3, BUKAN 1,5.
        $this->assertStringContainsString('13,3', $html);
        // §3 & §7 U95 0,52 -> 2 desimal.
        $this->assertStringContainsString('0,52', $html);
        // §4 Maximum Difference 0,2.
        $this->assertStringContainsString('0,2', $html);
        // §5 perbandingan, bukan nilai.
        $this->assertStringContainsString('&lt; 0,1', $html);
        // §6 LOP 13,66.
        $this->assertStringContainsString('13,66', $html);
        // §7 kalimat faktor cakupan, `K` bulat.
        $this->assertStringContainsString('coverage factor ( K ) =', $html);
    }

    /**
     * Sertifikat Timbangan muat SATU halaman.
     *
     * Kepalanya nulis `Page : 1 of 1`, dan delapan bagian di satu lembar itu
     * kasus terberat yang pernah masuk blade ini — lebih tinggi dari tiga
     * bagian Autoklaf yang dulu hampir membatalkannya.
     */
    public function test_sertifikat_muat_satu_halaman(): void
    {
        $sertifikat = $this->terbitkan(self::SESI_SUB);
        $pdf = Storage::disk('arsip')->get($sertifikat->pdf_path);

        // `/Type /Page` yang BUKAN `/Type /Pages` — yang kedua simpul induk.
        $halaman = preg_match_all('~/Type\s*/Page[^s]~', $pdf);

        $this->assertSame(1, $halaman, "Sertifikat Timbangan jadi {$halaman} halaman, padahal kepalanya nulis 'Page : 1 of 1'.");
    }

    /**
     * Alat lain nggak kesenggol: blok delapan bagiannya `null`, dan lembarnya
     * tetap tabel empat kolom.
     */
    #[DataProvider('sesiAlatLain')]
    public function test_alat_lain_tetap_tabel_empat_kolom(string $nomorSesi): void
    {
        $sertifikat = $this->terbitkan($nomorSesi);

        $this->assertNull($sertifikat->snapshot['timbangan'] ?? null);

        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();

        $this->assertStringNotContainsString('1. REPEATABILITY', $html);
        $this->assertStringContainsString('Correction', $html);
    }

    /** @return array<string, array{string}> */
    public static function sesiAlatLain(): array
    {
        return [
            'pH Meter' => ['2405.13.A'],
            'Turbidimeter' => ['2406.32.A'],
        ];
    }

    private function htmlSertifikat(string $nomor): string
    {
        return view(
            'sertifikat.pdf',
            app(DataTampilanSertifikat::class)->untuk($this->terbitkan($nomor)),
        )->render();
    }
}
