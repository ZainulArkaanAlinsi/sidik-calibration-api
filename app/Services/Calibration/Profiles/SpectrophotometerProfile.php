<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;
use App\Services\Calibration\SpectrophotometerCalculator;
use Illuminate\Support\Carbon;

/**
 * Profil Visible/UV-Vis Spectrophotometer (alat ke-6). Metode
 * `SIDIK-IK-CAL-0508_Rev.4` (DATABASE row 8), master
 * `Master Olah Data_Spectrofotometer.xlsm`.
 *
 * ## Yang bikin alat ini beda dari lima sebelumnya
 *
 * Lembarnya punya TIGA kelompok pengukuran, dan tiap kelompok punya SATU U95
 * bersama — bukan satu U95 per titik:
 *
 *   1. `holmium`            10 titik panjang gelombang, Filter Standard 1, satuan nm
 *   2. `didynium`            9 titik panjang gelombang, Filter Standard 2, satuan nm
 *   3. `akurasi_transmitan`  5 titik %T pada λ 560 nm,  Filter Standard 3, satuan %T
 *
 * Karena itu profil ini nge-override [hitungPerGrup] dan MENGGANTIKAN jalur
 * per-titik `GumCalculator::hitungTitik()` seluruhnya. [komponenBudget] sengaja
 * balik `null`: kalau suatu hari jalur per-titik kepanggil buat alat ini, dia
 * jangan diam-diam ngarang budget sendiri.
 *
 * Satuannya juga campur dalam satu lembar (nm & %T), kayak Conductivity — jadi
 * [satuanTitik], [resolusiTitik], dan [desimalTitik] semuanya jawab per titik.
 *
 * ## SRE: TIDAK diimplementasikan, dan itu keputusan sadar
 *
 * Master punya blok keempat, `%Transmitan (SRE)` — Stray Radiant Energy. Blok
 * itu **rusak di sumbernya** dan nggak ada satu pun angka yang bisa dipercaya:
 *
 *  - `SERTIFIKAT!C57` = `='[3]Input Data Mentah'!#REF!` → `#REF!`, dan `O57`
 *    (koreksinya) ikut `#REF!`. Jadi nilai standar SRE-nya sudah hilang dari
 *    workbook-nya sendiri.
 *  - `PERHITUNGAN U95%!AA65` & `AA66` = `#DIV/0!` — AVERAGE atas rentang kosong.
 *  - Baris keterulangannya dipatok `E67 = 0` (nol mati, bukan hasil hitung).
 *  - `k`-nya bukan t-student tapi rumus tempel
 *    `=((2,35746*1,099)+(veff*1,9599999))/veff` yang nggak ada padanannya di GUM.
 *  - `K75 = J74` — "CMC"-nya nunjuk balik ke U hitungnya sendiri, jadi
 *    `MAX(J74,K75)` selalu benar secara aritmetika dan nggak ngecek apa-apa.
 *  - `SERTIFIKAT!M58` = `='[3]CMC SPECTRO UD'!I85` — external reference yang
 *    filenya nggak ada.
 *
 * Mengarang penggantinya berarti nyetak angka ketidakpastian bikinan di
 * sertifikat terakreditasi. Jadi SRE muncul di lembar kerja sebagai bagian
 * BERSTATUS `sumber_belum_ada` yang nggak nerima input, dan nggak pernah masuk
 * hitungan mana pun. Begitu lab nyediain lembar SRE yang sah (nilai standar +
 * ketidakpastian standarnya), tinggal tambah satu kelompok di [TITIK] + satu
 * baris CMC di seeder-nya.
 *
 * @see \App\Services\Calibration\SpectrophotometerCalculator soal penyimpangan
 *      master yang sengaja ditiru (pembagi 3^0,25 & jangkauan SUM blok %T).
 * @see docs/handoff-backend-spectrophotometer.md
 */
class SpectrophotometerProfile extends CalibrationProfile
{
    public const KODE_DOKUMEN = 'SIDIK-IK-CAL-0508_Rev.4';

    /**
     * Kolom pengulangan bawaan tabel panjang gelombang (X1..X3). Blok %T pakai
     * [PENGULANGAN_TRANSMITAN] — lihat di sana kenapa beda.
     */
    public const JUMLAH_PENGULANGAN = 3;

    /**
     * Blok %T di master nyetak DUA baris X1..X3 per nilai standar, dan
     * `PERHITUNGAN` ngerata-rata keenamnya jadi satu (`F47 = SQRT(6)`,
     * `G47 = 6-1`). Jadi enam kolom, bukan tiga.
     *
     * Rumusnya sendiri tetap ngikut berapa kotak yang BENERAN diisi — teknisi
     * yang cuma ngisi tiga tetap dapat n=3 & vi=2, bukan angka master yang
     * dipaksakan.
     */
    public const PENGULANGAN_TRANSMITAN = 6;

    public const SATUAN_PANJANG_GELOMBANG = 'nm';

    public const SATUAN_TRANSMITAN = '%T';

    /**
     * Sedekat apa `titik_ukur` boleh meleset dari nilai [TITIK] dan masih
     * dianggap titik yang sama.
     *
     * Ketat (0,05 nm = 5× resolusi alat), BUKAN relatif kayak
     * [CalibrationProfile::TOLERANSI_PASANGAN_TITIK] yang 2%. Alasannya konkret:
     * 2% dari 536,3 nm itu ±10,7 nm, dan titik Didynium 529,7 masuk ke dalam
     * jendela itu — jadi baris Didynium bakal kepasangin Filter Standard 1
     * (Holmium) dan U95 kelompok yang salah kecetak di sertifikat. Standar
     * spektro nggak punya kurva suhu, jadi nilainya nggak pernah bergeser dan
     * jendela seketat ini aman.
     */
    private const TOLERANSI_TITIK = 0.05;

    /**
     * Titik ukur tercetak, per kelompok. Nilai panjang gelombang dari
     * `STANDAR_KALIBRATOR!J5:J14` (Holmium) & `J18:J26` (Didynium); nilai %T
     * dari `K29:K31` ditambah dua titik acuan yang diketik langsung di
     * `INPUT DATA!G66` (0 %T = berkas ditutup) dan `G74` (100 %T = berkas
     * terbuka) — dua-duanya bukan filter, makanya nggak ada di master standar.
     *
     * Resolusi dari `INPUT DATA!G16` (0,01 nm) & `E16` (0,001 %T).
     *
     * @var array<string, array{judul: string, satuan: string, resolusi: float, desimal: int, standar: list<string>, parameter_cmc: string, pengulangan: int, nilai: list<float>}>
     */
    public const TITIK = [
        SpectrophotometerCalculator::GRUP_HOLMIUM => [
            'judul' => 'Wave Length ( λ ) - Filter Holmium',
            'satuan' => self::SATUAN_PANJANG_GELOMBANG,
            'resolusi' => 0.01,
            'desimal' => 2,
            'standar' => ['Filter Standard 1'],
            'parameter_cmc' => 'panjang gelombang (nm)-Holmium',
            'pengulangan' => self::JUMLAH_PENGULANGAN,
            'nilai' => [279.6, 287.7, 334.0, 360.9, 418.6, 445.8, 453.6, 460.0, 536.3, 637.9],
        ],
        SpectrophotometerCalculator::GRUP_DIDYNIUM => [
            'judul' => 'Wave Length ( λ ) - Filter Didynium',
            'satuan' => self::SATUAN_PANJANG_GELOMBANG,
            'resolusi' => 0.01,
            'desimal' => 2,
            'standar' => ['Filter Standard 2'],
            'parameter_cmc' => 'panjang gelombang (nm)-Didynium',
            'pengulangan' => self::JUMLAH_PENGULANGAN,
            'nilai' => [475.2, 513.7, 529.7, 572.7, 585.7, 684.9, 738.5, 748.0, 806.1],
        ],
        SpectrophotometerCalculator::GRUP_TRANSMITAN => [
            'judul' => 'Accuracy %T and Linierity at λ = 560nm',
            'satuan' => self::SATUAN_TRANSMITAN,
            'resolusi' => 0.001,
            'desimal' => 3,
            'standar' => ['Filter Standard 3'],
            'parameter_cmc' => 'akurasi (%T)',
            'pengulangan' => self::PENGULANGAN_TRANSMITAN,
            'nilai' => [0.0, 9.9, 20.0, 30.1, 100.0],
        ],
    ];

    /**
     * Baris tabel STANDARD di lembar kerja — tiga filter yang dipilih lewat
     * `INPUT DATA!X16/X17/X18` lalu di-VLOOKUP ke `Standar_Kalibrator`.
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Filter Standard 1 (Holmium Oxide)', 'cocok' => ['Filter Standard 1', 'SPG080982.A']],
        ['label' => 'Filter Standard 2 (Didynium)', 'cocok' => ['Filter Standard 2', 'SPG080982.B']],
        ['label' => 'Filter Standard 3 (Neutral Gas Filter 1,2,3)', 'cocok' => ['Filter Standard 3', 'SPG080982.C']],
    ];

    /**
     * Sama daftarnya kayak profil lain — TH-1..TH-7, dikelompokkan Insitu vs
     * Inlab. Sesi master pakai TH-2 (`INPUT DATA!E23 = 2`).
     */
    public const THERMOHYGRO_TERCETAK = [
        ['label' => 'TH-1', 'grup' => 'Inlab'],
        ['label' => 'TH-3', 'grup' => 'Inlab'],
        ['label' => 'TH-4', 'grup' => 'Inlab'],
        ['label' => 'TH-5', 'grup' => 'Inlab'],
        ['label' => 'TH-7', 'grup' => 'Inlab'],
        ['label' => 'TH-2', 'grup' => 'Insitu'],
        ['label' => 'TH-6', 'grup' => 'Insitu'],
    ];

    /**
     * Dibikin malas, bukan di konstruktor — lihat alasan rantai muter di
     * [SpectrophotometerCalculator].
     */
    private ?SpectrophotometerCalculator $kalkulator = null;

    public function kode(): string
    {
        return 'spectrophotometer';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Spectrophotometer';
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_SPECTRO;
    }

    public function besaran(): string
    {
        return 'spektrofotometri';
    }

    /**
     * Kelompok yang menaungi satu titik ukur, atau `null` kalau titiknya nggak
     * dikenal sama sekali.
     */
    public function grupTitik(float $titikUkur): ?string
    {
        foreach (self::TITIK as $grup => $blok) {
            foreach ($blok['nilai'] as $nilai) {
                if (abs($nilai - $titikUkur) <= self::TOLERANSI_TITIK) {
                    return $grup;
                }
            }
        }

        return null;
    }

    /** Resolusi alat DI TITIK ini: 0,01 nm buat panjang gelombang, 0,001 %T buat transmitan. */
    public function resolusiTitik(float $titikUkur): ?float
    {
        $grup = $this->grupTitik($titikUkur);

        return $grup === null ? null : self::TITIK[$grup]['resolusi'];
    }

    public function desimalTitik(float $titikUkur): ?int
    {
        $grup = $this->grupTitik($titikUkur);

        return $grup === null ? null : self::TITIK[$grup]['desimal'];
    }

    /**
     * Satuan DI TITIK ini. Wajib per titik: satu lembar Spectrophotometer
     * nyampur nm & %T, jadi `equipments.satuan` yang tunggal nggak bisa jawab.
     */
    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        $grup = $this->grupTitik($titikUkur);

        return $grup === null ? null : self::TITIK[$grup]['satuan'];
    }

    /**
     * Kolom "Remark" di sertifikat — judul kelompoknya. Ini yang misahin tiga
     * blok hasil di dokumen, persis kayak tiga subjudul di `SERTIFIKAT!C17`,
     * `C32`, dan `C46`.
     */
    public function remarkTitik(float $titikUkur): ?string
    {
        $grup = $this->grupTitik($titikUkur);

        return $grup === null ? null : self::TITIK[$grup]['judul'];
    }

    /**
     * Filter standar dibaca NOMINAL apa adanya — nilai panjang gelombang
     * puncak Holmium/Didynium itu sifat bahan, bukan larutan yang bergeser
     * ikut suhu. Master pun nggak punya satu pun sel koreksi suhu buat nilai
     * standarnya (yang ada cuma catatan `*) Measured at 25°C`).
     */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * Master Spectrophotometer nggak punya SATU PUN sel yang mbandingin hasil
     * sama batas keberterimaan — sheet `SERTIFIKAT` cuma nyetak Standard / UUT
     * / Correction / U95% lalu berhenti. Sama kayak Conductivity: `toleransi`
     * NULL di sini artinya "emang nggak ada", bukan "belum diisi".
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        $pasangan = [];

        foreach (self::TITIK as $blok) {
            foreach ($blok['nilai'] as $nilai) {
                $pasangan[] = ['titik' => $nilai, 'standar' => $blok['standar']];
            }
        }

        return $pasangan;
    }

    /**
     * SENGAJA `null`. Alat ini nggak punya budget per titik — U95-nya lahir
     * per KELOMPOK lewat [hitungPerGrup]. Balik `null` bikin
     * `GumCalculator::hitungTitik()` jatuh ke jalur CMC apa adanya kalau suatu
     * saat kepanggil buat alat ini, bukan nyusun budget karangan yang
     * kelihatan sah.
     */
    public function komponenBudget(
        CalibrationCapability $kemampuan,
        Equipment $equipment,
        Standard $standard,
        float $titikUkur,
        float $typeA,
        int $n,
        ?float $suhuRuang = null,
    ): ?array {
        return null;
    }

    /**
     * Hitung seluruh sesi per KELOMPOK — pengganti jalur per-titik buat alat ini.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard}>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        $kemampuan = $this->kemampuanPerGrup($equipment);

        $perGrup = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            $grup = $this->grupTitik((float) $t['titik_ukur']);

            // Titik yang nggak dikenal DIBUANG dengan alasan yang kebaca, bukan
            // dipaksa masuk kelompok terdekat. Salah kelompok = U95 kelompok
            // lain kecetak di sertifikat, dan itu nggak keliatan salah.
            if ($grup === null) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Nilai standar %s nggak ada di daftar titik Spectrophotometer (Holmium/Didynium/%%T) — '
                        .'nggak bisa ditentukan masuk kelompok mana, jadi U95-nya nggak dihitung.',
                        $t['titik_ukur'],
                    ),
                ];

                continue;
            }

            if (! isset($kemampuan[$grup])) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'CMC buat "%s" belum ada di master kemampuan kalibrasi — jalankan '
                        .'SpectrophotometerCapabilitySeeder dulu.',
                        self::TITIK[$grup]['parameter_cmc'],
                    ),
                ];

                continue;
            }

            $perGrup[$grup][] = $t;
        }

        $hitungan = [];

        foreach ($perGrup as $grup => $anggota) {
            foreach ($this->hitungSatuGrup($grup, $anggota, $kemampuan[$grup]) as $baris) {
                $hitungan[] = $baris;
            }
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Satu kelompok → baris `uncertainty_calculations` buat tiap titiknya.
     *
     * Semua titik satu kelompok bawa uc / veff / k / U95 yang SAMA — itu memang
     * yang dicetak sertifikatnya (satu baris `Uncertainty U95% = ± …` per blok,
     * bukan per titik). Yang beda per titik cuma rata-rata, koreksi, STDEV, dan
     * Type A-nya sendiri.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard}>  $anggota
     * @return list<array<string, mixed>>
     */
    private function hitungSatuGrup(string $grup, array $anggota, CalibrationCapability $kemampuan): array
    {
        $blok = self::TITIK[$grup];
        $standar = $anggota[0]['standard'];

        $hasil = ($this->kalkulator ??= new SpectrophotometerCalculator)->hitungGrup(
            array_map(static fn (array $t): array => [
                'titik_ke' => (int) $t['titik_ke'],
                'titik_ukur' => (float) $t['titik_ukur'],
                'pembacaan' => $t['pembacaan'],
            ], $anggota),
            [
                'grup' => $grup,
                'satuan' => $blok['satuan'],
                'nama_standar' => (string) $standar->nama,
                'u_standar' => (float) ($standar->ketidakpastian ?? 0.0),
                'k_standar' => (float) ($standar->faktor_cakupan ?: 2.0),
                'resolusi' => $blok['resolusi'],
                'cmc' => (float) $kemampuan->ketidakpastian_terbaik,
            ],
        );

        $audit = $this->jejakAudit($hasil, $kemampuan);

        // RSS komponen Type B doang — yang IKUT dijumlah master DAN bukan
        // pengulangan. Aturannya identik sama `GumCalculator::hitungDariBudget()`
        // biar dua jalur ini nggak beda arti buat kolom yang sama.
        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter(
                $hasil['budget'],
                static fn (array $k): bool => $k['disertakan'] && $k['distribusi'] !== 't-student',
            ),
        )));

        $sekarang = Carbon::now();

        return array_map(static fn (array $t): array => [
            'standard_id' => $standar->id,
            'titik_ke' => $t['titik_ke'],
            'titik_ukur' => $t['titik_ukur'],
            'rata_rata' => $t['rata_rata'],
            'error' => $t['error'],
            'koreksi' => $t['koreksi'],
            'standar_deviasi' => $t['standar_deviasi'],
            'jumlah_pengulangan' => $t['jumlah_pengulangan'],
            'type_a' => $t['type_a'],
            'type_b_components' => $audit,
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $hasil['ketidakpastian_gabungan'],
            'faktor_cakupan_k' => $hasil['faktor_cakupan_k'],
            'derajat_kebebasan_efektif' => $hasil['derajat_kebebasan_efektif'],
            'ketidakpastian_diperluas' => $hasil['u95_sertifikat'],
            // Spectrophotometer nggak divonis PASS/FAIL — lihat [punyaToleransi].
            'toleransi' => null,
            'keputusan' => null,
            'metode' => $kemampuan->metode,
            'calculated_at' => $sekarang,
        ], $hasil['titik']);
    }

    /**
     * Rincian budget buat kolom `type_b_components`, bentuknya sama persis
     * kayak yang dikeluarin `GumCalculator::hitungDariBudget()` supaya layar
     * & lembar perhitungan nggak perlu tau alat ini beda.
     *
     * @param  array<string, mixed>  $hasil
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $hasil, CalibrationCapability $kemampuan): array
    {
        $audit = array_map(static fn (array $k): array => [
            'sumber' => $k['sumber'],
            'keterangan' => $k['keterangan'],
            'distribusi' => $k['distribusi'],
            'nilai' => $k['u'] * $k['ci'],
            'u_baku' => $k['u'],
            'ci' => $k['ci'],
            'vi' => $k['vi'],
            // Ditulis eksplisit, termasuk waktu `true`: pembaca jejak audit
            // harus bisa lihat komponen mana yang IKUT tanpa hafal kuirk master.
            'disertakan' => $k['disertakan'],
            'alasan_dikecualikan' => $k['alasan_dikecualikan'] ?? null,
        ], $hasil['budget']);

        foreach ($hasil['catatan_audit'] as $catatan) {
            $audit[] = [
                'sumber' => $catatan['kode'],
                'keterangan' => $catatan['pesan'],
                'distribusi' => '-',
                'nilai' => $catatan['ketidakpastian_diperluas'] ?? $hasil['cmc'],
            ];
        }

        // Titik di luar rentang CMC yang diakreditasi TETAP dihitung & dicetak
        // (master pun begitu: baris Holmium 279,6 nm ada di sertifikat padahal
        // CMC-nya dinyatakan 283–641 nm), tapi faktanya dicatat — kalau nggak,
        // nggak ada yang tau sertifikatnya ngeklaim di luar ruang lingkup.
        $luar = array_values(array_filter(
            $hasil['titik'],
            static fn (array $t): bool => $t['titik_ukur'] < (float) $kemampuan->range_min
                || $t['titik_ukur'] > (float) $kemampuan->range_max,
        ));

        if ($luar !== []) {
            $audit[] = [
                'sumber' => 'titik_luar_rentang_cmc',
                'keterangan' => sprintf(
                    'Titik %s di luar rentang CMC terakreditasi %s–%s %s ("%s"). '
                    .'Ikut master: tetap dihitung & dicetak, lantai CMC tetap dipakai.',
                    implode(', ', array_column($luar, 'titik_ukur')),
                    $kemampuan->range_min, $kemampuan->range_max,
                    $kemampuan->satuan ?? '', $kemampuan->parameter ?? '',
                ),
                'distribusi' => '-',
                'nilai' => (float) $kemampuan->ketidakpastian_terbaik,
            ];
        }

        return $audit;
    }

    /**
     * Baris CMC per kelompok, dicocokin lewat `calibration_capabilities.parameter`
     * — BUKAN lewat rentang angka.
     *
     * Rentangnya nggak bisa jadi kunci: Holmium 283–641 nm dan Didynium 474–810
     * nm tumpang tindih 167 nm, jadi titik 536,3 nm cocok ke dua-duanya dan
     * `GumCalculator::kemampuanUntukTitik()` (yang milih `range_max` terdekat)
     * bakal milih salah satunya secara kebetulan. Yang nentuin kelompok itu
     * FILTER yang dipakai, dan itu diketahui dari [TITIK], bukan dari angkanya.
     *
     * @return array<string, CalibrationCapability>
     */
    private function kemampuanPerGrup(Equipment $equipment): array
    {
        $parameter = array_column(self::TITIK, 'parameter_cmc');

        $master = CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->when(
                $equipment->equipment_category_id !== null,
                fn ($q) => $q->where('equipment_category_id', $equipment->equipment_category_id),
            )
            ->whereIn('parameter', $parameter)
            ->get()
            ->keyBy('parameter');

        $hasil = [];

        foreach (self::TITIK as $grup => $blok) {
            $cocok = $master->get($blok['parameter_cmc']);

            if ($cocok !== null) {
                $hasil[$grup] = $cocok;
            }
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = $this->bentukLengkap();
        $bentuk = $this->tautkanStandar($bentuk);
        $bentuk = $this->tautkanStandarSpektro($bentuk);
        $bentuk = $this->isiPilihanThermohygro($bentuk);

        if ($untukAdmin) {
            $bentuk['bagian'][] = $this->bagianAdmin();
            $bentuk['untuk'] = 'admin';

            return $bentuk;
        }

        $bentuk['untuk'] = 'teknisi';
        $bentuk['bagian'] = array_map(
            fn (array $bagian): array => [
                ...$bagian,
                'field' => array_values(array_filter(
                    $bagian['field'] ?? [],
                    fn (array $field): bool => ! $field['hanya_admin'],
                )),
            ],
            $bentuk['bagian'],
        );

        return $bentuk;
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'judul' => 'Calibration Worksheet - Spectrophotometer',
            'jumlah_pengulangan' => self::JUMLAH_PENGULANGAN,
            'larutan_standar' => array_merge(...array_map(
                static fn (array $blok): array => $blok['nilai'],
                array_values(self::TITIK),
            )),
            // Satuan lembar ini CAMPUR — tiap tabel & tiap baris bawa satuannya
            // sendiri. Kunci ini diisi satuan blok pertama cuma buat pemakai
            // lama yang belum baca per baris.
            'satuan' => self::SATUAN_PANJANG_GELOMBANG,
            'satuan_campuran' => [self::SATUAN_PANJANG_GELOMBANG, self::SATUAN_TRANSMITAN],
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin — '
                .'lembar kerja tetap bisa dikirim. Tiap kelompok (Holmium / Didynium / %T) punya '
                .'SATU U95 bersama yang dihitung dari STDEV terbesar di kelompok itu, jadi titik '
                .'yang kosong ngurangin dasar hitungnya.',
            'bagian' => [
                [
                    'kode' => 'identitas_alat',
                    'halaman' => 1,
                    'judul' => 'EQUIPMENT IDENTITY AND CUSTOMER DATA',
                    'field' => [
                        $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                        $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                        $this->field('equipment_id', 'Equipment', 'pilihan', sumber: 'master_alat'),
                        $this->field('equipment.nama_alat', '1. Name', 'teks', sumber: 'otomatis'),
                        $this->field('equipment.range_resolusi', '2. Range/Resolution', 'teks', sumber: 'otomatis'),
                        $this->field('alat_model', '3. Type/Model', 'teks'),
                        $this->field('alat_serial_number', '4. Serial Number/LPI', 'teks'),
                        $this->field('alat_merk', '5. Merk/Manufacture', 'teks'),
                        $this->field(
                            'thermohygro_standard_id',
                            '6. Thermohygro used',
                            'pilihan',
                            sumber: 'master_thermohygro',
                        ),
                    ],
                ],
                [
                    'kode' => 'pemilik',
                    'halaman' => 1,
                    'judul' => 'OWNER',
                    'field' => [
                        $this->field('pemilik_nama', '1. Name', 'teks'),
                        $this->field('pemilik_alamat', '2. Address', 'teks_panjang'),
                    ],
                ],
                [
                    'kode' => 'usage_check',
                    'halaman' => 1,
                    'judul' => 'STANDARD',
                    'baris' => self::STANDARD_TERCETAK,
                    'field' => [
                        $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                        $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
                    ],
                ],
                [
                    'kode' => 'data_kalibrasi',
                    'halaman' => 1,
                    'judul' => 'CALIBRATION DATA',
                    'field' => [
                        $this->field('lokasi', '1. Location', 'pilihan', pilihan: [
                            ['nilai' => 'lab', 'label' => 'In lab'],
                            ['nilai' => 'onsite', 'label' => 'Insitu'],
                        ]),
                        $this->field('room_id', 'Ruangan', 'pilihan', sumber: 'master_ruangan'),
                        $this->field(
                            'calibration_method_id',
                            '2. Calibration Methode',
                            'pilihan',
                            sumber: 'master_metode',
                            hanyaAdmin: true,
                        ),
                    ],
                ],
                [
                    'kode' => 'hasil',
                    'halaman' => 1,
                    'judul' => 'CALIBRATION RESULT',
                    'field' => [
                        $this->field('suhu_awal', 'Env. Condition — First', 'angka', satuan: '°C'),
                        $this->field('kelembaban_awal', 'Env. Condition — First', 'angka', satuan: '%RH'),
                        $this->field('suhu_akhir', 'Env. Condition — End', 'angka', satuan: '°C'),
                        $this->field('kelembaban_akhir', 'Env. Condition — End', 'angka', satuan: '%RH'),
                    ],
                    'tabel' => array_map(
                        fn (string $grup): array => $this->tabelGrup($grup),
                        array_keys(self::TITIK),
                    ),
                ],
                [
                    // Bagian ini SENGAJA nggak punya field input. Lihat catatan
                    // SRE di dokumentasi kelas: sumber angkanya `#REF!` di
                    // master, jadi nggak ada yang sah buat diisi.
                    'kode' => 'sre',
                    'halaman' => 1,
                    'judul' => 'SRE (Stray Radiant Energy)',
                    'status' => 'sumber_belum_ada',
                    'catatan' => 'Belum diimplementasikan: di master, nilai standar SRE hilang '
                        .'(SERTIFIKAT!C57 & O57 = #REF!), budget-nya #DIV/0! (PERHITUNGAN U95%!AA65-AA66), '
                        .'faktor cakupannya bukan t-student, dan CMC-nya nunjuk balik ke hasil hitungnya '
                        .'sendiri. Backend nggak nyetak angka SRE sampai lab nyediakan lembar sumber '
                        .'yang sah.',
                    'field' => [],
                ],
                [
                    'kode' => 'penutup',
                    'halaman' => 1,
                    'judul' => 'Catatan & Tanda Tangan',
                    'field' => [
                        $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                        $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                        $this->field('reviewer.nama', 'Checked by', 'teks', sumber: 'otomatis'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bagianAdmin(): array
    {
        return [
            'kode' => 'administratif',
            'halaman' => 1,
            'judul' => 'Data Administratif (Admin)',
            'field' => [
                $this->field('nomor_order', 'Order Number', 'teks', hanyaAdmin: true),
                $this->field('certificate.nomor', 'Certificate Number', 'teks', sumber: 'otomatis', hanyaAdmin: true),
                $this->field('suhu_ketidakpastian', 'U95% Suhu', 'angka', sumber: 'otomatis', satuan: '°C', hanyaAdmin: true),
                $this->field('kelembaban_ketidakpastian', 'U95% Kelembaban', 'angka', sumber: 'otomatis', satuan: '%RH', hanyaAdmin: true),
            ],
        ];
    }

    /**
     * Satu tabel hasil per kelompok. `tahap` dipakai sebagai IDENTITAS TABEL di
     * layar, dan buat Spectrophotometer isinya kode kelompok — bukan
     * before/after adjustment. Alat ini nggak punya tahap adjustment: master-nya
     * cuma sekali baca per titik.
     *
     * @return array<string, mixed>
     */
    private function tabelGrup(string $grup): array
    {
        $blok = self::TITIK[$grup];

        return [
            'tahap' => 'sesudah_adjustment',
            'grup' => $grup,
            'judul' => $blok['judul'],
            'satuan' => $blok['satuan'],
            'baris' => array_map(
                fn (float $nilai): array => [
                    'titik_ukur' => $nilai,
                    'label' => number_format($nilai, $blok['desimal'], '.', ''),
                    'resolusi' => $blok['resolusi'],
                    'desimal' => $blok['desimal'],
                    'satuan' => $blok['satuan'],
                ],
                $blok['nilai'],
            ),
            'kolom' => [
                [
                    'kode' => 'pembacaan',
                    'label' => $blok['satuan'],
                    'tipe' => 'angka',
                    'satuan' => $blok['satuan'],
                ],
            ],
            'pengulangan' => range(1, $blok['pengulangan']),
        ];
    }

    /**
     * Stempel `standard_id` ke tiap baris tabel hasil.
     *
     * Nulis sendiri, nggak makai [CalibrationProfile::tautkanStandarTitik],
     * karena toleransi pasangan di sana RELATIF 2% dan itu jebol buat alat ini
     * — lihat alasannya di [TOLERANSI_TITIK].
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanStandarSpektro(array $bentuk): array
    {
        $master = Standard::query()
            ->whereNull('parameter_kondisi')
            ->get(['id', 'nama', 'serial_number']);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            foreach ($bagian['tabel'] ?? [] as $j => $tabel) {
                $nama = self::TITIK[$tabel['grup']]['standar'];

                $standar = $master->first(fn (Standard $s): bool => in_array($s->nama, $nama, true)
                    || in_array($s->serial_number, $nama, true));

                foreach ($tabel['baris'] as $k => $baris) {
                    $bentuk['bagian'][$i]['tabel'][$j]['baris'][$k] = [
                        ...$baris,
                        'standard_id' => $standar?->id,
                        'standard_nama' => $standar?->nama,
                    ];
                }
            }
        }

        return $bentuk;
    }

    /**
     * Cocokin baris STANDARD tercetak ke master `standards` lab (nama/serial).
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanStandar(array $bentuk): array
    {
        $master = Standard::query()
            ->whereNull('parameter_kondisi')
            ->get(['id', 'nama', 'serial_number', 'no_sertifikat', 'tertelusur_ke']);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            $bentuk['bagian'][$i]['baris'] = array_map(
                function (array $baris) use ($master): array {
                    $cocok = $master->first(fn (Standard $s): bool => collect($baris['cocok'])
                        ->contains(fn (string $kunci): bool => $s->nama === $kunci
                            || $s->serial_number === $kunci));

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
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function isiPilihanThermohygro(array $bentuk): array
    {
        $master = Standard::query()
            ->whereNotNull('parameter_kondisi')
            ->pluck('id', 'nama');

        $pilihan = [];
        foreach (self::THERMOHYGRO_TERCETAK as $unit) {
            $id = $master[$unit['label']] ?? null;
            if ($id === null) {
                continue;
            }
            $pilihan[] = [
                'nilai' => (string) $id,
                'label' => $unit['label'],
                'grup' => $unit['grup'],
            ];
        }

        foreach ($bentuk['bagian'] as $i => $bagian) {
            foreach ($bagian['field'] ?? [] as $j => $field) {
                if ($field['kode'] === 'thermohygro_standard_id') {
                    $bentuk['bagian'][$i]['field'][$j]['pilihan'] = $pilihan;
                }
            }
        }

        return $bentuk;
    }

    /**
     * @param  list<array<string, string>>  $pilihan
     * @return array<string, mixed>
     */
    private function field(
        string $kode,
        string $label,
        string $tipe,
        ?string $sumber = null,
        ?string $satuan = null,
        array $pilihan = [],
        bool $hanyaAdmin = false,
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
        ];
    }

    /** Master Spectrophotometer: `PERHITUNGAN!G14` format `General` → `21,61`. */
    public function desimalSuhuEnv(): ?int
    {
        return null;
    }

    /** Master Spectrophotometer: `PERHITUNGAN!G15` format `General` → `56,5`. */
    public function desimalKelembabanEnv(): ?int
    {
        return null;
    }
}
