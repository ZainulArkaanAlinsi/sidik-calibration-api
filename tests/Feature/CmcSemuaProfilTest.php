<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tiap profil WAJIB punya baris kemampuan (CMC)-nya sendiri.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * `GumCalculator::hitungTitik()` milih jalur hitung dari DATA yang ketemu,
 * bukan dari jenis alatnya. Kalau `kemampuanUntukTitik()` nggak nemu baris,
 * dia jatuh ke `hitungDariStandarDanResolusi()` — jalur paling menyederhanakan,
 * tanpa lantai CMC dan tanpa tiga dari lima komponen budget.
 *
 * Jadi profil ke-18 yang mendarat tanpa seeder CMC-nya bakal: lembarnya kebuka,
 * sesinya kesimpen, sertifikatnya terbit, U95-nya lebih KECIL dari yang
 * diakreditasi — dan nggak ada satu pun test yang merah.
 *
 * ## Ini melengkapi penjaga runtime, bukan menggandakannya
 *
 * `CalibrationValidator::periksaAlatTanpaCmc()` sudah menahan kasus ini di
 * tingkat SESI: alat yang kelihatan tertaut ke kemampuan tapi angkanya belum
 * ada bikin peringatan yang harus dilewati admin secara sadar. Bedanya waktu:
 * penjaga itu baru bersuara sesudah ada teknisi yang menjalankan sesi nyata,
 * dan yang lewat cuma peringatan — bukan penghenti.
 *
 * Yang di sini bersuara di CI, sebelum alatnya sampai ke tangan teknisi.
 *
 * ## Kenapa disapu dari registry
 *
 * Sama alasannya dengan `SemuaProfilLembarKerjaTest` &
 * `ThermohygroSemuaLembarTest`: alat ke-18 ikut diuji tanpa ada yang perlu
 * ingat. `EnclosureProfilTest` sudah melakukan pemeriksaan serupa, tapi cuma
 * buat kelima profil Enclosure — dan yang bolong di repo ini selalu profil yang
 * nggak lagi disentuh.
 */
class CmcSemuaProfilTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Profil yang nama kemampuannya SENGAJA nggak ada di lampiran akreditasi.
     *
     * Ditulis berikut alasannya, bukan didiamkan. Menambah nama ke sini itu
     * keputusan yang harus diambil sadar — bukan cara bikin test hijau.
     *
     * @var array<string, string>
     */
    private const DILUAR_LAMPIRAN = [
        'Gas Detector' => 'KAN belum mengakreditasi gas detector sama sekali — kolom CMC di '
            .'`Gas Detector Uli Skin (std Rigaz).xlsm` (DATABASE!S5:S8) kosong seluruhnya. Barisnya tetap '
            .'ada dengan CMC nol supaya jalur budget penuh tetap jalan; lihat `GasDetectorCapabilitySeeder`.',

        'Spectrophotometer' => 'lampiran menulis ejaan Indonesia "Spektrofotometer", profil & master Excel '
            .'menulis "Spectrophotometer". Yang dipakai ejaan profil karena itu kunci pencocokan ke '
            .'`equipments.nama_alat_kemampuan` — mengubahnya memutus tautan alat pelanggan yang sudah '
            .'terdaftar. Perlu diseragamkan lab; lihat K13 di `docs/permintaan-user-7.md`.',
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
     * Nama alat yang tercatat di lampiran akreditasi LK-285-IDN.
     *
     * @return list<string>
     */
    private function namaDiLampiran(): array
    {
        $data = json_decode(
            (string) file_get_contents(base_path('database/data/kemampuan-kalibrasi.json')),
            true,
        );

        $nama = [];
        foreach ($data['kelompok_pengukuran'] as $kelompok) {
            foreach ($kelompok['alat'] as $alat) {
                $nama[] = $alat['nama_alat'];
            }
        }

        return $nama;
    }

    /**
     * INTI: barisnya harus ADA.
     *
     * Dibuktikan merah dulu — `DoMeterCapabilitySeeder` dicabut dari
     * `DatabaseSeeder` bikin cuma data set `do_meter` merah, 16 lainnya hijau.
     */
    #[DataProvider('semuaProfil')]
    public function test_tiap_profil_punya_baris_kemampuan(CalibrationProfile $profil): void
    {
        $this->seed(DatabaseSeeder::class);

        $jumlah = CalibrationCapability::where('nama_alat', $profil->namaAlatKemampuan())->count();

        $this->assertGreaterThan(
            0,
            $jumlah,
            "Profil `{$profil->kode()}` (`{$profil->namaAlatKemampuan()}`) nggak punya satu pun baris "
            ."`calibration_capabilities`.\n\n"
            .'Akibatnya BUKAN error: `GumCalculator::kemampuanUntukTitik()` pulang null, hitungannya jatuh '
            .'ke `hitungDariStandarDanResolusi()`, dan sertifikatnya terbit dengan U95 yang lebih KECIL '
            ."daripada yang diakreditasi — tanpa satu pun tanda.\n\n"
            .'Perbaikannya: bikin seeder CMC-nya dan daftarkan di `DatabaseSeeder`.',
        );
    }

    /**
     * Barisnya ada, tapi angkanya belum diisi — keadaan yang PALING menipu.
     *
     * `ketidakpastian_terbaik` NULL bikin `punyaKlaimCmc()` false: tautannya
     * kelihatan sah di panel admin, tapi lantai CMC-nya nggak pernah kepasang.
     * Bedanya dengan NOL penting dan disengaja — nol artinya "lab nggak
     * mengklaim CMC buat rentang ini" (Gas Detector, dan lima rentang
     * Viscometer di luar ruang lingkup), dan itu keadaan SAH yang ada
     * `keterangan`-nya masing-masing.
     */
    #[DataProvider('semuaProfil')]
    public function test_nggak_ada_baris_kemampuan_yang_angkanya_kosong(CalibrationProfile $profil): void
    {
        $this->seed(DatabaseSeeder::class);

        $kosong = CalibrationCapability::where('nama_alat', $profil->namaAlatKemampuan())
            ->whereNull('ketidakpastian_terbaik')
            ->pluck('parameter')
            ->all();

        $this->assertSame(
            [],
            $kosong,
            "Profil `{$profil->kode()}` punya baris kemampuan yang `ketidakpastian_terbaik`-nya NULL: "
            .implode(', ', array_map(static fn ($p): string => (string) $p, $kosong))."\n\n"
            .'NULL beda dari NOL. Nol = lab sengaja nggak mengklaim CMC buat rentang itu (sah, dan wajib '
            .'ada `keterangan`-nya). NULL = tautannya kelihatan sah tapi angkanya belum ada, dan itu cuma '
            .'ketahuan sebagai peringatan waktu sesi nyata dijalankan.',
        );
    }

    /*
     * SENGAJA NGGAK ADA: "tiap baris kemampuan punya kategori".
     *
     * Sempat ditulis, lalu dibuang — karena nggak akan pernah bisa merah.
     * `equipment_category_id` dan `organization_id` dua-duanya NOT NULL di
     * level database (migrasi `2026_08_24_100000`), jadi baris tanpa kategori
     * ditolak MySQL sebelum sampai ke assertion mana pun.
     *
     * Test yang mustahil gagal itu lebih buruk daripada nggak ada test: dia
     * menambah angka hijau dan rasa aman tanpa memeriksa apa pun. Penjagaannya
     * memang sudah ada, cuma bukan di sini — dan penjaga di level skema lebih
     * kuat daripada penjaga di level test.
     */

    /**
     * Ejaan nama alat harus cocok lampiran akreditasi, atau punya alasan.
     *
     * Ini jebakan yang docblock `EnclosureProfilTest` sendiri sudah mewanti:
     * "ejaan `namaAlatKemampuan()` yang meleset satu huruf dari baris CMC nggak
     * kelihatan dari baca kode". Bedanya, di sana yang dijaga cuma lima profil
     * Enclosure.
     *
     * Yang dijaga di sini ejaan LAWAN LAMPIRAN, bukan lawan tabel — tabelnya
     * sudah dijaga tiga test di atas. Gunanya menahan profil baru yang nama
     * kemampuannya diketik sendiri dan nggak pernah diadu ke dokumen yang
     * mengikat lab.
     */
    #[DataProvider('semuaProfil')]
    public function test_nama_kemampuan_ada_di_lampiran_atau_punya_alasan(CalibrationProfile $profil): void
    {
        $nama = $profil->namaAlatKemampuan();

        if (isset(self::DILUAR_LAMPIRAN[$nama])) {
            $this->assertNotSame('', self::DILUAR_LAMPIRAN[$nama], 'Alasan pengecualian nggak boleh kosong.');

            return;
        }

        $this->assertContains(
            $nama,
            $this->namaDiLampiran(),
            "Profil `{$profil->kode()}` mengaku `{$nama}`, tapi nama itu nggak ada di lampiran "
            ."akreditasi `database/data/kemampuan-kalibrasi.json`.\n\n"
            .'Kalau ejaannya yang meleset, betulin di profilnya — TAPI cek dulu: nama ini kunci '
            .'pencocokan ke `equipments.nama_alat_kemampuan`, jadi mengubahnya memutus tautan alat '
            ."pelanggan yang sudah terdaftar.\n\n"
            .'Kalau alatnya memang belum diakreditasi, masukkan ke DILUAR_LAMPIRAN berikut alasannya.',
        );
    }
}
