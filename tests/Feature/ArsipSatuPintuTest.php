<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Folder;
use App\Models\FolderFile;
use App\Models\Organization;
use App\Services\FolderOrganizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** K-50-06: PT › Tahun › Bulan › Jenis alat, revisi ikut aslinya, `arsip:rapikan`. */
class ArsipSatuPintuTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Customer $pt;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->pt = Customer::factory()->create(['organization_id' => $this->org->id, 'nama' => 'PT Arsip']);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->pt->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['nama' => 'Timbangan'])->id,
        ]);
    }

    private function sertifikat(string $nomor, string $terbit, array $atribut = []): Certificate
    {
        return Certificate::factory()->create([
            'organization_id' => $this->org->id,
            'nomor' => $nomor,
            'status' => Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => $terbit,
            'calibration_session_id' => CalibrationSession::factory()->create([
                'organization_id' => $this->org->id,
                'equipment_id' => $this->alat->id,
            ])->id,
            ...$atribut,
        ]);
    }

    private function namaJalur(Folder $folder): string
    {
        $nama = [$folder->nama];

        while ($folder->parent_id !== null) {
            $folder = $folder->parent;
            array_unshift($nama, $folder->nama);
        }

        return implode('/', $nama);
    }

    public function test_sertifikat_mendarat_di_pt_tahun_bulan_jenis_alat(): void
    {
        $c = $this->sertifikat('A-1', '2026-10-05')->load('session.equipment.customer');

        $berkas = app(FolderOrganizer::class)->tautkanSertifikat($c);

        $this->assertSame('PT Arsip/2026/10-Oktober/Timbangan', $this->namaJalur($berkas->folder));
    }

    public function test_revisi_mendarat_di_folder_sertifikat_aslinya(): void
    {
        $asli = $this->sertifikat('A-2', '2026-03-10')->load('session.equipment.customer');
        $organizer = app(FolderOrganizer::class);
        $organizer->tautkanSertifikat($asli);

        $revisi = $this->sertifikat('A-2-R1', '2026-10-16', ['revision_of' => $asli->id, 'revisi_ke' => 1])
            ->load('session.equipment.customer');
        $berkas = $organizer->tautkanSertifikat($revisi);

        $this->assertSame('PT Arsip/2026/3-Maret/Timbangan', $this->namaJalur($berkas->folder));
        // Idempoten: dipanggil ulang tidak menggandakan entri.
        $organizer->tautkanSertifikat($revisi);
        $this->assertSame(1, FolderFile::where('certificate_id', $revisi->id)->count());
    }

    public function test_rapikan_dry_run_tidak_mengubah_lalu_jalankan_memindahkan_dan_melaporkan_yatim(): void
    {
        $c = $this->sertifikat('A-3', '2026-10-05');
        $akar = Folder::create(['organization_id' => $this->org->id, 'nama' => 'PT Arsip', 'tipe' => Folder::TIPE_SISTEM, 'customer_id' => $this->pt->id]);
        $lama = Folder::create(['organization_id' => $this->org->id, 'parent_id' => $akar->id, 'nama' => '2026', 'tipe' => Folder::TIPE_SISTEM, 'customer_id' => $this->pt->id]);
        $berkas = FolderFile::create([
            'organization_id' => $this->org->id, 'folder_id' => $lama->id, 'certificate_id' => $c->id,
            'nama' => 'A-3.pdf', 'sumber' => FolderFile::SUMBER_SERTIFIKAT,
        ]);

        // Yatim: berkas otomatis yang sertifikat & sesinya tak terbaca. Tidak
        // dicocokkan lewat nama.
        $yatim = FolderFile::create([
            'organization_id' => $this->org->id, 'folder_id' => $lama->id,
            'nama' => 'PT Arsip lama.pdf', 'sumber' => FolderFile::SUMBER_SERTIFIKAT,
        ]);

        $this->artisan('arsip:rapikan --dry-run')
            ->expectsOutputToContain('1 berkas perlu dipindah, 0 sudah rapi, 0 dilewati (folder buatan admin), 1 yatim')
            ->assertSuccessful();
        $this->assertSame($lama->id, $berkas->fresh()->folder_id);

        $this->artisan('arsip:rapikan --jalankan')->assertSuccessful();

        $this->assertSame('PT Arsip/2026/10-Oktober/Timbangan', $this->namaJalur($berkas->fresh()->folder));
        $this->assertSame($lama->id, $yatim->fresh()->folder_id);

        // Jalankan ulang: sudah rapi.
        $this->artisan('arsip:rapikan --dry-run')->expectsOutputToContain('0 berkas perlu dipindah, 1 sudah rapi')->assertSuccessful();
    }
}
