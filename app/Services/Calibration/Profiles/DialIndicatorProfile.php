<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\DialIndicatorCalculator;
use App\Services\Calibration\TabelStandarDialIndicator;
use App\Support\DialIndicatorMentah;
use Carbon\Carbon;

/**
 * Lembar & hitungan **Dial Indicator** — lampiran akreditasi LK-285-IDN no. 36,
 * kelompok Panjang.
 *
 * Satu workbook master (`Master Olah Data_Dial Indicator.xlsm`, password
 * `spirit285`), dibuktikan sel demi sel lebih dulu; lihat
 * [DialIndicatorCalculator] untuk rumus dan penyimpangannya.
 *
 * ## Ketidakpastian lahir per SESI, dalam mm
 *
 * Satu budget sepuluh komponen untuk seluruh titik, lantai CMC per pita
 * kapasitas (0-25 6,5 µm; 0-50 8,6; 0-100 8,9; 0-300 9,7). Makanya
 * [hitungPerGrup] yang dipakai dan [komponenBudget] memulangkan `null`.
 *
 * ## KERTAS dan WORKBOOK tidak sebentuk — yang diikuti kertas
 *
 * Kertas `SIDIK-FM-CAL-0526_Rev.3` memungut **enam** penunjukan per nominal
 * (UP X1..X3, DOWN X1..X3) di lima belas baris, sementara `INPUT DATA` master
 * cuma punya **lima** kotak (X1..X5) di sepuluh baris. Yang dipegang teknisi
 * kertasnya, jadi lembar ini enam kotak berlabel UP/DOWN. Rata-rata dan
 * simpangan baku titik diambil atas SEMUA penunjukan yang terisi — persis
 * `AVERAGE(I31:M33)` master atas kotak yang terisi. Apakah UP dan DOWN
 * semestinya dirata-rata jadi satu (bukan dilaporkan sebagai histeresis)
 * ditanyakan ke lab, `docs/pertanyaan-lab-dial-indicator.md` §6.
 *
 * ## Nominal = TUMPUKAN balok ukur yang diketik teknisi
 *
 * Beda dari Micrometer (tumpukan dipatok per pita), di sini kertasnya kolom
 * nominal kosong dan master menerima sampai tiga keping per titik. Teknisi
 * mengetik kepingnya (`2,5+1,3+1,2`) di kotak per baris — bentuk `kolom_baris`
 * `daftar_angka` yang sudah dikenal HP dari lembar Timbangan. Setiap keping
 * WAJIB ada di daftar GB-9122-0; yang tidak, titiknya ditolak dengan alasan
 * yang menyebut angkanya. Master menjumlahkannya diam-diam tanpa keping itu.
 */
class DialIndicatorProfile extends CalibrationProfile
{
    public const SATUAN = 'mm';

    /** Baris awal lembar — sepuluh, sebanyak `INPUT DATA` master. Teknisi boleh menambah. */
    public const BARIS_AWAL = 10;

    /** UP X1..X3 + DOWN X1..X3, dari kertas FM-0526 Rev.3. */
    public const PENGULANGAN = 6;

    /** Sepuluh pembacaan blok Evaluation (`INPUT DATA!C31:M31`). */
    public const PRA_EVALUASI = 10;

    /** `DATABASE!C87` — jenis pengukuran no. 19. */
    public const KODE_METODE = 'SIDIK-IK-CAL-0519_Rev.3';

    /** Nomor formulir yang tercetak di kaki kertas lembar kerja. */
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0526_Rev.3';

    public const STANDARD_TERCETAK = [
        [
            'label' => 'Gauge Block/Metrology/GB-9122-0/0',
            'cocok' => ['Gauge Block', 'GB-9122-0', '160006'],
        ],
    ];

    /** Ketujuh unit thermohygro yang tercetak di `DATABASE!B31:B61` master. */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    /** Satuan `Satuan_Caliper` master (`DATABASE!R23:T25`). */
    public const SATUAN_PILIHAN = DialIndicatorMentah::FAKTOR_KE_MM;

    private ?DialIndicatorCalculator $kalk = null;

    public function kode(): string
    {
        return 'dial_indicator';
    }

    /** PERSIS nama lampiran LK-285-IDN no. 36. */
    public function namaAlatKemampuan(): string
    {
        return 'Dial Indicator';
    }

    public function aliasNama(): array
    {
        return ['Dial Gauge', 'Dial Indikator', 'Jam Ukur', 'Dial Test Indicator', 'Dial Consolidation'];
    }

    public function kodeFormula(): string
    {
        return 'gum-dial-indicator';
    }

    public function besaran(): string
    {
        return 'panjang';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function butuhBlokDialIndicator(): bool
    {
        return true;
    }

    /**
     * Dial Indicator tidak divonis PASS/FAIL — master berhenti di kolom
     * `Correction` + satu baris U95, tanpa satu pun batas keberterimaan.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * Jalur kamera belum dibuka: geometri OCR-nya grid generator yang belum
     * pernah diadu ke foto kertas asli, dan tumpukan balok ukur per baris
     * (`2,5+1,3+1,2`) bukan bentuk yang andal dibaca dari tulisan tangan.
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

    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        // DISAPU, bukan `$titik[0]` — jalur hitung ulang mengelompokkan lewat
        // `groupBy` dan urutannya tidak dijamin.
        $konteksSesi = [];

        foreach ($titik as $t) {
            if (isset($t['konteks']['spesifikasi_alat'])) {
                $konteksSesi = $t['konteks'];

                break;
            }
        }

        $blok = DialIndicatorMentah::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi Dial Indicator belum punya blok `spesifikasi_alat.'
                        .DialIndicatorMentah::KUNCI_SESI.'` — blok Evaluation, kapasitas, dan resolusi '
                        .'lahir di situ, bukan per titik.',
                ], $titik),
            ];
        }

        $masukan = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            $k = $t['konteks'] ?? [];
            $keping = array_map('floatval', $k[DialIndicatorMentah::PERAN_BALOK] ?? []);
            $pembacaan = array_map('floatval', $k[DialIndicatorMentah::PERAN_PEMBACAAN] ?? []);

            if ($keping === [] && $pembacaan === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d nggak punya baris ber-peran `%s`/`%s` — deret datar nggak bisa '
                        .'membedakan keping balok ukur dari penunjukan dial.',
                        $t['titik_ke'],
                        DialIndicatorMentah::PERAN_BALOK,
                        DialIndicatorMentah::PERAN_PEMBACAAN,
                    ),
                ];

                continue;
            }

            $masukan[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'keping' => $keping,
                'pembacaan' => $pembacaan,
                'standard' => $t['standard'] ?? null,
            ];
        }

        $hasil = $this->kalk()->hitungSesi(
            array_map(static fn (array $m): array => [
                'titik_ke' => $m['titik_ke'],
                'keping' => $m['keping'],
                'pembacaan' => $m['pembacaan'],
            ], $masukan),
            [
                'kapasitas_mm' => $blok['kapasitas_mm'],
                'resolusi_mm' => $blok['resolusi_mm'],
                'pra_evaluasi' => $blok['pra_evaluasi'],
                'balok_pra_evaluasi' => $blok['balok_pra_evaluasi'],
                'tanggal_kalibrasi' => $this->tanggalKalibrasi($konteksSesi),
                'suhu_ruang_rata_c' => (float) ($konteksSesi['suhu_ruang_rata'] ?? 0.0),
            ],
        );

        // Budget yang tidak utuh TIDAK melahirkan satu pun baris hitungan —
        // baris ber-U nol mencetak `± 0,000` (klaim pengukuran sempurna), dan
        // peringatan sesi bisa dilewati admin, jadi yang menahan harus
        // ketiadaan barisnya.
        if (! $hasil['boleh_terbit']) {
            $alasanSesi = implode(' ', array_map(
                static fn (array $d): string => (string) $d['alasan'],
                array_filter($hasil['ditolak'], static fn (array $d): bool => (int) $d['titik_ke'] === 0),
            ));

            foreach ($hasil['titik'] as $h) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $h['titik_ke'],
                    'alasan' => trim('Budget sesi tidak utuh, jadi titik ini tidak diterbitkan. '.$alasanSesi),
                ];
            }

            foreach ($hasil['ditolak'] as $d) {
                if ((int) $d['titik_ke'] !== 0) {
                    $belumDihitung[] = $d;
                }
            }

            usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

            return ['hitungan' => [], 'belum_dihitung' => $belumDihitung];
        }

        $standarPerTitik = collect($masukan)->keyBy('titik_ke');
        $kemampuan = $this->kemampuanSesi($equipment);
        $sekarang = Carbon::now();
        $hitungan = [];

        foreach ($hasil['titik'] as $h) {
            $hitungan[] = [
                'standard_id' => ($standarPerTitik[$h['titik_ke']]['standard'] ?? null)?->id,
                'titik_ke' => $h['titik_ke'],
                // Nominal Standard di sertifikat = standar TERKOREKSI
                // (`SERTIFIKAT!D18 = PERHITUNGAN!AB31`), bukan nominal cetak keping.
                'titik_ukur' => $h['standar_terkoreksi'],
                'rata_rata' => $h['rata_rata'],
                'error' => -$h['koreksi'],
                'koreksi' => $h['koreksi'],
                'standar_deviasi' => $h['simpangan_baku'],
                'jumlah_pengulangan' => $h['jumlah_pengulangan'],
                'type_a' => $this->typeASesi($hasil['budget']),
                'type_b_components' => $this->jejakAudit($hasil, $h),
                'type_b' => $hasil['type_b'],
                'ketidakpastian_gabungan' => $hasil['ketidakpastian_gabungan'],
                'faktor_cakupan_k' => $hasil['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $hasil['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $hasil['u95_sertifikat'],
                'toleransi' => null,
                'keputusan' => null,
                'metode' => $kemampuan?->metode ?? self::KODE_METODE,
                'calculated_at' => $sekarang,
            ];
        }

        foreach ($hasil['ditolak'] as $d) {
            $belumDihitung[] = $d;
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Peringatan sesi — tidak menahan (admin boleh melewatinya); yang menahan
     * ketiadaan baris di [hitungPerGrup].
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $blok = DialIndicatorMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null) {
            return [];
        }

        $temuan = [];

        if ((new TabelStandarDialIndicator)->pitaCmc($blok['kapasitas_mm']) === null) {
            $temuan[] = [
                'kode' => 'dial_indicator_di_luar_cmc',
                'pesan' => sprintf(
                    'Kapasitas %s mm tidak masuk pita CMC Dial Indicator (0-25, 0-50, 0-100, 0-300 mm), jadi '
                    .'sesi ini TIDAK menghasilkan titik terhitung. Periksa SATUAN alat lebih dulu — kapasitas '
                    .'diketik dalam satuan yang dipilih, dan 25 inch = 635 mm jatuh di luar semua pita.',
                    rtrim(rtrim(number_format($blok['kapasitas_mm'], 4, ',', '.'), '0'), ','),
                ),
            ];
        }

        $eval = $blok['pra_evaluasi'];

        if (count($eval) >= 2 && max($eval) === min($eval)) {
            $temuan[] = [
                'kode' => 'dial_indicator_evaluation_tanpa_sebaran',
                'pesan' => 'Kesepuluh pembacaan blok Evaluation bernilai sama, jadi komponen Repeatability '
                    .'budget bernilai nol. Untuk dial resolusi kasar ini lazim (sesi contoh master pun '
                    .'25,01 sepuluh kali), dan komponen resolusi serta lantai CMC tetap menampungnya — sesi '
                    .'tetap terbit. Pastikan angkanya memang dibaca sepuluh kali, bukan disalin.',
            ];
        }

        return $temuan;
    }

    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    public function desimalSertifikat(): ?int
    {
        // Lima desimal mm, bukan `0.00` sel master: nilai standar terkoreksi
        // (1,09989 mm) dan koreksi (−0,00011 mm) runtuh jadi `1,10` & `0,00` di
        // dua desimal — keping yang sedang dilaporkan koreksinya hilang. Sama
        // dengan keputusan Micrometer §9.
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
            'judul' => 'Calibration Work Sheet - Dial Indicator',
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Isi SATUAN alat lebih dulu — kapasitas, resolusi, blok Evaluation, dan '
                .'penunjukan dial diketik dalam satuan itu. Balok ukur SELALU mm dan wajib dari daftar '
                .'Gauge Block terkalibrasi; tulis tumpukannya dengan tanda + (mis. 2,5+1,3+1,2). Blok '
                .'Evaluation wajib diisi: dari situ ketidakpastian keterulangan lahir.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master Olah Data_Dial Indicator.xlsm',
                'catatan' => 'Sepuluh komponen tingkat-SESI dalam mm, k dari t-Student (v_eff), lantai CMC per '
                    .'pita kapasitas. Sesi tanpa pita CMC, blok Evaluation < 2 pembacaan, balok ukur '
                    .'Evaluation kosong/tak terdaftar, atau resolusi kosong TIDAK diterbitkan.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianEvaluasi(),
                $this->bagianDataKalibrasi(),
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
    private function typeASesi(array $budget): float
    {
        foreach ($budget as $k) {
            if (($k['distribusi'] ?? null) === 't-student') {
                return (float) $k['u'] * (float) $k['ci'];
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @param  array<string, mixed>  $h
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $hasil, array $h): array
    {
        $budget = array_map(fn (array $k): array => $this->barisAudit($k), $hasil['budget']);

        // WAJIB ada — `CalibrationValidator::cmcTitik()` mencarinya untuk
        // gerbang `u95_meledak_dari_cmc`.
        $budget[] = $this->barisPerbandinganCmc(
            (float) $hasil['ketidakpastian_diperluas'],
            isset($hasil['pita_cmc']['u95_mm']) ? (float) $hasil['pita_cmc']['u95_mm'] : null,
            self::SATUAN,
        );

        $budget[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'Balok ukur %s mm · total nominal %s mm · rata-rata %s mm dari %d penunjukan · koreksi %s mm · '
                .'L sensitivitas %s mm · pita CMC %s',
                implode(' + ', array_map(static fn ($n): string => (string) $n, $h['keping'])) ?: '-',
                $h['total_nominal'], $h['rata_rata'], $h['jumlah_pengulangan'], $h['koreksi'],
                $hasil['l_maks_mm'],
                $hasil['pita_cmc']['label'] ?? '-',
            ),
            'distribusi' => 'jejak',
            'nilai' => null,
            'u_baku' => 0.0,
            'ci' => 0.0,
            'vi' => 0.0,
        ];

        return $budget;
    }

    /** @return array<string, mixed> */
    private function bagianIdentitas(): array
    {
        $kunci = 'spesifikasi_alat.'.DialIndicatorMentah::KUNCI_SESI;

        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Identitas Alat dan Data Customer',
            'field' => [
                $this->field('equipment_id', 'Nama Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Nama Alat', 'teks', sumber: 'otomatis'),
                $this->field('alat_merk', 'Merk', 'teks'),
                $this->field('alat_model', 'Type', 'teks'),
                $this->field('alat_serial_number', 'Serial Number', 'teks'),
                // Satuan lebih dulu — dia mengubah arti dua field di bawahnya,
                // blok Evaluation, dan seluruh penunjukan. Dropdown, bukan teks
                // bebas: satuan tak dikenal jatuh ke faktor 1 dan angkanya salah
                // diam-diam.
                $this->field("{$kunci}.satuan", 'Satuan Alat', 'pilihan', pilihan: [
                    ['nilai' => 'mm', 'label' => 'mm'],
                    ['nilai' => 'inch', 'label' => 'inch'],
                    ['nilai' => 'µm', 'label' => 'µm'],
                ]),
                $this->field('spesifikasi_alat.rentang_ukur', 'Rentang Ukur', 'teks'),
                $this->field("{$kunci}.kapasitas_mm", 'Capacity (satuan alat)', 'angka'),
                $this->field("{$kunci}.resolusi_mm", 'Resolusi (satuan alat)', 'angka'),
                $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                $this->field('suhu_awal', 'Suhu Ruangan — awal', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Suhu Ruangan — akhir', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Kelembapan — awal', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Kelembapan — akhir', 'angka', satuan: '%RH'),
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
                $this->field('thermohygro_standard_id', 'Thermohygro used', 'pilihan', sumber: 'master_thermohygro'),
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
                $this->field('pemilik_nama', 'Owner', 'teks'),
                $this->field('pemilik_alamat', 'Address', 'teks_panjang'),
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
     * Blok Evaluation — sepuluh penunjukan berulang di satu tumpukan balok ukur
     * (master: 14 + 11 = 25 mm = kapasitas). Dari sini Repeatability dan
     * komponen "Standard balok ukur" SELURUH sesi lahir.
     *
     * @return array<string, mixed>
     */
    private function bagianEvaluasi(): array
    {
        $kunci = 'spesifikasi_alat.'.DialIndicatorMentah::KUNCI_SESI;

        return [
            'kode' => 'evaluasi',
            'halaman' => 1,
            'judul' => 'Evaluasi',
            'field' => [
                $this->field(
                    "{$kunci}.balok_pra_evaluasi",
                    'Nominal Balok Ukur Evaluasi (mm, pisahkan dengan +)',
                    'daftar_angka',
                    satuan: 'mm',
                ),
            ],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => 'pra_pembacaan',
                    'judul' => 'Evaluasi (X1..X10)',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Evaluasi',
                    'judul_pengulangan' => 'Pembacaan',
                    'titik_bisa_diubah' => false,
                    // Beda dari indeks 0..n tabel Data Kalibrasi — tanpa offset,
                    // baris Evaluasi berbagi kunci dengan titik pertama dan
                    // Repeatability seluruh sesi lahir dari angka titik ukur.
                    'offset_kunci' => 1000,
                    'simpan_ke' => "{$kunci}.pra_evaluasi",
                    'baris' => [[
                        'nomor' => 1,
                        'titik_ukur' => null,
                        'label' => 'Evaluasi',
                        'satuan' => self::SATUAN,
                    ]],
                    'kolom' => [
                        ['kode' => 'pembacaan', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                    ],
                    'pengulangan' => range(1, self::PRA_EVALUASI),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianDataKalibrasi(): array
    {
        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => 'Data Kalibrasi',
            'field' => [],
            'tabel' => [
                [
                    'kolom_baris' => [
                        $this->field('nominal', 'Nominal Balok Ukur (mm, pisahkan dengan +)', 'daftar_angka', satuan: 'mm'),
                    ],
                    'tahap' => 'sesudah_adjustment',
                    'grup' => DialIndicatorMentah::PERAN_PEMBACAAN,
                    'judul' => 'Pembacaan Alat',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Nominal Balok Ukur',
                    'judul_pengulangan' => 'Pembacaan',
                    'pengulangan_arah' => [
                        ['ke' => 1, 'label' => 'UP X1'],
                        ['ke' => 2, 'label' => 'UP X2'],
                        ['ke' => 3, 'label' => 'UP X3'],
                        ['ke' => 4, 'label' => 'DOWN X1'],
                        ['ke' => 5, 'label' => 'DOWN X2'],
                        ['ke' => 6, 'label' => 'DOWN X3'],
                    ],
                    'titik_bisa_diubah' => true,
                    'baris' => array_map(
                        static fn (int $n): array => [
                            'nomor' => $n,
                            'titik_ukur' => null,
                            'label' => 'Titik '.$n,
                            'satuan' => self::SATUAN,
                        ],
                        range(1, self::BARIS_AWAL),
                    ),
                    'kolom' => [
                        ['kode' => 'pembacaan', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                    ],
                    'pengulangan' => range(1, self::PENGULANGAN),
                ],
            ],
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

    private function kalk(): DialIndicatorCalculator
    {
        return $this->kalk ??= new DialIndicatorCalculator;
    }
}
