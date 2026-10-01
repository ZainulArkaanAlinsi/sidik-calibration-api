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
use App\Models\KoreksiPelanggan;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Penugasan;
use App\Models\PermintaanKalibrasi;
use App\Models\Room;
use App\Models\Standard;
use App\Models\User;
use App\Models\WorksheetScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RuteTerdaftar;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Sapu SELURUH rute internal ber-parameter: tidak satu pun boleh memulangkan
 * baris milik lab lain.
 *
 * ## Kenapa disapu, bukan ditulis satu-satu
 *
 * Isolasi antar-lab hari ini ditegakkan tujuh salinan `pastikanSatuOrganisasi()`
 * yang tersebar di controller. Cara itu bekerja — dan bentuk kegagalannya adalah
 * **yang kedelapan lupa ditulis**. Yang lupa tidak memunculkan error: dia
 * memulangkan data lab lain dengan HTTP 200, dan yang tahu cuma pelanggan yang
 * kebetulan melihat nama orang lain di layarnya.
 *
 * Test per-endpoint tidak menutup itu, karena rute baru lahir tanpa test baru.
 * Yang menutupnya cuma test yang **membaca daftar rutenya sendiri**, jadi rute
 * yang lahir besok ikut diuji tanpa ada yang perlu mengingatnya. Pola ini
 * dicontoh dari `RuteInternalMenolakRoleLainTest` dan `MatriksIzin` di repo ini —
 * dua-duanya sengaja menurunkan jawabannya dari rute, bukan dari daftar tulis
 * tangan.
 *
 * ## Yang dianggap BOCOR oleh test ini
 *
 * Bukan cuma HTTP 200. Yang diperiksa juga isi responsnya: kalau ada rute yang
 * memulangkan 200 tapi badannya tidak memuat penanda lab 2, itu dianggap aman
 * (mis. endpoint yang memang memulangkan daftar kosong). Yang merah adalah
 * respons yang **membawa** penanda — itu definisi bocor yang tidak bisa
 * diperdebatkan.
 *
 * ## Kalau test ini merah
 *
 * Jangan tambahkan rutenya ke `DIKECUALIKAN` supaya hijau. Daftar itu untuk rute
 * yang memang **bukan** milik satu lab; rute yang bocor harus diperbaiki di
 * controller-nya — satu baris `PenjagaOrganisasi::pastikanSatu($request, $baris)`.
 */
class SapuRuteIsolasiOrganisasiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nama parameter rute → cara mengambil id milik lab 2.
     *
     * Kunci di sini yang menentukan rute mana yang disapu. Rute dengan parameter
     * di luar daftar ini DILEWATI dan dihitung — jumlahnya dilaporkan di akhir
     * supaya kelewatan itu kelihatan, bukan hilang diam-diam.
     *
     * @var array<string, string>
     */
    private const PARAMETER = [
        'calibration' => 'sesi',
        'certificate' => 'sertifikat',
        'equipment' => 'alat',
        'customer' => 'pelanggan',
        'standard' => 'standar',
        'folder' => 'folder',
        'room' => 'ruang',
        'user' => 'pengguna',
        'technician' => 'teknisi',
        'order' => 'paket',
        'calibrationMethod' => 'metode',
        'formula' => 'rumus',
        'folderFile' => 'berkas',
        'worksheetScan' => 'pindai',
        'dokumenBacaan' => 'bacaan',
        'penugasan' => 'penugasan',
        'permintaan' => 'permintaan',
        // Koreksi data & foto pelat nama dari pelanggan (1 Okt, §42).
        'koreksi' => 'koreksi',
        'foto' => 'foto',
    ];

    /**
     * Rute yang SENGAJA tidak disapu, beserta alasannya.
     *
     * Tiap baris harus punya alasan yang bisa dibaca orang lain. Menambahkan
     * baris ke sini tanpa alasan sama dengan mematikan test-nya sebagian.
     *
     * @var array<string, string>
     */
    private const DIKECUALIKAN = [
        // Rute unduhan bertanda-tangan: pemeriksaannya di tanda tangan URL-nya,
        // bukan di organisasi pemanggil, dan tanpa tanda tangan yang sah dia
        // sudah 403 sebelum controller jalan.
        'api/certificates/{certificate}/download' => 'URL bertanda tangan — dijaga signature, bukan organisasi.',
        'api/certificates/{certificate}/qr' => 'URL bertanda tangan, dipindai dari kertas oleh pihak luar.',
    ];

    private Organization $lab1;

    private Organization $lab2;

    private User $adminLab1;

    /** @var array<string, int> nama parameter → id milik lab 2 */
    private array $idLab2 = [];

    /** @var list<string> penanda yang tidak boleh muncul di respons lab 1 */
    private array $penanda = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab1 = Organization::factory()->create(['nama' => 'Lab Satu']);
        $this->lab2 = Organization::factory()->create(['nama' => 'Lab Dua']);

        $this->adminLab1 = User::factory()->admin()->create(['organization_id' => $this->lab1->id]);

        $this->tanamDataLab2();
    }

    public function test_tidak_ada_rute_internal_yang_memulangkan_baris_lab_lain(): void
    {
        $disapu = 0;
        $dilewati = [];
        $bocor = [];

        foreach ($this->ruteYangDisapu() as $rute) {
            $uri = $this->isiParameter($rute);

            if ($uri === null) {
                $dilewati[] = $rute->uri();

                continue;
            }

            $disapu++;

            $respons = $this->actingAs($this->adminLab1)->getJson('/'.$uri);
            $isi = (string) $respons->getContent();

            if ($respons->getStatusCode() !== 200) {
                continue;
            }

            foreach ($this->penanda as $jarum) {
                if (str_contains($isi, $jarum)) {
                    $bocor[] = $rute->uri().' → 200 memuat `'.$jarum.'`';
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $bocor,
            "Rute berikut memulangkan data lab lain ke admin lab 1:\n  - "
            .implode("\n  - ", $bocor)
            ."\n\nBetulkan di controller-nya dengan satu baris:\n"
            ."  PenjagaOrganisasi::pastikanSatu(\$request, \$baris);\n"
            .'JANGAN menambahkannya ke DIKECUALIKAN.',
        );

        // Kalau tidak ada yang tersapu sama sekali, test ini hijau tanpa
        // membuktikan apa pun — bentuk kegagalan paling berbahaya untuk test
        // yang membaca daftar rute sendiri. Angka 10 itu ambang bawah yang
        // longgar; hari ini yang tersapu jauh lebih banyak.
        $this->assertGreaterThan(
            10,
            $disapu,
            "Cuma {$disapu} rute yang tersapu. Kemungkinan `PARAMETER` atau filter "
            .'rutenya tidak lagi cocok dengan `routes/api.php` — test ini jadi hijau '
            .'palsu. Periksa dulu sebelum melanjutkan.',
        );
    }

    /**
     * Rute ber-parameter yang tidak ada di `PARAMETER` dicatat, bukan diabaikan.
     *
     * Test terpisah supaya yang satu tidak menutupi yang lain: rute bocor bikin
     * test pertama merah, rute yang belum terjangkau bikin yang ini merah — dan
     * dua masalah itu perbaikannya beda.
     */
    public function test_semua_parameter_rute_internal_sudah_terjangkau(): void
    {
        $belumDikenal = [];

        foreach ($this->ruteYangDisapu() as $rute) {
            foreach ($rute->parameterNames() as $nama) {
                if (! array_key_exists($nama, self::PARAMETER)) {
                    $belumDikenal[$nama][] = $rute->uri();
                }
            }
        }

        // Parameter yang memang bukan pemilik data (mis. `{kode}` template,
        // `{tanggal}`) tidak perlu masuk `PARAMETER` — tapi dia harus disebut di
        // sini, sekali, supaya keputusannya tercatat.
        // `qr_token` itu kunci publik halaman verifikasi (dipindai dari kertas);
        // `kunci` & `notification` bukan penunjuk baris milik satu lab.
        $bukanPemilikData = [
            'kode', 'tanggal', 'instrumen', 'jenis', 'tipe', 'slug', 'kategori',
            'qr_token', 'kunci', 'notification',
            // Anak yang diraih lewat induknya — kepemilikannya diperiksa lewat
            // induk di controller (`$item->order`, `$item->penugasan`), dan
            // rutenya POST/PATCH, jadi tidak ikut sapuan GET ini.
            'orderItem', 'penugasanItem', 'formulaVersion',
            // Modul pelanggan sisi lab (M1-05) punya test isolasinya sendiri.
            'pengajuan', 'undangan',
        ];

        foreach ($bukanPemilikData as $nama) {
            unset($belumDikenal[$nama]);
        }

        $this->assertSame(
            [],
            array_keys($belumDikenal),
            'Ada parameter rute yang belum terjangkau sapuan isolasi: '
            .implode(', ', array_keys($belumDikenal))
            ."\n\nTambahkan ke `PARAMETER` beserta baris data lab 2-nya di "
            .'`tanamDataLab2()`, atau ke `$bukanPemilikData` kalau dia memang bukan '
            .'penunjuk baris milik satu lab.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Penolong
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<RuteTerdaftar> */
    private function ruteYangDisapu(): array
    {
        $hasil = [];

        foreach (Router::getRoutes() as $rute) {
            if (! str_starts_with($rute->uri(), 'api/')) {
                continue;
            }

            // Cuma GET — POST/PUT/DELETE lintas lab diuji terpisah per fitur,
            // dan menyapunya di sini berarti test ini MENGUBAH data waktu
            // berjalan. Test yang punya efek samping tidak bisa diulang.
            if (! in_array('GET', $rute->methods(), true)) {
                continue;
            }

            if ($rute->parameterNames() === []) {
                continue;
            }

            if (array_key_exists($rute->uri(), self::DIKECUALIKAN)) {
                continue;
            }

            // Dunia pelanggan (`api/pelanggan/*`) disaring `KonteksPerusahaan`,
            // bukan `organization_id` — modelnya beda dan admin lab tidak punya
            // `customer_id`. Isolasinya diuji berkas sendiri.
            if (str_starts_with($rute->uri(), 'api/pelanggan/')) {
                continue;
            }

            $hasil[] = $rute;
        }

        return $hasil;
    }

    /**
     * Ganti tiap `{param}` dengan id milik lab 2. Null kalau ada yang tidak
     * punya pasangan — rute itu dilewati dan dilaporkan.
     */
    private function isiParameter(RuteTerdaftar $rute): ?string
    {
        $uri = $rute->uri();

        foreach ($rute->parameterNames() as $nama) {
            if (! isset($this->idLab2[$nama])) {
                return null;
            }

            $uri = str_replace(
                ['{'.$nama.'}', '{'.$nama.'?}'],
                (string) $this->idLab2[$nama],
                $uri,
            );
        }

        // Masih ada kurung berarti ada parameter yang tidak tergantikan.
        return str_contains($uri, '{') ? null : $uri;
    }

    /**
     * Satu baris per tabel, milik lab 2, dengan nilai penanda yang mustahil
     * muncul secara kebetulan.
     *
     * Penandanya sengaja ditaruh di kolom yang PASTI ikut terkirim ke klien
     * (nama, serial number, nomor) — bukan di kolom teknis. Penanda yang
     * tersimpan tapi tidak pernah diserialisasi membuat test ini hijau tanpa
     * membuktikan apa pun.
     */
    private function tanamDataLab2(): void
    {
        $pelanggan = Customer::factory()->create([
            'organization_id' => $this->lab2->id,
            'nama' => 'BOCOR-PELANGGAN-LAB2',
        ]);

        $kategori = EquipmentCategory::factory()->create(['organization_id' => $this->lab2->id]);

        $alat = Equipment::factory()->create([
            'organization_id' => $this->lab2->id,
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'BOCOR-ALAT-LAB2',
            'serial_number' => 'BOCOR-SN-LAB2',
        ]);

        $standar = Standard::factory()->create([
            'organization_id' => $this->lab2->id,
            'nama' => 'BOCOR-STANDAR-LAB2',
            'serial_number' => 'BOCOR-SN-STD-LAB2',
            'no_sertifikat' => 'BOCOR-SERT-STD-LAB2',
        ]);

        $teknisiLab2 = User::factory()->create([
            'organization_id' => $this->lab2->id,
            'role' => User::ROLE_TEKNISI,
            'name' => 'BOCOR-TEKNISI-LAB2',
            'kode_teknisi' => 'BCR',
        ]);

        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $this->lab2->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisiLab2->id,
            'nomor_sesi' => 'BOCOR-SESI-LAB2',
            'status' => CalibrationSession::STATUS_DISETUJUI,
        ]);

        $sertifikat = Certificate::factory()->create([
            'organization_id' => $this->lab2->id,
            'calibration_session_id' => $sesi->id,
            'nomor' => 'BOCOR-CAL/2026/09/9999',
        ]);

        $ruang = Room::factory()->create([
            'organization_id' => $this->lab2->id,
            'nama' => 'BOCOR-RUANG-LAB2',
        ]);

        $folder = Folder::factory()->create([
            'organization_id' => $this->lab2->id,
            'nama' => 'BOCOR-FOLDER-LAB2',
        ]);

        $paket = Order::factory()->create([
            'organization_id' => $this->lab2->id,
            'customer_id' => $pelanggan->id,
            'nomor' => 'BOCOR-ORD-LAB2',
        ]);
        $metode = CalibrationMethod::factory()->create([
            'organization_id' => $this->lab2->id,
            'nama' => 'BOCOR-METODE-LAB2',
        ]);
        $rumus = Formula::factory()->create([
            'organization_id' => $this->lab2->id,
            'nama' => 'BOCOR-RUMUS-LAB2',
        ]);
        $berkas = FolderFile::factory()->create([
            'organization_id' => $this->lab2->id,
            'folder_id' => $folder->id,
            'nama' => 'BOCOR-BERKAS-LAB2.pdf',
        ]);
        $pindai = WorksheetScan::factory()->create([
            'organization_id' => $this->lab2->id,
            'user_id' => $teknisiLab2->id,
        ]);
        $bacaan = DokumenBacaan::create([
            'organization_id' => $this->lab2->id,
            'user_id' => $teknisiLab2->id,
            'judul' => 'BOCOR-BACAAN-LAB2',
            'status' => DokumenBacaan::STATUS_OK,
        ]);
        $penugasan = Penugasan::create([
            'organization_id' => $this->lab2->id,
            'judul' => 'BOCOR-PENUGASAN-LAB2',
            'tipe' => Penugasan::TIPE_PERSONAL,
            'status' => Penugasan::STATUS_AKTIF,
        ]);

        $permintaan = PermintaanKalibrasi::create([
            'organization_id' => $this->lab2->id,
            'customer_id' => $pelanggan->id,
            'nomor' => 'BOCOR-PMT-LAB2',
            'status' => PermintaanKalibrasi::STATUS_BARU,
            'metode_pengantaran' => PermintaanKalibrasi::METODE_DIANTAR_SENDIRI,
            'catatan' => 'BOCOR-CATATAN-PERMINTAAN-LAB2',
        ]);
        $permintaan->pesan()->create([
            'sisi' => 'pelanggan',
            'isi' => 'BOCOR-PESAN-LAB2',
        ]);

        $koreksi = KoreksiPelanggan::create([
            'organization_id' => $this->lab2->id,
            'customer_id' => $pelanggan->id,
            'jenis' => KoreksiPelanggan::JENIS_ALAT,
            'equipment_id' => $alat->id,
            'perubahan' => [['field' => 'merk', 'label' => 'Merk', 'lama' => 'A', 'baru' => 'B']],
            'catatan' => 'BOCOR-CATATAN-KOREKSI-LAB2',
            'status' => KoreksiPelanggan::STATUS_MENUNGGU,
        ]);
        $foto = $alat->fotoPelat()->create([
            'organization_id' => $this->lab2->id,
            'customer_id' => $pelanggan->id,
            'path' => 'foto-pelanggan/bocor-lab2.jpg',
            'mime' => 'image/jpeg',
            'ukuran' => 1,
        ]);

        $this->idLab2 = [
            'koreksi' => $koreksi->id,
            'foto' => $foto->id,
            'technician' => $teknisiLab2->id,
            'order' => $paket->id,
            'calibrationMethod' => $metode->id,
            'formula' => $rumus->id,
            'folderFile' => $berkas->id,
            'worksheetScan' => $pindai->id,
            'dokumenBacaan' => $bacaan->id,
            'penugasan' => $penugasan->id,
            'permintaan' => $permintaan->id,
            'calibration' => $sesi->id,
            'certificate' => $sertifikat->id,
            'equipment' => $alat->id,
            'customer' => $pelanggan->id,
            'standard' => $standar->id,
            'folder' => $folder->id,
            'room' => $ruang->id,
            'user' => $teknisiLab2->id,
        ];

        $this->penanda = [
            'BOCOR-PELANGGAN-LAB2',
            'BOCOR-ALAT-LAB2',
            'BOCOR-SN-LAB2',
            'BOCOR-STANDAR-LAB2',
            'BOCOR-SN-STD-LAB2',
            'BOCOR-SERT-STD-LAB2',
            'BOCOR-TEKNISI-LAB2',
            'BOCOR-SESI-LAB2',
            'BOCOR-CAL/2026/09/9999',
            'BOCOR-RUANG-LAB2',
            'BOCOR-FOLDER-LAB2',
            'BOCOR-ORD-LAB2',
            'BOCOR-METODE-LAB2',
            'BOCOR-RUMUS-LAB2',
            'BOCOR-BERKAS-LAB2',
            'BOCOR-BACAAN-LAB2',
            'BOCOR-PENUGASAN-LAB2',
            'BOCOR-PMT-LAB2',
            'BOCOR-CATATAN-PERMINTAAN-LAB2',
            'BOCOR-CATATAN-KOREKSI-LAB2',
            'BOCOR-PESAN-LAB2',
        ];
    }
}
