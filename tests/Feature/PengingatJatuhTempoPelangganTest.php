<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Pelanggan\AlatAndaJatuhTempo;
use App\Services\Pelanggan\PengingatJatuhTempoPelanggan;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Alarm jatuh tempo kalibrasi ke HP pelanggan.
 *
 * ## Yang dijaga, urut dari yang paling mahal kalau salah
 *
 * 1. **Tidak bocor.** Pelanggan A tidak boleh menerima nomor seri alat pelanggan
 *    B. Satu kueri yang lupa `where customer_id` sudah cukup, dan kebocorannya
 *    mendarat di HP orang — tidak bisa ditarik.
 * 2. **Satu notifikasi per pelanggan, bukan per alat.** Empat belas notifikasi
 *    dalam satu pagi adalah alasan orang mematikan notifikasi aplikasi ini
 *    selamanya, dan sesudah dimatikan pengingat yang penting pun tidak sampai.
 * 3. **Tangganya benar-benar tangga.** H-30, H-7, H-1, lalu tiap 7 hari sesudah
 *    lewat — bukan tiap hari, dan bukan sekali lalu senyap.
 */
class PengingatJatuhTempoPelangganTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Customer $pelangganA;

    private User $akunA;

    /** Satu kategori untuk semua alat — factory kategori punya kolom unik yang
     * kolamnya kecil, dan 14 alat dengan 14 kategori baru menghabiskannya. */
    private EquipmentCategory $kategori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->pelangganA = Customer::factory()->create([
            'organization_id' => $this->org->id,
            'nama' => 'PT Alpha',
        ]);
        $this->akunA = $this->akunPelanggan($this->pelangganA);
        $this->kategori = EquipmentCategory::factory()->create(['organization_id' => $this->org->id]);
    }

    /**
     * Tangganya benar-benar tangga: mengirim TEPAT di anak tangga, diam di antara.
     *
     * Dijalankan sebagai dataProvider supaya yang gagal menyebut HARI KEBERAPA
     * yang salah. Kalau semuanya digabung dalam satu test, yang terbaca cuma
     * "jumlahnya tidak cocok" dan harinya masih harus dicari sendiri.
     *
     * Hari yang diam-nya sama pentingnya dengan yang mengirim: kalau H-29 ikut
     * mengirim, pencocokannya `<=` bukan `===`, dan hasilnya pengingat tiap
     * minggu sepanjang bulan — bukan tangga. Kalau H+0 mengirim, pelanggan dapat
     * dua kabar dua hari berturut-turut (H-1 lalu H-0), dan itu awal dari
     * notifikasi yang dimatikan.
     */
    #[DataProvider('anakTangga')]
    public function test_tangga_pengingat_mengirim_di_hari_yang_benar(int $selisihHari, bool $harusKirim): void
    {
        Notification::fake();

        $this->alat($this->pelangganA, now()->addDays($selisihHari));

        app(PengingatJatuhTempoPelanggan::class)->untukOrganisasi($this->org);

        if ($harusKirim) {
            Notification::assertSentToTimes($this->akunA, AlatAndaJatuhTempo::class, 1);

            return;
        }

        Notification::assertNotSentTo($this->akunA, AlatAndaJatuhTempo::class);
    }

    /** @return array<string, array{int, bool}> */
    public static function anakTangga(): array
    {
        return [
            'H-45 diam' => [45, false],
            'H-31 diam' => [31, false],
            'H-30 KIRIM' => [30, true],
            'H-29 diam' => [29, false],
            'H-8 diam' => [8, false],
            'H-7 KIRIM' => [7, true],
            'H-2 diam' => [2, false],
            'H-1 KIRIM' => [1, true],
            'hari-H diam (H-1 baru kemarin)' => [0, false],
            'H+7 KIRIM' => [-7, true],
            'H+8 diam' => [-8, false],
            'H+14 KIRIM' => [-14, true],
        ];
    }

    public function test_pelanggan_dengan_banyak_alat_dapat_satu_notifikasi(): void
    {
        Notification::fake();

        for ($i = 0; $i < 14; $i++) {
            $this->alat($this->pelangganA, now()->addDays(7));
        }

        app(PengingatJatuhTempoPelanggan::class)->untukOrganisasi($this->org);

        Notification::assertSentToTimes($this->akunA, AlatAndaJatuhTempo::class, 1);
    }

    public function test_pelanggan_tidak_menerima_alat_pelanggan_lain(): void
    {
        Notification::fake();

        $pelangganB = Customer::factory()->create([
            'organization_id' => $this->org->id,
            'nama' => 'PT Beta',
        ]);
        $akunB = $this->akunPelanggan($pelangganB);

        $this->alat($this->pelangganA, now()->addDays(7), 'SN-ALPHA-1');
        $this->alat($pelangganB, now()->addDays(7), 'SN-BETA-RAHASIA');

        app(PengingatJatuhTempoPelanggan::class)->untukOrganisasi($this->org);

        Notification::assertSentTo(
            $this->akunA,
            AlatAndaJatuhTempo::class,
            function (AlatAndaJatuhTempo $n) use ($akunB): bool {
                $isi = json_encode($n->toDatabase($akunB), JSON_THROW_ON_ERROR);

                // Kerahasiaan antar pelanggan, ISO/IEC 17025 klausul 4.2. Ini
                // kebocoran yang paling tidak bisa ditarik: dia mendarat di HP
                // orang lewat push.
                $this->assertStringNotContainsString('SN-BETA-RAHASIA', $isi);
                $this->assertStringContainsString('SN-ALPHA-1', $isi);

                return true;
            },
        );
    }

    public function test_isi_yang_sama_tidak_diulang_dalam_masa_tenang(): void
    {
        // Notifikasi SUNGGUHAN, bukan fake: penjaga masa tenang membaca riwayat
        // di tabel `notifications`, dan `Notification::fake()` tidak menulis ke
        // sana — dengan fake, penjaganya selalu melihat riwayat kosong.
        $this->alat($this->pelangganA, now()->addDays(7));
        $layanan = app(PengingatJatuhTempoPelanggan::class);

        $layanan->untukOrganisasi($this->org);
        // Dijalankan lagi di hari yang sama — mis. scheduler dipicu manual, atau
        // dua container menjalankannya bersamaan.
        $layanan->untukOrganisasi($this->org->fresh());

        $this->assertSame(
            1,
            $this->akunA->notifications()->where('type', AlatAndaJatuhTempo::class)->count(),
            'Isi yang sama terkirim dua kali dalam sehari. Pelanggan yang dikirimi '
            .'pengingat kembar akan mematikan notifikasi aplikasi ini.',
        );
    }

    public function test_alat_baru_yang_masuk_daftar_menembus_masa_tenang(): void
    {
        $this->alat($this->pelangganA, now()->addDays(7));
        app(PengingatJatuhTempoPelanggan::class)->untukOrganisasi($this->org);

        // Alat KEDUA jatuh tempo di hari yang sama. Isinya berubah → kabar baru,
        // dan masa tenang tidak boleh menahannya. Ini bagian yang paling mudah
        // rusak: kalau tanda tangannya dihitung dari daftar yang sudah dipotong
        // 10 baris, alat ke-11 dan seterusnya tidak pernah mengubah tanda
        // tangannya, dan justru kabar BARU yang tertahan seminggu.
        $this->alat($this->pelangganA, now()->addDays(7), 'SN-BARU');

        app(PengingatJatuhTempoPelanggan::class)->untukOrganisasi($this->org->fresh());

        $this->assertSame(
            2,
            $this->akunA->notifications()->where('type', AlatAndaJatuhTempo::class)->count(),
            'Alat baru di daftar tertahan masa tenang — kabar BARU yang justru tertahan.',
        );
    }

    public function test_pelanggan_tanpa_akun_dihitung_sebagai_dilewat(): void
    {
        Notification::fake();

        $tanpaAkun = Customer::factory()->create(['organization_id' => $this->org->id]);
        $this->alat($tanpaAkun, now()->addDays(7));

        $ringkasan = app(PengingatJatuhTempoPelanggan::class)->untukOrganisasi($this->org);

        // Bukan kegagalan — sebagian pelanggan memang belum diundang. Tapi
        // angkanya harus kelihatan: dia yang menjelaskan kenapa pelanggan
        // tertentu selalu terlambat mengirim alat.
        $this->assertSame(0, $ringkasan['pelanggan_dikabarin']);
        $this->assertSame(1, $ringkasan['pelanggan_dilewat']);
        Notification::assertNothingSent();
    }

    public function test_alat_nonaktif_dan_alat_tanpa_jatuh_tempo_dilewati(): void
    {
        Notification::fake();

        $this->alat($this->pelangganA, now()->addDays(7))
            ->forceFill(['status' => Equipment::STATUS_NONAKTIF])->save();
        $this->alat($this->pelangganA, null);

        $ringkasan = app(PengingatJatuhTempoPelanggan::class)->untukOrganisasi($this->org);

        $this->assertNull($ringkasan);
        Notification::assertNothingSent();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function akunPelanggan(Customer $pelanggan): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_PELANGGAN,
            'status' => User::STATUS_AKTIF,
        ]);

        // Lewat `customer_members`, bukan cuma `users.customer_id` — satu orang
        // bisa jadi anggota beberapa perusahaan, dan pengingat yang dikirim
        // berdasarkan kolom di `users` akan melewatkan anggota kedua.
        DB::table('customer_members')->insert([
            'organization_id' => $this->org->id,
            'customer_id' => $pelanggan->id,
            'user_id' => $user->id,
            'peran' => 'pic_utama',
            'status' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function alat(Customer $pelanggan, ?CarbonInterface $jatuhTempo, ?string $serial = null): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => $this->kategori->id,
            'status' => Equipment::STATUS_AKTIF,
            'serial_number' => $serial ?? 'SN-'.Str::random(8),
            'tanggal_jatuh_tempo' => $jatuhTempo?->toDateString(),
        ]);
    }
}
