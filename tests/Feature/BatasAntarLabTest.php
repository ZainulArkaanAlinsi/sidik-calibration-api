<?php

namespace Tests\Feature;

use App\Models\CalibrationMethod;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Folder;
use App\Models\FolderFile;
use App\Models\Formula;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Standard;
use App\Models\User;
use App\Models\WorksheetScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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
 * Seluruh 20 endpoint di sini SUDAH memulangkan 404 buat akses lintas lab —
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

        // ---- Nggak bisa DIBUKTIKAN di sini, tapi sudah DIBACA kodenya. ----
        //
        // Keduanya memulangkan 404 buat pemiliknya sendiri, karena yang
        // ditagih bukan cuma baris DB tapi BERKAS atau SEL yang beneran ada.
        // Kontrol pemilik di [test_admin_lab_a_nggak_bisa_baca_punya_lab_b]
        // sengaja meledak buat kasus begitu: 404 yang keluar soal berkas, bukan
        // soal lab, jadi memasukkannya ke sapuan berarti menanam kasus yang
        // hijau selamanya tanpa pernah menguji apa pun.
        //
        // Karena nggak bisa dibuktikan, penjaganya dibaca langsung:
        //
        //  - `CertificateController::download` dan `::exportExcel` sama-sama
        //    memanggil `pastikanBolehLihat()` di baris PERTAMA, dan di dalamnya
        //    `pastikanSatuOrganisasi()` membanding `organization_id` — jauh
        //    sebelum `Storage::exists()` disentuh. Jadi urutannya benar: yang
        //    beda lab ditolak duluan, bukan sesudah ketahuan berkasnya ada.
        //  - Kalau suatu hari factory sertifikat sanggup membangkitkan PDF &
        //    Excel beneran, dua baris ini dipindah balik ke `endpointShow()`.
        'api/certificates/{certificate}/download' => 'butuh PDF nyata; penjaga dibaca manual — pastikanBolehLihat() di baris pertama',
        'api/certificates/{certificate}/excel' => 'butuh Excel nyata; penjaga dibaca manual — pastikanBolehLihat() di baris pertama',

        // Butuh satu baris `worksheet_scan_cells` + citra crop-nya. Tanpa itu
        // 404-nya soal sel, bukan soal lab.
        'api/worksheet-scans/{worksheetScan}/sel/{kunci}/crop' => 'butuh sel & citra crop nyata',
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
            'certificate qr' => ['api/certificates/{certificate}/qr', 'sertifikat'],
            'technician' => ['api/technicians/{technician}', 'teknisiB'],
            'worksheet-scan' => ['api/worksheet-scans/{worksheetScan}', 'pindaian'],
            'folder-file download' => ['api/folder-files/{folderFile}/download', 'berkasFolder'],
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

        // KONTROL PEMILIK — dan ini bukan basa-basi.
        //
        // Tanpa baris ini, 404 buat penyerang bisa berarti "bukan lab kamu"
        // ATAU "barangnya memang nggak ada" — id salah, route salah ketik,
        // parameter kedua yang nggak keisi benar. Dua-duanya menghasilkan test
        // HIJAU, dan yang kedua hijau tanpa pernah menguji apa pun.
        //
        // Jadi pemiliknya sendiri ditembak duluan ke URL yang sama persis.
        // Kalau dia juga 404, kasus ini nggak membuktikan apa-apa dan harus
        // meledak di sini — bukan lolos diam-diam bareng yang lain.
        $pemilik = User::factory()->create([
            'organization_id' => $labB->id,
            'role' => 'admin',
            'status' => 'aktif',
        ]);

        $this->assertNotSame(
            self::STATUS_TOLAK,
            $this->actingAs($pemilik)->getJson($jalan)->getStatusCode(),
            "Pemiliknya sendiri dapat ".self::STATUS_TOLAK." di `{$jalan}`. Berarti kasus ini "
            .'nggak menguji batas antar-lab sama sekali — 404 buat penyerang bakal keluar '
            .'walau penjaganya dicabut. Betulkan cara sumber dayanya dibikin, bukan '
            .'assertion-nya.',
        );

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
     * Berkas folder milik lab B, LENGKAP dengan berkasnya di disk.
     *
     * Endpoint unduhan nagih DUA hal: barisnya ada di DB, dan berkasnya ada di
     * disk `arsip`. Yang kedua gampang bohong, dan bohongnya nggak kelihatan:
     * 404 "berkas raib" buat pemiliknya berbentuk persis sama dengan 404 "bukan
     * lab kamu", jadi kasus ini bisa hijau tanpa pernah menguji batas apa pun.
     *
     * Dua hal yang dulu bikin bohongnya mungkin, dua-duanya ditutup di sini:
     *
     * 1. Nilai balik `put()` dibuang. Disk `arsip` disetel `throw => false`
     *    (config/filesystems.php), dan `Storage::fake()` MEWARISI setelan itu
     *    lewat `buildDiskConfiguration()` — dia nyalin `throw` dari config
     *    aslinya. Jadi tulis yang gagal balik `false` tanpa exception, tanpa
     *    apa-apa, dan fixture-nya jalan terus seolah berkasnya jadi. Sekarang
     *    nilai baliknya ditagih, dan berkasnya dibaca balik lewat disk yang
     *    sama persis dengan yang bakal dipakai controller.
     *
     * 2. `Storage::fake('arsip')` dipanggil DI SINI, padahal
     *    `TestCase::setUp()` sudah memalsukannya buat SETIAP test. Panggilan
     *    kedua itu bukan bikin folder sementara baru — dia `cleanDirectory()`
     *    folder yang SAMA dan dipakai bareng seluruh proses
     *    (`storage/framework/testing/disks/arsip`), di tengah test, SESUDAH
     *    sumber daya lain di [punyaLabB] terlanjur jadi. Hari ini nggak ada
     *    yang kehapus cuma karena kebetulan belum ada fixture lain yang nulis
     *    ke disk; begitu ada (PDF sertifikat, citra pindaian), yang hilang
     *    bakal muncul sebagai 404 di endpoint yang sama sekali lain dan nggak
     *    ada yang bakal nyangka penyebabnya ada di sini.
     *
     * Yang HILANG kalau dua-duanya kelewat: bukan test yang merah, tapi test
     * yang hijau padahal penjaganya sudah dicabut.
     */
    private function berkasFolderLabB(Organization $labB): int
    {
        $berkas = FolderFile::factory()->create([
            'organization_id' => $labB->id,
            'folder_id' => Folder::factory()->create(['organization_id' => $labB->id])->id,
        ]);

        $arsip = Storage::disk('arsip');

        $this->assertTrue(
            $arsip->put($berkas->path, 'isi berkas contoh'),
            "Nulis berkas contoh ke disk `arsip` di `{$berkas->path}` GAGAL. "
            .'Dibiarkan lewat, pemiliknya bakal dapat 404 "berkas raib" dan kasus '
            .'`folder-file download` jadi hijau tanpa nguji batas antar-lab.',
        );

        $this->assertTrue(
            $arsip->exists($berkas->path),
            "Berkas contoh nggak kebaca balik di `{$berkas->path}` padahal tulisnya "
            .'ngaku sukses. Disk `arsip` lagi diubah pihak lain di tengah test.',
        );

        return $berkas->id;
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
            'pindaian' => WorksheetScan::factory()->create([
                'organization_id' => $labB->id,
                'calibration_session_id' => $sesi->id,
            ])->id,
            'berkasFolder' => $this->berkasFolderLabB($labB),
        ];
    }
}
