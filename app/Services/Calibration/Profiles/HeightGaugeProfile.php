<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\HeightGaugeCalculator;
use App\Services\Calibration\TabelStandarHeightGauge;
use App\Support\HeightGaugeMentah;
use Carbon\Carbon;

/**
 * Lembar & hitungan **Height Gauge 600 mm** — alat ke-26, kelompok Panjang.
 *
 * Satu workbook master (`Master_olda_Height_Gauge_600_mm_2026.xlsm`, password
 * `spirit285`). Angkanya dibuktikan lebih dulu di Python lalu dijaga
 * `HeightGaugeMasterTest` sel demi sel — kesepuluh koreksi, kesembilan komponen
 * budget, dan kelima angka agregat, toleransi 5·10⁻⁶.
 *
 * ## Alat ini DI LUAR lampiran akreditasi — dan itu menentukan banyak hal
 *
 * Height Gauge tidak ada di LK-285-IDN. Kelompok Panjang di
 * `database/data/kemampuan-kalibrasi.json` cuma memuat Sieve, Micrometer,
 * Vernier Caliper, dan Dial Indicator. Master pun mengakuinya: sel lantai CMC
 * (`AA19`) KOSONG, jadi `U95 = U` telanjang.
 *
 * Konsekuensinya tiga, dan ketiganya sengaja:
 *
 *  1. `namaAlatKemampuan()` tetap `Height Gauge`, dan namanya masuk
 *     `CmcSemuaProfilTest::DILUAR_LAMPIRAN` berikut alasannya — bukan dipaksa
 *     cocok ke salah satu nama lampiran yang ada.
 *  2. Baris kemampuan tetap DIBUAT (`HeightGaugeCapabilitySeeder`, CMC nol),
 *     mengikuti preseden Gas Detector: tanpa baris itu jalur budget penuh
 *     tidak jalan dan `GumCalculator` jatuh ke `hitungDariStandarDanResolusi()`.
 *  3. [bentukLembarKerja] TIDAK memasang `nomor_lingkup`. Empat profil lain
 *     memasang `LK-285-IDN` di kop lembarnya; mencetak nomor lingkup untuk alat
 *     yang tidak diakreditasi itu temuan audit KAN, bukan bug tampilan.
 *
 * ### Sertifikatnya TIDAK membawa klaim akreditasi
 *
 * Sampai 7 Sep 2026 dia membawanya, dan itu bukan kelalaian lembar ini: klaim
 * akreditasi dicetak TANPA SYARAT di tingkat organisasi, tanpa satu pun
 * pemeriksaan alat. Gas Detector sudah kena hal yang sama sejak alat ke-10.
 *
 * Sekarang bersyarat lewat [dalamLingkupAkreditasi], yang dibekukan ke snapshot
 * oleh `CertificateSnapshotBuilder` dan dibaca blade. Kop banner
 * (`kop-surat.png`) ikut disetop untuk sesi di luar lingkup — nomor
 * `LK-285-IDN` tercetak DI DALAM gambarnya, jadi menyembunyikan kop teks saja
 * tidak menghilangkan klaimnya.
 *
 * Yang MASIH menunggu lab: sertifikat kedua alat yang **sudah terbit** tetap
 * membawa klaim lamanya, karena snapshot-nya beku dan cetak ulang membaca
 * snapshot. Itu keputusan mutu, bukan keputusan kode — lihat
 * `docs/pertanyaan-lab-height-gauge.md` §6.
 *
 * ## Ketidakpastian lahir per SESI, bukan per titik
 *
 * Sheet `PERHITUNGAN U95%` cuma punya satu kolom, dan sertifikatnya mencetak
 * satu baris `Uncertainty U95% = ±` di bawah sepuluh titik. Makanya
 * [hitungPerGrup] yang dipakai dan [komponenBudget] memulangkan `null` — bukan
 * karena belum ditulis, tapi karena bentuk per titik memang tidak ada di alat
 * ini. Pengulangannya sendiri tidak bisa dinyatakan per titik: dia datang dari
 * blok `Evaluation` (sepuluh pembacaan berulang di nominal kapasitas), bukan
 * dari sebaran tiga pembacaan tiap titik.
 *
 * ## Budgetnya mm, BUKAN µm
 *
 * `AF18 = I5 = "mm"`. [MicrometerProfile] membagi 1000 di ujung tulis karena
 * budget-nya µm; di sini TIDAK ADA konversi apa pun. Ini jebakan yang paling
 * gampang kelewat kalau lembar itu dipakai sebagai contekan, dan tidak ada
 * lantai CMC yang bakal menahan akibatnya.
 *
 * ## Tiga blok yang TIDAK sebangun
 *
 * Yang paling membedakan lembar ini dari 25 saudaranya: satu sesi punya tiga
 * blok pengukuran dan cuma satu di antaranya berbentuk titik ukur.
 *
 *  1. **Paralelisme Ujung Scriber** — tiga pembacaan, tingkat SESI. Catatan
 *     kelulusan di kaki sertifikat, bukan titik ukur.
 *  2. **Evaluation** — sepuluh pembacaan berulang, tingkat SESI. Satu-satunya
 *     sumber Repeatability.
 *  3. **Measurement** — sepuluh titik ber-nominal PRA-CETAK.
 *
 * Blok 1 dan 2 tinggal di `spesifikasi_alat.height_gauge`, bukan sebagai
 * `titik_ke` — lihat [HeightGaugeMentah::blokSesi].
 *
 * ## `offset_kunci` ketiga tabel itu WAJIB, bukan kerapian
 *
 * Ketiganya ber-`tahap` sama, jadi kunci barisnya bisa bertabrakan di layar.
 * Tabrakan seperti itu sudah nyata di Timbangan (Accuracy 50 kg vs
 * Repeatability Middle 50 kg): angka yang diketik di satu kotak muncul di kotak
 * lain, tanpa satu pun error. Di sini akibatnya lebih mahal — baris `Evaluasi`
 * yang tertimpa membuat Repeatability lahir dari angka titik ukur, dan U95
 * SELURUH sesi ikut salah. Paralelisme dan Evaluasi karena itu diberi offset
 * yang berbeda dan jauh di atas 600 mm (nominal terbesar lembar ini).
 *
 * ## Yang menyimpang dari kertas, dan sengaja
 *
 * Dua, sama seperti Micrometer: kotak Inlab/Insitu (permintaan 2 memintanya
 * bisa dipilih di semua lembar) dan dropdown Thermohygro
 * (`ThermohygroSemuaLembarTest` menuntutnya terisi dari master lab, sementara
 * kertas mencetak `TH-1` mati).
 *
 * Satu lagi khas lembar ini: **Kerataan Muka Ukur jadi SATU field pilihan**,
 * bukan dua centang. Master memakai dua checkbox dan di sesi contoh dua-duanya
 * `TRUE` sekaligus (`INPUT DATA!Y22` dan `Y23`) — dua boolean yang saling
 * meniadakan itu bentuk yang tidak bisa divalidasi.
 */
class HeightGaugeProfile extends CalibrationProfile
{
    /** Satuan lembar, sertifikat, DAN budget — ketiganya mm. */
    public const SATUAN = 'mm';

    /** Sepuluh titik, nominalnya dipatok Instruksi Kerja. */
    public const BARIS_KERTAS = 10;

    /** Tiga pembacaan per titik (`PERHITUNGAN` kolom I..K). */
    public const PENGULANGAN = 3;

    /** Sepuluh pembacaan berulang blok Evaluation (`PERHITUNGAN!C30:M30`). */
    public const PRA_EVALUASI = 10;

    /** Tiga pembacaan paralelisme (`INPUT DATA!C31`, `D31`, `F31`). */
    public const PARALELISME = 3;

    /**
     * Instruksi Kerja-nya, dari `DATABASE!A105:C105` — jenis pengukuran no. 37.
     *
     * Ini nomor IK, BUKAN nomor formulir lembar kerja. Sapuan `SIDIK-FM-` di
     * seluruh workbook cuma menemukan SATU (`SIDIK-FM-CAL-2403_Rev. 0` di
     * `SERTIFIKAT!A59`), dan itu formulir sertifikat bersama — bukan lembar
     * kerja. Lihat [bentukLembarKerja].
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0539_Rev.0';

    public const STANDARD_TERCETAK = [
        [
            'label' => 'Caliper Checker/Metrology/CMG-9060C',
            'cocok' => ['Caliper Checker', 'CMG-9060C', '800035'],
        ],
    ];

    /** Ketujuh unit thermohygro yang tercetak di `DATABASE!B31:B61`. */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    /**
     * Satuan yang ditawarkan master lewat `Satuan_Caliper` (`DATABASE!R23:T25`),
     * berikut faktor pengalinya ke mm.
     *
     * Dipasang sebagai dropdown, bukan teks bebas — dan bukan karena rapi:
     * master Micrometer 0-25 mm tersetel `inch` sementara angkanya diketik
     * dalam mm, dan akibatnya berantai sampai koreksi −61 mm pada balok ukur
     * 2,5 mm. Tidak ada satu pun sel yang memprotes.
     *
     * Di sini akibatnya LEBIH buruk daripada di Micrometer: tidak ada lantai
     * CMC yang menahan, jadi sesi berskala inch yang satuannya kelupaan dipilih
     * langsung menerbitkan U95 yang salah 25,4× tanpa apa pun yang ganjil.
     */
    public const SATUAN_PILIHAN = ['mm' => 1.0, 'inch' => 25.4, 'µm' => 0.001];

    private ?HeightGaugeCalculator $kalk = null;

    private ?TabelStandarHeightGauge $tabel = null;

    public function kode(): string
    {
        return 'height_gauge';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Height Gauge';
    }

    public function aliasNama(): array
    {
        return ['Height Gage', 'Vernier Height Gauge', 'Digital Height Gauge', 'Mistar Ketinggian'];
    }

    public function kodeFormula(): string
    {
        return 'gum-height-gauge';
    }

    public function besaran(): string
    {
        // `panjang`, mengikuti nama kelompok pengukuran lampiran akreditasi —
        // sama seperti Micrometer. Alat ini memang belum diakreditasi, tapi
        // KELOMPOKNYA ada dan alatnya jelas milik kelompok itu; membuat
        // kategori baru cuma melahirkan kategori hantu di `GET /api/categories`
        // yang isinya nol kemampuan. Dijaga `KategoriAlatIkutLampiranTest`.
        return 'panjang';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function butuhBlokHeightGauge(): bool
    {
        return true;
    }

    /**
     * Height Gauge tidak ada di lampiran LK-285-IDN — kelompok Panjang cuma
     * memuat Sieve, Micrometer, Vernier Caliper, dan Dial Indicator. Masternya
     * sendiri mengakuinya: sel lantai CMC (`PERHITUNGAN U95%!AA19`) kosong.
     *
     * Lihat docblock kelas dan `docs/pertanyaan-lab-height-gauge.md` §6.
     */
    public function dalamLingkupAkreditasi(): bool
    {
        return false;
    }

    /**
     * Sesi Height Gauge TIDAK divonis PASS/FAIL.
     *
     * Workbook master berhenti di kolom `Correction` + satu baris
     * `Uncertainty U95%`; tidak ada satu pun batas keberterimaan untuk
     * kesepuluh titiknya. Satu-satunya kriteria di lembar itu paralelisme
     * (≤ 0,01 mm), dan itu vonis tingkat SESI yang dicetak terpisah di kaki
     * sertifikat — bukan toleransi per titik.
     *
     * Dibiarkan `true` (bawaan), form Alat mewajibkan kolom `toleransi` yang
     * tidak punya isi yang benar — dan teknisi mengarang angkanya.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * `kolom_suhu = false`: kolom tabelnya cuma `nominal` dan `nilai`; suhu
     * diisi SEKALI per sesi. Dibiarkan `true`, prompt pembaca foto meminta
     * kolom yang tidak ada di kertasnya, dan yang balik bukan error melainkan
     * angka karangan yang wajar.
     *
     * `didukung = false`: kertas lembar kerjanya BELUM turun sama sekali (lihat
     * [bentukLembarKerja]), jadi geometri di
     * `database/ocr-templates/height_gauge-v1.json` masih grid rata hasil
     * generator dan belum pernah diadu ke foto formulir asli. Membuka jalur
     * kamera dengan geometri karangan berarti pembaca foto memungut sel yang
     * salah — dan yang balik bukan error melainkan angka wajar di baris yang
     * keliru.
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
     * Ketidakpastian alat ini lahir per SESI — lihat docblock kelas. `null` di
     * sini bukan "belum ditulis": jalur per titik memang tidak ada bentuknya.
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
        return null;
    }

    /**
     * Sepuluh titik + satu budget sesi.
     *
     * Titik yang tidak bisa dihitung dilaporkan lewat `belum_dihitung`, tidak
     * dibuang diam-diam — itu bedanya dari `IFERROR(...; "")` master, yang
     * membuat titiknya hilang dari sertifikat tanpa jejak.
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        // Blok tingkat-sesi datang lewat `konteks`, bukan relasi — jalur simpan
        // dan jalur hitung ulang sama-sama menaruhnya di situ, dan profil yang
        // menengok relasi sesi cuma jalan di salah satunya.
        //
        // DISAPU, bukan diambil dari `$titik[0]`: jalur hitung ulang
        // mengelompokkan per `titik_ke` lewat `groupBy` dan urutannya tidak
        // dijamin, jadi bertumpu pada elemen pertama berarti sesi yang titik
        // pertamanya kebetulan tersaring pulang tanpa blok — diam-diam, dengan
        // seluruh titiknya "belum dihitung".
        $konteksSesi = [];

        foreach ($titik as $t) {
            if (isset($t['konteks']['spesifikasi_alat'])) {
                $konteksSesi = $t['konteks'];

                break;
            }
        }

        $blok = HeightGaugeMentah::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi Height Gauge belum punya blok tingkat-sesi di `spesifikasi_alat.'
                        .HeightGaugeMentah::KUNCI_SESI.'` — pengulangan (blok Evaluation), paralelisme, '
                        .'kapasitas, dan resolusi lahir di situ, bukan per titik.',
                ], $titik),
            ];
        }

        $masukan = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            // Kedua deret datang lewat `konteks`, bukan level atas — jalur
            // simpan dan jalur hitung ulang sama-sama menaruhnya di situ,
            // persis seperti tumpukan Micrometer dan blok Timbangan.
            $k = $t['konteks'] ?? [];
            $nominal = array_map('floatval', $k[HeightGaugeMentah::PERAN_NOMINAL] ?? []);
            $pembacaan = array_map('floatval', $k[HeightGaugeMentah::PERAN_PEMBACAAN] ?? []);

            // Sesi yang baris mentahnya belum ber-`peran_sensor` DITOLAK dengan
            // alasan yang kebaca, bukan diam-diam dihitung dari `pembacaan`
            // datar. Deret datar tidak bisa dibedakan mana slot nominal Caliper
            // Checker mana penunjukan alat, dan koreksi yang lahir dari situ —
            // selisih keduanya — tidak berarti apa-apa.
            if ($nominal === [] && $pembacaan === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d nggak punya baris ber-peran `%s`/`%s`. Lembar Height Gauge nyimpen '
                        .'slot nominal Caliper Checker dan deret pembacaan terpisah — deret datar '
                        .'nggak bisa dipakai.',
                        $t['titik_ke'],
                        HeightGaugeMentah::PERAN_NOMINAL,
                        HeightGaugeMentah::PERAN_PEMBACAAN,
                    ),
                ];

                continue;
            }

            $masukan[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'nominal' => $nominal,
                'pembacaan' => $pembacaan,
                'standard' => $t['standard'] ?? null,
            ];
        }

        $kemampuan = $this->kemampuanSesi($equipment);
        $hasil = $this->kalk()->hitungSesi(
            array_map(static fn (array $m): array => [
                'titik_ke' => $m['titik_ke'],
                'nominal' => $m['nominal'],
                'pembacaan' => $m['pembacaan'],
            ], $masukan),
            [
                'resolusi_mm' => $blok['resolusi_mm'],
                'pra_evaluasi' => $blok['pra_evaluasi'],
                'paralelisme' => $blok['paralelisme'],
                'tanggal_kalibrasi' => $this->tanggalKalibrasi($konteksSesi),
                // Suhu Caliper Checker & suhu UUT diturunkan dari suhu ruangan,
                // tidak diminta terpisah — lihat
                // [HeightGaugeCalculator::hitungSesi].
                'suhu_ruang_rata_c' => (float) ($konteksSesi['suhu_ruang_rata'] ?? 0.0),
            ],
        );

        $standarPerTitik = collect($masukan)->keyBy('titik_ke');
        $sekarang = Carbon::now();
        $hitungan = [];

        // Budget yang tidak utuh TIDAK melahirkan satu pun baris hitungan.
        //
        // Ini bukan kerapian. Menerbitkan baris ber-`ketidakpastian_diperluas`
        // nol berarti sertifikatnya mencetak `± 0,000` — klaim pengukuran
        // SEMPURNA. Peringatan sesi tidak menahannya: `CalibrationValidator`
        // membungkus [peringatanSesi] jadi temuan tingkat PERINGATAN yang boleh
        // dilewati admin lewat `abaikan_peringatan`, jadi yang menahan harus
        // ketiadaan barisnya, bukan pesannya.
        //
        // Di alat ini gerbangnya lebih menentukan daripada di Micrometer: di
        // sana lantai CMC menampung budget yang kehilangan komponen, di sini
        // tidak ada apa pun yang menampung MAUPUN menahan.
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

            usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

            return ['hitungan' => [], 'belum_dihitung' => $belumDihitung];
        }

        foreach ($hasil['titik'] as $h) {
            $hitungan[] = [
                // Null-safe: sesi yang standarnya di-soft-delete memulangkan
                // null, dan tanpa `?->` perintah hitung ulang mati total.
                'standard_id' => ($standarPerTitik[$h['titik_ke']]['standard'] ?? null)?->id,
                'titik_ke' => $h['titik_ke'],
                'titik_ukur' => $h['total_nominal'],
                'rata_rata' => $h['rata_rata'],
                'error' => -$h['koreksi'],
                'koreksi' => $h['koreksi'],
                'standar_deviasi' => $h['simpangan_baku'],
                'jumlah_pengulangan' => $h['jumlah_pengulangan'],
                // Type A sesi, bukan titik: pengulangan lahir dari blok
                // Evaluation. TIDAK dibagi 1000 — budget alat ini sudah mm.
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
     * Peringatan sesi.
     *
     * Dua hal yang tidak boleh lewat diam-diam, dan keduanya BUKAN yang menahan
     * sesinya — `CalibrationValidator::periksaPeringatanProfil()` membungkus apa
     * pun yang dipulangkan di sini jadi temuan tingkat PERINGATAN yang boleh
     * dilewati admin. Yang benar-benar menahan ketiadaan baris hitungan di
     * [hitungPerGrup]; pesan ini tugasnya menjelaskan KENAPA.
     *
     * 1. **Status akreditasi.** Sesi ini terbit di luar lingkup LK-285-IDN,
     *    jadi U95-nya tidak punya lantai CMC dan sertifikatnya sengaja terbit
     *    TANPA klaim akreditasi. Peringatan ini yang memberi tahu admin sebelum
     *    dia menekan approve — supaya bedanya dari sertifikat terakreditasi
     *    disadari, bukan ditemukan pelanggan.
     * 2. **Paralelisme "Not Good".** Ini hasil UKUR, bukan cacat data — dia
     *    tidak menahan penerbitan dan memang dicetak apa adanya di kaki
     *    sertifikat. Diangkat supaya admin tahu sertifikat ini membawa vonis
     *    "Not Good", bukan supaya dia memperbaikinya.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $blok = HeightGaugeMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null) {
            return [];
        }

        $temuan = [[
            'kode' => 'height_gauge_diluar_akreditasi',
            'pesan' => 'Height Gauge TIDAK ada di lampiran akreditasi LK-285-IDN (kelompok Panjang '
                .'cuma memuat Sieve, Micrometer, Vernier Caliper, dan Dial Indicator), jadi U95 sesi '
                .'ini terbit TANPA lantai CMC — persis seperti masternya, yang sel lantainya (`AA19`) '
                .'memang kosong. Angkanya sah. Sertifikatnya sengaja terbit tanpa logo maupun nomor '
                .'akreditasi; pastikan pelanggan tahu bedanya dari sertifikat terakreditasi.',
        ]];

        $paralelisme = $this->kalk()->paralelisme($blok['paralelisme']);

        if ($paralelisme !== null && ! $paralelisme['lulus']) {
            $temuan[] = [
                'kode' => 'height_gauge_paralelisme_tidak_lulus',
                'pesan' => sprintf(
                    'Paralelisme ujung scriber %s mm melewati batas %s mm, jadi sertifikat ini '
                    .'mencetak "Not Good" di catatan kakinya. Ini hasil UKUR, bukan cacat data — '
                    .'sesinya tetap terbit dan angkanya tetap sah.',
                    rtrim(rtrim(number_format($paralelisme['hasil'], 6, ',', '.'), '0'), ','),
                    rtrim(rtrim(number_format($paralelisme['batas'], 6, ',', '.'), '0'), ','),
                ),
            ];
        }

        return $temuan;
    }

    /**
     * Satuan kolom tabel sertifikat — `mm`, dan ini WAJIB terisi.
     *
     * Blade mencetak satuan sebagai sufiks kepala kolom (`Standard (mm)`) cuma
     * kalau baris menyebutkannya. Master pun mencetak baris satuannya sendiri
     * (`SERTIFIKAT!D23`/`I23`/`L23`, tiga sel berisi `mm`).
     */
    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    public function desimalSertifikat(): ?int
    {
        // Lima desimal mm — nilai terkoreksi Caliper Checker master
        // (`25,00080`, `299,99955`) memang sedetail itu, dan membulatkannya
        // lebih dulu membuang koreksi yang justru sedang diukur.
        return 5;
    }

    public function desimalU95(): ?int
    {
        // Lima desimal, sama dengan kolom hasil di atasnya — angkanya mm.
        // U95 sesi contoh 0,01563 mm; di tiga desimal dia runtuh jadi `0,016`
        // dan kehilangan sebagian besar angka pentingnya.
        return 5;
    }

    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            // `null`, dan itu FAKTA bukan kelalaian: sapuan `SIDIK-FM-` di
            // seluruh workbook cuma menemukan SATU nomor —
            // `SIDIK-FM-CAL-2403_Rev. 0` di `SERTIFIKAT!A59` — dan itu formulir
            // SERTIFIKAT bersama, bukan lembar kerja. Kertas lembar kerja
            // Height Gauge belum turun dari lab.
            //
            // Menebak nomor berikutnya dari deret yang ada berarti mencetak
            // nomor formulir karangan di kop lembar yang dipakai teknisi dan
            // diaudit. `height_gauge` karena itu masuk
            // `SemuaProfilLembarKerjaTest::$belumAdaKertasnya` berikut buktinya.
            'kode_dokumen' => null,
            'kode_metode' => self::KODE_METODE,
            // `nomor_lingkup` SENGAJA tidak dipasang — alat ini di luar
            // lampiran LK-285-IDN. Lihat docblock kelas.
            'judul' => 'Calibration Work Sheet - Height Gauge',
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Isi SATUAN alat lebih dulu — dia yang mengubah pembacaan dan blok '
                .'Evaluation ke mm. Nominalnya SUDAH DIPATOK Instruksi Kerja (sepuluh titik, '
                .'25..600 mm) dan tidak bisa diubah. Blok Evaluation WAJIB diisi sepuluh-duanya: dari '
                .'situ ketidakpastian keterulangan lahir, bukan dari tiga pembacaan tiap titik — dan '
                .'alat ini tidak punya lantai CMC yang menampungnya kalau blok itu kosong. '
                .'Paralelisme dibaca pada Dial Indicator standar (resolusi 0,001 mm), jadi angkanya '
                .'SELALU mm apa pun satuan Height Gauge-nya.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master_olda_Height_Gauge_600_mm_2026.xlsm',
                'catatan' => 'Sembilan komponen tingkat-SESI dalam mm (bukan µm). TANPA lantai CMC — '
                    .'Height Gauge di luar lampiran akreditasi LK-285-IDN, dan sel lantai masternya '
                    .'(`AA19`) memang kosong. Sesi yang blok Evaluation-nya kurang dari dua '
                    .'pembacaan, sepuluh-duanya identik, atau resolusinya kosong TIDAK diterbitkan.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianParalelisme(),
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

    /**
     * Tanggal kalibrasi sesi — titik nol umur drift standar.
     *
     * Master memakai `NOW()`, jadi U95 sesi yang sama tidak pernah terulang.
     * Di sini tanggal SESI, yang jelas maksudnya dan bisa diulang tahun depan
     * dengan hasil yang sama.
     *
     * @param  array<string, mixed>  $konteks
     */
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

        // Baris `perbandingan_cmc` tetap diterbitkan walau CMC-nya nol, dan itu
        // WAJIB: `CalibrationValidator::cmcTitik()` menyapu `type_b_components`
        // mencari `sumber` ini, dan yang tidak ketemu mematikan gerbang ERROR
        // `u95_meledak_dari_cmc` — penjagaan yang lahir dari satu pembacaan
        // salah ketik yang menerbitkan U95 212x CMC lab.
        //
        // `null` dioper apa adanya supaya keterangannya berbunyi "tanpa lantai
        // CMC", bukan "vs CMC 0.00000000" yang terbaca seperti klaim sempurna.
        $budget[] = $this->barisPerbandinganCmc(
            (float) $hasil['ketidakpastian_diperluas'],
            null,
            self::SATUAN,
        );

        $paralelisme = $hasil['paralelisme'] ?? null;

        $budget[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'Nominal Caliper Checker %s mm · total nominal %s mm · rata-rata pembacaan %s mm · '
                .'koreksi %s mm · U95 %s mm (tanpa lantai CMC — di luar lampiran LK-285-IDN) · '
                .'paralelisme sesi %s',
                implode(' + ', array_map(static fn ($n): string => (string) $n, $h['nominal'])) ?: '-',
                $h['total_nominal'], $h['rata_rata'], $h['koreksi'],
                $hasil['u95_sertifikat'],
                $paralelisme === null
                    ? 'tidak dicatat'
                    : sprintf('%s mm (%s)', $paralelisme['hasil'], $paralelisme['lulus'] ? 'Good' : 'Not Good'),
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
                // Satuan lebih dulu, karena dia yang mengubah arti tiga field di
                // bawahnya DAN seluruh blok Evaluation. Lihat [SATUAN_PILIHAN].
                $this->field('spesifikasi_alat.height_gauge.satuan', 'Satuan Alat', 'pilihan', pilihan: [
                    ['nilai' => 'mm', 'label' => 'mm'],
                    ['nilai' => 'inch', 'label' => 'inch'],
                    ['nilai' => 'µm', 'label' => 'µm'],
                ]),
                $this->field('spesifikasi_alat.rentang_ukur', 'Rentang Ukur', 'teks'),
                $this->field('spesifikasi_alat.height_gauge.kapasitas_mm', 'Kapasitas Max.', 'angka', satuan: self::SATUAN),
                $this->field('spesifikasi_alat.height_gauge.resolusi_mm', 'Resolusi Alat', 'angka', satuan: self::SATUAN),
                $this->field('tanggal_terima', 'Tgl. Diterima', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Tgl. Kalibrasi', 'tanggal'),
                $this->field('suhu_awal', 'Suhu Ruangan — awal', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Suhu Ruangan — akhir', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Kelembapan — awal', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Kelembapan — akhir', 'angka', satuan: '%RH'),
                // SATU pilihan, bukan dua centang — master mencentang keduanya
                // sekaligus di sesi contoh. Lihat docblock kelas.
                $this->field(
                    'spesifikasi_alat.height_gauge.kerataan_muka_ukur',
                    'Kerataan Muka Ukur', 'pilihan',
                    pilihan: [
                        ['nilai' => 'baik', 'label' => 'Baik (Good)'],
                        ['nilai' => 'buruk', 'label' => 'Buruk (Not Good)'],
                    ],
                ),
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
     * Blok 1 — Paralelisme Ujung Scriber, satu baris tiga pembacaan.
     *
     * TIDAK melahirkan titik ukur dan TIDAK masuk budget: dia catatan kelulusan
     * yang dicetak di kaki sertifikat (`SERTIFIKAT!D38`/`H38` master).
     *
     * Angkanya SELALU mm, apa pun satuan Height Gauge-nya — dia dibaca pada
     * Dial Indicator standar resolusi 0,001 mm, dan batasnya (≤ 0,01 mm)
     * ditulis dalam mm. Lihat [HeightGaugeMentah::blokSesi].
     *
     * @return array<string, mixed>
     */
    private function bagianParalelisme(): array
    {
        return [
            'kode' => 'paralelisme',
            'halaman' => 1,
            'judul' => 'Paralelisme Ujung Scriber',
            'field' => [],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => 'paralelisme',
                    'judul' => 'Pengukuran Paralelisme (Dial Indicator, res. 0,001 mm)',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Paralelisme',
                    'judul_pengulangan' => 'Pembacaan',
                    'titik_bisa_diubah' => false,
                    // Offset 2000 — beda dari Evaluasi (1000) dan jauh di atas
                    // nominal terbesar lembar ini (600 mm). Ketiga tabel
                    // ber-`tahap` sama, jadi tanpa offset kunci barisnya bisa
                    // bertabrakan DI LAYAR: angka yang diketik di satu kotak
                    // muncul di kotak lain, tanpa satu pun error. Sudah nyata di
                    // Timbangan; lihat docblock kelas.
                    'offset_kunci' => 2000,
                    'simpan_ke' => 'spesifikasi_alat.height_gauge.paralelisme',
                    'baris' => [[
                        'nomor' => 1,
                        'titik_ukur' => null,
                        'label' => 'Paralelisme',
                        'satuan' => self::SATUAN,
                    ]],
                    'kolom' => [
                        ['kode' => 'pembacaan', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                    ],
                    'pengulangan' => range(1, self::PARALELISME),
                ],
            ],
        ];
    }

    /**
     * Blok 2 — `Evaluation`, sepuluh pembacaan berulang di nominal kapasitas.
     *
     * Dari sini SELURUH ketidakpastian keterulangan lahir, BUKAN dari tiga
     * pembacaan tiap titik (`PERHITUNGAN!N30 = STDEV(C30:M30)`).
     *
     * @return array<string, mixed>
     */
    private function bagianEvaluasi(): array
    {
        return [
            'kode' => 'evaluasi',
            'halaman' => 1,
            'judul' => 'Evaluation',
            'field' => [],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => 'pra_pembacaan',
                    'judul' => 'Evaluation (pembacaan berulang di kapasitas)',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Evaluation',
                    'judul_pengulangan' => 'Pembacaan',
                    'titik_bisa_diubah' => false,
                    // 1000, mengikuti Micrometer — dan HARUS beda dari 2000
                    // milik Paralelisme. Lihat [bagianParalelisme].
                    'offset_kunci' => 1000,
                    'simpan_ke' => 'spesifikasi_alat.height_gauge.pra_evaluasi',
                    'baris' => [[
                        'nomor' => 1,
                        'titik_ukur' => null,
                        'label' => 'Evaluation',
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

    /**
     * Blok 3 — `Measurement`: sepuluh nominal PRA-CETAK, tiga pembacaan tiap
     * barisnya.
     *
     * `titik_bisa_diubah = false` — nominalnya dipatok Instruksi Kerja dan sama
     * persis dengan sepuluh baris tabel Outside `Std_CaliperCek`. Teknisi tidak
     * memilih, tidak menambah, tidak mengurangi; yang dia isi cuma pembacaannya.
     *
     * Daftarnya diambil dari TABEL STANDAR, bukan diketik di sini: kesepuluh
     * nominal itu SATU daftar dengan tabel yang dipakai `VLOOKUP`-nya, dan
     * menyalinnya berarti dua tempat yang harus ingat diperbarui bareng — yang
     * ketinggalan tidak menerbitkan error, cuma titik yang nominalnya tidak
     * ketemu.
     *
     * @return array<string, mixed>
     */
    private function bagianDataKalibrasi(): array
    {
        $nominal = $this->tabel()->titikPraCetak();

        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => 'Measurement',
            'field' => [],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => HeightGaugeMentah::PERAN_PEMBACAAN,
                    'judul' => 'Pembacaan Alat',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Nominal Step Gauge',
                    'judul_pengulangan' => 'Pembacaan Alat',
                    'titik_bisa_diubah' => false,
                    'baris' => array_map(
                        static fn (int $n): array => [
                            'nomor' => $n,
                            'titik_ukur' => $nominal[$n - 1] ?? null,
                            'label' => isset($nominal[$n - 1])
                                ? rtrim(rtrim(number_format($nominal[$n - 1], 1, ',', '.'), '0'), ',')
                                : 'Titik '.$n,
                            'satuan' => self::SATUAN,
                        ],
                        range(1, self::BARIS_KERTAS),
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

    private function kalk(): HeightGaugeCalculator
    {
        // Malas, bukan di parameter bawaan konstruktor — lihat
        // [HeightGaugeCalculator::tabel].
        return $this->kalk ??= new HeightGaugeCalculator;
    }

    private function tabel(): TabelStandarHeightGauge
    {
        return $this->tabel ??= new TabelStandarHeightGauge;
    }
}
