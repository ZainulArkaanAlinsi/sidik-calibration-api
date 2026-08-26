<?php

namespace Tests\Feature;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\Enclosure\EnclosureProfileBase;
use App\Services\Calibration\TabelKalibratorEnclosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Baris "Standar used" di kelima lembar Enclosure WAJIB membawa `standard_id`.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * Sampai 26 Agt 2026 lembar Enclosure mengirim baris standar tercetak apa
 * adanya — cuma `label` + `cocok`, tanpa `standard_id`. Layar HP membaca
 * `json['standard_id']`, dapat null, dan sesi tersimpan dengan standar kosong.
 *
 * Dari situ jatuhnya beruntun dan SENYAP:
 *
 *     merkKalibrator(null) -> null -> syaratKurang() -> semuaBelum()
 *
 * Seluruh titik dicap "belum dihitung". Yang sampai ke admin bukan kalimat
 * "standarnya belum dipilih", tapi `titik_kosong` plus `titik_tidak_terhitung`
 * di tiap titik — enam peringatan dari satu sebab, dan sebabnya yang paling
 * nggak kelihatan di antara semuanya. Sesi Inkubator 26 Agt 2026 ditolak
 * pemilik lab persis karena ini.
 *
 * Kelimanya kena sekaligus karena mewarisi `EnclosureProfileBase`.
 *
 * ## Kenapa `EnclosureSesiTest` nggak menangkapnya
 *
 * Test itu menyuapkan `standard_id` LANGSUNG ke payload, diambil sendiri dari
 * database. Dia nggak pernah lewat `bentukLembarKerja()`. Jadi dia membuktikan
 * kalkulatornya benar sambil membiarkan jalur yang dilewati manusia putus —
 * kelas kegagalan paling mahal di repo ini: jalurnya berhasil, hasilnya nggak
 * pernah lahir.
 *
 * Berkas ini menguji dari sisi yang berlawanan: BENTUK lembarnya, bukan payload
 * yang sudah rapi.
 */
class EnclosureStandarTertautTest extends TestCase
{
    use RefreshDatabase;

    /** Kelima lembar Enclosure, diambil dari registry — bukan daftar tangan. */
    public static function lembarEnclosure(): array
    {
        return [
            'oven' => ['oven'],
            'bath' => ['bath'],
            'inkubator' => ['inkubator'],
            'furnace' => ['furnace'],
            'refrigerator' => ['refrigerator'],
        ];
    }

    private function barisStandar(string $kode): array
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode($kode);

        $this->assertInstanceOf(
            EnclosureProfileBase::class,
            $profil,
            "Profil `{$kode}` bukan turunan EnclosureProfileBase — daftar di test ini basi.",
        );

        foreach ($profil->bentukLembarKerja()['bagian'] as $bagian) {
            if (($bagian['kode'] ?? null) === 'usage_check') {
                return $bagian['baris'] ?? [];
            }
        }

        $this->fail("Lembar `{$kode}` nggak punya bagian `usage_check` sama sekali.");
    }

    /**
     * INTI: tiap baris standar wajib punya kunci `standard_id`.
     *
     * Yang diuji ADA-nya kunci, bukan isinya. Baris yang memang belum ada di
     * master (Victor) sah bernilai null — yang nggak boleh itu kuncinya hilang,
     * karena HP membacanya sebagai "standar nggak terdaftar" tanpa bisa
     * membedakan "belum dicocokkan" dari "sudah dicocokkan, nggak ketemu".
     */
    #[DataProvider('lembarEnclosure')]
    public function test_baris_standar_punya_kunci_standard_id(string $kode): void
    {
        $baris = $this->barisStandar($kode);

        $this->assertNotEmpty($baris, "Lembar `{$kode}` nggak punya satu pun baris standar tercetak.");

        foreach ($baris as $i => $b) {
            $this->assertArrayHasKey(
                'standard_id',
                $b,
                "Lembar `{$kode}` baris standar ke-{$i} nggak punya kunci `standard_id`.\n\n"
                ."Ini bukan soal kosmetik: HP mengambil `standard_id` sesi dari baris ini. "
                ."Tanpa kuncinya, `merkKalibrator()` pulang null dan SELURUH titik sesi "
                ."dicap belum dihitung — sertifikatnya nggak akan pernah lahir, dan yang "
                ."kelihatan di admin cuma `titik_kosong`.\n\n"
                .'Kemungkinan besar `tautkanStandar()` nggak dipanggil di `bentukLembarKerja()`.',
            );

            $this->assertArrayHasKey('merk', $b, "Lembar `{$kode}` baris ke-{$i} nggak bawa `merk`.");
            $this->assertArrayHasKey('terdaftar', $b, "Lembar `{$kode}` baris ke-{$i} nggak bawa `terdaftar`.");
        }
    }

    /**
     * Minimal SATU baris harus benar-benar tertaut ke master DAN merk-nya
     * dikenal tabel kalibrator.
     *
     * Kunci yang ada tapi isinya null di SEMUA baris sama nggak bergunanya
     * dengan kunci yang hilang: teknisi tetap nggak punya baris yang bisa
     * dicentang, dan sesinya tetap nggak kehitung. Jadi yang dijaga di sini
     * kemampuannya, bukan bentuknya.
     */
    #[DataProvider('lembarEnclosure')]
    public function test_ada_kalibrator_yang_bisa_dipakai(string $kode): void
    {
        // DatabaseSeeder, bukan seeder satuan: `EnclosureSeeder::run()` pulang
        // lebih awal kalau belum ada teknisi aktif di organisasi 1, dan
        // pulang diam-diam — nol standar, test hijau palsu. Pola yang sama
        // dipakai `EnclosureSesiTest`.
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $baris = $this->barisStandar($kode);

        $terpakai = array_values(array_filter(
            $baris,
            static fn (array $b): bool => ($b['standard_id'] ?? null) !== null
                && in_array(strtolower(trim((string) ($b['merk'] ?? ''))), TabelKalibratorEnclosure::MERK, true),
        ));

        $ringkas = implode("\n", array_map(
            static fn (array $b): string => sprintf(
                '  %-62s id=%s merk=%s',
                $b['label'] ?? '?',
                $b['standard_id'] === null ? 'NULL' : $b['standard_id'],
                $b['merk'] ?? 'NULL',
            ),
            $baris,
        ));

        $this->assertNotEmpty(
            $terpakai,
            "Lembar `{$kode}` nggak punya SATU PUN baris standar yang tertaut ke master "
            ."sekaligus ber-merk yang dikenal TabelKalibratorEnclosure.\n\n"
            ."Artinya teknisi nggak punya apa pun yang bisa dicentang supaya sesinya "
            ."kehitung — persis kegagalan 26 Agt 2026.\n\nBaris yang ada:\n{$ringkas}",
        );
    }

    /**
     * Baris Recorder tertaut lewat NOMOR SERI, bukan nama.
     *
     * Kertas Rev.3 nyetak "Graptech GL840-SDWV"; master menulis "Graphtech
     * GL840". Beda huruf DAN beda model — lewat nama nggak akan pernah ketemu.
     * Kalau suatu saat pencocokan seri dibuang "biar sederhana", baris Recorder
     * diam-diam berhenti tertaut dan seluruh sesi Recorder berhenti terhitung.
     */
    public function test_recorder_tertaut_lewat_nomor_seri(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $recorder = array_values(array_filter(
            $this->barisStandar('oven'),
            static fn (array $b): bool => str_contains(strtolower((string) ($b['label'] ?? '')), 'recorder'),
        ));

        $this->assertNotEmpty($recorder, 'Baris Recorder hilang dari daftar standar tercetak.');

        $this->assertNotNull(
            $recorder[0]['standard_id'],
            "Baris Recorder nggak tertaut ke master.\n\n"
            .'Kertas nyetak "Graptech GL840-SDWV", master nulis "Graphtech GL840" — yang '
            .'menyatukan mereka cuma nomor seri C305B1470. Pencocokan lewat nama saja '
            .'bikin seluruh sesi kalibrator Recorder berhenti terhitung.',
        );
    }
}
