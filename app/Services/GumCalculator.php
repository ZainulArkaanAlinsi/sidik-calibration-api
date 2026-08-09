<?php

namespace App\Services;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Perhitungan ketidakpastian ngikutin JCGM 100:2008 (GUM):
 * Type A + Type B → gabungan (u_c) → diperluas (U = k · u_c).
 *
 * Keputusan PASS/FAIL pakai *guarded acceptance* (ILAC-G8): alat baru lulus
 * kalau |error| DITAMBAH U masih masuk toleransi — bukan cuma |error| <=
 * toleransi. Bedanya kelihatan pas error mepet batas: alat yang errornya 0.047
 * dari toleransi 0.05 itu "lulus" versi simple acceptance, padahal ketidakpastian
 * pengukurannya sendiri udah nyebrang batas. Keputusan lab, 14 Jul.
 *
 * Semua angka di sini DISIMPAN ke `uncertainty_calculations`, nggak dihitung
 * ulang tiap dibaca — sertifikat 5 tahun lalu harus tetap nunjukin angka yang
 * sama walaupun rumusnya udah berubah.
 */
class GumCalculator
{
    /**
     * Faktor cakupan dikunci 2 (tingkat kepercayaan ~95%), ngikutin lampiran
     * akreditasi LK-285-IDN — bukan diambil dari tabel-t per sesi. Derajat
     * kebebasan efektif tetap dihitung & disimpen buat jejak audit.
     */
    public const FAKTOR_CAKUPAN = 2.0;

    /** Type A butuh sebaran; satu pembacaan doang nggak punya standar deviasi. */
    public const MIN_PENGULANGAN = 2;

    /**
     * Batas geser titik ukur dari titik nominal kemampuan (CMC) sebelum
     * dianggap BUKAN titik yang sama. Nilai sertifikat buffer/standar asli
     * di data pH cuma geser 0.009-0.021 dari nominalnya (3.99 vs 4, 6.9889
     * vs 7, 9.9789 vs 10) — 0.1 ngasih margin ~5x dari itu, jauh di bawah
     * jarak antar titik nominal (min. 3, buat pH 4 ke 7). Lihat
     * kemampuanUntukTitik(): titik ukur beneran BEDA (mis. 3.5) TIDAK boleh
     * ikut kepasangin CMC titik nominal terdekat cuma karena round()-nya
     * kebetulan sama.
     */
    private const MAX_DRIFT_TITIK_TUNGGAL = 0.1;

    /**
     * Cache kemampuan kalibrasi per `equipment_category_id`, seumur hidup
     * instance ini (satu request lewat DI, lihat CalibrationController) —
     * bukan cache lintas-request.
     *
     * @var array<int, Collection<int, CalibrationCapability>>
     */
    private array $kemampuanPerKategori = [];

    /**
     * Registry profil kalibrasi — nyediain DAFTAR komponen budget per jenis
     * alat. Opsional biar `new GumCalculator` di test lama tetap jalan; kalau
     * nggak dikasih, dibikin sendiri (konstruktornya cuma daftar profil,
     * nggak nyentuh DB).
     */
    private readonly CalibrationProfileRegistry $registry;

    public function __construct(?CalibrationProfileRegistry $registry = null)
    {
        $this->registry = $registry ?? new CalibrationProfileRegistry;
    }

    /**
     * Hitung satu titik ukur. Balikannya siap di-insert ke `uncertainty_calculations`.
     *
     * @param  list<float>  $pembacaan  hasil pengulangan di titik ini
     * @return array<string, mixed>
     */
    public function hitungTitik(
        int $titikKe,
        float $titikUkur,
        array $pembacaan,
        Equipment $equipment,
        Standard $standard,
        ?float $suhuLarutan = null,
        ?float $suhuRuang = null,
    ): array {
        // Nilai acuan yang dipakai itu nilai larutan PADA SUHU PENGUKURAN, bukan
        // angka nominal yang tercetak di botol.
        //
        // pH larutan buffer berubah sama suhu, dan sertifikat buffer-nya ngasih
        // kurvanya (kuadratik, disimpen di `standards.koefisien_suhu`). Buffer
        // "10.01" yang diukur pada 25,3 °C nilai benernya 9,9451681 — selisih
        // 0,065 pH dari nominalnya. Toleransi alat pH biasanya 0,2, jadi salah
        // di sini makan sepertiga anggaran toleransi dan bisa bikin alat yang
        // seharusnya FAIL kelihatan PASS (atau sebaliknya).
        //
        // Sebelum ini `titik_ukur` dipakai apa adanya dari yang diketik teknisi,
        // dan yang diketik teknisi itu nominal botol — jadi kolom "Correction"
        // di sertifikat kegeser sebesar koreksi suhu yang nggak pernah kepakai.
        // Dibandingin lembar olah data manual lab, tiga titik pH mismatch di
        // koreksi (-0,0038 vs -0,0096 · -0,0232 vs +0,0004 · -0,0554 vs +0,0094),
        // dua di antaranya sampai kebalik tanda.
        //
        // `?? $titikUkur` bikin ini aman buat semua yang bukan pH: standar tanpa
        // `koefisien_suhu` (atau titik tanpa suhu larutan) balik ke perilaku lama
        // persis, karena `nilaiPadaSuhu()` balikin null.
        $titikUkur = $standard->nilaiPadaSuhu($suhuLarutan) ?? $titikUkur;

        $n = count($pembacaan);

        // Satu pembacaan nggak punya sebaran. `standarDeviasiSampel()` balikin
        // 0.0 di kasus itu, dan 0 di sini artinya "pengulangannya sempurna" —
        // sertifikatnya bakal ngeklaim ketidakpastian lebih bagus dari yang bisa
        // dibuktiin. Salah yang diam-diam, dan justru di angka yang paling
        // dipercaya orang.
        //
        // Jalur API udah nyaring ini duluan (`alasanBelumBisaDihitung()`, yang
        // ngasih tahu teknisi KENAPA titiknya belum keluar). Yang dijaga di sini
        // pemanggil BARU — job, import, jalur AI Vision — yang gampang lupa
        // nyaring dan nggak bakal ketahuan salahnya karena nggak ada yang error.
        //
        // Jumlah pengulangan bebas: 2, 3, 5, berapa pun. Yang nggak boleh cuma 1.
        if ($n < self::MIN_PENGULANGAN) {
            throw new \InvalidArgumentException(sprintf(
                'Titik ke-%d cuma punya %d pembacaan, minimal %d — standar deviasi nggak bisa dihitung dari satu angka.',
                $titikKe,
                $n,
                self::MIN_PENGULANGAN,
            ));
        }

        $rataRata = array_sum($pembacaan) / $n;

        // STDEV dihitung dari pembacaan MENTAH, SEBELUM normalisasi suhu di
        // bawah — dan urutan ini nggak boleh dibalik. Normalisasi refractometer
        // dipasang ke rata-rata pakai SATU suhu rata-rata, jadi dia nggak nambah
        // sebaran; kalau tiap pembacaan dikoreksi sendiri-sendiri pakai suhunya
        // masing-masing, satu repeat yang suhunya nyeleneh bikin STDEV bukan nol
        // dan seluruh budget-nya meleset dari master lab.
        $standarDeviasi = $this->standarDeviasiSampel($pembacaan, $rataRata);

        // Type A — sebaran hasil pengulangan itu sendiri. Tetap dihitung &
        // disimpen buat audit/QC walaupun jalur CMC di bawah nggak makai
        // angka ini buat ketidakpastian yang dilaporkan.
        $typeA = $standarDeviasi / sqrt($n);

        // Daftar komponen budget-nya diserahin ke PROFIL alat (pH, Turbidimeter,
        // ...) — tiap alat punya bentuk budget beda, dan itu satu-satunya bagian
        // per-alat yang tersisa di sini. Aturan agregasi (Uc, Welch–Satterthwaite,
        // k, lantai CMC) tetap generik di bawah.
        $profil = $this->registry->untukAlat($equipment);

        // Pembacaan dinormalisasi ke suhu acuan alat. Cuma refractometer yang
        // ngelakuin sesuatu di sini (1,3362 @27 °C → 1,33935 @20 °C); profil
        // lain balikin angkanya apa adanya. Lihat
        // `CalibrationProfile::rataRataPadaSuhuAcuan()` soal kenapa ini beda
        // sisi dari `Standard::nilaiPadaSuhu()` di atas.
        //
        // Ditaruh SESUDAH STDEV & Type A, SEBELUM $error — biar nilai yang
        // ke-simpen sebagai `rata_rata` (dan kecetak di sertifikat sebagai
        // kolom "Unit Under Test") itu nilai terkoreksinya, dan `koreksi`
        // otomatis jadi `Standard − Corrected`.
        $rataRata = $profil->rataRataPadaSuhuAcuan($rataRata, $suhuLarutan, $equipment);

        $error = $rataRata - $titikUkur;
        $toleransi = (float) $equipment->toleransi;

        $kemampuan = $this->kemampuanUntukTitik($equipment, $titikUkur);

        $komponen = $kemampuan !== null
            ? $profil->komponenBudget($kemampuan, $equipment, $standard, $titikUkur, $typeA, $n, $suhuRuang)
            : null;

        // Tiga jalur, dari yang paling lengkap ke yang paling menyederhanakan.
        // Yang dipilih ditentuin DATA yang tersedia, bukan jenis alatnya:
        // profil yang konstanta budgetnya udah diturunkan dapat budget penuh,
        // yang belum tetap jalan lewat jalur lama tanpa berubah perilaku.
        if ($komponen !== null && $kemampuan !== null) {
            $hasil = $this->hitungDariBudget($komponen, $kemampuan, $typeA, $n);
        } elseif ($kemampuan !== null) {
            $hasil = $this->hitungDariKemampuan($kemampuan, $typeA, $n);
        } else {
            $hasil = $this->hitungDariStandarDanResolusi($typeA, $n, $equipment, $standard);
        }

        return [
            'standard_id' => $standard->id,
            'titik_ke' => $titikKe,
            'titik_ukur' => $titikUkur,
            'rata_rata' => $rataRata,
            'error' => $error,
            // Koreksi = lawan dari error: angka yang harus DITAMBAHKAN ke pembacaan
            // alat biar ketemu nilai benar. Ini yang dicetak di sertifikat, bukan error.
            'koreksi' => -$error,
            'standar_deviasi' => $standarDeviasi,
            'jumlah_pengulangan' => $n,
            'type_a' => $typeA,
            ...$hasil,
            'toleransi' => $toleransi,
            'keputusan' => $this->keputusan($error, $hasil['ketidakpastian_diperluas'], $toleransi),
            'calculated_at' => Carbon::now(),
        ];
    }

    /**
     * Jalur CMC: lab udah menyatakan ketidakpastian terbaiknya buat titik ini
     * di lampiran akreditasi. CMC dipakai sebagai **kontribusi lab + LANTAI**
     * yang dilaporkan — bukan sebagai angka jadi yang menggantikan sebaran ukur.
     *
     * ## Kenapa Type A WAJIB ikut
     *
     * Sampai 29 Juli 2026 method ini balikin CMC apa adanya dan Type A cuma
     * disimpen "buat QC". Akibatnya U95 di sertifikat SELALU sama persis buat
     * titik yang sama, berapa pun sebaran pembacaan teknisi — lima pembacaan
     * rapat dan lima pembacaan berantakan keluar angka identik.
     *
     * Itu bukan cuma bikin bingung; itu klaim yang salah. CMC adalah
     * ketidakpastian TERBAIK yang bisa dicapai lab pada kondisi optimal dengan
     * alat yang berperilaku normal (ILAC-P14). Dia nggak nyakup perilaku alat
     * PELANGGAN yang lagi dikalibrasi. Waktu pembacaan alat itu berserak jauh
     * lebih besar dari CMC — alat rusak, elektroda mau mati, larutan kotor —
     * ngelaporin ±CMC berarti nyatain presisi yang nggak pernah terjadi.
     *
     * Jadi: `u_c = sqrt(u_cmc² + u_A²)`, `U = k · u_c`, lalu **dilantai ke
     * CMC** supaya nggak pernah ngeklaim lebih baik dari kemampuan terakreditasi
     * lab. Sebaran yang rapat menghasilkan angka yang praktis sama kayak dulu;
     * yang berantakan sekarang kelihatan berantakan.
     *
     * @return array<string, mixed>
     */
    private function hitungDariKemampuan(
        CalibrationCapability $kemampuan,
        float $typeA,
        int $n,
    ): array {
        $k = $kemampuan->faktor_cakupan ?: self::FAKTOR_CAKUPAN;
        $cmcDiperluas = (float) $kemampuan->ketidakpastian_terbaik;
        $uCmc = $cmcDiperluas / $k;

        $gabungan = $this->akarJumlahKuadrat([$uCmc, $typeA]);

        // Lantai CMC: hasil hitung nggak boleh lebih kecil dari kemampuan
        // terakreditasi lab, walau pembacaannya kebetulan mulus banget.
        $diperluas = max($cmcDiperluas, $k * $gabungan);

        return [
            'type_b_components' => [[
                'sumber' => 'cmc_kemampuan_kalibrasi',
                'keterangan' => sprintf(
                    'CMC %s%s (U=%s %s, k=%s)',
                    $kemampuan->nama_alat,
                    $kemampuan->parameter ? " — {$kemampuan->parameter}" : '',
                    $cmcDiperluas,
                    $kemampuan->satuan_ketidakpastian,
                    $k,
                ),
                'distribusi' => 'normal',
                'nilai' => $uCmc,
            ]],
            'type_b' => $uCmc,
            'ketidakpastian_gabungan' => $gabungan,
            'faktor_cakupan_k' => $k,
            // Welch-Satterthwaite: cuma Type A yang punya derajat kebebasan
            // terbatas di sini; CMC dianggap tak-hingga (nilai tetap dari
            // akreditasi). Null kalau nggak ada pengulangan buat dihitung.
            'derajat_kebebasan_efektif' => $this->derajatKebebasanEfektif($gabungan, $typeA, $n),
            'ketidakpastian_diperluas' => $diperluas,
            // Nomor IK dari lampiran akreditasi — dicetak di sertifikat sebagai
            // "Calibration Method". Null kalau CMC ini nggak punya field metode.
            'metode' => $kemampuan->metode,
        ];
    }

    /**
     * Budget ketidakpastian PENUH gaya lampiran akreditasi (sheet
     * `PERHITUNGAN U95%`): komponennya dari PROFIL alat (lihat
     * `CalibrationProfile::komponenBudget`), digabung lewat `agregasiBudget`
     * (Welch-Satterthwaite + k t-student), lalu hasilnya dibandingin sama CMC —
     * yang DILAPORKAN yang LEBIH BESAR.
     *
     * Bedanya dari `hitungDariKemampuan`: yang itu nempel CMC apa adanya (buat
     * kemampuan yang budget-nya nggak dirinci). Yang ini beneran ngitung, jadi
     * sesi dengan suhu/pengulangan beda ngasih U yang beda — persis kayak
     * worksheet Excel-nya, cuma otomatis.
     *
     * DAFTAR komponennya milik profil (pH 5 komponen, Turbidimeter 4) — di sini
     * cuma agregasi & lantai CMC yang generik. Komponen pengulangan ditandai
     * `distribusi = 't-student'`; itu yang dipakai buat misahin Type A dari
     * Type B waktu ngitung `type_b`.
     *
     * @param  list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>  $komponen
     * @return array<string, mixed>
     */
    private function hitungDariBudget(
        array $komponen,
        CalibrationCapability $kemampuan,
        float $typeA,
        int $n,
    ): array {
        $agg = $this->agregasiBudget(array_map(
            fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $komponen,
        ));

        // Aturan akreditasi: lab nggak boleh ngeklaim ketidakpastian lebih baik
        // dari CMC-nya. Kalau U hitung < CMC, yang dilaporkan CMC-nya.
        $cmc = (float) $kemampuan->ketidakpastian_terbaik;
        $uHitung = $agg['ketidakpastian_diperluas'];
        $uFinal = max($uHitung, $cmc);

        // Jejak audit lengkap: tiap komponen bawa u baku, ci, vi, dan uici-nya.
        $komponenAudit = array_map(fn (array $k): array => [
            'sumber' => $k['sumber'],
            'keterangan' => $k['keterangan'],
            'distribusi' => $k['distribusi'],
            'nilai' => $k['u'] * $k['ci'],
            'u_baku' => $k['u'],
            'ci' => $k['ci'],
            'vi' => $k['vi'],
        ], $komponen);

        $komponenAudit[] = [
            'sumber' => 'perbandingan_cmc',
            'keterangan' => sprintf(
                'U hitung %.8f vs CMC %.8f → dilaporkan %s',
                $uHitung, $cmc, ($cmc > $uHitung) ? 'CMC' : 'hitung',
            ),
            'distribusi' => '-',
            'nilai' => $cmc,
        ];

        // type_b = RSS komponen Type B doang — semua yang BUKAN pengulangan
        // (Type A ditandai `distribusi = 't-student'`). Dulu di-slice 4 pertama
        // waktu budget selalu 5 komponen pH; sekarang jumlah komponen beda per
        // alat (Turbidimeter 4), jadi disaring by distribusi, bukan by posisi.
        $typeB = $this->akarJumlahKuadrat(
            array_map(
                fn (array $k): float => $k['u'] * $k['ci'],
                array_filter($komponen, fn (array $k): bool => $k['distribusi'] !== 't-student'),
            ),
        );

        return [
            'type_b_components' => $komponenAudit,
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $agg['ketidakpastian_gabungan'],
            'faktor_cakupan_k' => $agg['faktor_cakupan_k'],
            'derajat_kebebasan_efektif' => $agg['derajat_kebebasan_efektif'],
            'ketidakpastian_diperluas' => $uFinal,
            'metode' => $kemampuan->metode,
        ];
    }

    /**
     * Agregasi budget ketidakpastian gaya lampiran akreditasi (sheet
     * `PERHITUNGAN U95%` di workbook pH): tiap komponen bawa ketidakpastian baku
     * `u`, koefisien sensitivitas `ci`, dan derajat kebebasan `vi`.
     *
     *   uc     = √Σ(u·ci)²
     *   veff   = uc⁴ / Σ((u·ci)⁴ / vi)                (Welch–Satterthwaite)
     *   k      = t-student(97.5%, veff)               (CL 95% dua-sisi)
     *   U      = k · uc
     *
     * `k` DIHITUNG dari veff, bukan dikunci 2 — sertifikat pH asli pakai k dari
     * t-student (mis. 1.96857 buat veff 277.96). Beda tipis, tapi ikut ke angka
     * U yang dicetak.
     *
     * @param  list<array{u: float, ci: float, vi: float}>  $komponen
     * @return array{ketidakpastian_gabungan: float, derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float, ketidakpastian_diperluas: float}
     */
    public function agregasiBudget(array $komponen): array
    {
        $uici = array_map(fn (array $k): float => $k['u'] * $k['ci'], $komponen);

        $jumlahKuadrat = array_sum(array_map(fn (float $x): float => $x ** 2, $uici));
        $gabungan = sqrt($jumlahKuadrat);

        // Penyebut Welch-Satterthwaite. Komponen ber-vi <= 0 (mis. tak hingga)
        // atau ber-uici 0 nggak nyumbang — dilewati biar nggak bagi nol.
        $penyebutWs = 0.0;
        foreach ($komponen as $i => $k) {
            if ($k['vi'] > 0 && $uici[$i] !== 0.0) {
                $penyebutWs += ($uici[$i] ** 4) / $k['vi'];
            }
        }

        $veff = $penyebutWs > 0.0 ? ($jumlahKuadrat ** 2) / $penyebutWs : null;

        // Derajat kebebasan DIPOTONG ke bawah sebelum dicari t-nya.
        //
        // GUM G.4.1: kalau v_eff bukan bilangan bulat, ambil bilangan bulat
        // TERDEKAT KE BAWAH. Motong ke bawah = derajat kebebasan lebih kecil =
        // `k` lebih besar = U lebih besar. Itu arah yang aman: ketidakpastian
        // yang dilaporkan nggak boleh lebih kecil dari yang bisa
        // dipertanggungjawabkan.
        //
        // Bedanya nyata, bukan di desimal ke-10. Waktu Type A dominan,
        // v_eff-nya kecil dan pemotongannya kerasa:
        //
        //   v_eff 4.92 -> tepat  t=2.5830 -> U=0.1266  (kecetak "0,13")
        //   v_eff 4.92 -> potong t=2.7764 -> U=0.1361  (kecetak "0,14")
        //
        // Dicek lawan lembar manual lab: KEEMPAT nilai k di situ (1.96856,
        // 1.97066, 1.97076, 2.77645) cocok persis sama versi DIPOTONG, dan
        // nggak ada satu pun yang cocok sama versi tepat. Jadi ini bukan
        // "ngikutin Excel" — lembar labnya sendiri yang ngikutin GUM.
        //
        // Minimal 1: v_eff di bawah 1 nggak punya arti fisik, dan t(0.975, 0)
        // itu tak hingga.
        $k = $veff !== null
            ? (new StudentTDistribution)->quantile(0.975, max(1.0, floor($veff)))
            : self::FAKTOR_CAKUPAN;

        return [
            'ketidakpastian_gabungan' => $gabungan,
            'derajat_kebebasan_efektif' => $veff,
            'faktor_cakupan_k' => $k,
            'ketidakpastian_diperluas' => $k * $gabungan,
        ];
    }

    /**
     * Jalur generik yang udah ada dari awal: Type B dari sertifikat standar +
     * resolusi alat, digabung sama Type A sesi ini lewat RSS.
     *
     * @return array<string, mixed>
     */
    private function hitungDariStandarDanResolusi(float $typeA, int $n, Equipment $equipment, Standard $standard): array
    {
        $komponenTypeB = $this->komponenTypeB($equipment, $standard);
        $typeB = $this->akarJumlahKuadrat(array_column($komponenTypeB, 'nilai'));

        $gabungan = $this->akarJumlahKuadrat([$typeA, $typeB]);
        $diperluas = self::FAKTOR_CAKUPAN * $gabungan;

        return [
            'type_b_components' => $komponenTypeB,
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $gabungan,
            'faktor_cakupan_k' => self::FAKTOR_CAKUPAN,
            'derajat_kebebasan_efektif' => $this->derajatKebebasanEfektif($gabungan, $typeA, $n),
            'ketidakpastian_diperluas' => $diperluas,
            // Jalur generik nggak nunjuk ke CMC spesifik, jadi nggak ada nomor IK.
            'metode' => null,
        ];
    }

    /**
     * Kemampuan kalibrasi (CMC) yang dideklarasikan lab buat titik ini, kalau
     * ada. WAJIB `equipment->nama_alat_kemampuan` keisi — itu yang nunjuk baris
     * `CalibrationCapability` mana yang beneran punya JENIS alat yang sama
     * (mis. "Vernier Caliper"), bukan cuma kategori yang sama. Tanpa link ini,
     * balik `null` (jalur generik) — TIDAK nebak dari kategori doang.
     *
     * Kenapa link ini wajib: satu `equipment_category_id` (mis. "Panjang")
     * nampung banyak JENIS alat berbeda (Sieve, Micrometer, Vernier Caliper)
     * yang rentangnya suka tumpang tindih. Match cuma dari kategori + rentang
     * angka doang bikin jangka sorong toleransi 0.05mm kepasangin CMC Sieve
     * 4mm gara-gara sama-sama "Panjang" dan sama-sama nyakup 50mm — bug nyata
     * yang kejadian pas fitur ini pertama kali dicoba digeneralisir. Begitu
     * `nama_alat_kemampuan` mastiin jenis alatnya, ambiguitas itu ilang, dan
     * dua macam kemampuan di bawah ini aman dicocokkan:
     *
     * - Titik tunggal PRESISI (`range_min == range_max`, non-null — lihat
     *   `PhMeterCapabilitySeeder`): satu CMC buat satu titik ukur spesifik
     *   (mis. buffer pH 4/7/10), dari perhitungan budget ketidakpastian
     *   sendiri (bukan dibulatkan). Dicocokkan ke titik BULAT karena nilai
     *   sertifikat buffer/standar asli suka geser dikit per lot (mis. pH 3.99
     *   bukan 4.00 persis) — tapi selisihnya wajib di bawah
     *   `MAX_DRIFT_TITIK_TUNGGAL`, bukan asal "titik bulatnya sama" (round(3.5)
     *   kebetulan == 4 bukan berarti 3.5 itu "buffer 4 yang geser"). Ini
     *   PRIORITAS PERTAMA — dicek duluan sebelum titik tunggal generik.
     * - Titik tunggal GENERIK (`range_min` kosong — konvensi
     *   `kemampuan-kalibrasi.json` / `CalibrationCapabilitySeeder`, dibulatkan
     *   3 desimal): fallback kalau nggak ada baris presisi buat titik yang
     *   sama. Tanpa prioritas ini, `nama_alat` yang punya DUA baris buat titik
     *   bulat yang sama (satu generik dari lampiran akreditasi, satu presisi
     *   dari perhitungan GUM sendiri — persis kasus "pH Meter") bakal ambigu:
     *   `first()` polos milih siapa aja yang duluan ke-insert, biasanya yang
     *   generik, dan baris presisinya jadi nggak pernah kepake — bug nyata
     *   yang kejadian pas `PhMeterCapabilitySeeder` pertama kali ditambahin.
     * - Rentang kontinyu (`range_min < range_max` beneran, mis. jangka sorong
     *   0-300mm): satu CMC berlaku buat SELURUH rentang itu, dicocokkan
     *   dengan titik ukur ASLI (nggak dibulatkan) jatuh di dalamnya. Titik
     *   tunggal (presisi maupun generik) diprioritaskan lebih dulu kalau
     *   dua-duanya somehow cocok.
     *
     * Kandidat kemampuan per kategori di-cache di instance ini — satu sesi
     * kalibrasi bisa punya banyak titik ukur (hitungTitik dipanggil berkali-
     * kali di request yang sama), dan semuanya nunjuk ke `equipment` yang
     * sama, jadi kategorinya juga sama. Tanpa cache ini query-nya nge-N+1
     * satu kali per titik.
     */
    private function kemampuanUntukTitik(Equipment $equipment, float $titikUkur): ?CalibrationCapability
    {
        if ($equipment->equipment_category_id === null || $equipment->nama_alat_kemampuan === null) {
            return null;
        }

        $kategoriId = $equipment->equipment_category_id;

        $this->kemampuanPerKategori[$kategoriId] ??= CalibrationCapability::query()
            ->where('equipment_category_id', $kategoriId)
            ->get();

        $kandidat = $this->kemampuanPerKategori[$kategoriId]
            ->where('nama_alat', $equipment->nama_alat_kemampuan);

        // Dicocokin ke NILAI titik kemampuannya langsung (range_max), bukan ke
        // titik ukur yang dibulatkan ke integer. Versi lama pakai
        // `round($titikUkur)` — itu jebol buat titik pecahan di bawah 1: titik
        // 0,04 NTU dibulatin jadi 0, nggak match kemampuan 0,04, dan CMC-nya
        // nggak kepasang. Guard driftnya tetap: 3,5 nggak nyangkut ke buffer 4
        // (selisih 0,5 > 0,1), tapi buffer 10,01 tetap match titik 10 (0,01).
        $cocokTitikTunggal = fn (CalibrationCapability $k): bool => abs($titikUkur - (float) $k->range_max) <= self::MAX_DRIFT_TITIK_TUNGGAL;

        // Yang PALING DEKAT, bukan yang ketemu duluan.
        //
        // Dulu `->first(...)`, dan itu jebol begitu dua titik kemampuan jaraknya
        // lebih rapat dari MAX_DRIFT_TITIK_TUNGGAL. Chlorin Meter titiknya 1,74
        // & 1,83 — cuma 0,09 apart, di bawah toleransi 0,1 — jadi titik 1,83
        // nyangkut ke kemampuan 1,74 dan kepasang CMC 0,091, padahal punya dia
        // 0,08. Diam-diam: nggak ada error, cuma angka ketidakpastian yang salah
        // di sertifikat. pH (4/7/10) & Turbidimeter (1/100/1000) nggak pernah
        // kena karena titiknya berjauhan.
        $terdekat = fn (Collection $c): ?CalibrationCapability => $c
            ->sortBy(fn (CalibrationCapability $k): float => abs($titikUkur - (float) $k->range_max))
            ->first();

        // Presisi dulu (range_min == range_max, non-null), baru generik
        // (range_min null) — lihat penjelasan di docblock method ini.
        $titikTunggal = $terdekat($kandidat->filter(
            fn (CalibrationCapability $k): bool => $k->range_min !== null
                && (float) $k->range_min === (float) $k->range_max
                && $cocokTitikTunggal($k),
        )) ?? $terdekat($kandidat->filter(
            fn (CalibrationCapability $k): bool => $k->range_min === null && $cocokTitikTunggal($k),
        ));

        if ($titikTunggal !== null) {
            return $titikTunggal;
        }

        return $kandidat->first(
            fn (CalibrationCapability $k): bool => $k->range_min !== null
                && $k->range_min < $k->range_max
                && $titikUkur >= $k->range_min
                && $titikUkur <= $k->range_max,
        );
    }

    /**
     * Guarded acceptance: pita jaga selebar U dipotong dari batas toleransi.
     */
    private function keputusan(float $error, float $diperluas, float $toleransi): string
    {
        return abs($error) + $diperluas <= $toleransi ? 'PASS' : 'FAIL';
    }

    /**
     * Rincian komponen Type B, masing-masing udah dikonversi ke ketidakpastian
     * BAKU (u), bukan diperluas (U).
     *
     * Ini jebakan paling gampang kelewat: angka ketidakpastian di sertifikat
     * standar itu nilai DIPERLUAS (udah dikali k-nya sendiri, biasanya 2), jadi
     * harus dibagi balik sama k itu sebelum digabung. Kalau langsung dipakai
     * mentah, Type B jadi 2x lipat kegedean dan alat yang harusnya PASS ikut FAIL.
     *
     * @return list<array<string, mixed>>
     */
    private function komponenTypeB(Equipment $equipment, Standard $standard): array
    {
        $komponen = [];

        if ($standard->ketidakpastian !== null) {
            $k = $standard->faktor_cakupan ?: self::FAKTOR_CAKUPAN;

            $komponen[] = [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat standar %s (U=%s %s, k=%s)',
                    $standard->nama,
                    $standard->ketidakpastian,
                    $standard->satuan_ketidakpastian ?? '',
                    $k,
                ),
                'distribusi' => 'normal',
                'nilai' => $standard->ketidakpastian / $k,
            ];
        }

        if ($equipment->resolusi) {
            // Distribusi persegi: nilai benar bisa di mana aja dalam ±setengah
            // resolusi, jadi setengah-lebarnya a = resolusi/2, dibagi akar 3.
            $komponen[] = [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Resolusi alat %s %s', $equipment->resolusi, $equipment->satuan ?? ''),
                'distribusi' => 'persegi',
                'nilai' => ($equipment->resolusi / 2) / sqrt(3),
            ];
        }

        if ($standard->drift) {
            $komponen[] = [
                'sumber' => 'drift_standar',
                'keterangan' => sprintf('Drift standar %s per tahun', $standard->drift),
                'distribusi' => 'persegi',
                'nilai' => $standard->drift / sqrt(3),
            ];
        }

        return $komponen;
    }

    /**
     * Welch–Satterthwaite. Komponen Type B dianggap derajat kebebasannya tak
     * hingga (angkanya dari sertifikat standar, bukan dari pengulangan kita),
     * jadi cuma Type A yang nyumbang ke penyebut.
     *
     * `null` = tak hingga (nggak ada sebaran Type A yang bisa dihitung).
     */
    private function derajatKebebasanEfektif(float $gabungan, float $typeA, int $n): ?float
    {
        if ($typeA <= 0.0 || $n < 2) {
            return null;
        }

        return $gabungan ** 4 / ($typeA ** 4 / ($n - 1));
    }

    /**
     * Standar deviasi SAMPEL (pembagi n-1), bukan populasi (pembagi n). Pembacaan
     * kita cuma contoh dari semua kemungkinan pembacaan, bukan seluruh populasinya.
     *
     * @param  list<float>  $pembacaan
     */
    private function standarDeviasiSampel(array $pembacaan, float $rataRata): float
    {
        $n = count($pembacaan);

        if ($n < 2) {
            return 0.0;
        }

        $jumlahKuadratSelisih = array_sum(
            array_map(fn (float $x): float => ($x - $rataRata) ** 2, $pembacaan),
        );

        return sqrt($jumlahKuadratSelisih / ($n - 1));
    }

    /**
     * Gabung ketidakpastian: akar dari jumlah kuadrat. Ketidakpastian nggak
     * dijumlah lurus — komponennya saling bebas, jadi sebagiannya saling meniadakan.
     *
     * @param  list<float>  $nilai
     */
    private function akarJumlahKuadrat(array $nilai): float
    {
        return sqrt(array_sum(array_map(fn (float $u): float => $u ** 2, $nilai)));
    }
}
