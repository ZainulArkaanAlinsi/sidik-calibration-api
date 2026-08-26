<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Baris standar di lembar kerja nggak pernah menunjuk master milik lab LAIN.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * Tiga belas tempat menyalin query yang sama —
 * `Standard::query()->whereNull('parameter_kondisi')` — dan ketiga belasnya
 * lupa menyaring organisasi. Di berkas yang sama, 250 baris di bawahnya, pola
 * ini justru diperingatkan sendiri: *"tanpa saringan ini baris pertama yang
 * kebetulan cocok bisa berasal dari lab lain — dan CMC yang salah langsung
 * mendarat di sertifikat."*
 *
 * Akibatnya berlapis. Yang ikut ke layar teknisi lab kedua: nomor sertifikat &
 * ketertelusuran milik lab pertama — buat lab terakreditasi, ketertelusuran
 * yang menunjuk dokumen orang lain itu temuan audit yang paling mahal
 * jenisnya. Dan `standard_id` yang bocor ikut dipakai menurunkan kalibrator
 * sesi, jadi sesinya **ditolak sistem** dengan pesan menyebut kolom yang nggak
 * pernah dia ketik.
 *
 * Database hari ini masih satu organisasi, jadi belum ada yang kena. Yang
 * dijaga di sini hari onboarding lab kedua.
 *
 * ## Kenapa disapu ke SELURUH profil
 *
 * Karena polanya disalin. Perbaikan satu-satu meninggalkan yang lain, dan
 * profil ke-18 bakal menyalin salinan yang mana pun yang kebetulan dia lihat.
 * Daftarnya diambil dari registry, bukan ditulis tangan.
 */
class StandarTidakBocorAntarLabTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Daftarnya dari registry, bukan ditulis tangan — profil ke-18 ikut dijaga
     * tanpa ada yang perlu ingat menambahkannya ke sini.
     *
     * @return array<string, array{CalibrationProfile}>
     */
    public static function semuaProfil(): array
    {
        $hasil = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $p) {
            $hasil[$p->kode()] = [$p];
        }

        // Penjaga lantai: sweep yang daftarnya datang dari luar punya satu cara
        // gagal yang nggak bersuara — daftarnya menyusut, kasusnya ikut sedikit,
        // dan PHPUnit tetap menulis "OK" dengan lebih sedikit yang diperiksa.
        self::assertGreaterThanOrEqual(17, count($hasil), 'Registry profil menyusut.');

        return $hasil;
    }

    /**
     * Tiap `standard_id` yang keluar di lembar wajib milik lab pemilik alat.
     */
    #[DataProvider('semuaProfil')]
    public function test_lembar_nggak_bocor_ke_standar_lab_lain(CalibrationProfile $profil): void
    {
        $this->seed(DatabaseSeeder::class);

        $labKedua = Organization::create(['nama' => 'PT Lab Kedua', 'slug' => 'lab-kedua']);

        // Alat MILIK LAB KEDUA. Seluruh master `standards` yang ter-seed punya
        // lab pertama, jadi apa pun yang muncul di lembar ini bocor.
        $alat = Equipment::query()
            ->where('organization_id', 1)
            ->firstOrFail()
            ->replicate(['id']);

        $alat->organization_id = $labKedua->id;
        $alat->serial_number = 'LAB2-'.$profil->kode();
        $alat->save();

        $milikLabPertama = Standard::where('organization_id', 1)->pluck('id')->all();

        $this->assertNotEmpty($milikLabPertama, 'Seeder nggak punya standar sama sekali.');

        $bentuk = $profil->bentukLembarKerja(false, $alat);

        foreach ($this->idStandarDi($bentuk) + $this->idDropdownStandar($bentuk) as $jalur => $id) {
            $this->assertNotContains(
                $id,
                $milikLabPertama,
                sprintf('Lembar `%s` di %s menunjuk standar id %d milik lab lain.', $profil->kode(), $jalur, $id),
            );
        }

        // KONTROL POSITIF. Tanpa ini penjaganya lulus paling meyakinkan justru
        // waktu paling rusak: bentuk yang meledak jadi kosong, nol id buat
        // diperiksa, nol assertion yang sempat gagal. Alat lab PERTAMA wajib
        // memulangkan minimal satu id — kalau nggak, yang diuji di atas bukan
        // "nggak bocor" tapi "nggak ada apa-apa".
        $alatLabPertama = Equipment::where('organization_id', 1)->firstOrFail();
        $bentukLabPertama = $profil->bentukLembarKerja(false, $alatLabPertama);

        $this->assertNotEmpty(
            $this->idStandarDi($bentukLabPertama) + $this->idDropdownStandar($bentukLabPertama),
            sprintf(
                'Lembar `%s` nggak memulangkan satu pun `standard_id` buat alat lab sendiri — '
                .'penjaga di atas jadi lulus tanpa memeriksa apa pun.',
                $profil->kode(),
            ),
        );
    }

    /**
     * ID standar yang nyelip sebagai PILIHAN dropdown, bukan sebagai
     * `standard_id`.
     *
     * Dropdown "Environmental Meter Used" menaruh id-nya di `pilihan[].nilai`
     * sebagai STRING. Penjaga yang cuma mencari kunci `standard_id` bakal hijau
     * sambil menawarkan termohigrometer lab lain — dan yang kepilih di situ
     * masuk ke sesi, koreksi kondisi lingkungannya dibaca dari sertifikat lab
     * itu, lalu angkanya kecetak di sertifikat lab ini.
     *
     * @param  array<string, mixed>  $simpul
     * @return array<string, int>
     */
    private function idDropdownStandar(array $simpul, string $jalur = ''): array
    {
        $hasil = [];

        foreach ($simpul as $kunci => $nilai) {
            if (! is_array($nilai)) {
                continue;
            }

            $di = $jalur === '' ? (string) $kunci : "{$jalur}.{$kunci}";

            $berstandar = in_array($simpul['kode'] ?? null, ['thermohygro_standard_id', 'standard_id'], true);

            if ($kunci === 'pilihan' && $berstandar) {
                foreach ($nilai as $i => $opsi) {
                    if (is_array($opsi) && is_numeric($opsi['nilai'] ?? null)) {
                        $hasil["{$di}.{$i}.nilai"] = (int) $opsi['nilai'];
                    }
                }

                continue;
            }

            $hasil += $this->idDropdownStandar($nilai, $di);
        }

        return $hasil;
    }

    /**
     * Semua `standard_id` di seluruh bentuk lembar, berikut jalurnya.
     *
     * Sengaja menyapu SELURUH pohon, bukan cuma bagian `usage_check`: kunci itu
     * juga muncul di baris tabel hasil (pasangan titik↔larutan) dan di baris
     * standar tercetak lembar spektro. Penjaga yang cuma melihat satu bentuk
     * bakal hijau sambil kebocoran lewat bentuk sebelahnya.
     *
     * @param  array<string, mixed>  $simpul
     * @return array<string, int>
     */
    private function idStandarDi(array $simpul, string $jalur = ''): array
    {
        $hasil = [];

        foreach ($simpul as $kunci => $nilai) {
            $di = $jalur === '' ? (string) $kunci : "{$jalur}.{$kunci}";

            if (is_array($nilai)) {
                $hasil += $this->idStandarDi($nilai, $di);

                continue;
            }

            if ($kunci === 'standard_id' && is_int($nilai)) {
                $hasil[$di] = $nilai;
            }
        }

        return $hasil;
    }
}
