<?php

namespace App\Services;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Standard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
     * Batas geser titik ukur dari titik nominal kemampuan (CMC) sebelum
     * dianggap BUKAN titik yang sama. Nilai sertifikat buffer/standar asli
     * di data pH cuma geser 0.009-0.021 dari nominalnya (3.99 vs 4, 6.9889
     * vs 7, 9.9789 vs 10) — 0.1 ngasih margin ~5x dari itu, jauh di bawah
     * jarak antar titik nominal (min. 3, buat pH 4 ke 7). Lihat
     * kemampuanUntukTitik(): titik ukur beneran BEDA (mis. 3.5) TIDAK boleh
     * ikut kepasangin CMC titik nominal terdekat cuma karena round()-nya
     * kebetulan sama.
     */
    private const MAX_DRIFT_TITIK_TUNGGAL = 0.1;

    /**
     * Cache kemampuan kalibrasi per `equipment_category_id`, seumur hidup
     * instance ini (satu request lewat DI, lihat CalibrationController) —
     * bukan cache lintas-request.
     *
     * @var array<int, Collection<int, CalibrationCapability>>
     */
    private array $kemampuanPerKategori = [];

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
            ? $this->hitungDariKemampuan($kemampuan, $typeA, $n)
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
     * Jalur CMC: lab udah menyatakan ketidakpastian terbaiknya buat titik ini
     * di lampiran akreditasi. CMC dipakai sebagai **kontribusi lab + LANTAI**
     * yang dilaporkan — bukan sebagai angka jadi yang menggantikan sebaran ukur.
     *
     * ## Kenapa Type A WAJIB ikut
     *
     * Sampai 29 Juli 2026 method ini balikin CMC apa adanya dan Type A cuma
     * disimpen "buat QC". Akibatnya U95 di sertifikat SELALU sama persis buat
     * titik yang sama, berapa pun sebaran pembacaan teknisi — lima pembacaan
     * rapat dan lima pembacaan berantakan keluar angka identik.
     *
     * Itu bukan cuma bikin bingung; itu klaim yang salah. CMC adalah
     * ketidakpastian TERBAIK yang bisa dicapai lab pada kondisi optimal dengan
     * alat yang berperilaku normal (ILAC-P14). Dia nggak nyakup perilaku alat
     * PELANGGAN yang lagi dikalibrasi. Waktu pembacaan alat itu berserak jauh
     * lebih besar dari CMC — alat rusak, elektroda mau mati, larutan kotor —
     * ngelaporin ±CMC berarti nyatain presisi yang nggak pernah terjadi.
     *
     * Jadi: `u_c = sqrt(u_cmc² + u_A²)`, `U = k · u_c`, lalu **dilantai ke
     * CMC** supaya nggak pernah ngeklaim lebih baik dari kemampuan terakreditasi
     * lab. Sebaran yang rapat menghasilkan angka yang praktis sama kayak dulu;
     * yang berantakan sekarang kelihatan berantakan.
     *
     * @return array<string, mixed>
     */
    private function hitungDariKemampuan(
        CalibrationCapability $kemampuan,
        float $typeA,
        int $n,
    ): array {
        $k = $kemampuan->faktor_cakupan ?: self::FAKTOR_CAKUPAN;
        $cmcDiperluas = (float) $kemampuan->ketidakpastian_terbaik;
        $uCmc = $cmcDiperluas / $k;

        $gabungan = $this->akarJumlahKuadrat([$uCmc, $typeA]);

        // Lantai CMC: hasil hitung nggak boleh lebih kecil dari kemampuan
        // terakreditasi lab, walau pembacaannya kebetulan mulus banget.
        $diperluas = max($cmcDiperluas, $k * $gabungan);

        return [
            'type_b_components' => [[
                'sumber' => 'cmc_kemampuan_kalibrasi',
                'keterangan' => sprintf(
                    'CMC %s%s (U=%s %s, k=%s)',
                    $kemampuan->nama_alat,
                    $kemampuan->parameter ? " — {$kemampuan->parameter}" : '',
                    $cmcDiperluas,
                    $kemampuan->satuan_ketidakpastian,
                    $k,
                ),
                'distribusi' => 'normal',
                'nilai' => $uCmc,
            ]],
            'type_b' => $uCmc,
            'ketidakpastian_gabungan' => $gabungan,
            'faktor_cakupan_k' => $k,
            // Welch-Satterthwaite: cuma Type A yang punya derajat kebebasan
            // terbatas di sini; CMC dianggap tak-hingga (nilai tetap dari
            // akreditasi). Null kalau nggak ada pengulangan buat dihitung.
            'derajat_kebebasan_efektif' => $this->derajatKebebasanEfektif($gabungan, $typeA, $n),
            'ketidakpastian_diperluas' => $diperluas,
            // Nomor IK dari lampiran akreditasi — dicetak di sertifikat sebagai
            // "Calibration Method". Null kalau CMC ini nggak punya field metode.
            'metode' => $kemampuan->metode,
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
            // Jalur generik nggak nunjuk ke CMC spesifik, jadi nggak ada nomor IK.
            'metode' => null,
        ];
    }

    /**
     * Kemampuan kalibrasi (CMC) yang dideklarasikan lab buat titik ini, kalau
     * ada. WAJIB `equipment->nama_alat_kemampuan` keisi — itu yang nunjuk baris
     * `CalibrationCapability` mana yang beneran punya JENIS alat yang sama
     * (mis. "Vernier Caliper"), bukan cuma kategori yang sama. Tanpa link ini,
     * balik `null` (jalur generik) — TIDAK nebak dari kategori doang.
     *
     * Kenapa link ini wajib: satu `equipment_category_id` (mis. "Panjang")
     * nampung banyak JENIS alat berbeda (Sieve, Micrometer, Vernier Caliper)
     * yang rentangnya suka tumpang tindih. Match cuma dari kategori + rentang
     * angka doang bikin jangka sorong toleransi 0.05mm kepasangin CMC Sieve
     * 4mm gara-gara sama-sama "Panjang" dan sama-sama nyakup 50mm — bug nyata
     * yang kejadian pas fitur ini pertama kali dicoba digeneralisir. Begitu
     * `nama_alat_kemampuan` mastiin jenis alatnya, ambiguitas itu ilang, dan
     * dua macam kemampuan di bawah ini aman dicocokkan:
     *
     * - Titik tunggal PRESISI (`range_min == range_max`, non-null — lihat
     *   `PhMeterCapabilitySeeder`): satu CMC buat satu titik ukur spesifik
     *   (mis. buffer pH 4/7/10), dari perhitungan budget ketidakpastian
     *   sendiri (bukan dibulatkan). Dicocokkan ke titik BULAT karena nilai
     *   sertifikat buffer/standar asli suka geser dikit per lot (mis. pH 3.99
     *   bukan 4.00 persis) — tapi selisihnya wajib di bawah
     *   `MAX_DRIFT_TITIK_TUNGGAL`, bukan asal "titik bulatnya sama" (round(3.5)
     *   kebetulan == 4 bukan berarti 3.5 itu "buffer 4 yang geser"). Ini
     *   PRIORITAS PERTAMA — dicek duluan sebelum titik tunggal generik.
     * - Titik tunggal GENERIK (`range_min` kosong — konvensi
     *   `kemampuan-kalibrasi.json` / `CalibrationCapabilitySeeder`, dibulatkan
     *   3 desimal): fallback kalau nggak ada baris presisi buat titik yang
     *   sama. Tanpa prioritas ini, `nama_alat` yang punya DUA baris buat titik
     *   bulat yang sama (satu generik dari lampiran akreditasi, satu presisi
     *   dari perhitungan GUM sendiri — persis kasus "pH Meter") bakal ambigu:
     *   `first()` polos milih siapa aja yang duluan ke-insert, biasanya yang
     *   generik, dan baris presisinya jadi nggak pernah kepake — bug nyata
     *   yang kejadian pas `PhMeterCapabilitySeeder` pertama kali ditambahin.
     * - Rentang kontinyu (`range_min < range_max` beneran, mis. jangka sorong
     *   0-300mm): satu CMC berlaku buat SELURUH rentang itu, dicocokkan
     *   dengan titik ukur ASLI (nggak dibulatkan) jatuh di dalamnya. Titik
     *   tunggal (presisi maupun generik) diprioritaskan lebih dulu kalau
     *   dua-duanya somehow cocok.
     *
     * Kandidat kemampuan per kategori di-cache di instance ini — satu sesi
     * kalibrasi bisa punya banyak titik ukur (hitungTitik dipanggil berkali-
     * kali di request yang sama), dan semuanya nunjuk ke `equipment` yang
     * sama, jadi kategorinya juga sama. Tanpa cache ini query-nya nge-N+1
     * satu kali per titik.
     */
    private function kemampuanUntukTitik(Equipment $equipment, float $titikUkur): ?CalibrationCapability
    {
        if ($equipment->equipment_category_id === null || $equipment->nama_alat_kemampuan === null) {
            return null;
        }

        $kategoriId = $equipment->equipment_category_id;

        $this->kemampuanPerKategori[$kategoriId] ??= CalibrationCapability::query()
            ->where('equipment_category_id', $kategoriId)
            ->get();

        $kandidat = $this->kemampuanPerKategori[$kategoriId]
            ->where('nama_alat', $equipment->nama_alat_kemampuan);

        $titikBulat = round($titikUkur);

        $cocokTitikTunggal = fn (CalibrationCapability $k): bool => (float) $k->range_max === (float) $titikBulat
            && abs($titikUkur - $titikBulat) <= self::MAX_DRIFT_TITIK_TUNGGAL;

        // Presisi dulu (range_min == range_max, non-null), baru generik
        // (range_min null) — lihat penjelasan di docblock method ini.
        $titikTunggal = $kandidat->first(
            fn (CalibrationCapability $k): bool => $k->range_min !== null
                && (float) $k->range_min === (float) $k->range_max
                && $cocokTitikTunggal($k),
        ) ?? $kandidat->first(
            fn (CalibrationCapability $k): bool => $k->range_min === null && $cocokTitikTunggal($k),
        );

        if ($titikTunggal !== null) {
            return $titikTunggal;
        }

        return $kandidat->first(
            fn (CalibrationCapability $k): bool => $k->range_min !== null
                && $k->range_min < $k->range_max
                && $titikUkur >= $k->range_min
                && $titikUkur <= $k->range_max,
        );
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
