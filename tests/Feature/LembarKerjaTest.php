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
        $this->assertSame(
            ['titik_ukur', 'label'],
            array_keys($tabel[0]['baris'][0]),
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
}
