<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Mesin hitung **Flowmeter Gravimetri (ISO 4185)** — varian metode kedua untuk
 * alat ke-27 & ke-28, bukan alat ke-29.
 *
 * Sumbernya dua workbook master (password `spirit285`):
 *
 *  - `1.2 Master olda Flowmeter Totalizer dini (2026) 140-2500L.xlsm`
 *  - `2.1 Master olda Flowmeter Flowrate 100-980lpm 2026.xlsm`
 *
 * Keduanya mengukur alat yang sama, besaran yang sama, dan pita CMC yang sama
 * dengan varian UFM ([FlowmeterCalculator]) — dengan metode yang sama sekali
 * lain: penimbangan statis, timbangan digital sebagai standar.
 * `PERHITUNGAN FC!B74` menulis judulnya sendiri: `ISO 4185`, `Laju alir masa`.
 *
 * Kenapa varian, bukan profil baru: lihat [VarianMetodeFlowmeter].
 *
 * ## Rantai hitungnya
 *
 * ```
 * Totalizer                                  Flowrate (tambahan sumbu waktu)
 * M   = (W_isi − W_kosong) + koreksi_tabel    M   = idem
 * E   = ρ_udara·(1/ρ_air − 1/ρ_anak)          E   = idem
 * Mt  = M · (1 + E)                           Mt  = M · (1 + E)
 * hasil = Mt / ρ_air                          t   = t_rata + koreksi_timer
 * dev = hasil − UUT_rata                      laju = Mt / t
 *                                             Q   = laju / ρ_air
 *                                             dev = Q − UUT_rata
 * ```
 *
 * Densitas air TIDAK dihitung dengan rumus. Master menimbang piknometer
 * 50,3139 ml di empat suhu lalu menginterpolasi linier — lihat
 * [TabelStandarFlowmeterGravimetri::densitasAir]. Mengganti dengan Tanaka/Kell
 * (yang dipakai varian UFM) menggeser tiap sertifikat gravimetri lama.
 *
 * ## Yang TIDAK ditiru — dan semuanya membuat angkanya lebih jujur
 *
 * Tiap-tiapnya sudah terukur, bukan dikira:
 *
 *  1. **Koreksi timer dihitung lalu dibuang (Flowrate).** `PERHITUNGAN FC`
 *     menghitung rantai lengkap `t_rata` → `Index Timer` → `Correction Standard`
 *     → `Standard Corrected (minute)` di `D48`. Lalu laju alir massa memakai
 *     `D45` — waktu MENTAH. `D48` tidak dibaca satu sel pun di seluruh workbook.
 *
 *     Terukur di titik 1: deviasi bergerak dari **−0,0041534** ke
 *     **−0,0112284 Lpm** — 2,70 kali lebih besar. Koreksi standar yang sudah
 *     dihitung memang untuk dipakai, dan seluruh rantai lain di workbook yang
 *     sama (timbangan, suhu) memakai nilai terkoreksinya. Pertanyaan lab §3.
 *  2. **Dua rumus koreksi apung dalam SATU sheet (Flowrate).** `D75` (titik 1)
 *     berbunyi `= D74 * (1 + E)`; `I75` dan `M75` (titik 2 & 3) berbunyi
 *     `= I74 / (1 − ρ_udara/ρ_air)`. Keduanya pendekatan yang sah, tapi bukan
 *     hal yang sama: selisihnya 0,0151 % pada massa. Workbook Totalizer memakai
 *     bentuk KALI untuk keempat titiknya.
 *
 *     Dipilih bentuk KALI — mayoritas, dan konsisten dengan Totalizer.
 *     Terukur di Flowrate titik 2: Mt bergerak dari **9,9410046** ke
 *     **9,9394996 kg**. Pertanyaan lab §4.
 *  3. **Suhu terkoreksi memakai INDEX, bukan rata-rata (Flowrate).**
 *     `PERHITUNGAN FC!D66` berbunyi `= D64 + D65` — titik tabel (25) ditambah
 *     koreksi (−0,02), jadi 24,98 °C, padahal suhu air yang terukur 26,5 °C.
 *     Workbook Totalizer melakukannya dengan benar (`D58 = D55 + D57` = rata +
 *     koreksi). Salin-tempel murni.
 *
 *     Terukur: ρ_air bergerak dari **0,9964893** ke **0,9959691 kg/L** (0,052 %),
 *     dan deviasi titik 1 ikut bergeser 25 %. Pertanyaan lab §21.
 *  4. **`Ut-water` menunjuk sel kosong.** Sama persis dengan master UFM:
 *     labelnya `(Tmax−Tmin)Water` tapi rentang selnya satu kolom di luar blok
 *     suhu, jadi selalu nol. Di sini dia dihitung dari suhu air sesi yang
 *     sebenarnya. `U_temperature` naik dari 0,2780288 ke **0,3437051 °C**
 *     (Totalizer) dan **0,2795234 °C** (Flowrate). Arahnya aman: U membesar.
 *  5. **Komponen Koreksi Bouyancy lenyap di titik 2, 3, dan 4 Totalizer.**
 *     `E45`/`E65`/`E85` bernilai `0` sementara `E25` (titik 1) berisi
 *     `0,0005 % × Totalizer`. Rumusnya tidak ikut tersalin. Di sini dihitung di
 *     KEEMPAT titik. Sumbangannya kecil, tapi komponen yang lenyap tanpa jejak
 *     adalah persis kelas kerusakan yang tidak pernah menghasilkan error.
 *  6. **Tabel koreksi timbangan bersatuan gram dicocokkan ke penimbangan
 *     kilogram.** Lihat [TabelStandarFlowmeterGravimetri::cocokTerdekat].
 *  7. **Lantai CMC hilang.** Sel berlabel `CMC` ada di keempat blok (`J35`,
 *     `J55`, `J75`, `J95`) dan SEMUANYA kosong, sementara tabelnya lengkap di
 *     `DATABASE!R5:S6`. Keempat titik sesi contoh terbit di bawah pita — titik 3
 *     mengklaim ketidakpastian **sebelas kali** lebih baik dari yang diakui KAN.
 *     Terukur: titik 1 naik dari 1,06076 ke **1,68128 L**. §1.
 *  8. **Sel U95 sertifikat berhenti dikonversi di titik 3 & 4.**
 *     `K36`/`K56` berbunyi `= J34 * ρ_air`; `K76`/`K96` berbunyi
 *     `= MAX(J74:K75)` tanpa konversi, jadi dua baris sertifikat mencetak
 *     KILOGRAM di kolom berjudul LITER. Selisihnya 0,36 % hari ini karena
 *     fluidanya air — kalau suatu saat bukan air, dia meledak. Satu jalur,
 *     konversi selalu. §6.
 *
 * ## Yang DITIRU walau janggal
 *
 * Semuanya diangkat ke `docs/pertanyaan-lab-flowmeter-gravimetri.md`:
 *
 *  - **Pembagi `1,73`** alih-alih `√3` di komponen rectangular, sementara
 *    komponen pengulangan memakai `SQRT(3)` sungguhan di sheet yang SAMA. §9.
 *  - **Drift dibagi 2 lagi** sesudah sheet drift-nya sendiri sudah memotong
 *    `0,5·ΔC`. Judul kolomnya menjanjikan `/√3` yang tidak pernah dipakai. §17.
 *  - **Komponen 8 Flowrate `normal` dengan `U_temperature` utuh**, sementara
 *    komponen sebangun di Totalizer `rectangular` dengan `U_temperature/2`.
 *    Komponen yang sama, dua perlakuan. §9.
 *  - **`ci` suhu = `Mt · 0,00021 / ρ²`** — asal 0,00021 tidak bersumber di
 *    workbook mana pun. §22.
 *  - **Densitas anak timbang 8 kg/L dan densitas udara 1,2 g/L** dipatok
 *    nominal ISO 4185, bukan hasil ukur. §13.
 *
 * ## `vi` yang dipakai: blok TITIK 1 saja
 *
 * Blok titik 2, 3, dan 4 Totalizer memakai `vi` yang berbeda-beda untuk
 * komponen yang sama — `vi` timbangan 60 lalu 200, kestabilan 50 lalu 60, drift
 * 50 lalu **1.000.000**, dan pembagi Water Density 2 lalu 1,73. Cuma blok titik
 * 1 yang konsisten dengan dirinya sendiri, dan itu yang diikuti keempat titik.
 * Akibatnya U95 titik 2 & 3 kita sedikit berbeda dari master — arahnya
 * ditegakkan test, bukan didiamkan. §10.
 */
class FlowmeterGravimetriCalculator
{
    private const PERSEN = 100.0;

    private ?GumCalculator $gum = null;

    private ?TabelStandarFlowmeterGravimetri $tabel = null;

    private ?TabelStandarFlowmeter $tabelUfm = null;

    /**
     * Satu sesi gravimetri: deret mentah tiap titik -> deviasi + budget + U95.
     *
     * `$konteks` tingkat-sesi: `mode`, `satuan`, `resolusi`, `kode_timbangan`.
     *
     * `$titik[i]`: `titik_ke`, `uut` (Totalizer: list ulangan; Flowrate: list
     * ulangan berisi list durasi), `berat_isi` (list ulangan, kg), `berat_kosong`
     * (list ulangan, kg — boleh kosong), `waktu_menit` (list ulangan, hanya
     * Flowrate), `suhu_awal`, `suhu_akhir` (list per ulangan).
     *
     * @param  list<array<string, mixed>>  $titik
     * @param  array<string, mixed>  $konteks
     * @return array<string, mixed>
     */
    public function hitungSesi(array $titik, array $konteks): array
    {
        $mode = (string) $konteks['mode'];
        $satuan = (string) ($konteks['satuan'] ?? '');
        $kodeTimbangan = (int) ($konteks['kode_timbangan'] ?? 0);
        $resolusiMentah = (float) ($konteks['resolusi'] ?? 0.0);

        $ditolak = [];
        $peringatan = [];
        $dihitung = [];

        $timbangan = $this->tabel()->timbangan($mode, $kodeTimbangan);

        // Suhu air SELURUH sesi — `Ut_water` satu angka tingkat-sesi, sama
        // seperti master (satu sel `I9` dipakai semua blok titik).
        $suhuSesi = [];

        foreach ($titik as $t) {
            $suhuSesi = array_merge(
                $suhuSesi,
                $this->deretAngka($t['suhu_awal'] ?? []),
                $this->deretAngka($t['suhu_akhir'] ?? []),
            );
        }

        $uTemperature = $this->ketidakpastianSuhu($suhuSesi);

        foreach ($titik as $t) {
            $nomor = (int) $t['titik_ke'];

            if ($timbangan === null) {
                $ditolak[] = ['titik_ke' => $nomor, 'alasan' => sprintf(
                    'Kode timbangan standar `%s` tidak ada di tabel mode %s (yang ada: %s). '
                    .'Kode timbangan menentukan tabel koreksi, U95, kestabilan, DAN drift '
                    .'sekaligus — salah pilih, angkanya tetap keluar dan tetap terlihat wajar.',
                    $kodeTimbangan,
                    $mode,
                    implode(', ', array_keys($this->tabel()->semuaTimbangan($mode))) ?: '(kosong)',
                )];

                continue;
            }

            $hasil = $this->hitungTitik($t, $mode, $satuan, $resolusiMentah, $timbangan, $uTemperature);

            if (isset($hasil['alasan'])) {
                $ditolak[] = ['titik_ke' => $nomor, 'alasan' => $hasil['alasan']];

                continue;
            }

            if ($hasil['jarak_tabel'] > TabelStandarFlowmeterGravimetri::AMBANG_JARAK_TABEL) {
                $peringatan[] = sprintf(
                    'Titik %d: massa terukur %.4f kg memungut koreksi titik tabel %.4f kg — jaraknya '
                    .'%.1f %% dari penimbangan. Master memakai pencocokan TERDEKAT (bukan interpolasi), '
                    .'jadi koreksi titik tabel itu dipakai utuh. Lihat '
                    .'docs/pertanyaan-lab-flowmeter-gravimetri.md §15.',
                    $nomor,
                    $hasil['massa_bersih'],
                    $hasil['titik_tabel'],
                    $hasil['jarak_tabel'] * self::PERSEN,
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
            'u_temperature' => $uTemperature,
            'resolusi_mentah' => $resolusiMentah,
            'timbangan' => $timbangan,
            'varian' => VarianMetodeFlowmeter::GRAVIMETRI->value,
        ];
    }

    /**
     * `U_temperature` tingkat sesi.
     *
     * `√( (U95_kalibrator/2)² + (U95_sensor/2)² + Ut_water² )`, dengan
     * `Ut_water = (Tmax − Tmin)_air / (2√3)`.
     *
     * Suku ketiga itu yang di master selalu nol karena rentang selnya meleset
     * satu kolom — lihat penyimpangan no. 4 di docblock kelas.
     *
     * @param  list<float>  $suhuAir
     */
    public function ketidakpastianSuhu(array $suhuAir): float
    {
        $k = $this->tabel()->konstanta();
        $u95Sensor = (float) ($this->tabel()->sensorSuhu()['u95_c'] ?? 0.0);
        $u95Kalibrator = 0.0;

        // U95 kalibrator dipilih dari titik tabel yang benar-benar dipakai sesi
        // ini, bukan dipatok — dia 0,35 di −100 °C dan 0,34 di 25 °C.
        if ($suhuAir !== []) {
            $baris = $this->tabel()->koreksiSuhu(array_sum($suhuAir) / count($suhuAir));
            $u95Kalibrator = $baris['u95_c'] ?? 0.0;
        }

        $utWater = $suhuAir === []
            ? 0.0
            : (max($suhuAir) - min($suhuAir)) / (float) ($k['pembagi_ut_water'] ?? (2 * sqrt(3.0)));

        return sqrt(($u95Kalibrator / 2) ** 2 + ($u95Sensor / 2) ** 2 + $utWater ** 2);
    }

    /**
     * Simpangan baku sampel (n−1), sama dengan `STDEV()` Excel.
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

        return sqrt(array_sum(array_map(
            static fn (float $x): float => ($x - $rata) ** 2,
            $nilai,
        )) / ($n - 1));
    }

    /**
     * Satu titik: deret mentah -> deviasi + budget + U95 yang berlantai CMC.
     *
     * Balik `['alasan' => ...]` kalau titiknya tidak boleh terbit. Tiap alasan
     * menyebut AKIBATNYA — yang membacanya admin yang sedang memutuskan approve,
     * bukan pengembang.
     *
     * @param  array<string, mixed>  $t
     * @param  array<string, mixed>  $timbangan
     * @return array<string, mixed>
     */
    private function hitungTitik(
        array $t,
        string $mode,
        string $satuan,
        float $resolusiMentah,
        array $timbangan,
        float $uTemperature,
    ): array {
        $flowrate = $mode === TabelStandarFlowmeter::MODE_FLOWRATE;
        $satuanHasil = $flowrate ? 'Lpm' : 'L';
        $k = $this->tabel()->konstanta();

        // --- deret UUT ------------------------------------------------------
        // Flowrate: 3 ulangan x 3 durasi (20"/40"/60"). Totalizer: 3 ulangan.
        $ulangan = [];

        foreach ($t['uut'] ?? [] as $u) {
            $deret = $this->deretAngka(is_array($u) ? $u : [$u]);

            if ($deret !== []) {
                $ulangan[] = $deret;
            }
        }

        $beratIsi = $this->deretAngka($t['berat_isi'] ?? []);
        $beratKosong = $this->deretAngka($t['berat_kosong'] ?? []);

        if (count($ulangan) < 2 || count($beratIsi) < 2) {
            return ['alasan' => sprintf(
                'Titik ini butuh minimal dua pembacaan UUT dan dua penimbangan; yang ada %d dan %d. '
                .'Dengan kurang dari dua, simpangan bakunya nol dan komponen pengulangan hilang dari '
                .'budget — U95 terbit lebih kecil tanpa satu pun angka yang terlihat ganjil.',
                count($ulangan),
                count($beratIsi),
            )];
        }

        // --- suhu air titik ini ---------------------------------------------
        $suhuAwal = $this->deretAngka($t['suhu_awal'] ?? []);
        $suhuAkhir = $this->deretAngka($t['suhu_akhir'] ?? []);

        if ($suhuAwal === [] || $suhuAkhir === []) {
            return ['alasan' => 'Suhu air awal dan akhir titik ini belum tercatat, jadi densitas air tidak bisa '
                .'ditentukan. Densitas masuk hasil akhir sebagai PEMBAGI — tanpa dia hasilnya bukan '
                .'sekadar kurang teliti, melainkan tidak terdefinisi.',
            ];
        }

        // Rata-rata DULU, baru dikoreksi — bukan titik tabel yang dikoreksi.
        // Lihat penyimpangan no. 3 di docblock kelas.
        $densitasAwal = $this->densitasTerkoreksi(array_sum($suhuAwal) / count($suhuAwal));
        $densitasAkhir = $this->densitasTerkoreksi(array_sum($suhuAkhir) / count($suhuAkhir));

        if ($densitasAwal === null || $densitasAkhir === null) {
            $titikDensitas = $this->tabel()->densitasAirMeta()['titik'] ?? [];
            $terakhir = $titikDensitas === [] ? null : $titikDensitas[count($titikDensitas) - 1];

            return ['alasan' => sprintf(
                'Suhu air titik ini ada di luar jangkauan tabel densitas piknometer (%.1f – %.1f °C). '
                .'Master memungut baris terdekat apa pun jaraknya; mengekstrapolasi densitas air '
                .'melesetkan hasilnya di digit yang tercetak. Titik ini tidak diterbitkan.',
                (float) ($titikDensitas[0]['suhu_c'] ?? 0.0),
                (float) ($terakhir['suhu_c'] ?? 0.0),
            )];
        }

        $rho = ($densitasAwal + $densitasAkhir) / 2;

        // --- massa ------------------------------------------------------------
        $isiRata = array_sum($beratIsi) / count($beratIsi);
        // Wadah kosong DIKURANGKAN. Master tidak pernah mengujinya — blok
        // `Empty Container Weight` bernilai nol di seluruh sesi kedua workbook,
        // jadi `D41 = D38 + D40` kebetulan benar di sana. Sesi pertama yang
        // benar-benar memakai wadah akan terbit dengan massa kelebihan berat
        // wadahnya kalau pengurangan ini tidak ada, dan tanpa satu pun error.
        // Pertanyaan lab §11; dijaga `test_wadah_kosong_dikurangkan`.
        $kosongRata = $beratKosong === [] ? 0.0 : array_sum($beratKosong) / count($beratKosong);
        $massaBersih = $isiRata - $kosongRata;

        if ($massaBersih <= 0.0) {
            return ['alasan' => sprintf(
                'Berat wadah kosong (%.4f kg) tidak lebih kecil dari berat isi (%.4f kg), jadi massa '
                .'bersihnya nol atau negatif. Kemungkinan besar kolom isi dan kolom wadah tertukar.',
                $kosongRata,
                $isiRata,
            )];
        }

        $kode = (int) $timbangan['kode'];

        if (! $this->tabel()->dalamJangkauan($mode, $kode, $massaBersih)) {
            $rentang = $this->tabel()->rentangPakai($mode, $kode) ?? ['min' => 0.0, 'maks' => 0.0];

            return ['alasan' => sprintf(
                'Massa bersih %.4f kg ada di LUAR rentang pakai tabel koreksi timbangan %s '
                .'(%.4f – %.4f kg). Master memungut titik tabel terdekat berapa pun jaraknya — '
                .'termasuk memungut koreksi titik 27 g untuk penimbangan 27 kg. Titik ini tidak '
                .'diterbitkan.',
                $massaBersih,
                $timbangan['merk'] ?? '?',
                $rentang['min'],
                $rentang['maks'],
            )];
        }

        $barisTabel = $this->tabel()->cocokTerdekat($mode, $kode, $massaBersih);

        if ($barisTabel === null) {
            return ['alasan' => sprintf(
                'Tabel koreksi timbangan %s kosong untuk mode %s.',
                $timbangan['merk'] ?? '?',
                $mode,
            )];
        }

        $massa = $massaBersih + $barisTabel['koreksi_kg'];

        // --- konversi satuan DI TEMPAT PAKAI, sesudah densitas diketahui ------
        $resolusi = $this->konversi($mode, $resolusiMentah, $satuan, $rho);

        if ($resolusi === null || $resolusi <= 0.0) {
            return ['alasan' => sprintf(
                'Resolusi alat belum diisi, atau satuan `%s` tidak bisa dikonversi ke %s. Tanpa '
                .'resolusi, komponen resolusi budget bernilai nol dan U95 terbit lebih kecil dari '
                .'seharusnya.',
                $satuan,
                $satuanHasil,
            )];
        }

        $ulanganKonv = [];

        foreach ($ulangan as $u) {
            $deretKonv = [];

            foreach ($u as $nilai) {
                $hasilKonv = $this->konversi($mode, $nilai, $satuan, $rho);

                if ($hasilKonv === null) {
                    return ['alasan' => sprintf(
                        'Satuan `%s` tidak dikenal untuk mode %s. Yang dikenal: %s.',
                        $satuan,
                        $mode,
                        implode(', ', $this->tabelUfm()->satuanDikenal($mode)),
                    )];
                }

                $deretKonv[] = $hasilKonv;
            }

            $ulanganKonv[] = $deretKonv;
        }

        $semuaUut = array_merge(...$ulanganKonv);
        $uutRata = array_sum($semuaUut) / count($semuaUut);

        // --- koreksi apung ISO 4185 -------------------------------------------
        $rhoUdara = (float) $k['densitas_udara_kg_per_l'];
        $rhoAnak = (float) $k['densitas_anak_timbang_kg_per_l'];
        $e = $rhoUdara * (1 / $rho - 1 / $rhoAnak);
        // Bentuk KALI di seluruh titik — lihat penyimpangan no. 2.
        $mt = $massa * (1 + $e);

        // --- sumbu waktu (Flowrate saja) ---------------------------------------
        $waktu = $this->deretAngka($t['waktu_menit'] ?? []);
        $waktuRata = null;
        $waktuTerkoreksi = null;
        $koreksiTimer = 0.0;
        $stdevWaktu = 0.0;

        if ($flowrate) {
            // SATU cukup, bukan dua — dan angkanya datang dari kertas, bukan
            // selera. `SIDIK-FM-CAL-0538.A_Rev.3` cuma punya SATU baris
            // `Time ( )` di bawah blok `STANDARD READING`, satu kotak per set
            // point; yang tiga baris cuma penimbangannya. Master workbook
            // memang mengisi tiga (`INPUT DATA!D49:D51`), tapi kertas Rev.3
            // yang dipegang teknisi hanya menyediakan satu.
            //
            // Menuntut dua berarti setiap sesi yang diisi dari kertas Rev.3
            // diblokir dengan alasan yang TIDAK BISA dipenuhi teknisi — kotaknya
            // memang tidak ada. Dan simpangan baku waktu tidak masuk satu pun
            // komponen budget (yang masuk U95 stopwatch dan driftnya, keduanya
            // dari sertifikat standar), jadi satu nilai tidak menghilangkan apa
            // pun dari perhitungan.
            if ($waktu === []) {
                return ['alasan' => 'Mode flowrate butuh pencatatan waktu, dan tidak ada satu pun yang terisi. '
                    .'Waktu masuk hasil akhir sebagai PEMBAGI dan masuk budget lewat dua komponen '
                    .'(ketidakpastian stopwatch dan driftnya) — tanpa dia hasilnya bukan sekadar '
                    .'kurang teliti, melainkan tidak terdefinisi.',
                ];
            }

            if (min($waktu) <= 0.0) {
                return ['alasan' => 'Ada pencatatan waktu yang nol atau negatif. Laju alir massa membaginya, jadi '
                    .'hasilnya bukan sekadar salah melainkan tak hingga.',
                ];
            }

            $waktuRata = array_sum($waktu) / count($waktu);
            $titikTimer = $this->tabel()->koreksiTimer($waktuRata);
            // Koreksi timer DIPAKAI — lihat penyimpangan no. 1.
            $koreksiTimer = $titikTimer['koreksi_min'] ?? 0.0;
            $waktuTerkoreksi = $waktuRata + $koreksiTimer;
            $stdevWaktu = $this->simpanganBaku($waktu);

            if ($waktuTerkoreksi <= 0.0) {
                return ['alasan' => 'Waktu terkoreksi jatuh ke nol atau negatif — koreksi timernya lebih besar '
                    .'daripada durasi yang dicatat.',
                ];
            }
        }

        // --- hasil --------------------------------------------------------------
        if ($flowrate) {
            $lajuMassa = $mt / $waktuTerkoreksi;
            $hasil = $lajuMassa / $rho;
        } else {
            $lajuMassa = null;
            $hasil = $mt / $rho;
        }

        $deviasi = $hasil - $uutRata;

        // --- simpangan baku ------------------------------------------------------
        // Totalizer: selisih BERPASANGAN penimbangan lawan UUT per ulangan
        // (`PERHITUNGAN FC!D44:D46`). Flowrate: deret laju yang diturunkan dari
        // tiap penimbangan (`D51:D53`) — UUT-nya identik di ketiga ulangan, jadi
        // yang menyumbang sebaran cuma penimbangannya.
        if ($flowrate) {
            $selisih = array_map(static fn (float $w): float => $w / $rho, $beratIsi);
        } else {
            $pasang = min(count($ulanganKonv), count($beratIsi));
            $selisih = [];

            for ($i = 0; $i < $pasang; $i++) {
                $selisih[] = $beratIsi[$i] - $ulanganKonv[$i][0];
            }
        }

        // Deret yang seluruhnya bernilai sama lolos penjaga `n >= 2` di atas, dan
        // itu tanda tangan "satu nilai disalin n kali" bukan pengukuran. Diuji
        // `max !== min`, BUKAN `stdev > 0`: simpangan baku sepuluh nilai identik
        // cuma nol EKSAK kalau nilainya bisa direpresentasikan persis dalam
        // biner, dan 599,95 sepuluh kali memulangkan 1,2e-13. Di sini bahayanya
        // nyata — ketiga ulangan UUT Flowrate sesi contoh identik persis.
        // Diuji DUA-DUANYA, dan bukan kerapian. Di mode Totalizer yang masuk
        // budget deret SELISIH berpasangan, dan selisih itu tetap bervariasi
        // walau ketiga penimbangannya angka yang sama persis — cukup UUT-nya
        // yang bergerak. Akibatnya komponen "Pengulangan Pembacaan" berubah
        // diam-diam jadi ukuran sebaran UUT, bukan sebaran penimbangan, dan
        // tanda tangan "satu nilai disalin tiga kali" lolos tanpa jejak.
        if (! $this->punyaSebaran($beratIsi) || ! $this->punyaSebaran($selisih)) {
            return ['alasan' => 'Penimbangan titik ini tidak punya sebaran sama sekali — seluruh ulangannya '
                .'bernilai persis sama. Simpangan bakunya nol, jadi komponen pengulangan hilang dari '
                .'budget dan U95 terbit lebih kecil sambil tetap terlihat wajar. Ulangi '
                .'pengukurannya dan catat tiap penimbangan apa adanya.',
            ];
        }

        $stdevStd = $this->simpanganBaku($selisih);

        // --- pita CMC: gerbang DAN lantai -----------------------------------------
        // Pita dipilih dan lantai dihitung dari RATA-RATA UUT, sama persis
        // dengan varian UFM ([FlowmeterCalculator]).
        //
        // Master sendiri tidak konsisten: `%OR` dihitung dari nilai terkoreksi
        // di Totalizer titik 1 (`M36`), dari MASSA di titik 2 (`M54`), dari
        // rata-rata UUT di Flowrate (`M37`), dan titik 2, 3, 4 Totalizer tidak
        // punya selnya sama sekali. Tiga penyebut untuk satu besaran.
        //
        // Yang dipilih rata-rata UUT, dan alasannya BUKAN metrologi — kedua
        // penyebut sama-sama bisa dibela, selisihnya 0,3 % di sesi contoh, dan
        // yang memutuskan lab (pertanyaan lab §23). Alasannya: `flowmeter:audit-cmc`
        // menghitung `%OR` sesi tersimpan dari `rata_rata`, jadi lantai yang
        // memakai penyebut lain menandai SETIAP sesi gravimetri sebagai "perlu
        // ditinjau" selamanya — peringatan palsu yang melatih admin menekan
        // "setujui tetap" tanpa membaca. Dua varian, satu aturan.
        //
        // Terukur: titik 1 Totalizer berlantai 1,68128 L dengan rata-rata UUT,
        // 1,68635 L dengan nilai terkoreksi.
        $pita = $this->tabelUfm()->pitaCmc($mode, $uutRata);

        if ($pita === null) {
            return ['alasan' => sprintf(
                'Bacaan rata-rata UUT %.4f %s jatuh di LUAR kedua pita CMC terakreditasi (%s). '
                .'Sertifikat yang terbit di titik ini akan membawa nomor lingkup LK-285-IDN untuk '
                .'pengukuran yang tidak diakreditasi. Titik ini tidak diterbitkan.',
                $uutRata,
                $satuanHasil,
                implode(', ', array_map(
                    static fn (array $p): string => $p['label'],
                    $this->tabelUfm()->semuaPitaCmc($mode),
                )),
            )];
        }

        // --- budget ----------------------------------------------------------------
        $budget = $this->budget(
            $flowrate,
            $timbangan,
            $resolusi,
            $uTemperature,
            $massa,
            $mt,
            $rho,
            $hasil,
            $uutRata,
            $waktuTerkoreksi,
            $stdevStd,
            count($selisih),
        );

        $agregat = $this->gum()->agregasiBudget(array_map(
            static fn (array $b): array => ['u' => $b['u'], 'ci' => $b['ci'], 'vi' => $b['vi']],
            $budget,
        ));

        // U keluar dalam kg (Totalizer) atau kg/menit (Flowrate); sertifikat
        // mencetaknya dalam L / Lpm. Lihat penyimpangan no. 8.
        $uKonversi = $agregat['ketidakpastian_diperluas'] * $rho;
        // Lantai CMC dalam PERSEN, karena lampiran akreditasi menulisnya
        // `% of reading`. Membandingkan angka absolut akan salah di kedua arah.
        $lantai = $pita['cmc_persen_of_reading'] / self::PERSEN * $uutRata;
        $u95 = max($uKonversi, $lantai);

        return [
            'uut' => $ulanganKonv,
            'uut_rata' => $uutRata,
            'berat_isi' => $beratIsi,
            'berat_kosong' => $beratKosong,
            'berat_isi_rata' => $isiRata,
            'berat_kosong_rata' => $kosongRata,
            'massa_bersih' => $massaBersih,
            'titik_tabel' => $barisTabel['titik_kg'],
            'jarak_tabel' => $barisTabel['jarak_relatif'],
            'koreksi_standar' => $barisTabel['koreksi_kg'],
            'std_terkoreksi' => $massa,
            'koreksi_apung' => $e,
            'massa_terapung' => $mt,
            'densitas_standar' => $rho,
            'waktu' => $waktu,
            'waktu_rata' => $waktuRata,
            'koreksi_timer' => $koreksiTimer,
            'waktu_terkoreksi' => $waktuTerkoreksi,
            'simpangan_baku_waktu' => $stdevWaktu,
            'laju_massa' => $lajuMassa,
            'hasil' => $hasil,
            'deviasi' => $deviasi,
            'simpangan_baku_standar' => $stdevStd,
            'simpangan_baku_uut' => null,
            'jumlah_pengulangan' => count($ulanganKonv),
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agregat['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $agregat['ketidakpastian_diperluas'],
            'ketidakpastian_diperluas_terkonversi' => $uKonversi,
            'pita_cmc' => $pita,
            'lantai_cmc' => $lantai,
            'lantai_cmc_dipakai' => $u95 > $uKonversi,
            'u95_sertifikat' => $u95,
            'u95_persen_of_reading' => $uutRata == 0.0 ? null : $u95 / $uutRata * self::PERSEN,
            'type_a' => $this->rss($budget, 't-student', true),
            'type_b' => $this->rss($budget, 't-student', false),
        ];
    }

    /**
     * Sembilan komponen (Totalizer, satuan kg) atau sebelas (Flowrate, kg/menit),
     * urutannya sama dengan sheet `PERHITUNGAN U95%` master blok Titik 1.
     *
     * `u_mentah` disimpan berdampingan dengan `pembagi`, sama seperti kolom (4)
     * dan (5) master — supaya jejak auditnya bisa diadu kolom demi kolom, bukan
     * cuma hasil akhirnya.
     *
     * @param  array<string, mixed>  $timbangan
     * @return list<array{sumber: string, keterangan: string, satuan: string, distribusi: string, u_mentah: float, pembagi: float, u: float, ci: float, vi: float}>
     */
    private function budget(
        bool $flowrate,
        array $timbangan,
        float $resolusi,
        float $uTemperature,
        float $massa,
        float $mt,
        float $rho,
        float $hasil,
        float $uutRata,
        ?float $waktu,
        float $stdevStd,
        int $nPasang,
    ): array {
        $k = $this->tabel()->konstanta();
        $rect = (float) $k['pembagi_rect'];        // 1,73 — ditiru, bukan √3. §9
        $normal = (float) $k['pembagi_normal'];
        $akar3 = sqrt(3.0);                        // komponen pengulangan memang √3 di master
        $viB = (float) $k['vi_type_b'];            // 50
        $viN = (float) $k['vi_normal'];            // 60
        $viUlang = (float) max(1, $nPasang - 1);
        $muai = (float) $k['koefisien_muai_air_per_c'];
        $rhoUdara = (float) $k['densitas_udara_kg_per_l'];
        $pembagiDrift = (float) $k['pembagi_drift'];
        $persenApung = (float) $k['persen_bouyancy'] / self::PERSEN;

        $u95Timbangan = (float) $timbangan['u95_kg'];
        $kestabilan = (float) $timbangan['kestabilan_kg'];
        $driftTimbangan = (float) $timbangan['drift_kg'];
        $driftSuhu = (float) ($this->tabel()->kalibratorSuhu()['drift_c'] ?? 0.0);
        $uDensitas = (float) $k['u95_densitas_air_kg_per_l'];

        if (! $flowrate) {
            $ciDensitas = -$massa * $rhoUdara / $rho ** 2;
            $ciSuhu = $mt * $muai / $rho ** 2;

            return $this->lengkapi([
                [
                    'sumber' => 'standar_timbangan',
                    'keterangan' => 'Ketidakpastian standar timbangan',
                    'satuan' => 'kg',
                    'distribusi' => 'normal',
                    'u_mentah' => $u95Timbangan,
                    'pembagi' => $normal,
                    'ci' => 1.0,
                    'vi' => $viN,
                ],
                [
                    'sumber' => 'resolusi_uut',
                    'keterangan' => 'Resolusi alat yang dikalibrasi',
                    'satuan' => 'kg',
                    'distribusi' => 'rectangular',
                    'u_mentah' => $resolusi / 2,
                    'pembagi' => $rect,
                    'ci' => 1.0,
                    'vi' => $viB,
                ],
                [
                    'sumber' => 'kestabilan_aliran',
                    'keterangan' => 'Kestabilan aliran',
                    'satuan' => 'kg',
                    'distribusi' => 'rectangular',
                    'u_mentah' => $kestabilan,
                    'pembagi' => $rect,
                    'ci' => 1.0,
                    'vi' => $viB,
                ],
                [
                    'sumber' => 'pengulangan_standar',
                    'keterangan' => 'Pengulangan pembacaan',
                    'satuan' => 'kg',
                    'distribusi' => 't-student',
                    'u_mentah' => $stdevStd,
                    'pembagi' => $akar3,
                    'ci' => 1.0,
                    'vi' => $viUlang,
                ],
                [
                    'sumber' => 'koreksi_bouyancy',
                    'keterangan' => 'Koreksi bouyancy',
                    'satuan' => 'kg',
                    'distribusi' => 'rectangular',
                    // Master menghitungnya di titik 1 saja; di titik 2..4 selnya
                    // nol. Dihitung di keempatnya — penyimpangan no. 5.
                    'u_mentah' => $persenApung * $hasil,
                    'pembagi' => $rect,
                    'ci' => 1.0,
                    'vi' => $viB,
                ],
                [
                    'sumber' => 'densitas_air',
                    'keterangan' => 'Ketidakpastian densitas air',
                    'satuan' => 'kg/L',
                    'distribusi' => 'normal',
                    'u_mentah' => $uDensitas,
                    'pembagi' => $normal,
                    'ci' => $ciDensitas,
                    'vi' => $viN,
                ],
                [
                    'sumber' => 'densitas_beda_suhu',
                    'keterangan' => 'Ketidakpastian densitas karena pengaruh perbedaan suhu',
                    'satuan' => '°C',
                    'distribusi' => 'rectangular',
                    // `U_temperature/2` LALU dibagi 1,73 — dua pembagi berturut
                    // untuk satu besaran. Ditiru; §9.
                    'u_mentah' => $uTemperature / 2,
                    'pembagi' => $rect,
                    'ci' => $ciSuhu,
                    'vi' => $viB,
                ],
                [
                    'sumber' => 'drift_timbangan',
                    'keterangan' => 'Ketidakpastian drift timbangan standar',
                    'satuan' => 'kg',
                    'distribusi' => 'rectangular',
                    'u_mentah' => $driftTimbangan / $pembagiDrift,
                    'pembagi' => $rect,
                    'ci' => 1.0,
                    'vi' => $viB,
                ],
                [
                    'sumber' => 'drift_suhu',
                    'keterangan' => 'Ketidakpastian drift suhu & sensor standar',
                    'satuan' => '°C',
                    'distribusi' => 'rectangular',
                    'u_mentah' => $driftSuhu / $pembagiDrift,
                    'pembagi' => $rect,
                    'ci' => $ciSuhu,
                    'vi' => $viB,
                ],
            ]);
        }

        $ciTimbangan = 1 / $waktu;
        $ciStopwatch = $mt / $waktu ** 2;
        $ciDensitas = -$hasil;
        $ciSuhu = $ciDensitas * $muai;
        $timer = $this->tabel()->timer();

        return $this->lengkapi([
            [
                'sumber' => 'standar_timbangan',
                'keterangan' => 'Ketidakpastian standar timbangan',
                'satuan' => 'kg',
                'distribusi' => 'normal',
                'u_mentah' => $u95Timbangan,
                'pembagi' => $normal,
                'ci' => $ciTimbangan,
                'vi' => $viN,
            ],
            [
                'sumber' => 'resolusi_uut',
                'keterangan' => 'Resolusi alat yang dikalibrasi',
                'satuan' => 'kg/min',
                'distribusi' => 'rectangular',
                'u_mentah' => $resolusi / 2,
                'pembagi' => $rect,
                'ci' => 1.0,
                'vi' => $viB,
            ],
            [
                'sumber' => 'kestabilan_aliran',
                'keterangan' => 'Kestabilan aliran',
                'satuan' => 'kg/min',
                'distribusi' => 'rectangular',
                'u_mentah' => $kestabilan,
                'pembagi' => $rect,
                'ci' => 1.0,
                'vi' => $viB,
            ],
            [
                'sumber' => 'standar_stopwatch',
                'keterangan' => 'Ketidakpastian standar stopwatch',
                'satuan' => 'min',
                'distribusi' => 'normal',
                'u_mentah' => (float) ($timer['u95_min'] ?? 0.0),
                'pembagi' => $normal,
                'ci' => $ciStopwatch,
                'vi' => $viN,
            ],
            [
                'sumber' => 'pengulangan_standar',
                'keterangan' => 'Pengulangan pembacaan',
                'satuan' => 'kg/min',
                'distribusi' => 't-student',
                'u_mentah' => $stdevStd * $rho,
                'pembagi' => $akar3,
                'ci' => 1.0,
                'vi' => $viUlang,
            ],
            [
                'sumber' => 'koreksi_bouyancy',
                'keterangan' => 'Koreksi bouyancy',
                'satuan' => 'kg/min',
                'distribusi' => 'rectangular',
                // Master Flowrate memakai UUT_rata di sini, master Totalizer
                // memakai hasilnya. Masing-masing ditiru — menyeragamkannya
                // berarti mengubah dua workbook sekaligus tanpa perintah lab. §22.
                'u_mentah' => $persenApung * $uutRata * $rho,
                'pembagi' => $rect,
                'ci' => 1.0,
                'vi' => $viB,
            ],
            [
                'sumber' => 'densitas_air',
                'keterangan' => 'Ketidakpastian densitas air',
                'satuan' => 'kg/L',
                'distribusi' => 'normal',
                'u_mentah' => $uDensitas,
                'pembagi' => $normal,
                'ci' => $ciDensitas,
                'vi' => $viN,
            ],
            [
                'sumber' => 'densitas_beda_suhu',
                'keterangan' => 'Ketidakpastian densitas karena pengaruh perbedaan suhu',
                'satuan' => '°C',
                // `normal` dengan pembagi 2 dan vi 60 — sementara komponen
                // sebangun di Totalizer `rectangular` dengan 1,73, vi 50, dan
                // `U_temperature` yang sudah dibagi 2 lebih dulu. Komponen yang
                // sama, dua perlakuan. Masing-masing ditiru; §9.
                'distribusi' => 'normal',
                'u_mentah' => $uTemperature,
                'pembagi' => $normal,
                'ci' => $ciSuhu,
                'vi' => $viN,
            ],
            [
                'sumber' => 'drift_timbangan',
                'keterangan' => 'Ketidakpastian drift timbangan standar',
                'satuan' => 'kg',
                'distribusi' => 'rectangular',
                'u_mentah' => $driftTimbangan / $pembagiDrift,
                'pembagi' => $rect,
                'ci' => $ciTimbangan,
                'vi' => $viB,
            ],
            [
                'sumber' => 'drift_suhu',
                'keterangan' => 'Ketidakpastian drift suhu & sensor standar',
                'satuan' => '°C',
                'distribusi' => 'rectangular',
                'u_mentah' => $driftSuhu / $pembagiDrift,
                'pembagi' => $rect,
                'ci' => $ciSuhu,
                'vi' => $viB,
            ],
            [
                'sumber' => 'drift_stopwatch',
                'keterangan' => 'Ketidakpastian drift stopwatch standar',
                'satuan' => 'min',
                'distribusi' => 'rectangular',
                'u_mentah' => (float) ($timer['drift_min'] ?? 0.0) / $pembagiDrift,
                'pembagi' => $rect,
                'ci' => $ciStopwatch,
                'vi' => $viB,
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $budget
     * @return list<array<string, mixed>>
     */
    private function lengkapi(array $budget): array
    {
        return array_map(static function (array $b): array {
            $b['u'] = $b['u_mentah'] / $b['pembagi'];

            return $b;
        }, $budget);
    }

    /**
     * Densitas air pada pembacaan suhu `$suhu`, sesudah koreksi kalibrator.
     *
     * Rata-rata DULU baru dikoreksi — bukan titik tabel yang dikoreksi.
     * Penyimpangan no. 3.
     */
    private function densitasTerkoreksi(float $suhu): ?float
    {
        $baris = $this->tabel()->koreksiSuhu($suhu);

        return $this->tabel()->densitasAir($suhu + ($baris['koreksi_c'] ?? 0.0));
    }

    /**
     * Konversi pembacaan ke satuan hasil (L untuk Totalizer, Lpm untuk Flowrate).
     *
     * Satuan berbasis massa dibagi densitas air — di varian gravimetri fluidanya
     * memang air dan densitasnya sudah diukur piknometer, jadi tidak perlu
     * densitas UUT yang diketik teknisi seperti di varian UFM.
     */
    private function konversi(string $mode, float $nilai, string $satuan, float $rho): ?float
    {
        $faktor = $this->tabelUfm()->faktorSatuan($mode, $satuan);

        if ($faktor !== null) {
            return $nilai * $faktor;
        }

        if (! $this->tabelUfm()->satuanBerbasisMassa($mode, $satuan)) {
            return null;
        }

        // kg/h -> kg/min dulu, baru dibagi densitas.
        $perMenit = mb_strtolower(trim($satuan)) === 'kg/h' ? $nilai / 60 : $nilai;

        return $perMenit / $rho;
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

    /** @param  list<float>  $nilai */
    private function punyaSebaran(array $nilai): bool
    {
        return count($nilai) >= 2 && max($nilai) !== min($nilai);
    }

    /**
     * Deret angka yang bersih — yang bukan angka DILEWATI, bukan dibaca nol.
     *
     * Satu kotak kosong yang dipaksa jadi `0.0` di antara penimbangan 2.485,5
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

    private function tabel(): TabelStandarFlowmeterGravimetri
    {
        // Malas, bukan di parameter bawaan konstruktor: profil -> kalkulator ->
        // GumCalculator -> CalibrationProfileRegistry -> profil itu lagi membuat
        // lingkaran yang gejalanya (`Infinite recursion?`) muncul jauh dari
        // penyebabnya.
        return $this->tabel ??= new TabelStandarFlowmeterGravimetri;
    }

    private function tabelUfm(): TabelStandarFlowmeter
    {
        // Pita CMC dan faktor satuan hidup di sana — satu sumber untuk kedua
        // varian, karena lampiran akreditasinya memang satu.
        return $this->tabelUfm ??= new TabelStandarFlowmeter;
    }

    private function gum(): GumCalculator
    {
        return $this->gum ??= new GumCalculator;
    }
}
