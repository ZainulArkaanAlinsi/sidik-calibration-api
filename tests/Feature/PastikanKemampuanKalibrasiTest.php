<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationMethod;
use App\Services\Calibration\CalibrationProfileRegistry;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\KemampuanKalibrasiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Perintah boot `kemampuan:pastikan`.
 *
 * ## Kenapa test ini ada
 *
 * Perintah ini jalan di SETIAP boot produksi. Yang salah di sini tidak muncul
 * sebagai error di layar siapa pun — dia muncul sebagai alat baru yang tidak
 * pernah nongol di HP teknisi, atau sebagai 24,5 detik yang ditambahkan ke tiap
 * cold start selamanya.
 *
 * Empat kelas kegagalan yang diuji di bawah, semuanya SUNYI kalau lolos:
 *
 *  1. **Selalu menyeed.** Kalau pemeriksaannya tidak pernah bilang "lengkap",
 *     tiap container bangun membayar 24,5 detik. Tidak ada error; servernya
 *     cuma lambat menyala, dan Render menidurkan container tiap 15 menit
 *     nganggur.
 *  2. **Tidak pernah menyeed.** Kebalikannya, dan lebih mahal: alat baru tidak
 *     punya baris kemampuan, `GumCalculator` jatuh ke jalur generik, dan U95
 *     terbit lebih kecil dari yang diakreditasi.
 *  3. **Melapor sukses padahal belum beres.** Seeder bisa selesai tanpa
 *     exception sambil menulis nama yang tidak cocok profil mana pun.
 *  4. **Menyentuh yang bukan haknya.** Perintah ini cuma boleh menanam baris
 *     kemampuan. Data demo, pelanggan, alat, dan standar tidak boleh ikut
 *     tertulis.
 */
class PastikanKemampuanKalibrasiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nama alat yang wajib punya baris — satu per profil terdaftar.
     *
     * @return list<string>
     */
    private function namaProfil(): array
    {
        return array_values(array_unique(array_map(
            static fn ($p): string => $p->namaAlatKemampuan(),
            app(CalibrationProfileRegistry::class)->semua(),
        )));
    }

    /**
     * Sesudah `db:seed` penuh, perintahnya TIDAK menyeed apa pun.
     *
     * Ini penjaga kelas kegagalan no. 1. Kalau merah, tiap cold start produksi
     * membayar ongkos seeding yang tidak perlu.
     */
    public function test_kalau_sudah_lengkap_seeding_dilewati(): void
    {
        $this->seed(DatabaseSeeder::class);

        $jumlah = count($this->namaProfil());

        // SATU ekspektasi per pemanggilan, dan itu bukan selera:
        // `expectsOutputToContain` dicocokkan per panggilan `writeln`, jadi dua
        // ekspektasi untuk dua potongan kalimat yang ada di BARIS YANG SAMA
        // bikin yang kedua mencari baris berikutnya — yang tidak pernah ada.
        // Versi pertama test ini merah karena itu, bukan karena perintahnya
        // salah.
        $this->artisan('kemampuan:pastikan')
            ->expectsOutputToContain("{$jumlah}/{$jumlah}")
            ->assertSuccessful();

        $this->artisan('kemampuan:pastikan')
            ->expectsOutputToContain('seeding dilewati')
            ->assertSuccessful();
    }

    /**
     * Baris yang hilang ditanam ulang, dan perintahnya melaporkan namanya.
     *
     * Height Gauge dipakai sebagai contoh karena dia alasan perintah ini lahir:
     * barisnya absen di produksi sesudah alat ke-26 mendarat.
     */
    public function test_baris_yang_hilang_ditanam_ulang(): void
    {
        $this->seed(DatabaseSeeder::class);

        CalibrationCapability::where('nama_alat', 'Height Gauge')->delete();

        $this->assertSame(0, CalibrationCapability::where('nama_alat', 'Height Gauge')->count());

        $this->artisan('kemampuan:pastikan')
            ->expectsOutputToContain('Height Gauge')
            ->assertSuccessful();

        $this->assertGreaterThan(
            0,
            CalibrationCapability::where('nama_alat', 'Height Gauge')->count(),
            'Barisnya tidak kembali — alat baru tetap tidak akan muncul di HP.',
        );
    }

    /**
     * Perintahnya membuat SELURUH profil lengkap, bukan cuma yang kebetulan
     * diperiksa.
     *
     * Invarian yang sama dijaga `CmcSemuaProfilTest` untuk jalur `db:seed`
     * penuh; di sini yang dibuktikan jalur BOOT sampai ke hasil yang sama.
     */
    public function test_dari_kosong_semua_profil_jadi_lengkap(): void
    {
        $this->seed(DatabaseSeeder::class);

        CalibrationCapability::query()->delete();

        $this->artisan('kemampuan:pastikan')->assertSuccessful();

        foreach ($this->namaProfil() as $nama) {
            $this->assertGreaterThan(
                0,
                CalibrationCapability::where('nama_alat', $nama)->count(),
                "Profil `{$nama}` masih belum punya baris sesudah `kemampuan:pastikan`.",
            );
        }
    }

    /**
     * Idempoten: dijalankan dua kali tidak menambah baris.
     *
     * Tanpa penjaga ini, tiap boot menumpuk baris kembar — dan
     * `calibration_capabilities` TIDAK punya unique index yang menahannya, jadi
     * database yang benar-benar dipakai teknisi pelan-pelan penuh baris kembar
     * tanpa satu pun error.
     */
    public function test_dijalankan_dua_kali_tidak_menambah_baris(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sebelum = CalibrationCapability::count();

        $this->artisan('kemampuan:pastikan')->assertSuccessful();
        $this->artisan('kemampuan:pastikan')->assertSuccessful();

        $this->assertSame(
            $sebelum,
            CalibrationCapability::count(),
            'Jumlah baris berubah — perintahnya tidak idempoten.',
        );
    }

    /**
     * TIDAK menyentuh data demo, pelanggan, alat, maupun standar.
     *
     * Ini bedanya dari `SEED_ON_BOOT=true`, dan alasan perintah ini ada:
     * `db:seed` penuh menulis ulang sesi demo tiap container bangun, menimpa
     * data yang sedang diuji teknisi.
     */
    public function test_tidak_menyentuh_data_di_luar_kemampuan(): void
    {
        $this->seed(DatabaseSeeder::class);

        CalibrationCapability::where('nama_alat', 'Height Gauge')->delete();

        $tabel = ['calibration_sessions', 'raw_measurements', 'customers', 'equipments', 'standards'];
        $sebelum = [];

        foreach ($tabel as $t) {
            $sebelum[$t] = DB::table($t)->count();
        }

        $this->artisan('kemampuan:pastikan')->assertSuccessful();

        foreach ($tabel as $t) {
            $this->assertSame(
                $sebelum[$t],
                DB::table($t)->count(),
                "Tabel `{$t}` ikut berubah — perintah boot ini cuma boleh menyentuh kemampuan.",
            );
        }
    }

    /**
     * Database yang belum pernah di-seed TIDAK menjatuhkan boot.
     *
     * Organisasi 1 belum ada = deploy pertama. Seeder kemampuan bakal gagal di
     * foreign key-nya, dan pesan yang muncul tidak menunjuk ke mana-mana. Yang
     * benar: keluar dengan pesan yang menyebutkan langkah berikutnya, dan
     * SUCCESS — bukan menjatuhkan seluruh server karena satu langkah setup.
     */
    public function test_database_kosong_keluar_dengan_pesan_bukan_gagal(): void
    {
        $this->assertSame(0, DB::table('organizations')->count());

        $this->artisan('kemampuan:pastikan')
            ->expectsOutputToContain('belum pernah di-seed')
            ->assertSuccessful();
    }

    /** `--uji-coba` melaporkan yang kurang tanpa menulis apa pun. */
    public function test_uji_coba_tidak_menulis(): void
    {
        $this->seed(DatabaseSeeder::class);

        CalibrationCapability::where('nama_alat', 'Height Gauge')->delete();
        $sebelum = CalibrationCapability::count();

        $this->artisan('kemampuan:pastikan', ['--uji-coba' => true])
            ->expectsOutputToContain('Height Gauge')
            ->assertSuccessful();

        $this->assertSame($sebelum, CalibrationCapability::count());
    }

    /**
     * Nomor IK tiap profil dipastikan ada di `calibration_methods`.
     *
     * `MetodeKalibrasiSeeder` membacanya dari CSV ekspor manual berisi 34
     * baris; alat yang lahir sesudah ekspor itu tidak ada di sana. Height Gauge
     * (`SIDIK-IK-CAL-0539`) yang pertama ketahuan.
     */
    public function test_nomor_ik_tiap_profil_dipastikan_ada(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('kemampuan:pastikan')->assertSuccessful();

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $penuh = (string) $profil->kodeMetode();

            $this->assertSame(
                1,
                preg_match('/^(SIDIK-IK-CAL-\d+)_Rev\.(\d+)$/', $penuh, $cocok),
                "Nomor IK profil `{$profil->kode()}` bentuknya tidak dikenali: {$penuh}",
            );

            $this->assertTrue(
                CalibrationMethod::where('kode', $cocok[1])->exists(),
                "Nomor IK `{$cocok[1]}` (profil `{$profil->kode()}`) tidak ada di master "
                .'`calibration_methods` — dia hilang dari panel admin dan '
                .'`GET /api/calibration-methods`.',
            );
        }
    }

    /**
     * Baris metode yang SUDAH ada tidak pernah ditimpa.
     *
     * `calibration_methods` boleh disunting admin, dan CSV lab sumber resmi
     * nomor revisinya. Kalau jalur boot memakai `updateOrCreate`, tiap container
     * bangun mengembalikan revisi & nama yang barusan dibetulkan admin ke nilai
     * yang ditebak dari konstanta profil — tanpa satu pun error.
     */
    public function test_metode_yang_sudah_ada_tidak_ditimpa(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Dijalankan DULU supaya barisnya ada. `db:seed` penuh TIDAK
        // membuatnya: `MetodeKalibrasiSeeder` membaca CSV ekspor manual yang
        // tidak memuat `0539`, dan itu justru celah yang perintah ini tutup.
        $this->artisan('kemampuan:pastikan')->assertSuccessful();

        $metode = CalibrationMethod::where('kode', 'SIDIK-IK-CAL-0539')->firstOrFail();
        $metode->update(['nama' => 'Disunting Admin', 'revisi' => 9]);

        $this->artisan('kemampuan:pastikan')->assertSuccessful();

        $sesudah = $metode->fresh();

        $this->assertSame('Disunting Admin', $sesudah->nama, 'Nama yang disunting admin ketimpa.');
        $this->assertSame(9, (int) $sesudah->revisi, 'Revisi yang disunting admin ketimpa.');
    }

    /**
     * Daftar agregator memuat SEMUA seeder kemampuan yang ada di disk.
     *
     * Penjaga anti-drift: alat ke-27 yang seeder kemampuannya dibuat tapi lupa
     * didaftarkan di `KemampuanKalibrasiSeeder::DAFTAR` bakal lolos seluruh
     * sapuan lain — `db:seed` penuh tidak memanggilnya, jadi barisnya tidak
     * pernah ada, dan yang merah cuma `CmcSemuaProfilTest` dengan pesan yang
     * menunjuk ke tempat lain.
     */
    public function test_daftar_agregator_memuat_semua_seeder_kemampuan(): void
    {
        $diDisk = [];

        foreach (glob(database_path('seeders/*CapabilitySeeder.php')) as $berkas) {
            $diDisk[] = 'Database\\Seeders\\'.basename($berkas, '.php');
        }

        sort($diDisk);

        $terdaftar = array_values(array_filter(
            KemampuanKalibrasiSeeder::DAFTAR,
            static fn (string $k): bool => str_ends_with($k, 'CapabilitySeeder'),
        ));

        sort($terdaftar);

        $this->assertSame(
            $diDisk,
            $terdaftar,
            "Ada seeder `*CapabilitySeeder` di disk yang tidak terdaftar di\n"
            ."`KemampuanKalibrasiSeeder::DAFTAR` (atau sebaliknya).\n\n"
            .'Yang tidak terdaftar tidak pernah jalan — di `db:seed` penuh MAUPUN di boot.',
        );
    }
}
