<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Notifications\Pelanggan\TahapAlatBerubah;
use App\Services\PenautanSesiKeOrder;
use App\Services\TahapPaket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Sesi kalibrasi BARU otomatis tertaut ke item order alatnya (13 Nov 2026).
 *
 * Sebelumnya `calibration_sessions.order_item_id` tidak ditulis jalur mana pun, jadi
 * tahap turunan di `/paket`, kabar tahap, dan dua penjaga order lab ikut mati. Yang
 * dijaga di sini: tautan terbentuk untuk yang benar, TIDAK terbentuk untuk yang salah
 * (pelanggan lain, lab lain, sudah diserahkan, order tutup), pilihan ambigu
 * TIDAK ditautkan (semua kandidat tercatat), penautan tidak pernah menggagalkan pembuatan sesi,
 * dan nilai kiriman klien tidak pernah ditimpa.
 */
class PenautanSesiKeOrderTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    private Customer $ptA;

    private Customer $ptB;

    private Equipment $alatA;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->org = Organization::factory()->create();
        $this->siapkanJalurPelanggan();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_TEKNISI]);
        $this->ptA = Customer::factory()->create(['organization_id' => $this->org->id, 'nama' => 'PT Alfa']);
        $this->ptB = Customer::factory()->create(['organization_id' => $this->org->id, 'nama' => 'PT Beta']);
        $this->standar = Standard::factory()->create(['organization_id' => $this->org->id]);
        $this->alatA = $this->alat($this->ptA, ['nama_alat' => 'Mistar Baja Mitutoyo']);
    }

    private function alat(Customer $pemilik, array $isi = []): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $pemilik->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $this->org->id, 'kode' => 'panjang'.fake()->unique()->numberBetween(1, 99999), 'nama' => 'Panjang'])->id,
            'satuan' => 'mm',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
            ...$isi,
        ]);
    }

    private function order(Customer $pemilik, array $isi = []): Order
    {
        return Order::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'status' => Order::STATUS_DIPROSES,
            ...$isi,
        ]);
    }

    private function item(Order $order, Equipment $alat, array $isi = []): OrderItem
    {
        return OrderItem::factory()->create(['order_id' => $order->id, 'equipment_id' => $alat->id, ...$isi]);
    }

    /** @return array<string, mixed> */
    private function payload(Equipment $alat, array $ubah = []): array
    {
        return [
            'equipment_id' => $alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'manual',
            'status' => 'draft',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_ruang' => 23.5,
            'kelembaban' => 55.0,
            'measurements' => [],
            ...$ubah,
        ];
    }

    private function buatSesi(Equipment $alat, array $ubah = []): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, $ubah))
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::query()->findOrFail($id);
    }

    private function catatanTautan(CalibrationSession $sesi): ?string
    {
        return AuditLog::query()
            ->where('entity_type', 'calibration_sessions')
            ->where('entity_id', $sesi->id)
            ->where('note', 'like', 'Tertaut%')
            ->value('note');
    }

    private function catatanAmbigu(CalibrationSession $sesi): ?string
    {
        return AuditLog::query()
            ->where('entity_type', 'calibration_sessions')
            ->where('entity_id', $sesi->id)
            ->where('note', 'like', 'Tidak tertaut otomatis%')
            ->value('note');
    }

    /** @return array<string, mixed> */
    private function payloadAutoklaf(Equipment $alat): array
    {
        return [
            'equipment_id' => $alat->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'suhu_awal' => 24.4, 'suhu_akhir' => 24.5, 'kelembaban_awal' => 55, 'kelembaban_akhir' => 56,
            'set_point' => 121.0,
            'suhu' => [
                'disk' => [[121.27, 121.26, 121.26, 121.26, 121.28], [121.30, 121.26, 121.26, 121.25, 121.25], [121.26, 121.26, 121.28, 121.35, 121.28]],
                'indikator' => [121, 121, 121, 121, 121],
                'suhu_ruang' => [25, 25, 25, 25, 25],
            ],
            'tekanan' => ['uut_setting' => 0.112, 'satuan' => 'MPa', 'display' => 'Digital', 'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242]],
        ];
    }

    // --- tautan terbentuk ----------------------------------------------------

    public function test_sesi_baru_tertaut_ke_item_order_aktif_alat_itu_dan_tahapnya_hidup(): void
    {
        $order = $this->order($this->ptA, ['nomor' => 'ORD/2026/11/0101']);
        $item = $this->item($order, $this->alatA);
        $lain = $this->item($order, $this->alat($this->ptA));

        $sesi = $this->buatSesi($this->alatA);

        $this->assertSame($item->id, $sesi->order_item_id);
        $this->assertNotSame($lain->id, $sesi->order_item_id);

        $catatan = $this->catatanTautan($sesi);
        $this->assertNotNull($catatan, 'Tautan tidak tercatat di jejak audit sesi.');
        $this->assertStringContainsString('Tertaut otomatis', $catatan);
        $this->assertStringContainsString('ORD/2026/11/0101', $catatan);

        // Tahap turunan sekarang hidup: draft = "Sedang dikalibrasi", bukan "Alat diterima lab".
        $this->assertSame(TahapPaket::DIKALIBRASI, app(TahapPaket::class)->untukItem($item->fresh()->load('sesiTerakhir.certificate')));
    }

    public function test_sesi_autoklaf_juga_tertaut(): void
    {
        $autoklaf = $this->alat($this->ptA, ['nama_alat' => 'Autoclave', 'nama_alat_kemampuan' => 'Autoklaf']);
        $item = $this->item($this->order($this->ptA), $autoklaf);

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payloadAutoklaf($autoklaf))
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::query()->findOrFail($id);
        $this->assertSame($item->id, $sesi->order_item_id);
        $this->assertNotNull($this->catatanTautan($sesi));
    }

    public function test_kiriman_ulang_dengan_client_request_id_yang_sama_tidak_membuat_atau_menautkan_ulang(): void
    {
        $this->item($this->order($this->ptA), $this->alatA);
        $uuid = (string) Str::uuid();

        $pertama = $this->buatSesi($this->alatA, ['client_request_id' => $uuid]);
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload($this->alatA, ['client_request_id' => $uuid]))->assertOk();

        $this->assertSame(1, CalibrationSession::query()->count());
        $this->assertSame(1, AuditLog::query()->where('entity_id', $pertama->id)->where('note', 'like', 'Tertaut%')->count());
    }

    // --- tautan TIDAK terbentuk ----------------------------------------------

    public function test_tanpa_item_order_aktif_sesi_tetap_tanpa_tautan(): void
    {
        $sesi = $this->buatSesi($this->alatA);

        $this->assertNull($sesi->order_item_id);
        $this->assertNull($this->catatanTautan($sesi));
    }

    public function test_item_di_order_pelanggan_lain_tidak_dipilih(): void
    {
        // Data lama yang tercampur: alat PT A tercatat di order PT B.
        $this->item($this->order($this->ptB), $this->alatA);

        $this->assertNull($this->buatSesi($this->alatA)->order_item_id);
    }

    public function test_order_milik_lab_lain_tidak_dipilih(): void
    {
        $labLain = Organization::factory()->create();
        $this->item($this->order($this->ptA, ['organization_id' => $labLain->id]), $this->alatA);

        $this->assertNull($this->buatSesi($this->alatA)->order_item_id);
    }

    public function test_item_yang_sudah_diserahkan_tidak_dipilih_dan_yang_belum_di_order_lain_dipilih(): void
    {
        $diserahkan = $this->item($this->order($this->ptA, ['tanggal_masuk' => '2026-01-10']), $this->alatA, [
            'tahap_fisik' => TahapPaket::DISERAHKAN, 'tahap_fisik_pada' => now(), 'diserahkan_kepada' => 'Pak Budi',
        ]);
        $this->assertNull($this->buatSesi($this->alatA)->order_item_id, 'Item diserahkan dipilih.');

        $siapDiambil = $this->item($this->order($this->ptA, ['tanggal_masuk' => '2026-06-10']), $this->alatA, [
            'tahap_fisik' => TahapPaket::SIAP_DIAMBIL, 'tahap_fisik_pada' => now(),
        ]);

        // "Belum diserahkan" = aktif, termasuk yang sudah siap diambil.
        $this->assertSame($siapDiambil->id, $this->buatSesi($this->alatA)->order_item_id);
        $this->assertNotSame($diserahkan->id, $siapDiambil->id);
    }

    public function test_order_selesai_dibatalkan_atau_dihapus_tidak_dipilih(): void
    {
        $this->item($this->order($this->ptA, ['status' => Order::STATUS_SELESAI]), $this->alatA);
        $this->item($this->order($this->ptA, ['status' => Order::STATUS_DIBATALKAN]), $this->alatA);
        $terhapus = $this->order($this->ptA);
        $this->item($terhapus, $this->alatA);
        $terhapus->delete();

        $this->assertNull($this->buatSesi($this->alatA)->order_item_id);
    }

    // --- pilihan ambigu: TIDAK ditautkan, semua kandidat tercatat ---------------------
    //
    // Kode tidak pernah menulis `selesai` dan `diserahkan` jarang dicatat, jadi dua item
    // "aktif" untuk satu alat bisa berarti order lama yang basi. Menebak (terlama/terbaru)
    // mengirim kabar ke pelanggan untuk order yang salah; tidak menautkan = perilaku lama.

    public function test_beberapa_item_aktif_tidak_ditautkan_dan_semua_kandidat_tercatat_di_jejak_audit(): void
    {
        $baru = $this->order($this->ptA, ['tanggal_masuk' => '2026-11-10', 'nomor' => 'ORD/BARU']);
        $lama = $this->order($this->ptA, ['tanggal_masuk' => '2026-10-01', 'nomor' => 'ORD/LAMA']);
        $itemBaru = $this->item($baru, $this->alatA);
        $itemLama = $this->item($lama, $this->alatA);

        $sesi = $this->buatSesi($this->alatA);
        $kedua = $this->buatSesi($this->alatA);

        $this->assertNull($sesi->order_item_id, 'Pilihan ambigu ditautkan (menebak).');
        $this->assertNull($kedua->order_item_id);
        $this->assertNull($this->catatanTautan($sesi), 'Ada catatan "Tertaut" padahal tidak ditautkan.');

        $catatan = $this->catatanAmbigu($sesi);
        $this->assertNotNull($catatan, 'Kandidat tidak dicatat di jejak audit sesi.');
        foreach (['ORD/BARU', 'ORD/LAMA', "item #{$itemBaru->id}", "item #{$itemLama->id}", '2 item order aktif'] as $harus) {
            $this->assertStringContainsString($harus, $catatan);
        }

        // Tahap turunan tetap "Alat diterima lab": tidak ada tebakan yang bisa salah.
        $this->assertSame(TahapPaket::DITERIMA, app(TahapPaket::class)->untukItem($itemLama->fresh()->load('sesiTerakhir.certificate')));
    }

    public function test_dua_order_tetapi_satu_sudah_selesai_berarti_tepat_satu_kandidat_dan_ditautkan(): void
    {
        $this->item($this->order($this->ptA, ['status' => Order::STATUS_SELESAI]), $this->alatA);
        $aktif = $this->item($this->order($this->ptA), $this->alatA);

        $sesi = $this->buatSesi($this->alatA);

        $this->assertSame($aktif->id, $sesi->order_item_id);
        $this->assertNull($this->catatanAmbigu($sesi));
    }

    public function test_ambigu_dengan_banyak_kandidat_dicatat_ringkas_dengan_jumlahnya(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->item($this->order($this->ptA, [
                'nomor' => sprintf('ORD/KAND/%02d', $i),
                // Tanggal naik sesuai nomor: yang disebut di catatan 10 pertama menurut urutan tanggal.
                'tanggal_masuk' => sprintf('2026-10-%02d', $i),
            ]), $this->alatA);
        }

        $catatan = $this->catatanAmbigu($this->buatSesi($this->alatA));

        $this->assertStringContainsString('12 item order aktif', $catatan);
        $this->assertStringContainsString('ORD/KAND/01', $catatan);
        $this->assertStringContainsString('dan lainnya', $catatan);
    }

    // --- penautan TIDAK boleh menggagalkan pembuatan sesi ----------------------------

    /** Ganti `PenautanSesiKeOrder` dengan versi yang melempar galat di dalam bagian yang dijaga. */
    private function penautanYangMelempar(string $bagian): void
    {
        $this->app->bind(PenautanSesiKeOrder::class, fn () => $bagian === 'cari'
            ? new class extends PenautanSesiKeOrder
            {
                protected function cari(int $organizationId, int $equipmentId, ?int $dariKlien): array
                {
                    throw new \RuntimeException('galat rahasia@pelanggan.test');
                }
            }
            : new class extends PenautanSesiKeOrder
            {
                protected function tulisCatatan(CalibrationSession $sesi, array $tautan): void
                {
                    throw new \RuntimeException('galat rahasia@pelanggan.test');
                }
            });
    }

    /** @param  list<string>  $log */
    private function pastikanGalatPenautanTercatat(array $log): void
    {
        $this->assertNotEmpty(array_filter($log, fn (string $l): bool => str_contains($l, 'Penautan sesi ke item order gagal')), 'Galat penautan tidak dicatat.');
        $this->assertStringNotContainsString('rahasia@pelanggan.test', implode("\n", $log), 'Pesan galat (bisa memuat data pelanggan) ikut tercatat.');
    }

    public function test_penautan_yang_melempar_galat_tidak_menggagalkan_pembuatan_sesi_store(): void
    {
        $this->item($this->order($this->ptA), $this->alatA);
        $this->penautanYangMelempar('cari');
        $log = [];
        Log::listen(function ($pesan) use (&$log): void {
            $log[] = $pesan->message.' '.json_encode($pesan->context);
        });

        $sesi = $this->buatSesi($this->alatA); // buatSesi menuntut 201

        $this->assertNull($sesi->order_item_id);
        $this->assertSame(1, CalibrationSession::query()->count());
        $this->pastikanGalatPenautanTercatat($log);
    }

    public function test_penautan_yang_melempar_galat_tidak_menggagalkan_pembuatan_sesi_autoklaf(): void
    {
        $autoklaf = $this->alat($this->ptA, ['nama_alat' => 'Autoclave', 'nama_alat_kemampuan' => 'Autoklaf']);
        $this->item($this->order($this->ptA), $autoklaf);
        $this->penautanYangMelempar('cari');
        $log = [];
        Log::listen(function ($pesan) use (&$log): void {
            $log[] = $pesan->message.' '.json_encode($pesan->context);
        });

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payloadAutoklaf($autoklaf))
            ->assertCreated()
            ->json('data.id');

        $this->assertNull(CalibrationSession::query()->findOrFail($id)->order_item_id);
        $this->pastikanGalatPenautanTercatat($log);
    }

    public function test_catatan_audit_yang_melempar_galat_tidak_menggagalkan_pembuatan_sesi(): void
    {
        $item = $this->item($this->order($this->ptA), $this->alatA);
        $autoklaf = $this->alat($this->ptA, ['nama_alat' => 'Autoclave', 'nama_alat_kemampuan' => 'Autoklaf']);
        $itemAutoklaf = $this->item($this->order($this->ptA), $autoklaf);
        $this->penautanYangMelempar('catat');
        $log = [];
        Log::listen(function ($pesan) use (&$log): void {
            $log[] = $pesan->message.' '.json_encode($pesan->context);
        });

        $sesi = $this->buatSesi($this->alatA);
        $idAutoklaf = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payloadAutoklaf($autoklaf))
            ->assertCreated()
            ->json('data.id');

        // Tautannya sendiri sudah benar dan tersimpan (jejak "dibikin" Diaudit memuatnya); yang gagal hanya catatan tambahan.
        $this->assertSame($item->id, $sesi->order_item_id);
        $this->assertSame($itemAutoklaf->id, CalibrationSession::query()->findOrFail($idAutoklaf)->order_item_id);
        $this->assertNull($this->catatanTautan($sesi));
        $this->pastikanGalatPenautanTercatat($log);
    }

    // --- nilai kiriman klien ----------------------------------------------------

    public function test_order_item_id_kiriman_klien_dipakai_dan_tidak_ditimpa_pilihan_otomatis(): void
    {
        $lama = $this->order($this->ptA, ['tanggal_masuk' => '2026-10-01']);
        $baru = $this->order($this->ptA, ['tanggal_masuk' => '2026-11-10', 'nomor' => 'ORD/PILIHAN-KLIEN']);
        $this->item($lama, $this->alatA);
        $itemBaru = $this->item($baru, $this->alatA);

        $sesi = $this->buatSesi($this->alatA, ['order_item_id' => $itemBaru->id]);

        $this->assertSame($itemBaru->id, $sesi->order_item_id, 'Kiriman klien ditimpa pilihan otomatis.');
        $catatan = $this->catatanTautan($sesi);
        $this->assertStringContainsString('atas kiriman klien', $catatan);
        $this->assertStringContainsString('ORD/PILIHAN-KLIEN', $catatan);
    }

    public function test_order_item_id_kiriman_klien_yang_bukan_milik_alat_itu_ditolak_422(): void
    {
        $alatLain = $this->alat($this->ptA);
        $itemAlatLain = $this->item($this->order($this->ptA), $alatLain);
        $itemMilikB = $this->item($this->order($this->ptB), $alatLain);
        // Alat YANG SAMA, tetapi tercatat di order pelanggan lain (data lama yang tercampur).
        $itemAlatSamaOrderB = $this->item($this->order($this->ptB), $this->alatA);
        $this->item($this->order($this->ptA), $this->alatA);

        // item alat lain; item alat lain di order pelanggan lain; alat sama di order pelanggan lain; item yang tidak ada.
        foreach ([$itemAlatLain->id, $itemMilikB->id, $itemAlatSamaOrderB->id, 999999] as $idSalah) {
            $this->actingAs($this->teknisi)
                ->postJson('/api/calibrations', $this->payload($this->alatA, ['order_item_id' => $idSalah]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('order_item_id');
        }

        $this->assertSame(0, CalibrationSession::query()->count(), 'Sesi tersimpan walau tautan klien ditolak.');
    }

    public function test_order_item_id_kiriman_klien_dari_lab_lain_ditolak_422(): void
    {
        $labLain = Organization::factory()->create();
        $pelangganLab = Customer::factory()->create(['organization_id' => $labLain->id]);
        $alatLab = Equipment::factory()->create(['organization_id' => $labLain->id, 'customer_id' => $pelangganLab->id]);
        $itemLab = $this->item($this->order($pelangganLab, ['organization_id' => $labLain->id]), $alatLab);
        // Alat DAN pelanggan sama, hanya organisasi order yang beda (data salah-ketik / impor): satu-satunya
        // yang menolaknya adalah saringan organisasi di `ItemOrderMilikAlat`.
        $itemAlatSamaLabLain = $this->item($this->order($this->ptA, ['organization_id' => $labLain->id]), $this->alatA);

        foreach ([$itemLab->id, $itemAlatSamaLabLain->id] as $idSalah) {
            $this->actingAs($this->teknisi)
                ->postJson('/api/calibrations', $this->payload($this->alatA, ['order_item_id' => $idSalah]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('order_item_id');
        }
    }

    public function test_order_item_id_null_dari_klien_dianggap_tidak_dikirim(): void
    {
        $item = $this->item($this->order($this->ptA), $this->alatA);

        $this->assertSame($item->id, $this->buatSesi($this->alatA, ['order_item_id' => null])->order_item_id);
    }

    // --- efek ke penjaga order lab: tidak macet tanpa pesan jelas -----------------

    public function test_order_bersesi_ditolak_dihapus_dengan_pesan_jelas_dan_jalan_keluarnya_batalkan_berhasil(): void
    {
        $order = $this->order($this->ptA);
        $this->item($order, $this->alatA);
        $this->buatSesi($this->alatA);

        $tolak = $this->actingAs($this->admin)->deleteJson("/api/orders/{$order->id}")->assertStatus(422);
        $this->assertStringContainsString('dibatalkan', (string) $tolak->json('message'));
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/orders/{$order->id}", ['status' => Order::STATUS_DIBATALKAN])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_DIBATALKAN);
    }

    public function test_order_tanpa_sesi_tetap_bisa_dihapus_dan_item_bersesi_dipertahankan_saat_isi_diganti(): void
    {
        $kosong = $this->order($this->ptA);
        $this->item($kosong, $this->alat($this->ptA));
        $this->actingAs($this->admin)->deleteJson("/api/orders/{$kosong->id}")->assertOk();

        $order = $this->order($this->ptA);
        $itemBersesi = $this->item($order, $this->alatA);
        $this->buatSesi($this->alatA);
        $alatBaru = $this->alat($this->ptA);

        // Admin mengganti isi order tanpa menyebut alat yang sudah dikalibrasi: item bersesi TIDAK hilang.
        $respons = $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'items' => [['equipment_id' => $alatBaru->id]],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$itemBersesi->equipment_id, $alatBaru->id],
            collect($respons->json('data.items'))->pluck('alat.id')->all(),
        );
    }

    // --- hidup untuk pelanggan: tahap di /paket dan kabar tahap ------------------------

    private function picPelangganA(): User
    {
        $pic = $this->pelanggan();
        CustomerMember::create([
            'organization_id' => $this->org->id, 'customer_id' => $this->ptA->id, 'user_id' => $pic->id,
            'peran' => CustomerMember::PERAN_PIC_UTAMA, 'status' => CustomerMember::STATUS_AKTIF,
        ]);

        return $pic;
    }

    public function test_pelanggan_melihat_tahap_dikalibrasi_dan_dikabari_saat_teknisi_membuat_lembar(): void
    {
        config(['pelanggan.kabar_tahap_turunan' => true]);
        $pic = $this->picPelangganA();
        $order = $this->order($this->ptA);
        $item = $this->item($order, $this->alatA);

        $this->buatSesi($this->alatA);

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($pic))
            ->getJson("/api/pelanggan/v1/paket/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.alat.0.id', $item->id)
            ->assertJsonPath('data.alat.0.tahap', TahapPaket::DIKALIBRASI)
            ->assertJsonPath('data.alat.0.tahap_label', 'Sedang dikalibrasi');

        $kabar = $pic->notifications()->where('type', TahapAlatBerubah::class)->get();
        $this->assertCount(1, $kabar);
        $this->assertStringContainsString('Sedang dikalibrasi', $kabar->first()->data['body']);
    }

    public function test_dengan_sakelar_bawaan_mati_tahap_terlihat_di_paket_tetapi_tidak_ada_kabar(): void
    {
        // `KABAR_TAHAP_TURUNAN` bawaan false: tampilan `/paket` hidup, kabar turunan menunggu layar preferensi.
        $this->assertFalse((bool) config('pelanggan.kabar_tahap_turunan'), 'Bawaan sakelar kabar turunan harus mati.');
        $pic = $this->picPelangganA();
        $order = $this->order($this->ptA);
        $this->item($order, $this->alatA);

        $this->buatSesi($this->alatA);

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($pic))
            ->getJson("/api/pelanggan/v1/paket/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.alat.0.tahap', TahapPaket::DIKALIBRASI);

        $this->assertSame(0, $pic->notifications()->where('type', TahapAlatBerubah::class)->count());
    }

    // --- sensus untuk pemilik: sesi lama yang tak tertaut tapi punya item aktif ------------

    public function test_kueri_sensus_di_dokumen_menghitung_sesi_tak_tertaut_yang_punya_item_aktif(): void
    {
        $dokumen = (string) file_get_contents(base_path('docs/permintaan-user-7.md'));
        preg_match('/```sql\n(SELECT COUNT\(\*\) AS sesi_tak_tertaut_beritem_aktif.*?)```/s', $dokumen, $cocok);
        $this->assertNotEmpty($cocok, 'Kueri sensus tidak ditemukan di docs/permintaan-user-7.md §57.');
        $sql = trim($cocok[1]);
        $this->assertStringStartsWith('SELECT', $sql);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE)\b/i', $sql, 'Kueri sensus harus baca-saja.');

        $this->assertSame(0, (int) DB::selectOne($sql)->sesi_tak_tertaut_beritem_aktif);

        // Sesi LAMA (dibuat sebelum tautan ada) untuk alat yang kini punya item aktif: terhitung.
        $item = $this->item($this->order($this->ptA), $this->alatA);
        $lama = CalibrationSession::factory()->create(['organization_id' => $this->org->id, 'equipment_id' => $this->alatA->id, 'order_item_id' => null]);
        CalibrationSession::factory()->create(['organization_id' => $this->org->id, 'equipment_id' => $this->alatA->id, 'order_item_id' => $item->id]); // sudah tertaut
        $tanpaItem = $this->alat($this->ptA);
        CalibrationSession::factory()->create(['organization_id' => $this->org->id, 'equipment_id' => $tanpaItem->id, 'order_item_id' => null]); // tak ada item aktif

        $this->assertSame(1, (int) DB::selectOne($sql)->sesi_tak_tertaut_beritem_aktif);

        // Item diserahkan = tidak aktif lagi: sesi lama tidak terhitung.
        $item->update(['tahap_fisik' => TahapPaket::DISERAHKAN, 'tahap_fisik_pada' => now(), 'diserahkan_kepada' => 'X']);
        $this->assertSame(0, (int) DB::selectOne($sql)->sesi_tak_tertaut_beritem_aktif);
        $this->assertNull($lama->fresh()->order_item_id, 'Sensus tidak boleh menulis apa pun.');
    }

    public function test_kueri_sensus_ambigu_di_dokumen_menghitung_alat_dengan_lebih_dari_satu_item_aktif(): void
    {
        $dokumen = (string) file_get_contents(base_path('docs/permintaan-user-7.md'));
        preg_match('/```sql\n(SELECT COUNT\(\*\) AS alat_dengan_banyak_item_aktif.*?)```/s', $dokumen, $cocok);
        $this->assertNotEmpty($cocok, 'Kueri sensus ambigu tidak ditemukan di docs/permintaan-user-7.md §57.');
        $sql = trim($cocok[1]);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE)\b/i', $sql, 'Kueri sensus harus baca-saja.');

        $this->assertSame(0, (int) DB::selectOne($sql)->alat_dengan_banyak_item_aktif);

        // Satu item aktif = tidak ambigu; dua = ambigu; yang kedua sudah diserahkan = tidak lagi.
        $this->item($this->order($this->ptA), $this->alatA);
        $this->assertSame(0, (int) DB::selectOne($sql)->alat_dengan_banyak_item_aktif);
        $kedua = $this->item($this->order($this->ptA), $this->alatA);
        $this->assertSame(1, (int) DB::selectOne($sql)->alat_dengan_banyak_item_aktif);
        $kedua->update(['tahap_fisik' => TahapPaket::DISERAHKAN, 'tahap_fisik_pada' => now(), 'diserahkan_kepada' => 'X']);
        $this->assertSame(0, (int) DB::selectOne($sql)->alat_dengan_banyak_item_aktif);
        // Order selesai tidak dihitung aktif.
        $this->item($this->order($this->ptA, ['status' => Order::STATUS_SELESAI]), $this->alatA);
        $this->assertSame(0, (int) DB::selectOne($sql)->alat_dengan_banyak_item_aktif);
    }
}
