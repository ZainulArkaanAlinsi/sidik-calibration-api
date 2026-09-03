<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;

/**
 * Profil Conductivity Meter (alat ke-5). Metode `SIDIK-IK-CAL-0507_Rev.6`;
 * ASTM D 1125-2023, perbandingan langsung dengan larutan standar.
 *
 * Sumber: `Master Olah Data_Conductivity.xlsm` (terenkripsi, dibuka 10 Agt 2026).
 * Sheet yang jadi acuan: `INPUT DATA`, `PERHITUNGAN`, `PERHITUNGAN U95%`,
 * `DATABASE`, `SERTIFIKAT STYLE 1/2`.
 *
 * ## TIGA titik, dan titik tengahnya berganti satuan
 *
 * Master punya EMPAT kolom pengukuran (F/H/J/L), tapi H dan J adalah standar
 * yang SAMA (Supelco/Merck LRAD9052) dibaca dalam dua satuan berbeda —
 * operator ngisi salah satu, dan pilihan itu yang nentuin style sertifikat
 * (catatan `INPUT DATA!K57`). Sheet budget mengonfirmasi: cuma ada TIGA blok
 * titik, dan blok ke-2 nyalain sumbernya sendiri —
 * `C21 = IF('INPUT DATA'!H39="", J37, H37)`.
 *
 * Jadi di sini: 3 titik, titik tengah punya dua varian satuan.
 *
 * ## Beda inti dari empat profil sebelumnya
 *
 *  - Nilai acuan larutan DIGESER IKUT SUHU lewat polinomial kuadrat per
 *    larutan (`Standard::nilaiPadaSuhu()`), bukan nominal apa adanya. Ini
 *    sisi yang sama kayak buffer pH — yang bergeser nilai acuannya, bukan
 *    pembacaan alatnya. Makanya [standarBerkurvaSuhu] `true` dan
 *    [rataRataPadaSuhuAcuan] TIDAK di-override.
 *  - Budget 4 komponen. Komponen suhunya **normal ÷2** (pH & turbidimeter:
 *    rect ÷√3), dengan `ci = (u_temperature/100) · titik` — sel W11/W25/W39
 *    sheet `PERHITUNGAN U95%`.
 *  - Resolusi & desimal cetak beda per titik: 0,1 µS/cm (1 desimal),
 *    1 µS/cm (0 desimal), 0,01 mS/cm (2 desimal) — sel INPUT DATA E17/G17/I17
 *    dan format sel `SERTIFIKAT STYLE 2` C35/C36/C41.
 *
 * ## Yang SENGAJA tidak diambil dari master
 *
 * Empat keputusan audit yang menyimpang dari isi sel, semuanya berpijak pada
 * bukti lain di dalam workbook yang sama. Lihat
 * `docs/audit-sumber-conductivity-refractometer.md`
 * buat dasar lengkapnya; ringkasnya:
 *
 *  - **E-2** `PERHITUNGAN!J34/J47` isinya `=AVERAGE(...)`, bukan selisih.
 *    Sel itu BUNTU — nggak ada sheet yang baca. Correction yang beneran
 *    nyampe sertifikat dihitung di `SERTIFIKAT!P40 = C40-J40`, yaitu
 *    `Standar − Rata-rata`. Pola itu yang dipakai, buat keempat kolom.
 *  - **E-4** slot standar ke-4 (`Conduct 80000`) dicentang TRUE tapi baris
 *    DATABASE-nya kosong, nggak punya kolom ukur, nggak punya CMC. Nggak
 *    dibikin titik.
 *  - **E-5** lihat [KOEF_SUHU] di bawah.
 *  - **Q3** master nggak punya satu pun sel yang mbandingin hasil sama batas
 *    toleransi, dan kedua sheet sertifikat cuma nyetak `Correction` + `U95%`
 *    lalu berhenti. Jadi alat ini NGGAK ngasih vonis lulus/gagal. Caranya
 *    bukan flag baru: alat conductivity diseed `toleransi = null`, dan
 *    `GumCalculator::keputusan()` balikin `null` buat toleransi kosong. Kalau
 *    lab nanti nerbitin batas keberterimaan di instruksi kerjanya, isi
 *    `equipments.toleransi` — nggak ada kode yang perlu disentuh.
 */
class ConductivityProfile extends CalibrationProfile
{
    /**
     * Kode FORMULIR lembar kerjanya, bukan kode metodenya.
     *
     * Sebelumnya di sini terisi `SIDIK-IK-CAL-0507_Rev.6` — itu nomor instruksi
     * kerja (IK), sementara `kode_dokumen` di bentuk lembar kerja dipakai
     * sebagai identitas FORMULIR (FM), sama kayak empat profil lain
     * (`SIDIK-FM-CAL-0509/0523/0530/0531`). Nomor formulir yang benar kebaca
     * dari cetakan aslinya, `SIDIK-FM-CAL-0510_Rev.5 - LEMBAR KERJA
     * CONDUCTIVITY.pdf` (footer kanan bawah + `Revise : 5`), yang baru masuk
     * 13 Agt 2026.
     */
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0510_Rev.5';

    /**
     * Metode kalibrasinya — TERCETAK di lembar kerja ("2. Calibration Methode :
     * SIDIK-IK-CAL-0507"), jadi teknisi memang melihatnya di kertas. Sengaja
     * dipisah dari [KODE_DOKUMEN] biar dua nomor yang beda jenis nggak saling
     * menimpa lagi.
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0507_Rev.6';

    public const JUMLAH_PENGULANGAN = 5;

    public const SATUAN_MIKRO = 'µS/cm';

    public const SATUAN_MILI = 'mS/cm';

    /**
     * Kepala kolom "Solution Standard" PERSIS seperti tercetak di
     * `SIDIK-FM-CAL-0510_Rev.5`: empat slot, tiga di antaranya punya kotak
     * ceklis µS/mS.
     *
     * ## Kenapa labelnya nggak sama dengan titik yang dihitung
     *
     * Label di kertas itu nominal botol lama. Master pindah ke tiga titik pada
     * **3 Apr 2024** (`01 - FORM VALIDASI.csv` #8: *"Change point ukur menjadi
     * 3 (25 uS, 1412 uS, dan 111 mS)"*), sementara PDF formulirnya dibuat
     * 15 Des 2023 dan belum ikut direvisi. Sheet `INPUT DATA` master sendiri
     * masih menamai centangnya `Conduct 84/1413/5000/80000` sambil menunjuk ke
     * tiga larutan yang sekarang — jadi kertas DAN master sama-sama menyimpan
     * nama slot lama.
     *
     * `titik` di bawah = titik yang BENERAN dihitung untuk slot itu, dibaca
     * dari pemetaan master. Layar menampilkan dua-duanya (nama slot di kertas
     * + larutan yang sebenarnya) supaya teknisi nggak menuang botol yang salah
     * gara-gara kepala kolomnya nominal lama.
     *
     * Slot keempat `titik` = `null`: `Conduct 80000` dicentang TRUE di master
     * tapi baris DATABASE-nya kosong — nggak punya nilai acuan, CMC, maupun
     * kurva suhu (audit E-4 di docblock kelas). Digambar seperti di kertas,
     * tapi mati.
     *
     * @var list<array{label: string, varian: string|null, titik: float|null}>
     */
    public const SLOT_CETAK = [
        ['label' => '84', 'varian' => null, 'titik' => 25.0],
        ['label' => '1413 µS', 'varian' => '1.413 mS', 'titik' => 1412.0],
        ['label' => '5000 µS', 'varian' => '5 mS', 'titik' => 111.0],
        ['label' => '80000 µS', 'varian' => '80 mS', 'titik' => null],
    ];

    /**
     * Titik ukur, dalam satuan NATIVE-nya masing-masing (dua titik pertama
     * µS/cm, titik ketiga mS/cm) — persis kayak master, yang emang nyampur
     * satuan dalam satu lembar.
     *
     * `varian_mili` di titik tengah = nilai yang sama dibaca dalam mS/cm.
     * Titik itu punya DUA bentuk; yang kepakai tergantung satuan pembacaan
     * alat yang dipilih di sesi (lihat [titikUntukSatuan]).
     *
     * @var list<array{nilai: float, satuan: string, resolusi: float, desimal: int, varian_mili: array{nilai: float, satuan: string, resolusi: float, desimal: int}|null}>
     */
    public const TITIK = [
        [
            'nilai' => 25.0,
            'satuan' => self::SATUAN_MIKRO,
            'resolusi' => 0.1,
            'desimal' => 1,
            'varian_mili' => null,
        ],
        [
            'nilai' => 1412.0,
            'satuan' => self::SATUAN_MIKRO,
            'resolusi' => 1.0,
            'desimal' => 0,
            'varian_mili' => [
                'nilai' => 1.412,
                'satuan' => self::SATUAN_MILI,
                'resolusi' => 0.001,
                'desimal' => 3,
            ],
        ],
        [
            'nilai' => 111.0,
            'satuan' => self::SATUAN_MILI,
            'resolusi' => 0.01,
            'desimal' => 2,
            'varian_mili' => null,
        ],
    ];

    /**
     * Polinomial kurva suhu tiap larutan standar, `y = a·T² + b·T + c`, dalam
     * satuan native titiknya. Diseed ke `standards.koefisien_suhu` dan dipakai
     * `Standard::nilaiPadaSuhu()` — yang kebetulan MEMANG udah kuadrat, jadi
     * nggak ada mekanisme baru yang perlu dibikin.
     *
     * Asal tiap baris:
     *
     *  - **25 µS/cm** → sheet `nilai koefisien sensitifitas` A23 nyatain
     *    kurvanya `y = 25`. Konstan; larutan ini emang dianggap nggak
     *    bergantung suhu. Ditulis eksplisit `a=0, b=0, c=25` biar niatnya
     *    kebaca, bukan dibiarin `null` yang artinya "datanya belum diisi".
     *  - **1412 µS/cm** → sheet yang sama A39: `y = 2E-13x² + 27x + 738`.
     *  - **111 mS/cm** → sheet yang sama A55: `y = 0,0042x² + 1,732x + 64,88`,
     *    dan dieksekusi beneran di `PERHITUNGAN!L26`.
     *
     * ## E-5 — 25 & 1412 µS/cm DIKUNCI KONSTAN, atas arahan lab
     *
     * Riwayatnya perlu dibaca utuh, karena nilainya sempat bolak-balik:
     *
     * `PERHITUNGAN!H26` isinya angka ketik `1412`, tanpa koreksi suhu. Larutan
     * yang PERSIS SAMA di kolom sebelahnya (`J26`, varian mS/cm) dikoreksi
     * pakai polinomial `2E-13·T² + 27·T + 738`, dan sheet
     * `nilai koefisien sensitifitas` A39 mendokumentasikan polinomial itu tepat
     * di bawah judul "DR 1412 μS/cm". Satu botol, dua perlakuan, di satu
     * workbook.
     *
     * 10 Agt 2026 polinomialnya sempat dipakai — nilai acuan jadi 1410,84 pada
     * 24,92 °C, Correction −2,16. 11 Agt 2026 arahan lab masuk dan
     * MEMBATALKANNYA: **nilainya dikunci dulu, jangan diubah-ubah**, sambil
     * rumus yang benar buat titik ini dicari. Yang tetap dipakai cuma rumus
     * `0,0042·T² + 1,732·T + 64,88` buat titik 111 mS/cm — itu yang diminta
     * "ikutin aja".
     *
     * Jadi jangan "dirapikan" lagi biar konsisten. Ketidakkonsistenannya nyata
     * dan sudah diketahui lab; menutupnya sendiri berarti mengarang rumus buat
     * larutan yang rumusnya memang belum diputuskan.
     *
     * Begitu lab ngasih rumusnya, ganti baris `1412` di sini — nggak ada tempat
     * lain yang perlu disentuh, dan `ConductivitySeeder` ikut otomatis karena
     * dia baca konstanta yang sama.
     *
     * @var array<string, array{a: float, b: float, c: float}>
     */
    public const KOEF_SUHU = [
        '25' => ['a' => 0.0, 'b' => 0.0, 'c' => 25.0],
        '1412' => ['a' => 0.0, 'b' => 0.0, 'c' => 1412.0],
        '111' => ['a' => 0.0042, 'b' => 1.732, 'c' => 64.88],
    ];

    /**
     * Polinomial yang master DOKUMENTASIKAN buat larutan 1412 µS/cm tapi cuma
     * dia pakai di varian mS/cm (`PERHITUNGAN!J26`), sheet
     * `nilai koefisien sensitifitas` A39.
     *
     * Disimpen — bukan dibuang — supaya angkanya nggak hilang waktu lab
     * memutuskan rumus final buat titik ini. TIDAK dipakai menghitung apa pun.
     */
    public const KOEF_SUHU_1412_TERDOKUMENTASI = ['a' => 2.0e-13, 'b' => 27.0, 'c' => 738.0];

    /**
     * Baris tabel STANDARD di lembar kerja: 3 larutan Supelco/Merck (DATABASE
     * R13/R14/R15) + PT100 dan termometer & sensor (S18/R17). Urutannya ngikut
     * cetakan `SIDIK-FM-CAL-0510_Rev.5`, yang naruh PRT PT100 di atas.
     *
     * `label_cetak` = tulisan di kertas, `label` = nama alat standar yang
     * sebenarnya di master. Dua-duanya dikirim karena beda: kertas masih
     * menulis nominal botol lama (84/1413/5000) dan readout lama
     * ("Victor 14+", sekarang Yokogawa/CA 150 Handy Cal S/N 23P1005). Yang
     * dicentang teknisi tetap alat yang benar; yang dibacanya tetap tulisan
     * yang ada di kertas depan mata.
     *
     * `Conduct 80000` sengaja NGGAK ada — lihat E-4 di docblock kelas.
     *
     * @var list<array{label: string, label_cetak: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Conductivity Std Solution 25 µS/cm', 'label_cetak' => 'Std Solution 84 µS', 'cocok' => ['Conductivity Std Solution 25 µS/cm', 'LRAD7693']],
        ['label' => 'Conductivity Std Solution 1412 µS/cm', 'label_cetak' => 'Std Solution 1413 µS', 'cocok' => ['Conductivity Std Solution 1412 µS/cm', 'LRAD9052']],
        ['label' => 'Conductivity Std Solution 111 mS/cm', 'label_cetak' => 'Std Solution 5000 µS', 'cocok' => ['Conductivity Std Solution 111 mS/cm', 'HC56824055']],
        ['label' => 'PT100/SH1', 'label_cetak' => 'PRT PT100', 'cocok' => ['PT100/SH1', 'SH1/20', 'PT100']],
        ['label' => 'Termometer & Sensor Std.', 'label_cetak' => 'Victor 14+', 'cocok' => ['Termometer & Sensor Std.', '23P1005']],
    ];

    /**
     * Semua unit thermohygro lab. Alasan daftarnya utuh (bukan dipersempit)
     * sama persis kayak yang ditulis panjang di `TurbidimeterProfile`: pernah
     * dipersempit, dan sertifikat master malah pakai unit yang nggak ada di
     * daftar. Master conductivity sendiri pakai TH-6.
     */
    public const THERMOHYGRO_TERCETAK = [
        ['label' => 'TH-1', 'grup' => 'Inlab'],
        ['label' => 'TH-3', 'grup' => 'Inlab'],
        ['label' => 'TH-4', 'grup' => 'Inlab'],
        ['label' => 'TH-5', 'grup' => 'Inlab'],
        ['label' => 'TH-2', 'grup' => 'Insitu'],
        ['label' => 'TH-6', 'grup' => 'Insitu'],
        // TH-7 pindah ke Insitu KHUSUS lembar ini: cetakan
        // `SIDIK-FM-CAL-0510_Rev.5` menaruhnya di baris Insitu bareng TH-2 &
        // TH-6, sementara tiga profil lain masih menaruhnya di Inlab. Yang
        // dipilih teknisi tetap `standard_id` yang sama — ini murni soal di
        // baris mana kotaknya tercetak. Kalau lab menyatakan Inlab yang benar,
        // baris ini yang dibalik, bukan daftar di profil lain.
        ['label' => 'TH-7', 'grup' => 'Insitu'],
    ];

    public function kode(): string
    {
        return 'conductivity_meter';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Conductivitymeter';
    }

    /**
     * Lampiran akreditasi nulis "Conductivitymeter" (satu kata, lihat
     * [namaAlatKemampuan]) sementara sertifikat & lembar kerjanya nulis
     * "Conductivity Meter" (dua kata). Alasannya sama kayak Chlorin/Chlorine:
     * yang nyampe ke sini teks bebas, bukan enum.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Conductivity Meter'];
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_CONDUCTIVITY;
    }

    public function besaran(): string
    {
        return 'conductivity';
    }

    /**
     * Nilai acuan larutan bergeser ikut suhu (polinomial [KOEF_SUHU]), jadi
     * `koefisien_suhu` yang NULL di master standar conductivity beneran
     * berarti "datanya bolong" dan pantas di-flag validator.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return true;
    }

    /**
     * Pasangan tercetak titik → larutan. Titik tengah didaftarin dua kali
     * (varian µS/cm dan mS/cm) karena dua-duanya nunjuk botol yang sama.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return [
            ['titik' => 25.0, 'standar' => ['Conductivity Std Solution 25 µS/cm', 'LRAD7693']],
            ['titik' => 1412.0, 'standar' => ['Conductivity Std Solution 1412 µS/cm', 'LRAD9052']],
            ['titik' => 1.412, 'standar' => ['Conductivity Std Solution 1412 µS/cm', 'LRAD9052']],
            ['titik' => 111.0, 'standar' => ['Conductivity Std Solution 111 mS/cm', 'HC56824055']],
        ];
    }

    public function resolusiTitik(float $titikUkur): ?float
    {
        return $this->titikTerdekat($titikUkur)['resolusi'];
    }

    public function desimalTitik(float $titikUkur): ?int
    {
        return $this->titikTerdekat($titikUkur)['desimal'];
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = $this->bentukLengkap($equipment);
        $bentuk = $this->tautkanStandar($bentuk, $equipment);
        $bentuk = $this->tautkanStandarTitik($bentuk, $equipment);
        $bentuk = $this->isiPilihanThermohygro($bentuk, $equipment);

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
     * Budget 4 komponen conductivity buat satu titik — sheet `PERHITUNGAN U95%`
     * blok baris 7–20 (25 µS/cm), 21–34 (1412 µS/cm), 35–47 (111 mS/cm).
     *
     * Balik `null` kalau `u_temperature` belum keisi di kemampuannya, biar
     * jatuh ke jalur CMC lama — bukan ngarang angka. Sama polanya kayak
     * turbidimeter & chlorine.
     *
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>|null
     */
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
        if ($kemampuan->u_temperature === null) {
            return null;
        }

        $sqrt3 = sqrt(3);
        $kStandar = $standard->faktor_cakupan ?: 2.0;
        $uTemperature = (float) $kemampuan->u_temperature;
        // Satuan & resolusi diambil dari ALAT PELANGGAN dulu — dua-duanya
        // menggambarkan apa yang beneran tampil di layar alatnya. Baru jatuh
        // ke bawaan profil kalau alatnya belum diisi rinci.
        $satuan = $this->satuanTitik($titikUkur, $equipment);

        // Urutannya penting: baris RINCI punya alat menang, lalu bawaan profil,
        // baru `resolusi` tunggal. `resolusi` tunggal itu angka kasar — kalau
        // dia mendahului, titik 25 µS/cm kepakai resolusi 0,01 (punya mS/cm)
        // dan Uc-nya jatuh dari 0,2534 ke 0,0296.
        $baris = $this->barisAlat($equipment, $titikUkur);

        $resolusi = (isset($baris['resolusi']) ? (float) $baris['resolusi'] : null)
            ?? $this->resolusiTitik($titikUkur)
            ?? (float) $equipment->resolusi;

        // U95% sertifikat larutan disimpen dalam satuan native larutannya
        // (µS/cm buat dua yang pertama, mS/cm buat yang ketiga). Titik tengah
        // varian mS/cm baca botol yang sama, jadi U-nya dibagi 1000 —
        // sel `M23 = IF(I21="mS/cm", DATABASE!V14/1000, DATABASE!V14)`.
        $uStandar = ($standard->ketidakpastian ?? 0.0) / $kStandar;

        if ($satuan === self::SATUAN_MILI && $standard->satuan_ketidakpastian === self::SATUAN_MIKRO) {
            $uStandar /= 1000.0;
        }

        // Koefisien sensitivitas suhu: ci = (u_temperature/100)·titik — sel
        // W11/W25/W39.
        //
        // Titik yang dipakai itu NILAI NOMINAL botolnya, BUKAN nilai yang udah
        // dikoreksi suhu. Master ngambilnya langsung dari sheet input —
        // `C35 = 'INPUT DATA'!L37` = 111, padahal nilai acuan terkoreksi di
        // baris yang sama 111,193568. Sama buat dua titik lain (C7 = F37 = 25,
        // C21 = H37 = 1412).
        //
        // `$titikUkur` yang masuk ke sini SUDAH terkoreksi suhu (GumCalculator
        // ngegantinya lewat `Standard::nilaiPadaSuhu()` sebelum manggil profil),
        // jadi kalau dipakai mentah, ci titik 111 keluar 0,401685 — bukan
        // 0,4009850994737834 kayak master. Makanya disnap balik ke nominal.
        $ciSuhu = ($uTemperature / 100.0) * $this->titikTerdekat($titikUkur)['nilai'];

        // Catatan audit yang sengaja dibiarkan berdiri, bukan dibetulin:
        // `u_temperature` muncul DUA KALI di komponen ini — di `u` lewat ÷2,
        // dan di `ci` lewat /100 — jadi `u·ci` sebanding sama
        // `u_temperature²`. Menurut GUM, `ci` semestinya turunan parsial
        // ∂(konduktivitas)/∂T dan nggak bergantung ketidakpastian termometer
        // sama sekali. Nggak diubah: master itu source of truth, dan sertifikat
        // yang udah terbit pakai rumus ini. Kalau suatu saat ada asesmen KAN
        // yang nanya asal-usul `ci`, ini titik yang bakal ditanyain.

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat kalibrator %s (U=%s %s, k=%s)',
                    $standard->nama,
                    $standard->ketidakpastian,
                    $standard->satuan_ketidakpastian ?? $satuan,
                    $kStandar,
                ),
                'distribusi' => 'normal',
                'u' => $uStandar,
                'ci' => 1.0,
                'vi' => 200,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat %s %s (titik %s)', $resolusi, $satuan, $titikUkur),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000,
            ],
            [
                'sumber' => 'ketidakpastian_temperature',
                'keterangan' => sprintf(
                    'UTemperature %s °C (÷2), ci (%s/100)·%s',
                    $uTemperature,
                    $uTemperature,
                    $titikUkur,
                ),
                'distribusi' => 'normal',
                'u' => $uTemperature / 2.0,
                'ci' => $ciSuhu,
                'vi' => 200,
            ],
            [
                'sumber' => 'pengulangan_pembacaan',
                'keterangan' => sprintf('Pengulangan %d pembacaan (Type A)', $n),
                'distribusi' => 't-student',
                'u' => $typeA,
                'ci' => 1.0,
                'vi' => max($n - 1, 1),
            ],
        ];
    }

    /**
     * Satuan titik ini — dari ALAT PELANGGAN kalau dia punya, baru jatuh ke
     * bawaan profil.
     *
     * Arahan lab 11 Agt 2026: sertifikat ikut satuan yang tampil di alat
     * pelanggan, bukan format yang kita patok. Alat conductivity ganti satuan
     * sendiri di ambang yang beda-beda per merk — larutan 1412 bisa kebaca
     * `1412 µS/cm` di satu alat dan `1,412 mS/cm` di alat lain, dua-duanya
     * benar. Yang menentukan: baris resolusi alat yang diisi waktu input
     * spesifikasi (`equipments.resolusi_rentang`, kunci `satuan`).
     *
     * Tanpa `$equipment`, balik ke bawaan profil — dipakai pemanggil yang cuma
     * butuh bentuk lembar kerja kosong, sebelum alatnya dipilih.
     */
    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): string
    {
        $satuan = $this->barisAlat($equipment, $titikUkur)['satuan'] ?? null;

        if (is_string($satuan) && in_array($satuan, [self::SATUAN_MIKRO, self::SATUAN_MILI], true)) {
            return $satuan;
        }

        return $this->titikTerdekat($titikUkur)['satuan'];
    }

    /**
     * Conductivity nyetak koreksi yang membulat ke nol TANPA minus — kebalikan
     * dari empat alat lain. Lihat docblock induknya buat kenapa ini setelan per
     * alat dan bukan aturan umum.
     *
     * Titik 25 µS/cm di master: Standard `25`, UUT `25,04`, Correction
     * `-0.03999999999999915` di selnya, tapi yang KECETAK `0,0` (1 desimal,
     * ngikut resolusi 0,1 µS/cm). Angka mentahnya sama persis kayak yang
     * dihitung sistem — yang beda cuma format selnya.
     *
     * Dua titik lain nggak kena: koreksinya nggak membulat ke nol (`-1` dan
     * `0,52`), jadi override ini cuma nyentuh baris yang kolom Correction-nya
     * emang nol di kertas.
     */
    public function tandaNolDicetak(): bool
    {
        return false;
    }

    /**
     * Style sertifikat buat sesi ini — DITURUNKAN dari satuan tiap titik,
     * bukan dipilih orang.
     *
     *  - **style 1** — 25 µS/cm · 1412 µS/cm · 111 mS/cm
     *  - **style 2** — 25 µS/cm · 1,412 mS/cm · 111 mS/cm
     *  - **style 3** — semuanya mS/cm
     *
     * ## Nomornya SENGAJA kebalik dari nama sheet di master
     *
     * Sheet `SERTIFIKAT STYLE 1` di workbook nyetak titik tengah dalam mS/cm
     * (sel `C40 = PERHITUNGAN!J39`), dan `STYLE 2` nyetaknya dalam µS/cm
     * (`C36 = H39`) — jadi label di file itu kebalikan dari yang dipakai di
     * sini.
     *
     * Itu bukan kekeliruan baca. Lab sendiri yang bilang label di file-nya
     * salah ("coba lihat style satu ini salah, harusnya mikrosimen"), lalu
     * menegaskan urutan yang benar: opsi pertama µS·µS·mS, opsi kedua
     * µS·mS·mS. Penomoran di sini ngikut lab, karena itu yang dipakai ngomong
     * sehari-hari dan yang nyampe ke pelanggan — keputusan 11 Agt 2026.
     *
     * Yang perlu diingat waktu ngadu hasil sistem sama cetakan Excel lama:
     * ANGKANYA sama persis, cuma nomor style-nya yang ketuker.
     *
     * @param  list<float>  $titikUkur  titik yang beneran diukur di sesi ini
     */
    /**
     * Nilai acuan dari polinomial suhu selalu keluar dalam satuan NATIVE
     * botolnya. Titik tengah bisa dibaca dua satuan dari botol yang sama, jadi
     * varian mS/cm harus dibagi 1000.
     *
     * Sisi kembarannya udah lama ada di `komponenBudget()` buat KETIDAKPASTIAN
     * botol — sel `M23 = IF(I21="mS/cm", DATABASE!V14/1000, DATABASE!V14)`.
     * Yang kelewat cuma NILAI ACUANNYA, dan itu nggak pernah ketahuan karena
     * jalur mS/cm belum pernah dijalanin satu sesi pun: sesi contoh lab
     * (`2405.32.A.NK`) baca titik tengah dalam µS/cm.
     *
     * Tanpa ini, sesi varian mS/cm nyimpen `titik_ukur = 1412` di sebelah
     * pembacaan `1,413`, dan kolom Correction sertifikat keluar `+1410,587`
     * mS/cm — bukan `-0,001`.
     *
     * Yang dijaga di sini:
     *  - Cuma titik TENGAH yang kena. Titik 111 emang native mS/cm (botol &
     *    polinomialnya sama-sama mS/cm), jadi nggak boleh ikut dibagi.
     *  - Syarat botolnya masih µS/cm bikin ini mati sendiri kalau suatu saat
     *    lab nyimpen botol 1412 dalam mS/cm — nggak kebagi dua kali.
     */
    public function faktorKanonik(float $titikUkur, Equipment $equipment): float
    {
        $tengah = self::TITIK[1]['varian_mili'] ?? null;

        if ($tengah === null) {
            return 1.0;
        }

        // CUMA titik tengah yang punya dua varian. Titik 111 emang native
        // mS/cm — botol, polinomial, dan baris CMC-nya sama-sama mS/cm — jadi
        // dia udah kanonik dan nggak boleh ikut dinaikin.
        $variannya = abs($titikUkur - $tengah['nilai'])
            <= $tengah['nilai'] * self::TOLERANSI_PASANGAN_TITIK;

        // 1 mS/cm = 1000 µS/cm. Kanoniknya µS/cm, karena itu yang dipakai
        // master Excel lab dan yang baris CMC titik tengah ditulis.
        return $variannya ? 1000.0 : 1.0;
    }

    /**
     * Peringatan sesi: titik tengah kebaca dalam **mS/cm**.
     *
     * Titik tengah punya dua bentuk yang mewakili botol yang SAMA — 1412 µS/cm
     * dan 1,412 mS/cm. Yang µS/cm itu yang diadu ke master Excel dan cocok
     * angka per angka. Yang mS/cm belum pernah ketemu sesi nyata di master;
     * jalurnya kebentuk dari aturan lab ("sertifikat ikut satuan yang tampil di
     * alat pelanggan"), bukan dari lembar yang pernah dihitung tangan.
     *
     * Jadi bukan salah — cuma belum pernah dibuktikan. Admin berhak tau itu
     * SEBELUM sertifikatnya terbit, dan tetap boleh lanjut.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $tengah = self::TITIK[1]['varian_mili'] ?? null;

        if ($tengah === null) {
            return [];
        }

        $pakaiMili = $sesi->uncertaintyCalculations->contains(
            fn ($titik): bool => abs((float) $titik->titik_ukur - $tengah['nilai'])
                <= $tengah['nilai'] * self::TOLERANSI_PASANGAN_TITIK,
        );

        if (! $pakaiMili) {
            return [];
        }

        return [[
            'kode' => 'conductivity_titik_tengah_mili',
            'pesan' => 'Titik tengah sesi ini kecatat dalam '.self::SATUAN_MILI
                .' ('.$tengah['nilai'].'), bukan '.self::SATUAN_MIKRO.' ('.self::TITIK[1]['nilai'].'). '
                .'Angkanya diproses dengan rumus yang sama, tapi jalur satuan ini belum pernah '
                .'diadu ke sesi nyata di master Excel. Periksa sekali lagi sebelum sertifikatnya terbit.',
        ]];
    }

    public function styleSertifikat(array $titikUkur, ?Equipment $equipment = null): int
    {
        $satuan = array_map(
            fn (float $t): string => $this->satuanTitik($t, $equipment),
            $titikUkur,
        );

        if ($satuan !== [] && ! in_array(self::SATUAN_MIKRO, $satuan, true)) {
            return 3;
        }

        // Titik tengah kebaca mS/cm — nilainya ~1,412, bukan ~1412.
        foreach ($titikUkur as $t) {
            if (abs($t - 1.412) < 0.05) {
                return 2;
            }
        }

        return 1;
    }

    /**
     * Peringatan buat admin kalau sesi ini makai varian mS/cm titik tengah —
     * jalur yang di master NGGAK PERNAH kepakai dan rumusnya bermasalah.
     *
     * Balik `null` kalau sesinya nggak nyentuh varian itu.
     *
     * Dipasang sebagai PERINGATAN, bukan penghalang keras, ngikutin idiom yang
     * udah ada di `approve()`: admin bisa lanjut sadar-sadar lewat
     * `abaikan_peringatan: true`. Ngeblok total bakal ngilangin fitur yang
     * mungkin emang lab butuh; diem aja bakal nerbitin angka dari rumus yang
     * satu-satunya jejaknya di master adalah nilai ngaco.
     *
     * @param  list<float>  $titikUkur
     */
    public function peringatanVarianMili(array $titikUkur): ?string
    {
        if ($this->styleSertifikat($titikUkur) !== 2) {
            return null;
        }

        return 'Titik tengah dibaca dalam mS/cm (sertifikat style 1). Di master Excel, jalur '
            .'ini nggak pernah keisi sama sekali — kolomnya kosong, dan satu-satunya angka '
            .'yang pernah dia keluarin 0,738 mS/cm, hasil polinomial suhu dievaluasi di T=0 '
            .'karena kolom suhunya kosong. Dua cacat master di jalur ini udah dibetulin: '
            .'Correction pakai selisih (bukan =AVERAGE(...)), dan nilai acuannya dikoreksi '
            .'suhu pakai polinomial yang sama persis dengan varian µS/cm, jadi satu botol '
            .'nggak lagi dinilai beda gara-gara satuan. Yang tersisa: jalur ini belum pernah '
            .'diadu sama sesi nyata. Periksa angkanya sebelum approve.';
    }

    /**
     * Titik kalibrasi thermohygro yang paling dekat ke nilai terukur.
     *
     * ## E-6 / Q4 — kenapa "terdekat", dan kenapa itu bukan tebakan
     *
     * Master ngambil koreksi thermohygro lewat `VLOOKUP(H16, Index_suhuTH, 3, 0)`
     * — pencocokan PERSIS. Tapi `H16` bukan hasil hitungan: dia diketik
     * operator, dan di sesi contoh isinya 29,75 padahal suhu ruang terukur
     * 25,45 °C. Nggak ada satu pun formula di workbook yang ngehubungin dua
     * angka itu, jadi aturannya emang nggak tertulis.
     *
     * Yang bisa dibuktikan: nilai yang diketik SELALU salah satu titik
     * kalibrasi TH yang dipakai, dan selalu yang paling dekat. TH-6 punya titik
     * suhu {14,64 · 19,33 · 29,75 · 39,34 · 49,95} — terdekat ke 25,45 adalah
     * 29,75 (selisih 4,30 lawan 6,12 ke 19,33). ✓ cocok pilihan operator.
     * Titik RH-nya {28,67 · 46,83 · 67,23 · 76,79 · 86,52} — terdekat ke 54,5
     * adalah 46,83 (7,67 lawan 12,73). ✓ cocok juga.
     *
     * Dua-duanya kena, jadi "titik terdekat" mereproduksi pilihan manual
     * operator tanpa perlu nebak. Itu yang dipakai sebagai nilai awal.
     *
     * Admin tetap bisa nimpa lewat dropdown — pilihannya DIBATASI ke titik
     * kalibrasi TH yang kepilih, karena VLOOKUP-nya exact match: isian bebas
     * cuma bisa ngasih dua hasil, angka yang bener atau `#N/A`.
     *
     * @param  list<float>  $titikKalibrasi  standard indication dari sertifikat TH
     */
    public static function titikIndeksTerdekat(float $terukur, array $titikKalibrasi): ?float
    {
        if ($titikKalibrasi === []) {
            return null;
        }

        $terdekat = $titikKalibrasi[0];

        foreach ($titikKalibrasi as $t) {
            if (abs($t - $terukur) < abs($terdekat - $terukur)) {
                $terdekat = $t;
            }
        }

        return $terdekat;
    }

    /**
     * Conductivity Meter nggak divonis PASS/FAIL — Q3. `toleransi` NULL di
     * alat ini artinya "emang nggak ada", bukan "belum diisi", jadi validator
     * nggak boleh nahan penerbitan gara-gara itu.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * µS/cm → mS/cm buat pengecekan rentang alat.
     *
     * Lembar ini nyampur satuan: titik 25 & 1412 dicatat dalam µS/cm, sementara
     * rentang alatnya `0–100 mS/cm`. Tanpa konversi, pembacaan 1413 µS/cm
     * ke-flag "jauh di luar rentang — kemungkinan komanya kegeser", padahal
     * 1413 µS/cm = 1,413 mS/cm, jelas di dalam rentang.
     *
     * Faktor 1000-nya bukan pengetahuan umum yang saya bawa dari luar — master
     * makai konversi yang sama persis di tiga tempat: `M23` (U kalibrator
     * `DATABASE!V14/1000`), `AB20`, dan `AB34` (U95% µS/cm → mS/cm).
     */
    public function nilaiDalamSatuanAlat(float $nilai, ?string $satuanTitik, Equipment $equipment): float
    {
        $satuanAlat = trim((string) $equipment->satuan);

        if ($satuanTitik === self::SATUAN_MIKRO && $satuanAlat === self::SATUAN_MILI) {
            return $nilai / 1000.0;
        }

        if ($satuanTitik === self::SATUAN_MILI && $satuanAlat === self::SATUAN_MIKRO) {
            return $nilai * 1000.0;
        }

        return $nilai;
    }

    /**
     * Master Conductivity: sel `T14` di kedua sheet sertifikat format `0.0`
     * → `25,8`. Baris kelima buat tabel di [CalibrationProfile::desimalSuhuEnv].
     */
    public function desimalSuhuEnv(): ?int
    {
        return 1;
    }

    /** Master Conductivity: sel `T15` format `0` → `51` (nilai 51,33). */
    public function desimalKelembabanEnv(): ?int
    {
        return 0;
    }

    /**
     * Berapa desimal yang masuk akal buat resolusi ini: `0,1`→1, `1`→0,
     * `0,01`→2, `0,001`→3.
     *
     * Diturunkan, bukan diseed terpisah — kalau admin ngisi resolusi alat
     * `0,001 mS/cm`, jumlah desimal cetaknya HARUS ikut, bukan nyangkut di
     * angka bawaan profil. Nulis lebih banyak desimal dari yang bisa dibaca
     * alatnya itu ngaku-ngaku presisi yang nggak ada.
     */
    private function desimalDariResolusi(float $resolusi): int
    {
        if ($resolusi <= 0.0) {
            return 0;
        }

        // 8 = batas presisi kolom `decimal(20, 8)`; lebih dari itu nggak bisa
        // disimpen, jadi nggak ada gunanya dicetak.
        for ($d = 0; $d <= 8; $d++) {
            if (abs($resolusi * (10 ** $d) - round($resolusi * (10 ** $d))) < 1e-9) {
                return $d;
            }
        }

        return 8;
    }

    /**
     * Label titik buat tampilan: nol belakang dibuang HANYA di bagian desimal.
     */
    private function labelTitik(float $nilai, int $desimal): string
    {
        $label = number_format($nilai, $desimal, '.', '');

        return str_contains($label, '.') ? rtrim(rtrim($label, '0'), '.') : $label;
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukLengkap(?Equipment $equipment = null): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            // Tercetak di kertas sebagai "2. Calibration Methode :
            // SIDIK-IK-CAL-0507" — teknisi melihatnya, tapi nggak mengisinya.
            // `calibration_method_id` tetap `hanya_admin` karena yang dipilih
            // admin itu BARIS master metode, bukan teks ini.
            'kode_metode' => self::KODE_METODE,
            'judul' => 'Calibration Worksheet - Conductivity Meter',
            'jumlah_pengulangan' => self::JUMLAH_PENGULANGAN,
            'larutan_standar' => array_map(fn (array $t): float => $t['nilai'], self::TITIK),
            // Alat ini nyampur satuan dalam SATU lembar — dua titik pertama
            // µS/cm, titik ketiga mS/cm. `satuan` tunggal nggak cukup, jadi
            // yang mengikat itu `satuan` per baris tabel hasil.
            'satuan' => null,
            'satuan_campuran' => true,
            // Suhu larutan WAJIB kalau pembacaannya diisi — dan itu dikirim
            // sebagai BENDERA, bukan dibiarin layar nebak dari nama alat.
            //
            // Keempat alat sama-sama punya kolom `suhu` di tabelnya, jadi ada
            // atau nggaknya kolom nggak mbedain apa-apa. Yang mbedain: nilai
            // acuan Conductivity DIGESER ikut suhu, sementara Turbidimeter &
            // Chlorine dibaca nominal — suhunya dicatat tapi nggak masuk
            // hitungan. Tanpa bendera ini frontend cuma bisa mbedainnya lewat
            // `if (profil == conductivity)`, dan itu bakal diulang tiap alat
            // baru sampai alat ke-48.
            'suhu_wajib' => true,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Suhu larutan WAJIB diisi buat tiap titik yang pembacaannya diisi — '
                .'nilai acuan larutan digeser ikut suhu, jadi titik tanpa suhu nggak bisa dihitung. '
                .'Titik yang datanya belum cukup nggak ikut dihitung, dan sertifikatnya baru bisa '
                .'terbit sesudah dilengkapi admin.',
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
                            ['nilai' => 'lab', 'label' => 'Inlab'],
                            ['nilai' => 'onsite', 'label' => 'Insitu'],
                        ]),
                        // Kolom teks bebas nama tempat buat sesi Insitu. Tanpa
                        // dia sertifikat Insitu kecetak nama RUANG LAB — tempat
                        // yang alatnya nggak pernah ke sana.
                        $this->field('lokasi_nama', 'Nama Tempat (Insitu)', 'teks', tampilKalau: self::TAMPIL_KALAU_INSITU),
                        $this->field(
                            'room_id',
                            'Ruangan (Inlab)',
                            'pilihan',
                            sumber: 'master_ruangan',
                            tampilKalau: self::TAMPIL_KALAU_INLAB,
                        ),
                        $this->field(
                            'calibration_method_id',
                            '2. Calibration Methode',
                            'pilihan',
                            sumber: 'master_metode',
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
                    'tabel' => [
                        $this->tabelHasil('sebelum_adjustment', 'Before adjustment Reading', $equipment),
                        $this->tabelHasil('sesudah_adjustment', 'After adjustment Reading', $equipment),
                    ],
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
     * Satu tabel hasil. Baris = titik standar; titik tengah muncul DUA KALI
     * (varian µS/cm dan mS/cm) karena master emang nyediain dua kolom buat
     * botol yang sama, dan teknisi ngisi salah satu. Yang keisi nentuin style
     * sertifikatnya.
     *
     * @return array<string, mixed>
     */
    private function tabelHasil(string $tahap, string $judul, ?Equipment $equipment = null): array
    {
        $baris = [];

        foreach (self::TITIK as $t) {
            // ALATNYA UDAH DIKENAL → satu baris per titik, satuannya ngikut
            // yang tampil di layar alat pelanggan.
            //
            // Ini inti arahan lab 11 Agt 2026: "nggak ada memilih-milih lagi,
            // disesuaikan sama input data di resolusi alat". Baris titik tengah
            // nggak lagi dikirim dua-duanya buat dipilih teknisi — yang dikirim
            // yang benar buat alat ini, titik.
            $dariAlat = $this->barisAlat($equipment, $t['nilai']);
            $satuanAlat = $dariAlat['satuan'] ?? null;

            if (is_string($satuanAlat) && in_array($satuanAlat, [self::SATUAN_MIKRO, self::SATUAN_MILI], true)) {
                $v = ($satuanAlat === self::SATUAN_MILI && $t['varian_mili'] !== null)
                    ? $t['varian_mili']
                    : $t;

                $resolusi = isset($dariAlat['resolusi']) ? (float) $dariAlat['resolusi'] : $v['resolusi'];

                $baris[] = [
                    'titik_ukur' => $v['nilai'],
                    'label' => $this->labelTitik($v['nilai'], $this->desimalDariResolusi($resolusi)),
                    'satuan' => $satuanAlat,
                    'resolusi' => $resolusi,
                    'desimal' => $this->desimalDariResolusi($resolusi),
                    'eksklusif_dengan' => null,
                ];

                continue;
            }

            // Alatnya belum dipilih (template generik) → kirim dua-duanya, dan
            // layar yang saling mengunci lewat `eksklusif_dengan`.
            $varian = [$t];

            if ($t['varian_mili'] !== null) {
                $varian[] = $t['varian_mili'];
            }

            foreach ($varian as $v) {
                $baris[] = [
                    'titik_ukur' => $v['nilai'],
                    'label' => $this->labelTitik($v['nilai'], $v['desimal']),
                    'satuan' => $v['satuan'],
                    'resolusi' => $v['resolusi'],
                    'desimal' => $v['desimal'],
                    // Dua baris titik tengah saling meniadakan: begitu salah
                    // satu diisi, yang lain dikunci layar. Tanpa penanda ini,
                    // teknisi bisa ngisi dua-duanya dan sistem punya dua nilai
                    // buat satu botol.
                    'eksklusif_dengan' => $t['varian_mili'] !== null
                        ? ($v['satuan'] === self::SATUAN_MILI ? $t['nilai'] : $t['varian_mili']['nilai'])
                        : null,
                ];
            }
        }

        return [
            'tahap' => $tahap,
            'judul' => $judul,
            'baris' => $baris,
            // Di kertas Repeat 1..5 jalan KE BAWAH dan larutan berjajar ke
            // samping — kebalikan lembar pH (`SIDIK-FM-CAL-0509`) yang selama
            // ini jadi satu-satunya bentuk yang digambar layar. Dikirim sebagai
            // data biar layar nggak nebak orientasi dari nama alat.
            'sumbu_pengulangan' => 'baris',
            'slot_cetak' => $this->slotCetak($baris),
            'kolom' => [
                // Label satuan dikosongin di level kolom — tiap BARIS bawa
                // satuannya sendiri (µS/cm atau mS/cm).
                ['kode' => 'pembacaan', 'label' => 'Reading', 'tipe' => 'angka', 'satuan' => null],
                ['kode' => 'suhu', 'label' => '°C', 'tipe' => 'angka', 'satuan' => '°C'],
            ],
            'pengulangan' => range(1, self::JUMLAH_PENGULANGAN),
        ];
    }

    /**
     * Kepala kolom seperti tercetak, disambungin ke baris yang beneran ada.
     *
     * `titik_ukur` sengaja diambil dari `$baris`, bukan dari [SLOT_CETAK]:
     * titik tengah bisa keluar sebagai `1412` (µS/cm) atau `1,412` (mS/cm)
     * tergantung satuan alat pelanggan, dan slot harus nunjuk ke baris yang
     * beneran dikirim. Slot yang nggak ketemu barisnya balik `titik_ukur:
     * null` — layar menggambarnya mati, bukan menghapusnya, karena kotaknya
     * ada di kertas.
     *
     * Slot yang barisnya dikirim DUA-DUANYA (template generik: `1412 µS/cm`
     * dan `1,412 mS/cm`) balik dua `titik_ukur` — satu slot di kertas, satu
     * kotak ceklis, dan layar yang saling mengunci lewat `eksklusif_dengan`
     * yang udah ada di barisnya.
     *
     * @param  list<array<string, mixed>>  $baris
     * @return list<array{label: string, varian: string|null, titik_ukur: list<float>, satuan: string|null, resolusi: float|null, desimal: int|null}>
     */
    private function slotCetak(array $baris): array
    {
        $hasil = [];

        foreach (self::SLOT_CETAK as $slot) {
            // Nilai yang sah buat slot ini: titik nominalnya + varian mS/cm-nya
            // kalau ada. Slot tanpa titik (80000) nggak punya kandidat sama
            // sekali, jadi loop di bawah nggak ketemu apa-apa dan slotnya
            // keluar kosong — persis yang dimau.
            $sah = [];

            foreach (self::TITIK as $t) {
                if ($slot['titik'] === null || $t['nilai'] !== $slot['titik']) {
                    continue;
                }

                $sah[] = $t['nilai'];

                if ($t['varian_mili'] !== null) {
                    $sah[] = $t['varian_mili']['nilai'];
                }
            }

            $cocok = array_values(array_filter(
                $baris,
                fn (array $b): bool => in_array($b['titik_ukur'], $sah, true),
            ));

            $hasil[] = [
                'label' => $slot['label'],
                'varian' => $slot['varian'],
                'titik_ukur' => array_map(fn (array $b): float => (float) $b['titik_ukur'], $cocok),
                // Resolusi & satuan dibaca dari baris PERTAMA yang cocok. Kertas
                // cuma punya satu baris "Resolusi: ( )" per slot, dan dua varian
                // satuan nggak pernah keisi berbarengan.
                'satuan' => $cocok[0]['satuan'] ?? null,
                'resolusi' => $cocok[0]['resolusi'] ?? null,
                'desimal' => $cocok[0]['desimal'] ?? null,
            ];
        }

        return $hasil;
    }

    /**
     * Cocokin baris STANDARD tercetak ke master `standards` lab (nama/serial).
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanStandar(array $bentuk, ?Equipment $equipment = null): array
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
                        // Tulisan yang ada di kertas depan mata teknisi. Beda
                        // dari `label` (nama alat standar di master) karena
                        // formulir cetaknya masih pakai nominal & merk lama.
                        'label_cetak' => $baris['label_cetak'],
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
    private function isiPilihanThermohygro(array $bentuk, ?Equipment $equipment = null): array
    {
        $master = Standard::query()
            ->whereNotNull('parameter_kondisi')
            // Disaring ke lab pemilik alat: dropdown yang menawarkan
            // termohigrometer lab lain bikin koreksi kondisi lingkungan
            // dibaca dari sertifikat lab itu, lalu kecetak di sertifikat
            // lab ini.
            ->when(
                $equipment?->organization_id !== null,
                fn ($q) => $q->where('organization_id', $equipment->organization_id),
            )
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

    /**
     * Baris "Resolusi Alat" milik ALAT PELANGGAN buat titik ini — resolusi +
     * satuan yang beneran tampil di layar alatnya.
     *
     * ## Kenapa dikunci ke TITIK, bukan ke ambang angka
     *
     * `equipments.resolusi_rentang` aslinya dipakai Turbidimeter dengan kunci
     * `maks` (ambang numerik: 0–10 NTU resolusi 0,01, dst). Cara itu JEBOL di
     * conductivity, karena satuannya campur: titik `111 mS/cm` secara ANGKA
     * lebih kecil daripada `1412 µS/cm` (111 < 1412), padahal secara fisik
     * hampir 100× lebih besar. Titik 111 nyangkut ke band "sampai 1412" dan
     * kebaca µS/cm.
     *
     * Jadi di sini kuncinya `titik` — nilai nominal larutannya. Itu juga yang
     * lebih cocok sama lembar aslinya: `INPUT DATA` E16/G16/I16 emang tiga
     * kotak BERLABEL ("Std 25 µS/cm", "Std 1412 µS/cm", "Std 111 mS/cm"),
     * bukan tiga rentang.
     *
     * Baris ber-`maks` (punya Turbidimeter) diabaikan di sini, jadi dua bentuk
     * itu bisa hidup berdampingan di kolom yang sama tanpa saling ganggu.
     *
     * @return array{titik?: float, resolusi?: float, satuan?: string}
     */
    private function barisAlat(?Equipment $equipment, float $titikUkur): array
    {
        // Band MENTAH, bukan lewat `satuanPada()`: method itu jatuh ke
        // `equipments.satuan` borongan waktu bandnya nggak nyebut satuan, dan
        // buat alat bersatuan campur itu bikin titik 25 µS/cm ikut kebaca
        // mS/cm — satuan borongan alat contoh ini emang mS/cm.
        return $equipment?->bandResolusi($titikUkur) ?? [];
    }

    /**
     * Baris [TITIK] yang paling dekat ke titik ukur, varian mS/cm titik tengah
     * ikut dipertimbangkan.
     *
     * @return array{nilai: float, satuan: string, resolusi: float, desimal: int}
     */
    private function titikTerdekat(float $titikUkur): array
    {
        $kandidat = [];

        foreach (self::TITIK as $t) {
            $kandidat[] = [
                'nilai' => $t['nilai'],
                'satuan' => $t['satuan'],
                'resolusi' => $t['resolusi'],
                'desimal' => $t['desimal'],
            ];

            if ($t['varian_mili'] !== null) {
                $kandidat[] = $t['varian_mili'];
            }
        }

        // Dibandingin RELATIF, bukan selisih mutlak: titik 1,412 dan 25 beda
        // tiga orde besaran, jadi selisih mutlak bakal selalu milih yang
        // kecil-kecil buat nilai yang deket nol.
        $terdekat = $kandidat[0];
        $skor = static fn (array $k): float => abs($k['nilai'] - $titikUkur) / max(abs($k['nilai']), 1e-9);

        foreach ($kandidat as $k) {
            if ($skor($k) < $skor($terdekat)) {
                $terdekat = $k;
            }
        }

        return $terdekat;
    }

    /**
     * U95 dicetak PER TITIK, bukan satu angka buat seluruh tabel.
     *
     * Permintaan pemilik lab (Pak Rohman, 3 Sep 2026) buat kelompok
     * `instrumen-analitik`. Diukur dulu sebelum disetel — U95 tiap titik di
     * sesi contoh: 0,499 / 8,109 / 1,7 µS/cm.
     *
     * Selisihnya 16 kali lipat antar titik. Diringkas jadi satu baris, dua dari
     * tiga angka hilang dari dokumen.
     *
     * Faktor cakupannya SENGAJA nggak ikut dikunci ([faktorCakupanTetap] tetap
     * `null`): `k` di sini lahir per titik juga, jadi judul kolom `k=2` bakal
     * jadi pernyataan yang salah. Yang tercetak `U95% (±)`, dan `k`-nya
     * dilaporkan lengkap di kalimat di bawah tabel — preseden Gas Detector.
     *
     * Sertifikat yang SUDAH terbit nggak ikut berubah sendiri: bentuk cetaknya
     * dibekukan ke `snapshot['u95_per_titik']` waktu terbit.
     */
    public function u95PerTitik(): bool
    {
        return true;
    }
}
