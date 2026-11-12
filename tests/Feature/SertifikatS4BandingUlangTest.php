<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CalibrationValidator;
use App\Services\CertificateSnapshotBuilder;
use App\Services\DataTampilanSertifikat;
use App\Support\GambarKhasSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EnclosureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClassConstant;
use ReflectionMethod;
use Tests\TestCase;

/**
 * S-4 (banding ulang 12 Nov 2026): pressure_gauge, vacuum_gauge,
 * differential_pressure, tids, bath, furnace, refrigerator diadu ke lembar
 * SERTIFIKAT master masing-masing (Druck 07G/13G/SPMK/Differential, TIDS
 * Recorder Graptech + Yokogawa K N, Enclosure Constant Yokogawa + Recorder).
 *
 * Audit 11 Nov menandai Bath/Furnace/Refrigerator "master hilang" dan
 * Differential/13G/SPMK "tidak ada" — master-nya ADA: ketiga enclosure memakai
 * lembar Enclosure lewat `EnclosureProfileBase`. Tes ini membuktikan yang
 * dicetak PROFIL ITU SENDIRI (bukan cuma Oven/Inkubator), termasuk DIAGRAM
 * posisi termokopel (`GambarKhasSertifikat`) untuk ketiga jenis di kedua
 * varian kalibrator.
 *
 * Snapshot LAMA beku: dijaga `SertifikatS4BekuTest`.
 *
 * Satu metode per kelompok (seeder penuh dijalankan sekali per metode — mahal).
 */
class SertifikatS4BandingUlangTest extends TestCase
{
    use RefreshDatabase;

    /** jenis => [kode profil, rentang bawah, rentang atas (= kapasitas yang diketik)]. */
    private const JENIS = [
        'Bath' => ['bath', 0, 100],
        'Furnace' => ['furnace', 0, 1000],
        'Refrigerator' => ['refrigerator', 0, 100],
    ];

    /** varian => [grid seeder, nama standar kalibrator, tipe sensor, tanggal kalibrasi, N hari Issuance (master)]. */
    private const VARIAN = [
        'yokogawa' => ['GRID_YOKO', 'Temperature Calibrator Yokogawa CA 150 Handy Cal', 'Type N', '2024-05-02', 1, 'enclosure_constant_yokogawa'],
        'recorder' => ['GRID_RECORDER', 'Temperature Recorder Graphtech GL840', 'Type K', '2024-06-27', 4, 'enclosure_recorder'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
    }

    // ---- Bath / Furnace / Refrigerator ---------------------------------------

    public function test_bath_furnace_refrigerator_mencetak_diagram_kapasitas_tanggal_dan_judul_persis_master_enclosure(): void
    {
        $halamanDiperiksa = 0;

        foreach (self::JENIS as $nama => [$kode, $min, $maks]) {
            foreach (array_keys(self::VARIAN) as $varian) {
                $sesi = $this->sesiEnclosure($nama, $kode, $varian, $min, $maks);
                $bahan = $this->periksaCetakanEnclosure($sesi, $nama, $varian, $maks);

                // (8) Satu halaman (gerbang tidak dilonggarkan) — satu varian per jenis cukup.
                if ($varian === ($nama === 'Furnace' ? 'recorder' : 'yokogawa')) {
                    $this->assertSame(1, $this->halamanPdf($bahan), "{$nama}/{$varian}: harus satu halaman");
                    $halamanDiperiksa++;
                }
            }
        }

        $this->assertSame(3, $halamanDiperiksa);
    }

    /**
     * Oven dan Inkubator (sesi bawaan `EnclosureSeeder`, kelas dasar yang sama): bentuk yang
     * sama persis dengan Bath/Furnace/Refrigerator di atas. Oven = master Recorder (Issuance
     * +4 hari, Capacity `300 °C`), Inkubator = master Constant Yokogawa (+1 hari, `100 °C`).
     * Pemilik 12 Nov 2026: kelima jenis satu paket dalam master Enclosure.
     */
    public function test_oven_dan_inkubator_mencetak_diagram_kapasitas_tanggal_dan_judul_persis_master_enclosure(): void
    {
        /** nomor sesi bawaan seeder => [nama jenis, varian, kapasitas yang diketik]. */
        $bawaan = [
            '2406.25.AI' => ['Oven', 'recorder', 300],
            '2405.03.AV' => ['Inkubator', 'yokogawa', 100],
        ];

        foreach ($bawaan as $nomor => [$nama, $varian, $maks]) {
            $sesi = CalibrationSession::query()->where('nomor_sesi', $nomor)->firstOrFail();
            $this->assertSame(strtolower($nama), app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment)->kode(), $nomor);

            $bahan = $this->periksaCetakanEnclosure($sesi, $nama, $varian, $maks);

            $this->assertSame(1, $this->halamanPdf($bahan), "{$nama}/{$varian}: harus satu halaman");
        }
    }

    // ---- TIDS -----------------------------------------------------------------

    public function test_tids_mencetak_kapasitas_diketik_tanggal_menurut_varian_dan_catatan_kolom_bergeser(): void
    {
        $sesi = CalibrationSession::query()->where('nomor_sesi', 'DEMO-TIDS-001')->firstOrFail();
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);
        $this->assertSame('tids', $profil->kode());

        // Kapasitas belum diketik -> rentang atas alat terdaftar (600), bukan "-20–600".
        $sertifikat = $this->snapshotBaru($sesi);
        $this->assertSame('600 °C / 0.01 °C', $sertifikat->snapshot['header']['capacity_graduation']);

        // Diketik 250 (`193!F14` = `INPUT DATA!E15`, contoh master Recorder `250 °C / 0.01 °C`).
        $spesifikasi = (array) $sesi->spesifikasi_alat;
        $spesifikasi['kapasitas'] = 250;
        $sesi->update(['spesifikasi_alat' => $spesifikasi]);
        $baru = $this->snapshotBaru($sesi->fresh());
        $snapshot = $baru->snapshot;
        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($baru))->render();

        $this->assertSame(['graduasi' => 'resolusi'], $snapshot['tata_letak']['kapasitas_master']);
        $this->assertSame('250 °C / 0.01 °C', $snapshot['header']['capacity_graduation']);
        $this->assertStringContainsString('250 °C / 0.01 °C', $html);
        $this->assertStringNotContainsString('-20–600', $html);

        // Workbook Recorder (Graptech): Issuance Date `E67` = P16+4. Kalibrasi 25 Mar 2026 -> 29 Mar 2026.
        $this->assertSame(4, $snapshot['tata_letak']['tanggal_master']['tambah_hari']);
        $this->assertSame('2026-03-29', $snapshot['footer']['issuance_date']);

        // Workbook Yokogawa K N: P16+2 (kalibrator Yokogawa 23P1005 dipasang di sesi, tidak disimpan).
        $yokogawa = Standard::query()->where('serial_number', '23P1005')->firstOrFail();
        $sesiYoko = $sesi->fresh();
        $sesiYoko->setRelation('standard', $yokogawa);
        $this->assertSame(2, $profil->tataLetakSertifikat($sesiYoko)['tanggal_master']['tambah_hari']);

        // Keluarga standar tak dikenali -> tanggal terbit sistem (tidak ditebak).
        $asing = (new Standard)->forceFill(['nama' => 'Kalibrator Lain', 'merk' => 'XYZ', 'serial_number' => 'ZZ-1']);
        $sesiAsing = $sesi->fresh();
        $sesiAsing->setRelation('standard', $asing);
        $this->assertNull($profil->tataLetakSertifikat($sesiAsing)['tanggal_master']['tambah_hari']);

        // Kerusakan salin-tempel master Recorder (`I41`/`L41` bergeser) TERCATAT di jejak sesi, bukan dibetulkan diam-diam.
        $kode = collect($sesi->fresh()->uncertaintyCalculations->first()->type_b_components)->firstWhere('sumber', 'tids_recorder_standard_used_bergeser');
        $this->assertNotNull($kode, 'catatan penyimpangan master wajib ada di type_b_components');
        $this->assertStringContainsString('I41', $kode['keterangan']);
        $this->assertStringContainsString('L41', $kode['keterangan']);
        $this->assertStringContainsString('C305B1470', $kode['keterangan']);

        // Satu halaman.
        $this->assertSame(1, $this->halamanPdf(app(DataTampilanSertifikat::class)->untuk($baru)));
    }

    // ---- Pressure Gauge / Vacuum Gauge / Differential Pressure ------------------

    public function test_tiga_profil_tekanan_mencetak_u95_di_dalam_tabel_dan_kalimat_k_persis_master(): void
    {
        $halaman = 0;

        foreach (['DEMO-PG-13G-001' => 'pressure_gauge', 'DEMO-VG-07G-001' => 'vacuum_gauge', 'DEMO-DP-ADT-001' => 'differential_pressure'] as $nomor => $kode) {
            $sesi = CalibrationSession::query()->where('nomor_sesi', $nomor)->firstOrFail();
            $sertifikat = $this->snapshotBaru($sesi);
            $snapshot = $sertifikat->snapshot;
            $bahan = app(DataTampilanSertifikat::class)->untuk($sertifikat);
            $html = view('sertifikat.pdf', $bahan)->render();

            $this->assertSame($kode, app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment)->kode());
            $this->assertTrue($snapshot['tata_letak']['u95_dalam_tabel'], $kode);
            $this->assertSame('The Uncertainty is taken at a Confidence Level 95 % and Coverage Factor( k )', $snapshot['tata_letak']['kalimat_k'], $kode);

            // Baris U95 = baris TERAKHIR kotak tabel hasil (`E35:X35`), tepat satu kali.
            $this->assertSame(1, substr_count($html, 'class="u95-dalam-tabel"'), $kode);
            preg_match_all('#<table class="data">.*?</table>#s', $html, $tabel);
            $berisiU95 = array_values(array_filter($tabel[0], static fn (string $t): bool => str_contains($t, 'class="u95-dalam-tabel"')));
            $this->assertCount(1, $berisiU95, "{$kode}: U95 harus di dalam <table>");
            $this->assertStringContainsString('Uncertainty U95% = &plusmn;', $berisiU95[0], $kode);
            $this->assertStringContainsString('Hysterisis', $berisiU95[0], "{$kode}: tabel yang sama dengan kolom hasil");
            $this->assertStringNotContainsString('<div class="ket-k">Uncertainty U95%', $html, "{$kode}: tidak lagi baris teks di bawah tabel");

            // Kalimat k `E36`: huruf kapital, `Factor( k )` tanpa spasi; "= 2" ikut dicetak.
            $this->assertStringContainsString('The Uncertainty is taken at a Confidence Level 95 % and Coverage Factor( k ) =', $html, $kode);
            $this->assertStringNotContainsString('The uncertainty is taken at a confidence level', $html, $kode);

            // Mutan: kunci dihapus -> bentuk lama (U95 di bawah tabel, kalimat huruf kecil).
            $lama = $bahan['snapshot'];
            unset($lama['tata_letak']['u95_dalam_tabel'], $lama['tata_letak']['kalimat_k']);
            $htmlLama = view('sertifikat.pdf', [...$bahan, 'snapshot' => $lama])->render();
            $this->assertSame(0, substr_count($htmlLama, 'u95-dalam-tabel'), "{$kode}: mutan");
            $this->assertStringContainsString('<div class="ket-k">Uncertainty U95%', $htmlLama, "{$kode}: mutan");
            $this->assertStringContainsString('The uncertainty is taken at a confidence level 95 % and coverage factor ( k ) =', $htmlLama, "{$kode}: mutan");

            // Angka U95 yang sama tercetak di kedua bentuk (hanya tempatnya yang pindah).
            $this->assertSame(
                $this->angkaU95($htmlLama),
                $this->angkaU95($html),
                "{$kode}: angka U95 tidak boleh berubah",
            );

            if ($kode === 'pressure_gauge') {
                $this->assertSame(1, $this->halamanPdf($bahan), 'pressure_gauge: harus satu halaman');
                $halaman++;
            }
        }

        $this->assertSame(1, $halaman);
    }

    // ---- pembantu ----------------------------------------------------------------

    /**
     * Semua yang dicetak lembar `SERTIFIKAT` master Enclosure untuk satu sesi (Bath, Furnace,
     * Refrigerator, Oven, Inkubator): diagram, Capacity/Graduation, Issuance Date, huruf, baris
     * karangan yang dihapus, judul blok, Standard used, dan mutan snapshot lama.
     * Tanggal kalibrasi, N hari, dan kunci diagram diambil dari [VARIAN] (sesi di-seed dari
     * tanggal yang sama).
     *
     * @return array<string, mixed> bahan tampilan, untuk pemeriksaan halaman PDF oleh pemanggil
     */
    private function periksaCetakanEnclosure(CalibrationSession $sesi, string $nama, string $varian, int|float $maks): array
    {
        [, , , $tanggal, $tambahHari, $kunciGambar] = self::VARIAN[$varian];
        $sertifikat = $this->snapshotBaru($sesi);
        $snapshot = $sertifikat->snapshot;
        $bahan = app(DataTampilanSertifikat::class)->untuk($sertifikat);
        $html = view('sertifikat.pdf', $bahan)->render();
        $label = "{$nama}/{$varian}";
        $tl = $snapshot['tata_letak'];

        // (1) DIAGRAM posisi termokopel kubus 1-9 tercetak TEPAT SEKALI, geometri sesuai varian master.
        $berkas = GambarKhasSertifikat::PER_VARIAN[$kunciGambar][0];
        $this->assertSame('posisi-termokopel-enclosure', $tl['gambar_khas'][0]['berkas'], $label);

        foreach (['kiri', 'lebar', 'tinggi', 'kiri_teks', 'turun_teks_pt', 'turun_gambar_pt', 'teks'] as $k) {
            $this->assertEquals($berkas[$k], $tl['gambar_khas'][0][$k], "{$label}: gambar {$k}");
        }

        $this->assertSame(1, substr_count($html, 'data-gambar-khas="posisi-termokopel-enclosure"'), "{$label}: diagram");
        $this->assertSame(1, substr_count($html, base64_encode((string) file_get_contents(GambarKhasSertifikat::jalur('posisi-termokopel-enclosure')))), "{$label}: bytes PNG");
        $this->assertSame(1, substr_count($html, 'Thermocouple Position :'), "{$label}: label");

        // (2) Capacity/Graduation = satu angka DIKETIK + resolusi (`171!F14`/`I14`), bukan rentang 0–X.
        $this->assertSame(['graduasi' => 'resolusi'], $tl['kapasitas_master'], $label);
        $this->assertSame("{$maks} °C / 0.1 °C", $snapshot['header']['capacity_graduation'], "{$label}: Capacity/Graduation");
        $this->assertStringNotContainsString('0–'.$maks, $html, "{$label}: bukan rentang");

        // (3) Issuance Date = tanggal kalibrasi + N varian master (`E168` = P16+1, `E100` = P16+4).
        $this->assertSame($tambahHari, $tl['tanggal_master']['tambah_hari'], $label);
        $this->assertSame(
            date('Y-m-d', strtotime($tanggal.' +'.$tambahHari.' days')),
            $snapshot['footer']['issuance_date'],
            "{$label}: Issuance Date",
        );

        // (3b) Huruf mengikuti median lembar master Recorder (7,44 pt, seperti Oven); Constant
        // Yokogawa tetap tangga lama (null = tidak dinyatakan).
        $this->assertEquals($varian === 'recorder' ? 7.44 : null, $tl['font_master_pt'] ?? null, "{$label}: font_master_pt");

        // (4) Baris `Standar: …, Sensor: …, Enclosure: …` TIDAK ada di master.
        $this->assertNull($snapshot['catatan_atas_hasil'], $label);
        $this->assertStringNotContainsString('Standar: ', $html, $label);
        $this->assertStringNotContainsString('Enclosure: '.$nama, $html, $label);

        // (5) Judul blok bercampur huruf besar-kecil seperti sel master `B21`/`B39`.
        $this->assertTrue($tl['judul_kelompok_master'], $label);
        $this->assertStringContainsString('A) Sebaran Suhu', $html, $label);
        $this->assertStringContainsString('B) Kinerja Enklosur', $html, $label);
        $this->assertStringNotContainsString('A) SEBARAN SUHU', $html, $label);

        // (6) Standard used: kalibrator + sensor acuan (dua baris, `B53:Q54`).
        $this->assertCount(2, $snapshot['standar_digunakan'], "{$label}: Standard used");

        // (7) Mutan: kunci dihapus dari snapshot -> bentuk lama.
        $lama = $bahan['snapshot'];
        unset($lama['tata_letak']['judul_kelompok_master'], $lama['tata_letak']['gambar_khas']);
        $htmlLama = view('sertifikat.pdf', [...$bahan, 'snapshot' => $lama])->render();
        $this->assertStringContainsString('A) SEBARAN SUHU', $htmlLama, "{$label}: mutan judul");
        $this->assertSame(0, substr_count($htmlLama, 'data-gambar-khas="'), "{$label}: mutan diagram");

        return $bahan;
    }

    private function angkaU95(string $html): string
    {
        $this->assertSame(1, preg_match('#Uncertainty U95% = (?:&plusmn;|±)(?:</b>)?\s*([-0-9.,]+)#u', $html, $cocok), 'baris U95 tidak ditemukan');

        return $cocok[1];
    }

    private function sesiEnclosure(string $nama, string $kode, string $varian, int|float $min, int|float $maks): CalibrationSession
    {
        [$konstanta, $namaStandar, $sensor, $tanggal] = self::VARIAN[$varian];
        $seeder = new EnclosureSeeder;
        $grid = (new ReflectionClassConstant(EnclosureSeeder::class, $konstanta))->getValue();
        $standar = Standard::where('nama', $namaStandar)->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->orderBy('id')->firstOrFail();
        $urut = $varian === 'yokogawa' ? 1 : 2;
        $nomor = "S4.{$kode}.{$urut}";

        (new ReflectionMethod($seeder, 'seedSesi'))->invoke(
            $seeder,
            ['nama' => "PT Sintetis S4 {$kode}", 'alamat' => 'Alamat Sintetis S4'],
            ['nama_alat' => $nama, 'nama_alat_kemampuan' => $nama, 'merk' => 'MerkSintetis', 'model' => 'Model-'.$urut, 'serial_number' => 'S4-'.strtoupper($kode).'-'.$varian, 'range_min' => $min, 'range_max' => $maks, 'kapasitas' => (string) $maks],
            ['nomor_sesi' => $nomor, 'nomor_order' => $nomor, 'tanggal' => $tanggal, 'tipe_sensor' => $sensor],
            $grid,
            $standar,
            $teknisi,
        );

        return CalibrationSession::query()->where('nomor_sesi', $nomor)->firstOrFail();
    }

    /** Snapshot BARU lewat `CertificateSnapshotBuilder` (jalur yang sama dengan penerbitan), tanpa PDF. */
    private function snapshotBaru(CalibrationSession $sesi): Certificate
    {
        $sertifikat = Certificate::query()->where('calibration_session_id', $sesi->id)->first() ?? new Certificate;

        if (! $sertifikat->exists) {
            $sertifikat->forceFill([
                'organization_id' => $sesi->organization_id,
                'calibration_session_id' => $sesi->id,
                'nomor' => 'S4-UJI/'.$sesi->id.'/'.bin2hex(random_bytes(3)),
                'qr_token' => 's4-uji-'.$sesi->id.'-'.bin2hex(random_bytes(3)),
                'qr_payload' => 'https://contoh.invalid/verify/s4-uji-'.$sesi->id,
                'diterbitkan_pada' => now(),
                'berlaku_sampai' => now()->addYear(),
                'status' => Certificate::STATUS_MENUNGGU_GENERATE,
            ])->save();
        }

        $lengkap = CalibrationSession::with([
            'equipment.customer', 'organization', 'teknisi', 'reviewer', 'standard', 'thermohygro',
            'standarDicek', 'calibrationMethod', 'room', 'rawMeasurements', 'uncertaintyCalculations.standard',
        ])->findOrFail($sesi->id);

        $sertifikat->update([
            'validasi' => app(CalibrationValidator::class)->periksa($lengkap),
            'snapshot' => app(CertificateSnapshotBuilder::class)->bangun($lengkap, $sertifikat),
        ]);

        return $sertifikat->fresh();
    }

    /** @param array<string, mixed> $bahan */
    private function halamanPdf(array $bahan): int
    {
        // Urutan produksi (`SertifikatSatuHalaman`): normal dulu, padat bila meluap.
        foreach ([false, true] as $paksaPadat) {
            $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => $paksaPadat]);
            $pdf->output();
            $halaman = (int) $pdf->getDomPDF()->getCanvas()->get_page_count();

            if ($halaman <= 1) {
                return $halaman;
            }
        }

        return $halaman;
    }
}
