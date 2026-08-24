<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kotak LOKASI (Inlab/Insitu) harus lengkap di SEMUA lembar kerja, bukan
 * sebagian — dan lengkapnya diuji lewat endpoint yang beneran dipanggil HP.
 *
 * ## Kenapa test ini nyapu semua profil
 *
 * Tiga kotaknya nyebar ketinggalan satu-satu, dan tiap ketinggalan gagal
 * dengan cara yang diam:
 *
 *  - `lokasi_nama` cuma ada di 2 dari 12 profil. Sepuluh sisanya: teknisi milih
 *    Insitu, nggak ada kotak buat nama pabriknya, dan `CertificateSnapshotBuilder`
 *    jatuh ke `room_id` yang masih nyimpen ruang lab dari sesi Inlab sebelumnya.
 *    Sertifikatnya kecetak nama ruang lab buat kerjaan yang nggak pernah masuk lab.
 *  - `room_id` nggak ada sama sekali di kelima profil Enclosure — oven & bath
 *    nggak punya cara nyimpen ruangannya sama sekali.
 *  - Label `lokasi`-nya campur: separuh lembar nulis "In lab", separuh "Inlab".
 *
 * Ngecek satu-satu per profil bakal ngelewatin profil ke-18. Makanya yang
 * diiterasi di sini DAFTAR REGISTRY, bukan daftar kode yang diketik ulang —
 * profil baru otomatis ikut kejaring begitu didaftarin.
 *
 * Nilai enum `lab`/`onsite` DIKUNCI di sini. Yang boleh berubah cuma labelnya;
 * ngutak-atik enumnya berarti migrasi kolom `calibration_sessions.lokasi`
 * sekaligus mutusin semua APK yang udah beredar.
 */
class LokasiLembarKerjaSemuaProfilTest extends TestCase
{
    use RefreshDatabase;

    /** Ejaan yang MENGIKAT buat pilihan `lokasi` — sama di semua lembar. */
    private const PILIHAN_LOKASI = [
        ['nilai' => 'lab', 'label' => 'Inlab'],
        ['nilai' => 'onsite', 'label' => 'Insitu'],
    ];

    private User $admin;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
    }

    /**
     * Tiga kotaknya ada di tiap profil terdaftar, lengkap sama penanda
     * tampil-bersyaratnya — dilihat dari mata teknisi DAN mata admin.
     *
     * Dua-duanya diuji karena penyaringan `hanya_admin` jalan di jalur yang
     * beda: kalau suatu saat ada yang nandain `room_id` administratif, layar
     * teknisi kehilangan kotaknya tanpa satu pun error.
     */
    public function test_semua_profil_punya_lokasi_nama_dan_ruangan_beserta_penandanya(): void
    {
        foreach ($this->daftarKodeProfil() as $kode) {
            foreach (['teknisi' => $this->teknisi, 'admin' => $this->admin] as $peran => $pengguna) {
                $konteks = "profil {$kode} ({$peran})";

                $field = $this->fieldLembar($kode, $pengguna, $konteks);

                $this->assertArrayHasKey('lokasi', $field, "{$konteks}: kotak `lokasi` nggak ada");
                $this->assertArrayHasKey('lokasi_nama', $field, "{$konteks}: kotak `lokasi_nama` nggak ada — sesi Insitu nggak punya tempat nyimpen nama pabriknya");
                $this->assertArrayHasKey('room_id', $field, "{$konteks}: kotak `room_id` nggak ada — sesi Inlab nggak punya tempat nyimpen ruangannya");

                // Pilihannya HARUS persis: nilai enum database + label seragam.
                $this->assertSame('pilihan', $field['lokasi']['tipe'], "{$konteks}: `lokasi` harus tipe pilihan");
                $this->assertSame(
                    self::PILIHAN_LOKASI,
                    $field['lokasi']['pilihan'],
                    "{$konteks}: pilihan `lokasi` harus persis Inlab/Insitu di atas nilai enum lab/onsite",
                );

                // Nama tempat = teks bebas, muncul cuma buat Insitu.
                $this->assertSame('teks', $field['lokasi_nama']['tipe'], "{$konteks}: `lokasi_nama` harus teks bebas");
                $this->assertSame(
                    CalibrationProfile::TAMPIL_KALAU_INSITU,
                    $field['lokasi_nama']['tampil_kalau'],
                    "{$konteks}: `lokasi_nama` harus ditandai cuma tampil kalau lokasi = onsite",
                );

                // Ruangan = pilihan dari master ruangan lab, muncul cuma buat Inlab.
                $this->assertSame('pilihan', $field['room_id']['tipe'], "{$konteks}: `room_id` harus tipe pilihan");
                $this->assertSame(
                    'master_ruangan',
                    $field['room_id']['sumber'],
                    "{$konteks}: `room_id` harus ngambil dari master ruangan, bukan teks bebas",
                );
                $this->assertSame(
                    CalibrationProfile::TAMPIL_KALAU_INLAB,
                    $field['room_id']['tampil_kalau'],
                    "{$konteks}: `room_id` harus ditandai cuma tampil kalau lokasi = lab",
                );
            }
        }
    }

    /**
     * `tampil_kalau` itu KUNCI KONTRAK, bukan tambahan sesekali: tiap field
     * ngirimnya (null = selalu tampil).
     *
     * Kalau ada field yang nggak ngirim sama sekali, HP nggak bisa bedain
     * "field ini emang selalu tampil" dari "backendnya versi lama yang belum
     * ngerti penanda" — dan bedanya itu yang nentuin dia berani berhenti
     * nge-hardcode nama field atau nggak.
     *
     * Sekalian dijaga penandanya nggak nunjuk field yang nggak ada di lembar
     * yang sama. Penanda menggantung = kotak yang syaratnya nggak pernah
     * kepenuhi, alias kotak yang nggak pernah muncul — dan itu kelihatannya
     * persis kayak field yang kelupaan dipasang.
     */
    public function test_penanda_tampil_kalau_ikut_di_tiap_field_dan_nggak_menggantung(): void
    {
        foreach ($this->daftarKodeProfil() as $kode) {
            $konteks = "profil {$kode}";
            $field = $this->fieldLembar($kode, $this->admin, $konteks);

            foreach ($field as $kodeField => $isi) {
                $this->assertArrayHasKey(
                    'tampil_kalau',
                    $isi,
                    "{$konteks}: field `{$kodeField}` nggak ngirim kunci `tampil_kalau`",
                );

                $penanda = $isi['tampil_kalau'];

                if ($penanda === null) {
                    continue;
                }

                $this->assertSame(
                    ['kode', 'nilai'],
                    array_keys($penanda),
                    "{$konteks}: penanda `tampil_kalau` di `{$kodeField}` bentuknya harus ['kode' => ..., 'nilai' => [...]]",
                );
                $this->assertIsArray($penanda['nilai'], "{$konteks}: `tampil_kalau.nilai` di `{$kodeField}` harus daftar");
                $this->assertNotEmpty($penanda['nilai'], "{$konteks}: `tampil_kalau.nilai` di `{$kodeField}` kosong — syaratnya nggak mungkin kepenuhi");

                $this->assertArrayHasKey(
                    $penanda['kode'],
                    $field,
                    sprintf(
                        '%s: field `%s` nunggu field `%s` yang nggak ada di lembar ini — kotaknya nggak bakal pernah muncul.',
                        $konteks,
                        $kodeField,
                        $penanda['kode'],
                    ),
                );
            }
        }
    }

    /**
     * Kode profil dari REGISTRY, bukan daftar yang diketik ulang di test.
     *
     * @return list<string>
     */
    private function daftarKodeProfil(): array
    {
        $kode = array_map(
            static fn (CalibrationProfile $p): string => $p->kode(),
            app(CalibrationProfileRegistry::class)->semua(),
        );

        // Pagar buat registry yang kosong/kepotong: sweep yang nol putaran
        // lolos tanpa satu pun assertion, dan hijaunya bohong.
        $this->assertGreaterThanOrEqual(17, count($kode), 'Registry profil kekurangan isi — sweep-nya jadi nggak ngecek apa-apa.');

        return $kode;
    }

    /**
     * Semua field satu lembar, dipetakan kode -> isi, diambil lewat endpoint
     * yang beneran dipanggil HP.
     *
     * Judulnya diadu ke profil yang diminta karena `lembarKerja()` JATUH KE
     * pH kalau kodenya nggak dikenal — tanpa error. Sweep yang cuma ngecek
     * "ada field lokasi_nama" bakal hijau semua sambil ngetes lembar pH
     * tujuh belas kali.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fieldLembar(string $kode, User $pengguna, string $konteks): array
    {
        $data = $this->actingAs($pengguna)
            ->getJson('/api/calibrations/lembar-kerja?profil='.$kode)
            ->assertOk()
            ->json('data');

        $this->assertSame(
            app(CalibrationProfileRegistry::class)->untukKode($kode)?->bentukLembarKerja()['judul'],
            $data['judul'] ?? null,
            "{$konteks}: lembar yang balik bukan punya profil ini",
        );

        $field = [];

        foreach ($data['bagian'] ?? [] as $bagian) {
            foreach ($bagian['field'] ?? [] as $isi) {
                $field[$isi['kode']] = $isi;
            }
        }

        return $field;
    }
}
