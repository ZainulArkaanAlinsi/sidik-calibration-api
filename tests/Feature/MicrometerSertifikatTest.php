<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sertifikat **Micrometer** diadu baris demi baris ke sheet `SERTIFIKAT`
 * master `Master_Olah_Data_Micrometer_2550mm.xlsm`.
 *
 * ## Kenapa test ini ada, terpisah dari MicrometerMasterTest
 *
 * `MicrometerMasterTest` mengadu MESIN HITUNGnya — budget, `uc`, `veff`, `k`,
 * `U95` — dalam µm, satuan sheet `PERHITUNGAN U95%`. Semuanya hijau, dan tetap
 * hijau waktu sertifikatnya mencetak angka yang salah seribu kali lipat.
 *
 * Sebabnya: kolom `uncertainty_calculations.ketidakpastian_diperluas` bersanding
 * dengan `koreksi` di tabel sertifikat, dan `koreksi` itu mm. Lembar ini sempat
 * jadi satu-satunya yang menyimpan ketidakpastiannya dalam µm, jadi sertifikat
 * mencetak `0,00027` dan `0,871` di KOLOM YANG SAMA — dan kepala kolomnya
 * telanjang tanpa satuan, karena `satuanTitik()` belum diisi. Nol error di
 * seluruh jalur; ketahuan cuma waktu HTML-nya beneran dirender dan diadu ke
 * kertas master.
 *
 * Jadi yang dijaga di sini bukan hitungannya, tapi **yang tercetak**.
 */
class MicrometerSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 5e-6;

    /**
     * Sebelas baris `SERTIFIKAT!D18:L28` master, apa adanya — TIGA rentang.
     *
     * Kenapa tiga dan bukan satu: satu sesi contoh cuma membuktikan satu pita
     * CMC, satu nomor formulir, dan satu susunan sebelas nominal. Tiga pita
     * sisanya lolos seluruh sapuan tanpa pernah dijalankan ujung ke ujung —
     * dan varian C justru yang memuat titik 51,0 mm, nominal yang keluar dari
     * pola +2,6 mm dan paling gampang disangka salah ketik.
     *
     * Varian A (0-25 mm) TIDAK ada di sini, dan itu disengaja: blok
     * pra-evaluasi masternya berisi 635,0 sepuluh kali — nilai kapasitas hasil
     * bug inch yang bocor ke sana — jadi simpangan bakunya nol dan sesinya
     * tidak bisa ditanam tanpa mengarang data keterulangan. Lihat
     * `docs/pertanyaan-lab-micrometer.md` §3.
     *
     * @return array<string, array{string, list<array{float, float, float}>, float}>
     */
    public static function rentang(): array
    {
        return [
            '25-50 mm' => ['0106-CAL-1023', [
                [25.00027, 25.0, 0.00027],
                [27.50041, 27.5002, 0.00021],
                [30.99997, 31.0, -0.00003],
                [32.70044, 32.7, 0.00044],
                [35.3001, 35.3, 0.00010],
                [37.9002, 37.9, 0.00020],
                [40.00014, 40.0, 0.00014],
                [42.59983, 42.6, -0.00017],
                [45.20007, 45.2, 0.00007],
                [47.80041, 47.8, 0.00041],
                [49.9999, 49.9996, 0.00030],
            ], 0.87],
            '50-75 mm' => ['002-UB.P-11-20', [
                [49.9999, 50.0022, -0.0023],
                [52.50004, 52.5018, -0.00176],
                [51.00025, 51.003, -0.00275],
                [57.70019, 57.7028, -0.00261],
                [60.2999, 60.3022, -0.0023],
                [62.90012, 62.9022, -0.00208],
                [65.00018, 65.0022, -0.00202],
                [67.60044, 67.6024, -0.00196],
                [70.20009, 70.2028, -0.00271],
                [72.79993, 72.8022, -0.00227],
                [74.9999, 75.0022, -0.0023],
            ], 0.91],
            '75-100 mm' => ['003-UB.P-11-20', [
                [74.9999, 75.0002, -0.0003],
                [77.4998, 77.5002, -0.0004],
                [80.09971, 80.1002, -0.00049],
                [82.70002, 82.7002, -0.00018],
                [85.30032, 85.3, 0.00032],
                [87.9006, 87.9002, 0.0004],
                [90.00015, 90.0002, -0.00005],
                [92.6, 92.6, 0.0],
                [95.20037, 95.2002, 0.00017],
                [97.80039, 97.8002, 0.00019],
                [100.00012, 100.0002, -0.00008],
            ], 0.91],
        ];
    }

    /** @return array{CalibrationSession, array<string, mixed>} */
    private function terbitkan(string $nomorSesi = '0106-CAL-1023'): array
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();

        $this->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        return [$sesi->fresh(), $sesi->fresh()->certificate()->firstOrFail()->snapshot];
    }

    /**
     * @param  list<array{float, float, float}>  $master
     */
    #[DataProvider('rentang')]
    public function test_sebelas_baris_cocok_sertifikat_master(
        string $nomorSesi,
        array $master,
        float $cmcUm,
    ): void {
        [, $snapshot] = $this->terbitkan($nomorSesi);

        $this->assertCount(11, $snapshot['hasil'], 'Sertifikat master mencetak SEBELAS titik.');

        foreach ($snapshot['hasil'] as $i => $baris) {
            [$standar, $uut, $koreksi] = $master[$i];

            $this->assertEqualsWithDelta($standar, $baris['standard_value'], self::TOLERANSI, "Standar baris {$i}");
            $this->assertEqualsWithDelta($uut, $baris['unit_under_test'], self::TOLERANSI, "UUT baris {$i}");
            $this->assertEqualsWithDelta($koreksi, $baris['correction'], self::TOLERANSI, "Koreksi baris {$i}");
        }
    }

    /**
     * U95 tiap rentang berada DI ATAS lantai CMC pitanya sendiri, dan dalam mm.
     *
     * Menggabungkan dua penjagaan yang tadinya cuma jalan di satu rentang:
     * satuannya (mm, bukan µm) dan lantainya (pita masing-masing 0,87 / 0,91 /
     * 0,91 µm). Yang di bawah lantai berarti mengklaim kemampuan di luar
     * lingkup akreditasi — lihat `docs/analisis-pertanyaan-lab-micrometer.md` §1.
     *
     * @param  list<array{float, float, float}>  $master
     */
    #[DataProvider('rentang')]
    public function test_u95_tiap_rentang_dalam_mm_dan_di_atas_lantai_cmc(
        string $nomorSesi,
        array $master,
        float $cmcUm,
    ): void {
        [, $snapshot] = $this->terbitkan($nomorSesi);

        $u95 = (float) $snapshot['hasil'][0]['u95'];

        // Orde besaran: nilai dalam µm bakal ~1000× lebih besar dan tetap lolos
        // ambang mana pun yang cuma "lebih besar dari nol".
        $this->assertLessThan(
            0.01,
            $u95,
            'U95 tercetak dalam µm, bukan mm — di kolom yang sama dengan koreksi '
            .'angka itu terbaca seribu kali lebih besar dari sebenarnya.',
        );

        $this->assertGreaterThanOrEqual(
            $cmcUm / 1000,
            $u95,
            "U95 jatuh di bawah lantai CMC {$cmcUm} µm pita ini.",
        );
    }

    /**
     * Seeder menanam PERSIS tiga rentang — dipatok di sini, bukan dihitung.
     *
     * Ini test yang seharusnya sudah ada sejak awal. Seeder sempat menanam
     * SATU varian saja (`_dipakai_seeder: "2550"`) sementara `TimbanganSeeder`
     * — pola yang diikuti — menanam ketiganya. Tiga pita CMC sisanya lolos
     * SELURUH sapuan registry tanpa pernah dijalankan ujung ke ujung, dan
     * nggak ada satu pun test yang merah: sapuan yang daftarnya datang dari
     * database hijau dengan satu sertifikat persis seperti dia hijau dengan
     * tiga. Yang menangkapnya cuma pertanyaan manusia.
     *
     * Nomor sesi yang HILANG dari daftar ini berarti satu pita CMC berhenti
     * dibuktikan ujung ke ujung. Nomor BARU berarti varian A ikut ditanam —
     * dan itu justru yang harus ditahan: pra-evaluasinya 635,0 sepuluh kali,
     * simpangan bakunya nol.
     */
    public function test_seeder_menanam_persis_tiga_rentang(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tertanam = CalibrationSession::query()
            ->whereHas('equipment', fn ($q) => $q->where('nama_alat_kemampuan', 'Micrometer'))
            ->orderBy('nomor_sesi')
            ->pluck('nomor_sesi')
            ->all();

        $this->assertSame(
            ['002-UB.P-11-20', '003-UB.P-11-20', '0106-CAL-1023'],
            $tertanam,
            "Jumlah sesi Micrometer ter-seed berubah.\n"
            .'HILANG = satu pita CMC berhenti dijalankan ujung ke ujung, dan sapuan lain '
            ."tetap hijau sambil diam-diam memeriksa lebih sedikit.\n"
            .'BARU `095-CAL-324` = varian 0-25 mm ikut ditanam padahal pra-evaluasinya '
            .'berisi 635,0 sepuluh kali — simpangan baku nol, dan U95-nya bakal ditutupi '
            .'lantai CMC sehingga tampak wajar. Lihat docs/permintaan-user-7.md §21.',
        );
    }

    /**
     * U95 tercetak dalam MILIMETER — satuan yang sama dengan kolom di atasnya.
     *
     * Master mencetak `0.0008737653585539594` di `SERTIFIKAT!J29` dengan `mm`
     * di `L29`. Punya kita 0,00087097 mm: bedanya PERSIS komponen drift yang
     * di-nol-kan karena sesi ini mendahului sertifikat balok ukurnya
     * (0,06192/√3 = 0,03575 µm dalam kuadratur) — penyimpangan §2 yang memang
     * disengaja untuk sesi historis.
     *
     * Yang dikunci di sini ORDE BESARANnya, bukan digit terakhirnya: nilai
     * dalam µm bakal 871 kali lebih besar dan tetap lolos kalau ambangnya
     * longgar.
     */
    public function test_u95_tercetak_dalam_mm_bukan_um(): void
    {
        [, $snapshot] = $this->terbitkan();

        $u95 = (float) $snapshot['hasil'][0]['u95'];

        $this->assertEqualsWithDelta(0.00087097, $u95, 1e-7, 'U95 sertifikat (mm)');

        // Penjaga orde besaran, ditulis eksplisit supaya kegagalannya kebaca:
        // 0,871 (µm) lolos ambang mana pun yang cuma "lebih besar dari nol".
        $this->assertLessThan(
            0.01,
            $u95,
            'U95 tercetak dalam µm, bukan mm — di kolom yang sama dengan koreksi (0,00027 mm) '
            .'angka itu terbaca seribu kali lebih besar dari sebenarnya.',
        );

        // Dan tetap di ATAS lantai CMC pita B (0,87 µm = 0,00087 mm).
        $this->assertGreaterThanOrEqual(0.00087, $u95, 'U95 jatuh di bawah lantai CMC 0,87 µm.');
    }

    /**
     * Kepala kolom menyebut satuannya.
     *
     * Tanpa ini blade mencetak `Standard` / `Correction` telanjang, dan tabel mm
     * bersebelahan dengan baris U95 tanpa satu pun petunjuk satuan. Master
     * mencetak baris satuannya sendiri (`D17`/`I17`/`L17`, tiga sel berisi `mm`).
     */
    public function test_kepala_kolom_menyebut_satuan(): void
    {
        [, $snapshot] = $this->terbitkan();

        foreach ($snapshot['hasil'] as $baris) {
            $this->assertSame('mm', $baris['satuan'], 'Satuan baris sertifikat.');
        }
    }

    /**
     * Koreksi tercetak dengan desimal yang CUKUP — bukan `0,000` di semua titik.
     *
     * Master justru begitu: `SERTIFIKAT!D18:L28` berformat `0.000`, jadi
     * sertifikat cetaknya menampilkan koreksi `0.000` di kesebelas titik dan
     * U95 `0.001`. Koreksi mikrometer ini besarnya ~0,0003 mm — tiga desimal
     * menghapus seluruh isi pengukurannya.
     *
     * TIDAK ditiru, dan alasannya sama dengan `IFERROR(…,"")`: kolom koreksi
     * yang seluruhnya nol memberi tahu pelanggan alatnya sempurna di tiap
     * titik. Diangkat sebagai pertanyaan lab §9.
     */
    public function test_desimal_cukup_untuk_koreksi_mikrometer(): void
    {
        [, $snapshot] = $this->terbitkan();

        $this->assertGreaterThanOrEqual(
            5,
            (int) $snapshot['hasil'][0]['desimal'],
            'Koreksi ~0,0003 mm butuh minimal lima desimal; di bawah itu seluruh kolom jadi 0,000.',
        );
        $this->assertGreaterThanOrEqual(
            5,
            (int) $snapshot['hasil'][0]['desimal_u95'],
            'U95 ~0,00087 mm butuh minimal lima desimal; di tiga desimal dia jadi 0,001.',
        );
    }

    /** Sertifikatnya beneran kerender, bukan cuma snapshot-nya kebentuk. */
    public function test_html_kerender_dengan_satuan_dan_sebelas_baris(): void
    {
        [$sesi] = $this->terbitkan();

        $bahan = app(DataTampilanSertifikat::class)->untuk($sesi->certificate()->firstOrFail());
        $html = view('sertifikat.pdf', [...$bahan, 'paksaPadat' => false])->render();

        $this->assertStringContainsString('Standard (mm)', $html);
        $this->assertStringContainsString('Correction (mm)', $html);
        $this->assertStringContainsString('0,00087', $html);
        $this->assertStringContainsString('0,00027', $html);
        $this->assertStringNotContainsString('0,871', $html);
    }
}
