<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\WaktuCalculator;
use App\Support\WaktuMentah;
use Carbon\Carbon;

/**
 * Profil **Timer/Stopwatch** — lampiran akreditasi LK-285-IDN kelompok "Waktu
 * dan Frekuensi" no. 37, satu pita CMC 5–3600 detik → 0,81 detik.
 *
 * Mesin hitungnya di `App\Services\Calibration\WaktuCalculator`, tabel
 * standarnya di `App\Services\Calibration\TabelStandarWaktu`, dan penyusun
 * ulang baris mentahnya di [WaktuMentah].
 *
 * ## Yang membedakannya dari kelompok Putaran
 *
 * Di sini KEDUA sisi dibaca: teknisi menekan stopwatch standar dan alat
 * pelanggan berbarengan, tiga kali per set point. Jadi satu titik punya dua
 * deret — dan itulah kenapa [WaktuMentah] harus ada, sementara kelompok
 * Putaran yang bentuknya deret datar tidak membutuhkannya.
 *
 * Budget-nya juga lahir per TITIK, bukan per blok tiga: master membuat satu
 * blok `Set Point n` untuk tiap titik.
 *
 * ## Master ini cuma punya satu blok yang menghitung
 *
 * Dari lima blok `Set Point` di `Master Olda Timer dan Stopwatch.xlsm`, hanya
 * yang pertama utuh. Empat sisanya `#REF!` — komponen pengulangannya menunjuk
 * sel yang sudah tidak ada — DAN penjumlahannya memotong dua komponen human
 * reaction (`SUM(AC28:AC31)`, empat komponen, alih-alih enam seperti blok
 * pertama), DAN tiga di antaranya memakai `k = 2` yang diketik tangan alih-alih
 * `TINV`.
 *
 * Yang ditiru adalah blok pertama — satu-satunya yang bisa diadu — dan enam
 * komponennya diberlakukan untuk semua titik. Artinya hitungan kita tidak bisa
 * dibuktikan cocok di titik ke-2 dan seterusnya, karena masternya sendiri tidak
 * punya angka pembanding di situ. Ditulis terang di
 * `docs/pertanyaan-lab-waktu-frekuensi.md` §5, bukan disembunyikan.
 */
class TimerStopwatchProfile extends CalibrationProfile
{
    public const KODE_METODE = 'SIDIK-IK-CAL-0509_Rev.6';

    public const SATUAN = 's';

    /** Tiga ulangan per set point — `PERHITUNGAN` baris 1..3 tiap blok. */
    public const PENGULANGAN = 3;

    /** Sepuluh baris set point, sebanyak yang tercetak di lembar master. */
    public const BARIS_KERTAS = 10;

    /**
     * Baris `Standard Used` yang TERCETAK di kertasnya — sheet `SERTIFIKAT
     * KALIBRATOR` masternya menyebut stopwatch Casio digital s/n SW-1.
     */
    public const STANDARD_TERCETAK = [
        [
            'label' => 'Stopwatch/CASIO/Digital/SW-1',
            'cocok' => ['Stopwatch SW-1', 'SW-1'],
        ],
    ];

    /** Ketujuh unit thermohygro yang tercetak di kop master (`TH-1`…`TH-7`). */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    /**
     * Set point SARAN dalam DETIK, disalin dari sesi contoh master (1, 5, 10,
     * 15, dan 30 menit) lalu dilanjutkan ke nominal sertifikat berikutnya.
     *
     * Saran, bukan patokan — `titik_bisa_diubah` menyala. Diisi karena baris
     * ber-`titik_ukur` null tidak punya standar tertaut sama sekali:
     * `tautkanStandarTitik()` mencocokkan lewat NILAI titiknya.
     */
    public const TITIK_SARAN = [60.0, 300.0, 600.0, 900.0, 1800.0, 2400.0, 3600.0, 120.0, 180.0, 240.0];

    private ?WaktuCalculator $kalk = null;

    public function kode(): string
    {
        return 'timer_stopwatch';
    }

    /**
     * Persis seperti tertulis di lampiran akreditasi baris no. 37, garis
     * miringnya ikut. Nama yang meleset membuat baris CMC-nya tidak ketemu dan
     * sesinya terbit tanpa lantai ketidakpastian.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Timer/Stopwatch';
    }

    /**
     * Ejaan lain yang PUNYA BUKTI di data:
     *
     *  - `Stopwatch` — `INPUT DATA!E10` master menulisnya begitu, dan itu juga
     *    ejaan yang paling lazim di kolom `nama_alat` alat pelanggan.
     *  - `Timer` — tabel metode kalibrasi no. 9 (`Timer & Stopwatch`).
     *  - `Timer dan Stopwatch` / `Timer & Stopwatch` — judul workbook & metode.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Stopwatch', 'Timer', 'Timer dan Stopwatch', 'Timer & Stopwatch'];
    }

    /**
     * Kalibratornya SAMA di setiap titik — satu stopwatch Casio SW-1 yang
     * sertifikatnya mencakup 5..3600 detik. Tetap ditulis per titik karena itu
     * bentuk yang dibaca `tautkanStandarTitik()`, dan lewat situ pula
     * `standard_id` mendarat di tiap baris tabel.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return array_map(
            static fn (float $titik): array => [
                'titik' => $titik,
                'standar' => ['Stopwatch SW-1', 'SW-1'],
            ],
            self::TITIK_SARAN,
        );
    }

    public function kodeFormula(): string
    {
        return 'gum-waktu';
    }

    public function besaran(): string
    {
        return 'waktu';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function u95PerTitik(): bool
    {
        return true;
    }

    /**
     * `titik_ukur` lembar ini menyimpan SET POINT (60 detik), sedangkan nilai
     * acuan yang dicetak master di kolom `Standard Value` adalah penunjukan
     * stopwatch standar yang SUDAH dikoreksi (60,096 detik) — lihat
     * `SERTIFIKAT!E19:L19` yang menunjuk baris `STD CORRECTED`, bukan set point.
     *
     * `rata_rata` di sini sudah benar sejak awal (rata-rata penunjukan alat
     * pelanggan), jadi yang perlu disusun balik cuma kolom standarnya.
     */
    public function nilaiStandarDariKoreksi(): bool
    {
        return true;
    }

    /**
     * Master menyimpan koreksi dalam milidetik tapi mencetak sertifikatnya
     * dalam detik dengan tiga desimal — sepadan dengan resolusi stopwatch
     * (0,001 s). U95 dicetak dua desimal (`0,81`).
     */
    public function desimalSertifikat(): ?int
    {
        return 3;
    }

    public function desimalU95(): ?int
    {
        return 2;
    }

    /** Sama seperti kelompok Putaran: lampiran maupun master tidak menyebut batas keberterimaan. */
    public function punyaToleransi(): bool
    {
        return false;
    }

    public function minPengulanganPerTitik(): int
    {
        return 2;
    }

    /** Stopwatch standar dibaca nominal apa adanya — tidak berkurva suhu. */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * Jalur kamera DIMATIKAN sampai kertasnya turun — alasan yang sama persis
     * dengan `ProfilPutaran::bentukPindaiFoto()`: workbook sudah disapu untuk
     * pola `SIDIK-FM-…` dan yang ketemu cuma footer sertifikat bersama
     * (`SIDIK-FM-CAL-2403_Rev. 0`).
     *
     * Di sini ada alasan kedua yang lebih tajam: satu ulangan lembar ini
     * ditulis di EMPAT kotak (J/M/S/ms) yang harus dibaca sebagai satu angka.
     * Salah satu kotak yang terbaca meleset satu kolom mengubah waktunya
     * ribuan kali lipat, dan hasilnya tetap kelihatan seperti angka yang sah.
     */
    public function bentukPindaiFoto(): array
    {
        return [
            'kolom_suhu' => false,
            'standar_di_baris' => false,
            'didukung' => false,
            'lokal' => true,
        ];
    }

    /**
     * Menyalakan penyusunan ulang [WaktuMentah] di jalur hitung ulang, sejajar
     * dengan `butuhGridSensor()` / `butuhPasanganStandarUut()` / `butuhBlokTimbangan()`.
     */
    public function butuhBlokWaktu(): bool
    {
        return true;
    }

    /** Budget lahir di [hitungPerGrup] — lihat alasan yang sama di ProfilPutaran. */
    public function komponenBudget(
        CalibrationCapability $kemampuan,
        Equipment $equipment,
        Standard $standard,
        float $titikUkur,
        float $typeA,
        int $n,
        ?float $suhuRuang = null,
        array $konteksTitik = [],
    ): ?array {
        return null;
    }

    /**
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard, konteks?: array<string, mixed>}>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}|null
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        $kalk = $this->kalk ??= new WaktuCalculator;
        $kemampuan = $this->kemampuanSesi($equipment);
        $resolusi = (float) ($equipment->resolusi ?: 0.001);

        $hitungan = [];
        $belumDihitung = [];
        $sekarang = Carbon::now();

        foreach ($titik as $t) {
            // Kedua deret datang lewat `konteks`, bukan level atas — jalur
            // simpan (`CalibrationController::susunPengukuran()`) dan jalur
            // hitung ulang (`HitungUlangSesi`) sama-sama menaruhnya di situ,
            // persis seperti blok Timbangan dan grid Enclosure.
            $k = $t['konteks'] ?? [];
            $standar = array_map('floatval', $k[WaktuMentah::PERAN_STANDAR] ?? []);
            $uut = array_map('floatval', $k[WaktuMentah::PERAN_UUT] ?? []);

            // Sesi yang baris mentahnya belum ber-`peran_sensor` — mis. lembar
            // lama, atau payload yang salah bentuk — DITOLAK dengan alasan yang
            // kebaca, bukan diam-diam dihitung dari `pembacaan` datar. Deret
            // datar tidak bisa dibedakan mana standar mana UUT, dan koreksi yang
            // lahir dari situ tidak berarti apa-apa.
            if ($standar === [] && $uut === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d nggak punya baris ber-peran `%s`/`%s`. Lembar Timer/Stopwatch '
                        .'nyimpen DUA deret per titik (standar & alat), dan koreksinya selisih '
                        .'rata-rata keduanya — deret datar nggak bisa dipakai.',
                        $t['titik_ke'], WaktuMentah::PERAN_STANDAR, WaktuMentah::PERAN_UUT,
                    ),
                ];

                continue;
            }

            $hasil = $kalk->hitungTitik(
                [
                    'titik_ke' => (int) $t['titik_ke'],
                    'titik_ukur' => (float) $t['titik_ukur'],
                    'standar' => $standar,
                    'uut' => $uut,
                ],
                [
                    'resolusi_uut_detik' => $resolusi,
                    'cmc' => $kemampuan?->ketidakpastian_terbaik === null
                        ? null
                        : (float) $kemampuan->ketidakpastian_terbaik,
                ],
            );

            if ($hasil['hasil'] === null) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => (string) $hasil['alasan'],
                ];

                continue;
            }

            $h = $hasil['hasil'];

            $hitungan[] = [
                // Null-safe — alasan yang sama dengan `ProfilPutaran`: sesi
                // yang kalibratornya di-soft-delete memulangkan null, dan tanpa
                // `?->` perintah hitung ulang mati total.
                'standard_id' => ($t['standard'] ?? null)?->id,
                'titik_ke' => $h['titik_ke'],
                'titik_ukur' => $h['titik_ukur'],
                'rata_rata' => $h['rata_rata'],
                'error' => $h['error'],
                'koreksi' => $h['koreksi'],
                'standar_deviasi' => $h['standar_deviasi'],
                'jumlah_pengulangan' => $h['jumlah_pengulangan'],
                'type_a' => $h['type_a'],
                'type_b_components' => $this->jejakAudit($hasil['budget'], $h, $kemampuan),
                'type_b' => $h['type_b'],
                'ketidakpastian_gabungan' => $h['ketidakpastian_gabungan'],
                'faktor_cakupan_k' => $h['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $h['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $h['u95_sertifikat'],
                'toleransi' => null,
                'keputusan' => null,
                'metode' => $kemampuan?->metode,
                'calculated_at' => $sekarang,
            ];
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Satu-satunya baris CMC alat ini (5–3600 detik → 0,81 detik), disaring
     * organisasi DAN kategori — lihat alasan panjangnya di
     * `ProfilPutaran::kemampuanUntukBlok`.
     */
    private function kemampuanSesi(Equipment $equipment): ?CalibrationCapability
    {
        return CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->when(
                $equipment->equipment_category_id !== null,
                fn ($q) => $q->where('equipment_category_id', $equipment->equipment_category_id),
            )
            ->when(
                $equipment->organization_id !== null,
                fn ($q) => $q->milikOrganisasi($equipment->organization_id),
            )
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $budget
     * @param  array<string, mixed>  $h
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $budget, array $h, ?CalibrationCapability $kemampuan): array
    {
        $budget[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'Set point %s s · nominal sertifikat %s s · koreksi standar %s ms · '
                .'rata-rata standar %s s · rata-rata alat %s s · U sebelum lantai CMC %s · CMC %s',
                $h['titik_ukur'], $h['nominal_standar'], $h['koreksi_standar_ms'],
                $h['rata_rata_standar'], $h['rata_rata'],
                $h['ketidakpastian_diperluas'], $kemampuan?->ketidakpastian_terbaik ?? '-',
            ),
            'distribusi' => 'jejak',
            'u' => 0.0,
            'ci' => 0.0,
            'vi' => 0.0,
        ];

        return $budget;
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            'kode_dokumen' => null,
            'kode_metode' => self::KODE_METODE,
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => 'Calibration Work Sheet - Stopwatch / Timer',
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Tiap SET POINT ditekan tiga kali: stopwatch standar dan alat '
                .'pelanggan dijalankan BERBARENGAN, lalu dua-duanya dicatat. Isi jam/menit/detik/'
                .'milidetik apa adanya seperti yang tampil di layar masing-masing. Set point yang '
                .'nggak dipakai dikosongin — jangan diisi nol, karena baris nol tetap melahirkan '
                .'koreksi sebesar koreksi standarnya dan tercetak seperti titik sungguhan.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master Olda Timer dan Stopwatch.xlsm (16 Apr 2026)',
                'catatan' => 'Enam komponen per titik termasuk dua human reaction, lantai CMC 0,81 s. '
                    .'Tiga penyimpangan master dihitung benar dan dilaporkan lewat peringatan sesi.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianDataKalibrasi(),
                $this->bagianPenutup(),
            ],
        ];

        // Tiga penstempel, dan ketiganya harus jalan:
        //  - `tautkanStandarTitik` menaruh `standard_id` di tiap BARIS TABEL
        //    (lewat `standarPerTitik()`), yang dibaca layar teknisi & jalur
        //    kirim;
        //  - `tautkanStandarTercetak` menaruhnya di baris `Standard Used`;
        //  - `isiPilihanThermohygro` mengisi dropdown kondisi lingkungan.
        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak(
                $this->tautkanStandarTitik($bentuk, $equipment),
                $equipment,
            ),
            $equipment,
        );
    }

    /**
     * Tautkan baris `Standard Used` tercetak ke master milik LAB PEMILIK ALAT,
     * lewat `masterStandarTertaut()` — bukan query sendiri. Alasan panjangnya
     * sama dengan `ProfilPutaran::tautkanStandarTercetak()`.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanStandarTercetak(array $bentuk, ?Equipment $equipment): array
    {
        $master = $this->masterStandarTertaut($equipment);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            $bentuk['bagian'][$i]['baris'] = array_map(
                function (array $baris) use ($master): array {
                    $cocok = $this->cocokkanStandar($master, $baris['cocok']);

                    return [
                        'label' => $baris['label'],
                        'standard_id' => $cocok?->id,
                        'serial_number' => $cocok?->serial_number,
                        'no_sertifikat' => $cocok?->no_sertifikat,
                        'tertelusur_ke' => $cocok?->tertelusur_ke,
                        'terdaftar' => $cocok !== null,
                    ];
                },
                $bentuk['bagian'][$i]['baris'],
            );
        }

        return $bentuk;
    }

    /**
     * Isi dropdown "Environmental Meter Used" dengan ketujuh unit yang tercetak
     * di kop master (`INPUT DATA!W15` menawarkan TH-1..TH-7), disaring ke lab
     * pemilik alat.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function isiPilihanThermohygro(array $bentuk, ?Equipment $equipment = null): array
    {
        $master = $this->masterThermohygro($equipment)->pluck('id', 'nama');

        $pilihan = [];

        foreach (self::THERMOHYGRO_TERCETAK as $label) {
            $id = $master[$label] ?? null;

            if ($id === null) {
                continue;
            }

            $pilihan[] = ['nilai' => (string) $id, 'label' => $label, 'grup' => 'Thermohygro lab'];
        }

        foreach ($bentuk['bagian'] as $i => $bagian) {
            foreach ($bagian['field'] ?? [] as $j => $field) {
                if (($field['kode'] ?? null) === 'thermohygro_standard_id') {
                    $bentuk['bagian'][$i]['field'][$j]['pilihan'] = $pilihan;
                }
            }
        }

        return $bentuk;
    }

    /** @return array<string, mixed> */
    private function bagianIdentitas(): array
    {
        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Identitas Alat dan Data Customer',
            'field' => [
                $this->field('equipment_id', 'Nama Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Nama Alat', 'teks', sumber: 'otomatis'),
                $this->field('alat_merk', 'Merk', 'teks'),
                $this->field('alat_model', 'Type', 'teks'),
                $this->field('alat_serial_number', 'No. Seri', 'teks'),
                $this->field('spesifikasi_alat.rentang_ukur', 'Rentang Ukur', 'angka', satuan: 'min'),
                $this->field('spesifikasi_alat.kapasitas', 'Kapasitas Max.', 'angka', satuan: 'min'),
                $this->field('spesifikasi_alat.resolusi', 'Resolusi Alat', 'angka', satuan: self::SATUAN),
                $this->field('tanggal_terima', 'Tgl. Diterima', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Tgl. Kalibrasi', 'tanggal'),
                $this->field('suhu_awal', 'Suhu Ruangan — awal', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Suhu Ruangan — akhir', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Kelembapan — awal', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Kelembapan — akhir', 'angka', satuan: '%RH'),
                $this->field('lokasi', 'Lokasi Kalibrasi', 'pilihan', pilihan: [
                    ['nilai' => 'lab', 'label' => 'Inlab'],
                    ['nilai' => 'onsite', 'label' => 'Insitu'],
                ]),
                // Dua kotak lokasi yang saling meniadakan — tanpa
                // `tampil_kalau`, dropdown Ruangan tetap menyimpan pilihan lama
                // walau sedang Insitu, dan sertifikatnya mencetak nama ruang lab
                // yang tidak pernah didatangi.
                $this->field(
                    'room_id', 'Ruangan (Inlab)', 'pilihan',
                    sumber: 'master_ruangan', tampilKalau: self::TAMPIL_KALAU_INLAB,
                ),
                $this->field(
                    'lokasi_nama', 'Nama Tempat (Insitu)', 'teks',
                    tampilKalau: self::TAMPIL_KALAU_INSITU,
                ),
                $this->field('thermohygro_standard_id', 'Environmental Meter Used', 'pilihan', sumber: 'master_thermohygro'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianPemilik(): array
    {
        return [
            'kode' => 'pemilik',
            'halaman' => 1,
            'judul' => 'Data Customer',
            'field' => [
                $this->field('pemilik_nama', 'Nama Customer', 'teks'),
                $this->field('pemilik_alamat', 'Alamat Customer', 'teks_panjang'),
                $this->field('nomor_order', 'Order Number', 'teks'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianStandard(): array
    {
        return [
            'kode' => 'usage_check',
            'halaman' => 1,
            'judul' => 'Standard Used',
            'baris' => self::STANDARD_TERCETAK,
            'field' => [
                $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
            ],
        ];
    }

    /**
     * Dua tabel bersisian, `peran`-nya yang mengangkut arti standar/UUT —
     * dibaca [WaktuMentah] lewat `raw_measurements.peran_sensor`.
     *
     * @return array<string, mixed>
     */
    private function bagianDataKalibrasi(): array
    {
        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => 'Data Hasil Kalibrasi',
            'field' => [],
            'tabel' => [
                $this->tabelPembacaan(
                    peran: WaktuMentah::PERAN_STANDAR,
                    judul: 'Pembacaan Stopwatch Standar',
                ),
                $this->tabelPembacaan(
                    peran: WaktuMentah::PERAN_UUT,
                    judul: 'Pembacaan Alat yang Dikalibrasi',
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function tabelPembacaan(string $peran, string $judul): array
    {
        return [
            // `tahap` itu enum `raw_measurements` (sebelum/sesudah adjustment).
            // KEDUA tabel lembar ini `sesudah_adjustment` karena memang satu
            // tahap — yang beda SIAPA yang membaca, dan itu yang diangkut
            // `grup` di bawah.
            'tahap' => 'sesudah_adjustment',
            // `grup`, BUKAN `peran`.
            //
            // Di HP kunci `peran` berarti "lembar pasangan standar/UUT" dan
            // membelokkan seluruh jalur kirimnya ke bentuk yang dipakai ketiga
            // alat suhu — yang kosakatanya `standar`/`uut` dan yang disusun
            // `PasanganStandarUutMentah`, bukan `WaktuMentah`. Lembar ini
            // memang dua deret, tapi deretnya WAKTU dan penyusunnya lain.
            //
            // Dijaga `SemuaProfilLembarKerjaTest::test_peran_tabel_cuma_buat_lembar_pasangan`.
            'grup' => $peran,
            'judul' => $judul,
            'satuan' => self::SATUAN,
            'judul_nilai' => 'Set Point',
            'judul_pengulangan' => 'Ulangan (jam:menit:detik,ms)',
            'titik_bisa_diubah' => true,
            'baris' => array_map(
                static fn (int $n): array => [
                    'nomor' => $n,
                    'titik_ukur' => self::TITIK_SARAN[$n - 1] ?? null,
                    'label' => 'Set point '.$n,
                    'satuan' => self::SATUAN,
                ],
                range(1, self::BARIS_KERTAS),
            ),
            // Empat kolom per ulangan, persis kepala kolom masternya (`J M S
            // 0.001S`). Digabung jadi satu angka milidetik lewat
            // [WaktuMentah::keMilidetik] sebelum disimpan.
            'kolom' => [
                ['kode' => 'jam', 'label' => 'J', 'tipe' => 'angka', 'satuan' => 'jam'],
                ['kode' => 'menit', 'label' => 'M', 'tipe' => 'angka', 'satuan' => 'min'],
                ['kode' => 'detik', 'label' => 'S', 'tipe' => 'angka', 'satuan' => 's'],
                ['kode' => 'milidetik', 'label' => '0.001 S', 'tipe' => 'angka', 'satuan' => 'ms'],
            ],
            'pengulangan' => range(1, self::PENGULANGAN),
        ];
    }

    /** @return array<string, mixed> */
    private function bagianPenutup(): array
    {
        return [
            'kode' => 'penutup',
            'halaman' => 1,
            'judul' => 'Catatan & Tanda Tangan',
            'field' => [
                $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                $this->field('teknisi.nama', 'Dikalibrasi Oleh', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Diperiksa Oleh', 'teks', sumber: 'otomatis'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function field(
        string $kode,
        string $label,
        string $tipe,
        ?string $sumber = null,
        ?string $satuan = null,
        array $pilihan = [],
        bool $hanyaAdmin = false,
        ?array $tampilKalau = null,
    ): array {
        return [
            'kode' => $kode,
            'label' => $label,
            'tipe' => $tipe,
            'wajib' => false,
            'sumber' => $sumber,
            'satuan' => $satuan,
            'pilihan' => $pilihan,
            'hanya_admin' => $hanyaAdmin,
            'tampil_kalau' => $tampilKalau,
        ];
    }
}
