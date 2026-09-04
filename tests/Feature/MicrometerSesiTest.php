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

        // Tiap baris menyebutkan satuannya sendiri. Sesi contoh ini `mm`, jadi
        // penunjukan dan nominal balok sama-sama mm.
        $this->assertSame(['mm'], $baris->pluck('satuan')->unique()->all());
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
        // Selisih skala PEMBULATAN — HP menggambar 31,0004 dari bentuk lembar
        // sementara varian memegang 31,0. Yang menang varian.
        //
        // Selisih BESAR bukan pembulatan, itu salah pemetaan baris, dan sejak
        // penjaga di `susunBlokMicrometer` ada titiknya ditolak — bukan
        // disimpan di nominal yang salah. Lihat
        // `test_baris_yang_bergeser_ditolak_bukan_disimpan_salah`.
        $payload['measurements'][2]['titik_ukur'] = 31.0004;

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
     * Bentuk tabel HP + satuan `inch`: yang tersimpan MENTAH, bukan mm.
     *
     * Dipisah dari test di atas supaya kegagalannya bisa dibedakan — bentuk
     * yang tidak terbaca dan satuan yang salah tempat punya tambalan yang beda,
     * dan satu test yang menguji dua-duanya cuma bilang "ada yang salah".
     */
    public function test_baris_evaluasi_bentuk_tabel_hp_disimpan_mentah(): void
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

        // Tersimpan MENTAH: 2,0 apa adanya, bukan 50,8. Satuannya ikut di blok
        // yang sama, dan `MicrometerMentah::blokSesi()` yang mengubahnya ke mm
        // waktu dipakai menghitung.
        $this->assertEqualsWithDelta(array_fill(0, 10, 2.0), array_map('floatval', $tersimpan), self::TOLERANSI);

        $blok = MicrometerMentah::blokSesi(CalibrationSession::findOrFail($id)->spesifikasi_alat);
        $this->assertEqualsWithDelta(array_fill(0, 10, 50.8), $blok['pra_evaluasi'], self::TOLERANSI, '2 inch = 50,8 mm waktu dihitung');
    }

    /**
     * Menyimpan payload yang SAMA dua kali menghasilkan data yang sama.
     *
     * ## Kegagalan yang ditutup — data rusak berlipat, tanpa satu pun error
     *
     * Versi pertama mengonversi satuan di ujung MASUK: penunjukan dikalikan
     * 25,4 lalu disimpan dalam mm. HP tidak punya konversi balik sama sekali —
     * dia menggambar ulang lembar dari angka yang dia terima. Jadi alur kerja
     * lapangan yang paling biasa merusak datanya sendiri:
     *
     *  1. Teknisi simpan DRAFT di dekat alatnya. 1 inch → tersimpan 25,4 mm.
     *  2. Dia buka lagi lembarnya buat melanjutkan. Kotaknya terisi 25,4.
     *  3. Dia simpan lagi. 25,4 × 25,4 = **645,16 mm**.
     *
     * Dan berlipat tiap simpan. Baris `Evaluasi` ikut: 2 inch jadi 50,8 lalu
     * **1290,32**. Yang terbit sertifikat dengan koreksi salah ratusan kali
     * lipat, dari lembar yang di layar kelihatan wajar.
     *
     * Ditutup dengan berhenti mengonversi di ujung masuk: yang tersimpan angka
     * MENTAH plus satuannya, dan yang mengubah ke mm
     * [\App\Support\MicrometerMentah::keMm] di tempat pakai.
     *
     * Diuji lewat DRAFT, bukan sesi final: sesi `menunggu_approval` menolak
     * PUT dari teknisi, jadi test yang lewat jalur final bakal hijau tanpa
     * pernah menyentuh bug-nya.
     */
    public function test_simpan_draft_dua_kali_tidak_mengonversi_satuan_dua_kali(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat, ['status' => 'draft']);
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['satuan'] = 'inch';
        $payload['measurements'] = [
            ['titik_ukur' => 25.0, 'pembacaan' => array_fill(0, 5, 1.0)],
        ];
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['pra_evaluasi'] = [
            'baris' => [['titik_ukur' => 1.0, 'pembacaan' => array_fill(0, 10, 2.0)]],
        ];

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $bacaan = static fn (int $id): array => [
            (float) RawMeasurement::where('calibration_session_id', $id)
                ->where('peran_sensor', MicrometerMentah::PERAN_PEMBACAAN)
                ->orderBy('sensor_ke')->value('pembacaan'),
            (float) CalibrationSession::findOrFail($id)
                ->spesifikasi_alat[MicrometerMentah::KUNCI_SESI]['pra_evaluasi'][0],
        ];

        $pertama = $bacaan($id);

        // HP membuka draft lagi lalu menyimpan: yang dikirim ulang persis yang
        // dia terima dari server.
        $sesi = CalibrationSession::findOrFail($id);
        $ulang = $payload;
        $ulang['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['pra_evaluasi'] = [
            'baris' => [[
                'titik_ukur' => 1.0,
                'pembacaan' => array_values($sesi->spesifikasi_alat[MicrometerMentah::KUNCI_SESI]['pra_evaluasi']),
            ]],
        ];
        $ulang['measurements'] = [[
            'titik_ukur' => 25.0,
            'pembacaan' => array_fill(0, 5, $pertama[0]),
        ]];

        $this->actingAs($teknisi)
            ->putJson("/api/calibrations/{$id}", $ulang)
            ->assertSuccessful();

        $this->assertEqualsWithDelta(
            $pertama,
            $bacaan($id),
            self::TOLERANSI,
            'Simpanan kedua mengubah angkanya — satuan dikonversi dua kali.',
        );

        // Dan yang tersimpan memang MENTAH: 1.0 apa adanya, bukan 25,4.
        $this->assertEqualsWithDelta([1.0, 2.0], $pertama, self::TOLERANSI);
    }

    /**
     * Yang DIHITUNG tetap mm walau yang disimpan mentah dalam inch.
     *
     * Pasangan test di atas: berhenti mengonversi di ujung masuk tidak boleh
     * berarti berhenti mengonversi sama sekali.
     */
    public function test_pembacaan_inch_tetap_dihitung_dalam_mm(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['satuan'] = 'inch';
        // Baris pertama varian B: nominal 25,0 mm. 1 inch = 25,4 mm, jadi
        // koreksinya 25,00027 − 25,4 = −0,39973 mm.
        $payload['measurements'] = [
            ['titik_ukur' => 25.0, 'pembacaan' => array_fill(0, 5, 1.0)],
        ];

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $hitung = CalibrationSession::findOrFail($id)
            ->uncertaintyCalculations()->where('titik_ke', 1)->firstOrFail();

        $this->assertEqualsWithDelta(25.4, (float) $hitung->rata_rata, self::TOLERANSI, 'rata-rata harus mm');
        $this->assertEqualsWithDelta(-0.39973, (float) $hitung->koreksi, self::TOLERANSI, 'koreksi harus mm');
    }

    /**
     * Baris yang urutannya bergeser DITOLAK, bukan disimpan di nominal salah.
     *
     * Server memetakan baris lewat POSISI — baris ke-N payload jadi titik ke-N
     * kertas. Itu benar selama HP mengirim kesebelas barisnya utuh dan
     * berurutan, dan hari ini memang begitu. Tapi jaminan itu hidup di repo
     * LAIN (`TitikState.siapKirim`), dan kalau suatu saat HP membuang baris
     * kosong, seluruh baris sesudahnya bergeser satu: pembacaan yang diambil
     * di 35,3 mm tersimpan sebagai titik 31,0 mm — koreksinya meleset ~4 mm,
     * dan tidak ada satu pun error di kedua sisi.
     *
     * `titik_ukur` kiriman HP dipakai sebagai pemeriksa. Dia tidak menentukan
     * nominalnya (varian tetap menang), cuma membuktikan bahwa baris yang
     * dikirim memang baris yang dimaksud.
     */
    public function test_baris_yang_bergeser_ditolak_bukan_disimpan_salah(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        // Baris kedua kertas 27,5 mm. HP mengirim 35,3 di posisi itu — persis
        // yang terjadi kalau dua baris kosong di antaranya dibuang.
        $payload = $this->payload($alat);
        $payload['measurements'][1]['titik_ukur'] = 35.3;

        // Teknisi melihat alasannya di pratinjau, SEBELUM sesinya terkirim.
        $alasan = implode(' ', array_column(
            $this->actingAs($teknisi)
                ->postJson('/api/calibrations/preview', $payload)
                ->assertSuccessful()
                ->json('data.belum_dihitung') ?? [],
            'alasan',
        ));

        $this->assertStringContainsString(
            'Urutan baris tidak cocok',
            $alasan,
            'Baris yang bergeser harus ditolak dengan alasan yang kebaca.',
        );

        // Dan kalau tetap dikirim, pembacaannya TIDAK mendarat di titik 27,5.
        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $this->assertSame(
            0,
            RawMeasurement::where('calibration_session_id', $id)
                ->where('titik_ke', 2)->count(),
            'Pembacaan baris yang bergeser tetap tersimpan — di nominal yang salah.',
        );
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
     * Sesi `inch`: yang TERSIMPAN angka mentah + satuannya, dan nominal balok
     * ukur tetap mm.
     *
     * Dua hal yang dijaga sekaligus:
     *
     *  1. **Penunjukan disimpan mentah** (1,0) dengan `satuan = inch`, bukan
     *     dikonversi lebih dulu. Itu yang bikin simpan berulang idempoten —
     *     lihat `test_simpan_draft_dua_kali_...`. Yang mengubahnya ke mm
     *     `MicrometerMentah::keMm()` di tempat pakai, dan itu diuji
     *     `test_pembacaan_inch_tetap_dihitung_dalam_mm`.
     *  2. **Nominal balok ukur TIDAK ikut satuan alat.** Sertifikat balok ukur
     *     selalu mm apa pun skala mikrometernya, jadi barisnya tetap
     *     ber-`satuan = mm` walau di titik yang sama penunjukannya inch.
     *     Ini yang membedakan kita dari master, yang mengalikan pembacaan 25,4
     *     di dalam rumus sementara kolom standarnya tetap mm — dan karena itu
     *     menerbitkan koreksi −61 mm pada balok ukur 2,5 mm.
     */
    public function test_satuan_inch_disimpan_mentah_dan_balok_tetap_mm(): void
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

        $bacaan = $baris->firstWhere('peran_sensor', MicrometerMentah::PERAN_PEMBACAAN);

        $this->assertEqualsWithDelta(
            1.0,
            (float) $bacaan->pembacaan,
            self::TOLERANSI,
            'pembacaan disimpan MENTAH — konversi terjadi di tempat pakai',
        );
        $this->assertSame('inch', $bacaan->satuan, 'baris menyebut satuannya sendiri');
        $balok = $baris->where('peran_sensor', MicrometerMentah::PERAN_BALOK)->sortBy('sensor_ke');

        $this->assertSame(
            [6.0, 19.0],
            $balok->pluck('pembacaan')->map('floatval')->values()->all(),
            'nominal balok ukur JANGAN ikut satuan alat — sertifikatnya selalu mm',
        );
        $this->assertSame(['mm'], $balok->pluck('satuan')->unique()->values()->all());

        // Baris Evaluasi juga tersimpan MENTAH — bentuk datar maupun bentuk
        // tabel diperlakukan sama, dan dua-duanya baru jadi mm di tempat pakai.
        $this->assertEqualsWithDelta(
            $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['pra_evaluasi'],
            array_map('floatval', CalibrationSession::findOrFail($id)
                ->spesifikasi_alat[MicrometerMentah::KUNCI_SESI]['pra_evaluasi']),
            self::TOLERANSI,
            'baris Evaluasi disimpan mentah, sama seperti pembacaan tiap titik',
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
