<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\GayaCalculator;
use App\Services\Calibration\TabelStandarGaya;
use App\Services\GumCalculator;
use App\Support\GayaMentah as M;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Induk ketiga alat GAYA: Mesin UTM, Load Cell, dan Proving Ring.
 *
 * ## Yang dibagi bertiga, dan yang tidak
 *
 * Dibagi: rantai hitung per titik (`GayaCalculator`), delapan komponen budget,
 * agregasi lewat `GumCalculator`, dan lantai CMC dari lampiran akreditasi.
 *
 * Tidak dibagi: nama kemampuan, WORKBOOK mana yang jadi sumber angka drift, dan
 * bentuk lembar kerjanya. Proving Ring paling jauh bedanya — dia tidak diputar
 * empat posisi melainkan diuji naik-turun karena histeresis cincin baja, dan
 * keluarannya bukan "meleset berapa" melainkan faktor kalibrasi kN/Div.
 *
 * ## Tidak ada PASS/FAIL, dan itu keputusan metrologi
 *
 * Sertifikat gaya PT Sidik tidak memuat kesimpulan lulus/tidak. Lab melaporkan
 * seberapa meleset; pelanggan yang menilai apakah itu cukup baik — karena
 * "cukup baik" tergantung benda apa yang akan diuji, dan lab tidak tahu itu.
 * ISO/IEC 17025 klausul 7.8.6.1: pernyataan kesesuaian cuma diberikan kalau ada
 * aturan keputusan yang disepakati dengan pelanggan.
 *
 * Karena itu [punyaToleransi] `false`, dan `keputusan` di tiap baris hitungan
 * `null` — bukan karena belum sempat diisi.
 *
 * ## Budget lahir per SESI, bukan per titik
 *
 * `komponenBudget()` sengaja memulangkan `null`: RSD MAX diambil dari seluruh
 * titik, zero error dari preload, misalignment dari empat pengukuran tingkat
 * sesi. Tidak satu pun bisa dijawab dari satu titik saja, jadi yang dipakai
 * hook [hitungPerGrup].
 */
abstract class GayaProfile extends CalibrationProfile
{
    private ?GumCalculator $gum = null;

    /**
     * Nomor Instruksi Kerja, DARI kertas lembar kerjanya sendiri.
     *
     * Bukan slug karangan: nomor ini ikut tercetak di sertifikat sebagai metode
     * yang dipakai, dan asesor mencocokkannya ke dokumen IK yang sah. UTM
     * `SIDIK-IK-CAL-0513`, Load Cell `SIDIK-IK-CAL-0514` — dibaca dari baris
     * `Methode :` di PDF lembar kerjanya, bukan ditebak dari deret nomor.
     */
    abstract public function kodeMetodeIk(): string;

    /** Workbook mana yang jadi sumber angka drift standar: `utm`, `load_cell`, `proving_ring`. */
    abstract public function sumberDrift(): string;

    /**
     * Kolom `Standard Value` di sertifikat: pakai Z (sesudah koreksi termal)
     * atau Y (sebelum)?
     *
     * Ini ada karena **dua workbook master tidak sepakat**, dan keduanya
     * direplikasi apa adanya:
     *
     *   UTM       SERTIFIKAT!D26 = 200,7852728972789 kgf = Z
     *   Load Cell SERTIFIKAT!D26 = 2,1530386700000004 kN  = Y
     *
     * Standar yang sama, rumus yang sama, dua jawaban berbeda. Menyeragamkannya
     * sendiri berarti menggeser angka yang sudah tercetak di sertifikat
     * pelanggan salah satu dari keduanya. Diangkat sebagai pertanyaan lab G12.
     */
    /**
     * Berapa bacaan yang WAJIB ada di tiap titik.
     *
     * Dua belas untuk UTM & Load Cell (empat posisi x tiga replikat). Bukan
     * kelengkapan administratif: divisor pengulangan di budget mengasumsikan
     * n = 12, jadi titik yang cuma terisi sembilan menerbitkan ketidakpastian
     * yang lebih KECIL dari yang seharusnya — arah yang salah, dan tanpa satu
     * pun error.
     *
     * Proving Ring memulangkan 6 (UP 3x + DOWN 3x) waktu dia mendarat.
     */
    /** Empat deret posisi per titik — lihat [CalibrationProfile::butuhBlokGaya]. */
    public function butuhBlokGaya(): bool
    {
        return true;
    }

    public function jumlahBacaanWajib(): int
    {
        return count(M::PERAN_POSISI) * M::REPLIKAT;
    }

    /**
     * Rentang suhu ruangan yang diterima metodenya (°C), inklusif.
     *
     * Catatan eksplisit di workbook master; panduan §8.1 mengulangnya. Di luar
     * itu koefisien termal 0,00027/°C yang dipakai rantai hitung tidak lagi
     * bisa diklaim berlaku.
     */
    public const SUHU_RUANGAN_MIN = 10.0;

    public const SUHU_RUANGAN_MAKS = 35.0;

    /** Sebaran suhu awal-akhir di atas ini disorot (°C). Panduan §8.2 butir 8. */
    public const SEBARAN_SUHU_PANTAS_DILIHAT = 2.0;

    /** Berapa pengukuran misalignment yang wajib ada. STDEV butuh minimal itu. */
    public const MISALIGNMENT_WAJIB = 4;

    abstract public function pakaiKoreksiTermalDiSertifikat(): bool;

    /** Nomor formulir lembar kerjanya, dari kertas resmi — bukan dikarang. */
    abstract protected function kodeDokumen(): string;

    abstract protected function judulLembar(): string;

    /** Workbook master mana yang jadi sumber angkanya, buat jejak di lembar. */
    abstract protected function sumberMaster(): string;

    public function besaran(): string
    {
        return 'gaya';
    }

    public function kodeMetode(): ?string
    {
        return $this->kodeMetodeIk();
    }

    /** Sertifikat gaya tidak memvonis. Lihat docblock kelas. */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * Budget gaya lahir per sesi — lihat docblock kelas.
     *
     * @param  array<string, mixed>  $konteksTitik
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
     * Hitung seluruh titik satu sesi: rantai per titik, lalu SATU budget.
     *
     * @param  array<int, array<string, mixed>>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array<string, mixed>>}|null
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        // Disapu, bukan `$titik[0]`: jalur hitung ulang mengelompokkan lewat
        // `groupBy` dan urutannya tidak dijamin.
        $konteksSesi = [];
        foreach ($titik as $t) {
            if (isset($t['konteks']['spesifikasi_alat'])) {
                $konteksSesi = $t['konteks'];

                break;
            }
        }

        $blok = M::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null || $blok['satuan'] === null || $blok['standar'] === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi gaya belum punya blok `spesifikasi_alat.'.M::KUNCI_SESI.'` yang lengkap — '
                        .'satuan, standar, arah beban, dan suhu sertifikat standar lahir di situ, bukan per titik.',
                ], $titik),
            ];
        }

        $arah = $blok['tipe_beban'] ?? M::ARAH_PUSH;
        $standarGaya = TabelStandarGaya::standar((string) $blok['standar']);
        $suhuSertifikat = $blok['suhu_sertifikat_standar']
            ?? (float) ($standarGaya['suhu_sertifikat'] ?? 0.0);

        // Suhu load cell standar saat kalibrasi = rata-rata suhu ruangan.
        // Master mengasumsikan load cell sudah menyesuaikan dengan ruangan, dan
        // itu memang yang terjadi sesudah alat didiamkan sebelum diuji.
        $suhuAktual = self::rataDua(
            $konteksSesi['suhu_awal'] ?? null,
            $konteksSesi['suhu_akhir'] ?? null,
        ) ?? $suhuSertifikat;

        // ---- Pemblokir tingkat-SESI (panduan §8.1) ------------------------
        //
        // Ditaruh di sini, sebelum satu titik pun dihitung, karena ketiganya
        // merusak SELURUH sesi — bukan satu titik. Memblokir per titik akan
        // menerbitkan enam pesan yang sebenarnya satu sebab, dan orang yang
        // membacanya mengira ada enam masalah.
        $penghalangSesi = $this->penghalangSesi($blok, $konteksSesi);

        if ($penghalangSesi !== null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => $penghalangSesi,
                ], $titik),
            ];
        }

        $temuanSesi = $this->temuanSesi($blok, $konteksSesi, $titik);

        $hasilTitik = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            $k = $t['konteks'] ?? [];
            $bacaan = array_map('floatval', $k['bacaan'] ?? []);
            $nominal = (float) ($t['titik_ukur'] ?? 0);

            if ($bacaan === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Titik ini belum punya satu pun pembacaan.',
                ];

                continue;
            }

            // Panduan §8.1: TEPAT sekian bacaan, bukan "minimal".
            //
            // Lebih dari yang diminta sama bermasalahnya dengan kurang: divisor
            // pengulangan mengasumsikan satu angka tetap, dan lembar yang
            // terisi tiga belas kali berarti ada satu baris yang tidak tahu
            // dari posisi mana.
            if (count($bacaan) !== $this->jumlahBacaanWajib()) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %s baru terisi %d dari %d pembacaan. Budget ketidakpastiannya '
                        .'mengasumsikan %d bacaan, jadi menghitungnya sekarang menerbitkan U95 '
                        .'yang lebih kecil dari yang seharusnya.',
                        $nominal,
                        count($bacaan),
                        $this->jumlahBacaanWajib(),
                        $this->jumlahBacaanWajib(),
                    ),
                ];

                continue;
            }

            $h = GayaCalculator::hitungTitik(
                $nominal,
                $bacaan,
                (string) $blok['satuan'],
                (string) $blok['standar'],
                (string) $arah,
                (float) $suhuSertifikat,
                (float) $suhuAktual,
            );

            if ($h['W'] === null) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => implode(' ', $h['temuan']),
                ];

                continue;
            }

            $h['titik_ke'] = (int) $t['titik_ke'];
            $h['nominal'] = $nominal;
            $h['jumlah_bacaan'] = count($bacaan);
            $hasilTitik[] = $h;
        }

        if ($hasilTitik === []) {
            return ['hitungan' => [], 'belum_dihitung' => $belumDihitung];
        }

        // RSD terbesar antar titik — komponen pengulangan di budget. Titik yang
        // RSD-nya `null` (alat baca nol saat dibebani) TIDAK ikut: memasukkannya
        // sebagai nol menurunkan budget justru waktu datanya paling meragukan.
        $rsdMaks = 0.0;
        foreach ($hasilTitik as $h) {
            if ($h['T'] !== null) {
                $rsdMaks = max($rsdMaks, (float) $h['T']);
            }
        }

        $rentangKn = GayaCalculator::keKn(
            (float) ($blok['kapasitas'] ?? 0),
            (string) $blok['satuan'],
        );

        $komponen = GayaCalculator::komponenBudget(
            $blok,
            $rsdMaks,
            $rentangKn,
            (float) ($standarGaya['u95_persen'] ?? 0.0),
            TabelStandarGaya::drift((string) $blok['standar'], (string) $arah, $this->sumberDrift()),
        );

        $agregat = $this->gum()->agregasiBudget($komponen);

        $uPersen = (float) $agregat['ketidakpastian_diperluas'];
        $uKn = $rentangKn > 0.0 ? $uPersen / 100 * $rentangKn : 0.0;
        $cmcKn = $this->cmcKn($equipment, $blok, (string) $arah);
        $u95 = max($uKn, $cmcKn ?? 0.0);

        $sekarang = Carbon::now();
        $hitungan = [];
        $typeA = self::uiKomponen($komponen, 'pengulangan');
        $uc = (float) $agregat['ketidakpastian_gabungan'];

        foreach ($hasilTitik as $h) {
            // Kolom `Standard Value` yang TERCETAK, bukan rata-rata bacaan.
            //
            // Ini beda yang gampang terlewat: `R` (rata-rata 12 bacaan) memang
            // titik awal rantai, tapi yang dicetak sertifikat nilai standar
            // SESUDAH koreksi — dan di sesi contoh UTM bedanya 0,25 kgf, jauh
            // di atas satu digit terakhir yang tercetak.
            $nilaiStandar = $this->pakaiKoreksiTermalDiSertifikat() ? $h['Z'] : $h['Y'];

            // Dan `Correction` yang tercetak = Standard Value − UUT, bukan
            // kolom `AA` di lembar perhitungan (`AA` selalu memakai Y). Buat
            // workbook yang mencetak Z, kedua angka itu berbeda: 0,785 kgf
            // lawan 0,853 kgf — di satu desimal jadi 0,8 lawan 0,9.
            //
            // `AA` tetap disimpan utuh di jejak audit, jadi selisihnya bisa
            // ditelusuri tanpa membuka kode.
            $koreksi = $nilaiStandar - $h['B'];

            $hitungan[] = [
                'titik_ke' => $h['titik_ke'],
                // `titik_ukur` tetap dalam satuan ALAT — dia kunci pencocokan
                // ke `raw_measurements` dan yang dipakai `resolusiPada()`.
                // Satu-satunya kolom di baris ini yang BUKAN kN, dan sengaja:
                // dia tidak pernah dicetak, lihat [nilaiStandarDariKoreksi].
                'titik_ukur' => $h['nominal'],
                // `rata_rata` = kolom `Unit Under Test` sertifikat = nominal
                // yang ditunjukkan mesin, dalam kN.
                //
                // Kelihatan terbalik dari namanya, dan memang begitu: di alat
                // gaya yang dibaca berulang justru STANDARNYA (load cell), dan
                // penunjukan alat pelanggan cuma satu angka per titik. Preseden
                // persisnya kelompok Waktu dan Frekuensi — lihat
                // [CalibrationProfile::nilaiStandarDariKoreksi].
                'rata_rata' => $h['B'],
                // `error` = seberapa meleset UUT dari standar; `koreksi` lawan
                // tandanya, mengikuti kesepakatan repo (validator menegakkan
                // `koreksi = -error`).
                'error' => -$koreksi,
                'koreksi' => $koreksi,
                'standar_deviasi' => $h['S'],
                'jumlah_pengulangan' => $h['jumlah_bacaan'],
                'type_a' => $typeA,
                'type_b_components' => $this->jejakAudit($h, $komponen, $agregat, $uKn, $cmcKn, $u95, (string) $arah, $blok, $temuanSesi),
                'type_b' => sqrt(max(0.0, $uc ** 2 - $typeA ** 2)),
                'ketidakpastian_gabungan' => $uc,
                'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $u95,
                'toleransi' => null,
                'keputusan' => null,
                'metode' => $this->kodeMetodeIk(),
                'calculated_at' => $sekarang,
            ];
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Alasan sesi ini TIDAK boleh dihitung sama sekali, atau `null` kalau lolos.
     *
     * Panduan §8.1. Yang tidak ada di sini dan memang sengaja:
     *
     * - **Satuan wajib dipilih** dan **kombinasi standar x arah harus punya
     *   tabel** sudah ditegakkan lebih dalam, di `GayaCalculator` — yang
     *   pertama melempar, yang kedua memulangkan `W` null. Mengulangnya di
     *   sini bikin dua tempat yang harus ikut berubah bersamaan.
     * - **Standar tidak kedaluwarsa** ditegakkan `CalibrationValidator` untuk
     *   SEMUA alat. Menyalinnya ke sini menghasilkan dua pesan berbeda untuk
     *   satu keadaan.
     * - **Nominal naik monoton** SENGAJA bukan pemblokir — lihat
     *   [temuanSesi].
     *
     * @param  array<string, mixed>  $blok
     * @param  array<string, mixed>  $konteksSesi
     */
    protected function penghalangSesi(array $blok, array $konteksSesi): ?string
    {
        $misalignment = $blok['misalignment'] ?? [];

        if (count($misalignment) !== self::MISALIGNMENT_WAJIB) {
            return sprintf(
                'Misalignment perlu %d pengukuran, yang terisi %d. Simpangan bakunya masuk budget '
                .'ketidakpastian, jadi jumlah yang kurang menerbitkan U95 dari sebaran yang tidak '
                .'pernah diukur penuh.',
                self::MISALIGNMENT_WAJIB,
                count($misalignment),
            );
        }

        $suhu = array_values(array_filter(
            [$konteksSesi['suhu_awal'] ?? null, $konteksSesi['suhu_akhir'] ?? null],
            static fn (mixed $x): bool => $x !== null && $x !== '',
        ));

        foreach ($suhu as $nilai) {
            $t = (float) $nilai;

            if ($t < self::SUHU_RUANGAN_MIN || $t > self::SUHU_RUANGAN_MAKS) {
                return sprintf(
                    'Suhu %s °C di luar rentang metode (%s–%s °C). Koreksi termal 0,00027/°C yang '
                    .'dipakai rantai hitung tidak bisa diklaim berlaku di luar rentang itu.',
                    $t,
                    self::SUHU_RUANGAN_MIN,
                    self::SUHU_RUANGAN_MAKS,
                );
            }
        }

        return null;
    }

    /**
     * Peringatan tingkat-SESI: terlihat, tapi tidak memblokir. Panduan §8.2.
     *
     * ## Kenapa urutan titik cuma PERINGATAN, padahal panduan menaruhnya di §8.1
     *
     * Panduan meminta nominal naik monoton dan menyebutnya pemblokir. Sesi
     * master Load Cell sendiri melanggarnya: urutannya `0, 100, 2, 3, … 9 kN`
     * — titik kedua langsung kapasitas penuh, baru turun ke rentang bawah. Dan
     * sertifikatnya mencetak dalam urutan itu juga.
     *
     * Jadi menjadikannya pemblokir berarti lembar yang benar-benar dipakai lab
     * tidak bisa dikirim. Antara panduan dan master, yang menang master —
     * AGENTS.md §Aturan yang Lahir dari Kesalahan Nyata. Yang benar dilakukan:
     * tiru masternya, sorot urutannya, dan angkat pertentangannya sebagai
     * pertanyaan lab bernomor (G13).
     *
     * @param  array<string, mixed>  $blok
     * @param  array<string, mixed>  $konteksSesi
     * @param  list<array<string, mixed>>  $titik
     * @return list<string>
     */
    protected function temuanSesi(array $blok, array $konteksSesi, array $titik): array
    {
        $temuan = [];

        $nominal = array_map(static fn (array $t): float => (float) ($t['titik_ukur'] ?? 0), $titik);

        for ($i = 1, $n = count($nominal); $i < $n; $i++) {
            if ($nominal[$i] < $nominal[$i - 1]) {
                $temuan[] = sprintf(
                    'Titik ke-%d (%s) lebih kecil dari titik ke-%d (%s) — urutan bebannya tidak naik. '
                    .'Tidak memblokir: sesi master Load Cell memang begitu (G13).',
                    $i + 1,
                    $nominal[$i],
                    $i,
                    $nominal[$i - 1],
                );

                break;
            }
        }

        $awal = $konteksSesi['suhu_awal'] ?? null;
        $akhir = $konteksSesi['suhu_akhir'] ?? null;

        if ($awal !== null && $akhir !== null && $awal !== '' && $akhir !== '') {
            $sebaran = abs((float) $awal - (float) $akhir);

            if ($sebaran > self::SEBARAN_SUHU_PANTAS_DILIHAT) {
                $temuan[] = sprintf(
                    'Suhu bergeser %s °C selama pengukuran (%s → %s °C) — kondisi tidak stabil.',
                    round($sebaran, 2),
                    $awal,
                    $akhir,
                );
            }
        }

        // Zero error masuk budget lewat komponennya sendiri, jadi ini bukan
        // "angkanya belum terhitung" — ini "angkanya terhitung, dan besarnya
        // pantas dilihat sebelum sertifikatnya terbit".
        $zeroError = M::zeroErrorMaks($blok['preload_zero'] ?? []);

        if ($zeroError > 0.0) {
            $temuan[] = sprintf(
                'Zero error %s %s sesudah preload — sudah masuk budget, tapi patut dilihat.',
                $zeroError,
                $blok['satuan'] ?? '',
            );
        }

        return $temuan;
    }

    /**
     * Lantai CMC dari lampiran akreditasi — sadar ARAH dan sadar SATUAN.
     *
     * Dua hal yang membedakannya dari alat lain di repo ini:
     *
     * 1. Baris kemampuan gaya dipisah per arah (`Tekan`/`Tarik`), dan nilainya
     *    memang beda: UTM Tarik 10-88 kN = 0,22 kN sementara Tekan = 0,21 kN.
     *    Proving Ring tidak dipisah arah (`parameter` null) dan itu bukan data
     *    yang kurang — memang begitu akreditasinya.
     * 2. Satuannya campur: rentang 0-500 dalam kgf, rentang 10-88 dalam kN.
     *    Membandingkan kapasitas tanpa menyamakan satuan memilih pita yang
     *    salah, dan pita yang salah menggeser lantai U95% yang terbit.
     *
     * Kalau tidak ada pita yang cocok, memulangkan `null` — dan itu keadaan
     * NYATA: Load Cell memang tidak terakreditasi arah tarik di 10-88 kN.
     * Diam-diam memakai lantai nol berarti menerbitkan angka yang lebih kecil
     * dari kemampuan yang diakui.
     *
     * @param  array<string, mixed>  $blok
     */
    protected function cmcKn(Equipment $equipment, array $blok, string $arah): ?float
    {
        $kapasitas = (float) ($blok['kapasitas'] ?? 0);
        $satuan = (string) $blok['satuan'];

        if ($kapasitas <= 0.0) {
            return null;
        }

        $arahLampiran = $arah === M::ARAH_PULL ? 'Tarik' : 'Tekan';
        $kapasitasKn = GayaCalculator::keKn($kapasitas, $satuan);
        $terpilih = null;
        $jarakTerkecil = INF;

        foreach ($this->pitaKemampuan($equipment) as $p) {
            if ($p->ketidakpastian_terbaik === null || $p->range_max === null) {
                continue;
            }

            // `parameter` null = berlaku dua arah (Proving Ring).
            if ($p->parameter !== null && $p->parameter !== $arahLampiran) {
                continue;
            }

            $faktorPita = TabelStandarGaya::faktorSatuan((string) $p->satuan);

            if ($faktorPita === null) {
                continue;
            }

            $jarak = abs((float) $p->range_max * $faktorPita - $kapasitasKn);

            if ($jarak < $jarakTerkecil) {
                $faktorU = TabelStandarGaya::faktorSatuan((string) $p->satuan_ketidakpastian) ?? 1.0;
                $jarakTerkecil = $jarak;
                $terpilih = (float) $p->ketidakpastian_terbaik * $faktorU;
            }
        }

        return $terpilih;
    }

    /** @return Collection<int, CalibrationCapability> */
    protected function pitaKemampuan(Equipment $equipment): Collection
    {
        return CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->when(
                $equipment->organization_id !== null,
                fn ($q) => $q->milikOrganisasi($equipment->organization_id),
            )
            ->orderBy('range_max')
            ->get();
    }

    /**
     * Sertifikat dicetak dalam satuan ALAT; hitungannya hidup dalam kN.
     *
     * Tanpa hook ini titik 200 kgf tercetak `2,0` — angka kN berlabel kgf,
     * meleset 100 kali lipat, dan tidak ada satu pun error yang muncul. Master
     * melakukan hal yang sama: sel sertifikatnya membagi balik dengan faktor
     * satuan (`SERTIFIKAT!D26 = 'PERHITUNGAN FC'!…/DATABASE!$S$22`).
     *
     * Untuk alat yang satuannya memang kN faktornya 1 dan angkanya tidak
     * bergeser. Hook-nya tetap jalan: satuan dibaca dari blok SESI, bukan dari
     * jenis alatnya, dan alat yang sama bisa dikalibrasi dalam satuan lain di
     * sesi berikutnya.
     *
     * Memulangkan `null` kalau satuannya belum ditentukan — pemanggil
     * memperlakukan itu sebagai "jangan cetak angka", bukan jatuh diam-diam ke
     * nilai kN.
     *
     * @return array{satuan: string, ubah: \Closure(float, int): ?float}|null
     */
    public function cetakDalamSatuanAlat(CalibrationSession $sesi): ?array
    {
        $blok = M::blokSesi($sesi->spesifikasi_alat);
        $satuan = $blok['satuan'] ?? null;

        if ($satuan === null) {
            return null;
        }

        $faktor = TabelStandarGaya::faktorSatuan($satuan);

        if ($faktor === null || $faktor <= 0.0) {
            return null;
        }

        return [
            'satuan' => $satuan,
            'ubah' => static fn (float $kn, int $titikKe): ?float => $kn / $faktor,
        ];
    }

    /**
     * Kolom `Standard Value` disusun dari `rata_rata + koreksi`.
     *
     * WAJIB `true` di sini, dan bukan kerapian. `CertificateSnapshotBuilder`
     * mengambil `standard_value` dari `titik_ukur` kalau hook ini `false` —
     * padahal di alat gaya `titik_ukur` menyimpan SET POINT dalam satuan alat
     * (kgf), sementara seluruh kolom lain di baris yang sama bersatuan kN dan
     * ikut dibagi faktor satuan waktu dicetak.
     *
     * Akibatnya kalau dibiarkan `false` ada DUA kekeliruan sekaligus, dan
     * keduanya tanpa satu pun error: kolom `Standard Value` dan `Unit Under
     * Test` bertukar tempat, dan yang mendarat di `Standard Value` dibagi
     * 0,00981 sekali lagi — titik 100 kgf tercetak `10193,7`. Ditemukan
     * 24 Sep 2026 oleh `GayaSesiContohCocokMasterTest`, sebelum satu pun
     * sertifikat gaya terbit.
     */
    public function nilaiStandarDariKoreksi(): bool
    {
        return true;
    }

    /** Huruf kolom lembar perhitungan yang dicetak di kolom `Standard Value` sertifikat. */
    protected function kolomStandardValue(): string
    {
        return $this->pakaiKoreksiTermalDiSertifikat() ? 'Z' : 'Y';
    }

    /**
     * Jejak audit yang terbaca TANPA membuka kode.
     *
     * Penyimpangan master wajib kelihatan di jejak sesi, bukan cuma di
     * komentar, karena komentar tidak sampai ke orang yang menyetujui sesi
     * (AGENTS.md §Olah data butir 4). Yang dibawa ke sini: baris drift yang
     * tidak dibagi divisornya, kolom mana yang dicetak di `Standard Value`,
     * dan — untuk Load Cell — keputusan memakai Y di semua satuan sementara
     * master cuma memakainya di cabang kN.
     *
     * Ikut juga temuan per titik DAN temuan tingkat-sesi. Yang kedua diulang
     * di tiap baris dengan sengaja; alasannya di bawah, dekat kuncinya.
     *
     * @param  array<string, mixed>  $h
     * @param  list<array<string, mixed>>  $komponen
     * @param  array<string, mixed>  $agregat
     * @param  array<string, mixed>  $blok
     * @param  list<string>  $temuanSesi
     * @return array<string, mixed>
     */
    protected function jejakAudit(
        array $h,
        array $komponen,
        array $agregat,
        float $uKn,
        ?float $cmcKn,
        float $u95,
        string $arah,
        array $blok,
        array $temuanSesi = [],
    ): array {
        return [
            'komponen' => $komponen,
            'gaya_rantai' => [
                'B_nominal_kn' => $h['B'],
                'R_rata_kn' => $h['R'],
                'S_stdev_kn' => $h['S'],
                'T_rsd_persen' => $h['T'],
                'W_koreksi_standar_kn' => $h['W'],
                'Y_terkoreksi_kn' => $h['Y'],
                'Z_terkoreksi_termal_kn' => $h['Z'],
                'AA_correction_kn' => $h['AA'],
                'AB_rrpe_persen' => $h['AB'],
                'set_point_standar_kn' => $h['set_point_standar_kn'],
            ],
            'gaya_budget' => [
                'u_persen' => $agregat['ketidakpastian_diperluas'],
                'u_kn' => $uKn,
                'cmc_kn' => $cmcKn,
                'u95_kn' => $u95,
                'lantai_cmc_menang' => $cmcKn !== null && $cmcKn > $uKn,
            ],
            'gaya_standar' => [
                'kunci' => $blok['standar'],
                'arah' => $arah,
                'sumber_drift' => $this->sumberDrift(),
                'drift_semua_workbook' => TabelStandarGaya::driftSemuaSumber(
                    (string) $blok['standar'],
                    $arah,
                ),
            ],
            'penyimpangan_master' => array_filter([
                'drift_tidak_dibagi_divisor' => 'Master mengambil U drift langsung jadi ui tanpa dibagi akar 3, '
                    .'padahal kolom divisornya terisi — di KETIGA workbook. Direplikasi apa adanya; '
                    .'membaginya menggeser U95% yang sudah tercetak. Pertanyaan lab bernomor G3.',
                'kolom_standard_value' => 'Kolom Standard Value sertifikat ini mencetak `'
                    .$this->kolomStandardValue().'`, mengikuti workbook `'.$this->sumberDrift()
                    .'`. Ketiga workbook gaya tidak sepakat soal ini — pertanyaan lab bernomor G12.',
                'correction_memakai_y' => $this->pakaiKoreksiTermalDiSertifikat()
                    ? 'Kolom AA lembar perhitungan memakai Y (sebelum koreksi termal) sementara sertifikat '
                        .'mencetak Z. Yang dicetak di kolom Correction adalah Standard Value dikurangi UUT '
                        .'supaya kedua kolom sertifikat konsisten; selisihnya terhadap AA terbaca di '
                        .'`gaya_rantai.AA_correction_kn` di atas. Pertanyaan lab bernomor G5.'
                    : null,
                'standard_value_hanya_cabang_kn' => $this->pakaiKoreksiTermalDiSertifikat()
                    ? null
                    : 'PENYIMPANGAN DISENGAJA. Di workbook `'.$this->sumberDrift().'`, Y cuma dipakai pada '
                        .'cabang satuan kN; cabang N/lbf/kgf/tnf — dan penjaga IF(...="") di depannya — masih '
                        .'menunjuk Z. Itu bentuk suntingan yang berhenti di satu cabang, bukan keputusan '
                        .'metode: nilai tercetak jadi berubah arti tergantung satuan tampilan yang dipilih. '
                        .'Sistem memakai Y untuk SEMUA satuan. Sesi bersatuan kN identik dengan master; '
                        .'satuan lain berbeda sebesar koreksi termal. Pertanyaan lab bernomor G12.',
                'drift_beda_antar_workbook' => 'Tiga workbook tidak sepakat soal drift standar yang sama; '
                    .'yang dipakai sesi ini dari workbook `'.$this->sumberDrift().'`. Pertanyaan lab bernomor G2.',
            ], static fn (?string $x): bool => $x !== null),
            'temuan' => $h['temuan'],
            // Peringatan tingkat-SESI diulang di tiap baris, dan itu disengaja.
            // Jejak audit dibaca PER TITIK di layar persetujuan; ditaruh sekali
            // di satu baris saja, sembilan titik lain tidak memperlihatkannya
            // dan orang yang membuka titik ke-3 mengira sesinya bersih.
            'temuan_sesi' => $temuanSesi,
        ];
    }

    /** @param  list<array<string, mixed>>  $komponen */
    private static function uiKomponen(array $komponen, string $sumber): float
    {
        foreach ($komponen as $k) {
            if (($k['sumber'] ?? null) === $sumber) {
                return (float) $k['u'];
            }
        }

        return 0.0;
    }

    private static function rataDua(mixed $awal, mixed $akhir): ?float
    {
        $nilai = array_values(array_filter(
            [$awal, $akhir],
            static fn (mixed $x): bool => $x !== null && $x !== '',
        ));

        if ($nilai === []) {
            return null;
        }

        return array_sum(array_map('floatval', $nilai)) / count($nilai);
    }

    private function gum(): GumCalculator
    {
        // Malas, bukan di konstruktor: `GumCalculator` menyentuh
        // `CalibrationProfileRegistry`, dan registry memuat profil ini lagi —
        // lingkaran yang gejalanya "Maximum call stack size" jauh dari sebabnya.
        return $this->gum ??= new GumCalculator;
    }

    /** @return array<string, mixed> */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($this->rangkaLembar(), $equipment),
            $equipment,
        );
    }

    /** @return array<string, mixed> */
    protected function rangkaLembar(): array
    {
        return [
            // Nomor formulir dari kertas resminya, bukan karangan:
            // `worksheet_alat_calibration/SIDIK-FM-CAL-0519_Rev.3 - LEMBAR KERJA
            // COMPRESSION (UTM).pdf`.
            //
            // Workbook masternya sendiri DIAM soal ini — sapuan `SIDIK-FM-` di
            // seluruh folder `Alat_Gaya/` cuma menemukan `SIDIK-FM-CAL-2403`,
            // dan itu formulir SERTIFIKAT bersama (lihat preseden Height Gauge
            // di `SemuaProfilLembarKerjaTest`). Yang menjawab sumber kedua.
            'kode_dokumen' => $this->kodeDokumen(),
            'kode_metode' => $this->kodeMetodeIk(),
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => $this->judulLembar(),
            'jumlah_pengulangan' => M::REPLIKAT,
            'semua_kolom_opsional' => false,
            'catatan_pengisian' => 'Tiap titik beban diisi DUA BELAS kali: empat posisi (0°, 90°, 180°, 270°) '
                .'x tiga replikat. Empat posisi bukan pengulangan biasa — kalau load cell standar tidak tepat '
                .'di sumbu piringan, kesalahannya cuma kelihatan waktu alat dihadapkan ke arah berbeda. '
                .'Preload (zero & kapasitas maks) dan empat pengukuran misalignment diisi sekali per sesi, '
                .'bukan per titik.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => $this->sumberMaster(),
                'catatan' => 'Delapan komponen dalam PERSEN (kesalahan load cell proporsional, bukan absolut), '
                    .'k dari t-Student dengan v_eff dipotong ke bawah, lalu dikonversi ke kN dan diadu ke '
                    .'lantai CMC dari lampiran akreditasi. Dua penyimpangan master direplikasi apa adanya dan '
                    .'tercatat di jejak sesi: baris drift tidak dibagi divisornya, dan Correction memakai nilai '
                    .'sebelum koreksi termal sementara sertifikat mencetak yang sesudahnya.',
            ],
            'tanpa_keputusan' => [
                'alasan' => 'Sertifikat gaya tidak menyatakan lulus/tidak lulus. Lab melaporkan seberapa '
                    .'meleset; pelanggan yang menilai apakah itu cukup baik untuk pemakaiannya. ISO/IEC 17025 '
                    .'klausul 7.8.6.1 — pernyataan kesesuaian butuh aturan keputusan yang disepakati.',
            ],
            'bagian' => [
                $this->bagianIdentitasAlat(),
                $this->bagianPemilik(),
                $this->bagianStandarDanPreload(),
                $this->bagianMisalignment(),
                $this->bagianMeasurement(),
                $this->bagianPenutup(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianIdentitasAlat(): array
    {
        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Identitas Alat',
            'field' => [
                // Tanpa kotak ini sesinya tidak bisa dikirim sama sekali:
                // `equipment_id` yang menyambungkan lembar ke alat pelanggan.
                $this->field('equipment_id', 'Pilih Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('alat_model', 'Nama Alat', 'teks'),
                $this->field('alat_merk', 'Merk', 'teks'),
                $this->field('alat_serial_number', 'No. Seri', 'teks'),
                $this->field('spesifikasi_alat.gaya.kapasitas', 'Kapasitas Maks', 'angka'),
                // Tanpa satuan, master menjawab string "PILIH SATUAN" yang bisa
                // ikut mengalir ke hasil — angka lahir dari satuan yang tidak
                // pernah ditentukan. Karena itu disodorkan sebagai pilihan, bukan
                // kotak teks bebas.
                $this->field('spesifikasi_alat.gaya.satuan', 'Satuan Gaya', 'pilihan', pilihan: [
                    'kN', 'N', 'lbf', 'kgf', 'tnf',
                ]),
                $this->field('spesifikasi_alat.gaya.resolusi_uut', 'Resolusi Alat', 'angka'),
                $this->field('spesifikasi_alat.gaya.tipe_beban', 'Tipe Beban', 'pilihan', pilihan: [
                    M::ARAH_PUSH, M::ARAH_PULL,
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianPemilik(): array
    {
        return [
            'kode' => 'pemilik',
            'halaman' => 1,
            'judul' => 'Pemilik Alat',
            'field' => [
                $this->field('pemilik_nama', 'Nama Customer', 'teks'),
                $this->field('pemilik_alamat', 'Alamat Customer', 'teks_panjang'),
                // Kondisi lingkungan: rata-rata awal & akhir jadi suhu load cell
                // standar saat kalibrasi, dan itu yang dipakai koreksi termal.
                $this->field('suhu_awal', 'Env. Condition — First (°C)', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Env. Condition — End (°C)', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Env. Condition — First (%RH)', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Env. Condition — End (%RH)', 'angka', satuan: '%RH'),
                // Dua kotak lokasi yang saling meniadakan: yang Inlab memilih
                // ruangan terdaftar, yang Insitu mengetik nama tempat. Muncul
                // berbarengan, teknisi mengisi dua-duanya dan yang tercetak di
                // sertifikat jadi bergantung urutan baca.
                $this->field('lokasi', 'Location', 'pilihan', pilihan: [
                    ['nilai' => 'lab', 'label' => 'Inlab'],
                    ['nilai' => 'onsite', 'label' => 'Insitu'],
                ]),
                $this->field(
                    'room_id', 'Ruangan (Inlab)', 'pilihan',
                    sumber: 'master_ruangan', tampilKalau: self::TAMPIL_KALAU_INLAB,
                ),
                $this->field(
                    'lokasi_nama', 'Nama Tempat (Insitu)', 'teks',
                    tampilKalau: self::TAMPIL_KALAU_INSITU,
                ),
                // Thermohygro juga alat ukur: bacaannya dikoreksi pakai
                // sertifikatnya sendiri sebelum dipakai. Tanpa kotak ini teknisi
                // tidak bisa menyebut unit mana yang dipakai, dan koreksinya
                // tidak punya sumber.
                $this->field('thermohygro_standard_id', 'Thermohygro Used', 'pilihan', sumber: 'master_thermohygro'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianStandarDanPreload(): array
    {
        return [
            'kode' => 'usage_check',
            'halaman' => 1,
            'judul' => 'Standar & Preload Test',
            // Ditaut ke master `standards` lab pemilik alat oleh
            // `tautkanStandarTercetak()`; baris yang tidak ketemu tetap dikirim
            // dengan `terdaftar => false` supaya teknisi tidak mengira kertasnya
            // berubah.
            // `static::`, bukan `self::` — daftar standar tercetaknya milik
            // profil KONKRET. Ditulis `self::` di kelas induk ini, keduanya
            // membaca daftar milik `GayaProfile` yang tidak punya satu pun,
            // dan lembar kerjanya lahir tanpa baris Standard Used.
            'baris' => static::STANDARD_TERCETAK,
            'field' => [
                $this->field('spesifikasi_alat.gaya.standar', 'Load Cell Standar', 'pilihan', pilihan: [
                    '5kN', '100kN', '3000kN',
                ]),
                $this->field('spesifikasi_alat.gaya.kapasitas_standar', 'Kapasitas Standar (kN)', 'angka'),
                $this->field('spesifikasi_alat.gaya.resolusi_standar', 'Resolusi Standar (kN)', 'angka'),
                $this->field(
                    'spesifikasi_alat.gaya.suhu_sertifikat_standar',
                    'Suhu Sertifikat Standar (°C)',
                    'angka',
                ),
            ],
            'tabel' => [
                [
                    'tahap' => 'sebelum_adjustment',
                    'grup' => 'preload',
                    'offset_kunci' => 9000,
                    'judul' => 'Preload Test (3 replikat)',
                    'judul_nilai' => 'Pemeriksaan',
                    'judul_pengulangan' => 'Ulangan ke',
                    'titik_bisa_diubah' => false,
                    'simpan_ke' => 'spesifikasi_alat.gaya',
                    'baris' => [
                        [
                            'nomor' => 1,
                            'titik_ukur' => null,
                            'label' => 'Zero',
                            'kunci' => 'preload_zero',
                        ],
                        [
                            'nomor' => 2,
                            'titik_ukur' => null,
                            'label' => 'Max Capacity',
                            'kunci' => 'preload_max',
                        ],
                    ],
                    'kolom' => [
                        ['kode' => 'pembacaan', 'label' => 'Pembacaan', 'tipe' => 'angka'],
                    ],
                    'pengulangan' => range(1, M::REPLIKAT),
                ],
            ],
        ];
    }

    /**
     * Empat pengukuran misalignment — properti PEMASANGAN, bukan properti titik.
     *
     * Masuk `spesifikasi_alat`, bukan dipaksa jadi titik ukur ber-`titik_ke = 0`.
     * Blok tanpa titik yang diberi titik hantu selalu gagal di jalur hitung
     * ulang, dan gagalnya jauh dari sebabnya.
     *
     * Apakah nilainya milik alat atau milik mesin uji lab masih pertanyaan
     * terbuka ke lab (temuan §5.3 butir 6) — kalau ternyata milik mesin,
     * tempatnya pindah ke tabel referensi, bukan per sesi.
     *
     * @return array<string, mixed>
     */
    protected function bagianMisalignment(): array
    {
        return [
            'kode' => 'misalignment',
            'halaman' => 1,
            'judul' => 'Misalignment Axial',
            'field' => array_map(
                fn (int $n): array => $this->field(
                    "spesifikasi_alat.gaya.misalignment.{$n}",
                    "Sisi X{$n} (mm)",
                    'angka',
                ),
                range(1, 4),
            ),
        ];
    }

    /**
     * Empat tabel yang barisnya SINKRON: baris ke-n keempatnya titik beban yang
     * sama, dibaca dari empat posisi berbeda.
     *
     * `offset_kunci` beda per tabel supaya HP tidak berbagi kotak isian, dan
     * `simpan_ke` bernama supaya keempat deret sampai server terpisah — pola
     * yang sama dengan Hydrometer dan Volumetric Glassware.
     *
     * @return array<string, mixed>
     */
    protected function bagianMeasurement(): array
    {
        $baris = static fn (): array => array_map(
            static fn (int $n): array => [
                'nomor' => $n,
                // `null` membuka kotak Nominal untuk diketik: titik bebannya
                // ditentukan teknisi sesuai kapasitas mesin, bukan dipatok.
                'titik_ukur' => null,
                'label' => 'Titik '.$n,
            ],
            range(1, 14),
        );

        $tabel = static fn (string $peran, int $offset, string $judul): array => [
            'tahap' => 'sesudah_adjustment',
            'grup' => $peran,
            'offset_kunci' => $offset,
            'judul' => $judul,
            'judul_nilai' => 'Nominal',
            'judul_pengulangan' => 'Replikat ke',
            // `false` karena semua barisnya lahir ber-`titik_ukur: null` —
            // nominalnya diketik teknisi di tiap baris. Menyalakannya bikin
            // panel PengaturTitik di HP tidak pernah muncul, dan jumlah titik
            // mentok di baris bawaan tanpa satu pun error.
            'titik_bisa_diubah' => false,
            'simpan_ke' => 'measurements[].'.$peran,
            'baris' => $baris(),
            'kolom' => [
                ['kode' => 'pembacaan', 'label' => 'Pembacaan UUT', 'tipe' => 'angka'],
            ],
            'pengulangan' => range(1, M::REPLIKAT),
        ];

        return [
            'kode' => 'hasil',
            'halaman' => 2,
            'judul' => 'Accuracy Test',
            'field' => [],
            'tabel' => [
                $tabel(M::PERAN_POSISI[0], 1000, 'a. Posisi 0°'),
                $tabel(M::PERAN_POSISI[1], 2000, 'b. Posisi 90°'),
                $tabel(M::PERAN_POSISI[2], 3000, 'c. Posisi 180°'),
                $tabel(M::PERAN_POSISI[3], 4000, 'd. Posisi 270°'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianPenutup(): array
    {
        return [
            'kode' => 'penutup',
            'halaman' => 2,
            'judul' => 'Catatan & Tanda Tangan',
            'field' => [
                $this->field('catatan_teknisi', 'Note', 'teks_panjang'),
                $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Checked by', 'teks', sumber: 'otomatis'),
            ],
        ];
    }
}
