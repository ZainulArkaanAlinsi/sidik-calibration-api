<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\DataTampilanSertifikat;
use App\Support\Angka;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Instrumen analitik mencetak U95 PER TITIK, bukan satu angka untuk satu tabel.
 *
 * ## Asalnya
 *
 * Permintaan pemilik lab (Pak Rohman, 3 Sep 2026): di sertifikat instrumen
 * analitik, nilai Uncertainty harus muncul di tiap titik pengukuran.
 *
 * Sebelum disetel, diukur dulu ke seluruh 24 sertifikat bawaan — dan yang
 * ditemukan bukan soal selera bentuk, tapi informasi yang hilang:
 *
 *     pH Meter            0,023 / 0,021 / 0,031 pH      → tercetak satu angka
 *     Conductivity Meter  0,499 / 8,109 / 1,7 µS/cm     → tercetak satu angka
 *     Turbidimeter        0,041 / 3,1  / 22 NTU         → tercetak satu angka
 *     Refractometer       0,000527 / 0,00053 nD         → tercetak satu angka
 *
 * Turbidimeter yang paling parah: rentangnya 537 kali lipat, dan satu angka
 * buat seluruh tabel menyatakan ketidakpastian titik 0,041 NTU sebesar 22 NTU.
 *
 * ## Yang dijaga di sini
 *
 * Bukan "flag-nya `true`" — itu cuma menegaskan ulang kode. Yang diadu: TIAP
 * nilai U95 per titik benar-benar muncul di HTML yang dirender. Kalau kolomnya
 * hilang lagi, atau blade balik mengambil `first()`, dua dari tiga angka
 * berhenti sampai ke dokumen dan test ini merah.
 */
class U95PerTitikInstrumenAnalitikTest extends TestCase
{
    use RefreshDatabase;

    /** Alat yang U95-nya wajib per titik, beserta cacah titik yang diharapkan. */
    private const WAJIB_PER_TITIK = [
        'pH Meter',
        'Conductivity Meter',
        'Turbidimeter',
        'Refractometer',
    ];

    public function test_tiap_titik_analitik_nyetak_u95_nya_sendiri(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $tampilan = app(DataTampilanSertifikat::class);
        $registry = app(CalibrationProfileRegistry::class);
        $diperiksa = [];

        foreach (CalibrationSession::query()->with('equipment')->get() as $sesi) {
            $alat = $sesi->equipment?->nama_alat;

            if ($alat === null || ! in_array($alat, self::WAJIB_PER_TITIK, true)) {
                continue;
            }

            $this->assertTrue(
                $registry->untukAlat($sesi->equipment)->u95PerTitik(),
                "{$alat}: profilnya berhenti mencetak U95 per titik.",
            );

            $sertifikat = $this->terbitkan($sesi);
            $snapshot = $sertifikat->snapshot;

            $this->assertTrue(
                $snapshot['u95_per_titik'] ?? false,
                "{$alat}: snapshot-nya nggak membekukan bentuk per-titik, jadi "
                .'sertifikatnya bakal tetap nyetak satu angka.',
            );

            // Judul kolom WAJIB `U95% (±)`, bukan `k=2`: `k` alat-alat ini lahir
            // per titik, jadi mengunci 2 di judul itu pernyataan yang salah.
            $this->assertNull(
                $snapshot['faktor_cakupan_tetap'] ?? null,
                "{$alat}: faktor cakupannya kekunci, judul kolomnya bakal nulis "
                .'`k=…` padahal `k` tiap titiknya beda.',
            );

            $html = view('sertifikat.pdf', [
                ...$tampilan->untuk($sertifikat),
                'paksaPadat' => false,
            ])->render();

            // Judul kolomnya diadu HARFIAH. Tanpa ini, kolom U95 bisa hilang
            // sama sekali dan test tetap hijau selama angkanya kebetulan muncul
            // di tempat lain di halaman.
            $this->assertStringContainsString(
                'U<sub>95%</sub> (±)',
                $html,
                "{$alat}: kepala kolom `U95% (±)` nggak ada di sertifikatnya.",
            );

            // Tiap nilai dicari DI DALAM barisnya sendiri, bukan di seluruh
            // dokumen. Versi pertama test ini menyapu seluruh halaman — dan di
            // situ satu angka yang kebetulan tercetak di baris lain bisa
            // memuaskan beberapa titik sekaligus, jadi yang terbukti cuma
            // "angkanya ada di suatu tempat".
            $baris = self::barisTabel($html);
            $titik = 0;

            foreach ($snapshot['hasil'] ?? [] as $hasil) {
                $ke = $hasil['titik_ke'] ?? null;

                // Null DIHITUNG, bukan dilewati diam-diam: sertifikat yang
                // seluruh U95-nya null bakal bikin loop ini nol putaran dan
                // test-nya hijau tanpa menguji apa pun.
                $this->assertNotNull(
                    $hasil['u95'] ?? null,
                    "{$alat} titik ke-{$ke}: U95-nya null, padahal alat ini wajib punya U95 tiap titik.",
                );

                $tercetak = Angka::hasil(
                    (float) $hasil['u95'],
                    $hasil['desimal_u95'] ?? $hasil['desimal'] ?? 4,
                );

                $this->assertArrayHasKey(
                    $titik,
                    $baris,
                    "{$alat}: baris tabel ke-{$titik} nggak ada di HTML-nya.",
                );

                $this->assertStringContainsString(
                    $tercetak,
                    $baris[$titik],
                    sprintf(
                        '%s titik ke-%s: U95 %s nggak kecetak DI BARISNYA sendiri (baris: %s).',
                        $alat,
                        $ke ?? '?',
                        $tercetak,
                        trim(preg_replace('/\s+/', ' ', $baris[$titik]) ?? ''),
                    ),
                );

                $titik++;
            }

            $this->assertGreaterThan(
                1,
                $titik,
                "{$alat}: cuma {$titik} titik kesapu — U95 per titik nggak berarti apa-apa di satu titik.",
            );

            $diperiksa[] = $alat;
        }

        sort($diperiksa);

        // Tanpa ini, seeder yang berhenti membuat salah satu alat bikin test-nya
        // hijau tanpa menguji apa pun — gagal SUNYI, persis yang paling mahal.
        $this->assertSame(
            ['Conductivity Meter', 'Conductivity Meter', 'Refractometer', 'Turbidimeter', 'pH Meter'],
            $diperiksa,
            'Daftar alat analitik yang kesapu berubah. Nama yang HILANG = alat itu '
            .'berhenti diuji; nama BARU = seeder nambah sesi, perbarui daftarnya.',
        );
    }

    /**
     * Sertifikat sesi ini, diterbitkan lewat endpoint approve kalau belum ada.
     *
     * Penjaga "sudah ada" itu WAJIB, bukan pengoptimalan: seeder menerbitkan
     * sebagian sesi duluan, dan approve kedua kalinya ditolak 422 dengan
     * "cuma sesi `menunggu_approval` yang bisa disetujui".
     */
    private function terbitkan(CalibrationSession $sesi): Certificate
    {
        $ada = $sesi->certificate()->first();

        if ($ada !== null) {
            return $ada;
        }

        $this->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    /**
     * Isi tiap `<tr>` di tabel hasil, sudah dibuang tag-nya.
     *
     * Dipakai supaya nilai U95 tiap titik diadu ke BARISNYA sendiri. Mencari ke
     * seluruh dokumen bikin satu angka bisa memuaskan beberapa titik sekaligus
     * — yang terbukti cuma angkanya ada di suatu tempat, bukan di tempat yang
     * benar.
     *
     * @return list<string>
     */
    private static function barisTabel(string $html): array
    {
        $awal = strpos($html, '<table class="data"');

        if ($awal === false) {
            return [];
        }

        preg_match_all('#<tr[^>]*>(.*?)</tr>#s', substr($html, $awal), $cocok);

        $baris = [];

        foreach ($cocok[1] ?? [] as $isi) {
            // Baris kepala tabel dilewati — yang dicari baris DATA.
            if (str_contains($isi, '<th')) {
                continue;
            }

            $baris[] = html_entity_decode(strip_tags($isi));
        }

        return $baris;
    }
}
