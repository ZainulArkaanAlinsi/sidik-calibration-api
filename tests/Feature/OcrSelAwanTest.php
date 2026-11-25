<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Models\WorksheetScan;
use App\Services\Vision\GoogleVisionOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MembuatKunciUji;
use Tests\TestCase;

/**
 * OCR awan atas POTONGAN SEL ANGKA: `POST /api/worksheet-scans/{worksheetScan}/ocr-awan`.
 *
 * Google SELALU di-fake (`Http::fake` + `Http::preventStrayRequests`): tes ini tidak boleh memanggil
 * layanan sungguhan dan tidak membuktikan apa pun tentang akurasi atau tagihan Google. Yang dibuktikan:
 * sel mana yang keluar dari server (hanya sel angka, dipotong server dari citra tersimpan), gerbang
 * berlapis, batas per lembar / per hasil pindai, status yang tidak pernah hijau, teks mentah yang tidak
 * dikoreksi diam-diam, nilai sel di database yang tidak berubah, jejak audit tanpa gambar dan tanpa
 * teks, dan semua jalur gagal yang jatuh ke "isi manual".
 */
class OcrSelAwanTest extends TestCase
{
    use MembuatKunciUji;
    use RefreshDatabase;

    private Organization $org;

    private User $teknisi;

    private CalibrationSession $sesi;

    private WorksheetScan $scan;

    /**
     * Jawaban Google per gambar, dipakai berurutan lintas permintaan. Bentuk: `[teks, keyakinan]`,
     * `'kosong'` (Google tidak melihat tulisan), atau `'galat'` (Google menolak gambar itu).
     * Bila habis, gambar dijawab `7.5` dengan keyakinan 0,95.
     *
     * @var list<array{0: string, 1: float}|string>
     */
    private array $jawaban = [];

    private int $urutanJawaban = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD tidak ada: server pun menolak memotong citra di mesin seperti ini.');
        }

        Cache::flush();
        Storage::fake('local');
        Http::preventStrayRequests();

        [, $pem] = $this->bangkitkanKunciUji();

        config([
            'ocr.penyimpanan.disk' => 'local',
            'ocr.awan_sel.maks_sel_per_permintaan' => 48,
            'ocr.awan_sel.maks_sel_per_scan' => 80,
            'ocr.awan_sel.margin_px' => 4,
            'services.google_vision.aktif' => true,
            'services.google_vision.kredensial' => json_encode([
                'type' => 'service_account',
                'client_email' => 'ocr-uji@proyek-uji.iam.gserviceaccount.com',
                'private_key' => $pem,
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ]),
            // Dikunci di sini supaya tes tidak bergantung pada `.env` mesin kerja.
            'services.google_vision.maks_per_sesi' => 5,
            'services.google_vision.maks_gagal_per_sesi' => 3,
        ]);

        $this->org = Organization::factory()->create();
        $this->teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI, 'organization_id' => $this->org->id]);

        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'nama_alat' => 'pH Meter', 'satuan' => 'pH',
        ]);
        $this->sesi = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $alat->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);

        $this->scan = $this->buatScanDasar();
    }

    // ------------------------------------------------------------ bahan uji

    private function simpanCitra(string $nama): string
    {
        $gambar = imagecreatetruecolor(400, 600);
        imagefill($gambar, 0, 0, (int) imagecolorallocate($gambar, 255, 255, 255));
        ob_start();
        imagejpeg($gambar, null, 90);
        $isi = (string) ob_get_clean();
        unset($gambar);

        $path = "worksheet-scans/{$nama}-warp.jpg";
        Storage::disk('local')->put($path, $isi);

        return $path;
    }

    /**
     * Satu hasil pindai cetak dengan tujuh sel:
     *  - tabel_a baris 1..4 (angka): merah, kuning, hijau, kosong
     *  - tabel_j baris 1 (jam): merah
     *  - isian|suhu_awal (kondisi lingkungan): kuning
     *  - centang|ya: kuning
     */
    private function buatScanDasar(): WorksheetScan
    {
        $scan = WorksheetScan::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->teknisi->id,
            'calibration_session_id' => $this->sesi->id,
            'citra_warp_path' => $this->simpanCitra('dasar'),
            'hasil' => ['tabel' => [
                ['tabel_id' => 'tabel_a', 'baris' => [
                    ['baris_ke' => 1, 'tipe' => 'angka'],
                    ['baris_ke' => 2, 'tipe' => 'angka'],
                    ['baris_ke' => 3, 'tipe' => 'angka'],
                    ['baris_ke' => 4, 'tipe' => 'angka'],
                ]],
                ['tabel_id' => 'tabel_j', 'baris' => [['baris_ke' => 1, 'tipe' => 'jam']]],
            ]],
        ]);

        $this->buatSel($scan, 'tabel_a', 1, 'merah', 10, 10, '4,00');
        $this->buatSel($scan, 'tabel_a', 2, 'kuning', 10, 50, '4,01');
        $this->buatSel($scan, 'tabel_a', 3, 'hijau', 10, 90, '4,02');
        $this->buatSel($scan, 'tabel_a', 4, 'kosong', 10, 130, null);
        $this->buatSel($scan, 'tabel_j', 1, 'merah', 10, 170, '10:15');
        $this->buatSelLain($scan, 'isian', 'suhu_awal', 'isian|suhu_awal', 'kuning', 10, 210);
        $this->buatSelLain($scan, 'centang', 'ya', 'centang|ya', 'kuning', 10, 250);

        return $scan;
    }

    /** Hasil pindai dengan `$jumlah` baris angka berstatus kuning, untuk uji pemecahan batch. */
    private function buatScanBanyakSel(int $jumlah): WorksheetScan
    {
        $baris = [];
        foreach (range(1, $jumlah) as $i) {
            $baris[] = ['baris_ke' => $i, 'tipe' => 'angka'];
        }

        $scan = WorksheetScan::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->teknisi->id,
            'calibration_session_id' => $this->sesi->id,
            'citra_warp_path' => $this->simpanCitra('banyak'),
            'hasil' => ['tabel' => [['tabel_id' => 'tabel_a', 'baris' => $baris]]],
        ]);

        foreach (range(1, $jumlah) as $i) {
            $this->buatSel($scan, 'tabel_a', $i, 'kuning', 10, 10 + ($i - 1) * 30, '4,00', 20);
        }

        return $scan;
    }

    private function buatSel(
        WorksheetScan $scan,
        string $tabel,
        int $baris,
        string $status,
        int $x,
        int $y,
        ?string $teksMentah,
        int $tinggi = 30,
    ): void {
        $scan->cells()->create([
            'kunci' => "{$tabel}|{$baris}|1|pembacaan",
            'tabel_id' => $tabel,
            'baris_ke' => $baris,
            'repeat_no' => 1,
            'field_id' => 'pembacaan',
            'teks_mentah' => $teksMentah,
            'teks_normal' => $teksMentah,
            'nilai' => $teksMentah === null ? null : (float) str_replace(',', '.', $teksMentah),
            'status' => $status,
            'kotak' => ['x' => $x, 'y' => $y, 'w' => 80, 'h' => $tinggi],
        ]);
    }

    private function buatSelLain(WorksheetScan $scan, string $tabel, string $field, string $kunci, string $status, int $x, int $y): void
    {
        $scan->cells()->create([
            'kunci' => $kunci,
            'tabel_id' => $tabel,
            'baris_ke' => 0,
            'repeat_no' => 0,
            'field_id' => $field,
            'teks_mentah' => '25,0',
            'teks_normal' => '25,0',
            'status' => $status,
            'kotak' => ['x' => $x, 'y' => $y, 'w' => 80, 'h' => 30],
        ]);
    }

    /** @param  array{0: string, 1: float}|string  $j */
    private function responsGambar(array|string $j): array
    {
        if ($j === 'galat') {
            return ['error' => ['code' => 3, 'message' => 'tidak bisa dibaca']];
        }

        if ($j === 'kosong') {
            return [];
        }

        // Satu kata per potongan teks yang dipisah spasi, supaya "4 01" benar-benar dua kata.
        $kata = [];
        foreach (explode(' ', $j[0]) as $bagian) {
            $kata[] = [
                'confidence' => $j[1],
                'symbols' => array_map(static fn (string $c): array => ['text' => $c], mb_str_split($bagian)),
            ];
        }

        return ['fullTextAnnotation' => ['pages' => [[
            'blocks' => [['paragraphs' => [['words' => $kata]]]],
        ]]]];
    }

    private function fakeGoogle(int $statusVision = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'vision.googleapis.com/*' => function (Request $req) use ($statusVision) {
                if ($statusVision !== 200) {
                    return Http::response(['error' => ['code' => $statusVision, 'status' => 'INTERNAL']], $statusVision);
                }

                $balasan = [];
                foreach ($req->data()['requests'] as $_) {
                    $balasan[] = $this->responsGambar($this->jawaban[$this->urutanJawaban++] ?? ['7.5', 0.95]);
                }

                return Http::response(['responses' => $balasan]);
            },
        ]);
    }

    /** @return list<Request> */
    private function permintaanVision(): array
    {
        return Http::recorded(fn (Request $req): bool => str_contains($req->url(), 'vision.googleapis.com'))
            ->map(fn (array $pasangan): Request => $pasangan[0])
            ->values()
            ->all();
    }

    private function jumlahPanggilanVision(): int
    {
        return count($this->permintaanVision());
    }

    private function jumlahGambarTerkirim(): int
    {
        return array_sum(array_map(static fn (Request $r): int => count($r->data()['requests']), $this->permintaanVision()));
    }

    /** @param  array<string, mixed>  $payload */
    private function kirim(?User $user = null, array $payload = [], ?WorksheetScan $scan = null): TestResponse
    {
        return $this->actingAs(($user ?? $this->teknisi)->fresh())
            ->postJson('/api/worksheet-scans/'.($scan ?? $this->scan)->id.'/ocr-awan', $payload);
    }

    /** @return list<string> */
    private function kunciHasil(TestResponse $r): array
    {
        return array_column($r->json('hasil'), 'kunci');
    }

    // ------------------------------------------------------------ gerbang

    public function test_saklar_super_admin_mati_ditolak_dan_google_tidak_dihubungi(): void
    {
        $this->org->update(['settings' => ['fitur_nonaktif' => ['ocr_cloud_sel']]]);
        $this->fakeGoogle();

        $this->kirim()
            ->assertStatus(503)
            ->assertJsonPath('kode', 'fitur_dimatikan')
            ->assertJsonPath('fitur', 'ocr_cloud_sel');

        Http::assertNothingSent();
    }

    public function test_saklar_pindai_lembar_mati_juga_menutup_jalur_ini(): void
    {
        $this->org->update(['settings' => ['fitur_nonaktif' => ['pindai_lembar']]]);
        $this->fakeGoogle();

        $this->kirim()->assertStatus(503)->assertJsonPath('fitur', 'pindai_lembar');

        Http::assertNothingSent();
    }

    public function test_env_mati_ditolak_dan_google_tidak_dihubungi(): void
    {
        config(['services.google_vision.aktif' => false]);
        $this->fakeGoogle();

        $this->kirim()
            ->assertStatus(503)
            ->assertJsonPath('kode', 'ocr_cloud_belum_aktif')
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
        $log = WorksheetExtractionLog::sole();
        $this->assertSame('dimatikan', $log->status);
        $this->assertNull($log->unit_vision);
    }

    public function test_tamu_ditolak_401(): void
    {
        $this->postJson('/api/worksheet-scans/'.$this->scan->id.'/ocr-awan')->assertStatus(401);
    }

    public function test_viewer_ditolak_403_dan_google_tidak_dihubungi(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER, 'organization_id' => $this->org->id]);
        $this->fakeGoogle();

        $this->kirim($viewer)->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(0, WorksheetExtractionLog::count());
    }

    public function test_hasil_pindai_organisasi_lain_404_tanpa_menghubungi_google(): void
    {
        $orgLain = Organization::factory()->create();
        $teknisiLain = User::factory()->create(['role' => User::ROLE_TEKNISI, 'organization_id' => $orgLain->id]);
        $this->fakeGoogle();

        $this->kirim($teknisiLain)->assertStatus(404);

        Http::assertNothingSent();
        $this->assertSame(0, WorksheetExtractionLog::count());
    }

    public function test_teknisi_lain_di_organisasi_sama_403_tetapi_admin_boleh(): void
    {
        $teknisiLain = User::factory()->create(['role' => User::ROLE_TEKNISI, 'organization_id' => $this->org->id]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'organization_id' => $this->org->id]);
        $this->fakeGoogle();

        $this->kirim($teknisiLain)->assertStatus(403);
        $this->assertSame(0, $this->jumlahPanggilanVision());

        $this->kirim($admin)->assertOk();
        $this->assertGreaterThan(0, $this->jumlahPanggilanVision());
    }

    // ------------------------------------------------- yang keluar dari server

    public function test_bawaan_hanya_sel_angka_yang_perlu_dicek_yang_dikirim(): void
    {
        $this->fakeGoogle();

        $r = $this->kirim()->assertOk();

        // Dua sel angka (merah + kuning). TIDAK: hijau, kosong, jam, isian, centang.
        $this->assertSame(1, $this->jumlahPanggilanVision());
        $this->assertSame(2, $this->jumlahGambarTerkirim());
        $this->assertSame(['tabel_a|1|1|pembacaan', 'tabel_a|2|1|pembacaan'], $this->kunciHasil($r));

        $r->assertJsonPath('meta.jumlah_dikirim', 2)
            ->assertJsonPath('meta.jumlah_panggilan', 1)
            ->assertJsonPath('meta.unit', 2)
            ->assertJsonPath('meta.usulan', true)
            ->assertJsonPath('meta.dilewati_otomatis.sudah_hijau_lokal', 1)
            ->assertJsonPath('meta.dilewati_otomatis.kosong_lokal', 1)
            ->assertJsonPath('meta.dilewati_otomatis.bukan_sel_angka', 1)
            ->assertJsonPath('meta.dilewati_otomatis.isian_tidak_dikirim', 1)
            ->assertJsonPath('meta.dilewati_otomatis.centang_tidak_dikirim', 1);
    }

    public function test_kunci_sel_isian_centang_jam_dan_tak_dikenal_tidak_pernah_dikirim(): void
    {
        $this->fakeGoogle();

        $r = $this->kirim(null, ['kunci' => [
            'isian|suhu_awal',
            'centang|ya',
            'tabel_j|1|1|pembacaan',
            'identitas|nama_pelanggan',
            'tabel_a|4|1|pembacaan',
            'tabel_a|1|1|pembacaan',
        ]])->assertOk();

        $this->assertSame(1, $this->jumlahGambarTerkirim(), 'Hanya satu sel angka yang boleh keluar.');
        $this->assertSame(['tabel_a|1|1|pembacaan'], $this->kunciHasil($r));

        $alasan = array_column($r->json('dilewati'), 'alasan', 'kunci');
        $this->assertSame('isian_tidak_dikirim', $alasan['isian|suhu_awal']);
        $this->assertSame('centang_tidak_dikirim', $alasan['centang|ya']);
        $this->assertSame('bukan_sel_angka', $alasan['tabel_j|1|1|pembacaan']);
        $this->assertSame('tidak_ada_di_hasil_pindai', $alasan['identitas|nama_pelanggan']);
        $this->assertSame('kosong_lokal', $alasan['tabel_a|4|1|pembacaan']);
    }

    public function test_kunci_eksplisit_boleh_membaca_ulang_sel_hijau(): void
    {
        $this->fakeGoogle();

        $r = $this->kirim(null, ['kunci' => ['tabel_a|3|1|pembacaan']])->assertOk();

        $this->assertSame(['tabel_a|3|1|pembacaan'], $this->kunciHasil($r));
        $this->assertSame(1, $this->jumlahGambarTerkirim());
    }

    public function test_yang_dikirim_adalah_potongan_sel_bukan_foto_utuh_dan_bukan_gambar_klien(): void
    {
        $this->fakeGoogle();

        // Klien mencoba menyelipkan gambar sendiri: tidak ada kolom itu di kontrak, jadi diabaikan.
        $this->kirim(null, [
            'kunci' => ['tabel_a|1|1|pembacaan'],
            'foto' => UploadedFile::fake()->image('lembar-utuh.jpg', 1000, 1400),
            'gambar' => base64_encode('bukan-dari-kita'),
        ])->assertOk();

        $permintaan = $this->permintaanVision();
        $this->assertCount(1, $permintaan);

        $semua = $permintaan[0]->data()['requests'];
        $this->assertCount(1, $semua);
        $this->assertSame([['type' => GoogleVisionOcr::FITUR]], $semua[0]['features']);

        $isi = base64_decode((string) $semua[0]['image']['content'], true);
        $this->assertIsString($isi);
        $ukuran = getimagesizefromstring($isi);
        $this->assertNotFalse($ukuran, 'Isi yang dikirim bukan gambar yang bisa dibaca.');

        // Kotak 80x30 + margin 4 di tiap sisi. Halaman utuhnya 400x600.
        $this->assertSame([88, 38], [$ukuran[0], $ukuran[1]]);
    }

    public function test_identitas_dan_tanda_tangan_tidak_punya_jalan_keluar(): void
    {
        // Kontrak: tidak ada kolom `foto`, `gambar`, atau kotak dari klien. Kunci apa pun yang bukan baris
        // angka di hasil pindai ini berhenti di penyaring, apa pun namanya.
        $this->fakeGoogle();

        $kunci = ['nama_pelanggan', 'alamat', 'nomor_seri', 'tanda_tangan', 'tanggal_kalibrasi', 'identitas|nama'];
        $r = $this->kirim(null, ['kunci' => $kunci])->assertOk();

        Http::assertNothingSent();
        $this->assertSame([], $r->json('hasil'));
        $this->assertCount(count($kunci), $r->json('dilewati'));
        $this->assertSame(0, $r->json('meta.jumlah_panggilan'));
    }

    public function test_kunci_di_luar_batas_per_permintaan_ditolak_422(): void
    {
        config(['ocr.awan_sel.maks_sel_per_permintaan' => 2]);
        $this->fakeGoogle();

        $this->kirim(null, ['kunci' => ['a|1|1|x', 'b|1|1|x', 'c|1|1|x']])->assertStatus(422);
        $this->kirim(null, ['kunci' => []])->assertStatus(422);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ hasil

    public function test_status_tidak_pernah_hijau_walau_keyakinan_tinggi(): void
    {
        $this->jawaban = [['4.01', 1.0], ['4,00', 0.99]];
        $this->fakeGoogle();

        $r = $this->kirim()->assertOk();

        foreach ($r->json('hasil') as $item) {
            $this->assertSame('kuning', $item['status']);
            $this->assertNotSame('hijau', $item['status']);
        }
    }

    public function test_teks_mentah_dikembalikan_apa_adanya_dan_bentuk_angka_terpisah(): void
    {
        $this->jawaban = [['4.01', 0.9], ['4,00', 0.9]];
        $this->fakeGoogle();

        $r = $this->kirim()->assertOk();

        // "4.01" dari Google; bentuk angkanya "4,01" ikut terpisah dan ditandai berbeda.
        $r->assertJsonPath('hasil.0.kunci', 'tabel_a|1|1|pembacaan')
            ->assertJsonPath('hasil.0.teks_awan', '4.01')
            ->assertJsonPath('hasil.0.teks_normal', '4,01')
            ->assertJsonPath('hasil.0.dinormalkan', true)
            ->assertJsonPath('hasil.1.teks_awan', '4,00')
            ->assertJsonPath('hasil.1.teks_normal', '4,00')
            ->assertJsonPath('hasil.1.dinormalkan', false);

        $this->assertEqualsWithDelta(4.01, $r->json('hasil.0.nilai_awan'), 1e-9);
    }

    public function test_teks_yang_bukan_angka_murni_tetap_mentah_dan_merah_tanpa_koreksi(): void
    {
        // "4 01" (spasi di tengah), "1,413" (ambigu), "O" bukan nol: semuanya ditolak, tidak diperbaiki.
        $this->jawaban = [['4 01', 0.99], ['1,413', 0.99]];
        $this->fakeGoogle();

        $r = $this->kirim(null, ['kunci' => ['tabel_a|1|1|pembacaan', 'tabel_a|2|1|pembacaan']])->assertOk();

        $r->assertJsonPath('hasil.0.teks_awan', '4 01')
            ->assertJsonPath('hasil.0.status', 'merah')
            ->assertJsonPath('hasil.0.nilai_awan', null)
            ->assertJsonPath('hasil.0.teks_normal', null)
            ->assertJsonPath('hasil.1.teks_awan', '1,413')
            ->assertJsonPath('hasil.1.status', 'merah')
            ->assertJsonPath('hasil.1.nilai_awan', null);

        $this->assertNotEmpty($r->json('hasil.0.alasan'));
        $this->assertNotEmpty($r->json('hasil.1.alasan'));
    }

    public function test_huruf_di_sel_angka_tidak_diganti_jadi_digit(): void
    {
        $this->jawaban = [['4,O1', 0.99]];
        $this->fakeGoogle();

        $r = $this->kirim(null, ['kunci' => ['tabel_a|1|1|pembacaan']])->assertOk();

        $r->assertJsonPath('hasil.0.teks_awan', '4,O1')
            ->assertJsonPath('hasil.0.status', 'merah')
            ->assertJsonPath('hasil.0.nilai_awan', null);
    }

    public function test_keyakinan_rendah_atau_tidak_ada_merah_bukan_kuning(): void
    {
        $this->jawaban = [['4,01', 0.30]];
        $this->fakeGoogle();

        $r = $this->kirim(null, ['kunci' => ['tabel_a|1|1|pembacaan']])->assertOk();

        $r->assertJsonPath('hasil.0.status', 'merah')->assertJsonPath('hasil.0.alasan.0', 'keyakinan_rendah');
        $this->assertEqualsWithDelta(0.30, $r->json('hasil.0.keyakinan'), 1e-9);
    }

    public function test_sel_kosong_di_mata_google_tetap_kosong_bukan_angka_karangan(): void
    {
        $this->jawaban = ['kosong', ['4,01', 0.9]];
        $this->fakeGoogle();

        $r = $this->kirim()->assertOk();

        $r->assertJsonPath('hasil.0.status', 'kosong')
            ->assertJsonPath('hasil.0.teks_awan', '')
            ->assertJsonPath('hasil.0.nilai_awan', null)
            ->assertJsonPath('hasil.1.status', 'kuning');
    }

    public function test_satu_gambar_ditolak_google_sel_itu_merah_yang_lain_tetap_terbaca(): void
    {
        $this->jawaban = ['galat', ['4,01', 0.9]];
        $this->fakeGoogle();

        $r = $this->kirim()->assertOk();

        $r->assertJsonPath('hasil.0.status', 'merah')
            ->assertJsonPath('hasil.0.teks_awan', null)
            ->assertJsonPath('hasil.0.alasan.0', 'google_gagal_sel')
            ->assertJsonPath('hasil.1.status', 'kuning')
            ->assertJsonPath('meta.unit', 1);
    }

    public function test_nilai_sel_di_database_tidak_berubah(): void
    {
        $this->jawaban = [['9.99', 0.99], ['8,88', 0.99]];
        $this->fakeGoogle();

        $sebelum = $this->scan->cells()->orderBy('id')->get()->map->only([
            'kunci', 'teks_mentah', 'teks_normal', 'nilai', 'nilai_final', 'status', 'alasan', 'confidence_ocr', 'confidence_akhir',
        ])->all();
        $hasilSebelum = $this->scan->fresh()->hasil;

        $this->kirim()->assertOk();

        $sesudah = $this->scan->cells()->orderBy('id')->get()->map->only([
            'kunci', 'teks_mentah', 'teks_normal', 'nilai', 'nilai_final', 'status', 'alasan', 'confidence_ocr', 'confidence_akhir',
        ])->all();

        $this->assertSame($sebelum, $sesudah, 'Endpoint ini hanya mengusulkan; sel di database tidak boleh berubah.');
        $this->assertSame($hasilSebelum, $this->scan->fresh()->hasil);
    }

    // ------------------------------------------------------------ audit

    public function test_log_mencatat_metadata_tanpa_gambar_dan_tanpa_teks(): void
    {
        $this->jawaban = [['333.7', 0.9], ['444,8', 0.9]];
        $this->fakeGoogle();

        $r = $this->kirim()->assertOk();

        $log = WorksheetExtractionLog::sole();

        $this->assertSame(GoogleVisionOcr::MODEL, $log->model);
        $this->assertSame('sukses', $log->status);
        $this->assertSame($this->teknisi->id, $log->user_id);
        $this->assertSame($this->sesi->id, $log->calibration_session_id);
        $this->assertSame(2, $log->unit_vision);
        $this->assertGreaterThan(0, $log->ukuran_gambar_byte);
        $this->assertNotNull($log->durasi_ms);
        $this->assertNull($log->raw_model_response);
        $this->assertNull($log->extracted);
        $this->assertNull($log->error);

        $json = json_encode($log->toArray());
        // Teks hasil dan isi gambar (JPEG base64 selalu diawali "/9j/") tidak boleh ada di log.
        $this->assertStringNotContainsString('333', $json);
        $this->assertStringNotContainsString('444', $json);
        $this->assertStringNotContainsString('/9j/', $json);

        // Tiap sel hasil tetap sampai ke klien (sanity: log tidak menelan jawaban).
        $this->assertCount(2, $r->json('hasil'));
    }

    // ------------------------------------------------------------ batas

    public function test_batas_per_lembar_menolak_tanpa_menghubungi_google(): void
    {
        config(['services.google_vision.maks_per_sesi' => 2]);
        $this->fakeGoogle();

        foreach ([1, 1] as $unit) {
            WorksheetExtractionLog::create([
                'calibration_session_id' => $this->sesi->id,
                'user_id' => $this->teknisi->id,
                'model' => GoogleVisionOcr::MODEL,
                'status' => 'sukses',
                'unit_vision' => $unit,
                'ukuran_gambar_byte' => 100,
            ]);
        }

        $this->kirim()
            ->assertStatus(429)
            ->assertJsonPath('kode', 'batas_per_lembar')
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
        $this->assertSame(1, WorksheetExtractionLog::where('status', 'dibatasi')->count());
    }

    public function test_batas_panggilan_per_lembar_memotong_batch_dan_melaporkan_sisanya(): void
    {
        // 17 sel = 2 panggilan (16 + 1). Jatah tinggal 1 panggilan: 16 sel terbaca, 1 dilewati.
        config(['services.google_vision.maks_per_sesi' => 1]);
        $scan = $this->buatScanBanyakSel(17);
        $this->fakeGoogle();

        $r = $this->kirim(null, [], $scan)->assertOk();

        $this->assertSame(1, $this->jumlahPanggilanVision());
        $this->assertSame(16, $this->jumlahGambarTerkirim());
        $this->assertCount(16, $r->json('hasil'));
        $this->assertSame([['kunci' => 'tabel_a|17|1|pembacaan', 'alasan' => 'jatah_lembar_habis']], $r->json('dilewati'));
    }

    public function test_satu_lembar_banyak_sel_dikirim_dalam_batch_paling_banyak_16(): void
    {
        $scan = $this->buatScanBanyakSel(17);
        $this->fakeGoogle();

        $r = $this->kirim(null, [], $scan)->assertOk();

        $ukuran = array_map(static fn (Request $q): int => count($q->data()['requests']), $this->permintaanVision());
        $this->assertSame([16, 1], $ukuran);
        $this->assertCount(17, $r->json('hasil'));
        $this->assertSame(2, WorksheetExtractionLog::count(), 'Satu baris log per panggilan Google.');
    }

    public function test_batas_sel_per_permintaan_memotong_dan_melaporkan(): void
    {
        config(['ocr.awan_sel.maks_sel_per_permintaan' => 3]);
        $scan = $this->buatScanBanyakSel(5);
        $this->fakeGoogle();

        $r = $this->kirim(null, [], $scan)->assertOk();

        $this->assertSame(3, $this->jumlahGambarTerkirim());
        $alasan = array_column($r->json('dilewati'), 'alasan');
        $this->assertSame(['melebihi_batas_permintaan', 'melebihi_batas_permintaan'], $alasan);
    }

    public function test_batas_sel_per_hasil_pindai_menghentikan_pembacaan_berulang(): void
    {
        config(['ocr.awan_sel.maks_sel_per_scan' => 2]);
        $this->fakeGoogle();

        // Pertama: dua sel habis jatah scan.
        $this->kirim()->assertOk()->assertJsonPath('meta.sisa_sel_scan', 0);
        // Kedua: ditolak tanpa menghubungi Google.
        $this->kirim()
            ->assertStatus(429)
            ->assertJsonPath('kode', 'batas_sel_per_scan')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(1, $this->jumlahPanggilanVision());
    }

    public function test_pembatas_laju_per_pengguna(): void
    {
        $this->fakeGoogle();

        foreach (range(1, 4) as $_) {
            $this->kirim(null, ['kunci' => ['tabel_a|3|1|pembacaan']])->assertOk();
        }

        $this->kirim(null, ['kunci' => ['tabel_a|3|1|pembacaan']])->assertStatus(429);
        $this->assertSame(4, $this->jumlahPanggilanVision());
    }

    // ------------------------------------------------------- jalur gagal

    public function test_google_5xx_dijawab_sopan_bukan_500_dan_dicatat(): void
    {
        $this->fakeGoogle(500);

        $r = $this->kirim()
            ->assertStatus(502)
            ->assertJsonPath('kode', 'ocr_cloud_gagal')
            ->assertJsonPath('fallback_manual', true);

        // Pesan untuk pengguna tetap, tidak membawa teks galat Google.
        $this->assertStringNotContainsString('INTERNAL', (string) $r->getContent());

        $log = WorksheetExtractionLog::sole();
        $this->assertSame('gagal', $log->status);
        $this->assertSame(0, $log->unit_vision);
    }

    public function test_jaringan_putus_dijawab_sopan(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'vision.googleapis.com/*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->kirim()
            ->assertStatus(502)
            ->assertJsonPath('kode', 'ocr_cloud_gagal')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame('gagal', WorksheetExtractionLog::sole()->status);
    }

    public function test_kredensial_kosong_503_belum_disetel_dan_tidak_memakan_jatah(): void
    {
        config(['services.google_vision.kredensial' => '']);
        $this->fakeGoogle();

        $this->kirim()
            ->assertStatus(503)
            ->assertJsonPath('kode', 'ocr_cloud_belum_disetel')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(0, $this->jumlahPanggilanVision());
        $log = WorksheetExtractionLog::sole();
        $this->assertSame('belum_disetel', $log->status);
        $this->assertNull($log->unit_vision);
    }

    public function test_kegagalan_di_tengah_batch_menyisakan_hasil_sebagian(): void
    {
        // Panggilan pertama (16 sel) sukses, kedua (1 sel) gagal.
        $scan = $this->buatScanBanyakSel(17);
        $panggilan = 0;
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'vision.googleapis.com/*' => function (Request $req) use (&$panggilan) {
                $panggilan++;
                if ($panggilan === 2) {
                    return Http::response(['error' => ['code' => 500, 'status' => 'INTERNAL']], 500);
                }

                return Http::response(['responses' => array_map(
                    fn () => $this->responsGambar(['7.5', 0.95]),
                    $req->data()['requests'],
                )]);
            },
        ]);

        $r = $this->kirim(null, [], $scan)->assertOk();

        $this->assertCount(16, $r->json('hasil'));
        $r->assertJsonPath('meta.sebagian', true);
        $this->assertSame([['kunci' => 'tabel_a|17|1|pembacaan', 'alasan' => 'google_gagal']], $r->json('dilewati'));
        $this->assertSame(['sukses', 'gagal'], WorksheetExtractionLog::orderBy('id')->pluck('status')->all());
    }

    public function test_batas_kegagalan_per_lembar_menghentikan_google_dan_menulis_jejak_admin(): void
    {
        // Dua kegagalan sudah tercatat; yang ketiga datang dari panggilan ini.
        foreach ([1, 2] as $_) {
            WorksheetExtractionLog::create([
                'calibration_session_id' => $this->sesi->id,
                'user_id' => $this->teknisi->id,
                'model' => GoogleVisionOcr::MODEL,
                'status' => 'gagal',
                'error' => 'Google Vision menolak permintaan (HTTP 500).',
                'unit_vision' => 0,
                'ukuran_gambar_byte' => 100,
            ]);
        }
        $this->fakeGoogle(500);

        $this->kirim()->assertStatus(502);
        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count());

        $this->kirim()
            ->assertStatus(429)
            ->assertJsonPath('kode', 'gagal_berulang_per_lembar')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(1, $this->jumlahPanggilanVision(), 'Google dihubungi lagi sesudah batas kegagalan.');
        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count(), 'Jejak admin harus idempoten.');
    }

    // ------------------------------------------------------------ citra

    public function test_citra_tidak_tersimpan_ditolak_422_tanpa_menghubungi_google(): void
    {
        $this->scan->update(['citra_warp_path' => 'worksheet-scans/tidak-ada.jpg']);
        $this->fakeGoogle();

        $this->kirim()
            ->assertStatus(422)
            ->assertJsonPath('kode', 'citra_tidak_tersimpan')
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
    }

    public function test_citra_formulir_asli_yang_bukan_halaman_utuh_tidak_dipotong(): void
    {
        // Rasio citra 400x600 (0,667) jauh dari rasio acuan formulir (0,5): potongan akan meleset.
        $this->scan->update(['hasil' => [
            'kertas' => 'asli',
            'ukuran_referensi' => ['w' => 1000, 'h' => 2000],
            'tabel' => $this->scan->hasil['tabel'],
        ]]);
        $this->fakeGoogle();

        $r = $this->kirim(null, ['kunci' => ['tabel_a|1|1|pembacaan']])->assertOk();

        Http::assertNothingSent();
        $this->assertSame([['kunci' => 'tabel_a|1|1|pembacaan', 'alasan' => 'citra_bukan_halaman_utuh']], $r->json('dilewati'));
    }

    // ------------------------------------------------- jalur lama tidak terganggu

    public function test_jalur_lama_tetap_jalan_saat_google_gagal(): void
    {
        $this->fakeGoogle(500);

        $this->kirim()->assertStatus(502);

        // Hasil pindai tetap terbaca dan citra potongannya tetap tersedia: teknisi bisa lanjut manual.
        $this->actingAs($this->teknisi->fresh())
            ->getJson('/api/worksheet-scans/'.$this->scan->id)
            ->assertOk();

        $this->actingAs($this->teknisi->fresh())
            ->get('/api/worksheet-scans/'.$this->scan->id.'/sel/'.rawurlencode('tabel_a|1|1|pembacaan').'/crop')
            ->assertOk();
    }

    public function test_jalur_foto_utuh_tidak_berubah_dan_tidak_dipakai_endpoint_ini(): void
    {
        $this->fakeGoogle();

        // Endpoint sel tidak menyentuh jalur foto utuh: yang terkirim hanya potongan 88x38.
        $this->kirim(null, ['kunci' => ['tabel_a|1|1|pembacaan']])->assertOk();

        $this->actingAs($this->teknisi->fresh())->postJson('/api/ocr/halaman', [
            'foto' => UploadedFile::fake()->image('lembar.jpg', 1000, 1400),
            'calibration_session_id' => $this->sesi->id,
        ])->assertOk();

        $panggilan = $this->permintaanVision();
        $this->assertCount(2, $panggilan);
        $this->assertSame(
            [88, 38],
            array_slice(getimagesizefromstring((string) base64_decode((string) $panggilan[0]->data()['requests'][0]['image']['content'])), 0, 2),
        );
        $this->assertGreaterThan(
            88 * 38,
            strlen((string) base64_decode((string) $panggilan[1]->data()['requests'][0]['image']['content'])),
            'Jalur foto utuh mengirim foto utuh; jalur sel mengirim potongan kecil.',
        );
    }
}
