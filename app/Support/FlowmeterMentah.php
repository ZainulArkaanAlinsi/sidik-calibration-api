<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Susun ulang satu titik lembar **Flowmeter Ultrasonic** dari baris
 * `raw_measurements`, plus blok tingkat-sesinya dari `spesifikasi_alat`.
 *
 * ## Kenapa ini ada — kejadian KESEBELAS dengan pola yang sama
 *
 * Saudaranya [GridSensorMentah], [PasanganStandarUutMentah], [TimbanganMentah],
 * [WaktuMentah], [MicrometerMentah], dan [HeightGaugeMentah] sudah menjelaskan
 * pola besarnya: alat yang satu titiknya BUKAN satu deret pembacaan datar harus
 * disusun ULANG tiap kali sesi tersimpan dihitung lagi. Lupa melakukannya tidak
 * menghasilkan error — yang muncul `hitung_ulang_gagal` di setiap titik, tiap
 * kali, sampai admin belajar menekan "setujui tetap" tanpa membaca.
 *
 * Ditulis BERSAMAAN dengan profilnya, bukan ditemukan belakangan.
 *
 * ## Satu kelas, DUA varian
 *
 * Totalizer dan Flowrate memakai kosakata `peran_sensor` yang SAMA. Yang
 * membedakannya `mode` di blok sesi, bukan kelas mentah kedua — dua kelas yang
 * 90 % sama berarti dua tempat yang harus ingat diperbarui bareng, dan yang
 * ketinggalan tidak menerbitkan error.
 *
 * ## Bentuk yang disusun ulang
 *
 *   peran_sensor        pembacaan_ke  sensor_ke   arti
 *   flow_uut_pembacaan  1..3 ulangan  1..3 durasi Flowrate: 20"/40"/60"
 *                                     1           Totalizer: tanpa durasi
 *   flow_std_pembacaan  1..3 ulangan  1           pembacaan totalizer standar UFM
 *   flow_suhu_awal      1..3 ulangan  1           suhu air awal (°C)
 *   flow_suhu_akhir     1..3 ulangan  1           suhu air akhir (°C)
 *   flow_densitas_uut   1..3 ulangan  1           densitas fluida UUT (kg/L), OPSIONAL
 *
 * Sisi UUT sengaja BERSARANG (ulangan → durasi). Meratakannya jadi satu deret
 * datar menghancurkan komponen "Pengulangan Pembacaan UUT" varian Flowrate,
 * yang justru `MAX` dari simpangan baku TIAP ULANGAN atas ketiga durasinya
 * (`PERHITUNGAN FC!Q28`) — bukan simpangan baku kesembilan angka. Diratakan,
 * angkanya keluar jauh lebih besar dan tetap terlihat masuk akal.
 *
 * ## Nama yang JUJUR, beda dari master
 *
 * Di master Totalizer, blok yang diketik teknisi berlabel *"Readings of
 * Weighing Result Standard"* (`INPUT DATA!B50:P53`) — sisa metode penimbangan
 * statis. Tapi `INPUT DATA!G50` sendiri bertuliskan **`'TIDAK DIPAKAI'`**:
 * angka yang diketik di situ sebenarnya **pembacaan totalizer standar UFM**,
 * bukan hasil timbangan. Label sheet-nya berbohong, begitu juga
 * `'Lookup status timbangan'` di `X20` yang sebenarnya memantau UFM.
 *
 * Di sini perannya `flow_std_pembacaan` dan lembarnya menulis *"Pembacaan
 * Totalizer Standar"*. Blok **Empty Container Weight** (`B35:P37`) tidak
 * dipungut sama sekali — kertas `SIDIK-FM-CAL-0538` tidak punya kotaknya, dan
 * tidak ada satu pun rumus yang membacanya.
 *
 * ## NOL kolom baru
 *
 * Sumbu `peran_sensor`/`pembacaan_ke`/`sensor_ke` yang sudah ada cukup, dan
 * blok tingkat-SESI — mode, satuan, kapasitas, resolusi, geometri pipa,
 * material, jenis fluida, path configuration, liner — hidup di
 * `calibration_sessions.spesifikasi_alat`. Ini alat kelima berturut-turut yang
 * mendarat tanpa satu pun kolom baru di `raw_measurements`.
 *
 * Diameter dan ketebalan pipa tingkat SESI, BUKAN per titik: di kedua master
 * dia diisi sekali (`INPUT DATA!E25:G25` dan `E26:G26`) dan `u_A` yang lahir
 * darinya dipakai semua titik. Memaksanya jadi `titik_ke` melahirkan titik
 * hantu yang selalu gagal hitung ulang.
 *
 * Balik `[]` — bukan blok kosong — kalau tidak ada baris ber-`peran_sensor`
 * milik lembar ini. Alasannya sama seperti keenam saudaranya: profil alat lain
 * tidak pernah menengok kunci ini, dan kunci yang muncul kosong lebih berbahaya
 * daripada kunci yang tidak ada.
 */
class FlowmeterMentah
{
    public const PERAN_UUT = 'flow_uut_pembacaan';

    public const PERAN_STD = 'flow_std_pembacaan';

    public const PERAN_SUHU_AWAL = 'flow_suhu_awal';

    public const PERAN_SUHU_AKHIR = 'flow_suhu_akhir';

    public const PERAN_DENSITAS = 'flow_densitas_uut';

    /** Kelima peran lembar ini — dipakai penjaga "ini titik flowmeter atau bukan". */
    public const SEMUA_PERAN = [
        self::PERAN_UUT,
        self::PERAN_STD,
        self::PERAN_SUHU_AWAL,
        self::PERAN_SUHU_AKHIR,
        self::PERAN_DENSITAS,
    ];

    /** Kunci blok tingkat-sesi di `calibration_sessions.spesifikasi_alat`. */
    public const KUNCI_SESI = 'flowmeter';

    public const MODE_TOTALIZER = 'totalizer';

    public const MODE_FLOWRATE = 'flowrate';

    /**
     * Tiga path configuration yang tercetak di kertas `SIDIK-FM-CAL-0538`.
     *
     * Satu PILIHAN, bukan tiga centang. Master tidak punya kotaknya sama sekali
     * — ini murni dari kertas — dan tiga boolean yang saling meniadakan itu
     * bentuk yang tidak bisa divalidasi: apa pun yang dikirim HP, selalu ada
     * tafsir yang membuatnya "sah". Persoalan yang sama dengan checkbox
     * kerataan muka ukur Height Gauge yang di sesi contoh tercentang dua-duanya.
     */
    public const PATH_CONFIGURATION = ['Z', 'V', 'W'];

    /**
     * @param  Collection<int, RawMeasurement>  $baris  baris satu `titik_ke`
     * @return array{flow_uut_pembacaan: list<list<float>>, flow_std_pembacaan: list<float>, flow_suhu_awal: list<float>, flow_suhu_akhir: list<float>, flow_densitas_uut: list<float>}|array{}
     */
    public static function dari(Collection $baris): array
    {
        $milikKita = $baris->filter(static fn ($b): bool => in_array(
            (string) $b->peran_sensor,
            self::SEMUA_PERAN,
            true,
        ));

        if ($milikKita->isEmpty()) {
            return [];
        }

        return [
            self::PERAN_UUT => self::deretBersarang($milikKita),
            self::PERAN_STD => self::deret($milikKita, self::PERAN_STD),
            self::PERAN_SUHU_AWAL => self::deret($milikKita, self::PERAN_SUHU_AWAL),
            self::PERAN_SUHU_AKHIR => self::deret($milikKita, self::PERAN_SUHU_AKHIR),
            self::PERAN_DENSITAS => self::deret($milikKita, self::PERAN_DENSITAS),
        ];
    }

    /**
     * Blok tingkat-SESI dari `spesifikasi_alat`, sudah dinormalkan ke bentuk
     * yang diterima `FlowmeterCalculator::hitungSesi()`.
     *
     * Kenapa di `spesifikasi_alat` dan bukan sebagai `titik_ke = 0`: jalur
     * hitung ulang mengelompokkan baris mentah per `titik_ke`, dan blok tanpa
     * titik yang dipaksa masuk ke situ lahir sebagai titik hantu yang selalu
     * gagal — persis yang sudah terjadi pada blok keterulangan Timbangan.
     *
     * Balik `null` kalau bloknya belum ada ATAU `mode`-nya bukan salah satu dari
     * dua yang sah: sesi flowmeter tanpa mode tidak bisa dihitung sama sekali
     * (8 komponen atau 9 ditentukan dari situ), dan menebaknya berarti
     * menerbitkan budget generasi yang salah.
     *
     * Yang dioper `spesifikasi_alat`-nya, BUKAN model sesinya — jalur simpan
     * dan jalur hitung ulang sama-sama menaruh blok itu di `konteks`, dan profil
     * yang menengok relasi sesi cuma jalan di salah satunya.
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat  isi `calibration_sessions.spesifikasi_alat`
     * @return array{mode: string, satuan: string, kapasitas: float, resolusi: float, diameter_pipa_mm: list<float>, ketebalan_pipa_mm: list<float>, material_pipa: string|null, jenis_fluida: string|null, path_configuration: string|null, liner_material: string|null, liner_ketebalan_mm: float|null}|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = is_array($spesifikasiAlat) ? ($spesifikasiAlat[self::KUNCI_SESI] ?? null) : null;

        if (! is_array($blok)) {
            return null;
        }

        $mode = strtolower(trim((string) ($blok['mode'] ?? '')));

        if ($mode !== self::MODE_TOTALIZER && $mode !== self::MODE_FLOWRATE) {
            return null;
        }

        return [
            'mode' => $mode,
            'satuan' => (string) ($blok['satuan'] ?? ($mode === self::MODE_FLOWRATE ? 'LPM' : 'L')),
            'kapasitas' => self::angka($blok['kapasitas'] ?? null),
            'resolusi' => self::angka($blok['resolusi'] ?? null),
            // Geometri pipa SELALU mm — caliper dan thickness gauge standarnya
            // bersertifikat mm, dan `u_A` dihitung dari keduanya. Dia tidak ikut
            // satuan aliran alat pelanggan.
            'diameter_pipa_mm' => self::deretAngka($blok['diameter_pipa_mm'] ?? null),
            'ketebalan_pipa_mm' => self::deretAngka($blok['ketebalan_pipa_mm'] ?? null),
            // Keempat di bawah dipungut KERTAS dan tidak ada di master mana pun.
            // Tidak masuk budget hari ini — jangan mengarang komponen untuknya;
            // lihat docs/pertanyaan-lab-flowmeter.md §13.
            'material_pipa' => self::teks($blok['material_pipa'] ?? null),
            'jenis_fluida' => self::teks($blok['jenis_fluida'] ?? null),
            'path_configuration' => self::pathConfiguration($blok['path_configuration'] ?? null),
            'liner_material' => self::teks($blok['liner_material'] ?? null),
            'liner_ketebalan_mm' => is_numeric($blok['liner_ketebalan_mm'] ?? null)
                ? (float) $blok['liner_ketebalan_mm']
                : null,
        ];
    }

    /**
     * Path configuration — `Z`, `V`, atau `W`, atau `null`.
     *
     * Nilai di luar ketiganya balik `null`, bukan ditebak ke salah satunya. Ini
     * catatan metode yang tercetak di sertifikat, bukan angka; menebaknya
     * berarti mencetak cara pemasangan sensor yang tidak pernah dipilih
     * teknisi.
     */
    private static function pathConfiguration(mixed $nilai): ?string
    {
        if (! is_string($nilai)) {
            return null;
        }

        // "Z-Methode" / "V-Methode" / "W-Metode" dari kertas ikut diterima — HP
        // mengirim labelnya apa adanya kalau dropdown-nya belum disunat.
        $bersih = explode('-', strtoupper(trim($nilai)))[0];

        return in_array($bersih, self::PATH_CONFIGURATION, true) ? $bersih : null;
    }

    /**
     * Deret UUT BERSARANG: ulangan (`pembacaan_ke`) → durasi (`sensor_ke`).
     *
     * Nilai disimpan MENTAH, dalam satuan yang diketik teknisi — konversinya di
     * TEMPAT PAKAI (`FlowmeterCalculator::konversi()`), bukan di ujung masuk.
     *
     * Ini bukan kehati-hatian teoretis: versi pertama Micrometer mengalikan di
     * controller dan menyimpan hasilnya, dan jalur draft membuktikannya tidak
     * idempoten — HP mengirimkan kembali angka yang dia terima, jadi faktornya
     * berlipat tiap kali draft disimpan. Nol error, sertifikat salah ratusan
     * kali lipat. Menyimpan payload yang sama dua kali di sini menghasilkan
     * baris yang sama persis.
     *
     * @param  Collection<int, RawMeasurement>  $baris
     * @return list<list<float>>
     */
    private static function deretBersarang(Collection $baris): array
    {
        return $baris
            ->filter(static fn ($b): bool => (string) $b->peran_sensor === self::PERAN_UUT)
            ->filter(static fn ($b): bool => is_numeric($b->pembacaan))
            // Dikelompokkan per ULANGAN, lalu diurut per DURASI. Urutan dari
            // database tidak dijamin, dan di sini urutannya menggeser angka:
            // simpangan baku per ulangan dihitung atas ketiga durasi ulangan
            // ITU — tercampur, komponen "Pengulangan Pembacaan UUT" jadi
            // sebaran antar-ulangan dan keluar jauh lebih besar.
            ->groupBy(static fn ($b): int => (int) ($b->pembacaan_ke ?? 1))
            ->sortKeys()
            ->map(static fn (Collection $ulangan): array => $ulangan
                ->sortBy(static fn ($b): int => (int) ($b->sensor_ke ?? 1))
                ->map(static fn ($b): float => (float) $b->pembacaan)
                ->values()
                ->all())
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, RawMeasurement>  $baris
     * @return list<float>
     */
    private static function deret(Collection $baris, string $peran): array
    {
        return $baris
            ->filter(static fn ($b): bool => (string) $b->peran_sensor === $peran)
            ->filter(static fn ($b): bool => is_numeric($b->pembacaan))
            // Diurut EKSPLISIT per ulangan: pembacaan standar dipasangkan
            // BERURUTAN dengan ulangan UUT untuk melahirkan selisih berpasangan.
            // Urutan yang tertukar menggeser simpangan bakunya tanpa satu pun
            // error.
            ->sortBy(static fn ($b): int => (int) ($b->pembacaan_ke ?? $b->sensor_ke ?? 0))
            ->map(static fn ($b): float => (float) $b->pembacaan)
            ->values()
            ->all();
    }

    private static function angka(mixed $x): float
    {
        return is_numeric($x) ? (float) $x : 0.0;
    }

    private static function teks(mixed $x): ?string
    {
        $bersih = is_string($x) ? trim($x) : '';

        return $bersih === '' ? null : $bersih;
    }

    /**
     * Deret angka yang bersih — yang bukan angka DILEWATI, bukan dibaca nol.
     *
     * Satu kotak diameter yang kosong dibaca `0` menarik rata-ratanya turun
     * sepertiga, `A` mengecil, dan `ci` komponen cross-sectional melonjak —
     * tanpa satu pun error.
     *
     * @return list<float>
     */
    private static function deretAngka(mixed $x): array
    {
        return is_array($x)
            ? array_values(array_map(
                static fn ($v): float => (float) $v,
                array_filter($x, static fn ($v): bool => is_numeric($v)),
            ))
            : [];
    }
}
