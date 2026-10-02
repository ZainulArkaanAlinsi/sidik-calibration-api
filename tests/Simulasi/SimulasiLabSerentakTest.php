<?php

namespace Tests\Simulasi;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\PermintaanKalibrasi;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Satu hari lab yang sibuk, dengan kiriman yang datang BERSAMAAN, lawan
 * beberapa server lokal yang berbagi satu database MySQL.
 *
 * Bukan bagian suite biasa: berkas ini di luar `tests/Unit` dan `tests/Feature`,
 * jadi CI dan `php artisan test` tidak menjalankannya. Jalankan sengaja:
 *
 *     .\jalankan-test-mysql.ps1 tests/Simulasi/SimulasiLabSerentakTest.php
 *
 * ## Kenapa butuh proses terpisah
 *
 * Test biasa menjalankan request satu per satu di satu proses, di dalam
 * transaksi yang tidak pernah di-commit. Balapan antar request — dua admin
 * menyetujui sesi yang sama, sepuluh teknisi mengirim lembar kerja di detik yang
 * sama — butuh proses PHP yang benar-benar terpisah dan data yang benar-benar
 * ter-commit. Produksi (FrankenPHP) memang begitu, jadi di sanalah balapan
 * semacam itu bisa terjadi.
 *
 * Susunannya meniru produksi: beberapa server HTTP (pengganti thread FrankenPHP),
 * SATU pekerja antrean (`docker/entrypoint.sh` juga cuma menyalakan satu), cache
 * & antrean di database. Skenarionya ada di `docs/skrip/simulasi-lab-serentak.py`.
 *
 * ## Pengaman
 *
 * Database yang dipakai DIHAPUS ISINYA (`migrate:fresh`). Test ini menolak jalan
 * kalau koneksinya bukan MySQL lokal `asmo_db_test`, dan skrip Python berhenti
 * kalau ada server yang tidak mengenal akun penanda yang cuma ada di DB tes.
 * Semua layanan luar (email, FCM, arsip awan, AI, broadcast) dimatikan lewat env
 * proses anak, supaya data dummy tidak bocor ke layanan produksi.
 */
class SimulasiLabSerentakTest extends TestCase
{
    private const PORT = [8131, 8132, 8133, 8134];

    private const SANDI = 'simulasi-lab-2026';

    private const PENANDA = 'simulasi.penanda@contoh.test';

    /** @var list<Process> */
    private array $proses = [];

    private ?string $mulaiSimulasi = null;

    public function test_lab_sibuk_dengan_kiriman_serentak(): void
    {
        $this->pastikanDatabaseTesLokal();

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $fixture = $this->siapkanOrangDanAlat();
        $this->mulaiSimulasi = now()->subSecond()->toDateTimeString();

        $folder = $this->folderKeluaran();
        $jalurFixture = $folder.DIRECTORY_SEPARATOR.'fixture.json';
        $jalurLaporan = $folder.DIRECTORY_SEPARATOR.'laporan.json';
        file_put_contents($jalurFixture, json_encode($fixture, JSON_PRETTY_PRINT));

        $this->nyalakanServerDanPekerja();

        $python = new Process(
            [$this->python(), base_path('docs/skrip/simulasi-lab-serentak.py'), $jalurFixture, $jalurLaporan],
            base_path(),
        );
        // Keluarannya ditulis SELAGI jalan, bukan sesudah selesai: kalau ada
        // skenario yang macet, berkas ini yang menunjukkan di mana berhentinya.
        $jalurKeluaran = $folder.DIRECTORY_SEPARATOR.'keluaran.txt';
        $python->setTimeout(1200);
        $python->run(fn (string $jenis, string $isi) => file_put_contents($jalurKeluaran, $isi, FILE_APPEND));
        fwrite(STDOUT, PHP_EOL.$python->getOutput().$python->getErrorOutput().PHP_EOL);

        $this->assertFileExists($jalurLaporan, 'Skrip simulasi tidak menulis laporan: '.$python->getErrorOutput());
        $laporan = json_decode((string) file_get_contents($jalurLaporan), true);

        $invarian = $this->periksaInvarianDatabase($laporan['sesi_dikenal'] ?? []);
        $laporan['invarian_database'] = $invarian;
        file_put_contents($jalurLaporan, json_encode($laporan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fwrite(STDOUT, 'INVARIAN DATABASE: '.json_encode($invarian, JSON_UNESCAPED_UNICODE).PHP_EOL);
        fwrite(STDOUT, "Laporan lengkap: {$jalurLaporan}".PHP_EOL);

        $gagal = collect($laporan['skenario'] ?? [])->where('lulus', false)->pluck('kode')->all();
        $this->assertSame([], $invarian['pelanggaran'], 'Isi database melanggar invarian.');
        $this->assertArrayNotHasKey('dihentikan', $laporan, (string) ($laporan['dihentikan'] ?? ''));
        $this->assertSame([], $gagal, 'Skenario gagal: '.implode(', ', $gagal).'. Lihat '.$jalurLaporan);
    }

    protected function tearDown(): void
    {
        foreach ($this->proses as $p) {
            if ($p->isRunning()) {
                $p->stop(3);
            }
        }

        // PDF hasil simulasi ditulis proses anak ke disk `arsip` SUNGGUHAN, bukan
        // disk palsu milik test ini. Dibersihkan supaya tidak menumpuk seperti
        // kebocoran 11 GB yang dicatat di `Tests\TestCase`.
        if ($this->mulaiSimulasi !== null) {
            $disk = Storage::build(config('filesystems.disks.arsip'));
            Certificate::query()
                ->where('created_at', '>=', $this->mulaiSimulasi)
                ->whereNotNull('pdf_path')
                ->pluck('pdf_path')
                ->each(fn (string $path) => $disk->delete($path));
        }

        parent::tearDown();
    }

    private function pastikanDatabaseTesLokal(): void
    {
        $koneksi = DB::connection();

        if ($koneksi->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Simulasi butuh MySQL bersama. Jalankan lewat .\\jalankan-test-mysql.ps1.');
        }

        // `migrate:fresh` di bawah menghapus seluruh isi database. Dua baris ini
        // yang memastikan isinya memang database tes, bukan database kerja.
        $this->assertSame('127.0.0.1', $koneksi->getConfig('host'), 'Simulasi hanya boleh di MySQL lokal.');
        $this->assertSame('asmo_db_test', $koneksi->getDatabaseName(), 'Simulasi hanya boleh di asmo_db_test.');
    }

    /** @return array<string, mixed> */
    private function siapkanOrangDanAlat(): array
    {
        $adminSeeder = User::query()->where('email', 'admin@sidik.test')->firstOrFail();
        $org = $adminSeeder->organization_id;

        $buat = fn (string $email, string $nama, string $role, ?string $idPegawai) => User::query()->create([
            'organization_id' => $org,
            'employee_id' => $idPegawai,
            'name' => $nama,
            'department' => 'Kalibrasi',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
            'password' => self::SANDI,
        ]);

        // Nama orang fiktif. Domain `contoh.test` tidak bisa menerima email
        // sungguhan, jadi notifikasi yang lolos pun tidak sampai ke siapa-siapa.
        $admin = [
            $buat('hendra.gunawan@contoh.test', 'Hendra Gunawan', User::ROLE_ADMIN, 'SIM-ADM-01'),
            $buat('maya.lestari@contoh.test', 'Maya Lestari', User::ROLE_ADMIN, 'SIM-ADM-02'),
        ];
        $namaTeknisi = [
            'Bagus Wicaksono', 'Rina Kartika', 'Dimas Prasetyo', 'Sari Wulandari', 'Yoga Pratama',
            'Fitri Anggraini', 'Arif Setiawan', 'Nadia Putri', 'Rizal Hakim', 'Lina Marlina',
        ];
        $teknisi = [];
        foreach ($namaTeknisi as $i => $nama) {
            $email = strtolower(str_replace(' ', '.', $nama)).'@contoh.test';
            $teknisi[] = $buat($email, $nama, User::ROLE_TEKNISI, sprintf('SIM-TEK-%02d', $i + 1));
        }
        $viewer = $buat('agus.santoso@contoh.test', 'Agus Santoso', User::ROLE_VIEWER, 'SIM-VWR-01');
        $buat(self::PENANDA, 'Penanda Simulasi', User::ROLE_VIEWER, 'SIM-PENANDA');

        // Alat pH dari `PhMeterSeeder` digandakan: tiap kiriman serentak dapat
        // alatnya sendiri, supaya yang diuji balapan nomor & persetujuan — bukan
        // aturan "satu alat satu sesi aktif".
        $ph = Equipment::query()->where('organization_id', $org)->where('serial_number', 'B628755900')->firstOrFail();
        $alat = [];
        for ($i = 1; $i <= 20; $i++) {
            $kembar = $ph->replicate();
            $kembar->serial_number = sprintf('SIM-PH-%04d', $i);
            $kembar->save();
            $alat[] = ['id' => $kembar->id, 'serial' => $kembar->serial_number];
        }

        // Delapan perusahaan pelanggan fiktif, masing-masing satu PIC dan dua alat:
        // satu dimintakan lewat aplikasi pelanggan, satu didaftarkan langsung di
        // meja penerimaan. Dua pintu itu memakai SATU penomoran order
        // (`PenomoranOrder`), jadi keduanya sengaja ditembak bersamaan.
        // Token dibuat langsung, bukan lewat `/auth/masuk`: pintu masuk itu
        // memeriksa sandi bocor ke layanan luar, dan simulasi tidak boleh keluar.
        $namaPerusahaan = [
            'PT Simulasi Tirta Lestari', 'CV Simulasi Analitika', 'RS Simulasi Medika Utama',
            'PDAM Simulasi Kota Hujan', 'PT Simulasi Pangan Nusantara', 'PT Simulasi Farma Sejahtera',
            'CV Simulasi Laboratorium Prima', 'PT Simulasi Kimia Mandiri',
        ];
        $pelanggan = [];
        foreach ($namaPerusahaan as $i => $namaPt) {
            $customer = Customer::factory()->create(['organization_id' => $org, 'nama' => $namaPt]);
            $pic = $buat(sprintf('pic%02d@contoh.test', $i + 1), 'PIC '.$namaPt, User::ROLE_PELANGGAN, null);
            CustomerMember::query()->create([
                'organization_id' => $org,
                'customer_id' => $customer->id,
                'user_id' => $pic->id,
                'peran' => CustomerMember::PERAN_PIC_UTAMA,
                'status' => CustomerMember::STATUS_AKTIF,
            ]);

            $alatPelanggan = [];
            foreach ([1, 2] as $k) {
                $kembar = $ph->replicate();
                $kembar->serial_number = sprintf('SIM-PL-%02d-%d', $i + 1, $k);
                $kembar->customer_id = $customer->id;
                $kembar->status = Equipment::STATUS_AKTIF;
                $kembar->save();
                $alatPelanggan[] = $kembar->id;
            }

            $pelanggan[] = [
                'email' => $pic->email,
                'token' => $pic->createToken('simulasi', ['pelanggan'])->plainTextToken,
                'customer_id' => $customer->id,
                'alat_permintaan' => $alatPelanggan[0],
                'alat_order' => $alatPelanggan[1],
            ];
        }

        $buffer = [];
        foreach (['4' => 'HC32513535', '7' => 'HC46341939', '10' => 'HC45400338'] as $nilaiPh => $serial) {
            $standar = Standard::query()->where('organization_id', $org)->where('serial_number', $serial)->firstOrFail();
            $this->assertTrue(
                $standar->berlaku_sampai === null || $standar->berlaku_sampai->isFuture(),
                "Buffer {$serial} sudah kadaluarsa — perpanjang di seeder, jangan di simulasi.",
            );
            $buffer[(string) $nilaiPh] = $standar->id;
        }

        return [
            'base_urls' => array_map(fn (int $port) => "http://127.0.0.1:{$port}/api", self::PORT),
            'sandi' => self::SANDI,
            'penanda' => self::PENANDA,
            'admin' => array_map(fn (User $u) => ['email' => $u->email, 'nama' => $u->name], $admin),
            'teknisi' => array_map(fn (User $u) => ['email' => $u->email, 'nama' => $u->name], $teknisi),
            'viewer' => ['email' => $viewer->email, 'nama' => $viewer->name],
            'alat' => $alat,
            'buffer' => $buffer,
            'pelanggan' => $pelanggan,
            'metode_pengantaran' => PermintaanKalibrasi::METODE_DIANTAR_SENDIRI,
        ];
    }

    private function nyalakanServerDanPekerja(): void
    {
        // Diwarisi dari proses test (DB tes + kredensial dari pembungkus), lalu
        // ditimpa supaya mirip produksi dan tidak menyentuh layanan luar mana pun.
        $env = [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'MAIL_MAILER' => 'array',
            'ARSIP_DRIVER' => 'local',
            'FCM_PROJECT_ID' => '',
            'FCM_CREDENTIALS' => '',
            'VISION_AKTIF' => 'false',
            'ANTHROPIC_API_KEY' => '',
            'GEMINI_API_KEY' => '',
            'OPENAI_API_KEY' => '',
            'GITHUB_TOKEN' => '',
            'GERBANG_PENGESAHAN' => 'false',
            // Menyala di simulasi (MATI di produksi, 2 Okt 2026) supaya penomoran
            // permintaan & order pelanggan ikut diuji sebelum sakelarnya dinyalakan.
            'FITUR_PELANGGAN' => 'true',
            'SEED_ON_BOOT' => 'false',
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'asmo_db_test',
            'DB_URL' => '',
        ];

        foreach (self::PORT as $port) {
            $server = new Process(
                [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', "--port={$port}", '--no-reload'],
                base_path(),
                $env + ['APP_URL' => "http://127.0.0.1:{$port}"],
            );
            $server->setTimeout(null);
            $server->start();
            $this->proses[] = $server;
        }

        $pekerja = new Process(
            [PHP_BINARY, 'artisan', 'queue:work', '--sleep=1', '--tries=3', '--timeout=600'],
            base_path(),
            $env + ['APP_URL' => 'http://127.0.0.1:'.self::PORT[0]],
        );
        $pekerja->setTimeout(null);
        $pekerja->start();
        $this->proses[] = $pekerja;

        foreach (self::PORT as $port) {
            $this->tungguSiap($port);
        }
    }

    private function tungguSiap(int $port): void
    {
        $tenggat = microtime(true) + 90;
        $konteks = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

        while (microtime(true) < $tenggat) {
            if (@file_get_contents("http://127.0.0.1:{$port}/api/health", false, $konteks) !== false) {
                return;
            }
            usleep(500_000);
        }

        $this->fail("Server port {$port} tidak menyala dalam 90 detik.");
    }

    /**
     * Yang dijamin database, apa pun urutan request-nya.
     *
     * @return array{ringkas: array<string, mixed>, pelanggaran: list<string>}
     */
    private function periksaInvarianDatabase(array $sesiDikenal): array
    {
        $pelanggaran = [];
        $sesiSim = CalibrationSession::query()->where('created_at', '>=', $this->mulaiSimulasi);

        // Sesi yang tersimpan tapi tidak pernah dijawab ke klien mana pun. Belum
        // dijadikan pelanggaran sampai asal-usulnya jelas — rinciannya dicetak
        // supaya bisa dilacak, bukan ditebak.
        $takDikenal = (clone $sesiSim)->whereNotIn('id', $sesiDikenal ?: [0])
            ->get(['id', 'status', 'nomor_sesi', 'teknisi_id', 'equipment_id', 'client_request_id', 'order_item_id', 'created_at'])
            ->toArray();

        $sertifikatPerSesi = Certificate::query()
            ->whereIn('calibration_session_id', (clone $sesiSim)->select('id'))
            ->where('nomor', 'not like', '%-R%')
            ->groupBy('calibration_session_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('calibration_session_id');
        if ($sertifikatPerSesi->isNotEmpty()) {
            $pelanggaran[] = 'Sesi dengan >1 sertifikat: '.$sertifikatPerSesi->implode(', ');
        }

        $kembarClient = (clone $sesiSim)->whereNotNull('client_request_id')
            ->groupBy('client_request_id')->havingRaw('COUNT(*) > 1')->pluck('client_request_id');
        if ($kembarClient->isNotEmpty()) {
            $pelanggaran[] = 'client_request_id melahirkan >1 sesi: '.$kembarClient->implode(', ');
        }

        $disetujuiTanpaSertifikat = (clone $sesiSim)
            ->where('status', CalibrationSession::STATUS_DISETUJUI)
            ->whereDoesntHave('certificate', fn ($q) => $q->where('status', 'terbit'))
            ->pluck('id');
        if ($disetujuiTanpaSertifikat->isNotEmpty()) {
            $pelanggaran[] = 'Sesi disetujui tanpa sertifikat terbit: '.$disetujuiTanpaSertifikat->implode(', ');
        }

        $tidakDisetujuiBersertifikat = (clone $sesiSim)
            ->where('status', '!=', CalibrationSession::STATUS_DISETUJUI)
            ->whereHas('certificate')
            ->pluck('id');
        if ($tidakDisetujuiBersertifikat->isNotEmpty()) {
            $pelanggaran[] = 'Sesi tidak disetujui tapi punya sertifikat: '.$tidakDisetujuiBersertifikat->implode(', ');
        }

        // Celah di urutan nomor bukan pelanggaran (sertifikat gagal bisa
        // meninggalkannya), tapi dilaporkan: asesor menanyakan nomor yang hilang.
        $prefix = 'CAL/'.now()->format('Y/m').'/';
        $nomor = Certificate::query()->where('nomor', 'like', $prefix.'%')->where('nomor', 'not like', '%-R%')
            ->orderBy('nomor')->pluck('nomor')->map(fn (string $n) => (int) substr($n, -4))->all();
        $celah = $nomor === [] ? [] : array_values(array_diff(range(min($nomor), max($nomor)), $nomor));

        $permintaanSim = PermintaanKalibrasi::query()->where('created_at', '>=', $this->mulaiSimulasi);
        $diterimaTanpaOrder = (clone $permintaanSim)
            ->where('status', PermintaanKalibrasi::STATUS_DITERIMA)->whereNull('order_id')->pluck('id');
        if ($diterimaTanpaOrder->isNotEmpty()) {
            $pelanggaran[] = 'Permintaan diterima tanpa order: '.$diterimaTanpaOrder->implode(', ');
        }
        $orderDipakaiDua = (clone $permintaanSim)->whereNotNull('order_id')
            ->groupBy('order_id')->havingRaw('COUNT(*) > 1')->pluck('order_id');
        if ($orderDipakaiDua->isNotEmpty()) {
            $pelanggaran[] = 'Satu order dipakai >1 permintaan: '.$orderDipakaiDua->implode(', ');
        }

        $urutan = fn (string $kelas, string $awalan) => $kelas::query()
            ->where('nomor', 'like', $awalan.now()->format('Y/m').'/%')
            ->orderBy('nomor')->pluck('nomor')->map(fn (string $n) => (int) substr($n, -4))->all();
        $celahDari = fn (array $angka) => $angka === [] ? [] : array_values(array_diff(range(min($angka), max($angka)), $angka));

        return [
            'ringkas' => [
                'permintaan_baru' => (clone $permintaanSim)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
                'order_baru' => Order::query()->where('created_at', '>=', $this->mulaiSimulasi)->count(),
                'celah_nomor_permintaan_bulan_ini' => $celahDari($urutan(PermintaanKalibrasi::class, 'PMT/')),
                'celah_nomor_order_bulan_ini' => $celahDari($urutan(Order::class, 'ORD/')),
                'sesi_baru' => (clone $sesiSim)->count(),
                'sesi_tak_dikenal' => $takDikenal,
                'per_status' => (clone $sesiSim)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
                'sertifikat_baru' => Certificate::query()->where('created_at', '>=', $this->mulaiSimulasi)->count(),
                'celah_nomor_sertifikat_bulan_ini' => $celah,
                'job_tersisa' => DB::table('jobs')->count(),
                'job_gagal' => DB::table('failed_jobs')->count(),
            ],
            'pelanggaran' => $pelanggaran,
        ];
    }

    private function folderKeluaran(): string
    {
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sidik-simulasi-'.now()->format('Ymd-His');
        @mkdir($folder, 0777, true);

        return $folder;
    }

    private function python(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }
}
