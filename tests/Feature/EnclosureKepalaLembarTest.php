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

    /**
     * Baris "Standar used": ketiga baris kertas Rev.3 PLUS Yokogawa.
     *
     * ## Kenapa dulu tiga, sekarang empat
     *
     * Test ini dulu mengunci TEPAT tiga baris, "apa adanya dari kertasnya".
     * Maksudnya benar dan tetap dipegang: lembar di HP nggak boleh beda dari
     * lembar di tangan teknisi. Yang berubah cuma satu hal — kertas Rev.3
     * ternyata KURANG satu baris, dan kekurangan itu menahan sertifikat.
     *
     * Yokogawa CA 150 adalah kalibrator enclosure yang paling kepakai: master
     * olah datanya bernama `..._Constant_Yokogawa.xlsm`, sesi acuan
     * `EnclosureSesiTest` memakainya, dan `TabelKalibratorEnclosure::MERK`
     * sudah lama punya tabel koreksinya. Tapi dia nggak tercetak di Rev.3, jadi
     * teknisi yang memakainya nggak punya baris buat dicentang — dan sesinya
     * SELURUH titiknya nggak kehitung, tanpa satu pun pesan yang menyebut
     * standar. Itu kegagalan yang dilaporkan 26 Agt 2026.
     *
     * Menambahkannya bukan karangan: `FORM VALIDASI rev. 11` (24 Mei 2024)
     * berbunyi "Remove std. Victor / Add std kalibrator yokogawa". Kertas Rev.3
     * belum menyusul keputusan itu.
     *
     * Victor SENGAJA tetap tinggal walau rev. 11 minta dihapus — kertas yang
     * dipegang teknisi masih memuatnya, dan baris yang hilang dari layar bikin
     * dia mengira salah lembar. Dia tampil sebagai baris yang `terdaftar: false`.
     *
     * ## Kenapa dicek per isi, bukan per indeks
     *
     * Versi lama mengunci `baris[0]` dan `baris[2]`. Begitu ada baris disisipkan
     * di tengah, test-nya merah bukan karena ada yang rusak, tapi karena
     * nomornya geser — dan yang memperbaiki tergoda menggeser indeksnya tanpa
     * memeriksa isinya. Sekarang tiap baris dicari lewat labelnya.
     */
    public function test_standar_tercetak_lengkap(): void
    {
        $bentuk = app(CalibrationProfileRegistry::class)
            ->untukKode('oven')
            ->bentukLembarKerja();

        $usage = collect($bentuk['bagian'])->firstWhere('kode', 'usage_check');
        $label = collect($usage['baris'])->pluck('label')->implode(' | ');

        foreach (['Constant', 'Yokogawa', 'Victor', 'Graptech'] as $wajib) {
            $this->assertStringContainsString(
                $wajib,
                $label,
                "Baris standar `{$wajib}` hilang dari lembar Enclosure.\n\n"
                .'Tiap baris yang hilang = kalibrator yang nggak bisa dicentang teknisi, '
                .'dan sesi yang standarnya nggak ketaut SELURUH titiknya nggak kehitung. '
                ."Yang ada sekarang: {$label}",
            );
        }

        $this->assertCount(
            4,
            $usage['baris'],
            "Jumlah baris standar berubah.\n\nKalau nambah: pastikan dia beneran ada di "
            .'kertas yang dipegang teknisi ATAU di FORM VALIDASI, jangan ditambah dari '
            ."ingatan. Yang ada sekarang: {$label}",
        );
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

    /**
     * Kop lembar bawa nomor formulir dari kertasnya, bukan `null`.
     *
     * Dulu `null` karena nomornya beneran belum ketahuan. Sekarang kertasnya
     * sudah ada di tangan, jadi lembar tercetaknya wajib bernomor — lembar
     * kerja lab terakreditasi tanpa nomor formulir itu temuan audit.
     */
    #[DataProvider('profilEnclosure')]
    public function test_kop_lembar_bawa_nomor_formulir_dari_pdf(string $profil): void
    {
        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil='.$profil)
            ->assertOk()
            ->assertJsonPath('data.kode_dokumen', 'SIDIK-FM-CAL-0504_Rev.3');
    }

    /**
     * Nomor LEMBAR KERJA dan nomor INSTRUKSI KERJA nggak boleh disamakan.
     *
     * Dua-duanya nempel di halaman yang sama dan mirip bentuknya, jadi gampang
     * dikira satu. Padahal revisinya jalan sendiri-sendiri: `0504` naik ke
     * Rev.3 tanpa `0501` ikut pindah. Kalau ada yang menyatukannya, lembar
     * tercetak bakal mengaku ikut revisi yang bukan revisinya.
     */
    #[DataProvider('profilEnclosure')]
    public function test_nomor_formulir_beda_dari_nomor_metode(string $profil): void
    {
        $bentuk = app(CalibrationProfileRegistry::class)
            ->untukKode($profil)
            ->bentukLembarKerja();

        $this->assertSame('SIDIK-FM-CAL-0504_Rev.3', $bentuk['kode_dokumen']);
        $this->assertSame('SIDIK-IK-CAL-0501_Rev.6', $bentuk['kode_metode']);
        $this->assertNotSame($bentuk['kode_dokumen'], $bentuk['kode_metode']);
    }
}
