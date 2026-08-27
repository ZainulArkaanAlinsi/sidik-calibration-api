<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Folder;
use App\Models\FolderFile;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bentuk `GET /api/arsip/folders/{id}` — dua hal yang layar Arsip di mobile
 * NGGAK BISA turunkan sendiri, jadi wajib ada di respons.
 *
 * ## Kenapa berkas ini ada
 *
 * Sampai 27 Agt 2026 respons ini dan parser di mobile bicara dua bahasa yang
 * beda: server ngirim `{data: {…, sub_folder[], file[]}}`, mobile baca
 * `{folder, subfolder, data[]}`. `json['data']` yang sebenarnya OBJEK bikin
 * `as List` di sana ngelempar — **tiap folder yang dibuka lawan server asli
 * gagal**, dan layarnya berhenti di pesan error. Nol test nangkep itu, di kedua
 * repo.
 *
 * Sisi mobile sekarang ngikut penamaan di sini. Yang NGGAK bisa dia ikuti cuma
 * dua, dan dua-duanya dijaga di bawah:
 *
 * | | Kenapa nggak bisa diturunkan di HP |
 * |---|---|
 * | `breadcrumb` | Yang dipegang layar cuma folder yang lagi kebuka. `parent_id` doang nggak cukup — NAMA induknya ada di baris induk yang nggak ikut terkirim |
 * | `lembar_kerja.equipment/teknisi/tanggal_kalibrasi/keputusan` | Baris `folder_files` cuma punya nama berkas & penunjuk sesi. Tanpa keempatnya kartu arsip berhenti menjawab "alat mana, lulus apa nggak" |
 */
class BentukIsiFolderArsipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Folder $akar;

    private Folder $tahun;

    private CalibrationSession $sesi;

    private FolderFile $berkas;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['id' => 1]);
        $this->admin = User::factory()->admin()->create([
            'organization_id' => 1,
            'name' => 'Budi Teknisi',
        ]);

        $pelanggan = Customer::factory()->create([
            'organization_id' => 1,
            'nama' => 'PT Alfa',
            'alamat' => 'Jl. Raya Cikarang KM 27, Bekasi',
        ]);

        $this->akar = Folder::query()->create([
            'organization_id' => 1, 'customer_id' => $pelanggan->id,
            'nama' => 'PT Alfa', 'tipe' => 'sistem', 'parent_id' => null,
        ]);
        $this->tahun = Folder::query()->create([
            'organization_id' => 1, 'customer_id' => $pelanggan->id,
            'nama' => '2026', 'tipe' => 'sistem', 'parent_id' => $this->akar->id,
        ]);

        $standar = Standard::factory()->create(['organization_id' => 1]);

        // Sesi boneka dulu, biar id sesi NGGAK sama dengan id folder_files.
        // Waktu dua-duanya kebetulan sama, jalur yang ngirim id berkas ke rute
        // yang minta id sesi kelihatan jalan mulus.
        $lain = Equipment::factory()->create(['organization_id' => 1, 'customer_id' => $pelanggan->id]);
        foreach (range(1, 4) as $ignored) {
            CalibrationSession::factory()->create([
                'organization_id' => 1, 'equipment_id' => $lain->id,
                'teknisi_id' => $this->admin->id, 'standard_id' => $standar->id,
            ]);
        }

        $alat = Equipment::factory()->create([
            'organization_id' => 1, 'customer_id' => $pelanggan->id,
            'nama_alat' => 'Thermocouple Fluke 51-II',
        ]);
        $this->sesi = CalibrationSession::factory()->create([
            'organization_id' => 1, 'equipment_id' => $alat->id,
            'teknisi_id' => $this->admin->id, 'standard_id' => $standar->id,
        ]);

        $this->berkas = FolderFile::query()->create([
            'organization_id' => 1, 'folder_id' => $this->tahun->id,
            'calibration_session_id' => $this->sesi->id,
            'nama' => 'Lembar kerja', 'sumber' => FolderFile::SUMBER_LEMBAR_KERJA,
            'uploaded_by' => $this->admin->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function buka(Folder $folder): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/arsip/folders/'.$folder->id)
            ->assertOk()
            ->json('data');
    }

    public function test_breadcrumb_dari_akar_ke_folder_yang_dibuka(): void
    {
        $data = $this->buka($this->tahun);

        $this->assertSame(
            [
                ['id' => $this->akar->id, 'nama' => 'PT Alfa', 'is_root' => true],
                ['id' => $this->tahun->id, 'nama' => '2026', 'is_root' => false],
            ],
            $data['breadcrumb'],
        );
    }

    public function test_folder_akar_breadcrumbnya_dirinya_sendiri(): void
    {
        $data = $this->buka($this->akar);

        $this->assertCount(1, $data['breadcrumb']);
        $this->assertTrue($data['breadcrumb'][0]['is_root']);
    }

    public function test_daftar_akar_nggak_ikut_bawa_breadcrumb(): void
    {
        // Bukan kelupaan. Daftar akar bisa berisi ratusan PT, dan tiap baris
        // bakal manjat `parent` sendiri buat jalur yang selalu satu langkah.
        $baris = $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('breadcrumb', $baris);
    }

    public function test_tiap_berkas_bawa_empat_field_kartu_arsip(): void
    {
        $lembar = $this->buka($this->tahun)['file'][0]['lembar_kerja'];

        $this->assertSame($this->sesi->id, $lembar['calibration_session_id']);
        $this->assertSame('Thermocouple Fluke 51-II', $lembar['equipment']['nama_alat']);
        $this->assertSame('Budi Teknisi', $lembar['teknisi']['nama']);
        $this->assertSame(
            $this->sesi->tanggal_kalibrasi->toDateString(),
            $lembar['tanggal_kalibrasi'],
        );
        $this->assertSame($this->sesi->keputusan, $lembar['keputusan']);
    }

    public function test_id_berkas_beda_dari_id_sesi_dan_dua_duanya_kekirim(): void
    {
        // Yang dipegang mobile buat membuka detail & buat
        // `PUT /arsip/berkas/{sesiId}/pindah` itu id SESI. Dua-duanya harus
        // ada di respons, dan skenarionya cuma sahih kalau angkanya beda.
        $file = $this->buka($this->tahun)['file'][0];

        $this->assertNotSame(
            $this->berkas->id,
            $this->sesi->id,
            'Skenarionya nggak kepasang: id berkas kebetulan sama dengan id sesi.'
        );
        $this->assertSame($this->berkas->id, $file['id']);
        $this->assertSame($this->sesi->id, $file['lembar_kerja']['calibration_session_id']);
    }

    public function test_berkas_tanpa_sesi_lembar_kerjanya_null(): void
    {
        // Sertifikat unggahan & berkas manual. Mobile membuangnya dari daftar
        // (kartunya nggak bisa dibuka ke detail sesi) — yang penting `null`-nya
        // eksplisit, bukan blok setengah isi yang bikin dia kelihatan sah.
        FolderFile::query()->create([
            'organization_id' => 1, 'folder_id' => $this->tahun->id,
            'calibration_session_id' => null,
            'nama' => 'Scan surat jalan.pdf', 'sumber' => FolderFile::SUMBER_UNGGAHAN,
            'uploaded_by' => $this->admin->id,
        ]);

        $file = collect($this->buka($this->tahun)['file'])
            ->firstWhere('nama', 'Scan surat jalan.pdf');

        $this->assertNotNull($file);
        $this->assertNull($file['lembar_kerja']);
    }

    public function test_subfolder_dan_berkas_pakai_kunci_sub_folder_dan_file(): void
    {
        // Penamaannya yang diikuti mobile. Ganti kunci di sini = layar Arsip
        // balik kosong tanpa error.
        Folder::query()->create([
            'organization_id' => 1, 'customer_id' => $this->tahun->customer_id,
            'nama' => 'Revisi', 'tipe' => 'manual', 'parent_id' => $this->tahun->id,
        ]);

        $data = $this->buka($this->tahun);

        $this->assertArrayHasKey('sub_folder', $data);
        $this->assertArrayHasKey('file', $data);
        $this->assertCount(1, $data['sub_folder']);
        $this->assertCount(1, $data['file']);
    }
}
