<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\JangkaSorongCalculator;
use App\Services\Calibration\TabelStandarJangkaSorong;
use App\Support\JangkaSorongMentah;
use Carbon\Carbon;

/**
 * Lembar & hitungan **Jangka Sorong (Vernier Caliper)** — lampiran akreditasi
 * LK-285-IDN no. 35, kelompok Panjang.
 *
 * Satu workbook master (`Master Olah Data Caliper 2026 (std caliper
 * checker+gb).xlsm`, ber-password), dibuktikan lebih dulu di Python
 * lalu dijaga `JangkaSorongMasterTest` — tiap komponen KETIGA budget dan tiap
 * titik Outside & Inside, toleransi 5·10⁻⁶.
 *
 * ## Tiga tabel titik, tiga budget
 *
 * Satu sesi memuat Outside (sebelas baris: titik nol + sepuluh nominal Caliper
 * Checker, sepuluh pembacaan X1..X5'), Inside (sebelas baris, lima pembacaan),
 * dan Depth (lima tumpukan balok ukur, lima pembacaan). Masing-masing punya
 * budget tingkat-sesinya sendiri, dan tiap baris hitungan membawa U95 grupnya.
 *
 * Rentang `titik_ke` dipisah per tabel — Outside 1..11, Inside 101..111, Depth
 * 201..205 — lihat [JangkaSorongMentah]. Jalur hitung ulang mengelompokkan per
 * `titik_ke`, dan dua tabel yang berbagi satu `titik_ke` melebur jadi rata-rata
 * dua sisi rahang yang tidak berarti apa-apa.
 *
 * ## Blok tingkat-sesi
 *
 * Kedua Evaluation (sumber repeatability Outside & Inside), kesejajaran muka
 * ukur, kapasitas, resolusi, satuan, dan kerataan muka ukur tinggal di
 * `spesifikasi_alat.jangka_sorong`, bukan sebagai `titik_ke`.
 *
 * ## Yang menyimpang dari kertas, dan sengaja
 *
 *  - Kertas `SIDIK-FM-CAL-0527_Rev.2` cuma punya SATU baris Evaluasi; master
 *    punya dua (Outside & Inside) dan budget Inside membaca yang kedua. Lembar
 *    ini meminta keduanya — pertanyaan lab §9.
 *  - Kertas tidak punya kotak Kerataan maupun Kesejajaran; master punya.
 *  - Kotak Inlab/Insitu dan dropdown Thermohygro, seperti semua lembar.
 */
class JangkaSorongProfile extends CalibrationProfile
{
    public const SATUAN = 'mm';

    /** Pembacaan per titik mengikuti KERTAS: Outside 5 pasang, Inside & Depth 5. */
    public const PENGULANGAN = ['outside' => 10, 'inside' => 5, 'depth' => 5];

    public const PRA_EVALUASI = 10;

    public const KODE_METODE = 'SIDIK-IK-CAL-0520_Rev.2';

    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0527_Rev.2';

    public const STANDARD_TERCETAK = [
        [
            'label' => 'Caliper Checker/Metrology/CMG-9060C',
            'cocok' => ['Caliper Checker', 'CMG-9060C', '800035'],
        ],
        [
            'label' => 'Gauge Block/Metrology/GB-9122-0',
            'cocok' => ['Gauge Block', 'GB-9122-0', '160006'],
        ],
    ];

    /** Ketujuh unit thermohygro yang tercetak di `DATABASE!B31:B61`. */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    /** `Satuan_Caliper` (`DATABASE!R23:T24`) — cuma mm & inch. */
    public const SATUAN_PILIHAN = JangkaSorongMentah::FAKTOR_KE_MM;

    private ?JangkaSorongCalculator $kalk = null;

    private ?TabelStandarJangkaSorong $tabel = null;

    public function kode(): string
    {
        return 'jangka_sorong';
    }

    /** PERSIS nama lampiran akreditasi no. 35. */
    public function namaAlatKemampuan(): string
    {
        return 'Vernier Caliper';
    }

    /**
     * Tanpa alias telanjang `Caliper`: nama itu memuat `Caliper Checker` —
     * standar lab sendiri — dan alat standar yang mendarat di lembar jangka
     * sorong dihitung terhadap dirinya sendiri tanpa satu pun error.
     */
    public function aliasNama(): array
    {
        return ['Jangka Sorong', 'Jangka Sorong Digital', 'Digital Caliper', 'Vernier Kaliper'];
    }

    public function kodeFormula(): string
    {
        return 'gum-jangka-sorong';
    }

    public function besaran(): string
    {
        return 'panjang';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function butuhBlokJangkaSorong(): bool
    {
        return true;
    }

    /**
     * Tidak divonis PASS/FAIL — master berhenti di `Correction` + U95 per tabel,
     * dan kesejajaran pun dicetak tanpa batas kelulusan.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * `didukung = false`: geometri OCR belum pernah diadu ke foto formulir asli.
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

    /** Ketidakpastian lahir per SESI per tabel — jalur per titik tidak ada. */
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
     * Titik ketiga tabel + tiga budget sesi.
     *
     * Tiap grup punya gerbangnya sendiri: Inside yang Evaluation-nya kosong
     * tidak menahan Outside yang lengkap. Budget yang tidak utuh TIDAK
     * melahirkan satu pun baris hitungan — baris ber-U95 nol tercetak sebagai
     * klaim pengukuran sempurna, dan peringatan sesi bisa dilewati admin.
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        // Disapu, bukan `$titik[0]` — urutan `groupBy` jalur hitung ulang tidak
        // dijamin. Lihat [HeightGaugeProfile::hitungPerGrup].
        $konteksSesi = [];

        foreach ($titik as $t) {
            if (isset($t['konteks']['spesifikasi_alat'])) {
                $konteksSesi = $t['konteks'];

                break;
            }
        }

        $blok = JangkaSorongMentah::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi Jangka Sorong belum punya blok tingkat-sesi di `spesifikasi_alat.'
                        .JangkaSorongMentah::KUNCI_SESI.'` — kedua Evaluation, kapasitas, dan resolusi lahir di situ.',
                ], $titik),
            ];
        }

        $masukan = ['outside' => [], 'inside' => [], 'depth' => []];
        $standarPerTitik = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            $k = $t['konteks'] ?? [];
            $grup = $k[JangkaSorongMentah::KONTEKS_GRUP] ?? JangkaSorongMentah::grupDariTitik((int) $t['titik_ke']);
            $nominal = array_map('floatval', $k[JangkaSorongMentah::KONTEKS_NOMINAL] ?? []);
            $pembacaan = array_map('floatval', $k[JangkaSorongMentah::KONTEKS_PEMBACAAN] ?? []);

            if (! in_array($grup, JangkaSorongMentah::GRUP, true) || ($nominal === [] && $pembacaan === [])) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d nggak punya baris ber-peran `js_<outside|inside|depth>_*`. Lembar Jangka Sorong '
                        .'nyimpen slot nominal dan deret pembacaan terpisah per tabel — deret datar nggak bisa dipakai.',
                        $t['titik_ke'],
                    ),
                ];

                continue;
            }

            $masukan[$grup][] = [
                'titik_ke' => (int) $t['titik_ke'],
                'nominal' => $nominal,
                'pembacaan' => $pembacaan,
            ];
            $standarPerTitik[(int) $t['titik_ke']] = $t['standard'] ?? null;
        }

        $hasil = $this->kalk()->hitungSesi($masukan, [
            'kapasitas_mm' => $blok['kapasitas_mm'],
            'resolusi_mm' => $blok['resolusi_mm'],
            'tanggal_kalibrasi' => $this->tanggalKalibrasi($konteksSesi),
            'pra_evaluasi_outside' => $blok['pra_evaluasi_outside'],
            'pra_evaluasi_inside' => $blok['pra_evaluasi_inside'],
            'suhu_ruang_rata_c' => (float) ($konteksSesi['suhu_ruang_rata'] ?? 0.0),
            'kesejajaran' => $blok['kesejajaran'],
        ]);

        $kemampuan = $this->kemampuanSesi($equipment);
        $sekarang = Carbon::now();
        $hitungan = [];

        foreach ($hasil['ditolak'] as $d) {
            if ((int) $d['titik_ke'] !== 0) {
                $belumDihitung[] = $d;
            }
        }

        foreach ($hasil['grup'] as $grup => $g) {
            if (! $g['boleh_terbit']) {
                foreach ($g['titik'] as $h) {
                    $belumDihitung[] = [
                        'titik_ke' => (int) $h['titik_ke'],
                        'alasan' => trim(sprintf(
                            'Budget %s tidak utuh, jadi titik ini tidak diterbitkan. %s',
                            ucfirst($grup),
                            implode(' ', $g['alasan_tahan']),
                        )),
                    ];
                }

                continue;
            }

            foreach ($g['titik'] as $h) {
                $hitungan[] = [
                    // Null-safe: standar yang di-soft-delete memulangkan null.
                    'standard_id' => ($standarPerTitik[$h['titik_ke']] ?? null)?->id,
                    'titik_ke' => $h['titik_ke'],
                    'titik_ukur' => $h['total_nominal'],
                    'rata_rata' => $h['rata_rata'],
                    'error' => -$h['koreksi'],
                    'koreksi' => $h['koreksi'],
                    'standar_deviasi' => $h['simpangan_baku'],
                    'jumlah_pengulangan' => $h['jumlah_pengulangan'],
                    'type_a' => $this->typeA($g['budget']),
                    'type_b_components' => $this->jejakAudit($grup, $g, $h, $hasil),
                    'type_b' => $g['type_b'],
                    'ketidakpastian_gabungan' => $g['ketidakpastian_gabungan'],
                    'faktor_cakupan_k' => $g['faktor_cakupan_k'],
                    'derajat_kebebasan_efektif' => $g['derajat_kebebasan_efektif'],
                    'ketidakpastian_diperluas' => $g['u95_sertifikat'],
                    'toleransi' => null,
                    'keputusan' => null,
                    'metode' => $kemampuan?->metode ?? self::KODE_METODE,
                    'calculated_at' => $sekarang,
                ];
            }
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Peringatan sesi — yang TIDAK menahan (penahannya ketiadaan baris hitungan).
     *
     * @return list<array{kode: string, pesan: string}>
     */
    /**
     * Lampiran LK-285-IDN no. 35 membatasi Vernier Caliper ke **0-300 mm**. Sesi
     * di atas itu terbit tanpa klaim akreditasi — dibekukan ke snapshot oleh
     * `CertificateSnapshotBuilder`, sama seperti Height Gauge untuk seluruh
     * profilnya. Kapasitas kosong dianggap di dalam: sesi itu toh tidak
     * menerbitkan hitungan (lihat `JangkaSorongCalculator`).
     */
    public function dalamLingkupAkreditasiSesi(CalibrationSession $sesi): bool
    {
        $blok = JangkaSorongMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null || $blok['kapasitas_mm'] <= 0.0) {
            return true;
        }

        return $this->tabel()->pitaCmc($blok['kapasitas_mm']) !== null;
    }

    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $blok = JangkaSorongMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null) {
            return [];
        }

        $temuan = [];

        if ($blok['kapasitas_mm'] <= 0.0) {
            $temuan[] = [
                'kode' => 'jangka_sorong_kapasitas_kosong',
                'pesan' => 'Kapasitas alat belum diisi, jadi sesi ini tidak menerbitkan U95 — pita CMC dan status '
                    .'akreditasi ditentukan kapasitas.',
            ];
        } elseif ($this->tabel()->pitaCmc($blok['kapasitas_mm']) === null) {
            $temuan[] = [
                'kode' => 'jangka_sorong_diluar_akreditasi',
                'pesan' => 'Kapasitas alat di atas 300 mm — di luar lingkup akreditasi Vernier Caliper (LK-285-IDN '
                    .'no. 35, 0-300 mm). U95 terbit TANPA lantai CMC, dan sertifikatnya sengaja terbit tanpa logo '
                    .'maupun nomor akreditasi. Pastikan pelanggan tahu bedanya. Lihat '
                    .'docs/pertanyaan-lab-jangka-sorong.md §2.',
            ];
        }

        foreach (['outside' => 'Outside', 'inside' => 'Inside'] as $kunci => $label) {
            $ev = $blok['pra_evaluasi_'.$kunci];

            if (count($ev) >= 2 && max($ev) === min($ev)) {
                $temuan[] = [
                    'kode' => "jangka_sorong_evaluasi_{$kunci}_seragam",
                    'pesan' => "Kesepuluh pembacaan Evaluation {$label} identik, jadi repeatability {$label} nol. "
                        .'U95 tetap dijaga lantai CMC dan komponen resolusi — pastikan memang tidak ada sebaran.',
                ];
            }
        }

        if ($blok['kerataan_muka_ukur'] === 'buruk') {
            $temuan[] = [
                'kode' => 'jangka_sorong_kerataan_buruk',
                'pesan' => 'Kerataan muka ukur dicatat BURUK. Ini hasil UKUR, bukan cacat data — dicetak apa adanya.',
            ];
        }

        return $temuan;
    }

    /**
     * Judul kelompok sertifikat — persis tiga judul tabel `SERTIFIKAT` master
     * (`D22`, `D38`, `D54`). Dipilih dari rentang `titik_ke`, bukan nominal:
     * Outside dan Inside memakai nominal Caliper Checker yang sama.
     *
     * Remark juga kunci pengelompokan, jadi tiap tabel mencetak `Uncertainty
     * U95% = ±` dan `k`-nya sendiri — tiga budget yang berbeda (Outside/Inside
     * 10 komponen, Depth 11) tidak boleh diwakili satu baris U95.
     */
    public function remarkTitikKe(int $titikKe, float $titikUkur): ?string
    {
        return match (true) {
            $titikKe > JangkaSorongMentah::OFFSET_TITIK['depth'] => '<50 mm Kedalaman (Depth Measurement)',
            $titikKe > JangkaSorongMentah::OFFSET_TITIK['inside'] => 'Pengukuran Dalam (Inside Measurement)',
            default => 'Pengukuran Luar (Outside Measurement)',
        };
    }

    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    public function desimalSertifikat(): ?int
    {
        // Lima desimal mm — nilai terkoreksi Caliper Checker (25,00080) dan
        // balok ukur (9,99997) memang sedetail itu. Master memformat 0,00 dan
        // mencetak koreksi yang hilang di bawah resolusi cetaknya.
        return 5;
    }

    public function desimalU95(): ?int
    {
        return 5;
    }

    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'kode_metode' => self::KODE_METODE,
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => 'Calibration Work Sheet - Jangka Sorong',
            'jumlah_pengulangan' => self::PENGULANGAN['outside'],
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Isi SATUAN alat lebih dulu (mm/inch) — dia yang mengubah pembacaan dan blok '
                .'Evaluation ke mm. Nominal ketiga tabel SUDAH DIPATOK dari daftar Caliper Checker dan balok ukur '
                .'terkalibrasi; baris yang tidak dipakai biarkan kosong. Kedua blok Evaluation WAJIB diisi: dari '
                .'situ repeatability Outside dan Inside lahir. Koma maupun titik diterima sebagai pemisah desimal.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master Olah Data Caliper 2026 (std caliper checker+gb).xlsm',
                'catatan' => 'Tiga budget tingkat-SESI dalam mm — Outside & Inside 10 komponen, Depth 11 — dengan '
                    .'lantai CMC 0,015 mm. Kapasitas di luar 0-300 mm, resolusi kosong, atau Evaluation kurang '
                    .'dari dua pembacaan TIDAK diterbitkan.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                // Urutan kertas FM-0527: Pengukuran Luar → baris Evaluasi tepat
                // di bawahnya → Pengukuran Dalam → Kedalaman. Kesejajaran muka
                // ukur tidak punya kotak di kertas; ditaruh sesudah semua tabel
                // pengukuran supaya tidak menyela alur yang tercetak.
                $this->bagianTitik('outside'),
                $this->bagianEvaluasi(),
                $this->bagianTitik('inside'),
                $this->bagianTitik('depth'),
                $this->bagianKesejajaran(),
                $this->bagianPenutup(),
            ],
        ];

        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($bentuk, $equipment),
            $equipment,
        );
    }

    /**
     * Baris pra-cetak satu tabel, dalam URUTAN yang juga dipakai
     * `CalibrationController::susunBlokJangkaSorong()` untuk memetakan balik
     * posisi baris ke nominal. Satu sumber untuk keduanya — dua daftar yang
     * harus ingat diperbarui bareng selalu berakhir dengan pembacaan yang
     * mendarat di nominal yang salah.
     *
     * @return list<array{label: string, nominal: list<float>}>
     */
    public function barisPraCetak(string $grup): array
    {
        $tabel = $this->tabel();
        $angka = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');

        if ($grup === 'depth') {
            return array_map(static fn (array $tumpukan): array => [
                'label' => count($tumpukan) > 1
                    ? implode(' + ', array_map($angka, $tumpukan)).' = '.$angka(array_sum($tumpukan))
                    : $angka($tumpukan[0]),
                'nominal' => $tumpukan,
            ], $tabel->tumpukanDepth());
        }

        $daftar = $grup === 'outside' ? $tabel->nominalOutside() : $tabel->nominalInside();

        // Titik NOL (rahang tertutup) ada di master (`INPUT DATA!C42`/`C77`)
        // dan tidak ada di tabel Caliper Checker — dia baris pertama.
        return array_map(
            static fn (float $n): array => ['label' => $angka($n), 'nominal' => [$n]],
            [0.0, ...$daftar],
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
        foreach ($budget as $k) {
            if (($k['distribusi'] ?? null) === 't-student') {
                return (float) $k['u'] * (float) $k['ci'];
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $g
     * @param  array<string, mixed>  $h
     * @param  array<string, mixed>  $hasil
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(string $grup, array $g, array $h, array $hasil): array
    {
        $baris = array_map(fn (array $k): array => $this->barisAudit($k), $g['budget']);

        // WAJIB ada: `CalibrationValidator::cmcTitik()` mencari `sumber` ini.
        $baris[] = $this->barisPerbandinganCmc(
            (float) $g['ketidakpastian_diperluas'],
            isset($hasil['pita_cmc']['u95_mm']) ? (float) $hasil['pita_cmc']['u95_mm'] : null,
            self::SATUAN,
        );

        $baris[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'Tabel %s · nominal %s mm · total terkoreksi %s mm · rata-rata pembacaan %s mm · koreksi %s mm · '
                .'U95 %s mm%s',
                ucfirst($grup),
                implode(' + ', array_map(static fn ($n): string => (string) $n, $h['nominal'])) ?: '-',
                $h['total_nominal'], $h['rata_rata'], $h['koreksi'], $g['u95_sertifikat'],
                $hasil['kesejajaran'] === [] ? '' : ' · kesejajaran '.implode(', ', array_map(
                    static fn (array $s): string => sprintf('%s %s mm', $s['posisi'], $s['koreksi']),
                    $hasil['kesejajaran'],
                )),
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
        $kunci = 'spesifikasi_alat.'.JangkaSorongMentah::KUNCI_SESI;

        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Identitas Alat',
            'field' => [
                $this->field('equipment_id', 'Pilih Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Nama Alat', 'teks', sumber: 'otomatis'),
                $this->field('alat_merk', 'Merk', 'teks'),
                $this->field('alat_model', 'Type', 'teks'),
                $this->field('alat_serial_number', 'No. Seri', 'teks'),
                $this->field("{$kunci}.satuan", 'Satuan Alat', 'pilihan', pilihan: [
                    ['nilai' => 'mm', 'label' => 'mm'],
                    ['nilai' => 'inch', 'label' => 'inch'],
                ]),
                $this->field('spesifikasi_alat.rentang_ukur', 'Rentang Ukur', 'teks'),
                $this->field("{$kunci}.kapasitas_mm", 'Kapasitas Max.', 'angka', satuan: self::SATUAN),
                $this->field("{$kunci}.resolusi_mm", 'Resolusi Alat', 'angka', satuan: self::SATUAN),
                $this->field('tanggal_terima', 'Tgl. Diterima', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Tgl. Kalibrasi', 'tanggal'),
                $this->field('suhu_awal', 'Suhu Ruangan — awal', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Suhu Ruangan — akhir', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Kelembapan — awal', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Kelembapan — akhir', 'angka', satuan: '%RH'),
                // SATU pilihan, bukan dua centang — lihat [JangkaSorongMentah::kerataan].
                $this->field("{$kunci}.kerataan_muka_ukur", 'Kerataan Muka Ukur', 'pilihan', pilihan: [
                    ['nilai' => 'baik', 'label' => 'Baik'],
                    ['nilai' => 'buruk', 'label' => 'Buruk'],
                ]),
                $this->field('lokasi', 'Lokasi Kalibrasi', 'pilihan', pilihan: [
                    ['nilai' => 'lab', 'label' => 'Inlab'],
                    ['nilai' => 'onsite', 'label' => 'Insitu'],
                ]),
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
     * Kesejajaran muka ukur — tiga posisi, nominal & pembacaan. Tingkat SESI,
     * tidak masuk budget, tanpa batas kelulusan (`PERHITUNGAN!H126:H128`).
     *
     * @return array<string, mixed>
     */
    private function bagianKesejajaran(): array
    {
        return [
            'kode' => 'kesejajaran',
            'halaman' => 1,
            'judul' => 'Pengukuran Kesejajaran Muka Ukur (Outside)',
            'field' => [],
            'tabel' => [[
                'tahap' => 'sesudah_adjustment',
                'grup' => 'kesejajaran',
                'judul' => 'Kesejajaran Muka Ukur',
                'satuan' => self::SATUAN,
                'judul_nilai' => 'Posisi',
                'judul_pengulangan' => 'Nilai',
                'titik_bisa_diubah' => false,
                // Offset tiap tabel dijaga jauh di atas nominal terbesar (600
                // mm) dan beda satu sama lain: tabel ber-`tahap` sama yang kunci
                // barisnya bertabrakan menaruh angka satu kotak di kotak lain,
                // tanpa error. Sudah nyata di Timbangan.
                'offset_kunci' => 5000,
                'simpan_ke' => 'spesifikasi_alat.'.JangkaSorongMentah::KUNCI_SESI.'.kesejajaran',
                'baris' => array_map(static fn (int $i, string $p): array => [
                    'nomor' => $i + 1,
                    'titik_ukur' => (float) ($i + 1),
                    'label' => $p,
                    'satuan' => self::SATUAN,
                ], [0, 1, 2], ['Atas', 'Tengah', 'Bawah']),
                'kolom' => [
                    ['kode' => 'nominal', 'label' => 'Nominal', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                    ['kode' => 'pembacaan', 'label' => 'Pembacaan', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                ],
                'pengulangan' => [1],
            ]],
        ];
    }

    /**
     * Kedua blok Evaluation — sepuluh pembacaan berulang di nominal kapasitas,
     * sumber repeatability Outside (`PERHITUNGAN!N25`) dan Inside (`N31`).
     *
     * @return array<string, mixed>
     */
    private function bagianEvaluasi(): array
    {
        $tabel = [];

        foreach (['outside' => 3000, 'inside' => 4000] as $grup => $offset) {
            $label = ucfirst($grup);
            $tabel[] = [
                'tahap' => 'sesudah_adjustment',
                'grup' => 'pra_pembacaan_'.$grup,
                'judul' => "Evaluation {$label} (pembacaan berulang di kapasitas)",
                'satuan' => self::SATUAN,
                'judul_nilai' => 'Evaluation',
                'judul_pengulangan' => 'Pembacaan',
                'titik_bisa_diubah' => false,
                'offset_kunci' => $offset,
                'simpan_ke' => 'spesifikasi_alat.'.JangkaSorongMentah::KUNCI_SESI.'.pra_evaluasi_'.$grup,
                'baris' => [[
                    'nomor' => 1,
                    'titik_ukur' => null,
                    'label' => "Evaluation {$label}",
                    'satuan' => self::SATUAN,
                ]],
                'kolom' => [
                    ['kode' => 'pembacaan', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                ],
                'pengulangan' => range(1, self::PRA_EVALUASI),
            ];
        }

        return [
            'kode' => 'evaluasi',
            'halaman' => 1,
            'judul' => 'Evaluation',
            'field' => [],
            'tabel' => $tabel,
        ];
    }

    /**
     * Satu tabel titik (Outside / Inside / Depth).
     *
     * `simpan_ke: measurements[].js_<grup>` — tabel ber-kunci-bernama. HP
     * menggabung ketiga tabel per POSISI baris ke satu `measurements[i]`
     * (`js_outside`, `js_inside`, `js_depth`), dan server memecahnya lagi ke tiga
     * rentang `titik_ke`. Nominalnya dipatok; teknisi cuma mengisi pembacaan.
     *
     * @return array<string, mixed>
     */
    private function bagianTitik(string $grup): array
    {
        $judul = [
            'outside' => 'Outside Measurement',
            'inside' => 'Inside Measurement',
            'depth' => 'Depth Measurement (Kedalaman < 50 mm)',
        ][$grup];

        $arah = $grup === 'outside'
            ? array_map(static fn (int $i): array => [
                'ke' => $i + 1,
                'label' => 'X'.(intdiv($i, 2) + 1).($i % 2 === 1 ? "'" : ''),
            ], range(0, 9))
            : array_map(static fn (int $i): array => ['ke' => $i, 'label' => 'X'.$i], range(1, 5));

        $offset = ['outside' => 0, 'inside' => 1000, 'depth' => 2000][$grup];

        return [
            'kode' => 'hasil_'.$grup,
            'halaman' => 1,
            'judul' => $judul,
            'field' => [],
            'tabel' => [[
                'tahap' => 'sesudah_adjustment',
                'grup' => $grup,
                'judul' => $judul,
                'satuan' => self::SATUAN,
                'judul_nilai' => $grup === 'depth' ? 'Nominal Gauge Block' : 'Nominal Caliper Checker',
                'judul_pengulangan' => 'Pembacaan Alat',
                'titik_bisa_diubah' => false,
                'offset_kunci' => $offset,
                'simpan_ke' => 'measurements[].js_'.$grup,
                'pengulangan_arah' => $arah,
                'baris' => array_map(
                    static fn (int $i, array $b): array => [
                        'nomor' => $i + 1,
                        'titik_ukur' => array_sum($b['nominal']),
                        'label' => $b['label'],
                        'satuan' => self::SATUAN,
                    ],
                    array_keys($this->barisPraCetak($grup)),
                    $this->barisPraCetak($grup),
                ),
                'kolom' => [
                    ['kode' => 'pembacaan', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                ],
                'pengulangan' => range(1, self::PENGULANGAN[$grup]),
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
                $this->field('teknisi.nama', 'Dikalibrasi Oleh', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Diperiksa Oleh', 'teks', sumber: 'otomatis'),
            ],
        ];
    }

    private function kalk(): JangkaSorongCalculator
    {
        return $this->kalk ??= new JangkaSorongCalculator;
    }

    private function tabel(): TabelStandarJangkaSorong
    {
        return $this->tabel ??= new TabelStandarJangkaSorong;
    }
}
