<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `POST /api/calibrations/preview` — hitung tanpa nyimpen, buat "hitung sambil
 * ngetik" di lembar kerja (docs/permintaan-worksheet-ph.md §4).
 *
 * Dua hal yang diuji di sini, dan dua-duanya alasan fitur ini ada:
 *
 * 1. Angkanya IDENTIK sama yang keluar kalau payload yang sama disubmit beneran.
 *    Kalau nggak, teknisi lihat satu angka di layar dan angka lain tercetak di
 *    sertifikat — buat lab terakreditasi itu temuan audit, bukan cuma nggak enak.
 * 2. Nggak ada satu baris pun masuk DB. Preview dipanggil tiap teknisi selesai
 *    ngisi satu baris; kalau nyisain jejak, satu lembar kerja bisa ninggalin
 *    puluhan sesi sampah dan ngabisin nomor sesi.
 */
class CalibrationPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/calibrations/preview';

    private User $teknisi;

    private User $viewer;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->teknisi = User::factory()->create();
        $this->viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->alat = Equipment::factory()->create([
            'nama_alat' => 'pH Meter Mettler Toledo',
            'customer_id' => Customer::factory()->create(['nama' => 'PT Tirta Gracia'])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'satuan' => 'pH',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        $this->standar = Standard::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $ubah
     * @return array<string, mixed>
     */
    private function payload(array $ubah = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_awal' => 22.2,
            'suhu_akhir' => 22.4,
            'kelembaban_awal' => 55.0,
            'kelembaban_akhir' => 56.0,
            'measurements' => [
                [
                    'titik_ukur' => 7.0,
                    'satuan' => 'pH',
                    'pembacaan' => [7.02, 7.04, 7.03],
                    'suhu' => [22.2, 22.3, 22.2],
                    'pembacaan_sebelum' => [7.10, 7.12],
                    'suhu_sebelum' => [22.2, 22.2],
                ],
            ],
            ...$ubah,
        ];
    }

    /**
     * INI test intinya: payload yang sama dikirim ke /preview dan ke
     * /calibrations harus ngasih angka yang sama, sampai desimal terakhir.
     *
     * Dibandingin field-per-field, bukan cuma "ada angkanya" — soalnya yang
     * bahaya di sini bukan error, tapi selisih tipis yang lolos tanpa gejala.
     */
    public function test_angka_preview_identik_sama_angka_yang_tersimpan(): void
    {
        $payload = $this->payload();

        $preview = $this->actingAs($this->teknisi)->postJson(self::URL, $payload)->assertOk();

        $tersimpan = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated();

        $titikPreview = $preview->json('data.titik');
        $titikTersimpan = $tersimpan->json('data.titik');

        $this->assertCount(1, $titikPreview);
        $this->assertSameSize($titikTersimpan, $titikPreview);

        // `titik` dua-duanya lewat CalibrationResource::petakanTitik(), jadi
        // bentuk & isinya wajib sama persis.
        $this->assertSame($titikTersimpan, $titikPreview);

        // Ringkasan titik penentu — termasuk `keputusan` di dalamnya.
        $this->assertSame($tersimpan->json('data.hasil'), $preview->json('data.hasil'));

        // Preview naruh `keputusan` di top-level juga (buat kasus `hasil` masih
        // null). Nilainya wajib sama sama yang di dalam `hasil`. Sesi tersimpan
        // nggak punya key ini di top-level — `keputusan`-nya cuma di `hasil`.
        $this->assertSame($tersimpan->json('data.hasil.keputusan'), $preview->json('data.keputusan'));
        $this->assertSame($preview->json('data.hasil.keputusan'), $preview->json('data.keputusan'));
    }

    /**
     * `lembar_perhitungan` & `kondisi_lingkungan` di preview dibandingin ke sumber
     * resmi-nya: `GET /calibrations/{id}/perhitungan` (lembar PERHITUNGAN sisi
     * admin). Dua-duanya lewat PerhitunganBuilder yang sama, jadi kalau ini beda
     * berarti ada yang nulis implementasi kedua.
     *
     * Catat bedanya nama: di endpoint perhitungan dua tabel itu ada di
     * `data.hasil`, di preview namanya `data.lembar_perhitungan` — soalnya di
     * `GET /calibrations/{id}` key `hasil` artinya ringkasan titik penentu.
     */
    public function test_lembar_perhitungan_preview_sama_sama_punya_admin(): void
    {
        $payload = $this->payload();

        $preview = $this->actingAs($this->teknisi)->postJson(self::URL, $payload)->assertOk();

        $sesi = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated()
            ->json('data.id');

        $admin = User::factory()->admin()->create();
        $perhitungan = $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesi}/perhitungan")
            ->assertOk();

        $this->assertSame($perhitungan->json('data.hasil'), $preview->json('data.lembar_perhitungan'));
        $this->assertSame(
            $perhitungan->json('data.kondisi_lingkungan'),
            $preview->json('data.kondisi_lingkungan'),
        );
    }

    /**
     * `hasil` di preview HARUS artinya sama kayak `hasil` di GET /calibrations/{id}:
     * ringkasan titik penentu, bukan dua tabel worksheet. Bentuk sama tapi arti
     * beda antar endpoint itu jebakan paling mahal buat sisi frontend — pernah
     * hampir kejadian waktu endpoint ini dibikin.
     */
    public function test_key_hasil_artinya_sama_kayak_detail_sesi(): void
    {
        $preview = $this->actingAs($this->teknisi)
            ->postJson(self::URL, $this->payload())
            ->assertOk();

        $this->assertSame(
            ['rata_rata', 'error', 'ketidakpastian_gabungan', 'faktor_cakupan_k', 'ketidakpastian_diperluas', 'keputusan'],
            array_keys($preview->json('data.hasil')),
        );

        // Dua tabel worksheet ada di key-nya sendiri, bukan numpang `hasil`.
        $this->assertCount(2, $preview->json('data.lembar_perhitungan'));
    }

    /**
     * Urutan key `type_b_components` wajib tetap seperti di kontrak-api.md.
     *
     * Kolomnya JSON: MySQL nyimpen dalam format biner yang ngurutin ulang key,
     * jadi baris yang udah lewat DB balik dengan urutan beda dari yang baru
     * dihitung di memori. SQLite nggak, makanya test ini nggak bakal gagal di CI
     * gara-gara driver — yang dijaga di sini normalisasinya di PHP, bukan DB-nya.
     *
     * Kalau normalisasi itu dilepas, `preview` berhenti byte-identik sama sesi
     * tersimpan di MySQL — padahal itu jaminan utama endpoint ini.
     */
    public function test_urutan_key_type_b_components_dinormalisasi(): void
    {
        $res = $this->actingAs($this->teknisi)
            ->postJson(self::URL, $this->payload())
            ->assertOk();

        $komponen = $res->json('data.titik.0.type_b_components');

        $this->assertNotEmpty($komponen);

        foreach ($komponen as $k) {
            $this->assertSame(['sumber', 'keterangan', 'distribusi', 'nilai'], array_keys($k));
        }
    }

    public function test_preview_nggak_nyimpen_apa_apa(): void
    {
        $this->actingAs($this->teknisi)->postJson(self::URL, $this->payload())->assertOk();

        $this->assertSame(0, CalibrationSession::count());
        $this->assertSame(0, RawMeasurement::count());
        $this->assertSame(0, UncertaintyCalculation::count());
    }

    /** Dipanggil berkali-kali sambil ngetik — tetap nggak boleh ninggalin jejak. */
    public function test_dipanggil_berulang_tetap_nggak_ninggalin_jejak(): void
    {
        foreach ([[7.02], [7.02, 7.04], [7.02, 7.04, 7.03]] as $pembacaan) {
            $this->actingAs($this->teknisi)->postJson(self::URL, $this->payload([
                'measurements' => [
                    ['titik_ukur' => 7.0, 'satuan' => 'pH', 'pembacaan' => $pembacaan],
                ],
            ]))->assertOk();
        }

        $this->assertSame(0, CalibrationSession::count());
        $this->assertSame(0, RawMeasurement::count());
    }

    public function test_balikin_tabel_sebelum_dan_sesudah_adjustment(): void
    {
        $res = $this->actingAs($this->teknisi)
            ->postJson(self::URL, $this->payload())
            ->assertOk();

        $this->assertSame('sebelum_adjustment', $res->json('data.lembar_perhitungan.0.tahap'));
        $this->assertSame('sesudah_adjustment', $res->json('data.lembar_perhitungan.1.tahap'));

        // Kolom yang diminta worksheet §4: Average, Correction, STDEV.
        foreach ([0, 1] as $tabel) {
            $this->assertNotNull($res->json("data.lembar_perhitungan.{$tabel}.titik.0.average"));
            $this->assertNotNull($res->json("data.lembar_perhitungan.{$tabel}.titik.0.stdev"));
            $this->assertArrayHasKey('correction', $res->json("data.lembar_perhitungan.{$tabel}.titik.0"));
            // MAX STDEV per tabel — penanda cepat titik mana yang paling nggak stabil.
            $this->assertArrayHasKey('max_stdev', $res->json("data.lembar_perhitungan.{$tabel}"));
        }

        // As-found cuma 2 pembacaan, sesudah adjustment 3 — kebalik bakal ketangkep.
        $this->assertCount(2, $res->json('data.lembar_perhitungan.0.titik.0.pembacaan'));
        $this->assertCount(3, $res->json('data.lembar_perhitungan.1.titik.0.pembacaan'));
    }

    public function test_kondisi_lingkungan_dihitung_dari_pembacaan_awal_akhir(): void
    {
        $res = $this->actingAs($this->teknisi)
            ->postJson(self::URL, $this->payload())
            ->assertOk();

        // Average = (22.2 + 22.4) / 2, delta = |22.4 - 22.2|.
        // Pakai delta, bukan assertSame: (22.2 + 22.4) / 2 di float itu
        // 22.299999999999997, dan angka ini nggak lewat pembulatan kolom —
        // `kondisi_lingkungan` dihitung on the fly, bukan disimpen per-titik.
        // Jalur tersimpan ngasih nilai yang sama persis (diuji di test identik
        // di atas), jadi yang salah kalau ditulis 22.3 itu ekspektasinya.
        $this->assertEqualsWithDelta(22.3, $res->json('data.kondisi_lingkungan.suhu.average'), 1e-9);
        $this->assertEqualsWithDelta(0.2, $res->json('data.kondisi_lingkungan.suhu.delta'), 1e-9);
        $this->assertEqualsWithDelta(55.5, $res->json('data.kondisi_lingkungan.kelembaban.average'), 1e-9);
    }

    /**
     * Titik yang belum bisa dihitung wajib dijelasin alasannya. Tanpa ini, titik
     * yang ilang dari `titik` kelihatan kayak bug di mata teknisi — padahal cuma
     * kurang satu pembacaan.
     */
    public function test_titik_kurang_pembacaan_dijelasin_alasannya(): void
    {
        $res = $this->actingAs($this->teknisi)->postJson(self::URL, $this->payload([
            'measurements' => [
                ['titik_ukur' => 7.0, 'satuan' => 'pH', 'pembacaan' => [7.02]],
            ],
        ]))->assertOk();

        $this->assertSame([], $res->json('data.titik'));
        $this->assertNull($res->json('data.keputusan'));
        $this->assertSame(1, $res->json('data.belum_dihitung.0.titik_ke'));
        $this->assertStringContainsString('minimal 2', $res->json('data.belum_dihitung.0.alasan'));
    }

    public function test_toleransi_alat_kosong_dijelasin_alasannya(): void
    {
        $this->alat->update(['toleransi' => null]);

        $res = $this->actingAs($this->teknisi)
            ->postJson(self::URL, $this->payload())
            ->assertOk();

        $this->assertSame([], $res->json('data.titik'));
        $this->assertStringContainsString('Toleransi', $res->json('data.belum_dihitung.0.alasan'));
    }

    /** Satu titik FAIL bikin keputusan sesi FAIL — aturan yang sama kayak submit. */
    public function test_satu_titik_fail_bikin_keputusan_sesi_fail(): void
    {
        $res = $this->actingAs($this->teknisi)->postJson(self::URL, $this->payload([
            'measurements' => [
                // Jauh di luar toleransi 0.05.
                ['titik_ukur' => 7.0, 'satuan' => 'pH', 'pembacaan' => [9.5, 9.52, 9.51]],
            ],
        ]))->assertOk();

        $this->assertSame('FAIL', $res->json('data.titik.0.keputusan'));
        $this->assertSame('FAIL', $res->json('data.keputusan'));
    }

    public function test_viewer_ditolak(): void
    {
        $this->actingAs($this->viewer)
            ->postJson(self::URL, $this->payload())
            ->assertForbidden();
    }

    /** Admin juga boleh — dia yang meriksa, jadi butuh lihat angkanya juga. */
    public function test_admin_boleh_preview(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson(self::URL, $this->payload())
            ->assertOk()
            ->assertJsonPath('data.keputusan', 'PASS');
    }

    /**
     * Alat milik PT lain ditolak — dan ini yang dijaga: `susunPengukuran()`
     * manggil `Equipment::findOrFail()` TANPA scope organisasi, jadi satu-satunya
     * penjaga isolasi di jalur ini adalah `Rule::exists()->where('organization_id')`
     * di CalibrationRequest.
     *
     * Kalau aturan itu kelepas, preview bakal balikin nama alat DAN nama +
     * alamat customer PT lain (lewat `identitas_alat` / `identitas_customer` di
     * lembar perhitungan) ke siapa pun yang bisa nebak id-nya. Test ini yang
     * bikin kelepasannya kelihatan.
     */
    public function test_alat_milik_organisasi_lain_ditolak(): void
    {
        $lain = Organization::factory()->create();

        $alatOrgLain = Equipment::factory()->create([
            'organization_id' => $lain->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $lain->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph-lain'])->id,
            'satuan' => 'pH',
            'toleransi' => 0.05,
        ]);

        $this->actingAs($this->teknisi)
            ->postJson(self::URL, $this->payload(['equipment_id' => $alatOrgLain->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('equipment_id');
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->postJson(self::URL, $this->payload())->assertUnauthorized();
    }

    /** Validasi jalan sama kayak POST /calibrations — pakai FormRequest yang sama. */
    public function test_payload_ngawur_ditolak_422(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson(self::URL, $this->payload(['equipment_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('equipment_id');
    }

    /**
     * Lembar kerja boleh dikirim setengah jadi, jadi preview tanpa satu pun
     * pembacaan pun harus jalan — bukan 500.
     */
    public function test_tanpa_measurements_tetap_jalan(): void
    {
        $res = $this->actingAs($this->teknisi)->postJson(self::URL, $this->payload([
            'measurements' => [],
        ]))->assertOk();

        $this->assertSame([], $res->json('data.titik'));
        $this->assertNull($res->json('data.keputusan'));
        $this->assertSame([], $res->json('data.belum_dihitung'));
    }
}
