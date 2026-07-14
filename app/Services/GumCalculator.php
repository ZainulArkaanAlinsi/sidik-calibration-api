<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Standard;
use Illuminate\Support\Carbon;

/**
 * Perhitungan ketidakpastian ngikutin JCGM 100:2008 (GUM):
 * Type A + Type B → gabungan (u_c) → diperluas (U = k · u_c).
 *
 * Keputusan PASS/FAIL pakai *guarded acceptance* (ILAC-G8): alat baru lulus
 * kalau |error| DITAMBAH U masih masuk toleransi — bukan cuma |error| <=
 * toleransi. Bedanya kelihatan pas error mepet batas: alat yang errornya 0.047
 * dari toleransi 0.05 itu "lulus" versi simple acceptance, padahal ketidakpastian
 * pengukurannya sendiri udah nyebrang batas. Keputusan lab, 14 Jul.
 *
 * Semua angka di sini DISIMPAN ke `uncertainty_calculations`, nggak dihitung
 * ulang tiap dibaca — sertifikat 5 tahun lalu harus tetap nunjukin angka yang
 * sama walaupun rumusnya udah berubah.
 */
class GumCalculator
{
    /**
     * Faktor cakupan dikunci 2 (tingkat kepercayaan ~95%), ngikutin lampiran
     * akreditasi LK-285-IDN — bukan diambil dari tabel-t per sesi. Derajat
     * kebebasan efektif tetap dihitung & disimpen buat jejak audit.
     */
    public const FAKTOR_CAKUPAN = 2.0;

    /** Type A butuh sebaran; satu pembacaan doang nggak punya standar deviasi. */
    public const MIN_PENGULANGAN = 2;

    /**
     * Hitung satu titik ukur. Balikannya siap di-insert ke `uncertainty_calculations`.
     *
     * @param  list<float>  $pembacaan  hasil pengulangan di titik ini
     * @return array<string, mixed>
     */
    public function hitungTitik(
        int $titikKe,
        float $titikUkur,
        array $pembacaan,
        Equipment $equipment,
        Standard $standard,
    ): array {
        $n = count($pembacaan);
        $rataRata = array_sum($pembacaan) / $n;
        $standarDeviasi = $this->standarDeviasiSampel($pembacaan, $rataRata);

        // Type A — sebaran hasil pengulangan itu sendiri.
        $typeA = $standarDeviasi / sqrt($n);

        $komponenTypeB = $this->komponenTypeB($equipment, $standard);
        $typeB = $this->akarJumlahKuadrat(array_column($komponenTypeB, 'nilai'));

        $gabungan = $this->akarJumlahKuadrat([$typeA, $typeB]);
        $diperluas = self::FAKTOR_CAKUPAN * $gabungan;

        $error = $rataRata - $titikUkur;
        $toleransi = (float) $equipment->toleransi;

        return [
            'titik_ke' => $titikKe,
            'titik_ukur' => $titikUkur,
            'rata_rata' => $rataRata,
            'error' => $error,
            // Koreksi = lawan dari error: angka yang harus DITAMBAHKAN ke pembacaan
            // alat biar ketemu nilai benar. Ini yang dicetak di sertifikat, bukan error.
            'koreksi' => -$error,
            'standar_deviasi' => $standarDeviasi,
            'jumlah_pengulangan' => $n,
            'type_a' => $typeA,
            'type_b_components' => $komponenTypeB,
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $gabungan,
            'faktor_cakupan_k' => self::FAKTOR_CAKUPAN,
            'derajat_kebebasan_efektif' => $this->derajatKebebasanEfektif($gabungan, $typeA, $n),
            'ketidakpastian_diperluas' => $diperluas,
            'toleransi' => $toleransi,
            'keputusan' => $this->keputusan($error, $diperluas, $toleransi),
            'calculated_at' => Carbon::now(),
        ];
    }

    /**
     * Guarded acceptance: pita jaga selebar U dipotong dari batas toleransi.
     */
    private function keputusan(float $error, float $diperluas, float $toleransi): string
    {
        return abs($error) + $diperluas <= $toleransi ? 'PASS' : 'FAIL';
    }

    /**
     * Rincian komponen Type B, masing-masing udah dikonversi ke ketidakpastian
     * BAKU (u), bukan diperluas (U).
     *
     * Ini jebakan paling gampang kelewat: angka ketidakpastian di sertifikat
     * standar itu nilai DIPERLUAS (udah dikali k-nya sendiri, biasanya 2), jadi
     * harus dibagi balik sama k itu sebelum digabung. Kalau langsung dipakai
     * mentah, Type B jadi 2x lipat kegedean dan alat yang harusnya PASS ikut FAIL.
     *
     * @return list<array<string, mixed>>
     */
    private function komponenTypeB(Equipment $equipment, Standard $standard): array
    {
        $komponen = [];

        if ($standard->ketidakpastian !== null) {
            $k = $standard->faktor_cakupan ?: self::FAKTOR_CAKUPAN;

            $komponen[] = [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat standar %s (U=%s %s, k=%s)',
                    $standard->nama,
                    $standard->ketidakpastian,
                    $standard->satuan_ketidakpastian ?? '',
                    $k,
                ),
                'distribusi' => 'normal',
                'nilai' => $standard->ketidakpastian / $k,
            ];
        }

        if ($equipment->resolusi) {
            // Distribusi persegi: nilai benar bisa di mana aja dalam ±setengah
            // resolusi, jadi setengah-lebarnya a = resolusi/2, dibagi akar 3.
            $komponen[] = [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Resolusi alat %s %s', $equipment->resolusi, $equipment->satuan ?? ''),
                'distribusi' => 'persegi',
                'nilai' => ($equipment->resolusi / 2) / sqrt(3),
            ];
        }

        if ($standard->drift) {
            $komponen[] = [
                'sumber' => 'drift_standar',
                'keterangan' => sprintf('Drift standar %s per tahun', $standard->drift),
                'distribusi' => 'persegi',
                'nilai' => $standard->drift / sqrt(3),
            ];
        }

        return $komponen;
    }

    /**
     * Welch–Satterthwaite. Komponen Type B dianggap derajat kebebasannya tak
     * hingga (angkanya dari sertifikat standar, bukan dari pengulangan kita),
     * jadi cuma Type A yang nyumbang ke penyebut.
     *
     * `null` = tak hingga (nggak ada sebaran Type A yang bisa dihitung).
     */
    private function derajatKebebasanEfektif(float $gabungan, float $typeA, int $n): ?float
    {
        if ($typeA <= 0.0 || $n < 2) {
            return null;
        }

        return $gabungan ** 4 / ($typeA ** 4 / ($n - 1));
    }

    /**
     * Standar deviasi SAMPEL (pembagi n-1), bukan populasi (pembagi n). Pembacaan
     * kita cuma contoh dari semua kemungkinan pembacaan, bukan seluruh populasinya.
     *
     * @param  list<float>  $pembacaan
     */
    private function standarDeviasiSampel(array $pembacaan, float $rataRata): float
    {
        $n = count($pembacaan);

        if ($n < 2) {
            return 0.0;
        }

        $jumlahKuadratSelisih = array_sum(
            array_map(fn (float $x): float => ($x - $rataRata) ** 2, $pembacaan),
        );

        return sqrt($jumlahKuadratSelisih / ($n - 1));
    }

    /**
     * Gabung ketidakpastian: akar dari jumlah kuadrat. Ketidakpastian nggak
     * dijumlah lurus — komponennya saling bebas, jadi sebagiannya saling meniadakan.
     *
     * @param  list<float>  $nilai
     */
    private function akarJumlahKuadrat(array $nilai): float
    {
        return sqrt(array_sum(array_map(fn (float $u): float => $u ** 2, $nilai)));
    }
}
