<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur API Autoklaf: lembar kerja (bentuknya) + preview olah data (angkanya).
 *
 * `AutoclaveCalculatorTest` ngadu ANGKA murni ke master; yang ini mastiin
 * angka yang sama nyampe lewat HTTP + auth + `config/autoclave.php` (tabel
 * kalibrator server-side), bukan cuma dari objek yang dirakit di test.
 */
class AutoclaveApiTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
    }

    /**
     * Lembar digital diadu BARIS PER BARIS ke kertas
     * `SIDIK-FM-CAL-0539_Rev.4`, bukan cuma dicek "ada tabelnya".
     *
     * Bentuk sebelumnya lolos test lama padahal beda dari kertas: dua baris
     * (`Indikator Pressure`, `Tekanan atm awal`) nggak ada sama sekali, dan
     * suhu-tekanan kepisah dua tabel padahal kertasnya satu. Urutan baris itu
     * yang dipakai teknisi waktu nyalin dari kertas — geser satu baris berarti
     * angka tekanan kecatat di baris suhu.
     */
    public function test_bentuk_lembar_kerja_sama_persis_kayak_kertas_rev4(): void
    {
        $data = $this->actingAs($this->teknisi, 'sanctum')
            ->getJson('/api/calibrations/lembar-kerja?profil=autoclave')
            ->assertOk()
            ->json('data');

        $this->assertSame('Calibration Worksheet - Autoclave', $data['judul']);
        $this->assertSame('SIDIK-FM-CAL-0539_Rev.4', $data['kode_dokumen']);
        $this->assertSame('LK-285-IDN', $data['kode_lembar']);
        $this->assertSame(3, $data['jumlah_disk']);

        $bagian = collect($data['bagian']);

        // --- Panel "General Information" ---
        $this->assertSame(
            ['Receive Date', 'Customer', 'Addresss', 'Calibration Date'],
            collect($bagian->firstWhere('kode', 'informasi_umum')['field'])->pluck('label')->all(),
        );

        // Kolom kanan kertas, urut dari atas.
        $identitas = collect($bagian->firstWhere('kode', 'identitas_alat')['field'])->pluck('label');
        foreach (['Equipment Name', 'Manufacturer', 'Type', 'SN', 'Range Temp.', 'Resolution Temp.', 'Range Pressure', 'Resolution Pressure'] as $label) {
            $this->assertContains($label, $identitas->all(), "Kertas punya baris {$label}.");
        }

        // --- Panel "Data Result": SATU tabel, tujuh baris, urut kertas ---
        $hasil = $bagian->firstWhere('kode', 'hasil_pengukuran');
        $this->assertNotNull($hasil, 'Ada section hasil_pengukuran.');
        $this->assertSame('Calibration Result for Temperature & Pressure', $hasil['judul']);
        $this->assertSame(
            'Pengukuran Berulang UUT Selama Proses Sterilisasi',
            $hasil['matriks']['judul_kolom'],
        );
        $this->assertSame('Time', $hasil['matriks']['baris_waktu']['label']);
        $this->assertSame('jam', $hasil['matriks']['baris_waktu']['tipe']);
        $this->assertSame(
            [
                'Temp. Disk 1',
                'Temp. Disk 2',
                'Temp. Disk 3',
                'Indikator Suhu',
                'Indikator Pressure',
                'Tekanan atm awal',
                'Suhu Ruang',
            ],
            collect($hasil['matriks']['baris'])->pluck('label')->all(),
        );
        $this->assertCount(5, $hasil['matriks']['titik_waktu']);

        // Unduhan Pressure Disk Logger emang nggak ada di kertas — boleh ikut,
        // asal ditandai, biar nggak kebaca sebagai baris formulir.
        $this->assertTrue($hasil['tabel_tekanan']['di_luar_kertas']);
        $this->assertSame('Bar', $hasil['tabel_tekanan']['kolom']['satuan']);

        // --- "Standard Used:" — dua kotak centang, kayak kertas ---
        $standar = $bagian->firstWhere('kode', 'usage_check');
        $this->assertSame(
            ['Temperature Calibrator -Technosoft (Disk 1,2,3)', 'Pressure Disk Logger-Technosoft'],
            collect($standar['baris'])->pluck('label')->all(),
        );
        // Satu kotak centang, tapi ketertelusuran ketiga disk tetap kebawa.
        $this->assertCount(3, $standar['baris'][0]['anggota']);

        // --- Kaki lembar ---
        $penutup = collect($bagian->firstWhere('kode', 'penutup')['field'])->pluck('label');
        foreach (['Catatan', 'Calibrated by — Name', 'Calibrated by — Sign', 'Corrected by — Name', 'Corrected by — Sign'] as $label) {
            $this->assertContains($label, $penutup->all(), "Kertas punya baris {$label}.");
        }

        // Kertasnya SATU halaman — nggak ada bagian yang dilempar ke halaman 2.
        $this->assertSame([1], $bagian->pluck('halaman')->unique()->values()->all());
    }

    public function test_preview_reproduksi_angka_master(): void
    {
        $payload = [
            'set_point' => 121.0,
            'suhu' => [
                'disk' => [
                    [121.27, 121.26, 121.26, 121.26, 121.28],
                    [121.30, 121.26, 121.26, 121.25, 121.25],
                    [121.26, 121.26, 121.28, 121.35, 121.28],
                ],
                'indikator' => [121, 121, 121, 121, 121],
                'suhu_ruang' => [25, 25, 25, 25, 25],
            ],
            'tekanan' => [
                'uut_setting' => 0.112,
                'satuan' => 'MPa',
                'display' => 'Digital',
                'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
            ],
        ];

        $data = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave/preview', $payload)
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(0.4419439029528431, $data['suhu']['u95'], 1e-9);
        $this->assertEqualsWithDelta(1.9713602363081708, $data['suhu']['k'], 1e-9);
        $this->assertEqualsWithDelta(0.045, $data['suhu']['kestabilan'], 1e-9);
        $this->assertEqualsWithDelta(0.464, $data['suhu']['keseragaman'], 1e-9);
        $this->assertEqualsWithDelta(0.0059, $data['tekanan']['u95'], 1e-9);
        $this->assertEqualsWithDelta(0.0111, $data['tekanan']['koreksi'], 1e-9);
    }

    /**
     * Baris kertas "Indikator Pressure" diisi lima kolom; master cuma nyimpen
     * satu "UUT Reading". Selama kelima kolomnya sama, angkanya HARUS sama
     * persis kayak kiriman gaya lama yang ngirim `uut_setting` langsung —
     * kalau nggak, lembar yang bentuknya benar malah bikin sertifikat beda.
     */
    public function test_indikator_pressure_lima_kolom_sama_hasilnya_sama_kayak_uut_setting(): void
    {
        $dasar = [
            'set_point' => 121.0,
            'waktu' => ['02:00:00', '04:00:00', '06:00:00', '08:00:00', '10:00:00'],
            'suhu' => [
                'disk' => [
                    [121.27, 121.26, 121.26, 121.26, 121.28],
                    [121.30, 121.26, 121.26, 121.25, 121.25],
                    [121.26, 121.26, 121.28, 121.35, 121.28],
                ],
                'indikator' => [121, 121, 121, 121, 121],
                'suhu_ruang' => [25, 25, 25, 25, 25],
            ],
        ];

        $lama = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave/preview', [...$dasar, 'tekanan' => [
                'uut_setting' => 0.112,
                'satuan' => 'MPa',
                'display' => 'Digital',
                'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
            ]])
            ->assertOk()
            ->json('data.tekanan');

        $kertas = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave/preview', [...$dasar, 'tekanan' => [
                'indikator_pressure' => [0.112, 0.112, 0.112, 0.112, 0.112],
                'tekanan_atm_awal' => [0.0, 0.0, 0.0, 0.0, 0.0],
                'satuan' => 'MPa',
                'display' => 'Digital',
                'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
            ]])
            ->assertOk()
            ->json('data.tekanan');

        $this->assertSame($lama, $kertas);
        $this->assertEqualsWithDelta(0.0111, $kertas['koreksi'], 1e-9);
    }

    /**
     * Lima bacaan manometer yang beda-beda bukan urusan sistem buat dirata-rata
     * — metode `SIDIK-IK-CAL-0531_Rev.4` nggak nyuruh gitu. Lebih baik mental
     * daripada nerbitin angka karangan yang kelihatan wajar.
     */
    public function test_indikator_pressure_beda_beda_ditolak_bukan_dirata_rata(): void
    {
        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave/preview', [
                'set_point' => 121.0,
                'tekanan' => [
                    'indikator_pressure' => [0.112, 0.113, 0.112, 0.112, 0.112],
                    'satuan' => 'MPa',
                    'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tekanan.indikator_pressure');
    }

    public function test_blok_tekanan_tanpa_bacaan_uut_ditolak(): void
    {
        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave/preview', [
                'set_point' => 121.0,
                'tekanan' => [
                    'satuan' => 'MPa',
                    'pembacaan_standar' => [1.233, 1.231],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tekanan.indikator_pressure');
    }

    /**
     * Sesi TEKANAN-SAJA harus jalan.
     *
     * `AutoclaveCalculationRequest` mengizinkannya (`suhu` cuma `sometimes`),
     * handoff frontend menjanjikannya, dan layar mobile memang cuma mengirim
     * blok yang keisi. Tapi `hitungSuhu` dulu melempar `Data suhu kosong`
     * begitu blok suhunya nggak ada — jadi teknisi yang cuma sempat ngukur
     * tekanan dapat 500, bukan hasil.
     *
     * `hitungTekanan` sejak awal balik `null` kalau blok tekanannya kosong;
     * yang salah cuma sisi suhunya yang nggak simetris.
     */
    public function test_sesi_tekanan_saja_tetap_dihitung(): void
    {
        $data = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave/preview', [
                'set_point' => 121.0,
                'tekanan' => [
                    'indikator_pressure' => [0.112, 0.112, 0.112, 0.112, 0.112],
                    'satuan' => 'MPa',
                    'display' => 'Digital',
                    'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertNull($data['suhu'], 'Blok suhu yang nggak dikirim balik null, bukan bikin gagal.');
        $this->assertEqualsWithDelta(0.0111, $data['tekanan']['koreksi'], 1e-9);
        $this->assertEqualsWithDelta(0.0059, $data['tekanan']['u95'], 1e-9);
    }

    /**
     * Blok suhu yang DIKIRIM tapi kosong tetap ditolak — beda dari blok yang
     * memang nggak dikirim. Teknisi yang ngirim blok suhu bermaksud ngukur
     * suhu; hasil yang hilang tanpa keterangan lebih buruk daripada penolakan.
     *
     * **422, bukan 500.** Test ini dulu mematok 500, dan angka itu bukan
     * keputusan — itu `InvalidArgumentException` dari kalkulator yang nggak
     * ada yang menangkap. Penolakannya sendiri benar dan tetap; yang berubah
     * cuma teknisi sekarang dapat pesan yang bisa dia tindaklanjuti, bukan
     * "Server Error". Penjagaannya pindah ke `pastikanAdaBacaanSuhu()` —
     * sejajar dengan `pastikanAdaBacaanUut()` yang sudah lama ada buat blok
     * tekanan, lengkap dengan alasan yang sama persis: *"ditolak di sini (422)
     * biar teknisi dapat pesan yang jelas, bukan 500 dari kalkulator."*
     *
     * Penjagaan kalkulatornya TIDAK dihapus — dia tetap jadi lapis kedua buat
     * pemanggil yang nggak lewat FormRequest (`HitungUlangSesi`), dan diuji
     * langsung di `BlokSuhuAutoklafKosongDitolakTest`.
     */
    public function test_blok_suhu_dikirim_tapi_kosong_tetap_ditolak(): void
    {
        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave/preview', [
                'set_point' => 121.0,
                'suhu' => ['disk' => [[null, null]], 'indikator' => [null]],
                'tekanan' => [
                    'indikator_pressure' => [0.112, 0.112],
                    'satuan' => 'MPa',
                    'pembacaan_standar' => [1.233, 1.231],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['suhu.disk']);
    }

    public function test_preview_wajib_set_point(): void
    {
        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave/preview', ['suhu' => ['disk' => [[121.0]]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('set_point');
    }
}
