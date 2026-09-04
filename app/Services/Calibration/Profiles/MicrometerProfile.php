<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\MicrometerCalculator;
use App\Services\Calibration\TabelStandarMicrometer;
use App\Support\MicrometerMentah;
use Carbon\Carbon;

/**
 * Lembar & hitungan **Micrometer** — lampiran akreditasi LK-285-IDN no. 34,
 * kelompok Panjang.
 *
 * Empat workbook master turun bersamaan (0-25, 25-50, 50-75, 75-100 mm) dan
 * keempatnya identik baris demi baris — yang membedakan cuma pita CMC-nya. Jadi
 * SATU profil dengan empat pita, bukan empat profil; polanya sama dengan
 * [TimbanganProfile] yang tiga workbook masternya jadi satu profil tiga varian.
 *
 * ## Ketidakpastian lahir per SESI, bukan per titik
 *
 * Sheet `PERHITUNGAN U95%` master cuma punya satu kolom, dan sertifikatnya
 * mencetak satu baris `Uncertainty U95% = ±` di bawah sebelas titik. Makanya
 * [hitungPerGrup] yang dipakai, dan [komponenBudget] memulangkan `null` —
 * bukan karena belum ditulis, tapi karena bentuk per titik tidak ada di alat
 * ini. Pengulangannya sendiri memang tidak bisa dinyatakan per titik: dia
 * datang dari baris `Evaluasi` (sepuluh pembacaan berulang di satu titik),
 * bukan dari sebaran lima pembacaan tiap titik.
 *
 * ## Blok tingkat-sesi tinggal di `spesifikasi_alat`
 *
 * Pra-evaluasi, kapasitas, dan resolusi bukan titik ukur — memaksanya jadi
 * `titik_ke` melahirkan titik hantu yang selalu gagal hitung ulang. Lihat
 * [MicrometerMentah::blokSesi].
 *
 * ## Bentuk lembarnya mengikuti KERTAS, bukan `INPUT DATA` Excel
 *
 * Kertasnya turun 4 Sep 2026 — empat, satu per rentang
 * (`SIDIK-FM-CAL-0522.{A,B,C,D}_Rev.1`) — dan bentuknya jauh lebih ramping
 * daripada sheet `INPUT DATA` master:
 *
 *  - **Nominal balok ukurnya PRA-CETAK**, sebelas baris tetap per rentang.
 *    Teknisi tidak memilih tumpukan keping; itu ditentukan Instruksi Kerja.
 *  - **Satu tabel pembacaan** (X1..X5), plus **satu baris `Evaluasi`**
 *    (X1..X10) yang jadi sumber pengulangan.
 *  - **Tidak ada** kotak suhu balok ukur maupun suhu UUT, dan tidak ada
 *    bagian pemeriksaan muka ukur.
 *
 * Ketiadaan kotak suhu itu bukan kelalaian kertas — di KEEMPAT workbook master
 * `suhu_balok = suhu_uut = (suhu_awal + suhu_akhir) / 2`, jadi keduanya
 * DITURUNKAN dari suhu ruangan yang memang dipungut kertas. Itu juga sebabnya
 * komponen "selisih suhu mikrometer dengan balok ukur" selalu nol: dia
 * `|suhu_uut − suhu_balok|` dari dua angka yang sama menurut konstruksi.
 *
 * Permintaan 6 memang memerintahkan lembar mengikuti PDF resmi; dua hal yang
 * TETAP menyimpang dari kertas, dan sengaja: kotak Inlab/Insitu (kertas
 * mencetak "Inlab (Lab. Dimensi PT SIDIK)" mati, permintaan 2 memintanya bisa
 * dipilih di semua lembar) dan dropdown Thermohygro (kertas mencetak `TH-1`
 * mati, `ThermohygroSemuaLembarTest` menuntutnya terisi dari master lab).
 */
class MicrometerProfile extends CalibrationProfile
{
    /** Satuan panjang lembar & sertifikat. Budget-nya sendiri dalam µm. */
    public const SATUAN = 'mm';

    public const SATUAN_BUDGET = 'µm';

    /**
     * Sebelas titik — dan angkanya dipatok KERTAS, bukan pilihan teknisi.
     * Sama di keempat varian, dan cocok baris demi baris dengan sertifikat
     * master (baris 18..28).
     */
    public const BARIS_KERTAS = 11;

    /** Lima pembacaan per titik (`PERHITUNGAN` kolom I..M). */
    public const PENGULANGAN = 5;

    /** Sepuluh pembacaan berulang pra-evaluasi (`PERHITUNGAN` baris 25). */
    public const PRA_EVALUASI = 10;

    /**
     * Instruksi Kerja-nya, dari lampiran akreditasi baris no. 34 — dan tercetak
     * juga di kop kertas (`Metode : SIDIK-IK-CAL-0515`).
     *
     * Ini BUKAN nomor formulir lembar kerja: yang itu ada EMPAT, satu per
     * rentang, dan dipilih per alat di [bentukLembarKerja].
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0515_Rev.3';

    public const STANDARD_TERCETAK = [
        [
            'label' => 'Gauge Block Standard/Metrology/GB-9122-0',
            'cocok' => ['Gauge Block Standard', 'GB-9122-0', '160006'],
        ],
    ];

    /** Ketujuh unit thermohygro yang tercetak di kop master (`TH-1`…`TH-7`). */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    /**
     * Satuan yang ditawarkan master lewat `Satuan_Caliper` (DATABASE R23:T25),
     * berikut faktor pengalinya ke mm.
     *
     * Dipasang sebagai dropdown, bukan teks bebas: sesi contoh 0-25 mm master
     * tersetel `inch` sementara angkanya diketik dalam mm, dan akibatnya
     * berantai — kapasitas jadi 635 mm, jatuh di luar semua pita CMC, dan
     * koreksinya terbit −61 mm pada balok ukur 2,5 mm. Tidak ada satu pun sel
     * yang memprotes.
     */
    public const SATUAN_PILIHAN = ['mm' => 1.0, 'inch' => 25.4, 'µm' => 0.001];

    private ?MicrometerCalculator $kalk = null;

    public function kode(): string
    {
        return 'micrometer';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Micrometer';
    }

    public function aliasNama(): array
    {
        return ['Mikrometer', 'Outside Micrometer', 'Micrometer Outside', 'Mikrometer Luar'];
    }

    public function kodeFormula(): string
    {
        return 'gum-micrometer';
    }

    public function besaran(): string
    {
        // `panjang`, mengikuti nama kelompok pengukuran lampiran akreditasi —
        // sama seperti `massa`, `suhu`, `waktu` di profil lain.
        //
        // Sempat `dimensi`, dan itu ikut menyeret seeder-nya membuat kategori
        // alat `Dimensi` yang tidak ada di lampiran. Kelompok no. 34 namanya
        // **Panjang**; nama kedua untuk hal yang sama cuma menunggu ada yang
        // memakainya sebagai kunci.
        return 'panjang';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function butuhBlokMicrometer(): bool
    {
        return true;
    }

    /**
     * Sesi Micrometer TIDAK divonis PASS/FAIL.
     *
     * Keempat workbook master berhenti di kolom `Correction` + satu baris
     * `Uncertainty U95%`; tidak ada satu pun batas keberterimaan di lembarnya,
     * dan lampiran akreditasi baris no. 34 juga tidak menyebutnya. Dibiarkan
     * `true` (bawaan), form Alat mewajibkan kolom `toleransi` yang tidak punya
     * isi yang benar — dan teknisi mengarang angkanya.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * Bentuk lembar buat pembaca foto.
     *
     * `kolom_suhu = false`: bawaan [CalibrationProfile::bentukPindaiFoto]
     * menuruti lembar pH, yang tiap selnya sepasang angka (pembacaan + suhu).
     * Lembar ini tidak — kolom tabelnya cuma `nominal` dan `nilai`, dan suhu
     * diisi SEKALI per sesi di blok pra-evaluasi. Dibiarkan `true`, prompt
     * pembaca foto meminta model membaca kolom yang tidak ada di kertasnya, dan
     * yang balik bukan error melainkan angka karangan yang wajar.
     *
     * `didukung = false`: kertasnya memang sudah turun, tapi geometri di
     * `database/ocr-templates/micrometer-v1.json` masih grid rata hasil
     * generator (`terverifikasi: false`) — koordinatnya belum pernah DIUKUR
     * dari formulir cetak asli dan belum pernah diadu ke foto nyata. Membuka
     * jalur kamera dengan geometri karangan berarti pembaca foto memungut sel
     * yang salah, dan yang balik bukan error melainkan angka yang wajar di
     * baris yang keliru. Input manual dulu; jalur kamera dibuka begitu
     * geometrinya diukur.
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
     * Sebelas titik + satu budget sesi.
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
        // Disapu, bukan diambil dari `$titik[0]`: jalur hitung ulang
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

        $blok = MicrometerMentah::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi Micrometer belum punya blok pra-evaluasi di `spesifikasi_alat.'
                        .MicrometerMentah::KUNCI_SESI.'` — pengulangan, suhu, kapasitas, dan '
                        .'resolusi lahir di situ, bukan per titik.',
                ], $titik),
            ];
        }

        $masukan = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            // Kedua deret datang lewat `konteks`, bukan level atas — jalur
            // simpan dan jalur hitung ulang sama-sama menaruhnya di situ,
            // persis seperti blok Timbangan dan grid Enclosure.
            $k = $t['konteks'] ?? [];
            $balok = array_map('floatval', $k[MicrometerMentah::PERAN_BALOK] ?? []);
            $pembacaan = array_map('floatval', $k[MicrometerMentah::PERAN_PEMBACAAN] ?? []);

            // Sesi yang baris mentahnya belum ber-`peran_sensor` DITOLAK dengan
            // alasan yang kebaca, bukan diam-diam dihitung dari `pembacaan`
            // datar. Deret datar tidak bisa dibedakan mana nominal balok ukur
            // mana penunjukan alat, dan koreksi yang lahir dari situ tidak
            // berarti apa-apa.
            if ($balok === [] && $pembacaan === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d nggak punya baris ber-peran `%s`/`%s`. Lembar Micrometer nyimpen '
                        .'TUMPUKAN balok ukur dan deret pembacaan terpisah — deret datar nggak '
                        .'bisa dipakai.',
                        $t['titik_ke'],
                        MicrometerMentah::PERAN_BALOK,
                        MicrometerMentah::PERAN_PEMBACAAN,
                    ),
                ];

                continue;
            }

            $masukan[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'nominal' => $balok,
                'pembacaan' => $pembacaan,
                'standard' => $t['standard'] ?? null,
            ];
        }

        $kemampuan = $this->kemampuanSesi($equipment);
        $hasil = ($this->kalk ??= new MicrometerCalculator)->hitungSesi(
            array_map(static fn (array $m): array => [
                'titik_ke' => $m['titik_ke'],
                'nominal' => $m['nominal'],
                'pembacaan' => $m['pembacaan'],
            ], $masukan),
            $blok + [
                'tanggal_kalibrasi' => $this->tanggalKalibrasi($konteksSesi),
                // Diturunkan dari suhu ruangan, tidak diminta terpisah — lihat
                // [MicrometerCalculator::budget].
                'suhu_ruang_rata_c' => (float) ($konteksSesi['suhu_ruang_rata'] ?? 0.0),
                // Balok ukur pra-evaluasi ditentukan VARIAN kertas, bukan
                // diketik teknisi: kertasnya cuma menyediakan satu baris
                // Evaluasi tanpa kolom nominal.
                'balok_pra_evaluasi' => array_map(
                    'floatval',
                    $this->varian($equipment)['balok_pra_evaluasi_mm'] ?? [],
                ),
            ],
        );

        $standarPerTitik = collect($masukan)->keyBy('titik_ke');
        $sekarang = Carbon::now();
        $hitungan = [];

        // Budget yang tidak utuh TIDAK melahirkan satu pun baris hitungan —
        // titiknya masuk `belum_dihitung` seluruhnya.
        //
        // Ini bukan kerapian. Menerbitkan baris ber-`ketidakpastian_diperluas`
        // nol berarti sertifikatnya mencetak `± 0,000` — klaim pengukuran
        // SEMPURNA, dan itu lebih buruk daripada 0,735 µm yang sedang
        // diperbaiki. Peringatan sesi tidak menahannya: `CalibrationValidator`
        // membungkus [peringatanSesi] jadi temuan tingkat PERINGATAN yang boleh
        // dilewati admin lewat `abaikan_peringatan`, jadi yang menahan harus
        // ketiadaan barisnya, bukan pesannya.
        //
        // Dua sebabnya, dan gerbangnya sengaja SATU
        // ([MicrometerCalculator] `boleh_terbit`): pita CMC yang hilang, dan
        // budget yang kehilangan komponen — mis. pra-evaluasi berisi kurang
        // dari dua pembacaan, yang membuat pengulangan jatuh ke nol dan U95
        // mendarat di lantai CMC dengan tampang wajar.
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
                // Type A sesi, bukan titik: pengulangan lahir dari pra-evaluasi.
                'type_a' => self::umKeMm($this->typeASesi($hasil['budget'])),
                // Jejak audit TETAP µm — itu satuan sheet `PERHITUNGAN U95%`
                // master, dan tiap barisnya menyebut satuannya sendiri.
                'type_b_components' => $this->jejakAudit($hasil, $h, $kemampuan),
                'type_b' => self::umKeMm($hasil['type_b']),
                'ketidakpastian_gabungan' => self::umKeMm($hasil['ketidakpastian_gabungan']),
                'faktor_cakupan_k' => $hasil['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $hasil['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => self::umKeMm($hasil['u95_sertifikat']),
                'toleransi' => null,
                'keputusan' => null,
                'metode' => $kemampuan?->metode,
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
     * Peringatan sesi: sesi tanpa pita CMC tidak boleh lewat diam-diam.
     *
     * Master 0-25 mm menerbitkan U95 0,735 µm padahal pita terakreditasinya
     * 0,83 µm — dan tidak ada satu pun sel yang memprotes.
     *
     * ## Peringatan ini BUKAN yang menahan sesinya
     *
     * `CalibrationValidator::periksaPeringatanProfil()` membungkus apa pun yang
     * dipulangkan di sini jadi temuan tingkat PERINGATAN — menahan approve
     * sekali, lalu boleh dilewati admin lewat `abaikan_peringatan`. Jadi
     * mengembalikan `tingkat => 'blokir'` di sini cuma bohong pada pembaca
     * kode: kuncinya tidak pernah dibaca, cuma `kode` dan `pesan`.
     *
     * Yang benar-benar menahan: [hitungPerGrup] tidak melahirkan satu pun baris
     * hitungan waktu pitanya hilang, jadi tidak ada angka yang bisa dicetak.
     * Pesan ini tugasnya menjelaskan KENAPA sesinya kosong.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $blok = MicrometerMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null) {
            return [];
        }

        $pita = (new TabelStandarMicrometer)->pitaCmc($blok['kapasitas_mm']);

        if ($pita !== null) {
            return [];
        }

        return [[
            'kode' => 'micrometer_di_luar_cmc',
            'pesan' => sprintf(
                'Kapasitas %s mm tidak masuk keempat pita CMC Micrometer (0-25, 25-50, 50-75, '
                .'75-100 mm), jadi sesi ini TIDAK menghasilkan satu pun titik terhitung — '
                .'ketidakpastiannya tidak punya lantai yang bisa dipertanggungjawabkan. '
                .'Periksa SATUAN kapasitas lebih dulu: master 0-25 mm sendiri tersetel `inch` '
                .'sementara angkanya milimeter, dan 25 × 25,4 = 635 mm jatuh di luar semua pita.',
                rtrim(rtrim(number_format($blok['kapasitas_mm'], 4, ',', '.'), '0'), ','),
            ),
        ]];
    }

    /**
     * Satuan kolom tabel sertifikat — `mm`, dan ini WAJIB terisi.
     *
     * Blade mencetak satuan sebagai sufiks kepala kolom (`Standard (mm)`) cuma
     * kalau baris menyebutkannya; yang memulangkan null mencetak `Standard`
     * telanjang. Buat lembar ini kolom telanjang berbahaya: tabelnya mm
     * (koreksi 0,00027) sementara baris `Uncertainty U95%` di bawahnya dulu
     * mencetak µm (0,871) di KOLOM YANG SAMA. Pembaca yang melihat 0,00027 dan
     * 0,871 bersebelahan tanpa satuan akan membaca U95-nya seribu kali lebih
     * besar dari yang sebenarnya — di angka yang justru jadi inti sertifikat
     * terakreditasi.
     *
     * Master pun mencetak baris satuan sendiri (`SERTIFIKAT!D17/I17/L17`, tiga
     * sel `=F11` yang isinya `mm`).
     */
    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    public function desimalSertifikat(): ?int
    {
        // Lima desimal mm — nilai terkoreksi balok ukur master (`2.50014`,
        // `100.00012`) memang sedetail itu, dan membulatkannya lebih dulu
        // membuang koreksi yang justru sedang diukur.
        return 5;
    }

    public function desimalU95(): ?int
    {
        // LIMA desimal, sama dengan kolom hasil di atasnya — karena angkanya
        // sekarang mm, bukan µm. U95 sesi contoh 0,00087 mm; di tiga desimal
        // dia runtuh jadi `0,001` dan kehilangan seluruh angka pentingnya.
        //
        // Master justru begitu: `SERTIFIKAT!J29` berformat `0.000`, jadi
        // sertifikat cetaknya menampilkan `0.001 mm` — dan kolom Correction-nya
        // (format `0.000` juga) menampilkan `0.000` di KESEBELAS titik.
        // Itu tidak ditiru: kolom koreksi yang seluruhnya nol memberi tahu
        // pelanggan alatnya sempurna di tiap titik. Lihat
        // `docs/pertanyaan-lab-micrometer.md` §9.
        return 5;
    }

    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $varian = $this->varian($equipment);

        $bentuk = [
            // Nomor formulir per VARIAN — kertasnya empat, satu per rentang
            // (`SIDIK-FM-CAL-0522.A/B/C/D_Rev.1`, turun 4 Sep 2026). Polanya
            // sama dengan Timbangan, yang juga memasang nomor per varian.
            //
            // `null` waktu alatnya belum diketahui (panggilan tanpa
            // `$equipment`, mis. sapuan bentuk lembar): varian belum bisa
            // ditentukan, dan menebak salah satunya berarti mencetak nomor
            // formulir yang bukan miliknya di kop lembar terakreditasi.
            'kode_dokumen' => $varian['kode_dokumen'] ?? null,
            'kode_metode' => self::KODE_METODE,
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => $varian === null
                ? 'Calibration Work Sheet - Micrometer'
                : "Calibration Work Sheet - Micrometer ({$varian['judul_rentang']})",
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Isi SATUAN alat lebih dulu — dia yang mengubah semua angka '
                .'lembar ini ke mm. Nominal balok ukurnya SUDAH DIPATOK kertas (sebelas titik '
                .'per rentang) dan tidak bisa diubah: tumpukan keping yang membentuknya '
                .'ditentukan Instruksi Kerja, bukan dipilih di lapangan. Baris Evaluasi WAJIB '
                .'diisi sepuluh-duanya — dari situ pengulangannya lahir, bukan dari lima '
                .'pembacaan tiap titik.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master_Olah_Data_Micrometer_{025,2550,5075,75100}mm.xlsm',
                'catatan' => 'Sembilan komponen tingkat-SESI, lantai CMC empat pita '
                    .'(0,83 / 0,87 / 0,91 / 0,91 µm). Sesi yang kapasitasnya di luar keempat '
                    .'pita diblokir, bukan diterbitkan tanpa lantai.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianDataKalibrasi($varian),
                $this->bagianEvaluasi(),
                $this->bagianPenutup(),
            ],
        ];

        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($bentuk, $equipment),
            $equipment,
        );
    }

    /**
     * Varian kertas yang berlaku buat satu alat, dipilih dari KAPASITASnya.
     *
     * `null` kalau alatnya belum diketahui atau kapasitasnya di luar keempat
     * pita terakreditasi — dan di kasus kedua itu sesinya memang tidak boleh
     * terbit, lihat [TabelStandarMicrometer::pitaCmc].
     *
     * @return array<string, mixed>|null
     */
    private function varian(?Equipment $equipment): ?array
    {
        $kapasitas = (float) ($equipment?->range_max ?? 0.0);

        return $kapasitas > 0.0
            ? (new TabelStandarMicrometer)->pitaCmc($kapasitas)
            : null;
    }

    /**
     * Tanggal kalibrasi sesi — titik nol umur drift standar.
     *
     * Master memakai `NOW()`, jadi U95 sesi yang sama tidak pernah terulang:
     * keempat workbook disimpan selang dua menit dan umur driftnya beda
     * (695,4212 vs 695,4225 hari). Di sini tanggal SESI, yang jelas maksudnya
     * dan bisa diulang tahun depan dengan hasil yang sama.
     *
     * @param  array<string, mixed>  $konteks
     */
    private function tanggalKalibrasi(array $konteks): \DateTimeInterface
    {
        $tanggal = $konteks['tanggal_kalibrasi'] ?? null;

        return $tanggal ? Carbon::parse($tanggal) : Carbon::now();
    }

    /** @param  list<array<string, mixed>>  $budget */
    /**
     * µm → mm untuk kolom `uncertainty_calculations` yang bersanding dengan
     * `koreksi`.
     *
     * Budget alat ini hidup dalam µm — itu satuan sheet `PERHITUNGAN U95%`
     * master, dan `MicrometerMasterTest` mengadu tiap komponennya ke sana dalam
     * µm. Tapi KOLOM tabelnya (`titik_ukur`, `rata_rata`, `koreksi`) mm, dan di
     * 24 alat lain kolom ketidakpastian selalu sesatuan dengan koreksi. Lembar
     * ini sempat jadi satu-satunya yang tidak, dan akibatnya sertifikat
     * mencetak `0,00027` dan `0,871` di kolom yang sama.
     *
     * Konversinya di SINI, di ujung tulis — bukan di kalkulator — supaya
     * pembanding lantai CMC (yang memang µm) dan jejak auditnya tetap apa
     * adanya seperti master.
     */
    private static function umKeMm(float $um): float
    {
        return $um / 1000.0;
    }

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
    private function jejakAudit(array $hasil, array $h, ?CalibrationCapability $kemampuan): array
    {
        $budget = array_map(fn (array $k): array => $this->barisAudit($k), $hasil['budget']);

        $budget[] = $this->barisPerbandinganCmc(
            (float) $hasil['ketidakpastian_diperluas'],
            $hasil['pita_cmc']['u95_um'] ?? null,
            self::SATUAN_BUDGET,
        );

        $budget[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'Tumpukan balok ukur %s mm · total nominal %s mm · rata-rata pembacaan %s mm · '
                .'koreksi %s mm · U sebelum lantai CMC %s µm · pita CMC %s',
                implode(' + ', array_map(
                    static fn ($n): string => (string) $n,
                    array_filter($h['nominal'], static fn ($n): bool => $n !== null),
                )) ?: '-',
                $h['total_nominal'], $h['rata_rata'], $h['koreksi'],
                $hasil['ketidakpastian_diperluas'],
                $hasil['pita_cmc']['label'] ?? 'DI LUAR PITA — U95 tidak diterbitkan',
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
            'judul' => 'Identitas Alat dan Data Customer',
            'field' => [
                $this->field('equipment_id', 'Nama Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Nama Alat', 'teks', sumber: 'otomatis'),
                $this->field('alat_merk', 'Merk', 'teks'),
                $this->field('alat_model', 'Type', 'teks'),
                $this->field('alat_serial_number', 'No. Seri', 'teks'),
                // Satuan lebih dulu, karena dia yang mengubah arti tiga field
                // di bawahnya. Lihat [SATUAN_PILIHAN].
                $this->field('spesifikasi_alat.micrometer.satuan', 'Satuan Alat', 'pilihan', pilihan: [
                    ['nilai' => 'mm', 'label' => 'mm'],
                    ['nilai' => 'inch', 'label' => 'inch'],
                    ['nilai' => 'µm', 'label' => 'µm'],
                ]),
                $this->field('spesifikasi_alat.rentang_ukur', 'Rentang Ukur', 'teks'),
                $this->field('spesifikasi_alat.micrometer.kapasitas_mm', 'Kapasitas Max.', 'angka', satuan: self::SATUAN),
                $this->field('spesifikasi_alat.micrometer.resolusi_mm', 'Resolusi Alat', 'angka', satuan: self::SATUAN),
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
     * Baris `Evaluasi` kertas — sepuluh pembacaan berulang, satu baris.
     *
     * Dari sini pengulangan (Type A) lahir, BUKAN dari lima pembacaan tiap
     * titik. Kertas menaruhnya sebagai satu baris ber-kolom X1..X10 di bawah
     * tabel utama, dan balok ukur yang dipakainya ditentukan varian — teknisi
     * tidak memilihnya, sama seperti nominal titik.
     *
     * @return array<string, mixed>
     */
    private function bagianEvaluasi(): array
    {
        return [
            'kode' => 'evaluasi',
            'halaman' => 1,
            'judul' => 'Evaluasi',
            'field' => [],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => 'pra_pembacaan',
                    'judul' => 'Evaluasi (pembacaan berulang)',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Evaluasi',
                    'judul_pengulangan' => 'Pembacaan',
                    'titik_bisa_diubah' => false,
                    // Geser kunci baris tabel ini DI LAYAR supaya tidak pernah
                    // rebutan dengan sebelas baris `Data Kalibrasi`, yang
                    // ber-`tahap` sama persis dan karena itu ber-`kunciTabel`
                    // sama juga (`peran` null → kuncinya jatuh ke `tahap`).
                    //
                    // Hari ini keduanya kebetulan tidak bertabrakan: baris ini
                    // `titik_ukur`-nya null, jadi HP memakai `nomor` = 1
                    // sebagai kuncinya, dan 1,0 mm tidak ada di keempat puluh
                    // empat nominal pra-cetak. Itu KEBETULAN, bukan jaminan —
                    // balok ukur 1 mm ada di set 32 keping, dan kertas revisi
                    // berikutnya bisa memakainya kapan saja.
                    //
                    // Tabrakannya sudah pernah nyata di Timbangan (Accuracy
                    // 50 kg vs Repeatability Middle 50 kg): dua tabel berbagi
                    // satu baris isian, angka yang diketik di salah satunya
                    // muncul di kotak satunya lagi, tanpa satu pun error. Di
                    // sini akibatnya lebih mahal — baris Evaluasi yang
                    // tertimpa membuat pengulangan lahir dari angka titik ukur,
                    // dan U95 seluruh sesi ikut salah.
                    //
                    // Angkanya cuma harus TIDAK bertabrakan; 1000 mengikuti
                    // Timbangan, dan jauh di atas kapasitas terakreditasi
                    // paling besar (100 mm).
                    'offset_kunci' => 1000,
                    'simpan_ke' => 'spesifikasi_alat.micrometer.pra_evaluasi',
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

    /**
     * Tabel `Data Kalibrasi` kertas: sebelas nominal balok ukur PRA-CETAK,
     * lima pembacaan alat tiap barisnya.
     *
     * `titik_bisa_diubah = false` — dan itu inti bedanya dari lembar lain.
     * Nominalnya dipatok kertas per rentang, dan tumpukan keping yang
     * membentuknya ditentukan Instruksi Kerja. Teknisi tidak memilih, tidak
     * menambah, tidak mengurangi; yang dia isi cuma pembacaannya.
     *
     * Waktu alatnya belum diketahui (panggilan tanpa `$equipment`), barisnya
     * tetap sebelas tapi `titik_ukur`-nya null — bentuk lembarnya benar,
     * angkanya menyusul begitu alatnya dipilih.
     *
     * @param  array<string, mixed>|null  $varian
     * @return array<string, mixed>
     */
    private function bagianDataKalibrasi(?array $varian): array
    {
        $titik = $varian['titik'] ?? [];

        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => 'Data Kalibrasi',
            'field' => [],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => MicrometerMentah::PERAN_PEMBACAAN,
                    'judul' => 'Pembacaan Alat',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Nominal Balok Ukur',
                    'judul_pengulangan' => 'Pembacaan Alat',
                    'titik_bisa_diubah' => false,
                    'baris' => array_map(
                        static fn (int $n): array => [
                            'nomor' => $n,
                            'titik_ukur' => isset($titik[$n - 1])
                                ? (float) $titik[$n - 1]['nominal_cetak_mm']
                                : null,
                            'label' => isset($titik[$n - 1])
                                ? rtrim(rtrim(number_format(
                                    (float) $titik[$n - 1]['nominal_cetak_mm'], 1, ',', '.'
                                ), '0'), ',')
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
}
