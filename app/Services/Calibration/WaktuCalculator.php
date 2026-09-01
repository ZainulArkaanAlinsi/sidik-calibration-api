<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Mesin hitung **Timer/Stopwatch**.
 *
 * ## Bentuk satu titik
 *
 * Berbeda dari kelompok Putaran, di sini KEDUA sisi dibaca: teknisi menekan
 * stopwatch standar dan alat pelanggan berbarengan, tiga kali, di satu set
 * point. Master menyimpan tiap pembacaan sebagai empat kolom (jam, menit,
 * detik, milidetik) untuk masing-masing sisi — delapan angka per ulangan.
 *
 * Di sini tiap pembacaan disimpan sebagai SATU angka: total milidetik. Itu
 * bukan penyederhanaan yang menghilangkan sesuatu — pemisahan jam/menit/detik
 * cuma cara master menampung penunjukan stopwatch, dan
 * [WaktuMentah::keMilidetik] menyusunnya balik kapan pun perlu dicetak.
 *
 *     nilai benar = rata-rata standar + koreksi sertifikat standar
 *     koreksi     = nilai benar − rata-rata UUT       (yang dicetak sertifikat)
 *
 * ### Simpangan baku: satu deret, bukan delapan kolom
 *
 * Master menghitung simpangan baku per KOLOM lalu mengambil yang terbesar
 * (`L31 = MAX(C31:K31)`). Di sini dihitung dari total milidetik. Untuk seluruh
 * data master hasilnya sama persis — jam/menit/detik tidak pernah berubah
 * antar-ulangan sehingga simpangan bakunya nol dan yang tersisa cuma kolom
 * milidetik. Bedanya baru muncul kalau satu ulangan melewati batas detik
 * (mis. 59,9 s lawan 60,1 s): master akan memecah ragamnya ke dua kolom dan
 * MENGECILKAN simpangan bakunya, sedangkan total milidetik menangkapnya utuh.
 * Arah yang benar, dan arah yang aman.
 *
 * ## Penyimpangan master yang TIDAK ditiru
 *
 *  1. **`uHRTB` cuma dua operator.** `N23 = MAX(N21:N22)` hanya mencakup DT dan
 *     AW, sementara sel sebelahnya `P23 = MAX(P19:P22)` mencakup keempatnya.
 *     Ketimpangan dua sel bersebelahan itu yang menjadikannya kerusakan
 *     salin-tempel, bukan pilihan metode: 0,0191 → 0,0351 detik.
 *  2. **Drift cuma 4 dari 13 titik.** Kolom `K` di sheet `Drift Stopwatch`
 *     berrumus di empat baris saja: 298 → 322 ms.
 *  3. **Titik hantu.** Set point 6–10 di master kosong seluruhnya, dan sel
 *     kosong yang dibaca nol tetap melahirkan `CORRECTION = 30 ms` — angka yang
 *     tercetak persis seperti titik sungguhan. Diblokir di sini.
 *
 * Kedua yang pertama menaikkan U95; keduanya ditegakkan arahnya oleh test.
 *
 * ## Yang DITIRU meski janggal
 *
 * Hanya Set Point 1 di master yang benar-benar menghitung — Set Point 2–5
 * semuanya `#REF!`, memakai penjumlahan yang memotong dua komponen human
 * reaction (`SUM(AC28:AC31)` alih-alih enam komponen), dan tiga di antaranya
 * memakai `k = 2` yang diketik tangan alih-alih `TINV`. Yang ditiru adalah Set
 * Point 1 — satu-satunya yang utuh — dan enam komponennya diberlakukan untuk
 * SEMUA titik. Lihat `docs/pertanyaan-lab-waktu-frekuensi.md` §5.
 */
class WaktuCalculator
{
    public function __construct(
        private readonly TabelStandarWaktu $tabel = new TabelStandarWaktu,
        private ?GumCalculator $gum = null,
    ) {}

    /**
     * Hitung satu titik Timer/Stopwatch berikut budget enam komponennya.
     *
     * Ketidakpastian lahir per TITIK di sini (tidak seperti Putaran yang per
     * blok tiga) karena master pun membuat satu blok `Set Point n` per titik.
     *
     * @param  array{titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>}  $titik  waktu dalam MILIDETIK
     * @param  array{resolusi_uut_detik: float, cmc: float|null}  $konteks
     * @return array{hasil: array<string, mixed>|null, budget: list<array<string, mixed>>, alasan: string|null}
     */
    public function hitungTitik(array $titik, array $konteks): array
    {
        $setPointDetik = (float) $titik['titik_ukur'];
        $standar = $this->bersihkan($titik['standar']);
        $uut = $this->bersihkan($titik['uut']);

        // Titik hantu — lihat docblock kelas. Set point nol, deret kosong, atau
        // sisi yang tidak seimbang semuanya diblokir dengan alasan yang kebaca.
        if ($setPointDetik <= 0.0 || count($standar) < 2 || count($uut) < 2) {
            return ['hasil' => null, 'budget' => [], 'alasan' => sprintf(
                'Titik %d belum bisa dihitung: set point %s detik, %d pembacaan standar, '
                .'%d pembacaan alat. Butuh set point > 0 dan minimal 2 pembacaan di kedua sisi.',
                $titik['titik_ke'], $setPointDetik, count($standar), count($uut),
            )];
        }

        if (count($standar) !== count($uut)) {
            return ['hasil' => null, 'budget' => [], 'alasan' => sprintf(
                'Titik %d punya %d pembacaan standar tapi %d pembacaan alat — tiap ulangan '
                .'harus punya pasangan, karena koreksinya selisih rata-rata keduanya.',
                $titik['titik_ke'], count($standar), count($uut),
            )];
        }

        $nominal = $this->tabel->nominalTerdekat($setPointDetik);
        $koreksiStdMs = $nominal === null ? null : $this->tabel->koreksiMs($nominal);

        if ($koreksiStdMs === null) {
            return ['hasil' => null, 'budget' => [], 'alasan' => sprintf(
                'Set point %s detik nggak punya nominal padanan di sertifikat kalibrator '
                .'stopwatch — koreksi standarnya nggak ada, jadi titik ini nggak dihitung.',
                $setPointDetik,
            )];
        }

        $uSertifikat = $this->tabel->u95Sertifikat($nominal);

        if ($uSertifikat === null) {
            return ['hasil' => null, 'budget' => [], 'alasan' => sprintf(
                'Nominal %s detik ada DI BAWAH pita ketidakpastian pertama sertifikat '
                .'kalibrator stopwatch (5 detik) — U95-nya nggak punya sumber.',
                $nominal,
            )];
        }

        $n = count($standar);
        $rataStandar = array_sum($standar) / $n;
        $rataUut = array_sum($uut) / $n;
        $terkoreksiMs = $rataStandar + $koreksiStdMs;
        $koreksiMs = $terkoreksiMs - $rataUut;

        // Simpangan baku terbesar di antara kedua sisi — master mengambil MAX
        // lintas delapan kolomnya, dan kedua deret ini padanannya.
        $sd = max(
            $this->standarDeviasi($standar, $rataStandar),
            $this->standarDeviasi($uut, $rataUut),
        );

        $budget = $this->budget($uSertifikat, $sd / 1000.0, $n, (float) $konteks['resolusi_uut_detik']);

        $agregat = ($this->gum ??= new GumCalculator)->agregasiBudget(array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $budget,
        ));

        $u95 = max($agregat['ketidakpastian_diperluas'], (float) ($konteks['cmc'] ?? 0.0));

        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter($budget, static fn (array $k): bool => $k['distribusi'] !== 't-student'),
        )));

        return [
            'hasil' => [
                'titik_ke' => (int) $titik['titik_ke'],
                'titik_ukur' => $setPointDetik,
                'nominal_standar' => $nominal,
                'koreksi_standar_ms' => $koreksiStdMs,
                // Kolom sertifikat bersatuan DETIK — master menyimpan mentahnya
                // dalam milidetik lalu membaginya 1000 waktu masuk budget.
                'rata_rata' => $rataUut / 1000.0,
                'rata_rata_standar' => $rataStandar / 1000.0,
                'nilai_terkoreksi' => $terkoreksiMs / 1000.0,
                'koreksi' => $koreksiMs / 1000.0,
                'error' => -$koreksiMs / 1000.0,
                'standar_deviasi' => $sd / 1000.0,
                'jumlah_pengulangan' => $n,
                'type_a' => ($sd / 1000.0) / sqrt($n),
                'ketidakpastian_gabungan' => $agregat['ketidakpastian_gabungan'],
                'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
                'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
                'ketidakpastian_diperluas' => $agregat['ketidakpastian_diperluas'],
                'u95_sertifikat' => $u95,
                'type_b' => $typeB,
            ],
            'budget' => $budget,
            'alasan' => null,
        ];
    }

    /**
     * Enam komponen budget, urutannya sama dengan blok `Set Point 1` master.
     *
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>
     */
    private function budget(float $uSertifikat, float $sdDetik, int $n, float $resolusiUut): array
    {
        $sqrt3 = sqrt(3);
        $driftDetik = $this->tabel->driftMs() / 1000.0;

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat kalibrator stopwatch %s (U95=%s s, k=2)',
                    $this->tabel->standar()['seri'], $uSertifikat,
                ),
                'distribusi' => 'normal',
                'u' => $uSertifikat / 2.0,
                'ci' => 1.0,
                'vi' => 60.0,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat yang dikalibrasi %s s', $resolusiUut),
                'distribusi' => 'persegi',
                'u' => ($resolusiUut / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000.0,
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => sprintf(
                    'Drift stopwatch standar (%s s dari %d sertifikat)',
                    $driftDetik, $this->tabel->jumlahSnapshotDrift(),
                ),
                'distribusi' => 'persegi',
                'u' => $driftDetik / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000.0,
            ],
            [
                'sumber' => 'pengulangan_pembacaan',
                'keterangan' => sprintf('Pengulangan %d pembacaan (Type A)', $n),
                'distribusi' => 't-student',
                'u' => $sdDetik / sqrt($n),
                'ci' => 1.0,
                'vi' => (float) max($n - 1, 1),
            ],
            [
                'sumber' => 'human_reaction_rata',
                'keterangan' => 'Waktu reaksi operator, rata-rata terbesar dari 4 operator (uHRTB)',
                'distribusi' => 'normal',
                'u' => $this->tabel->uHumanReactionRata() / 2.0,
                'ci' => 1.0,
                'vi' => 60.0,
            ],
            [
                'sumber' => 'human_reaction_sebaran',
                'keterangan' => 'Waktu reaksi operator, simpangan baku terbesar dari 4 operator (uHRTSD)',
                'distribusi' => 'persegi',
                'u' => $this->tabel->uHumanReactionStdev() / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000.0,
            ],
        ];
    }

    /**
     * @param  list<mixed>  $nilai
     * @return list<float>
     */
    private function bersihkan(array $nilai): array
    {
        return array_map(
            static fn ($v): float => (float) $v,
            array_values(array_filter($nilai, static fn ($v): bool => is_numeric($v))),
        );
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
