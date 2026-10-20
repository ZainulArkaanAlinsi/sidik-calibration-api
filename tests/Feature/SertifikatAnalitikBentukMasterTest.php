<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * K-50-15 (pemilik, 18 Okt 2026; ditegaskan 20 Okt): sertifikat persis lembar
 * `SERTIFIKAT` master. Hook profil kelompok ANALITIK — Chlorine `156`,
 * Turbidimeter `177`, Refractometer `168`, DO Meter `158`, Conductivity `157`
 * (Studio `workbook_master` aktif) — dijaga lewat sesi contoh seeder yang
 * diterbitkan dan dirender sungguhan. pH Meter & Gas Detector tidak di sini:
 * master aktifnya belum ada di Studio.
 *
 * Angka diadu ke teks sel master dengan pemisah desimal `[.,]` — pemisahnya
 * urusan template bersama, bukan hook ini.
 */
class SertifikatAnalitikBentukMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_sertifikat_analitik_ikut_lembar_sertifikat_master(): void
    {
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        // ---- Chlorine (`156`) -------------------------------------------------
        [$s, $html] = $this->terbitkan('2406.32.C');
        $this->assertSame(['standar' => '0.00', 'uut' => '0.00', 'koreksi' => '0.00', 'u95' => '0.00', 'k' => '0'], $s->snapshot['hasil'][0]['format']);
        // `C16:W16` satu tabel, Remark jadi kolom; `C17:S17` satuan, Remark kosong.
        $this->assertSame(1, substr_count($html, '<th>Standard Value</th>'), 'Chlorine: satu tabel hasil.');
        $this->assertMatchesRegularExpression('#<th>Standard Value</th>\s*<th>Unit Under Test</th>\s*<th>Correction</th>\s*<th>U<sub>95%</sub> \(±\)</th>\s*<th>Remark</th>#u', $html);
        $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th>mg/L</th>\s*<th>mg/L</th>\s*<th>mg/L</th>\s*<th>mg/L</th>\s*<th></th>#u', $html);
        // `C18:W19` 1.74 | 1.76 | -0.02 | 0.09 | Free Chlorine; 1.83 | 1.86 | -0.03 | 0.08 | Total Chlorine.
        $this->assertMatchesRegularExpression('#<td>1[.,]74</td>\s*<td>1[.,]76</td>\s*<td>-0[.,]02</td>\s*<td>0[.,]09</td>\s*<td>Free Chlorine</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>1[.,]83</td>\s*<td>1[.,]86</td>\s*<td>-0[.,]03</td>\s*<td>0[.,]08</td>\s*<td>Total Chlorine</td>#u', $html);
        $this->assertStringNotContainsString('<div class="judul-kelompok">', $html, 'Chlorine: remark bukan judul tabel lagi.');
        $this->assertStringNotContainsString('<tr class="u95">', $html, 'Chlorine: U95 sudah kolom, tanpa baris U95 terpisah.');
        $this->assertSame(1, preg_match_all('#Coverage Factor \( k \) =\s*2\s*</div>#u', $html), '`U21` satu kalimat k, format `0`.');
        $this->assertSame(1, $this->halamanTerbaik($s));

        // Snapshot terbit SEBELUM hook ini (tanpa `tata_letak`/`format`) tetap
        // tercetak bentuk lamanya: dua tabel per remark, baris U95 per tabel.
        $lama = $s->snapshot;
        unset($lama['tata_letak'], $lama['catatan_blok'], $lama['header_tambahan']);
        $lama['hasil'] = array_map(static function (array $b): array {
            unset($b['format']);

            return $b;
        }, $lama['hasil']);
        $htmlLama = view('sertifikat.pdf', [...app(DataTampilanSertifikat::class)->untuk($s), 'snapshot' => $lama, 'paksaPadat' => false])->render();
        $this->assertSame(2, substr_count($htmlLama, '<th>Standard Value</th>'));
        $this->assertMatchesRegularExpression('#<div class="judul-kelompok">Free Chlorine</div>#u', $htmlLama);
        $this->assertSame(2, substr_count($htmlLama, '<tr class="u95">'));

        // ---- Turbidimeter (`177`) ---------------------------------------------
        [$s, $html] = $this->terbitkan('2406.32.A');
        $this->assertSame(
            [
                ['standar' => '0', 'uut' => '0.00', 'koreksi' => '0.00', 'u95' => '0.00', 'k' => '0.0'],
                ['standar' => '0', 'uut' => '0.0', 'koreksi' => '0.0', 'u95' => '0.0', 'k' => '0.0'],
                ['standar' => '0', 'uut' => '0', 'koreksi' => '0', 'u95' => '0', 'k' => '0.0'],
            ],
            array_column($s->snapshot['hasil'], 'format'),
        );
        // `C22:S24`: 1 | 1.00 | (-)0.00 | 0.04; 100 | 100.0 | (-)0.0 | 3.1; 1000 | 1001 | -1 | 22.
        $this->assertMatchesRegularExpression('#<td>1</td>\s*<td>1[.,]00</td>\s*<td>-?0[.,]00</td>\s*<td>0[.,]04</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>100</td>\s*<td>100[.,]0</td>\s*<td>-?0[.,]0</td>\s*<td>3[.,]1</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>1000</td>\s*<td>1001</td>\s*<td>-1</td>\s*<td>22</td>#u', $html);
        $this->assertMatchesRegularExpression('#Coverage Factor \( k \) =\s*2[.,]0\s*</div>#u', $html, '`U27` k `0.0`.');
        $this->assertSame(1, $this->halamanTerbaik($s));

        // ---- Refractometer (`168`) --------------------------------------------
        [$s, $html] = $this->terbitkan('2211.11.R');
        $this->assertMatchesRegularExpression('#<th>Correction</th>\s*<th>U95%, k=2</th>#u', $html, '`R16`.');
        // `C18:R18` 1.33659 | 1.33935 | -0.00276 | 0.00053.
        $this->assertMatchesRegularExpression('#<td>1[.,]33659</td>\s*<td>1[.,]33935</td>\s*<td>-0[.,]00276</td>\s*<td>0[.,]00053</td>#u', $html);
        $this->assertMatchesRegularExpression('#Coverage Factor \( k \) =\s*2\s*</div>#u', $html, '`U21` k `0`.');
        $this->assertSame(1, $this->halamanTerbaik($s));

        // ---- DO Meter (`158`) -------------------------------------------------
        [$s, $html] = $this->terbitkan('2406.50.S');
        $this->assertMatchesRegularExpression('#<th>Standard Indication</th>\s*<th>Instrument Indication</th>\s*<th>Correction</th>#u', $html, '`E22`/`N22`/`S22`.');
        $this->assertStringNotContainsString('<div class="judul-kelompok">', $html, 'Master tidak mencetak judul "Dissolved Oxygen".');
        // `E24:S24` 8.77 | 8.82 | -0.05, `Q25` 0.16 mg/L, `V26` 2.
        $this->assertMatchesRegularExpression('#<td>8[.,]77</td>\s*<td>8[.,]82</td>\s*<td>-0[.,]05</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>0[.,]16 mg/L</td>#u', $html);
        $this->assertMatchesRegularExpression('#Coverage Factor \( k \) =\s*2\s*</div>#u', $html);
        $this->assertSame(1, $this->halamanTerbaik($s));

        // ---- Conductivity (`157`) ---------------------------------------------
        foreach (['2405.32.A.NK', 'DEMO-COND-MSCM'] as $nomor) {
            [$s, $html] = $this->terbitkan($nomor);
            $this->assertSame(['k' => '0'], $s->snapshot['hasil'][0]['format'], "{$nomor}: `U36`/`U42` k `0`.");
            $this->assertStringContainsString('Standard Used :', $html, "{$nomor}: `B47`.");
            $this->assertSame(1, $this->halamanTerbaik($s), "{$nomor}: wajib satu halaman.");
        }
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
}
