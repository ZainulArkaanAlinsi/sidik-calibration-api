<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Organization;
use App\Models\PaketAcuanSuntingan;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Acuan\SumberAcuan;
use App\Services\Studio\PetaSel\PemeriksaMaster;
use App\Services\Studio\PetaSel\PetaSel;
use App\Services\Studio\PetaSel\PetaSelRusak;
use App\Services\Studio\Workbook\GagalBacaWorkbook;
use App\Services\Studio\Workbook\ImporWorkbook;
use App\Services\Studio\Workbook\PembacaCsvWorkbook;
use App\Support\JsonKanonik;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SesiDataAcuan;
use Tests\TestCase;

/**
 * Peta sel Micrometer end-to-end (rencana studio-kendali-olah-data fase 1, 2,
 * 4 — 22 Okt 2026).
 *
 * Masternya ekspor CSV ASLI keempat workbook Micrometer di repo
 * (`Project-PT-Sidik/alat-alat-Pt-Sidik/Panjang_CSV/...`), bukan fixture
 * karangan: ekstraksi peta dari keempatnya harus menghasilkan
 * `tabel-standar-micrometer.json` byte-kanonik demi byte-kanonik — JSON yang
 * dulu dibangkitkan generator dari `.xlsm` yang sama.
 */
class StudioPetaSelMicrometerTest extends TestCase
{
    use RefreshDatabase;
    use SesiDataAcuan;

    private const AKAR = 'Project-PT-Sidik/alat-alat-Pt-Sidik/Panjang_CSV/';

    private const MASTER = [
        'A' => 'Master_Olah_Data_Micrometer_0-25mm',
        'B' => 'Master_Olah_Data_Micrometer_25-50mm',
        'C' => 'Master_Olah_Data_Micrometer_50-75mm',
        'D' => 'Master_Olah_Data_Micrometer_75-100mm',
    ];

    /** `balok_ukur['19.0']` di JSON = 19,00011; master v2 menggesernya 2 µm. */
    private const BALOK_19_LAIN = '19.00211';

    private string $temp = '';

    protected function setUp(): void
    {
        parent::setUp();
        SumberAcuan::lupakanCache();
        config(['studio.peta_sel.direktori' => database_path('data/peta-sel')]);
    }

    protected function tearDown(): void
    {
        if ($this->temp !== '' && is_dir($this->temp)) {
            (new Filesystem)->deleteDirectory($this->temp);
        }

        parent::tearDown();
    }

    private function peta(): PetaSel
    {
        return PetaSel::untuk('micrometer') ?? $this->fail('peta micrometer tidak ada');
    }

    /** @return array<array-key, mixed> */
    private function jsonAcuan(): array
    {
        return json_decode((string) file_get_contents(database_path('data/tabel-standar-micrometer.json')), true);
    }

    /** Salinan folder CSV master asli ke temp, untuk diubah. */
    private function salin(string $nama): string
    {
        $this->temp = $this->temp !== '' ? $this->temp : sys_get_temp_dir().DIRECTORY_SEPARATOR.'sidik-petasel-'.bin2hex(random_bytes(5));
        $tujuan = $this->temp.DIRECTORY_SEPARATOR.$nama;
        (new Filesystem)->copyDirectory(base_path(self::AKAR.$nama), $tujuan);

        return $tujuan;
    }

    /**
     * Ubah sel CSV (1-basis baris & kolom).
     *
     * @param  callable(list<list<string>>): list<list<string>>  $ubah
     */
    private function ubahCsv(string $berkas, callable $ubah): void
    {
        $h = fopen($berkas, 'rb');
        $baris = [];

        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $baris[] = $r;
        }

        fclose($h);
        $baris[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $baris[0][0]);
        $baris = $ubah($baris);
        $h = fopen($berkas, 'wb');

        foreach ($baris as $r) {
            fputcsv($h, $r, ',', '"', '');
        }

        fclose($h);
    }

    // ---- peta & bentuknya -------------------------------------------------

    public function test_peta_micrometer_sah_dan_bentuk_rusak_ditolak(): void
    {
        $peta = $this->peta();
        $this->assertSame('micrometer', $peta->kodeProfil);
        $this->assertSame([], PetaSel::validasi('micrometer', $peta->isi));

        $rusak = $peta->isi;
        $rusak['jangkar'][0]['sel'] = 'TIDAKADA!S3';
        $rusak['ekstrak'][0]['tipe'] = 'karangan';
        $rusak['ekstrak'][14]['jalur'] = 'cmc[kode={varian}].titik[{i}]'; // {i} tanpa ulang
        $rusak['sumber_json'] = 'lain.json';
        $masalah = implode(' | ', PetaSel::validasi('micrometer', $rusak));

        $this->assertStringContainsString("lembar 'TIDAKADA'", $masalah);
        $this->assertStringContainsString('ekstrak[0]: tipe', $masalah);
        $this->assertStringContainsString('ekstrak[14]: {i}', $masalah);
        $this->assertStringContainsString("sumber_json harus 'tabel-standar-micrometer.json'", $masalah);

        // Berkas peta rusak = gagal keras, bukan "belum terpetakan".
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sidik-petasel-rusak-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/micrometer.json', json_encode($rusak));
        config(['studio.peta_sel.direktori' => $dir]);

        try {
            $this->expectException(PetaSelRusak::class);
            PetaSel::untuk('micrometer');
        } finally {
            (new Filesystem)->deleteDirectory($dir);
        }
    }

    public function test_profil_tanpa_peta_belum_terpetakan(): void
    {
        $this->assertNull(PetaSel::untuk('ph_meter'));
        $this->assertNull(PetaSel::untuk(null));
        $this->assertNull(PetaSel::untuk('../micrometer'));
    }

    // ---- paritas: master v1 = JSON hari ini ---------------------------------

    /**
     * Ekstraksi peta dari KEEMPAT master asli, masing-masing di atas dasar
     * JSON repo, memulangkan isi yang IDENTIK (sha256 kanonik) dengan
     * `tabel-standar-micrometer.json`. Ini yang membuktikan alamat peta =
     * alamat generator.
     */
    public function test_ekstraksi_keempat_master_asli_identik_dengan_json(): void
    {
        $peta = $this->peta();
        $json = $this->jsonAcuan();
        $shaJson = JsonKanonik::sha256(JsonKanonik::serialisasi($json));

        foreach (self::MASTER as $varian => $nama) {
            $lembar = app(PembacaCsvWorkbook::class)->baca(base_path(self::AKAR.$nama))['lembar'];
            $hasil = app(PemeriksaMaster::class)->periksa($peta, $nama, $lembar, $json);

            $this->assertSame($varian, $hasil['varian'], $nama);
            $this->assertSame($shaJson, JsonKanonik::sha256(JsonKanonik::serialisasi($hasil['isi'])), "{$nama}: isi ekstraksi beda dari JSON");
            // 5 standar + balok + 8 cmc + pra-evaluasi + 11 titik.
            $this->assertCount(26, $hasil['jalur'], $nama);
        }

        // Pembanding: ekstraksi memang MENULIS (bukan cuma membawa dasar) —
        // dasar yang dirusak dipulihkan oleh master.
        $rusak = $json;
        $rusak['balok_ukur']['19.0'] = 0.0;
        $rusak['cmc'][1]['u95_um'] = 9.99;
        $rusak['cmc'][1]['titik'][0]['tumpukan_mm'] = [];
        $lembar = app(PembacaCsvWorkbook::class)->baca(base_path(self::AKAR.self::MASTER['B']))['lembar'];
        $hasil = app(PemeriksaMaster::class)->periksa($peta, self::MASTER['B'], $lembar, $rusak);
        $this->assertSame($shaJson, JsonKanonik::sha256(JsonKanonik::serialisasi($hasil['isi'])));
    }

    // ---- validasi struktur: master berubah DITOLAK --------------------------

    public function test_master_bergeser_hilang_atau_salah_tipe_ditolak_dengan_pesan_jelas(): void
    {
        $lab = Organization::factory()->create();

        // 1) DATABASE digeser satu baris ke bawah.
        $geser = $this->salin(self::MASTER['A']);
        $this->ubahCsv($geser.'/DATABASE.csv', static fn (array $b): array => [[''], ...$b]);
        $pesan = $this->ditolak($geser, $lab);
        $this->assertStringContainsString("jangkar DATABASE!S3 seharusnya 'Jenis/Rentang'", $pesan);
        $this->assertStringContainsString('sekarang ada di DATABASE!S4 (struktur bergeser)', $pesan);

        // 2) Lembar wajib hilang.
        (new Filesystem)->deleteDirectory($this->temp);
        $hilang = $this->salin(self::MASTER['A']);
        unlink($hilang.'/Standar_GB.csv');
        $this->assertStringContainsString("lembar wajib 'Standar_GB' tidak ada", $this->ditolak($hilang, $lab));

        // 3) Sel angka berisi teks.
        (new Filesystem)->deleteDirectory($this->temp);
        $teks = $this->salin(self::MASTER['A']);
        $this->ubahCsv($teks.'/DATABASE.csv', static function (array $b): array {
            $b[4][19] = 'lihat lampiran'; // T5

            return $b;
        });
        $this->assertStringContainsString("DATABASE!T5 berisi 'lihat lampiran', bukan angka", $this->ditolak($teks, $lab));

        // 4) Nama tanpa varian yang dikenali.
        $this->assertStringContainsString('varian tidak dikenali', $this->ditolak($teks, $lab, 'Master Micrometer Baru'));

        $this->assertSame(0, WorkbookMaster::query()->count(), 'master yang ditolak tidak meninggalkan baris');
        $this->assertSame(0, PaketAcuanVersi::query()->count());
    }

    private function ditolak(string $folder, Organization $lab, ?string $nama = null): string
    {
        foreach ([true, false] as $kering) {
            try {
                app(ImporWorkbook::class)->imporCsv($folder, $nama ?? basename($folder), 'uji', $lab, null, $kering);
                $this->fail('master yang strukturnya berubah seharusnya ditolak'.($kering ? ' (kering)' : ''));
            } catch (GagalBacaWorkbook $e) {
                $this->assertSame(GagalBacaWorkbook::STRUKTUR_BERUBAH, $e->kode);
                $pesan = $e->getMessage();
            }
        }

        return $pesan;
    }

    public function test_tanpa_paket_impor_melaporkan_setara_json_tanpa_draf(): void
    {
        $lab = Organization::factory()->create();

        $r = app(ImporWorkbook::class)->imporCsv(base_path(self::AKAR.self::MASTER['A']), self::MASTER['A'], 'uji', $lab, null, false);

        $this->assertSame(['baru', 'tervalidasi', 'paket_belum_ada', true, 'A'], [
            $r['status'], $r['peta_sel'], $r['acuan']['status'], $r['acuan']['sama_dengan_dasar'], $r['acuan']['varian'],
        ]);
        $this->assertStringContainsString('Peta sel micrometer: struktur tervalidasi (varian A).', (string) WorkbookMaster::query()->sole()->catatan);
        $this->assertSame(0, PaketAcuanVersi::query()->count(), 'tanpa paket tidak ada versi yang dikarang');
    }

    // ---- master v2 → draf → hanya berlaku sesudah disahkan ------------------

    /**
     * Alur penuh di atas versi 1 hasil `acuan:impor-awal`:
     *  1. master v1 asli diimpor → sama dengan v1, tidak ada draf;
     *  2. master v2 (balok 19 mm digeser 2 µm) diimpor → DRAF v2 + suntingan;
     *  3. sesi baru tetap dihitung v1 selama v2 masih draf;
     *  4. sesudah v2 berlaku, sesi baru dihitung v2 (angkanya bergeser), sesi
     *     berstempel v1 dihitung ulang tetap v1 — angka identik.
     * Angka v1 sendiri = angka jalur JSON (paritas versi 1).
     */
    public function test_master_baru_mengubah_hasil_hanya_sesudah_berlaku_dan_tidak_untuk_sesi_v1(): void
    {
        $this->siapkanAcuan();
        $org = Organization::query()->findOrFail($this->teknisi->organization_id);
        $admin = User::query()->where('organization_id', $org->id)->where('role', User::ROLE_ADMIN)->firstOrFail();

        // Paritas v1: angka berstempel v1 = angka jalur JSON.
        config(['acuan.sumber' => 'json']);
        $idJson = $this->simpanSesi($this->payloadMicrometer());
        config(['acuan.sumber' => 'db']);
        $idV1 = $this->simpanSesi($this->payloadMicrometer());
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($idV1));
        $this->assertEquals($this->angkaSesi($idJson), $this->angkaSesi($idV1));
        $angkaV1 = $this->angkaSesi($idV1);
        // Hitung ulang menulis presisi penuh (store membulatkan ke kolom) — acuan v1 untuk langkah 4.
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$idV1]])->assertSuccessful();
        $ulangV1 = $this->angkaSesi($idV1);
        // Pembanding bertanggal sama dengan sesi sesudah v2 (umur drift standar ikut tanggal).
        $idV1Juli = $this->simpanSesi($this->payloadMicrometer(['tanggal_kalibrasi' => '2025-07-01']));
        $angkaV1Juli = $this->angkaSesi($idV1Juli);

        // 1) Master asli (varian B) = v1 → tidak ada draf.
        $impor = app(ImporWorkbook::class);
        $r1 = $impor->imporCsv(base_path(self::AKAR.self::MASTER['B']), self::MASTER['B'], 'uji', $org, $admin->id, false);
        $this->assertSame(['sama_dengan_dasar', $this->v1->id], [$r1['acuan']['status'], $r1['acuan']['versi_id']]);
        $this->assertSame(1, PaketAcuanVersi::query()->count());

        // 2) Master v2: Standar_GB baris nominal 19 → 19,00211.
        $v2Folder = $this->salin(self::MASTER['B']);
        $this->ubahCsv($v2Folder.'/Standar_GB.csv', static function (array $b): array {
            foreach ($b as $i => $r) {
                if (($r[16] ?? '') === '19') { // kolom Q
                    $b[$i][17] = self::BALOK_19_LAIN; // kolom R
                }
            }

            return $b;
        });
        $r2 = $impor->imporCsv($v2Folder, self::MASTER['B'], 'uji', $org, $admin->id, false);
        $this->assertSame('draf_dibuat', $r2['acuan']['status']);
        $v2 = PaketAcuanVersi::query()->findOrFail($r2['acuan']['versi_id']);
        $this->assertSame([PaketAcuanVersi::STATUS_DRAF, 2, $this->v1->id, $admin->id], [$v2->status, $v2->nomor_versi, $v2->dasar_versi_id, $v2->dibuat_oleh]);
        $this->assertSame(19.00211, $v2->isiTerurai()['balok_ukur']['19.0']);
        $this->assertStringContainsString('#'.$r2['id'], $v2->rujukan[0]['nilai']);
        $this->assertSame(
            [['balok_ukur', null, '19.0', '19.00011', '19.00211']],
            PaketAcuanSuntingan::query()->where('paket_acuan_versi_id', $v2->id)->get()
                ->map(fn ($s) => [$s->lembar, $s->kunci_baris, $s->kolom, $s->nilai_lama, $s->nilai_baru])->all(),
        );

        // Impor ulang master v2 yang sama: tidak ada draf kembar.
        $this->assertSame('sudah_ada', $impor->imporCsv($v2Folder, self::MASTER['B'], 'uji', $org, $admin->id, false)['status']);

        // 3) Draf tidak menghitung apa pun.
        $idSaatDraf = $this->simpanSesi($this->payloadMicrometer(['tanggal_kalibrasi' => '2025-07-01']));
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($idSaatDraf));
        $this->assertEquals($angkaV1Juli, $this->angkaSesi($idSaatDraf));

        // 4) Pengesahan DITIRU langsung di sini; alur resminya (ajukan/simulasi/
        // sahkan) diuji AlurVersiAcuanTest. Yang diuji: begitu berlaku, baru menghitung.
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => '2025-05-31']);
        $v2->update([
            'status' => PaketAcuanVersi::STATUS_AKTIF,
            'isi_sha256' => JsonKanonik::sha256((string) $v2->isi),
            'berlaku_mulai' => '2025-06-01',
        ]);

        $idV2 = $this->simpanSesi($this->payloadMicrometer(['tanggal_kalibrasi' => '2025-07-01']));
        $this->assertSame([$v2->id, $v2->id, $v2->id], $this->stempelSesi($idV2));
        $angkaV2 = $this->angkaSesi($idV2);
        $this->assertNotEquals($angkaV1Juli[0]['error'], $angkaV2[0]['error'], 'titik 1 (6+19 mm) harus bergeser di v2');
        $this->assertEquals($angkaV1Juli[2]['error'], $angkaV2[2]['error'], 'titik 3 (31,0 = 14+17 mm) tidak memakai balok 19');

        // Sesi berstempel v1 dihitung ulang: tetap v1, angkanya tidak bergeser.
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$idV1]])->assertSuccessful();
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($idV1));
        $this->assertEquals($ulangV1, $this->angkaSesi($idV1), 'sesi berstempel v1 tidak bergeser sesudah v2 berlaku');
        $this->assertEquals(CalibrationSession::query()->findOrFail($idV1)->uncertaintyCalculations()->count(), count($angkaV1));
    }

    /**
     * Penjaga (fase 0 g): jalur hitung, validator, hitung ulang, dan sertifikat
     * tidak pernah membaca master Studio (`workbook_master`) atau peta sel
     * secara langsung — satu-satunya pintu angka dari master adalah versi
     * `paket_acuan_versi` yang berlaku (`PenentuVersiAcuan`, status
     * aktif/pensiun saja).
     *
     * Pengecualian sempit yang disengaja (K-50-15, 22 Okt 2026): FORMAT TAMPIL
     * sertifikat (`format_sertifikat`, kode number format — bukan angka)
     * dibaca lewat `App\Services\Acuan\FormatSertifikatAcuan` saja; kelas itu
     * tidak boleh membaca kunci peta selain `format_sertifikat`.
     */
    public function test_jalur_hitung_tidak_membaca_master_studio_maupun_draf(): void
    {
        $berkas = [
            ...glob(app_path('Services/Calibration').'/*.php'),
            ...glob(app_path('Services/Calibration/Profiles').'/*.php'),
            ...glob(app_path('Services/Calibration/Profiles/*').'/*.php'),
            app_path('Services/CalibrationValidator.php'),
            app_path('Services/PerhitunganBuilder.php'),
            app_path('Services/CertificateSnapshotBuilder.php'),
            app_path('Services/Acuan/SumberAcuan.php'),
            app_path('Services/Acuan/PenentuVersiAcuan.php'),
            app_path('Console/Commands/HitungUlangSesi.php'),
            app_path('Http/Controllers/Api/CalibrationController.php'),
        ];
        $this->assertGreaterThan(30, count($berkas));

        foreach ($berkas as $f) {
            // Kode saja: docblock boleh MENYEBUT Studio (mis. "disalin tangan dari workbook_master").
            $isi = implode('', array_map(
                static fn ($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                token_get_all((string) file_get_contents($f)),
            ));

            foreach (['WorkbookMaster', 'workbook_master', 'Studio\\PetaSel', 'Studio\\Workbook', 'peta-sel'] as $terlarang) {
                $this->assertStringNotContainsString($terlarang, $isi, basename($f)." menyebut {$terlarang}");
            }
        }

        // Jembatan format: hanya `format_sertifikat` yang dibaca dari peta.
        $jembatan = (string) file_get_contents(app_path('Services/Acuan/FormatSertifikatAcuan.php'));
        preg_match_all("/isi\\['([a-z_]+)'\\]/", $jembatan, $kunci);
        $this->assertSame(['format_sertifikat'], array_values(array_unique($kunci[1])), 'FormatSertifikatAcuan hanya membaca format_sertifikat');
        $this->assertStringNotContainsString('WorkbookMaster', $jembatan);

        // Status yang boleh menghitung: aktif & pensiun — draf/diajukan/terjadwal tidak.
        $sumber = (string) file_get_contents(app_path('Services/Acuan/PenentuVersiAcuan.php'));
        $this->assertMatchesRegularExpression('/STATUS_BERLAKU = \[\s*PaketAcuanVersi::STATUS_AKTIF,\s*PaketAcuanVersi::STATUS_PENSIUN,\s*\]/', $sumber);
    }
}
