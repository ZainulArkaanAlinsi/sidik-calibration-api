<?php

namespace Tests\Feature;

use App\Models\CalibrationMethod;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Standard;
use App\Models\User;
use App\Services\CertificateSnapshotBuilder;
use App\Services\DataTampilanSertifikat;
use App\Support\Angka;
use App\Support\TataLetakSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * K-50-15 (pemilik, 18 Okt 2026): sertifikat persis lembar `SERTIFIKAT`
 * master alatnya. Bagian BERSAMA-nya (bawaan `CalibrationProfile::
 * tataLetakSertifikat()` = bentuk mayoritas 52 lembar SERTIFIKAT master aktif
 * di Studio) dijaga di sini lewat render lembar sungguhan:
 *
 *  - G1 kepala dua kelompok urutan master (kiri Certificate Number … Technician
 *    ID, kanan Owner … Env. Condition);
 *  - G3 label `Calibration Report :`, `Standard used :`, `Merk/Type :`,
 *    `Issuance Date :`;
 *  - G5 penafian berstrip;
 *  - G9 kondisi lingkungan bertumpuk (T, %RH sebaris-sebaris);
 *  - G10 baris satuan di bawah judul kolom;
 *  - G11 judul kolom pertama `Standard Value`.
 *
 * Mekanisme hook per profil (G6 End of Certificate, G8 format sel, G12 blok
 * catatan, `k` seperti format selnya, baris tersembunyi) diuji dengan
 * menyuntikkan kunci snapshot yang sama yang akan ditulis profil.
 *
 * Satu lagi yang dijaga: snapshot LAMA (tanpa `tata_letak`) tetap tercetak
 * bentuk lamanya — cetak ulang tidak boleh mengubah dokumen yang sudah
 * dipegang pelanggan.
 */
class SertifikatBentukMasterTest extends TestCase
{
    use RefreshDatabase;

    private function terbitkan(): Certificate
    {
        Storage::fake('local');

        Organization::factory()->create(['nama' => 'PT Sidik Kalibrasi']);
        $admin = User::factory()->admin()->create();
        $teknisi = User::factory()->create(['name' => 'Dedi Rahmat', 'kode_teknisi' => 'DR']);
        $ruangan = Room::factory()->create(['nama' => 'Lab. Uji A']);
        $pelanggan = Customer::factory()->create(['nama' => 'PT CONTOH SINTETIS', 'alamat' => 'Jl. Contoh No. 1']);

        $alat = Equipment::factory()->create([
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'nama_alat' => 'pH Meter',
            'merk' => 'Merk Contoh', 'model' => 'Model Contoh', 'serial_number' => 'SN-UJI-01',
            'range_min' => 0, 'range_max' => 14,
            'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.2,
        ]);

        $standar = Standard::factory()->create([
            'nama' => 'pH Buffer Solution 4', 'merk' => 'Supelco', 'model' => 'Merck',
            'serial_number' => 'HC-UJI', 'tertelusur_ke' => 'Merck KGaA', 'satuan_ketidakpastian' => 'pH',
        ]);

        $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
            'room_id' => $ruangan->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'tanggal_terima' => now()->subDays(3)->toDateString(),
            'suhu_ruang' => 21.0,
            'kelembaban' => 51.95,
            'measurements' => [
                ['titik_ukur' => 4.01, 'satuan' => 'pH', 'pembacaan' => [4.00, 4.00, 4.01]],
                ['titik_ukur' => 6.99, 'satuan' => 'pH', 'pembacaan' => [7.00, 7.00, 7.01]],
            ],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $metode = CalibrationMethod::factory()->create(['kode' => 'SIDIK-IK-CAL-0506', 'revisi' => '6']);

        $this->actingAs($admin)->patchJson("/api/calibrations/{$sesi->id}/admin", [
            'nomor_order' => '2405.13.A',
            'calibration_method_id' => $metode->id,
            'suhu_ketidakpastian' => 1.7,
            'kelembaban_ketidakpastian' => 5.7,
        ])->assertOk();

        $this->denganOrderNumber($sesi->id)->actingAs($admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji()])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    /** @param  array<string, mixed>|null  $snapshot */
    private function html(Certificate $sertifikat, ?array $snapshot = null): string
    {
        $bahan = app(DataTampilanSertifikat::class)->untuk($sertifikat);

        if ($snapshot !== null) {
            $bahan['snapshot'] = $snapshot;
        }

        return view('sertifikat.pdf', $bahan)->render();
    }

    /** Teks polos sel tabel, spasi dirapatkan — supaya urutan bisa diadu. */
    private function teks(string $html): string
    {
        $tanpaGaya = preg_replace('#<style>.*?</style>#su', '', $html);

        return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($tanpaGaya)));
    }

    /** @param  list<string>  $urutan */
    private function assertUrutan(array $urutan, string $teks): void
    {
        $posisi = -1;

        foreach ($urutan as $potongan) {
            $ketemu = mb_strpos($teks, $potongan, $posisi + 1);
            $this->assertNotFalse($ketemu, "`{$potongan}` tidak ditemukan sesudah posisi {$posisi}.");
            $posisi = $ketemu;
        }
    }

    public function test_sertifikat_baru_memakai_bentuk_mayoritas_master(): void
    {
        $sertifikat = $this->terbitkan();
        $snapshot = $sertifikat->snapshot;

        // Yang dibekukan TIMPAAN, dan `catatan` pindah ke `snapshot.catatan`.
        $this->assertSame(true, $snapshot['tata_letak']['env_bertumpuk']);
        $this->assertArrayNotHasKey('catatan', $snapshot['tata_letak']);
        $this->assertSame(TataLetakSertifikat::MAYORITAS_MASTER['catatan'], $snapshot['catatan']);
        $this->assertSame('Standard Value', $snapshot['judul_standar']);

        // G9: T & %RH sebaris-sebaris, format pH master (T `0.0`, %RH General).
        // P3 (pemilik 20 Okt 2026): titik desimal seperti tampilan sel master.
        $this->assertSame("T 21.0 °C ± 1.7 °C\n%RH 51.95 % ± 5.7 %", $snapshot['header']['env_condition']);

        $html = $this->html($sertifikat);
        $teks = $this->teks($html);

        // G10: satuan di baris sendiri di bawah judul kolom, bukan di judulnya.
        $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th>pH</th>\s*<th>pH</th>\s*<th>pH</th>#u', $html);

        // G1: kelompok kiri lalu kanan dibaca per baris grid — tiap baris
        // [kiri, kanan], jadi yang diadu urutan di dalam kolomnya masing-masing.
        $kepala = $snapshot['tata_letak']['kepala'];
        $this->assertSame('Certificate Number', $kepala[0][0]['label']);
        $this->assertSame('Owner', $kepala[0][1]['label']);
        $this->assertSame('Technician ID', $kepala[9][0]['label']);
        $this->assertNull($kepala[9][1]);
        $this->assertUrutan(['Certificate Number', 'Owner', 'Page', 'Address', 'Order Number',
            'Calibration Location', 'Received Date', 'Calibration Date', 'Equipment Name',
            'Calibration Method', 'Manufacturer', 'Env. Condition', 'Model/Type', 'Serial Number',
            'Capacity/Graduation', 'Technician ID'], $teks);

        // G3, G5, G10, G11.
        $this->assertUrutan(['Calibration Report :', 'Standard Value', 'Unit Under Test', 'Correction'], $teks);
        $this->assertStringNotContainsString('Standard Value (pH)', $teks);
        $this->assertStringContainsString('- Calibration results are not to be announced and only apply to related tools -', $teks);
        $this->assertUrutan(['Standard used :', 'Name', 'Merk/Type :', 'Serial Number :', 'Traceable to SI through :'], $teks);
        $this->assertStringContainsString('Issuance Date :', $teks);
        $this->assertStringNotContainsString('CALIBRATION REPORT', $teks);
        $this->assertStringNotContainsString('STANDARD USED', $teks);

        // G4: "Convidence" master TIDAK ditiru — penyimpangan sadar.
        $this->assertStringNotContainsString('Convidence', $teks);

        // Keputusan pemilik 20 Okt 2026 ("ikut master semua"):
        // P1 — kop & judul besar TETAP (kop membawa nomor akreditasi KAN;
        // mencabutnya menunggu konfirmasi eksplisit pemilik). Jalur kop teks
        // wajib menyebut nomor akreditasinya di bentuk master juga.
        $this->assertStringContainsString('CALIBRATION CERTIFICATE', $teks);
        $kan = $snapshot;
        $kan['meta']['organization']['no_akreditasi'] = 'LK-285-IDN';
        $htmlKan = view('sertifikat.pdf', [...app(DataTampilanSertifikat::class)->untuk($sertifikat), 'kop' => null, 'snapshot' => $kan])->render();
        $this->assertStringContainsString('class="kop"', $htmlKan);
        $this->assertStringContainsString('Terakreditasi', $htmlKan);
        $this->assertStringContainsString('LK-285-IDN', $htmlKan);
        // P3 — titik desimal di tabel hasil (4.01, bukan 4,01).
        $this->assertStringContainsString('4.01', $teks);
        $this->assertStringNotContainsString('4,01', $teks);
    }

    public function test_snapshot_lama_tanpa_tata_letak_tetap_tercetak_bentuk_lama(): void
    {
        $sertifikat = $this->terbitkan();
        $lama = $sertifikat->snapshot;
        unset($lama['tata_letak']);
        $lama['catatan'] = CertificateSnapshotBuilder::CATATAN_HASIL;
        $lama['judul_standar'] = 'Standard';

        $teks = $this->teks($this->html($sertifikat, $lama));

        $this->assertUrutan(['Certificate Number', 'Page', 'Owner', 'Order Number', 'Address',
            'Received Date', 'Equipment Name', 'Manufacturer'], $teks);
        $this->assertStringContainsString('CALIBRATION REPORT', $teks);
        $this->assertStringContainsString('CALIBRATION REPORT Standard Unit Under Test Correction', $teks);
        $this->assertStringNotContainsString('class="satuan"', $this->html($sertifikat, $lama));
        $this->assertStringContainsString('STANDARD USED', $teks);
        $this->assertStringContainsString('Issuance Date:', $teks);
        $this->assertStringNotContainsString('End of Certificate', $teks);
        // Kop, judul besar, dan koma desimal bentuk lama tetap.
        $this->assertStringContainsString('CALIBRATION CERTIFICATE', $teks);
        $this->assertStringContainsString('4,01', $teks);
    }

    /**
     * Mekanisme yang dipakai hook per profil: blok catatan, End of
     * Certificate, format sel per kolom, `k` seperti format selnya, dan baris
     * yang disembunyikan master. Kuncinya disuntik langsung ke snapshot —
     * bentuk yang sama dengan yang dibekukan builder dari profil.
     */
    /**
     * Snapshot TERBIT tanpa `tata_letak` dikelompokkan seperti waktu terbit:
     * per `remark` saja. Pemisahan tabel per satuan (master `157!C33:T42`)
     * hanya milik bentuk master — cetak ulang sertifikat Conductivity atau
     * (Thermohygro) yang sudah di tangan pelanggan tidak boleh berubah jadi dua
     * tabel.
     */
    public function test_snapshot_lama_satuan_campuran_tetap_satu_tabel(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        // Dua sesi contoh Conductivity bersatuan campuran (µS/cm + mS/cm). Sesi
        // contoh Thermohygro meratakan titiknya ke satu satuan, jadi tidak
        // bisa dipakai di sini; jalurnya sama (`kunciKelompok`).
        foreach (['DEMO-COND-MSCM', '2405.32.A.NK'] as $nomor) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
            $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
                ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
                ->assertOk();
            $sertifikat = $sesi->fresh()->certificate()->firstOrFail();

            $lama = $sertifikat->snapshot;
            $satuan = array_unique(array_filter(array_column($lama['hasil'], 'satuan')));
            $this->assertGreaterThan(1, count($satuan), "{$nomor}: sesi contoh harus bersatuan campuran.");
            unset($lama['tata_letak']);

            $html = $this->html($sertifikat, $lama);
            $laporan = substr($html, (int) strpos($html, 'CALIBRATION REPORT'), (int) strpos($html, 'STANDARD USED') - (int) strpos($html, 'CALIBRATION REPORT'));
            $this->assertSame(1, substr_count($laporan, '<table class="data">'), "{$nomor}: snapshot lama terpecah per satuan.");

            // Bentuk master (snapshot baru) memang memisah per satuan.
            $baru = $this->html($sertifikat);
            $laporanBaru = substr($baru, (int) strpos($baru, 'Calibration Report :'), (int) strpos($baru, 'Standard used :') - (int) strpos($baru, 'Calibration Report :'));
            $this->assertSame(count($satuan), substr_count($laporanBaru, '<table class="data">'), "{$nomor}: bentuk master tidak memisah per satuan.");
        }
    }

    /**
     * Baris satuan bentuk master memakai satuan ALAT, bukan satuan pembacaan
     * mentah. Piston, Dispensett, dan Buret Digital menimbang dalam `g`, tapi
     * master mencetak `ml` (`160!E20`, `164!E20:S20`). Temuan penjaga-angka
     * 20 Okt 2026.
     */
    public function test_satuan_cetak_piston_ikut_satuan_alat_bukan_gram(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        foreach (['DEMO-PP-FIX-001', 'DEMO-DS-FIX-001', 'DEMO-BD-GRD-001'] as $nomor) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
            $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
                ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
                ->assertOk();
            $sertifikat = $sesi->fresh()->certificate()->firstOrFail();

            $this->assertContains($sertifikat->snapshot['satuan_cetak'], ['ml', 'µl'], "{$nomor}: satuan cetak bukan satuan volume.");

            $html = $this->html($sertifikat);
            $this->assertStringNotContainsString('<th>g</th>', $html, "{$nomor}: baris satuan mencetak gram.");
            $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th>(ml|µl)</th>#u', $html, "{$nomor}: baris satuan tidak ber-ml.");
        }
    }

    /**
     * Tabel Tekanan bentuk master (`181!E19:V36`): kepala `UUT`, ejaan
     * `Hysterisis`, baris satuan, U95 sebaris sendiri lalu kalimat `k`, dan
     * format kolom setelan dari `tekanan.format_setelan`. Snapshot lama tetap
     * `UUT Setting` / `Hysteresis` / satu kalimat.
     */
    public function test_tabel_tekanan_bentuk_master(): void
    {
        $sertifikat = $this->terbitkan();
        $snapshot = $sertifikat->snapshot;
        $snapshot['tekanan'] = [
            'satuan' => 'Psi', 'desimal' => 1, 'desimal_u95' => 2, 'u95' => 0.3, 'k_cetak' => 2.0,
            'format_setelan' => '0',
            'baris' => [[
                'setelan' => 100.0, 'standar_up' => 99.77, 'standar_down' => 99.81,
                'koreksi_up' => -0.23, 'koreksi_down' => -0.19, 'histeresis' => [0.04, 0.05, 0.03],
            ]],
        ];

        $html = $this->html($sertifikat, $snapshot);
        $this->assertStringContainsString('<th>UUT</th>', $html);
        $this->assertStringContainsString('Hysterisis', $html);
        $this->assertStringNotContainsString('UUT Setting', $html);
        $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th>Psi</th>\s*<th colspan="2">Psi</th>#u', $html);
        $this->assertMatchesRegularExpression('#<td>100</td>\s*<td>99\.8</td>#u', $html);
        $this->assertMatchesRegularExpression('#<div class="ket-k">Uncertainty U95% = &plusmn; 0\.30 Psi</div>#u', $html);

        unset($snapshot['tata_letak']);
        $lama = $this->html($sertifikat, $snapshot);
        $this->assertStringContainsString('UUT Setting (Psi)', $lama);
        $this->assertStringContainsString('Hysteresis (Psi)', $lama);
        $this->assertMatchesRegularExpression('#<td>100,0</td>#u', $lama);
        $this->assertStringContainsString('Uncertainty U95% = &plusmn; 0,30 Psi.', $lama);
    }

    /** Kolom berpecah Timer tergambar: judul selebar pecahan, judul pecahan di baris satuan. */
    public function test_kolom_pecah_tergambar(): void
    {
        $sertifikat = $this->terbitkan();
        $snapshot = $sertifikat->snapshot;
        $pecah = [
            ['judul' => 'H', 'faktor' => 3600, 'format' => '0.00'],
            ['judul' => 'M', 'faktor' => 60, 'format' => '0.00'],
            ['judul' => 'S', 'faktor' => 1, 'format' => '0.00'],
            ['judul' => 'S(0,001)', 'faktor' => 0.001, 'format' => '0'],
        ];
        $snapshot['tata_letak'] = [...$snapshot['tata_letak'], ...TataLetakSertifikat::normalisasi(['kolom_hasil' => [
            ['kolom' => 'standar', 'pecah' => $pecah], ['kolom' => 'uut', 'pecah' => $pecah], ['kolom' => 'koreksi', 'pecah' => $pecah], 'u95',
        ]])];
        $snapshot['hasil'] = [[...$snapshot['hasil'][0], 'standard_value' => 60.0963333333, 'unit_under_test' => 60.137, 'correction' => -0.0406666667, 'satuan' => 's']];

        $html = $this->html($sertifikat, $snapshot);
        $this->assertStringContainsString('<th colspan="4">Standard Value</th>', $html);
        $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th>H</th>\s*<th>M</th>\s*<th>S</th>\s*<th>S\(0,001\)</th>#u', $html);
        $this->assertMatchesRegularExpression('#<td>0\.00</td>\s*<td>1\.00</td>\s*<td>0\.00</td>\s*<td>96</td>\s*<td>0\.00</td>\s*<td>1\.00</td>\s*<td>0\.00</td>\s*<td>137</td>\s*<td>0\.00</td>\s*<td>0\.00</td>\s*<td>0\.00</td>\s*<td>-41</td>#u', $html);
    }

    /**
     * Jalur bersama dengan sesi contoh sungguhan:
     *  - Autoclave bentuk master: `Sensor No. (°C)` bernomor & `C) Tekanan`
     *    (`155!F23:P24`, `B35`);
     *  - Jangka Sorong mode padat bentuk master masih muat blok Note
     *    Kerataan (`153!B64:F65`) dalam satu halaman;
     *  - U95 relatif Flowmeter UFM sama dengan `u95_persen_of_reading`
     *    kalkulator yang tercatat di jejak titik.
     */
    public function test_autoclave_note_jangka_dan_u95_relatif_dengan_sesi_contoh(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
        $terbit = function (string $nomor): Certificate {
            $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
            $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
                ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
                ->assertOk();

            return $sesi->fresh()->certificate()->firstOrFail();
        };

        $auto = $terbit('2406.51.S');
        $html = $this->html($auto);
        $this->assertStringContainsString('C) Tekanan', $html);
        $this->assertStringNotContainsString('C) TEKANAN', $html);
        $this->assertMatchesRegularExpression('#<th colspan="\d+">Sensor No\. \(°C\)</th>#u', $html);
        $lamaAuto = $auto->snapshot;
        unset($lamaAuto['tata_letak']);
        $this->assertStringContainsString('C) TEKANAN', $this->html($auto, $lamaAuto));
        $this->assertStringContainsString('Sensor No. 1 (°C)', $this->html($auto, $lamaAuto));

        $jangka = $terbit('001-CAL-126');
        $bahan = app(DataTampilanSertifikat::class)->untuk($jangka);
        $bahan['snapshot']['catatan_blok'] = TataLetakSertifikat::normalisasiBlok([[
            'posisi' => TataLetakSertifikat::POSISI_BAWAH_HASIL,
            'judul' => 'Note :',
            'tabel' => [['Kerataan Muka Ukur dalam kondisi', ':', 'Baik']],
        ]]);
        $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => true]);
        $pdf->output();
        $this->assertSame(1, (int) $pdf->getDomPDF()->getCanvas()->get_page_count(), 'Jangka Sorong + Note meluap ke halaman dua.');

        $sesiUfm = CalibrationSession::where('nomor_sesi', 'DEMO-FM-TOT-001')->firstOrFail();
        $ufm = $terbit('DEMO-FM-TOT-001');
        $jejak = collect($sesiUfm->uncertaintyCalculations()->orderBy('titik_ke')->first()->type_b_components)
            ->firstWhere('sumber', 'jejak_titik');
        $this->assertNotNull($jejak);
        $this->assertSame(1, preg_match('/([\d.]+) % of reading/', $jejak['keterangan'], $m));
        $baris = $ufm->snapshot['hasil'][0];
        $this->assertEqualsWithDelta((float) $m[1], abs($baris['u95'] / $baris['unit_under_test'] * 100), 0.0006);
        $this->assertSame(
            Angka::keTitik(Angka::hasil((float) $m[1], 3)),
            TataLetakSertifikat::teksSel($baris, 'u95_relatif', $ufm->snapshot, 2, ['format' => '0.000']),
        );
    }

    public function test_mekanisme_hook_profil_tergambar_di_lembar(): void
    {
        $sertifikat = $this->terbitkan();
        $snapshot = $sertifikat->snapshot;

        $snapshot['tata_letak']['akhir_sertifikat'] = '------- End of Certificate -------';
        $snapshot['catatan_blok'] = TataLetakSertifikat::normalisasiBlok([[
            'posisi' => TataLetakSertifikat::POSISI_BAWAH_HASIL,
            'judul' => 'Note :',
            'tabel' => [['Kerataan Muka Ukur dalam Kondisi', ':', 'Baik']],
        ]]);

        $titikPertama = $snapshot['hasil'][0]['standard_value'];
        $snapshot['hasil'][0]['dicetak'] = false;

        foreach ($snapshot['hasil'] as $i => $baris) {
            $snapshot['hasil'][$i]['format'] = ['standar' => '0.000', 'k' => '0'];
            $snapshot['hasil'][$i]['faktor_cakupan_k'] = 1.9714;
        }

        $teks = $this->teks($this->html($sertifikat, $snapshot));

        $this->assertStringContainsString('------- End of Certificate -------', $teks);
        $this->assertUrutan(['Calibration Report :', 'Note :', 'Kerataan Muka Ukur dalam Kondisi', 'Baik', 'Standard used :'], $teks);
        $this->assertStringContainsString('Coverage Factor ( k ) = 2', $teks);
        $this->assertStringNotContainsString('1,97', $teks);
        // Titik kedua tercetak dengan format sel `0.000` (nol di belakang tetap).
        $this->assertStringContainsString(
            Angka::keTitik(Angka::hasil((float) $snapshot['hasil'][1]['standard_value'], 3)),
            $teks,
        );
        // Titik pertama disembunyikan: angkanya tidak tercetak di tabel hasil.
        $this->assertStringNotContainsString(Angka::keTitik(Angka::hasil((float) $titikPertama, 3)), $teks);
    }
}
