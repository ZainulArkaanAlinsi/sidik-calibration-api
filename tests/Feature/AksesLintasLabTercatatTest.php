<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Percobaan akses lintas lab harus meninggalkan jejak — dan jejaknya harus
 * ditulis di lab PEMANGGIL, bukan lab pemilik data.
 *
 * ## Pertanyaan yang hari ini tidak punya jawaban
 *
 * "Pernah ada yang mencoba membuka data lab lain?" Hari ini 404-nya tidak
 * meninggalkan apa pun — tidak di `audit_logs`, tidak di log aplikasi. Jadi
 * jawabannya "tidak tahu", dan itu pertanyaan yang ditanyakan asesor waktu
 * menilai kerahasiaan antar pelanggan (ISO/IEC 17025 klausul 4.2).
 *
 * ## Yang paling gampang salah, dan test kedua di bawah yang menjaganya
 *
 * Naluri pertama: catat di riwayat lab yang datanya diincar, supaya dia tahu ada
 * yang mengintip. Itu **membuat kebocoran baru** — riwayat lab B jadi berisi
 * keberadaan lab A, lengkap dengan id barisnya. Alat yang dibuat untuk mencegah
 * kebocoran malah menjadi salurannya.
 */
class AksesLintasLabTercatatTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab1;

    private Organization $lab2;

    private User $adminLab1;

    private Equipment $alatLab2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab1 = Organization::factory()->create(['nama' => 'Lab Satu']);
        $this->lab2 = Organization::factory()->create(['nama' => 'Lab Dua']);

        $this->adminLab1 = User::factory()->admin()->create(['organization_id' => $this->lab1->id]);

        $this->alatLab2 = Equipment::factory()->create([
            'organization_id' => $this->lab2->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->lab2->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->lab2->id,
            ])->id,
            'nama_alat' => 'BOCOR-ALAT-LAB2',
        ]);
    }

    public function test_percobaan_lintas_lab_dijawab_404_dan_tercatat(): void
    {
        $this->actingAs($this->adminLab1)
            ->getJson("/api/equipments/{$this->alatLab2->id}")
            ->assertNotFound();

        $baris = AuditLog::query()
            ->where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)
            ->get();

        $this->assertCount(
            1,
            $baris,
            'Percobaan akses lintas lab tidak tercatat. 404 yang tidak meninggalkan jejak '
            .'membuat pertanyaan "pernah ada yang mencoba?" tidak bisa dijawab.',
        );

        $this->assertSame($this->adminLab1->id, $baris->first()->changed_by);
        $this->assertSame('equipments', $baris->first()->entity_type);
        $this->assertSame($this->alatLab2->id, $baris->first()->entity_id);
    }

    public function test_jejaknya_ditulis_di_lab_pemanggil_bukan_lab_pemilik_data(): void
    {
        $this->actingAs($this->adminLab1)
            ->getJson("/api/equipments/{$this->alatLab2->id}")
            ->assertNotFound();

        $baris = AuditLog::query()->where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)->firstOrFail();

        $this->assertSame(
            $this->lab1->id,
            $baris->organization_id,
            'Jejak percobaan ditulis di riwayat lab PEMILIK DATA. Akibatnya riwayat lab 2 '
            .'jadi memuat keberadaan lab 1 beserta id barisnya — kebocoran baru, dibuat '
            .'oleh alat yang seharusnya mencegah kebocoran.',
        );

        // Lab pemilik TIDAK boleh menemukan barisnya di riwayatnya sendiri.
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('organization_id', $this->lab2->id)
                ->where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)
                ->count(),
        );

        // Dan barisnya sendiri tidak boleh menyebut lab mana yang diincar.
        $this->assertStringNotContainsString(
            'Lab Dua',
            json_encode($baris->new_data, JSON_THROW_ON_ERROR),
        );
    }

    public function test_kegagalan_mencatat_tidak_mengubah_404_jadi_500(): void
    {
        // Kalau `audit_logs` tidak bisa ditulisi (penuh, terkunci, migrasi
        // setengah jalan), yang HARUS tetap terjadi adalah 404. 500 di jalur ini
        // berbahaya: beberapa penangan galat menyertakan pesan yang memuat nama
        // tabel dan id yang barusan diperiksa — jadi kegagalan mencatat justru
        // membocorkan hal yang dijaga.
        AuditLog::creating(fn () => throw new \RuntimeException('simulasi: audit_logs terkunci'));

        $this->actingAs($this->adminLab1)
            ->getJson("/api/equipments/{$this->alatLab2->id}")
            ->assertNotFound();
    }

    public function test_404_biasa_tidak_ikut_tercatat(): void
    {
        // Id yang memang tidak ada di tabel mana pun bukan percobaan lintas lab.
        // Kalau ini ikut tercatat, riwayatnya penuh derau dan yang sungguhan
        // tenggelam di dalamnya.
        $this->actingAs($this->adminLab1)
            ->getJson('/api/equipments/999999')
            ->assertNotFound();

        $this->assertSame(
            0,
            AuditLog::query()->where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)->count(),
        );
    }
}
