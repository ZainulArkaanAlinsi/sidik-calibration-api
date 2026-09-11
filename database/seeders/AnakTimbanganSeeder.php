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
use App\Services\Calibration\Profiles\AnakTimbanganProfile;
use App\Services\Calibration\TabelStandarAnakTimbangan;
use App\Services\KondisiLingkungan;
use App\Support\AnakTimbanganMentah;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sesi contoh **Anak Timbangan** (OIML R111) — alat ke-29, kelompok Massa.
 *
 * Sumbernya `1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx`, sesi
 * `001-CAL-126`. Masukannya digenerate `docs/skrip/gen-sesi-anak-timbangan.py`
 * ke `database/data/sesi-master-anak-timbangan.json`, bukan diketik.
 *
 * ## Angkanya DIHITUNG, bukan ditempel
 *
 * Yang ditanam cuma MASUKAN — empat penimbangan ABBA tiap keping dan keenam
 * ujung kondisi ruangan. Hasilnya lahir dari
 * `AnakTimbanganProfile::hitungPerGrup()` lewat jalur yang sama dengan sesi
 * sungguhan, jadi kalau mesin hitungnya bergeser `HitungUlangSemuaSesiTest` yang
 * merah — bukan angka tempelan yang diam-diam ikut bergeser.
 *
 * ## Identitas pelanggan SINTETIS
 *
 * `PT Contoh Kalibrasi Nusantara`, sama seperti seluruh seeder lain di repo ini.
 * Alasannya bukan visibility repo hari ini melainkan riwayat git: `git log -S`
 * membaca seluruh masa lalu.
 *
 * ## EMPAT BELAS keping, bukan dua puluh
 *
 * Enam keping master sengaja tidak ikut — lima yang masternya terbitkan sebagai
 * `#VALUE!` (densitas kelas F1 tidak ditabelkan untuk 5 mg–50 mg, pertanyaan lab
 * §4) dan satu keping 10 g yang salah ketik satu digit dan terbit **5,500163 g**,
 * meleset 45 % (pertanyaan lab §3).
 *
 * Keenamnya tidak hilang dari bukti: `AnakTimbanganMasterTest` menanam kedua
 * puluh titik dan menuntut keenam itu DITOLAK dengan alasan yang kebaca. Yang
 * tidak ikut cuma ke sesi contoh — data demo yang selamanya membawa enam temuan
 * melatih admin menekan "setujui tetap" tanpa membaca, dan itu persis kebiasaan
 * yang bikin sertifikat rusak lolos.
 *
 * ## Angka sesi ini SENGAJA beda dari yang tercetak di sertifikat master
 *
 * Untuk sebelas dari empat belas keping, massa konvensional di sini **tidak
 * sama** dengan yang dicetak master — dan itu perbaikan yang disengaja, bukan
 * penyimpangan. Master mengalikan koreksi apung tiap keping dengan massa keping
 * PERTAMA (100,000144 g) alih-alih massa keping yang sedang dihitung; rujukan
 * relatif yang tidak ikut bergeser waktu rumusnya di-drag ke bawah. Selisihnya
 * sampai 2,58 mg pada keping 1 g yang toleransinya 0,10 mg. Pertanyaan lab §2,
 * dan `AnakTimbanganMasterTest` menegakkan ARAH perbaikannya, bukan cuma
 * "berbeda".
 *
 * ## Sesinya terbit DI LUAR lingkup akreditasi, dan itu ditanam apa adanya
 *
 * Kalibrasi anak timbangan tidak ada di lampiran LK-285-IDN, jadi sesi ini
 * memunculkan peringatan `anak_timbangan_diluar_akreditasi` dan
 * `anak_timbangan_densitas_disengketakan` di `kalibrasi:sapu-sesi`. Keduanya
 * temuan yang BENAR, bukan artefak data demo — dan dibiarkan muncul justru
 * supaya admin melihat bentuknya sebelum sesi pelanggan sungguhan datang.
 */
class AnakTimbanganSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Lima neraca yang tercetak di blok `2. STANDARD USED` sertifikat master.
     *
     * Kesemuanya diseed, bukan cuma yang dipakai sesi ini. Alasannya bukan
     * kerapian: blok "Standard Used" lembar kerja mencocokkan baris tercetak ke
     * master data lewat `tautkanStandarTercetak()`, dan yang tidak ketemu
     * tampil sebagai baris tanpa tautan — persis keluhan "belum terdaftar di
     * master standar".
     *
     * `berlaku_sampai` memakai kolom `DATABASE` yang berlabel **"Due Date
     * Calibration"**. Kalau label itu benar, seluruh sesi master dikerjakan
     * dengan neraca yang sudah lewat masa berlakunya; kalau yang dimaksud
     * sebenarnya tanggal kalibrasi, tidak ada masalah. Pertanyaan lab §17 —
     * sampai dijawab, tanggalnya disalin apa adanya dan [berlakuSampaiDemo]
     * yang memanjangkannya untuk data demo.
     */
    private const NERACA = [
        ['nama' => 'Semi Micro Balance', 'merk' => 'OHAUS', 'model' => 'PIONEER/PX85',
            'serial' => 'C543502629', 'tertelusur' => 'LK-064-IDN', 'berlaku_sampai' => '2026-01-26',
            'u' => 9.7e-05],
        ['nama' => 'Analytical Balance', 'merk' => 'Mettler Toledo', 'model' => 'XS204',
            'serial' => '1129063525', 'tertelusur' => 'LK-305-IDN', 'berlaku_sampai' => '2026-01-19',
            'u' => 0.0011],
        ['nama' => 'Electronic Balance Fujitsu', 'merk' => 'Fujitsu', 'model' => 'FSR-A',
            'serial' => 'SIDIK/134/2024', 'tertelusur' => 'LK-305-IDN', 'berlaku_sampai' => '2026-01-19',
            'u' => 0.0019],
        ['nama' => 'Electronic Balance Excellent', 'merk' => 'Excellent', 'model' => 'DJ',
            'serial' => 'HSEX1403752', 'tertelusur' => 'LK-305-IDN', 'berlaku_sampai' => '2026-01-19',
            'u' => 0.007],
        ['nama' => 'Electronic Balance  Mettler', 'merk' => 'Mettler Toledo', 'model' => 'IND690',
            'serial' => '3127471', 'tertelusur' => 'LK-305-IDN', 'berlaku_sampai' => '2026-01-19',
            'u' => 0.32],
    ];

    public function run(): void
    {
        $teknisi = User::where('organization_id', 1)->orderBy('id')->firstOrFail();

        $data = json_decode(
            (string) file_get_contents(database_path('data/sesi-master-anak-timbangan.json')),
            true,
        );

        $m = $data['sesi'];

        $this->seedSetStandar();
        $neraca = $this->seedNeraca();
        $standar = $neraca[$m['timbangan']] ?? reset($neraca);

        $thermohygro = Standard::where('organization_id', 1)
            ->where('nama', $m['thermohygro'])
            ->first();

        $pelanggan = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $m['pelanggan']],
            ['organization_id' => 1, 'alamat' => $m['alamat']],
        );

        // **Massa** — kelompok lampiran akreditasi yang sudah ada (dipakai
        // bareng Timbangan), bukan kategori baru. Alat ini memang belum
        // diakreditasi, tapi kelompoknya ada; kategori sendiri melahirkan kartu
        // kategori hantu di HP. Dijaga `KategoriAlatIkutLampiranTest`.
        $kategori = EquipmentCategory::updateOrCreate(
            ['organization_id' => 1, 'kode' => Str::slug('Massa')],
            ['organization_id' => 1, 'nama' => 'Massa'],
        );

        $profil = new AnakTimbanganProfile;

        $alat = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => (string) $m['serial']],
            [
                'organization_id' => 1,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $m['nama_alat'],
                // Kunci pencocokan ke profilnya — ejaannya harus PERSIS sama
                // dengan `namaAlatKemampuan()`. Kalau meleset, alatnya jatuh ke
                // `TimbanganProfile` (yang mengklaim ejaan `Timbangan`) tanpa
                // satu pun error: bentuk lembar yang sah, alat yang salah.
                'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
                'merk' => $m['merk'],
                'model' => $m['kelas_uut'],
                'range_min' => 0,
                'range_max' => (float) $m['kapasitas_g'],
                'satuan' => AnakTimbanganProfile::SATUAN,
                // Anak timbangan tidak punya daya baca — `Ketelitian Baca Alat`
                // di kepala budget master memang 0. Yang punya resolusi
                // neracanya, dan itu dibaca dari tabel standar.
                'resolusi' => null,
                // NULL: sertifikat master terbit TANPA kolom lulus/tidak lulus
                // walau tabel MPE OIML R111 lengkap ada di workbook yang sama.
                // Menambahkannya sendiri berarti mencetak vonis yang lab belum
                // pernah membuatnya. Pertanyaan lab §10.
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
                'kelembaban_awal' => $m['kelembaban_awal'],
                'kelembaban_akhir' => $m['kelembaban_akhir'],
                'alat_merk' => $m['merk'],
                'alat_model' => $m['kelas_uut'],
                'alat_serial_number' => (string) $m['serial'],
                'pemilik_nama' => $m['pelanggan'],
                'pemilik_alamat' => $m['alamat'],
                'spesifikasi_alat' => [
                    'rentang_ukur' => (string) $m['rentang'],
                    'kapasitas' => (string) $m['kapasitas_g'],
                    'satuan' => AnakTimbanganProfile::SATUAN,
                    // Blok tingkat-SESI. Kelas OIML, neraca, meter lingkungan,
                    // dan keenam ujung kondisi ruangan bukan titik ukur —
                    // memaksanya jadi `titik_ke` melahirkan titik hantu yang
                    // selalu gagal hitung ulang.
                    AnakTimbanganMentah::KUNCI_SESI => [
                        'kelas_uut' => $m['kelas_uut'],
                        'kelas_standar' => $m['kelas_standar'],
                        'timbangan' => $m['timbangan'],
                        'meter_lingkungan' => $m['meter_lingkungan'],
                        'kapasitas_g' => (float) $m['kapasitas_g'],
                        // Kedua ujung, bukan rata-ratanya: rata-rata memasok
                        // densitas udara, SELISIHNYA memasok ketidakpastian
                        // kondisi lingkungan yang tercetak di kepala sertifikat.
                        'suhu_awal' => $m['suhu_awal'],
                        'suhu_akhir' => $m['suhu_akhir'],
                        'kelembaban_awal' => $m['kelembaban_awal'],
                        'kelembaban_akhir' => $m['kelembaban_akhir'],
                        // Kertas Rev.0 belum punya kolom ini, tapi tanpa
                        // tekanan densitas udara tidak bisa dihitung dan koreksi
                        // apung SELURUH keping hilang. Pertanyaan lab §21.
                        'tekanan_awal' => $m['tekanan_awal'],
                        'tekanan_akhir' => $m['tekanan_akhir'],
                        // Penanda keping kembar. Master mencetak `-` dua puluh
                        // kali; yang di sini sintetis (pertanyaan lab §11).
                        'identitas' => $data['identitas'],
                    ],
                ],
            ],
        );

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $siapHitung = [];

        foreach ($data['titik'] as $titik) {
            $titikKe = (int) $titik['titik_ke'];
            $nominal = (float) $titik['nominal_g'];

            // Penjagaan yang menolak diam-diam: nominal yang tidak ada di tabel
            // keping standar tidak boleh lahir jadi baris mentah. Master
            // membungkus pencariannya dengan IFERROR, jadi di sana nominal yang
            // salah ketik lahir sebagai massa standar NOL alih-alih error.
            if (TabelStandarAnakTimbangan::cariKeping($nominal) === null) {
                throw new RuntimeException(
                    "Sesi contoh Anak Timbangan titik {$titikKe}: nominal {$nominal} g nggak ada di "
                    .'tabel keping standar. Jalankan ulang docs/skrip/gen-sesi-anak-timbangan.py.',
                );
            }

            $konteksTitik = [];

            foreach (AnakTimbanganMentah::PERAN_URUT as $peran) {
                $deret = array_map('floatval', $titik[$peran] ?? []);

                foreach ($deret as $ke => $angka) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titikKe,
                        'pembacaan_ke' => $ke + 1,
                        // Peran ABBA — BUKAN `pembacaan_ke` 1..4. Keempatnya
                        // punya tanda yang berbeda di `de`, jadi urutan baris
                        // dari database tidak boleh yang menentukan.
                        'peran_sensor' => $peran,
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => $nominal,
                        'pembacaan' => $angka,
                        'satuan' => AnakTimbanganProfile::SATUAN,
                        'standard_id' => $standar->id,
                        'input_source' => 'manual',
                        // Diketik tangan, bukan kamera. Tanpa penanda ini
                        // validator melaporkan `ocr_belum_diverifikasi` di sesi
                        // contoh, dan temuan palsu di data demo melatih admin
                        // mengabaikan temuan yang sama waktu dia nyata.
                        'is_verified' => true,
                    ]);
                }

                $konteksTitik[$peran] = $deret === []
                    ? null
                    : array_sum($deret) / count($deret);
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $nominal,
                // Jalur datar TIDAK dipakai alat ini — keempat peran ABBA punya
                // tanda yang berbeda, dan meratakannya jadi satu deret membuat
                // rata-ratanya campur aduk lintas peran.
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    ...$konteksTitik,
                    'spesifikasi_alat' => $sesi->spesifikasi_alat,
                    'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi,
                ],
            ];
        }

        $this->tulisHitungan($sesi, $profil->hitungPerGrup($siapHitung, $alat));
    }

    /**
     * Lima neraca standar.
     *
     * @return array<string, Standard>
     */
    private function seedNeraca(): array
    {
        $hasil = [];

        foreach (self::NERACA as $n) {
            $hasil[$n['nama']] = Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => $n['nama']],
                [
                    'organization_id' => 1,
                    'merk' => $n['merk'],
                    'model' => $n['model'],
                    'serial_number' => $n['serial'],
                    'no_sertifikat' => $n['serial'],
                    'tertelusur_ke' => $n['tertelusur'],
                    // Dipanjangkan ke masa berlaku demo kalau yang asli sudah
                    // lewat. Tanpa ini `kalibrasi:sapu-sesi` melaporkan
                    // `standar_kadaluarsa` di sesi contoh — temuan yang benar
                    // untuk data demo, tapi melatih pembacanya mengabaikan
                    // temuan yang sama waktu dia nyata.
                    'berlaku_sampai' => $this->berlakuSampaiDemo($n['nama'], $n['berlaku_sampai']),
                    'ketidakpastian' => $n['u'],
                    'satuan_ketidakpastian' => AnakTimbanganProfile::SATUAN,
                    'faktor_cakupan' => 2,
                ],
            );
        }

        return $hasil;
    }

    /**
     * Tujuh set anak timbangan standar, dari tabel standar yang digenerate.
     *
     * Dibaca dari `TabelStandarAnakTimbangan::setStandar()`, bukan diketik di
     * sini: keduanya lahir dari workbook yang sama, dan memakainya dari satu
     * sumber yang sama itu yang menjaga keduanya tidak menyimpang diam-diam.
     */
    private function seedSetStandar(): void
    {
        foreach (TabelStandarAnakTimbangan::setStandar() as $set) {
            [$merk, $tipe] = array_pad(explode('/', (string) $set['merk_tipe'], 2), 2, '');

            Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => (string) $set['nama']],
                [
                    'organization_id' => 1,
                    'merk' => $merk,
                    'model' => $tipe,
                    'serial_number' => (string) $set['no_seri'],
                    'no_sertifikat' => (string) ($set['no_sertifikat'] ?: $set['no_seri']),
                    'tertelusur_ke' => (string) $set['tertelusur'],
                    'berlaku_sampai' => $this->berlakuSampaiDemo(
                        (string) $set['nama'],
                        (string) $set['due_date'],
                    ),
                    // Ketidakpastian keping ada PER NOMINAL, bukan satu angka
                    // per set — 0,0008 mg di 1 mg sampai 0,03 mg di 200 g. Yang
                    // membacanya buat budget `TabelStandarAnakTimbangan`, jadi
                    // kolom ini sengaja null: satu angka di sini pasti salah
                    // untuk dua puluh delapan nominal lainnya.
                    'ketidakpastian' => null,
                    'satuan_ketidakpastian' => 'mg',
                    'faktor_cakupan' => 2,
                ],
            );
        }
    }

    /** @param  array<string, mixed>|null  $perGrup */
    private function tulisHitungan(CalibrationSession $sesi, ?array $perGrup): void
    {
        $belum = $perGrup['belum_dihitung'] ?? [];

        // Sesi contoh yang titiknya ditolak itu BUG di generatornya, bukan
        // temuan — keempat belas keping di sini sudah disaring justru supaya
        // semuanya lolos. Kalau ada yang ditolak, yang salah datanya, dan
        // seeder yang "sukses" separuh jauh lebih berbahaya daripada yang gagal.
        if ($belum !== []) {
            $alasan = implode(' | ', array_map(
                static fn (array $d): string => "titik {$d['titik_ke']}: {$d['alasan']}",
                $belum,
            ));

            throw new RuntimeException("Sesi contoh Anak Timbangan nggak utuh — {$alasan}");
        }

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
