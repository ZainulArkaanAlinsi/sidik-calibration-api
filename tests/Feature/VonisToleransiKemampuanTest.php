<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `punya_toleransi` di `GET /api/categories/{kode}` — jawaban server buat
 * pertanyaan "jenis alat ini divonis PASS/FAIL atau nggak".
 *
 * ## Kenapa berkas ini ada
 *
 * Form Tambah Alat di mobile mewajibkan `toleransi` buat SEMUA alat, alasannya
 * ditulis di kodenya sendiri: "alat tanpa toleransi nggak bisa dikalibrasi —
 * 422 belakangan".
 *
 * Alasan itu keliru buat **15 dari 20** profil. Conductivity, Spectro,
 * Autoklaf, DO, Gas Detector, TITS, TIDS, kelima Enclosure, dan ketiga alat
 * suhu masternya berhenti di `Correction` + `U95%` — nggak ada batas
 * keberterimaan sama sekali. Dan `CalibrationValidator::periksaKelengkapanHitung()`
 * sengaja melewatinya (`$profilAlat?->punyaToleransi() !== false`), jadi 422
 * yang ditakutkan form itu **nggak pernah datang**.
 *
 * Yang datang justru sebaliknya: teknisi dipaksa MENGARANG angka toleransi
 * buat alat yang nggak divonis — mengarang kriteria kelulusan. Mengisi kolom
 * itu pernah mematikan seluruh sesi Conductivity.
 *
 * Jadi jawabannya dituturkan server per baris kemampuan, di response yang
 * sama yang udah dibaca form itu buat ngisi dropdown jenis alat.
 *
 * ## Yang dikunci
 *
 * Bukan cuma "field-nya ada". Yang dikunci: jawabannya lahir dari
 * `CalibrationProfileRegistry`, BUKAN dari daftar nama alat yang ditulis
 * tangan di controller. Daftar tulis tangan bakal ketinggalan begitu profil
 * ke-21 masuk — dan ketinggalannya diem, nggak ada yang bunyi.
 */
class VonisToleransiKemampuanTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'role' => User::ROLE_TEKNISI,
            'organization_id' => 1,
        ]);
    }

    private function kategoriSuhu(): EquipmentCategory
    {
        return EquipmentCategory::factory()->create([
            'organization_id' => 1,
            'kode' => 'suhu-dan-kelembapan',
            'nama' => 'Suhu dan Kelembapan',
        ]);
    }

    private function baris(EquipmentCategory $kategori, string $nama, array $tambahan = []): CalibrationCapability
    {
        // `CalibrationCapabilityFactory::definition()` narik `nama_alat` dari
        // `fake()->unique()->randomElement()` atas daftar 6 nama — jadi baris
        // ke-7 di satu test bikin Faker nyerah ("Maximum retries reached"),
        // walaupun namanya kita timpa sendiri di bawah. Test ini sengaja bikin
        // 15+ baris (satu per profil tanpa vonis), jadi penyimpan nilai
        // uniknya dikosongin dulu tiap baris.
        fake()->unique(true);

        return CalibrationCapability::factory()->create(array_merge([
            'organization_id' => 1,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => $nama,
            'satuan' => '°C',
        ], $tambahan));
    }

    /** @return array<string, bool> nama_alat => punya_toleransi */
    private function vonisDariApi(string $kode): array
    {
        $rows = $this->actingAs($this->teknisi)
            ->getJson("/api/categories/{$kode}")
            ->assertOk()
            ->json('data.kemampuan');

        $hasil = [];
        foreach ($rows as $r) {
            $this->assertArrayHasKey(
                'punya_toleransi',
                $r,
                "Baris '{$r['nama_alat']}' nggak bawa `punya_toleransi`. Tanpa field ini form Alat "
                .'balik mewajibkan toleransi buat semua alat.'
            );
            $hasil[$r['nama_alat']] = $r['punya_toleransi'];
        }

        return $hasil;
    }

    public function test_tiga_alat_suhu_baru_nggak_divonis_pass_fail(): void
    {
        $kategori = $this->kategoriSuhu();
        foreach (['Thermocouple', 'Termometer Gelas', 'Thermohygrometer'] as $nama) {
            $this->baris($kategori, $nama);
        }

        $vonis = $this->vonisDariApi('suhu-dan-kelembapan');

        $this->assertFalse($vonis['Thermocouple']);
        $this->assertFalse($vonis['Termometer Gelas']);
        $this->assertFalse($vonis['Thermohygrometer']);
    }

    public function test_alat_yang_beneran_divonis_tetap_true(): void
    {
        // Arah sebaliknya, dan ini yang taruhannya lebih berat: kalau `false`
        // bocor ke alat yang divonis, form berhenti minta angka yang nentuin
        // PASS/FAIL — dan sesinya baru mentok belakangan di 422 server.
        $kategori = EquipmentCategory::factory()->create([
            'organization_id' => 1,
            'kode' => 'instrumen-analitik',
            'nama' => 'Instrumen Analitik',
        ]);
        $this->baris($kategori, 'pH Meter', ['satuan' => 'pH']);
        $this->baris($kategori, 'Turbidimeter', ['satuan' => 'NTU']);

        $vonis = $this->vonisDariApi('instrumen-analitik');

        $this->assertTrue($vonis['pH Meter']);
        $this->assertTrue($vonis['Turbidimeter']);
    }

    public function test_nama_alat_di_luar_profil_mana_pun_dianggap_divonis(): void
    {
        // Nama yang ditambah teknisi sendiri jatuh ke jalur generik, dan di
        // situ toleransi memang penentu PASS/FAIL-nya. Bawaannya `true` —
        // salah di sisi yang aman.
        $kategori = $this->kategoriSuhu();
        $this->baris($kategori, 'Alat Karangan Yang Nggak Ada Profilnya');

        $vonis = $this->vonisDariApi('suhu-dan-kelembapan');

        $this->assertTrue($vonis['Alat Karangan Yang Nggak Ada Profilnya']);
    }

    public function test_jawabannya_ikut_registry_bukan_daftar_tulis_tangan(): void
    {
        // Penjaga yang sebenernya. Setiap profil yang `punyaToleransi()`-nya
        // false HARUS bikin `punya_toleransi: false` di API — nggak boleh ada
        // satu pun yang cuma ada di registry tapi nggak nyampe ke response,
        // karena itu persis bentuk kegagalan daftar tulis tangan: ketinggalan,
        // dan ketinggalannya diem.
        $registry = app(CalibrationProfileRegistry::class);
        $kategori = $this->kategoriSuhu();

        $tanpaVonis = [];
        foreach ($registry->semua() as $profil) {
            if ($profil->punyaToleransi()) {
                continue;
            }
            $nama = $profil->namaAlatKemampuan();
            $tanpaVonis[] = $nama;
            $this->baris($kategori, $nama);
        }

        $this->assertGreaterThanOrEqual(
            15,
            count($tanpaVonis),
            'Jumlah profil tanpa vonis turun di bawah 15. Kalau itu disengaja, perbarui angkanya '
            .'di sini DAN di docs/kontrak-api.md §3 — dua-duanya nyebut "15 dari 20".'
        );

        $vonis = $this->vonisDariApi('suhu-dan-kelembapan');
        foreach ($tanpaVonis as $nama) {
            $this->assertFalse(
                $vonis[$nama],
                "Profil '{$nama}' bilang nggak divonis PASS/FAIL, tapi API-nya masih ngirim "
                .'`punya_toleransi: true`. Form Alat bakal terus maksa teknisi ngarang toleransi '
                .'buat alat ini.'
            );
        }
    }

    public function test_rentang_lengkap_ikut_kekirim_buat_isian_otomatis(): void
    {
        // Isian otomatis rentang di form Tambah Alat mecah baris per SATUAN,
        // jadi `satuan` + `range_min` + `range_max` harus utuh per baris —
        // bukan cuma nama alatnya.
        $kategori = $this->kategoriSuhu();
        $this->baris($kategori, 'Thermocouple', ['range_min' => -20, 'range_max' => 150]);
        $this->baris($kategori, 'Thermocouple', ['range_min' => 150, 'range_max' => 400]);
        $this->baris($kategori, 'Thermocouple', ['range_min' => 400, 'range_max' => 600]);
        $this->baris($kategori, 'Thermohygrometer', [
            'parameter' => 'Suhu', 'range_min' => 15, 'range_max' => 50, 'satuan' => '°C',
        ]);
        $this->baris($kategori, 'Thermohygrometer', [
            'parameter' => 'Kelembapan', 'range_min' => 30, 'range_max' => 90, 'satuan' => '%RH',
        ]);

        $rows = collect(
            $this->actingAs($this->teknisi)
                ->getJson('/api/categories/suhu-dan-kelembapan')
                ->assertOk()
                ->json('data.kemampuan')
        );

        $tc = $rows->where('nama_alat', 'Thermocouple');
        $this->assertCount(3, $tc);
        $this->assertSame([-20.0, 150.0, 400.0], $tc->pluck('range_min')->map(fn ($v) => (float) $v)->values()->all());
        $this->assertSame([150.0, 400.0, 600.0], $tc->pluck('range_max')->map(fn ($v) => (float) $v)->values()->all());
        $this->assertSame(['°C'], $tc->pluck('satuan')->unique()->values()->all());

        // Thermohygro: DUA satuan di satu nama alat. Ini yang bikin isian
        // otomatisnya nggak boleh nggabung rentang jadi 15-90.
        $th = $rows->where('nama_alat', 'Thermohygrometer');
        $this->assertSame(['°C', '%RH'], $th->pluck('satuan')->values()->all());
        $this->assertSame(['Suhu', 'Kelembapan'], $th->pluck('parameter')->values()->all());
    }
}
