<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Mesin hitung kelompok **Putaran** — dipakai bersama oleh Infrared Tachometer
 * dan Centrifuge.
 *
 * Satu mesin untuk dua alat karena kedua workbook master (`Master Olda
 * Tachometer.xlsm`, `Master Olda Centrifuge.xlsm`) memuat sheet `PERHITUNGAN`
 * yang identik baris demi baris — sampai ke data contohnya. Yang membedakan
 * kedua alat cuma tabel CMC-nya, dan itu datang dari lampiran akreditasi lewat
 * `CalibrationCapability`, bukan dari sini.
 *
 * ## Bentuk satu titik
 *
 * Set point = penunjukan ALAT PELANGGAN (putaran yang disetel di centrifuge,
 * atau nominal yang dibaca tachometer-nya). Pembacaan = lima kali baca
 * TACHOMETER STANDAR pada putaran yang sama. Jadi:
 *
 *     nilai benar = rata-rata pembacaan standar + koreksi sertifikat standar
 *     koreksi     = nilai benar − set point          (yang dicetak sertifikat)
 *     error       = −koreksi
 *
 * ## Kenapa budget lahir per BLOK TIGA TITIK
 *
 * Sheet `PERHITUNGAN` menaruh tiga titik per baris (kolom G, I, K), dan sheet
 * `PERHITUNGAN U95%` membuat SATU budget per baris itu — memakai simpangan baku
 * TERBESAR di antara ketiganya, dan pita ketidakpastian sertifikat milik
 * `Indexed Value` TERTINGGI di antara ketiganya. Satu U95 lalu tercetak sama
 * untuk ketiga titik.
 *
 * Itu tidak bisa dinyatakan lewat `komponenBudget()` yang per titik, makanya
 * profilnya lewat `hitungPerGrup()`. Blok disusun dari tiga `titik_ke` berurutan
 * — geometri lembarnya, bukan selera.
 *
 * ## Penyimpangan master yang TIDAK ditiru
 *
 * Tiga hal dihitung benar di sini dan berbeda dari master. Ketiganya membuat
 * ketidakpastian kita LEBIH BESAR, tidak pernah lebih kecil, dan ketiganya
 * ditegakkan arahnya oleh test:
 *
 *  1. **Drift standar.** `K35` master = `MAX(K13:K34)/2`, tapi kolom `K` cuma
 *     berrumus di 5 dari 15 baris berdata. Melengkapinya menaikkan
 *     setengah-lebar drift 2,25 → 2,75 rpm. Lihat
 *     [TabelStandarPutaran::driftSetengahLebar].
 *  2. **Pengulangan blok 1 Centrifuge.** Master memakai `PERHITUNGAN!G34`
 *     (satu sel) sementara sepuluh blok lain memakai `MAX(...)` sebaris penuh.
 *     Workbook Tachometer memakai `MAX(G34:L34)` di blok yang sama persis —
 *     jadi bentuk yang benar terbukti dari master itu sendiri, bukan dari
 *     penalaran kita.
 *  3. **Blok 5 Tachometer.** Rusak di tiga tempat sekaligus: pita sertifikat
 *     menunjuk `F15` (1,6) padahal titik tertingginya 15000 rpm yang bernaung
 *     di pita `F18` (3,1); `u_drift` menunjuk `'[1]Drift Std Kalibrator'!K54`
 *     — sel KOSONG di workbook LAIN, ter-cache sebagai 0; dan pengulangannya
 *     menunjuk baris 113 yang kosong, juga 0. Ketiganya dihitung benar di sini.
 *
 * Selebihnya ditiru persis: sembilan blok sisanya cocok sampai 5·10⁻⁶, dan
 * seluruh 33 nilai turunan per titik cocok tanpa kecuali.
 */
class PutaranCalculator
{
    /** Jumlah titik per blok budget — tiga kolom ukur per baris lembar master. */
    public const TITIK_PER_BLOK = 3;

    /**
     * Ambang resolusi tachometer standar. Sampai 10000 rpm sertifikatnya
     * mencatat satu desimal (0,1 rpm); di atasnya bilangan bulat (1 rpm).
     * Dibaca balik dari blok budget master: blok ber-nominal tertinggi 10000
     * memakai 0,1 dan yang 15000 memakai 1.
     */
    private const AMBANG_RESOLUSI_STANDAR = 10_000.0;

    public function __construct(
        private readonly TabelStandarPutaran $tabel = new TabelStandarPutaran,
        private ?GumCalculator $gum = null,
    ) {}

    /**
     * Daya baca TACHOMETER STANDAR di sekitar `$setPoint` — 0,1 rpm sampai
     * 10000 rpm, 1 rpm di atasnya.
     *
     * Publik karena bukan cuma budget yang butuh: yang tercatat di
     * `raw_measurements` kelompok ini adalah pembacaan STANDARNYA, jadi
     * pemeriksa "bukan kelipatan resolusi" dan lembar perhitungan harus memakai
     * penggaris yang sama. Satu tempat yang memutuskan, supaya ambangnya tidak
     * bisa menyimpang antara budget dan pemeriksa.
     */
    public function resolusiStandar(float $setPoint): float
    {
        $nominal = $this->tabel->nominalTerdekat($setPoint) ?? $setPoint;

        return $nominal > self::AMBANG_RESOLUSI_STANDAR ? 1.0 : 0.1;
    }

    /**
     * Hitung satu blok (sampai tiga titik) sekaligus.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>}>  $titik
     * @param  array{resolusi_uut: float, cmc: float|null, satuan: string}  $konteks
     * @return array{titik: list<array<string, mixed>>, budget: list<array<string, mixed>>, ketidakpastian_gabungan: float, derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float, ketidakpastian_diperluas: float, u95_sertifikat: float, type_b: float, ditolak: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungBlok(array $titik, array $konteks): array
    {
        $dihitung = [];
        $ditolak = [];

        foreach ($titik as $t) {
            $setPoint = (float) $t['titik_ukur'];
            $pembacaan = array_values(array_filter(
                $t['pembacaan'],
                static fn ($v): bool => is_numeric($v),
            ));
            $pembacaan = array_map(static fn ($v): float => (float) $v, $pembacaan);

            // Titik hantu: master melahirkan `CORRECTION` bukan-nol dari baris
            // yang seluruh pembacaannya kosong (dibaca nol), dan angka itu
            // tercetak seperti titik sungguhan. Diblokir dengan alasan yang
            // kebaca, bukan ditiru.
            if ($setPoint <= 0.0 || count($pembacaan) < 2) {
                $ditolak[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d belum bisa dihitung: set point %s dengan %d pembacaan. '
                        .'Butuh set point > 0 dan minimal 2 pembacaan.',
                        $t['titik_ke'], $setPoint, count($pembacaan),
                    ),
                ];

                continue;
            }

            $nominal = $this->tabel->nominalTerdekat($setPoint);
            $koreksiStd = $nominal === null ? null : $this->tabel->koreksi($nominal);

            if ($koreksiStd === null) {
                // Ditampung dulu: `end()` menerima REFERENSI, jadi
                // `end($this->tabel->sertifikat())` melempar "Only variables
                // should be passed by reference". Cabang ini dulu nggak pernah
                // punya jalan masuk (lihat `TabelStandarPutaran::nominalTerdekat`),
                // jadi kesalahannya ikut nggak pernah kelihatan.
                $pita = $this->tabel->sertifikat();

                $ditolak[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Set point %s rpm ada di luar jangkauan sertifikat kalibrator '
                        .'(%s–%s rpm) — koreksi standarnya nggak ada, jadi titik ini nggak '
                        .'dihitung. Kalibrasi di luar jangkauan itu keputusan manajer teknis, '
                        .'bukan angka yang boleh ditebak dari baris terdekat.',
                        $setPoint,
                        $pita[0]['nominal'] ?? '-',
                        end($pita)['nominal'] ?? '-',
                    ),
                ];

                continue;
            }

            $n = count($pembacaan);
            $rata = array_sum($pembacaan) / $n;
            $sd = $this->standarDeviasi($pembacaan, $rata);
            $terkoreksi = $rata + $koreksiStd;
            $koreksi = $terkoreksi - $setPoint;

            $dihitung[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'titik_ukur' => $setPoint,
                'nominal_standar' => $nominal,
                'koreksi_standar' => $koreksiStd,
                'rata_rata' => $rata,
                'nilai_terkoreksi' => $terkoreksi,
                'koreksi' => $koreksi,
                'error' => -$koreksi,
                'standar_deviasi' => $sd,
                'jumlah_pengulangan' => $n,
                'type_a' => $sd / sqrt($n),
            ];
        }

        if ($dihitung === []) {
            return [
                'titik' => [], 'budget' => [], 'ketidakpastian_gabungan' => 0.0,
                'derajat_kebebasan_efektif' => null, 'faktor_cakupan_k' => 2.0,
                'ketidakpastian_diperluas' => 0.0, 'u95_sertifikat' => 0.0,
                'type_b' => 0.0, 'ditolak' => $ditolak,
            ];
        }

        $budget = $this->budget($dihitung, $konteks, $ditolak);

        if ($budget === []) {
            return [
                'titik' => [], 'budget' => [], 'ketidakpastian_gabungan' => 0.0,
                'derajat_kebebasan_efektif' => null, 'faktor_cakupan_k' => 2.0,
                'ketidakpastian_diperluas' => 0.0, 'u95_sertifikat' => 0.0,
                'type_b' => 0.0, 'ditolak' => $ditolak,
            ];
        }

        $agregat = ($this->gum ??= new GumCalculator)->agregasiBudget(array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $budget,
        ));

        // Lantai CMC: master menutup tiap blok dengan `MAX(U; CMC)`. U95 yang
        // terbit tidak boleh lebih kecil dari kemampuan terbaik yang diakui
        // lampiran akreditasi.
        $u95 = max($agregat['ketidakpastian_diperluas'], (float) ($konteks['cmc'] ?? 0.0));

        // RSS komponen Type B saja — aturannya disamakan dengan
        // `GumCalculator::hitungDariBudget()` biar kolom `type_b` nggak beda
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
            'ditolak' => $ditolak,
        ];
    }

    /**
     * Lima komponen budget satu blok, urutannya sama dengan sheet `PERHITUNGAN
     * U95%` master.
     *
     * @param  list<array<string, mixed>>  $dihitung
     * @param  array{resolusi_uut: float, cmc: float|null, satuan: string}  $konteks
     * @param  list<array{titik_ke: int, alasan: string}>  $ditolak
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>
     */
    private function budget(array $dihitung, array $konteks, array &$ditolak): array
    {
        $nominalTertinggi = max(array_map(
            static fn (array $t): float => (float) $t['nominal_standar'],
            $dihitung,
        ));

        $uSertifikat = $this->tabel->u95Sertifikat($nominalTertinggi);

        if ($uSertifikat === null) {
            foreach ($dihitung as $t) {
                $ditolak[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Nominal %s rpm ada DI BAWAH pita ketidakpastian pertama sertifikat '
                        .'kalibrator (60 rpm) — U95 blok ini nggak punya sumber, jadi nggak dihitung.',
                        $nominalTertinggi,
                    ),
                ];
            }

            return [];
        }

        $sqrt3 = sqrt(3);
        // Lewat [resolusiStandar] supaya ambangnya satu tempat — `nominal_standar`
        // memang sudah nominal sertifikat, jadi pemetaan ulangnya no-op di sini.
        $resolusiStandar = $this->resolusiStandar($nominalTertinggi);
        $resolusiUut = (float) $konteks['resolusi_uut'];
        $satuan = $konteks['satuan'];

        // Pengulangan: simpangan baku TERBESAR di blok, dibagi √n titik itu.
        // Dipilih lewat `type_a` (= s/√n), bukan lewat `s` mentah, supaya blok
        // yang titiknya beda jumlah pengulangan tetap punya arti. `n` yang
        // dipakai buat `vi` ikut titik yang menang — dipungut dalam satu
        // lintasan, bukan dicari ulang lewat pembandingan float.
        $typeA = 0.0;
        $nPengulangan = 2;

        foreach ($dihitung as $t) {
            if ((float) $t['type_a'] >= $typeA) {
                $typeA = (float) $t['type_a'];
                $nPengulangan = (int) $t['jumlah_pengulangan'];
            }
        }

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat kalibrator %s (U95=%s %s pada pita %s %s, k=2)',
                    $this->tabel->standar()['nama'], $uSertifikat, $satuan, $nominalTertinggi, $satuan,
                ),
                'distribusi' => 'normal',
                'u' => $uSertifikat / 2.0,
                'ci' => 1.0,
                'vi' => 60.0,
            ],
            [
                'sumber' => 'resolusi_standar',
                'keterangan' => sprintf('Daya baca tachometer standar %s %s', $resolusiStandar, $satuan),
                'distribusi' => 'persegi',
                'u' => ($resolusiStandar / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000.0,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat yang dikalibrasi %s %s', $resolusiUut, $satuan),
                'distribusi' => 'persegi',
                'u' => ($resolusiUut / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000.0,
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => sprintf(
                    'Drift tachometer standar (setengah-lebar %s %s dari %d sertifikat)',
                    $this->tabel->driftSetengahLebar(), $satuan, $this->tabel->jumlahSnapshotDrift(),
                ),
                'distribusi' => 'persegi',
                'u' => $this->tabel->driftSetengahLebar() / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000.0,
            ],
            [
                'sumber' => 'pengulangan_pembacaan',
                'keterangan' => sprintf('Pengulangan %d pembacaan, simpangan baku terbesar di blok', $nPengulangan),
                'distribusi' => 't-student',
                'u' => $typeA,
                'ci' => 1.0,
                'vi' => (float) max($nPengulangan - 1, 1),
            ],
        ];
    }

    /** @param  list<float>  $nilai */
    private function standarDeviasi(array $nilai, float $rata): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        return sqrt(array_sum(array_map(
            static fn (float $x): float => ($x - $rata) ** 2,
            $nilai,
        )) / ($n - 1));
    }
}
