<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\Enclosure\EnclosureProfileBase;
use App\Services\Calibration\TabelKalibratorEnclosure;
use Database\Seeders\DatabaseSeeder;
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
     * Yokogawa — baris yang BARU ditambahkan — benar-benar tertaut & terpakai.
     *
     * Dia ditambahkan mengikuti `FORM VALIDASI rev. 11` ("Add std kalibrator
     * yokogawa") karena kertas Rev.3 nggak memuatnya, padahal dia kalibrator
     * enclosure yang paling kepakai: master olah datanya sendiri bernama
     * `Master Olah Data_Suhu_Enclosure_Constant_Yokogawa.xlsm`.
     *
     * Baris yang ditambahkan tapi nggak tertaut LEBIH BURUK daripada nggak
     * ditambahkan sama sekali: teknisi melihat pilihan yang kelihatan sah,
     * mencentangnya, dan sesinya tetap nggak kehitung — sekarang tanpa petunjuk
     * apa pun bahwa yang salah pilihannya. Jadi yang diuji di sini rantai
     * penuhnya: tertaut, merk-nya kebaca, dan merk itu punya tabel koreksi.
     */
    public function test_yokogawa_tertaut_dan_punya_tabel(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $yoko = array_values(array_filter(
            $this->barisStandar('inkubator'),
            static fn (array $b): bool => str_contains(strtolower((string) ($b['label'] ?? '')), 'yokogawa'),
        ));

        $this->assertNotEmpty($yoko, 'Baris Yokogawa hilang dari daftar standar tercetak Enclosure.');

        $this->assertNotNull(
            $yoko[0]['standard_id'],
            'Baris Yokogawa tampil tapi NGGAK tertaut ke master standar. Teknisi bakal '
            .'mencentang pilihan yang kelihatan sah, dan sesinya tetap nggak kehitung.',
        );

        $merk = strtolower(trim((string) ($yoko[0]['merk'] ?? '')));

        $this->assertContains(
            $merk,
            TabelKalibratorEnclosure::MERK,
            "Merk baris Yokogawa (`{$merk}`) nggak dikenal TabelKalibratorEnclosure. "
            .'Tertaut tapi tanpa tabel koreksi = sesi tetap mati, cuma di langkah berikutnya.',
        );

        $this->assertSame('yokogawa', $merk, 'Baris Yokogawa tertaut ke standar yang merk-nya bukan Yokogawa.');
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

    /**
     * Baris Yokogawa tertaut ke KALIBRATORNYA, bukan ke sensor RTD-nya.
     *
     * Dua baris master berbagi nomor seri `23P1005`: "Termometer & Sensor Std."
     * (id lebih kecil) dan "Temperature Calibrator Yokogawa CA 150 Handy Cal" —
     * memang begitu, sensornya menempel di kalibrator itu. Dengan satu
     * pencarian yang menerima nama ATAU seri sekaligus, yang menang cuma yang
     * ID-nya lebih kecil.
     *
     * Merknya kebetulan sama-sama Yokogawa, jadi ANGKANYA nggak salah. Yang
     * salah nomor sertifikat & ketertelusuran yang ikut ke lembar — diambil
     * dari dokumen sensor, bukan dokumen kalibrator. Buat lab terakreditasi
     * itu tepat jenis kesalahan yang dicari auditor.
     */
    public function test_yokogawa_tertaut_ke_kalibrator_bukan_sensornya(): void
    {
        $this->seed(DatabaseSeeder::class);

        $kalibrator = Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail();
        $sensor = Standard::where('nama', 'Termometer & Sensor Std.')->firstOrFail();

        $this->assertSame(
            $kalibrator->serial_number,
            $sensor->serial_number,
            'Nomor serinya nggak lagi kembar — testnya nggak menguji apa pun.',
        );
        $this->assertLessThan($kalibrator->id, $sensor->id, 'Urutan ID-nya berubah — testnya jadi tumpul.');

        foreach (array_keys(self::lembarEnclosure()) as $kode) {
            foreach ($this->barisStandar($kode) as $baris) {
                if (! str_contains($baris['label'], 'Yokogawa')) {
                    continue;
                }

                $this->assertSame(
                    $kalibrator->id,
                    $baris['standard_id'],
                    "Lembar {$kode}: baris Yokogawa nunjuk standar id {$baris['standard_id']}, "
                    ."bukan kalibrator id {$kalibrator->id}.",
                );
            }
        }
    }

    /**
     * Baris standar nggak pernah tertaut ke master milik lab LAIN.
     *
     * Hari ini database masih satu organisasi, jadi belum ada yang kena. Yang
     * dijaga di sini hari onboarding lab kedua: tanpa saringan, nomor
     * sertifikat & ketertelusuran lab pertama muncul di lembar teknisi lab
     * kedua — dan `standard_id`-nya dipakai menurunkan kalibrator sesi, jadi
     * sesinya ditolak sistem dengan pesan menyebut kolom yang nggak pernah dia
     * ketik.
     */
    public function test_standar_lab_lain_nggak_bocor(): void
    {
        $this->seed(DatabaseSeeder::class);

        $alat = Equipment::where('serial_number', 'D132469')->firstOrFail();

        $milikLabIni = Standard::where('organization_id', $alat->organization_id)->pluck('id')->all();

        // Lab kedua yang beneran ada, lalu alatnya dipindah ke sana: master
        // lab pertama sekarang milik "lab lain" dari sudut pandang alat ini.
        $labKedua = Organization::create(['nama' => 'PT Lab Kedua', 'slug' => 'lab-kedua']);

        $alat->forceFill(['organization_id' => $labKedua->id])->save();

        $profil = app(CalibrationProfileRegistry::class)->untukAlat($alat);

        foreach ($profil->bentukLembarKerja(false, $alat)['bagian'] as $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            foreach ($bagian['baris'] as $baris) {
                $this->assertNotContains(
                    $baris['standard_id'],
                    $milikLabIni,
                    "Baris \"{$baris['label']}\" bocor ke standar milik lab lain (id {$baris['standard_id']}).",
                );
            }
        }
    }
}
