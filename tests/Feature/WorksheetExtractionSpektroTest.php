<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pindai foto buat lembar yang BENTUKNYA beda: Spectrophotometer.
 *
 * Lembar pH — yang jadi cetakan seluruh jalur ini — punya standar sebagai KOLOM,
 * Repeat sebagai BARIS, dan tiap sel isinya sepasang angka (pembacaan + suhu
 * °C). Lembar Spectrophotometer melanggar dua-duanya: tiap sel cuma SATU angka
 * (nggak ada kolom °C sama sekali; suhu ruang dicatat sekali di Env. Condition)
 * dan standarnya turun ke bawah sementara Repeat X1..X3 berjajar ke kanan.
 *
 * Sebelum ini bedanya nggak pernah disebut ke model: prompt-nya bilang "tiap sel
 * isinya dua angka" dan skema JSON-nya MEWAJIBKAN `suhu` per baris. Modelnya
 * nurut — dia ngarang kolom suhu, atau memampatkan tiga Repeat jadi satu baris —
 * dan yang nyampe teknisi cuma "gagal baca, isi manual". Yang dikunci di sini
 * bukan kalimat prompt-nya, tapi apa yang bikin lembarnya kepakai: bentuk yang
 * nyampe HP tetap satu entri per Repeat, dan angkanya nggak geser.
 */
class WorksheetExtractionSpektroTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/raw-measurements/extract-from-photo';

    private User $teknisi;

    private CalibrationSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();

        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'spektro'])->id,
            'nama_alat' => 'Spectrophotometer',
            'satuan' => 'nm',
        ]);

        $this->sesi = CalibrationSession::factory()->create([
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $alat->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);
    }

    /**
     * Fake respons bentuk KERTAS: 3 baris standar × 3 kolom Repeat, satu angka
     * per sel. Baris kedua sengaja cuma 2 kolom — Repeat ketiganya kosong di
     * foto, dan itu HARUS tetap kosong, bukan digeser dari tetangganya.
     */
    private function fakeLembarSpektro(): void
    {
        Config::set('services.anthropic.api_key', 'test-key');

        $isi = json_encode([
            'standard_value' => [279.6, 287.7, 334.0],
            'baris' => [
                ['ph' => [279.4, 279.5, 279.4], 'ph_keyakinan' => ['high', 'high', 'high']],
                ['ph' => [287.6, 287.7], 'ph_keyakinan' => ['high', 'medium']],
                ['ph' => [334.1, 334.0, 334.2], 'ph_keyakinan' => ['high', 'high', 'low']],
            ],
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 900, 'output_tokens' => 120, 'cache_read_input_tokens' => 0],
            'content' => [['type' => 'text', 'text' => $isi]],
        ])]);
    }

    /** @param array<string, mixed> $payload */
    private function kirim(array $payload = []): TestResponse
    {
        return $this->actingAs($this->teknisi)->postJson(self::URL, array_merge([
            'foto' => UploadedFile::fake()->image('lembar-spektro.jpg', 1000, 1400),
            'satuan' => 'nm',
            'jumlah_titik' => 3,
            'jumlah_pengulangan' => 3,
            'calibration_session_id' => $this->sesi->id,
            'kolom_suhu' => false,
            'standar_di_baris' => true,
        ], $payload));
    }

    /**
     * Tabel yang standarnya turun ke bawah diputar jadi satu entri per Repeat.
     *
     * Yang dijaga: HP nggak perlu tau kertasnya bentuknya beda. Yang dia terima
     * tetap `baris[0] = Repeat 1`, isinya pembacaan tiap standar kiri→kanan —
     * sama persis kayak lembar pH.
     */
    public function test_standar_di_baris_diputar_jadi_satu_entri_per_repeat(): void
    {
        $this->fakeLembarSpektro();

        $r = $this->kirim()->assertOk();

        // 3 kolom Repeat di kertas → 3 entri, bukan 3 baris standar.
        $r->assertJsonCount(3, 'baris');

        // Repeat 1 = kolom X1 dari ketiga baris standar, urut atas→bawah.
        $r->assertJsonPath('baris.0.ph', [279.4, 287.6, 334.1]);
        // 334.0 balik sebagai `334` — JSON nggak nyimpen nol di belakang koma.
        $r->assertJsonPath('baris.1.ph', [279.5, 287.7, 334]);

        // Nilai standarnya ikut dikirim, urut sama kayak isi tiap `ph`.
        $r->assertJsonPath('standard_value', [279.6, 287.7, 334]);
    }

    /**
     * Sel kosong tetap di slotnya — nggak dirapatin.
     *
     * Baris standar 287,7 cuma punya 2 kolom di foto. Di Repeat 3, slot standar
     * kedua HARUS null. Kalau digeser, pembacaan 334,2 mendarat di panjang
     * gelombang 287,7 di sertifikat, dan nggak ada satu pun yang keliatan salah.
     */
    public function test_sel_kosong_nggak_digeser_waktu_diputar(): void
    {
        $this->fakeLembarSpektro();

        $this->kirim()
            ->assertOk()
            ->assertJsonPath('baris.2.ph', [279.4, null, 334.2]);
    }

    /**
     * Lembar tanpa kolom suhu balik dengan `suhu` KOSONG, bukan berisi null.
     *
     * Bedanya kelihatan di HP: array null sepanjang barisnya bikin sel-sel suhu
     * muncul sebagai kolom yang nggak kebaca, dan `perlu_dicek` nyala terus
     * padahal nggak ada satu pun sel suhu di kertasnya.
     */
    public function test_tanpa_kolom_suhu_arraynya_kosong(): void
    {
        $this->fakeLembarSpektro();

        $r = $this->kirim()->assertOk();

        $r->assertJsonPath('baris.0.suhu', []);
        $r->assertJsonPath('baris.0.suhu_keyakinan', []);
    }

    /**
     * Prompt & skema yang dikirim ke model ikut bentuk kertasnya.
     *
     * Ini yang bikin modelnya berhenti ngarang: dia nggak lagi disuruh baca
     * kolom suhu yang nggak ada, dan skema-nya nggak lagi MEWAJIBKAN `suhu`
     * yang cuma bisa dipenuhi dengan mengarang.
     */
    public function test_prompt_dan_skema_ikut_bentuk_kertas(): void
    {
        $this->fakeLembarSpektro();

        $this->kirim()->assertOk();

        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();

            return str_contains($body, 'one row per STANDARD')
                && str_contains($body, 'NO temperature column')
                && ! str_contains($body, '"suhu"');
        });
    }

    /**
     * Aplikasi lama yang nggak ngirim penanda bentuk TETAP kebaca benar.
     *
     * Aplikasi yang udah kepasang di HP teknisi nggak tau soal `kolom_suhu` /
     * `standar_di_baris`, dan nggak bisa dipaksa update sebelum kalibrasi
     * berikutnya jalan. Bentuknya ditebak dari alat di sesinya — yang emang
     * udah dikirim sejak dulu.
     */
    public function test_bentuk_ditebak_dari_alat_di_sesi_kalau_penandanya_nggak_dikirim(): void
    {
        $this->fakeLembarSpektro();

        $r = $this->actingAs($this->teknisi)->postJson(self::URL, [
            'foto' => UploadedFile::fake()->image('lembar-spektro.jpg', 1000, 1400),
            'calibration_session_id' => $this->sesi->id,
        ])->assertOk();

        $r->assertJsonCount(3, 'baris');
        $r->assertJsonPath('baris.0.ph', [279.4, 287.6, 334.1]);
        $r->assertJsonPath('baris.0.suhu', []);
    }
}
