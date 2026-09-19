<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Mesin hitung **Flowmeter Ultrasonic** — alat ke-27 & ke-28, kelompok Aliran.
 *
 * Satu mesin, DUA mode. Sumbernya dua workbook master (ber-password):
 *
 *  - `1.3 Master Olah Data Flowmeter_Ultrasonic Totalizer (1000-1900L) 2026.xlsm`
 *  - `Master olda Ultrasonic Flowrate (100-300 lpm) 2026.xlsm`
 *
 * Keduanya `.xlsm` dengan `xl/vbaProject.bin` sungguhan, tapi isinya kosong:
 * `Sub DropDown7_Change()` dan `Private Sub OptionButton1_Click()` tanpa badan.
 * Tidak ada logika tersembunyi.
 *
 * ## Budget PER TITIK, bukan per sesi
 *
 * Ini yang membedakannya dari Height Gauge dan Micrometer. Sheet
 * `PERHITUNGAN U95%` punya satu blok budget PENUH per titik, dan sertifikatnya
 * mencetak kolom `U95% ±` per baris. Jadi [hitungSesi] memulangkan `uc`,
 * `veff`, `k`, dan `U` sendiri-sendiri untuk tiap titik.
 *
 * Jalur masuknya tetap `hitungPerGrup()` dan `komponenBudget()` tetap `null` —
 * dan itu bukan sekadar meniru tetangga. `komponenBudget()` cuma menerima satu
 * `$typeA` skalar dari SATU deret pembacaan, sementara satu titik flowmeter
 * punya DUA deret (UUT dan standar) yang selisih berpasangannya jadi komponen
 * pengulangan, plus blok tingkat-sesi (geometri pipa) yang melahirkan `u_A`.
 * Alasannya sama persis dengan [EnclosureCalculator].
 *
 * ## Dua generasi budget — TIDAK diseragamkan
 *
 * Totalizer 8 komponen, Flowrate 9. Bukan karena besarannya beda:
 * `FORM VALIDASI` Flowrate punya baris kedua (20 Mei 2026, PIC `NR`) berbunyi
 * *"Merubah all budget ketidakpastian ; menambahkan stdev untuk UUT ;
 * menambahkan keterangan spek pipa pada sheet sertifikat"* — dan workbook
 * Totalizer BELUM ikut revisi itu. Komponen "Pengulangan Pembacaan UUT" yang
 * lahir Mei memang tidak ada di Totalizer.
 *
 * Ditiru masing-masing apa adanya. Menambahkan komponen ke-9 ke Totalizer
 * berarti mengubah U95 yang sudah tercetak di sertifikat pelanggan, dan itu
 * keputusan manajer teknis. Pertanyaan lab §3.
 *
 * ## Tiga penyimpangan master yang TIDAK ditiru
 *
 * Ketiganya membuat angkanya lebih besar atau menolak menerbitkan — tidak
 * pernah diam-diam lebih kecil:
 *
 *  1. **Lantai CMC hilang, dan sudah menerbitkan angka di bawah akreditasi.**
 *     Di blok titik yang benar-benar dipakai, sel U95 sertifikat berbunyi
 *     `=MAX(J60:K61)` dengan `K61` KOSONG — `MAX` atas satu angka, jadi tidak
 *     ada lantai. Blok titik 3 & 4 punya rumus CMC tapi menunjuk `DATABASE!S42`
 *     / `S43` yang ada di area tabel thermohygro dan kosong.
 *
 *     Akibatnya bukan hipotetis: sertifikat yang terbit dari workbook Flowrate
 *     titik 2 mencetak **1,0466 %** pada pita terakreditasi **1,2 %** — 0,153 poin
 *     persen lebih kecil dari yang diakui KAN, dan tidak ada satu pun sel yang
 *     memprotes. Di sini lantainya dipasang: `U95 = max(U; CMC% · UUT_avg/100)`,
 *     dan titik yang jatuh di LUAR kedua pita DIBLOKIR — bukan diterbitkan
 *     tanpa lantai. Aturannya disamakan dengan `TabelStandarMicrometer::pitaCmc()`.
 *
 *     Terukur di sesi contoh: Flowrate titik 2 naik dari 3,2512388 ke
 *     **3,7277107 Lpm**. Tiga titik lain sudah di atas lantainya dan tidak
 *     bergerak.
 *  2. **Rentang densitas melenceng kolom (Totalizer saja).** `PERHITUNGAN FC`
 *     `D64` (densitas UUT titik 1) membaca `D44:G46` — benar; `H64` (titik 2)
 *     membaca `H44:K46`, dan kolom `K` itu **titik 3**; `K64` (titik 3) membaca
 *     `K44:N46` yang mencaplok titik 4; `M65` bahkan berbunyi `=M59:P59` —
 *     rentang tanpa `AVERAGE`. Salin-tempel murni. Di sini tiap titik membaca
 *     kolom suhunya SENDIRI.
 *
 *     Terukur: deviasi Totalizer titik 2 bergeser dari −18,907204 ke
 *     **−18,890667 L**. Workbook Flowrate TIDAK punya cacat ini.
 *  3. **`Ut-water` menunjuk sel kosong.** `PERHITUNGAN U95%!I24` Totalizer
 *     berbunyi `='PERHITUNGAN FC'!Q52-'PERHITUNGAN FC'!Q54` — kolom `Q` ada
 *     satu kolom di luar blok suhu (yang berhenti di `P`), jadi kosong. Flowrate
 *     sama: `P53-P55`, dan `P` juga di luar bloknya. Hasilnya `Ut-water`
 *     **selalu nol** di kedua master, padahal labelnya sendiri menulis
 *     `(Tmax−Tmin)Water`. Di sini dia dihitung dari suhu air sesi yang
 *     sebenarnya.
 *
 *     Terukur di kedua sesi contoh: `U_temperature` naik dari 0,2780288 ke
 *     0,2795234 °C. Arahnya aman (U membesar). Pertanyaan lab §12.
 *
 * ## Yang DITIRU walau janggal
 *
 * Semuanya diangkat ke `docs/pertanyaan-lab-flowmeter.md`:
 *
 *  - **π = 3,14**, bukan `PI()` (`PERHITUNGAN U95%!D41`/`D40`). Menggeser `A`
 *    0,05 %, dan `A` cuma masuk lewat `ci` komponen cross-sectional yang
 *    sumbangannya kecil. §8.
 *  - **Pembagi `1,73`** alih-alih `√3` di komponen rectangular, sementara
 *    komponen pengulangan memakai `SQRT(3)` sungguhan di sheet yang SAMA.
 *    Selisihnya 0,12 %; pembulatan ke bawah membuat `u` sedikit lebih besar —
 *    arah yang aman. §7.
 *  - **`vi` komponen suhu 2 di Totalizer, 50 di Flowrate**, dan **pembagi
 *    cross-sectional 2 di Totalizer, 1,73 di Flowrate** — komponen yang sama,
 *    tetapan beda. §6.
 *  - **Pencocokan tabel standar TERDEKAT, bukan interpolasi.** Lihat
 *    [TabelStandarFlowmeter]; di sini yang ditambahkan cuma peringatannya. §4.
 *  - **`ci` suhu = `STD_terkoreksi · 0,00021 / ρ²`** — asal 0,00021 tidak
 *    bersumber di workbook mana pun, dan dimensinya tidak jelas. §9.
 *  - **0,11 % (velocity profile) dan 0,3 % (geometry factor)** juga tidak
 *    bersumber. §10.
 *
 * ## Blok titik 3 & 4 master TIDAK dipakai sebagai acuan bentuk
 *
 * Keduanya tidak terisi di sesi contoh, jadi kerusakannya tidak pernah terlihat:
 * `vi` FU 200 alih-alih 60, `vi` suhu 50 alih-alih 2, `ci` cross-sectional
 * memakai rumus yang sama sekali lain, `u` velocity menunjuk sel kosong,
 * `K82 = K81/'PERHITUNGAN FC'!D80*100` menghasilkan `#DIV/0!` sementara
 * tetangganya `L82` menunjuk sel yang benar. Acuannya titik 1 dan 2 saja.
 */
class FlowmeterCalculator
{
    /** Pembagi persen — komponen 0,11 %, 0,3 %, 1 %, dan lantai CMC semuanya persen. */
    private const PERSEN = 100.0;

    private ?GumCalculator $gum = null;

    private ?TabelStandarFlowmeter $tabel = null;

    /**
     * Densitas air murni bebas udara pada `$suhu` °C, satuan kg/L.
     *
     * Rumusnya **Tanaka/Kell (ITS-90)** — bukan angka karangan master. Koefisien
     * (0,999974; 3,983035; 301,797; 522528,9; 69,34881) disimpan di
     * `tabel-standar-flowmeter.json` supaya terbaca sebagai tetapan bersumber,
     * bukan sihir di tengah rumus.
     */
    public function densitasAir(float $suhu): float
    {
        $k = $this->tabel()->konstanta();

        return $k['densitas_a0'] * (
            1 - ((($suhu - $k['densitas_a1']) ** 2) * ($suhu + $k['densitas_a2']))
                / ($k['densitas_a3'] * ($suhu + $k['densitas_a4']))
        );
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

        return sqrt(array_sum(array_map(static fn (float $x): float => ($x - $rata) ** 2, $nilai)) / ($n - 1));
    }

    /**
     * Luas penampang DALAM pipa (mm²) dan ketidakpastian bakunya — tingkat SESI.
     *
     *     A   = (π/4) · (D_luar − 2·tebal)²
     *     u_A = (2A / (D_luar − 2·tebal)) · √(u_caliper² + 4·u_thickness²)
     *
     * π-nya 3,14 (ditiru dari master). Balik `null` kalau diameter/ketebalan
     * belum terisi atau menghasilkan diameter dalam ≤ 0 — tanpa itu `u_A` nol
     * dan DUA komponen budget lenyap sekaligus, dan di alat ini tidak ada
     * lantai CMC di master yang menyamarkannya.
     *
     * @param  array<int|string, mixed>  $diameterLuar
     * @param  array<int|string, mixed>  $ketebalan
     * @return array{a: float, u_a: float, diameter_luar: float, ketebalan: float, diameter_dalam: float}|null
     */
    public function geometriPipa(array $diameterLuar, array $ketebalan): ?array
    {
        $d = $this->deretAngka($diameterLuar);
        $t = $this->deretAngka($ketebalan);

        if ($d === [] || $t === []) {
            return null;
        }

        $k = $this->tabel()->konstanta();
        $dLuar = array_sum($d) / count($d);
        $tebal = array_sum($t) / count($t);
        $dalam = $dLuar - 2 * $tebal;

        if ($dalam <= 0.0) {
            return null;
        }

        $a = ($k['pi'] / 4) * $dalam ** 2;

        return [
            'a' => $a,
            'u_a' => (2 * $a / $dalam) * sqrt(
                ($k['u95_caliper_mm'] / 2) ** 2 + 4 * ($k['u95_thickness_gauge_mm'] / 2) ** 2
            ),
            'diameter_luar' => $dLuar,
            'ketebalan' => $tebal,
            'diameter_dalam' => $dalam,
        ];
    }

    /**
     * Ketidakpastian baku pengukuran suhu fluida (°C) — tingkat SESI.
     *
     *     U_temperature = √( (U95_Yokogawa/2)² + (U95_TypeK/2)² + Ut_water² )
     *     Ut_water      = (Tmax − Tmin)_air / (2√3)
     *
     * `Ut_water` DIHITUNG di sini, tidak diambil nol seperti master — lihat
     * penyimpangan no. 3 di docblock kelas. Deret berisi kurang dari dua angka
     * memberi `Ut_water` nol, dan itu benar: satu pembacaan memang belum punya
     * sebaran yang bisa dinilai.
     *
     * @param  array<int|string, mixed>  $suhuAir
     */
    public function ketidakpastianSuhu(array $suhuAir): float
    {
        $k = $this->tabel()->konstanta();
        $nilai = $this->deretAngka($suhuAir);
        $utWater = count($nilai) >= 2 ? (max($nilai) - min($nilai)) / (2 * sqrt(3.0)) : 0.0;

        return sqrt(
            ($k['u95_yokogawa_c'] / 2) ** 2 + ($k['u95_thermocouple_c'] / 2) ** 2 + $utWater ** 2
        );
    }

    /**
     * Ubah satu penunjukan dari `$satuan` ke satuan budget (L atau LPM).
     *
     * Balik `null` kalau tidak bisa — pemanggil WAJIB mengangkatnya jadi titik
     * terblokir, bukan memaksanya jadi nol.
     *
     * Satuan berbasis MASSA dihitung lewat densitas, tidak lewat tabel master:
     * `DATABASE!S25` Totalizer berisi teks `'perlu dibagi densitas'`,
     * `DATABASE!S25` Flowrate berisi `=S23/1000` (0,0166667 — itu m³/h dibagi
     * seribu, salah dimensi), dan `S26` berisi `#REF!`. Tidak ada satu pun yang
     * bisa dipakai, jadi konversinya dihitung dari dimensinya sendiri:
     *
     *     kg     ÷ ρ        = L
     *     kg/min ÷ ρ        = L/min = LPM
     *     kg/h   ÷ ρ ÷ 60   = LPM
     *
     * Tanpa densitas UUT yang diketik teknisi, titiknya DIBLOKIR — menebak
     * densitas air pada suhu sesi berarti mengarang angka untuk fluida yang
     * mungkin bukan air.
     */
    public function konversi(string $mode, float $nilai, string $satuan, ?float $densitasUut): ?float
    {
        $tabel = $this->tabel();

        if ($tabel->satuanBerbasisMassa($mode, $satuan)) {
            if ($densitasUut === null || $densitasUut <= 0.0) {
                return null;
            }

            return $nilai / $densitasUut / ($satuan === 'kg/h' ? 60.0 : 1.0);
        }

        $faktor = $tabel->faktorSatuan($mode, $satuan);

        return $faktor === null ? null : $nilai * $faktor;
    }

    /**
     * Kebalikan [konversi] — dari satuan budget (L/LPM) kembali ke satuan yang
     * diketik teknisi.
     *
     * Dipakai jalur SERTIFIKAT. Master melakukan hal yang sama
     * (`SERTIFIKAT!E26 = 'PERHITUNGAN FC'!D63 / DATABASE!$S$22`): budget-nya
     * hidup dalam L/LPM, tapi yang tercetak untuk pelanggan angka dalam satuan
     * alatnya sendiri. Menyimpan hasil per titik dalam satuan budget berarti
     * sertifikat sesi ber-m³/h mencetak angka yang 16,7× lebih besar dari yang
     * dibaca teknisi di layar alat — dan tidak ada satu pun yang ganjil, karena
     * kolom satuannya ikut berubah.
     */
    public function konversiBalik(string $mode, float $nilai, string $satuan, ?float $densitasUut): ?float
    {
        $tabel = $this->tabel();

        if ($tabel->satuanBerbasisMassa($mode, $satuan)) {
            if ($densitasUut === null || $densitasUut <= 0.0) {
                return null;
            }

            return $nilai * $densitasUut * ($satuan === 'kg/h' ? 60.0 : 1.0);
        }

        $faktor = $tabel->faktorSatuan($mode, $satuan);

        return ($faktor === null || $faktor == 0.0) ? null : $nilai / $faktor;
    }

    /**
     * Hitung SATU sesi flowmeter — semua titiknya, masing-masing dengan budget
     * sendiri.
     *
     * `$konteks` tingkat-sesi: `mode`, `satuan`, `resolusi`, `diameter_pipa_mm`,
     * `ketebalan_pipa_mm`.
     *
     * `$titik[i]`: `titik_ke`, `uut` (Totalizer: list ulangan; Flowrate: list
     * ulangan berisi list durasi), `std` (list ulangan), `suhu_awal`,
     * `suhu_akhir` (list per ulangan), `densitas_uut` (list, boleh kosong).
     *
     * @param  list<array<string, mixed>>  $titik
     * @param  array<string, mixed>  $konteks
     * @return array<string, mixed>
     */
    public function hitungSesi(array $titik, array $konteks): array
    {
        $tabel = $this->tabel();
        $mode = (string) $konteks['mode'];
        $satuan = (string) ($konteks['satuan'] ?? '');
        $ditolak = [];
        $peringatan = [];

        $geometri = $this->geometriPipa(
            $konteks['diameter_pipa_mm'] ?? [],
            $konteks['ketebalan_pipa_mm'] ?? [],
        );

        // Suhu air SELURUH sesi — `Ut_water` itu satu angka tingkat-sesi, sama
        // seperti di master (satu sel `I24` dipakai semua blok titik).
        $suhuSesi = [];

        foreach ($titik as $t) {
            $suhuSesi = array_merge(
                $suhuSesi,
                $this->deretAngka($t['suhu_awal'] ?? []),
                $this->deretAngka($t['suhu_akhir'] ?? []),
            );
        }

        $uTemperature = $this->ketidakpastianSuhu($suhuSesi);
        // Resolusi dioper MENTAH dan dikonversi di dalam [hitungTitik], bukan
        // di sini. Alasannya bukan kerapian: satuan berbasis massa butuh
        // densitas UUT untuk bisa dikonversi sama sekali, dan densitas itu
        // hidup PER TITIK. Dikonversi di tingkat sesi dengan densitas `null`,
        // resolusinya selalu pulang `null` dan SELURUH sesi ber-kg/min ditolak
        // dengan alasan "resolusi belum diisi" — padahal resolusinya ada dan
        // densitasnya juga.
        $resolusiMentah = (float) ($konteks['resolusi'] ?? 0.0);
        $dihitung = [];

        foreach ($titik as $t) {
            $nomor = (int) $t['titik_ke'];
            $hasil = $this->hitungTitik($t, $mode, $satuan, $resolusiMentah, $geometri, $uTemperature);

            if (isset($hasil['alasan'])) {
                $ditolak[] = ['titik_ke' => $nomor, 'alasan' => $hasil['alasan']];

                continue;
            }

            // Jarak ke titik tabel terdekat TIDAK menahan — dia peringatan,
            // dengan angkanya ikut supaya admin bisa menilai tanpa membuka
            // Excel. Di sesi contoh Flowrate titik 2 dia 23,8 %.
            $jarak = $tabel->jarakRelatif($mode, $hasil['std_rata']);

            if ($jarak !== null && $jarak > TabelStandarFlowmeter::AMBANG_JARAK_TABEL) {
                $peringatan[] = sprintf(
                    'Titik %d: rata-rata pembacaan standar %.4f memungut koreksi titik tabel %.3f — '
                    .'jaraknya %.1f %% dari bacaan. Master memakai pencocokan TERDEKAT (bukan '
                    .'interpolasi), jadi koreksi titik tabel itu dipakai utuh. Lihat '
                    .'docs/pertanyaan-lab-flowmeter.md §4.',
                    $nomor,
                    $hasil['std_rata'],
                    $hasil['baris_tabel']['standard'],
                    $jarak * self::PERSEN,
                );
            }

            $dihitung[] = ['titik_ke' => $nomor] + $hasil;
        }

        usort($dihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($ditolak, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return [
            'titik' => $dihitung,
            'ditolak' => $ditolak,
            'peringatan' => $peringatan,
            'geometri' => $geometri,
            'u_temperature' => $uTemperature,
            'resolusi_mentah' => $resolusiMentah,
        ];
    }

    /**
     * Satu titik: deret mentah -> deviasi + budget + U95 yang berlantai CMC.
     *
     * Balik `['alasan' => ...]` kalau titiknya tidak boleh terbit. Tiap alasan
     * menyebut AKIBATNYA — bukan cuma "data kurang" — karena yang membacanya
     * admin yang sedang memutuskan approve.
     *
     * @param  array<string, mixed>  $t
     * @param  array{a: float, u_a: float, diameter_luar: float, ketebalan: float, diameter_dalam: float}|null  $geometri
     * @return array<string, mixed>
     */
    private function hitungTitik(
        array $t,
        string $mode,
        string $satuan,
        float $resolusiMentah,
        ?array $geometri,
        float $uTemperature,
    ): array {
        $tabel = $this->tabel();
        $flowrate = $mode === TabelStandarFlowmeter::MODE_FLOWRATE;
        $satuanHasil = $flowrate ? 'Lpm' : 'L';

        // --- densitas UUT: dipakai KONVERSI satuan massa DAN rasio densitas --
        $densitasKetik = $this->deretAngka($t['densitas_uut'] ?? []);
        $densitasUut = $densitasKetik === [] ? null : array_sum($densitasKetik) / count($densitasKetik);

        if ($densitasUut !== null && $densitasUut <= 0.0) {
            $densitasUut = null;
        }

        if ($tabel->satuanBerbasisMassa($mode, $satuan) && $densitasUut === null) {
            return ['alasan' => sprintf(
                'Satuan `%s` berbasis massa dan densitas fluida UUT belum diisi, jadi pembacaannya '
                .'tidak bisa diubah ke %s sama sekali. Master pun tidak punya faktornya — selnya '
                .'berisi teks atau `#REF!`. Isi densitas UUT (kg/L) atau ganti satuannya.',
                $satuan,
                $satuanHasil,
            )];
        }

        // Resolusi dikonversi DI SINI, sesudah densitas titik ini diketahui —
        // satuan berbasis massa tidak punya arti volume tanpa densitas.
        $resolusi = $this->konversi($mode, $resolusiMentah, $satuan, $densitasUut);

        // --- deret UUT ------------------------------------------------------
        // Flowrate: 3 ulangan x 3 durasi (20"/40"/60"). Totalizer: 3 ulangan
        // tanpa durasi. Bentuknya beda, jadi disamakan jadi list-of-list dulu.
        $ulangan = [];

        foreach ($t['uut'] ?? [] as $u) {
            $deret = $this->deretAngka(is_array($u) ? $u : [$u]);

            if ($deret !== []) {
                $ulangan[] = $deret;
            }
        }

        $std = $this->deretAngka($t['std'] ?? []);

        if (count($ulangan) < 2 || count($std) < 2) {
            return ['alasan' => sprintf(
                'Titik ini butuh minimal dua pembacaan UUT dan dua pembacaan standar; yang ada %d dan %d. '
                .'Dengan kurang dari dua, simpangan bakunya nol dan komponen pengulangan hilang dari '
                .'budget — U95 terbit lebih kecil tanpa satu pun angka yang terlihat ganjil.',
                count($ulangan),
                count($std),
            )];
        }

        // --- konversi satuan DI TEMPAT PAKAI --------------------------------
        $ulanganKonv = [];

        foreach ($ulangan as $u) {
            $baris = [];

            foreach ($u as $nilai) {
                $hasil = $this->konversi($mode, $nilai, $satuan, $densitasUut);

                if ($hasil === null) {
                    return ['alasan' => $this->alasanSatuan($mode, $satuan)];
                }

                $baris[] = $hasil;
            }

            $ulanganKonv[] = $baris;
        }

        $stdKonv = [];

        foreach ($std as $nilai) {
            $hasil = $this->konversi($mode, $nilai, $satuan, $densitasUut);

            if ($hasil === null) {
                return ['alasan' => $this->alasanSatuan($mode, $satuan)];
            }

            $stdKonv[] = $hasil;
        }

        if ($resolusi === null || $resolusi <= 0.0) {
            return ['alasan' => 'Resolusi alat belum diisi (atau satuannya tidak bisa dikonversi). Tanpa resolusi, '
                .'komponen resolusi budget bernilai nol dan U95 terbit lebih kecil dari seharusnya.',
            ];
        }

        if ($geometri === null) {
            return ['alasan' => 'Diameter luar dan ketebalan pipa belum terisi. Tanpa keduanya `u_A` bernilai nol dan '
                .'DUA komponen budget (cross sectional area dan koefisien sensitivitasnya) lenyap '
                .'sekaligus — di alat ini master tidak punya lantai CMC yang menyamarkannya.',
            ];
        }

        // --- besaran turunan ------------------------------------------------
        $semuaUut = array_merge(...$ulanganKonv);
        $uutRata = array_sum($semuaUut) / count($semuaUut);
        $stdRata = array_sum($stdKonv) / count($stdKonv);

        // Simpangan baku UUT: MAX dari simpangan baku tiap ulangan atas ketiga
        // durasi (`PERHITUNGAN FC!Q28`). Cuma ada di Flowrate — Totalizer belum
        // ikut revisi 20 Mei 2026.
        $stdevUut = $flowrate
            ? max(array_map(fn (array $u): float => $this->simpanganBaku($u), $ulanganKonv))
            : null;

        // Simpangan baku standar: selisih BERPASANGAN standar-vs-UUT per
        // ulangan. Di Totalizer pasangannya pembacaan ulangan itu sendiri
        // (`D30-D23`); di Flowrate pasangannya RATA-RATA ketiga durasi ulangan
        // itu (`D31-H24`).
        $pasang = min(count($ulanganKonv), count($stdKonv));
        $selisih = [];

        for ($i = 0; $i < $pasang; $i++) {
            $u = $ulanganKonv[$i];
            $selisih[] = $stdKonv[$i] - ($flowrate ? array_sum($u) / count($u) : $u[0]);
        }

        $stdevStd = $this->simpanganBaku($selisih);

        // Deret yang seluruhnya bernilai sama lolos penjaga `n >= 2` di atas —
        // dan itu tanda tangan "satu nilai disalin n kali", bukan pengukuran.
        // Diuji `max !== min`, bukan `stdev > 0`: simpangan baku nilai identik
        // cuma nol EKSAK kalau nilainya bisa direpresentasikan persis dalam
        // biner, dan pembacaan flowmeter (1002,65 / 309,776) umumnya tidak.
        if (! $this->punyaSebaran($selisih) || ($flowrate && ! $this->punyaSebaranBersarang($ulanganKonv))) {
            return ['alasan' => 'Pembacaan titik ini tidak punya sebaran sama sekali — seluruh ulangannya bernilai '
                .'persis sama. Simpangan bakunya nol, jadi komponen pengulangan hilang dari budget dan '
                .'U95 terbit lebih kecil sambil tetap terlihat wajar. Ulangi pengukurannya dan catat '
                .'tiap pembacaan apa adanya.',
            ];
        }

        if (! $tabel->dalamJangkauan($mode, $stdRata)) {
            $standard = array_map(static fn (array $b): float => (float) $b['standard'], $tabel->baris($mode));

            return ['alasan' => sprintf(
                'Rata-rata pembacaan standar %.4f %s ada di LUAR jangkauan tabel sertifikat UFM '
                .'(%.3f – %.3f). Master memungut baris terdekat apa pun jaraknya — termasuk baris '
                .'kosong yang dibacanya nol, yang menerbitkan `#N/A` ke sertifikat. Titik ini tidak '
                .'diterbitkan.',
                $stdRata,
                $satuanHasil,
                min($standard),
                max($standard),
            )];
        }

        $baris = $tabel->cocokTerdekat($mode, $stdRata);

        // --- densitas -------------------------------------------------------
        // Tiap titik membaca kolom suhunya SENDIRI — lihat penyimpangan no. 2.
        $suhuTitik = array_merge(
            $this->deretAngka($t['suhu_awal'] ?? []),
            $this->deretAngka($t['suhu_akhir'] ?? []),
        );

        if ($suhuTitik === []) {
            return ['alasan' => 'Suhu air titik ini belum tercatat, jadi densitas standar tidak bisa dihitung dan '
                .'rasio densitas jatuh ke pembagian nol. Isi suhu air awal dan akhir.',
            ];
        }

        $rhoStd = array_sum(array_map(fn (float $s): float => $this->densitasAir($s), $suhuTitik))
            / count($suhuTitik);
        // Densitas UUT kosong itu SAH — jatuh ke densitas air pada suhu sesi,
        // persis `IFERROR(IF(AVERAGE(...)=0; AVERAGE(rho); ...))` master.
        $rhoUut = $densitasUut ?? $rhoStd;

        $stdTerkoreksiSimpel = $stdRata + $baris['koreksi'];
        $stdTerkoreksi = ($rhoStd / $rhoUut) * $stdRata + $baris['koreksi'];
        $deviasi = $stdTerkoreksi - $uutRata;

        // --- pita CMC: gerbang DAN lantai ------------------------------------
        $pita = $tabel->pitaCmc($mode, $uutRata);

        if ($pita === null) {
            return ['alasan' => sprintf(
                'Bacaan rata-rata UUT %.4f %s jatuh di LUAR kedua pita CMC terakreditasi (%s). '
                .'Sertifikat yang terbit di titik ini akan membawa nomor lingkup LK-285-IDN untuk '
                .'pengukuran yang tidak diakreditasi. Titik ini tidak diterbitkan.',
                $uutRata,
                $satuanHasil,
                implode(', ', array_map(
                    static fn (array $p): string => $p['label'],
                    $tabel->semuaPitaCmc($mode),
                )),
            )];
        }

        // --- budget -----------------------------------------------------------
        $budget = $this->budget(
            $mode,
            $baris,
            $resolusi,
            $uTemperature,
            $stdTerkoreksi,
            $stdTerkoreksiSimpel,
            $rhoStd,
            $geometri,
            $stdevUut,
            $stdevStd,
            count($selisih),
        );

        $agregat = $this->gum()->agregasiBudget(array_map(
            static fn (array $b): array => ['u' => $b['u'], 'ci' => $b['ci'], 'vi' => $b['vi']],
            $budget,
        ));

        $u = $agregat['ketidakpastian_diperluas'];
        // Lantai CMC — dalam PERSEN, karena lampiran akreditasi menulisnya
        // `% of reading`. Membandingkan angka absolut akan salah di kedua arah.
        $lantai = $pita['cmc_persen_of_reading'] / self::PERSEN * $uutRata;
        $u95 = max($u, $lantai);

        return [
            'uut' => $ulanganKonv,
            'uut_rata' => $uutRata,
            'std' => $stdKonv,
            'std_rata' => $stdRata,
            'baris_tabel' => $baris,
            'koreksi_standar' => $baris['koreksi'],
            'std_terkoreksi_simpel' => $stdTerkoreksiSimpel,
            'std_terkoreksi' => $stdTerkoreksi,
            'densitas_standar' => $rhoStd,
            'densitas_uut' => $rhoUut,
            'densitas_uut_diketik' => $densitasUut !== null,
            'deviasi' => $deviasi,
            'simpangan_baku_uut' => $stdevUut,
            'simpangan_baku_standar' => $stdevStd,
            'jumlah_pengulangan' => count($ulanganKonv),
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agregat['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $u,
            'pita_cmc' => $pita,
            'lantai_cmc' => $lantai,
            'lantai_cmc_dipakai' => $u95 > $u,
            'u95_sertifikat' => $u95,
            'u95_persen_of_reading' => $uutRata == 0.0 ? null : $u95 / $uutRata * self::PERSEN,
            'type_a' => $this->rss($budget, 't-student', true),
            'type_b' => $this->rss($budget, 't-student', false),
        ];
    }

    /**
     * Delapan komponen (Totalizer) atau sembilan (Flowrate), urutannya sama
     * dengan sheet `PERHITUNGAN U95%` master blok Titik 1.
     *
     * `u_mentah` disimpan berdampingan dengan `pembagi`, sama seperti kolom (4)
     * dan (5) master — supaya jejak auditnya bisa diadu kolom demi kolom, bukan
     * cuma hasil akhirnya.
     *
     * @param  array{standard: float, uut: float, koreksi: float, persen_of_reading: float, u: float}  $baris
     * @param  array{a: float, u_a: float, diameter_luar: float, ketebalan: float, diameter_dalam: float}  $geometri
     * @return list<array{sumber: string, keterangan: string, satuan: string, distribusi: string, u_mentah: float, pembagi: float, u: float, ci: float, vi: float}>
     */
    private function budget(
        string $mode,
        array $baris,
        float $resolusi,
        float $uTemperature,
        float $stdTerkoreksi,
        float $stdTerkoreksiSimpel,
        float $rhoStd,
        array $geometri,
        ?float $stdevUut,
        float $stdevStd,
        int $nPasang,
    ): array {
        $k = $this->tabel()->konstanta();
        $flowrate = $mode === TabelStandarFlowmeter::MODE_FLOWRATE;
        $satuan = $flowrate ? 'Lpm' : 'L';
        $rect = $k['pembagi_rect'];      // 1,73 — ditiru, bukan √3. §7
        $normal = $k['pembagi_normal'];
        $akar3 = sqrt(3.0);              // komponen pengulangan memang √3 di master
        $viB = $k['vi_type_b'];
        $viUlang = (float) max(1, $nPasang - 1);

        // `ci` suhu memakai standar terkoreksi ber-RASIO DENSITAS (`D66`/`D67`),
        // sementara cross-sectional/velocity/geometry memakai yang TANPA rasio
        // (`D63`/`D64`). Bedanya nol selama densitas UUT tidak diketik, dan
        // nyata begitu diketik — jadi keduanya dibedakan, bukan disatukan.
        $ciSuhu = $stdTerkoreksi * $k['koefisien_muai_air_per_c'] / $rhoStd ** 2;

        $fu = [
            'sumber' => 'standar_ufm',
            'keterangan' => 'Ketidakpastian Standar Flowmeter Ultrasonik (FU)',
            'satuan' => $satuan,
            'distribusi' => 'normal',
            'u_mentah' => $baris['u'],
            'pembagi' => $normal,
            'ci' => 1.0,
            'vi' => $k['vi_standar_ufm'],
        ];

        $resolusiKomponen = [
            'sumber' => 'resolusi_uut',
            'keterangan' => 'Resolusi alat yang dikalibrasi',
            'satuan' => $satuan,
            'distribusi' => 'rectangular',
            'u_mentah' => $resolusi / 2,
            'pembagi' => $rect,
            'ci' => 1.0,
            'vi' => $viB,
        ];

        $suhu = [
            'sumber' => 'suhu_fluida',
            'keterangan' => $flowrate
                ? 'Ketidakpastian pengukuran temperature media fluida'
                : 'Ketidakpastian pengukuran temperature fluida',
            'satuan' => '°C',
            'distribusi' => 'normal',
            'u_mentah' => $uTemperature,
            'pembagi' => $normal,
            'ci' => $ciSuhu,
            // vi 2 di Totalizer, 50 di Flowrate — komponen yang sama. §6
            'vi' => $flowrate ? $k['vi_suhu_flowrate'] : $k['vi_suhu_totalizer'],
        ];

        $pengulanganStd = [
            'sumber' => 'pengulangan_standar',
            'keterangan' => $flowrate ? 'Pengulangan pembacaan standar' : 'Pengulangan pembacaan',
            'satuan' => $satuan,
            'distribusi' => 't-student',
            'u_mentah' => $stdevStd,
            'pembagi' => $akar3,
            'ci' => 1.0,
            'vi' => $viUlang,
        ];

        $crossSection = [
            'sumber' => 'cross_sectional_area',
            'keterangan' => 'Ketidakpastian cross sectional area',
            'satuan' => 'mm²',
            'distribusi' => 'rectangular',
            'u_mentah' => $geometri['u_a'],
            // Pembagi 2 di Totalizer, 1,73 di Flowrate. §6
            'pembagi' => $flowrate
                ? $k['pembagi_cross_section_flowrate']
                : $k['pembagi_cross_section_totalizer'],
            'ci' => $stdTerkoreksiSimpel / $geometri['a'],
            'vi' => $viB,
        ];

        $velocity = [
            'sumber' => 'velocity_profile',
            'keterangan' => 'Velocity profile',
            'satuan' => $satuan,
            'distribusi' => 'rectangular',
            'u_mentah' => $k['velocity_profile_persen'] / self::PERSEN * $stdTerkoreksiSimpel,
            'pembagi' => $rect,
            'ci' => 1.0,
            'vi' => $viB,
        ];

        $geometryFactor = [
            'sumber' => 'geometry_factor',
            'keterangan' => 'Geometry factor',
            'satuan' => $satuan,
            'distribusi' => 'rectangular',
            'u_mentah' => $k['geometry_factor_persen'] / self::PERSEN * $stdTerkoreksiSimpel,
            'pembagi' => $rect,
            'ci' => 1.0,
            'vi' => $viB,
        ];

        $drift = [
            'sumber' => 'drift_ufm',
            'keterangan' => 'Drift UFM standar',
            'satuan' => $satuan,
            'distribusi' => 'rectangular',
            // 1 % dari `U` MENTAH standar (kolom 4 master), bukan dari `ui`.
            // Sheet drift tersembunyi menghitung 0,5·(Cmax−Cmin)/√3 dan TIDAK
            // dipakai — pertanyaan lab §11.
            'u_mentah' => $k['drift_ufm_persen'] / self::PERSEN * $baris['u'],
            'pembagi' => $rect,
            'ci' => 1.0,
            'vi' => $viB,
        ];

        if (! $flowrate) {
            // Totalizer — 8 komponen, urutan master blok Titik 1 baris 48..55.
            $budget = [
                $fu, $resolusiKomponen, $suhu, $pengulanganStd,
                $crossSection, $velocity, $geometryFactor, $drift,
            ];
        } else {
            $pengulanganUut = [
                'sumber' => 'pengulangan_uut',
                'keterangan' => 'Pengulangan pembacaan UUT',
                'satuan' => $satuan,
                'distribusi' => 't-student',
                'u_mentah' => $stdevUut ?? 0.0,
                'pembagi' => $akar3,
                'ci' => 1.0,
                'vi' => $viUlang,
            ];
            // Flowrate — 9 komponen, urutan master blok Titik 1 baris 47..55.
            // Blok Titik 2 master menulis distribusi komponen ini `rectangular`
            // padahal pembaginya √3 dan vi-nya n−1; itu label yang salah, dan
            // Titik 1 & 3 menulis `T-Student`. Yang diikuti Titik 1.
            $budget = [
                $fu, $resolusiKomponen, $pengulanganUut, $suhu, $pengulanganStd,
                $crossSection, $velocity, $geometryFactor, $drift,
            ];
        }

        return array_map(static function (array $b): array {
            $b['u'] = $b['u_mentah'] / $b['pembagi'];

            return $b;
        }, $budget);
    }

    /**
     * RSS `u·ci` komponen yang distribusinya `$distribusi` (kalau `$cocok`) atau
     * yang BUKAN (kalau tidak).
     *
     * Aturannya disamakan dengan `GumCalculator::hitungDariBudget()` biar kolom
     * `type_a`/`type_b` tidak beda arti antar-alat.
     *
     * @param  list<array<string, mixed>>  $budget
     */
    private function rss(array $budget, string $distribusi, bool $cocok): float
    {
        return sqrt(array_sum(array_map(
            static fn (array $b): float => ($b['u'] * $b['ci']) ** 2,
            array_filter(
                $budget,
                static fn (array $b): bool => ($b['distribusi'] === $distribusi) === $cocok,
            ),
        )));
    }

    private function alasanSatuan(string $mode, string $satuan): string
    {
        return sprintf(
            'Satuan `%s` tidak dikenal untuk mode %s. Yang dikenal: %s.',
            $satuan,
            $mode,
            implode(', ', $this->tabel()->satuanDikenal($mode)),
        );
    }

    /** @param  list<float>  $nilai */
    private function punyaSebaran(array $nilai): bool
    {
        return count($nilai) >= 2 && max($nilai) !== min($nilai);
    }

    /** @param  list<list<float>>  $bersarang */
    private function punyaSebaranBersarang(array $bersarang): bool
    {
        foreach ($bersarang as $deret) {
            if ($this->punyaSebaran($deret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deret angka yang bersih — yang bukan angka DILEWATI, bukan dibaca nol.
     *
     * Satu kotak kosong yang dipaksa jadi `0.0` di antara pembacaan 1002,65
     * menggelembungkan simpangan bakunya ratusan kali, dan yang terbit bukan
     * error melainkan U95 yang salah besar.
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

    private function tabel(): TabelStandarFlowmeter
    {
        // Malas, bukan di parameter bawaan konstruktor: profil -> kalkulator ->
        // GumCalculator -> CalibrationProfileRegistry -> profil itu lagi membuat
        // lingkaran yang gejalanya (`Infinite recursion?`) muncul jauh dari
        // penyebabnya.
        return $this->tabel ??= new TabelStandarFlowmeter;
    }

    private function gum(): GumCalculator
    {
        return $this->gum ??= new GumCalculator;
    }
}
