<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Support\AnakTimbanganMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alur penuh **Anak Timbangan** (alat ke-29) dengan payload BENTUK HP.
 *
 * ## Kenapa berkas ini ada
 *
 * Alat ke-29 mendarat 10 Sep 2026 dengan tabel standar, mesin hitung, profil,
 * sesi contoh, dan 1.011 pengaduan ke workbook master — semuanya hijau. Yang
 * tidak ada: **jalur kirim dari HP.**
 *
 * Keempat tabel ABBA tidak menyatakan `simpan_ke`, dan `CalibrationController`
 * tidak punya cabang `butuhBlokAnakTimbangan()`. Akibatnya payload dari aplikasi
 * teknisi jatuh ke loop deret-datar generik, baris mentahnya lahir TANPA
 * `peran_sensor`, dan `hitungPerGrup()` menolak seluruh titik dengan alasan
 * "nggak punya baris ber-peran". Lembarnya kegambar penuh, teknisi mengisi
 * sepuluh keping × empat peran × tiga ulangan, tombol kirim jalan mulus — dan
 * yang kembali sesi tanpa satu pun titik terhitung.
 *
 * Sesi contoh `DEMO-AT-001` tidak pernah memperlihatkannya: seeder menulis baris
 * mentahnya LANGSUNG ke database, melewati controller. Jadi seluruh suite hijau
 * di atas jalur yang tidak pernah dilewati satu pun teknisi.
 *
 * Ini kejadian KELIMA dari pola yang sama — Micrometer, TIDS, Timbangan, dan
 * Flowmeter mendahuluinya, dan keempatnya ketahuan dari sisi HP, bukan dari
 * suite backend, **karena test backend memakai payload yang ditulis backend
 * sendiri**. Lihat docblock `AlurPenuhFlowmeterTest`.
 */
class AlurPenuhAnakTimbanganTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Blok tingkat-sesi, persis bentuk yang dikirim `simpan_ke:
     * 'spesifikasi_alat.anak_timbangan.…'` di sisi HP.
     *
     * @return array<string, mixed>
     */
    private static function blokSesi(): array
    {
        return [
            'kelas_uut' => 'F1',
            'kelas_standar' => 'E2',
            'timbangan' => 'Analytical Balance',
            'meter_lingkungan' => 'Thermobarometer',
            'kapasitas_g' => 200,
            'suhu_awal' => 23.1,
            'suhu_akhir' => 23.0,
            'kelembaban_awal' => 55.0,
            'kelembaban_akhir' => 56.0,
            'tekanan_awal' => 933.2,
            'tekanan_akhir' => 933.1,
        ];
    }

    /**
     * Empat deret ber-peran per KEPING — satu entri `measurements[]` membawa
     * keempatnya, bukan empat entri terpisah.
     *
     * Itu yang dihasilkan `_measurementsDeretBernama()` di HP: dia menyusuri
     * keempat tabel per indeks baris dan menggabungkan hasilnya jadi satu entri.
     *
     * Angkanya dari sesi contoh master (identitas sintetis). Keping ketiga
     * SENGAJA kehilangan `at_t2`, dan keempat sengaja kosong seluruhnya.
     *
     * @return list<array<string, mixed>>
     */
    private static function keping(): array
    {
        return [
            [
                'titik_ukur' => 100.0,
                'at_s1' => [100.0, 100.0, 100.0],
                'at_t1' => [99.9999, 99.9999, 99.9999],
                'at_t2' => [99.9999, 99.9999, 99.9999],
                'at_s2' => [100.0, 100.0, 100.0],
            ],
            [
                'titik_ukur' => 50.0,
                'at_s1' => [50.0003, 50.0003, 50.0003],
                'at_t1' => [50.0003, 50.0003, 50.0003],
                'at_t2' => [50.0003, 50.0003, 50.0003],
                'at_s2' => [50.0002, 50.0002, 50.0002],
            ],
            [
                'titik_ukur' => 5.0,
                'at_s1' => [5.0002, 5.0002, 5.0002],
                'at_t1' => [5.0003, 5.0003, 5.0003],
                // `at_t2` sengaja TIDAK ada.
                'at_s2' => [5.0003, 5.0003, 5.0003],
            ],
            // Keping yang belum disentuh sama sekali — tidak boleh jadi titik,
            // dan tidak boleh menggeser penomoran titik sesudahnya.
            [
                'titik_ukur' => 20.0,
                'at_s1' => [],
                'at_t1' => [],
                'at_t2' => [],
                'at_s2' => [],
            ],
        ];
    }

    private function kirim(): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-AT-001')->firstOrFail();
        $alat = Equipment::findOrFail($contoh->equipment_id);
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $alat->id,
                'standard_id' => $contoh->standard_id,
                'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
                'tanggal_kalibrasi' => '2026-09-11',
                'suhu_awal' => 23.1,
                'suhu_akhir' => 23.0,
                'kelembaban_awal' => 55.0,
                'kelembaban_akhir' => 56.0,
                'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => self::blokSesi()],
                'measurements' => self::keping(),
            ])
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /**
     * Penjaga utamanya: pembacaan yang dikirim HP lahir dengan kosakata `at_*`.
     *
     * Kalau daftar perannya kosong, payload HP tidak pernah sampai ke jalur
     * simpan ABBA — dan seluruh sesi tidak bisa dihitung.
     */
    public function test_pembacaan_bentuk_hp_lahir_dengan_peran_abba(): void
    {
        $sesi = $this->kirim();

        $peran = $sesi->rawMeasurements->pluck('peran_sensor')->unique()->sort()->values()->all();
        $diharap = AnakTimbanganMentah::PERAN_URUT;
        sort($diharap);

        $this->assertSame(
            $diharap,
            $peran,
            'Baris mentahnya nggak lahir dengan kosakata `at_*`. Kalau kosong sama sekali, '
            .'payload HP jatuh ke loop deret-datar dan tanda tiap suku `de` ikut hilang.',
        );
    }

    /**
     * Keempat peran tersimpan UTUH, dan urutan pembacaannya tidak tertukar.
     *
     * Yang dijaga di sini bukan jumlahnya saja. `de = (T1 − S1 − S2 + T2)/2`
     * memberi tanda berbeda ke tiap suku, jadi baris yang mendarat di peran yang
     * salah membalikkan ARAH koreksi kepingnya tanpa satu pun error.
     */
    public function test_keempat_peran_tersimpan_utuh_dan_tidak_tertukar(): void
    {
        $sesi = $this->kirim();

        // Dua keping penuh × 4 peran × 3 ulangan = 24; keping ketiga cuma
        // punya 3 peran × 3 = 9. Total 33.
        $this->assertCount(33, $sesi->rawMeasurements);

        $deret = static fn (CalibrationSession $s, string $peran): array => $s->rawMeasurements
            ->where('peran_sensor', $peran)
            ->where('titik_ke', 1)
            ->sortBy('pembacaan_ke')
            ->pluck('pembacaan')
            ->map(static fn ($x): float => (float) $x)
            ->values()
            ->all();

        $this->assertEqualsWithDelta([100.0, 100.0, 100.0], $deret($sesi, AnakTimbanganMentah::PERAN_S1), 1e-9);
        $this->assertEqualsWithDelta([99.9999, 99.9999, 99.9999], $deret($sesi, AnakTimbanganMentah::PERAN_T1), 1e-9);
        $this->assertEqualsWithDelta([99.9999, 99.9999, 99.9999], $deret($sesi, AnakTimbanganMentah::PERAN_T2), 1e-9);
        $this->assertEqualsWithDelta([100.0, 100.0, 100.0], $deret($sesi, AnakTimbanganMentah::PERAN_S2), 1e-9);
    }

    /**
     * Pembacaannya TIDAK dibulatkan di jalur simpan.
     *
     * Enam jalur blok lain melewatkan tiap angka ke `bulatkanKolom()`. Di sini
     * itu fatal: `100,0000` lawan `99,9999` selisihnya 0,1 mg, dan justru
     * selisih itulah yang sedang diukur. Dibulatkan, seluruh `de` sesi ini nol
     * dan sertifikatnya mencetak koreksi sempurna di setiap keping.
     */
    public function test_pembacaan_tidak_dibulatkan_di_jalur_simpan(): void
    {
        $sesi = $this->kirim();

        $t1 = (float) $sesi->rawMeasurements
            ->where('peran_sensor', AnakTimbanganMentah::PERAN_T1)
            ->where('titik_ke', 1)
            ->first()
            ->pembacaan;

        $this->assertEqualsWithDelta(
            99.9999,
            $t1,
            1e-9,
            'Pembacaan UUT dibulatkan — selisih 0,1 mg yang jadi isi seluruh lembar ini hilang '
            .'di jalur simpan.',
        );
    }

    /** Titik yang keempat perannya lengkap benar-benar terhitung. */
    public function test_titik_lengkap_menghasilkan_baris_ketidakpastian(): void
    {
        $sesi = $this->kirim();

        $hitungan = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(
            2,
            $hitungan,
            'Dua keping yang perannya lengkap harus terbit; yang ketiga sengaja kurang `at_t2`.',
        );

        $this->assertEqualsWithDelta(100.0, (float) $hitungan[0]->titik_ukur, 1e-9);
        $this->assertEqualsWithDelta(50.0, (float) $hitungan[1]->titik_ukur, 1e-9);

        foreach ($hitungan as $h) {
            $this->assertGreaterThan(
                0.0,
                (float) $h->ketidakpastian_diperluas,
                'U95 nol berarti komponen budgetnya hilang — dan `± 0,000` di sertifikat itu '
                .'klaim pengukuran sempurna, bukan sekadar angka kosong.',
            );
        }
    }

    /**
     * Keping yang belum disentuh tidak jadi titik, dan tidak menggeser
     * penomoran titik sesudahnya.
     *
     * Kertasnya punya sepuluh baris dan satu set anak timbangan jarang mengisi
     * kesepuluhnya. Baris kosong yang tetap memakan satu slot `titik_ke`
     * menggeser seluruh titik sesudahnya — dan geseran itu mendarat langsung di
     * sertifikat, karena `PerhitunganBuilder` mencetak baris per `titik_ke`.
     */
    public function test_keping_kosong_tidak_menggeser_penomoran(): void
    {
        $sesi = $this->kirim();

        $this->assertSame(
            [1, 2, 3],
            $sesi->rawMeasurements->pluck('titik_ke')->unique()->sort()->values()->all(),
            'Keping keempat yang kosong ikut memakan nomor titik — atau salah satu titik terisi hilang.',
        );
    }

    /**
     * Titik yang perannya belum lengkap DITOLAK, bukan dihitung dari tiga suku.
     */
    public function test_titik_yang_perannya_belum_lengkap_tidak_terbit(): void
    {
        $sesi = $this->kirim();

        $this->assertNull(
            $sesi->uncertaintyCalculations()->where('titik_ke', 3)->first(),
            'Keping tanpa `at_t2` tetap terbit. `de` yang lahir dari tiga suku bukan sekadar '
            .'kurang teliti — dia besaran yang berbeda.',
        );
    }

    /**
     * Jalur hitung ulang memulangkan angka yang SAMA dengan jalur simpan.
     *
     * `kalibrasi:hitung-ulang` membaca dari `raw_measurements`, bukan dari
     * payload. Kalau kosakata perannya tidak sepakat antara dua jalur itu,
     * bedanya baru ketahuan berbulan-bulan kemudian — dan yang berubah angka di
     * sertifikat yang sudah terbit.
     */
    public function test_hitung_ulang_memulangkan_angka_yang_sama(): void
    {
        $sesi = $this->kirim();

        $sebelum = $sesi->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->pluck('ketidakpastian_diperluas', 'titik_ke')
            ->map(static fn ($x): float => (float) $x)
            ->all();

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesi->nomor_sesi]])->assertSuccessful();

        $sesudah = $sesi->refresh()->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->pluck('ketidakpastian_diperluas', 'titik_ke')
            ->map(static fn ($x): float => (float) $x)
            ->all();

        $this->assertSame(array_keys($sebelum), array_keys($sesudah));

        // Toleransinya 1e-8, yaitu SATU satuan di tempat terakhir kolom
        // `decimal(20,8)` — bukan angka yang dilonggarkan supaya lolos.
        //
        // Jalur simpan membulatkan lebih dulu lewat `bulatkanHitungan()`
        // (`desimalU95()` = 8); jalur hitung ulang menulis apa adanya dan
        // membiarkan kolomnya yang membulatkan. Di MySQL keduanya mendarat
        // identik karena kolomnya memang decimal. Di SQLite presisi desimal
        // diabaikan, jadi nilai mentahnya bertahan dan selisihnya muncul —
        // 2,1e-9 pada titik 1, di BAWAH resolusi kolom yang sebenarnya.
        //
        // Menyetel toleransinya lebih ketat dari presisi penyimpanan berarti
        // menguji sesuatu yang skemanya sendiri tidak menjanjikan; yang perlu
        // dijaga di sini kedua jalur sepakat sampai digit yang benar-benar
        // tersimpan.
        foreach ($sebelum as $titikKe => $u95) {
            $this->assertEqualsWithDelta($u95, $sesudah[$titikKe], 1e-8, "Titik {$titikKe} bergeser.");
        }
    }
}
