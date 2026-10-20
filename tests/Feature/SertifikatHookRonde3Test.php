<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use App\Support\TataLetakSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * K-50-15 putaran 3: hook profil yang menyambung mekanisme template baru
 * (`satuanTitikSesi`/`remarkTitikSesi`, `kolom_hasil.pecah`, `kunciGabungTitik`,
 * `tambahan:`, `faktor`, `desimalKelembabanEnvSesi`) — semuanya CETAK saja.
 * Sesi contoh seeder diterbitkan dan dirender sungguhan; satu seeder per test.
 */
class SertifikatHookRonde3Test extends TestCase
{
    use RefreshDatabase;

    /**
     * Thermohygro `154`: seksi `1. Temperature` (`E19`) dan `2. Humidity`
     * (`E32`) masing-masing tabel sendiri, satuan `(oC)` / `(%RH)`, UUT suhu
     * `0.0` (`K22`) dan %RH `0` (`K35`).
     */
    public function test_thermohygro_seksi_humidity_terpisah_bersatuan_rh(): void
    {
        $this->siap();
        [$s, $html] = $this->terbitkan('0312-CAL-624');
        $hasil = $s->snapshot['hasil'];

        $suhu = array_values(array_filter($hasil, static fn (array $b): bool => $b['satuan'] === '°C'));
        $rh = array_values(array_filter($hasil, static fn (array $b): bool => $b['satuan'] === '%RH'));
        $this->assertCount(5, $suhu);
        $this->assertCount(5, $rh);

        foreach ($suhu as $b) {
            $this->assertSame('1. Temperature', $b['remark']);
            $this->assertSame('0.0', $b['format']['uut']);
        }

        foreach ($rh as $b) {
            $this->assertSame('2. Humidity', $b['remark']);
            $this->assertSame('0', $b['format']['uut']);
            $this->assertSame('0.00', $b['format']['standar']);
        }

        $this->assertLessThan(strpos($html, '2. Humidity'), strpos($html, '1. Temperature'));
        $humidity = substr($html, (int) strpos($html, '2. Humidity'));
        $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th>%RH</th>#u', $humidity);
        // UUT %RH tanpa desimal (`K35` `0`), contoh baris 30 %RH: 29.
        $this->assertMatchesRegularExpression('#<td>29\.\d\d</td>\s*<td>29</td>#u', $humidity);
        $this->assertSame(1, $this->halamanTerbaik($s));
    }

    /**
     * Timer `183`: Standard, UUT, Correction dipecah `H | M | S | S(0,001)`
     * (`E18:W18`; dicetak `S(0.001)`, titik desimal bentuk master), baris pertama persis `E19:W19` (0.00 | 1.00 | 0.00 | 96 —
     * 0.00 | 1.00 | 0.00 | 137 — 0.00 | 0.00 | 0.00 | -41), blok Note
     * (`E30:P32`) di bawah tabel. `k_tunggal` tidak diisi (menunggu lab).
     */
    public function test_timer_kolom_h_m_s_dan_note(): void
    {
        $this->siap();
        [$s, $html] = $this->terbitkan('015-CAL-424');

        $this->assertSame(['standar', 'uut', 'koreksi'], array_column(
            array_filter($s->snapshot['tata_letak']['kolom_hasil'], static fn (array $k): bool => isset($k['pecah'])),
            'kolom',
        ));
        $this->assertNull(TataLetakSertifikat::untuk($s->snapshot)['k_tunggal']);
        $this->assertStringContainsString('<th colspan="4">Standard Value</th>', $html);
        $this->assertStringContainsString('<th colspan="4">Unit Under Test</th>', $html);
        $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th>H</th>\s*<th>M</th>\s*<th>S</th>\s*<th>S\(0\.001\)</th>\s*<th>H</th>#u', $html);
        $this->assertMatchesRegularExpression(
            '#<td>0\.00</td>\s*<td>1\.00</td>\s*<td>0\.00</td>\s*<td>96</td>\s*<td>0\.00</td>\s*<td>1\.00</td>\s*<td>0\.00</td>\s*<td>137</td>\s*<td>0\.00</td>\s*<td>0\.00</td>\s*<td>0\.00</td>\s*<td>-41</td>#u',
            $html,
        );
        $this->assertSame([[
            'posisi' => TataLetakSertifikat::POSISI_BAWAH_HASIL,
            'judul' => 'Note :',
            'baris' => [],
            'tabel' => [['H :', 'Hour', 'S :', 'Second'], ['M :', 'Minute', 'S(0.001) :', 'Second per One Thousand']],
        ]], $s->snapshot['catatan_blok']);
        $this->assertStringContainsString('Second per One Thousand', $html);
        $this->assertSame(1, $this->halamanTerbaik($s));
    }

    private function siap(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
    }

    /** @return array{0: Certificate, 1: string} */
    private function terbitkan(string $nomor): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
        $sertifikat = $sesi->certificate()->first();

        if ($sertifikat === null) {
            $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
                ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
                ->assertOk();
            $sertifikat = $sesi->fresh()->certificate()->firstOrFail();
        }

        $html = view('sertifikat.pdf', [...app(DataTampilanSertifikat::class)->untuk($sertifikat), 'paksaPadat' => false])->render();

        return [$sertifikat, $html];
    }

    private function halamanTerbaik(Certificate $sertifikat): int
    {
        $bahan = app(DataTampilanSertifikat::class)->untuk($sertifikat);
        $halaman = PHP_INT_MAX;

        foreach ([false, true] as $padat) {
            $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => $padat]);
            $pdf->output();
            $halaman = min($halaman, (int) $pdf->getDomPDF()->getCanvas()->get_page_count());

            if ($halaman === 1) {
                break;
            }
        }

        return $halaman;
    }
}
