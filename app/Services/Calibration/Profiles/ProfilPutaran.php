<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\PutaranCalculator;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Basis bersama kelompok **Putaran** — Infrared Tachometer & Centrifuge.
 *
 * Kedua alat lahir dari dua workbook master yang sheet `PERHITUNGAN`-nya
 * identik baris demi baris, sampai ke data contohnya; workbook Tachometer
 * bahkan masih menyimpan tautan luar ke `Master Olda Centrifuge.xlsm` sebagai
 * jejak bahwa ia disalin dari sana. Yang benar-benar membedakan keduanya cuma
 * dua hal, dan keduanya dinyatakan oleh subkelas:
 *
 *  - **Nama & pita CMC** di lampiran akreditasi LK-285-IDN: Tachometer no. 39
 *    (60–7000 → 1,5 rpm; 7000–30000 → 5,0 rpm), Centrifuge no. 38 (60–200 →
 *    1,5 rpm; 200–9000 → 1,6 rpm).
 *  - **Judul lembar kerjanya.**
 *
 * Mesin hitungnya satu, di [PutaranCalculator]; tabel standarnya satu, di
 * `App\Services\Calibration\TabelStandarPutaran`. Menyalinnya jadi dua berarti
 * dua tempat yang harus ingat diperbarui waktu sertifikat kalibratornya turun.
 *
 * ## Kenapa lewat `hitungPerGrup()`, bukan `komponenBudget()`
 *
 * Master membuat SATU budget per baris tiga titik, memakai simpangan baku
 * terbesar di antara ketiganya — lihat docblock [PutaranCalculator]. Itu tidak
 * bisa dinyatakan lewat hook per titik.
 *
 * ## Kenapa TIDAK ada `PutaranMentah`
 *
 * Ketiga saudaranya di `App\Support` ada karena bentuk titiknya bukan deret
 * datar. Di sini bentuknya MEMANG deret datar: satu `titik_ke`, `pembacaan_ke`
 * 1..5, `pembacaan` = penunjukan tachometer standar; penunjukan alat pelanggan
 * adalah `titik_ukur` itu sendiri. Jalur hitung ulang bersama sudah mengirim
 * persis kedua hal itu, jadi tidak ada yang perlu disusun ulang.
 *
 * > Dan baris kelompok ini **tidak boleh** diberi `peran_sensor`. `GridSensorMentah::dari()`
 * > memulangkan `[]` hanya kalau tidak ada `peran_sensor` sama sekali — kosakata
 * > baru apa pun akan menjatuhkan tiap titik ke cabang enclosure, ketemu grid
 * > kosong, lalu `continue`: perintah hitung ulang "sukses" tanpa menghitung
 * > apa pun. Dijaga `PutaranTidakPakaiPeranSensorTest`.
 */
abstract class ProfilPutaran extends CalibrationProfile
{
    public const KODE_METODE = 'SIDIK-IK-CAL-0511_Rev.6';

    public const SATUAN = 'rpm';

    /** Lima ulangan per titik — `PERHITUNGAN` baris `Repeat` 1..5. */
    public const PENGULANGAN = 5;

    /**
     * Enam baris × tiga kolom = delapan belas titik, sebanyak yang muat di
     * lembar master Tachometer. Centrifuge memakai lima baris; barisnya boleh
     * dikosongkan, dan titik tanpa pembacaan diblokir [PutaranCalculator]
     * dengan alasan yang kebaca alih-alih lahir sebagai titik hantu.
     */
    public const BARIS_KERTAS = 6;

    /**
     * Baris `Standard Used` yang TERCETAK di kertasnya — satu, dan sama untuk
     * kedua alat rpm.
     *
     * Sheet `SERTIFIKAT KALIBRATOR` di kedua workbook master menyebut keping
     * yang sama persis: Infrared Tachometer NKTECH NK-300 s/n 1186.01.23-1,
     * tertelusur LK-305-IDN.
     */
    public const STANDARD_TERCETAK = [
        [
            'label' => 'Infrared Tachometer/NKTECH/NK-300/1186.01.23-1',
            'cocok' => ['Infrared Tachometer NK-300', '1186.01.23-1'],
        ],
    ];

    /** Ketujuh unit thermohygro yang tercetak di kop kedua master (`TH-1`…`TH-7`). */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    /**
     * Set point SARAN, disalin dari sesi contoh kedua workbook master.
     *
     * Saran, bukan patokan: `titik_bisa_diubah` menyala, dan yang menentukan
     * putaran mana yang diuji tetap alat pelanggan. Diisi karena baris yang
     * lahir ber-`titik_ukur` null tidak punya standar tertaut sama sekali —
     * `tautkanStandarTitik()` mencocokkan lewat NILAI titiknya — sehingga
     * teknisi jatuh ke pilih-standar-manual di setiap baris.
     */
    public const TITIK_SARAN = [
        60.0, 80.0, 100.0,
        120.0, 150.0, 200.0,
        500.0, 1000.0, 2000.0,
        3000.0, 5000.0, 7000.0,
        10000.0, 12000.0, 14000.0,
        15000.0, 20000.0, 25000.0,
    ];

    private ?PutaranCalculator $kalk = null;

    /** Judul yang tercetak di kepala lembar kerja. */
    abstract protected function judulLembar(): string;

    /**
     * Kalibratornya SAMA di setiap titik — satu Infrared Tachometer NK-300
     * yang sertifikatnya mencakup 60..30000 rpm.
     *
     * Tetap ditulis per titik (bukan satu baris untuk semuanya) karena itu
     * bentuk yang dibaca `tautkanStandarTitik()`, dan lewat situ pula
     * `standard_id` mendarat di tiap baris tabel.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return array_map(
            static fn (float $titik): array => [
                'titik' => $titik,
                'standar' => ['Infrared Tachometer NK-300', '1186.01.23-1'],
            ],
            self::TITIK_SARAN,
        );
    }

    public function kodeFormula(): string
    {
        return 'gum-putaran';
    }

    public function besaran(): string
    {
        return 'putaran';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    /**
     * `titik_ukur` kelompok ini menyimpan SET POINT, bukan nilai acuan — jadi
     * kolom `Standard Value` sertifikat disusun balik dari koreksinya.
     * Lihat docblock [CalibrationProfile::nilaiStandarDariKoreksi].
     */
    public function nilaiStandarDariKoreksi(): bool
    {
        return true;
    }

    /** Satu U95 per blok tiga titik, jadi angkanya memang beda antar titik. */
    public function u95PerTitik(): bool
    {
        return true;
    }

    /**
     * Kolom `UUT` sertifikat berisi SET POINT alat pelanggan, dan kolom
     * `Standard` berisi penunjukan tachometer standar yang sudah dikoreksi —
     * kebalikan dari sepuluh alat yang UUT-nya yang dibaca berulang.
     */
    public function judulKolomUut(): string
    {
        return 'Setting';
    }

    /**
     * Master mencetak koreksi rpm dengan dua desimal (`-0,22`), dan U95 dengan
     * satu (`2,6`). Diambil dari format sel `SERTIFIKAT`, bukan dikira-kira.
     */
    public function desimalSertifikat(): ?int
    {
        return 2;
    }

    public function desimalU95(): ?int
    {
        return 1;
    }

    /**
     * Kelompok Putaran tidak divonis PASS/FAIL: baik lampiran akreditasi maupun
     * kedua workbook master tidak menyebut satu pun batas keberterimaan, dan
     * memvonis tanpa toleransi berarti mengarang ambang di dokumen
     * terakreditasi.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    public function minPengulanganPerTitik(): int
    {
        return 2;
    }

    /**
     * Tachometer standar dibaca nominal apa adanya — tidak punya kurva suhu
     * seperti buffer pH. Tanpa override ini setiap sesi kelompok Putaran
     * ke-flag `valid: false` gara-gara `koefisien_suhu` yang memang seharusnya
     * NULL.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * Jalur kamera DIMATIKAN sampai kertasnya turun.
     *
     * Kedua workbook master sudah disapu untuk pola `SIDIK-FM-…` dan yang
     * ketemu cuma SATU: `SIDIK-FM-CAL-2403_Rev. 0` di footer sheet `SERTIFIKAT`
     * — formulir sertifikat yang dipakai bersama semua alat, bukan nomor lembar
     * kerjanya. Nomor lembar kerja kelompok Putaran memang belum pernah
     * dikirim.
     *
     * Dibiarkan `true` (bawaannya), pembaca foto diminta mencocokkan geometri
     * kertas yang tidak ada — dan yang terbit bukan error, melainkan sel yang
     * terbaca dari tempat yang salah.
     */
    public function bentukPindaiFoto(): array
    {
        return [
            // Satu angka per sel; tidak ada kolom °C di dalam tiap pengulangan.
            'kolom_suhu' => false,
            'standar_di_baris' => false,
            'didukung' => false,
            'lokal' => true,
        ];
    }

    /**
     * Budget lahir per blok lewat [hitungPerGrup], jadi hook per titik ini
     * sengaja memulangkan `null` — pemanggil yang lewat jalur lama jatuh ke
     * jalur CMC generik alih-alih memakai budget setengah jadi.
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
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard}>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}|null
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        $kalk = $this->kalk ??= new PutaranCalculator;
        $resolusiUut = (float) ($equipment->resolusi ?: 1.0);

        // Blok = tiga `titik_ke` berurutan, geometri lembar masternya. Diurut
        // dulu: urutan baris dari database tidak dijamin, dan blok yang
        // anggotanya berbeda melahirkan U95 yang berbeda.
        //
        // > **Batas yang diketahui, bukan kelupaan.** `titik_ke` diberikan
        // > `CalibrationController` sebagai nomor urut titik yang TERISI, jadi
        // > informasi "titik ini ada di baris lembar keberapa" tidak tersimpan.
        // > Untuk pengisian normal (kiri ke kanan, atas ke bawah) pengelompokan
        // > di bawah menghasilkan blok yang sama persis dengan lembar masternya.
        // > Untuk pengisian BERLUBANG — mis. dua kolom di baris 1 lalu satu
        // > kolom di baris 2 — master memecahnya jadi dua blok sementara di
        // > sini jadi satu. Arah selisihnya selalu aman: satu blok yang lebih
        // > besar memakai simpangan baku dan pita sertifikat yang lebih besar
        // > pula, jadi U95 kita >= U95 master, tidak pernah lebih kecil.
        usort($titik, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        $blok = array_chunk($titik, PutaranCalculator::TITIK_PER_BLOK);

        $hitungan = [];
        $belumDihitung = [];
        $sekarang = Carbon::now();

        foreach ($blok as $anggota) {
            // Null-safe, dan itu bukan kehati-hatian berlebih: `standard` boleh
            // null (lihat kontrak `TimbanganProfile::hitungPerGrup`), dan sesi
            // yang kalibratornya di-soft-delete memulangkan null di sini. Tanpa
            // `?->`, `kalibrasi:hitung-ulang` MATI TOTAL — bukan melewati satu
            // sesi, tapi menghentikan seluruh perintahnya.
            $standar = $anggota[0]['standard'] ?? null;
            $kemampuan = $this->kemampuanUntukBlok($equipment, $anggota);

            $hasil = $kalk->hitungBlok(
                array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'titik_ukur' => (float) $t['titik_ukur'],
                    'pembacaan' => $t['pembacaan'],
                ], $anggota),
                [
                    'resolusi_uut' => $resolusiUut,
                    'cmc' => $kemampuan?->ketidakpastian_terbaik === null
                        ? null
                        : (float) $kemampuan->ketidakpastian_terbaik,
                    'satuan' => self::SATUAN,
                ],
            );

            foreach ($hasil['ditolak'] as $tolak) {
                $belumDihitung[] = $tolak;
            }

            foreach ($hasil['titik'] as $t) {
                $hitungan[] = [
                    'standard_id' => $standar?->id,
                    'titik_ke' => $t['titik_ke'],
                    'titik_ukur' => $t['titik_ukur'],
                    // Kolom `rata_rata` itu PENUNJUKAN ALAT PELANGGAN menurut
                    // kontrak `uncertainty_calculations`, dan buat kelompok ini
                    // penunjukan itu SET POINT-nya — bukan rata-rata pembacaan
                    // tachometer standar, yang justru nilai acuannya.
                    //
                    // Diisi terbalik, sertifikat mencetak `60 | 59,98 | −0,22`
                    // padahal master menulis `59,78 | 60 | −0,22`: kolomnya
                    // tertukar DAN angkanya tidak menjumlah. Rata-rata standar
                    // mentahnya tetap tersimpan di jejak audit `type_b_components`.
                    'rata_rata' => $t['titik_ukur'],
                    'error' => $t['error'],
                    'koreksi' => $t['koreksi'],
                    'standar_deviasi' => $t['standar_deviasi'],
                    'jumlah_pengulangan' => $t['jumlah_pengulangan'],
                    'type_a' => $t['type_a'],
                    'type_b_components' => $this->jejakAudit($hasil, $t, $kemampuan),
                    'type_b' => $hasil['type_b'],
                    'ketidakpastian_gabungan' => $hasil['ketidakpastian_gabungan'],
                    'faktor_cakupan_k' => $hasil['faktor_cakupan_k'],
                    'derajat_kebebasan_efektif' => $hasil['derajat_kebebasan_efektif'],
                    'ketidakpastian_diperluas' => $hasil['u95_sertifikat'],
                    'toleransi' => null,
                    'keputusan' => null,
                    'metode' => $kemampuan?->metode,
                    'calculated_at' => $sekarang,
                ];
            }
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Baris kemampuan (CMC) yang menaungi titik TERTINGGI di blok — pita yang
     * sama dengan yang dipakai master untuk memilih `DATABASE!S5` vs `S6`.
     *
     * Kalau tidak ada pita yang memuatnya, yang dipulangkan pita TERDEKAT,
     * bukan `null` — lihat [pitaTerdekat] untuk alasannya.
     *
     * @param  list<array{titik_ukur: float}>  $anggota
     */
    protected function kemampuanUntukBlok(Equipment $equipment, array $anggota): ?CalibrationCapability
    {
        $tertinggi = max(array_map(static fn (array $t): float => (float) $t['titik_ukur'], $anggota));

        $pita = CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->when(
                $equipment->equipment_category_id !== null,
                fn ($q) => $q->where('equipment_category_id', $equipment->equipment_category_id),
            )
            // Organisasi disaring juga, bukan cuma kategori. `organization_id`
            // di baris kemampuan bisa BEDA dari organisasi kategorinya (baris
            // milik lab A yang nangkring di kategori lab B), dan baris seperti
            // itu lolos saringan kategori bulat-bulat — lalu angka CMC lab A
            // terpasang sebagai lantai U95 di sertifikat lab B.
            ->when(
                $equipment->organization_id !== null,
                fn ($q) => $q->milikOrganisasi($equipment->organization_id),
            )
            ->orderBy('range_max')
            ->get();

        // Pita PERTAMA yang memuat titik tertinggi, dengan daftar terurut
        // menaik dan KEDUA batas inklusif.
        //
        // Lampiran akreditasi menulis pitanya bersambung (60–7000, 7000–30000),
        // jadi 7000 rpm memenuhi dua pita sekaligus. Yang menang harus pita
        // BAWAH — master memakai `DATABASE!S5` untuk blok yang titik
        // tertingginya 7000 — dan urutan menaik + "ambil yang pertama" yang
        // menegakkannya. Membuat batas bawah eksklusif juga menyelesaikan
        // tumpang tindih itu, tapi sekalian membuang titik 60 rpm dari pita
        // pertama: 60 > 60 itu salah, dan titik terendah yang sah jadi
        // kehilangan lantai CMC-nya tanpa satu pun error.
        $memuat = $pita->first(
            static fn (CalibrationCapability $k): bool => self::jarakKePita($k, $tertinggi) <= 1e-9,
        );

        return $memuat ?? self::pitaTerdekat($pita, $tertinggi);
    }

    /**
     * Pita CMC terdekat ke `$titik` — dipakai kalau TIDAK ADA pita yang
     * memuatnya.
     *
     * ## Kegagalan yang ditutup method ini
     *
     * Sebelumnya yang dipulangkan `null`, dan `null` berarti
     * `max($u95, (float) (null ?? 0.0))` — lantai CMC-nya **hilang seluruhnya**,
     * untuk SATU BLOK PENUH. Pemilihan pitanya per blok (titik tertinggi yang
     * menentukan), jadi satu set point di luar lingkup mencabut lantai CMC dua
     * titik lain di blok yang sama yang justru berada di dalam lingkup.
     *
     * Arah salahnya yang paling buruk: U95 terbit LEBIH KECIL. Diadu ke sistem
     * yang berjalan, dengan pembacaan rapat di 60 & 100 rpm:
     *
     *     blok {60, 100, 12000} rpm  ->  U95 = 5,00 rpm   (pita 7000–30000)
     *     blok {60, 100, 40000} rpm  ->  U95 = 4,44 rpm   (TANPA lantai)
     *
     * Mendorong titik ketiganya makin jauh ke luar lingkup justru memperbaiki
     * ketidakpastian yang tercetak. Untuk lab terakreditasi itu temuan audit
     * yang paling mahal jenisnya: sertifikat yang mengaku lebih baik daripada
     * CMC yang terdaftar di lampiran.
     *
     * ## Kenapa "terdekat", bukan menolak titiknya
     *
     * Karena begitulah master lab-nya berperilaku, dan begitu pula yang sudah
     * dijanjikan ke admin. Blok 5 `Master Olda Centrifuge.xlsm` mengukur 15000,
     * 20000, dan 25000 rpm — ketiganya di atas 9000 — dan tetap memakai CMC
     * 1,6 rpm dari pita `200–9000`. Teks peringatan
     * `centrifuge_di_luar_akreditasi` juga sudah menulis "lantai CMC yang
     * terpasang diambil dari pita tertinggi yang ada". Kode yang memulangkan
     * `null` menyalahi dua-duanya sekaligus — dan peringatan yang isinya tidak
     * benar melatih admin menekan "setujui tetap" tanpa membaca.
     *
     * Lantai selalu aman searah: dia cuma bisa MENAIKKAN U95, tidak pernah
     * menurunkan. Yang menjaga titik di luar lingkup tidak terbit sebagai
     * terakreditasi tetap peringatan sesi, bukan hilangnya lantai ini.
     *
     * Seri jarak dimenangkan CMC yang lebih BESAR, dengan alasan yang sama.
     *
     * @param  Collection<int, CalibrationCapability>  $pita
     */
    private static function pitaTerdekat($pita, float $titik): ?CalibrationCapability
    {
        $terdekat = null;
        $jarakTerdekat = INF;

        foreach ($pita as $k) {
            $jarak = self::jarakKePita($k, $titik);
            $cmc = (float) ($k->ketidakpastian_terbaik ?? 0.0);
            $cmcTerdekat = (float) ($terdekat?->ketidakpastian_terbaik ?? 0.0);

            $lebihDekat = $jarak < $jarakTerdekat - 1e-9;
            $seriTapiLebihBesar = abs($jarak - $jarakTerdekat) <= 1e-9 && $cmc > $cmcTerdekat;

            if ($lebihDekat || $seriTapiLebihBesar) {
                $terdekat = $k;
                $jarakTerdekat = $jarak;
            }
        }

        return $terdekat;
    }

    /**
     * Jarak `$titik` ke pita `$k` — `0.0` kalau pita itu memuatnya.
     *
     * Batas kosong berarti tak terbatas ke arah itu, jadi pita tanpa
     * `range_min` memuat segala yang di bawah `range_max`.
     */
    private static function jarakKePita(CalibrationCapability $k, float $titik): float
    {
        $min = $k->range_min === null ? -INF : (float) $k->range_min;
        $maks = $k->range_max === null ? INF : (float) $k->range_max;

        return max($min - $titik, $titik - $maks, 0.0);
    }

    /**
     * Batas atas pita CMC TERTINGGI alat ini di lampiran akreditasi, dan nomor
     * barisnya di sana.
     *
     * @return array{float, int}
     */
    abstract protected function batasAkreditasi(): array;

    /**
     * Peringatkan admin kalau sesi ini memuat set point DI LUAR pita akreditasi
     * alat ini.
     *
     * Bukan kehati-hatian berlebih: blok 5 `Master Olda Centrifuge.xlsm`
     * mengukur 15000, 20000, dan 25000 rpm — ketiganya di atas 9000 — dan tetap
     * memakai CMC 1,6 rpm dari pita `200–9000`. Angka itu lalu tercetak sebagai
     * ketidakpastian terakreditasi untuk putaran yang lampirannya tidak pernah
     * mencakup. [pitaTerdekat] meniru perilaku itu dengan sengaja, jadi
     * peringatan ini yang menahannya terbit diam-diam.
     *
     * Peringatan, bukan penolakan: lab boleh saja mengkalibrasi di luar lingkup
     * asal sertifikatnya tidak mengaku terakreditasi di titik itu — dan yang
     * berhak memutuskan manajer teknis, bukan kode ini.
     *
     * Ada di basis, bukan di masing-masing profil: dulu cuma Centrifuge yang
     * punya, jadi sesi Tachometer di atas 30000 rpm meminjam lantai CMC pita
     * teratas TANPA satu pun peringatan — persis kebocoran yang peringatan ini
     * dibuat untuk menutup.
     *
     * @return list<array<string, mixed>>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        [$batas, $nomorLampiran] = $this->batasAkreditasi();

        $diLuar = $sesi->uncertaintyCalculations
            ->filter(static fn ($u): bool => (float) $u->titik_ukur > $batas)
            ->map(static fn ($u): string => rtrim(rtrim(number_format((float) $u->titik_ukur, 2, ',', '.'), '0'), ','))
            ->values()
            ->all();

        if ($diLuar === []) {
            return [];
        }

        $batasTerbaca = number_format($batas, 0, ',', '.');

        return [[
            'kode' => $this->kode().'_di_luar_akreditasi',
            'pesan' => sprintf(
                'Sesi ini memuat %d set point di atas %s rpm (%s) — di luar pita akreditasi '
                .'%s LK-285-IDN no. %d yang berhenti di %s rpm. Lantai CMC yang terpasang '
                .'diambil dari pita tertinggi yang ada, jadi ketidakpastian di titik-titik itu '
                .'TIDAK didukung lampiran akreditasi. Pastikan sertifikatnya tidak mengaku '
                .'terakreditasi di titik tersebut.',
                count($diLuar),
                $batasTerbaca,
                implode(', ', $diLuar),
                $this->namaAlatKemampuan(),
                $nomorLampiran,
                $batasTerbaca,
            ),
        ]];
    }

    /**
     * Jejak audit yang disimpan di kolom `type_b_components`.
     *
     * Isinya komponen budget apa adanya PLUS nilai turunan per titik yang
     * tidak punya kolom sendiri (nominal sertifikat yang dipakai, koreksi
     * standar). Tanpa keduanya, sengketa angka setahun lagi tidak bisa
     * ditelusuri sampai ke baris sertifikat kalibrator mana yang terbaca.
     *
     * @param  array<string, mixed>  $hasil
     * @param  array<string, mixed>  $t
     * @return list<array<string, mixed>>
     */
    protected function jejakAudit(array $hasil, array $t, ?CalibrationCapability $kemampuan): array
    {
        $jejak = $hasil['budget'];

        $jejak[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'Set point %s %s · nominal sertifikat %s %s · koreksi standar %s %s · '
                .'rata-rata standar %s %s · U sebelum lantai CMC %s · CMC %s',
                $t['titik_ukur'], self::SATUAN,
                $t['nominal_standar'], self::SATUAN,
                $t['koreksi_standar'], self::SATUAN,
                $t['rata_rata'], self::SATUAN,
                $hasil['ketidakpastian_diperluas'],
                self::sebutKemampuan($kemampuan),
            ),
            'distribusi' => 'jejak',
            'u' => 0.0,
            'ci' => 0.0,
            'vi' => 0.0,
        ];

        return $jejak;
    }

    /**
     * CMC berikut PITA-nya untuk jejak audit — mis. `1,6 (pita 200–9000 rpm)`.
     *
     * Pitanya ikut ditulis, bukan cuma angkanya: blok yang titik tertingginya
     * di luar lingkup meminjam pita terdekat (lihat [pitaTerdekat]), dan tanpa
     * batas pitanya tertulis, sengketa setahun lagi tidak bisa membedakan
     * lantai yang memang menaungi titiknya dari lantai pinjaman.
     */
    private static function sebutKemampuan(?CalibrationCapability $kemampuan): string
    {
        if ($kemampuan === null) {
            return '-';
        }

        return sprintf(
            '%s (pita %s–%s %s)',
            $kemampuan->ketidakpastian_terbaik ?? '-',
            $kemampuan->range_min ?? '~',
            $kemampuan->range_max ?? '~',
            self::SATUAN,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            'kode_dokumen' => null,
            'kode_metode' => self::KODE_METODE,
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => $this->judulLembar(),
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Satu baris = satu SET POINT alat yang dikalibrasi, dan lima kolomnya '
                .'lima kali baca TACHOMETER STANDAR pada putaran itu. Set point yang nggak dipakai '
                .'dikosongin — jangan diisi nol, karena baris nol tetap ikut kehitung sebagai titik. '
                .'U95 lahir per TIGA titik berurutan (satu baris lembar master), memakai simpangan baku '
                .'terbesar di antara ketiganya.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master Olda Tachometer.xlsm & Master Olda Centrifuge.xlsm (16 Apr 2026)',
                'catatan' => 'Lima komponen per blok tiga titik, lantai CMC dari lampiran akreditasi. '
                    .'Tiga penyimpangan master dihitung benar dan dilaporkan lewat peringatan sesi.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianDataKalibrasi(),
                $this->bagianPenutup(),
            ],
        ];

        // Tiga penstempel, dan ketiganya harus jalan:
        //  - `tautkanStandarTitik` menaruh `standard_id` di tiap BARIS TABEL
        //    (lewat `standarPerTitik()`), yang dibaca layar teknisi & jalur
        //    kirim;
        //  - `tautkanStandarTercetak` menaruhnya di baris `Standard Used`;
        //  - `isiPilihanThermohygro` mengisi dropdown kondisi lingkungan.
        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak(
                $this->tautkanStandarTitik($bentuk, $equipment),
                $equipment,
            ),
            $equipment,
        );
    }

    /**
     * Tautkan baris `Standard Used` tercetak ke master milik LAB PEMILIK ALAT.
     *
     * Lewat [masterStandarTertaut], bukan query sendiri: sembilan tempat pernah
     * menyalin `Standard::whereNull('parameter_kondisi')` tanpa saringan
     * organisasi, dan yang terbit bukan error melainkan nomor sertifikat &
     * ketertelusuran milik lab lain di lembar lab ini.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    protected function tautkanStandarTercetak(array $bentuk, ?Equipment $equipment): array
    {
        $master = $this->masterStandarTertaut($equipment);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            $bentuk['bagian'][$i]['baris'] = array_map(
                function (array $baris) use ($master): array {
                    $cocok = $this->cocokkanStandar($master, $baris['cocok']);

                    return [
                        'label' => $baris['label'],
                        'standard_id' => $cocok?->id,
                        'serial_number' => $cocok?->serial_number,
                        'no_sertifikat' => $cocok?->no_sertifikat,
                        'tertelusur_ke' => $cocok?->tertelusur_ke,
                        'terdaftar' => $cocok !== null,
                    ];
                },
                $bentuk['bagian'][$i]['baris'],
            );
        }

        return $bentuk;
    }

    /**
     * Isi dropdown "Environmental Meter Used" dengan ketujuh unit yang tercetak
     * di kop master, disaring ke lab pemilik alat lewat [masterThermohygro].
     *
     * Disaring ke daftar TERCETAK, bukan ke semua baris ber-`parameter_kondisi`:
     * di lab ini yang terakhir itu termasuk Thermobarometer Lutron — unit
     * TEKANAN yang tidak pernah muncul di kertas kelompok Putaran. Membiarkannya
     * lolos berarti teknisi bisa memilih barometer sebagai sumber koreksi suhu
     * & kelembapan ruangan.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    protected function isiPilihanThermohygro(array $bentuk, ?Equipment $equipment = null): array
    {
        $master = $this->masterThermohygro($equipment)->pluck('id', 'nama');

        $pilihan = [];

        foreach (self::THERMOHYGRO_TERCETAK as $label) {
            $id = $master[$label] ?? null;

            if ($id === null) {
                continue;
            }

            $pilihan[] = ['nilai' => (string) $id, 'label' => $label, 'grup' => 'Thermohygro lab'];
        }

        foreach ($bentuk['bagian'] as $i => $bagian) {
            foreach ($bagian['field'] ?? [] as $j => $field) {
                if (($field['kode'] ?? null) === 'thermohygro_standard_id') {
                    $bentuk['bagian'][$i]['field'][$j]['pilihan'] = $pilihan;
                }
            }
        }

        return $bentuk;
    }

    /** @return array<string, mixed> */
    protected function bagianIdentitas(): array
    {
        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Identitas Alat dan Data Customer',
            'field' => [
                // WAJIB — tombol kirim di HP menahan sesi yang alatnya belum
                // dipilih. Profil yang lupa memasang field ini menghasilkan
                // lembar yang bisa diisi penuh lalu tidak bisa dikirim.
                $this->field('equipment_id', 'Nama Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Nama Alat', 'teks', sumber: 'otomatis'),
                $this->field('alat_merk', 'Merk', 'teks'),
                $this->field('alat_model', 'Type', 'teks'),
                $this->field('alat_serial_number', 'No. Seri', 'teks'),
                $this->field('spesifikasi_alat.rentang_ukur', 'Rentang Ukur', 'angka', satuan: self::SATUAN),
                // Kapasitas tinggal di `spesifikasi_alat`, BUKAN kolom
                // `equipments` — tabel itu cuma punya `range_min`/`range_maks`.
                $this->field('spesifikasi_alat.kapasitas', 'Kapasitas Max.', 'angka', satuan: self::SATUAN),
                $this->field('spesifikasi_alat.resolusi', 'Resolusi Alat', 'angka', satuan: self::SATUAN),
                $this->field('tanggal_terima', 'Tgl. Diterima', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Tgl. Kalibrasi', 'tanggal'),
                $this->field('suhu_awal', 'Suhu Ruangan — awal', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Suhu Ruangan — akhir', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Kelembapan — awal', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Kelembapan — akhir', 'angka', satuan: '%RH'),
                $this->field('lokasi', 'Lokasi Kalibrasi', 'pilihan', pilihan: [
                    ['nilai' => 'lab', 'label' => 'Inlab'],
                    ['nilai' => 'onsite', 'label' => 'Insitu'],
                ]),
                // Dua kotak lokasi yang saling meniadakan. Tanpa `tampil_kalau`,
                // dropdown Ruangan tetap menyimpan pilihan lama walau sedang
                // Insitu, dan nilai itu ikut terkirim — sertifikat Insitu
                // mencetak nama ruang lab yang tidak pernah didatangi.
                $this->field(
                    'room_id', 'Ruangan (Inlab)', 'pilihan',
                    sumber: 'master_ruangan', tampilKalau: self::TAMPIL_KALAU_INLAB,
                ),
                $this->field(
                    'lokasi_nama', 'Nama Tempat (Insitu)', 'teks',
                    tampilKalau: self::TAMPIL_KALAU_INSITU,
                ),
                $this->field('thermohygro_standard_id', 'Environmental Meter Used', 'pilihan', sumber: 'master_thermohygro'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianPemilik(): array
    {
        return [
            'kode' => 'pemilik',
            'halaman' => 1,
            'judul' => 'Data Customer',
            'field' => [
                $this->field('pemilik_nama', 'Nama Customer', 'teks'),
                $this->field('pemilik_alamat', 'Alamat Customer', 'teks_panjang'),
                $this->field('nomor_order', 'Order Number', 'teks'),
            ],
        ];
    }

    /**
     * Blok `Standard Used` — satu baris tercetak, keping yang sama untuk kedua
     * alat rpm.
     *
     * Barisnya ditulis di sini (bukan diambil dari dropdown master) karena
     * begitulah kertasnya: nama, merk, dan serial kalibratornya sudah TERCETAK,
     * dan teknisi cuma mencentang bahwa dia dipakai. `standard_id`-nya
     * ditautkan [tautkanStandarTercetak] ke master milik lab pemilik alat.
     *
     * @return array<string, mixed>
     */
    protected function bagianStandard(): array
    {
        return [
            'kode' => 'usage_check',
            'halaman' => 1,
            'judul' => 'Standard Used',
            'baris' => self::STANDARD_TERCETAK,
            'field' => [
                $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianDataKalibrasi(): array
    {
        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => 'Data Hasil Kalibrasi',
            'field' => [],
            'tabel' => [
                [
                    // `tahap` itu enum `raw_measurements` (sebelum/sesudah
                    // adjustment), BUKAN identitas tabel. Lembar ini cuma punya
                    // satu tabel dan tidak mengenal adjustment, jadi semua
                    // pembacaannya `sesudah_adjustment` — sama seperti sembilan
                    // lembar lain yang alatnya tidak bisa disetel ulang.
                    'tahap' => 'sesudah_adjustment',
                    'grup' => 'pembacaan_standar',
                    'judul' => 'Pembacaan Tachometer Standar',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Set Point',
                    'judul_pengulangan' => 'Pembacaan Standar (rpm)',
                    'titik_bisa_diubah' => true,
                    'baris' => array_map(
                        static fn (int $n): array => [
                            'nomor' => $n,
                            'titik_ukur' => self::TITIK_SARAN[$n - 1] ?? null,
                            'label' => 'Set point '.$n,
                            'satuan' => self::SATUAN,
                        ],
                        range(1, self::BARIS_KERTAS * PutaranCalculator::TITIK_PER_BLOK),
                    ),
                    'kolom' => [
                        ['kode' => 'pembacaan', 'label' => self::SATUAN, 'tipe' => 'angka', 'satuan' => self::SATUAN],
                    ],
                    'pengulangan' => range(1, self::PENGULANGAN),
                    'catatan' => 'Set point diisi sesuai penunjukan alat pelanggan; kelima kolomnya '
                        .'pembacaan tachometer standar pada putaran yang sama.',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianPenutup(): array
    {
        return [
            'kode' => 'penutup',
            'halaman' => 1,
            'judul' => 'Catatan & Tanda Tangan',
            'field' => [
                $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                $this->field('teknisi.nama', 'Dikalibrasi Oleh', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Diperiksa Oleh', 'teks', sumber: 'otomatis'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function field(
        string $kode,
        string $label,
        string $tipe,
        ?string $sumber = null,
        ?string $satuan = null,
        array $pilihan = [],
        bool $hanyaAdmin = false,
        ?array $tampilKalau = null,
    ): array {
        return [
            'kode' => $kode,
            'label' => $label,
            'tipe' => $tipe,
            'wajib' => false,
            'sumber' => $sumber,
            'satuan' => $satuan,
            'pilihan' => $pilihan,
            'hanya_admin' => $hanyaAdmin,
            'tampil_kalau' => $tampilKalau,
        ];
    }
}
