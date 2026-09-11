<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Mesin hitung lembar **Anak Timbangan** (OIML R111) — alat ke-29.
 *
 * Diturunkan dari `1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx` dan
 * diadu ke sana sel demi sel: **1033 nilai, nol beda pada 5·10⁻⁶** — 20 titik ×
 * (ms, de, b, mT) plus 20 blok budget × 6 komponen × (U, divisor, vi, ui, ci,
 * uici, uici²) plus uc, veff, k, U95 tiap blok.
 *
 * ## Jalur angkanya
 *
 * ```
 * ρ_udara = ((0,34848·P) − (0,009·RH)·EXP(0,061·T)) / (T + 273,15)   OIML R111 Lampiran E
 * de      = (T1 − S1 − S2 + T2) / 2                                  substitusi ganda ABBA
 * b       = (ρ_udara − 1,2) · (1/ρ_UUT − 1/ρ_std) · ms               koreksi apung
 * mT      = ms + de + b                                              massa konvensional
 * ```
 *
 * Budgetnya enam komponen, seluruhnya dalam MILIGRAM, lalu dikonversi ke gram
 * di ujung — supaya angkanya bisa diadu sel demi sel ke sheet `PERHITUNGAN U95%`
 * master tanpa faktor konversi yang menyelinap di tengah.
 *
 * ## Yang DIBETULKAN dari master, dan kenapa cuma yang ini
 *
 * Aturannya dari `[[sidik-alat-baru-dari-master]]`: kejanggalan METODE ditiru
 * lalu ditanyakan; kerusakan RUJUKAN dihitung benar lalu selisihnya ditulis.
 *
 * **Dibetulkan — kolom `b` memakai `ms` keping PERTAMA.** Sejak titik 9 sampai
 * 20, master memungut `ms` titik 1 (100,000144 g) alih-alih massa keping yang
 * sedang dihitung. Itu rujukan relatif yang tidak ikut bergeser waktu rumusnya
 * di-drag ke bawah — kerusakan rujukan, bukan pilihan metode. Selisihnya sampai
 * **2,58 mg pada keping 1 g yang toleransinya 0,10 mg** (25,8× MPE), dan
 * sertifikat master mencetak keping 0,1 g sebagai 0,09884965 g — meleset 1,16 mg.
 * Di sini `b` memakai `ms` keping itu sendiri; nilai jalur master ikut disimpan
 * sebagai `b_jalur_master_g` supaya selisihnya bisa dibaca, bukan cuma
 * dipercaya. Pertanyaan lab §2, dan `AnakTimbanganMasterTest` menegakkan arahnya.
 *
 * **Dibetulkan — titik yang koreksi apungnya HILANG.** Di sesi contoh, kolom `b`
 * kedua keping 200 g bernilai 0 padahal densitasnya ada dan koreksi yang benar
 * +0,0169 mg. Besarnya kecil (14 % U95); yang tidak boleh ditiru cara diamnya.
 * Pertanyaan lab §5.
 *
 * **Dibetulkan — ketidakpastian tekanan yang tercetak.** Sertifikat master
 * memakai ketidakpastian KELEMBABAN meternya untuk kolom tekanan. Rujukan
 * meleset satu kolom. Lihat [lingkungan]. Pertanyaan lab §7.
 *
 * **Ditiru — keterulangan neraca.** Sel master berlabel `Rata-rata STDev`
 * sebenarnya berisi SIMPANGAN BAKU dari keenam simpangan baku harian. Itu
 * pilihan METODE, dan `FORM VALIDASI` mencatatnya sebagai perubahan yang sengaja
 * (revisi 3, 29 Mei 2026, sudah divalidasi Manajer Teknis). Kalau diganti
 * gabungan kuadrat harian, U95 seluruh sertifikat naik ~1,9×. Ditiru; yang
 * memutuskan lab. Pertanyaan lab §1.
 *
 * **Ditiru — pembagi 1,73 dan pengali 1,414.** Bukan √3 dan √2. Selisihnya
 * 0,12 % dan 0,015 %, dua-duanya menaikkan `ui`. Pertanyaan lab §18.
 *
 * **Ditiru — `ci` komponen apung.** Dimensinya tidak konsisten: `u` dalam
 * kg/m³, `ci` dalam m³/kg dikali massa dalam GRAM, hasilnya dibaca MILIGRAM.
 * Kalau dibaca konsisten, `ci`-nya seribu kali lebih besar dan `U95` naik
 * 0,35 % (0,1263 → 0,1268 mg di titik 100 g). Workbook yang sama memang
 * mislabel satuan di tempat lain (sel `Data Sens` berisi 20075 berlabel mg
 * padahal µg), jadi ini kemungkinan besar slip satuan — tapi "kemungkinan
 * besar" bukan dasar untuk menggeser U95 tiap sertifikat yang sudah terbit.
 * Ditiru, dan angkanya ditulis. Pertanyaan lab §20.
 *
 * **Ditiru — densitas udara memakai rata-rata MENTAH.** Rantai koreksi meter
 * lingkungan dihitung lengkap lalu tidak dipakai. Pertanyaan lab §13.
 *
 * ## Gerbang yang menahan, bukan yang memperingatkan
 *
 * Master menerbitkan `#VALUE!` di lima dari dua puluh baris sertifikat, dan
 * satu keping 10 g sebagai **5,500163 g** (salah ketik satu digit, meleset 45 %)
 * tanpa satu pun sel memprotes. Di sini titik seperti itu **tidak melahirkan
 * baris hitungan sama sekali** — bukan peringatan, karena
 * `CalibrationValidator` membungkus peringatan profil jadi temuan yang boleh
 * dilewati admin lewat `abaikan_peringatan`.
 */
class AnakTimbanganCalculator
{
    /** Massa jenis udara acuan untuk massa konvensional (kg/m³), OIML R111. */
    private const RHO_ACUAN = 1.2;

    /**
     * Ambang penolakan `|de|`, dalam kelipatan MPE kelas UUT.
     *
     * Di sesi contoh master, `de` yang wajar semuanya di bawah 0,35 mg
     * sementara titik yang salah ketik memberi **4.500,05 mg** — 22.500× MPE
     * keping 10 g. Ambang 10× MPE memisahkan keduanya dengan jarak sangat lebar
     * dan tidak menyentuh satu pun titik sehat. Pertanyaan lab §3 meminta lab
     * menyetujui atau mengganti angkanya.
     */
    public const AMBANG_DE_KALI_MPE = 10.0;

    private ?GumCalculator $gum = null;

    /**
     * Hitung seluruh keping satu sesi.
     *
     * @param  list<array{titik_ke: int, nominal_g: float, at_s1: float|null, at_t1: float|null, at_t2: float|null, at_s2: float|null}>  $titik
     * @param  array<string, mixed>  $konteks  keluaran `AnakTimbanganMentah::blokSesi()`
     * @return array{boleh_terbit: bool, rho_udara: float|null, lingkungan: array<string, mixed>, titik: list<array<string, mixed>>, ditolak: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungSesi(array $titik, array $konteks): array
    {
        $ditolak = [];
        $lingkungan = $this->lingkungan($konteks);

        $suhu = $lingkungan['suhu']['rata'];
        $rh = $lingkungan['kelembaban']['rata'];
        $tekanan = $lingkungan['tekanan']['rata'];

        $kelasUut = $konteks['kelas_uut'] ?? null;
        $kelasStandar = $konteks['kelas_standar'] ?? null;
        $namaTimbangan = $konteks['timbangan'] ?? null;
        $timbangan = $namaTimbangan === null
            ? null
            : TabelStandarAnakTimbangan::timbangan($namaTimbangan);

        // Prasyarat tingkat SESI. Satu pun yang hilang membuat seluruh sesi
        // tidak bisa dihitung — bukan "dihitung sebagian", karena densitas udara
        // masuk KE SETIAP keping dan neraca memasok DUA dari enam komponen
        // budget setiap keping.
        $kurang = [];

        if ($suhu === null || $rh === null || $tekanan === null) {
            $kurang[] = 'kondisi lingkungan (suhu, kelembaban, dan tekanan wajib punya nilai awal DAN '
                .'akhir — densitas udara lahir dari ketiganya)';
        }

        if ($kelasUut === null) {
            $kurang[] = 'kelas OIML alat yang dikalibrasi';
        }

        if ($kelasStandar === null) {
            $kurang[] = 'kelas OIML keping standar';
        }

        if ($timbangan === null) {
            $kurang[] = $namaTimbangan === null
                ? 'neraca yang dipakai'
                : "neraca '{$namaTimbangan}' nggak ada di tabel standar";
        }

        if ($kurang !== []) {
            return [
                'boleh_terbit' => false,
                'rho_udara' => null,
                'lingkungan' => $lingkungan,
                'titik' => [],
                'ditolak' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi Anak Timbangan belum lengkap: '.implode('; ', $kurang).'.',
                ], $titik),
            ];
        }

        $rhoUdara = $this->densitasUdara($suhu, $rh, $tekanan);
        $kembar = TabelStandarAnakTimbangan::kepingKembar();
        $identitas = $konteks['identitas'] ?? [];
        $msPertama = self::msKepingPertama($titik);
        $hasil = [];

        foreach ($titik as $t) {
            $titikKe = (int) $t['titik_ke'];
            $nominal = (float) $t['nominal_g'];
            $tolak = static function (string $alasan) use (&$ditolak, $titikKe): void {
                $ditolak[] = ['titik_ke' => $titikKe, 'alasan' => $alasan];
            };

            $baca = [];

            foreach (['at_s1', 'at_t1', 'at_t2', 'at_s2'] as $peran) {
                $baca[$peran] = isset($t[$peran]) && is_numeric($t[$peran]) ? (float) $t[$peran] : null;
            }

            $hilang = array_keys(array_filter($baca, static fn (?float $v): bool => $v === null));

            if ($hilang !== []) {
                $tolak(sprintf(
                    'Titik %d belum punya pembacaan %s. Substitusi ganda ABBA butuh keempatnya — '
                    .'`de = (T1 − S1 − S2 + T2)/2` nggak punya arti kalau salah satunya kosong.',
                    $titikKe,
                    implode(', ', $hilang),
                ));

                continue;
            }

            $keping = TabelStandarAnakTimbangan::cariKeping($nominal);

            if ($keping === null) {
                $tolak(sprintf(
                    'Titik %d: nominal %s g nggak ada di tabel keping standar. Master membungkus '
                    .'pencarian ini dengan IFERROR, jadi nominal yang salah ketik lahir sebagai massa '
                    .'standar NOL alih-alih error.',
                    $titikKe,
                    self::angka($nominal),
                ));

                continue;
            }

            $rhoUut = TabelStandarAnakTimbangan::densitas($nominal, $kelasUut);
            $rhoStd = TabelStandarAnakTimbangan::densitas($nominal, $kelasStandar);

            if ($rhoUut === null || $rhoStd === null) {
                $tolak(sprintf(
                    'Titik %d: tabel densitas nggak punya baris %s g untuk kelas %s. Di master titik '
                    .'seperti ini terbit sebagai `#VALUE!` di kolom massa konvensional DAN '
                    .'ketidakpastian sertifikat pelanggan (pertanyaan lab §4).',
                    $titikKe,
                    self::angka($nominal),
                    $rhoUut === null ? $kelasUut : $kelasStandar,
                ));

                continue;
            }

            if (self::adaDiDaftar($nominal, $kembar) && ! isset($identitas[$titikKe])) {
                $tolak(sprintf(
                    'Titik %d: nominal %s g punya lebih dari satu keping di set ini, jadi `no_identitas` '
                    .'wajib diisi. Tanpa penanda, dua baris sertifikat bernominal sama nggak bisa '
                    .'dipetakan pelanggan ke kepingnya (pertanyaan lab §11).',
                    $titikKe,
                    self::angka($nominal),
                ));

                continue;
            }

            $de = ($baca['at_t1'] - $baca['at_s1'] - $baca['at_s2'] + $baca['at_t2']) / 2;
            $mpe = TabelStandarAnakTimbangan::mpe($nominal, $kelasUut);

            if ($mpe !== null && abs($de) * 1000 > self::AMBANG_DE_KALI_MPE * $mpe) {
                $tolak(sprintf(
                    'Titik %d: selisih penimbangan |de| = %s mg, lebih dari %s× MPE kelas %s pada %s g '
                    .'(%s mg). Ini pola salah ketik satu digit — di sesi contoh master, keping 10 g '
                    .'yang T1-nya tertulis 0,9998 (harusnya 9,9998) terbit sebagai 5,500163 g, meleset '
                    .'45 %%, dengan ketidakpastian yang tetap rapi (pertanyaan lab §3).',
                    $titikKe,
                    self::angka($de * 1000),
                    self::angka(self::AMBANG_DE_KALI_MPE),
                    $kelasUut,
                    self::angka($nominal),
                    self::angka($mpe),
                ));

                continue;
            }

            $ms = $keping['konvensional_g'];
            $faktorApung = ($rhoUdara - self::RHO_ACUAN) * (1 / $rhoUut - 1 / $rhoStd);

            // `ms` keping INI — bukan keping pertama. Lihat docblock kelas.
            $b = $faktorApung * $ms;
            $mt = $ms + $de + $b;

            $budget = $this->budget($timbangan, $keping, $rhoUut, $rhoStd);
            $agregat = $this->gum()->agregasiBudget(array_map(
                static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
                $budget,
            ));

            // RSS komponen Type B saja — aturannya disamakan dengan
            // `GumCalculator::hitungDariBudget()` supaya kolom `type_b` tidak
            // beda arti antar-alat.
            $typeB = sqrt(array_sum(array_map(
                static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
                array_filter($budget, static fn (array $k): bool => $k['distribusi'] !== 't-student'),
            )));

            $hasil[] = [
                'titik_ke' => $titikKe,
                'nominal_g' => $nominal,
                'no_identitas' => $identitas[$titikKe] ?? null,
                'keping_standar' => $keping,
                'rho_uut' => $rhoUut,
                'rho_standar' => $rhoStd,
                'ms_g' => $ms,
                'de_g' => $de,
                'b_g' => $b,
                // Nilai yang akan dikeluarkan master untuk titik ini. Disimpan
                // supaya selisihnya bisa DIBACA di jejak audit, bukan dipercaya
                // begitu saja — lihat docblock kelas.
                'b_jalur_master_g' => $faktorApung * $msPertama,
                'mt_g' => $mt,
                // Sebaran kedua penimbangan UUT. INFORMATIF: bukan dia yang jadi
                // Type A budget — master memakai keterulangan neraca dari sheet
                // verifikasi harian, bukan sebaran sesi ini.
                'simpangan_baku_g' => abs($baca['at_t1'] - $baca['at_t2']) / M_SQRT2,
                'jumlah_pengulangan' => 2,
                'budget' => $budget,
                'type_a_g' => $this->typeA($budget),
                'type_b_g' => $typeB / 1000,
                'ketidakpastian_gabungan_g' => $agregat['ketidakpastian_gabungan'] / 1000,
                'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
                'u95_g' => $agregat['ketidakpastian_diperluas'] / 1000,
                'mpe_mg' => $mpe,
            ];
        }

        usort($hasil, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($ditolak, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return [
            'boleh_terbit' => true,
            'rho_udara' => $rhoUdara,
            'lingkungan' => $lingkungan,
            'titik' => $hasil,
            'ditolak' => $ditolak,
        ];
    }

    /**
     * Massa jenis udara (kg/m³), OIML R111 Lampiran E.
     *
     * ```
     * ρ = ((0,34848·P) − (0,009·RH)·EXP(0,061·T)) / (T + 273,15)
     * ```
     *
     * Bukan CIPM-2007 penuh. Untuk kelas F1 dan U95 sebesar sekarang selisih
     * keduanya tidak terlihat; pertanyaan lab §14 memintanya ditulis di prosedur.
     *
     * Yang masuk rata-rata MENTAH kedua ujung — bukan yang sudah dikoreksi meter
     * lingkungan. Terbukti dari sesi contoh: 1,0909731524586854 kg/m³ hanya
     * reproduksi dengan T = 23,05, RH = 55,5, P = 933,15. Pertanyaan lab §13.
     */
    public function densitasUdara(float $suhuC, float $kelembabanPersen, float $tekananHpa): float
    {
        return ((0.34848 * $tekananHpa) - (0.009 * $kelembabanPersen) * exp(0.061 * $suhuC))
            / ($suhuC + 273.15);
    }

    /**
     * Enam komponen budget, seluruhnya MILIGRAM.
     *
     * @param  array<string, mixed>  $timbangan
     * @param  array<string, mixed>  $keping
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>
     */
    public function budget(array $timbangan, array $keping, float $rhoUut, float $rhoStd): array
    {
        $konstanta = TabelStandarAnakTimbangan::konstanta();
        $rect = (float) $konstanta['pembagi_rectangular'];
        $normal = (float) $konstanta['pembagi_normal'];
        $ganda = (float) $konstanta['faktor_resolusi_ganda'];
        $vi = $konstanta['vi'];

        $resolusiMg = (float) $timbangan['resolusi_g'] * 1000;

        return [
            [
                'sumber' => 'repeatability',
                'keterangan' => sprintf(
                    'Keterulangan %s, %s mg — sebaran enam simpangan baku harian '
                    .'(sheet `Deviasi Standard Timbangan`, pertanyaan lab §1)',
                    $timbangan['nama'],
                    self::angka((float) $timbangan['std_dev_mg']),
                ),
                'distribusi' => 't-student',
                'u' => (float) $timbangan['std_dev_mg'],
                'ci' => 1.0,
                'vi' => (float) $vi['repeatability'],
            ],
            [
                'sumber' => 'sertifikat_calibrator_at',
                'keterangan' => sprintf(
                    'Sertifikat keping standar %s g, U = %s mg, dibagi %s',
                    $keping['nominal_teks'],
                    self::angka((float) $keping['u_mg']),
                    self::angka($normal),
                ),
                'distribusi' => 'normal',
                'u' => (float) $keping['u_mg'] / $normal,
                'ci' => 1.0,
                'vi' => (float) $vi['sertifikat_calibrator_at'],
            ],
            [
                'sumber' => 'resolusi_timbangan_standard',
                'keterangan' => sprintf(
                    'Setengah resolusi %s (%s mg) dibagi %s, dikali %s karena resolusinya '
                    .'masuk dua kali — baca standar dan baca UUT (pertanyaan lab §18)',
                    $timbangan['nama'],
                    self::angka($resolusiMg / 2),
                    self::angka($rect),
                    self::angka($ganda),
                ),
                'distribusi' => 'rectangular',
                'u' => ($resolusiMg / 2) / $rect * $ganda,
                'ci' => 1.0,
                'vi' => (float) $vi['resolusi_timbangan_standard'],
            ],
            [
                'sumber' => 'instability',
                'keterangan' => sprintf(
                    'Drift keping standar %s g, %s mg',
                    $keping['nominal_teks'],
                    self::angka((float) $keping['drift_mg']),
                ),
                'distribusi' => 'rectangular',
                'u' => (float) $keping['drift_mg'],
                'ci' => 1.0,
                'vi' => (float) $vi['instability'],
            ],
            [
                'sumber' => 'bouyancy',
                'keterangan' => sprintf(
                    'Massa jenis udara ±%s kg/m³ dibagi %s; ci = (1/%s − 1/%s)·%s g. '
                    .'Dimensinya ditiru apa adanya dari master — pertanyaan lab §20',
                    self::angka((float) $konstanta['u_bouyancy_kg_m3']),
                    self::angka($rect),
                    self::angka($rhoUut),
                    self::angka($rhoStd),
                    self::angka((float) $keping['konvensional_g']),
                ),
                'distribusi' => 'rectangular',
                'u' => (float) $konstanta['u_bouyancy_kg_m3'] / $rect,
                'ci' => (1 / $rhoUut - 1 / $rhoStd) * (float) $keping['konvensional_g'],
                'vi' => (float) $vi['bouyancy'],
            ],
            [
                'sumber' => 'sensitivity',
                'keterangan' => sprintf(
                    'Sensitivitas %s, %s mg dibagi %s',
                    $timbangan['nama'],
                    self::angka((float) $timbangan['u_sens_mg']),
                    self::angka($rect),
                ),
                'distribusi' => 't-student',
                'u' => (float) $timbangan['u_sens_mg'] / $rect,
                'ci' => 1.0,
                'vi' => (float) $vi['sensitivity'],
            ],
        ];
    }

    /**
     * Ujung awal/akhir, rata-rata, selisih, dan ketidakpastian tercetak tiap
     * besaran lingkungan.
     *
     * `U95 = √(U95_meter² + Δ²)` dengan `Δ = |awal − akhir|` — diverifikasi
     * cocok di ketiga besaran sesi contoh (suhu 1,2041594578792296 °C, RH
     * 3,1622776601683795 %RH, tekanan 2,0024984394500795 hPa).
     *
     * Sertifikat master sendiri mencetak **3,0016662039607276 hPa** untuk
     * tekanan, yaitu `√(3² + 0,1²)` — angka 3 itu ketidakpastian KELEMBABAN
     * meternya, bukan tekanan (yang benar 2). Rujukan yang meleset satu kolom,
     * jadi dihitung benar di sini. Pertanyaan lab §7.
     *
     * @param  array<string, mixed>  $konteks
     * @return array<string, mixed>
     */
    public function lingkungan(array $konteks): array
    {
        $meter = (string) ($konteks['meter_lingkungan'] ?? '');
        $hasil = [];

        foreach (['suhu', 'kelembaban', 'tekanan'] as $besaran) {
            $awal = $konteks[$besaran]['awal'] ?? null;
            $akhir = $konteks[$besaran]['akhir'] ?? null;
            $rata = ($awal === null || $akhir === null) ? null : ((float) $awal + (float) $akhir) / 2;

            $indeks = ($rata === null || $meter === '')
                ? null
                : TabelStandarAnakTimbangan::titikIndeks($meter, $besaran, $rata);

            $delta = ($awal === null || $akhir === null) ? null : abs((float) $awal - (float) $akhir);
            $u95Meter = $indeks['u95'] ?? null;

            $hasil[$besaran] = [
                'awal' => $awal === null ? null : (float) $awal,
                'akhir' => $akhir === null ? null : (float) $akhir,
                'rata' => $rata,
                'delta' => $delta,
                'titik_indeks' => $indeks['standar'] ?? null,
                // Dihitung dan DISIMPAN, tapi tidak dipakai densitas udara —
                // persis seperti master. Pertanyaan lab §13.
                'koreksi_meter' => $indeks['koreksi'] ?? null,
                'u95_meter' => $u95Meter,
                'u95_tercetak' => ($u95Meter === null || $delta === null)
                    ? null
                    : sqrt($u95Meter ** 2 + $delta ** 2),
            ];
        }

        return $hasil;
    }

    /**
     * Type A sesi dalam GRAM — komponen keterulangan neraca, bukan sebaran
     * kedua penimbangan titik ini.
     *
     * Master memang mengambilnya dari sheet verifikasi harian neraca, bukan dari
     * sesi yang sedang dihitung. `simpangan_baku_g` per titik tetap disimpan
     * sebagai catatan, tapi dia tidak masuk budget.
     *
     * @param  list<array{sumber: string, u: float, ci: float, vi: float}>  $budget
     */
    private function typeA(array $budget): float
    {
        foreach ($budget as $k) {
            if ($k['sumber'] === 'repeatability') {
                return $k['u'] * $k['ci'] / 1000;
            }
        }

        return 0.0;
    }

    /**
     * Massa konvensional keping titik PERTAMA — satu-satunya tempat jalur
     * master yang cacat masih dipakai, dan cuma untuk dicatat di jejak audit.
     *
     * Titik pertama dicari lewat `titik_ke` TERKECIL, bukan elemen pertama
     * larik: jalur hitung ulang mengelompokkan per `titik_ke` lewat `groupBy`
     * dan urutannya tidak dijamin.
     *
     * @param  list<array{titik_ke: int, nominal_g: float}>  $titik
     */
    private static function msKepingPertama(array $titik): float
    {
        $pertama = null;

        foreach ($titik as $t) {
            if ($pertama === null || (int) $t['titik_ke'] < (int) $pertama['titik_ke']) {
                $pertama = $t;
            }
        }

        $keping = $pertama === null
            ? null
            : TabelStandarAnakTimbangan::cariKeping((float) $pertama['nominal_g']);

        return $keping === null ? 0.0 : $keping['konvensional_g'];
    }

    /**
     * Nominal ada di daftar, dibandingkan sebagai angka.
     *
     * `in_array` ketat menolak `0.2` yang lahir dari JSON request kalau bit-nya
     * beda setitik dari yang tersimpan, dan yang balik bukan error melainkan
     * keping kembar yang lolos tanpa `no_identitas`.
     *
     * @param  list<float>  $daftar
     */
    private static function adaDiDaftar(float $nilai, array $daftar): bool
    {
        foreach ($daftar as $x) {
            if (abs($x - $nilai) <= 1e-12 + 1e-6 * max(abs($x), abs($nilai))) {
                return true;
            }
        }

        return false;
    }

    private static function angka(float $nilai): string
    {
        $teks = rtrim(rtrim(number_format($nilai, 8, ',', ''), '0'), ',');

        return $teks === '' || $teks === '-' ? '0' : $teks;
    }

    private function gum(): GumCalculator
    {
        // Malas — `new GumCalculator` di parameter bawaan konstruktor melahirkan
        // lingkaran lewat `CalibrationProfileRegistry` yang gejalanya
        // "Maximum call stack size" jauh dari penyebabnya.
        return $this->gum ??= new GumCalculator;
    }
}
