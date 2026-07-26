<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\PengingatJatuhTempo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Badge status sertifikat standar + banner di kepala lembar kerja
 * (docs/permintaan-worksheet-ph.md §2.1).
 *
 * Worksheet aslinya nampilin banner merah "ONE OR MORE STANDARD EXPIRED" plus
 * badge per standar (VALID / WARNING). `masih_berlaku` yang udah ada nggak cukup:
 * itu cuma bool, sementara worksheet-nya punya state TENGAH — "mau kadaluarsa".
 *
 * Yang penting dijaga di sini: **ambangnya dari backend, bukan dari mobile.**
 * Kalau HP nentuin sendiri, dia bisa nampilin VALID buat standar yang nanti
 * ditolak backend waktu approve — dan teknisi baru tahu setelah kerjaannya kelar.
 */
class StatusStandarTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->teknisi = User::factory()->create();
    }

    private function standar(?string $berlakuSampai, string $nama = 'pH Buffer 7'): Standard
    {
        return Standard::factory()->create([
            'nama' => $nama,
            'berlaku_sampai' => $berlakuSampai,
        ]);
    }

    /** @param array<string, mixed> $atribut */
    private function sesiDengan(array $atribut): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'satuan' => 'pH', 'toleransi' => 0.05,
        ]);

        return CalibrationSession::factory()->create([
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $alat->id,
            ...$atribut,
        ]);
    }

    // ------------------------------------------------- badge per standar

    public function test_standar_masih_lama_statusnya_valid(): void
    {
        $this->standar(now()->addMonths(6)->toDateString());

        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonPath('data.0.status_kalibrasi', Standard::STATUS_VALID)
            ->assertJsonPath('data.0.masih_berlaku', true);
    }

    /** Default ambangnya 30 hari — 20 hari lagi masuk WARNING. */
    public function test_standar_mendekati_kadaluarsa_statusnya_warning(): void
    {
        $this->standar(now()->addDays(20)->toDateString());

        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonPath('data.0.status_kalibrasi', Standard::STATUS_WARNING)
            // Masih berlaku — WARNING itu peringatan, bukan penolakan.
            ->assertJsonPath('data.0.masih_berlaku', true)
            ->assertJsonPath('data.0.hari_menuju_kadaluarsa', 20);
    }

    public function test_standar_lewat_masa_berlaku_statusnya_expired(): void
    {
        $this->standar(now()->subDays(12)->toDateString());

        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonPath('data.0.status_kalibrasi', Standard::STATUS_EXPIRED)
            ->assertJsonPath('data.0.masih_berlaku', false)
            // Bertanda: negatif = udah lewat, biar layar bisa bilang "lewat 12 hari".
            ->assertJsonPath('data.0.hari_menuju_kadaluarsa', -12);
    }

    public function test_standar_tanpa_tanggal_berlaku_dianggap_valid(): void
    {
        $this->standar(null);

        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonPath('data.0.status_kalibrasi', Standard::STATUS_VALID)
            // null, bukan 0 — "nggak punya tanggal" beda dari "habis hari ini".
            ->assertJsonPath('data.0.hari_menuju_kadaluarsa', null);
    }

    /**
     * Standar yang `berlaku_sampai`-nya HARI INI dihitung **expired**, sisa hari `0`.
     *
     * Kelihatan aneh dibaca sekilas ("0 hari lagi" tapi udah expired), tapi ini
     * SENGAJA dibikin konsisten sama `masihBerlaku()` yang udah lama jalan —
     * aturan yang sama yang nolak `POST /calibrations` dengan `422`. Kalau badge
     * bilang WARNING sementara backend nolak sesinya, teknisi kerja sia-sia, dan
     * itu justru masalah yang mau diberesin permintaan §2.1 ini.
     *
     * Konsekuensinya buat frontend: **`status_kalibrasi` yang jadi pegangan,
     * bukan `hari_menuju_kadaluarsa`.** Angka harinya buat teks ("lewat 12 hari"),
     * bukan buat nurunin status sendiri.
     *
     * Apakah sertifikat mestinya masih sah di tanggal terakhirnya itu keputusan
     * domain (lab/asesor), bukan keputusan kode — kalau nanti diputuskan iya,
     * yang diubah `masihBerlaku()`, dan badge ini ikut sendiri.
     */
    public function test_habis_hari_ini_dihitung_expired_konsisten_sama_masih_berlaku(): void
    {
        $this->standar(now()->toDateString());

        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonPath('data.0.hari_menuju_kadaluarsa', 0)
            ->assertJsonPath('data.0.masih_berlaku', false)
            ->assertJsonPath('data.0.status_kalibrasi', Standard::STATUS_EXPIRED);
    }

    // ------------------------------------------------- ambang per organisasi

    /**
     * Ambangnya ngikut setting organisasi, bukan angka mati di kode.
     *
     * Ini yang bikin "satu sumber" beneran jalan: kalau admin nyetel 60 hari,
     * badge di mobile ikut geser tanpa mobile ngerti soal ambangnya.
     */
    public function test_ambang_warning_ngikut_setting_organisasi(): void
    {
        $this->standar(now()->addDays(45)->toDateString());

        // Default 30 hari → 45 hari lagi masih VALID.
        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertJsonPath('data.0.status_kalibrasi', Standard::STATUS_VALID);

        $this->org->update(['settings' => [Organization::KEY_AMBANG_HARI => 60]]);

        // Ambang 60 hari → 45 hari lagi jadi WARNING.
        $this->actingAs($this->teknisi->fresh())
            ->getJson('/api/standards')
            ->assertJsonPath('data.0.status_kalibrasi', Standard::STATUS_WARNING);
    }

    /** Ambangnya sama sama yang dipakai reminder alat — satu knob, bukan dua. */
    public function test_ambang_dipakai_bareng_sama_reminder_jatuh_tempo(): void
    {
        $this->assertSame(
            Organization::DEFAULT_AMBANG_HARI,
            PengingatJatuhTempo::DEFAULT_HARI,
        );
        $this->assertSame(
            Organization::KEY_AMBANG_HARI,
            PengingatJatuhTempo::KEY_SETTING,
        );
    }

    // ------------------------------------------------- banner di sesi

    public function test_semua_standar_aman_bannernya_null(): void
    {
        $sesi = $this->sesiDengan([
            'standard_id' => $this->standar(now()->addYear()->toDateString())->id,
        ]);

        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.status_standar.ringkasan', Standard::STATUS_VALID)
            // null = nggak usah nampilin banner sama sekali.
            ->assertJsonPath('data.status_standar.pesan', null);
    }

    /**
     * Satu standar kadaluarsa bikin banner merah — walau yang lain masih valid.
     *
     * Sengaja diuji pakai thermohygro yang expired sementara standar utamanya
     * aman: bannernya nggak boleh kalem cuma karena mayoritas standar oke. Satu
     * yang kadaluarsa udah cukup nahan penerbitan sertifikat.
     */
    public function test_satu_standar_expired_bikin_banner_expired(): void
    {
        $sesi = $this->sesiDengan([
            'standard_id' => $this->standar(now()->addYear()->toDateString(), 'pH Buffer 7')->id,
            'thermohygro_standard_id' => $this->standar(
                now()->subDay()->toDateString(),
                'Termometer & Sensor Std.',
            )->id,
        ]);

        $res = $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.status_standar.ringkasan', Standard::STATUS_EXPIRED)
            ->assertJsonPath('data.status_standar.pesan', 'ONE OR MORE STANDARD EXPIRED');

        // Rinciannya ikut, biar mobile bisa nandain badge per baris.
        $status = collect($res->json('data.status_standar.standar'))->keyBy('nama');
        $this->assertSame(Standard::STATUS_VALID, $status['pH Buffer 7']['status']);
        $this->assertSame(Standard::STATUS_EXPIRED, $status['Termometer & Sensor Std.']['status']);
    }

    public function test_standar_mendekati_kadaluarsa_bikin_banner_warning(): void
    {
        $sesi = $this->sesiDengan([
            'standard_id' => $this->standar(now()->addDays(10)->toDateString())->id,
        ]);

        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.status_standar.ringkasan', Standard::STATUS_WARNING)
            ->assertJsonPath('data.status_standar.pesan', 'ONE OR MORE STANDARD NEAR EXPIRY');
    }

    /** Standar yang kepakai dari dua jalur cukup dihitung sekali. */
    public function test_standar_yang_sama_nggak_dihitung_dobel(): void
    {
        $standar = $this->standar(now()->addYear()->toDateString());

        $sesi = $this->sesiDengan([
            'standard_id' => $standar->id,
            'thermohygro_standard_id' => $standar->id,
        ]);

        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.status_standar.standar');
    }

    public function test_sesi_tanpa_standar_sama_sekali_tetap_valid(): void
    {
        $sesi = $this->sesiDengan(['standard_id' => null]);

        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.status_standar.ringkasan', Standard::STATUS_VALID)
            ->assertJsonPath('data.status_standar.standar', []);
    }
}
