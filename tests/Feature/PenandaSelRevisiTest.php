<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Support\KodeSelRevisi;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penolakan admin bisa menandai SATU SEL, bukan cuma satu kolom.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * `revisi_field` cuma bisa menunjuk kolom identitas — `alat_model`,
 * `pemilik_nama`. Yang paling sering minta dibetulkan justru satu ANGKA di
 * dalam tabel pengukuran, di antara puluhan angka lain yang sudah benar.
 *
 * Yang bisa dilakukan admin cuma dua-duanya buruk: nulis prosa dan berharap
 * teknisi nemu kotaknya pakai mata, atau nandai seluruh tabelnya. Yang kedua
 * itu yang bikin teknisi ngosongin tabel lalu ngetik ulang semuanya — termasuk
 * angka yang sudah benar, yang lalu berisiko salah ketik BARU di sesi revisi.
 *
 * ## Yang dijaga di sini
 *
 * 1. Kode selnya bolak-balik utuh, dan yang dipakai TITIK UKUR — bukan
 *    `titik_ke` yang posisinya geser tiap bentuk lembar berubah.
 * 2. Nggak pernah lewat 64 karakter, batas `revisi_field.*` di `reject()`.
 *    Kode yang kepotong masih kelihatan sah tapi nunjuk sel yang beda.
 * 3. Temuan validator yang nunjuk satu pembacaan bawa `kode_sel`-nya sendiri,
 *    supaya layar admin bisa nawarin "tandai yang ini" sekali ketuk.
 * 4. `reject` beneran nyimpen kode sel apa adanya, dan mulanginnya di respons.
 */
class PenandaSelRevisiTest extends TestCase
{
    use RefreshDatabase;

    public function test_kode_sel_bolak_balik_utuh(): void
    {
        $kode = KodeSelRevisi::buat('sesudah_adjustment', 1412.0, 'pembacaan', 3);

        $this->assertSame('sel:sesudah_adjustment:1412:pembacaan:3', $kode);
        $this->assertTrue(KodeSelRevisi::adalahKodeSel($kode));

        $this->assertSame([
            'tahap' => 'sesudah_adjustment',
            'titik_ukur' => 1412.0,
            'kolom' => 'pembacaan',
            'pembacaan_ke' => 3,
        ], KodeSelRevisi::urai($kode));
    }

    /**
     * Angkanya dipendekin, tapi nilainya nggak berubah.
     *
     * `decimal(20,8)` bikin `1412.00000000` — 11 karakter dari jatah 64 tanpa
     * nambah satu pun informasi.
     */
    #[DataProvider('angkaTitik')]
    public function test_titik_ukur_ditulis_ringkas(float $titik, string $harap): void
    {
        $kode = KodeSelRevisi::buat('sesudah_adjustment', $titik, 'pembacaan', 1);

        $this->assertSame("sel:sesudah_adjustment:{$harap}:pembacaan:1", $kode);
        $this->assertSame($titik, KodeSelRevisi::urai($kode)['titik_ukur']);
    }

    /** @return array<string, array{float, string}> */
    public static function angkaTitik(): array
    {
        return [
            'bulat' => [1412.0, '1412'],
            'desimal' => [7.01, '7.01'],
            'negatif' => [-20.0, '-20'],
            'nol' => [0.0, '0'],
            'pecahan kecil' => [1.412, '1.412'],
        ];
    }

    /** Kode yang kepanjangan DIBUANG, bukan dipotong. */
    public function test_kode_kepanjangan_dibuang_bukan_dipotong(): void
    {
        $kolom = str_repeat('x', 64);

        $this->assertNull(KodeSelRevisi::buat('sesudah_adjustment', 1412.0, $kolom, 3));
    }

    /** @return array<string, array{string}> */
    public static function kodeRusak(): array
    {
        return [
            'kode kolom biasa' => ['alat_model'],
            'kurang bagian' => ['sel:sesudah_adjustment:1412:pembacaan'],
            'kelebihan bagian' => ['sel:sesudah_adjustment:1412:pembacaan:3:4'],
            'repeat bukan angka' => ['sel:sesudah_adjustment:1412:pembacaan:tiga'],
            'repeat nol' => ['sel:sesudah_adjustment:1412:pembacaan:0'],
            'titik bukan angka' => ['sel:sesudah_adjustment:awal:pembacaan:3'],
            'tahap kosong' => ['sel::1412:pembacaan:3'],
            'prefiks lain' => ['field:sesudah_adjustment:1412:pembacaan:3'],
        ];
    }

    #[DataProvider('kodeRusak')]
    public function test_kode_rusak_diurai_jadi_null(string $kode): void
    {
        $this->assertNull(KodeSelRevisi::urai($kode));
    }

    /**
     * Temuan yang nunjuk satu pembacaan bawa kode selnya.
     *
     * Tanpa ini layar admin cuma punya prosa buat diketuk, dan penandaannya
     * balik lagi jadi urusan mata teknisi.
     */
    public function test_temuan_pembacaan_bawa_kode_sel(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::whereHas('rawMeasurements')
            ->whereNull('reviewed_at')
            ->firstOrFail();

        // Satu pembacaan digeser komanya — persis salah ketik yang dicari
        // validator, dan persis yang pantas ditandai satu kotak.
        //
        // Nilainya dibikin jauh di luar rentang dengan sengaja: `diLuarRentang`
        // punya pita jaga selebar toleransi alat, dan titik teratas justru duduk
        // persis di batas rentang. Angka yang cuma lewat sedikit memang nggak
        // pantas ke-flag — yang diuji di sini bukan ambangnya, tapi kode selnya.
        $baris = $sesi->rawMeasurements()->whereNull('peran_sensor')->firstOrFail();
        $baris->update(['pembacaan' => 999999]);

        $admin = User::where('organization_id', $sesi->organization_id)
            ->where('role', User::ROLE_ADMIN)
            ->firstOrFail();

        $temuan = $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->json('data.temuan');

        $berkode = array_values(array_filter(
            $temuan,
            static fn (array $t): bool => ($t['konteks']['kode_sel'] ?? null) !== null,
        ));

        $this->assertNotEmpty(
            $berkode,
            'Nol temuan yang bawa `kode_sel` — layar admin nggak punya apa pun buat diketuk, '
            .'dan penolakannya balik jadi "ulangi tabelnya".',
        );

        foreach ($berkode as $t) {
            $kode = $t['konteks']['kode_sel'];

            $this->assertLessThanOrEqual(64, strlen($kode), "Kode `{$kode}` lewat batas `max:64`.");

            $urai = KodeSelRevisi::urai($kode);

            $this->assertNotNull($urai, "Kode `{$kode}` nggak bisa diurai balik.");
            $this->assertSame($t['konteks']['pembacaan_ke'], $urai['pembacaan_ke']);
        }
    }

    /**
     * Tiap kode sel yang dikirim nunjuk TEPAT SATU baris pembacaan.
     *
     * Ini yang bikin penandanya jujur. Di matriks Autoklaf delapan baris
     * besaran — `Temp. Disk 1`, `Indikator Pressure`, dst. — semuanya
     * ber-`titik_ukur` nol, jadi kode yang cuma menyebut titik bakal nunjuk
     * delapan kotak sekaligus. Yang kesorot merah jadi angka yang justru sudah
     * benar, teknisi mbetulin yang nggak salah, dan yang salah tetap lolos.
     *
     * Disapu ke SELURUH sesi hasil seeder, bukan cuma satu alat: alat
     * berikutnya yang lembarnya kembar titik bakal ketahuan di sini, bukan di
     * lembar kerja pelanggan.
     */
    public function test_kode_sel_nggak_pernah_nunjuk_dua_baris(): void
    {
        $this->seed(DatabaseSeeder::class);

        $diperiksa = 0;

        foreach (CalibrationSession::whereHas('rawMeasurements')->with('rawMeasurements')->get() as $sesi) {
            $admin = User::where('organization_id', $sesi->organization_id)
                ->where('role', User::ROLE_ADMIN)
                ->first();

            if ($admin === null) {
                continue;
            }

            $temuan = $this->actingAs($admin)
                ->getJson("/api/calibrations/{$sesi->id}/validasi")
                ->assertOk()
                ->json('data.temuan');

            foreach ($temuan as $t) {
                $kode = $t['konteks']['kode_sel'] ?? null;

                if ($kode === null) {
                    continue;
                }

                $urai = KodeSelRevisi::urai($kode);
                $this->assertNotNull($urai, "Kode `{$kode}` nggak bisa diurai balik.");

                $cocok = $sesi->rawMeasurements->filter(
                    fn ($m): bool => $m->peran_sensor === null
                        && $m->tahap === $urai['tahap']
                        && abs((float) $m->titik_ukur - $urai['titik_ukur']) < 1e-6
                        && (int) $m->pembacaan_ke === $urai['pembacaan_ke'],
                );

                $this->assertCount(
                    1,
                    $cocok,
                    "Kode `{$kode}` (sesi {$sesi->nomor_sesi}) nunjuk {$cocok->count()} baris. "
                    .'Penanda yang nunjuk lebih dari satu kotak bikin teknisi mbetulin angka yang bener.',
                );

                $diperiksa++;
            }
        }

        $this->assertGreaterThan(
            0,
            $diperiksa,
            'Nol kode sel di seluruh sesi seeder — penjaga ini nggak menguji apa pun.',
        );
    }

    /**
     * Titik yang KEMBAR nggak dikasih kode sel sama sekali.
     *
     * Kembarnya dibikin di sini dengan sengaja karena data seeder hari ini
     * kebetulan nggak punya — dan "kebetulan nggak punya" itu bukan penjagaan.
     * Bentuk yang bikin kembar sudah ada di produk: matriks Autoklaf menaruh
     * delapan baris besaran dengan `titik_ukur` nol semua.
     *
     * Yang diuji: temuannya TETAP muncul (angkanya memang mencurigakan), yang
     * hilang cuma kodenya. Prosa yang menyebut posisinya tetap sampai ke admin.
     */
    public function test_titik_kembar_nggak_dikasih_kode_sel(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::whereHas('rawMeasurements')
            ->whereNull('reviewed_at')
            ->firstOrFail();

        $baris = $sesi->rawMeasurements()->whereNull('peran_sensor')->firstOrFail();
        $baris->update(['pembacaan' => 999999]);

        // Baris kedua yang menempati (tahap, titik ukur, pengulangan) yang sama
        // persis — beda `titik_ke`, sama kayak dua baris besaran di satu matriks.
        $kembar = $baris->replicate(['id']);
        $kembar->titik_ke = $baris->titik_ke + 100;
        $kembar->save();

        $admin = User::where('organization_id', $sesi->organization_id)
            ->where('role', User::ROLE_ADMIN)
            ->firstOrFail();

        $temuan = $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->json('data.temuan');

        $atasAngkaKembar = array_values(array_filter(
            $temuan,
            static fn (array $t): bool => ($t['konteks']['nilai'] ?? null) == 999999,
        ));

        $this->assertNotEmpty(
            $atasAngkaKembar,
            'Temuannya ikut hilang — yang mau dijaga cuma kodenya, bukan peringatannya.',
        );

        foreach ($atasAngkaKembar as $t) {
            $this->assertNull(
                $t['konteks']['kode_sel'] ?? null,
                'Titik kembar dikasih kode sel — penandanya bakal nunjuk dua kotak sekaligus, '
                .'dan teknisi mbetulin angka yang justru sudah bener.',
            );
        }
    }

    /** `reject` nerima & nyimpen kode sel, lalu mulanginnya utuh. */
    public function test_reject_nyimpen_kode_sel(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('status', CalibrationSession::STATUS_MENUNGGU_APPROVAL)
            ->firstOrFail();

        $admin = User::where('organization_id', $sesi->organization_id)
            ->where('role', User::ROLE_ADMIN)
            ->firstOrFail();

        $kode = KodeSelRevisi::buat('sesudah_adjustment', 1412.0, 'pembacaan', 3);

        $revisi = $this->actingAs($admin)
            ->postJson("/api/calibrations/{$sesi->id}/reject", [
                'catatan_revisi' => 'Repeat 3 di titik 1412 komanya kegeser — yang lain udah bener.',
                'revisi_field' => [$kode, 'alat_serial_number'],
            ])
            ->assertOk()
            ->json('data.revisi_field');

        $this->assertContains($kode, $revisi);
        $this->assertContains('alat_serial_number', $revisi);
    }
}
