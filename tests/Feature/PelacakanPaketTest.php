<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\TahapPaket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pelacakan paket alat.
 *
 * ## Dua test di berkas ini yang paling berharga
 *
 * 1. `test_tahap_paket_mengikuti_alat_yang_paling_tertinggal()` — paket dengan 11
 *    alat selesai dan 1 masih dikalibrasi **belum selesai**. Pelanggan yang
 *    berangkat mengambil karena layarnya bilang "siap diambil" sudah kehilangan
 *    satu perjalanan, dan itu jenis kegagalan yang tidak muncul sebagai error.
 *
 * 2. `test_tahap_tidak_melenceng_waktu_sesi_dikembalikan_ke_teknisi()` — inti
 *    keputusan desainnya. Tahapnya DITURUNKAN, bukan disimpan, justru supaya
 *    `reject()` yang tidak tahu apa-apa soal pelacakan tidak bisa membuat layar
 *    pelanggan menampilkan tahap yang sudah tidak benar.
 */
class PelacakanPaketTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    private Customer $pelanggan;

    private Order $paket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
            'kode_teknisi' => 'RZP',
        ]);
        $this->pelanggan = Customer::factory()->create([
            'organization_id' => $this->org->id,
            'nama' => 'PT Contoh Sejahtera',
        ]);

        $this->paket = Order::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->pelanggan->id,
            'nomor' => 'ORD-2026-0001',
            'tanggal_masuk' => now()->subDays(5)->toDateString(),
            'tanggal_janji_selesai' => now()->addDays(2)->toDateString(),
        ]);
    }

    public function test_tahap_paket_mengikuti_alat_yang_paling_tertinggal(): void
    {
        // Dua alat selesai, satu masih dikalibrasi.
        $this->itemDenganSesi(CalibrationSession::STATUS_DISETUJUI, denganSertifikatTerbit: true);
        $this->itemDenganSesi(CalibrationSession::STATUS_DISETUJUI, denganSertifikatTerbit: true);
        $this->itemDenganSesi(CalibrationSession::STATUS_DRAFT);

        $tahap = app(TahapPaket::class)->untukPaket($this->paket->fresh()->load([
            'items.sesiTerakhir.certificate',
        ])->items);

        $this->assertSame(
            TahapPaket::DIKALIBRASI,
            $tahap,
            'Paket dengan satu alat masih dikalibrasi dilaporkan lebih maju dari kenyataannya. '
            .'Pelanggan yang berangkat mengambil karena layar bilang selesai kehilangan satu '
            .'perjalanan.',
        );
    }

    public function test_jumlah_selesai_ditampilkan_apa_adanya(): void
    {
        $this->itemDenganSesi(CalibrationSession::STATUS_DISETUJUI, denganSertifikatTerbit: true);
        $this->itemDenganSesi(CalibrationSession::STATUS_DISETUJUI, denganSertifikatTerbit: true);
        $this->itemDenganSesi(CalibrationSession::STATUS_DRAFT);

        $this->actingAs($this->admin)
            ->getJson("/api/pelacakan/{$this->paket->id}")
            ->assertOk()
            // "2/3 selesai" menjawab lebih banyak daripada nama tahapnya sendiri,
            // karena tahap paket selalu tahap yang paling tertinggal.
            ->assertJsonPath('data.jumlah_alat', 3)
            ->assertJsonPath('data.jumlah_selesai', 2);
    }

    public function test_tahap_tidak_melenceng_waktu_sesi_dikembalikan_ke_teknisi(): void
    {
        $item = $this->itemDenganSesi(CalibrationSession::STATUS_MENUNGGU_APPROVAL);
        $tahapService = app(TahapPaket::class);

        $this->assertSame(
            TahapPaket::MENUNGGU_PEMERIKSAAN,
            $tahapService->untukItem($item->fresh()->load('sesiTerakhir.certificate')),
        );

        // Admin mengembalikan ke teknisi. Kode `reject()` tidak tahu apa-apa soal
        // pelacakan dan tidak menyentuh kolom apa pun di `order_items` — dan itu
        // justru intinya: tahap yang diturunkan ikut berubah sendiri.
        $item->sesiTerakhir->forceFill([
            'status' => CalibrationSession::STATUS_PERLU_REVISI,
        ])->save();

        $this->assertSame(
            TahapPaket::PERLU_DIULANG,
            $tahapService->untukItem($item->fresh()->load('sesiTerakhir.certificate')),
            'Tahap pelacakan tidak ikut turun waktu sesinya dikembalikan ke teknisi. '
            .'Kalau ini merah, kemungkinan tahapnya disimpan di kolom, bukan diturunkan — '
            .'dan kolom itu akan terus melenceng lewat jalan lain yang tidak kelihatan.',
        );
    }

    public function test_label_pelanggan_tidak_menyebut_istilah_internal(): void
    {
        $this->itemDenganSesi(CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $isi = (string) $this->actingAs($this->admin)
            ->getJson("/api/pelacakan/{$this->paket->id}?untuk=pelanggan")
            ->assertOk()
            ->getContent();

        // "Ditolak" & "revisi" di layar pelanggan memicu telepon ke lab untuk
        // sesuatu yang normal terjadi. "Pengesahan" memunculkan pertanyaan soal
        // alur internal yang tidak ada gunanya dijawab.
        foreach (['Ditolak', 'ditolak', 'Perlu revisi', 'pengesahan'] as $istilah) {
            $this->assertStringNotContainsString(
                $istilah,
                $isi,
                "Label pelanggan memuat istilah internal `{$istilah}`.",
            );
        }
    }

    public function test_serah_terima_wajib_menyebut_nama_penerima(): void
    {
        $item = $this->itemDenganSesi(CalibrationSession::STATUS_DISETUJUI, denganSertifikatTerbit: true);

        $this->actingAs($this->admin)
            ->postJson("/api/pelacakan/item/{$item->id}/tahap-fisik", [
                'tahap_fisik' => TahapPaket::DISERAHKAN,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('diserahkan_kepada');

        $this->actingAs($this->admin)
            ->postJson("/api/pelacakan/item/{$item->id}/tahap-fisik", [
                'tahap_fisik' => TahapPaket::DISERAHKAN,
                'diserahkan_kepada' => 'Pak Budi (kurir JNE)',
            ])
            ->assertOk();

        $this->assertSame(TahapPaket::DISERAHKAN, $item->fresh()->tahap_fisik);
        $this->assertSame('Pak Budi (kurir JNE)', $item->fresh()->diserahkan_kepada);
    }

    public function test_alat_yang_sudah_diserahkan_tidak_bisa_dimundurkan(): void
    {
        $item = $this->itemDenganSesi(CalibrationSession::STATUS_DISETUJUI, denganSertifikatTerbit: true);

        $this->actingAs($this->admin)
            ->postJson("/api/pelacakan/item/{$item->id}/tahap-fisik", [
                'tahap_fisik' => TahapPaket::DISERAHKAN,
                'diserahkan_kepada' => 'Pak Budi',
            ])
            ->assertOk();

        // Barangnya sudah keluar dari lab. Membatalkannya di sistem tidak
        // membatalkan kenyataannya, dan riwayat yang bisa dibalik berhenti jadi
        // bukti serah terima.
        $this->actingAs($this->admin)
            ->postJson("/api/pelacakan/item/{$item->id}/tahap-fisik", [
                'tahap_fisik' => TahapPaket::SIAP_DIAMBIL,
            ])
            ->assertStatus(422);

        $this->assertSame(TahapPaket::DISERAHKAN, $item->fresh()->tahap_fisik);
    }

    public function test_terlambat_berhenti_dihitung_sesudah_diserahkan(): void
    {
        $this->paket->forceFill([
            'tanggal_janji_selesai' => now()->subDays(10)->toDateString(),
        ])->save();

        $item = $this->itemDenganSesi(CalibrationSession::STATUS_DISETUJUI, denganSertifikatTerbit: true);

        $this->actingAs($this->admin)
            ->getJson("/api/pelacakan/{$this->paket->id}")
            ->assertOk()
            ->assertJsonPath('data.terlambat_hari', 10);

        $item->forceFill([
            'tahap_fisik' => TahapPaket::DISERAHKAN,
            'tahap_fisik_pada' => now(),
            'diserahkan_kepada' => 'Pak Budi',
        ])->save();

        // Kalau tidak berhenti, daftar "terlambat" penuh pekerjaan yang sudah
        // selesai — sampai tidak ada yang membukanya lagi.
        $this->actingAs($this->admin)
            ->getJson("/api/pelacakan/{$this->paket->id}")
            ->assertOk()
            ->assertJsonPath('data.terlambat_hari', null);
    }

    public function test_paket_lab_lain_dijawab_404(): void
    {
        $this->itemDenganSesi(CalibrationSession::STATUS_DRAFT);

        $labLain = Organization::factory()->create();
        $adminLabLain = User::factory()->admin()->create(['organization_id' => $labLain->id]);

        $this->actingAs($adminLabLain)
            ->getJson("/api/pelacakan/{$this->paket->id}")
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function itemDenganSesi(string $status, bool $denganSertifikatTerbit = false): OrderItem
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->org->id,
            ])->id,
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $this->paket->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $this->teknisi->id,
        ]);

        // Ditautkan lewat `order_item_id`, persis seperti sesi yang dibuat dari
        // paket — itu sambungan yang dibaca `OrderItem::sesiTerakhir()`.
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'equipment_id' => $alat->id,
            'order_item_id' => $item->id,
            'teknisi_id' => $this->teknisi->id,
            'status' => $status,
            'keputusan' => 'PASS',
        ]);

        if ($denganSertifikatTerbit) {
            Certificate::factory()->create([
                'organization_id' => $this->org->id,
                'calibration_session_id' => $sesi->id,
                'status' => Certificate::STATUS_TERBIT,
            ]);
        }

        return $item;
    }
}
