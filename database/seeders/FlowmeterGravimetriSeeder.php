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
use App\Services\Calibration\Profiles\FlowmeterProfile;
use App\Services\Calibration\Profiles\FlowmeterTotalizerProfile;
use App\Services\Calibration\TabelStandarFlowmeterGravimetri;
use App\Services\Calibration\VarianMetodeFlowmeter;
use App\Services\KondisiLingkungan;
use App\Support\FlowmeterMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh **Flowmeter Gravimetri (ISO 4185)** — varian metode kedua alat
 * ke-27 (Totalizer) dan ke-28 (Flowrate).
 *
 * Sumbernya dua workbook master (password `spirit285`): `1.2 Master olda
 * Flowmeter Totalizer dini (2026) 140-2500L.xlsm` dan `2.1 Master olda
 * Flowmeter Flowrate 100-980lpm 2026.xlsm`. Keduanya sesi yang SAMA — satu
 * pelanggan, satu nomor order, satu nomor sertifikat, satu alat, satu hari.
 *
 * ## Identitas pelanggannya SINTETIS, angkanya ASLI
 *
 * Nama, alamat, nomor sertifikat, nomor order, dan serial alat pelanggan di
 * kedua master **tidak** disalin ke sini; yang dipakai identitas contoh yang
 * sama dengan [FlowmeterSeeder]. Yang asli cuma angka UKUR-nya — pembacaan UUT,
 * penimbangan, waktu, dan suhu air — karena justru itu yang membuat sesi ini
 * berguna sebagai penjaga.
 *
 * Alasannya BUKAN status repo. Repo sempat publik dan sekarang privat (dicek
 * 10 Sep 2026); yang menentukan bukan itu, melainkan bahwa riwayat git menyimpan
 * apa pun yang pernah masuk — `git log -S` bisa dijalankan siapa pun yang punya
 * akses, sekarang atau nanti. Identitas sintetis tetap wajib walau repo tertutup.
 * Lihat AGENTS.md §Sebelum repo dibalik jadi PUBLIK.
 *
 * ## SATU sesi yang di-seed, bukan dua — dan kenapa
 *
 * Yang mendarat di database contoh cuma sesi **Totalizer**. Titik 4-nya
 * (2.485,8 kg / 2.498 L) diblokir dua kali — di luar rentang pakai tabel
 * koreksi Dini Argeo DAN di luar pita CMC yang berhenti di 1.991 L — jadi sesi
 * ini sudah memperlihatkan gerbangnya bekerja, dengan tiga titik lain yang
 * tetap terbit.
 *
 * Sesi **Flowrate** master sengaja TIDAK di-seed. Yang diukur 2,03 dan
 * 9,98 Lpm, sementara pita akreditasi mulai di 75 Lpm — dua puluh sampai tiga
 * puluh tujuh kali di bawah batas bawah, jadi SELURUH titiknya diblokir dan
 * sesinya pulang dengan nol baris hitungan. `kalibrasi:sapu-sesi` menandainya
 * `titik_kosong` ber-tingkat ERROR, dan itu vonis yang BENAR.
 *
 * Justru karena benar dia tidak boleh ada di data contoh: database demo yang
 * selamanya melaporkan satu ERROR melatih pembacanya mengabaikan daftarnya,
 * dan itu persis kelas kerusakan yang ditulis di `docs/permintaan-user-7.md` §9.
 * Sesi Flowrate master tetap hidup sebagai data test —
 * `FlowmeterGravimetriMasterTest::test_seluruh_sesi_flowrate_di_luar_lingkup_diblokir`
 * mengadu angkanya sel demi sel dan menegakkan blokirnya. Pertanyaan lab §2.
 *
 * ## Tiga titik Totalizer yang terbit semuanya memakai LANTAI CMC
 *
 * U95 hasil budget 1,06 L; lantai CMC 1,2 % memberi 1,68 / 6,01 / 12,02 L.
 * Master mencetak yang 1,06 — titik 3 mengklaim ketidakpastian sebelas kali
 * lebih baik dari yang diakui KAN. Pertanyaan lab §1.
 */
class FlowmeterGravimetriSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Sesi Totalizer — `INPUT DATA!D35:M48` dan `D53:P55`, timbangan 1
     * (Dini Argeo).
     */
    private const TOTALIZER = [
        'kode' => 'FM-GRAV-TOT-DEMO-01',
        'nomor_sesi' => 'DEMO-FM-GRAV-TOT-001',
        'nomor_order' => 'DEMO-ORD-FM-GRAV-01',
        'tanggal' => '2026-01-05',
        'suhu_awal' => 25.7,
        'suhu_akhir' => 25.8,
        'rh_awal' => 41.0,
        'rh_akhir' => 40.0,
        'satuan' => 'L',
        'kapasitas' => 9999.0,
        'resolusi' => 0.01,
        'rentang' => '140-2500',
        'kode_timbangan' => 1,
        'titik' => [
            [
                'titik_ke' => 1,
                'uut' => [[140.11], [140.12], [140.09]],
                'berat_isi' => [139.9, 139.8, 139.9],
                'berat_kosong' => [0.0, 0.0, 0.0],
                'waktu_menit' => [],
                'suhu_awal' => [25.5, 25.5, 25.5],
                'suhu_akhir' => [25.4, 25.4, 25.4],
            ],
            [
                'titik_ke' => 2,
                'uut' => [[500.68], [500.71], [500.70]],
                'berat_isi' => [499.3, 499.4, 499.4],
                'berat_kosong' => [0.0, 0.0, 0.0],
                'waktu_menit' => [],
                'suhu_awal' => [25.4, 25.4, 25.4],
                'suhu_akhir' => [25.4, 25.4, 25.4],
            ],
            [
                'titik_ke' => 3,
                'uut' => [[1001.24], [1001.25], [1001.30]],
                'berat_isi' => [998.6, 998.7, 998.7],
                'berat_kosong' => [0.0, 0.0, 0.0],
                'waktu_menit' => [],
                'suhu_awal' => [25.3, 25.3, 25.3],
                'suhu_akhir' => [25.4, 25.4, 25.4],
            ],
            [
                'titik_ke' => 4,
                'uut' => [[2499.53], [2499.52], [2499.51]],
                'berat_isi' => [2485.5, 2484.9, 2487.0],
                'berat_kosong' => [0.0, 0.0, 0.0],
                'waktu_menit' => [],
                'suhu_awal' => [26.0, 26.0, 26.0],
                'suhu_akhir' => [26.0, 26.0, 26.0],
            ],
        ],
    ];

    /**
     * Sesi Flowrate — `INPUT DATA!D36:M51` dan `D56:M58`, timbangan 3 (Mettler).
     *
     * Ketiga ulangan UUT identik PERSIS di master. Itu yang membuat penjaga
     * sebaran harus jatuh ke penimbangan, bukan ke UUT — dan yang membuat
     * `max !== min` (bukan `stdev > 0`) jadi keharusan.
     */
    private const FLOWRATE = [
        'kode' => 'FM-GRAV-RATE-DEMO-01',
        'nomor_sesi' => 'DEMO-FM-GRAV-RATE-001',
        'nomor_order' => 'DEMO-ORD-FM-GRAV-01',
        'tanggal' => '2026-01-05',
        'suhu_awal' => 28.2,
        'suhu_akhir' => 28.4,
        'rh_awal' => 56.0,
        'rh_akhir' => 54.0,
        'satuan' => 'm3/h',
        'kapasitas' => 9999.0,
        'resolusi' => 0.0001,
        'rentang' => '0.1-0.6',
        'kode_timbangan' => 3,
        'titik' => [
            [
                'titik_ke' => 1,
                'uut' => [
                    [0.1221, 0.1219, 0.1220],
                    [0.1221, 0.1219, 0.1220],
                    [0.1221, 0.1219, 0.1220],
                ],
                'berat_isi' => [2.0205, 2.0207, 2.0206],
                'berat_kosong' => [0.0, 0.0, 0.0],
                'waktu_menit' => [1.001, 1.0, 1.0],
                'suhu_awal' => [26.5, 26.5, 26.5],
                'suhu_akhir' => [26.5, 26.6, 26.6],
            ],
            [
                'titik_ke' => 2,
                'uut' => [
                    [0.5967, 0.5971, 0.5965],
                    [0.5967, 0.5971, 0.5965],
                    [0.5967, 0.5971, 0.5965],
                ],
                'berat_isi' => [9.9287, 9.9295, 9.9289],
                'berat_kosong' => [0.0, 0.0, 0.0],
                'waktu_menit' => [1.0, 1.0, 1.0],
                'suhu_awal' => [26.5, 26.5, 26.5],
                'suhu_akhir' => [26.5, 26.5, 26.5],
            ],
        ],
    ];

    public function run(): void
    {
        $tabel = new TabelStandarFlowmeterGravimetri;
        $teknisi = User::where('organization_id', 1)->first();

        if ($teknisi === null) {
            return;
        }

        // `TH-4` — `INPUT DATA!E23 = 4` di KEDUA workbook, sama dengan varian UFM.
        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-4')->first();

        // SINTETIS — lihat docblock kelas. Pelanggan yang sama dengan sesi
        // varian UFM: di kenyataannya memang satu pelanggan yang sama, dan
        // membuat pelanggan contoh kedua cuma menambah baris tanpa menambah arti.
        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT Contoh Kalibrasi Nusantara'],
            ['organization_id' => 1, 'alamat' => 'Kawasan Industri Contoh Blok A No. 1, Bandung'],
        );

        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Aliran')],
            ['organization_id' => 1, 'nama' => 'Aliran'],
        );

        $this->seedSesi(
            new FlowmeterTotalizerProfile,
            self::TOTALIZER,
            $this->seedTimbangan($tabel, FlowmeterMentah::MODE_TOTALIZER, self::TOTALIZER['kode_timbangan']),
            $pelanggan, $kategori, $thermohygro, $teknisi,
        );

        // Sesi Flowrate master SENGAJA TIDAK di-seed — lihat docblock kelas.
        // Timbangannya tetap di-seed supaya baris standarnya ada di database
        // contoh: teknisi memilihnya dari dropdown sebelum titik pertama diisi,
        // dan dropdown yang kosong menghentikan pekerjaan di lapangan.
        $this->seedTimbangan($tabel, FlowmeterMentah::MODE_FLOWRATE, self::FLOWRATE['kode_timbangan']);
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function seedSesi(
        FlowmeterProfile $profil,
        array $m,
        Standard $standar,
        Customer $pelanggan,
        EquipmentCategory $kategori,
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
                'model' => 'GRAV-DEMO',
                'range_min' => 0,
                'range_max' => (float) $m['kapasitas'],
                'satuan' => $profil->satuanHasil(),
                'resolusi' => (float) $m['resolusi'],
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
                'alat_model' => 'GRAV-DEMO',
                'alat_serial_number' => (string) $m['kode'],
                'pemilik_nama' => $pelanggan->nama,
                'pemilik_alamat' => $pelanggan->alamat,
                'spesifikasi_alat' => [
                    'rentang_ukur' => (string) $m['rentang'],
                    'kapasitas' => (string) $m['kapasitas'],
                    'resolusi' => (string) $m['resolusi'],
                    'satuan' => (string) $m['satuan'],
                    FlowmeterMentah::KUNCI_SESI => [
                        'mode' => $profil->mode(),
                        // DUA kunci yang MENENTUKAN ANGKA. Varian memilih 9/11
                        // komponen budget dan rantai hitung yang lain sama
                        // sekali; kode timbangan memilih tabel koreksi, U95,
                        // kestabilan, dan drift sekaligus. Salah satu saja
                        // kelupaan, angkanya tetap keluar dan tetap terlihat
                        // wajar.
                        'varian_metode' => VarianMetodeFlowmeter::GRAVIMETRI->value,
                        'kode_timbangan' => (int) $m['kode_timbangan'],
                        'satuan' => (string) $m['satuan'],
                        'kapasitas' => (float) $m['kapasitas'],
                        'resolusi' => (float) $m['resolusi'],
                        // Volume pipa UUT→standar. Dihitung KEDUA workbook
                        // (`INPUT DATA!P32`/`Q32`) dan dibaca NOL sel. Disimpan
                        // supaya tidak hilang; TIDAK masuk hitungan tanpa
                        // perintah lab. Pertanyaan lab §5.
                        'volume_pipa_l' => 0.10129012,
                        // Geometri pipa milik varian UFM — dikosongkan di sini,
                        // bukan diisi angka contoh: varian gravimetri tidak
                        // punya komponen `u_A`, dan angka yang ada tapi tidak
                        // dibaca siapa pun adalah persis bentuk yang bikin
                        // pembaca berikutnya mengira dia dipakai.
                        'diameter_pipa_mm' => [],
                        'ketebalan_pipa_mm' => [],
                        'material_pipa' => 'Carbon Steel',
                        'jenis_fluida' => 'Air',
                        'path_configuration' => null,
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
                FlowmeterMentah::PERAN_BERAT_ISI => [$titik['berat_isi'], 'kg'],
                // Nol di seluruh sesi kedua master — dan justru karena itu
                // barisnya tetap ditulis: jalur pengurangannya nol kali teruji
                // di sana, jadi bentuk datanya harus ada supaya sesi pertama
                // yang benar-benar memakai wadah tidak mendarat di bentuk yang
                // belum pernah dilihat siapa pun.
                FlowmeterMentah::PERAN_BERAT_KOSONG => [$titik['berat_kosong'], 'kg'],
                FlowmeterMentah::PERAN_WAKTU => [$titik['waktu_menit'], 'menit'],
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

            // Densitas UUT tidak dipakai varian gravimetri sama sekali —
            // fluidanya air dan densitasnya sudah DIUKUR piknometer, bukan
            // diketik teknisi.
            $konteks[FlowmeterMentah::PERAN_DENSITAS] = [];
            $konteks[FlowmeterMentah::PERAN_STD] = [];

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => 0.0,
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
            'is_verified' => true,
            ...$isi,
        ];
    }

    /**
     * Timbangan standar yang benar-benar dipakai sesi ini.
     *
     * Diambil dari tabel, bukan diketik: `u95_kg`, `kestabilan_kg`, dan
     * `drift_kg` semuanya masuk budget, dan baris `standards` yang menyimpang
     * dari tabelnya melahirkan sertifikat yang menyebut standar dengan angka
     * yang bukan miliknya.
     */
    private function seedTimbangan(TabelStandarFlowmeterGravimetri $tabel, string $mode, int $kode): Standard
    {
        $t = $tabel->timbangan($mode, $kode);

        return Standard::updateOrCreate(
            ['organization_id' => 1, 'nama' => $t['nama'].' '.$t['merk'], 'serial_number' => $t['seri']],
            [
                'organization_id' => 1,
                'merk' => $t['merk'],
                'model' => $t['tipe'],
                'no_sertifikat' => $t['seri'],
                'tertelusur_ke' => $t['tertelusur'],
                // Dipanjangkan ke masa berlaku demo kalau yang asli sudah lewat.
                // Dua dari empat timbangan master memang SUDAH kedaluwarsa pada
                // tanggal sesi (Mettler 22 Jul 2025, Fujitsu 16 Jun 2025) dan
                // `INPUT DATA!K3` sendiri memvonis `ONE OR MORE STANDARD
                // EXPIRED` — sertifikatnya tetap terbit. Pertanyaan lab §12.
                'berlaku_sampai' => $this->berlakuSampaiDemo(
                    $t['nama'].' '.$t['merk'],
                    $t['tanggal_jatuh_tempo'],
                ),
                'ketidakpastian' => (float) $t['u95_kg'],
                'satuan_ketidakpastian' => 'kg',
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

        app(KondisiLingkungan::class)->terapkan($sesi->fresh()->load('thermohygro'));
    }
}
