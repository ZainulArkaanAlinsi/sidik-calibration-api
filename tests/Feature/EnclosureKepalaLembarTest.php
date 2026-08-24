<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kepala lembar kerja Enclosure — `SIDIK-FM-CAL-0504 Rev.3`.
 *
 * ## Kenapa test ini ada, dan kenapa yang pertama diuji justru satu kotak
 *
 * Sampai 24 Agustus 2026, bentuk lembar enclosure cuma punya SATU bagian berisi
 * DUA kotak: tipe sensor dan lokasi. Nggak ada satu pun tempat memilih alatnya.
 *
 * Sementara di HP, tombol kirim nahan kalau alat belum dipilih:
 *
 *     if (_isian.alat == null) { ...tolak...; return; }
 *
 * Jadi sesi enclosure baru **nggak bisa dikirim sama sekali** — lima jenis alat
 * (oven, furnace, bath, inkubator, refrigerator) yang mesin hitungnya sudah
 * selesai dan sudah diadu ke master sampai digit terakhir, tapi jalannya buntu
 * di kotak yang nggak ada.
 *
 * Yang bikin ini lolos berbulan-bulan: nggak ada yang gagal. Test mesin
 * hitungnya hijau, test bentuk lembarnya hijau, dan endpoint-nya balik 200.
 * Yang rusak cuma "apa yang bisa dikerjakan orang", dan itu nggak punya test.
 *
 * Karena itu test ini bukan soal kelengkapan formulir — dia penjaga supaya
 * jalan masuknya nggak ketutup lagi tanpa ada yang sadar.
 */
class EnclosureKepalaLembarTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    /** Kelima profil enclosure — kepala lembarnya sama, bedanya cuma CMC. */
    public static function profilEnclosure(): array
    {
        return [
            'oven' => ['oven'],
            'furnace' => ['furnace'],
            'bath' => ['bath'],
            'inkubator' => ['inkubator'],
            'refrigerator' => ['refrigerator'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fieldSemua(string $profil): array
    {
        $bentuk = app(CalibrationProfileRegistry::class)
            ->untukKode($profil)
            ->bentukLembarKerja();

        $field = [];
        foreach ($bentuk['bagian'] ?? [] as $bagian) {
            foreach ($bagian['field'] ?? [] as $f) {
                $field[] = $f;
            }
        }

        return $field;
    }

    private function kodeField(string $profil): array
    {
        return array_column($this->fieldSemua($profil), 'kode');
    }

    // ------------------------------------------------------------ BLOKER

    /**
     * INTI: tanpa `equipment_id`, sesi enclosure nggak bisa dikirim sama sekali.
     */
    #[DataProvider('profilEnclosure')]
    public function test_lembar_enclosure_punya_kotak_pilih_alat(string $profil): void
    {
        $this->assertContains(
            'equipment_id',
            $this->kodeField($profil),
            "Profil {$profil} nggak punya kotak `equipment_id`. Tombol kirim di HP "
            .'nahan kalau alat belum dipilih, jadi sesi alat ini nggak akan pernah '
            .'bisa dikirim — fitur mati, bukan formulir yang kurang lengkap.',
        );
    }

    /** Kotak alatnya harus narik dari master alat, bukan diketik bebas. */
    public function test_kotak_alat_narik_dari_master(): void
    {
        $alat = collect($this->fieldSemua('oven'))->firstWhere('kode', 'equipment_id');

        $this->assertSame('pilihan', $alat['tipe']);
        $this->assertSame('master_alat', $alat['sumber']);
    }

    // ------------------------------------------------- isi kepala lembar

    /**
     * Kotak yang tercetak di `SIDIK-FM-CAL-0504 Rev.3` harus ada semua.
     *
     * Diadu ke daftar dari PDF-nya langsung, bukan dari ingatan.
     */
    #[DataProvider('profilEnclosure')]
    public function test_kepala_lembar_ikut_formulir_resmi(string $profil): void
    {
        $ada = $this->kodeField($profil);

        $wajibAda = [
            // Identitas alat & customer
            'alat_merk', 'alat_model', 'alat_serial_number',
            'spesifikasi_alat.rentang_ukur', 'spesifikasi_alat.kapasitas',
            'spesifikasi_alat.resolusi',
            'tanggal_terima', 'tanggal_kalibrasi',
            'pemilik_nama', 'pemilik_alamat',
            // Kondisi lingkungan — yang HIDUP, kecetak di sertifikat
            'suhu_awal', 'suhu_akhir', 'kelembaban_awal', 'kelembaban_akhir',
            'thermohygro_standard_id',
            // Dimensi alat
            'dimensi_panjang', 'dimensi_lebar', 'dimensi_tinggi',
            'dimensi_jari_jari', 'dimensi.volume',
            'persyaratan_alat',
            // Penutup
            'catatan_teknisi',
        ];

        foreach ($wajibAda as $kode) {
            $this->assertContains($kode, $ada, "Profil {$profil} kehilangan kotak `{$kode}` yang tercetak di formulir.");
        }
    }

    /**
     * Volume DIHITUNG, bukan diketik.
     *
     * Di master: balok `P × L × T`, silinder `π · r² · t`, dalam meter, tanpa
     * satu pun faktor konversi. Kalau kotaknya dibikin bisa diketik, angkanya
     * bisa nggak cocok sama P/L/T di sebelahnya — dan nggak ada yang tahu mana
     * yang bener.
     */
    public function test_volume_otomatis_bukan_diketik(): void
    {
        $volume = collect($this->fieldSemua('oven'))->firstWhere('kode', 'dimensi.volume');

        $this->assertSame('otomatis', $volume['sumber']);
        $this->assertSame('m³', $volume['satuan']);
    }

    /** Tiga baris "Standar used" tercetak apa adanya dari kertasnya. */
    public function test_standar_tercetak_tiga_baris(): void
    {
        $bentuk = app(CalibrationProfileRegistry::class)
            ->untukKode('oven')
            ->bentukLembarKerja();

        $usage = collect($bentuk['bagian'])->firstWhere('kode', 'usage_check');

        $this->assertCount(3, $usage['baris']);
        $this->assertStringContainsString('Constant', $usage['baris'][0]['label']);
        $this->assertStringContainsString('Graptech', $usage['baris'][2]['label']);
    }

    /**
     * Kotak lokasi tetap ikut aturan tampil-bersyarat, bukan tampil dua-duanya.
     *
     * Bug sertifikat Insitu lahirnya persis dari sini: dropdown Ruangan tetap
     * nyimpen pilihan lama walau lagi Insitu.
     */
    public function test_kotak_lokasi_bersyarat(): void
    {
        $field = collect($this->fieldSemua('oven'));

        $this->assertSame(
            ['onsite'],
            $field->firstWhere('kode', 'lokasi_nama')['tampil_kalau']['nilai'],
        );
        $this->assertSame(
            ['lab'],
            $field->firstWhere('kode', 'room_id')['tampil_kalau']['nilai'],
        );
    }

    /** Endpoint lembar kerja beneran ngirim bentuk barunya, bukan cuma kelasnya. */
    public function test_endpoint_lembar_kerja_ngirim_kepala_lembar(): void
    {
        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=oven')
            ->assertOk()
            ->assertJsonPath('data.bagian.0.kode', 'identitas_alat');
    }
}
