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

/**
 * Cabang replay ikut aturan "teknisi cuma lihat sesi miliknya sendiri".
 *
 * ## Kenapa berkas ini ada
 *
 * `store()` dan `simpanAutoclave()` memulangkan `CalibrationResource` LENGKAP
 * hanya dengan mencocokkan `organization_id + client_request_id` — tanpa
 * memanggil `pastikanBolehLihat()`. Itu satu-satunya jalur baca
 * `CalibrationSession` di controller ini yang lolos dari aturan tersebut,
 * padahal empat belas jalur lain memakainya.
 *
 * ## Yang HARUS ditulis jujur: ini bukan lubang yang terbuka
 *
 * Menembusnya menuntut menebak `client_request_id` orang lain, dan itu tidak
 * mungkin dari luar:
 *
 * - mobile membangkitkannya dengan `Random.secure()`, UUIDv4 penuh (122 bit
 *   acak kriptografis) — `sidik-calibration-mobile/lib/core/utils/uuid.dart`;
 * - `grep -rn client_request_id app/Http/Resources/` → **nol hasil**. Nilainya
 *   tidak pernah dikembalikan ke klien mana pun, jadi tidak ada tempat untuk
 *   mengintipnya.
 *
 * Karena itu temuannya P3, bukan P2, dan berkas ini menjaga KONSISTENSI —
 * bukan menambal kebocoran. Pengecualian yang tidak punya alasan tertulis itu
 * yang biasanya disalin ke tempat berikutnya, dan di tempat berikutnya
 * nilainya belum tentu masih rahasia.
 */
class ReplayTidakLewatPenjagaKepemilikanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $teknisiA;

    private User $teknisiB;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->teknisiA = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
        $this->teknisiB = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
        ]);

        $kategori = EquipmentCategory::factory()->create(['kode' => 'panjang']);
        $pelanggan = Customer::factory()->create(['organization_id' => $this->org->id]);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => $kategori->id,
        ]);
        $this->standar = Standard::factory()->create(['organization_id' => $this->org->id]);
    }

    /** Sesi milik teknisi A, dengan `client_request_id` yang diketahui. */
    private function sesiMilikA(string $clientRequestId): CalibrationSession
    {
        return CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'teknisi_id' => $this->teknisiA->id,
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'client_request_id' => $clientRequestId,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $clientRequestId): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'kategori' => 'panjang',
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->toDateString(),
            'client_request_id' => $clientRequestId,
            'measurements' => [
                ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01]],
            ],
        ];
    }

    /**
     * INTI temuannya: teknisi B tidak menerima isi sesi milik teknisi A lewat
     * cabang replay.
     *
     * 404, bukan 403 — sama dengan yang dipulangkan `pastikanBolehLihat()` di
     * jalur baca lain, dan itu memang yang benar: 403 memberi tahu bahwa
     * sesinya ADA.
     */
    public function test_teknisi_lain_nggak_dapat_isi_sesi_lewat_replay(): void
    {
        $ada = $this->sesiMilikA('11111111-2222-4333-8444-555555555555');

        $this->actingAs($this->teknisiB)
            ->postJson('/api/calibrations', $this->payload($ada->client_request_id))
            ->assertNotFound();
    }

    /**
     * JANGAN kebablasan #1: pemiliknya sendiri TETAP dapat sesinya kembali.
     *
     * Ini seluruh gunanya cabang replay. Kalau test ini merah, teknisi yang
     * sinyalnya putus di lapangan bikin sesi dobel buat satu kejadian kalibrasi
     * — persis bug yang cabang ini dibangun untuk mencegahnya.
     */
    public function test_pemiliknya_tetap_dapat_sesinya_balik(): void
    {
        $ada = $this->sesiMilikA('11111111-2222-4333-8444-666666666666');

        $this->actingAs($this->teknisiA)
            ->postJson('/api/calibrations', $this->payload($ada->client_request_id))
            ->assertOk()
            ->assertJsonPath('data.id', $ada->id);

        $this->assertSame(
            1,
            CalibrationSession::where('client_request_id', $ada->client_request_id)->count(),
            'Retry-nya bikin sesi dobel.'
        );
    }

    /**
     * JANGAN kebablasan #2: admin tetap boleh melihatnya.
     *
     * `pastikanBolehLihat()` cuma membatasi peran teknisi. Kalau admin ikut
     * kena, jalur perbaikan sesi teknisi dari panel admin ikut mati.
     */
    public function test_admin_tetap_dapat_sesinya(): void
    {
        $ada = $this->sesiMilikA('11111111-2222-4333-8444-777777777777');
        $admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);

        $this->actingAs($admin)
            ->postJson('/api/calibrations', $this->payload($ada->client_request_id))
            ->assertOk()
            ->assertJsonPath('data.id', $ada->id);
    }

    /**
     * JANGAN kebablasan #3: kiriman baru (id yang belum pernah ada) tetap
     * membuat sesi seperti biasa.
     */
    public function test_kiriman_baru_tetap_bikin_sesi(): void
    {
        $this->actingAs($this->teknisiA)
            ->postJson('/api/calibrations', $this->payload('11111111-2222-4333-8444-888888888888'))
            ->assertCreated();

        $this->assertSame(1, CalibrationSession::count());
    }

    /**
     * Lab lain tetap tidak bisa menyentuhnya — penyaring `organization_id` yang
     * sudah ada tidak ikut hilang waktu cabangnya dipindah ke satu fungsi.
     */
    public function test_lab_lain_tetap_nggak_kesentuh(): void
    {
        $ada = $this->sesiMilikA('11111111-2222-4333-8444-999999999999');

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        // Bukan replay buat dia — id-nya milik organisasi lain, jadi
        // penyaringnya tidak menemukan apa pun dan permintaannya diproses
        // sebagai kiriman baru. Yang penting: dia tidak menerima isi sesi A.
        $respons = $this->actingAs($adminLain)
            ->postJson('/api/calibrations', $this->payload($ada->client_request_id));

        $this->assertNotSame(
            200,
            $respons->status(),
            'Lab lain dapat sesi orang lewat cabang replay.'
        );
    }
}
