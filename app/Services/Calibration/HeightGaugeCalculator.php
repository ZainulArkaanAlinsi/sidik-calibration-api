<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Mesin hitung **Height Gauge 600 mm** — alat ke-26, kelompok Panjang.
 *
 * Satu workbook master turun dari lab (`Master_olda_Height_Gauge_600_mm_2026.xlsm`,
 * ber-password). Tidak ada `vbaProject.bin` di dalamnya: ekstensinya
 * `.xlsm` tapi checkbox "Good / Not Good"-nya Form Control biasa, jadi tidak
 * ada logika tersembunyi yang perlu dibaca ulang.
 *
 * ## Tiga blok yang TIDAK sebangun — dan cuma satu yang berbentuk titik ukur
 *
 * Ini yang paling membedakan alat ini dari 25 saudaranya. Satu sesi memuat:
 *
 *  1. **Paralelisme Ujung Scriber** — tiga pembacaan, tingkat SESI. Tidak masuk
 *     budget, tidak melahirkan titik ukur; dia catatan kelulusan yang dicetak
 *     di kaki sertifikat. Lihat [paralelisme].
 *  2. **Evaluation / pra-evaluasi** — sepuluh pembacaan berulang di nominal
 *     kapasitas, tingkat SESI. Satu-satunya sumber Repeatability seluruh sesi.
 *  3. **Measurement** — sepuluh titik ukur ber-nominal pra-cetak.
 *
 * Blok 1 dan 2 tinggal di `spesifikasi_alat`, bukan sebagai `titik_ke` — blok
 * tanpa titik yang dipaksa ke situ lahir sebagai titik hantu yang selalu gagal
 * hitung ulang.
 *
 * ## Satu budget untuk SATU SESI, bukan per titik
 *
 * Sheet `PERHITUNGAN U95%` cuma punya satu kolom, dan sertifikatnya mencetak
 * satu baris `Uncertainty U95% = ±` di bawah sepuluh titik. Makanya profilnya
 * lewat `hitungPerGrup()` dan `komponenBudget()` memulangkan `null`.
 *
 * ## Budget-nya hidup dalam mm, BUKAN µm
 *
 * `AF18 = I5 = "mm"`. Ini jebakan yang paling gampang kelewat kalau
 * [MicrometerCalculator] dipakai sebagai contekan: di sana budget-nya µm dan
 * profilnya membagi 1000 di ujung tulis. Di sini tidak ada konversi apa pun —
 * kolom tabel dan kolom ketidakpastian sama-sama mm.
 *
 * ## Bentuk satu titik
 *
 *     standar terkoreksi (Y) = H + H·((α_avg·δϴ) + (ϴ·δα)) − ld − lw − lg − lf
 *     koreksi           (AA) = Y − rata-rata pembacaan
 *
 * `H` = `SUM(F:G)`, jumlah nilai terkoreksi slot nominal titik itu. Master
 * menyediakan tiga slot per titik (sisa bentuk *wringing* dari master Jangka
 * Sorong) tapi sesi contoh cuma mengisi slot pertama — jadi lembar kerjanya
 * satu kolom nominal, sementara jalur `SUM` beberapa slot tetap disediakan
 * supaya tidak perlu dibongkar kalau lab mulai menumpuk.
 *
 * Keempat suku deformasi (`ld`, `lw`, `lg`, `lf`) nol di seluruh sesi contoh.
 * Ditiru apa adanya: jalurnya ada, isinya nol.
 *
 * ## Penyimpangan master yang TIDAK ditiru
 *
 * Tiga hal dihitung berbeda di sini. Ketiganya membuat hasilnya lebih jujur
 * atau menolak menerbitkan, tidak pernah diam-diam lebih kecil:
 *
 *  1. **Kolom termal cuma terisi di baris pertama.** `PERHITUNGAN` kolom
 *     `M`..`T` (suhu std, suhu UUT, ϴ, αs, αt, δα, α avg, δϴ) cuma terisi di
 *     baris 35 — titik 25 mm. Baris 38 sampai 62 kosong seluruhnya, tapi rumus
 *     `Y` di baris-baris itu tetap menunjuk `S38`/`T38`/`O38`/`R38` yang
 *     kosong. Sel kosong dibaca nol, seluruh suku koreksinya lenyap, dan
 *     `Y = H` telanjang.
 *
 *     Hari ini tidak menggeser satu angka pun, karena di baris 35 pun
 *     `T35 = 0` dan `R35 = 0`. Tapi begitu lab mencatat suhu UUT ≠ suhu
 *     standar, **cuma titik pertama yang terkoreksi** dan sembilan lainnya
 *     diam-diam salah. Itu persis kategori "sel kosong dibaca nol" yang aturan
 *     proyek larang ditiru. Di sini suku termal dihitung untuk KESEPULUH titik
 *     dari nilai tingkat-sesi yang sama.
 *  2. **Umur drift dari `NOW()`.** `DATABASE!X11` berisi `=NOW()`, jadi U95
 *     sesi yang sama berubah tiap kali berkasnya dibuka — di snapshot yang kami
 *     terima dia 2026-06-11 15:55:46, sementara sesinya dikalibrasi
 *     2026-05-05. Di sini umurnya dari **tanggal kalibrasi sesi**, yang jelas
 *     maksudnya dan bisa diulang tahun depan dengan hasil yang sama.
 *  3. **`SERTIFIKAT!L27` cabang `inch` menunjuk `PERHITUNGAN!M44`** —
 *     rata-rata pembacaan — sementara sembilan baris tetangganya menunjuk
 *     `AA…` (koreksi). Salin-tempel murni; tidak terlihat hari ini karena
 *     satuannya `mm`. Di sini kolom koreksi satu jalur untuk semua satuan.
 *
 * ## Yang DITIRU walau janggal
 *
 * Tiga kejanggalan METODE ditiru apa adanya dan diangkat ke
 * `docs/pertanyaan-lab-height-gauge.md`, karena yang berhak memutuskan manajer
 * teknis lab:
 *
 *  - **Pembagi drift `/12` padahal selisihnya HARI** (`K10`), sementara satuan
 *    komponennya sendiri ditulis `mm/th`. Micrometer memakai `/365` untuk
 *    komponen yang sebangun. Dipertahankan karena membetulkannya ke `/365`
 *    membuat drift ~30× lebih kecil dan `U` yang terbit LEBIH KECIL
 *    (0,0156680 → 0,0154996 mm pada umur master) — dan aturan proyek melarang
 *    penyimpangan yang diam-diam mengecilkan ketidakpastian. Pertanyaan §1.
 *  - **Pembagi `√6` untuk komponen muai** (`N9 = SQRT(6)`) sementara `J9`
 *    menulis distribusinya `rect.`, yang pembaginya `√3`. Pertanyaan §2.
 *  - **Paralelisme dihitung `STDEV(Max; Min)`** alih-alih `Max − Min`.
 *    Pertanyaan §5. Lihat [paralelisme].
 *
 * ## TIDAK ADA lantai CMC — dan itu BENAR, bukan bug
 *
 * `AA20 = IF(AF20="mm"; MAX(AA18:AA19)/1; …)` dengan `AA19` KOSONG, jadi
 * `U95 = U` telanjang. Sebabnya bukan kelalaian: **Height Gauge tidak ada di
 * lampiran akreditasi LK-285-IDN.** Kelompok Panjang di
 * `database/data/kemampuan-kalibrasi.json` cuma memuat Sieve, Micrometer,
 * Vernier Caliper, dan Dial Indicator.
 *
 * `DATABASE!S5:T5` memang memuat `CMC 0-300mm = 15 µm` (defined name
 * `CMC_UTM`), tapi (a) tidak tersambung ke sheet U95 mana pun, dan (b) alatnya
 * 600 mm — di luar pita 0-300. Tidak dipungut.
 *
 * Konsekuensinya penjagaan komponen di sini justru LEBIH perlu daripada di
 * Micrometer, bukan kurang: di sana lantai CMC menyamarkan komponen yang hilang
 * (U95 mendarat di lantai dan tampak wajar), di sini tidak ada apa pun yang
 * menyamarkan **maupun** menahan — `U` langsung terbit terlalu kecil. Itu
 * sebabnya [hitungSesi] memblokir sesi yang pra-evaluasinya tidak berdasar atau
 * resolusinya kosong, alih-alih sekadar memperingatkan.
 */
class HeightGaugeCalculator
{
    /**
     * Pembagi umur drift: **365 hari**, bukan 12 seperti `K10` master.
     *
     * Lihat komentar di komponen drift — satuan komponennya mm/tahun dan umur
     * dihitung dalam hari. Paket keputusan butir 5, 16 Sep 2026.
     */
    public const PEMBAGI_UMUR_HARI = 365.0;

    private ?GumCalculator $gum = null;

    private ?TabelStandarHeightGauge $tabel = null;

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
     * Blok 1 — Paralelisme Ujung Scriber, tingkat SESI.
     *
     * ## `Max − Min`, sesudah lab menjawab §5 (16 Sep 2026)
     *
     * Master menulis `Hasil = STDEV(Max; Min)` — simpangan baku atas DUA angka,
     * yang secara aljabar `|Max − Min| / √2`: selalu 29 % lebih kecil dari
     * rentangnya. Paralelisme menurut ISO 1101 adalah RENTANG, yaitu jarak dua
     * bidang sejajar yang mengapit permukaannya, jadi pembagi √2 itu tidak
     * punya dasar.
     *
     * Sampai 15 Sep 2026 rumus master ditiru apa adanya, dan itu meluluskan
     * alat yang seharusnya gagal: pita 0,0100–0,0141 mm lolos batas 0,01 mm
     * cuma karena dibagi √2. Ini satu-satunya penyimpangan Height Gauge yang
     * arah salahnya MERUGIKAN penerima sertifikat, jadi begitu lab menjawab,
     * yang dipakai `Max − Min` (sesi contoh 0,0014142 → 0,002).
     *
     * Blok ini TIDAK masuk budget dan TIDAK melahirkan titik ukur. Hasil
     * "Not Good" juga tidak menahan penerbitan — itu hasil ukur, bukan cacat
     * data; dia dicetak apa adanya di kaki sertifikat.
     *
     * Balik `null` kalau pembacaannya kurang dari dua: satu pembacaan tidak
     * punya Max dan Min yang berbeda, dan "hasil 0" dari satu angka terbaca
     * seperti paralelisme sempurna.
     *
     * @param  array<int|string, mixed>  $pembacaan
     * @return array{maks: float, min: float, hasil: float, batas: float, lulus: bool}|null
     */
    public function paralelisme(array $pembacaan): ?array
    {
        $nilai = $this->deretAngka($pembacaan);

        if (count($nilai) < 2) {
            return null;
        }

        $maks = max($nilai);
        $min = min($nilai);
        $batas = (float) $this->tabel()->konstanta()['batas_paralelisme_mm'];
        // Rentang, bukan `STDEV(Max; Min)` master — lihat docblock.
        $hasil = $maks - $min;

        return [
            'maks' => $maks,
            'min' => $min,
            'hasil' => $hasil,
            'batas' => $batas,
            'lulus' => $hasil <= $batas,
        ];
    }

    /**
     * Umur sertifikat Caliper Checker dalam hari pada `$tanggalKalibrasi`.
     *
     * Master memakai `NOW()`; di sini tanggal sesi — lihat penyimpangan no. 2
     * di docblock kelas.
     *
     * Balik `null` kalau sesi dikalibrasi SEBELUM sertifikat Caliper Checker
     * yang sekarang tersimpan. Umur negatif tidak punya arti fisik, dan
     * membiarkannya lewat berarti komponen drift MENGURANGI ketidakpastian.
     *
     * `null` di sini BUKAN penanda sesi rusak — dia penanda catatan yang tidak
     * lengkap; tabel standar cuma menyimpan sertifikat terakhir. Pemanggil
     * menyetel driftnya nol, mencatat alasannya, dan TETAP menerbitkan —
     * perlakuan yang sama persis dengan
     * [MicrometerCalculator::umurStandarHari].
     */
    public function umurStandarHari(DateTimeInterface $tanggalKalibrasi): ?float
    {
        $standar = new DateTimeImmutable($this->tabel()->standar()['tanggal_kalibrasi']);
        $hari = ($tanggalKalibrasi->getTimestamp() - $standar->getTimestamp()) / 86400;

        return $hari >= 0 ? $hari : null;
    }

    /**
     * Hitung SATU sesi: sepuluh titik + satu budget sembilan komponen, meniru
     * sheet `PERHITUNGAN` dan `PERHITUNGAN U95%` master.
     *
     * Titik yang tidak bisa dihitung dilaporkan lewat `ditolak`, tidak dibuang
     * diam-diam.
     *
     * @param  list<array{titik_ke: int, nominal: array<int|string, mixed>, pembacaan: array<int|string, mixed>}>  $titik
     * @param  array{resolusi_mm: float, tanggal_kalibrasi: DateTimeInterface, pra_evaluasi: list<float>, paralelisme: list<float>, suhu_ruang_rata_c: float, suhu_uut_c?: float|null}  $konteks
     * @return array{titik: list<array<string, mixed>>, budget: list<array<string, mixed>>, paralelisme: array<string, mixed>|null, ketidakpastian_gabungan: float, derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float, ketidakpastian_diperluas: float, u95_sertifikat: float, type_b: float, boleh_terbit: bool, ditolak: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungSesi(array $titik, array $konteks): array
    {
        $ditolak = [];
        $dihitung = [];

        // Suhu Caliper Checker & suhu UUT DITURUNKAN dari rata-rata suhu
        // ruangan, tidak diminta terpisah — dan itu bukan penyederhanaan kami:
        // `PERHITUNGAN!M35` (suhu Caliper Checker) dan `N35` (suhu UUT) master
        // dua-duanya berisi 20,25, dan itu persis `(20,2 + 20,3) / 2`. Kertas
        // lembar kerjanya pun tidak punya kotak untuk keduanya.
        //
        // `suhu_uut_c` tetap bisa dioper terpisah supaya jalurnya HIDUP dan
        // bisa diuji: begitu lab menjawab pertanyaan §10 dengan "suhu UUT
        // diukur terpisah", yang berubah cuma sisi pemanggil.
        $suhuStandar = (float) $konteks['suhu_ruang_rata_c'];
        $suhuUut = isset($konteks['suhu_uut_c']) && is_numeric($konteks['suhu_uut_c'])
            ? (float) $konteks['suhu_uut_c']
            : $suhuStandar;

        $k = $this->tabel()->konstanta();
        $theta = $suhuStandar - (float) $k['suhu_acuan_c'];   // O = ϴ
        $deltaTheta = $suhuUut - $suhuStandar;                // T = δϴ
        // αs = αt di master, jadi δα = 0 dan α_avg = α. Ditulis sebagai dua
        // besaran terpisah, bukan disederhanakan jadi konstanta: `δα` di sini
        // BEDA dengan `delta_alpha_per_c` yang dipakai budget (2e-6), dan
        // menyamakan keduanya menggeser suku koreksi tiap titik. Lihat
        // pertanyaan lab §3.
        $alphaStandar = (float) $k['alpha_per_c'];
        $alphaUut = (float) $k['alpha_per_c'];
        $deltaAlphaTitik = $alphaUut - $alphaStandar;         // R = δα
        $alphaRata = ($alphaStandar + $alphaUut) / 2;         // S = α avg

        foreach ($titik as $t) {
            $total = $this->totalNominal($t['nominal']);

            if ($total === null) {
                $ditolak[] = [
                    'titik_ke' => $t['titik_ke'],
                    'alasan' => 'Nominal tidak ada di tabel Caliper Checker (Outside) — titik tidak dihitung.',
                ];

                continue;
            }

            $pembacaan = $this->deretAngka($t['pembacaan']);

            if ($pembacaan === []) {
                $ditolak[] = [
                    'titik_ke' => $t['titik_ke'],
                    'alasan' => 'Tidak ada pembacaan alat — titik tidak dihitung.',
                ];

                continue;
            }

            $rata = array_sum($pembacaan) / count($pembacaan);

            // Suku termal dihitung untuk KESEPULUH titik, bukan cuma titik
            // pertama — lihat penyimpangan no. 1 di docblock kelas. Keempat
            // suku deformasi (ld, lw, lg, lf) nol di seluruh sesi contoh;
            // jalurnya ada, isinya nol.
            $standarTerkoreksi = $total + $total * (($alphaRata * $deltaTheta) + ($theta * $deltaAlphaTitik));

            $dihitung[] = [
                'titik_ke' => $t['titik_ke'],
                'nominal' => $this->deretAngka($t['nominal']),
                'total_nominal' => $total,
                'standar_terkoreksi' => $standarTerkoreksi,
                'pembacaan' => $pembacaan,
                'rata_rata' => $rata,
                'koreksi' => $standarTerkoreksi - $rata,
                'simpangan_baku' => $this->simpanganBaku($pembacaan),
                'jumlah_pengulangan' => count($pembacaan),
            ];
        }

        $paralelisme = $this->paralelisme($konteks['paralelisme'] ?? []);

        if ($dihitung === []) {
            return [
                'titik' => [], 'budget' => [], 'paralelisme' => $paralelisme,
                'ketidakpastian_gabungan' => 0.0, 'derajat_kebebasan_efektif' => null,
                'faktor_cakupan_k' => 2.0, 'ketidakpastian_diperluas' => 0.0,
                'u95_sertifikat' => 0.0, 'type_b' => 0.0, 'boleh_terbit' => false,
                'ditolak' => $ditolak,
            ];
        }

        $budget = $this->budget($dihitung, $konteks, $theta, $deltaTheta, $ditolak);
        $agregat = $this->gum()->agregasiBudget(array_map(
            static fn (array $b): array => ['u' => $b['u'], 'ci' => $b['ci'], 'vi' => $b['vi']],
            $budget,
        ));

        // TIDAK ada `max(U, CMC)` di sini, dan itu bukan kelalaian — lihat
        // docblock kelas. `AA19` master kosong karena Height Gauge memang di
        // luar lampiran LK-285-IDN.
        $u95 = $agregat['ketidakpastian_diperluas'];

        // RSS komponen Type B saja — aturannya disamakan dengan
        // `GumCalculator::hitungDariBudget()` biar kolom `type_b` tidak beda
        // arti antar-alat.
        $typeB = sqrt(array_sum(array_map(
            static fn (array $b): float => ($b['u'] * $b['ci']) ** 2,
            array_filter($budget, static fn (array $b): bool => $b['distribusi'] !== 't-student'),
        )));

        $praEvaluasi = $this->deretAngka($konteks['pra_evaluasi'] ?? []);

        return [
            'titik' => $dihitung,
            'budget' => $budget,
            'paralelisme' => $paralelisme,
            'ketidakpastian_gabungan' => $agregat['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $agregat['ketidakpastian_diperluas'],
            'u95_sertifikat' => $u95,
            'type_b' => $typeB,
            // Gerbangnya DIPATOK eksplisit — tiga syarat, bukan diturunkan dari
            // "tidak ada satu pun penolakan". Bedanya menentukan: `budget()`
            // juga mencatat hal yang TIDAK menahan (umur drift negatif pada
            // sesi historis), dan menggabungkan keduanya menghukum catatan
            // sertifikat lama alih-alih pengukurannya.
            //
            // Yang TIDAK menahan, dan sengaja: paralelisme "Not Good". Itu
            // hasil UKUR — alat pelanggan yang ujung scriber-nya memang tidak
            // paralel — bukan cacat data. Dia dicetak apa adanya.
            //
            // Kenapa dua syarat pertama menahan, padahal di Micrometer "cuma"
            // menggeser angka: di sana lantai CMC menampung U95 yang kehilangan
            // komponen sehingga hasilnya masih di atas kemampuan terakreditasi.
            // Di sini TIDAK ADA lantai — pra-evaluasi yang sepuluh-duanya
            // identik atau resolusi yang kosong langsung menerbitkan U yang
            // terlalu kecil, dan tidak ada satu pun angka di jalurnya yang
            // terlihat ganjil.
            'boleh_terbit' => count($praEvaluasi) >= 2
                // `punyaSebaran`, bukan `stdev > 0` — lihat [budget]. Yang
                // kedua diam-diam tidak pernah menyala untuk nilai yang tidak
                // bisa direpresentasikan persis dalam biner, dan 599,95
                // (pembacaan blok Evaluation sesi contoh) termasuk.
                && $this->punyaSebaran($praEvaluasi)
                && (float) $konteks['resolusi_mm'] > 0.0,
            'ditolak' => $ditolak,
        ];
    }

    /**
     * Total nominal satu titik (mm) — `H = SUM(F:G)`, jumlah nilai TERKOREKSI
     * tiap slot menurut sertifikat Caliper Checker, bukan nominal cetaknya.
     *
     * Balik `null` kalau salah satu slot tidak ada di tabel Outside. Master
     * membungkusnya `IFERROR(...; "")`, jadi titiknya lenyap dari sertifikat
     * tanpa error; di sini `null` wajib diangkat pemanggil jadi titik yang
     * diblokir dengan alasan kebaca.
     *
     * Beberapa slot dijumlahkan walau lembar kerjanya cuma menyediakan satu:
     * master menyapu tiga baris per titik, dan sesi contoh cuma mengisi baris
     * pertama. Jalurnya disediakan supaya tidak perlu dibongkar kalau lab mulai
     * menumpuk — bukan karena ada yang memakainya hari ini.
     *
     * @param  array<int|string, mixed>  $nominal
     */
    public function totalNominal(array $nominal): ?float
    {
        $tabel = $this->tabel();
        $total = 0.0;
        $adaSlot = false;

        foreach ($nominal as $n) {
            if (! is_numeric($n)) {
                continue;
            }

            $nilai = $tabel->nilaiTerkoreksi((float) $n);

            if ($nilai === null) {
                return null;
            }

            $total += $nilai;
            $adaSlot = true;
        }

        return $adaSlot ? $total : null;
    }

    /**
     * Sembilan komponen budget satu sesi (**mm**), urutannya sama dengan sheet
     * `PERHITUNGAN U95%` master baris 5 sampai 13.
     *
     * @param  list<array<string, mixed>>  $dihitung
     * @param  array<string, mixed>  $konteks
     * @param  list<array{titik_ke: int, alasan: string}>  $ditolak
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float, satuan: string}>
     */
    private function budget(
        array $dihitung,
        array $konteks,
        float $theta,
        float $deltaTheta,
        array &$ditolak,
    ): array {
        $k = $this->tabel()->konstanta();
        $akar3 = sqrt(3.0);
        $viRect = (float) $k['vi_type_b_rect'];
        $viNormal = (float) $k['vi_type_b_normal'];

        // Panjang untuk koefisien sensitivitas: `Lmaks = PERHITUNGAN!C65 =
        // MAX(C35:E64)` — nominal titik TERBESAR di sesi ini, bukan kapasitas
        // alat. Di sesi contoh keduanya kebetulan 600 mm; memakai kapasitas
        // berarti sesi yang berhenti di 300 mm memungut ci dua kali lipat
        // haknya, tanpa satu pun error.
        //
        // Yang dipakai NOMINAL, bukan total terkoreksi: `C65` menyapu kolom
        // nominal (`C`), dan selisihnya (600 vs 599,9973) memang tidak
        // menggeser angka di desimal yang tercetak — tapi memakai yang bukan
        // sumbernya membuat pembaca berikutnya mengira ada pembulatan.
        $lMaks = max(array_map(
            static fn (array $t): float => $t['nominal'] === [] ? 0.0 : max($t['nominal']),
            $dihitung,
        ));

        $deltaAlpha = (float) $k['delta_alpha_per_c'];

        // ci suku termal: Δα·L untuk komponen suhu, dan L·Δϴ untuk komponen
        // muai. Komponen ke-9 memakai ci yang SAMA dengan komponen ke-4 —
        // ditiru dari master apa adanya.
        $ciSuhu = $lMaks * $deltaAlpha;
        $ciMuai = $lMaks * $theta;

        // Pengulangan dari PRA-EVALUASI (sepuluh pembacaan berulang di nominal
        // kapasitas, `PERHITUNGAN!C30:M30`), bukan dari sebaran tiga pembacaan
        // tiap titik. `N30 = STDEV(C30:M30)` satu-satunya sumber Repeatability
        // seluruh sesi.
        $praEvaluasi = $this->deretAngka($konteks['pra_evaluasi'] ?? []);
        $nUlang = count($praEvaluasi);
        $stdev = $this->simpanganBaku($praEvaluasi);

        if ($nUlang < 2) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => 'Blok Evaluation butuh minimal dua pembacaan berulang untuk simpangan baku. '
                    .'Tanpa itu komponen keterulangan bernilai nol — dan alat ini tidak punya lantai '
                    .'CMC yang menampungnya, jadi U95 langsung terbit terlalu kecil.',
            ];
        }

        // Sepuluh pembacaan yang SEMUANYA sama persis lolos penjaga `n >= 2` di
        // atas, dan itu yang bikin penjaga ini perlu berdiri sendiri.
        //
        // Yang dibandingkan `max === min`, BUKAN `stdev <= 0` — dan bedanya
        // bukan gaya penulisan. Simpangan baku sepuluh nilai identik cuma nol
        // EKSAK kalau nilainya kebetulan bisa direpresentasikan persis dalam
        // biner. Diukur pada pembacaan nyata lembar ini:
        //
        //   sepuluh kali 50,00  -> stdev 0,0e+0    (penjaga menyala)
        //   sepuluh kali 599,95 -> stdev 1,2e-13   (penjaga TIDAK menyala)
        //
        // 599,95 itu justru nilai yang dipakai blok Evaluation sesi contoh —
        // jadi versi `stdev <= 0` lolos di test dengan angka bulat dan diam di
        // data sungguhan. Nol error, dan U95 terbit tanpa komponen keterulangan.
        //
        // `max === min` menguji hal yang SAMA (sebaran nol) tapi eksak apa pun
        // nilainya, karena dia perbandingan nilai — bukan hasil aritmetika
        // yang mengakumulasi galat pembulatan.
        //
        // TIDAK diganti lantai keterulangan berbasis resolusi walau itu
        // perlakuan yang lazim: memilih lantai berarti MENGUBAH U95 yang
        // terbit, dan itu keputusan metode milik manajer teknis.
        if ($nUlang >= 2 && ! $this->punyaSebaran($praEvaluasi)) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => 'Sepuluh pembacaan blok Evaluation seluruhnya bernilai sama, jadi simpangan '
                    .'bakunya nol dan komponen keterulangan hilang dari budget. Di alat ini tidak ada '
                    .'lantai CMC yang menampungnya — U95 langsung terbit lebih kecil dan tetap terlihat '
                    .'wajar. Ulangi blok Evaluation dan catat tiap pembacaan apa adanya.',
            ];
        }

        if ((float) $konteks['resolusi_mm'] <= 0.0) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => 'Resolusi alat belum diisi. Tanpa resolusi, komponen resolusi budget bernilai '
                    .'nol dan U95 terbit lebih kecil dari seharusnya — dan tidak ada lantai CMC yang '
                    .'menutupinya, jadi tidak ada satu pun angka yang terlihat ganjil.',
            ];
        }

        $umurHari = $this->umurStandarHari($konteks['tanggal_kalibrasi']);

        // Umur negatif TIDAK menahan penerbitan — dia dicatat, driftnya nol.
        // Alasannya sama persis dengan [MicrometerCalculator::budget]: sesi yang
        // mendahului sertifikat Caliper Checker yang sekarang tersimpan itu sesi
        // HISTORIS, dan yang hilang cuma catatan sertifikat lama, bukan
        // pengukurannya.
        if ($umurHari === null) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => 'Tanggal kalibrasi sesi lebih awal dari tanggal kalibrasi Caliper Checker '
                    .'standar yang tersimpan, jadi umur drift tidak bisa dihitung dan komponennya '
                    .'dianggap nol. Sesi tetap diterbitkan.',
            ];
            $umurHari = 0.0;
        }

        // Umur dibagi 365 HARI, bukan 12 seperti `K10` master.
        //
        // Komponennya bersatuan mm/tahun dan `$umurHari` bersatuan hari, jadi
        // (mm/tahun) × (hari/365) = mm; `/12` hanya benar kalau selisihnya
        // bulan. Master Micrometer dari lab yang sama memakai /365. Dibetulkan
        // 16 Sep 2026 (paket keputusan butir 5, disetujui pemilik proyek;
        // paraf Manajer Teknis menyusul) — U turun ~1,1 % di sesi contoh, dan
        // alat ini di luar lampiran akreditasi jadi tidak ada lantai yang
        // tertembus. Pembagi master tetap dicetak di jejak audit.
        $drift = ((float) $k['drift_a_mm'] + (float) $k['drift_b_mm_per_mm'] * $lMaks)
            / 1000
            * ($umurHari / self::PEMBAGI_UMUR_HARI);

        return [
            [
                'sumber' => 'pengulangan',
                'keterangan' => 'Repeatability (Urep) — blok Evaluation',
                'distribusi' => 't-student',
                'u' => $nUlang >= 2 ? $stdev / sqrt($nUlang) : 0.0,
                'ci' => 1.0,
                'vi' => $nUlang >= 2 ? (float) ($nUlang - 1) : 0.0,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'resolusi_uut',
                'keterangan' => 'Resolusi (Urnd)',
                'distribusi' => 'rectangular',
                'u' => ((float) $konteks['resolusi_mm'] / 2) / $akar3,
                'ci' => 1.0,
                'vi' => $viNormal,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => 'Standard Caliper Checker (Uls)',
                'distribusi' => 'normal',
                'u' => $this->tabel()->ketidakpastianStandarMm(),
                'ci' => 1.0,
                'vi' => $viNormal,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'suhu_ruang',
                'keterangan' => 'Perubahan suhu terhadap suhu acuan 20 °C',
                'distribusi' => 'rectangular',
                'u' => $theta / $akar3,
                'ci' => $ciSuhu,
                'vi' => $viRect,
                'satuan' => '/°C',
            ],
            [
                'sumber' => 'koefisien_muai',
                'keterangan' => 'Koefisien muai thermal',
                'distribusi' => 'rectangular',
                // Pembaginya `√6`, bukan `√3` — ditiru dari `N9` master walau
                // `J9` menulis distribusinya `rect.`. Pertanyaan lab §2.
                'u' => $deltaAlpha / (float) $k['pembagi_muai'],
                'ci' => $ciMuai,
                'vi' => $viRect,
                'satuan' => '/°C',
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => 'Drift standard',
                'distribusi' => 'rectangular',
                'u' => $drift / $akar3,
                'ci' => 1.0,
                'vi' => $viRect,
                'satuan' => 'mm/th',
            ],
            [
                'sumber' => 'geometri',
                'keterangan' => 'Kesalahan Geometri',
                'distribusi' => 'rectangular',
                'u' => (float) $k['geometri_mm'] / $akar3,
                'ci' => 1.0,
                'vi' => $viRect,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'meja_granit',
                'keterangan' => 'Meja Granit (meja rata)',
                'distribusi' => 'normal',
                'u' => (float) $k['meja_granit_mm'] / 2,
                'ci' => 1.0,
                'vi' => $viNormal,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'selisih_suhu',
                'keterangan' => 'Selisih suhu Height Gauge dengan Caliper Checker',
                'distribusi' => 'rectangular',
                // Nol menurut konstruksi selama suhu UUT diturunkan dari suhu
                // ruangan yang sama — ditiru dari master, yang juga selalu nol
                // di sini. Jalurnya hidup supaya begitu lab mulai mengukur
                // keduanya terpisah, tempatnya sudah ada.
                'u' => abs($deltaTheta) / $akar3,
                'ci' => $ciSuhu,
                'vi' => $viRect,
                'satuan' => '°C',
            ],
        ];
    }

    /**
     * Apakah deret ini punya SEBARAN sama sekali — yaitu bukan satu nilai yang
     * disalin berkali-kali.
     *
     * Diuji `max !== min`, bukan lewat simpangan bakunya. Simpangan baku
     * sepuluh nilai identik cuma nol EKSAK kalau nilainya bisa
     * direpresentasikan persis dalam biner; untuk 599,95 dia keluar 1,2e-13,
     * dan penjaga ber-`> 0.0` lolos begitu saja. Lihat [budget].
     *
     * Deret berisi kurang dari dua angka balik `false`: satu pembacaan memang
     * belum punya sebaran yang bisa dinilai.
     *
     * @param  list<float>  $nilai
     */
    private function punyaSebaran(array $nilai): bool
    {
        return count($nilai) >= 2 && max($nilai) !== min($nilai);
    }

    /**
     * Deret angka yang bersih — yang bukan angka DILEWATI, bukan dibaca nol.
     *
     * Kotak Evaluation yang belum diisi terbaca `0` kalau dipaksa jadi float,
     * dan satu nol di antara sepuluh pembacaan 599,9x menggelembungkan
     * simpangan bakunya ratusan kali. Yang terbit bukan error, cuma U95 yang
     * salah besar.
     *
     * @param  array<int|string, mixed>  $nilai
     * @return list<float>
     */
    private function deretAngka(array $nilai): array
    {
        return array_values(array_map('floatval', array_filter(
            $nilai,
            static fn ($x): bool => is_numeric($x),
        )));
    }

    private function tabel(): TabelStandarHeightGauge
    {
        // Malas, bukan di parameter bawaan konstruktor: profil -> kalkulator ->
        // GumCalculator -> CalibrationProfileRegistry -> profil itu lagi
        // membuat lingkaran yang gejalanya (`Infinite recursion?`) muncul jauh
        // dari penyebabnya.
        return $this->tabel ??= new TabelStandarHeightGauge;
    }

    private function gum(): GumCalculator
    {
        return $this->gum ??= new GumCalculator;
    }
}
