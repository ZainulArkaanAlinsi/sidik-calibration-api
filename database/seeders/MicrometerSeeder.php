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
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Services\Calibration\TabelStandarMicrometer;
use App\Services\KondisiLingkungan;
use App\Support\MicrometerMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh **Micrometer** — alat ke-25, kelompok Panjang, lampiran
 * akreditasi LK-285-IDN no. 34.
 *
 * ## Kenapa yang ditanam cuma SATU dari empat sesi master
 *
 * Empat workbook master turun bersamaan, tapi sesi contoh 0-25 mm CACAT dan
 * cacatnya berantai: dropdown satuannya tersetel `inch` sementara angkanya
 * diketik dalam milimeter. Akibatnya kapasitas 25 dikali 25,4 jadi 635 mm,
 * yang (a) jatuh di luar keempat pita CMC sehingga U95 terbit **tanpa lantai
 * terakreditasi** — 0,735 µm padahal pitanya 0,83 µm — dan (b) menggelembungkan
 * komponen drift 13 kali lipat. Koreksi yang tercetak di sertifikatnya −61 mm
 * pada balok ukur 2,5 mm, angka yang mustahil untuk mikrometer.
 *
 * Menanamnya apa adanya berarti `HitungUlangSemuaSesiTest` menuntut sesi yang
 * memang tidak boleh terbit, dan `uji-profil` melaporkan "jalan" untuk lembar
 * yang hasilnya salah. Jadi yang ditanam **25-50 mm** (`0106-CAL-1023`) — sesi
 * bersih yang keempat komponen budgetnya cocok master sampai 5·10⁻⁶.
 *
 * Sesi 0-25 mm tetap tersimpan di `database/data/sesi-master-micrometer.json`
 * sebagai pembanding, dan yang menjaganya `MicrometerMasterTest::
 * test_kapasitas_di_luar_pita_cmc_diblokir_bukan_diterbitkan`.
 *
 * ## Angkanya DIHITUNG, bukan ditempel
 *
 * Yang ditanam cuma MASUKAN — tumpukan balok ukur, pembacaan, pra-evaluasi,
 * suhu. Hasilnya lahir dari `MicrometerProfile::hitungPerGrup()` lewat jalur
 * hitung ulang yang sama dengan sesi sungguhan, jadi kalau mesin hitungnya
 * bergeser, `HitungUlangSemuaSesiTest` yang merah — bukan angka tempelan yang
 * diam-diam ikut bergeser.
 */
class MicrometerSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Keping standar balok ukur, dari `DATABASE!S13:W13` keempat workbook.
     *
     * `ketidakpastian` NULL, bukan angka: ketidakpastian balok ukur berbentuk
     * TANGGA per nominal (0,12 / 0,14 / 0,25 / 0,26 / 0,73 µm), dan yang
     * membacanya `TabelStandarMicrometer` — bukan kolom ini. Diisi satu angka,
     * budget-nya diam-diam memakai anak tangga yang salah di sebagian besar
     * titik.
     */
    private const STANDAR = [
        'nama' => 'Gauge Block Standard',
        'merk' => 'Metrology',
        'model' => 'GB-9122-0',
        'serial' => '160006',
        'tertelusur' => 'LK-410-IDN',
        'berlaku_sampai' => '2026-01-24',
    ];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $standar = $this->seedStandar();
        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-3')->first();

        $data = json_decode(
            (string) file_get_contents(database_path('data/sesi-master-micrometer.json')),
            true,
        );

        // TIGA varian ditanam, bukan satu — sama seperti `TimbanganSeeder` yang
        // menanam ketiga variannya. Satu sesi contoh cuma membuktikan satu pita
        // CMC, satu nomor formulir, dan satu susunan sebelas nominal; tiga pita
        // sisanya lolos seluruh sapuan tanpa pernah dijalankan ujung ke ujung.
        //
        // Varian A (`025`) sengaja TIDAK ikut, dan sebabnya bukan cuma label
        // satuannya: blok pra-evaluasinya berisi 635,0 sepuluh kali — nilai
        // kapasitas hasil bug inch yang bocor ke sana. Simpangan bakunya NOL,
        // jadi sesi itu bakal terbit dengan komponen keterulangan nol yang
        // ditutupi lantai CMC. Membetulkannya berarti mengarang data
        // keterulangan, dan keterulangan itu dasar seluruh budget alat ini.
        // Lihat `docs/pertanyaan-lab-micrometer.md` §3.
        //
        // Jalur blokirnya sendiri tetap terjaga tanpa sesi ter-seed:
        // `MicrometerMasterTest::test_kapasitas_di_luar_pita_cmc_diblokir_bukan_diterbitkan`
        // dan `MicrometerSesiTest::test_kapasitas_di_luar_pita_cmc_diblokir`.
        foreach ($data['_ditanam_seeder'] as $varian) {
            $this->seedSesi($data['sesi'][$varian], $teknisi, $standar, $thermohygro);
        }
    }

    private function seedStandar(): Standard
    {
        return Standard::updateOrCreate(
            ['organization_id' => 1, 'nama' => self::STANDAR['nama']],
            [
                'organization_id' => 1,
                'merk' => self::STANDAR['merk'],
                'model' => self::STANDAR['model'],
                'serial_number' => self::STANDAR['serial'],
                'no_sertifikat' => self::STANDAR['serial'],
                'tertelusur_ke' => self::STANDAR['tertelusur'],
                // Dipanjangkan ke masa berlaku demo kalau yang asli sudah lewat.
                // Tanpa ini `kalibrasi:sapu-sesi` melaporkan
                // `standar_kadaluarsa` di sesi contoh — temuan yang benar
                // untuk data demo, tapi melatih pembacanya mengabaikan
                // temuan yang sama waktu dia nyata.
                'berlaku_sampai' => $this->berlakuSampaiDemo(
                    self::STANDAR['nama'],
                    self::STANDAR['berlaku_sampai'],
                ),
                // Lihat docblock [STANDAR].
                'ketidakpastian' => null,
                'satuan_ketidakpastian' => 'µm',
                'faktor_cakupan' => 2,
            ],
        );
    }

    /**
     * Batas bawah rentang ukur (mm) dari teks `rentang` master — "25-50" → 25.
     *
     * Balik 0 kalau bentuknya tidak dikenali: mikrometer 0-25 mm memang
     * berbatas bawah nol, jadi nol itu jawaban yang aman, bukan penanda gagal.
     */
    private static function batasBawah(string $rentang): float
    {
        return preg_match('/^\s*(-?\d+(?:[.,]\d+)?)/', $rentang, $cocok) === 1
            ? (float) str_replace(',', '.', $cocok[1])
            : 0.0;
    }

    /** @param  array<string, mixed>  $data */
    private function seedSesi(
        array $data,
        User $teknisi,
        Standard $standar,
        ?Standard $thermohygro,
    ): void {
        $m = $data['_sesi'];
        $profil = new MicrometerProfile;

        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $m['pelanggan']],
            ['organization_id' => 1, 'alamat' => $m['alamat']],
        );

        // **Panjang**, bukan "Dimensi" — dan bedanya bukan selera penamaan.
        //
        // Kategori alat itu yang dipungut `GET /api/categories`, dan daftarnya
        // HARUS sepuluh kelompok pengukuran lampiran akreditasi, tidak lebih.
        // Micrometer ada di baris no. 34, kelompok **Panjang**, dan baris
        // CMC-nya sudah ditaruh di situ oleh `CalibrationCapabilitySeeder`.
        //
        // Seeder ini sempat membuat kategori `dimensi` sendiri. Yang lahir dari
        // situ kategori HANTU: kelompok kesebelas, nol kemampuan kalibrasi di
        // dalamnya, sementara alat contohnya duduk di sana dan Micrometer tetap
        // terdaftar di Panjang. Di HP teknisi melihat sebelas kartu kategori
        // dan satu di antaranya kosong. Nol error di mana pun.
        //
        // Dijaga `KategoriAlatIkutLampiranTest`.
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Panjang')],
            ['organization_id' => 1, 'nama' => 'Panjang'],
        );

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => (string) $m['serial']],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $m['nama_alat'],
                // Kunci pencocokan ke profilnya — ejaannya harus PERSIS seperti
                // di lampiran akreditasi. Kalau meleset, alatnya jatuh ke profil
                // default (pH) tanpa satu pun error.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => $m['merk'],
                'model' => $m['model'],
                // Rentang alat SELALU mm, apa pun skala alatnya — kolom ini
                // bukan tempat satuan teknisi hidup. Master menyimpan
                // penunjukan dalam satuan ALAT lalu mengalikannya di dalam
                // rumus, dan itu yang melahirkan sesi 0-25 mm berkapasitas
                // 635 mm.
                //
                // Pembacaan teknisi mengambil jalan yang BERBEDA: dia disimpan
                // mentah berikut `raw_measurements.satuan`, dan baru diubah ke
                // mm di tempat pakai (`MicrometerMentah::keMm()`). Mengubahnya
                // di ujung masuk pernah dicoba dan ternyata tidak idempoten —
                // simpan draft dua kali mengalikan 25,4 tiap kali.
                //
                // Batas bawah DITURUNKAN dari `rentang` ("25-50"), bukan
                // dipatok 25: tiap varian punya batas bawahnya sendiri, dan
                // angka yang dipatok diam-diam jadi salah — dan yang muncul
                // bukan error melainkan peringatan "di luar rentang" di tiap
                // baris sesi contohnya.
                'range_min' => self::batasBawah((string) $m['rentang']),
                'range_max' => (float) $m['kapasitas_mm'],
                'satuan' => 'mm',
                'resolusi' => (float) $m['resolusi_mm'],
                // NULL: lampiran maupun keempat master nggak menyebut satu pun
                // batas keberterimaan, jadi sesi ini nggak divonis PASS/FAIL.
                'toleransi' => null,
                'lokasi' => 'Lab PT. Sidik',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => (string) $m['nomor_sertifikat']],
            [
                'equipment_id' => $alat->id,
                'nomor_order' => $m['nomor_order'],
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => $m['tanggal'],
                'tanggal_terima' => $m['tanggal_terima'],
                'lokasi' => 'lab',
                'suhu_awal' => $m['suhu_awal'],
                'suhu_akhir' => $m['suhu_akhir'],
                'kelembaban_awal' => $m['rh_awal'],
                'kelembaban_akhir' => $m['rh_akhir'],
                'alat_merk' => $m['merk'],
                'alat_model' => $m['model'],
                'alat_serial_number' => (string) $m['serial'],
                'pemilik_nama' => $m['pelanggan'],
                'pemilik_alamat' => $m['alamat'],
                'spesifikasi_alat' => [
                    'rentang_ukur' => (string) $m['rentang'],
                    'kapasitas' => (string) $m['kapasitas_mm'],
                    'resolusi' => (string) $m['resolusi_mm'],
                    'satuan' => 'mm',
                    // Blok tingkat-SESI. Pra-evaluasi, suhu, kapasitas, dan
                    // resolusi bukan titik ukur — memaksanya jadi `titik_ke`
                    // melahirkan titik hantu yang selalu gagal hitung ulang.
                    // Empat kunci saja — yang dipungut KERTAS. Suhu balok ukur
                    // & suhu UUT diturunkan dari suhu ruangan, dan balok ukur
                    // pra-evaluasi ditentukan varian; tidak satu pun disimpan
                    // lagi di sini.
                    MicrometerMentah::KUNCI_SESI => [
                        // `mm`, BUKAN `$m['satuan_alat']` — dan bedanya penting.
                        //
                        // Satuan blok ini menyatakan angka pra-evaluasi di
                        // bawahnya ditulis dalam satuan apa, dan yang disalin
                        // seeder ini angka master: semuanya MILIMETER, termasuk
                        // sesi 0-25 mm yang di masternya berlabel `inch`.
                        // Menyalin label `inch`-nya bikin
                        // `MicrometerMentah::blokSesi()` mengalikan angka yang
                        // sudah mm dengan 25,4.
                        //
                        // Kejanggalan `inch` masternya sendiri TIDAK hilang dari
                        // demo: dia tetap muncul lewat kapasitas 635 mm yang
                        // menjatuhkan sesi itu ke luar keempat pita CMC —
                        // persis rantai yang dibahas di
                        // `docs/pertanyaan-lab-micrometer.md` §3.
                        'satuan' => 'mm',
                        'kapasitas_mm' => (float) $m['kapasitas_mm'],
                        'resolusi_mm' => (float) $m['resolusi_mm'],
                        'pra_evaluasi' => array_map('floatval', $data['pra_evaluasi_mm']),
                    ],
                ],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $siapHitung = [];

        // Nominal & tumpukan datang dari VARIAN kertas, bukan dari sesi master
        // — jalur yang sama persis dengan `susunBlokMicrometer()`. Angkanya
        // memang sama (dua-duanya lahir dari workbook yang sama), dan
        // memakainya dari satu sumber yang sama itu yang menjaga keduanya tidak
        // menyimpang diam-diam.
        $tabel = new TabelStandarMicrometer;
        $varian = $tabel->pitaCmc((float) $m['kapasitas_mm']);
        $suhuRata = MicrometerMentah::rataSuhuRuang($m['suhu_awal'], $m['suhu_akhir']);

        foreach ($data['titik'] as $i => $titik) {
            $titikKe = (int) $titik['titik_ke'];
            $bawaan = $varian['titik'][$i] ?? null;

            if ($bawaan === null) {
                continue;
            }

            $titikUkur = (float) $bawaan['nominal_cetak_mm'];

            $deret = [
                MicrometerMentah::PERAN_BALOK => array_map('floatval', $bawaan['tumpukan_mm']),
                MicrometerMentah::PERAN_PEMBACAAN => array_map('floatval', $titik['pembacaan_mm']),
            ];

            foreach ($deret as $peran => $nilai) {
                foreach ($nilai as $ke => $angka) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $ke + 1,
                        // Urutan keping dalam tumpukan / nomor ulangan —
                        // `MicrometerMentah` mengurut ulang lewat kolom ini.
                        'sensor_ke' => $ke + 1,
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => $titikUkur,
                        // Angka master semuanya MILIMETER — termasuk sesi 0-25 mm
                        // yang di masternya berlabel `inch`. Lihat blok
                        // `micrometer.satuan` di atas.
                        'pembacaan' => $angka,
                        'satuan' => 'mm',
                        'standard_id' => $standar->id,
                        'input_source' => 'manual',
                        // Diketik tangan, bukan kamera. Tanpa penanda ini
                        // validator melaporkan `ocr_belum_diverifikasi` di
                        // sesi contoh, dan temuan palsu di data demo melatih
                        // admin mengabaikan temuan yang sama waktu dia nyata.
                        'is_verified' => true,
                    ]);
                }
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $titikUkur,
                // Jalur datar TIDAK dipakai alat ini — lihat
                // `CalibrationController::susunBlokMicrometer()`.
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    MicrometerMentah::PERAN_BALOK => $deret[MicrometerMentah::PERAN_BALOK],
                    MicrometerMentah::PERAN_PEMBACAAN => $deret[MicrometerMentah::PERAN_PEMBACAAN],
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi,
                    'suhu_ruang_rata' => $suhuRata,
                ],
            ];
        }

        // Angkanya DIHITUNG lewat profilnya, bukan ditempel. Kalau mesin
        // hitungnya bergeser, `HitungUlangSemuaSesiTest` yang merah — bukan
        // angka tempelan yang diam-diam ikut bergeser.
        $this->tulisHitungan($sesi, (new MicrometerProfile)->hitungPerGrup($siapHitung, $alat));
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
        // memunculkan `env_condition` di setiap sesi. Data contoh yang
        // memunculkan temuan palsu melatih pembacanya mengabaikan temuan.
        app(KondisiLingkungan::class)->terapkan($sesi->fresh()->load('thermohygro'));
    }
}
