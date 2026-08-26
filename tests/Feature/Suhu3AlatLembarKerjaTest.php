<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\PhMeterProfile;
use App\Services\Calibration\Profiles\ProfilSuhuPasangan;
use App\Services\Calibration\Profiles\ThermocoupleProfile;
use App\Services\Calibration\Profiles\ThermohygroProfile;
use App\Services\Calibration\Profiles\ThermometerGlassProfile;
use App\Services\Calibration\Profiles\TidsProfile;
use App\Services\Calibration\Profiles\TitsProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Suhu3AlatMasterTest;

/**
 * Tiga alat suhu ber-PASANGAN deret — jalur API dari ujung ke ujung.
 *
 * Yang dijaga di sini empat hal, dan keempatnya gagal dengan cara yang DIAM:
 *
 *  1. **Alatnya nyasar ke lembar orang lain.** `untukNamaAlat()` fallback-nya
 *     profil pH, jadi ejaan yang meleset satu huruf bikin teknisi dapat lembar
 *     buffer 4/7/10 untuk termokopel — tanpa satu pun error. Yang paling rawan
 *     di sini: kelima nama diawali "Thermo…", dan `Thermocouple` nempel di
 *     tengah `Thermocouple Thermometer`.
 *  2. **Deret STANDAR hilang di jalan.** Payload-nya dua deret; jalur datar
 *     cuma punya tempat buat satu. Kalau `butuhPasanganStandarUut()` nggak
 *     kebaca, yang tersimpan cuma sisi UUT — dan sisi yang hilang justru sisi
 *     KIRI kolom `Correction`.
 *  3. **Sesi yang dikembalikan pulang setengah.** `alat_bantu`,
 *     `tipe_pencelupan`, `titik_es` & `sensor_ke` wajib ikut di respons; tanpa
 *     itu teknisi membuka lembar revisi dengan dryblock kosong lalu memilih
 *     yang pertama di daftar — dan dua komponen budget ikut berubah.
 *  4. **Angka yang dikarang.** Sama seperti TIDS: baris CMC ketiganya SUDAH
 *     ter-seed, jadi jalur CMC generik bakal sukses memulangkan U95 kalau
 *     profilnya nggak menahan waktu syaratnya kurang.
 *
 * @see ProfilSuhuPasangan
 * @see Suhu3AlatMasterTest — adu angkanya ke workbook master
 */
class Suhu3AlatLembarKerjaTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Organization $organisasi;

    protected function setUp(): void
    {
        parent::setUp();

        // ID-nya DIPEGANG, bukan diasumsikan 1: `User::factory()` bikin
        // organisasinya sendiri kalau nggak dikasih, jadi urutan auto-increment
        // di sini nggak dijamin.
        $this->organisasi = Organization::factory()->create();
        $this->teknisi = User::factory()->create(['organization_id' => $this->organisasi->id]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: class-string}>
     */
    public static function tigaAlat(): array
    {
        return [
            'thermocouple' => ['thermocouple', 'Thermocouple', ThermocoupleProfile::class],
            'thermometer_glass' => ['thermometer_glass', 'Termometer Gelas', ThermometerGlassProfile::class],
            'thermohygro' => ['thermohygro', 'Thermohygrometer', ThermohygroProfile::class],
        ];
    }

    #[DataProvider('tigaAlat')]
    public function test_registry_kasih_profilnya_sendiri_bukan_ph(string $kode, string $nama, string $kelas): void
    {
        $registry = app(CalibrationProfileRegistry::class);
        $profil = $registry->untukNamaAlat($nama);

        $this->assertInstanceOf($kelas, $profil);
        $this->assertNotInstanceOf(PhMeterProfile::class, $profil);
        $this->assertSame($kode, $profil->kode());
        $this->assertSame($nama, $profil->namaAlatKemampuan());
    }

    /**
     * Tiga alat baru TIDAK menggeser dua saudara suhunya yang sudah ada.
     *
     * Kelima nama itu berbagi awalan, dan pencocokan registry menerima kunci
     * yang nempel di TENGAH nama. Satu alias yang kepanjangan cukup buat bikin
     * sesi TITS mendarat di lembar termokopel.
     */
    public function test_tits_dan_tids_nggak_ikut_kegeser(): void
    {
        $registry = app(CalibrationProfileRegistry::class);

        $this->assertInstanceOf(TitsProfile::class, $registry->untukNamaAlat('Temperature Indicator tanpa Sensor'));
        $this->assertInstanceOf(TidsProfile::class, $registry->untukNamaAlat('Temperatur Indikator dengan Sensor'));
        // `Thermocouple` nempel di tengah nama alat sesi master.
        $this->assertInstanceOf(ThermocoupleProfile::class, $registry->untukNamaAlat('Thermocouple Thermometer'));
    }

    /**
     * `Hydrometer` (alat DENSITAS) jangan sampai ketarik ke lembar thermohygro.
     *
     * Alias itu sempat didaftarkan dan langsung ditangkap
     * `ProfilDariNamaAlatTest`. Diuji lagi dari sisi ini karena akibatnya
     * berbeda kelas dari sekadar routing salah: teknisi mengisi tabel suhu &
     * %RH untuk alat yang mengukur berat jenis, dan U95-nya terbit berlantai
     * CMC kelembapan.
     */
    public function test_hydrometer_nggak_ketarik_ke_thermohygro(): void
    {
        $registry = app(CalibrationProfileRegistry::class);

        $this->assertNull($registry->kodeProfilDariNama('Hydrometer'));
        $this->assertNull($registry->kodeProfilDariNama('Hydrometer Baume'));
    }

    #[DataProvider('tigaAlat')]
    public function test_lembar_kerja_punya_dua_tabel_pasangan(string $kode, string $nama, string $kelas): void
    {
        $data = $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/lembar-kerja?profil={$kode}")
            ->assertOk()
            ->json('data');

        $tabel = collect($data['bagian'])->flatMap(fn (array $b): array => $b['tabel'] ?? []);

        $this->assertGreaterThanOrEqual(2, $tabel->count(), 'Lembar pasangan wajib punya minimal dua tabel.');
        $this->assertSame(
            $tabel->count(),
            $tabel->pluck('grup')->unique()->count(),
            'Tiap tabel wajib punya `grup` sendiri. `TemplateLembarKerja` mengunci identitas tabel ke '
            .'`grup ?? tahap`, dan kedua tabel ini ber-`tahap` sama — tanpa `grup` yang satu menimpa yang lain '
            .'dan berkas geometri OCR-nya lahir dengan setengah sel.',
        );

        foreach (['standar', 'uut'] as $peran) {
            $this->assertTrue(
                $tabel->contains(fn (array $t): bool => ($t['peran'] ?? null) === $peran),
                "Tabel ber-peran `{$peran}` wajib ada.",
            );
        }

        // Lima pembacaan tiap deret, dan DAFTAR ANGKA — aplikasi teknisi
        // menyaringnya `whereType<num>()`, jadi daftar objek lolos tanpa error
        // tapi menghasilkan nol kolom pembacaan.
        foreach ($tabel as $t) {
            $this->assertSame([1, 2, 3, 4, 5], $t['pengulangan']);
            $this->assertContainsOnly('int', $t['pengulangan']);
        }
    }

    /**
     * Kotak "Environmental Meter Used" wajib BERISI.
     *
     * `field()` memberi `pilihan` nilai bawaan `[]`, jadi kotak bisa lahir
     * lengkap dengan `sumber: master_thermohygro` tanpa satu pun kode yang
     * mengisinya — dan itu bukan error di mana pun. Sudah kejadian di 7 dari 17
     * lembar sekaligus.
     */
    #[DataProvider('tigaAlat')]
    public function test_dropdown_thermohygro_berisi(string $kode, string $nama, string $kelas): void
    {
        foreach (['TH-1', 'TH-3', 'TH-4', 'TH-5', 'TH-7', 'TH-2', 'TH-6'] as $label) {
            Standard::factory()->create([
                'organization_id' => $this->organisasi->id,
                'nama' => $label,
                'serial_number' => $label,
                'parameter_kondisi' => ['suhu' => ['indexed_value' => 20.0, 'correction' => 0.0, 'u95' => 1.7]],
            ]);
        }

        $data = $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/lembar-kerja?profil={$kode}")
            ->assertOk()
            ->json('data');

        $kotak = collect($data['bagian'])
            ->flatMap(fn (array $b): array => $b['field'] ?? [])
            ->firstWhere('kode', 'thermohygro_standard_id');

        $this->assertNotNull($kotak, 'Kotak Environmental Meter Used wajib ada di lembar ini.');
        $this->assertCount(7, $kotak['pilihan'], 'Ketujuh unit TH wajib jadi pilihan — daftar kosong bikin layar jatuh ke cabang teks mati.');

        foreach ($kotak['pilihan'] as $pilihan) {
            $this->assertTrue(
                Standard::whereKey($pilihan['nilai'])->whereNotNull('parameter_kondisi')->exists(),
                'Tiap pilihan wajib menunjuk baris `standards` nyata yang memang thermohygro.',
            );
        }
    }

    /**
     * Kirim sesi Thermocouple lewat API: dua deret tersimpan, angkanya cocok
     * sama master, dan sesinya pulang UTUH.
     */
    public function test_kirim_sesi_thermocouple_simpan_dua_deret_dan_hitung_sesuai_master(): void
    {
        $alat = $this->alat('Thermocouple', 'Thermocouple Thermometer', 0.1, '°C');
        $kalibrator = $this->kalibratorYokogawa();
        $this->cmcThermocouple();

        $payload = [
            'equipment_id' => $alat->id,
            'standard_id' => $kalibrator->id,
            'tanggal_kalibrasi' => '2024-12-03',
            'lokasi' => 'lab',
            'tipe_sensor' => 'Type K',
            'alat_bantu' => 'A',
            'suhu_awal' => 24.5,
            'suhu_akhir' => 24.6,
            'kelembaban_awal' => 61,
            'kelembaban_akhir' => 62,
            'measurements' => [
                ['titik_ukur' => 50, 'no_probe' => 1, 'standar' => [49.5, 49.5, 49.5, 49.5, 49.5], 'uut' => [49.9, 49.9, 49.9, 49.9, 49.9]],
                ['titik_ukur' => 100, 'no_probe' => 2, 'standar' => [99, 99, 99, 99, 99], 'uut' => [99.9, 99.9, 99.9, 99.9, 99.9]],
                ['titik_ukur' => 150, 'no_probe' => 3, 'standar' => [148.6, 148.6, 148.6, 148.6, 148.6], 'uut' => [150.1, 150.1, 150.1, 150.1, 150.1]],
            ],
        ];

        $sesi = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated()
            ->json('data');

        // 3 titik × 5 pembacaan × 2 peran.
        $this->assertSame(30, RawMeasurement::where('calibration_session_id', $sesi['id'])->count());
        $this->assertSame(15, RawMeasurement::where('calibration_session_id', $sesi['id'])->where('peran_sensor', 'standar')->count());
        $this->assertSame(15, RawMeasurement::where('calibration_session_id', $sesi['id'])->where('peran_sensor', 'uut')->count());

        // Nomor probe menumpang `sensor_ke`, dan CUMA di sisi standar.
        $this->assertSame(
            [1, 2, 3],
            RawMeasurement::where('calibration_session_id', $sesi['id'])
                ->where('peran_sensor', 'standar')
                ->distinct()->orderBy('sensor_ke')->pluck('sensor_ke')->map(fn ($v): int => (int) $v)->all(),
        );
        $this->assertSame(
            [null],
            RawMeasurement::where('calibration_session_id', $sesi['id'])
                ->where('peran_sensor', 'uut')
                ->distinct()->pluck('sensor_ke')->all(),
            'Sisi UUT memakai probe bawaan alat pelanggan — `sensor_ke`-nya wajib null.',
        );

        // Angkanya: `SERTIFIKAT!E20:L22` master.
        $titik = collect($sesi['titik'] ?? [])->sortBy('titik_ke')->values();
        $this->assertCount(3, $titik);
        $this->assertEqualsWithDelta(49.275, (float) $titik[0]['titik_ukur'], 5e-6);
        $this->assertEqualsWithDelta(-0.48, (float) $titik[0]['koreksi'], 5e-6);
        $this->assertEqualsWithDelta(-1.255, (float) $titik[2]['koreksi'], 5e-6);
        // Lantai CMC 0,84 menang atas hitungan 0,7686 — dan pita yang dipakai
        // pita BAWAH, karena set point tertingginya tepat 150 °C.
        $this->assertEqualsWithDelta(0.84, (float) $titik[0]['ketidakpastian_diperluas'], 1e-9);

        // Sesi pulang UTUH — tanpa ini teknisi mengisi ulang dari ingatan.
        $this->assertSame('A', $sesi['alat_bantu']);
        $this->assertSame('Type K', $sesi['tipe_sensor']);
    }

    /**
     * Dryblock yang belum dipilih MENAHAN angkanya, bukan diam-diam memakai A.
     *
     * Baris CMC Thermocouple sudah ter-seed, jadi jalur generik akan sukses
     * memulangkan U95 kalau profilnya tidak menahan — dan U95 itu kelihatan
     * wajar.
     */
    public function test_dryblock_kosong_menahan_angkanya_dengan_alasan_yang_kebaca(): void
    {
        $alat = $this->alat('Thermocouple', 'Thermocouple Thermometer', 0.1, '°C');
        $kalibrator = $this->kalibratorYokogawa();
        $this->cmcThermocouple();

        $hasil = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', [
                'equipment_id' => $alat->id,
                'standard_id' => $kalibrator->id,
                'tanggal_kalibrasi' => '2024-12-03',
                'tipe_sensor' => 'Type K',
                // `alat_bantu` SENGAJA nggak dikirim.
                'measurements' => [
                    ['titik_ukur' => 50, 'no_probe' => 1, 'standar' => [49.5, 49.5, 49.5, 49.5, 49.5], 'uut' => [49.9, 49.9, 49.9, 49.9, 49.9]],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame([], $hasil['titik'] ?? [], 'Nggak boleh ada satu pun angka yang terbit.');
        $this->assertNotEmpty($hasil['belum_dihitung'] ?? [], 'Alasannya wajib kebaca, bukan diam.');
        $this->assertStringContainsString('Dryblock', $hasil['belum_dihitung'][0]['alasan']);
    }

    private function alat(string $namaKemampuan, string $namaAlat, float $resolusi, string $satuan): Equipment
    {
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $this->organisasi->id, 'nama' => 'Suhu dan Kelembapan']);
        $pelanggan = Customer::factory()->create(['organization_id' => $this->organisasi->id]);

        return Equipment::factory()->create([
            'organization_id' => $this->organisasi->id,
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => $namaAlat,
            'nama_alat_kemampuan' => $namaKemampuan,
            'satuan' => $satuan,
            'resolusi' => $resolusi,
            'toleransi' => null,
            'range_min' => 0,
            'range_max' => 600,
        ]);
    }

    private function kalibratorYokogawa(): Standard
    {
        return Standard::factory()->create([
            'organization_id' => $this->organisasi->id,
            'nama' => 'Temperature Calibrator Yokogawa CA 150 Handy Cal',
            // Kolom `merk` yang MENENTUKAN tabel koreksi mana yang dibaca.
            'merk' => 'Yokogawa',
            'serial_number' => '23P1005',
            'parameter_kondisi' => null,
            'berlaku_sampai' => now()->addYear(),
        ]);
    }

    private function cmcThermocouple(): void
    {
        foreach ([[-20, 150, 0.84], [150, 400, 1.5], [400, 600, 3.3]] as [$min, $max, $u]) {
            CalibrationCapability::factory()->create([
                'organization_id' => $this->organisasi->id,
                'nama_alat' => 'Thermocouple',
                'parameter' => null,
                'range_min' => $min,
                'range_max' => $max,
                'satuan' => '°C',
                'ketidakpastian_terbaik' => $u,
                'satuan_ketidakpastian' => '°C',
                'faktor_cakupan' => 2,
            ]);
        }
    }
}
