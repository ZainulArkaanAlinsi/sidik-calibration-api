<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\AnakTimbanganProfile;
use App\Services\PraPeriksaSatuHalaman;
use App\Services\SertifikatSatuHalaman;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EnclosureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClassConstant;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SKENARIO TERBURUK satu halaman, SEMUA profil (PK-6, audit 16 Nov D3).
 *
 * `SertifikatSemuaAlatSatuHalamanTest` merender sesi contoh seeder dengan nama +
 * alamat pendek (21 + 50 karakter) dan jumlah baris SEEDER — sesi contoh itu
 * banyak yang lebih ringan daripada yang boleh diterima skema lembar (Buret 2 dari
 * 5 titik, Timer 5 dari 10, tekanan 5 dari 14, Enclosure 3-4 dari 6 set point).
 * Yang di sini menjawab pertanyaan yang tidak bisa dijawabnya: kalau lab memakai
 * lembar sampai PENUH dengan pelanggan yang namanya panjang, alamat tiga baris, dan
 * lokasi onsite beralamat — masih satu halaman? Bukan tebakan: lembar dirakit
 * `CertificateSnapshotBuilder` dari sesi bawaan, barisnya DIKLONING sampai jumlah
 * baris terbesar skema, kepalanya diganti teks panjang, lalu diadili
 * `SertifikatSatuHalaman::terbuktiTidakMuat()` — vonis jalur terbit, bukan hitungan baru.
 *
 * ## Daftar yang dipatok (dua arah, seperti `BUTUH_PADAT`)
 *
 * `TIDAK_MUAT` = profil yang TERBUKTI meluap di skenario ini. Nama BARU = ada perubahan
 * yang membuat lembar penuh sebuah alat tidak lagi muat. Nama yang HILANG = alat itu
 * sekarang muat: keluarkan dari daftar (jangan dibiarkan jadi izin diam-diam).
 * Daftar itu bukan keputusan bahwa meluapnya BOLEH — itu keputusan pemilik (lihat
 * docblock `TIDAK_MUAT`); di produksi gerbang pra-approve (`PraPeriksaSatuHalaman`) yang
 * menolak sesinya sebelum nomor sertifikat terpakai.
 *
 * ## Batas skema ikut dijaga
 *
 * `BARIS_MAKS` ditulis tangan per profil (sumber di komentar tiap baris). Supaya tidak
 * membusuk diam-diam: tiap profil terdaftar wajib punya entri, dan jumlah baris tabel
 * lembar kerjanya (`bentukLembarKerja`) / batas titik sesi (`batasTitik`) tidak boleh
 * melebihi angka itu. Skema bertambah baris = tes ini merah sampai angkanya diperbarui
 * DAN skenario terburuknya dijalankan ulang.
 */
class SertifikatSkenarioTerburukTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Header sintetis panjang (tidak ada nama pelanggan sungguhan): nama perusahaan dua
     * baris, alamat tiga baris, lokasi onsite beralamat, merk/model/seri/order panjang.
     */
    private const HEADER_PANJANG = [
        'owner' => 'PT SINTETIS NUSANTARA KARYA SEJAHTERA ABADI TBK. DIVISI MANUFAKTUR DAN PENGEMASAN TERPADU',
        'address' => 'Jl. Raya Industri Sintetis No. 12-14 Kawasan Industri Contoh Tahap II Desa Pasirsari Kecamatan Cikarang Selatan Kabupaten Bekasi Provinsi Jawa Barat 17530',
        'calibration_location' => 'Insitu (PT Sintetis Nusantara Karya, Gedung Produksi Lantai 3 Ruang Kalibrasi Utama, Kawasan Industri Contoh)',
        'manufacturer' => 'Contoh Instrument Manufacturing GmbH (Germany)',
        'model_type' => 'Seri ABC-12345-XL/Rev.B',
        'serial_number' => 'SN-2026-00012345-ABCD-9876',
        'order_number' => 'ORD/2026/11/0001234-ADDENDUM-A',
    ];

    /**
     * Bath/Furnace/Refrigerator tidak punya sesi bawaan seeder: sesi sintetis dari grid
     * master Enclosure Constant Yokogawa (pola `SertifikatSemuaAlatSatuHalamanTest`).
     *
     * @var array<string, array{0: string, 1: int, 2: int}>
     */
    private const ENCLOSURE_SINTETIS = [
        'Bath' => ['bath', 0, 100],
        'Furnace' => ['furnace', 0, 1000],
        'Refrigerator' => ['refrigerator', 0, 100],
    ];

    /**
     * Jumlah baris terbesar yang diterima skema lembar, per profil, dan SIAPA yang
     * menyatakannya. Yang dikloning = daftar di `DAFTAR_KLONING` (bawaan: `hasil`).
     *
     * @var array<string, int>
     */
    private const BARIS_MAKS = [
        // Keping terbanyak yang masih SATU halaman di sertifikat berkepala bawaan (diukur
        // dompdf, 6 Okt 2026); server menolak kiriman di atasnya. HP bisa menambah sampai 60.
        'anak_timbangan' => 40, // AnakTimbanganProfile::BATAS_KEPING_SATU_HALAMAN
        'autoclave' => 0, // lembar tetap (3 bagian master); tidak ada daftar yang bertambah
        // Template INPUT DATA master Enclosure menyediakan 6 set point (pertanyaan-lab-enclosure.md §11e);
        // server tidak membatasi (set point = data) — gerbang pra-approve menolak yang lebih.
        'bath' => 6, 'furnace' => 6, 'inkubator' => 6, 'oven' => 6, 'refrigerator' => 6,
        'buret' => 5, // 3 tabel x 5 baris (bentukLembarKerja)
        'buret_digital' => 3, // 3 baris
        'centrifuge' => 18, // 18 baris sebelum/sesudah adjustment
        'chlorine_meter' => 2, // batasTitik.maks
        'conductivity_meter' => 3, // batasTitik.maks
        'dial_indicator' => 15, // tabel Pembacaan Alat 15 baris
        'differential_pressure' => 14, // TekananProfile::TITIK_MAKS (batasTitik.maks)
        'dispensett' => 3,
        'do_meter' => 1, // batasTitik.maks
        'flowmeter_flowrate' => 3, // 3 titik x 3 pengulangan
        'flowmeter_totalizer' => 3,
        'gas_detector' => 4, // batasTitik.maks (CO/H2S/CH4/O2)
        'gelas_ukur' => 5,
        'height_gauge' => 10, // Pembacaan Alat 10 baris
        'hydrometer' => 5, // Weight/Temperature 5 baris
        'jangka_sorong' => 27, // Luar 11 + Dalam 11 + Kedalaman 5
        'labu_ukur' => 1,
        'load_cell' => 15, // 15 baris per sudut
        'micrometer' => 11, // batasTitik.maks (BARIS_KERTAS)
        'ph_meter' => 3, // batasTitik.maks
        'picnometer' => 1,
        'pipet_ukur' => 5,
        'pipet_volume' => 1,
        'piston_pipette' => 3,
        'pressure_gauge' => 14, // TekananProfile::TITIK_MAKS (batasTitik.maks)
        'proving_ring' => 15,
        'refractometer' => 2, // batasTitik.maks
        'sieve' => 3, // tiga kelompok parameter (Warp/Weft/Wire)
        'spectrophotometer' => 24, // Holmium 10 + Didymium 9 + Neutral Filter 5
        'tachometer' => 18,
        'thermocouple' => 6,
        'thermohygro' => 10, // 5 titik suhu + 5 titik RH
        'thermometer_glass' => 5,
        'tids' => 7,
        'timbangan' => 10, // batasTitik.maks (tangga Accuracy)
        'timer_stopwatch' => 10, // BARIS_KERTAS (batasTitik.maks)
        'tits' => 9,
        'turbidimeter' => 3, // batasTitik.maks
        'utm' => 14,
        'vacuum_gauge' => 14, // TekananProfile::TITIK_MAKS (batasTitik.maks)
        'viscometer' => 5, // batasTitik.maks
    ];

    /**
     * Daftar di snapshot yang dikloning ('a.b' = bersarang). Bawaan: hanya `hasil`.
     *
     * @var array<string, list<string>>
     */
    private const DAFTAR_KLONING = [
        'pressure_gauge' => ['hasil', 'tekanan.baris'], 'vacuum_gauge' => ['hasil', 'tekanan.baris'], 'differential_pressure' => ['hasil', 'tekanan.baris'],
        'bath' => ['enclosure.set_point'], 'furnace' => ['enclosure.set_point'], 'oven' => ['enclosure.set_point'],
        'inkubator' => ['enclosure.set_point'], 'refrigerator' => ['enclosure.set_point'],
        'timbangan' => ['hasil', 'timbangan.titik'],
    ];

    /**
     * PROFIL YANG TERBUKTI MELUAP di skenario terburuk (17 Nov 2026, kode `8a41909c`+PK-6) —
     * ini TEMUAN untuk keputusan pemilik, BUKAN izin. Di produksi gerbang pra-approve
     * menolak sesinya (`422 sertifikat_lebih_satu_halaman`) sebelum nomor terpakai; yang
     * dibutuhkan keputusan: batas jumlah titik/keping per profil di server, atau tingkat
     * pemadatan baru (mengubah tata letak — tidak boleh tanpa keputusan). Jarak ke batas
     * (jumlah baris terbanyak yang masih muat dengan header panjang; angka skema):
     *
     *   anak_timbangan     33 keping   (skema/server: 40)
     *   bath               5 set point (master: 6)
     *   furnace            5 set point (master: 6)
     *   inkubator          5 set point (master: 6)
     *   refrigerator       5 set point (master: 6)
     *   jangka_sorong      20 baris    (skema: 27; sesi contoh 25 baris pun meluap dengan header panjang)
     *   spectrophotometer  21 baris    (skema: 24; sesi contoh 24 baris pun meluap dengan header panjang)
     *
     * Oven (6 set point) dan Timbangan (10 titik) muat dengan jarak 0 baris: satu baris
     * lagi meluap.
     *
     * @var list<string>
     */
    private const TIDAK_MUAT = [
        'anak_timbangan', 'bath', 'furnace', 'inkubator', 'jangka_sorong', 'refrigerator', 'spectrophotometer',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        // Direktori sementara dompdf sendiri (alasan sama dengan `SertifikatSemuaAlatSatuHalamanTest`).
        $this->tempDompdf = storage_path('framework/testing/dompdf-terburuk-'.getmypid().'-'.bin2hex(random_bytes(4)));
        @mkdir($this->tempDompdf, 0777, true);
        config(['dompdf.options.temp_dir' => $this->tempDompdf]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDompdf.'/*') ?: [] as $berkas) {
            @unlink($berkas);
        }
        @rmdir($this->tempDompdf);

        parent::tearDown();
    }

    private string $tempDompdf = '';

    public function test_lembar_penuh_dengan_header_panjang_muat_satu_halaman_untuk_semua_profil(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (self::ENCLOSURE_SINTETIS as $nama => [$kode, $min, $maks]) {
            $this->sesiEnclosureSintetis($nama, $kode, $min, $maks);
        }

        $registry = app(CalibrationProfileRegistry::class);
        $gerbang = app(PraPeriksaSatuHalaman::class);
        $renderer = app(SertifikatSatuHalaman::class);
        $penyetuju = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $terdaftar = [];

        foreach ($registry->semua() as $profil) {
            $terdaftar[$profil->kode()] = $profil; // registry memuat TidsProfile dua kali
        }

        ksort($terdaftar);

        // 1. Tiap profil terdaftar punya angka skema (dan sebaliknya).
        $selisih = array_merge(
            array_diff(array_keys($terdaftar), array_keys(self::BARIS_MAKS)),
            array_diff(array_keys(self::BARIS_MAKS), array_keys($terdaftar)),
        );
        sort($selisih);

        $this->assertSame(
            [],
            $selisih,
            'BARIS_MAKS tidak sejalan dengan profil terdaftar (profil baru/hilang): '.implode(', ', $selisih)
            .'. Profil baru WAJIB diputuskan: berapa baris terbesar yang diterima lembarnya?',
        );

        // 2. Vonis per sesi.
        $tidakMuat = [];
        $diperiksa = [];

        foreach (CalibrationSession::query()->orderBy('id')->get() as $sesi) {
            $sesi->load('equipment');
            $kode = $registry->untukAlat($sesi->equipment)->kode();
            $diperiksa[$kode] = true;

            $sertifikat = $gerbang->sertifikatSementara($sesi, $penyetuju, 'UJI/TERBURUK/'.$sesi->id);
            $snapshot = $this->klonBaris($sertifikat->snapshot, $kode, self::BARIS_MAKS[$kode]);

            foreach (self::HEADER_PANJANG as $kolom => $nilai) {
                if (array_key_exists($kolom, $snapshot['header'])) {
                    $snapshot['header'][$kolom] = $nilai;
                }
            }

            $sertifikat->setAttribute('snapshot', $snapshot);

            if ($renderer->terbuktiTidakMuat($sertifikat) !== null) {
                $tidakMuat[$kode] = true;
            }
        }

        $diperiksa = array_keys($diperiksa);
        sort($diperiksa);

        $this->assertSame(array_keys($terdaftar), $diperiksa, 'Ada profil terdaftar tanpa sesi yang diperiksa — sapuan memeriksa lebih sedikit dari yang dijanjikan.');

        $tidakMuat = array_keys($tidakMuat);
        sort($tidakMuat);

        $this->assertSame(
            self::TIDAK_MUAT,
            $tidakMuat,
            "Daftar profil yang meluap di skenario terburuk berubah.\n"
            ."Nama BARU = perubahan membuat lembar PENUH alat itu (baris terbesar skema + header panjang) tak lagi muat satu halaman.\n"
            .'Nama yang HILANG = sekarang muat; keluarkan dari TIDAK_MUAT.',
        );
    }

    /**
     * Angka skema tidak boleh tertinggal dari kode: tabel lembar kerja & batas titik sesi
     * tidak menyatakan baris lebih banyak daripada yang dijalankan skenario terburuk.
     */
    public function test_baris_maks_tidak_tertinggal_dari_skema_lembar(): void
    {
        $this->seed(DatabaseSeeder::class);

        $registry = app(CalibrationProfileRegistry::class);
        $tertinggal = [];

        foreach (CalibrationSession::query()->with('equipment')->get() as $sesi) {
            $profil = $registry->untukAlat($sesi->equipment);
            $kode = $profil->kode();
            $maks = self::BARIS_MAKS[$kode] ?? null;

            if ($maks === null) {
                continue;
            }

            $batas = $profil->batasTitik($sesi->equipment, (array) $sesi->spesifikasi_alat);

            if (($batas['didukung'] ?? false) === true) {
                foreach ($batas['tabel'] as $tabel) {
                    if ((int) $tabel['maks'] > $maks) {
                        $tertinggal[] = "{$kode}: batasTitik.maks {$tabel['maks']} > BARIS_MAKS {$maks}";
                    }
                }
            }
        }

        $this->assertSame(
            AnakTimbanganProfile::BATAS_KEPING_SATU_HALAMAN,
            self::BARIS_MAKS['anak_timbangan'],
            'BARIS_MAKS[anak_timbangan] harus sama dengan batas keping yang ditolak server.',
        );
        $this->assertSame([], array_values(array_unique($tertinggal)), "BARIS_MAKS tertinggal dari skema:\n- ".implode("\n- ", array_unique($tertinggal)));
    }

    // ------------------------------------------------------------------ bahan

    /**
     * Kloning baris sampai `$jumlah` (siklus baris yang ada). Jumlah <= baris yang ada = tak berubah.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function klonBaris(array $snapshot, string $kode, int $jumlah): array
    {
        foreach (self::DAFTAR_KLONING[$kode] ?? ['hasil'] as $jalur) {
            $kunci = explode('.', $jalur);
            $ptr = &$snapshot;

            foreach ($kunci as $k) {
                if (! is_array($ptr) || ! array_key_exists($k, $ptr)) {
                    unset($ptr);

                    continue 2;
                }

                $ptr = &$ptr[$k];
            }

            if (is_array($ptr) && $ptr !== [] && $jumlah > count($ptr)) {
                $asal = array_values($ptr);
                $baru = [];

                for ($i = 0; $i < $jumlah; $i++) {
                    $baru[] = $asal[$i % count($asal)];
                }

                $ptr = $baru;
            }

            unset($ptr);
        }

        return $snapshot;
    }

    /** Sesi Enclosure sintetis (Yokogawa Type N) lewat seeder Enclosure, hanya di DB uji. */
    private function sesiEnclosureSintetis(string $nama, string $kode, int $min, int $maks): void
    {
        $seeder = new EnclosureSeeder;
        $grid = (new ReflectionClassConstant(EnclosureSeeder::class, 'GRID_YOKO'))->getValue();
        $standar = Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->orderBy('id')->firstOrFail();
        $nomor = "TERBURUK.{$kode}.1";

        (new ReflectionMethod($seeder, 'seedSesi'))->invoke(
            $seeder,
            ['nama' => "PT Sintetis Terburuk {$kode}", 'alamat' => 'Alamat Sintetis Terburuk'],
            ['nama_alat' => $nama, 'nama_alat_kemampuan' => $nama, 'merk' => 'MerkSintetis', 'model' => 'Model-1', 'serial_number' => 'TERBURUK-'.strtoupper($kode), 'range_min' => $min, 'range_max' => $maks, 'kapasitas' => (string) $maks],
            ['nomor_sesi' => $nomor, 'nomor_order' => $nomor, 'tanggal' => '2024-05-02', 'tipe_sensor' => 'Type N'],
            $grid,
            $standar,
            $teknisi,
        );
    }
}
