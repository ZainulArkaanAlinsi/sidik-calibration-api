<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sesi Autoklaf nyampe sampai lembar yang dipegang pelanggan.
 *
 * ## Dua kegagalan yang dijaga di sini
 *
 * **1. Sesi Autoklaf nggak bisa di-approve.** `CalibrationValidator` menolak
 * tiap sesi yang `uncertainty_calculations`-nya kosong dengan error
 * `titik_kosong` — dan sesi Autoklaf SELALU kosong di situ, karena olah datanya
 * lahir di `AutoclaveCalculator` dan dibekukan di kolom `hasil_autoclave`.
 * Bentuk gagalnya rapi: sesi tersimpan lengkap, `U95` suhu benar 0,4419439,
 * lalu `boleh_terbit: false` dan sertifikatnya nggak pernah terbit.
 *
 * **2. Sertifikatnya kosong.** Tabel hasil dibangun dari
 * `uncertainty_calculations`, jadi Autoklaf mendarat di lembar tanpa satu angka
 * pun. Master `Autoclave_CSV/SERTIFIKAT.csv` mencetak TIGA bagian yang
 * strukturnya beda satu sama lain — A) Sebaran Suhu, B) Kinerja, C) Tekanan —
 * dan nggak ada satu pun yang muat di tabel `Standard | UUT | Correction`.
 *
 * Diadu ke HTML yang BENERAN dirender, bukan ke snapshot: jarak antara
 * "angkanya benar di DB" dan "angkanya kecetak" itu persis tempat dua bug
 * sebelumnya di repo ini sembunyi.
 */
class AutoclaveCertificateTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private User $admin;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
        $this->admin = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_ADMIN,
        ]);
        $customer = Customer::factory()->create(['organization_id' => $org->id]);
        $category = EquipmentCategory::factory()->create(['organization_id' => $org->id]);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'equipment_category_id' => $category->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
    }

    /**
     * Angka input disalin dari master `Autoclave_CSV/INPUT_DATA.csv` baris
     * 36-39: tiga disk x lima titik waktu pada set point 121 °C.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'suhu_awal' => 24.4,
            'suhu_akhir' => 24.5,
            'kelembaban_awal' => 55,
            'kelembaban_akhir' => 56,
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
    }

    private function sesiTersimpan(): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload())
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /** Sesi tanpa satu pun titik hasil hitung TETAP boleh terbit — itu bentuk yang benar buat alat ini. */
    public function test_sesi_autoclave_lolos_approve_walau_tanpa_titik(): void
    {
        $sesi = $this->sesiTersimpan();

        $this->assertTrue($sesi->uncertaintyCalculations()->doesntExist());
        $this->assertTrue($sesi->adalahAutoclave());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();

        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        Storage::disk('arsip')->assertExists($sertifikat->pdf_path);
    }

    /** Snapshot beku memuat hasil olah datanya, bukan tabel titik yang kosong. */
    public function test_snapshot_bawa_hasil_autoclave(): void
    {
        $sesi = $this->sesiTersimpan();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        $snapshot = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail()->snapshot;

        $this->assertSame([], $snapshot['hasil'], 'Tabel titik memang kosong buat alat ini.');

        $ac = $snapshot['autoclave'];

        // Diadu ke master: Uc suhu 0,2241822 · k 1,9713602 · U 0,4419439.
        $this->assertEqualsWithDelta(0.4419439029528431, $ac['suhu']['u95'], 1e-9);
        $this->assertEqualsWithDelta(1.9713602363081708, $ac['suhu']['k'], 1e-9);
        $this->assertCount(3, $ac['suhu']['sensor']);

        // Keseragaman 0,464 — BUKAN 0,466 yang tercetak di sertifikat lama.
        // Master menariknya dari sel yang saling bertentangan; lihat docblock
        // `AutoclaveCalculator`. Keputusan final pemilik repo.
        $this->assertEqualsWithDelta(0.464, $ac['suhu']['keseragaman'], 1e-9);
        $this->assertEqualsWithDelta(0.0059, $ac['tekanan']['u95'], 1e-9);
    }

    /**
     * Sertifikat Autoklaf muat SATU halaman.
     *
     * Lembar sertifikat lab dirancang satu halaman — `@page` di blade dipatok
     * ketat persis buat itu, dan `Page : 1 of 1` dicetak di kepalanya. Autoklaf
     * hampir membatalkannya: mode `padat` dulu cuma dipicu jumlah BARIS hasil
     * (> 12), dan tabel hasil Autoklaf KOSONG — nol baris — padahal lembarnya
     * justru paling tinggi dari semua alat: tiga tabel berjudul plus dua
     * kalimat faktor cakupan.
     *
     * Akibatnya blok tanda tangan & kode dokumen kedorong ke halaman dua,
     * sementara kepalanya tetap nulis `1 of 1`. Dokumen terkendali yang
     * membantah dirinya sendiri.
     *
     * Dihitung dari PDF yang BENERAN dihasilkan dompdf, bukan dari tinggi HTML
     * yang cuma bisa ditebak.
     */
    public function test_sertifikat_muat_satu_halaman(): void
    {
        $sesi = $this->sesiTersimpan();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();
        $pdf = Storage::disk('arsip')->get($sertifikat->pdf_path);

        // `/Type /Page` yang BUKAN `/Type /Pages` — yang kedua itu simpul induk,
        // selalu ada satu, dan bikin hitungan naif selalu kelebihan satu.
        $halaman = preg_match_all('~/Type\s*/Page[^s]~', $pdf);

        $this->assertSame(1, $halaman, "Sertifikat Autoklaf jadi {$halaman} halaman, padahal kepalanya nulis 'Page : 1 of 1'.");
    }

    /** Ketiga bagian beneran kecetak, lengkap dengan angkanya. */
    public function test_lembar_cetak_bawa_bagian_a_b_c(): void
    {
        $sesi = $this->sesiTersimpan();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();
        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();

        foreach (['A) SEBARAN SUHU', 'B) KINERJA AUTOCLAVE', 'C) TEKANAN'] as $judul) {
            $this->assertStringContainsString($judul, $html, "Bagian {$judul} nggak kecetak.");
        }

        // Bagian A: baris Terukur & Koreksi, plus U95 sekali buat seluruh blok.
        $this->assertStringContainsString('Terukur', $html);
        $this->assertStringContainsString('Koreksi', $html);
        $this->assertStringContainsString('0,44', $html);

        // Bagian C: desimalnya IKUT FORMAT SEL master, dan formatnya beda per
        // kolom — `Master Olah Data_Autoclave.xlsm` sheet SERTIFIKAT:
        //
        //   B39 UUT / D39 Standard / K39 Correction = `0.000`  -> 3 desimal
        //   P39 & K42 U95                           = `0.0000` -> 4 desimal
        //
        // Dulu keempat kolom dipukul rata 4 desimal dengan alasan "3 desimal
        // mbulatin U95 0,0059 jadi 0,006". Alasannya benar buat U95 — dan
        // U95-nya memang tetap 4 desimal — tapi kebablasan ke tiga kolom
        // lainnya: koreksi `0,0111` kecetak di tempat master nulis `0,011`.
        // Waktu itu workbook-nya belum ada di tangan, jadi formatnya ditebak.
        $this->assertStringContainsString('0,0059', $html);
        $this->assertStringContainsString('0,011', $html);
        $this->assertStringNotContainsString('0,0111', $html);

        // `k` di master diformat `0` (sel P33 & P43) — yang tercetak `2`,
        // bukan `1,97`/`2,09`.
        $this->assertStringNotContainsString('1,97', $html);
        $this->assertStringNotContainsString('2,09', $html);

        // Tabel empat kolom milik tujuh alat lain NGGAK ikut kegambar.
        $this->assertStringNotContainsString('Uncertainty U', $html);
    }
}
