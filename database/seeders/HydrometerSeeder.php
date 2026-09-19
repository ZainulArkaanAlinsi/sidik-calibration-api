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
use App\Services\Calibration\Profiles\HydrometerProfile;
use App\Services\Calibration\TabelStandarHydrometer;
use App\Services\KondisiLingkungan;
use App\Support\HydrometerMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh **Hydrometer** — `Master Olah Data Hydrometer 0.600-0.650.xlsm`,
 * sertifikat terbit 8 Sep 2025, varian **dengan beban tambahan (sinker)**.
 *
 * ## Kenapa yang ringan, bukan yang berat
 *
 * Dua workbook master ada, dan yang ringan jalurnya lebih panjang: dia melewati
 * rumus berat semu sinker (`Q = Sl·(1 − ρ_liq/ρ_sinker)`) yang varian berat
 * lewati sama sekali, DAN ketiga skalanya jatuh ke lantai CMC sementara yang
 * berat tidak. Sesi contoh yang memakai jalur pendek membiarkan jalur panjang
 * tidak pernah dijalankan `kalibrasi:uji-profil` — dan yang tidak pernah
 * dijalankan tidak pernah ketahuan rusak.
 *
 * Angkanya diadu penuh di `tests/Unit/HydrometerMasterTest.php`; di sini dia
 * cuma dipasang sebagai sesi yang bisa dibuka orang.
 *
 * ## Neraca Fujitsu di-seed DI SINI, bukan dititipkan ke seeder lain
 *
 * `Analytical Balance Fujitsu FS-AR210` (INS-N1600555) belum pernah ada di
 * master `standards` — yang ada `Analytical Balance` milik Mettler Toledo
 * XS204 (Anak Timbangan) dan `Electronic Balance Fujitsu` (FSR-A). Ketiganya
 * neraca, dan nama telanjang `Analytical Balance` mendarat di Mettler: cocok,
 * terdaftar, dan ALAT YANG SALAH — seluruh `U massa aquadest` budget hydrometer
 * lahir dari sertifikat neraca yang di sini.
 *
 * Gejalanya nol: barisnya hijau di lembar kerja, sertifikatnya terbit, dan yang
 * salah cuma nomor sertifikat & ketertelusuran yang dicetak di bagian
 * `Standard Used`.
 */
class HydrometerSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /** Blok Pre Condition sesi contoh — `'INPUT DATA'!E31:E35` & `L33:N33`. */
    private const BLOK = [
        'pakai_beban_tambahan' => 'ya',
        'beban_tambahan' => 54.0052,
        'massa_udara' => 39.9327,
        'tegangan_permukaan' => 17.5,
        'satuan_tegangan' => 'mN/m',
        'suhu_acuan_alat' => 15,
        'suhu_acuan_faktor' => 20,
        'diameter_stem' => [0.708, 0.710, 0.709],
        'resolusi' => 0.0005,
        'satuan_densitas' => 'g/ml',
    ];

    /** Tiga titik skala — `'INPUT DATA'!G40:I43` (massa) & `G47:I49` (suhu). */
    private const TITIK = [
        ['titik_ukur' => 0.610, 'massa' => [21.2727, 21.2726, 21.2856], 'suhu' => [20.6, 20.6, 20.6]],
        ['titik_ukur' => 0.625, 'massa' => [22.7483, 22.7446, 22.7491], 'suhu' => [20.6, 20.6, 20.6]],
        ['titik_ukur' => 0.650, 'massa' => [25.4602, 25.4608, 25.4621], 'suhu' => [20.7, 20.7, 20.7]],
    ];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $profil = new HydrometerProfile;

        $neraca = $this->seedNeraca();

        $thermohygro = Standard::where('organization_id', 1)
            ->where('nama', 'Thermobarometer Lutron')
            ->first();

        // Nama & alamat SINTETIS, bukan pelanggan aslinya.
        //
        // Yang asli ada di workbook master (`Hydrometer 0.600-0.650 gmL.xlsx`,
        // `SERTIFIKAT!R2`/`R4`) dan sempat tersalin ke sini waktu alat ke-33
        // mendarat 18 Sep 2026 — sesudah sapuan besar `c0645f6` (10 Sep, 81
        // berkas), jadi dia lolos justru karena sapuannya sudah lewat.
        //
        // Polanya sama dengan yang dipakai `c0645f6`: sifat pelanggannya
        // dipertahankan (kilang, unit kilang) supaya sesi contohnya tetap masuk
        // akal, identitasnya diganti. Lihat AGENTS.md §Data sumber & dokumen.
        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT CONTOH KILANG NUSANTARA - REFINERY UNIT V'],
            [
                'organization_id' => 1,
                'alamat' => 'JL. CONTOH RAYA NO. 1, KEC. CONTOH TENGAH, KOTA CONTOH, '
                    .'PROVINSI CONTOH 10000',
            ],
        );

        // Kelompok **Densitas**, bukan "Volumetrik".
        //
        // Nama kelompoknya WAJIB salah satu dari sepuluh yang ada di lampiran
        // akreditasi (`database/data/kemampuan-kalibrasi.json`), dan Hydrometer
        // memang sudah terdaftar di sana — kelompok Densitas, no. 32, metode
        // `SIDIK-IK-CAL-0525 (Metode Cuckcow)`, lengkap dengan DUA pita CMC-nya.
        //
        // Versi pertama seeder ini bikin kategori baru bernama "Volumetrik"
        // (nama LAB-nya, yang memang Lab. Volumetrik) dan itu melahirkan
        // kategori hantu: kartunya muncul di HP, isinya kosong, dan alatnya
        // duduk di kelompok yang tidak terakreditasi. Dijaga
        // `KategoriAlatIkutLampiranTest` dari dua arah.
        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Densitas'))
            ->firstOrFail();

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => '350015'],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Hydrometer',
                // Ejaan PERSIS `namaAlatKemampuan()` — meleset, alatnya jatuh ke
                // profil generik tanpa satu pun error, dan lembar generik minta
                // teknisi mengetik PEMBACAAN untuk alat yang memungut massa.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => 'Alla France',
                'model' => 'L50- ISO 650',
                'range_min' => 0.600,
                'range_max' => 0.650,
                'satuan' => HydrometerProfile::SATUAN,
                'resolusi' => self::BLOK['resolusi'],
                // Hydrometer tidak divonis PASS/FAIL: sertifikat master berhenti
                // di `Correction` + `U95%`, tanpa satu pun batas keberterimaan.
                'toleransi' => null,
                'lokasi' => 'Lab. Volumetrik PT. SIDIK',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => 'DEMO-HYD-001'],
            [
                'equipment_id' => $alat->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $neraca->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2025-09-04',
                'tanggal_terima' => '2025-09-01',
                'lokasi' => 'lab',
                'suhu_awal' => 20.4,
                'suhu_akhir' => 20.5,
                'kelembaban_awal' => 56,
                'kelembaban_akhir' => 55,
                // Tekanan udara (hPa) — tanpa dia densitas udara tidak bisa
                // dihitung sama sekali, dan seluruh sesi pulang tanpa titik.
                'tekanan_awal' => 933.2,
                'tekanan_akhir' => 933.1,
                'alat_merk' => 'Alla France',
                'alat_model' => 'L50- ISO 650',
                'alat_serial_number' => '350015',
                'pemilik_nama' => $pelanggan->nama,
                'pemilik_alamat' => $pelanggan->alamat,
                'spesifikasi_alat' => [
                    'rentang_ukur' => '0.600-0.650',
                    HydrometerMentah::KUNCI_SESI => self::BLOK,
                ],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $siapHitung = [];

        foreach (self::TITIK as $i => $t) {
            $titikKe = $i + 1;

            foreach ([
                HydrometerMentah::PERAN_MASSA => [$t['massa'], HydrometerMentah::SATUAN_MASSA],
                HydrometerMentah::PERAN_SUHU => [$t['suhu'], '°C'],
            ] as $peran => [$deret, $satuan]) {
                foreach ($deret as $urutan => $nilai) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $urutan + 1,
                        'sensor_ke' => $urutan + 1,
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => $t['titik_ukur'],
                        'pembacaan' => $nilai,
                        'satuan' => $satuan,
                        'standard_id' => $neraca->id,
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);
                }
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $t['titik_ukur'],
                'pembacaan' => [],
                'standard' => $neraca,
                'konteks' => [
                    HydrometerMentah::KONTEKS_MASSA => $t['massa'],
                    HydrometerMentah::KONTEKS_SUHU => $t['suhu'],
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi,
                    'suhu_awal' => $sesi->suhu_awal,
                    'suhu_akhir' => $sesi->suhu_akhir,
                    'kelembaban_awal' => $sesi->kelembaban_awal,
                    'kelembaban_akhir' => $sesi->kelembaban_akhir,
                    'tekanan_awal' => $sesi->tekanan_awal,
                    'tekanan_akhir' => $sesi->tekanan_akhir,
                ],
            ];
        }

        $versiRumus = $this->versiRumusUntuk($sesi);

        foreach ($profil->hitungPerGrup($siapHitung, $alat)['hitungan'] ?? [] as $h) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                ...$h,
                'formula_version_id' => $versiRumus,
            ]);
        }

        app(KondisiLingkungan::class)->terapkan($sesi->fresh()->load('thermohygro'));
    }

    /**
     * Neraca analitik Fujitsu FS-AR210 — `NILAI U95%!E9:J9` master.
     *
     * `ketidakpastian` diambil dari blok `Utimb` masternya (`E29` = 0,00074 g,
     * k = 2), jadi angka yang tersimpan di master `standards` sama dengan yang
     * dipakai `TabelStandarHydrometer::uMassaAquadest()`. Kalau suatu saat
     * sertifikat neracanya diperbarui, dua tempat itu yang harus berubah
     * bareng — dan komentar ini yang menyebutkan keduanya.
     */
    private function seedNeraca(): Standard
    {
        return Standard::updateOrCreate(
            [
                'organization_id' => 1,
                'nama' => HydrometerProfile::NERACA_NAMA,
                'serial_number' => HydrometerProfile::NERACA_SERI,
            ],
            [
                'organization_id' => 1,
                'merk' => 'Fujitsu',
                'model' => 'FS-AR210',
                'no_sertifikat' => HydrometerProfile::NERACA_SERI,
                'tertelusur_ke' => 'LK-285-IDN',
                'berlaku_sampai' => $this->berlakuSampaiDemo(HydrometerProfile::NERACA_NAMA, null),
                'ketidakpastian' => TabelStandarHydrometer::U95_TIMBANGAN_LOP,
                'satuan_ketidakpastian' => 'g',
                'faktor_cakupan' => TabelStandarHydrometer::K_TIMBANGAN_LOP,
            ],
        );
    }
}
