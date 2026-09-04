<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Support\MicrometerMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jalur penuh lembar **Micrometer**: payload HP → `raw_measurements` → hitung
 * ulang, dan angkanya sama di ketiga titik itu.
 *
 * ## Kenapa test ini ada
 *
 * Pola "alat yang satu titiknya bukan satu deret datar" sudah menggigit
 * **delapan kali** — Viscometer, Gas Detector, TITS, Enclosure, tiga alat suhu,
 * Timbangan, Timer/Stopwatch. Bentuknya selalu sama dan selalu **tanpa error**:
 * jalur simpan menaruh bentuknya benar, jalur hitung ulang tidak tahu cara
 * menyusunnya balik, dan tiap titik pulang `hitung_ulang_gagal` sampai admin
 * belajar menekan "setujui tetap" tanpa membaca.
 *
 * Micrometer bentuk kesembilan. Test ini ditulis bareng profilnya, bukan
 * ditemukan belakangan.
 */
class MicrometerSesiTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 5e-6;

    /**
     * Payload sesi ringkas — tiga titik, cukup buat membuktikan jalurnya.
     *
     * @param  array<string, mixed>  $ganti
     * @return array<string, mixed>
     */
    private function payload(Equipment $alat, array $ganti = []): array
    {
        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 20.5, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 41, 'kelembaban_akhir' => 40,
            // Tiga baris PERTAMA kertas varian B (25,0 / 27,5 / 31,0 mm).
            // Nominalnya tidak dikirim — dia dipatok kertas, dan urutan baris
            // yang menentukan titik mana. Tumpukan baloknya diturunkan server.
            // `titik_ukur` ikut dikirim karena HP memang menggambarnya dari
            // bentuk lembar — tapi yang DIPAKAI server nominal varian, bukan
            // yang dikirim. Lihat
            // `test_nominal_dari_varian_menang_atas_yang_dikirim_hp`.
            'measurements' => [
                ['titik_ukur' => 25.0, 'pembacaan' => [25.001, 25.0, 25.001, 25.0, 25.001]],
                ['titik_ukur' => 27.5, 'pembacaan' => [27.502, 27.501, 27.502, 27.501, 27.502]],
                ['titik_ukur' => 31.0, 'pembacaan' => [31.003, 31.002, 31.003, 31.002, 31.003]],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '25-50', 'kapasitas' => '50', 'resolusi' => '0.001',
                // Empat kunci saja — yang dipungut kertas. Suhu balok ukur &
                // suhu UUT diturunkan dari `suhu_awal`/`suhu_akhir` di atas.
                MicrometerMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => 50.0,
                    'resolusi_mm' => 0.001,
                    'pra_evaluasi' => [50.0, 50.0, 50.0, 49.999, 50.0, 50.0, 50.0, 50.001, 50.0, 50.0],
                ],
            ],
            ...$ganti,
        ];
    }

    /** @return array{Equipment, User} */
    private function siapkan(): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', 'ZQ-100')->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    /**
     * Tumpukan balok ukur DITURUNKAN server dari varian kertas, lalu disimpan
     * bersama pembacaannya sebagai baris ber-`peran_sensor` yang TERPISAH.
     *
     * Titik 3 varian B nominalnya 31,0 mm dan tumpukannya 14 + 17 — angka yang
     * tidak pernah dikirim HP. Kalau suatu saat tumpukan itu ikut dikirim dari
     * luar, sesi bisa lahir dengan balok ukur yang berbeda dari yang tercetak
     * di lembarnya sendiri.
     */
    public function test_payload_hp_tersimpan_sebagai_dua_peran(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        $baris = RawMeasurement::where('calibration_session_id', $id)
            ->where('titik_ke', 3)
            ->get();

        $balok = $baris->where('peran_sensor', MicrometerMentah::PERAN_BALOK)
            ->sortBy('sensor_ke')->pluck('pembacaan')->map('floatval')->all();
        $baca = $baris->where('peran_sensor', MicrometerMentah::PERAN_PEMBACAAN)
            ->sortBy('sensor_ke')->pluck('pembacaan')->map('floatval')->all();

        $this->assertSame([14.0, 17.0], array_values($balok), 'tumpukan balok ukur titik 3 varian B');
        $this->assertCount(5, $baca);
        $this->assertEqualsWithDelta(31.003, $baca[0], self::TOLERANSI);

        // Titik ukur yang tersimpan = nominal PRA-CETAK kertas, bukan angka
        // yang dikirim HP.
        $this->assertEqualsWithDelta(31.0, (float) $baris->first()->titik_ukur, self::TOLERANSI);

        // Satuan simpan SELALU mm — lihat MicrometerMentah::SATUAN.
        $this->assertSame([MicrometerMentah::SATUAN], $baris->pluck('satuan')->unique()->all());
    }

    /**
     * Nominal yang DIPAKAI datang dari varian kertas, bukan dari `titik_ukur`
     * yang dikirim HP.
     *
     * Bedanya menentukan: nominal itu yang jadi kolom `Standard` di sertifikat.
     * Kalau HP yang menentukannya, satu payload salah bentuk — atau satu versi
     * HP yang bentuk lembarnya sudah basi — menerbitkan sertifikat dengan
     * nominal yang tidak pernah ada di kertas manapun, dan tidak ada satu pun
     * error yang muncul.
     */
    public function test_nominal_dari_varian_menang_atas_yang_dikirim_hp(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        // Angka ngawur dari HP.
        $payload['measurements'][2]['titik_ukur'] = 999.0;

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $titikUkur = RawMeasurement::where('calibration_session_id', $id)
            ->where('titik_ke', 3)
            ->value('titik_ukur');

        $this->assertEqualsWithDelta(31.0, (float) $titikUkur, self::TOLERANSI);
    }

    /**
     * Bentuk yang BENAR-BENAR dikirim HP untuk baris `Evaluasi` diterima, dan
     * pembacaannya dikonversi ke mm.
     *
     * ## Kenapa test ini ada
     *
     * Tabel `Evaluasi` menyatakan `simpan_ke:
     * spesifikasi_alat.micrometer.pra_evaluasi`, dan HP mengirim SETIAP tabel
     * ber-`simpan_ke` sebagai cerminan tabel yang digambarnya —
     * `{ baris: [ { titik_ukur, nilai: [...] } ] }` — bukan deret datar.
     * (`LembarKerjaState._tanamTabelSpesifikasi()` di repo HP; Timbangan sudah
     * memakai bentuk yang sama untuk blok keterulangannya.)
     *
     * Payload `payload()` di atas memakai bentuk DATAR, yaitu bentuk yang
     * ditulis seeder — jadi seluruh test lain di berkas ini tetap hijau
     * sekalipun jalur HP-nya putus. Dua kegagalan yang lolos dari situ, dan
     * dua-duanya tanpa satu pun error:
     *
     *  1. Deret bersarang kena aturan `pra_evaluasi.* => numeric` dan pulang
     *     **422**. Kegagalannya memang kelihatan — tapi yang gagal SETIAP sesi
     *     Micrometer dari HP, dengan keluhan yang menunjuk sepuluh angka yang
     *     sudah benar diisi teknisi.
     *  2. Pembacaannya tidak ikut dikonversi satuan → sesi `inch` menghitung
     *     simpangan baku ~25× terlalu kecil, komponen pengulangan nyaris
     *     hilang, dan U95 mendarat di lantai CMC — dan yang ini **tanpa satu
     *     pun error**.
     */
    public function test_baris_evaluasi_bentuk_tabel_hp_diterima_dan_dikonversi(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $bacaan = [50.0, 50.0, 50.0, 49.999, 50.0, 50.0, 50.0, 50.001, 50.0, 50.0];

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['pra_evaluasi'] = [
            'baris' => [
                ['titik_ukur' => 1.0, 'pembacaan' => $bacaan],
            ],
        ];

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $tersimpan = CalibrationSession::findOrFail($id)
            ->spesifikasi_alat[MicrometerMentah::KUNCI_SESI]['pra_evaluasi'];

        // Yang TERSIMPAN sudah datar — jalur hitung ulang membaca kolom ini apa
        // adanya, jadi bentuk mentah yang lolos ke DB bakal memblokir sesinya
        // tiap kali dihitung ulang.
        $this->assertCount(10, $tersimpan);
        $this->assertEqualsWithDelta($bacaan, array_map('floatval', $tersimpan), self::TOLERANSI);

        // Dan sesinya beneran TERBIT — bukan nol baris hitungan.
        $this->assertNotEmpty(
            CalibrationSession::findOrFail($id)->uncertaintyCalculations,
            'Sesi terbit dengan nol baris hitungan — pra-evaluasi tidak terbaca.',
        );
    }

    /**
     * Bentuk tabel HP + satuan `inch`: yang tersimpan mm.
     *
     * Dipisah dari test di atas supaya kegagalannya bisa dibedakan — bentuk
     * yang tidak terbaca dan satuan yang tidak dikonversi punya tambalan yang
     * beda, dan satu test yang menguji dua-duanya cuma bilang "ada yang salah".
     */
    public function test_baris_evaluasi_bentuk_tabel_hp_ikut_konversi_satuan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['satuan'] = 'inch';
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['pra_evaluasi'] = [
            'baris' => [
                ['titik_ukur' => 1.0, 'pembacaan' => array_fill(0, 10, 2.0)],
            ],
        ];

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $tersimpan = CalibrationSession::findOrFail($id)
            ->spesifikasi_alat[MicrometerMentah::KUNCI_SESI]['pra_evaluasi'];

        // 2 inch = 50,8 mm. Tanpa konversi yang tersimpan tetap 2.
        $this->assertEqualsWithDelta(array_fill(0, 10, 50.8), array_map('floatval', $tersimpan), self::TOLERANSI);
    }

    /**
     * Nomor formulir ikut VARIAN, dan variannya dipilih dari kapasitas alat.
     *
     * Empat kertas, empat nomor. Salah varian berarti kop lembar terakreditasi
     * mencetak nomor formulir yang bukan miliknya — temuan audit yang tidak
     * menghasilkan satu pun error.
     *
     * Keempatnya diadu, bukan cuma varian alat contoh. Test ini yang DITUNJUK
     * `SemuaProfilLembarKerjaTest::test_nomor_formulir_ada_kecuali_yang_kertasnya_belum_ada`
     * waktu memaklumi `kode_dokumen` null pada panggilan tanpa alat: di sapuan
     * itu `micrometer` terdaftar sebagai nomor-per-varian, dan yang menjaga
     * sisi terisinya cuma di sini. Kalau tabel variannya suatu saat gagal
     * termuat, sapuan itu tetap hijau — yang merah harus test ini.
     *
     * Batas pitanya `<=`, jadi 25 mm masuk varian A dan bukan B; itu ditiru
     * dari `INPUT DATA!F5` master, bukan dipilih di sini.
     *
     * @return list<array{float, ?string, string}>
     */
    public static function kapasitasVarian(): array
    {
        return [
            'batas atas A' => [25.0, 'SIDIK-FM-CAL-0522.A_Rev.1', 'Calibration Work Sheet - Micrometer (0-25 mm)'],
            'tengah B' => [50.0, 'SIDIK-FM-CAL-0522.B_Rev.1', 'Calibration Work Sheet - Micrometer (25-50 mm)'],
            'batas atas C' => [75.0, 'SIDIK-FM-CAL-0522.C_Rev.1', 'Calibration Work Sheet - Micrometer (50-75 mm)'],
            'batas atas D' => [100.0, 'SIDIK-FM-CAL-0522.D_Rev.1', 'Calibration Work Sheet - Micrometer (75-100 mm)'],
            // Di luar keempat pita: kop lembar TIDAK boleh mencetak nomor
            // formulir mana pun. Ini bentuk sesi 0-25 mm master yang satuannya
            // `inch` — kapasitas 635 mm — dan sesi seperti itu memang diblokir.
            'di luar pita' => [635.0, null, 'Calibration Work Sheet - Micrometer'],
        ];
    }

    #[DataProvider('kapasitasVarian')]
    public function test_nomor_formulir_ikut_varian_kapasitas(
        float $kapasitasMm,
        ?string $nomor,
        string $judul,
    ): void {
        [$alat, $teknisi] = $this->siapkan();

        $alat->update(['range_max' => $kapasitasMm]);

        $bentuk = $this->actingAs($teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertSuccessful()
            ->json('data');

        $this->assertSame($nomor, $bentuk['kode_dokumen'] ?? null);
        $this->assertSame($judul, $bentuk['judul']);
    }

    /**
     * Sesi yang baru disimpan bisa dihitung ULANG dan hasilnya sama — nol titik
     * `hitung_ulang_gagal`.
     *
     * Ini gerbang yang menangkap pola kesembilan itu.
     */
    public function test_sesi_tersimpan_bisa_dihitung_ulang_tanpa_beda(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        // Exit code 0 = nol titik gagal — lihat `HitungUlangSesi::handle()`,
        // yang memulangkan FAILURE begitu satu titik pun tidak bisa disusun
        // ulang. Itu gerbang yang menangkap pola kesembilan ini.
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id]])
            ->assertSuccessful();
    }

    /**
     * Satuan `inch` dikonversi SEKALI di ujung masuk: yang tersimpan mm, dan
     * nominal balok ukur TIDAK ikut dikonversi.
     *
     * Ini yang membedakan kita dari master, yang mengalikan pembacaan 25,4 di
     * dalam rumus sementara kolom standarnya tetap mm — dan karena itu
     * menerbitkan koreksi −61 mm pada balok ukur 2,5 mm.
     */
    public function test_satuan_inch_dikonversi_sekali_dan_hanya_pembacaan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['satuan'] = 'inch';
        // Baris pertama varian B: nominal 25,0 mm, tumpukan 6 + 19.
        // 1 inch = 25,4 mm.
        $payload['measurements'] = [
            ['titik_ukur' => 25.0, 'pembacaan' => [1.0, 1.0]],
        ];

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $baris = RawMeasurement::where('calibration_session_id', $id)->get();

        $this->assertEqualsWithDelta(
            25.4,
            (float) $baris->firstWhere('peran_sensor', MicrometerMentah::PERAN_PEMBACAAN)->pembacaan,
            self::TOLERANSI,
            'pembacaan 1 inch harus tersimpan 25,4 mm',
        );
        $this->assertSame(
            [6.0, 19.0],
            $baris->where('peran_sensor', MicrometerMentah::PERAN_BALOK)
                ->sortBy('sensor_ke')->pluck('pembacaan')->map('floatval')->values()->all(),
            'nominal balok ukur JANGAN ikut dikonversi — sertifikatnya selalu mm',
        );

        // Baris Evaluasi ikut dikonversi juga, dan itu BUKAN kelengkapan:
        // `payload()` mengirimnya bentuk DATAR sementara HP mengirim bentuk
        // tabel. Kalau cuma bentuk tabel yang dikonversi, dua bentuk yang
        // membawa angka sama berarti beda — dan tidak ada satu pun error yang
        // membedakannya. Satuan itu sifat SESI-nya, bukan sifat pembungkus
        // payload-nya. Lihat `CalibrationRequest::bakukanPraEvaluasiMicrometer()`.
        $this->assertEqualsWithDelta(
            array_map(
                static fn (float $v): float => $v * 25.4,
                $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['pra_evaluasi'],
            ),
            array_map('floatval', CalibrationSession::findOrFail($id)
                ->spesifikasi_alat[MicrometerMentah::KUNCI_SESI]['pra_evaluasi']),
            self::TOLERANSI,
            'baris Evaluasi harus ikut dikonversi, sama seperti pembacaan tiap titik',
        );
    }

    /**
     * Kapasitas di luar keempat pita CMC memblokir penerbitan U95, dan
     * peringatan sesinya menyebut sebabnya.
     *
     * Ini bentuk yang menjatuhkan master 0-25 mm: satuan `inch` × kapasitas 25
     * = 635 mm, di luar semua pita, dan U95 terbit 0,735 µm tanpa lantai 0,83.
     */
    public function test_kapasitas_di_luar_pita_cmc_diblokir(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['kapasitas_mm'] = 635.0;

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);

        // NOL baris hitungan — bukan baris ber-U95 nol.
        //
        // Bedanya menentukan: baris ber-`ketidakpastian_diperluas` 0 tetap
        // tercetak di sertifikat sebagai `± 0,000`, yaitu klaim pengukuran
        // SEMPURNA — lebih buruk daripada 0,735 µm yang sedang diperbaiki. Dan
        // peringatan sesi tidak menahannya: `CalibrationValidator` membungkus
        // `peringatanSesi()` jadi temuan tingkat PERINGATAN yang boleh dilewati
        // admin lewat `abaikan_peringatan`.
        $this->assertSame(
            0,
            $sesi->uncertaintyCalculations()->count(),
            'sesi tanpa pita CMC tidak boleh melahirkan satu pun baris hitungan',
        );

        $peringatan = collect((new MicrometerProfile)->peringatanSesi($sesi));

        $this->assertTrue(
            $peringatan->contains(fn (array $p): bool => $p['kode'] === 'micrometer_di_luar_cmc'),
            'sesi di luar pita CMC wajib memperingatkan dirinya sendiri',
        );

        // Bentuknya WAJIB `kode` + `pesan` saja — itu yang dibaca
        // `CalibrationValidator::periksaPeringatanProfil()`. Kunci lain
        // (`tingkat`, `judul`) dibuang diam-diam, jadi menaruhnya di sini cuma
        // membohongi pembaca kode.
        $this->assertSame(
            ['kode', 'pesan'],
            array_keys($peringatan->firstWhere('kode', 'micrometer_di_luar_cmc')),
        );
    }

    /**
     * Sesi tanpa blok pra-evaluasi TIDAK dihitung dengan pengulangan nol — dia
     * ditolak dengan alasan yang kebaca.
     */
    public function test_tanpa_blok_pra_evaluasi_titiknya_ditolak_bukan_dihitung(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        unset($payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]);

        $respons = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful();

        $sesi = CalibrationSession::findOrFail($respons->json('data.id'));

        $this->assertSame(
            0, $sesi->uncertaintyCalculations()->count(),
            'tanpa pra-evaluasi tidak boleh ada satu pun titik terhitung',
        );
    }

    /**
     * Blok pra-evaluasi yang ADA tapi cuma berisi SATU pembacaan juga tidak
     * menerbitkan apa-apa.
     *
     * Ini bentuk yang paling licin: simpangan bakunya jatuh ke nol, komponen
     * pengulangan hilang dari budget, U95 mendarat di lantai CMC (0,87 µm) —
     * dan hasilnya kelihatan **wajar**. Persis jebakan "sel kosong dibaca nol"
     * yang aturan proyek larang ditiru.
     */
    public function test_pra_evaluasi_satu_pembacaan_tidak_menerbitkan_u95(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['pra_evaluasi'] = [50.0];

        $respons = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful();

        $sesi = CalibrationSession::findOrFail($respons->json('data.id'));

        $this->assertSame(
            0,
            $sesi->uncertaintyCalculations()->count(),
            'pra-evaluasi satu pembacaan tidak punya simpangan baku — jangan terbit',
        );

        // Alasannya diadu di jalur PREVIEW, karena di situ `belum_dihitung`
        // memang dipulangkan ke teknisi — `store()` cuma menyimpan. Tanpa
        // pemeriksaan ini, sesi yang diblokir tampil sebagai tabel kosong tanpa
        // sebab, dan teknisi membacanya sebagai bug.
        $alasan = collect(
            $this->actingAs($teknisi)
                ->postJson('/api/calibrations/preview', $payload)
                ->assertSuccessful()
                ->json('data.belum_dihitung') ?? []
        )->pluck('alasan')->implode(' ');

        $this->assertStringContainsString('Pra-evaluasi', $alasan);
    }
}
