<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\FlowmeterProfile;
use App\Services\Calibration\Profiles\FlowmeterTotalizerProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sertifikat **Flowmeter Ultrasonic** — empat kolom hasil, `k` per titik, dan
 * blok spesifikasi pipa yang di master ada labelnya tapi tidak ada isinya.
 *
 * ## Tiga hal yang dijaga
 *
 *  1. **Arah kolom `Correction`.** Sertifikat master menamai kolomnya
 *     `Correction` dan mengisinya `=H26-E26` — Standard Indication dikurangi
 *     Unit Under Test. Tertukar tandanya, sertifikat menyuruh pelanggan
 *     menggeser alatnya ke arah yang salah, dan BESARNYA tetap benar sehingga
 *     tidak ada satu pun angka yang terlihat ganjil. Ini sudah pernah terjadi
 *     di berkas profilnya dan ketahuan lewat test, bukan lewat pembacaan.
 *  2. **`k` per titik.** Master mencetak SATU baris `Coverage Factor (k) =`
 *     yang menunjuk `'PERHITUNGAN U95%'!J59` — `k` Titik 1. Di sesi contoh
 *     Flowrate, titik 1 `k = 2,1009` dan titik 2 `k = 1,9908`: beda 5,5 %, dan
 *     yang tercetak 2,1009 untuk KEDUANYA.
 *  3. **Spesifikasi pipa tercetak.** Sertifikat Flowrate master punya label
 *     `B19`..`B22` dengan sel isi KOSONG tanpa rumus; sertifikat Totalizer
 *     tidak punya labelnya sama sekali.
 */
class FlowmeterSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private const TOL = 5e-6;

    /**
     * Kolom `SERTIFIKAT!E26:K27` master Flowrate — UUT, Standard Indication,
     * Correction.
     *
     * @var array<int, array{float, float, float}>
     */
    private const FLOWRATE_MASTER = [
        1 => [101.95755555555554, 100.67866666666667, -1.278888888888872],
        2 => [310.6425555555555, 306.828, -3.8145555555555006],
    ];

    /** @return array<string, mixed> */
    private function terbitkan(string $nomorSesi): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();

        // `abaikan_peringatan` memang diperlukan, dan itu BUKAN kelemahan sesi
        // contohnya: `FlowmeterProfile::peringatanSesi()` selalu memunculkan
        // catatan bahwa kedua master belum divalidasi (`FORM VALIDASI` kolom
        // VALIDATION kosong), dan sesi Flowrate menambah peringatan jarak titik
        // tabel 23,8 %. Keduanya benar dan sengaja tidak dihilangkan.
        $this->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail()->snapshot;
    }

    private function siap(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
    }

    /**
     * Kolom `Correction` = Standard Indication − Unit Under Test, sama seperti
     * `=H26-E26` master. Ketiga kolomnya diadu sekaligus supaya tandanya tidak
     * bisa "benar sendirian".
     */
    public function test_empat_kolom_flowrate_cocok_sertifikat_master(): void
    {
        $this->siap();
        $snapshot = $this->terbitkan('DEMO-FM-FLW-001');

        $this->assertCount(2, $snapshot['hasil'], 'Sesi contoh Flowrate punya DUA titik.');

        foreach ($snapshot['hasil'] as $baris) {
            [$uut, $standar, $koreksi] = self::FLOWRATE_MASTER[$baris['titik_ke']];

            $this->assertEqualsWithDelta($uut, $baris['unit_under_test'], abs($uut) * self::TOL);
            $this->assertEqualsWithDelta($standar, $baris['standard_value'], abs($standar) * self::TOL);
            $this->assertEqualsWithDelta(
                $koreksi,
                $baris['correction'],
                abs($koreksi) * self::TOL,
                "Kolom Correction titik {$baris['titik_ke']} meleset. Arahnya Standard − UUT "
                .'(`=H26-E26` master); tertukar, besarnya tetap benar dan tidak ada yang terlihat ganjil.',
            );

            // Tandanya NEGATIF di kedua titik — alatnya membaca lebih besar
            // dari standarnya. Diuji terpisah dari nilainya supaya kegagalan
            // tanda kebaca sebagai kegagalan tanda.
            $this->assertLessThan(0, $baris['correction']);
        }
    }

    /**
     * U95 dicetak PER TITIK, dan `k`-nya juga — bukan satu angka Titik 1 untuk
     * seluruh tabel.
     */
    public function test_u95_dan_k_per_titik_bukan_satu_untuk_semua(): void
    {
        $this->siap();
        $snapshot = $this->terbitkan('DEMO-FM-FLW-001');

        $this->assertTrue($snapshot['u95_per_titik'], 'Kolom U95 per titik tidak dinyalakan.');

        $baris = collect($snapshot['hasil'])->keyBy('titik_ke');

        foreach ([1, 2] as $titikKe) {
            $this->assertNotNull($baris[$titikKe]['u95'] ?? null, "U95 titik {$titikKe} kosong.");
            $this->assertNotNull($baris[$titikKe]['faktor_cakupan_k'] ?? null, "k titik {$titikKe} kosong.");
        }

        // Master mencetak 2,1009 untuk KEDUA titik. Di sini keduanya beda, dan
        // itu yang membuat blade mencetak `Coverage Factor ( k ) ≈` alih-alih
        // `=`. Kalau suatu saat keduanya jadi sama, berarti `k` kembali diambil
        // dari satu titik.
        $this->assertGreaterThan(
            1e-6,
            abs((float) $baris[1]['faktor_cakupan_k'] - (float) $baris[2]['faktor_cakupan_k']),
            'Kedua titik punya `k` yang sama persis — master mencetak k Titik 1 untuk semua titik, '
            .'dan itu yang justru sedang diperbaiki.',
        );

        $this->assertEqualsWithDelta(2.1009220402410387, (float) $baris[1]['faktor_cakupan_k'], 1e-6);
        $this->assertEqualsWithDelta(1.9908470688116875, (float) $baris[2]['faktor_cakupan_k'], 1e-6);

        // Titik 2 mendarat di lantai CMC — lihat `FlowmeterLantaiCmcTest`.
        $this->assertEqualsWithDelta(3.727710666666666, (float) $baris[2]['u95'], 3.7277 * self::TOL);
    }

    /**
     * Blok spesifikasi pipa tercetak, dan isinya berasal dari
     * `spesifikasi_alat.flowmeter` — bukan label kosong seperti di master.
     */
    public function test_spesifikasi_pipa_tercetak(): void
    {
        $this->siap();

        foreach (['DEMO-FM-TOT-001', 'DEMO-FM-FLW-001'] as $nomorSesi) {
            $snapshot = $this->terbitkan($nomorSesi);
            $pipa = $snapshot['flowmeter'] ?? null;

            $this->assertNotNull($pipa, "Sertifikat `{$nomorSesi}` nggak membawa blok spesifikasi pipa.");

            $this->assertSame('Carbon Steel', $pipa['material_pipa']);
            $this->assertSame('Air', $pipa['jenis_fluida']);
            $this->assertSame('Z', $pipa['path_configuration']);

            // Diameter LUAR rata-rata tiga bacaan (50,81 / 50,82 / 50,81) dan
            // ketebalan (2,32 / 2,31 / 2,32) — `INPUT DATA!E25:G25` & `E26:G26`.
            $this->assertEqualsWithDelta(50.81333333333333, $pipa['diameter_luar_mm'], 1e-9);
            $this->assertEqualsWithDelta(2.3166666666666664, $pipa['ketebalan_mm'], 1e-9);
            // Diameter DALAM = D_luar − 2·tebal; dia yang masuk hitungan `u_A`.
            $this->assertEqualsWithDelta(46.18, $pipa['diameter_dalam_mm'], 1e-9);
            // `A = (3,14/4)·d²` — π-nya 3,14 ditiru master. `PERHITUNGAN U95%!L35`.
            $this->assertEqualsWithDelta(1674.0850340000002, $pipa['luas_penampang_mm2'], 1674.085 * self::TOL);

            // Liner kosong TIDAK dicetak sebagai baris berisi `—`: pipa polos
            // itu keadaan yang sah, dan baris `—` di sertifikat terakreditasi
            // kebaca seperti data yang HILANG.
            $this->assertNull($pipa['liner_material']);
        }
    }

    /**
     * Alat LAIN tidak ikut membawa blok ini.
     *
     * Kunci `flowmeter` ditambahkan ke snapshot SEMUA sertifikat; kalau
     * `instanceof`-nya longgar, sertifikat alat lain ikut mencetak blok pipa
     * berisi `—` — dan itu bukan error, cuma lembar yang salah.
     */
    public function test_alat_lain_tidak_membawa_blok_pipa(): void
    {
        $this->siap();

        $registry = app(CalibrationProfileRegistry::class);

        // Diuji lewat PENJAGANYA, bukan lewat menerbitkan sertifikat alat lain.
        // Alasannya bukan kemudahan: sesi contoh alat lain punya temuan
        // pemblokirnya masing-masing (`approve` memulangkan 422), jadi test yang
        // lewat situ bakal merah karena sebab yang sama sekali tidak berhubungan
        // dengan blok pipa — dan yang membaca merahnya bakal mengira blok
        // pipanya yang rusak.
        $lain = CalibrationSession::whereHas(
            'equipment',
            fn ($q) => $q->whereNotIn('nama_alat_kemampuan', [
                'Flow Meter Cairan (Totalizer)',
                'Flow Meter Cairan (Flowrate)',
            ]),
        )->has('uncertaintyCalculations')->with('equipment')->firstOrFail();

        $profil = $registry->untukAlat($lain->equipment);

        $this->assertNotInstanceOf(
            FlowmeterProfile::class,
            $profil,
            "Alat `{$lain->equipment->nama_alat_kemampuan}` mendarat di profil Flowmeter.",
        );

        // Penjaga KEDUA: profil Flowmeter pun balik `null` untuk sesi yang blok
        // `spesifikasi_alat.flowmeter`-nya tidak ada. Tanpa ini, sesi flowmeter
        // yang bloknya belum terisi mencetak blok pipa berisi `—` semua.
        $this->assertNull(
            (new FlowmeterTotalizerProfile)->spesifikasiPipaSertifikat($lain),
            'Profil Flowmeter memulangkan blok pipa untuk sesi yang nggak punya bloknya.',
        );

        // Dan penjaga KETIGA: varian yang salah pun balik `null` — sesi
        // Flowrate tidak boleh mencetak blok lewat profil Totalizer.
        $flowrate = CalibrationSession::where('nomor_sesi', 'DEMO-FM-FLW-001')->firstOrFail();

        $this->assertNull(
            (new FlowmeterTotalizerProfile)->spesifikasiPipaSertifikat($flowrate),
            'Profil Totalizer memulangkan blok pipa untuk sesi bermode `flowrate`.',
        );
    }
}
