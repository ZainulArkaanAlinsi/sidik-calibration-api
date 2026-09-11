<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Susun ulang satu keping lembar **Anak Timbangan** (OIML R111) dari baris
 * `raw_measurements`, plus blok tingkat-sesinya dari `spesifikasi_alat`.
 *
 * ## Kenapa ini ada — kejadian KESEBELAS dengan pola yang sama
 *
 * Saudaranya [GridSensorMentah], [PasanganStandarUutMentah], [TimbanganMentah],
 * [WaktuMentah], [MicrometerMentah], [HeightGaugeMentah], dan [FlowmeterMentah]
 * sudah menjelaskan pola besarnya: alat yang satu titiknya BUKAN satu deret
 * pembacaan datar harus disusun ULANG tiap kali sesi tersimpan dihitung lagi.
 * Lupa melakukannya tidak menghasilkan error — yang muncul `hitung_ulang_gagal`
 * di setiap titik, tiap kali, sampai admin belajar menekan "setujui tetap"
 * tanpa membaca.
 *
 * Kelas ini ditulis BERSAMAAN dengan profilnya, bukan menyusul.
 *
 * ## Bentuk yang disusun ulang, dan kenapa deret datar TIDAK cukup
 *
 *   peran_sensor  pembacaan_ke  arti
 *   at_s1         1..3          penimbangan standar, pertama
 *   at_t1         1..3          penimbangan UUT, pertama
 *   at_t2         1..3          penimbangan UUT, kedua
 *   at_s2         1..3          penimbangan standar, kedua
 *
 * Empat PERAN itu bukan empat ulangan yang boleh ditukar-tukar. Substitusi
 * ganda ABBA menghitung
 *
 *     de = (T1 − S1 − S2 + T2) / 2
 *
 * yang tanda tiap sukunya BEDA. Diratakan jadi satu deret `[a, b, c, d]`,
 * urutan baris dari database menentukan mana yang jadi S dan mana yang jadi T —
 * dan `raw_measurements` tidak menjamin urutan tanpa `ORDER BY`. Sesi yang
 * barisnya kebetulan pulang terbalik menghasilkan `de` dengan **tanda yang
 * berlawanan**, jadi koreksi kepingnya terbalik arah, tanpa satu pun error.
 *
 * Itulah kenapa perannya eksplisit, bukan `pembacaan_ke` 1..4.
 *
 * ## NOL kolom baru
 *
 * Sumbu `peran_sensor` yang sudah ada cukup. Blok tingkat-SESI — kelas UUT,
 * kelas standar, neraca yang dipakai, meter lingkungan, dan keenam ujung
 * kondisi ruangan — hidup di `calibration_sessions.spesifikasi_alat`.
 * Memberinya `titik_ke` melahirkan titik hantu yang selalu gagal hitung ulang;
 * lihat [blokSesi].
 *
 * ## `no_identitas` ikut blok sesi, bukan baris mentah
 *
 * Satu set anak timbangan berisi keping KEMBAR — dua 200 g, dua 20 g, dua 2 g,
 * dua 0,2 g, dua 0,02 g. Tanpa penanda fisik, pelanggan menerima dua baris
 * bernominal sama dan tidak tahu yang mana yang mana; di sesi contoh master
 * kolom itu tercetak `-` dua puluh kali (pertanyaan lab §11).
 *
 * Penandanya TEKS, dan `raw_measurements.pembacaan` itu angka. Dia juga tidak
 * pernah masuk hitungan — dia keterangan sertifikat. Jadi tempatnya
 * `spesifikasi_alat.anak_timbangan.identitas`, dipetakan per `titik_ke`, dan
 * jalur hitung ulang sudah mengangkut `spesifikasi_alat` apa adanya.
 *
 * Balik `[]` — bukan blok kosong — kalau tidak ada baris ber-`peran_sensor`
 * milik lembar ini. Alasannya sama seperti ketujuh saudaranya: profil alat lain
 * tidak pernah menengok kunci ini, dan kunci yang muncul kosong lebih berbahaya
 * daripada kunci yang tidak ada.
 */
class AnakTimbanganMentah
{
    public const PERAN_S1 = 'at_s1';

    public const PERAN_T1 = 'at_t1';

    public const PERAN_T2 = 'at_t2';

    public const PERAN_S2 = 'at_s2';

    /** Urutan ABBA, dan urutannya MENGIKAT — lihat docblock kelas. */
    public const PERAN_URUT = [self::PERAN_S1, self::PERAN_T1, self::PERAN_T2, self::PERAN_S2];

    /** Kunci blok tingkat-sesi di `calibration_sessions.spesifikasi_alat`. */
    public const KUNCI_SESI = 'anak_timbangan';

    /**
     * Kunci deret mentah di `konteks`.
     *
     * Ber-prefiks `at_` dengan sengaja: `konteks` itu ruang BERSAMA yang
     * disebari kunci dua belas alat sekaligus, dan nama generik seperti
     * `deret` di situ cepat atau lambat bertabrakan dengan alat lain —
     * diam-diam, karena yang menang cuma yang disebar belakangan.
     */
    public const KUNCI_DERET = 'at_deret';

    /** Kelas OIML yang dikenali, untuk UUT maupun keping standar. */
    public const KELAS = ['E1', 'E2', 'F1', 'F2', 'M1', 'M2', 'M3'];

    /**
     * Jumlah pembacaan tiap peran menurut KERTASNYA.
     *
     * Lembar `SIDIK-FM-CAL-0541_Rev.0` menyediakan tiga kolom (`X1 X2 X3`) untuk
     * tiap baris Standard/UUT/UUT/Standard — dua belas angka per keping.
     * Workbook masternya cuma menyimpan SATU angka per baris, jadi delapan dari
     * dua belas tidak punya tempat di sana. Pertanyaan lab §23.
     */
    public const PENGULANGAN_KERTAS = 3;

    /**
     * @param  Collection<int, RawMeasurement>  $baris  baris satu `titik_ke`
     * @return array{at_s1: float|null, at_t1: float|null, at_t2: float|null, at_s2: float|null, at_deret: array<string, list<float>>}|array{}
     */
    public static function dari(Collection $baris): array
    {
        $milikKita = $baris->filter(static fn ($b): bool => in_array(
            (string) $b->peran_sensor,
            self::PERAN_URUT,
            true,
        ));

        if ($milikKita->isEmpty()) {
            return [];
        }

        $hasil = [self::KUNCI_DERET => []];

        foreach (self::PERAN_URUT as $peran) {
            $deret = $milikKita
                ->filter(static fn ($b): bool => (string) $b->peran_sensor === $peran)
                // Diurut EKSPLISIT. Rata-rata memang tidak bergeser oleh urutan,
                // tapi jejak auditnya menyebut deretnya apa adanya dan urutan
                // baris dari database tidak dijamin tanpa `ORDER BY`.
                ->sortBy(static fn ($b): int => (int) ($b->pembacaan_ke ?? 0))
                ->map(static fn ($b): float => (float) $b->pembacaan)
                ->values()
                ->all();

            $hasil[self::KUNCI_DERET][$peran] = $deret;
            $hasil[$peran] = self::rataDeret($deret);
        }

        return $hasil;
    }

    /**
     * Rata-rata pembacaan satu peran, atau `null` kalau deretnya kosong.
     *
     * ## Kenapa rata-rata, dan kenapa itu perlu ditanyakan
     *
     * Kertasnya meminta tiga pembacaan per baris; workbook masternya cuma punya
     * satu sel per baris. Ke mana dua sisanya, tidak tertulis di mana pun.
     * Rata-rata dipilih karena dia satu-satunya perlakuan yang **runtuh jadi
     * perilaku master** waktu deretnya cuma berisi satu angka — memilih yang
     * pertama, yang terakhir, atau yang tengah sama-sama membuang data yang
     * sengaja dikumpulkan teknisi.
     *
     * Yang memutuskan tetap lab. Pertanyaan lab §23.
     *
     * @param  list<float>  $deret
     */
    private static function rataDeret(array $deret): ?float
    {
        return $deret === [] ? null : array_sum($deret) / count($deret);
    }

    /**
     * Blok tingkat-SESI dari `spesifikasi_alat`, sudah dinormalkan ke bentuk
     * yang diterima `AnakTimbanganCalculator::hitungSesi()`.
     *
     * Yang dioper `spesifikasi_alat`-nya, BUKAN model sesinya — alasannya sama
     * dengan [HeightGaugeMentah]: jalur simpan dan jalur hitung ulang sama-sama
     * menaruh blok itu di `konteks`, dan profil yang menengok relasi sesi cuma
     * jalan di salah satunya, diam-diam, di jalur yang tidak pernah dites.
     *
     * ## Ujung kondisi ruangan disimpan BERPASANGAN, bukan rata-ratanya
     *
     * Rata-rata dipakai densitas udara, tapi SELISIH kedua ujung dipakai
     * ketidakpastian kondisi lingkungan yang tercetak di kepala sertifikat
     * (`U95 = √(U95_meter² + Δ²)`, terbukti cocok di ketiga besaran). Menyimpan
     * rata-ratanya saja membuang Δ, dan yang hilang bukan angka pelengkap —
     * dia salah satu dari dua suku di bawah akar.
     *
     * Balik `null` kalau bloknya belum ada: densitas udara tidak bisa dihitung
     * tanpa suhu, kelembaban, dan tekanan, dan menebaknya berarti menerbitkan
     * koreksi apung yang tidak bersumber.
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat  isi `calibration_sessions.spesifikasi_alat`
     * @return array{kelas_uut: string|null, kelas_standar: string|null, timbangan: string|null, meter_lingkungan: string|null, kapasitas_g: float|null, suhu: array{awal: float|null, akhir: float|null}, kelembaban: array{awal: float|null, akhir: float|null}, tekanan: array{awal: float|null, akhir: float|null}, identitas: array<int, string>}|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = is_array($spesifikasiAlat) ? ($spesifikasiAlat[self::KUNCI_SESI] ?? null) : null;

        if (! is_array($blok)) {
            return null;
        }

        return [
            'kelas_uut' => self::kelas($blok['kelas_uut'] ?? null),
            'kelas_standar' => self::kelas($blok['kelas_standar'] ?? null),
            'timbangan' => self::teks($blok['timbangan'] ?? null),
            'meter_lingkungan' => self::teks($blok['meter_lingkungan'] ?? null),
            'kapasitas_g' => self::angka($blok['kapasitas_g'] ?? null),
            'suhu' => self::pasangan($blok, 'suhu'),
            'kelembaban' => self::pasangan($blok, 'kelembaban'),
            'tekanan' => self::pasangan($blok, 'tekanan'),
            'identitas' => self::identitas($blok['identitas'] ?? null),
        ];
    }

    /**
     * Rata-rata sepasang ujung, atau `null` kalau salah satunya belum diisi.
     *
     * `null` — BUKAN separuh. Satu ujung yang kosong membuat rata-rata jadi
     * separuhnya, dan tekanan 466 hPa di ruangan ber-933 hPa menggeser densitas
     * udara dari 1,09 ke 0,55 kg/m³. Koreksi apungnya ikut geser tanpa satu pun
     * error — persis bentuk yang sudah menggigit di Height Gauge, di mana suhu
     * separuh melonjakkan komponen budget empat puluh kali.
     *
     * Sengaja beda dari `HeightGaugeMentah::rataSuhuRuang()`, yang memakai ujung
     * yang ada. Di sana suhu cuma memasok ϴ dan sesinya tetap bisa terbit; di
     * sini ketiganya masuk densitas udara, jadi yang benar memblokir sesinya.
     */
    public static function rataUjung(mixed $awal, mixed $akhir): ?float
    {
        if (! is_numeric($awal) || ! is_numeric($akhir)) {
            return null;
        }

        return ((float) $awal + (float) $akhir) / 2;
    }

    /** @return array{awal: float|null, akhir: float|null} */
    private static function pasangan(array $blok, string $nama): array
    {
        return [
            'awal' => self::angka($blok[$nama.'_awal'] ?? null),
            'akhir' => self::angka($blok[$nama.'_akhir'] ?? null),
        ];
    }

    /**
     * Penanda keping per `titik_ke`.
     *
     * Kunci non-numerik dan nilai non-teks dibuang, bukan dipaksa: penanda yang
     * salah tempat mencetak identitas keping LAIN di sertifikat, dan itu lebih
     * buruk daripada kolom yang kosong.
     *
     * @return array<int, string>
     */
    private static function identitas(mixed $nilai): array
    {
        if (! is_array($nilai)) {
            return [];
        }

        $hasil = [];

        foreach ($nilai as $titikKe => $penanda) {
            if (! is_numeric($titikKe) || ! is_string($penanda)) {
                continue;
            }

            $bersih = trim($penanda);

            if ($bersih !== '') {
                $hasil[(int) $titikKe] = $bersih;
            }
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * Kelas OIML yang dikenali saja; selain itu `null`.
     *
     * Tidak ditebak ke F1 walau itu yang paling lazim. Kelas menentukan kolom
     * mana yang dibaca di tabel densitas DAN baris mana di tabel MPE — menebak
     * salah satunya menggeser koreksi apung sekaligus gerbang penolakan `de`,
     * dua-duanya tanpa error.
     */
    private static function kelas(mixed $nilai): ?string
    {
        $bersih = is_string($nilai) ? strtoupper(trim($nilai)) : null;

        return in_array($bersih, self::KELAS, true) ? $bersih : null;
    }

    private static function teks(mixed $nilai): ?string
    {
        $bersih = is_string($nilai) ? trim($nilai) : '';

        return $bersih === '' ? null : $bersih;
    }

    private static function angka(mixed $nilai): ?float
    {
        return is_numeric($nilai) ? (float) $nilai : null;
    }
}
