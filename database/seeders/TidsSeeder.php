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
use App\Services\Calibration\Profiles\TidsProfile;
use App\Services\Calibration\TabelStandarTids;
use App\Services\KondisiLingkungan;
use App\Support\PasanganStandarUutMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sesi contoh **TIDS** — Temperatur Indikator dengan Sensor, alat ke-12.
 *
 * Sumbernya `Master Olah Data Suhu TIDS - Recorder Graptech.xlsm`, sesi
 * `071-CAL-325`. Keempat titiknya (0 / 60 / 100 / 200 °C) berikut kelima
 * pembacaan standar & UUT tiap titik disalin dari `INPUT DATA` baris 33..36 dan
 * 50..53 — sel yang sama yang diadu `Tests\Unit\TidsMasterTest`.
 *
 * ## Kenapa seeder ini ditulis belakangan, dan kenapa itu masalah
 *
 * TIDS satu-satunya dari 29 alat yang TIDAK punya sesi contoh sampai
 * 11 Sep 2026. Akibatnya bukan cuma kosmetik:
 *
 *  - `kalibrasi:uji-profil` — perintah kesiapan yang dijalankan sebelum sesi uji
 *    lapangan — melewati TIDS dengan baris `-`, karena tidak ada alat contoh
 *    yang bisa diperiksa. Perintah itu ada justru untuk menangkap profil yang
 *    diam-diam berhenti menghitung.
 *  - `HitungUlangSemuaSesiTest` menyapu sesi ter-seed. Tanpa sesi TIDS, jalur
 *    hitung ulang alat ini tidak pernah dilalui satu test pun — padahal itu
 *    jalur KEDUA yang menghitung hal yang sama, dan pola "alat baru lupa
 *    disambung ke jalur hitung ulang" sudah menggigit tujuh kali di repo ini.
 *  - Sertifikatnya tidak pernah dirender, jadi `SertifikatSemuaAlatSatuHalaman`
 *    dan `SertifikatPunyaMargin` tidak pernah memeriksanya.
 *
 * Rumusnya sendiri tidak pernah bermasalah: `TidsCalculator` mereproduksi kedua
 * workbook master sampai digit terakhir sejak 28 Agt 2026. Yang hilang cuma
 * datanya.
 *
 * ## Angkanya DIHITUNG, bukan ditempel
 *
 * Yang ditanam cuma MASUKAN. Hasilnya lahir dari `TidsProfile::hitungPerGrup()`
 * lewat jalur yang sama dengan sesi sungguhan.
 *
 * ## Empat penyimpangan master ikut terbawa, dan itu disengaja
 *
 * Sesi ini memunculkan peringatan `tids_penyimpangan_master` di
 * `kalibrasi:sapu-sesi`, karena masternya memang memuat empat penyimpangan yang
 * ditiru apa adanya (U95 kalibrator dari sel tetap, U95 sensor literal, drift
 * dari sel tabel koreksi, dan penjumlahan yang cuma mencakup sembilan dari dua
 * belas komponen). Tiga di antaranya menggeser U95 ke arah lebih KECIL.
 *
 * Peringatan itu **temuan yang benar**, bukan artefak data demo — dan dibiarkan
 * muncul justru supaya admin melihat bentuknya sebelum sesi pelanggan datang.
 * Rinciannya di `docs/pertanyaan-lab-tids-workbook.md`.
 *
 * ## Identitas pelanggan SINTETIS
 *
 * `PT Contoh Kalibrasi Nusantara`, sama seperti seluruh seeder lain.
 *
 * PENTING — urutan run: harus SESUDAH `ThermohygroSeeder` (menautkan TH sebagai
 * sumber koreksi kondisi lingkungan) dan `KemampuanKalibrasiSeeder`.
 */
class TidsSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /** Set point + kelima pembacaan standar & UUT, `INPUT DATA` 33..36 / 50..53. */
    private const TITIK = [
        ['titik_ke' => 1, 'set_point' => 0.0, 'no_probe' => 1,
            'standar' => [0.0, 0.0, 0.0, 0.0, 0.0],
            'uut' => [0.0, 0.0, 0.0, 0.0, 0.0]],
        ['titik_ke' => 2, 'set_point' => 60.0, 'no_probe' => 2,
            'standar' => [60.1, 60.1, 60.1, 60.2, 60.2],
            'uut' => [60.3, 60.34, 60.45, 60.47, 60.41]],
        ['titik_ke' => 3, 'set_point' => 100.0, 'no_probe' => 3,
            'standar' => [92.8, 93.4, 94.5, 94.6, 94.6],
            'uut' => [92.29, 92.85, 94.05, 94.09, 94.12]],
        ['titik_ke' => 4, 'set_point' => 200.0, 'no_probe' => 4,
            'standar' => [193.0, 193.0, 193.1, 193.1, 193.1],
            'uut' => [192.17, 192.19, 192.21, 192.24, 192.25]],
    ];

    /**
     * Uji titik es 0 °C, `INPUT DATA!N50` & `P50`.
     *
     * Awal & akhir, dan SELISIHNYA yang melahirkan komponen `Drift UUT`.
     * Dikosongkan, komponennya jadi nol — dan nol itu klaim ("alatnya tidak
     * drift sama sekali"), bukan ketiadaan data.
     */
    private const TITIK_ES = [0.2, 0.4];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $standar = $this->seedStandar();

        $thermohygro = Standard::where('organization_id', 1)
            ->where('nama', 'TH-2')
            ->first();

        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT Contoh Kalibrasi Nusantara'],
            ['organization_id' => 1, 'alamat' => 'Kawasan Industri Contoh Blok A No. 1, Indonesia'],
        );

        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Suhu dan Kelembapan')],
            ['organization_id' => 1, 'nama' => 'Suhu dan Kelembapan'],
        );

        $profil = new TidsProfile;

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => 'DEMO-TIDS-GL840'],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Temperature Recorder',
                // Kunci pencocokan ke profilnya — ejaannya harus PERSIS sama
                // dengan `namaAlatKemampuan()`.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => 'Graphtec',
                'model' => 'GL240',
                'range_min' => -20,
                'range_max' => 600,
                'satuan' => TidsProfile::SATUAN,
                // `INPUT DATA!E16` — resolusi UUT sesi contoh. Masuk budget
                // sebagai komponen tersendiri, jadi bukan angka hiasan.
                'resolusi' => 0.01,
                // NULL: master TIDS berhenti di `Correction` + `U95%` tanpa
                // batas keberterimaan, jadi sesinya tidak divonis PASS/FAIL.
                'toleransi' => null,
                'lokasi' => 'Lab PT. Sidik',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => 'DEMO-TIDS-001'],
            [
                'equipment_id' => $alat->id,
                'nomor_order' => 'DEMO-TIDS-ORD-001',
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2026-03-25',
                'tanggal_terima' => '2026-03-24',
                'lokasi' => 'lab',
                'suhu_awal' => 23.4,
                'suhu_akhir' => 23.6,
                'kelembaban_awal' => 54.0,
                'kelembaban_akhir' => 55.0,
                'alat_merk' => 'Graphtec',
                'alat_model' => 'GL240',
                'alat_serial_number' => 'DEMO-TIDS-GL840',
                'pemilik_nama' => $pelanggan->nama,
                'pemilik_alamat' => $pelanggan->alamat,
                // Kolom SESI, bukan `spesifikasi_alat`: profilnya membaca
                // dua-duanya dan kolom sesi yang menang. Tipe sensor menentukan
                // koreksi meter, koreksi sensor, U95, DAN drift — tanpa itu
                // sesinya tidak kehitung sama sekali.
                'tipe_sensor' => 'Type K',
                // Dryblock: Isotech = A. Koreksi Isotech dan Techne berbeda
                // (keseragaman media 0,47 lawan 0,1 °C), jadi merk yang tidak
                // tercatat berarti dua komponen budget tidak punya angka.
                'alat_bantu' => 'A',
                // KOLOM SESI, bukan cuma `spesifikasi_alat` — dan bedanya
                // bukan kerapian. `CalibrationValidator` merakit ulang konteks
                // hitung ulang dari `$sesi->titik_es`, sementara cadangan di
                // `spesifikasi_alat` yang dibaca profil bernama
                // `titik_es_awal`/`titik_es_akhir`, BUKAN `titik_es`.
                //
                // Versi pertama seeder ini menaruhnya di `spesifikasi_alat`
                // dengan nama `titik_es`, dan akibatnya persis kelas kegagalan
                // yang paling mahal: jalur simpan memakai [0,2 · 0,4] sementara
                // jalur hitung ulang membacanya KOSONG, komponen `Drift UUT`
                // jatuh ke nol, dan U95-nya berbeda. Tidak ada error di mana
                // pun — `HitungUlangSemuaSesiTest` yang menangkapnya sebagai
                // `hitung_ulang_beda`, dan test itu baru bisa melihat TIDS
                // sesudah sesi contoh ini ada.
                'titik_es' => self::TITIK_ES,
                'spesifikasi_alat' => [
                    'rentang_ukur' => '-20 s.d. 600 °C',
                    'resolusi' => '0.01',
                    'satuan' => TidsProfile::SATUAN,
                    // Cadangan buat APK lama yang mengirim lewat peta ini —
                    // ejaan kertasnya, dua kunci terpisah.
                    'sensor_standar' => 'Type K',
                    'dryblock' => 'A',
                    'titik_es_awal' => self::TITIK_ES[0],
                    'titik_es_akhir' => self::TITIK_ES[1],
                ],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $siapHitung = [];

        foreach (self::TITIK as $t) {
            foreach ([
                PasanganStandarUutMentah::PERAN_STANDAR => $t['standar'],
                PasanganStandarUutMentah::PERAN_UUT => $t['uut'],
            ] as $peran => $deret) {
                foreach ($deret as $ke => $nilai) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $t['titik_ke'],
                        'pembacaan_ke' => $ke + 1,
                        // Nomor probe cuma menempel ke sisi STANDAR — sisi UUT
                        // memakai sensor bawaan alat pelanggan.
                        'sensor_ke' => $peran === PasanganStandarUutMentah::PERAN_STANDAR
                            ? $t['no_probe']
                            : null,
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => $t['set_point'],
                        'pembacaan' => (float) $nilai,
                        'satuan' => TidsProfile::SATUAN,
                        'standard_id' => $standar->id,
                        'input_source' => 'manual',
                        // Diketik tangan, bukan kamera. Tanpa penanda ini
                        // validator melaporkan `ocr_belum_diverifikasi` di sesi
                        // contoh, dan temuan palsu di data demo melatih admin
                        // mengabaikan temuan yang sama waktu dia nyata.
                        'is_verified' => true,
                    ]);
                }
            }

            $siapHitung[] = [
                'titik_ke' => $t['titik_ke'],
                'titik_ukur' => $t['set_point'],
                // Jalur datar TIDAK dipakai alat ini: satu titik punya DUA
                // deret yang artinya beda, dan meratakannya jadi satu
                // `pembacaan` membuat rata-ratanya campur aduk lintas peran.
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    'standar' => $t['standar'],
                    'uut' => $t['uut'],
                    'no_probe' => $t['no_probe'],
                    'tipe_sensor' => $sesi->tipe_sensor,
                    'alat_bantu' => $sesi->alat_bantu,
                    'titik_es' => self::TITIK_ES,
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                ],
            ];
        }

        $this->tulisHitungan($sesi, $profil->hitungPerGrup($siapHitung, $alat));
    }

    /**
     * Recorder Graptech GL840 — keluarga standar `recorder`.
     *
     * Nomor serinya MENGIKAT: `TidsProfile::keluargaStandar()` mencocokkannya
     * ke `TabelStandarTids::KELUARGA_SERTIFIKAT`, dan keluarga itu yang memilih
     * tabel koreksi mana yang dibaca. Serial yang meleset membuat sesinya jatuh
     * ke keluarga lain — tabel koreksi yang salah, tanpa satu pun error.
     */
    private function seedStandar(): Standard
    {
        $sertifikat = TabelStandarTids::KELUARGA_SERTIFIKAT['recorder'];
        [$merk, $tipe] = array_pad(explode('/', (string) $sertifikat['merk_tipe'], 2), 2, '');

        return Standard::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'Temperature Recorder Graptech GL840'],
            [
                'organization_id' => 1,
                'merk' => $merk,
                'model' => $tipe,
                'serial_number' => $sertifikat['serial'],
                'no_sertifikat' => $sertifikat['serial'],
                'tertelusur_ke' => $sertifikat['tertelusur'],
                // Dipanjangkan ke masa berlaku demo kalau yang asli sudah lewat.
                'berlaku_sampai' => $this->berlakuSampaiDemo(
                    'Temperature Recorder Graptech GL840',
                    '2027-02-18',
                ),
                // Ketidakpastian kalibratornya dibaca PER TIPE SENSOR dari
                // `TabelStandarTids`, bukan dari kolom ini — satu angka di sini
                // pasti salah untuk dua tipe sensor lainnya.
                'ketidakpastian' => null,
                'satuan_ketidakpastian' => TidsProfile::SATUAN,
                'faktor_cakupan' => 2,
            ],
        );
    }

    /** @param  array<string, mixed>|null  $perGrup */
    private function tulisHitungan(CalibrationSession $sesi, ?array $perGrup): void
    {
        $belum = $perGrup['belum_dihitung'] ?? [];

        // Sesi contoh yang titiknya ditahan itu BUG di seeder ini, bukan temuan:
        // keempat titiknya diambil dari sesi master yang memang kehitung penuh.
        // Seeder yang "sukses" separuh jauh lebih berbahaya daripada yang gagal
        // — dia menanam sesi yang kelihatan lengkap dengan titik yang hilang.
        if ($belum !== []) {
            $alasan = implode(' | ', array_map(
                static fn (array $d): string => "titik {$d['titik_ke']}: {$d['alasan']}",
                $belum,
            ));

            throw new RuntimeException("Sesi contoh TIDS nggak utuh — {$alasan}");
        }

        $versiRumus = $this->versiRumusUntuk($sesi);

        foreach ($perGrup['hitungan'] ?? [] as $baris) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$baris,
            ]);
        }

        // TIDS tidak divonis (`punyaToleransi()` false) — keputusan sesi null.
        $sesi->update(['keputusan' => null]);

        // Suhu & kelembapan RUANG diturunkan di sini, bukan ditulis tangan —
        // jalur yang sama persis dengan `POST /calibrations`.
        app(KondisiLingkungan::class)->terapkan($sesi->fresh()->load('thermohygro'));
    }
}
