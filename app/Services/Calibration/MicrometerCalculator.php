<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Mesin hitung **Micrometer** (lampiran akreditasi LK-285-IDN no. 34).
 *
 * Empat workbook master turun dari lab — 0-25, 25-50, 50-75, dan 75-100 mm —
 * dan sheet `PERHITUNGAN` serta `PERHITUNGAN U95%` keempatnya identik baris
 * demi baris. Yang membedakan cuma pita CMC, kapasitas, dan balok ukur yang
 * dipakai. Jadi satu mesin untuk empat rentang; pita CMC-nya datang dari
 * `TabelStandarMicrometer`, bukan dari sini.
 *
 * ## Satu budget untuk SATU SESI, bukan per titik
 *
 * Sheet `PERHITUNGAN U95%` master cuma punya satu kolom — sembilan komponen,
 * satu `Uc`, satu `k`, satu `U95` — dan sertifikatnya mencetak satu baris
 * `Uncertainty U95% = ±` di bawah sebelas titik. Ketidakpastiannya memang
 * lahir sekali per sesi, bukan per titik, makanya profilnya lewat
 * `hitungPerGrup()` dan bukan `komponenBudget()`.
 *
 * Dua komponennya juga tidak bisa dinyatakan per titik: pengulangan diambil
 * dari **pra-evaluasi** (sepuluh pembacaan berulang di satu titik, sheet
 * `PERHITUNGAN` baris 25) dan bukan dari sebaran lima pembacaan tiap titik,
 * dan suhu diisi SEKALI per sesi (baris 31) untuk balok ukur dan UUT.
 *
 * ## Bentuk satu titik
 *
 * Satu titik = tumpukan balok ukur (sampai tiga keping di-*wringing*) yang
 * nilai terkoreksinya dijumlahkan jadi `Total Nominal`, dibaca alat sampai
 * lima kali:
 *
 *     standar terkoreksi = Σ nilai_sertifikat(balok) × (1 + α·Δϴ + αs·Δα) − ld − lw − lg
 *     koreksi            = standar terkoreksi − rata-rata pembacaan
 *
 * Suku koreksi termal dan ketiga suku deformasi (`ld`, `lw`, `lg`) ada di
 * master tapi tidak pernah terisi di keempat workbook — nol semua. Ditiru apa
 * adanya: kalau lab mulai mengisinya, jalurnya sudah ada.
 *
 * ## Penyimpangan master yang TIDAK ditiru
 *
 * Tiga hal dihitung berbeda di sini. Ketiganya membuat ketidakpastian kita
 * LEBIH BESAR atau menolak menerbitkan, tidak pernah diam-diam lebih kecil,
 * dan arahnya ditegakkan test:
 *
 *  1. **Lantai CMC hilang waktu kapasitas di luar pita.** Master memulangkan
 *     teks `"cek range"`, lalu `MAX()` di sel U95-sertifikat mengabaikan teks
 *     dan angka terbit tanpa lantai. Di workbook 0-25 mm itu nyata terjadi:
 *     satuannya tersetel `inch`, kapasitas 25 dikali 25,4 jadi 635 mm, jatuh
 *     di luar keempat pita, dan yang tercetak **0,735 µm padahal pita
 *     terakreditasinya 0,83 µm**. Di sini sesi tanpa pita CMC DIBLOKIR, bukan
 *     diterbitkan. Lihat [TabelStandarMicrometer::pitaCmc].
 *  2. **Umur drift dari `NOW()`.** `DATABASE!X11` master berisi `=NOW()`, jadi
 *     komponen drift tumbuh tiap kali berkasnya dibuka dan U95 sesi yang sama
 *     tidak pernah terulang. Terbukti kasat mata: keempat workbook disimpan
 *     selang dua menit dan umur driftnya beda — 695,4212 vs 695,4225 hari. Di
 *     sini umurnya dihitung dari **tanggal kalibrasi sesi**, yang jelas
 *     maksudnya dan bisa diulang.
 *  3. **Panjang untuk koefisien sensitivitas.** Master memakai
 *     `PERHITUNGAN!F61` — nilai balok ukur PERTAMA di tumpukan titik terakhir,
 *     bukan total nominalnya. Waktu titik terakhir cuma satu keping (25-50,
 *     50-75, 75-100 mm) keduanya kebetulan sama; waktu ditumpuk tidak: di
 *     0-25 mm master memakai 6 mm padahal tumpukannya 6 + 19 = 25 mm. Di sini
 *     dipakai total nominal TERBESAR di sesi itu.
 *
 * Selisih no. 3 tidak menggeser angka yang tercetak hari ini — komponen suhu
 * dan muai menyumbang ~5·10⁻¹⁰ dari `Uc²` 0,14 — tapi dia jadi nyata begitu
 * ruangan menyimpang jauh dari 20 °C.
 *
 * ## Yang DITIRU walau janggal
 *
 * Dua kejanggalan METODE ditiru apa adanya dan diangkat ke
 * `docs/pertanyaan-lab-micrometer.md`, karena yang berhak memutuskan manajer
 * teknis lab:
 *
 *  - Komponen "Perubahan suhu" dan "Koefisien muai thermal" memakai nilai
 *    besaran itu sendiri sebagai ketidakpastiannya (`u(Δϴ) = Δϴ`,
 *    `u(α) = Δα`), sehingga sumbangan keduanya identik menurut konstruksi.
 *  - Semua komponen Type B diberi `vi = 200`, bukan tak hingga.
 */
class MicrometerCalculator
{
    private ?GumCalculator $gum = null;

    private ?TabelStandarMicrometer $tabel = null;

    /**
     * Ketidakpastian baku gabungan satu tumpukan balok ukur (µm) — akar jumlah
     * kuadrat ketidakpastian tiap keping, meniru `PERHITUNGAN!O25` master.
     *
     * Slot kosong disaring DI SINI, bukan di tabel. Di master penjagaannya
     * kebetulan: sel kosong berisi teks `""`, dan Excel menilai teks selalu
     * lebih besar dari angka berapa pun, jadi `"" <= 10` salah dan tangganya
     * jatuh ke cabang terakhir (0). PHP tidak berperilaku begitu — nominal
     * `null`/`0` akan lolos ke cabang `<= 10` dan memungut 0,12 µm yang bukan
     * haknya.
     *
     * @param  list<float|null>  $nominal  nominal tiap keping (mm); null/0 = slot kosong
     */
    public function ketidakpastianTumpukan(array $nominal): float
    {
        $tabel = $this->tabel();
        $jumlah = 0.0;

        foreach ($nominal as $n) {
            if ($n === null || (float) $n <= 0.0) {
                continue;
            }

            $jumlah += $tabel->ketidakpastianBalok((float) $n) ** 2;
        }

        return sqrt($jumlah);
    }

    /**
     * Total nominal satu titik (mm) — jumlah nilai TERKOREKSI tiap keping
     * menurut sertifikat balok ukur, bukan nominal cetaknya.
     *
     * Balik `null` kalau salah satu keping tidak ada di tabel standar. Master
     * membungkusnya `IFERROR(...; "")`, jadi titiknya lenyap dari sertifikat
     * tanpa error; di sini `null` wajib diangkat pemanggil jadi titik yang
     * diblokir dengan alasan kebaca.
     *
     * @param  list<float|null>  $nominal
     */
    public function totalNominal(array $nominal): ?float
    {
        $tabel = $this->tabel();
        $total = 0.0;
        $adaKeping = false;

        foreach ($nominal as $n) {
            if ($n === null) {
                continue;
            }

            // Nominal 0 = titik nol mikrometer (rahang tertutup, tanpa balok
            // ukur). Sah, dan totalnya memang nol — bukan slot kosong.
            if ((float) $n === 0.0) {
                $adaKeping = true;

                continue;
            }

            $nilai = $tabel->nilaiTerkoreksi((float) $n);

            if ($nilai === null) {
                return null;
            }

            $total += $nilai;
            $adaKeping = true;
        }

        return $adaKeping ? $total : null;
    }

    /**
     * Umur sertifikat balok ukur dalam hari pada `$tanggalKalibrasi`.
     *
     * Master memakai `NOW()`; di sini tanggal sesi — lihat penyimpangan no. 2
     * di docblock kelas.
     *
     * Balik `null` kalau sesi dikalibrasi SEBELUM sertifikat balok ukur yang
     * sekarang tersimpan. Umur negatif tidak punya arti fisik, dan
     * membiarkannya lewat berarti komponen drift MENGURANGI ketidakpastian.
     *
     * `null` di sini BUKAN penanda sesi rusak — dia penanda catatan yang tidak
     * lengkap. Tabel standar cuma menyimpan sertifikat terakhir (2024-01-24),
     * sementara tiga dari empat sesi master lab berasal dari sebelum itu.
     * Pemanggil menyetel driftnya nol, mencatat alasannya, dan tetap
     * menerbitkan di atas lantai CMC — lihat [hitungSesi].
     */
    public function umurStandarHari(\DateTimeInterface $tanggalKalibrasi): ?float
    {
        $standar = new \DateTimeImmutable($this->tabel()->standar()['tanggal_kalibrasi']);
        $hari = ($tanggalKalibrasi->getTimestamp() - $standar->getTimestamp()) / 86400;

        return $hari >= 0 ? $hari : null;
    }

    /**
     * Simpangan baku contoh (n-1), sama dengan `STDEV()` Excel.
     *
     * @param  list<float>  $nilai
     */
    public function simpanganBaku(array $nilai): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $rata = array_sum($nilai) / $n;
        $jumlah = array_sum(array_map(static fn (float $x): float => ($x - $rata) ** 2, $nilai));

        return sqrt($jumlah / ($n - 1));
    }

    /**
     * Hitung SATU sesi: sebelas titik + satu budget, meniru sheet `PERHITUNGAN`
     * dan `PERHITUNGAN U95%` master.
     *
     * Titik yang tidak bisa dihitung dilaporkan lewat `ditolak`, tidak dibuang
     * diam-diam — itu bedanya dari `IFERROR(...; "")` master, yang membuat
     * titiknya hilang dari sertifikat tanpa jejak.
     *
     * @param  list<array{titik_ke: int, nominal: list<float|null>, pembacaan: list<float>}>  $titik
     * @param  array{kapasitas_mm: float, resolusi_mm: float, tanggal_kalibrasi: \DateTimeInterface, pra_evaluasi: list<float>, balok_pra_evaluasi: list<float|null>, suhu_ruang_rata_c: float}  $konteks
     * @return array{titik: list<array<string, mixed>>, budget: list<array<string, mixed>>, ketidakpastian_gabungan: float, derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float, ketidakpastian_diperluas: float, u95_sertifikat: float, type_b: float, pita_cmc: array<string, mixed>|null, boleh_terbit: bool, ditolak: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungSesi(array $titik, array $konteks): array
    {
        $ditolak = [];
        $dihitung = [];

        foreach ($titik as $t) {
            $total = $this->totalNominal($t['nominal']);

            if ($total === null) {
                $ditolak[] = [
                    'titik_ke' => $t['titik_ke'],
                    'alasan' => 'Nominal balok ukur tidak ada di tabel standar — titik tidak dihitung.',
                ];

                continue;
            }

            $pembacaan = array_values(array_filter(
                $t['pembacaan'],
                static fn ($x): bool => $x !== null && $x !== '',
            ));

            if ($pembacaan === []) {
                $ditolak[] = [
                    'titik_ke' => $t['titik_ke'],
                    'alasan' => 'Tidak ada pembacaan alat — titik tidak dihitung.',
                ];

                continue;
            }

            $pembacaan = array_map(static fn ($x): float => (float) $x, $pembacaan);
            $rata = array_sum($pembacaan) / count($pembacaan);

            $dihitung[] = [
                'titik_ke' => $t['titik_ke'],
                'nominal' => $t['nominal'],
                'total_nominal' => $total,
                'standar_terkoreksi' => $total,
                'pembacaan' => $pembacaan,
                'rata_rata' => $rata,
                'koreksi' => $total - $rata,
                'simpangan_baku' => $this->simpanganBaku($pembacaan),
                'jumlah_pengulangan' => count($pembacaan),
            ];
        }

        $pita = $this->tabel()->pitaCmc($konteks['kapasitas_mm']);

        if ($dihitung === []) {
            return [
                'titik' => [], 'budget' => [], 'ketidakpastian_gabungan' => 0.0,
                'derajat_kebebasan_efektif' => null, 'faktor_cakupan_k' => 2.0,
                'ketidakpastian_diperluas' => 0.0, 'u95_sertifikat' => 0.0,
                'type_b' => 0.0, 'pita_cmc' => $pita, 'boleh_terbit' => false,
                'ditolak' => $ditolak,
            ];
        }

        // Budget tetap dihitung walau pita CMC-nya hilang. Yang diblokir
        // penerbitan U95-nya, bukan perhitungannya: admin yang membaca sesi
        // bermasalah butuh melihat sembilan komponennya untuk tahu APA yang
        // salah — sesi kosong tanpa angka cuma bikin dia menekan "setujui
        // tetap" tanpa bisa memeriksa.
        $budget = $this->budget($dihitung, $konteks, $ditolak);
        $agregat = $this->gum()->agregasiBudget(array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $budget,
        ));

        // Lantai CMC: master menutup sel U95-sertifikat dengan `MAX(U95; CMC)`.
        // Yang terbit tidak boleh lebih kecil dari kemampuan terbaik yang diakui
        // lampiran akreditasi. Tanpa pita, lantainya tidak ada — dan angka
        // tanpa lantai justru yang membuat master 0-25 mm menerbitkan 0,735 µm
        // di bawah 0,83 µm miliknya sendiri. Jadi `0.0` = belum terbit.
        if ($pita === null) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => sprintf(
                    'Kapasitas %s mm di luar keempat pita CMC terakreditasi (0-100 mm) — '.
                    'U95 tidak diterbitkan karena tidak punya lantai CMC.',
                    rtrim(rtrim(number_format($konteks['kapasitas_mm'], 4, '.', ''), '0'), '.'),
                ),
            ];
        }

        $u95 = $pita === null
            ? 0.0
            : max($agregat['ketidakpastian_diperluas'], (float) $pita['u95_um']);

        // RSS komponen Type B saja — aturannya disamakan dengan
        // `GumCalculator::hitungDariBudget()` biar kolom `type_b` tidak beda
        // arti antar-alat.
        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter($budget, static fn (array $k): bool => $k['distribusi'] !== 't-student'),
        )));

        return [
            'titik' => $dihitung,
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agregat['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $agregat['ketidakpastian_diperluas'],
            'u95_sertifikat' => $u95,
            'type_b' => $typeB,
            'pita_cmc' => $pita,
            // Boleh terbit cuma kalau DUA-DUANYA berdiri: ada pita CMC sebagai
            // lantai, DAN pengulangan punya dasar.
            //
            // Kedua syarat ini DIPATOK eksplisit, bukan diturunkan dari "tidak
            // ada satu pun penolakan". Bedanya menentukan: `budget()` juga
            // mencatat hal yang TIDAK menahan (umur drift negatif pada sesi
            // historis), dan menggabungkan keduanya membuat tiga dari empat
            // sesi master lab berhenti bisa dihitung ulang — hukuman yang
            // menimpa catatan sertifikat lama, bukan pengukurannya.
            //
            // Yang kedua bukan kehati-hatian berlebih: `budget()` menolak
            // pra-evaluasi berisi kurang dari dua pembacaan, dan tanpa gerbang
            // ini barisnya tetap terbit dengan pengulangan NOL. Itu persis
            // bentuk "sel kosong dibaca nol" yang aturan proyek larang ditiru —
            // U95-nya jatuh ke lantai CMC dan kelihatan wajar, padahal sesi itu
            // belum pernah diuji keterulangannya.
            //
            // RESOLUSI ikut jadi syarat, dan bentuknya paling licin dari
            // ketiganya. Kotak resolusi boleh kosong (`semua_kolom_opsional`),
            // dan yang kosong terbaca 0 — komponen resolusi jadi
            // `(0 × 1000 / 2) / √3` alias nol. Diukur pada sesi contoh 25-50 mm:
            //
            //   resolusi 0,001 mm -> uc 0,4439 µm -> U95 0,8722 µm (terbit 0,8722)
            //   resolusi kosong   -> uc 0,3372 µm -> U95 0,6638 µm (terbit 0,8700)
            //
            // Yang terbit berubah 0,8722 → 0,8700, dan angka kedua itu PERSIS
            // lantai CMC pita B. Jadi lantai yang seharusnya jadi penjaga malah
            // menyamarkan komponen yang hilang: selisihnya 0,25 %, dan tidak
            // ada satu pun error di sepanjang jalurnya.
            'boleh_terbit' => $pita !== null
                && count($konteks['pra_evaluasi']) >= 2
                && (float) $konteks['resolusi_mm'] > 0.0,
            'ditolak' => $ditolak,
        ];
    }

    /**
     * Sembilan komponen budget satu sesi (µm), urutannya sama dengan sheet
     * `PERHITUNGAN U95%` master baris 5 sampai 13.
     *
     * @param  list<array<string, mixed>>  $dihitung
     * @param  array<string, mixed>  $konteks
     * @param  list<array{titik_ke: int, alasan: string}>  $ditolak
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>
     */
    private function budget(array $dihitung, array $konteks, array &$ditolak): array
    {
        $k = $this->tabel()->konstanta();
        $akar3 = sqrt(3.0);

        // Panjang untuk koefisien sensitivitas: total nominal TERBESAR di sesi,
        // bukan `F61` master (keping pertama tumpukan terakhir) — lihat
        // penyimpangan no. 3 di docblock kelas.
        $panjangMm = max(array_map(
            static fn (array $t): float => (float) $t['total_nominal'],
            $dihitung,
        ));

        // Suhu balok ukur & suhu UUT DITURUNKAN dari rata-rata suhu ruangan,
        // tidak diminta terpisah — dan itu bukan penyederhanaan kita.
        //
        // Di KEEMPAT workbook master `O31` (suhu balok) dan `P31` (suhu UUT)
        // berisi angka yang SAMA, dan angka itu persis `(suhu_awal +
        // suhu_akhir) / 2`:
        //
        //   0-25 mm    20,6 & 20,7 -> 20,65   O31 = P31 = 20,65
        //   25-50 mm   20,5 & 20,6 -> 20,55   O31 = P31 = 20,55
        //   50-75 mm   20,5 & 20,6 -> 20,55   O31 = P31 = 20,55
        //   75-100 mm  20,6 & 20,2 -> 20,40   O31 = P31 = 20,40
        //
        // Itu sebabnya kertas lembar kerja (`SIDIK-FM-CAL-0522`) tidak punya
        // kotak untuk keduanya: yang dipungut cuma suhu ruangan. Meminta
        // keduanya lagi berarti membuka jalan buat teknisi mengisi angka yang
        // BERTENTANGAN dengan suhu ruangan yang dia tulis sendiri.
        $suhuRata = (float) $konteks['suhu_ruang_rata_c'];
        $deltaSuhu = $suhuRata - (float) $k['suhu_acuan_c'];

        // Konsekuensinya komponen ke-9 selalu NOL — dia `|suhu_uut − suhu_balok|`
        // dari dua angka yang sama menurut konstruksi. Ditiru apa adanya (master
        // juga selalu memulangkan nol di sini) supaya urutan sembilan komponen
        // budget tetap sama dengan sheet `PERHITUNGAN U95%`, dan supaya begitu
        // lab mulai mengukur keduanya terpisah, tempatnya sudah ada.
        $selisihSuhu = 0.0;
        $deltaAlpha = (float) $k['delta_alpha_per_c'];

        // ci suku termal: ∂L/∂ϴ = Δα · L untuk komponen suhu, dan L · Δϴ untuk
        // komponen muai. Master memakai nilai besaran itu sendiri sebagai
        // ketidakpastiannya, jadi sumbangan keduanya identik menurut
        // konstruksi — ditiru, dan diangkat jadi pertanyaan lab.
        $ciSuhu = $deltaAlpha * $panjangMm;
        $ciMuai = $panjangMm * $deltaSuhu;

        // Pengulangan dari PRA-EVALUASI (sepuluh pembacaan berulang di satu
        // titik), bukan dari sebaran lima pembacaan tiap titik.
        $praEvaluasi = array_map(static fn ($x): float => (float) $x, $konteks['pra_evaluasi']);
        $nUlang = count($praEvaluasi);
        $stdevUm = $this->simpanganBaku($praEvaluasi) * 1000;

        if ($nUlang < 2) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => 'Pra-evaluasi butuh minimal dua pembacaan berulang untuk simpangan baku.',
            ];
        }

        if ((float) $konteks['resolusi_mm'] <= 0.0) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => 'Resolusi alat belum diisi. Tanpa resolusi, komponen resolusi budget '
                    .'bernilai nol dan U95 terbit lebih kecil dari seharusnya — pada sesi 25-50 mm '
                    .'0,8722 µm jadi 0,6638 µm, lalu ditutupi lantai CMC 0,87 µm sehingga tampak wajar.',
            ];
        }

        $umurHari = $this->umurStandarHari($konteks['tanggal_kalibrasi']);

        // Umur negatif TIDAK menahan penerbitan — dia dicatat, driftnya nol.
        //
        // Sesi yang mendahului sertifikat balok ukur yang sekarang tersimpan
        // itu sesi HISTORIS, dan tabel standar kita cuma menyimpan sertifikat
        // terakhir. Menahannya berarti tiga dari empat sesi master lab (dan
        // tiap sesi lama di produksi) berhenti bisa dihitung ulang — padahal
        // yang hilang cuma catatan sertifikat lama, bukan pengukurannya.
        //
        // Yang menjaga angkanya tetap jujur: drift itu sifat STANDAR, bukan
        // alat yang dikalibrasi, sumbangannya kecil (0,06 µm dari uc 0,44 di
        // sesi 25-50 mm), dan lantai CMC tetap berlaku — tanpa drift, uc turun
        // sedikit dan yang terbit justru lantai terakreditasinya.
        //
        // Bedanya dari pra-evaluasi yang kosong (yang MEMANG menahan):
        // pengulangan mengukur alat pelanggan itu sendiri, dan sesi tanpa dia
        // belum mengukur apa yang diakui sertifikatnya.
        if ($umurHari === null) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => 'Tanggal kalibrasi sesi lebih awal dari tanggal kalibrasi balok ukur '
                    .'standar yang tersimpan, jadi umur drift tidak bisa dihitung dan komponennya '
                    .'dianggap nol. Lantai CMC tetap berlaku.',
            ];
            $umurHari = 0.0;
        }

        $drift = ((float) $k['drift_a_um'] + (float) $k['drift_b_um_per_mm'] * (float) $konteks['kapasitas_mm'])
            * ($umurHari / 365);

        return [
            [
                'sumber' => 'pengulangan',
                'keterangan' => 'Repeatability (pra-evaluasi)',
                'distribusi' => 't-student',
                'u' => $nUlang >= 2 ? $stdevUm / sqrt($nUlang) : 0.0,
                'ci' => 1.0,
                'vi' => $nUlang >= 2 ? (float) ($nUlang - 1) : 0.0,
            ],
            [
                'sumber' => 'resolusi_uut',
                'keterangan' => 'Resolusi',
                'distribusi' => 'rectangular',
                'u' => ((float) $konteks['resolusi_mm'] * 1000 / 2) / $akar3,
                'ci' => 1.0,
                'vi' => (float) $k['vi_type_b'],
            ],
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => 'Standard balok ukur',
                'distribusi' => 'normal',
                'u' => $this->ketidakpastianTumpukan($konteks['balok_pra_evaluasi']) / 2,
                'ci' => 1.0,
                'vi' => (float) $k['vi_type_b'],
            ],
            [
                'sumber' => 'suhu_ruang',
                'keterangan' => 'Perubahan suhu terhadap suhu acuan 20 °C',
                'distribusi' => 'rectangular',
                'u' => $deltaSuhu / $akar3,
                'ci' => $ciSuhu,
                'vi' => (float) $k['vi_type_b'],
            ],
            [
                'sumber' => 'koefisien_muai',
                'keterangan' => 'Koefisien muai thermal',
                'distribusi' => 'rectangular',
                'u' => $deltaAlpha / $akar3,
                'ci' => $ciMuai,
                'vi' => (float) $k['vi_type_b'],
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => 'Drift standard',
                'distribusi' => 'rectangular',
                'u' => $drift / $akar3,
                'ci' => 1.0,
                'vi' => (float) $k['vi_type_b'],
            ],
            [
                'sumber' => 'wringing',
                'keterangan' => 'Lapisan wringing',
                'distribusi' => 'rectangular',
                'u' => (float) $k['wringing_um'] / $akar3,
                'ci' => 1.0,
                'vi' => (float) $k['vi_type_b'],
            ],
            [
                'sumber' => 'geometri',
                'keterangan' => 'Kesalahan geometri',
                'distribusi' => 'rectangular',
                'u' => (float) $k['geometri_um'] / $akar3,
                'ci' => 1.0,
                'vi' => (float) $k['vi_type_b'],
            ],
            [
                'sumber' => 'selisih_suhu',
                'keterangan' => 'Selisih suhu mikrometer dengan balok ukur',
                'distribusi' => 'rectangular',
                'u' => $selisihSuhu / $akar3,
                'ci' => $ciSuhu,
                'vi' => (float) $k['vi_type_b'],
            ],
        ];
    }

    private function tabel(): TabelStandarMicrometer
    {
        // Malas, bukan di parameter bawaan konstruktor: profil -> kalkulator ->
        // GumCalculator -> CalibrationProfileRegistry -> profil itu lagi
        // membuat lingkaran yang gejalanya (`Infinite recursion?`) muncul jauh
        // dari penyebabnya.
        return $this->tabel ??= new TabelStandarMicrometer;
    }

    private function gum(): GumCalculator
    {
        return $this->gum ??= new GumCalculator;
    }
}
