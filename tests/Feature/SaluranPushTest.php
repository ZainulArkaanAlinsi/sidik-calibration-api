<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Channels\SaluranPush;
use App\Notifications\SesiDisetujui;
use App\Services\Push\PengirimPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saluran `push` — notifikasi naik ke HP yang aplikasinya ketutup total.
 *
 * Yang diuji di sini keputusannya, bukan pengirimannya: FCM butuh kredensial
 * yang cuma ada di server beneran. Yang bisa salah di lapisan ini dua-duanya
 * senyap, dan dua-duanya bikin orang berhenti percaya sama notifikasinya —
 * token sehat kecabut gara-gara jaringan ngadat, atau token mati numpuk
 * selamanya karena FCM nggak pernah ngabarin aplikasi dicopot.
 */
class SaluranPushTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teknisi = User::factory()->create([
            'role' => 'teknisi',
            'organization_id' => Organization::factory()->create()->id,
        ]);
    }

    private function perangkat(string $token = 'fcm-abc'): DeviceToken
    {
        return DeviceToken::catat($this->teknisi, $token, 'android');
    }

    private function notifikasi(): SesiDisetujui
    {
        return new SesiDisetujui(1, 'CAL/2026/08/0001', 'pH Meter', 'lulus');
    }

    public function test_terkirim_ke_semua_perangkat_milik_penerima(): void
    {
        $this->perangkat('fcm-hp');
        $this->perangkat('fcm-tablet');

        $pengirim = new PengirimPushPalsu(berhasil: true);
        (new SaluranPush($pengirim))->send($this->teknisi, $this->notifikasi());

        $this->assertCount(2, $pengirim->terkirim);
    }

    public function test_judul_dan_isi_ikut_bukan_cuma_bunyi(): void
    {
        $this->perangkat();

        $pengirim = new PengirimPushPalsu(berhasil: true);
        (new SaluranPush($pengirim))->send($this->teknisi, $this->notifikasi());

        $this->assertNotSame('', $pengirim->terkirim[0]['judul']);
        $this->assertStringContainsString('CAL/2026/08/0001', $pengirim->terkirim[0]['isi']);
    }

    /**
     * Tautan ikut supaya ketukan di layar kunci membuka layar yang
     * bersangkutan, bukan cuma membuka aplikasi di halaman depan.
     */
    public function test_tautan_layar_tujuan_ikut_di_muatan(): void
    {
        $this->perangkat();

        $pengirim = new PengirimPushPalsu(berhasil: true);
        (new SaluranPush($pengirim))->send($this->teknisi, $this->notifikasi());

        $data = $pengirim->terkirim[0]['data'];
        $this->assertSame('calibration', $data['tautan_tipe']);
        $this->assertSame('1', $data['tautan_id']);
    }

    public function test_gagal_sesaat_tidak_mencabut_token(): void
    {
        $this->perangkat();

        // Jaringan ngadat / layanan lagi down — tokennya masih sehat.
        $pengirim = new PengirimPushPalsu(berhasil: false, mati: false);
        (new SaluranPush($pengirim))->send($this->teknisi, $this->notifikasi());

        $this->assertDatabaseHas('device_tokens', ['token' => 'fcm-abc']);
    }

    public function test_token_yang_ditolak_permanen_dihapus(): void
    {
        $this->perangkat();

        // Aplikasinya dicopot / tokennya dirotasi. FCM nggak pernah ngabarin
        // ini duluan, jadi kalau nggak dihapus di sini, barisnya nggak akan
        // pernah hilang.
        $pengirim = new PengirimPushPalsu(berhasil: false, mati: true);
        (new SaluranPush($pengirim))->send($this->teknisi, $this->notifikasi());

        $this->assertDatabaseMissing('device_tokens', ['token' => 'fcm-abc']);
    }

    public function test_tanpa_perangkat_terdaftar_tidak_meledak(): void
    {
        $pengirim = new PengirimPushPalsu(berhasil: true);
        (new SaluranPush($pengirim))->send($this->teknisi, $this->notifikasi());

        $this->assertSame([], $pengirim->terkirim);
    }

    /** Bawaan aplikasi harus yang diam — lihat `AppServiceProvider::register`. */
    public function test_bawaannya_pengirim_yang_diam(): void
    {
        $this->perangkat();

        $this->assertFalse(
            app(PengirimPush::class)->kirim($this->perangkat(), 'judul', 'isi'),
        );
    }
}

/** Pengirim palsu — nyatet apa yang mestinya berangkat. */
class PengirimPushPalsu implements PengirimPush
{
    /** @var array<int, array{judul: string, isi: string, data: array<string, string>}> */
    public array $terkirim = [];

    public function __construct(
        private readonly bool $berhasil,
        private readonly bool $mati = false,
    ) {}

    public function kirim(
        DeviceToken $perangkat,
        string $judul,
        string $isi,
        array $data = [],
    ): bool {
        if ($this->berhasil) {
            $this->terkirim[] = ['judul' => $judul, 'isi' => $isi, 'data' => $data];
        }

        return $this->berhasil;
    }

    public function tokenMati(): bool
    {
        return $this->mati;
    }
}
