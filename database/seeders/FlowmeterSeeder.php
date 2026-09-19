<?php

namespace Database\Seeders;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Calibration\Profiles\FlowmeterFlowrateProfile;
use App\Services\Calibration\Profiles\FlowmeterProfile;
use App\Services\Calibration\Profiles\FlowmeterTotalizerProfile;
use App\Services\Calibration\TabelStandarFlowmeter;
use App\Services\KondisiLingkungan;
use App\Support\FlowmeterMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Dua sesi contoh **Flowmeter Ultrasonic** — alat ke-27 (Totalizer) dan ke-28
 * (Flowrate), kelompok Aliran.
 *
 * Sumbernya dua workbook master (ber-password): `1.3 Master Olah Data
 * Flowmeter_Ultrasonic Totalizer (1000-1900L) 2026.xlsm` dan `Master olda
 * Ultrasonic Flowrate (100-300 lpm) 2026.xlsm`. Keduanya sesi yang SAMA —
 * pelanggan sama, order sama, alat sama, dikalibrasi selang sehari.
 *
 * ## Identitas pelanggannya SINTETIS, angkanya ASLI
 *
 * Repo ini publik. Nama, alamat, nomor sertifikat, nomor order, dan serial
 * alat pelanggan di kedua master **tidak** disalin ke sini; yang dipakai
 * identitas contoh. Yang asli cuma angka UKUR-nya — pembacaan UUT, pembacaan
 * totalizer standar, suhu air, dan geometri pipa — karena justru itu yang
 * membuat sesi ini berguna sebagai penjaga: `HitungUlangSemuaSesiTest`
 * mengadunya balik lewat jalur hitung yang sama dengan sesi sungguhan.
 *
 * ## Angkanya DIHITUNG, bukan ditempel
 *
 * Yang ditanam cuma MASUKAN. Hasilnya lahir dari
 * `FlowmeterProfile::hitungPerGrup()` lewat jalur yang sama dengan
 * `POST /calibrations`, jadi kalau mesin hitungnya bergeser
 * `HitungUlangSemuaSesiTest` yang merah — bukan angka tempelan yang diam-diam
 * ikut bergeser.
 *
 * ## U95 sesi ini SENGAJA beda dari yang tercetak di master
 *
 * Tiga perbaikan terpasang, dan ketiganya membesarkan angkanya atau menolak
 * menerbitkan — tidak pernah diam-diam mengecilkan. Terukur di kedua sesi ini:
 *
 * | | master | di sini |
 * |---|---|---|
 * | Flowrate titik 2, U95 | 3,2512388 Lpm (1,047 %OR) | **3,7277107 Lpm** (1,200 %OR) |
 * | Totalizer titik 2, deviasi | −18,907204 L | **−18,890667 L** |
 * | `U_temperature` (kedua sesi) | 0,2780288 °C | **0,2795234 °C** |
 *
 * Yang pertama lantai CMC yang master lupa pasang — sertifikatnya sudah terbit
 * di BAWAH pita terakreditasi 1,2 %. Yang kedua rentang densitas Totalizer yang
 * melenceng satu kolom ke titik 3. Yang ketiga `Ut-water` yang di master
 * menunjuk sel kosong. Semuanya di
 * [\App\Services\Calibration\FlowmeterCalculator].
 *
 * Angka master yang asli tetap dijaga: `FlowmeterMasterTest` mengadu tiap kolom
 * turunan dan tiap komponen budget KEDUA workbook sampai 5·10⁻⁶.
 *
 * ## Yang sengaja dibiarkan muncul sebagai peringatan
 *
 * Sesi Flowrate titik 2 memungut koreksi titik tabel 236,147 Lpm untuk bacaan
 * 309,739 Lpm — jaraknya 23,8 %, jauh di atas ambang 10 %. Peringatannya
 * MUNCUL, dan itu disengaja: bentuknya perlu dilihat admin di data contoh
 * sebelum sesi pelanggan sungguhan datang.
 */
class FlowmeterSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Geometri pipa — tingkat SESI, sama di kedua workbook
     * (`INPUT DATA!E25:G25` dan `E26:G26`).
     */
    private const PIPA = [
        'diameter_mm' => [50.81, 50.82, 50.81],
        'ketebalan_mm' => [2.32, 2.31, 2.32],
    ];

    /**
     * Sesi Totalizer — `INPUT DATA` workbook Totalizer.
     *
     * UUT `D40:D42`/`H40:H42`, standar `D51:D53`/`H51:H53`, suhu air baris
     * 58–60 kolom `D`/`F` (titik 1) dan `H`/`I` (titik 2).
     *
     * Dua titik terisi dari empat slot — dan hanya keduanya yang disalin. Blok
     * titik 3 & 4 master rusak salin-tempel (`vi` FU 200 alih-alih 60, `ci`
     * cross-sectional memakai rumus lain, `u` velocity menunjuk sel kosong),
     * jadi memakainya sebagai acuan bentuk berarti menyalin bug.
     */
    private const TOTALIZER = [
        'kode' => 'FM-TOT-DEMO-01',
        'nomor_sesi' => 'DEMO-FM-TOT-001',
        'nomor_order' => 'DEMO-ORD-FM-01',
        'tanggal' => '2026-02-02',
        'suhu_awal' => 23.5,
        'suhu_akhir' => 23.6,
        'rh_awal' => 54.0,
        'rh_akhir' => 55.0,
        'satuan' => 'L',
        'kapasitas' => 9999.0,
        'resolusi' => 0.01,
        'rentang' => '140-2500',
        'titik' => [
            [
                'titik_ke' => 1,
                'uut' => [[1002.65], [1004.56], [1006.52]],
                'std' => [1010.885, 1011.52, 1012.215],
                'suhu_awal' => [25.5, 25.5, 25.5],
                'suhu_akhir' => [25.4, 25.4, 25.4],
            ],
            [
                'titik_ke' => 2,
                'uut' => [[1901.37], [1902.66], [1904.27]],
                'std' => [1901.654, 1903.158, 1904.998],
                'suhu_awal' => [25.4, 25.4, 25.4],
                'suhu_akhir' => [25.4, 25.4, 25.4],
            ],
        ],
    ];

    /**
     * Sesi Flowrate — `INPUT DATA` workbook Flowrate.
     *
     * UUT baris 38–40 (tiap ulangan TIGA durasi: 20″/40″/60″), standar baris
     * 49–51, suhu air baris 57–59.
     *
     * Dua titik terisi dari tiga slot; master menamainya lewat bukaan valve
     * ("3 gigi valve", "4 gigi valve").
     */
    private const FLOWRATE = [
        'kode' => 'FM-FLW-DEMO-01',
        'nomor_sesi' => 'DEMO-FM-FLW-001',
        'nomor_order' => 'DEMO-ORD-FM-02',
        'tanggal' => '2026-02-03',
        'suhu_awal' => 24.2,
        'suhu_akhir' => 24.4,
        'rh_awal' => 56.0,
        'rh_akhir' => 54.0,
        'satuan' => 'LPM',
        'kapasitas' => 9999.0,
        'resolusi' => 0.001,
        'rentang' => '500',
        'titik' => [
            [
                'titik_ke' => 1,
                'uut' => [
                    [101.255, 101.276, 101.289],
                    [102.654, 102.625, 102.678],
                    [101.986, 101.910, 101.945],
                ],
                'std' => [101.998, 101.897, 101.123],
                'suhu_awal' => [24.5, 24.5, 24.5],
                'suhu_akhir' => [24.5, 24.6, 24.6],
            ],
            [
                'titik_ke' => 2,
                'uut' => [
                    [309.785, 309.776, 309.779],
                    [311.376, 311.374, 311.398],
                    [310.772, 310.778, 310.745],
                ],
                'std' => [308.711, 310.255, 310.251],
                'suhu_awal' => [24.6, 24.6, 24.6],
                'suhu_akhir' => [24.6, 24.6, 24.6],
            ],
        ],
    ];

    public function run(): void
    {
        $tabel = new TabelStandarFlowmeter;
        $standar = $this->seedStandar($tabel);
        $teknisi = User::where('organization_id', 1)->first();

        if ($teknisi === null) {
            return;
        }

        // `TH-4` — `INPUT DATA!E23 = 4` di KEDUA workbook. Unit thermohygro
        // menentukan koreksi suhu & kelembapan yang tercetak di blok
        // Environmental Condition sertifikat, jadi disalin dari masternya
        // sendiri, bukan diambil yang pertama ketemu.
        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-4')->first();

        // SINTETIS — lihat docblock kelas. Satu pelanggan untuk kedua sesi,
        // sama seperti di master (dua workbook, satu order).
        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT Contoh Kalibrasi Nusantara'],
            ['organization_id' => 1, 'alamat' => 'Kawasan Industri Contoh Blok A No. 1, Bandung'],
        );

        // **Aliran** — kelompok lampiran akreditasi yang sudah ada (no. 30 &
        // 31), bukan kategori baru. Dijaga `KategoriAlatIkutLampiranTest`.
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Aliran')],
            ['organization_id' => 1, 'nama' => 'Aliran'],
        );

        $this->seedSesi(new FlowmeterTotalizerProfile, self::TOTALIZER, $pelanggan, $kategori, $standar, $thermohygro, $teknisi);
        $this->seedSesi(new FlowmeterFlowrateProfile, self::FLOWRATE, $pelanggan, $kategori, $standar, $thermohygro, $teknisi);
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function seedSesi(
        FlowmeterProfile $profil,
        array $m,
        Customer $pelanggan,
        EquipmentCategory $kategori,
        Standard $standar,
        ?Standard $thermohygro,
        User $teknisi,
    ): void {
        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => (string) $m['kode']],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $profil->namaAlatKemampuan(),
                // Kunci pencocokan ke profilnya — ejaannya harus PERSIS sama
                // dengan `namaAlatKemampuan()`. Kalau meleset, alatnya jatuh ke
                // profil default (pH) tanpa satu pun error.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => 'Contoh',
                'model' => 'UFM-DEMO',
                'range_min' => 0,
                'range_max' => (float) $m['kapasitas'],
                'satuan' => $profil->satuanHasil(),
                'resolusi' => (float) $m['resolusi'],
                // NULL: kedua master nggak nyebut satu pun batas keberterimaan
                // per titik — sertifikatnya cuma mencetak deviasi dan U95.
                'toleransi' => null,
                'lokasi' => 'Lab PT. Sidik',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => (string) $m['nomor_sesi']],
            [
                'equipment_id' => $alat->id,
                'nomor_order' => $m['nomor_order'],
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => $m['tanggal'],
                'tanggal_terima' => $m['tanggal'],
                'lokasi' => 'lab',
                'suhu_awal' => $m['suhu_awal'],
                'suhu_akhir' => $m['suhu_akhir'],
                'kelembaban_awal' => $m['rh_awal'],
                'kelembaban_akhir' => $m['rh_akhir'],
                'alat_merk' => 'Contoh',
                'alat_model' => 'UFM-DEMO',
                'alat_serial_number' => (string) $m['kode'],
                'pemilik_nama' => $pelanggan->nama,
                'pemilik_alamat' => $pelanggan->alamat,
                'spesifikasi_alat' => [
                    'rentang_ukur' => (string) $m['rentang'],
                    'kapasitas' => (string) $m['kapasitas'],
                    'resolusi' => (string) $m['resolusi'],
                    'satuan' => (string) $m['satuan'],
                    // Blok tingkat-SESI. Mode, satuan, resolusi, dan geometri
                    // pipa bukan titik ukur — memaksanya jadi `titik_ke`
                    // melahirkan titik hantu yang selalu gagal hitung ulang.
                    //
                    // `mode` yang menentukan budgetnya 8 komponen atau 9;
                    // tanpa dia `FlowmeterMentah::blokSesi()` balik `null` dan
                    // seluruh titiknya pulang "belum dihitung".
                    FlowmeterMentah::KUNCI_SESI => [
                        'mode' => $profil->mode(),
                        'satuan' => (string) $m['satuan'],
                        'kapasitas' => (float) $m['kapasitas'],
                        'resolusi' => (float) $m['resolusi'],
                        // SELALU mm — caliper & thickness gauge standarnya
                        // bersertifikat mm, dan `u_A` lahir dari keduanya.
                        'diameter_pipa_mm' => self::PIPA['diameter_mm'],
                        'ketebalan_pipa_mm' => self::PIPA['ketebalan_mm'],
                        // Keempat di bawah dipungut KERTAS `SIDIK-FM-CAL-0538`
                        // dan tidak ada di master mana pun — sertifikat Flowrate
                        // punya labelnya (`B19`..`B22`) tapi sel isinya kosong.
                        // Diisi di sini supaya bentuk cetaknya terlihat.
                        'material_pipa' => 'Carbon Steel',
                        'jenis_fluida' => 'Air',
                        'path_configuration' => 'Z',
                        'liner_material' => null,
                        'liner_ketebalan_mm' => null,
                    ],
                ],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $siapHitung = [];

        foreach ($m['titik'] as $titik) {
            $titikKe = (int) $titik['titik_ke'];
            $konteks = [];

            // Deret UUT BERSARANG: `pembacaan_ke` ulangan, `sensor_ke` durasi.
            // Di Totalizer tiap ulangan cuma satu angka, jadi durasinya satu.
            foreach ($titik['uut'] as $ulangan => $durasi) {
                foreach ($durasi as $ke => $angka) {
                    RawMeasurement::create($this->baris($sesi, $standar, [
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $ulangan + 1,
                        'sensor_ke' => $ke + 1,
                        'peran_sensor' => FlowmeterMentah::PERAN_UUT,
                        'pembacaan' => $angka,
                        'satuan' => (string) $m['satuan'],
                    ]));
                }

                $konteks[FlowmeterMentah::PERAN_UUT][] = array_map('floatval', $durasi);
            }

            $deretDatar = [
                FlowmeterMentah::PERAN_STD => [$titik['std'], (string) $m['satuan']],
                FlowmeterMentah::PERAN_SUHU_AWAL => [$titik['suhu_awal'], '°C'],
                FlowmeterMentah::PERAN_SUHU_AKHIR => [$titik['suhu_akhir'], '°C'],
            ];

            foreach ($deretDatar as $peran => [$nilai, $satuan]) {
                foreach ($nilai as $ke => $angka) {
                    RawMeasurement::create($this->baris($sesi, $standar, [
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $ke + 1,
                        'sensor_ke' => $ke + 1,
                        'peran_sensor' => $peran,
                        'pembacaan' => $angka,
                        'satuan' => $satuan,
                    ]));
                }

                $konteks[$peran] = array_map('floatval', $nilai);
            }

            // Densitas UUT sengaja KOSONG — medianya air, dan itu sah: dia
            // jatuh ke densitas air pada suhu yang tercatat, persis
            // `IFERROR(IF(AVERAGE(...)=0; AVERAGE(rho); ...))` master. Jalur
            // "densitas kosong tidak menahan" ikut teruji dari sini.
            $konteks[FlowmeterMentah::PERAN_DENSITAS] = [];

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                // Titik ukurnya lahir dari pembacaan, bukan dari setpoint —
                // teknisi memutar valve sampai aliran yang dia mau.
                'titik_ukur' => 0.0,
                // Jalur datar TIDAK dipakai alat ini.
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => $konteks + [
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi,
                ],
            ];
        }

        $this->tulisHitungan($sesi, $profil->hitungPerGrup($siapHitung, $alat));
    }

    /**
     * @param  array<string, mixed>  $isi
     * @return array<string, mixed>
     */
    private function baris(CalibrationSession $sesi, Standard $standar, array $isi): array
    {
        return [
            'calibration_session_id' => $sesi->id,
            'tahap' => 'sesudah_adjustment',
            'titik_ukur' => 0.0,
            'standard_id' => $standar->id,
            'input_source' => 'manual',
            // Diketik tangan, bukan kamera. Tanpa penanda ini validator
            // melaporkan `ocr_belum_diverifikasi` di sesi contoh, dan temuan
            // palsu di data demo melatih admin mengabaikan temuan yang sama
            // waktu dia nyata.
            'is_verified' => true,
            ...$isi,
        ];
    }

    /**
     * Caliper & thickness gauge — standar yang TERCETAK di kertas tapi tidak
     * pernah dibuatkan barisnya di master `standards`.
     *
     * Keduanya bukan pelengkap: diameter luar dan ketebalan pipa yang mereka
     * ukur melahirkan `u_A`, dan `u_A` masuk DUA komponen budget varian UFM
     * (cross sectional area dan koefisien sensitivitasnya). Standar yang masuk
     * perhitungan tapi tidak ada di master berarti sertifikat menyebut
     * ketertelusuran yang tidak bisa ditunjukkan dokumennya.
     *
     * Gejalanya kelihatan di HP, bukan di server: baris `STANDARD USED` lembar
     * kerja muncul merah dengan tulisan "belum terdaftar di master standar",
     * dan teknisi tidak bisa mencentangnya.
     *
     * Yang TIDAK ikut diseed: Victor 14+ (S/N 992613877). Alatnya sudah dicabut
     * lab — `FORM VALIDASI` TITS rev. 11 (24 Mei 2024) berbunyi *"Remove std.
     * Victor / Add std kalibrator yokogawa"*, dan tabel koreksinya sudah
     * `#REF!` semua. Barisnya tetap tercetak karena kertasnya memang masih
     * memuatnya; label merahnya JUJUR, dan menyeed dia berarti menghidupkan
     * kembali ketertelusuran ke alat yang tidak dipakai. Lihat
     * `EnclosureProfileBase` untuk ceritanya.
     */
    private function seedStandarPendukung(TabelStandarFlowmeter $tabel): void
    {
        foreach (['caliper', 'thickness_gauge'] as $kunci) {
            $s = $tabel->standar()[$kunci];

            Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => $s['nama'], 'serial_number' => $s['seri']],
                [
                    'organization_id' => 1,
                    'merk' => $s['merk'] === '-' ? null : $s['merk'],
                    'model' => $s['tipe'],
                    'no_sertifikat' => $s['seri'],
                    'tertelusur_ke' => $s['tertelusur'],
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['tanggal_jatuh_tempo']),
                    'ketidakpastian' => (float) $s['u95_mm'],
                    'satuan_ketidakpastian' => 'mm',
                    'faktor_cakupan' => 2,
                ],
            );
        }
    }

    private function seedStandar(TabelStandarFlowmeter $tabel): Standard
    {
        $this->seedStandarPendukung($tabel);

        $ufm = $tabel->standar()['ufm'];

        return Standard::updateOrCreate(
            ['organization_id' => 1, 'nama' => $ufm['nama']],
            [
                'organization_id' => 1,
                'merk' => $ufm['merk'],
                'model' => $ufm['tipe'],
                'serial_number' => $ufm['seri'],
                'no_sertifikat' => $ufm['seri'],
                'tertelusur_ke' => $ufm['tertelusur'],
                // Dipanjangkan ke masa berlaku demo kalau yang asli sudah lewat.
                // Tanpa ini `kalibrasi:sapu-sesi` melaporkan `standar_kadaluarsa`
                // di sesi contoh — temuan yang benar untuk data demo, tapi
                // melatih pembacanya mengabaikan temuan yang sama waktu nyata.
                'berlaku_sampai' => $this->berlakuSampaiDemo($ufm['nama'], $ufm['tanggal_jatuh_tempo']),
                // Ketidakpastian UFM itu TANGGA per titik tabel (1,3–1,5 %OR),
                // bukan satu angka — komponen budget ke-1 mengambilnya lewat
                // `cocokTerdekat()`. Yang disimpan di sini nilai terbesar tabel
                // supaya kolom master `standards` tidak kosong dan tidak pernah
                // mengecilkan; jalur hitung TIDAK membacanya.
                'ketidakpastian' => 1.5,
                'satuan_ketidakpastian' => '% of reading',
                'faktor_cakupan' => 2,
            ],
        );
    }

    /** @param  array<string, mixed>|null  $perGrup */
    private function tulisHitungan(CalibrationSession $sesi, ?array $perGrup): void
    {
        $versiRumus = $this->versiRumusUntuk($sesi);

        foreach ($perGrup['hitungan'] ?? [] as $h) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                ...$h,
                'formula_version_id' => $versiRumus,
            ]);
        }

        // Suhu & kelembapan RUANG diturunkan di sini, bukan ditulis tangan —
        // jalur yang sama persis dengan `POST /calibrations`. Dilupakan, sesi
        // contoh ini terbit tanpa `suhu_ruang` & `kelembaban`, blok
        // "Environmental Condition" sertifikatnya kosong, dan validator
        // memunculkan `env_condition` di setiap sesi.
        app(KondisiLingkungan::class)->terapkan($sesi->fresh()->load('thermohygro'));
    }
}
