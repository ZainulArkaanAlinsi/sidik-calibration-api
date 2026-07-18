<?php

namespace App\Services;

use App\Models\CalibrationCapability;
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

        // Type A — sebaran hasil pengulangan itu sendiri. Tetap dihitung &
        // disimpen buat audit/QC walaupun jalur CMC di bawah nggak makai
        // angka ini buat ketidakpastian yang dilaporkan.
        $typeA = $standarDeviasi / sqrt($n);

        $error = $rataRata - $titikUkur;
        $toleransi = (float) $equipment->toleransi;

        $kemampuan = $this->kemampuanUntukTitik($equipment, $titikUkur);

        $hasil = $kemampuan !== null
            ? $this->hitungDariKemampuan($kemampuan)
            : $this->hitungDariStandarDanResolusi($typeA, $n, $equipment, $standard);

        return [
            'standard_id' => $standard->id,
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
            ...$hasil,
            'toleransi' => $toleransi,
            'keputusan' => $this->keputusan($error, $hasil['ketidakpastian_diperluas'], $toleransi),
            'calculated_at' => Carbon::now(),
        ];
    }

    /**
     * Jalur CMC: lab udah menyatakan ketidakpastian terbaiknya (dari lampiran
     * akreditasi) buat titik ini, jadi itu yang dilaporkan apa adanya — bukan
     * dikombinasi ulang sama Type A sesi ini. Type A sesi cuma buat QC internal.
     *
     * @return array<string, mixed>
     */
    private function hitungDariKemampuan(CalibrationCapability $kemampuan): array
    {
        $k = $kemampuan->faktor_cakupan ?: self::FAKTOR_CAKUPAN;
        $diperluas = $kemampuan->ketidakpastian_terbaik;
        $gabungan = $diperluas / $k;

        return [
            'type_b_components' => [[
                'sumber' => 'cmc_kemampuan_kalibrasi',
                'keterangan' => sprintf(
                    'CMC %s%s (U=%s %s, k=%s)',
                    $kemampuan->nama_alat,
                    $kemampuan->parameter ? " — {$kemampuan->parameter}" : '',
                    $diperluas,
                    $kemampuan->satuan_ketidakpastian,
                    $k,
                ),
                'distribusi' => 'normal',
                'nilai' => $gabungan,
            ]],
            'type_b' => $gabungan,
            'ketidakpastian_gabungan' => $gabungan,
            'faktor_cakupan_k' => $k,
            // CMC itu nilai tetap dari akreditasi, bukan turunan dari sebaran
            // sesi ini — Welch-Satterthwaite nggak berlaku di sini.
            'derajat_kebebasan_efektif' => null,
            'ketidakpastian_diperluas' => $diperluas,
        ];
    }

    /**
     * Jalur generik yang udah ada dari awal: Type B dari sertifikat standar +
     * resolusi alat, digabung sama Type A sesi ini lewat RSS.
     *
     * @return array<string, mixed>
     */
    private function hitungDariStandarDanResolusi(float $typeA, int $n, Equipment $equipment, Standard $standard): array
    {
        $komponenTypeB = $this->komponenTypeB($equipment, $standard);
        $typeB = $this->akarJumlahKuadrat(array_column($komponenTypeB, 'nilai'));

        $gabungan = $this->akarJumlahKuadrat([$typeA, $typeB]);
        $diperluas = self::FAKTOR_CAKUPAN * $gabungan;

        return [
            'type_b_components' => $komponenTypeB,
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $gabungan,
            'faktor_cakupan_k' => self::FAKTOR_CAKUPAN,
            'derajat_kebebasan_efektif' => $this->derajatKebebasanEfektif($gabungan, $typeA, $n),
            'ketidakpastian_diperluas' => $diperluas,
        ];
    }

    /**
     * Kemampuan kalibrasi (CMC) yang dideklarasikan lab buat titik ini, kalau
     * ada. Dicocokkan ke titik BULAT terdekat (round) karena nilai sertifikat
     * buffer/standar asli suka geser dikit per lot (mis. pH 3.99 bukan 4.00
     * persis), sementara kemampuan didaftarkan per titik nominal (4, 7, 10).
     *
     * SENGAJA cuma nyocokin `range_min = range_max = titik` (titik tunggal
     * presisi, lihat `PhMeterCapabilitySeeder`) — BUKAN rentang kontinyu
     * (mis. jangka sorong 0-300mm) dan BUKAN konvensi `range_min: null` punya
     * lampiran akreditasi. Satu `equipment_category_id` (mis. "Panjang")
     * nampung banyak JENIS alat berbeda (Sieve, Micrometer, Vernier Caliper)
     * yang rentangnya suka tumpang tindih, dan nggak ada link yang jelas
     * antara `Equipment.nama_alat` sama `CalibrationCapability.nama_alat`
     * buat mastiin baris kemampuan yang match itu emang punya JENIS alat yang
     * sama — kalau match cuma dari kategori + rentang angka doang, alat yang
     * satu bisa kepasangin CMC alat lain yang kebetulan rentangnya nyerempet
     * (kejadian nyata: jangka sorong 0.05mm toleransi kepasangin CMC Sieve
     * 4mm gara-gara sama-sama "Panjang" dan sama-sama nyakup 50mm).
     */
    private function kemampuanUntukTitik(Equipment $equipment, float $titikUkur): ?CalibrationCapability
    {
        if ($equipment->equipment_category_id === null) {
            return null;
        }

        $titikBulat = round($titikUkur);

        return CalibrationCapability::query()
            ->where('equipment_category_id', $equipment->equipment_category_id)
            ->where('range_min', $titikBulat)
            ->where('range_max', $titikBulat)
            ->first();
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

// Project ini sebenernya udah punya contoh konkretnya sendiri: app/Services/GumCalculator.php. Itu implementasi perhitungan ketidakpastian kalibrasi (standar JCGM/GUM). Polanya:

// 1. Tiap istilah rumus → method sendiri, dikasih nama sesuai istilah fisikanya, bukan nama matematis: standarDeviasiSampel(), komponenTypeB(), akarJumlahKuadrat(), derajatKebebasanEfektif().
// 2. Method utama (hitungTitik) cuma manggil method-method itu berurutan dan nyusun hasilnya jadi array — jadi alurnya kebaca kayak baca rumus di kertas: Type A → Type B → gabungan → diperluas → keputusan.
// 3. Komentar dipakai buat jelasin bagian yang nggak kelihatan dari kode doang — misal kenapa Type B harus dibagi faktor cakupan k dulu sebelum digabung (baris 96-102), atau kenapa dipakai n-1 bukan n (baris 166-171). Rumusnya sendiri (sqrt, **, dll) udah jelas dari kode, jadi nggak perlu dikomentarin ulang — yang dijelasin itu jebakan/keputusan yang nggak kelihatan cuma dari liat operator matematikanya.
// 4. Konstanta dikasih nama & dikunci di satu tempat (FAKTOR_CAKUPAN, MIN_PENGULANGAN), bukan angka ajaib nyebar di banyak tempat.
