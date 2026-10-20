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
 * `SERTIFIKAT` master. Hook profil kelompok DIMENSI — Micrometer
 * `165`/`166`/`167`, Dial Indicator `159`, Jangka Sorong `153`, Height Gauge
 * `184`, Sieve `169` (Studio `workbook_master` aktif) — dijaga lewat sesi
 * contoh seeder yang diterbitkan dan dirender sungguhan.
 *
 * Satu method, satu kali seeder: `DatabaseSeeder` mahal, dan yang diperiksa
 * tujuh sertifikat dari satu basis data yang sama.
 *
 * Angka diadu ke teks sel master, dengan pemisah desimal `[.,]` — pemisahnya
 * urusan template bersama, bukan hook ini.
 */
class SertifikatDimensiBentukMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_tujuh_sertifikat_dimensi_ikut_lembar_sertifikat_master(): void
    {
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        // ---- bersama: label lingkungan & kolom Standard used tanpa `:` ----
        foreach (['0106-CAL-1023', '002-UB.P-11-20', '003-UB.P-11-20', '0392-CAL-324', '001-CAL-126', '001-UBLK-05.26', '0736-CAL-526'] as $nomor) {
            [$sertifikat, $html] = $this->terbitkan($nomor);
            $tata = $sertifikat->snapshot['tata_letak'];

            $this->assertContains(['kunci' => 'env_condition', 'label' => 'Environmental Condition'], array_merge(...$tata['kepala']),
                "{$nomor}: label lingkungan master `Environmental Condition` (`165!J12`, `153!J11`).");
            $this->assertSame(['Name', 'Merk/Type', 'Serial Number', 'Traceable to SI through'],
                array_column($tata['kolom_standar_digunakan'], 'label'), "{$nomor}: kolom Standard used tanpa titik dua.");
            $this->assertSame(1, $this->halamanTerbaik($sertifikat), "{$nomor}: wajib satu halaman.");
        }

        // ---- Micrometer 25-50 mm (`166`) --------------------------------------
        [$s, $html] = $this->terbitkan('0106-CAL-1023');
        $this->assertSame('Standar Reading', $s->snapshot['judul_standar']);
        $this->assertSame(['standar' => '0.000', 'uut' => '0.000', 'koreksi' => '0.000', 'u95' => '0.000', 'k' => '0'],
            $s->snapshot['hasil'][0]['format']);
        // `166!D19:L19` 27.500 | 27.500 | 0.000, `J29` 0.001, `P30` 2.
        $this->assertMatchesRegularExpression('#<td>27[.,]500</td>\s*<td>27[.,]500</td>\s*<td>-?0[.,]000</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>0[.,]001 mm</td>#u', $html);
        $this->assertMatchesRegularExpression('#Coverage Factor \( k \) =\s*2\s*</div>#u', $html);
        $this->assertGreaterThanOrEqual(5, (int) $s->snapshot['hasil'][0]['desimal'], 'Nilai snapshot tetap presisi penuh.');

        // Snapshot LAMA (tanpa kunci K-50-15) tetap tercetak bentuk lamanya.
        $lama = $s->snapshot;
        unset($lama['tata_letak'], $lama['catatan_blok'], $lama['header_tambahan']);
        $lama['hasil'] = array_map(static function (array $b): array {
            unset($b['format'], $b['dicetak']);

            return $b;
        }, $lama['hasil']);
        $htmlLama = view('sertifikat.pdf', [...app(DataTampilanSertifikat::class)->untuk($s), 'snapshot' => $lama, 'paksaPadat' => false])->render();
        $this->assertStringContainsString('0,00087', $htmlLama, 'Snapshot lama: U95 lima desimal seperti waktu terbit.');
        $this->assertStringContainsString('Env. Condition', $htmlLama);

        // ---- Dial Indicator (`159`) -------------------------------------------
        [$s, $html] = $this->terbitkan('0392-CAL-324');
        $this->assertSame('Nominal Standard', $s->snapshot['judul_standar']);
        $this->assertSame('Pembacaan Alat', $s->snapshot['judul_uut']);
        $this->assertSame(['standar' => '0.00', 'uut' => '0.00', 'koreksi' => '0.00', 'u95' => '0.0000', 'k' => '0'],
            $s->snapshot['hasil'][0]['format']);
        // `159!D23:L23` 12.00 | 12.01 | -0.01, `J28` 0.0088 (sesi contoh 0.0086 — angka hitung, bukan hook ini).
        $this->assertMatchesRegularExpression('#<td>12[.,]00</td>\s*<td>12[.,]01</td>\s*<td>-0[.,]01</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>0[.,]\d{4} mm</td>#u', $html);

        // ---- Jangka Sorong (`153`) --------------------------------------------
        [$s, $html] = $this->terbitkan('001-CAL-126');
        $this->assertSame('Standar Reading', $s->snapshot['judul_standar']);
        $this->assertSame(['standar' => '0.00', 'uut' => '0.00', 'koreksi' => '0.00', 'u95' => '0.000', 'k' => 'General'],
            $s->snapshot['hasil'][0]['format']);
        // `153!D27:L27` 100.00 | 100.05 | -0.05, `J35` 0.033, `O36` k General.
        $this->assertMatchesRegularExpression('#<td>100[.,]00</td>\s*<td>100[.,]05</td>\s*<td>-0[.,]05</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>0[.,]033 mm</td>#u', $html);
        $this->assertMatchesRegularExpression('#Coverage Factor \( k \) =\s*1[.,]97\d{4,}#u', $html);

        // ---- Height Gauge (`184`) ---------------------------------------------
        [$s, $html] = $this->terbitkan('001-UBLK-05.26');
        $this->assertCount(10, $s->snapshot['hasil'], 'Sepuluh titik tetap di snapshot.');
        $this->assertSame([1, 6, 10], array_column(TataLetakSertifikat::barisDicetak($s->snapshot['hasil']), 'titik_ke'),
            'Master `184` menyembunyikan baris 25:28 & 30:32.');
        $this->assertSame(3, substr_count($this->tabelHasil($html), '<tr>') - 1, 'Tiga baris data tercetak (+1 baris judul).');
        $this->assertMatchesRegularExpression('#<td>25[.,]00</td>\s*<td>24[.,]99</td>\s*<td>0[.,]01</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>0[.,]01\d mm</td>#u', $html);
        $this->assertStringContainsString('<td>Result of Paralelism :</td>', $html);
        $this->assertStringContainsString('<td>Good</td>', $html);

        // ---- Sieve (`169`) ----------------------------------------------------
        [$s, $html] = $this->terbitkan('0736-CAL-526');
        $this->assertSame('Inspection', $s->snapshot['header_tambahan']['tipe_sieve']);
        $this->assertSame(1, substr_count($html, '<th>Parameters</th>'), 'SATU tabel Hole Dimension, bukan tiga.');
        $this->assertMatchesRegularExpression('#<th>Parameters</th>\s*<th>Nominal Value</th>\s*<th>Standard Indication</th>\s*<th>Correction</th>\s*<th>U95%</th>#u', $html);
        $this->assertStringNotContainsString('<tr class="u95">', $html, 'U95 per baris (`N20:N22`), tanpa baris ringkas.');
        $this->assertStringContainsString('Capacity/Sieve No.', $html);
        $this->assertStringContainsString('Type of Test Sieve', $html);
        $this->assertStringContainsString('Hole Dimension', $html);
        $this->assertMatchesRegularExpression('#<td>Wrap \(x&\#039;\)</td>\s*<td>19[.,]0000</td>#u', $html);
        // Simpangan baku `169!G26:G28` = 0.0584 / 0.1164 / 0.0463.
        $this->assertMatchesRegularExpression('#<td>Standard Deviation of Warp</td>\s*<td>:</td>\s*<td>0[.,]0584</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>Standard Deviation of Weft</td>\s*<td>:</td>\s*<td>0[.,]1164</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>Standard Deviation of Φ</td>\s*<td>:</td>\s*<td>0[.,]0463</td>#u', $html);
        $this->assertArrayNotHasKey('k', $s->snapshot['hasil'][0]['format'], 'k sieve menunggu jawaban paritas (audit §6 no. 10).');
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

    private function tabelHasil(string $html): string
    {
        preg_match('#<table class="data">.*?</table>#su', $html, $m);

        return preg_replace('#<thead>.*?</thead>#su', '<tr>', $m[0] ?? '');
    }
}
