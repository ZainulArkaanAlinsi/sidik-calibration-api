<?php

namespace Tests\Concerns;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\CitraSintetis;

/**
 * Bahan bersama tes `POST /api/ocr/sel-awan` (`OcrSelUnggahTest`, `OcrSelUnggahBatasTest`).
 *
 * Google SELALU di-fake (`Http::fake` + `Http::preventStrayRequests`): tes tidak boleh memanggil layanan
 * sungguhan. Unggahan dikirim sebagai multipart (parameter + berkas) lewat `call()`, bentuk yang sama dengan
 * yang dikirim aplikasi, bukan JSON.
 *
 * Kelas pemakai harus memakai `MembuatKunciUji` dan `RefreshDatabase`, dan memanggil `siapkanOcrSelUnggah()`
 * dari `setUp()`.
 */
trait MenyiapkanOcrSelUnggah
{
    private Organization $org;

    private User $teknisi;

    private Equipment $alat;

    private CalibrationSession $sesi;

    /**
     * Jawaban Google per gambar, dipakai berurutan lintas permintaan. Bentuk: `[teks, keyakinan]`,
     * `'kosong'` (Google tidak melihat tulisan), atau `'galat'` (Google menolak gambar itu).
     * Bila habis, gambar dijawab `7.5` dengan keyakinan 0,95.
     *
     * @var list<array{0: string, 1: float}|string>
     */
    private array $jawaban = [];

    private int $urutanJawaban = 0;

    protected function siapkanOcrSelUnggah(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD tidak ada: bahan uji citra tidak bisa dibuat di mesin seperti ini.');
        }

        Cache::flush();
        Storage::fake('local');
        Http::preventStrayRequests();

        [, $pem] = $this->bangkitkanKunciUji();

        config([
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
            'ocr.awan_sel.maks_sel_per_permintaan' => 48,
            'ocr.awan_sel.maks_byte_potongan' => 307_200,
            'ocr.awan_sel.maks_piksel_potongan' => 500_000,
            'ocr.awan_sel.maks_sel_unggah_per_lembar' => 160,
            'ocr.awan_sel.maks_sel_unggah_tanpa_sesi_per_hari' => 240,
        ]);

        $this->org = Organization::factory()->create();
        $this->teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI, 'organization_id' => $this->org->id]);

        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'nama_alat' => 'pH Meter', 'satuan' => 'pH',
        ]);
        $this->sesi = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $this->alat->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);
    }

    // ------------------------------------------------------------ kunci sel

    /**
     * Kunci sel angka templat `ph_meter` (semua barisnya bertipe `angka`; lihat `ph_meter-v1.json`).
     * Diambil dari templat yang disusun server, supaya tes tidak menyalin daftar kunci yang bisa basi.
     *
     * @return list<string>
     */
    private function kunciAngka(int $jumlah): array
    {
        $templat = app(TemplateLembarKerja::class)->untukKode('ph_meter', $this->alat);
        $this->assertIsArray($templat);

        $kunci = array_keys($templat['sel']);
        $this->assertGreaterThanOrEqual($jumlah, count($kunci), 'Templat ph_meter tidak punya sel sebanyak itu.');

        return array_map('strval', array_slice($kunci, 0, $jumlah));
    }

    /**
     * Kunci sel di baris bertipe `jam` pada templat Autoklaf (baris Time), dan satu kunci sel angka sebagai
     * pembanding.
     *
     * @return array{jam: string, angka: string}
     */
    private function kunciAutoklaf(): array
    {
        $templat = app(TemplateLembarKerja::class)->untukKode('autoclave', $this->alat);
        $this->assertIsArray($templat);

        $tipe = [];
        foreach ($templat['tabel'] as $tabel) {
            foreach ($tabel['baris'] as $baris) {
                $tipe[$tabel['tabel_id'].'|'.$baris['baris_ke']] = $baris['tipe'];
            }
        }

        $jam = null;
        $angka = null;
        foreach ($templat['sel'] as $kunci => $sel) {
            $jenis = $tipe[$sel['tabel_id'].'|'.$sel['baris_ke']] ?? null;
            $jam ??= $jenis === 'jam' ? (string) $kunci : null;
            $angka ??= $jenis === 'angka' ? (string) $kunci : null;
        }

        $this->assertNotNull($jam, 'Templat Autoklaf harus punya baris jam.');
        $this->assertNotNull($angka, 'Templat Autoklaf harus punya baris angka.');

        return ['jam' => $jam, 'angka' => $angka];
    }

    // ------------------------------------------------------------ Google palsu

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

    // ------------------------------------------------------------ pengirim

    /** JPEG sungguhan kecil (60x30), potongan sel yang wajar. */
    private function potonganSah(): string
    {
        return CitraSintetis::jpegSungguhan(60, 30);
    }

    /**
     * Kirim unggahan multipart ke `POST /api/ocr/sel-awan`.
     *
     * @param  list<string|array{kunci: string, isi?: ?string}>  $sel  string = potongan JPEG sah; array dengan
     *                                                                 `isi` = isi berkas apa adanya, `isi` null = tanpa berkas
     * @param  array<string, mixed>  $badan  parameter tambahan atau penimpa (`template_id`, `kertas`, ...)
     */
    private function kirim(array $sel, array $badan = [], ?User $user = null, bool $denganSesi = true): TestResponse
    {
        $parameter = array_merge(
            ['template_id' => 'ph_meter'],
            $denganSesi ? ['calibration_session_id' => $this->sesi->id] : [],
            $badan,
        );

        foreach (array_values($sel) as $i => $s) {
            $kunci = is_array($s) ? $s['kunci'] : $s;
            $isi = is_array($s) && array_key_exists('isi', $s) ? $s['isi'] : $this->potonganSah();

            $parameter['sel'][$i] = ['kunci' => $kunci];

            if ($isi !== null) {
                $parameter['sel'][$i]['gambar'] = UploadedFile::fake()->createWithContent("sel-{$i}.bin", $isi);
            }
        }

        // Berkas SENGAJA ditaruh di dalam `$parameter`, bukan di argumen `$files` milik `call()`: `call()`
        // menggabung `$files` dengan hasil ekstraksi berkas dari `$parameter` lewat `array_merge`, dan
        // kerangka kosong `sel => [n => []]` dari ekstraksi itu menimpa `sel` milik `$files`. Lewat jalur
        // ekstraksi, berkas dipindah ke bag berkas dengan struktur bersarang yang utuh.
        return $this->actingAs(($user ?? $this->teknisi)->fresh())
            ->call('POST', '/api/ocr/sel-awan', $parameter, [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    /** @return list<string> */
    private function kunciHasil(TestResponse $r): array
    {
        return array_column($r->json('hasil'), 'kunci');
    }

    /** @return array<string, string> kunci => alasan */
    private function alasanDilewati(TestResponse $r): array
    {
        return array_column($r->json('dilewati'), 'alasan', 'kunci');
    }
}
