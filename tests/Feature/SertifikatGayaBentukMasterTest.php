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
 * K-50-15 (pemilik, 18 Okt 2026; ditegaskan 20 Okt): sertifikat persis lembar
 * `SERTIFIKAT` master. Hook profil kelompok GAYA — UTM `163`, Load Cell `161`,
 * Proving Ring `162` (Studio `workbook_master` aktif) — dijaga lewat sesi
 * contoh seeder yang diterbitkan dan dirender sungguhan. Satu method, satu
 * kali seeder.
 *
 * Angka diadu ke teks sel master dengan pemisah desimal `[.,]` — pemisahnya
 * urusan template bersama, bukan hook ini.
 */
class SertifikatGayaBentukMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_tiga_sertifikat_gaya_ikut_lembar_sertifikat_master(): void
    {
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        foreach (['DEMO-UTM-001', 'DEMO-LC-001', 'DEMO-PR-001'] as $nomor) {
            [$sertifikat] = $this->terbitkan($nomor);
            $hasil = $sertifikat->snapshot['hasil'];

            // Titik nol tersembunyi di master (`163!E21`, `161!E21`, `162!E22`):
            // tetap di snapshot, tidak dicetak.
            // Standard Value titik nol = rata-rata + koreksi (Load Cell -0,00196 kN),
            // jadi yang dipakai pembacaan UUT-nya — set point 0.
            $this->assertEqualsWithDelta(0.0, (float) $hasil[0]['unit_under_test'], 1e-9, "{$nomor}: baris pertama titik nol.");
            $this->assertFalse($hasil[0]['dicetak'], "{$nomor}: titik nol tidak dicetak.");
            $this->assertCount(count($hasil) - 1, TataLetakSertifikat::barisDicetak($hasil));
            $this->assertSame(1, $this->halamanTerbaik($sertifikat), "{$nomor}: wajib satu halaman.");
        }

        // ---- UTM (`163`) ------------------------------------------------------
        [$s, $html] = $this->terbitkan('DEMO-UTM-001');
        $this->assertSame(['standar' => '0.0', 'uut' => '0.0', 'koreksi' => '0.00', 'sebaran' => '0.00', 'u95' => '0.0', 'k' => '0'],
            $s->snapshot['hasil'][1]['format']);
        // `163!E22:V22` 100.4 | 100.0 | 0.38 | 0.00, `P35` 2.1, `T36` 2.
        $this->assertMatchesRegularExpression('#<td>100[.,]4</td>\s*<td>100[.,]0</td>\s*<td>0[.,]38</td>\s*<td>0[.,]00</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>2[.,]1 kgf</td>#u', $html);
        $this->assertMatchesRegularExpression('#Coverage Factor \( k \) =\s*2\s*</div>#u', $html);
        $this->assertDoesNotMatchRegularExpression('#<td>0[.,]0</td>\s*<td>0[.,]0</td>#u', $html, 'Titik nol tidak tercetak.');
        $this->assertMatchesRegularExpression('#<td>Type of Force</td>\s*<td>:</td>\s*<td>Pull</td>#u', $html);
        $this->assertStringContainsString('RRPE : Relative Repeatability Error', $html);
        $this->assertMatchesRegularExpression('#Units Used:</div>.*?<td>kgf</td>\s*<td>:</td>\s*<td>Kilogram-force</td>#su', $html);
        $this->assertStringNotContainsString('Full Scale', $html, '`163` tidak punya baris FS.');
        $this->assertSame(
            ['Type of Force', 'RRPE', 'Units Used:'],
            array_map(static fn (array $b): string => $b['judul'] ?? (($b['baris'][0] ?? null) !== null ? strtok($b['baris'][0], ' ') : $b['tabel'][0][0]), $s->snapshot['catatan_blok']),
            'Urutan blok master: E18, E37, E39.',
        );

        // ---- Load Cell (`161`) ------------------------------------------------
        [$s, $html] = $this->terbitkan('DEMO-LC-001');
        $this->assertSame(['standar' => '0.00', 'uut' => '0.00', 'koreksi' => '0.00', 'sebaran' => '0.00', 'u95' => '0.00', 'k' => '0'],
            $s->snapshot['hasil'][1]['format']);
        // `161!E22:V22` 99.88 | 100.00 | -0.12 | 0.00, `P35` 0.30.
        $this->assertMatchesRegularExpression('#<td>99[.,]88</td>\s*<td>100[.,]00</td>\s*<td>-0[.,]12</td>\s*<td>0[.,]00</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>0[.,]30 kN</td>#u', $html);
        $this->assertDoesNotMatchRegularExpression('#<td>-0([.,]0+)?</td>#u', $this->barisPertama($html), 'Nol negatif titik nol tidak tercetak lagi.');
        $this->assertMatchesRegularExpression('#<td>kN</td>\s*<td>:</td>\s*<td>Kilo-newton</td>.*?<td>FS</td>\s*<td>:</td>\s*<td>Full Scale</td>#su', $html);

        // ---- Proving Ring (`162`) ---------------------------------------------
        [$s, $html] = $this->terbitkan('DEMO-PR-001');
        $this->assertSame(['standar' => '0.00', 'uut' => '0', 'koreksi' => '0.0', 'sebaran' => '0.0', 'u95' => '0.0', 'k' => '0'],
            $s->snapshot['hasil'][1]['format']);
        $this->assertMatchesRegularExpression('#<th>Standard Value</th>\s*<th>Unit Under Test</th>\s*<th>Calibration Factor</th>\s*<th>Repeatability</th>#u', $html);
        $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th>kgf</th>\s*<th>\(1 Division x 0[.,]002 mm\)</th>\s*<th>kgf / Div</th>\s*<th>%</th>#u', $html);
        // `162!E23` 30.00 (Z31 / S23), `L23` 237, `Q23` 0.1, `V23` 0.2.
        $this->assertMatchesRegularExpression('#<td>30[.,]00</td>\s*<td>237</td>\s*<td>0[.,]1</td>\s*<td>0[.,]2</td>#u', $html);
        $this->assertStringNotContainsString('Type of Force', $html, '`162` tidak punya baris Type of Force.');
        $this->assertStringContainsString('Dial Spesification :', $html);
        $this->assertMatchesRegularExpression('#<td>Range &amp; Res\.</td>\s*<td>:</td>\s*<td>25 mm / 0[.,]002 mm</td>#u', $html);
        $this->assertStringContainsString('Units used:', $html);

        // Mean = AVERAGE faktor yang TERCETAK (`162!L40` = `AVERAGE(Q23:U37)`
        // = 0.12) — rata-rata kolom snapshot yang sama, bukan hitungan lain.
        $faktor = array_column(array_filter(
            TataLetakSertifikat::barisDicetak($s->snapshot['hasil']),
            static fn (array $b): bool => ! ($b['koreksi_kosong'] ?? false),
        ), 'correction');
        $rata = array_sum($faktor) / count($faktor);
        $this->assertStringContainsString('Mean of Calibration Factor = '.TataLetakSertifikat::formatAngka($rata, '0.00').' kgf / Div', $html);
        $this->assertMatchesRegularExpression('#Mean of Calibration Factor = 0[.,]12 kgf / Div#u', $html);
        $this->assertLessThan(strpos($html, 'Units used:'), strpos($html, 'Mean of Calibration Factor'), 'Urutan master: E40 lalu E42.');
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

    /** Halaman terkecil: normal dulu, padat kalau meluap — urutan `SertifikatSatuHalaman`. */
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

    private function barisPertama(string $html): string
    {
        preg_match('#<tbody>\s*(<tr>.*?</tr>)#su', $html, $m);

        return $m[1] ?? '';
    }
}
