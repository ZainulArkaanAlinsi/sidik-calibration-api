<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\Enclosure\BathProfile;
use App\Services\Calibration\Profiles\Enclosure\EnclosureProfileBase;
use App\Services\Calibration\Profiles\Enclosure\FurnaceProfile;
use App\Services\Calibration\Profiles\Enclosure\InkubatorProfile;
use App\Services\Calibration\Profiles\Enclosure\OvenProfile;
use App\Services\Calibration\Profiles\Enclosure\RefrigeratorProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Smoke test kelima profil ENCLOSURE — `OvenProfile`/`InkubatorProfile` dulu
 * satu-satunya yang keinstansiasi di seluruh test suite (lewat
 * `EnclosureSeeder`); `FurnaceProfile`, `BathProfile`, & `RefrigeratorProfile`
 * nggak pernah disentuh sama sekali.
 *
 * Ketiganya SECARA KODE identik dengan Oven/Inkubator — seluruh logika di
 * `EnclosureProfileBase`, anak-anaknya cuma nyuplai `kode()` &
 * `namaAlatKemampuan()` (lihat docblock kelas itu) — tapi identik secara kode
 * bukan jaminan identik secara DATA. Ejaan `namaAlatKemampuan()` yang meleset
 * satu huruf dari baris CMC di `kemampuan-kalibrasi.json`, atau kelas yang
 * lupa didaftarkan di `CalibrationProfileRegistry`, nggak kelihatan dari
 * baca kode — cuma kelihatan kalau beneran dijalanin & dicocokin ke data.
 *
 * @see EnclosureProfileBase
 * @see CalibrationProfileRegistry
 */
class EnclosureProfilTest extends TestCase
{
    /**
     * @return array<string, array{class-string<EnclosureProfileBase>, string, string}>
     *                                                                                  [kelas, kode_harap, nama_alat_kemampuan_harap]
     */
    public static function profil(): array
    {
        return [
            'Oven' => [OvenProfile::class, 'oven', 'Oven'],
            'Furnace' => [FurnaceProfile::class, 'furnace', 'Furnace'],
            'Bath' => [BathProfile::class, 'bath', 'Bath'],
            'Inkubator' => [InkubatorProfile::class, 'inkubator', 'Inkubator'],
            'Refrigerator' => [RefrigeratorProfile::class, 'refrigerator', 'Refrigerator'],
        ];
    }

    /**
     * Kontrak bersama kelima profil: identitas, dan tanda-tanda yang dipakai
     * `CalibrationController`/`GumCalculator` buat milih JALUR HITUNG yang
     * bener (grid per set point, bukan budget per titik yang generik).
     */
    #[DataProvider('profil')]
    public function test_identitas_dan_kontrak(string $kelas, string $kodeHarap, string $namaHarap): void
    {
        /** @var EnclosureProfileBase $profil */
        $profil = new $kelas;

        $this->assertSame($kodeHarap, $profil->kode(), "kode() {$kelas} meleset");
        // Ejaan PERSIS — kunci pencocokan ke `equipments.nama_alat_kemampuan`
        // DAN ke baris CMC `kemampuan-kalibrasi.json`. Satu huruf beda bikin
        // dua-duanya putus tanpa error yang kelihatan.
        $this->assertSame($namaHarap, $profil->namaAlatKemampuan(), "namaAlatKemampuan() {$kelas} meleset");
        $this->assertSame(Formula::KODE_GUM_ENCLOSURE, $profil->kodeFormula(), "{$kelas} harus pakai formula enclosure bersama");

        // Master enclosure nggak punya batas keberterimaan (nggak divonis
        // PASS/FAIL), dan tiap set point punya U95 sendiri lewat grid — beda
        // dari sepuluh alat lain yang budget-nya satu per titik.
        $this->assertFalse($profil->punyaToleransi(), "{$kelas} nggak boleh divonis PASS/FAIL");
        $this->assertTrue($profil->u95PerTitik(), "{$kelas} harus U95 per titik, bukan satu buat seluruh sesi");
        $this->assertTrue($profil->butuhGridSensor(), "{$kelas} harus lewat jalur grid, bukan pembacaan datar");

        // SENGAJA null — U95 enclosure lahir dari grid lewat hitungPerGrup(),
        // bukan dari komponenBudget() satu-titik yang dipakai sepuluh alat
        // lain. Kalau ini suatu saat balikin array, GumCalculator generik
        // bakal dipanggil buat enclosure dengan cuma SATU deret pembacaan —
        // padahal komponen Type A-nya statistik grid 9 termokopel.
        $kemampuan = new CalibrationCapability(['nama_alat' => $namaHarap, 'ketidakpastian_terbaik' => 1.4]);
        $equipment = new Equipment(['nama_alat' => $namaHarap, 'nama_alat_kemampuan' => $namaHarap, 'satuan' => EnclosureProfileBase::SATUAN, 'resolusi' => 0.1]);
        $standard = new Standard(['nama' => 'Standar Uji', 'ketidakpastian' => 0.1, 'faktor_cakupan' => 2]);

        $this->assertNull(
            $profil->komponenBudget($kemampuan, $equipment, $standard, 50.0, 0.1, 5),
            "{$kelas}::komponenBudget() harus null — budget enclosure lahir dari hitungPerGrup(), bukan jalur per-titik",
        );

        // Bentuk lembar kerja WAJIB nawarin grid_sensor — tanpa ini layar
        // teknisi nggak tau harus nggambar grid 9 termokopel × 5 pengulangan,
        // dan jatuh ke lembar satu-kolom-pembacaan yang salah bentuk total.
        $bentuk = $profil->bentukLembarKerja();
        $this->assertArrayHasKey('grid_sensor', $bentuk, "bentukLembarKerja() {$kelas} nggak punya kunci grid_sensor");
        $this->assertIsArray($bentuk['grid_sensor']);
        $this->assertNotEmpty($bentuk['grid_sensor']);

        // Teks Sensor Acuan yang KEBACA TEKNISI harus menyatakan aturan yang
        // beneran jalan: acuannya nomor TERKECIL, bukan yang pertama diisi.
        //
        // Dulu di sini tertulis "sensor pertama" — sisa dari versi waktu urutan
        // grid memang menentukan angka. Sesudah kalkulator mengurutkan nomor
        // sendiri, kalimat itu jadi menyuruh teknisi menjaga sesuatu yang nggak
        // ngaruh, sekaligus menutupi yang beneran ngaruh. Instruksi layar yang
        // basi lebih berbahaya daripada nggak ada instruksi.
        $catatan = $bentuk['grid_sensor']['catatan_sensor_acuan'] ?? '';
        $this->assertStringContainsString('TERKECIL', $catatan, "catatan Sensor Acuan {$kelas} nggak nyebut aturan nomor terkecil");
        $this->assertStringNotContainsString(
            'Sensor pertama =',
            $catatan,
            "catatan Sensor Acuan {$kelas} masih pakai aturan lama (urutan grid)",
        );
    }

    /**
     * `CalibrationProfileRegistry::untukAlat()` cocokin lewat
     * `nama_alat_kemampuan` alat — bukan kategori, bukan kode. Kelima profil
     * ini WAJIB ketemu lewat jalur itu, karena itu jalur yang beneran dipakai
     * `CalibrationController` waktu sesi masuk.
     *
     * Nggak butuh database: `untukNamaAlat()` cuma cocokin STRING, dan
     * `Equipment` di sini nggak pernah disimpen — cukup buat mancing
     * `nama_alat_kemampuan`-nya kebaca.
     */
    #[DataProvider('profil')]
    public function test_ketemu_lewat_registry(string $kelas, string $kodeHarap, string $namaHarap): void
    {
        $equipment = new Equipment(['nama_alat_kemampuan' => $namaHarap]);

        $profil = app(CalibrationProfileRegistry::class)->untukAlat($equipment);

        $this->assertInstanceOf($kelas, $profil, "alat ber-nama_alat_kemampuan \"{$namaHarap}\" harus ketemu {$kelas} lewat registry");
        $this->assertSame($kodeHarap, $profil->kode());

        // Dan harus ketemu juga lewat kode stabilnya — jalur yang dipakai
        // routing yang ngirim kode eksplisit (bukan nama alat).
        $this->assertInstanceOf($kelas, app(CalibrationProfileRegistry::class)->untukKode($kodeHarap));
    }

    /**
     * Baris CMC kelima jenis enclosure ini beneran ada di lampiran akreditasi
     * (`database/data/kemampuan-kalibrasi.json`, kelompok "Suhu dan
     * Kelembapan") — bukan cuma diasumsikan ada karena kelasnya kompil.
     *
     * Kalau salah satu baris kehapus atau ejaannya melenceng dari
     * `namaAlatKemampuan()`, kelasnya tetap lolos SEMUA test di atas (nggak
     * ada yang nyentuh database), tapi `EnclosureProfileBase::
     * kemampuanEnclosure()` di produksi nggak akan pernah ketemu CMC-nya —
     * setiap sesi jenis itu jatuh ke `belum_dihitung` selamanya.
     */
    public function test_cmc_kelima_enclosure_ada_di_lampiran_akreditasi(): void
    {
        $path = base_path('database/data/kemampuan-kalibrasi.json');
        $data = json_decode((string) file_get_contents($path), true);

        $kelompok = collect($data['kelompok_pengukuran'])->firstWhere('kelompok', 'Suhu dan Kelembapan');
        $this->assertNotNull($kelompok, 'kelompok "Suhu dan Kelembapan" nggak ketemu di lampiran akreditasi');

        $namaTerdaftar = collect($kelompok['alat'])->pluck('nama_alat')->all();

        foreach (self::profil() as [$kelas, $kodeHarap, $namaHarap]) {
            $this->assertContains(
                $namaHarap,
                $namaTerdaftar,
                "baris CMC \"{$namaHarap}\" nggak ada di kelompok Suhu dan Kelembapan — {$kelas} nggak akan pernah kehitung di produksi",
            );
        }
    }
}
