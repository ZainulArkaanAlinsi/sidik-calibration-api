<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\PhMeterProfile;
use App\Services\Calibration\Profiles\TidsProfile;
use App\Services\Calibration\Profiles\TitsProfile;
use App\Services\Calibration\TabelStandarTids;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TIDS — Temperatur Indikator dengan Sensor (`SIDIK-FM-CAL-0506 Rev.4`),
 * TAHAP 1: bentuk lembar kerja + jalur simpan. BUKAN budget ketidakpastian.
 *
 * Yang dijaga di sini tiga hal, dan ketiganya gagal dengan cara yang DIAM:
 *
 *  1. **Alatnya nyasar ke lembar orang lain.** `untukNamaAlat()` fallback-nya
 *     profil pH, jadi ejaan yang meleset satu huruf bikin teknisi dapat lembar
 *     buffer 4/7/10 buat indikator suhu — tanpa satu pun error.
 *  2. **Lembar kerjanya kebuka tapi nggak nuturin lima UUT.** Sumbu lima UUT
 *     nggak ada padanannya di 16 profil lain; kalau kunci `sumbu_uut` /
 *     `pengulangan_uut` hilang, lembarnya tetap kegambar rapi — cuma jadi
 *     lembar satu alat, dan empat alat pelanggan lain nggak punya kolom.
 *  3. **Angka ketidakpastian yang dikarang.** Ini yang paling mahal. Baris CMC
 *     TIDS SUDAH ter-seed (0,86 / 1,4 / 3,1 °C), jadi jalur CMC generik di
 *     `GumCalculator::hitungTitik()` bakal SUKSES memulangkan U95 kalau
 *     profilnya nggak menahan — padahal workbook olah data TIDS belum ada dan
 *     nggak ada satu komponen budget pun yang pernah diadu ke dokumen lab.
 *     Sertifikat yang mencetak angka itu temuan audit.
 *
 * @see TidsProfile
 * @see docs/permintaan-user-7.md — K1 (5 UUT) & K2 (workbook TIDS)
 */
class TidsLembarKerjaTest extends TestCase
{
    use RefreshDatabase;

    /** Ejaan yang MENGIKAT — persis lampiran akreditasi LK-285-IDN no. 2. */
    private const NAMA_LAMPIRAN = 'Temperatur Indikator dengan Sensor';

    private User $admin;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
    }

    public function test_registry_kasih_tids_buat_nama_lampiran_akreditasi_bukan_ph(): void
    {
        $registry = app(CalibrationProfileRegistry::class);

        $profil = $registry->untukNamaAlat(self::NAMA_LAMPIRAN);

        $this->assertInstanceOf(TidsProfile::class, $profil);
        $this->assertNotInstanceOf(PhMeterProfile::class, $profil);
        $this->assertSame('tids', $profil->kode());
        $this->assertSame(self::NAMA_LAMPIRAN, $profil->namaAlatKemampuan());

        // Alat pelanggan yang `nama_alat_kemampuan`-nya keisi ikut kena jalur
        // yang sama — ini yang beneran dipakai `CalibrationController`.
        $alat = $this->alatTids();
        $this->assertInstanceOf(TidsProfile::class, $registry->untukAlat($alat));

        // Saudaranya JANGAN ikut kegeser. Dua nama itu cuma beda satu kata,
        // dan pencocokannya nerima kunci yang nempel di tengah nama.
        $this->assertInstanceOf(
            TitsProfile::class,
            $registry->untukNamaAlat('Temperature Indicator tanpa Sensor'),
        );
    }

    /**
     * `GET /api/calibrations/lembar-kerja?profil=tids` memulangkan bentuk yang
     * LENGKAP — tiap bagian yang tercetak di PDF-nya ada, dengan label PDF-nya.
     */
    public function test_lembar_kerja_tids_lengkap(): void
    {
        $data = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=tids')
            ->assertOk()
            ->assertJsonPath('data.kode_dokumen', TidsProfile::KODE_DOKUMEN)
            ->assertJsonPath('data.kode_metode', TidsProfile::KODE_METODE)
            ->assertJsonPath('data.judul', 'Calibration Work Sheet - Temperature Indikator With Sensors')
            ->assertJsonPath('data.semua_kolom_opsional', true)
            ->assertJsonPath('data.satuan', '°C')
            ->json('data');

        $this->assertSame(
            ['identitas_alat', 'pemilik', 'usage_check', 'dryblock', 'titik_es', 'hasil', 'penutup'],
            array_column($data['bagian'], 'kode'),
            'Urutan bagian ngikut POLA BERSAMA semua lembar, bukan urutan kertasnya dibaca dari atas: '
            .'identitas alat > pemilik > standar yang dipakai > pengukuran > penutup. '
            .'Di `SIDIK-FM-CAL-0506 Rev.4` kotak dryblock emang tercetak di ATAS blok `Standard used:`, '
            .'tapi ngikutin itu bikin TIDS satu-satunya dari tujuh belas lembar yang `usage_check`-nya '
            .'nggak di posisi ketiga. Kertas & lembar cetaknya nggak ikut berubah — yang digeser cuma '
            .'urutan baca di layar.',
        );

        $bagian = collect($data['bagian'])->keyBy('kode');

        // `equipment_id` WAJIB ada: tombol kirim di HP nahan sesi yang alatnya
        // belum dipilih, jadi profil tanpa field ini bikin lembar yang bisa
        // diisi penuh lalu nggak bisa dikirim sama sekali (kasus Enclosure).
        $kodeIdentitas = array_column($bagian['identitas_alat']['field'], 'kode');
        $this->assertContains('equipment_id', $kodeIdentitas);
        $this->assertContains('spesifikasi_alat.resolusi', $kodeIdentitas);
        $this->assertContains('thermohygro_standard_id', $kodeIdentitas);

        // Inlab/Insitu + kolom teks bebas nama tempat buat Insitu.
        $lokasi = collect($bagian['identitas_alat']['field'])->firstWhere('kode', 'lokasi');
        $this->assertSame(
            [['nilai' => 'lab', 'label' => 'Inlab'], ['nilai' => 'onsite', 'label' => 'Insitu']],
            $lokasi['pilihan'],
        );
        $this->assertContains('lokasi_nama', $kodeIdentitas);

        // Empat thermohygro tercetak di kop, berikut lokasi pemakaiannya.
        $this->assertSame(
            ['TH-4', 'TH-2', 'TH-6', 'TH-7'],
            array_column($bagian['identitas_alat']['baris_thermohygro'], 'label'),
        );
        $this->assertSame('Inlab', $bagian['identitas_alat']['baris_thermohygro'][0]['lokasi']);

        // Dryblock A (Isotech) / B (Techne) — label persis kertasnya.
        $dryblock = collect($bagian['dryblock']['field'])->firstWhere('kode', 'spesifikasi_alat.dryblock');
        $this->assertSame(['A (Isotech)', 'B (Techne)'], array_column($dryblock['pilihan'], 'label'));

        // Blok `Standard used:` — TIGA kalibrator + tiga sensor acuan.
        //
        // Baris ketiga (Temperature Recorder Graptech GL840, s/n C305B1470)
        // masuk 28 Agt 2026 bersama workbook Recorder. Dicocokkan lewat SERIAL:
        // kertas TIDS nulis "Graptech", master `standards` nulis "Graphtech",
        // dan dua ejaan itu nggak akan pernah ketemu lewat nama.
        $this->assertSame(
            [
                'Temperature Calibrator/Constant/40T/99875850',
                'Temperature Calibrator/Yokogawa/CA150 Handy Cal/23P1005',
                'Temperature Recorder/Graptech/GL840/C305B1470',
            ],
            array_column($bagian['usage_check']['baris'], 'label'),
        );
        $this->assertSame(
            ['Thermocouple Type-K', 'Thermocouple Type-N', 'Sensor RTD/PT 100'],
            array_column($bagian['usage_check']['baris_sensor_standar'], 'label'),
        );

        // Uji titik es 0 °C — Awal & Akhir.
        //
        // Kodenya pindah ke `titik_es_N` (kolom sesi kanonik, sama dengan
        // lembar Termometer Gelas) 28 Agt 2026, waktu selisih dua angka ini
        // akhirnya punya arti: komponen budget `Drift UUT`. Peta
        // `spesifikasi_alat` yang lama tetap DIBACA sebagai cadangan supaya
        // sesi yang sudah tersimpan dari APK lama nggak kehilangan komponennya.
        $this->assertSame('Pengujian di titik es 0˚C', $bagian['titik_es']['judul']);
        $this->assertSame(
            ['titik_es_1', 'titik_es_2'],
            array_column($bagian['titik_es']['field'], 'kode'),
        );
        $this->assertSame(['Awal', 'Akhir'], array_column($bagian['titik_es']['field'], 'label'));

        // Penutup: Catatan + dua tanda tangan.
        $this->assertSame(
            ['Catatan', 'Dikalibrasi Oleh', 'Diperiksa Oleh'],
            array_column($bagian['penutup']['field'], 'label'),
        );

        // DUA gerbang foto, dan bedanya disengaja:
        //
        //  - `lokal` menggerbangi tombol `FOTO TABEL INI` — ML Kit, sepenuhnya
        //    di HP. DINYALAKAN 27 Agt 2026: dua tabelnya difoto satu-satu, dan
        //    masing-masing memang baris × kolom. Barisnya dijangkar tulisan
        //    `Set point N`, kolomnya `0" (UUT1)`.
        //  - `didukung` menggerbangi `POST /raw-measurements/extract-from-photo`,
        //    yang MENGIRIM FOTO LEMBAR KERJA PELANGGAN KE LAYANAN PIHAK KETIGA.
        //    TETAP DITOLAK.
        //
        // Keduanya sempat jadi satu penanda, dan menyalakan yang lokal
        // diam-diam ikut melebarkan batas datanya. Dua-duanya di-assert di
        // sini supaya penyatuan itu nggak bisa balik tanpa ketahuan.
        $this->assertFalse(
            $data['pindai_foto']['didukung'],
            'Foto TIDS nggak boleh keluar dari HP. `didukung: true` bikin lembar ini memenuhi '
            .'syarat dikirim ke penyedia AI pihak ketiga begitu Vision nyala.',
        );
        $this->assertTrue($data['pindai_foto']['lokal']);

        // Tulisan kepala kolom yang tercetak WAJIB ikut dikirim — itu
        // satu-satunya jangkar sumbu mendatar yang dipunya jalur foto. Tanpa
        // dia, tombolnya nyala dan tiap jepretan pulang nol sel.
        $tabel = collect($data['bagian'])
            ->flatMap(static fn (array $b): array => $b['tabel'] ?? [])
            ->firstWhere('tahap', 'pembacaan_standard');
        $this->assertNotNull($tabel, 'Tabel Pembacaan Standard hilang dari bentuknya.');
        $this->assertSame(
            '0" (UUT1)',
            $tabel['pengulangan_uut'][0]['label'],
        );

        // Dan `simpan_ke` tetap dinyatakan eksplisit: tabel Pembacaan Standard
        // belum punya kolom penampung, dan layar HP menggambar keterangannya
        // dari kunci ini. Dihilangkan, teknisi mengisi 35 kotak yang hilang
        // tanpa pesan apa pun.
        $this->assertArrayHasKey('simpan_ke', $tabel);
    }

    /**
     * Bagian admin cuma nongol buat admin — dan isinya ikut dijaga.
     *
     * Sebelum ini `administratif` satu-satunya bagian yang lolos dari semua
     * test: penguncian urutan bagian di atas jalan pakai bentuk TEKNISI, jadi
     * bagian admin bisa hilang, berubah nama, atau kehilangan field-nya tanpa
     * satu pun test berubah merah.
     *
     * Dua kolom U95 di dalamnya yang bikin ini bukan sekadar kerapian:
     * `suhu_ketidakpastian` & `kelembaban_ketidakpastian` itu U95 KONDISI
     * LINGKUNGAN (dari sertifikat thermohygro), BUKAN U95 hasil kalibrasi TIDS
     * yang memang masih terblokir. Dua hal beda yang gampang ketuker justru
     * karena namanya mirip — dan yang ketuker bakal kecetak di sertifikat.
     */
    public function test_bagian_admin_cuma_buat_admin_dan_isinya_kejaga(): void
    {
        $kodeBagian = static fn (array $data): array => array_column($data['bagian'], 'kode');

        // Teknisi: NGGAK dapat bagian admin.
        $teknisi = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=tids')
            ->assertOk()
            ->json('data');

        $this->assertNotContains('administratif', $kodeBagian($teknisi));

        // Admin: dapat, dan bagiannya paling belakang.
        $admin = $this->actingAs($this->admin)
            ->getJson('/api/calibrations/lembar-kerja?profil=tids')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            ['identitas_alat', 'pemilik', 'usage_check', 'dryblock', 'titik_es', 'hasil', 'penutup', 'administratif'],
            $kodeBagian($admin),
        );

        $bagian = collect($admin['bagian'])->firstWhere('kode', 'administratif');

        $this->assertSame(
            ['nomor_order', 'certificate.nomor', 'suhu_ketidakpastian', 'kelembaban_ketidakpastian'],
            array_column($bagian['field'], 'kode'),
        );

        // Keempatnya WAJIB bertanda admin. Satu saja yang lepas, teknisi bisa
        // ngetik nomor sertifikat sendiri.
        foreach ($bagian['field'] as $f) {
            $this->assertTrue($f['hanya_admin'] ?? false, "Field `{$f['kode']}` lepas dari tanda admin.");
        }

        // Dua kolom U95 ini U95 KONDISI LINGKUNGAN, bukan U95 kalibrasi TIDS.
        // Satuannya yang membedakan, dan satuannya nggak boleh ketuker.
        $satuan = collect($bagian['field'])->keyBy('kode');
        $this->assertSame('°C', $satuan['suhu_ketidakpastian']['satuan']);
        $this->assertSame('%RH', $satuan['kelembaban_ketidakpastian']['satuan']);
    }

    /**
     * Dua tabel, dan kepala kolomnya diambil dari PDF-nya: standar dibaca di
     * detik 0/20/40/60/80, alatnya 10 detik sesudahnya di 10/30/50/70/90.
     */
    public function test_dua_tabel_menuturkan_lima_uut_per_interval_waktu(): void
    {
        $data = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=tids')
            ->assertOk()
            ->json('data');

        $this->assertSame(5, $data['sumbu_uut']['jumlah']);
        $this->assertSame(
            ['UUT1', 'UUT2', 'UUT3', 'UUT4', 'UUT5'],
            array_column($data['sumbu_uut']['daftar'], 'label'),
        );
        // DIJAWAB workbook master 28 Agt 2026: lima kolom itu lima ULANGAN,
        // bukan lima alat. Dinyatakan sebagai data — bukan cuma di komentar
        // yang nggak kebaca sisi HP.
        $this->assertSame('lima_ulangan', $data['sumbu_uut']['keputusan_skema']);
        // Label CETAK-nya tetap `UUT1` (itu yang tertulis di kertas yang
        // dipegang teknisi & jadi jangkar OCR); label master-nya ikut dikirim
        // supaya dua dokumen itu bisa diadu tanpa nebak.
        $this->assertSame(
            ['PRT1', 'PRT2', 'PRT3', 'PRT4', 'PRT5'],
            array_column($data['sumbu_uut']['daftar'], 'label_master'),
        );

        $tabel = collect($data['bagian'])->firstWhere('kode', 'hasil')['tabel'];
        $this->assertCount(2, $tabel);

        $this->assertSame('pembacaan_standard', $tabel[0]['tahap']);
        $this->assertSame('Pembacaan Standard', $tabel[0]['judul']);
        $this->assertSame(
            ['0" (UUT1)', '20" (UUT2)', '40" (UUT3)', '60" (UUT4)', '80" (UUT5)'],
            array_column($tabel[0]['pengulangan_uut'], 'label'),
        );

        $this->assertSame('pembacaan_uut', $tabel[1]['tahap']);
        $this->assertSame('Pembacaan Alat yang Dikalibrasi', $tabel[1]['judul']);
        $this->assertSame(
            ['10" (UUT1)', '30" (UUT2)', '50" (UUT3)', '70" (UUT4)', '90" (UUT5)'],
            array_column($tabel[1]['pengulangan_uut'], 'label'),
        );

        foreach ($tabel as $t) {
            // Bentuk lama TETAP bentuknya: aplikasi teknisi nyaring
            // `pengulangan` pakai `whereType<num>()` dan daftar objek kebuang
            // diam-diam — tabelnya kebuka rapi tanpa satu pun kolom pembacaan.
            $this->assertSame([1, 2, 3, 4, 5], $t['pengulangan']);
            $this->assertIsString($t['judul_nilai']);

            // Tujuh baris set point KOSONG, persis kertasnya. Nggak ada satu
            // angka pun tercetak di kolom Setpoint lembar TIDS, jadi
            // menyodorkan deret saran = mengarang prosedur.
            $this->assertCount(TidsProfile::BARIS_SETPOINT_KERTAS, $t['baris']);
            $this->assertNull($t['baris'][0]['titik_ukur']);
            $this->assertTrue($t['titik_bisa_diubah']);
        }

        // Tabel Pembacaan Standard AKHIRNYA punya kolom penyimpanan.
        // Sampai 27 Agt 2026 kuncinya `null` — pernyataan jujur waktu itu, dan
        // artinya 35 kotak yang diisi teknisi nggak pernah nyampe server.
        // Sekarang dua-duanya lewat sumbu `peran_sensor` yang sudah ada sejak
        // Enclosure; nol kolom baru di `raw_measurements`.
        $this->assertSame('measurements[].standar', $tabel[0]['simpan_ke']);
        $this->assertSame('measurements[].uut', $tabel[1]['simpan_ke']);
        $this->assertSame('standar', $tabel[0]['peran']);
        $this->assertSame('uut', $tabel[1]['peran']);
    }

    /**
     * Lembar kerjanya MENYATAKAN budget-nya sudah ada, dan menyatakannya
     * sebagai data.
     *
     * Kuncinya dipertahankan (bukan dihapus) waktu jawabannya berubah dari
     * `false` ke `true` 28 Agt 2026: HP membacanya buat memutuskan apakah panel
     * hasil digambar, dan kunci yang hilang bikin APK lama jatuh ke cabang
     * "belum ada" — lembar yang sebenarnya sudah bisa menghitung tampil seperti
     * masih terblokir.
     */
    public function test_lembar_kerja_ngaku_budget_ketidakpastiannya_sudah_ada(): void
    {
        $data = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=tids')
            ->assertOk()
            ->assertJsonPath('data.budget_ketidakpastian.tersedia', true)
            ->json('data.budget_ketidakpastian');

        $this->assertStringContainsString('Recorder Graptech', $data['sumber']);
        $this->assertStringContainsString('Yokogawa', $data['sumber']);

        // Kunci `butuh` — daftar bahan yang dulu kurang — HARUS ilang, bukan
        // ditinggal berisi daftar basi. Daftar yang sudah nggak berlaku lebih
        // buruk dari nggak ada daftar: yang membacanya nyari barang yang sudah
        // di tangan.
        $this->assertArrayNotHasKey('butuh', $data);
    }

    /**
     * INTI test ini: sesi TIDS yang BAHANNYA KURANG tersimpan utuh, dan NOL
     * angka ketidakpastian.
     *
     * Sampai 28 Agt 2026 judulnya "budget-nya belum ada"; sekarang budget-nya
     * ada, dan yang dijaga di sini justru jadi lebih tajam: sesi ini nggak
     * menyebut tipe sensor standar maupun dryblock, dan tanpa dua itu koreksi
     * meter, koreksi sensor, U95 & drift-nya nggak punya baris tabel sama
     * sekali.
     *
     * Yang dilawan bukan pengecualian yang meledak, tapi jalur yang berhasil
     * diam-diam. Baris CMC TIDS sudah ter-seed, jadi tanpa
     * `TidsProfile::hitungPerGrup()` yang menahan, `GumCalculator` bakal
     * memulangkan `U95 = CMC` buat tiap titik — angka yang kelihatan sah dan
     * lolos ke sertifikat.
     *
     * Deret DATAR-nya juga dijaga di sini: payload ini bentuk lama
     * (`measurements[].pembacaan`, tanpa `standar`/`uut`), dan sepuluh baris
     * mentahnya WAJIB tetap tersimpan sesudah lembar ini pindah ke jalur
     * pasangan. Kalau `assertSame(10, …)` di bawah jadi nol, APK yang sudah
     * terpasang kehilangan seluruh kerja lapangannya tanpa satu pun error.
     */
    public function test_sesi_tids_bahan_kurang_nggak_ngeluarin_angka_karangan(): void
    {
        $alat = $this->alatTids();
        $standar = Standard::factory()->create(['nama' => 'Temperature Calibrator Constant 40T']);
        $this->seedCmcTids();

        $respons = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $alat->id,
                'standard_id' => $standar->id,
                'input_method' => 'manual',
                'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
                'suhu_ruang' => 23.5,
                'kelembaban' => 55.0,
                'measurements' => [
                    ['titik_ukur' => 100.0, 'satuan' => '°C', 'pembacaan' => [100.1, 100.2, 100.0, 100.1, 100.2]],
                    ['titik_ukur' => 300.0, 'satuan' => '°C', 'pembacaan' => [300.4, 300.2, 300.3, 300.1, 300.5]],
                ],
            ])
            ->assertCreated();

        $sesi = CalibrationSession::findOrFail($respons->json('data.id'));

        // Pengukuran MENTAHNYA tersimpan utuh — teknisi nggak kehilangan kerja
        // lapangan gara-gara rumusnya belum ada.
        $this->assertSame(10, $sesi->rawMeasurements()->count());

        // Dan NOL baris hitungan. Ini baris yang paling penting di file ini.
        $this->assertSame(
            0,
            $sesi->uncertaintyCalculations()->count(),
            'Sesi TIDS yang tipe sensor & dryblock-nya kosong nggak boleh punya satu pun baris '
            .'ketidakpastian — jalur CMC generik bakal ngasih angka yang kelihatan sah.',
        );

        // Dan alasannya kebaca teknisi SEBELUM dia kirim, lewat jalur "hitung
        // sambil ngetik" — bukan titiknya hilang begitu aja dari layar.
        $alasan = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', [
                'equipment_id' => $alat->id,
                'standard_id' => $standar->id,
                'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
                'measurements' => [
                    ['titik_ukur' => 100.0, 'satuan' => '°C', 'pembacaan' => [100.1, 100.2, 100.0, 100.1, 100.2]],
                ],
            ])
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertCount(1, $alasan);
        $this->assertStringContainsString('Tipe sensor STANDAR belum dipilih', $alasan[0]['alasan']);
    }

    /**
     * Peringatan sesi dipasang SEJAK AWAL, bukan ditambal belakangan.
     *
     * Yang paling penting `tids_titik_luar_cmc`: CMC TIDS berhenti di 600 °C,
     * sementara sensor acuan yang tercetak di lembar kerjanya sendiri (Type-K &
     * Type-N) berlaku sampai 1000 °C. Artinya set point 900 °C itu WAJAR
     * diambil — alatnya sanggup, sensornya sanggup — dan yang nggak sanggup
     * cuma klaim akreditasi lab, satu-satunya hal yang nggak kelihatan dari
     * meja kerja.
     */
    public function test_peringatan_sesi_muncul_sejak_awal(): void
    {
        $sesi = new CalibrationSession(['spesifikasi_alat' => []]);
        $kode = array_column((new TidsProfile)->peringatanSesi($sesi), 'kode');

        // Tiga bahan yang tanpanya sesi TIDS nggak kehitung sama sekali.
        // `tids_budget_belum_ada` DICABUT 28 Agt 2026 — budget-nya sudah ada,
        // dan peringatan yang bilang sebaliknya bikin admin nahan sesi yang
        // sebenarnya sudah lengkap.
        $this->assertContains('tids_dryblock_kosong', $kode);
        $this->assertContains('tids_tipe_sensor_kosong', $kode);
        $this->assertContains('tids_titik_es_kosong', $kode);
        $this->assertNotContains('tids_budget_belum_ada', $kode);

        // Yang UDAH diisi nggak diperingatin lagi — tiga-tiganya, dan dryblock
        // sengaja diisi lewat ejaan kolom `alat_bantu` (`A`) buat mbuktiin
        // jalur cadangannya hidup.
        $lengkap = new CalibrationSession([
            'spesifikasi_alat' => ['titik_es_awal' => 0.2, 'titik_es_akhir' => 0.4],
            'alat_bantu' => 'A',
            'tipe_sensor' => 'Type K',
        ]);
        $kodeLengkap = array_column((new TidsProfile)->peringatanSesi($lengkap), 'kode');

        $this->assertNotContains('tids_dryblock_kosong', $kodeLengkap);
        $this->assertNotContains('tids_tipe_sensor_kosong', $kodeLengkap);
        $this->assertNotContains('tids_titik_es_kosong', $kodeLengkap);
    }

    /**
     * Kolom `No. Termokopel` per baris tabel standar — berikut DAFTAR
     * PILIHANNYA.
     *
     * Yang dijaga bukan keberadaan kolomnya, tapi isi `pilihan`-nya. Layar HP
     * (`_BarisNoProbe`) menggambar kolom ini sebagai dropdown yang daftarnya
     * disaring `grup == tipe_sensor`; dikirim kosong, dropdown-nya lahir tanpa
     * satu pun pilihan dan lembar TIDS nggak bisa diisi sama sekali — tanpa
     * satu pun error di jalur mana pun. Sudah kejadian sekali di sini, di
     * jam yang sama dengan lembar ini pindah ke jalur pasangan.
     */
    public function test_kolom_no_termokopel_bawa_daftar_pilihannya(): void
    {
        $tabel = collect(
            $this->actingAs($this->teknisi)
                ->getJson('/api/calibrations/lembar-kerja?profil=tids')
                ->assertOk()
                ->json('data.bagian'),
        )->firstWhere('kode', 'hasil')['tabel'];

        // Cuma tabel STANDAR yang punya kolomnya — UUT membawa sensor bawaan
        // alat pelanggan, yang justru sedang diukur penyimpangannya.
        $this->assertArrayNotHasKey('kolom_baris', $tabel[1]);
        $this->assertCount(1, $tabel[0]['kolom_baris']);

        $kolom = $tabel[0]['kolom_baris'][0];
        $this->assertSame('no_probe', $kolom['kode']);
        $this->assertSame('pilihan', $kolom['tipe']);
        $this->assertNotEmpty($kolom['pilihan'], 'Dropdown tanpa pilihan = lembar yang nggak bisa diisi.');

        // Penomorannya BEDA per tipe, dan itu dari kertasnya sendiri: "If
        // using Thermocouple Type N, No. Thermocouple START FROM 3. If using
        // PRT PT100 (RTD), No. Thermocouple ALL 17."
        $perGrup = [];
        foreach ($kolom['pilihan'] as $p) {
            $perGrup[$p['grup']][] = (int) $p['nilai'];
        }

        $this->assertSame(['RTD', 'Type K', 'Type N'], array_keys($perGrup));
        $this->assertSame([TabelStandarTids::NOMOR_RTD], $perGrup['RTD']);
        $this->assertSame(range(1, 16), $perGrup['Type K']);
        $this->assertSame(range(3, 12), $perGrup['Type N']);
    }

    /**
     * Empat penyimpangan master yang ditiru NAIK KE LAYAR, bukan cuma ke jejak
     * audit.
     *
     * Tiga di antaranya menggeser U95 ke arah lebih KECIL, dan sertifikat yang
     * understate ketidakpastiannya itu temuan asesor. Jadi tiap sesi TIDS yang
     * standarnya sudah dikenali WAJIB membawa peringatannya — itu yang menahan
     * tombol APPROVE sampai ada manusia yang membacanya.
     */
    public function test_penyimpangan_master_naik_jadi_peringatan_sesi(): void
    {
        $recorder = Standard::factory()->create([
            'nama' => 'Temperature Recorder Graphtech GL840',
            'merk' => 'Graphtech',
            'serial_number' => 'C305B1470',
        ]);
        $yokogawa = Standard::factory()->create([
            'nama' => 'Temperature Calibrator Yokogawa CA 150 Handy Cal',
            'merk' => 'Yokogawa',
            'serial_number' => '23P1005',
        ]);

        // SATU alat buat dua sesi — `alatTids()` bikin kategori
        // `suhu-dan-kelembapan` sendiri tiap dipanggil, dan kodenya unik per
        // organisasi.
        $alat = $this->alatTids();

        $sesiRecorder = CalibrationSession::factory()->create([
            'equipment_id' => $alat->id,
            'standard_id' => $recorder->id,
        ]);
        $kode = array_column((new TidsProfile)->peringatanSesi($sesiRecorder->fresh()), 'kode');
        $this->assertContains('tids_master_recorder_sel_tetap', $kode);

        $sesiYokogawa = CalibrationSession::factory()->create([
            'equipment_id' => $alat->id,
            'standard_id' => $yokogawa->id,
        ]);
        $kodeYoko = array_column((new TidsProfile)->peringatanSesi($sesiYokogawa->fresh()), 'kode');
        $this->assertContains('tids_master_tiga_komponen_tidak_dijumlah', $kodeYoko);
    }

    public function test_set_point_di_atas_600_derajat_diperingatin(): void
    {
        $alat = $this->alatTids();
        $standar = Standard::factory()->create();
        $this->seedCmcTids();

        $sesi = CalibrationSession::factory()->create([
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
            'teknisi_id' => $this->teknisi->id,
        ]);

        // Baris hitungan dibikin langsung: jalur normal sengaja NGGAK
        // memproduksinya (lihat test di atas), padahal peringatan rentang harus
        // tetap jalan buat sesi yang barisnya datang dari jalur lain — impor
        // lembar lama, atau nanti waktu workbook-nya udah masuk.
        $sesi->uncertaintyCalculations()->create($this->barisHitungan(1, 300.0));
        $sesi->uncertaintyCalculations()->create($this->barisHitungan(2, 900.0));

        $peringatan = (new TidsProfile)->peringatanSesi($sesi->fresh(['uncertaintyCalculations']));
        $luarCmc = array_values(array_filter(
            $peringatan,
            static fn (array $p): bool => $p['kode'] === 'tids_titik_luar_cmc',
        ));

        $this->assertCount(1, $luarCmc, 'Cuma titik 900 °C yang di luar rentang CMC (−20…600 °C).');
        $this->assertStringContainsString('900', $luarCmc[0]['pesan']);
        $this->assertStringContainsString('600', $luarCmc[0]['pesan']);
    }

    /**
     * Ejaan TIDS yang BUKAN byte-exact lampiran akreditasi tetap NOL angka.
     *
     * Ini lubang yang paling mahal di berkas ini, dan sampai perbaikan routing
     * profil dia menganga: `kodeProfilDariNama()` nerima alias + kunci yang
     * nempel di tengah nama, jadi HP dapat lembar TIDS buat "Temperature
     * Indikator With Sensors" (judul lembar kerjanya SENDIRI). Tapi
     * `untukNamaAlat()` cocoknya PERSIS dan jatuh ke pH — jadi yang MENGHITUNG
     * bukan `TidsProfile` melainkan `PhMeterProfile`.
     *
     * Akibatnya berantai dan semuanya diam:
     *
     *  - `TidsProfile::hitungPerGrup()` — satu-satunya rem yang nahan angka —
     *    nggak pernah kepanggil;
     *  - `GumCalculator::hitungTitik()` jalan lewat jalur CMC generik, dan
     *    baris CMC TIDS SUDAH ter-seed, jadi tiap titik terbit `U95 = CMC`;
     *  - `peringatanSesi()` yang mestinya masang peringatan bahan-kurang ikut
     *    hilang, jadi nggak ada satu pun tanda yang nahan tombol APPROVE.
     *
     * Angka itu lantai kemampuan terbaik lab, bukan hasil hitung sesi ini —
     * dan dia nggak pernah diturunkan dari workbook mana pun. Sejak endpoint
     * tambah-alat hidup, tiap teknisi lapangan bisa bikin nama varian sendiri.
     *
     * Baris CMC di sini sengaja diseed di KATEGORI YANG SAMA dengan alatnya
     * dan pakai ejaan varian yang sama — kalau nggak, `kemampuanUntukTitik()`
     * nggak ketemu apa-apa dan test ini bakal hijau tanpa membuktikan apa pun.
     *
     * `toleransi` alatnya juga sengaja DIISI, alasannya sama: selama kolom itu
     * kosong, jalur pH ketahan lebih dulu di `alasanBelumBisaDihitung()`
     * ("Toleransi alat masih kosong") dan angkanya nggak sempat terbit —
     * ketahan gara-gara kolom yang belum diisi, bukan gara-gara ada yang
     * nahan. Begitu admin ngisi toleransi (dan pesan errornya emang nyuruh
     * gitu), remnya lepas: dijalanin sebelum perbaikan, sesi ini nerbitin
     * U95 0,86324967 °C di 100 °C dan 1,40712473 °C di 300 °C — persis lantai
     * CMC 0,86 & 1,4 dari lampiran akreditasi, bukan hasil hitung sesi.
     */
    public function test_sesi_tids_nama_varian_nggak_nerbitin_satu_pun_u95(): void
    {
        $kategori = EquipmentCategory::factory()->create(['kode' => 'suhu-dan-kelembapan']);

        // Judul lembar kerja TIDS-nya sendiri (`SIDIK-FM-CAL-0506 Rev.4`) —
        // ejaan yang paling wajar diketik teknisi, dan beda dari lampiran.
        $namaVarian = 'Temperature Indikator With Sensors';

        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Temperature Indicator',
            'nama_alat_kemampuan' => $namaVarian,
            'range_min' => -20, 'range_max' => 600,
            'satuan' => '°C', 'resolusi' => 0.1, 'toleransi' => 1.0,
        ]);

        foreach ([[-20.0, 150.0, 0.86], [150.0, 400.0, 1.4], [400.0, 600.0, 3.1]] as [$min, $maks, $u]) {
            CalibrationCapability::factory()->create([
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $namaVarian,
                'range_min' => $min,
                'range_max' => $maks,
                'satuan' => '°C',
                'ketidakpastian_terbaik' => $u,
                'satuan_ketidakpastian' => '°C',
                'metode' => TidsProfile::KODE_METODE,
            ]);
        }

        $registry = app(CalibrationProfileRegistry::class);
        $this->assertSame('tids', $registry->kodeProfilDariNama($namaVarian), 'HP dapat lembar TIDS.');
        $this->assertInstanceOf(
            TidsProfile::class,
            $registry->untukAlat($alat),
            'Dan yang MENGHITUNG harus TidsProfile juga — bukan profil default.',
        );

        $standar = Standard::factory()->create(['nama' => 'Temperature Calibrator Constant 40T']);

        $respons = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $alat->id,
                'standard_id' => $standar->id,
                'input_method' => 'manual',
                'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
                'suhu_ruang' => 23.5,
                'kelembaban' => 55.0,
                'measurements' => [
                    ['titik_ukur' => 100.0, 'satuan' => '°C', 'pembacaan' => [100.1, 100.2, 100.0, 100.1, 100.2]],
                    ['titik_ukur' => 300.0, 'satuan' => '°C', 'pembacaan' => [300.4, 300.2, 300.3, 300.1, 300.5]],
                ],
            ])
            ->assertCreated();

        $sesi = CalibrationSession::findOrFail($respons->json('data.id'));

        // Kerja lapangannya tetap utuh — yang ditahan angkanya, bukan sesinya.
        $this->assertSame(10, $sesi->rawMeasurements()->count());

        // Baris yang paling penting: NOL U95, walaupun baris CMC-nya ada dan
        // jalur generik sanggup memulangkan angka buat kedua titik.
        $this->assertSame(
            0,
            $sesi->uncertaintyCalculations()->count(),
            'Sesi TIDS bernama varian nggak boleh nerbitin satu pun U95 — angkanya bakal lahir dari '
            .'lantai CMC, bukan dari budget yang belum pernah disusun.',
        );

        // Dan remnya kelihatan sebagai peringatan, jadi ada yang nahan APPROVE.
        // Sejak budget-nya ada (28 Agt 2026) yang menahan bukan lagi
        // "budget belum ada" melainkan bahan yang memang kurang di sesi ini.
        $kode = array_column($registry->untukAlat($alat)->peringatanSesi($sesi), 'kode');
        $this->assertContains('tids_tipe_sensor_kosong', $kode);
        $this->assertContains('tids_dryblock_kosong', $kode);
    }

    private function alatTids(): Equipment
    {
        return Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'suhu-dan-kelembapan'])->id,
            'nama_alat' => 'Temperature Indicator',
            'nama_alat_kemampuan' => self::NAMA_LAMPIRAN,
            'range_min' => -20, 'range_max' => 600,
            'satuan' => '°C', 'resolusi' => 0.1, 'toleransi' => null,
        ]);
    }

    /**
     * Tiga pita CMC TIDS dari lampiran akreditasi. Sengaja diseed di test yang
     * nguji "nggak ada angka yang keluar" — justru KARENA barisnya ada, jalur
     * CMC generik bakal sukses kalau profilnya nggak menahan.
     */
    private function seedCmcTids(): void
    {
        foreach ([[-20.0, 150.0, 0.86], [150.0, 400.0, 1.4], [400.0, 600.0, 3.1]] as [$min, $maks, $u]) {
            CalibrationCapability::factory()->create([
                'nama_alat' => self::NAMA_LAMPIRAN,
                'range_min' => $min,
                'range_max' => $maks,
                'satuan' => '°C',
                'ketidakpastian_terbaik' => $u,
                'satuan_ketidakpastian' => '°C',
                'metode' => TidsProfile::KODE_METODE,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function barisHitungan(int $titikKe, float $titikUkur): array
    {
        return [
            'titik_ke' => $titikKe,
            'titik_ukur' => $titikUkur,
            'rata_rata' => $titikUkur,
            'error' => 0.0,
            'koreksi' => 0.0,
            'standar_deviasi' => 0.1,
            'jumlah_pengulangan' => 5,
            'type_a' => 0.045,
            'type_b' => 0.5,
            'ketidakpastian_gabungan' => 0.5,
            'faktor_cakupan_k' => 2.0,
            'ketidakpastian_diperluas' => 1.0,
        ];
    }
}
