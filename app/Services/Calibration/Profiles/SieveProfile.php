<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\SieveCalculator;
use App\Support\SieveMentah;
use Carbon\Carbon;

/**
 * Lembar & hitungan **Sieve Mesh** — lampiran akreditasi LK-285-IDN no. 33
 * ("Sieve": 45–4000 µm U 4,33 µm; 4–100 mm U 0,02 mm), metode
 * SIDIK-IK-CAL-0526, kertas SIDIK-FM-CAL-0536 Rev.2.
 *
 * Workbook master `Master Olah Data_Sieve Mesh.xlsm` (ber-password);
 * angkanya dijaga `SieveMasterTest` komponen demi komponen di ketiga budget.
 * Rumus, penyimpangan yang ditiru, dan yang dihitung benar ada di docblock
 * [SieveCalculator].
 *
 * ## Tiga grup, bukan seratus titik
 *
 * Satu sesi = sampai 100 opening × (warp, weft, Ø kawat), tapi ketidakpastian
 * dan vonisnya lahir per PARAMETER. Jadi `titik_ke` 1/2/3 = warp/weft/kawat —
 * lihat [SieveMentah]. `komponenBudget()` memulangkan `null` karena bentuk per
 * titik memang tidak ada.
 *
 * ## Tabel opening lewat `spesifikasi_alat`, bukan `measurements[]`
 *
 * Kertasnya satu tabel: tiap baris SATU opening dengan tiga kolom (Wrap x',
 * Weft y', Ø Kawat), dua blok × 15 baris. Di HP itu satu `tabel` berbaris
 * opening dan berkolom tiga — dikirim sebagai cerminan tabel lewat
 * `simpan_ke: spesifikasi_alat.sieve.opening`. Jalur `measurements[]` tidak
 * dipakai karena dua hal: tiap baris akan jadi satu "titik" ber-`titik_ukur`
 * wajib yang artinya cuma nomor opening, dan `measurements` dibatasi 60
 * sementara ASTM E11 meminta sampai 100 opening. Yang MENULIS
 * `raw_measurements` controller (`susunBlokSieve`), dan hitung ulang membaca
 * `raw_measurements` — cerminan di `spesifikasi_alat` cuma bahan lembar.
 *
 * ## Vonis
 *
 * Guarded acceptance (keputusan proyek 14 Jul): warp/weft PASS kalau
 * `|deviasi| + U ≤ Y` DAN simpangan baku ≤ batas; kawat PASS kalau Ø
 * terkoreksi di dalam [min, max]. Vonis versi master (tanpa U, tanpa koreksi
 * standar) ikut di jejak audit.
 */
class SieveProfile extends CalibrationProfile
{
    public const SATUAN = 'mm';

    /** Baris opening yang tercetak di kertas: dua blok × 15. */
    public const BARIS_KERTAS = 30;

    /** Baris frame yang tercetak di kertas (`2. Kalibrasi Diameter dan Ketinggian Rangka`). */
    public const BARIS_FRAME = 3;

    public const KODE_METODE = 'SIDIK-IK-CAL-0526_Rev.3';

    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0536_Rev.2';

    /** Offset kunci baris tabel frame — jauh di atas 100 opening. */
    public const OFFSET_FRAME = 1000;

    public const STANDARD_TERCETAK = [
        [
            'label' => 'Digital Microscope/Dino-Lite',
            'cocok' => ['Digital Microscope', 'Dino-Lite', 'AF3113', 'B1401068'],
        ],
        [
            'label' => 'Digital Caliper Tesa',
            'cocok' => ['Digital Caliper', 'Cal-IP67', 'LPI-0368'],
        ],
    ];

    /** Ketujuh unit thermohygro `DATABASE!B31:B61` (`INPUT DATA!F25` = 1..7). */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    private ?SieveCalculator $kalk = null;

    public function kode(): string
    {
        return 'sieve';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Sieve';
    }

    public function aliasNama(): array
    {
        return ['Sieve Mesh', 'Test Sieve', 'Ayakan', 'Ayakan Uji'];
    }

    public function kodeFormula(): string
    {
        return 'gum-sieve';
    }

    public function besaran(): string
    {
        return 'panjang';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function butuhBlokSieve(): bool
    {
        return true;
    }

    public function dalamLingkupAkreditasi(): bool
    {
        return true;
    }

    /**
     * Toleransinya datang dari Tabel MPE ASTM E11 per ukuran, bukan dari
     * kolom `toleransi` alat — dibiarkan `true`, form Alat meminta angka yang
     * tidak punya isi yang benar dan teknisi mengarangnya.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    public function faktorCakupanTetap(): ?float
    {
        // `PERHITUNGAN U95%!AC20/AC37/AC55` — angka 2 ketikan. Pertanyaan lab §2.
        return 2.0;
    }

    /**
     * Label baris sertifikat — persis `SERTIFIKAT!B20:B22` master, ejaan
     * "Wrap" ikut master. `titik_ke` 1/2/3 = warp/weft/kawat
     * ([SieveMentah::titikKe]); nominalnya tidak bisa membedakan warp dari weft.
     *
     * Tiga kelompok satu baris masing-masing, dan itu memang bentuknya: tiap
     * parameter punya budget, U95, dan vonisnya sendiri.
     */
    public function remarkTitikKe(int $titikKe, float $titikUkur): ?string
    {
        return match ($titikKe) {
            1 => "Wrap (x')",
            2 => "Weft (y')",
            3 => 'Wire Diameter (Ø)',
            default => null,
        };
    }

    /**
     * Kolom `Correction` master Sieve = opening terukur − nominal (`K20 = H20 − E20`),
     * kebalikan konvensi Standard − UUT alat lain. Lihat
     * [CalibrationProfile::tandaKoreksiSertifikat].
     */
    public function tandaKoreksiSertifikat(): int
    {
        return -1;
    }

    /** `SERTIFIKAT!H18` master: kolom opening terukur berjudul "Standard Indication". */
    public function judulKolomUut(): string
    {
        return 'Standard Indication';
    }

    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    public function desimalSertifikat(): ?int
    {
        // `SERTIFIKAT!H20:K22` berformat 0.0000.
        return 4;
    }

    public function desimalU95(): ?int
    {
        return 4;
    }

    /**
     * Kertasnya grid 15 × 2 blok yang BELUM pernah diadu ke foto formulir
     * asli — geometri template OCR masih grid hasil generator. Membuka jalur
     * kamera dengan geometri karangan memungut sel yang salah, dan yang balik
     * bukan error melainkan angka wajar di opening yang keliru.
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
     * Tiga grup (warp, weft, kawat) dari seluruh opening sesi.
     *
     * Deret opening DISATUKAN dari semua titik yang dioper, bukan dibaca dari
     * titik dengan `titik_ke` yang sama: jalur simpan mengoper ketiga deret di
     * tiap titik, jalur hitung ulang mengoper satu deret per `titik_ke` — dan
     * kalkulatornya butuh ketiganya sekaligus karena gerbang minimum opening
     * dan MPE berlaku tingkat sesi.
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        $konteksSesi = [];
        $opening = ['warp' => [], 'weft' => [], 'kawat' => []];
        $standarPerTitik = [];

        foreach ($titik as $t) {
            $k = $t['konteks'] ?? [];

            if ($konteksSesi === [] && isset($k['spesifikasi_alat'])) {
                $konteksSesi = $k;
            }

            foreach (SieveMentah::PARAMETER as $peran => $parameter) {
                foreach ((array) ($k[$peran] ?? []) as $no => $nilai) {
                    if (is_numeric($nilai)) {
                        $opening[$parameter][(int) $no] = (float) $nilai;
                    }
                }
            }

            $standarPerTitik[(int) $t['titik_ke']] = $t['standard'] ?? null;
        }

        $blok = SieveMentah::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);
        $nomorTitik = array_map(static fn (array $t): int => (int) $t['titik_ke'], $titik);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (int $n): array => [
                    'titik_ke' => $n,
                    'alasan' => 'Sesi Sieve belum punya blok `spesifikasi_alat.'.SieveMentah::KUNCI_SESI.'` — tipe sieve, '
                        .'nominal + satuan, dan standar yang dipakai lahir di situ.',
                ], array_values(array_unique($nomorTitik))),
            ];
        }

        $hasil = $this->kalk()->hitungSesi($opening, [
            'tipe' => $blok['tipe'],
            'nominal' => $blok['nominal'],
            'satuan' => $blok['satuan'],
            'standar_dipakai' => $blok['standar_dipakai'],
            'jumlah_opening_total' => $blok['jumlah_opening_total'],
            // `> 0`, bukan sekadar angka: jalur hitung ulang memakai
            // `MicrometerMentah::rataSuhuRuang()` yang memulangkan 0,0 untuk suhu
            // kosong — dan 0 °C di Lab Dimensi bukan pengukuran, dia sel kosong.
            'suhu_ruang_rata_c' => is_numeric($konteksSesi['suhu_ruang_rata'] ?? null)
                && (float) $konteksSesi['suhu_ruang_rata'] > 0.0
                ? (float) $konteksSesi['suhu_ruang_rata']
                : null,
            'tanggal_kalibrasi' => $this->tanggalKalibrasi($konteksSesi),
        ]);

        // Sesi yang tertahan TIDAK melahirkan satu pun baris hitungan — peringatan
        // sesi bisa dilewati admin, ketiadaan baris tidak. Alasan sama dengan
        // Height Gauge.
        if (! $hasil['boleh_terbit']) {
            $alasan = implode(' ', array_map(
                static fn (array $d): string => (string) $d['alasan'],
                $hasil['ditolak'],
            ));

            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (int $n): array => [
                    'titik_ke' => $n,
                    'alasan' => trim('Sesi Sieve tertahan, tidak ada grup yang diterbitkan. '.$alasan),
                ], [1, 2, 3]),
            ];
        }

        $kemampuan = $this->kemampuanSesi($equipment);
        $sekarang = Carbon::now();
        $hitungan = [];

        foreach ($hasil['parameter'] as $p) {
            $hitungan[] = [
                'standard_id' => ($standarPerTitik[$p['titik_ke']] ?? null)?->id
                    ?? (reset($standarPerTitik) ?: null)?->id,
                'titik_ke' => $p['titik_ke'],
                'titik_ukur' => $p['nominal_parameter_mm'],
                // Rata-rata TERKOREKSI — yang tercetak sebagai "Standard
                // indication" di sertifikat master (`SERTIFIKAT!H20`).
                'rata_rata' => $p['terkoreksi'],
                // `error` = terkoreksi − nominal = kolom "Correction" SERTIFIKAT
                // master (`K20 = H20 − E20`). Namanya di master "Correction",
                // artinya penyimpangan.
                'error' => $p['deviasi'],
                'koreksi' => -$p['deviasi'],
                'standar_deviasi' => $p['simpangan_baku'],
                'jumlah_pengulangan' => $p['jumlah'],
                'type_a' => $this->typeA($p['budget']),
                'type_b_components' => $this->jejakAudit($hasil, $p),
                'type_b' => $p['type_b'],
                'ketidakpastian_gabungan' => $p['ketidakpastian_gabungan'],
                'faktor_cakupan_k' => $p['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $p['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $p['u95_sertifikat'],
                'toleransi' => $p['vonis']['batas']['y_mm'] ?? null,
                'keputusan' => $p['keputusan'],
                'metode' => $kemampuan?->metode ?? self::KODE_METODE,
                'calculated_at' => $sekarang,
            ];
        }

        return ['hitungan' => $hitungan, 'belum_dihitung' => []];
    }

    /**
     * Catatan yang TIDAK menahan: pemeriksaan stdev yang tidak berlaku ("-"),
     * stdev kawat & batas +X yang tidak dinilai, dan vonis yang berbeda dari
     * versi master. Yang menahan ketiadaan baris di [hitungPerGrup].
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $blok = SieveMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null) {
            return [];
        }

        $opening = ['warp' => [], 'weft' => [], 'kawat' => []];
        $semua = SieveMentah::dari($sesi->rawMeasurements()->get());

        foreach (SieveMentah::PARAMETER as $peran => $parameter) {
            $opening[$parameter] = $semua[$peran] ?? [];
        }

        $hasil = $this->kalk()->hitungSesi($opening, [
            'tipe' => $blok['tipe'],
            'nominal' => $blok['nominal'],
            'satuan' => $blok['satuan'],
            'standar_dipakai' => $blok['standar_dipakai'],
            'jumlah_opening_total' => $blok['jumlah_opening_total'],
            'suhu_ruang_rata_c' => SieveMentah::rataSuhuRuang($sesi->suhu_awal, $sesi->suhu_akhir),
            'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi ? Carbon::parse($sesi->tanggal_kalibrasi) : Carbon::now(),
        ]);

        $temuan = [];

        foreach ($hasil['catatan'] as $i => $c) {
            $temuan[] = ['kode' => 'sieve_catatan_'.($i + 1), 'pesan' => $c];
        }

        foreach ($hasil['parameter'] as $p) {
            if ($p['vonis']['lulus'] !== $p['vonis']['lulus_master']) {
                $temuan[] = [
                    'kode' => 'sieve_vonis_beda_dari_master_'.$p['parameter'],
                    'pesan' => sprintf(
                        'Vonis %s %s, sementara rumus master (tanpa U & tanpa koreksi standar) akan mencetak %s. '
                        .'Yang terbit memakai guarded acceptance keputusan proyek.',
                        $p['parameter'], $p['keputusan'], $p['vonis']['lulus_master'] ? 'PASS' : 'NOT PASS',
                    ),
                ];
            }
        }

        return $temuan;
    }

    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'kode_metode' => self::KODE_METODE,
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => 'Calibration Worksheet - Sieve Mesh',
            'jumlah_pengulangan' => 1,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Isi tipe sieve, nominal + satuan, dan standar yang dipakai LEBIH DULU — ketiganya '
                .'menentukan batas MPE ASTM E11, jumlah minimum opening, dan budget. Nominal wajib persis ukuran '
                .'ASTM E11 (penanda inch ditulis dalam inch, mis. 0,75). Opening diisi berurutan dari nomor 1: '
                .'opening 1..6 WAJIB lengkap karena ketidakpastian pengulangan diambil dari keenamnya. Jumlah '
                .'opening minimal mengikuti tabel (Inspection 19 mm = 15). Koma atau titik desimal sama-sama diterima.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master Olah Data_Sieve Mesh.xlsm',
                'catatan' => 'Tiga budget tingkat-SESI (warp, weft, Ø kawat) dalam mm, enam komponen masing-masing, '
                    .'k = 2. Lantai CMC = yang lebih besar dari pita lampiran dan pita master. Sesi dengan nominal di '
                    .'luar Tabel MPE, opening di bawah minimum, opening 1..6 tidak lengkap, standar kedaluwarsa, '
                    .'atau tanpa pita CMC TIDAK diterbitkan.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianOpening(),
                $this->bagianFrame(),
                $this->bagianPenutup(),
            ],
        ];

        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($bentuk, $equipment),
            $equipment,
        );
    }

    /** @param  array<string, mixed>  $konteks */
    private function tanggalKalibrasi(array $konteks): \DateTimeInterface
    {
        $tanggal = $konteks['tanggal_kalibrasi'] ?? null;

        return $tanggal ? Carbon::parse($tanggal) : Carbon::now();
    }

    /** @param  list<array<string, mixed>>  $budget */
    private function typeA(array $budget): float
    {
        foreach ($budget as $b) {
            if ($b['distribusi'] === 't-student') {
                return (float) $b['u'] * (float) $b['ci'];
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @param  array<string, mixed>  $p
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $hasil, array $p): array
    {
        $baris = array_map(fn (array $b): array => $this->barisAudit($b), $p['budget']);

        $baris[] = $this->barisPerbandinganCmc(
            (float) $p['ketidakpastian_diperluas'],
            $hasil['lantai_cmc']['u95_mm'] ?? null,
            self::SATUAN,
        );

        $batas = $p['vonis']['batas'];

        $baris[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                '%s · %d opening · rata-rata mentah %s mm · koreksi standar %s mm (titik %s mm) · terkoreksi %s mm · '
                .'deviasi %s mm · stdev %s mm · stdev opening 1..6 %s mm · U95 %s mm · batas %s · vonis %s '
                .'(versi master tanpa U: %s) · ukuran MPE %s mm, minimum opening %s',
                $p['parameter'], $p['jumlah'], $p['rata_rata'], $p['koreksi_standar'],
                $p['titik_koreksi']['nilai_standar_mm'], $p['terkoreksi'], $p['deviasi'], $p['simpangan_baku'],
                $p['simpangan_baku_pengulangan'], $p['u95_sertifikat'],
                isset($batas['y_mm']) ? '±'.$batas['y_mm'].' mm' : $batas['min_mm'].'–'.$batas['max_mm'].' mm',
                $p['keputusan'], $p['vonis']['lulus_master'] ? 'PASS' : 'NOT PASS',
                $hasil['nominal_mm'], $hasil['minimum_opening'] ?? '-',
            ),
            'distribusi' => 'jejak',
            'nilai' => null,
            'u_baku' => 0.0,
            'ci' => 0.0,
            'vi' => 0.0,
        ];

        return $baris;
    }

    /** @return array<string, mixed> */
    private function bagianIdentitas(): array
    {
        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Equipment Identity and Customer Data',
            'field' => [
                $this->field('equipment_id', 'Pilih Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Name', 'teks', sumber: 'otomatis'),
                $this->field('alat_model', 'Type/Model', 'teks'),
                $this->field('alat_serial_number', 'Serial Number', 'teks'),
                $this->field('alat_merk', 'Merk/Manufacture', 'teks'),
                $this->field('spesifikasi_alat.sieve.tipe', 'Tipe Sieve', 'pilihan', pilihan: [
                    ['nilai' => 'compliance', 'label' => 'Compliance'],
                    ['nilai' => 'inspection', 'label' => 'Inspection'],
                    ['nilai' => 'calibration', 'label' => 'Calibration'],
                ]),
                // Satuan sebelum nominal — dia yang menentukan kolom Tabel MPE
                // yang dicocokkan (inch ke kolom inch).
                $this->field('spesifikasi_alat.sieve.satuan', 'Satuan Nominal', 'pilihan', pilihan: [
                    ['nilai' => 'mm', 'label' => 'mm'],
                    ['nilai' => 'inch', 'label' => 'inch'],
                    ['nilai' => 'µm', 'label' => 'µm'],
                ]),
                $this->field('spesifikasi_alat.sieve.nominal', 'Range/Nominal Sieve', 'angka'),
                $this->field('spesifikasi_alat.sieve.jumlah_opening_total', 'Jumlah total opening (untuk ukuran "all")', 'angka'),
                $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                $this->field('suhu_awal', 'Suhu — First', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Suhu — End', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Kelembapan — First', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Kelembapan — End', 'angka', satuan: '%RH'),
                $this->field('lokasi', 'Lokasi Kalibrasi', 'pilihan', pilihan: [
                    ['nilai' => 'lab', 'label' => 'Inlab'],
                    ['nilai' => 'onsite', 'label' => 'Insitu'],
                ]),
                $this->field('room_id', 'Ruangan (Inlab)', 'pilihan', sumber: 'master_ruangan', tampilKalau: self::TAMPIL_KALAU_INLAB),
                $this->field('lokasi_nama', 'Nama Tempat (Insitu)', 'teks', tampilKalau: self::TAMPIL_KALAU_INSITU),
                $this->field('thermohygro_standard_id', 'Thermohygro Used', 'pilihan', sumber: 'master_thermohygro'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianPemilik(): array
    {
        return [
            'kode' => 'pemilik',
            'halaman' => 1,
            'judul' => 'Owner',
            'field' => [
                $this->field('pemilik_nama', 'Name', 'teks'),
                $this->field('pemilik_alamat', 'Address', 'teks_panjang'),
                $this->field('nomor_order', 'Order Number', 'teks'),
            ],
        ];
    }

    /**
     * Standar dipilih SEBELUM mengukur: dia yang menentukan pembagi sertifikat
     * (2,02 / 2), koefisien muai (1× / 2× Δα), resolusi, tabel koreksi, dan
     * lantai CMC. Centang "Usage Check" kertas tidak cukup — dua centang yang
     * saling meniadakan tidak bisa divalidasi, jadi yang dihitung dari field
     * pilihan `standar_dipakai`.
     *
     * @return array<string, mixed>
     */
    private function bagianStandard(): array
    {
        return [
            'kode' => 'usage_check',
            'halaman' => 1,
            'judul' => 'Standard',
            'baris' => self::STANDARD_TERCETAK,
            'field' => [
                $this->field('spesifikasi_alat.sieve.standar_dipakai', 'Standar yang dipakai mengukur', 'pilihan', pilihan: [
                    ['nilai' => 'mikroskop', 'label' => 'Digital Microscope/Dino-Lite'],
                    ['nilai' => 'caliper', 'label' => 'Digital Caliper Tesa'],
                ]),
                $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
            ],
        ];
    }

    /**
     * `1. Kalibrasi Dimensi Lubang dan Diameter Kawat` — satu baris = satu
     * opening, tiga kolom. Kertasnya dua blok × 15 baris (30 opening);
     * `titik_bisa_diubah` supaya teknisi bisa menambah sampai 100 (minimum
     * Calibration ukuran kecil memang lebih dari 30).
     *
     * `titik_ukur` = nomor opening. Nomor itu yang jadi `sensor_ke`, dan
     * opening 1..6 sumber komponen pengulangan.
     *
     * @return array<string, mixed>
     */
    private function bagianOpening(): array
    {
        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => '1. Kalibrasi Dimensi Lubang dan Diameter Kawat',
            'field' => [],
            'tabel' => [[
                'tahap' => 'sesudah_adjustment',
                'grup' => 'opening',
                'judul' => 'Measurement Result',
                'satuan' => null,
                'judul_nilai' => 'No',
                'judul_pengulangan' => 'Opening',
                'titik_bisa_diubah' => true,
                'simpan_ke' => 'spesifikasi_alat.sieve.opening',
                // Nomor opening berurutan 1..30 seperti master (`INPUT DATA`
                // kolom No.), tapi kertas FM-0536 mencetak dua blok berdampingan
                // yang masing-masing bernomor 1..15. Label menyebut bloknya
                // supaya baris kertas "kanan 1" tidak disalin ke opening 1.
                'baris' => array_map(static fn (int $n): array => [
                    'nomor' => $n,
                    'titik_ukur' => (float) $n,
                    'label' => $n <= self::BARIS_KERTAS / 2
                        ? sprintf('%d (kiri %d)', $n, $n)
                        : sprintf('%d (kanan %d)', $n, $n - self::BARIS_KERTAS / 2),
                    'satuan' => null,
                ], range(1, self::BARIS_KERTAS)),
                'kolom' => [
                    ['kode' => 'warp', 'label' => "Wrap (x')", 'tipe' => 'angka', 'satuan' => null],
                    ['kode' => 'weft', 'label' => "Weft (y')", 'tipe' => 'angka', 'satuan' => null],
                    ['kode' => 'kawat', 'label' => 'Ø Kawat', 'tipe' => 'angka', 'satuan' => null],
                ],
                'pengulangan' => [1],
            ]],
        ];
    }

    /**
     * `2. Kalibrasi Diameter dan Ketinggian Rangka` — tiga baris, SELALU mm.
     * Dicatat saja: master cuma memungut satu angka masing-masing
     * (`INPUT DATA!F17/F18`) dan tidak menghitung maupun mencetaknya.
     *
     * @return array<string, mixed>
     */
    private function bagianFrame(): array
    {
        return [
            'kode' => 'frame',
            'halaman' => 1,
            'judul' => '2. Kalibrasi Diameter dan Ketinggian Rangka',
            'field' => [],
            'tabel' => [[
                'tahap' => 'sesudah_adjustment',
                'grup' => 'frame',
                'judul' => 'Measurement Result',
                'satuan' => self::SATUAN,
                'judul_nilai' => 'No',
                'judul_pengulangan' => 'Rangka',
                'titik_bisa_diubah' => false,
                'offset_kunci' => self::OFFSET_FRAME,
                'simpan_ke' => 'spesifikasi_alat.sieve.frame',
                'baris' => array_map(static fn (int $n): array => [
                    'nomor' => $n,
                    'titik_ukur' => null,
                    'label' => (string) $n,
                    'satuan' => self::SATUAN,
                ], range(1, self::BARIS_FRAME)),
                'kolom' => [
                    ['kode' => 'diameter', 'label' => 'Ø Rangka (mm)', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                    ['kode' => 'tinggi', 'label' => 'Ketinggian Rangka (mm)', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                ],
                'pengulangan' => [1],
            ]],
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
                $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Checked by', 'teks', sumber: 'otomatis'),
            ],
        ];
    }

    private function kalk(): SieveCalculator
    {
        return $this->kalk ??= new SieveCalculator;
    }
}
