<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sesi Enclosure bisa dihitung TANPA teknisi mengisi kolom yang nggak ada.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * Perhitungan Enclosure membaca `calibration_sessions.standard_id` buat tahu
 * tabel koreksi mana yang dipakai — Constant, Yokogawa, atau Recorder. Begitu
 * merk kalibrator nggak kebaca, SELURUH titik dicap belum bisa dihitung.
 *
 * Tapi lembar kerja Enclosure sama sekali nggak punya kotak buat kolom itu.
 * Yang ada cuma kolom centang "Dipakai" di daftar standar, dan centangan itu
 * mendarat di tabel pivot yang nggak pernah dibaca jalur hitung. Panel admin
 * juga nggak punya formnya.
 *
 * Jadi tiap sesi Enclosure berakhir NOL TITIK — dengan pesan yang justru
 * berbunyi *"belum kebaca dari standar yang dicentang"*, yaitu menyalahkan
 * kotak yang SUDAH dicentang teknisi.
 *
 * ## Kenapa diturunkan di server
 *
 * Teknisi sudah menyatakan alat mana yang dia pakai — dia mencentangnya.
 * Menambah kotak KEDUA yang menanyakan hal yang sama membuka jalan keduanya
 * berbeda, dan di lab terakreditasi dua jawaban buat satu pertanyaan
 * ketertelusuran itu temuan audit.
 */
class EnclosureKalibratorTurunanTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 1e-6;

    /** @return array{Equipment, Standard, User} */
    private function bahan(): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', 'D132469')->firstOrFail(),
            Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Equipment $alat): array
    {
        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2024-05-02',
            'tipe_sensor' => 'Type N',
            'suhu_awal' => 23.7, 'suhu_akhir' => 23.7,
            'kelembaban_awal' => 47, 'kelembaban_akhir' => 46,
            'measurements' => [[
                'titik_ukur' => 15.0,
                'satuan' => '°C',
                'sensor_grid' => [
                    ['no' => 3, 'pembacaan' => [15.02, 15.03, 15.01, 15.02, 15.03]],
                    ['no' => 4, 'pembacaan' => [15.11, 15.12, 15.10, 15.11, 15.12]],
                ],
                'indikator' => [15.0, 15.0, 15.0, 15.0, 15.0],
            ]],
        ];
    }

    /**
     * INTI: centang "Dipakai" saja cukup — `standard_id` nggak dikirim.
     */
    public function test_kalibrator_diturunkan_dari_baris_yang_dicentang(): void
    {
        [$alat, $standar, $teknisi] = $this->bahan();

        $respons = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat) + [
                'standar_dicek' => [
                    ['standard_id' => $standar->id, 'dipakai' => true],
                ],
            ])
            ->assertCreated();

        $titik = $respons->json('data.titik');

        $this->assertNotEmpty(
            $titik,
            'Nol titik terhitung padahal kalibratornya sudah dicentang. Ini persis kegagalan '
            .'yang bikin tiap sesi Enclosure mustahil bersertifikat.',
        );

        $this->assertNotNull(
            $titik[0]['ketidakpastian_diperluas'] ?? null,
            'Titiknya ada tapi U95-nya kosong — tabel koreksinya tetap nggak kebaca.',
        );

        // Ketertelusurannya tercatat di KOLOM SESI, bukan cuma nempel di baris
        // mentah: itu yang dibaca sertifikat dan auditor.
        $sesi = CalibrationSession::findOrFail($respons->json('data.id'));

        $this->assertSame($standar->id, $sesi->standard_id);
    }

    /** Peringatan "merk kalibrator belum kebaca" ikut hilang. */
    public function test_peringatan_kalibrator_kosong_hilang(): void
    {
        [$alat, $standar, $teknisi] = $this->bahan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat) + [
                'standar_dicek' => [['standard_id' => $standar->id, 'dipakai' => true]],
            ])
            ->assertCreated()
            ->json('data.id');

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $kode = array_column(
            $this->actingAs($admin)->getJson("/api/calibrations/{$id}/validasi")->assertOk()->json('data.temuan'),
            'kode',
        );

        $this->assertNotContains('enclosure_kalibrator_kosong', $kode);
        $this->assertNotContains('titik_tidak_terhitung', $kode);
    }

    /**
     * Pilihan EKSPLISIT teknisi selalu menang atas turunan.
     *
     * Kalau nggak, teknisi yang sengaja memilih kalibrator lain diam-diam
     * ditimpa oleh centangan — dan yang tercetak di sertifikat bukan alat yang
     * dia pakai.
     */
    public function test_standard_id_eksplisit_menang(): void
    {
        [$alat, $yokogawa, $teknisi] = $this->bahan();

        $constant = Standard::where('organization_id', $alat->organization_id)
            ->where('nama', 'not like', '%Yokogawa%')
            ->where('merk', 'like', '%onstant%')
            ->first();

        if ($constant === null) {
            $this->markTestSkipped('Seeder nggak punya kalibrator Constant terpisah.');
        }

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat) + [
                'standard_id' => $constant->id,
                'standar_dicek' => [['standard_id' => $yokogawa->id, 'dipakai' => true]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($constant->id, CalibrationSession::findOrFail($id)->standard_id);
    }

    /**
     * Dua kalibrator tercentang = nggak ada dasar buat memilih.
     *
     * Memilih diam-diam berarti sertifikat terbit dengan tabel koreksi yang
     * mungkin salah tanpa satu pun jejak. Lebih baik sesinya tetap belum
     * kehitung dengan peringatan yang jujur.
     */
    public function test_dua_kalibrator_tercentang_nggak_ditebak(): void
    {
        [$alat, $yokogawa, $teknisi] = $this->bahan();

        $recorder = Standard::where('organization_id', $alat->organization_id)
            ->where('nama', 'like', '%Recorder%')
            ->firstOrFail();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat) + [
                'standar_dicek' => [
                    ['standard_id' => $yokogawa->id, 'dipakai' => true],
                    ['standard_id' => $recorder->id, 'dipakai' => true],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNull(CalibrationSession::findOrFail($id)->standard_id);
    }

    /**
     * Simpan ulang TANPA membawa kolomnya nggak menghapus ketertelusuran.
     *
     * `standard_id` dan `tanggal_terima` dulu ada di blok yang selalu ditulis,
     * dan di situ "nggak dikirim" berarti "kosongkan". Jadi sekali sesi yang
     * sudah benar disimpan ulang oleh siapa pun — simpan draft, revisi kepala
     * lembar, admin membetulkan nomor order — catatan ketertelusurannya hilang
     * DIAM-DIAM. Yang menghapus bukan orang yang mengisi.
     *
     * Untuk lab terakreditasi, kalibrator acuan yang hilang dari sesi itu
     * temuan audit. `tanggal_terima` lebih menyebalkan lagi: yang mengisinya
     * admin, jadi tiap kali teknisi menyimpan revisi, tanggal yang sudah benar
     * terhapus dan muncul lagi sebagai temuan di layar approval.
     */
    public function test_simpan_ulang_tanpa_kolomnya_nggak_menghapus_ketertelusuran(): void
    {
        [$alat, $standar, $teknisi] = $this->bahan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat) + [
                'standard_id' => $standar->id,
                'tanggal_terima' => '2024-05-01',
                'status' => CalibrationSession::STATUS_DRAFT,
            ])
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $this->assertSame($standar->id, $sesi->standard_id);
        $this->assertSame('2024-05-01', $sesi->tanggal_terima?->toDateString());

        // Simpan ulang kepala lembar: payload teknisi yang cuma membetulkan
        // satu kolom identitas, TANPA membawa `standard_id` maupun
        // `tanggal_terima` — persis bentuk yang dikirim HP waktu revisi.
        $tanpa = $this->payload($alat);
        unset($tanpa['equipment_id']);

        $this->actingAs($teknisi)
            ->putJson("/api/calibrations/{$id}", $tanpa + [
                'equipment_id' => $alat->id,
                'alat_model' => 'INK-100',
                'status' => CalibrationSession::STATUS_DRAFT,
            ])
            ->assertOk();

        $sesi->refresh();

        $this->assertSame(
            $standar->id,
            $sesi->standard_id,
            'Kalibrator acuan terhapus oleh simpan ulang yang nggak menyentuhnya sama sekali.',
        );
        $this->assertSame(
            '2024-05-01',
            $sesi->tanggal_terima?->toDateString(),
            'Tanggal terima yang diisi admin terhapus oleh simpan ulang teknisi.',
        );
        $this->assertSame('INK-100', $sesi->alat_model);
    }

    /** Dikosongkan EKSPLISIT tetap boleh — yang dilarang cuma "nggak dikirim = hapus". */
    public function test_dikirim_kosong_eksplisit_tetap_mengosongkan(): void
    {
        [$alat, $standar, $teknisi] = $this->bahan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat) + [
                'standard_id' => $standar->id,
                'status' => CalibrationSession::STATUS_DRAFT,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payload($alat) + [
                'standard_id' => null,
                'status' => CalibrationSession::STATUS_DRAFT,
            ])
            ->assertOk();

        $this->assertNull(CalibrationSession::findOrFail($id)->standard_id);
    }

    /** Baris yang TIDAK dicentang nggak ikut jadi kandidat. */
    public function test_centang_mati_nggak_diturunkan(): void
    {
        [$alat, $standar, $teknisi] = $this->bahan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat) + [
                'standar_dicek' => [['standard_id' => $standar->id, 'dipakai' => false]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNull(CalibrationSession::findOrFail($id)->standard_id);
    }
}
