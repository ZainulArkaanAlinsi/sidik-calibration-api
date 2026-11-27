<?php

namespace Tests\Feature;

use App\Http\Resources\EquipmentResource;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Status per alat: `sesi_terakhir` di `GET /api/equipments` (dan `/{id}`).
 * Kontrak: `docs/perintah-frontend-status-alat.md`.
 *
 * Yang dikunci:
 *  - Tautan alat ke sesi lewat `calibration_sessions.equipment_id`; "terakhir"
 *    = id sesi terbesar. Tahapnya diturunkan `TahapPaket` (delapan tahap yang
 *    sudah ada), bukan tahap baru.
 *  - Isolasi: sesi organisasi lain tidak pernah muncul, bahkan bila baris sesi
 *    yang rusak menunjuk ke alat kita.
 *  - Sesi yang diarsipkan (soft delete) tidak dihitung.
 *  - Teknisi hanya melihat sesi miliknya (aturan `GET /calibrations`).
 *  - Satu halaman = jumlah kueri yang sama berapa pun jumlah alatnya.
 */
class StatusPerAlatTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private Organization $labLain;

    private User $admin;

    private User $teknisi;

    private User $teknisiLain;

    private User $viewer;

    private Customer $pt;

    private EquipmentCategory $kategori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->labLain = Organization::factory()->create();

        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI]);
        $this->teknisiLain = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI]);
        $this->viewer = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_VIEWER]);

        $this->pt = Customer::factory()->create(['organization_id' => $this->lab->id]);
        $this->kategori = EquipmentCategory::factory()->create(['organization_id' => $this->lab->id, 'kode' => 'panjang']);
    }

    // ------------------------------------------------------------ bentuk dasar

    public function test_alat_tanpa_sesi_memulangkan_sesi_terakhir_null_dan_kuncinya_ada(): void
    {
        $alat = $this->alat();

        // Kunci SELALU ada di daftar, nilainya null: klien membedakan "belum
        // ada sesi" dari "server lama yang belum mengirim field ini".
        $this->actingAs($this->admin)->getJson('/api/equipments')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'status', 'sesi_terakhir']]])
            ->assertJsonPath('data.0.id', $alat->id)
            ->assertJsonPath('data.0.sesi_terakhir', null);

        $this->actingAs($this->admin)->getJson("/api/equipments/{$alat->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'sesi_terakhir']])
            ->assertJsonPath('data.sesi_terakhir', null);
    }

    public function test_alat_dengan_satu_sesi_draft_dilaporkan_dikalibrasi(): void
    {
        $alat = $this->alat();
        $sesi = $this->sesi($alat, CalibrationSession::STATUS_DRAFT);

        $this->actingAs($this->admin)->getJson('/api/equipments')
            ->assertOk()
            ->assertJsonPath('data.0.sesi_terakhir.id', $sesi->id)
            ->assertJsonPath('data.0.sesi_terakhir.status', 'draft')
            ->assertJsonPath('data.0.sesi_terakhir.tahap', TahapPaket::DIKALIBRASI)
            ->assertJsonPath('data.0.sesi_terakhir.tahap_label', 'Dikalibrasi')
            ->assertJsonPath('data.0.sesi_terakhir.diperbarui_pada', $sesi->fresh()->updated_at->toIso8601String());

        // `status` milik ALAT (aktif/overdue/nonaktif) tidak tersentuh.
        $this->actingAs($this->admin)->getJson("/api/equipments/{$alat->id}")
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.sesi_terakhir.id', $sesi->id);
    }

    public function test_tahap_mengikuti_status_sesi_dan_sertifikat(): void
    {
        $perkiraan = [];

        foreach ([
            CalibrationSession::STATUS_DRAFT => TahapPaket::DIKALIBRASI,
            CalibrationSession::STATUS_PERLU_REVISI => TahapPaket::PERLU_DIULANG,
            // "Sudah dikirim teknisi": menunggu pemeriksaan admin.
            CalibrationSession::STATUS_MENUNGGU_APPROVAL => TahapPaket::MENUNGGU_PEMERIKSAAN,
            CalibrationSession::STATUS_MENUNGGU_PENGESAHAN => TahapPaket::MENUNGGU_PENGESAHAN,
            // Disetujui tapi sertifikat belum terbit: PDF masih dicetak.
            CalibrationSession::STATUS_DISETUJUI => TahapPaket::MENUNGGU_PENGESAHAN,
        ] as $status => $tahap) {
            $perkiraan[$this->sesi($this->alat(), $status)->equipment_id] = [$status, $tahap];
        }

        $terbit = $this->alat();
        $this->sesi($terbit, CalibrationSession::STATUS_DISETUJUI, terbit: true);
        $perkiraan[$terbit->id] = [CalibrationSession::STATUS_DISETUJUI, TahapPaket::SERTIFIKAT_TERBIT];

        $data = $this->daftar($this->admin);

        $this->assertCount(count($perkiraan), $data);

        foreach ($perkiraan as $idAlat => [$status, $tahap]) {
            $this->assertSame($status, $data[$idAlat]['sesi_terakhir']['status']);
            $this->assertSame($tahap, $data[$idAlat]['sesi_terakhir']['tahap'], "status {$status}");
            $this->assertSame(TahapPaket::LABEL_INTERNAL[$tahap], $data[$idAlat]['sesi_terakhir']['tahap_label']);
        }
    }

    public function test_tahap_fisik_baris_paket_menang_atas_status_sesi(): void
    {
        $alat = $this->alat();
        $item = $this->itemPaket($alat);
        $this->sesi($alat, CalibrationSession::STATUS_DISETUJUI, item: $item, terbit: true);

        $this->assertSame(
            TahapPaket::SERTIFIKAT_TERBIT,
            $this->daftar($this->admin)[$alat->id]['sesi_terakhir']['tahap'],
        );

        // Petugas mencatat alat sudah diserahkan: aturan `TahapPaket::untukItem`.
        $item->forceFill(['tahap_fisik' => TahapPaket::DISERAHKAN])->save();

        $respons = $this->daftar($this->admin)[$alat->id]['sesi_terakhir'];
        $this->assertSame(TahapPaket::DISERAHKAN, $respons['tahap']);
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $respons['status']);
    }

    public function test_untuk_sesi_sama_dengan_untuk_item_untuk_semua_status(): void
    {
        $tahap = app(TahapPaket::class);

        foreach ([
            CalibrationSession::STATUS_DRAFT,
            CalibrationSession::STATUS_PERLU_REVISI,
            CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            CalibrationSession::STATUS_MENUNGGU_PENGESAHAN,
            CalibrationSession::STATUS_DISETUJUI,
        ] as $status) {
            foreach ([false, true] as $terbit) {
                $alat = $this->alat();
                $item = $this->itemPaket($alat);
                $this->sesi($alat, $status, item: $item, terbit: $terbit);

                $item = OrderItem::query()->with('sesiTerakhir.certificate')->findOrFail($item->id);
                $sesi = CalibrationSession::query()->with(['certificate', 'orderItem'])->findOrFail($item->sesiTerakhir->id);

                $this->assertSame(
                    $tahap->untukItem($item),
                    $tahap->untukSesi($sesi),
                    "status {$status}, sertifikat terbit: ".($terbit ? 'ya' : 'tidak'),
                );
            }
        }
    }

    // ---------------------------------------------------------- banyak sesi

    public function test_banyak_sesi_mengambil_yang_terbaru_per_alat(): void
    {
        $a = $this->alat();
        $this->sesi($a, CalibrationSession::STATUS_DISETUJUI, terbit: true);
        $a2 = $this->sesi($a, CalibrationSession::STATUS_DRAFT);

        $b = $this->alat();
        $this->sesi($b, CalibrationSession::STATUS_DRAFT);
        $b2 = $this->sesi($b, CalibrationSession::STATUS_PERLU_REVISI);

        $c = $this->alat();

        $data = $this->daftar($this->admin);

        $this->assertSame($a2->id, $data[$a->id]['sesi_terakhir']['id']);
        $this->assertSame(TahapPaket::DIKALIBRASI, $data[$a->id]['sesi_terakhir']['tahap']);
        $this->assertSame($b2->id, $data[$b->id]['sesi_terakhir']['id']);
        $this->assertSame(TahapPaket::PERLU_DIULANG, $data[$b->id]['sesi_terakhir']['tahap']);
        $this->assertNull($data[$c->id]['sesi_terakhir']);
    }

    public function test_terbaru_artinya_id_terbesar_bukan_waktu_ubah(): void
    {
        $alat = $this->alat();
        $lama = $this->sesi($alat, CalibrationSession::STATUS_DISETUJUI, terbit: true);
        $baru = $this->sesi($alat, CalibrationSession::STATUS_DRAFT);

        // Sesi lama disentuh belakangan (mis. cetak ulang sertifikat): tetap
        // BUKAN yang terakhir. Aturan yang sama dengan `GET /calibrations`.
        CalibrationSession::query()->whereKey($lama->id)->update(['updated_at' => now()->addDay()]);

        $this->assertSame($baru->id, $this->daftar($this->admin)[$alat->id]['sesi_terakhir']['id']);
    }

    // ---------------------------------------------------------- soft delete

    public function test_sesi_yang_diarsipkan_tidak_dihitung(): void
    {
        $alat = $this->alat();
        $lama = $this->sesi($alat, CalibrationSession::STATUS_DISETUJUI, terbit: true);
        $arsip = $this->sesi($alat, CalibrationSession::STATUS_DRAFT);

        $this->assertSame($arsip->id, $this->daftar($this->admin)[$alat->id]['sesi_terakhir']['id']);

        $arsip->delete();

        // Kembali ke sesi sebelumnya, bukan null dan bukan sesi arsip.
        $respons = $this->daftar($this->admin)[$alat->id]['sesi_terakhir'];
        $this->assertSame($lama->id, $respons['id']);
        $this->assertSame(TahapPaket::SERTIFIKAT_TERBIT, $respons['tahap']);

        $lama->delete();

        $this->assertNull($this->daftar($this->admin)[$alat->id]['sesi_terakhir']);
    }

    // -------------------------------------------------------- lintas organisasi

    public function test_sesi_organisasi_lain_tidak_bocor(): void
    {
        $alat = $this->alat();
        $milikKita = $this->sesi($alat, CalibrationSession::STATUS_DRAFT);

        $alatAsing = $this->alat($this->labLain);
        $sesiAsing = $this->sesi($alatAsing, CalibrationSession::STATUS_MENUNGGU_APPROVAL, organisasi: $this->labLain);

        // Admin lab kita: hanya alat kita, tanpa jejak sesi lab lain.
        $data = $this->daftar($this->admin);
        $this->assertSame([$alat->id], array_keys($data));
        $this->assertSame($milikKita->id, $data[$alat->id]['sesi_terakhir']['id']);

        // Admin lab lain: alat kita tidak ada di daftar, detailnya 404.
        $adminLain = User::factory()->admin()->create(['organization_id' => $this->labLain->id]);
        $dataLain = $this->daftar($adminLain);
        $this->assertSame([$alatAsing->id], array_keys($dataLain));
        $this->assertSame($sesiAsing->id, $dataLain[$alatAsing->id]['sesi_terakhir']['id']);
        $this->actingAs($adminLain)->getJson("/api/equipments/{$alat->id}")->assertNotFound();
    }

    public function test_baris_sesi_rusak_dari_organisasi_lain_pada_alat_kita_tidak_dilaporkan(): void
    {
        $alat = $this->alat();

        // Data rusak: sesi berorganisasi lain tetapi menunjuk alat lab kita,
        // dan id-nya TERBESAR. Penyaring `organization_id` harus menolaknya.
        $rusak = $this->sesi($alat, CalibrationSession::STATUS_DRAFT, organisasi: $this->labLain);

        $this->assertNull($this->daftar($this->admin)[$alat->id]['sesi_terakhir']);

        $milikKita = $this->sesi($alat, CalibrationSession::STATUS_DRAFT);
        $rusakLagi = $this->sesi($alat, CalibrationSession::STATUS_DRAFT, organisasi: $this->labLain);

        $respons = $this->daftar($this->admin)[$alat->id]['sesi_terakhir'];
        $this->assertSame($milikKita->id, $respons['id']);
        $this->assertNotContains($respons['id'], [$rusak->id, $rusakLagi->id]);
    }

    // ----------------------------------------------------------------- peran

    public function test_teknisi_hanya_melihat_sesi_miliknya_admin_dan_viewer_melihat_terbaru(): void
    {
        $bersama = $this->alat();
        $miliknya = $this->sesi($bersama, CalibrationSession::STATUS_DISETUJUI, teknisi: $this->teknisi, terbit: true);
        $milikLain = $this->sesi($bersama, CalibrationSession::STATUS_DRAFT, teknisi: $this->teknisiLain);

        $hanyaLain = $this->alat();
        $this->sesi($hanyaLain, CalibrationSession::STATUS_MENUNGGU_APPROVAL, teknisi: $this->teknisiLain);

        // Teknisi: sesi terbarunya SENDIRI, bukan null dan bukan sesi teknisi lain.
        $data = $this->daftar($this->teknisi);
        $this->assertSame($miliknya->id, $data[$bersama->id]['sesi_terakhir']['id']);
        $this->assertNull($data[$hanyaLain->id]['sesi_terakhir']);

        $dataLain = $this->daftar($this->teknisiLain);
        $this->assertSame($milikLain->id, $dataLain[$bersama->id]['sesi_terakhir']['id']);

        foreach ([$this->admin, $this->viewer] as $pengguna) {
            $this->assertSame($milikLain->id, $this->daftar($pengguna)[$bersama->id]['sesi_terakhir']['id'], $pengguna->role);
            $this->assertNotNull($this->daftar($pengguna)[$hanyaLain->id]['sesi_terakhir'], $pengguna->role);
        }

        // Detail mengikuti aturan yang sama.
        $this->actingAs($this->teknisi)->getJson("/api/equipments/{$bersama->id}")
            ->assertJsonPath('data.sesi_terakhir.id', $miliknya->id);
    }

    // ------------------------------------------------------------ performa

    public function test_jumlah_kueri_sama_untuk_dua_alat_dan_untuk_delapan_alat(): void
    {
        foreach (range(1, 2) as $i) {
            $this->sesiLengkap($this->alat());
        }

        $this->actingAs($this->admin)->getJson('/api/equipments')->assertOk(); // hangatkan
        $sedikit = $this->hitungKueri(fn () => $this->actingAs($this->admin)->getJson('/api/equipments')->assertOk());

        foreach (range(1, 6) as $i) {
            $this->sesiLengkap($this->alat());
        }

        $banyak = $this->hitungKueri(fn () => $this->actingAs($this->admin)->getJson('/api/equipments')
            ->assertOk()
            ->assertJsonCount(8, 'data'));

        $this->assertSame(
            $sedikit,
            $banyak,
            'Jumlah kueri daftar alat tumbuh mengikuti jumlah alat: ada N+1 di status per alat.',
        );
    }

    // --------------------------------------------------- jalur tulis & bersarang

    public function test_tambah_alat_dan_ubah_alat_ikut_memuat_sesi_terakhir(): void
    {
        $this->actingAs($this->admin)->postJson('/api/equipments', [
            'nama_alat' => 'Alat Uji Status',
            'serial_number' => 'SN-STATUS-001',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pt->id,
        ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'sesi_terakhir']])
            ->assertJsonPath('data.sesi_terakhir', null);

        $alat = $this->alat();
        $sesi = $this->sesi($alat, CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $this->actingAs($this->admin)->putJson("/api/equipments/{$alat->id}", ['merk' => 'Merk Uji'])
            ->assertOk()
            ->assertJsonPath('data.merk', 'Merk Uji')
            ->assertJsonPath('data.sesi_terakhir.id', $sesi->id)
            ->assertJsonPath('data.sesi_terakhir.tahap', TahapPaket::MENUNGGU_PEMERIKSAAN);
    }

    public function test_resource_bersarang_tidak_memuat_kunci_dan_tidak_menambah_kueri(): void
    {
        $alat = $this->alat();
        $this->sesi($alat, CalibrationSession::STATUS_DRAFT);
        $alat->load(['customer', 'category']);

        // Dipakai bersarang (mis. `OrderItemResource.alat`): sesi tidak dimuat,
        // jadi kuncinya TIDAK ADA dan Resource tidak menyentuh tabel sesi.
        DB::enableQueryLog();
        $hasil = (new EquipmentResource($alat))->resolve();
        $keSesi = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'calibration_sessions'));
        DB::disableQueryLog();

        $this->assertArrayNotHasKey('sesi_terakhir', $hasil);
        $this->assertSame([], array_values($keSesi));
    }

    public function test_jalur_studio_membawa_field_yang_sama(): void
    {
        $pemilik = User::factory()->create([
            'organization_id' => $this->lab->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'email' => 'pemilik-status@sidik.test',
            'password' => Hash::make('sandi-uji-status-1'),
        ]);
        $alat = $this->alat();
        $sesi = $this->sesi($alat, CalibrationSession::STATUS_PERLU_REVISI);

        config(['studio.akun' => 'pemilik-status@sidik.test']);
        $token = $pemilik->createToken('studio', ['studio'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/studio/equipments', ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => '1.0.0'])
            ->assertOk()
            ->assertJsonPath('data.0.id', $alat->id)
            ->assertJsonPath('data.0.sesi_terakhir.id', $sesi->id)
            ->assertJsonPath('data.0.sesi_terakhir.tahap', TahapPaket::PERLU_DIULANG);
    }

    // --------------------------------------------------------------- bantu

    private function alat(?Organization $lab = null): Equipment
    {
        $lab ??= $this->lab;
        $punyaKita = $lab->is($this->lab);

        return Equipment::factory()->create([
            'organization_id' => $lab->id,
            'customer_id' => $punyaKita
                ? $this->pt->id
                : Customer::factory()->create(['organization_id' => $lab->id])->id,
            'equipment_category_id' => $punyaKita
                ? $this->kategori->id
                : EquipmentCategory::factory()->create(['organization_id' => $lab->id])->id,
        ]);
    }

    private function sesi(
        Equipment $alat,
        string $status,
        ?User $teknisi = null,
        ?OrderItem $item = null,
        bool $terbit = false,
        ?Organization $organisasi = null,
    ): CalibrationSession {
        $organisasi ??= $this->lab;

        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $organisasi->id,
            'equipment_id' => $alat->id,
            'order_item_id' => $item?->id,
            'teknisi_id' => ($teknisi ?? $this->teknisi)->id,
            'status' => $status,
        ]);

        if ($terbit) {
            Certificate::factory()->create([
                'organization_id' => $organisasi->id,
                'calibration_session_id' => $sesi->id,
                'status' => Certificate::STATUS_TERBIT,
            ]);
        }

        return $sesi;
    }

    /** Sesi + baris paket + sertifikat terbit: semua relasi yang dibaca tahap. */
    private function sesiLengkap(Equipment $alat): CalibrationSession
    {
        return $this->sesi($alat, CalibrationSession::STATUS_DISETUJUI, item: $this->itemPaket($alat), terbit: true);
    }

    private function itemPaket(Equipment $alat): OrderItem
    {
        $paket = Order::factory()->create(['organization_id' => $this->lab->id, 'customer_id' => $this->pt->id]);

        return OrderItem::factory()->create([
            'order_id' => $paket->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $this->teknisi->id,
        ]);
    }

    /**
     * `GET /api/equipments` dengan per_page 100, hasilnya per id alat.
     *
     * @return array<int, array<string, mixed>>
     */
    private function daftar(User $pengguna): array
    {
        $respons = $this->actingAs($pengguna)->getJson('/api/equipments?per_page=100')->assertOk();

        return collect($respons->json('data'))->keyBy('id')->all();
    }

    private function hitungKueri(callable $kerja): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $kerja();

        $jumlah = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $jumlah;
    }
}
