<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\ProfilGenerik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Aturan yang harus dipenuhi SETIAP lembar kerja, bukan cuma yang lagi digarap.
 *
 * ## Kenapa disapu semua, bukan diuji satu-satu
 *
 * Tiga kali berturut-turut yang bolong itu profil yang NGGAK lagi disentuh:
 *
 *  - Enclosure nggak punya `equipment_id`, jadi sesinya nggak bisa dikirim
 *    sama sekali — ketahuan berminggu-minggu sesudah profilnya jadi.
 *  - `lokasi_nama`/`room_id` (permintaan 2) awalnya cuma nempel di 2 dari 12
 *    profil, padahal permintaannya "di SEMUA lembar kerja".
 *  - Nomor formulir kosong di dua profil, dan nggak ada yang gagal karenanya.
 *
 * Pola bersamanya: nggak satu pun bikin error. Lembarnya tetap terbit, cuma
 * bolong. Test per-profil nggak pernah menangkap ini karena yang bolong justru
 * profil yang nggak punya test.
 *
 * Jadi daftarnya diambil dari REGISTRY, bukan diketik di sini. Profil ke-13
 * yang ditambahkan besok langsung ikut diuji tanpa ada yang perlu ingat.
 */
class SemuaProfilLembarKerjaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Semua profil BERLEMBAR yang terdaftar.
     *
     * [ProfilGenerik] sengaja NGGAK ikut: dia satu-satunya yang memang nggak
     * punya lembar kerja, dan `bentukLembarKerja()`-nya melempar. Kontraknya
     * diuji sendiri di [test_profil_generik_nggak_punya_lembar_dan_ditolak_endpoint]
     * — bukan dikecualikan diam-diam.
     *
     * Daftarnya dibaca dari REGISTRY, bukan diketik di sini. Profil ke-13 yang
     * ditambahkan besok langsung ikut diuji tanpa ada yang perlu ingat.
     *
     * @return array<string, array{CalibrationProfile}>
     */
    public static function semuaProfil(): array
    {
        $registry = app(CalibrationProfileRegistry::class);

        $prop = (new ReflectionClass($registry))->getProperty('profil');
        $prop->setAccessible(true);

        /** @var list<CalibrationProfile> $profil */
        $profil = $prop->getValue($registry);

        $hasil = [];
        foreach ($profil as $p) {
            $hasil[$p->kode()] = [$p];
        }

        return $hasil;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
    }

    /**
     * Semua kode field dalam satu lembar, digabung dari seluruh bagian.
     *
     * @return list<string>
     */
    private function kodeField(CalibrationProfile $profil): array
    {
        $kode = [];

        foreach ($profil->bentukLembarKerja()['bagian'] ?? [] as $bagian) {
            foreach ($bagian['field'] ?? [] as $field) {
                $kode[] = $field['kode'] ?? '(tanpa kode)';
            }
        }

        return $kode;
    }

    /**
     * INTI: tanpa `equipment_id`, sesinya nggak bisa dikirim sama sekali.
     *
     * Tombol kirim menahan kalau alat belum dipilih, jadi lembar tanpa kotak
     * ini bikin teknisi mentok tanpa tahu apa yang kurang.
     */
    #[DataProvider('semuaProfil')]
    public function test_setiap_lembar_punya_kotak_pilih_alat(CalibrationProfile $profil): void
    {
        $this->assertContains(
            'equipment_id',
            $this->kodeField($profil),
            "Profil `{$profil->kode()}` nggak punya `equipment_id` — sesinya nggak bisa dikirim.",
        );
    }

    /**
     * Permintaan 2: Inlab pilih ruangan, Insitu tulis nama PT. **Di semua lembar.**
     */
    #[DataProvider('semuaProfil')]
    public function test_setiap_lembar_punya_kotak_lokasi(CalibrationProfile $profil): void
    {
        $kode = $this->kodeField($profil);

        $this->assertContains('room_id', $kode, "Profil `{$profil->kode()}` nggak punya pilihan ruangan (Inlab).");
        $this->assertContains('lokasi_nama', $kode, "Profil `{$profil->kode()}` nggak punya isian nama tempat (Insitu).");
    }

    /**
     * Dua kotak lokasi nggak boleh tampil barengan.
     *
     * Bug sertifikat Insitu lahirnya persis dari sini: dropdown Ruangan tetap
     * menyimpan pilihan lama walau sedang Insitu, lalu nilai itu ikut terkirim
     * dan tercetak di sertifikat sebagai tempat kalibrasi yang salah.
     */
    #[DataProvider('semuaProfil')]
    public function test_dua_kotak_lokasi_saling_meniadakan(CalibrationProfile $profil): void
    {
        $field = [];
        foreach ($profil->bentukLembarKerja()['bagian'] ?? [] as $bagian) {
            foreach ($bagian['field'] ?? [] as $f) {
                $field[$f['kode'] ?? ''] = $f;
            }
        }

        $this->assertSame(
            ['onsite'],
            $field['lokasi_nama']['tampil_kalau']['nilai'] ?? null,
            "Profil `{$profil->kode()}`: nama tempat harus cuma muncul waktu Insitu.",
        );
        $this->assertSame(
            ['lab'],
            $field['room_id']['tampil_kalau']['nilai'] ?? null,
            "Profil `{$profil->kode()}`: pilihan ruangan harus cuma muncul waktu Inlab.",
        );
    }

    /**
     * Nggak ada kode field kembar dalam satu lembar.
     *
     * Ini yang paling diam dari semuanya. Kotak kembar nggak bikin error apa
     * pun: dua kotak digambar, dua-duanya bisa diketik, dan yang sampai ke
     * server cuma SATU — yang belakangan menimpa yang duluan. Teknisi lihat
     * angkanya masuk, lalu angka itu nggak ada di sertifikat, dan nggak ada
     * satu pun pesan yang menjelaskan ke mana perginya.
     */
    #[DataProvider('semuaProfil')]
    public function test_nggak_ada_kode_field_kembar(CalibrationProfile $profil): void
    {
        $kode = $this->kodeField($profil);

        $kembar = array_keys(array_filter(array_count_values($kode), static fn (int $n): bool => $n > 1));

        $this->assertSame(
            [],
            $kembar,
            "Profil `{$profil->kode()}` punya kode field kembar: ".implode(', ', $kembar),
        );
    }

    /**
     * Nomor formulir ada, atau memang belum ketahuan — dan yang belum ketahuan
     * ditulis di sini, bukan dibiarkan lolos diam-diam.
     *
     * Lembar kerja lab terakreditasi tanpa nomor formulir itu temuan audit.
     * Yang masih `null` cuma boleh yang kertasnya beneran belum ada di tangan;
     * begitu kertasnya datang, hapus dia dari daftar ini — dan test bakal
     * merah kalau ada yang menambahkan `null` baru tanpa alasan.
     */
    #[DataProvider('semuaProfil')]
    public function test_nomor_formulir_ada_kecuali_yang_kertasnya_belum_ada(CalibrationProfile $profil): void
    {
        // Kertasnya beneran belum pernah dikirim lab. Bukan kelupaan.
        $belumAdaKertasnya = ['gas_detector'];

        $nomor = $profil->bentukLembarKerja()['kode_dokumen'] ?? null;

        if (in_array($profil->kode(), $belumAdaKertasnya, true)) {
            $this->assertNull(
                $nomor,
                "Profil `{$profil->kode()}` sekarang punya nomor formulir — keluarkan dia dari daftar `belumAdaKertasnya`.",
            );

            return;
        }

        $this->assertIsString($nomor, "Profil `{$profil->kode()}` belum punya nomor formulir.");
        $this->assertMatchesRegularExpression(
            '/^SIDIK-FM-CAL-\d{4}_Rev\.\d+$/',
            $nomor,
            "Nomor formulir `{$profil->kode()}` nggak berbentuk `SIDIK-FM-CAL-NNNN_Rev.N`.",
        );
    }

    /**
     * [ProfilGenerik] memang nggak punya lembar — dan yang MENJAGA itu bukan
     * lemparannya, tapi endpoint yang menyaringnya lebih dulu.
     *
     * Sampai 24 Agt 2026 `untukNamaAlat()` jatuh ke pH buat nama apa pun yang
     * nggak dikenali, jadi `?equipment_id=` sebuah Buret memulangkan lembar
     * buffer 4/7/10 dengan status 200. Teknisi mengisi tiga titik pH untuk
     * buret, sesinya terkirim, dan nol error di sepanjang jalur itu.
     *
     * Yang diuji di sini dua-duanya sekaligus:
     *
     *  1. Lemparannya masih ada — kalau seseorang "membetulkannya" jadi
     *     memulangkan lembar kosong, kegagalan diamnya balik lagi.
     *  2. Endpoint nggak pernah SAMPAI ke lemparan itu: dia menjawab 422
     *     dengan alasan yang bisa dibaca teknisi, bukan 500.
     *
     * Alat yang baru ditambahkan teknisi sendiri (permintaan 1) lewat jalur
     * ini, jadi inilah lembar yang paling mungkin dibuka tanpa ada yang pernah
     * memeriksanya.
     */
    public function test_profil_generik_nggak_punya_lembar_dan_ditolak_endpoint(): void
    {
        $this->expectException(\LogicException::class);

        try {
            $this->actingAs(\App\Models\User::factory()->create())
                ->getJson('/api/calibrations/lembar-kerja?instrumen=Buret+Digital')
                ->assertStatus(422)
                ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'form generik'));
        } catch (\Throwable $e) {
            $this->fail('Endpoint harus menjawab 422, bukan melempar: '.$e->getMessage());
        }

        // Baru di sini lemparannya dibuktikan — langsung ke profilnya, bukan
        // lewat HTTP.
        (new ProfilGenerik)->bentukLembarKerja();
    }

    /**
     * Nomor formulir nggak boleh dipakai dua profil yang beda.
     *
     * Kalau kembar, lembar tercetaknya mengaku formulir yang bukan dirinya —
     * dan yang ketahuan duluan biasanya auditor, bukan kita.
     */
    public function test_nomor_formulir_nggak_dipakai_dua_profil(): void
    {
        $pakai = [];

        foreach (self::semuaProfil() as [$profil]) {
            $nomor = $profil->bentukLembarKerja()['kode_dokumen'] ?? null;

            if ($nomor === null) {
                continue;
            }

            $pakai[$nomor][] = $profil->kode();
        }

        // Kelima profil enclosure memang SATU formulir (`0504`) — itu yang
        // tertulis di kertasnya, bukan kelalaian. Yang dilarang: dua ALAT BEDA
        // berbagi nomor.
        $kembar = array_filter(
            $pakai,
            static fn (array $kode, string $nomor): bool => count($kode) > 1 && $nomor !== 'SIDIK-FM-CAL-0504_Rev.3',
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertSame([], $kembar, 'Nomor formulir dipakai lebih dari satu profil: '.json_encode($kembar));
    }
}
