<?php

namespace Tests\Feature;

use App\Models\CalibrationMethod;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\DokumenBacaan;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Folder;
use App\Models\FolderFile;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Standard;
use App\Models\User;
use App\Models\WorksheetScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RuteLaravel;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tiap rute internal menolak role yang bukan orang lab — deny by default.
 *
 * ## Celah yang ditutup
 *
 * Sebelum M0-06, sebagian besar rute GET di grup `auth:sanctum` cuma disaring
 * `organization_id` di controller. Itu cukup selama semua pemegang akun memang
 * orang lab. Begitu role `pelanggan` lahir dan akunnya duduk di organisasi PT
 * Sidik, `organization_id`-nya COCOK — dan PT A bisa membaca alat & sertifikat
 * PT B lewat rute internal. Kerahasiaan antar pelanggan, ISO/IEC 17025 §4.2
 * (06-Risk-Register R-D01, level kritis).
 *
 * ## Kenapa daftarnya dibaca dari router, bukan ditulis tangan
 *
 * Daftar tulis tangan basi diam-diam. Rute baru yang lupa dipagari nggak bikin
 * apa pun merah — dia cuma nggak ada di daftar. Di sini daftarnya diambil dari
 * `Router::getRoutes()`, jadi rute yang lahir besok ikut diuji tanpa ada yang
 * perlu mengingat.
 *
 * Konsekuensinya: rute publik BARU harus didaftarkan di [PUBLIK] dengan sadar.
 * Itu disengaja — membuka rute ke orang luar memang keputusan yang pantas
 * ditulis, bukan efek samping.
 *
 * ## Kenapa usernya nggak disimpan
 *
 * `users.role` itu enum(`admin`,`teknisi`,`viewer`) di MySQL, jadi baris
 * ber-role `pelanggan` nggak bisa disimpan sebelum migrasi Fase 3. Yang dipakai
 * user yang dibikin di memori (`make()`) + `Sanctum::actingAs`. Aman, karena
 * `role:` menolak jauh sebelum controller menyentuh database.
 *
 * ## Kenapa parameternya diisi ID yang beneran ada
 *
 * Prioritas middleware Laravel menjalankan `SubstituteBindings` SEBELUM `role:`.
 * ID karangan bikin rute membalas 404 duluan — dan 404 itu nggak membuktikan
 * apa-apa soal gerbangnya. Yang membuktikan cuma 403 atas ID yang sah.
 */
class RuteInternalMenolakRoleLainTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rute yang memang buat orang luar, tanpa token.
     *
     * Ditulis lengkap dengan URI-nya, bukan prefix, supaya `api/login` yang
     * dibuka nggak diam-diam ikut membuka `api/login-sebagai-siapa-saja`.
     */
    private const PUBLIK = [
        'api/health',
        'api/login',
        'api/register',
        'api/forgot-password',
        'api/reset-password',
        'api/app/versi-terbaru',
        'api/verify/{qr_token}',
    ];

    /**
     * Modul pelanggan punya gerbangnya sendiri (`aplikasi:pelanggan` +
     * `role:pelanggan`, Fase 3-4), jadi dia bukan urusan test ini.
     *
     * Sudah didaftarkan dari sekarang walau rutenya belum ada: begitu
     * `routes/api_pelanggan.php` lahir, test ini nggak ikut memerahkannya.
     */
    private const PREFIX_DILEWAT = [
        'api/pelanggan/',
    ];

    private User $pelanggan;

    /** @var array<string, string> nama parameter → nilai yang dipakai di URL */
    private array $isiParameter = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('arsip');

        $org = Organization::factory()->create(['nama' => 'PT Contoh Satu']);

        $admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN]);
        $teknisi = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_TEKNISI]);

        // Role yang belum ada di enum `users.role`. Sengaja nggak disimpan.
        $this->pelanggan = User::factory()->make([
            'organization_id' => $org->id,
            'role' => 'pelanggan',
            'status' => User::STATUS_AKTIF,
        ]);
        $this->pelanggan->id = 999_999;

        $pelangganPt = Customer::factory()->create([
            'organization_id' => $org->id,
            'nama' => 'PT Contoh Dua',
        ]);
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $org->id]);
        $alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $pelangganPt->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Timbangan Contoh',
        ]);
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $org->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisi->id,
        ]);
        $sertifikat = Certificate::factory()->create([
            'organization_id' => $org->id,
            'calibration_session_id' => $sesi->id,
        ]);
        $folder = Folder::factory()->create(['organization_id' => $org->id]);
        $berkas = FolderFile::factory()->create([
            'organization_id' => $org->id,
            'folder_id' => $folder->id,
        ]);
        $rumus = Formula::factory()->create(['organization_id' => $org->id]);
        $versiRumus = FormulaVersion::factory()->create(['formula_id' => $rumus->id]);
        $order = Order::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $pelangganPt->id,
        ]);
        $ruang = Room::factory()->create(['organization_id' => $org->id]);
        $standar = Standard::factory()->create(['organization_id' => $org->id]);
        $pindai = WorksheetScan::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $teknisi->id,
        ]);

        // Satu-satunya model tanpa factory di antara yang dipakai rute.
        $bacaan = DokumenBacaan::create([
            'organization_id' => $org->id,
            'user_id' => $teknisi->id,
            'judul' => 'Lembar Contoh',
            'status' => DokumenBacaan::STATUS_OK,
        ]);

        $this->isiParameter = [
            'calibration' => (string) $sesi->id,
            'calibrationMethod' => (string) CalibrationMethod::factory()->create(['organization_id' => $org->id])->id,
            'certificate' => (string) $sertifikat->id,
            'customer' => (string) $pelangganPt->id,
            'dokumenBacaan' => (string) $bacaan->id,
            'equipment' => (string) $alat->id,
            'folder' => (string) $folder->id,
            'folderFile' => (string) $berkas->id,
            'formula' => (string) $rumus->id,
            'formulaVersion' => (string) $versiRumus->id,
            'order' => (string) $order->id,
            'room' => (string) $ruang->id,
            'standard' => (string) $standar->id,
            'technician' => (string) $teknisi->id,
            'user' => (string) $admin->id,
            'worksheetScan' => (string) $pindai->id,

            // Bukan model binding — string biasa, jadi nilainya bebas.
            'kode' => $kategori->kode ?? 'CONTOH',
            'kunci' => 'A1',
            'notification' => '00000000-0000-4000-8000-000000000000',
        ];
    }

    /**
     * INTI-nya: 134-an rute internal, satu pun nggak boleh menjawab selain 403.
     *
     * Yang dikumpulkan daftar pelanggarnya, bukan gagal di rute pertama —
     * kalau sepuluh rute bocor, yang berguna melihat kesepuluhnya sekaligus.
     */
    public function test_semua_rute_internal_menolak_role_tak_dikenal(): void
    {
        Sanctum::actingAs($this->pelanggan);

        $pelanggar = [];
        $diuji = 0;

        foreach ($this->ruteInternal() as [$method, $uri]) {
            $status = $this->json($method, '/'.$this->isiUri($uri))->status();
            $diuji++;

            if ($status !== 403) {
                $pelanggar[] = "{$method} {$uri} → {$status}";
            }
        }

        $this->assertGreaterThan(
            100,
            $diuji,
            "Cuma {$diuji} rute yang keuji — daftar rutenya kemungkinan nggak kebaca, ".
            'dan test ini jadi hijau tanpa memeriksa apa pun.'
        );

        $this->assertSame([], $pelanggar, sprintf(
            "%d dari %d rute internal nggak menjawab 403 buat role tak dikenal.\n".
            'Yang bukan 403 berarti gerbangnya nggak kena — 404 pun nggak cukup, '.
            "karena dia cuma bilang datanya nggak ketemu, bukan aksesnya ditolak.\n\n  %s",
            count($pelanggar),
            $diuji,
            implode("\n  ", $pelanggar),
        ));
    }

    /** Rute publik tetap kebuka tanpa token — memastikan saya nggak kebablasan menutup. */
    public function test_rute_publik_tetap_bisa_diakses_tanpa_token(): void
    {
        $this->getJson('/api/health')->assertOk();
        $this->getJson('/api/app/versi-terbaru')->assertOk();

        // Kredensial salah dijawab 401 ("siapa kamu nggak ketahuan"), BUKAN 403
        // ("kamu ketahuan tapi nggak boleh"). 403 di sini artinya layar login
        // ikut kepagar gerbang role dan nggak bisa dipakai siapa pun — termasuk
        // teknisi yang tokennya kadung kedaluwarsa.
        $this->postJson('/api/login', ['identifier' => 'bukan-siapa-siapa', 'password' => 'salah'])
            ->assertUnauthorized();
    }

    /**
     * Penjaga daftar pengecualian.
     *
     * Kalau suatu hari ada yang menaruh rute baru di luar grup `auth:sanctum`
     * tanpa mendaftarkannya di [PUBLIK], test ini yang menyebut namanya —
     * bukan test di atas, yang bakal melaporkannya sebagai "menjawab 401".
     */
    public function test_tidak_ada_rute_publik_yang_tidak_terdaftar(): void
    {
        $tanpaGerbang = [];

        foreach (Router::getRoutes() as $rute) {
            $uri = $rute->uri();

            if (! str_starts_with($uri, 'api/') || $this->dilewat($uri)) {
                continue;
            }

            $punyaGerbang = false;

            foreach ($rute->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                    $punyaGerbang = true;
                    break;
                }
            }

            if (! $punyaGerbang) {
                $tanpaGerbang[] = implode('|', $this->methodDipakai($rute))." {$uri}";
            }
        }

        $this->assertSame([], $tanpaGerbang, sprintf(
            "Rute `api/` ini nggak punya gerbang `role:` dan nggak terdaftar sebagai publik:\n  %s\n\n".
            'Kalau memang buat orang luar, daftarkan di RuteInternalMenolakRoleLainTest::PUBLIK '.
            'dengan sadar. Kalau nggak, taruh di dalam grup `auth:sanctum`.',
            implode("\n  ", $tanpaGerbang),
        ));
    }

    /**
     * Daftar rute internal yang diuji: `[method, uri]`.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function ruteInternal(): array
    {
        $daftar = [];

        foreach (Router::getRoutes() as $rute) {
            $uri = $rute->uri();

            if (! str_starts_with($uri, 'api/') || $this->dilewat($uri)) {
                continue;
            }

            foreach ($this->methodDipakai($rute) as $method) {
                $daftar[] = [$method, $uri];
            }
        }

        return $daftar;
    }

    /**
     * Method yang benar-benar dipanggil klien.
     *
     * `HEAD` dibuang karena dia cuma bayangan `GET`, dan `OPTIONS` karena dia
     * preflight CORS yang memang nggak lewat gerbang role.
     *
     * @return list<string>
     */
    private function methodDipakai(RuteLaravel $rute): array
    {
        return array_values(array_diff($rute->methods(), ['HEAD', 'OPTIONS']));
    }

    private function dilewat(string $uri): bool
    {
        if (in_array($uri, self::PUBLIK, true)) {
            return true;
        }

        foreach (self::PREFIX_DILEWAT as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Ganti tiap `{param}` dengan nilai yang beneran ada. */
    private function isiUri(string $uri): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', function (array $cocok): string {
            $nama = $cocok[1];

            $this->assertArrayHasKey(
                $nama,
                $this->isiParameter,
                "Parameter rute `{{$nama}}` belum punya nilai di RuteInternalMenolakRoleLainTest::setUp(). ".
                'Rute baru dengan jenis parameter baru wajib dikasih fixture, kalau nggak dia bakal '.
                'membalas 404 dan gerbangnya nggak pernah keuji.'
            );

            return $this->isiParameter[$nama];
        }, $uri);
    }
}
