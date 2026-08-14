<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Standard;
use App\Models\User;
use App\Services\LembarKerjaTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lembar Kerja pH Meter (SIDIK-FM-CAL-0509_Rev.4).
 *
 * Inti yang diuji: teknisi di lapangan NGGAK PERNAH keblokir tombol kirim,
 * berapa pun kolom yang belum keisi — tapi lembar setengah jadi juga nggak
 * pernah lolos jadi sertifikat.
 */
class LembarKerjaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $buffer4;

    private Standard $rtd;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();

        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'nama_alat' => 'pH Meter',
            'range_min' => 0, 'range_max' => 14,
            'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.2,
        ]);

        $this->buffer4 = Standard::factory()->create(['nama' => 'pH Buffer Solutions 4']);
        $this->rtd = Standard::factory()->create(['nama' => 'RTD Sensor/SH1/20']);
    }

    public function test_bentuk_lembar_kerja_bisa_diambil_dan_nggak_ada_kolom_wajib(): void
    {
        $data = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja')
            ->assertOk()
            ->assertJsonPath('data.kode_dokumen', LembarKerjaTemplate::KODE_DOKUMEN)
            ->assertJsonPath('data.jumlah_pengulangan', 5)
            ->assertJsonPath('data.semua_kolom_opsional', true)
            ->json('data');

        // Baris tabel hasilnya persis larutan standar di kertas: 4,00 / 7,00 / 10,01.
        $this->assertEqualsWithDelta([4.0, 7.0, 10.01], $data['larutan_standar'], 1e-9);

        // Nggak boleh ada satu pun field yang ditandai wajib.
        foreach ($data['bagian'] as $bagian) {
            foreach ($bagian['field'] ?? [] as $field) {
                $this->assertFalse($field['wajib'], "Field {$field['kode']} nggak boleh wajib.");
            }
        }

        // Dua tabel: sebelum & sesudah adjustment.
        $tabel = collect($data['bagian'])->firstWhere('kode', 'hasil')['tabel'];
        $this->assertSame(['sebelum_adjustment', 'sesudah_adjustment'], array_column($tabel, 'tahap'));

        // Bentuk kertas buat pindai foto: lembar pH punya kolom suhu, dan
        // standarnya jadi KOLOM. Dikirim eksplisit — mobile nerusinnya apa
        // adanya ke endpoint ekstraksi, jadi dia nggak perlu nyimpen daftar
        // alat mana yang kertasnya beda.
        $this->assertSame(['kolom_suhu' => true, 'standar_di_baris' => false], $data['pindai_foto']);
    }

    /**
     * Tiap baris tabel hasil bawa standar PASANGANNYA, bukan dikosongin buat
     * dipilih teknisi satu-satu dari seluruh master.
     *
     * Ini yang nahan `-2,99`. Sesi pH 7 Agt 2026 kecetak Correction
     * `0,01 / -2,99 / -0,13`: titik 7,00 kepilih Buffer 4, jadi nilai standar
     * yang dipakai 4,0092 dan koreksinya jadi 4,0092 − 7,00. Dua tetangganya
     * wajar, jadi yang di tengah nggak keliatan salah sampai nyampe pelanggan.
     *
     * Yang diuji PASANGANNYA, bukan cuma "ada isinya" — tertukar itu justru
     * mode gagal yang bikin sertifikatnya salah.
     */
    public function test_tiap_titik_bawa_larutan_standar_pasangannya(): void
    {
        $buffer = collect(['4', '7', '10'])->mapWithKeys(
            fn (string $n): array => [$n => Standard::factory()->create(['nama' => "pH Buffer Solution {$n}"])->id],
        );

        $data = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja')
            ->assertOk()
            ->json('data');

        foreach (collect($data['bagian'])->firstWhere('kode', 'hasil')['tabel'] as $tabel) {
            $this->assertSame(
                [$buffer['4'], $buffer['7'], $buffer['10']],
                array_column($tabel['baris'], 'standard_id'),
                "Tabel {$tabel['tahap']}: titik ketuker sama buffer yang salah.",
            );

            $this->assertSame(
                ['pH Buffer Solution 4', 'pH Buffer Solution 7', 'pH Buffer Solution 10'],
                array_column($tabel['baris'], 'standard_nama'),
            );
        }
    }

    /**
     * Standar yang belum keseed di master bikin titik itu doang jatuh ke
     * pilihan manual — bukan bikin barisnya hilang, dan bukan bikin titik lain
     * ikut kosong.
     */
    public function test_titik_tanpa_standar_di_master_tetap_tampil_dengan_id_null(): void
    {
        Standard::factory()->create(['nama' => 'pH Buffer Solution 7']);

        $data = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja')
            ->assertOk()
            ->json('data');

        $baris = collect($data['bagian'])->firstWhere('kode', 'hasil')['tabel'][0]['baris'];

        $this->assertCount(3, $baris);
        $this->assertNull($baris[0]['standard_id']);
        $this->assertNotNull($baris[1]['standard_id']);
        $this->assertNull($baris[2]['standard_id']);
    }

    public function test_lembar_kerja_turbidimeter_pakai_ntu_tiga_titik(): void
    {
        $data = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=turbidimeter')
            ->assertOk()
            ->assertJsonPath('data.satuan', 'NTU')
            // Kode dokumen form turbidimeter (DATABASE row), bukan pH (0509).
            ->assertJsonPath('data.kode_dokumen', 'SIDIK-FM-CAL-0530_Rev.2')
            ->json('data');

        // Tiga titik turbidity yang lab beneran punya standarnya (1/100/1000),
        // dengan angka akreditasi asli. Form nyetak 5 kolom tapi standar 15 &
        // 750 NTU belum ada — lihat TurbidimeterProfile.
        $this->assertEqualsWithDelta([1.0, 100.0, 1000.0], $data['larutan_standar'], 1e-9);
        $this->assertStringContainsString('Turbidimeter', $data['judul']);

        // Resolusi per titik ikut di baris tabel hasil (0.01/0.1/1 → 2/1/0
        // desimal) — ini yang bikin layar nampilin "4.60" vs "999".
        $tabel = collect($data['bagian'])->firstWhere('kode', 'hasil')['tabel'][0];
        $this->assertSame([2, 1, 0], array_column($tabel['baris'], 'desimal'));
        $this->assertEqualsWithDelta([0.01, 0.1, 1.0], array_column($tabel['baris'], 'resolusi'), 1e-9);
        // Label titik: 1000 harus utuh "1000", bukan kestrip jadi "1".
        $this->assertSame(['1', '100', '1000'], array_column($tabel['baris'], 'label'));
    }

    public function test_lembar_kerja_refractometer_pakai_n20d_dua_titik(): void
    {
        $data = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=refractometer')
            ->assertOk()
            ->assertJsonPath('data.satuan', 'n20D')
            ->assertJsonPath('data.kode_dokumen', 'SIDIK-FM-CAL-0523_Rev.2')
            ->json('data');

        // Dua titik n20D yang ada di lingkup akreditasi. Nilainya nominal
        // larutan aslinya (1,33659 & 1,39986), bukan versi bulat 1,3366/1,3999
        // yang ketulis di tabel CMC.
        $this->assertEqualsWithDelta([1.33659, 1.39986], $data['larutan_standar'], 1e-9);
        $this->assertStringContainsString('Refractometer', $data['judul']);

        // Alat ini bisa dua satuan dan itu ngubah SEMUA angka hilirnya, jadi
        // pilihannya wajib ikut kekirim — layar input nanya di depan, nggak nebak.
        $this->assertSame(
            ['n20D', '°Brix'],
            array_column($data['pilihan_satuan'], 'nilai'),
        );

        // Tiap sel punya DUA kolom: pembacaan + suhu larutan. Kolom suhunya
        // bukan pelengkap — dia yang dipakai normalisasi ke 20 °C. Kalau kolom
        // ini hilang dari lembar kerja, Correction di sertifikat meleset.
        $tabel = collect($data['bagian'])->firstWhere('kode', 'hasil')['tabel'];
        $this->assertSame(
            ['sebelum_adjustment', 'sesudah_adjustment'],
            array_column($tabel, 'tahap'),
        );
        $this->assertSame(['pembacaan', 'suhu'], array_column($tabel[0]['kolom'], 'kode'));

        // Resolusi seragam 0,0001 → `desimal`/`resolusi` per baris sengaja
        // NGGAK dikirim sama sekali (bukan dikirim null), sama kayak Chlorine.
        // Di sisi mobile "nggak ada" itu artinya "seragam, pakai resolusi alat".
        // Penting buat refractometer: nilai terkoreksinya bisa 5 desimal
        // (1,33935), jadi mad per baris ke 4 desimal justru bakal salah.
        //
        // Dicek sebagai KETIDAKHADIRAN dua kunci itu, bukan sebagai daftar
        // kunci yang persis: baris tabel emang nambah kunci dari waktu ke waktu
        // (`standard_id` pasangan titik, misalnya), dan tes yang mematok daftar
        // penuh bakal merah tiap kali itu terjadi tanpa ada yang rusak.
        $this->assertArrayNotHasKey('desimal', $tabel[0]['baris'][0]);
        $this->assertArrayNotHasKey('resolusi', $tabel[0]['baris'][0]);

        // Titik standarnya ikut SATUAN, bukan cuma koefisien suhunya. Larutan
        // fisiknya sama — BSAG2.5-0034 dibaca 2,5 °Brix ATAU 1,33659 n20D —
        // tapi angka yang ditulis di lembar kerja beda, dan `titik_ukur` yang
        // kekirim ikut beda. Sebelum ini sesi °Brix ngirim titik n20D bareng
        // satuan °Brix: nilai standar satu skala, pembacaan skala lain.
        //
        // Dua set dikirim sekaligus karena satuannya dipilih DI DALAM formulir,
        // sesudah lembar kerjanya diambil — lihat docblock `tabelHasil`.
        $this->assertEqualsWithDelta(
            [1.33659, 1.39986],
            array_column($tabel[0]['baris_per_satuan']['n20D'], 'titik_ukur'),
            1e-9,
        );
        $this->assertEqualsWithDelta(
            [2.5, 40.0],
            array_column($tabel[0]['baris_per_satuan']['°Brix'], 'titik_ukur'),
            1e-9,
        );

        // Dua titik °Brix itu WAJIB sama dengan yang diseed CMC-nya
        // (`RefractometerCapabilitySeeder`), kalau nggak titiknya kehilangan
        // budget ketidakpastian tanpa satu pun peringatan.
        $this->assertSame(
            ['2,5', '40'],
            array_column($tabel[0]['baris_per_satuan']['°Brix'], 'label'),
        );

        // `baris` lama tetap n20D — app versi lama nggak boleh ikut berubah.
        $this->assertEqualsWithDelta(
            [1.33659, 1.39986],
            array_column($tabel[0]['baris'], 'titik_ukur'),
            1e-9,
        );
    }

    public function test_lembar_kerja_default_tetap_ph_kalau_tanpa_param(): void
    {
        // Mobile lama yang belum ngirim ?profil harus tetap dapat pH persis.
        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja')
            ->assertOk()
            ->assertJsonPath('data.satuan', 'pH');
    }

    /**
     * Jumlah KOTAK pengulangan bisa diatur — 5 cuma bawaan, bukan patokan.
     *
     * Teknisi kadang cuma perlu 3 (sampel terbatas, atau alatnya stabil banget).
     * Sebelum ini kolomnya selalu 5 dan dua kolom terakhir cuma jadi ruang
     * kosong yang bikin ragu: "ini wajib diisi apa nggak?"
     *
     * Yang berubah CUMA gambarnya. Rumusnya tetap ngikut berapa yang beneran
     * diisi — dijaga `PengulanganBebasTest`.
     */
    public function test_jumlah_kolom_pengulangan_bisa_diatur_di_ketiga_alat(): void
    {
        foreach (['ph_meter', 'turbidimeter', 'chlorine_meter', 'refractometer'] as $profil) {
            $data = $this->actingAs($this->teknisi)
                ->getJson("/api/calibrations/lembar-kerja?profil={$profil}&pengulangan=3")
                ->assertOk()
                ->assertJsonPath('data.jumlah_pengulangan', 3)
                ->json('data');

            foreach ($data['bagian'] as $bagian) {
                foreach ($bagian['tabel'] ?? [] as $tabel) {
                    $this->assertSame(
                        [1, 2, 3],
                        $tabel['pengulangan'],
                        "{$profil}: kotak pengulangan di tabel harus ikut, bukan cuma angkanya di header",
                    );
                }
            }
        }
    }

    public function test_tanpa_parameter_tetap_lima_kolom_kayak_form_kertas(): void
    {
        // Mobile lama yang belum ngirim `pengulangan` nggak boleh ikut berubah.
        foreach (['ph_meter', 'turbidimeter', 'chlorine_meter', 'refractometer'] as $profil) {
            $this->actingAs($this->teknisi)
                ->getJson("/api/calibrations/lembar-kerja?profil={$profil}")
                ->assertOk()
                ->assertJsonPath('data.jumlah_pengulangan', 5);
        }
    }

    public function test_kolom_pengulangan_di_luar_batas_ditolak(): void
    {
        // 1 kolom = standar deviasi nggak ada. Ditolak di sini, bukan dibiarin
        // sampai teknisi selesai ngisi lalu titiknya ilang dari hasil.
        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?pengulangan=1')
            ->assertJsonValidationErrors('pengulangan');

        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?pengulangan=99')
            ->assertJsonValidationErrors('pengulangan');
    }

    public function test_lembar_kerja_setengah_jadi_tetap_bisa_dikirim(): void
    {
        // Skenario lapangan: buffer 7 & 10 habis, jadi cuma titik pertama yang
        // kelar. Env condition cuma keukur di awal. Thermohygro nggak ada.
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->alat->id,
                'standard_id' => $this->buffer4->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'suhu_awal' => 24.5,
                'kelembaban_awal' => 55,
                'catatan_teknisi' => 'Buffer 7 & 10 habis, dilanjut besok.',
                'measurements' => [
                    [
                        'titik_ukur' => 4.00,
                        'pembacaan' => [4.01, 4.00, 4.01, null, null],
                        'suhu' => [24.5, 24.6, 24.5, null, null],
                    ],
                    // Baris kosong — kolomnya kecetak di kertas tapi belum diisi.
                    ['titik_ukur' => 7.00, 'pembacaan' => [null, null, null, null, null]],
                    ['titik_ukur' => 10.01, 'pembacaan' => []],
                ],
            ])
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        // Yang keisi kesimpen, yang kosong nggak bikin baris hantu.
        $this->assertSame(3, $sesi->rawMeasurements()->count());
        $this->assertSame(1, $sesi->uncertaintyCalculations()->count());
        $this->assertSame('Buffer 7 & 10 habis, dilanjut besok.', $sesi->catatan_teknisi);

        // Suhu larutan per pembacaan ikut kecatat.
        $this->assertEqualsWithDelta(
            24.5,
            (float) $sesi->rawMeasurements()->where('pembacaan_ke', 1)->value('suhu'),
            1e-9,
        );

        // Dan ini yang paling gampang kelewat: ANGKANYA harus ikut 3 pembacaan,
        // bukan 5. Lembar kerjanya nyetak 5 kolom, jadi gampang dikira angka 5
        // itu ikut masuk rumus.
        //
        // Kalau rata-ratanya dibagi 5 (dua kolom kosong kehitung sebagai nol),
        // hasilnya 2,404 — bukan 4,006667. Nggak ada error yang muncul; yang
        // salah cuma sertifikatnya.
        $titik = $sesi->uncertaintyCalculations()->where('titik_ke', 1)->firstOrFail();

        $this->assertSame(3, $titik->jumlah_pengulangan);
        // Delta 1e-7: kolomnya `decimal(20,8)`, jadi yang kesimpen udah
        // dibulatkan ke 8 desimal.
        $this->assertEqualsWithDelta((4.01 + 4.00 + 4.01) / 3, (float) $titik->rata_rata, 1e-7);

        // s = √(((4,01−4,00667)² + (4,00−4,00667)² + (4,01−4,00667)²) / 2)
        $rata = (4.01 + 4.00 + 4.01) / 3;
        $s = sqrt(((4.01 - $rata) ** 2 + (4.00 - $rata) ** 2 + (4.01 - $rata) ** 2) / 2);

        $this->assertEqualsWithDelta($s, (float) $titik->standar_deviasi, 1e-7);
        $this->assertEqualsWithDelta($s / sqrt(3), (float) $titik->type_a, 1e-7, 'Type A harus √3, bukan √5');
    }

    public function test_kondisi_ruang_yang_cuma_keukur_sebagian_tetap_kepakai(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->alat->id,
                'standard_id' => $this->buffer4->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'suhu_awal' => 24.0,
                'suhu_akhir' => 25.0,
                'kelembaban_awal' => 50,
                'measurements' => [['titik_ukur' => 4.00, 'pembacaan' => [4.01, 4.00, 4.01]]],
            ])
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        // Yang dicetak di sertifikat = rata-rata awal & akhir.
        $this->assertEqualsWithDelta(24.5, $sesi->suhu_ruang, 1e-9);
        // Kelembaban cuma keukur sekali — dipakai apa adanya, bukan dibagi dua.
        $this->assertEqualsWithDelta(50.0, $sesi->kelembaban, 1e-9);
    }

    public function test_usage_check_standar_kecatat_termasuk_yang_nggak_dipakai(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->alat->id,
                'standard_id' => $this->buffer4->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'standar_dicek' => [
                    ['standard_id' => $this->buffer4->id, 'dipakai' => true],
                    ['standard_id' => $this->rtd->id, 'dipakai' => false, 'keterangan' => 'Sensor dibawa tim lain'],
                ],
                'measurements' => [['titik_ukur' => 4.00, 'pembacaan' => [4.01, 4.00, 4.01]]],
            ])
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        // Yang nggak dicentang tetap disimpen — "diperiksa lalu nggak dipakai"
        // itu informasi, beda dari "nggak pernah ditanya".
        $this->assertSame(2, $sesi->standarDicek()->count());
        $this->assertFalse((bool) $sesi->standarDicek()->where('standards.id', $this->rtd->id)->first()->pivot->dipakai);
    }

    public function test_standar_yang_dicentang_ikut_ke_tabel_standard_used_di_sertifikat(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->alat->id,
                'standard_id' => $this->buffer4->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'standar_dicek' => [
                    // RTD cuma buat baca suhu larutan — dia nggak nempel ke titik
                    // hitung mana pun, tapi tetap standar yang dipakai.
                    ['standard_id' => $this->rtd->id, 'dipakai' => true],
                ],
                'measurements' => [['titik_ukur' => 4.00, 'pembacaan' => [4.01, 4.00, 4.01]]],
            ])
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $this->actingAs($this->admin)->postJson("/api/calibrations/{$sesi->id}/approve")->assertOk();

        $nama = array_column($sesi->fresh()->certificate->snapshot['standar_digunakan'], 'name');

        $this->assertContains('RTD Sensor/SH1/20', $nama);
        $this->assertContains('pH Buffer Solutions 4', $nama);
    }

    public function test_titik_yang_nggak_kehitung_diperingatkan_ke_admin_sebelum_terbit(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->alat->id,
                'standard_id' => $this->buffer4->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'measurements' => [
                    ['titik_ukur' => 4.00, 'pembacaan' => [4.01, 4.00, 4.01]],
                    // Cuma satu pembacaan — nggak bisa dihitung Type A-nya.
                    ['titik_ukur' => 7.00, 'pembacaan' => [7.02]],
                ],
            ])
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $temuan = $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->json('data.temuan');

        $this->assertContains('titik_tidak_terhitung', array_column($temuan, 'kode'));

        // Peringatan, bukan error: admin boleh lanjut kalau memang disadari.
        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('butuh_konfirmasi', true);

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();
    }

    public function test_draft_boleh_disimpen_walau_tanggal_dan_pengukurannya_belum_ada(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->alat->id,
                'status' => CalibrationSession::STATUS_DRAFT,
            ])
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->assertSame(CalibrationSession::STATUS_DRAFT, $sesi->status);
        $this->assertNull($sesi->keputusan);
        $this->assertSame(0, $sesi->rawMeasurements()->count());
    }

    public function test_nerusin_draft_tanpa_ngirim_ulang_pengukuran_nggak_ngehapus_yang_udah_kecatat(): void
    {
        $ruangan = Room::factory()->create(['nama' => 'Lab. Uji A']);

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->alat->id,
                'standard_id' => $this->buffer4->id,
                'status' => CalibrationSession::STATUS_DRAFT,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'measurements' => [['titik_ukur' => 4.00, 'pembacaan' => [4.01, 4.00, 4.01]]],
            ])
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        // Balik lagi cuma buat ngelengkapin bagian header — payloadnya nggak
        // bawa `measurements` sama sekali.
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$sesi->id}", [
                'equipment_id' => $this->alat->id,
                'standard_id' => $this->buffer4->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'room_id' => $ruangan->id,
                'suhu_awal' => 24.0,
            ])
            ->assertOk();

        $sesi->refresh();

        $this->assertSame(3, $sesi->rawMeasurements()->count());
        $this->assertSame(1, $sesi->uncertaintyCalculations()->count());
        $this->assertSame($ruangan->id, $sesi->room_id);
    }

    public function test_satuan_ngikut_alat_kalau_teknisi_nggak_ngisi(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->alat->id,
                'standard_id' => $this->buffer4->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'measurements' => [['titik_ukur' => 4.00, 'pembacaan' => [4.01, 4.00, 4.01]]],
            ])
            ->assertCreated();

        $this->assertSame(
            'pH',
            CalibrationSession::latest('id')->firstOrFail()->rawMeasurements()->value('satuan'),
        );
    }

    /**
     * Refractometer yang dipindah teknisi ke skala °Brix.
     *
     * Satu alat fisik bisa nampilin n20D atau °Brix, dan pilihannya nentuin
     * koefisien normalisasi suhu — 0,00045/°C vs 0,07/°C, beda 155 kali.
     * `RefractometerProfile::satuan()` bacanya `equipments.satuan`, jadi
     * pilihan teknisi wajib nyampe ke sana; kalau cuma nempel di pembacaan,
     * angkanya kelabel °Brix tapi dikoreksi pakai koefisien n20D dan nggak ada
     * satu pun yang error.
     */
    private function alatRefracto(string $satuan = 'n20D'): Equipment
    {
        return Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(
                ['kode' => 'instrumen-analitik'],
            )->id,
            'nama_alat' => 'Refractometer',
            'range_min' => 0, 'range_max' => 1.7,
            // Toleransi wajib ada, bukan hiasan: tanpa itu titiknya nggak
            // dihitung sama sekali ("PASS/FAIL nggak ada dasarnya").
            'satuan' => $satuan, 'resolusi' => 0.0001, 'toleransi' => 0.01,
        ]);
    }

    /** @return array<string, mixed> */
    private function payloadRefracto(Equipment $alat, ?string $satuan): array
    {
        // Titik tanpa standar acuan sengaja NGGAK dihitung ketidakpastiannya
        // (lihat CalibrationRequest), dan test ini butuh angkanya keluar.
        $larutan = Standard::factory()->create([
            'nama' => 'Refractometer Std Solution 1.33659 n20D',
        ]);

        return array_filter([
            'equipment_id' => $alat->id,
            'equipment_satuan' => $satuan,
            'standard_id' => $larutan->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'suhu_awal' => 20.9,
            'suhu_akhir' => 21.2,
            'measurements' => [[
                'titik_ukur' => 1.33659,
                'pembacaan' => [1.3362, 1.3362, 1.3362],
                'suhu' => [25, 25, 25],
            ]],
        ], fn ($v): bool => $v !== null);
    }

    public function test_satuan_pilihan_teknisi_kesimpen_ke_master_alat(): void
    {
        $alat = $this->alatRefracto('n20D');

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadRefracto($alat, '°Brix'))
            ->assertCreated();

        $this->assertSame('°Brix', $alat->fresh()->satuan);

        // Dan yang penting: hitungannya IKUT satuan baru, bukan cuma labelnya.
        // 1,3362 pada 25 °C → 1,3362 + 0,07 × 5 = 1,6862. Kalau koefisiennya
        // masih n20D hasilnya 1,33845 — selisih yang nggak mungkin kelewat.
        $titik = CalibrationSession::latest('id')->firstOrFail()
            ->uncertaintyCalculations()->firstOrFail();

        $this->assertEqualsWithDelta(1.6862, (float) $titik->rata_rata, 1e-6);

        // Ini satu-satunya jalur di lembar kerja yang nulis ke DATA MASTER, jadi
        // jejaknya wajib ada. Trait `Diaudit` di Equipment yang ngerjain, dan
        // test ini yang njaga dia nggak ilang diam-diam: perubahan master yang
        // nggak kecatat baru ketahuan waktu asesmen, waktu udah nggak bisa
        // direkonstruksi siapa yang ngubah dan kapan.
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'equipments',
            'entity_id' => $alat->id,
            'action' => 'diubah',
            'changed_by' => $this->teknisi->id,
        ]);
    }

    public function test_lembar_tanpa_kolom_satuan_nggak_ngutak_atik_master_alat(): void
    {
        // Mobile cuma ngirim `equipment_satuan` buat lembar yang punya kolomnya.
        // Sesi pH/Turbidimeter/Chlorine nggak ngirim apa-apa, dan satuan alatnya
        // wajib tetap kayak semula — kiriman lembar kerja bukan tempat data
        // master keubah diam-diam.
        $alat = $this->alatRefracto('n20D');

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadRefracto($alat, null))
            ->assertCreated();

        $this->assertSame('n20D', $alat->fresh()->satuan);
    }

    public function test_satuan_kosong_nggak_ngehapus_satuan_alat(): void
    {
        // Lembar kerja boleh dikirim setengah jadi dari lapangan. Kolom yang
        // dilewat artinya "belum diisi", BUKAN "kosongin satuan alatnya".
        $alat = $this->alatRefracto('°Brix');

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadRefracto($alat, ''))
            ->assertCreated();

        $this->assertSame('°Brix', $alat->fresh()->satuan);
    }

    public function test_preview_ikut_satuan_pilihan_tapi_nggak_nyimpen(): void
    {
        // Preview & sesi tersimpan wajib ngeluarin angka yang sama persis —
        // teknisi ngintip hasil sebelum kirim, dan dua angka beda buat satu
        // pengukuran itu temuan audit. Tapi preview tetap NGGAK boleh ninggalin
        // jejak: dia dipanggil tiap selesai satu baris.
        $alat = $this->alatRefracto('n20D');

        $preview = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $this->payloadRefracto($alat, '°Brix'))
            ->assertOk();

        $this->assertEqualsWithDelta(
            1.6862,
            (float) $preview->json('data.titik.0.rata_rata'),
            1e-6,
        );

        $this->assertSame('n20D', $alat->fresh()->satuan, 'Preview nggak boleh nyentuh DB.');
        $this->assertSame(0, CalibrationSession::count());
    }

    /**
     * Baris cadangan per satuan ikut bawa standar pasangannya.
     *
     * Refractometer ngirim `baris_per_satuan` supaya mobile bisa nuker titik
     * waktu teknisi milih °Brix — tanpa narik ulang formulir & ngosongin isian.
     * Sebelum 11 Agt 2026 yang distempel standar cuma `baris` bawaan, jadi
     * begitu pindah ke °Brix titiknya bener (2,5 & 40) tapi standarnya KOSONG,
     * dan teknisi balik milih manual dari seluruh master — lubang yang persis
     * bikin sesi pH 7 Agt kecetak Correction `-2,99`.
     */
    public function test_baris_per_satuan_ikut_bawa_standar_pasangannya(): void
    {
        // Master standarnya disiapin dulu — pemasangan titik→standar cuma bisa
        // kejadian kalau barisnya ADA di `standards`. Tanpa ini yang keuji
        // cuma "field-nya null", bukan pemasangannya.
        foreach ([
            'Refractometer Std Solution 1.33659 n20D',
            'Refractometer Std Solution 1.39986 n20D',
            'Refractometer Std Solution 2.5 oBrix',
            'Refractometer Std Solution 40 oBrix',
        ] as $nama) {
            Standard::factory()->create(['nama' => $nama, 'parameter_kondisi' => null]);
        }

        $data = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=refractometer')
            ->assertOk()
            ->json('data');

        $perSatuan = null;

        foreach ($data['bagian'] as $bagian) {
            foreach ($bagian['tabel'] ?? [] as $tabel) {
                if (! empty($tabel['baris_per_satuan'])) {
                    $perSatuan = $tabel['baris_per_satuan'];
                    break 2;
                }
            }
        }

        $this->assertNotNull($perSatuan, 'Refractometer mesti ngirim baris_per_satuan');
        $this->assertArrayHasKey('°Brix', $perSatuan);

        $this->assertSame(
            [2.5, 40.0],
            array_map(fn (array $b): float => (float) $b['titik_ukur'], $perSatuan['°Brix']),
        );

        foreach ($perSatuan as $satuan => $baris) {
            foreach ($baris as $b) {
                $this->assertNotNull(
                    $b['standard_nama'] ?? null,
                    "baris {$satuan} titik {$b['titik_ukur']} nggak bawa standar pasangannya",
                );
            }
        }
    }
}
