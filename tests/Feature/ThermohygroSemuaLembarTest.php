<?php

namespace Tests\Feature;

use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\TidsProfile;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\ThermohygroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kolom "Environmental Meter Used" wajib PUNYA ISI di tiap lembar.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * Tujuh lembar — TITS, TIDS, dan kelima Enclosure — mendeklarasikan
 * `thermohygro_standard_id` dengan `sumber: 'master_thermohygro'`, tapi nggak
 * ada satu pun yang mengisi `pilihan`-nya. `field()` memberi nilai bawaan `[]`,
 * layar teknisi menggambar dropdown dari daftar yang dibawa BENTUK (bukan dari
 * master standar), dan daftar kosong bikin dia jatuh ke cabang teks mati.
 *
 * Yang bikin ini mahal: nggak ada yang error. Lembarnya terbit, sesinya
 * tersimpan, sertifikatnya keluar — cuma `thermohygro_standard_id`-nya null,
 * jadi koreksi kondisi lingkungan berikut U95-nya nggak nempel ke unit mana
 * pun. Ini kelas kesalahan yang sama dengan Env. Condition tiga alat yang
 * meleset 10 Agustus 2026: bukan salah hitung, tapi tabel koreksi yang
 * terpakai punya unit yang berbeda.
 *
 * Sepuluh lembar lain nggak pernah kena karena mereka memang memanggil
 * pengisinya. Yang bolong justru tujuh lembar terbaru — persis pola yang sama
 * dengan template pindai yang berhenti di 7 dari 17.
 *
 * ## Kenapa disapu dari registry
 *
 * Sama alasannya dengan `SemuaProfilLembarKerjaTest`: lembar ke-18 ikut diuji
 * tanpa ada yang perlu ingat.
 */
class ThermohygroSemuaLembarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Lembar yang daftarnya memang BUKAN daftar thermohygro biasa.
     *
     * Ditulis berikut alasannya, bukan didiamkan — biar yang membaca tau ini
     * keputusan, bukan yang kelupaan.
     *
     * @var array<string, string>
     */
    private const DIKECUALIKAN = [
        'gas_detector' => 'disaring ke unit yang PUNYA kalibrasi tekanan lewat '
            .'`parameter_kondisi[tekanan]`, bukan ke daftar nama tercetak — dan cuma Thermobarometer '
            .'Lutron yang punya. Jumlahnya sengaja 1, bukan 7.',
    ];

    /**
     * Semua profil berlembar.
     *
     * @return array<string, array{CalibrationProfile}>
     */
    public static function semuaProfil(): array
    {
        $hasil = [];
        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $hasil[$profil->kode()] = [$profil];
        }

        if (count($hasil) < 17) {
            throw new \RuntimeException(
                'Registry cuma memulangkan '.count($hasil).' profil, di bawah lantai 17. '
                .'Sweep di berkas ini jadi nggak ngecek apa-apa buat yang hilang.',
            );
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * Field thermohygro di satu bentuk lembar, atau null kalau nggak ada.
     *
     * @return array<string, mixed>|null
     */
    private function fieldThermohygro(CalibrationProfile $profil): ?array
    {
        foreach ($profil->bentukLembarKerja(true)['bagian'] ?? [] as $bagian) {
            foreach ($bagian['field'] ?? [] as $field) {
                if (($field['kode'] ?? null) === 'thermohygro_standard_id') {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * INTI: dropdown-nya nggak boleh pulang kosong.
     *
     * Dibuktikan merah dulu — `isiPilihanThermohygro()` dicabut dari
     * `TitsProfile` bikin data set `tits` merah dengan sebutan lembarnya, dan
     * 16 lainnya tetap hijau.
     */
    #[DataProvider('semuaProfil')]
    public function test_tiap_lembar_punya_pilihan_thermohygro(CalibrationProfile $profil): void
    {
        $this->seed([OrganizationSeeder::class, ThermohygroSeeder::class]);

        $field = $this->fieldThermohygro($profil);

        $this->assertNotNull(
            $field,
            "Lembar `{$profil->kode()}` nggak punya kotak `thermohygro_standard_id` sama sekali. "
            .'Koreksi kondisi lingkungan butuh unit yang dipakai; tanpa kotak ini teknisi nggak bisa nyebutnya.',
        );

        $this->assertNotSame(
            [],
            $field['pilihan'] ?? [],
            "Kotak \"Environmental Meter Used\" di lembar `{$profil->kode()}` pulang KOSONG.\n\n"
            .'Ini nggak bikin error di mana pun — layar teknisi cuma menggambarnya sebagai teks mati, '
            .'sesinya tetap kesimpen dengan `thermohygro_standard_id` null, dan koreksi kondisi '
            ."lingkungan berikut U95-nya nggak nempel ke unit mana pun.\n\n"
            .'Perbaikannya: panggil `isiPilihanThermohygro()` di `bentukLembarKerja()` profil ini.',
        );
    }

    /**
     * Tiap pilihan harus menunjuk baris `standards` yang NYATA dan memang
     * thermohygro.
     *
     * Tanpa ini daftar boleh saja berisi id karangan dan test di atas tetap
     * hijau — panjangnya kan nggak nol.
     */
    #[DataProvider('semuaProfil')]
    public function test_tiap_pilihan_menunjuk_thermohygro_nyata(CalibrationProfile $profil): void
    {
        $this->seed([OrganizationSeeder::class, ThermohygroSeeder::class]);

        foreach ($this->fieldThermohygro($profil)['pilihan'] ?? [] as $pilihan) {
            $standar = Standard::find($pilihan['nilai']);

            $this->assertNotNull(
                $standar,
                "Lembar `{$profil->kode()}` menawarkan `{$pilihan['label']}` dengan id "
                ."`{$pilihan['nilai']}` yang nggak ada di tabel `standards`.",
            );

            $this->assertNotNull(
                $standar->parameter_kondisi,
                "Lembar `{$profil->kode()}` menawarkan `{$pilihan['label']}` (id {$pilihan['nilai']}), "
                .'tapi barisnya nggak punya `parameter_kondisi` — artinya dia KALIBRATOR, bukan '
                .'thermohygro. Koreksi kondisi lingkungannya bakal dicari di baris yang nggak menyimpannya.',
            );

            $this->assertSame(
                $pilihan['label'],
                $standar->nama,
                "Label `{$pilihan['label']}` di lembar `{$profil->kode()}` nunjuk ke standar bernama "
                ."`{$standar->nama}`. Teknisi milih satu unit, yang kesimpen unit lain.",
            );
        }
    }

    /**
     * Ketujuh unit master harus kepilih, kecuali lembar yang punya alasan.
     *
     * Mempersempit daftar ini sudah pernah dicoba dan hasilnya kebalikan dari
     * niatnya: sertifikat pH master `012-CAL-524` memakai TH-3, dan TH-3 nggak
     * ada di daftar yang dipersempit itu — teknisinya jadi nggak punya pilihan
     * yang benar sama sekali.
     *
     * Yang DIPERIKSA cuma keanggotaan, bukan grup Inlab/Insitu-nya. Grup itu
     * memang beda-beda per lembar karena yang menentukan CETAKANNYA:
     * `ConductivityProfile` & `TidsProfile` menaruh TH-7 di Insitu ngikut
     * formulirnya, lembar lain menaruhnya di Inlab. Mengunci grup di sini
     * bakal melawan keputusan yang sudah tercatat di profil-profilnya.
     */
    #[DataProvider('semuaProfil')]
    public function test_ketujuh_unit_master_kepilih(CalibrationProfile $profil): void
    {
        $this->seed([OrganizationSeeder::class, ThermohygroSeeder::class]);

        if (isset(self::DIKECUALIKAN[$profil->kode()])) {
            $this->markTestSkipped(
                "Lembar `{$profil->kode()}` dikecualikan: ".self::DIKECUALIKAN[$profil->kode()],
            );
        }

        $ditawarkan = array_map(
            static fn (array $p): string => $p['label'],
            $this->fieldThermohygro($profil)['pilihan'] ?? [],
        );
        sort($ditawarkan);

        $this->assertSame(
            ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'],
            $ditawarkan,
            "Lembar `{$profil->kode()}` nggak menawarkan ketujuh unit master. "
            .'Kalau lembar ini memang cuma boleh sebagian, tulis alasannya di DIKECUALIKAN — '
            .'jangan dibiarkan menyusut diam-diam.',
        );
    }

    /**
     * TIDS: keempat baris thermohygro di kop harus BENERAN ketaut.
     *
     * Kegagalan aslinya halus dan kelihatan bekerja: barisnya ada, labelnya
     * benar, cuma `standard_id`-nya selalu null. Penyebabnya
     * `baris_thermohygro` dicocokkan ke koleksi `whereNull('parameter_kondisi')`
     * milik `tautkanStandar()` — saringan buat KALIBRATOR, yang menurut
     * definisi nggak memuat satu pun thermohygro karena `ThermohygroSeeder`
     * SELALU mengisi kolom itu.
     *
     * Jadi yang diuji di sini bukan "barisnya ada", tapi "barisnya ketemu
     * orangnya".
     */
    public function test_baris_thermohygro_tids_ketaut_semua(): void
    {
        $this->seed([OrganizationSeeder::class, ThermohygroSeeder::class]);

        $baris = [];
        foreach (app(TidsProfile::class)->bentukLembarKerja(true)['bagian'] ?? [] as $bagian) {
            if (isset($bagian['baris_thermohygro'])) {
                $baris = $bagian['baris_thermohygro'];
            }
        }

        $this->assertCount(
            count(TidsProfile::THERMOHYGRO_TERCETAK),
            $baris,
            'Baris thermohygro di kop TIDS nggak selengkap yang tercetak di kertasnya.',
        );

        foreach ($baris as $b) {
            $this->assertTrue(
                $b['terdaftar'],
                "Baris `{$b['label']}` di kop TIDS nggak ketaut ke master `standards`. "
                .'Labelnya tetap kecetak dan `standard_id`-nya null, jadi gagalnya nggak bersuara — '
                .'cek saringan koleksi yang dipakai mencocokkan (thermohygro punya `parameter_kondisi`, '
                .'kalibrator nggak).',
            );

            $this->assertNotNull($b['standard_id'], "Baris `{$b['label']}` ketaut tapi `standard_id`-nya null.");
        }
    }
}
