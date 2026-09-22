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
use App\Services\Calibration\Profiles\BuretProfile;
use App\Services\Calibration\Profiles\GelasUkurProfile;
use App\Services\Calibration\Profiles\LabuUkurProfile;
use App\Services\Calibration\Profiles\PicnometerProfile;
use App\Services\Calibration\Profiles\PipetUkurProfile;
use App\Services\Calibration\Profiles\PipetVolumeProfile;
use App\Services\Calibration\Profiles\VolumetricGlasswareProfile;
use App\Services\KondisiLingkungan;
use App\Support\VolumetricGlasswareMentah as M;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh **Volumetric Glassware** — satu per profil, enam sesi.
 *
 * ## Masukan mentahnya dari mana
 *
 * Workbook master cuma memuat DUA contoh: Pipet Volume 1 mL (Fixed) dan Gelas
 * Ukur 100 mL titik 10/50/100 (Graduated). Kedua sesi itu di sini persis
 * masukan `INPUT_DATA` master, dan hasilnya sudah diadu ke sheet `SERTIFIKAT`
 * di `tests/Feature/VolumetricGlasswareSesiTest.php`.
 *
 * Empat alat lainnya **meminjam** masukan mentah contoh keluarganya — Labu
 * Ukur & Picnometer memakai timbangan Pipet Volume, Buret & Pipet Ukur memakai
 * titik 10 & 50 mL Gelas Ukur. Itu data contoh untuk membuka lembar &
 * menjalankan `kalibrasi:uji-profil`, BUKAN pengukuran alat-alat itu. Angka
 * hasilnya tetap DIHITUNG profilnya, tidak ditempel.
 *
 * Nama pelanggan sintetis (AGENTS.md §Data sumber & dokumen). Nomor sesi
 * `DEMO-VOL-*` — bukan nomor resmi.
 */
class VolumetricGlasswareSeeder extends Seeder
{
    use MenstempelVersiRumus;

    /** Pipet Volume 1 mL — `Fixed_…/INPUT_DATA`. */
    private const TITIK_FIXED = [
        ['titik_ukur' => 1.0, 'kosong' => [0.0, 0.0, 0.0], 'isi' => [0.9998, 0.9997, 0.9996], 'suhu' => [27.0, 27.0, 27.0]],
    ];

    /** Gelas Ukur 100 mL — `Graduated_…/INPUT_DATA`. */
    private const TITIK_GRADUATED = [
        ['titik_ukur' => 10.0, 'kosong' => [60.234, 60.24, 60.243], 'isi' => [70.7791, 70.7965, 70.8854], 'suhu' => [25.4, 25.3, 25.4]],
        ['titik_ukur' => 50.0, 'kosong' => [60.255, 60.258, 60.263], 'isi' => [110.944, 110.9023, 111.0863], 'suhu' => [25.4, 25.3, 25.5]],
        ['titik_ukur' => 100.0, 'kosong' => [60.241, 60.247, 60.25], 'isi' => [159.4398, 159.5042, 159.4852], 'suhu' => [25.3, 25.2, 25.4]],
    ];

    private const LINGKUNGAN_FIXED = [
        'suhu_awal' => 21.0, 'suhu_akhir' => 20.5, 'kelembaban_awal' => 48, 'kelembaban_akhir' => 47,
        'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
    ];

    private const LINGKUNGAN_GRADUATED = [
        'suhu_awal' => 20.4, 'suhu_akhir' => 20.5, 'kelembaban_awal' => 64, 'kelembaban_akhir' => 62,
        'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
    ];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Volume'))
            ->firstOrFail();

        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT CONTOH LABORATORIUM KIMIA'],
            [
                'organization_id' => 1,
                'alamat' => 'JL. CONTOH RAYA NO. 2, KEC. CONTOH TENGAH, KOTA CONTOH, PROVINSI CONTOH 10000',
            ],
        );

        $thermohygro = Standard::where('organization_id', 1)->where('nama', 'TH-3')->first();

        // [profil, nomor, titik, kapasitas, blok keluarga]
        $daftar = [
            [new PipetVolumeProfile, 1, self::TITIK_FIXED, 1.0, ['toleransi_ml' => 0.008]],
            [new LabuUkurProfile, 2, self::TITIK_FIXED, 1.0, ['toleransi_ml' => 0.008]],
            [new PicnometerProfile, 3, self::TITIK_FIXED, 1.0, ['toleransi_ml' => 0.008]],
            [new GelasUkurProfile, 4, self::TITIK_GRADUATED, 100.0, ['toleransi_ml' => 0.5, 'resolusi_ml' => 1.0]],
            [new BuretProfile, 5, array_slice(self::TITIK_GRADUATED, 0, 2), 50.0, ['toleransi_ml' => 0.05, 'resolusi_ml' => 0.1]],
            [new PipetUkurProfile, 6, array_slice(self::TITIK_GRADUATED, 0, 2), 50.0, ['toleransi_ml' => 0.1, 'resolusi_ml' => 0.1]],
        ];

        foreach ($daftar as [$profil, $nomor, $titik, $kapasitas, $blokKeluarga]) {
            $this->seedSatu($profil, $nomor, $titik, $kapasitas, $blokKeluarga, $kategori, $pelanggan, $teknisi, $thermohygro);
        }
    }

    /**
     * @param  list<array{titik_ukur: float, kosong: list<float>, isi: list<float>, suhu: list<float>}>  $titik
     * @param  array<string, float>  $blokKeluarga
     */
    private function seedSatu(
        VolumetricGlasswareProfile $profil,
        int $nomor,
        array $titik,
        float $kapasitas,
        array $blokKeluarga,
        EquipmentCategory $kategori,
        Customer $pelanggan,
        User $teknisi,
        ?Standard $thermohygro,
    ): void {
        $fixed = $profil->keluarga() === 'fixed';
        $namaNeraca = $fixed ? 'Analytical Balance' : 'Electronic Balance Precisa';

        // Neraca Precisa tidak punya baris `standards` (lihat
        // docs/volumetric-sisa-pekerjaan.md) — standar sesinya neraca tercetak
        // yang ada; yang menentukan angka blok `neraca` di bawah.
        $standar = Standard::where('organization_id', 1)
            ->where('nama', $fixed ? 'Analytical Balance' : 'Electronic Balance Excellent')
            ->first();

        $serial = sprintf('DEMO-VOL-%03d', $nomor);

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => $serial],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $profil->namaAlatKemampuan(),
                // Ejaan PERSIS `namaAlatKemampuan()` — meleset, alatnya jatuh
                // ke profil generik tanpa satu pun error.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => 'Pyrex',
                'range_min' => 0,
                'range_max' => $kapasitas,
                'satuan' => VolumetricGlasswareProfile::SATUAN,
                'resolusi' => $blokKeluarga['resolusi_ml'] ?? null,
                'toleransi' => null,
                'lokasi' => 'Lab. Volumetrik PT. SIDIK',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $lingkungan = $fixed ? self::LINGKUNGAN_FIXED : self::LINGKUNGAN_GRADUATED;

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => $serial],
            [
                'equipment_id' => $alat->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar?->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2026-09-22',
                'tanggal_terima' => '2026-09-21',
                'lokasi' => 'lab',
                ...$lingkungan,
                'alat_merk' => 'Pyrex',
                'alat_serial_number' => $serial,
                'pemilik_nama' => $pelanggan->nama,
                'pemilik_alamat' => $pelanggan->alamat,
                'spesifikasi_alat' => [M::KUNCI_SESI => [
                    'kelas' => 'B',
                    'kapasitas_ml' => $kapasitas,
                    'neraca' => $namaNeraca,
                    ...$blokKeluarga,
                ]],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $siapHitung = [];

        foreach ($titik as $i => $t) {
            $titikKe = $i + 1;

            foreach ([
                M::PERAN_KOSONG => [$t['kosong'], M::SATUAN_MASSA],
                M::PERAN_ISI => [$t['isi'], M::SATUAN_MASSA],
                M::PERAN_SUHU => [$t['suhu'], M::SATUAN_SUHU],
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
                        'standard_id' => $standar?->id,
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);
                }
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $t['titik_ukur'],
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    M::KONTEKS_KOSONG => $t['kosong'],
                    M::KONTEKS_ISI => $t['isi'],
                    M::KONTEKS_SUHU => $t['suhu'],
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi,
                    ...$lingkungan,
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
}
