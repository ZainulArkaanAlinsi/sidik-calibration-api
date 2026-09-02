<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Jejak perubahan data (arsitektur-desktop-database.md Keputusan 4).
 *
 * Alasannya bukan kerapian: ISO/IEC 17025 minta rekaman bisa ditelusuri — siapa
 * ngubah apa, kapan, dari nilai berapa ke berapa. Menu "Kelola Data" ngasih admin
 * keleluasaan kayak phpMyAdmin; tabel ini yang bikin keleluasaan itu boleh ada.
 *
 * Empat hal yang paling dijaga di sini:
 * 1. pencatatannya OTOMATIS, lewat model event — bukan ditempel per endpoint
 * 2. `password` nggak pernah masuk riwayat
 * 3. riwayat lab lain nggak kebaca
 * 4. baris audit nggak bisa diubah atau dihapus
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/audit-logs';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function standar(): Standard
    {
        return Standard::factory()->create(['nama' => 'pH Buffer 7']);
    }

    // ------------------------------------------------- pencatatan otomatis

    public function test_bikin_data_kecatat(): void
    {
        $standar = $this->standar();

        $log = AuditLog::where('entity_type', 'standards')->where('entity_id', $standar->id)->first();

        $this->assertNotNull($log, 'Bikin standar harus kecatat tanpa disuruh.');
        $this->assertSame(AuditLog::ACTION_DIBIKIN, $log->action);
        $this->assertNull($log->old_data);
        $this->assertSame('pH Buffer 7', $log->new_data['nama']);
    }

    /** Yang kecatat cuma kolom yang BERUBAH, bukan seluruh baris. */
    public function test_ubah_data_cuma_nyatet_kolom_yang_berubah(): void
    {
        $standar = $this->standar();
        AuditLog::query()->delete();

        $standar->update(['nama' => 'pH Buffer 7 (kalibrasi ulang)']);

        $log = AuditLog::latest('id')->first();

        $this->assertSame(AuditLog::ACTION_DIUBAH, $log->action);
        $this->assertSame(['nama'], array_keys($log->new_data));
        $this->assertSame('pH Buffer 7', $log->old_data['nama']);
        $this->assertSame('pH Buffer 7 (kalibrasi ulang)', $log->new_data['nama']);
    }

    /** `updated_at` berubah tiap update — kalau ikut, tiap baris punya "perubahan" palsu. */
    public function test_updated_at_nggak_ikut_kecatat(): void
    {
        $standar = $this->standar();
        AuditLog::query()->delete();

        $standar->update(['nama' => 'Nama Baru']);

        $log = AuditLog::latest('id')->first();

        $this->assertArrayNotHasKey('updated_at', $log->new_data);
        $this->assertArrayNotHasKey('created_at', $log->new_data);
    }

    /** `touch()` doang nggak nyatet apa-apa — baris riwayat kosong cuma nyampah. */
    public function test_update_tanpa_perubahan_nyata_nggak_nyatet(): void
    {
        $standar = $this->standar();
        AuditLog::query()->delete();

        $standar->touch();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_hapus_data_kecatat(): void
    {
        $standar = $this->standar();
        AuditLog::query()->delete();

        $standar->delete();

        $this->assertSame(
            AuditLog::ACTION_DIHAPUS,
            AuditLog::latest('id')->first()->action,
        );
    }

    public function test_pulihkan_data_kecatat(): void
    {
        $standar = $this->standar();
        $standar->delete();
        AuditLog::query()->delete();

        $standar->restore();

        $this->assertSame(
            AuditLog::ACTION_DIPULIHKAN,
            AuditLog::latest('id')->first()->action,
        );
    }

    /**
     * Pencatatannya lewat model event, jadi jalur MANA PUN kecatat — termasuk yang
     * nggak lewat API. Ini yang bikin endpoint yang lupa dipasangin nggak jadi
     * lubang: nggak ada yang perlu dipasangin.
     */
    public function test_perubahan_lewat_api_kecatat_pelakunya(): void
    {
        $standar = $this->standar();
        AuditLog::query()->delete();

        $this->actingAs($this->admin)
            ->putJson("/api/standards/{$standar->id}", ['nama' => 'Diubah Lewat API'])
            ->assertOk();

        $log = AuditLog::latest('id')->first();

        $this->assertSame($this->admin->id, $log->changed_by, 'Pelakunya harus keisi kalau ada sesi.');
        $this->assertSame('Diubah Lewat API', $log->new_data['nama']);
    }

    /** Tanpa sesi (queue/command/seeder) → `changed_by` null, bukan dituduhin ke user acak. */
    public function test_tanpa_sesi_pelakunya_null(): void
    {
        $this->standar();

        $this->assertNull(AuditLog::latest('id')->first()->changed_by);
    }

    // ------------------------------------------------------------- sensor

    /**
     * `password` NGGAK boleh masuk riwayat.
     *
     * Itu hash, dan hash yang kesimpen di tabel lain berarti satu kebocoran jadi
     * dua. Yang tetap kecatat NAMA kolomnya — jadi masih kelihatan "password
     * diubah", tanpa nyimpen isinya.
     */
    public function test_password_disensor_tapi_kejadiannya_tetap_kecatat(): void
    {
        $user = User::factory()->create();
        AuditLog::query()->delete();

        $user->update(['password' => 'rahasia-banget-123']);

        $log = AuditLog::latest('id')->first();

        $this->assertArrayHasKey('password', $log->new_data, 'Kejadian "password diubah" harus tetap kelihatan.');
        $this->assertSame('[disensor]', $log->new_data['password']);
        $this->assertSame('[disensor]', $log->old_data['password']);
        $this->assertStringNotContainsString('rahasia-banget', json_encode($log->new_data));
    }

    public function test_password_waktu_bikin_user_juga_disensor(): void
    {
        User::factory()->create();

        $log = AuditLog::where('entity_type', 'users')->latest('id')->first();

        $this->assertSame('[disensor]', $log->new_data['password']);
    }

    // --------------------------------------------------- nggak bisa diubah

    public function test_baris_audit_nggak_bisa_diubah(): void
    {
        $this->standar();
        $log = AuditLog::latest('id')->first();

        $this->expectException(\RuntimeException::class);
        $log->update(['note' => 'nyoba ngedit riwayat']);
    }

    public function test_baris_audit_nggak_bisa_dihapus(): void
    {
        $this->standar();
        $log = AuditLog::latest('id')->first();

        $this->expectException(\RuntimeException::class);
        $log->delete();
    }

    /**
     * Nggak ada pintu tulis di API — cuma index & export.
     *
     * Diperiksa langsung ke daftar rute, bukan lewat status code: `404` vs `405`
     * itu soal ada-nggaknya URI, dan yang mau dijamin di sini "nggak ada rute
     * tulis", bukan "URI-nya kebetulan nggak kedaftar".
     */
    public function test_nggak_ada_rute_tulis_ke_audit_logs(): void
    {
        $tulis = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/audit-logs'))
            ->flatMap(fn ($r) => array_map(fn ($m) => "{$m} {$r->uri()}", $r->methods()))
            ->reject(fn (string $m) => str_starts_with($m, 'GET') || str_starts_with($m, 'HEAD'))
            ->values();

        $this->assertSame([], $tulis->all(), 'Audit log nggak boleh punya rute tulis.');

        // Dan POST ke koleksinya ditolak router, bukan diam-diam nyampe controller.
        $this->actingAs($this->admin)->postJson(self::URL, [])->assertStatus(405);
    }

    // ------------------------------------------------------------- akses

    public function test_tanpa_token_ditolak(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    /**
     * Admin doang — isinya nilai SEBELUM & SESUDAH dari semua data lab, termasuk
     * data pelanggan dan angka kalibrasi.
     */
    public function test_teknisi_dan_viewer_ditolak(): void
    {
        $this->actingAs(User::factory()->create())->getJson(self::URL)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]))
            ->getJson(self::URL)->assertForbidden();
    }

    /** Riwayat lab lain NGGAK kebaca — ini tabel yang paling parah kalau bocor. */
    public function test_riwayat_organisasi_lain_nggak_kebaca(): void
    {
        $this->standar();

        $orgLain = Organization::factory()->create();
        Standard::factory()->create(['organization_id' => $orgLain->id, 'nama' => 'RAHASIA LAB LAIN']);

        $res = $this->actingAs($this->admin)->getJson(self::URL)->assertOk();

        $this->assertStringNotContainsString('RAHASIA LAB LAIN', json_encode($res->json()));
        foreach ($res->json('data') as $baris) {
            $this->assertNotSame('RAHASIA LAB LAIN', $baris['perubahan']['nama']['baru'] ?? null);
        }
    }

    // --------------------------------------------------------- penyaring

    public function test_bentuk_respons_kebaca_manusia(): void
    {
        $standar = $this->standar();
        AuditLog::query()->delete();
        $this->actingAs($this->admin)
            ->putJson("/api/standards/{$standar->id}", ['nama' => 'Nama Baru'])->assertOk();

        $baris = $this->actingAs($this->admin)->getJson(self::URL)->assertOk()->json('data.0');

        $this->assertSame('standards', $baris['entitas']['tipe']);
        $this->assertSame($standar->id, $baris['entitas']['id']);
        $this->assertSame('diubah', $baris['aksi']);
        // Perubahan disusun per kolom, `lama` → `baru`.
        $this->assertSame('pH Buffer 7', $baris['perubahan']['nama']['lama']);
        $this->assertSame('Nama Baru', $baris['perubahan']['nama']['baru']);
        $this->assertSame($this->admin->name, $baris['pelaku']['nama']);
        $this->assertFalse($baris['oleh_sistem']);
    }

    /**
     * `perubahan` SELALU objek, walaupun kosong.
     *
     * PHP nge-serialize array kosong jadi `[]`, dan itu bikin tipenya berubah
     * tergantung isi — objek kalau ada yang berubah, array kalau nggak. Aksi
     * `dihapus`/`dipulihkan` selalu kosong, jadi ini bukan kasus langka: klien yang
     * ngiterasi key-nya bakal pecah di SETIAP baris hapus.
     */
    public function test_perubahan_selalu_objek_walaupun_kosong(): void
    {
        $standar = $this->standar();
        AuditLog::query()->delete();
        $standar->delete();

        $mentah = $this->actingAs($this->admin)->getJson(self::URL)->assertOk()->content();
        $baris = json_decode($mentah, true)['data'][0];

        $this->assertSame('dihapus', $baris['aksi']);
        $this->assertSame([], $baris['perubahan'], 'Isinya kosong…');
        // …tapi di JSON-nya harus `{}`, bukan `[]`.
        $this->assertStringContainsString('"perubahan":{}', $mentah);
    }

    public function test_saring_per_entitas(): void
    {
        $standar = $this->standar();
        Customer::factory()->create();

        $res = $this->actingAs($this->admin)
            ->getJson(self::URL."?entity_type=standards&entity_id={$standar->id}")
            ->assertOk();

        $this->assertGreaterThan(0, count($res->json('data')));
        foreach ($res->json('data') as $b) {
            $this->assertSame('standards', $b['entitas']['tipe']);
        }
    }

    public function test_saring_per_aksi(): void
    {
        $standar = $this->standar();
        $standar->update(['nama' => 'Diubah']);

        $res = $this->actingAs($this->admin)->getJson(self::URL.'?action=diubah')->assertOk();

        foreach ($res->json('data') as $b) {
            $this->assertSame('diubah', $b['aksi']);
        }
    }

    public function test_saring_per_pelaku(): void
    {
        $standar = $this->standar();
        $this->actingAs($this->admin)
            ->putJson("/api/standards/{$standar->id}", ['nama' => 'Lewat API'])->assertOk();

        $res = $this->actingAs($this->admin)
            ->getJson(self::URL."?changed_by={$this->admin->id}")
            ->assertOk();

        foreach ($res->json('data') as $b) {
            $this->assertSame($this->admin->id, $b['pelaku']['id']);
        }
    }

    public function test_aksi_ngawur_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->getJson(self::URL.'?action=diapain')
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');
    }

    public function test_sampai_sebelum_dari_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-07-31&sampai=2026-07-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sampai');
    }

    // ------------------------------------------------------------ export

    public function test_export_csv(): void
    {
        $standar = $this->standar();
        $standar->update(['nama' => 'Diubah']);

        $res = $this->actingAs($this->admin)->get(self::URL.'/export')->assertOk();

        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('Riwayat-Perubahan-', (string) $res->headers->get('content-disposition'));

        $isi = $res->streamedContent();
        $this->assertStringContainsString('Nilai Lama', $isi);
        $this->assertStringContainsString('standards', $isi);
        $this->assertStringContainsString('Diubah', $isi);
    }

    /** Satu baris per KOLOM yang berubah — CSV yang selnya JSON nggak bisa di-pivot. */
    public function test_export_mecah_satu_baris_per_kolom(): void
    {
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
        ]);
        AuditLog::query()->delete();

        $alat->update(['nama_alat' => 'Alat Baru', 'lokasi' => 'Lab B']);

        $isi = $this->actingAs($this->admin)->get(self::URL.'/export')->assertOk()->streamedContent();

        // Dua kolom berubah → dua baris data (plus kepala tabel).
        $this->assertStringContainsString('nama_alat', $isi);
        $this->assertStringContainsString('lokasi', $isi);
        $this->assertSame(3, count(array_filter(explode("\n", trim($isi)))));
    }

    /**
     * Ekspor tidak boleh memicu deprecation `fputcsv()`.
     *
     * PHP 8.4: *"the $escape parameter must be provided as its default value
     * will change"*. Di sini deprecation-nya TIDAK KELIHATAN sama sekali —
     * `config/logging.php` menyetel kanal deprecation ke `null`, jadi dibuang
     * diam-diam. Yang tersisa cuma perilaku yang **belum dipatok**: begitu PHP
     * mengganti bawaannya, keluaran ekspor ini berubah tanpa ada satu baris pun
     * di repo ini yang ikut berubah.
     *
     * Bukan soal rapi: berkas ini yang dibawa asesor.
     */
    public function test_export_tidak_memicu_deprecation_fputcsv(): void
    {
        $this->standar()->update(['nama' => 'Diubah']);

        $deprecation = [];

        set_error_handler(static function (int $no, string $pesan) use (&$deprecation): bool {
            if ($no === E_DEPRECATED) {
                $deprecation[] = $pesan;

                return true;
            }

            return false;
        });

        try {
            $this->actingAs($this->admin)->get(self::URL.'/export')->assertOk()->streamedContent();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecation, 'Ekspor audit memicu deprecation PHP: '.implode(' | ', $deprecation));
    }

    /**
     * Nilai yang berakhir backslash tidak boleh menggeser kolom sesudahnya.
     *
     * `note` itu teks bebas dan `old_data`/`new_data` bisa memuat path Windows,
     * jadi nilai berakhir `\` bukan mengada-ada. Yang diadu di sini keluarannya
     * dibaca **seperti Excel membacanya** — RFC 4180, escape kosong — karena itu
     * yang benar-benar dipakai asesor, bukan pembaca CSV milik PHP.
     *
     * Penjaga arah maju: sekarang sudah benar, dan test ini yang bikin dia tetap
     * benar waktu PHP mengganti bawaan `$escape`-nya.
     */
    public function test_export_tetap_terbaca_excel_walau_nilainya_berakhir_backslash(): void
    {
        $standar = $this->standar();
        AuditLog::query()->delete();

        $standar->update(['nama' => 'Kalibrator, Blok C\\']);

        $isi = $this->actingAs($this->admin)->get(self::URL.'/export')->assertOk()->streamedContent();

        $aliran = fopen('php://memory', 'r+');
        fwrite($aliran, ltrim($isi, "\xEF\xBB\xBF"));
        rewind($aliran);

        $kepala = fgetcsv($aliran, 0, ',', '"', '');
        $baris = fgetcsv($aliran, 0, ',', '"', '');
        fclose($aliran);

        $this->assertNotFalse($baris);
        $this->assertCount(count($kepala), $baris, 'Kolomnya melebur — nilai berakhir backslash menelan kutip penutupnya.');
        // Kolom 9 `Nilai Baru`, kolom 10 `Catatan`. Kalau meleburnya kejadian,
        // yang di indeks 8 memuat isi kolom sesudahnya juga.
        $this->assertSame('Kalibrator, Blok C\\', $baris[8]);
    }

    public function test_export_ditolak_buat_non_admin(): void
    {
        $this->actingAs(User::factory()->create())->get(self::URL.'/export')->assertForbidden();
    }

    // --------------------------------------------------------- sakelar

    /** Seeder/pabrik data bisa matiin pencatatan — nyatet 151 baris cuma nyampah. */
    public function test_bisa_dimatiin_buat_seeder(): void
    {
        AuditLog::query()->delete();

        Standard::tanpaAudit(function (): void {
            Standard::factory()->create(['nama' => 'Diseed']);
        });

        $this->assertSame(0, AuditLog::count());

        // Dan nyala lagi sesudahnya — kalau nggak, seeder sekali jalan bikin
        // pencatatan mati buat seluruh proses.
        $this->standar();
        $this->assertGreaterThan(0, AuditLog::count());
    }
}
