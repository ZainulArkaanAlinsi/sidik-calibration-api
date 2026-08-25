<?php

namespace Tests\Feature;

use App\Models\CalibrationMethod;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Folder;
use App\Models\Formula;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Batas antar lab, disapu ke SELURUH endpoint `show`, bukan satu per satu.
 *
 * ## Kenapa berkas ini ada
 *
 * `ScopeOrganisasiKemampuanTest` menjaga satu model — `CalibrationCapability`,
 * satu-satunya tempat bug ini pernah beneran kejadian. Yang nggak ada sampai
 * sekarang: sesuatu yang menangkap endpoint BARU yang lupa menyaring.
 *
 * Penyaringan organisasi di proyek ini ditegakkan TANGAN, per query. Nggak ada
 * global scope di model — 18 dari 19 model ber-`organization_id` nggak punya
 * penjaga di lapisan model sama sekali. Jadi endpoint ke-18 yang ditulis besok
 * aman cuma kalau yang menulisnya ingat. Berkas ini yang bikin "lupa" jadi
 * merah, bukan jadi bocor.
 *
 * Harganya tertulis di docblock `ScopeOrganisasiKemampuanTest`, dan itu bukan
 * kebocoran "lihat data" biasa: **angka CMC lab A jadi lantai ketidakpastian di
 * sertifikat lab B** — sertifikatnya terbit normal, PASS/FAIL-nya wajar, dan
 * yang salah cuma angka di dokumen yang menyatakan dirinya terakreditasi.
 *
 * ## Kenapa 404 yang ditegakkan, bukan 403
 *
 * Seluruh 17 endpoint hari ini SUDAH memulangkan 404 buat akses lintas lab —
 * diukur, bukan dikira. Nol bocor.
 *
 * Dan 404 memang jawaban yang lebih rapat daripada 403. 403 bilang "barangnya
 * ADA, kamu saja yang nggak boleh" — itu oracle keberadaan: penyerang yang
 * menyisir id belajar id mana yang nyata, dan dari situ dia tahu lab sebelah
 * punya berapa pelanggan dan berapa sertifikat. 404 nggak membocorkan apa pun.
 *
 * Yang dijaga di sini sebenarnya SATU: jangan pernah 200. Kode persisnya
 * dikunci di [STATUS_TOLAK] supaya kalau suatu hari diputuskan pindah ke 403,
 * yang berubah satu baris — bukan dua puluh test.
 */
class BatasAntarLabTest extends TestCase
{
    use RefreshDatabase;

    /** Jawaban yang WAJIB keluar buat akses lintas lab. Lihat docblock kelas. */
    private const STATUS_TOLAK = 404;

    /**
     * Route berparam yang SENGAJA nggak ikut disapu, berikut alasannya.
     *
     * Ditulis satu-satu supaya nggak ada yang lolos diam-diam: route baru yang
     * nggak masuk daftar kasus DAN nggak masuk daftar ini bikin
     * [test_tiap_route_berparam_kesapu_atau_punya_alasan] merah.
     *
     * @var array<string, string>
     */
    private const DIKECUALIKAN = [
        // Verifikasi QR di sertifikat cetak. Memang publik dan memang lintas
        // organisasi — pelanggan & asesor membukanya tanpa login, dan tokennya
        // yang jadi otorisasi. Menyaring organisasi di sini justru merusak.
        'api/verify/{qr_token}' => 'verifikasi publik, token yang jadi otorisasi',

        // Dicari pakai KODE katalog (`ph_meter`, `oven`), bukan id milik lab.
        // Bentuk lembar kerja sama buat semua lab — itu turunan profil, bukan
        // data pelanggan.
        'api/categories/{kode}' => 'katalog by kode, bukan data milik lab',
        'api/worksheet-templates/{kode}' => 'katalog by kode, bukan data milik lab',

        // Belum punya factory. Bukan "aman", cuma BELUM DISAPU — dan ditulis di
        // sini supaya statusnya kelihatan, bukan kelewat.
        'api/folder-files/{folderFile}/download' => 'BELUM DISAPU — FolderFile belum punya factory',
        'api/worksheet-scans/{worksheetScan}' => 'BELUM DISAPU — WorksheetScan belum punya factory',
        'api/worksheet-scans/{worksheetScan}/sel/{kunci}/crop' => 'BELUM DISAPU — WorksheetScan belum punya factory',
    ];

    /**
     * Tiap kasus: label => [uri route asli, kunci sumber daya milik lab B].
     *
     * @return array<string, array{string, string}>
     */
    public static function endpointShow(): array
    {
        return [
            'customer' => ['api/customers/{customer}', 'pelanggan'],
            'equipment' => ['api/equipments/{equipment}', 'alat'],
            'standard' => ['api/standards/{standard}', 'standar'],
            'room' => ['api/rooms/{room}', 'ruang'],
            'calibration-method' => ['api/calibration-methods/{calibrationMethod}', 'metode'],
            'formula versions' => ['api/formulas/{formula}/versions', 'rumus'],
            'formula versi-berlaku' => ['api/formulas/{formula}/versi-berlaku', 'rumus'],
            'folder' => ['api/folders/{folder}', 'folder'],
            'arsip folder' => ['api/arsip/folders/{folder}', 'folder'],
            'arsip perusahaan' => ['api/arsip/perusahaan/{customer}/folder', 'pelanggan'],
            'order' => ['api/orders/{order}', 'order'],
            'calibration' => ['api/calibrations/{calibration}', 'sesi'],
            'calibration perhitungan' => ['api/calibrations/{calibration}/perhitungan', 'sesi'],
            'calibration validasi' => ['api/calibrations/{calibration}/validasi', 'sesi'],
            'certificate' => ['api/certificates/{certificate}', 'sertifikat'],
            'certificate riwayat-email' => ['api/certificates/{certificate}/riwayat-email', 'sertifikat'],
            'certificate download' => ['api/certificates/{certificate}/download', 'sertifikat'],
            'certificate excel' => ['api/certificates/{certificate}/excel', 'sertifikat'],
            'certificate qr' => ['api/certificates/{certificate}/qr', 'sertifikat'],
            'technician' => ['api/technicians/{technician}', 'teknisiB'],
        ];
    }

    #[DataProvider('endpointShow')]
    public function test_admin_lab_a_nggak_bisa_baca_punya_lab_b(string $uri, string $kunci): void
    {
        $labB = Organization::factory()->create(['nama' => 'Lab B']);
        $labA = Organization::factory()->create(['nama' => 'Lab A']);

        // Admin, BUKAN teknisi. Kalau perannya kurang, penolakan yang keluar
        // soal PERAN dan test ini jadi hijau tanpa pernah menguji organisasi —
        // hijau karena alasan yang salah, bentuk kegagalan paling mahal di
        // seluruh berkas ini.
        $penyerang = User::factory()->create([
            'organization_id' => $labA->id,
            'role' => 'admin',
            'status' => 'aktif',
        ]);

        $milikB = $this->punyaLabB($labB);
        $id = $milikB[$kunci];

        $jalan = preg_replace('/\{[a-zA-Z_]+\}/', (string) $id, $uri);
        $this->assertIsString($jalan);

        $respons = $this->actingAs($penyerang)->getJson($jalan);

        $this->assertSame(
            self::STATUS_TOLAK,
            $respons->getStatusCode(),
            "`{$uri}` memulangkan {$respons->getStatusCode()} buat sumber daya lab lain. "
            .'200 berarti bocor; kode tolak yang beda berarti endpoint ini nggak sepakat '
            .'dengan yang lain soal cara menolak.',
        );
    }

    /**
     * Yang paling penting di berkas ini, dan bukan test-nya yang di atas.
     *
     * Menembaki 20 endpoint cuma menjaga 20 endpoint. Yang beneran menahan bug
     * berikutnya adalah ini: tiap route berparam WAJIB ada di daftar kasus atau
     * di daftar pengecualian berikut alasannya. Route ke-21 yang ditambah besok
     * nggak bisa lahir tanpa pemiliknya memutuskan dia masuk yang mana.
     */
    public function test_tiap_route_berparam_kesapu_atau_punya_alasan(): void
    {
        $disapu = array_map(
            static fn (array $k): string => $k[0],
            array_values(self::endpointShow()),
        );

        $telanjang = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/') || ! str_contains($uri, '{')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (in_array($uri, $disapu, true) || isset(self::DIKECUALIKAN[$uri])) {
                continue;
            }

            $telanjang[] = $uri;
        }

        sort($telanjang);

        $this->assertSame(
            [],
            $telanjang,
            "Route GET berparam ini nggak disapu batas antar-lab dan nggak punya alasan tertulis:\n  "
            .implode("\n  ", $telanjang)
            ."\n\nTambahin ke `endpointShow()` kalau dia baca data milik lab, atau ke "
            .'`DIKECUALIKAN` berikut alasannya kalau memang bukan.',
        );
    }

    /**
     * Bikin satu sumber daya per jenis, semuanya milik lab B.
     *
     * @return array<string, int|string>
     */
    private function punyaLabB(Organization $labB): array
    {
        $pelanggan = Customer::factory()->create(['organization_id' => $labB->id]);
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $labB->id]);

        $alat = Equipment::factory()->create([
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => $kategori->id,
        ]);

        $standar = Standard::factory()->create(['organization_id' => $labB->id]);

        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $labB->id,
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
        ]);

        return [
            'pelanggan' => $pelanggan->id,
            'alat' => $alat->id,
            'standar' => $standar->id,
            'ruang' => Room::factory()->create(['organization_id' => $labB->id])->id,
            'metode' => CalibrationMethod::factory()->create(['organization_id' => $labB->id])->id,
            'rumus' => Formula::factory()->create(['organization_id' => $labB->id])->id,
            'folder' => Folder::factory()->create(['organization_id' => $labB->id])->id,
            'order' => Order::factory()->create([
                'organization_id' => $labB->id,
                'customer_id' => $pelanggan->id,
            ])->id,
            'sesi' => $sesi->id,
            'sertifikat' => Certificate::factory()->create([
                'organization_id' => $labB->id,
                'calibration_session_id' => $sesi->id,
            ])->id,
            'teknisiB' => User::factory()->create([
                'organization_id' => $labB->id,
                'role' => 'teknisi',
                'status' => 'aktif',
            ])->id,
        ];
    }
}
