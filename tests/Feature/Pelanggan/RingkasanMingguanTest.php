<?php

namespace Tests\Feature\Pelanggan;

use App\Mail\Pelanggan\RingkasanMingguanEmail;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\User;
use App\Services\Pelanggan\PreferensiNotifikasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * §42 B7 — ringkasan email mingguan. Yang dijaga: cuma ke yang menyalakan
 * saklarnya, tidak mengirim minggu kosong, tidak mengirim dua kali seminggu,
 * dan diam selama FITUR_PELANGGAN mati.
 */
class RingkasanMingguanTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private User $pic;

    private Customer $perusahaan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Mail::fake();

        $this->pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->perusahaan = $this->pic->keanggotaan()->first()->customer;
    }

    private function langganan(User $user, bool $nyala = true): void
    {
        $anggota = $user->keanggotaan()->where('customer_id', $this->perusahaan->id)->firstOrFail();
        app(PreferensiNotifikasi::class)->simpan($anggota, [PreferensiNotifikasi::RINGKASAN_EMAIL_MINGGUAN => $nyala]);
    }

    private function alatJatuhTempo(int $hari): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
            'status' => Equipment::STATUS_AKTIF,
            'tanggal_jatuh_tempo' => now()->addDays($hari)->toDateString(),
        ]);
    }

    public function test_cuma_ke_yang_berlangganan_dan_sekali_seminggu(): void
    {
        $this->langganan($this->pic);
        $this->alatJatuhTempo(10);

        $this->artisan('pelanggan:ringkasan-mingguan')->assertSuccessful();

        Mail::assertSent(RingkasanMingguanEmail::class, fn (RingkasanMingguanEmail $m) => $m->hasTo($this->pic->email)
            && count($m->isi['jatuh_tempo']) === 1);

        // Dijalankan ulang di minggu yang sama → tidak mengirim lagi.
        $this->artisan('pelanggan:ringkasan-mingguan')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_bawaan_mati_tidak_dikirimi(): void
    {
        $this->alatJatuhTempo(10);

        $this->artisan('pelanggan:ringkasan-mingguan')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_minggu_tanpa_isi_tidak_dikirimi(): void
    {
        $this->langganan($this->pic);
        $this->alatJatuhTempo(120);

        $this->artisan('pelanggan:ringkasan-mingguan')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_diam_selama_fitur_pelanggan_mati(): void
    {
        $this->langganan($this->pic);
        $this->alatJatuhTempo(10);
        config(['pelanggan.fitur' => false]);

        $this->artisan('pelanggan:ringkasan-mingguan')->expectsOutputToContain('FITUR_PELANGGAN mati')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_kosongan_menghitung_tanpa_mengirim(): void
    {
        $this->langganan($this->pic);
        $this->alatJatuhTempo(-3);

        $this->artisan('pelanggan:ringkasan-mingguan --kosongan')
            ->expectsOutputToContain('1 email akan dikirim')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_isi_email_tidak_memuat_data_perusahaan_lain(): void
    {
        $this->langganan($this->pic);
        $this->alatJatuhTempo(5);
        Equipment::factory()->create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->perusahaan->organization_id])->id,
            'nama_alat' => 'Alat Milik Orang Lain',
            'tanggal_jatuh_tempo' => now()->addDays(5)->toDateString(),
        ]);

        $this->artisan('pelanggan:ringkasan-mingguan')->assertSuccessful();

        Mail::assertSent(RingkasanMingguanEmail::class, function (RingkasanMingguanEmail $m): bool {
            $html = $m->render();

            return ! str_contains($html, 'Alat Milik Orang Lain') && count($m->isi['jatuh_tempo']) === 1;
        });
    }
}
