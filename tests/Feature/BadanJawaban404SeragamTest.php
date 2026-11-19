<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Penugasan;
use App\Models\UndanganPelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * QA ronde 2-B: BADAN jawaban 404 untuk data lab/perusahaan lain sama persis
 * dengan ID yang tidak ada.
 *
 * Dulu keduanya 404 tetapi badannya beda: ID yang tidak ada (route model binding)
 * menjawab `{"message":"Data yang dicari nggak ketemu."}`, sedangkan ID milik lab
 * lain (`abort(404)` di `PenjagaOrganisasi`, `abort_if(...)`, `authorize()`, ...)
 * menjawab `{"message": ""}`. Pembanding teks itu cukup untuk membedakan "ada
 * tetapi bukan punyamu" dari "tidak ada" -- oracle yang sama dengan 403-vs-404,
 * hanya lebih halus. Sekarang semua `abort(404)` tanpa pesan jatuh ke render 404
 * generik di `bootstrap/app.php`.
 */
class BadanJawaban404SeragamTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const PESAN = 'Data yang dicari nggak ketemu.';

    private const TAK_ADA = 99999999;

    private User $admin;

    private User $teknisi;

    /** @var array<string, int> ID milik LAB LAIN per jenis data */
    private array $lain = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $lab = $this->organisasi();
        $labLain = Organization::factory()->create();

        $this->admin = User::factory()->admin()->create(['organization_id' => $lab->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $lab->id, 'role' => User::ROLE_TEKNISI]);

        $pt = Customer::factory()->create(['organization_id' => $labLain->id]);
        $alat = Equipment::factory()->create(['organization_id' => $labLain->id, 'customer_id' => $pt->id]);
        $sesi = CalibrationSession::factory()->create(['organization_id' => $labLain->id, 'equipment_id' => $alat->id]);
        $sertifikat = Certificate::factory()->create(['organization_id' => $labLain->id, 'calibration_session_id' => $sesi->id]);
        $order = Order::factory()->create(['organization_id' => $labLain->id, 'customer_id' => $pt->id]);
        $penugasan = Penugasan::create([
            'organization_id' => $labLain->id,
            'judul' => 'Tugas lab lain',
            'tipe' => Penugasan::TIPE_PERSONAL,
            'status' => Penugasan::STATUS_AKTIF,
            'dibuat_oleh' => User::factory()->admin()->create(['organization_id' => $labLain->id])->id,
        ]);

        $this->lain = [
            'sesi' => $sesi->id,
            'alat' => $alat->id,
            'pelanggan' => $pt->id,
            'penugasan' => $penugasan->id,
            'sertifikat' => $sertifikat->id,
            'order' => $order->id,
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}> [jenis, metode, template, badan] */
    public static function rute(): array
    {
        return [
            'GET sesi' => ['sesi', 'GET', '/api/calibrations/{id}', []],
            'PUT sesi' => ['sesi', 'PUT', '/api/calibrations/{id}', []],
            'GET alat' => ['alat', 'GET', '/api/equipments/{id}', []],
            'GET pelanggan' => ['pelanggan', 'GET', '/api/customers/{id}', []],
            'GET penugasan' => ['penugasan', 'GET', '/api/penugasan/{id}', []],
            'PUT penugasan' => ['penugasan', 'PUT', '/api/penugasan/{id}', []],
            'POST batalkan penugasan' => ['penugasan', 'POST', '/api/penugasan/{id}/batalkan', ['alasan' => 'tidak berhak']],
            'POST selesaikan penugasan' => ['penugasan', 'POST', '/api/penugasan/{id}/selesaikan', []],
            'GET sertifikat' => ['sertifikat', 'GET', '/api/certificates/{id}', []],
            'GET order' => ['order', 'GET', '/api/orders/{id}', []],
        ];
    }

    /**
     * @param  array<string, mixed>  $badan
     */
    #[DataProvider('rute')]
    public function test_data_lab_lain_dijawab_status_dan_badan_sama_dengan_id_tak_ada(string $jenis, string $metode, string $template, array $badan): void
    {
        foreach ([$this->admin, $this->teknisi] as $pelaku) {
            if ($pelaku->role === User::ROLE_TEKNISI && in_array($jenis, ['pelanggan', 'order'], true)) {
                continue; // teknisi tidak punya rute ini; yang diuji hanya peran yang berhak
            }

            $lain = $this->actingAs($pelaku)->json($metode, str_replace('{id}', (string) $this->lain[$jenis], $template), $badan);
            $takAda = $this->actingAs($pelaku)->json($metode, str_replace('{id}', (string) self::TAK_ADA, $template), $badan);

            if ($lain->getStatusCode() === 403 && $takAda->getStatusCode() === 403) {
                continue; // peran tanpa hak: dijawab 403 seragam (PeranSebelumBindingTest)
            }

            $this->assertSame(404, $takAda->getStatusCode(), "{$pelaku->role} {$metode} {$template}: ID tak ada");
            $this->assertSame(404, $lain->getStatusCode(), "{$pelaku->role} {$metode} {$template}: data lab lain");
            $this->assertSame($takAda->getContent(), $lain->getContent(), "{$pelaku->role} {$metode} {$template}: badan 404 berbeda");
            $this->assertSame(self::PESAN, $lain->json('message'));
        }
    }

    /** Sisi pelanggan: undangan dan anggota perusahaan LAIN lawan ID yang tidak ada (PIC yang berhak). */
    public function test_pelanggan_data_perusahaan_lain_badan_sama_dengan_id_tak_ada(): void
    {
        $pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $picLain = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $perusahaanLain = $picLain->keanggotaan()->first()->customer;

        $undanganLain = UndanganPelanggan::factory()->create([
            'organization_id' => $perusahaanLain->organization_id,
            'customer_id' => $perusahaanLain->getKey(),
        ]);
        $anggotaLain = $picLain->keanggotaan()->first();

        $kasus = [
            ['DELETE', '/api/pelanggan/v1/anggota/undangan/{id}', $undanganLain->getKey()],
            ['POST', '/api/pelanggan/v1/anggota/{id}/nonaktifkan', $anggotaLain->getKey()],
        ];

        foreach ($kasus as [$metode, $template, $idLain]) {
            $this->permintaanBaru();
            $lain = $this->withHeaders($this->bearer($pic))->json($metode, str_replace('{id}', (string) $idLain, $template));
            $this->permintaanBaru();
            $takAda = $this->withHeaders($this->bearer($pic))->json($metode, str_replace('{id}', (string) self::TAK_ADA, $template));

            $this->assertSame(404, $takAda->getStatusCode(), "{$metode} {$template}: ID tak ada");
            $this->assertSame(404, $lain->getStatusCode(), "{$metode} {$template}: perusahaan lain");
            $this->assertSame($takAda->getContent(), $lain->getContent(), "{$metode} {$template}: badan 404 berbeda");
        }
    }

    /** Yang SENGAJA berpesan (rute tak ada) tidak diseragamkan: hanya `abort(404)` tanpa pesan dan binding model. */
    public function test_rute_yang_tidak_ada_tidak_tersentuh(): void
    {
        $badan = $this->actingAs($this->admin)->getJson('/api/rute-yang-tidak-pernah-ada')->assertNotFound()->json('message');

        $this->assertNotSame(self::PESAN, $badan);
    }
}
