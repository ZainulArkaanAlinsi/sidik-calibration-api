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
use App\Services\Calibration\Profiles\SieveProfile;
use App\Services\Calibration\TabelStandarSieve;
use App\Services\KondisiLingkungan;
use App\Support\SieveMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sesi contoh **Sieve Mesh** — lampiran LK-285-IDN no. 33.
 *
 * Sumbernya `Master Olah Data_Sieve Mesh.xlsm` (ber-password), sesi
 * `0736-CAL-526`: sieve 19 mm tipe Inspection, 15 opening, diukur Digital
 * Caliper. Masukannya digenerate `docs/skrip/gen-sesi-sieve.py` ke
 * `database/data/sesi-master-sieve.json`, bukan diketik.
 *
 * ## Angkanya DIHITUNG, bukan ditempel
 *
 * Yang ditanam cuma opening mentah + blok sesi; tiga grup hasilnya lahir dari
 * `SieveProfile::hitungPerGrup()`. U ketiga grup sama persis dengan master
 * (tidak ada komponen budget yang tersentuh penyimpangan), tapi nilai
 * terkoreksi warp & weft **0,01 mm lebih besar** dari cetakan master: master
 * membaca koreksi standar dari kolom kosong (selalu 0). Lihat
 * `SieveCalculator`.
 */
class SieveSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();
        $tabel = new TabelStandarSieve;

        $data = json_decode(
            (string) file_get_contents(database_path('data/sesi-master-sieve.json')),
            true,
        );

        $m = $data['_sesi'];
        $standar = $this->seedStandar($tabel, $m['standar_dipakai']);
        $this->seedStandar($tabel, $m['standar_dipakai'] === 'caliper' ? 'mikroskop' : 'caliper');

        $thermohygro = Standard::where('organization_id', 1)->where('nama', $m['thermohygro'])->first();

        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $m['pelanggan']],
            ['organization_id' => 1, 'alamat' => $m['alamat']],
        );

        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Panjang')],
            ['organization_id' => 1, 'nama' => 'Panjang'],
        );

        $profil = new SieveProfile;
        $mpe = $tabel->barisMpe((float) $m['nominal'], $m['satuan']);

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => (string) $m['serial']],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $m['nama_alat'],
                // Ejaan PERSIS `namaAlatKemampuan()` — meleset, alatnya jatuh ke
                // profil generik tanpa satu pun error.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => $m['merk'],
                'model' => $m['model'],
                'range_min' => 0,
                // Nominal + X (bukaan individu terbesar yang diizinkan ASTM E11),
                // bukan nominal telanjang: lubang sieve 19 mm yang sah terukur
                // 19,29 mm, dan rentang yang berhenti di 19 membuat validator
                // memunculkan `pembacaan_di_luar_rentang` palsu di sesi contoh.
                'range_max' => (float) $mpe['ukuran_mm'] + (float) $mpe['x_mm'],
                'satuan' => 'mm',
                'resolusi' => $tabel->standar($m['standar_dipakai'])['resolusi_mm'],
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
                    'rentang_ukur' => (string) $m['nominal'],
                    'satuan' => $m['satuan'],
                    SieveMentah::KUNCI_SESI => [
                        'tipe' => $m['tipe'],
                        'nominal' => (float) $m['nominal'],
                        'satuan' => $m['satuan'],
                        'standar_dipakai' => $m['standar_dipakai'],
                        'jumlah_opening_total' => null,
                        'frame' => array_map(
                            static fn (float $d, float $t): array => ['diameter' => $d, 'tinggi' => $t],
                            $m['frame_diameter_mm'],
                            $m['frame_tinggi_mm'],
                        ),
                    ],
                ],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $nominalParameter = [
            SieveMentah::PERAN_WARP => (float) $mpe['ukuran_mm'],
            SieveMentah::PERAN_WEFT => (float) $mpe['ukuran_mm'],
            SieveMentah::PERAN_KAWAT => (float) $mpe['kawat_preferred_mm'],
        ];

        $deret = array_fill_keys(SieveMentah::PERAN_URUT, []);

        foreach ($data['opening'] as $o) {
            foreach (SieveMentah::PARAMETER as $peran => $parameter) {
                if ($o[$parameter] === null) {
                    continue;
                }

                $deret[$peran][(int) $o['no']] = (float) $o[$parameter];

                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id,
                    'titik_ke' => SieveMentah::titikKe($peran),
                    'pembacaan_ke' => (int) $o['no'],
                    // Nomor OPENING di lembar — sumber pengulangan (opening 1..6).
                    'sensor_ke' => (int) $o['no'],
                    'peran_sensor' => $peran,
                    'tahap' => 'sesudah_adjustment',
                    'titik_ukur' => $nominalParameter[$peran],
                    'pembacaan' => (float) $o[$parameter],
                    'satuan' => $m['satuan'],
                    'standard_id' => $standar->id,
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }
        }

        $suhuRata = SieveMentah::rataSuhuRuang($m['suhu_awal'], $m['suhu_akhir']);
        $siapHitung = [];

        foreach (SieveMentah::PERAN_URUT as $peran) {
            $siapHitung[] = [
                'titik_ke' => SieveMentah::titikKe($peran),
                'titik_ukur' => $nominalParameter[$peran],
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    $peran => $deret[$peran],
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi,
                    'suhu_ruang_rata' => $suhuRata,
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
     * Digital Caliper (`DATABASE!R13`) / Digital Microscope (`R14`). Kuncinya
     * nama + seri, SAMA dengan `FlowmeterSeeder` — Digital Caliper Tesa
     * LPI-0368 itu keping fisik yang sama, jadi satu baris.
     */
    private function seedStandar(TabelStandarSieve $tabel, string $kunci): Standard
    {
        $s = $tabel->standar($kunci);
        [$merk, $tipe] = array_pad(explode('/', $s['merk_tipe'], 2), 2, null);
        $u95 = $tabel->u95Sertifikat($kunci, 'warp');

        return Standard::updateOrCreate(
            ['organization_id' => 1, 'nama' => $s['nama'], 'serial_number' => $s['seri']],
            [
                'organization_id' => 1,
                'merk' => $merk,
                'model' => $tipe,
                'no_sertifikat' => $s['seri'],
                'tertelusur_ke' => $s['traceability'],
                'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['berlaku_sampai']),
                'ketidakpastian' => $u95,
                'satuan_ketidakpastian' => 'mm',
                'faktor_cakupan' => $kunci === 'mikroskop' ? 2.02 : 2,
            ],
        );
    }
}
