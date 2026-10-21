<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use App\Support\AnakTimbanganMentah;
use App\Support\JangkaSorongMentah;
use App\Support\TataLetakSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PetaJsonTanpaUrutan;
use Tests\TestCase;

/**
 * K-50-15 putaran 3: hook profil yang menyambung mekanisme template baru
 * (`satuanTitikSesi`/`remarkTitikSesi`, `kolom_hasil.pecah`, `kunciGabungTitik`,
 * `tambahan:`, `faktor`, `desimalKelembabanEnvSesi`) — semuanya CETAK saja.
 * Sesi contoh seeder diterbitkan dan dirender sungguhan; satu seeder per test.
 */
class SertifikatHookRonde3Test extends TestCase
{
    use PetaJsonTanpaUrutan;
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
        $this->assertSamaPetaJson([[
            'posisi' => TataLetakSertifikat::POSISI_BAWAH_HASIL,
            'judul' => 'Note :',
            'baris' => [],
            'tabel' => [['H :', 'Hour', 'S :', 'Second'], ['M :', 'Minute', 'S(0.001) :', 'Second per One Thousand']],
        ]], $s->snapshot['catatan_blok']);
        $this->assertStringContainsString('Second per One Thousand', $html);
        $this->assertSame(1, $this->halamanTerbaik($s));
    }

    /**
     * Centrifuge `180` & Tachometer `182`: U95 satu kotak per RENTANG (blok
     * tiga titik = satu budget, `S19:V21`, `S22:V24`, …), dipisah menurut
     * identitas blok, bukan kesamaan angka cetak.
     */
    public function test_putaran_u95_digabung_per_rentang(): void
    {
        $this->siap();

        foreach (['0133-CAL-324', '0140-CAL-424'] as $nomor) {
            [$s, $html] = $this->terbitkan($nomor);
            $baris = TataLetakSertifikat::barisDicetak($s->snapshot['hasil']);
            $blok = collect($baris)->groupBy('kunci_gabung');

            $this->assertNotContains(null, array_column($baris, 'kunci_gabung'), "{$nomor}: tiap baris punya rentang.");
            $this->assertSame(range(0, $blok->count() - 1), $blok->keys()->map(static fn (string $k): int => (int) substr($k, 5))->all());

            foreach ($blok as $kunci => $anggota) {
                $this->assertLessThanOrEqual(3, $anggota->count(), "{$nomor} {$kunci}: maksimal tiga titik.");
                // Satu blok satu budget: U95 dan k anggotanya sama.
                $this->assertCount(1, $anggota->pluck('u95')->unique(), "{$nomor} {$kunci}: U95 satu blok.");
                $this->assertCount(1, $anggota->pluck('faktor_cakupan_k')->unique(), "{$nomor} {$kunci}: k satu blok.");
            }

            // Satu kotak U95 per rentang.
            $rowspan = preg_match_all('#<td class="gabung" rowspan="(\d+)"#u', $html, $m) ? array_map('intval', $m[1]) : [];
            $this->assertSame(
                $blok->map->count()->filter(static fn (int $n): bool => $n > 1)->values()->all(),
                $rowspan,
                "{$nomor}: rowspan U95 = ukuran tiap rentang.",
            );
        }
    }

    /**
     * Anak Timbangan `139`: `1. ACCURACY` (`C14`), `ID Number` = No. Seri
     * keping dari sesi (`C15`/`C17`), U dalam mg `0.00` (`L16:L17`), baris
     * satuan (`F16:L16`).
     */
    public function test_anak_timbangan_id_number_dan_u_mg(): void
    {
        $this->siap();
        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-AT-001')->firstOrFail();
        $keping = [];

        foreach ([1.0, 2.0, 5.0] as $i => $n) {
            $keping[] = [
                'titik_ukur' => $n, 'no_identitas' => 'SN-'.($i + 1),
                'at_s1' => [$n, $n, $n], 'at_t1' => [$n + 0.0001, $n + 0.0001, $n + 0.0001],
                'at_t2' => [$n + 0.0001, $n + 0.0001, $n + 0.0001], 'at_s2' => [$n, $n, $n],
            ];
        }

        $id = $this->actingAs(User::where('role', User::ROLE_TEKNISI)->firstOrFail())->postJson('/api/calibrations', [
            'equipment_id' => $contoh->equipment_id,
            'standard_id' => $contoh->standard_id,
            'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
            'tanggal_kalibrasi' => '2026-09-11',
            'suhu_awal' => 23.1, 'suhu_akhir' => 23.0,
            'kelembaban_awal' => 55.0, 'kelembaban_akhir' => 56.0,
            'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => [
                'kelas_uut' => 'F1', 'kelas_standar' => 'E2', 'timbangan' => 'Analytical Balance',
                'meter_lingkungan' => 'Thermobarometer', 'kapasitas_min_g' => 1, 'kapasitas_g' => 200,
                'suhu_awal' => 23.1, 'suhu_akhir' => 23.0, 'kelembaban_awal' => 55.0, 'kelembaban_akhir' => 56.0,
                'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
            ]],
            'measurements' => $keping,
        ])->assertCreated()->json('data.id');
        $sesi = CalibrationSession::findOrFail($id);
        [$s, $html] = $this->terbitkan($sesi->nomor_sesi);

        $this->assertSame(['SN-1', 'SN-2', 'SN-3'], array_map(static fn (array $b): string => $b['tambahan']['id'], $s->snapshot['hasil']));
        $this->assertStringContainsString('1. ACCURACY', $html);
        $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th></th>\s*<th>g</th>\s*<th>g</th>\s*<th>mg</th>#u', $html);

        foreach ($s->snapshot['hasil'] as $b) {
            $mg = number_format((float) $b['u95'] * 1000, 2, '.', '');
            $this->assertMatchesRegularExpression('#<td>'.preg_quote($b['tambahan']['id'], '#').'</td>\s*<td>[^<]+</td>\s*<td>[^<]+</td>\s*<td>'.preg_quote($mg, '#').'</td>#u', $html);
        }
    }

    /** Timbangan: %RH env `0.0` di master gram (`145!AB14`), `0` di kg (`146!AB14`). */
    public function test_timbangan_gram_kelembaban_satu_desimal(): void
    {
        $this->siap();

        // Sesi contoh tidak membawa kelembapan ruang; diisi seperti `145!AB14`
        // (50.8) supaya baris %RH tercetak.
        foreach (['019-CAL-425', '011-CAL-525'] as $nomor) {
            CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail()->forceFill(['kelembaban' => 50.8])->saveQuietly();
        }

        [$gram] = $this->terbitkan('019-CAL-425');
        [$kg] = $this->terbitkan('011-CAL-525');

        $this->assertStringContainsString('%RH 50.8 %', (string) $gram->snapshot['header']['env_condition']);
        $this->assertStringContainsString('%RH 51 %', (string) $kg->snapshot['header']['env_condition']);
    }

    /** Jangka Sorong: blok Note Kerataan (`153!B64:F65`) dari sesi, tetap satu halaman. */
    public function test_jangka_sorong_note_kerataan(): void
    {
        $this->siap();
        $sesi = CalibrationSession::where('nomor_sesi', '001-CAL-126')->firstOrFail();
        $kerataan = JangkaSorongMentah::blokSesi($sesi->spesifikasi_alat)['kerataan_muka_ukur'];
        $this->assertNotNull($kerataan, 'Sesi contoh mengisi kerataan muka ukur.');

        [$s, $html] = $this->terbitkan('001-CAL-126');

        $this->assertSamaPetaJson([[
            'posisi' => TataLetakSertifikat::POSISI_BAWAH_HASIL,
            'judul' => 'Note :',
            'baris' => [],
            'tabel' => [['Kerataan Muka Ukur dalam kondisi :', ucfirst($kerataan)]],
        ]], $s->snapshot['catatan_blok']);
        $this->assertStringContainsString('Kerataan Muka Ukur dalam kondisi :', $html);
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
