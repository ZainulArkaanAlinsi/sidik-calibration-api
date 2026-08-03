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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Struktur baku sertifikat (spesifikasi poin 9) — header 16 field, tabel hasil
 * 4 kolom, tabel standar, footer. Yang diuji di sini isinya, bukan tampilannya.
 */
class CertificateSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    private Room $ruangan;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create([
            'nama' => 'PT Sidik Kalibrasi',
            'settings' => [
                'penandatangan_nama' => 'Alex Misramto',
                'penandatangan_jabatan' => 'Technical Manager',
                'kode_dokumen_form' => 'SIDIK-FM-CAL-2403_Rev. 0',
            ],
        ]);

        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create(['name' => 'Dedi Rahmat', 'kode_teknisi' => 'DR']);
        $this->ruangan = Room::factory()->create(['nama' => 'Lab. Uji A']);

        $pelanggan = Customer::factory()->create([
            'nama' => 'PT TIRTA GRACIA SEMESTA MANDIRI',
            'alamat' => 'Jl. Arteri Primer A-10, Cicalengka, Kab. Bandung',
        ]);

        $this->alat = Equipment::factory()->create([
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'nama_alat' => 'pH Meter',
            'merk' => 'Mettler Toledo',
            'model' => 'Five Easy',
            'serial_number' => 'B628755900',
            'range_min' => 0, 'range_max' => 14,
            'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.2,
        ]);

        $this->standar = Standard::factory()->create([
            'nama' => 'pH Buffer Solution 4',
            'merk' => 'Supelco', 'model' => 'Merck',
            'serial_number' => 'HC32513535',
            'tertelusur_ke' => 'Merck KGaA',
            'satuan_ketidakpastian' => 'pH',
        ]);
    }

    private function terbitkanSertifikat(): Certificate
    {
        Storage::fake('local');

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'room_id' => $this->ruangan->id,
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

        // Field administratif diisi admin, bukan teknisi (spesifikasi poin 1).
        $metode = CalibrationMethod::factory()->create(['kode' => 'SIDIK-IK-CAL-0506', 'revisi' => '6']);

        $this->actingAs($this->admin)->patchJson("/api/calibrations/{$sesi->id}/admin", [
            'nomor_order' => '2405.13.A',
            'calibration_method_id' => $metode->id,
            'suhu_ketidakpastian' => 1.7,
            'kelembaban_ketidakpastian' => 5.7,
        ])->assertOk();

        $this->actingAs($this->admin)->postJson("/api/calibrations/{$sesi->id}/approve")->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    public function test_header_sertifikat_keisi_lengkap_dari_data_yang_terhubung(): void
    {
        $header = $this->terbitkanSertifikat()->snapshot['header'];

        // 16 field, nggak lebih & nggak kurang — strukturnya dikunci spesifikasi.
        $this->assertSame([
            'certificate_number', 'page', 'owner', 'order_number', 'address', 'received_date',
            'equipment_name', 'manufacturer', 'calibration_location', 'model_type',
            'calibration_date', 'serial_number', 'calibration_method', 'capacity_graduation',
            'env_condition', 'technician_id',
        ], array_keys($header));

        $this->assertSame('PT TIRTA GRACIA SEMESTA MANDIRI', $header['owner']);
        $this->assertSame('2405.13.A', $header['order_number']);
        $this->assertSame('pH Meter', $header['equipment_name']);
        $this->assertSame('Mettler Toledo', $header['manufacturer']);
        $this->assertSame('Five Easy', $header['model_type']);
        $this->assertSame('B628755900', $header['serial_number']);
        $this->assertSame('Lab. Uji A', $header['calibration_location']);
        $this->assertSame('SIDIK-IK-CAL-0506_Rev.6', $header['calibration_method']);
        $this->assertSame('0–14 pH / 0,01 pH', $header['capacity_graduation']);
        $this->assertSame('T: 21,0°C ± 1,7°C — %RH: 51,95% ± 5,7%', $header['env_condition']);
        $this->assertSame('DR', $header['technician_id']);
    }

    public function test_correction_di_tabel_hasil_itu_standard_value_dikurangi_pembacaan(): void
    {
        $hasil = $this->terbitkanSertifikat()->snapshot['hasil'];

        $this->assertCount(2, $hasil);

        foreach ($hasil as $baris) {
            $this->assertEqualsWithDelta(
                $baris['standard_value'] - $baris['unit_under_test'],
                $baris['correction'],
                1e-9,
                'Correction wajib = Standard Value − Unit Under Test.',
            );
            $this->assertGreaterThan(0, $baris['u95']);
        }
    }

    public function test_dua_catatan_baku_selalu_ikut_di_bawah_tabel(): void
    {
        $snapshot = $this->terbitkanSertifikat()->snapshot;

        $this->assertSame(CertificateSnapshotBuilder::CATATAN_HASIL, $snapshot['catatan']);
    }

    public function test_tabel_standar_nggak_nulis_standar_yang_sama_dua_kali(): void
    {
        $standar = $this->terbitkanSertifikat()->snapshot['standar_digunakan'];

        // Dua titik pakai standar yang sama → tetap satu baris.
        $this->assertCount(1, $standar);
        $this->assertSame('pH Buffer Solution 4', $standar[0]['name']);
        $this->assertSame('Supelco/Merck', $standar[0]['merk_type']);
        $this->assertSame('Merck KGaA', $standar[0]['traceable_to']);
    }

    public function test_penandatangan_diambil_dari_pengaturan_organisasi_bukan_admin_yang_mencet_approve(): void
    {
        $footer = $this->terbitkanSertifikat()->snapshot['footer'];

        $this->assertSame('Alex Misramto', $footer['penandatangan']);
        $this->assertSame('Technical Manager', $footer['jabatan']);
        $this->assertSame('SIDIK-FM-CAL-2403_Rev. 0', $footer['kode_dokumen']);
        $this->assertNotSame($this->admin->name, $footer['penandatangan']);
    }

    public function test_snapshot_nggak_ikut_berubah_waktu_data_master_diedit(): void
    {
        $sertifikat = $this->terbitkanSertifikat();

        // Alamat PT pindah setahun kemudian — sertifikat yang udah dipegang
        // pelanggan nggak boleh ikut berubah isinya.
        $this->alat->customer->update(['alamat' => 'Alamat baru sesudah pindah kantor']);
        $this->alat->update(['serial_number' => 'SN-DIGANTI']);

        $header = $sertifikat->fresh()->snapshot['header'];

        $this->assertStringContainsString('Cicalengka', $header['address']);
        $this->assertSame('B628755900', $header['serial_number']);
    }

    public function test_teknisi_tanpa_kode_jatuh_ke_inisial_namanya(): void
    {
        $this->teknisi->update(['kode_teknisi' => null]);

        $this->assertSame('DR', $this->terbitkanSertifikat()->snapshot['header']['technician_id']);
    }

    /**
     * Desimal tabel hasil ngikut resolusi alat, bukan angka mati.
     *
     * Nulis desimal lebih banyak daripada yang bisa dibaca alatnya itu ngaku
     * presisi yang nggak ada; kekurangan bikin U95% kehilangan angka penting.
     */
    public function test_desimal_tabel_hasil_ngikut_resolusi_alat(): void
    {
        // Resolusi 0,01 → 2 desimal, dibekukan di snapshot waktu terbit.
        $this->assertSame(2, $this->terbitkanSertifikat()->snapshot['desimal']);
    }

    /**
     * Semua variasi diuji lewat builder langsung, bukan dengan nerbitin
     * sertifikat berulang: `terbitkanSertifikat()` bikin `CalibrationMethod`
     * berkode tetap, jadi pemanggilan kedua nabrak unique constraint.
     */
    public function test_desimal_bisa_ditimpa_pengaturan_dan_nolak_nilai_bukan_angka(): void
    {
        $sertifikat = $this->terbitkanSertifikat();
        $sesi = $sertifikat->session;
        $organisasi = Organization::query()->firstOrFail();
        $builder = app(CertificateSnapshotBuilder::class);

        $desimalUntuk = function (mixed $setelan) use ($organisasi, $sesi, $sertifikat, $builder): int {
            $organisasi->update([
                'settings' => [...$organisasi->settings, Organization::KEY_DESIMAL_SERTIFIKAT => $setelan],
            ]);

            return $builder->bangun($sesi->fresh(), $sertifikat)['desimal'];
        };

        // Resolusi alatnya tetap 0,01 (otomatisnya 2), jadi 3 & 4 di bawah cuma
        // bisa datang dari pengaturan.
        $this->assertSame(3, $desimalUntuk(3));
        $this->assertSame(4, $desimalUntuk('4'), 'Angka berupa string dari form tetap kepakai.');

        // Yang bukan angka HARUS jatuh balik ke resolusi alat, bukan jadi 0
        // desimal. Bukan kasus karangan: kolom teks di panel admin ngirim string
        // kosong waktu dibersihin, dan `(int) ''` itu 0 — kalau lolos, seluruh
        // tabel sertifikat kecetak jadi bilangan bulat (`4` bukan `4,01`) tanpa
        // ada yang minta.
        foreach (['', 'otomatis', null] as $nilai) {
            $this->assertSame(
                2,
                $desimalUntuk($nilai),
                'Setelan '.var_export($nilai, true).' mestinya diabaikan, bukan jadi 0 desimal.',
            );
        }
    }

    /** Alat yang bacanya lebih halus dapet lebih banyak desimal. */
    public function test_alat_resolusi_lebih_halus_dapet_desimal_lebih_banyak(): void
    {
        $this->alat->update(['resolusi' => 0.001]);

        $this->assertSame(3, $this->terbitkanSertifikat()->snapshot['desimal']);
    }

    /**
     * `desimal` ikut kekirim ke mobile, dan di sertifikat dia BEKU.
     *
     * Tanpa ini mobile kepaksa nebak aturan pembulatannya sendiri, dan layar bisa
     * nulis `0,0234` sementara sertifikat nyetak `0,023` — pelanggan yang
     * mencocokkan dua-duanya nggak punya cara tau mana yang bener.
     *
     * Bekunya penting: sertifikat yang udah terbit nggak boleh berubah bentuk
     * gara-gara pengaturan diubah sesudahnya.
     */
    public function test_desimal_kekirim_ke_api_dan_beku_di_sertifikat(): void
    {
        $this->alat->update(['resolusi' => 0.001]);
        $sertifikat = $this->terbitkanSertifikat();

        $this->actingAs($this->admin)
            ->getJson("/api/certificates/{$sertifikat->id}")
            ->assertOk()
            ->assertJsonPath('data.desimal', 3);

        // Pengaturan diubah SESUDAH sertifikat terbit — yang udah terbit nggak
        // boleh ikut berubah.
        $organisasi = Organization::query()->firstOrFail();
        $organisasi->update([
            'settings' => [...$organisasi->settings, Organization::KEY_DESIMAL_SERTIFIKAT => 5],
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/certificates/{$sertifikat->id}")
            ->assertOk()
            ->assertJsonPath('data.desimal', 3);

        // Sesi masih HIDUP: dia belum punya dokumen resmi, jadi dia ngikut
        // pengaturan yang berlaku sekarang.
        $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sertifikat->calibration_session_id}")
            ->assertOk()
            ->assertJsonPath('data.desimal', 5);
    }
}
