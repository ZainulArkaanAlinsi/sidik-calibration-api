<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\TidsProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Coba TEMBUS blokir U95 TIDS lewat routing — bukan lewat rumusnya.
 *
 * `TidsU95TidakBocorTest` menjaga jalur SESUDAH profilnya kepilih. Berkas ini
 * menyerang satu lapis sebelumnya: bikin sebuah alat TIDS mendarat di profil
 * LAIN, supaya rumus alat lain yang menghitungnya. Kalau itu berhasil, blokir
 * di `TidsProfile` nggak pernah kepanggil sama sekali — dan U95 yang terbit
 * lahir dari rumus alat yang beda, bukan dari rumus TIDS yang memang belum ada.
 *
 * ## Kenapa serangan ini masuk akal, bukan teoretis
 *
 * `nama_alat_kemampuan` itu TEKS BEBAS yang disalin dari lampiran akreditasi,
 * bukan enum. Yang mengisinya manusia, dan lampirannya sendiri nulis dua nama
 * bersaudara dalam dua bahasa: `Temperature Indicator tanpa Sensor` (Inggris)
 * dan `Temperatur Indikator dengan Sensor` (Indonesia, "dengan" huruf kecil).
 * Jadi ejaan yang meleset itu keadaan normal, bukan kejahatan.
 *
 * Dan kalau melesetnya bikin alat jatuh ke profil lain, nggak ada satu pun
 * error yang muncul: teknisi ngisi lembar yang salah, sertifikatnya terbit,
 * dan yang nemu belakangan asesor.
 */
class TidsRoutingTidakBisaDitembusTest extends TestCase
{
    use RefreshDatabase;

    /** Ejaan yang MENGIKAT — persis lampiran akreditasi LK-285-IDN no. 2. */
    private const NAMA_LAMPIRAN = 'Temperatur Indikator dengan Sensor';

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();
    }

    /**
     * Ejaan yang HARUS tetap mendarat di TIDS.
     *
     * Semuanya bentuk yang wajar diketik manusia yang menyalin dari lampiran
     * akreditasi atau dari faktur pelanggan.
     *
     * @return array<string, array{string}>
     */
    public static function ejaanYangTetapTids(): array
    {
        return [
            'persis lampiran' => [self::NAMA_LAMPIRAN],
            'huruf kecil semua' => ['temperatur indikator dengan sensor'],
            'HURUF BESAR SEMUA' => ['TEMPERATUR INDIKATOR DENGAN SENSOR'],
            'campur' => ['Temperatur INDIKATOR Dengan Sensor'],
            'spasi dobel' => ['Temperatur  Indikator   dengan Sensor'],
            'spasi di ujung' => ['  Temperatur Indikator dengan Sensor  '],
            'nempel nama pelanggan' => ['Temperatur Indikator dengan Sensor Fluke 1524'],
            'didahului merk' => ['Fluke Temperatur Indikator dengan Sensor'],
        ];
    }

    private function alat(string $namaKemampuan): Equipment
    {
        return Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'suhu-dan-kelembapan'])->id,
            'nama_alat' => 'Temperature Indicator',
            'nama_alat_kemampuan' => $namaKemampuan,
            'range_min' => -20, 'range_max' => 600,
            'satuan' => '°C', 'resolusi' => 0.1, 'toleransi' => null,
        ]);
    }

    /**
     * Diseed SENGAJA: baris CMC TIDS yang ada bikin jalur generik SUKSES
     * memulangkan U95 kalau alatnya sampai jatuh ke profil lain.
     */
    private function seedCmcTids(): void
    {
        foreach ([[-20.0, 150.0, 0.86], [150.0, 400.0, 1.4], [400.0, 600.0, 3.1]] as [$min, $maks, $u]) {
            CalibrationCapability::factory()->create([
                'nama_alat' => self::NAMA_LAMPIRAN,
                'range_min' => $min,
                'range_max' => $maks,
                'satuan' => '°C',
                'ketidakpastian_terbaik' => $u,
                'satuan_ketidakpastian' => '°C',
                'metode' => TidsProfile::KODE_METODE,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Equipment $alat, Standard $standar, array $tambahan = []): array
    {
        return array_merge([
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_ruang' => 23.5,
            'kelembaban' => 55.0,
            'measurements' => [
                ['titik_ukur' => 100.0, 'satuan' => '°C', 'pembacaan' => [100.1, 100.2, 100.0, 100.1, 100.2]],
            ],
        ], $tambahan);
    }

    /**
     * Delapan ejaan wajar, delapan-delapannya harus nol baris U95.
     *
     * Satu angka lolos di sini artinya alatnya mendarat di profil lain, dan
     * rumus alat lain yang ngitung suhu — tanpa satu pun error.
     */
    #[DataProvider('ejaanYangTetapTids')]
    public function test_ejaan_wajar_tetap_ketahan_nol_u95(string $ejaan): void
    {
        $alat = $this->alat($ejaan);
        $standar = Standard::factory()->create();
        $this->seedCmcTids();

        // Pertama: alatnya beneran mendarat di TIDS. Tanpa baris ini "nol U95"
        // bisa benar karena alasan yang salah — ejaan yang jatuh ke
        // `ProfilGenerik` juga nol, dan itu kegagalan yang beda sama sekali.
        $this->assertInstanceOf(
            TidsProfile::class,
            app(CalibrationProfileRegistry::class)->untukAlat($alat),
            "Ejaan `{$ejaan}` nggak mendarat di TIDS — teknisi bakal dapat lembar alat lain.",
        );

        // Dan lembar yang dikirim ke HP ikut sepakat.
        $this->assertSame(
            TidsProfile::KODE_DOKUMEN,
            $this->actingAs($this->teknisi)
                ->getJson("/api/calibrations/lembar-kerja?equipment_id={$alat->id}")
                ->assertOk()
                ->json('data.kode_dokumen'),
        );

        // Baru sesudah itu: nol baris U95.
        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, $standar))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            0,
            CalibrationSession::findOrFail($id)->uncertaintyCalculations()->count(),
            "Ejaan `{$ejaan}` nembus blokir TIDS — alatnya mendarat di profil lain dan "
            .'rumus alat lain yang ngitung suhu.',
        );
    }

    /**
     * `?profil=` NGGAK boleh ngalahin alat yang ditunjuk.
     *
     * Kalau parameter itu menang, siapa pun yang bisa mengirim request bisa
     * memilih rumus mana yang menghitung alat orang lain — dan `profil=ph`
     * pada alat TIDS bakal SUKSES menerbitkan U95, karena pH punya budget
     * lengkap.
     */
    public function test_parameter_profil_nggak_ngalahin_alat_yang_ditunjuk(): void
    {
        $alat = $this->alat(self::NAMA_LAMPIRAN);
        $standar = Standard::factory()->create();
        $this->seedCmcTids();

        // Lembar kerjanya: minta pH, tapi alatnya TIDS.
        $bentuk = $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/lembar-kerja?profil=ph&equipment_id={$alat->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(
            TidsProfile::KODE_DOKUMEN,
            $bentuk['kode_dokumen'],
            '`?profil=ph` ngalahin alat TIDS — teknisi dapat lembar pH buat alat suhu.',
        );

        // Dan jalur simpannya ikut: tetap nol U95 walau `profil` dipaksa.
        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, $standar, ['profil' => 'ph']))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            0,
            CalibrationSession::findOrFail($id)->uncertaintyCalculations()->count(),
            '`profil=ph` di badan request nembus blokir TIDS.',
        );
    }

    /**
     * Lembar kerja & rumus yang menghitung WAJIB sepakat.
     *
     * Ini invariant yang paling mahal kalau lepas: teknisi mengisi lembar satu
     * alat sementara server menghitung pakai aturan alat lain, dan nggak ada
     * satu pun error di sepanjang jalur itu. Dijaga dari dua ujungnya — bentuk
     * lembar yang dikirim ke HP, dan jumlah baris U95 yang lahir.
     */
    public function test_lembar_yang_dikirim_dan_rumus_yang_ngitung_sepakat(): void
    {
        $alat = $this->alat(self::NAMA_LAMPIRAN);
        $standar = Standard::factory()->create();
        $this->seedCmcTids();

        $bentuk = $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/lembar-kerja?equipment_id={$alat->id}")
            ->assertOk()
            ->json('data');

        // Yang dikirim ke HP: lembar TIDS.
        $this->assertSame(TidsProfile::KODE_DOKUMEN, $bentuk['kode_dokumen']);

        // Dan lembar itu ngaku sendiri bahwa budget-nya belum ada — jadi
        // teknisi tau SEBELUM ngisi, bukan sesudah sertifikatnya terbit.
        $ngaku = json_encode($bentuk);
        $this->assertIsString($ngaku);
        $this->assertStringContainsString('ketidakpastian', mb_strtolower($ngaku));

        // Yang ngitung: rumus TIDS, yang menolak. Nol baris.
        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, $standar))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(0, CalibrationSession::findOrFail($id)->uncertaintyCalculations()->count());
    }

    /**
     * Saudaranya JANGAN ikut ketahan.
     *
     * TITS (`tanpa Sensor`) punya budget lengkap dan MEMANG harus menerbitkan
     * U95. Kalau blokir TIDS kelebaran sampai kena TITS, yang rusak bukan
     * angka karangan tapi sebaliknya: alat yang sah berhenti bisa disertifikasi,
     * dan itu kelihatan sebagai "aplikasinya rusak", bukan sebagai penjagaan.
     *
     * Dua nama itu cuma beda satu kata, dan pencocokannya nerima kunci yang
     * nempel di tengah nama — jadi jarak antara keduanya lebih tipis dari
     * kelihatannya.
     */
    public function test_tits_nggak_ikut_ketahan(): void
    {
        $alat = $this->alat('Temperature Indicator tanpa Sensor');

        $bentuk = $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/lembar-kerja?equipment_id={$alat->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(
            'SIDIK-FM-CAL-0505_Rev.3',
            $bentuk['kode_dokumen'],
            'Blokir TIDS kelebaran sampai kena TITS — alat yang sah berhenti bisa disertifikasi.',
        );
    }
}
