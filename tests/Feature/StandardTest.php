<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_daftar_standar_bentuknya_sesuai_yang_dibutuhin_dropdown_mobile(): void
    {
        Standard::factory()->create(['nama' => 'Gauge Block Set Grade 0']);

        $response = $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'Gauge Block Set Grade 0')
            ->assertJsonPath('data.0.masih_berlaku', true)
            ->assertJsonStructure(['data' => ['*' => [
                'id', 'nama', 'no_sertifikat', 'tertelusur_ke', 'berlaku_sampai',
                'masih_berlaku', 'ketidakpastian', 'satuan_ketidakpastian', 'faktor_cakupan',
            ]]]);

        // Angkanya dikirim sebagai number, bukan string — kontrak minta gitu biar
        // Dart nggak perlu parsing. Float bulat (2.0) kekirim jadi `2`, itu tetap
        // number yang sah, jadi bandinginnya jangan pakai tipe yang kaku.
        $this->assertIsNumeric($response->json('data.0.ketidakpastian'));
        $this->assertEqualsWithDelta(0.0004, $response->json('data.0.ketidakpastian'), 1e-9);
        $this->assertEqualsWithDelta(2, $response->json('data.0.faktor_cakupan'), 1e-9);
    }

    public function test_standar_kadaluarsa_tetap_muncul_tapi_ditandain(): void
    {
        Standard::factory()->kadaluarsa()->create(['nama' => 'Anak Timbangan F1']);

        // Sengaja nggak disembunyiin: kalau ilang dari daftar, teknisi ngira
        // datanya kehapus — padahal cuma perlu dikalibrasi ulang.
        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.masih_berlaku', false);
    }

    public function test_bisa_disaring_cuma_yang_masih_berlaku(): void
    {
        Standard::factory()->create(['nama' => 'Gauge Block Set Grade 0']);
        Standard::factory()->kadaluarsa()->create(['nama' => 'Anak Timbangan F1']);

        $this->actingAs($this->teknisi)
            ->getJson('/api/standards?berlaku_saja=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'Gauge Block Set Grade 0');
    }

    public function test_viewer_boleh_baca_standar(): void
    {
        Standard::factory()->create();
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)->getJson('/api/standards')->assertOk();
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->getJson('/api/standards')->assertUnauthorized();
    }

    public function test_standar_punya_pt_lain_nggak_kelihatan(): void
    {
        $ptLain = Organization::factory()->create();
        $standarPtLain = Standard::factory()->create(['organization_id' => $ptLain->id]);

        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->teknisi)
            ->getJson("/api/standards/{$standarPtLain->id}")
            ->assertNotFound();
    }

    public function test_admin_bikin_standar_baru(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/standards', [
                'nama' => 'Anak Timbangan Kelas F1',
                'merk' => 'Kern',
                'serial_number' => 'KRN-F1-001',
                'no_sertifikat' => 'SNSU/2026/M-0088',
                'tertelusur_ke' => 'SNSU-BSN',
                'berlaku_sampai' => now()->addYear()->toDateString(),
                'ketidakpastian' => 0.15,
                'satuan_ketidakpastian' => 'mg',
                'faktor_cakupan' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.nama', 'Anak Timbangan Kelas F1')
            ->assertJsonPath('data.masih_berlaku', true);

        // `organization_id` diambil dari organisasi tesnya, bukan dipatok `1`.
        // Di SQLite in-memory id-nya selalu 1; di MySQL AUTO_INCREMENT terus
        // maju lintas tes, jadi patokan itu ngecek organisasi yang salah.
        $this->assertDatabaseHas('standards', [
            'serial_number' => 'KRN-F1-001',
            'organization_id' => $this->admin->organization_id,
        ]);
    }

    public function test_teknisi_nggak_boleh_nambah_atau_ngubah_standar(): void
    {
        $standar = Standard::factory()->create();

        // Salah ngetik ketidakpastian di sini bikin SEMUA sertifikat yang pakai
        // standar ini ikut salah — makanya dikunci ke admin.
        $this->actingAs($this->teknisi)
            ->postJson('/api/standards', ['nama' => 'Standar Palsu'])
            ->assertForbidden();

        $this->actingAs($this->teknisi)
            ->putJson("/api/standards/{$standar->id}", ['ketidakpastian' => 0.00001])
            ->assertForbidden();

        $this->actingAs($this->teknisi)
            ->deleteJson("/api/standards/{$standar->id}")
            ->assertForbidden();
    }

    public function test_admin_bisa_ngisi_kurva_suhu_buffer_lewat_api(): void
    {
        // Sebelum ini `koefisien_suhu` nggak ada di aturan validasi, jadi
        // `$request->validated()` ngebuangnya diam-diam: form ngirim, admin lihat
        // sukses, kolomnya tetap null, dan lembar perhitungan diam-diam balik ke
        // nilai nominal. Test ini yang jagain itu nggak kejadian lagi.
        $this->actingAs($this->admin)
            ->postJson('/api/standards', [
                'nama' => 'pH Buffer Solution 4',
                'serial_number' => 'HC32513535',
                'koefisien_suhu' => ['a' => 3e-5, 'b' => -0.0023, 'c' => 4.0455],
            ])
            ->assertCreated()
            ->assertJsonPath('data.koefisien_suhu.c', 4.0455)
            ->assertJsonPath('data.punya_kurva_suhu', true);

        // Nilai buffer pH 4 di 22,2 °C — angka yang dipakai lembar olah data lab
        // (`PERHITUNGAN.csv`: Standard = 4,0092252), bukan nominal 4,00.
        $standar = Standard::where('serial_number', 'HC32513535')->firstOrFail();
        $this->assertEqualsWithDelta(4.0092252, $standar->nilaiPadaSuhu(22.2), 1e-9);
    }

    public function test_koefisien_suhu_setengah_terisi_ditolak(): void
    {
        // `nilaiPadaSuhu()` balik null kalau salah satu koefisiennya ilang —
        // separuh terisi sama aja kayak kosong, cuma bikin admin ngira udah beres.
        $this->actingAs($this->admin)
            ->postJson('/api/standards', [
                'nama' => 'Buffer Setengah Jadi',
                'koefisien_suhu' => ['a' => 3e-5],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['koefisien_suhu.b', 'koefisien_suhu.c']);
    }

    public function test_admin_bisa_ngisi_data_sertifikat_thermohygro(): void
    {
        // Angka TH-3 dari DATABASE.csv — yang bikin blok kondisi lingkungan di
        // lembar perhitungan keluar koreksi & U95%-nya, bukan pembacaan mentah.
        $this->actingAs($this->admin)
            ->postJson('/api/standards', [
                'nama' => 'TH-3',
                'serial_number' => 'TH-3',
                'parameter_kondisi' => [
                    'suhu' => ['indexed_value' => 19.83, 'correction' => -0.43, 'u95' => 1.7],
                    'kelembaban' => ['indexed_value' => 47.05, 'correction' => -2.55, 'u95' => 4.8],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.parameter_kondisi.suhu.correction', -0.43)
            ->assertJsonPath('data.parameter_kondisi.kelembaban.u95', 4.8);

        $standar = Standard::where('serial_number', 'TH-3')->firstOrFail();

        $this->assertSame(
            // `instrument` = angka yang kebaca di layar thermohygro. Null di
            // sini karena bentuk lama cuma nyimpen SATU titik tanpa kolom itu.
            // Standar yang titiknya lengkap (lihat `parameterKondisi`) pakai
            // kolom ini buat milih koreksi yang paling dekat sama pembacaan.
            ['indexed_value' => 19.83, 'instrument' => null, 'correction' => -0.43, 'u95' => 1.7],
            $standar->parameterKondisi('suhu'),
        );
    }

    public function test_u95_negatif_ditolak_tapi_koreksi_negatif_diterima(): void
    {
        // Koreksi thermohygro nyaris selalu negatif (lihat TH-1..TH-7) — itu sah.
        // Yang nggak masuk akal cuma ketidakpastian negatif.
        $this->actingAs($this->admin)
            ->postJson('/api/standards', [
                'nama' => 'TH Ngawur',
                'parameter_kondisi' => ['suhu' => ['correction' => -0.43, 'u95' => -1.7]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parameter_kondisi.suhu.u95');
    }

    public function test_faktor_cakupan_nol_ditolak_karena_dipakai_sebagai_pembagi(): void
    {
        // k dipakai buat bagi balik ketidakpastian diperluas jadi baku. Nol bikin
        // pembagian nol; di bawah 1 nggak ada artinya secara metrologi.
        $this->actingAs($this->admin)
            ->postJson('/api/standards', ['nama' => 'Standar Ngawur', 'faktor_cakupan' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('faktor_cakupan');
    }

    public function test_standar_yang_dihapus_tetap_kebaca_di_sesi_kalibrasi_lama(): void
    {
        $standar = Standard::factory()->create(['nama' => 'Gauge Block Set Grade 0']);
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->deleteJson("/api/standards/{$standar->id}")
            ->assertOk();

        // Ilang dari dropdown...
        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // ...tapi sesi lama TETAP nunjukin standarnya. Kalau ini jadi null,
        // ketertelusuran sertifikatnya ilang — persis yang dicari asesor pas audit.
        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.standar_acuan.nama', 'Gauge Block Set Grade 0');
    }

    public function test_ngubah_standar_nggak_ngubah_hasil_sesi_yang_udah_kehitung(): void
    {
        $standar = Standard::factory()->create();
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $uSebelum = $sesi->uncertaintyCalculations()->value('ketidakpastian_diperluas');

        // Standarnya dikalibrasi ulang, ketidakpastiannya berubah drastis.
        $this->actingAs($this->admin)
            ->putJson("/api/standards/{$standar->id}", ['ketidakpastian' => 0.05])
            ->assertOk();

        // Sesi lama TETAP pakai angka waktu dia dihitung. Sertifikat yang udah
        // dipegang pelanggan nggak boleh berubah diam-diam.
        $this->assertEqualsWithDelta(
            $uSebelum,
            $sesi->fresh()->uncertaintyCalculations()->value('ketidakpastian_diperluas'),
            1e-9,
        );
    }
}
