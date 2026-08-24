<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Alur OCR: OCR-nya jalan di mobile, backend nyimpen foto + metadata + jaga
 * biar angka OCR yang belum dikonfirmasi manusia nggak ikut disertifikasi.
 */
class OcrMeasurementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private User $teknisiLain;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->teknisiLain = User::factory()->create();
        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    private function uploadFoto(User $user): string
    {
        return $this->actingAs($user)
            ->post('/api/calibrations/photos', [
                'photo' => UploadedFile::fake()->image('display.jpg', 640, 480),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.photo_path');
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $ocr
     */
    private function submitOcr(?array $ocr = null): CalibrationSession
    {
        $foto = $this->uploadFoto($this->teknisi);

        $ocr ??= [
            ['photo_path' => $foto, 'confidence' => 0.98, 'raw_text' => '50.02'],
            ['photo_path' => $foto, 'confidence' => 0.95, 'raw_text' => '50.01'],
            ['photo_path' => $foto, 'confidence' => 0.90, 'raw_text' => '50.03'],
        ];

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'ocr',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [[
                'titik_ukur' => 50.0,
                'satuan' => 'mm',
                'pembacaan' => [50.02, 50.01, 50.03],
                'ocr' => $ocr,
            ]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_upload_foto_balikin_path_dan_kesimpen(): void
    {
        $path = $this->uploadFoto($this->teknisi);

        $this->assertStringStartsWith('measurements/', $path);
        Storage::disk('arsip')->assertExists($path);
    }

    public function test_upload_nolak_file_bukan_gambar(): void
    {
        $this->actingAs($this->teknisi)
            ->post('/api/calibrations/photos', [
                'photo' => UploadedFile::fake()->create('data.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('photo');
    }

    public function test_submit_ocr_nyimpen_metadata_dan_belum_terverifikasi(): void
    {
        $foto = $this->uploadFoto($this->teknisi);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'ocr',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [[
                'titik_ukur' => 50.0,
                'satuan' => 'mm',
                'pembacaan' => [50.02, 50.01, 50.03],
                'ocr' => [
                    ['photo_path' => $foto, 'confidence' => 0.98, 'raw_text' => '50.02'],
                    ['photo_path' => $foto, 'confidence' => 0.95, 'raw_text' => '50.01'],
                    ['photo_path' => $foto, 'confidence' => 0.90, 'raw_text' => '50.03'],
                ],
            ]],
        ])->assertCreated();

        $baris = RawMeasurement::query()->firstOrFail();
        $this->assertSame('ocr', $baris->input_source);
        $this->assertFalse($baris->is_verified);
        $this->assertSame($foto, $baris->photo_path);
        $this->assertSame(0.98, $baris->ocr_confidence);
        $this->assertSame('50.02', $baris->ocr_raw_text);
    }

    public function test_input_manual_langsung_terverifikasi(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $this->assertSame(0, RawMeasurement::where('is_verified', false)->count());
        $this->assertSame('manual', RawMeasurement::query()->firstOrFail()->input_source);
    }

    public function test_submit_ocr_jumlah_beda_pembacaan_ditolak(): void
    {
        $foto = $this->uploadFoto($this->teknisi);

        // 3 pembacaan tapi cuma 2 data OCR.
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'ocr',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [[
                'titik_ukur' => 50.0,
                'satuan' => 'mm',
                'pembacaan' => [50.02, 50.01, 50.03],
                'ocr' => [
                    ['photo_path' => $foto, 'confidence' => 0.98, 'raw_text' => '50.02'],
                    ['photo_path' => $foto, 'confidence' => 0.95, 'raw_text' => '50.01'],
                ],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrorFor('measurements.0.ocr');
    }

    public function test_submit_ocr_photo_path_tak_dikenal_ditolak(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'ocr',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [[
                'titik_ukur' => 50.0,
                'satuan' => 'mm',
                'pembacaan' => [50.02, 50.01, 50.03],
                'ocr' => [
                    ['photo_path' => 'measurements/tidakada1.jpg'],
                    ['photo_path' => 'measurements/tidakada2.jpg'],
                    ['photo_path' => 'measurements/tidakada3.jpg'],
                ],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrorFor('measurements.0.ocr.0.photo_path');
    }

    public function test_submit_ocr_photo_path_di_luar_folder_ditolak(): void
    {
        // Path traversal / path sembarangan ditolak sama regex, sebelum sentuh disk.
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'ocr',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [[
                'titik_ukur' => 50.0,
                'satuan' => 'mm',
                'pembacaan' => [50.02, 50.01, 50.03],
                'ocr' => [
                    ['photo_path' => '../../.env'],
                    ['photo_path' => '../../.env'],
                    ['photo_path' => '../../.env'],
                ],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrorFor('measurements.0.ocr.0.photo_path');
    }

    public function test_approve_ditolak_kalau_ada_ocr_belum_diverifikasi(): void
    {
        Queue::fake();
        $sesi = $this->submitOcr();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertStatus(422);

        // Sertifikat nggak boleh kegenerate; sinyal broadcast realtime boleh jalan.
        Queue::assertNotPushed(GenerateCertificate::class);
    }

    public function test_verifikasi_semua_lalu_approve_berhasil(): void
    {
        $sesi = $this->submitOcr();

        $this->actingAs($this->teknisi)
            ->postJson("/api/calibrations/{$sesi->id}/measurements/verify")
            ->assertOk()
            ->assertJsonPath('meta.diverifikasi', 3);

        $this->assertSame(0, $sesi->rawMeasurements()->where('is_verified', false)->count());

        Queue::fake();
        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        // Approve nerbitin sertifikat langsung sejak 29 Juli 2026 — yang
        // diperiksa hasilnya, bukan job-nya masuk antrean.
        $this->assertDatabaseHas('certificates', [
            'calibration_session_id' => $sesi->id,
            'status' => 'terbit',
        ]);
    }

    public function test_verifikasi_sebagian_pakai_ids(): void
    {
        $sesi = $this->submitOcr();
        $duaId = $sesi->rawMeasurements()->orderBy('id')->limit(2)->pluck('id')->all();

        $this->actingAs($this->teknisi)
            ->postJson("/api/calibrations/{$sesi->id}/measurements/verify", [
                'measurement_ids' => $duaId,
            ])
            ->assertOk()
            ->assertJsonPath('meta.diverifikasi', 2);

        // Masih ada 1 yang belum → approve tetap ketahan.
        $this->assertSame(1, $sesi->rawMeasurements()->where('is_verified', false)->count());
        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertStatus(422);
    }

    public function test_teknisi_lain_nggak_bisa_verifikasi(): void
    {
        $sesi = $this->submitOcr();

        // Konsisten sama proteksi sesi lain: teknisi bukan pemilik dapat 404.
        $this->actingAs($this->teknisiLain)
            ->postJson("/api/calibrations/{$sesi->id}/measurements/verify")
            ->assertNotFound();
    }

    public function test_detail_sesi_nampilin_status_verifikasi_pembacaan(): void
    {
        $sesi = $this->submitOcr();

        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.perlu_verifikasi', true)
            ->assertJsonCount(3, 'data.pembacaan_mentah')
            ->assertJsonPath('data.pembacaan_mentah.0.is_verified', false)
            ->assertJsonPath('data.pembacaan_mentah.0.input_source', 'ocr');
    }

    public function test_perlu_verifikasi_jadi_false_setelah_diverifikasi(): void
    {
        $sesi = $this->submitOcr();
        $this->actingAs($this->teknisi)
            ->postJson("/api/calibrations/{$sesi->id}/measurements/verify")
            ->assertOk();

        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.perlu_verifikasi', false)
            ->assertJsonPath('data.pembacaan_mentah.0.is_verified', true);
    }

    public function test_detail_sesi_manual_nggak_perlu_verifikasi(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();
        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.perlu_verifikasi', false);
    }

    public function test_daftar_sesi_nggak_muat_pembacaan_mentah(): void
    {
        $this->submitOcr();

        // whenLoaded: rawMeasurements cuma dimuat di detail, biar list nggak berat.
        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations')
            ->assertOk()
            ->assertJsonMissingPath('data.0.pembacaan_mentah')
            ->assertJsonMissingPath('data.0.perlu_verifikasi');
    }

    /**
     * Sesi yang tabelnya dibaca AI Vision harus BENERAN kesimpen `ai_vision`.
     *
     * **Bug lapangan 7 Agt 2026.** Mobile udah ngirim `ai_vision` sejak jalur
     * kamera pindah ke server, `CalibrationRequest` udah nerima
     * (`Rule::in(['manual','ocr','ai_vision'])`) — tapi kolomnya masih
     * `enum('manual','ocr')`, sisa dari sebelum AI Vision ada. MySQL nolak:
     *
     *     SQLSTATE[01000]: 1265 Data truncated for column 'input_method'
     *
     * Teknisi lihat "gagal kirim" padahal fotonya sukses kebaca dan angkanya
     * udah masuk formulir. Migrasi `2026_07_24_100100` udah ngelebarin
     * `raw_measurements.input_source` buat alasan yang sama persis —
     * `calibration_sessions.input_method` kelewat.
     *
     * Nggak ada satu pun test yang pernah ngirim `ai_vision`; semuanya `ocr`.
     */
    public function test_sesi_hasil_ai_vision_kesimpen_apa_adanya(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'ai_vision',
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [[
                'titik_ukur' => 50.0,
                'satuan' => 'mm',
                'pembacaan' => [50.02, 50.01, 50.03],
            ]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->assertSame('ai_vision', $sesi->input_method);
        $this->assertSame('ai_vision', $sesi->rawMeasurements()->value('input_source'));
    }

    /**
     * Pagar buat kelas bug di atas, bukan cuma satu nilainya.
     *
     * Test jalan di SQLite, dan SQLite **nggak nagih enum sama sekali** — nilai
     * apa pun masuk tanpa keluhan. Jadi test di atas bakal tetap hijau di
     * SQLite walau kolomnya balik jadi enum sempit, dan bugnya baru ketahuan
     * lagi dari lapangan. Yang ini nyium langsung ke skema, dan cuma jalan di
     * MySQL — satu-satunya tempat enum-nya beneran ditegakkan.
     */
    public function test_kolom_sumber_input_bukan_enum_sempit(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Enum cuma ditegakkan MySQL; SQLite nerima apa aja.');
        }

        foreach ([
            ['calibration_sessions', 'input_method'],
            ['raw_measurements', 'input_source'],
        ] as [$tabel, $kolom]) {
            // Lewat `information_schema`, BUKAN `SHOW COLUMNS ... LIKE ?`.
            //
            // MySQL nggak nerima placeholder di `SHOW COLUMNS`, jadi bind-nya
            // nggak kepasang dan yang kekirim `... LIKE input_method` tanpa
            // kutip — syntax error, bukan assert yang gagal.
            //
            // Efeknya tes ini NGGAK PERNAH beneran jalan: di SQLite di-skip,
            // di MySQL meledak sebelum sampai assert. Ketahuan 11 Agt 2026
            // waktu suite sengaja dijalanin ke MySQL buat ngilangin skip
            // terakhir. Cakupan yang cuma kelihatan ada.
            $baris = DB::selectOne(
                'select column_type as tipe from information_schema.columns
                 where table_schema = database() and table_name = ? and column_name = ?',
                [$tabel, $kolom],
            );

            $this->assertNotNull($baris, "kolom {$tabel}.{$kolom} nggak ketemu di skema");

            $this->assertStringStartsNotWith(
                'enum(',
                (string) $baris->tipe,
                "{$tabel}.{$kolom} balik jadi enum — sumber input baru bakal ditolak diam-diam",
            );
        }
    }
}
