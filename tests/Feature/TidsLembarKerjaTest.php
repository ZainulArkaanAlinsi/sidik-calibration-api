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
            ['identitas_alat', 'pemilik', 'dryblock', 'usage_check', 'titik_es', 'hasil', 'penutup'],
            array_column($data['bagian'], 'kode'),
            'Urutan bagian ngikut urutan kertasnya dibaca dari atas.',
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

        // Blok `Standard used:` — dua kalibrator + tiga sensor acuan.
        $this->assertCount(2, $bagian['usage_check']['baris']);
        $this->assertSame(
            ['Thermocouple Type-K', 'Thermocouple Type-N', 'Sensor RTD/PT 100'],
            array_column($bagian['usage_check']['baris_sensor_standar'], 'label'),
        );

        // Uji titik es 0 °C — Awal & Akhir.
        $this->assertSame('Pengujian di titik es 0˚C', $bagian['titik_es']['judul']);
        $this->assertSame(
            ['spesifikasi_alat.titik_es_awal', 'spesifikasi_alat.titik_es_akhir'],
            array_column($bagian['titik_es']['field'], 'kode'),
        );

        // Penutup: Catatan + dua tanda tangan.
        $this->assertSame(
            ['Catatan', 'Dikalibrasi Oleh', 'Diperiksa Oleh'],
            array_column($bagian['penutup']['field'], 'label'),
        );

        // Jalur pindai foto DITOLAK — kertas TIDS bukan "titik × Repeat".
        $this->assertFalse($data['pindai_foto']['didukung']);
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
        // Keputusan skema sengaja belum diambil, dan itu dinyatakan sebagai
        // data — bukan cuma di komentar yang nggak kebaca sisi HP.
        $this->assertSame('belum_diambil', $data['sumbu_uut']['keputusan_skema']);

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

        // Tabel Pembacaan Standard BELUM punya kolom penyimpanan.
        // `raw_measurements.tahap` artinya as-found/as-left, bukan
        // standar/UUT — dan `CalibrationController` maksa nilainya sendiri.
        $this->assertNull($tabel[0]['simpan_ke']);
        $this->assertSame('measurements[].pembacaan', $tabel[1]['simpan_ke']);
    }

    /**
     * Lembar kerjanya MENYATAKAN budget-nya kosong, dan menyatakannya sebagai
     * data — supaya HP bisa nunjukin keadaannya ke teknisi SEBELUM dia ngisi
     * puluhan kotak angka, bukan sesudah waktu tombol hitungnya nggak
     * memulangkan apa-apa dan kelihatan kayak bug.
     */
    public function test_lembar_kerja_ngaku_budget_ketidakpastiannya_belum_ada(): void
    {
        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=tids')
            ->assertOk()
            ->assertJsonPath('data.budget_ketidakpastian.tersedia', false)
            ->assertJsonCount(3, 'data.budget_ketidakpastian.butuh');
    }

    /**
     * INTI test ini: sesi TIDS tersimpan utuh, dan NOL angka ketidakpastian.
     *
     * Yang dilawan bukan pengecualian yang meledak, tapi jalur yang berhasil
     * diam-diam. Baris CMC TIDS sudah ter-seed, jadi tanpa
     * `TidsProfile::hitungPerGrup()` yang memblokir, `GumCalculator` bakal
     * memulangkan `U95 = CMC` buat tiap titik — angka yang kelihatan sah dan
     * lolos ke sertifikat.
     */
    public function test_sesi_tids_nggak_ngeluarin_angka_ketidakpastian_karangan(): void
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
            'Sesi TIDS nggak boleh punya satu pun baris ketidakpastian — budget-nya belum ada, '
            .'dan jalur CMC generik bakal ngasih angka yang kelihatan sah.',
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
        $this->assertStringContainsString('Budget ketidakpastian TIDS belum ada', $alasan[0]['alasan']);
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

        $this->assertContains('tids_budget_belum_ada', $kode);
        $this->assertContains('tids_dryblock_kosong', $kode);

        // Dryblock yang UDAH dicentang nggak diperingatin lagi.
        $sesiBerdryblock = new CalibrationSession(['spesifikasi_alat' => ['dryblock' => 'isotech']]);
        $this->assertNotContains(
            'tids_dryblock_kosong',
            array_column((new TidsProfile)->peringatanSesi($sesiBerdryblock), 'kode'),
        );
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
