<?php

namespace Tests\Feature;

use App\Models\CalibrationMethod;
use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tiap lembar kerja membawa bekal buat BIKIN ALAT BARU dari layar itu juga.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * Sejak dropdown "Pilih alat" disaring ke lembar yang lagi dibuka (26 Agt
 * 2026), kategori yang belum punya satu alat pun jadi BUNTU: dropdown-nya mati
 * dengan tulisan "Belum ada alat.", dan tombol kirim menahan sesi yang alatnya
 * belum dipilih. Lembar Bath persis begitu — bisa dibuka, bisa dibaca, nggak
 * bisa dipakai. Teknisi di lapangan mentok tanpa satu pun jalan keluar di layar
 * itu.
 *
 * Saringannya sendiri benar dan jangan dicabut: sebelum ada saringan, teknisi
 * yang membuka lembar Refrigerator disodori SELURUH alat lab, dan salah pilih
 * di situ nggak bikin error di mana pun — sesinya tersimpan lalu dihitung pakai
 * aturan alat lain.
 *
 * Jadi yang ditambah jalan keluarnya, bukan saringannya yang dilepas.
 */
class BekalAlatBaruTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function semuaProfil(): array
    {
        $hasil = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $p) {
            $hasil[$p->kode()] = [$p->kode()];
        }

        self::assertGreaterThanOrEqual(17, count($hasil), 'Registry profil menyusut.');

        return $hasil;
    }

    /** @return array<string, mixed> */
    private function bentuk(string $kode): array
    {
        $teknisi = User::where('role', User::ROLE_TEKNISI)
            ->where('status', User::STATUS_AKTIF)
            ->firstOrFail();

        return $this->actingAs($teknisi)
            ->getJson("/api/calibrations/lembar-kerja?profil={$kode}")
            ->assertOk()
            ->json('data');
    }

    /**
     * INTI: bekalnya ADA di ketujuh belas lembar, dan nama kemampuannya yang
     * benar-benar dipakai registry buat memilih profil ini.
     *
     * Nama yang meleset satu huruf bikin alat barunya lahir dengan jenis yang
     * nggak dikenali — dan alat yang jenisnya nggak dikenali jatuh ke form
     * generik, persis masalah yang saringan tadi mau cegah.
     */
    #[DataProvider('semuaProfil')]
    public function test_tiap_lembar_bawa_bekal_alat_baru(string $kode): void
    {
        $this->seed(DatabaseSeeder::class);

        $bekal = $this->bentuk($kode)['alat_baru'] ?? null;

        $this->assertIsArray($bekal, "Lembar `{$kode}` nggak bawa `alat_baru` — layarnya buntu kalau alatnya belum ada.");
        $this->assertArrayHasKey('kategori', $bekal);

        $nama = $bekal['nama_alat_kemampuan'] ?? null;

        $this->assertSame(
            app(CalibrationProfileRegistry::class)->untukKode($kode)->namaAlatKemampuan(),
            $nama,
            "Nama kemampuan di bekal beda dari yang dipakai profil `{$kode}`.",
        );

        // Bulak-balik: alat yang lahir dengan nama ini harus balik ke profil
        // yang sama. Kalau nggak, alat barunya dapat lembar yang beda dari
        // lembar tempat dia dibikin.
        $this->assertSame(
            $kode,
            app(CalibrationProfileRegistry::class)->untukNamaAlat($nama)->kode(),
            "Nama kemampuan `{$nama}` nggak balik ke profil `{$kode}`.",
        );
    }

    /**
     * Kategorinya kebaca buat lembar yang kemampuannya sudah ter-seed.
     *
     * Null itu sah — lab yang belum mendaftarkan kemampuannya tetap boleh
     * bikin alat, tinggal memilih kategorinya sendiri. Yang diuji di sini:
     * kalau datanya ADA, jangan sampai tetap null.
     */
    public function test_kategori_kebaca_buat_lembar_yang_kemampuannya_terdaftar(): void
    {
        $this->seed(DatabaseSeeder::class);

        $adaYangKebaca = false;

        foreach (array_keys(self::semuaProfil()) as $kode) {
            if (($this->bentuk($kode)['alat_baru']['kategori'] ?? null) !== null) {
                $adaYangKebaca = true;
                break;
            }
        }

        $this->assertTrue(
            $adaYangKebaca,
            'Nol lembar yang kategorinya kebaca — HP bakal selalu menyuruh teknisi menebak kategorinya.',
        );
    }

    /**
     * Teknisi boleh memilih Calibration Methode sendiri.
     *
     * Dulu kolom ini `hanyaAdmin` di ketujuh belas lembar DAN masuk
     * `CalibrationSession::fieldAdmin()`, jadi kiriman teknisi yang membawanya
     * dibuang diam-diam oleh `CalibrationRequest::prepareForValidation()` —
     * sengaja dibuang, bukan ditolak, supaya HP versi lama nggak gagal submit.
     * Akibatnya: teknisi memilih metodenya, menekan kirim, dan kolomnya sampai
     * di admin dalam keadaan kosong tanpa satu pun pesan.
     *
     * Buat alat yang BARU didaftarkan dari lembar kerja, nggak ada admin yang
     * bisa mengisinya lebih dulu — jadi selama kolom ini tertutup, alat baru
     * selalu lahir tanpa metode.
     */
    public function test_teknisi_bisa_menyimpan_metode_kalibrasi(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::whereNotNull('equipment_id')
            ->where('status', CalibrationSession::STATUS_DRAFT)
            ->first()
            ?? CalibrationSession::whereNotNull('equipment_id')->firstOrFail();

        $metode = CalibrationMethod::where('organization_id', $sesi->organization_id)->firstOrFail();

        $teknisi = User::where('organization_id', $sesi->organization_id)
            ->where('role', User::ROLE_TEKNISI)
            ->where('status', User::STATUS_AKTIF)
            ->firstOrFail();

        // `saveQuietly()`, bukan `save()`: ini MENYUSUN fixture, bukan menguji
        // perilaku aplikasi. Kalau nol sesi draft ter-seed, baris di atas jatuh
        // ke sesi mana pun — dan sesi itu bisa yang sudah disetujui BERIKUT
        // sertifikatnya. `CalibrationSession::booted()` menahan penurunan status
        // yang seperti itu (satu sesi produksi pernah mundur diam-diam dan
        // menelantarkan nomor sertifikat resmi), jadi lewat `save()` statusnya
        // tetap `disetujui` dan PUT-nya dijawab 422 — gagal yang nol
        // hubungannya dengan metode kalibrasi yang sedang diuji.
        $sesi->forceFill([
            'teknisi_id' => $teknisi->id,
            'status' => CalibrationSession::STATUS_DRAFT,
            'calibration_method_id' => null,
        ])->saveQuietly();

        $this->actingAs($teknisi)
            ->putJson("/api/calibrations/{$sesi->id}", [
                'equipment_id' => $sesi->equipment_id,
                'calibration_method_id' => $metode->id,
                'status' => CalibrationSession::STATUS_DRAFT,
                'measurements' => [],
            ])
            ->assertOk();

        $this->assertSame(
            $metode->id,
            $sesi->fresh()->calibration_method_id,
            'Metode yang dipilih teknisi dibuang diam-diam — kolomnya sampai di admin kosong.',
        );
    }

    /** Kolom yang MEMANG administratif tetap tertutup. */
    public function test_nomor_order_tetap_admin_saja(): void
    {
        $this->assertContains('nomor_order', CalibrationSession::fieldAdmin());
        $this->assertNotContains('calibration_method_id', CalibrationSession::fieldAdmin());
    }
}
