<?php

namespace Tests\Unit;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\ProfilGenerik;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dua jalur routing profil harus ngasih jawaban yang SAMA buat nama yang sama.
 *
 * Ada dua pertanyaan yang kelihatannya beda tapi sebenernya satu:
 *
 *  1. "Lembar kerja mana yang dikirim ke HP?" — `kodeProfilDariNama()`, yang
 *     kepakai `GET /api/categories/{kode}` lewat field `profil`.
 *  2. "Profil mana yang MENGHITUNG sesinya?" — `untukNamaAlat()` /
 *     `untukAlat()`, yang kepakai `GumCalculator`, `CalibrationValidator`,
 *     `PerhitunganBuilder`, dan `CertificateSnapshotBuilder`.
 *
 * Sampai perbaikan ini keduanya punya SALINAN aturan pencocokan sendiri, dan
 * salinannya beda: yang pertama baca `aliasNama()` + nerima kunci yang nempel
 * di tengah nama, yang kedua cocok PERSIS dan jatuh ke pH kalau meleset.
 * Akibatnya diam dan mahal — buat "Temperature Indikator With Sensors"
 * (judul lembar kerjanya sendiri) HP dapat lembar TIDS sementara server
 * ngitung pakai PhMeterProfile, jadi `TidsProfile::hitungPerGrup()` yang
 * SELURUH penjagaan angkanya bertumpu di situ nggak pernah kepanggil, dan U95
 * lahir dari lantai CMC TIDS. Bentuk yang sama juga kena "Water Bath",
 * "Turbidimeter Hach", dan tiap nama alat pelanggan yang nggak byte-exact.
 *
 * Test ini nyapu SEMUA nama yang beneran ada di data — 48 nama alat lampiran
 * akreditasi, `namaAlatKemampuan()` tiap profil, dan tiap `aliasNama()` yang
 * terdaftar — biar salinan aturan yang ke-3 nggak bisa lahir diam-diam lagi.
 *
 * @see CalibrationProfileRegistry::kodeProfilDariNama()
 * @see CalibrationProfileRegistry::untukNamaAlat()
 */
class RoutingProfilSepakatTest extends TestCase
{
    private CalibrationProfileRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new CalibrationProfileRegistry;
    }

    /**
     * Tiap nama yang bisa nyampe ke registry dari data nyata.
     *
     * Tiga sumber, dan ketiganya beneran dipakai:
     *
     *  - `nama_alat` lampiran akreditasi LK-285-IDN — yang diseed
     *    `CalibrationCapabilitySeeder` ke `calibration_capabilities.nama_alat`,
     *    dan itu persis yang dipilih admin buat ngisi
     *    `equipments.nama_alat_kemampuan`;
     *  - `namaAlatKemampuan()` tiap profil terdaftar — kunci resmi profil;
     *  - `aliasNama()` tiap profil — ejaan lain yang PUNYA bukti di data
     *    (judul lembar kerja, master Excel, kolom `equipments.nama_alat`).
     *
     * @return array<string, array{string}>
     */
    public static function namaDariData(): array
    {
        $registry = new CalibrationProfileRegistry;

        $nama = self::namaAlatLampiran();

        foreach ($registry->semua() as $profil) {
            $nama[] = $profil->namaAlatKemampuan();

            foreach ($profil->aliasNama() as $alias) {
                $nama[] = $alias;
            }
        }

        $kasus = [];
        foreach (array_unique($nama) as $n) {
            $kasus[$n] = [$n];
        }

        return $kasus;
    }

    /**
     * INTI berkas ini: satu nama, satu jawaban.
     *
     * `null` dari `kodeProfilDariNama()` artinya "pakai form generik", dan
     * padanannya di jalur hitung [ProfilGenerik] — bukan pH. Dua-duanya
     * dianggap jawaban yang sama di sini, karena dua-duanya berarti "alat ini
     * nggak punya jalur khusus".
     */
    #[DataProvider('namaDariData')]
    public function test_lembar_kerja_dan_jalur_hitung_sepakat(string $nama): void
    {
        $kodeLembar = $this->registry->kodeProfilDariNama($nama) ?? ProfilGenerik::KODE;
        $kodeHitung = $this->registry->untukNamaAlat($nama)->kode();

        $this->assertSame($kodeLembar, $kodeHitung, implode(' ', [
            "Nama '{$nama}' dapat lembar '{$kodeLembar}' tapi dihitung profil '{$kodeHitung}'.",
            'Dua jalur ini WAJIB satu sumber kebenaran — kalau beda, teknisi ngisi lembar satu alat',
            'dan server ngitungnya pakai aturan alat lain, tanpa satu pun error yang muncul.',
        ]));
    }

    /**
     * Nama alat PELANGGAN — teks bebas dengan merk/kata sifat nempel — juga
     * harus sepakat.
     *
     * Ini yang paling sering kejadian di lapangan: `equipments.nama_alat` itu
     * yang diketik teknisi, dan `untukAlat()` jatuh ke kolom itu waktu
     * `nama_alat_kemampuan` kosong (alat lama, sebelum kolomnya ada).
     *
     * @return array<string, array{string, string}> [nama, kode_harap]
     */
    public static function namaAlatPelanggan(): array
    {
        return [
            'merk nempel di belakang' => ['Turbidimeter Hach', 'turbidimeter'],
            'merk 2 kata' => ['pH Meter Mettler Toledo', 'ph_meter'],
            'kata sifat di depan' => ['Visible Spectrofotometer', 'spectrophotometer'],
            'jenis bath di depan' => ['Water Bath', 'bath'],
            'huruf besar semua' => ['TURBIDIMETER', 'turbidimeter'],
            'spasi dobel & pinggir' => ['  pH   Meter  ', 'ph_meter'],
            // Empat varian TIDS ini yang paling mahal — lihat docblock kelas.
            'TIDS judul lembar kerjanya sendiri' => ['Temperature Indikator With Sensors', 'tids'],
            'TIDS ejaan Inggris' => ['Temperature Indicator dengan Sensor', 'tids'],
            'TIDS + merk pelanggan' => ['Temperatur Indikator dengan Sensor Fluke', 'tids'],
            // Saudaranya cuma beda satu kata, dan nggak boleh ikut kegeser.
            'TITS ejaan Inggris' => ['Temperature Indicator tanpa Sensor', 'tits'],
        ];
    }

    #[DataProvider('namaAlatPelanggan')]
    public function test_nama_alat_pelanggan_sepakat_di_dua_jalur(string $nama, string $kodeHarap): void
    {
        $this->assertSame($kodeHarap, $this->registry->kodeProfilDariNama($nama));
        $this->assertSame($kodeHarap, $this->registry->untukNamaAlat($nama)->kode());
    }

    /**
     * Alat yang nggak punya lembar khusus dihitung [ProfilGenerik], BUKAN pH.
     *
     * Yang dilawan di sini fallback lama `untukNamaAlat()`: Buret Digital
     * dihitung PhMeterProfile, dicap rumus `gum-ph` di jejak audit, dan kalau
     * baris kemampuannya kebetulan punya konstanta budget lengkap dia bakal
     * dapat lima komponen budget pH — buat buret.
     *
     * @return array<string, array{string}>
     */
    public static function namaGenerik(): array
    {
        return [
            'Buret Digital' => ['Buret Digital'],
            'Termometer Gelas' => ['Termometer Gelas'],
            'Timbangan (Elektronik, mekanik)' => ['Timbangan (Elektronik, mekanik)'],
            'Pressure Gauge' => ['Pressure Gauge'],
            'TIDS (singkatan, sengaja nggak didaftarin)' => ['TIDS'],
        ];
    }

    #[DataProvider('namaGenerik')]
    public function test_alat_tanpa_lembar_khusus_nggak_dihitung_sebagai_ph(string $nama): void
    {
        $this->assertNull($this->registry->kodeProfilDariNama($nama));
        $this->assertSame(ProfilGenerik::KODE, $this->registry->untukNamaAlat($nama)->kode());
    }

    /**
     * Nama KOSONG tetap jatuh ke pH — kompatibilitas yang sengaja dijaga.
     *
     * Alat lama yang `nama_alat_kemampuan`-NYA null dan `nama_alat`-nya juga
     * kosong udah ada di produksi sejak sebelum kolom itu lahir. Perilakunya
     * dipertahankan apa adanya: nama kosong bukan "alat yang jelas bukan pH",
     * dia "alat yang belum ngaku apa-apa", dan menggeser sesi lama gara-gara
     * kolom yang emang belum pernah diisi itu bukan perbaikan.
     */
    public function test_nama_kosong_tetap_jatuh_ke_ph(): void
    {
        $this->assertSame('ph_meter', $this->registry->untukNamaAlat('')->kode());
        $this->assertSame('ph_meter', $this->registry->untukNamaAlat('   ')->kode());
        $this->assertSame('ph_meter', $this->registry->default()->kode());
    }

    /**
     * Semua `nama_alat` di lampiran akreditasi LK-285-IDN (48 alat).
     *
     * @return list<string>
     */
    private static function namaAlatLampiran(): array
    {
        // Path-nya dirangkai dari `__DIR__`, BUKAN `database_path()`: data
        // provider PHPUnit jalan SEBELUM aplikasi Laravel di-boot, jadi helper
        // container-nya belum ada. Yang kejadian bukan error yang kebaca —
        // providernya lempar, seluruh kasusnya raib, dan hasilnya "No tests
        // found" yang gampang dikira test-nya emang hijau.
        $path = dirname(__DIR__, 2).'/database/data/kemampuan-kalibrasi.json';

        /** @var array{kelompok_pengukuran: list<array{alat: list<array{nama_alat: string}>}>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $nama = [];
        foreach ($data['kelompok_pengukuran'] as $kelompok) {
            foreach ($kelompok['alat'] as $alat) {
                $nama[] = $alat['nama_alat'];
            }
        }

        return $nama;
    }
}
