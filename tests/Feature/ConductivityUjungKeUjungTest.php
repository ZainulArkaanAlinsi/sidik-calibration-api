<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\Profiles\ConductivityProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Conductivity dari LEMBAR KERJA sampai SERTIFIKAT, lewat API beneran.
 *
 * `ConductivityBudgetTest` sudah mengunci angkanya, tapi lewat pintu belakang:
 * dia memanggil `GumCalculator::hitungTitik()` langsung dengan array yang
 * disusun tangan. Yang belum pernah diuji utuh justru jalan yang beneran
 * dipakai orang:
 *
 *   teknisi ngisi lembar → POST /api/calibrations → hitung otomatis →
 *   admin approve → sertifikat terbit
 *
 * Dan di situlah kesalahan yang paling mahal bisa lewat: angka yang benar
 * mendarat di titik yang salah. Perhitungannya sendiri tetap benar, jadi nggak
 * ada yang merah — sertifikatnya saja yang salah. Persis yang kejadian di sesi
 * 53 (12 Agt 2026), waktu `1413` mendarat di baris `1,412 mS/cm` dan koreksinya
 * keluar `1411,588`.
 *
 * Angka pembandingnya dari `Master Olah Data_Conductivity.xlsm` job
 * `0189-CAL-524` (order `2405.32.A.NK`) — sheet `INPUT DATA` buat masukannya,
 * `PERHITUNGAN U95%` buat hasilnya.
 */
class ConductivityUjungKeUjungTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Isi lembar kerja apa adanya, sheet `INPUT DATA` baris 37–43.
     *
     * Sengaja disusun seperti yang DIKETIK TEKNISI — per titik, lima
     * pengulangan, pembacaan dan suhu berpasangan — bukan sebagai rata-rata
     * yang sudah jadi. Rata-rata dihitung di jalan; kalau tes ini menyuapkan
     * rata-rata, bagian yang paling gampang salah justru dilewati.
     *
     * @var list<array{titik: float, satuan: string, pembacaan: list<float>, suhu: list<float>}>
     */
    private const LEMBAR = [
        [
            'titik' => 25.0,
            'satuan' => 'µS/cm',
            'pembacaan' => [25, 25, 25, 25.1, 25.1],
            'suhu' => [25.2, 25.2, 25.2, 25.3, 25.2],
        ],
        [
            'titik' => 1412.0,
            'satuan' => 'µS/cm',
            'pembacaan' => [1413, 1413, 1413, 1413, 1413],
            'suhu' => [25, 24.9, 24.9, 24.9, 24.9],
        ],
        [
            'titik' => 111.0,
            'satuan' => 'mS/cm',
            'pembacaan' => [110.69, 110.67, 110.67, 110.67, 110.67],
            'suhu' => [25.2, 25.2, 25.2, 25.2, 25.2],
        ],
    ];

    /**
     * Yang harus keluar di ujung, sheet `PERHITUNGAN U95%` + `SERTIFIKAT`.
     *
     * `standar` = nilai acuan SESUDAH koreksi suhu. Cuma titik ketiga yang
     * bergeser (111 → 111,193568); dua yang pertama dikunci konstan atas
     * arahan lab 11 Agt 2026.
     *
     * @var list<array{titik: float, standar: float, rata: float, koreksi: float, u95: float}>
     */
    private const HASIL = [
        ['titik' => 25.0, 'standar' => 25.0, 'rata' => 25.04, 'koreksi' => -0.03999999999999915, 'u95' => 0.49948652157359164],
        ['titik' => 1412.0, 'standar' => 1412.0, 'rata' => 1413.0, 'koreksi' => -1.0, 'u95' => 8.109011947857544],
        ['titik' => 111.0, 'standar' => 111.193568, 'rata' => 110.674, 'koreksi' => 0.5195679999999925, 'u95' => 1.7],
    ];

    /**
     * Sama alasannya dengan `ConductivityBudgetTest`: kolom hasilnya
     * `decimal(20, 8)`, jadi 1e-8 itu sehalus-halusnya yang bisa dijanjikan
     * database. Toleransi yang lebih ketat cuma lolos di SQLite.
     */
    private const TOLERANSI = 1e-8;

    private Equipment $alat;

    private User $teknisi;

    private User $admin;

    /** @var array<int, Standard> */
    private array $standar = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $org->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $org->id]);

        $kategori = EquipmentCategory::factory()->create(['organization_id' => $org->id]);
        $uTemperature = sqrt(0.36 ** 2 + 0.03 ** 2);

        $spek = [
            ['nama' => 'Conductivity Std Solution 25 µS/cm', 'u' => 0.5, 'satuan' => 'µS/cm', 'koef' => '25', 'titik' => 25, 'cmc' => 0.41],
            ['nama' => 'Conductivity Std Solution 1412 µS/cm', 'u' => 8.0, 'satuan' => 'µS/cm', 'koef' => '1412', 'titik' => 1412, 'cmc' => 5.1],
            ['nama' => 'Conductivity Std Solution 111 mS/cm', 'u' => 1.6, 'satuan' => 'mS/cm', 'koef' => '111', 'titik' => 111, 'cmc' => 1.7],
        ];

        foreach ($spek as $i => $s) {
            $this->standar[$i] = Standard::factory()->create([
                'organization_id' => $org->id,
                'nama' => $s['nama'],
                'ketidakpastian' => $s['u'],
                'satuan_ketidakpastian' => $s['satuan'],
                'faktor_cakupan' => 2,
                'koefisien_suhu' => ConductivityProfile::KOEF_SUHU[$s['koef']],
                'parameter_kondisi' => null,
            ]);

            CalibrationCapability::create([
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Conductivitymeter',
                'range_min' => $s['titik'],
                'range_max' => $s['titik'],
                'parameter' => 'Conductivity',
                'satuan' => $s['satuan'],
                'ketidakpastian_terbaik' => $s['cmc'],
                'satuan_ketidakpastian' => $s['satuan'],
                'faktor_cakupan' => 2,
                'u_temperature' => $uTemperature,
                'ci_suhu' => null,
                'u_perbedaan_suhu' => null,
                'ci_perbedaan_suhu' => null,
            ]);
        }

        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Conductivity Meter',
            'nama_alat_kemampuan' => 'Conductivitymeter',
            'satuan' => 'mS/cm',
            'resolusi' => 0.01,
            'toleransi' => null,
            'resolusi_rentang' => [
                ['titik' => 25, 'satuan' => 'µS/cm', 'resolusi' => 0.1],
                ['titik' => 1412, 'satuan' => 'µS/cm', 'resolusi' => 1],
                ['titik' => 111, 'satuan' => 'mS/cm', 'resolusi' => 0.01],
            ],
        ]);
    }

    /**
     * Kirim lembar kerja seperti aplikasi HP mengirimkannya.
     *
     * @param  list<array<string, mixed>>|null  $measurements
     */
    private function kirimLembar(?array $measurements = null): CalibrationSession
    {
        $measurements ??= array_map(
            fn (array $b, int $i): array => [
                'titik_ukur' => $b['titik'],
                'satuan' => $b['satuan'],
                'standard_id' => $this->standar[$i]->id,
                'pembacaan_sebelum' => $b['pembacaan'],
                'suhu_sebelum' => $b['suhu'],
                'pembacaan' => $b['pembacaan'],
                'suhu' => $b['suhu'],
            ],
            self::LEMBAR,
            array_keys(self::LEMBAR),
        );

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->alat->id,
                'standard_id' => $this->standar[0]->id,
                'tanggal_kalibrasi' => '2024-05-13T00:00:00Z',
                'suhu_awal' => 25.4,
                'suhu_akhir' => 25.5,
                'kelembaban_awal' => 54,
                'kelembaban_akhir' => 55,
                'measurements' => $measurements,
            ])
            ->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    /**
     * Inti berkas ini: lembar kerja masuk lewat API, hasilnya sama dengan
     * master sampai kuantum simpan.
     */
    public function test_lembar_kerja_lewat_api_menghasilkan_angka_master(): void
    {
        $sesi = $this->kirimLembar();

        $titik = $sesi->uncertaintyCalculations->sortBy('titik_ke')->values();

        $this->assertCount(3, $titik, 'Tiga titik harus tercatat, satu per larutan.');

        foreach (self::HASIL as $i => $h) {
            $this->assertEqualsWithDelta(
                $h['standar'],
                (float) $titik[$i]->titik_ukur,
                self::TOLERANSI,
                "Nilai acuan titik ke-{$i} meleset dari master.",
            );

            $this->assertEqualsWithDelta(
                $h['rata'],
                (float) $titik[$i]->rata_rata,
                self::TOLERANSI,
                "Rata-rata pembacaan titik ke-{$i} meleset dari master.",
            );

            $this->assertEqualsWithDelta(
                $h['koreksi'],
                (float) $titik[$i]->koreksi,
                self::TOLERANSI,
                "Koreksi titik ke-{$i} meleset dari master.",
            );

            $this->assertEqualsWithDelta(
                $h['u95'],
                (float) $titik[$i]->ketidakpastian_diperluas,
                self::TOLERANSI,
                "U95% titik ke-{$i} meleset dari master.",
            );
        }
    }

    /**
     * Penjaga yang paling penting di berkas ini, dan yang paling nggak
     * kelihatan kalau nggak ditulis: angka mendarat di titik yang BENAR.
     *
     * Sesi 53 kejeblos persis di sini — `1413` masuk ke baris `1,412 mS/cm`.
     * Hitungannya tetap jalan, hasilnya tetap keluar, dan yang salah cuma
     * angkanya. Jadi yang diuji bukan "ada tiga titik", tapi "titik yang
     * pembacaannya 1413 itu titik yang nilai acuannya 1412".
     */
    public function test_pembacaan_mendarat_di_titik_yang_benar(): void
    {
        $sesi = $this->kirimLembar();

        foreach ($sesi->uncertaintyCalculations as $t) {
            $acuan = (float) $t->titik_ukur;
            $rata = (float) $t->rata_rata;

            // Dibandingkan RELATIF, bukan selisih mutlak: tiga titik ini beda
            // sampai tiga orde besaran, jadi ambang mutlak yang masuk akal buat
            // titik 1412 justru meloloskan apa saja di titik 25.
            $this->assertLessThan(
                0.05,
                abs($rata - $acuan) / max(abs($acuan), 1e-9),
                sprintf(
                    'Pembacaan %s nyasar ke titik %s — beda lebih dari 5%%, '
                    .'tanda kolomnya ketuker, bukan alatnya melenceng.',
                    $rata,
                    $acuan,
                ),
            );
        }
    }

    /**
     * Titik yang kolom suhunya kosong TIDAK boleh jatuh ke polinomial pada T=0.
     *
     * Ini bug yang beneran kecetak di master: nilai acuan titik 1,412 mS/cm
     * dievaluasi pada T=0 waktu kolom suhunya kosong, keluar `0,738 mS/cm` —
     * bukan error, angka yang kelihatan wajar, dan ikut tercetak di sertifikat
     * (baris 40 `07 - SERTIFIKAT STYLE 1.csv`).
     *
     * Sistem menghindarinya bukan dengan menolak titiknya, tapi dengan jatuh ke
     * nilai NOMINAL: `Standard::nilaiPadaSuhu(null)` balik `null`, dan
     * `GumCalculator` baris 140 memakai `$titikUkur` apa adanya. Jadi titiknya
     * tetap dihitung, cuma tanpa geseran suhu — dan `0,738` nggak pernah bisa
     * muncul. Yang dikunci di sini perilaku itu, bukan penolakannya.
     */
    public function test_titik_tanpa_suhu_tidak_jatuh_ke_polinomial_t_nol(): void
    {
        $tanpaSuhu = array_map(
            fn (array $b, int $i): array => [
                'titik_ukur' => $b['titik'],
                'satuan' => $b['satuan'],
                'standard_id' => $this->standar[$i]->id,
                'pembacaan' => $b['pembacaan'],
                // Titik tengah dikirim TANPA suhu, dua yang lain normal.
                'suhu' => $i === 1 ? [] : $b['suhu'],
            ],
            self::LEMBAR,
            array_keys(self::LEMBAR),
        );

        $sesi = $this->kirimLembar($tanpaSuhu);

        $titik = $sesi->uncertaintyCalculations->sortBy('titik_ke')->values();

        $this->assertCount(3, $titik, 'Satu titik kurang data nggak boleh menjatuhkan lembar.');

        // Titik tengah: nominal 1412, BUKAN 738 (polinomial `2e-13·T² + 27·T +
        // 738` pada T=0) dan bukan 0,738 versi mS/cm-nya.
        $this->assertEqualsWithDelta(
            1412.0,
            (float) $titik[1]->titik_ukur,
            self::TOLERANSI,
            'Nilai acuan titik tengah nggak jatuh ke nominal waktu suhunya kosong.',
        );

        // Dua titik yang suhunya lengkap sama sekali nggak ketularan.
        $this->assertEqualsWithDelta(25.0, (float) $titik[0]->titik_ukur, self::TOLERANSI);
        $this->assertEqualsWithDelta(111.193568, (float) $titik[2]->titik_ukur, self::TOLERANSI);
    }

    /**
     * Conductivity nggak divonis lulus/gagal — master nggak punya satu pun sel
     * yang membandingkan hasil dengan batas keberterimaan.
     */
    public function test_tidak_ada_vonis_lulus_gagal_di_sesi_nyata(): void
    {
        $sesi = $this->kirimLembar();

        foreach ($sesi->uncertaintyCalculations as $t) {
            $this->assertNull($t->keputusan, 'Titik conductivity nggak boleh punya vonis.');
        }
    }

    /**
     * Sertifikat terbit, dan angkanya masih angka yang sama.
     *
     * Snapshot inilah yang dicetak dan dikirim ke pelanggan; kalau ada yang
     * bergeser antara hitungan dan snapshot, di sinilah ketahuannya.
     */
    public function test_sertifikat_terbit_dengan_angka_yang_sama(): void
    {
        $sesi = $this->kirimLembar();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $sertifikat = $sesi->certificate()->firstOrFail();
        $snapshot = $sertifikat->snapshot ?? [];

        $this->assertNotEmpty($snapshot, 'Sertifikat terbit tanpa snapshot.');

        $baris = $snapshot['hasil'] ?? [];

        $this->assertCount(
            3,
            $baris,
            'Tabel sertifikat harus punya tiga baris, satu per larutan.',
        );

        foreach (self::HASIL as $i => $h) {
            $nilai = $baris[$i];

            $this->assertEqualsWithDelta(
                $h['standar'],
                (float) $nilai['standard_value'],
                self::TOLERANSI,
                "Standard Value baris ke-{$i} di sertifikat beda dari hasil hitung.",
            );

            $this->assertEqualsWithDelta(
                $h['rata'],
                (float) $nilai['unit_under_test'],
                self::TOLERANSI,
                "Unit Under Test baris ke-{$i} di sertifikat beda dari hasil hitung.",
            );

            $this->assertEqualsWithDelta(
                $h['koreksi'],
                (float) $nilai['correction'],
                self::TOLERANSI,
                "Koreksi baris ke-{$i} di sertifikat beda dari hasil hitung.",
            );

            $this->assertEqualsWithDelta(
                $h['u95'],
                (float) $nilai['u95'],
                self::TOLERANSI,
                "U95% baris ke-{$i} di sertifikat beda dari hasil hitung.",
            );
        }
    }

    /**
     * Yang KECETAK di kertas, bukan yang tersimpan.
     *
     * Pembulatannya beda per titik — `0.0` / `0` / `0.00` — dan itu format sel
     * master, bukan konvensi metrologi yang bisa dinalar. Kolom Correction
     * titik 25 kecetak `0,0` TANPA minus walau nilai simpannya negatif;
     * conductivity satu-satunya alat yang begitu.
     *
     * Ini lapisan yang paling gampang meleset tanpa ketahuan: angka simpannya
     * benar semua, yang salah cuma tampilannya, dan yang dilihat pelanggan
     * justru tampilannya.
     */
    public function test_desimal_cetak_ikut_master(): void
    {
        $sesi = $this->kirimLembar();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $baris = $sesi->certificate()->firstOrFail()->snapshot['hasil'];

        $this->assertSame([1, 0, 2], array_map(
            fn (array $b): int => (int) $b['desimal'],
            $baris,
        ), 'Desimal cetak per titik nggak sama dengan format sel master.');

        // Koreksi yang membulat ke nol dicetak tanpa tanda minus.
        $this->assertFalse(
            (bool) $baris[0]['tanda_nol'],
            'Conductivity nyetak koreksi nol TANPA minus — kebalikan empat alat lain.',
        );

        // Faktor cakupan yang dibekukan ke snapshot itu nilai HITUNGnya, dan
        // itu memang yang dicetak master per blok titik: sel di bawah tabel
        // `07 - SERTIFIKAT STYLE 1.csv` baris 36 & 42 berisi
        // `1.9713247932433295` dan `1.9717188484634527`. Angka bulat `2` yang
        // muncul di baris 31 itu kalimat kepala sertifikat, bukan k per titik —
        // dua-duanya ada di dokumen yang sama.
        $this->assertEqualsWithDelta(
            1.9713247932433295,
            (float) $baris[0]['faktor_cakupan_k'],
            self::TOLERANSI,
            'k titik 25 µS/cm beda dari sel master.',
        );

        $this->assertEqualsWithDelta(
            1.9717188484634527,
            (float) $baris[2]['faktor_cakupan_k'],
            self::TOLERANSI,
            'k titik 111 mS/cm beda dari sel master.',
        );
    }
}
