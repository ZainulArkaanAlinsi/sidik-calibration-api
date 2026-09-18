<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\HydrometerCalculator;
use App\Services\Calibration\TabelStandarHydrometer;
use App\Support\HydrometerMentah;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Profil **Hydrometer** — lampiran akreditasi LK-285-IDN baris no. **25**,
 * metode `SIDIK-IK-CAL-0525_Rev.3`, Lab. Volumetrik, besaran densitas.
 *
 * Rantai hitungnya ada di [HydrometerCalculator] dan sudah diadu ke dua
 * workbook master di `tests/Unit/HydrometerMasterTest.php`. Kelas ini cuma
 * menyambungkan: bentuk lembar kerja, jalur `hitungPerGrup`, dan aturan cetak
 * sertifikat.
 *
 * ## Kenapa lembarnya tidak sebangun dengan satu pun lembar yang sudah ada
 *
 * Dua puluh lembar sebelumnya berbentuk "standar lawan pembacaan": teknisi
 * mengetik apa yang terbaca di alat, server membandingkannya dengan nilai
 * standar. Hydrometer tidak punya bentuk itu. Yang dipungut kertas
 * `SIDIK-FM-CAL-0533_Rev.2` adalah **massa hasil timbang (gram)** dan **suhu
 * air (°C)** — tiga ulangan masing-masing — dan densitas yang dicetak
 * sertifikat tidak pernah diketik siapa pun; dia hasil metode Cuckow.
 *
 * Akibatnya dua tabel per titik skala yang harus SINKRON: kolom ke-n tabel
 * massa dan kolom ke-n tabel suhu merujuk titik skala yang sama. Tanpa cabang
 * sendiri di `lib/services/lembar_kerja_service.dart`, profil ini jatuh ke
 * bentuk bawaan dan menampilkan lembar yang salah **tanpa satu pun error** —
 * pola yang sudah berulang di repo ini (TIDS, Timbangan, Micrometer).
 *
 * ## Titik skala DIKETIK teknisi, dan slotnya dikirim penuh
 *
 * Beda dari Micrometer & Dial Indicator yang nominalnya dipatok kertas: kertas
 * hydrometer membiarkan kolom `Point of Calibration` kosong untuk diisi, dan
 * kedua master contoh memakai tiga titik dari lima kolom yang tersedia.
 *
 * Karena itu tiap barisnya `titik_ukur: null` — yang di HP membuka kotak
 * `Point of Calibration`. Konsekuensinya lembar ini TIDAK bisa memakai
 * `titik_bisa_diubah`: panel `PengaturTitik` cuma dirender kalau semua barisnya
 * `titikDitentukan`, dan `titik_ukur: null` justru kebalikannya. Jadi kelima
 * slotnya dikirim sejak awal ([TITIK_MAKS] baris) dan baris yang tidak diisi
 * gugur sendiri sebelum terkirim. Lihat `bagianMeasurement()`.
 *
 * ## Yang kertas Rev.2 TIDAK punya tapi rantai hitung BUTUH
 *
 * Kotak **tekanan udara (hPa)**. Rumus densitas udara
 * (`PERHITUNGAN!J78`) membacanya, dan `INPUT DATA!E25:F25` menyediakannya —
 * tapi kertas Rev.2 cuma mencetak suhu & kelembaban. Di sini kotaknya ADA,
 * memakai kolom `calibration_sessions.tekanan_awal`/`tekanan_akhir` yang sudah
 * bersatuan hPa sejak Gas Detector. Pertanyaan §9
 * `docs/pertanyaan-lab-hydrometer.md`.
 */
class HydrometerProfile extends CalibrationProfile
{
    public const KODE_METODE = 'SIDIK-IK-CAL-0525_Rev.3';

    /** Nomor formulir yang tercetak di kaki kertas lembar kerja. */
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0533_Rev.2';

    /** Nomor formulir sertifikatnya (`SERTIFIKAT!A47`). */
    public const KODE_SERTIFIKAT = 'SIDIK-FM-CAL-2403_Rev.0';

    /** Satuan densitas lembar & sertifikat. */
    public const SATUAN = 'g/ml';

    /** Tepat tiga ulangan massa & tiga ulangan suhu per titik. */
    public const PENGULANGAN = TabelStandarHydrometer::PENGULANGAN;

    /** Lima kolom `Point of Calibration` disediakan master; tiga terisi di kedua contoh. */
    public const TITIK_AWAL = 3;

    public const TITIK_MAKS = 5;

    /**
     * Serapat apa densitas terbit boleh "tidak mengikuti" titik skalanya sebelum
     * dianggap mencurigakan — lihat [peringatanDeretTertukar].
     *
     * Setengah, dan longgarnya disengaja. Diukur dari KEDUA workbook master:
     *
     *     file ringan (0,600-0,650)  rentang titik 0,0400  densitas 0,0407  rasio 1,018
     *     file berat  (1,800-2,000)  rentang titik 0,2000  densitas 0,1975  rasio 0,987
     *     file ringan, deret tertukar                                       rasio 0,011
     *
     * Sesi yang sah duduk di sekitar 1 — memang harus, karena hydrometer
     * membaca skalanya sendiri dan koreksinya kecil dibanding jarak antar
     * tanda. Yang tertukar dua orde besaran di bawahnya. Ambang 0,5 duduk di
     * tengah jurang itu: alat yang melenceng sampai setengah jarak tandanya pun
     * masih lolos, dan itu alat yang sudah rusak parah.
     */
    private const RASIO_RENTANG_MINIMUM = 0.5;

    /**
     * Sejauh apa densitas terbit boleh meleset dari tanda skalanya, sebagai
     * pecahan LEBAR SKALA alat — lihat [peringatanKoreksiTidakMasukAkal].
     *
     * Setengah lebar skala. Kedua master yang sah meleset paling jauh 14,7%
     * (ringan) dan 2,4% (berat); bentuk salah-isi yang paling ringan yang
     * ditemukan review meleset 69%. Ambangnya duduk di jurang itu.
     */
    private const KOREKSI_MAKS_DARI_LEBAR = 0.5;

    /**
     * Empat standar blok `Uncertainty of Calibrator` master (`NILAI U95%` E9:J12),
     * bukan salinan mentah kop kertas Rev.2.
     *
     * Dua barisnya sengaja BEDA dari yang tercetak di kertas:
     *
     *  1. Kertas nulis **"Temp. Kalibrator Victor"**. Victor dicabut lab
     *     24 Mei 2024 (`FORM VALIDASI` TITS rev. 11: "Remove std. Victor / Add
     *     std kalibrator yokogawa") dan tabel koreksinya sudah `#REF!` semua.
     *     Kedua workbook hydrometer sendiri — Sep DAN Nov 2025 — sudah memakai
     *     Yokogawa: `DATABASE!E11:J11` nulis `Temperature Calibrator /
     *     Yokogawa / CA 150 Handy / 23P1005`, dan sertifikatnya mencetak
     *     `Termometer & Sensor Std. / Yokogawa/CA 150 Handy Cal / 23P1005`.
     *     Jadi yang basi barisnya di KERTAS, dan lembar aplikasi mengikuti
     *     workbook. Pertanyaan §12 `docs/pertanyaan-lab-hydrometer.md`
     *     (usul revisi kertas).
     *  2. Neraca yang dipakai **Fujitsu FS-AR210 (INS-N1600555)**, bukan
     *     `Analytical Balance` Mettler Toledo XS204 milik Anak Timbangan.
     *     Nama `Analytical Balance` telanjang mendarat di Mettler — cocok,
     *     terdaftar, dan ALAT YANG SALAH: `U massa aquadest` seluruh budget
     *     lahir dari sertifikat neraca ini.
     *
     * Kuncinya NAMA PERSIS master `standards`, dan itu bukan kerapian: dua
     * baris master lab berbagi seri `23P1005` (sensor RTD & kalibrator yang
     * menempel padanya), jadi pencarian yang menerima serial lebih dulu
     * menautkan barisnya ke dokumen yang salah — lihat
     * [CalibrationProfile::cocokkanStandar].
     *
     * ## Kenapa tanpa `label_cetak`
     *
     * [CalibrationProfile::tautkanStandarTercetak] — yang dipakai profil ini
     * dan dua puluh lainnya — cuma meneruskan `label`; `label_cetak` hidup di
     * salinan PRIVAT milik `ConductivityProfile`. Menuliskannya di sini berarti
     * kunci yang tidak pernah sampai ke HP, dan kunci yang diabaikan diam-diam
     * itu persis kelas cacat yang sudah dua kali digigit lembar ini
     * (`saklar` yang jatuh ke kotak teks, `titik_maks` yang tidak dibaca
     * siapa pun).
     *
     * Jadi yang tampil nama master-nya — dan itu justru yang dibutuhkan
     * teknisi: dia mencentang standar yang BENAR-BENAR dia pakai, bukan nama
     * yang sudah dicabut lab dua tahun lalu. Kalau suatu saat `label_cetak`
     * diangkat ke kelas dasar dan salinan privat Conductivity dicabut, baris
     * di sini tinggal menambahkannya.
     */
    public const STANDARD_TERCETAK = [
        [
            'label' => 'Analytical Balance Fujitsu FS-AR210',
            'cocok' => ['Analytical Balance Fujitsu FS-AR210', 'INS-N1600555'],
        ],
        [
            'label' => 'Digital Caliper Tesa',
            'cocok' => ['Digital Caliper', 'Cal-IP67', 'LPI-0368'],
        ],
        [
            'label' => 'Temperature Calibrator Yokogawa CA 150 Handy Cal',
            'cocok' => ['Temperature Calibrator Yokogawa CA 150 Handy Cal'],
        ],
        [
            'label' => 'PRT Pt-100',
            'cocok' => ['PRT Pt-100', 'SH1/20'],
        ],
    ];

    /** Nama & seri neraca analitik master — dipakai profil DAN seeder. */
    public const NERACA_NAMA = 'Analytical Balance Fujitsu FS-AR210';

    public const NERACA_SERI = 'INS-N1600555';

    /** Ketujuh unit thermohygro `DATABASE!B31:B61` master. */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    private ?HydrometerCalculator $kalk = null;

    public function kode(): string
    {
        return 'hydrometer';
    }

    /** PERSIS nama lampiran LK-285-IDN no. 25. */
    public function namaAlatKemampuan(): string
    {
        return 'Hydrometer';
    }

    /**
     * Kedua master mengeja nama alatnya BEDA di sel yang sama — `Hydrometer`
     * (8 Sep 2025) dan `Hidrometer` (7 Nov 2025). Ejaan Indonesianya ikut
     * didaftarkan, kalau tidak sesi yang diketik begitu jatuh ke [ProfilGenerik]
     * dan menampilkan lembar "standar lawan pembacaan" yang tidak berarti
     * apa-apa di alat ini.
     */
    public function aliasNama(): array
    {
        return ['Hidrometer', 'Hydro Meter', 'Hidro Meter'];
    }

    public function kodeFormula(): string
    {
        return 'gum-hydrometer';
    }

    public function besaran(): string
    {
        return 'densitas';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function dalamLingkupAkreditasi(): bool
    {
        return true;
    }

    /**
     * Lampiran memuat Hydrometer di kelompok Densitas no. 32, tapi cuma pada
     * DUA pita: 0,60-1,00 dan 1,10-1,70 g/mL. Sesi yang titiknya di luar itu
     * tetap boleh terbit — U95-nya telanjang, murni hasil budget — tapi TIDAK
     * boleh membawa klaim akreditasi.
     *
     * Bukan kasus teoretis: hydrometer contoh rentang berat (1,800-2,000 g/mL)
     * ada di atas pita tertinggi, dan sertifikatnya sudah terbit 7 Nov 2025.
     * Preseden perlakuannya Jangka Sorong (caliper 600 mm di luar pita
     * 0-300 mm) dan Height Gauge.
     */
    public function dalamLingkupAkreditasiSesi(CalibrationSession $sesi): bool
    {
        $alat = $sesi->equipment;

        if ($alat === null) {
            return false;
        }

        $pita = $this->pitaKemampuan($alat);
        $titik = $sesi->uncertaintyCalculations;

        if ($pita->isEmpty() || $titik->isEmpty()) {
            return false;
        }

        foreach ($titik as $t) {
            if ($this->cmcTitik($pita, (float) $t->titik_ukur) === null) {
                return false;
            }
        }

        return true;
    }

    /** Kolom `UUT` sertifikat master berjudul `Actual Value` (`SERTIFIKAT!J15`). */
    public function judulKolomUut(): string
    {
        return 'Actual Value';
    }

    /** Kolom `Standard` sertifikat master berjudul `Nominal Value` (`SERTIFIKAT!E15`). */
    public function judulKolomStandar(): string
    {
        return 'Nominal Value';
    }

    /** Nominal/aktual/koreksi 3 desimal (`SERTIFIKAT!E17:O19`). */
    public function desimalSertifikat(): ?int
    {
        return 3;
    }

    /** `U95%` 5 desimal (`SERTIFIKAT!S17:S19`). */
    public function desimalU95(): ?int
    {
        return 5;
    }

    /**
     * `k` dicetak BULAT (`SERTIFIKAT!U23` berformat 0 desimal, isinya
     * 1,9791241). Pembulatan cuma di tampilan — `U = k·uc` tetap memakai nilai
     * penuh, dan memakai k=2 di perhitungan menggeser U sekitar 1 %.
     */
    public function desimalFaktorCakupan(): ?int
    {
        return 0;
    }

    /** `U95%` beda tiap titik skala — satu blok budget per titik (`NILAI U95%`). */
    public function u95PerTitik(): bool
    {
        return true;
    }

    /**
     * Hydrometer TIDAK divonis PASS/FAIL.
     *
     * Tidak ada satu pun sel di kedua workbook master yang membandingkan hasil
     * dengan batas keberterimaan, dan sertifikatnya berhenti di `Correction` +
     * `U95%` lalu langsung ke `Standard Used`. Preseden perlakuannya
     * Conductivity Meter.
     *
     * Dibiarkan `true` (bawaan), `equipments.toleransi` jadi kolom wajib buat
     * alat yang tidak punya isi yang benar — dan yang terjadi berikutnya bukan
     * kolomnya dikosongkan, tapi teknisi mengarang angkanya.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * Pembacaan lembar ini TIDAK boleh diadu ke `equipments.resolusi`.
     *
     * Pemeriksa `pembacaan_bukan_kelipatan_resolusi` berdiri di atas premis
     * bahwa angka yang dicatat dibaca di LAYAR alat yang sedang dikalibrasi.
     * Di sini premisnya tidak berlaku sama sekali: yang tercatat di
     * `raw_measurements` adalah **massa hasil timbang (gram)** dan **suhu air
     * (°C)** — dua besaran yang tidak satu pun terbaca di skala hydrometer.
     *
     * Resolusi alatnya 0,0005 g/ml. Diadukan ke situ, massa 21,2727 g bukan
     * kelipatan 0,0005 dan SETIAP sesi hydrometer memunculkan peringatan
     * "layarnya nggak mungkin nunjukin angka itu" untuk angka yang benar —
     * persis cara peringatan berhenti dibaca orang.
     */
    public function pembacaanDiadukeResolusi(): bool
    {
        return false;
    }

    public function resolusiTitik(float $titikUkur): ?float
    {
        return null;
    }

    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    /**
     * Lembar ini TIDAK lewat jalur pindai foto, cloud maupun lokal.
     *
     * Kedua penanda `kolom_suhu`/`standar_di_baris` cuma bisa menggambarkan
     * lembar "titik ukur × Repeat". Kertas hydrometer DUA tabel yang harus
     * sinkron kolom-per-kolom (massa dan suhu di titik skala yang sama), plus
     * blok Pre Condition berisi field skalar dan tiga ukuran diameter stem.
     * Dipaksa lewat bentuk pH, model diminta membaca satu tabel yang tidak
     * pernah ada di kertasnya — dan yang balik ke teknisi angka ngawur yang
     * kelihatan wajar, di alat yang massanya berbeda 4 desimal.
     */
    public function bentukPindaiFoto(): array
    {
        return ['kolom_suhu' => false, 'standar_di_baris' => false, 'didukung' => false, 'lokal' => false];
    }

    /**
     * Jalur simpan sendiri — lihat [CalibrationProfile::butuhBlokHydrometer].
     *
     * Satu `measurements[i]` membawa DUA deret (`massa` & `suhu`) plus satu
     * `titik_ukur`. Lewat cabang mana pun yang sudah ada, salah satu deret
     * tidak punya tempat dan hilang tanpa error.
     */
    public function butuhBlokHydrometer(): bool
    {
        return true;
    }

    /** Budget lahir di [hitungPerGrup] — per titik, tapi butuh konteks SELURUH sesi. */
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

    /** @return array<string, mixed> */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'kode_metode' => self::KODE_METODE,
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => 'Calibration Worksheet - Hydrometer',
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Yang diisi BUKAN pembacaan densitas: tiap titik skala diisi tiga kali '
                .'timbang (gram, 4 desimal) dan tiga kali baca suhu air (°C, 1 desimal). Densitasnya '
                .'dihitung server dengan metode Cuckow. Nyalakan "Pakai beban tambahan" hanya kalau '
                .'hydrometer-nya memang butuh sinker — rumusnya beda, dan kosong karena tidak perlu '
                .'harus bisa dibedakan dari kosong karena lupa. Tekanan udara (hPa) wajib: tanpa itu '
                .'densitas udara tidak bisa dihitung.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master Olah Data Hydrometer 0.600-0.650 & 1.800-2.000 (.xlsm)',
                'catatan' => 'Sebelas komponen PER TITIK skala dalam g/ml, k dari t-Student (v_eff '
                    .'dipotong ke bawah), lantai CMC dari pita lampiran akreditasi yang memuat titiknya '
                    .'(0,60-1,00 → 0,00051; 1,10-1,70 → 0,00070). Titik di luar kedua pita tetap terbit '
                    .'dengan U95 telanjang, tapi sesinya tidak membawa klaim akreditasi. Sesi yang '
                    .'ulangannya bukan tiga, diameter stem-nya bukan tiga ukuran, atau tekanan udaranya '
                    .'kosong TIDAK diterbitkan.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianPreCondition(),
                $this->bagianMeasurement(),
                $this->bagianPenutup(),
            ],
        ];

        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($bentuk, $equipment),
            $equipment,
        );
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

        // Blok sesi DISAPU, bukan diambil dari `$titik[0]`: jalur hitung ulang
        // mengelompokkan per `titik_ke` lewat `groupBy` dan urutannya tidak
        // dijamin. Bertumpu pada elemen pertama berarti sesi yang titik
        // pertamanya kebetulan tersaring pulang tanpa blok — diam-diam, dengan
        // seluruh titiknya "belum dihitung".
        $konteksSesi = [];

        foreach ($titik as $t) {
            if (isset($t['konteks']['spesifikasi_alat'])) {
                $konteksSesi = $t['konteks'];

                break;
            }
        }

        $blok = HydrometerMentah::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi Hydrometer belum punya blok Pre Condition di `spesifikasi_alat.'
                        .HydrometerMentah::KUNCI_SESI.'` — Ma, yx, tr, beban tambahan, dan ketiga '
                        .'ukuran diameter stem lahir di situ, bukan per titik.',
                ], $titik),
            ];
        }

        $blok += [
            'suhu_awal' => $konteksSesi['suhu_awal'] ?? null,
            'suhu_akhir' => $konteksSesi['suhu_akhir'] ?? null,
            'kelembaban_awal' => $konteksSesi['kelembaban_awal'] ?? null,
            'kelembaban_akhir' => $konteksSesi['kelembaban_akhir'] ?? null,
            'tekanan_awal' => $konteksSesi['tekanan_awal'] ?? null,
            'tekanan_akhir' => $konteksSesi['tekanan_akhir'] ?? null,
        ];

        $satuan = (string) ($blok['satuan_densitas'] ?? self::SATUAN);
        $masukan = [];
        $belumDihitung = [];
        $standarPerTitik = [];

        foreach ($titik as $t) {
            $k = $t['konteks'] ?? [];
            $massa = array_map('floatval', $k[HydrometerMentah::KONTEKS_MASSA] ?? []);
            $suhu = array_map('floatval', $k[HydrometerMentah::KONTEKS_SUHU] ?? []);

            // Sesi yang baris mentahnya belum ber-`peran_sensor` DITOLAK dengan
            // alasan yang kebaca, bukan diam-diam dihitung dari `pembacaan`
            // datar. Massa (21 g) dan suhu (20,6 °C) ber-orde mirip dan tidak
            // ada satu pun yang bisa membedakannya begitu perannya hilang —
            // densitas yang lahir dari deret tertukar tetap terbit, cuma salah.
            if ($massa === [] && $suhu === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d nggak punya baris ber-peran `%s`/`%s`. Lembar Hydrometer nyimpen deret '
                        .'massa dan deret suhu terpisah — deret datar nggak bisa dipakai.',
                        $t['titik_ke'],
                        HydrometerMentah::PERAN_MASSA,
                        HydrometerMentah::PERAN_SUHU,
                    ),
                ];

                continue;
            }

            $standarPerTitik[(int) $t['titik_ke']] = $t['standard'] ?? null;
            $masukan[] = [
                'titik_ke' => (int) $t['titik_ke'],
                // Titik skala dikonversi ke g/ml DI SINI, bukan di ujung masuk —
                // jalur draft tidak idempoten, lihat [HydrometerMentah::keGramPerMl].
                'titik_ukur' => HydrometerMentah::keGramPerMl($t['titik_ukur'], $satuan),
                'massa' => $massa,
                'suhu' => $suhu,
            ];
        }

        // `resolusi` itu besaran DENSITAS, sama seperti titik skala — jadi
        // satuannya ikut dropdown yang sama, dan komentar di kotak `Satuan
        // Densitas` sudah menulisnya ("mengubah arti titik skala DAN
        // resolusi"). Yang dikonversi cuma titik skalanya, dan resolusinya
        // masuk budget apa adanya.
        //
        // Akibatnya cuma kelihatan di sesi ber-`kg/m3`: teknisi mengetik 0,5
        // (kg/m3, setara 0,0005 g/ml) dan komponen `Resolution of Hydrometer`
        // menerimanya sebagai 0,5 **g/ml** — seribu kali terlalu besar, di
        // komponen yang labelnya sendiri sudah bertuliskan `g/ml`. Ketidakpastian
        // terbitnya membengkak tanpa satu pun error.
        //
        // Dikonversi DI SINI, sejajar dengan titik skalanya, bukan di
        // `HydrometerMentah::blokSesi()`: alasannya sama dengan yang sudah
        // ditulis di `keGramPerMl()` — jalur draft tidak idempoten, dan
        // mengalikan di ujung masuk membuat angkanya berlipat tiap kali teknisi
        // menyimpan ulang lembar yang sama.
        $blok['resolusi'] = HydrometerMentah::keGramPerMl($blok['resolusi'] ?? 0.0, $satuan);

        $hasil = $this->kalk()->hitungSesi($masukan, $blok);

        // Budget yang tidak utuh TIDAK melahirkan satu pun baris hitungan.
        // Alasannya sama dengan Micrometer: sertifikat ber-`± 0,000` itu klaim
        // pengukuran SEMPURNA, dan peringatan sesi tidak menahannya — admin
        // boleh melewatinya lewat `abaikan_peringatan`. Yang menahan harus
        // ketiadaan barisnya.
        if (! $hasil['boleh_terbit']) {
            foreach ($hasil['ditolak'] as $d) {
                $belumDihitung[] = $d;
            }

            usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

            return ['hitungan' => [], 'belum_dihitung' => $belumDihitung];
        }

        $kemampuan = $this->kemampuanSesi($equipment);
        $pita = $this->pitaKemampuan($equipment);
        $sekarang = Carbon::now();
        $hitungan = [];

        foreach ($hasil['titik'] as $h) {
            // Lantai CMC dari pita lampiran akreditasi yang MEMUAT titik ini —
            // bukan baris pertama yang kebetulan cocok namanya. Hydrometer
            // punya DUA pita (0,60-1,00 → 0,00051 dan 1,10-1,70 → 0,00070), dan
            // `kemampuanSesi()` memulangkan yang pertama apa pun titiknya.
            // Dipakai begitu, hydrometer 0,600-0,650 dilantai 0,0007 — persis
            // kekeliruan yang dilakukan masternya sendiri.
            $cmc = $this->cmcTitik($pita, (float) $h['titik_ukur']);
            $u95 = max((float) $h['ketidakpastian_diperluas'], $cmc ?? 0.0);

            $hitungan[] = [
                'standard_id' => ($standarPerTitik[$h['titik_ke']] ?? null)?->id,
                'titik_ke' => $h['titik_ke'],
                'titik_ukur' => $h['titik_ukur'],
                // Kolom `Actual Value` sertifikat — densitas hasil Cuckow,
                // bukan angka yang diketik teknisi.
                'rata_rata' => $h['densitas'],
                'error' => -$h['koreksi'],
                'koreksi' => $h['koreksi'],
                'standar_deviasi' => $h['simpangan_baku'],
                'jumlah_pengulangan' => $h['jumlah_pengulangan'],
                'type_a' => $h['type_a'],
                'type_b_components' => $this->jejakAudit($h, $hasil['praolah'], $u95, $cmc),
                'type_b' => $h['type_b'],
                'ketidakpastian_gabungan' => $h['ketidakpastian_gabungan'],
                'faktor_cakupan_k' => $h['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $h['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $u95,
                'toleransi' => null,
                'keputusan' => null,
                'metode' => $kemampuan?->metode ?? self::KODE_METODE,
                'calculated_at' => $sekarang,
            ];
        }

        foreach ($hasil['ditolak'] as $d) {
            $belumDihitung[] = $d;
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Sesi tanpa tekanan udara / blok Pre Condition tidak boleh lewat diam-diam.
     *
     * Peringatan ini BUKAN yang menahan sesinya — `CalibrationValidator`
     * membungkusnya jadi temuan tingkat PERINGATAN yang boleh dilewati admin.
     * Yang benar-benar menahan: [hitungPerGrup] tidak melahirkan satu pun baris
     * hitungan. Pesan ini tugasnya menjelaskan KENAPA sesinya kosong.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $peringatan = [];
        $blok = HydrometerMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null) {
            return [[
                'kode' => 'hydrometer_pre_condition_kosong',
                'pesan' => 'Blok Pre Condition (Ma, yx, tr, beban tambahan, diameter stem) belum diisi — '
                    .'sesi ini tidak bisa menerbitkan densitas.',
            ]];
        }

        if ($sesi->tekanan_awal === null || $sesi->tekanan_akhir === null) {
            $peringatan[] = [
                'kode' => 'hydrometer_tekanan_kosong',
                'pesan' => 'Tekanan udara ruangan (hPa) belum lengkap. Densitas udara dihitung dari situ, '
                    .'jadi tanpa tekanan tidak ada densitas yang bisa diterbitkan.',
            ];
        }

        if (count($blok['diameter_stem']) !== TabelStandarHydrometer::UKUR_DIAMETER_STEM) {
            $peringatan[] = [
                'kode' => 'hydrometer_diameter_stem_tidak_tiga',
                'pesan' => sprintf(
                    'Diameter stem harus diukur tepat %d kali — selisih terbesar-terkecil dipakai sebagai '
                    .'komponen ketidakpastian, jadi kurang dari itu membuat komponennya tidak sah.',
                    TabelStandarHydrometer::UKUR_DIAMETER_STEM,
                ),
            ];
        }

        if ($blok['pakai_beban_tambahan'] && ($blok['beban_tambahan'] === null || $blok['beban_tambahan'] <= 0)) {
            $peringatan[] = [
                'kode' => 'hydrometer_beban_tambahan_kosong',
                'pesan' => 'Varian beban tambahan dipilih tapi massa bebannya (Sl) belum diisi.',
            ];
        }

        if ($blok['suhu_acuan_alat'] !== null
            && ! in_array($blok['suhu_acuan_alat'], TabelStandarHydrometer::SUHU_ACUAN_SAH, true)) {
            $peringatan[] = [
                'kode' => 'hydrometer_suhu_acuan_tidak_lazim',
                'pesan' => sprintf(
                    'Suhu acuan alat (tr) %s °C di luar nilai yang lazim tertera di hydrometer (%s °C).',
                    $blok['suhu_acuan_alat'],
                    implode(' / ', array_map(
                        static fn (float $s): string => rtrim(rtrim(number_format($s, 1, ',', ''), '0'), ','),
                        TabelStandarHydrometer::SUHU_ACUAN_SAH,
                    )),
                ),
            ];
        }

        foreach ($this->peringatanDeretTertukar($sesi) as $p) {
            $peringatan[] = $p;
        }

        foreach ($this->peringatanKoreksiTidakMasukAkal($sesi) as $p) {
            $peringatan[] = $p;
        }

        // Kotak Pre Condition yang dibiarkan KOSONG, satu per satu.
        //
        // `HydrometerMentah::blokSesi()` menjatuhkan kotak kosong ke `0.0`, dan
        // nol itu angka yang sah buat rumusnya — jadi sesinya terbit, cuma
        // dengan angka yang salah. Diukur dari kedua master:
        //
        //             kotak kosong  geser densitas   U95 terbit
        //   ringan    yx            0,0005992        -33%
        //   berat     yx            0,0048816        -29%
        //   ringan    resolusi      0                -19%
        //   berat     resolusi      0                -24%
        //
        // Yang paling mahal `yx`: geseran densitasnya (0,0006) LEBIH BESAR
        // daripada lantai CMC-nya sendiri (0,00051), jadi satu kotak yang lupa
        // diisi menggeser angka yang tercetak lebih jauh daripada seluruh
        // ketidakpastian yang diklaim dokumen itu — sambil membuat
        // ketidakpastiannya tampak sepertiga lebih kecil.
        //
        // `Ma` tidak ikut: kosong, kalkulatornya sudah menahan seluruh sesi
        // (nol titik terbit), jadi peringatan di sini cuma pengulangan.
        foreach ([
            'tegangan_permukaan' => 'Tegangan permukaan cairan (yx)',
            'resolusi' => 'Resolusi skala hydrometer',
        ] as $kunci => $sebutan) {
            if ((float) ($blok[$kunci] ?? 0.0) > 0.0) {
                continue;
            }

            $peringatan[] = [
                'kode' => 'hydrometer_'.$kunci.'_kosong',
                'pesan' => $sebutan.' belum diisi, dan kotak kosong dibaca sebagai NOL — '
                    .($kunci === 'tegangan_permukaan'
                        ? 'densitas yang terbit bergeser lebih jauh daripada lantai ketidakpastiannya sendiri, '
                        : '')
                    .'sementara ketidakpastian yang tercetak justru jadi lebih kecil daripada yang sebenarnya. '
                    .'Isi dulu sebelum sesi ini disetujui.',
            ];
        }

        return $peringatan;
    }

    /**
     * Densitas terbit tidak boleh jauh dari tanda skala yang dibaca.
     *
     * ## Lubang yang ditutup gerbang ini
     *
     * Hydrometer alat pertama yang pembacaan mentahnya (gram, °C) BUKAN besaran
     * alatnya (g/ml), jadi `CalibrationValidator` sengaja melewatkan kedua deret
     * itu dari penjaga `pembacaan_di_luar_rentang` — kalau tidak, satu sesi yang
     * sempurna memuntahkan 18 peringatan palsu.
     *
     * Harga dari pengecualian itu: alat ini kehilangan SATU-SATUNYA penjaga
     * "koma kegeser" yang dipunyai tiga puluh dua alat lain, dan tidak ada
     * penggantinya. Diukur: satu koma kegeser di kolom Weight titik pertama
     * (21,2727 → 2,12727 g) menerbitkan densitas **0,468497** g/ml untuk tanda
     * skala 0,610 — angka yang alatnya sendiri tidak punya tandanya, karena
     * skalanya cuma 0,600-0,650. Sesinya lolos dengan `valid = true`,
     * `boleh_terbit = true`, nol temuan, dan `U95` tetap 0,00051 (lantai CMC).
     * Sertifikat terakreditasi terbit dengan densitas yang mustahil.
     *
     * ## Kenapa diadu ke LEBAR SKALA, bukan ke batas rentang telanjang
     *
     * Densitas terbit memang boleh sedikit di luar `range_min..range_max` —
     * itu justru yang diukur kalibrasi. Yang tidak masuk akal bukan "di luar
     * rentang" melainkan "meleset sejauh ini dari tanda yang dibaca": hydrometer
     * membaca skalanya sendiri, jadi koreksinya kecil dibanding lebar skalanya.
     * Diukur dari kedua master:
     *
     *     ringan (0,600-0,650, lebar 0,050)  koreksi terbesar 14,7% lebar
     *     berat  (1,800-2,000, lebar 0,200)  koreksi terbesar  2,4% lebar
     *
     * Dan ketiga bentuk salah-isi yang ditemukan review:
     *
     *     koma kegeser di kolom Weight              283% lebar
     *     deret dipetakan TERBALIK ke titiknya        69% lebar
     *     deret tertukar + suhu air menyebar 3 °C    135% lebar
     *
     * Ambang [KOREKSI_MAKS_DARI_LEBAR] = 0,5 duduk di jurang antara 14,7% dan
     * 69% — 4,7 kali di atas kasus sah terburuk, dan masih di bawah bentuk
     * salah-isi yang paling ringan.
     *
     * ## Hubungannya dengan [peringatanDeretTertukar]
     *
     * Keduanya dipertahankan karena mati di keadaan yang BERBEDA. Gerbang ini
     * butuh `range_min`/`range_max` terisi — `EquipmentFactory` saja
     * meninggalkannya null, jadi di alat yang rentangnya belum diisi dia diam
     * total. Gerbang rasio tidak butuh rentang sama sekali, tapi cuma mengukur
     * SEBARAN sehingga deret terbalik (sebarannya persis sama) lolos. Yang satu
     * menambal lubang yang satunya.
     *
     * @return list<array<string, mixed>>
     */
    private function peringatanKoreksiTidakMasukAkal(CalibrationSession $sesi): array
    {
        $alat = $sesi->equipment;

        if ($alat === null) {
            return [];
        }

        // Rentang alat KOSONG bukan "tidak berlaku" — itu keadaan paling
        // telanjang di seluruh lembar ini, dan harus dibilang.
        //
        // `equipments.range_min`/`range_max` nullable di mana-mana
        // (`EquipmentRequest`, form Filament), jadi hydrometer bisa terdaftar
        // tanpa rentang lewat jalur normal. Waktu itu terjadi, alat ini
        // kehilangan KEDUA penjaganya sekaligus: penjaga pembacaan mentah
        // `pembacaan_di_luar_rentang` sengaja dilewati (massa & suhu bukan
        // besaran alatnya), dan gerbang koreksi di bawah tidak punya pembanding.
        //
        // Diukur: dengan rentang kosong, koma kegeser di kolom Weight
        // menerbitkan densitas 0,468497 g/ml dan sesinya lolos `valid = true`
        // dengan NOL temuan. Dengan rentang 0,600-0,650 terisi, gerbang di bawah
        // menangkapnya di 283% lebar skala.
        //
        // Jadi yang dilaporkan bukan cuma "isi rentangnya", tapi bahwa
        // pemeriksaannya sedang MATI.
        if ($alat->range_min === null || $alat->range_max === null) {
            return [[
                'kode' => 'hydrometer_rentang_alat_kosong',
                'pesan' => 'Rentang ukur alat (range min/max) belum diisi di master alat, jadi '
                    .'pemeriksaan "densitas terbit masuk akal atau tidak" TIDAK BISA jalan buat sesi '
                    .'ini — dan lembar Hydrometer tidak punya penjaga pengganti, karena yang diketik '
                    .'teknisi (gram & °C) beda besaran dari yang diterbitkan (g/ml). Isi rentang '
                    .'alatnya dulu, lalu hitung ulang sesi ini.',
            ]];
        }

        $lebar = abs((float) $alat->range_max - (float) $alat->range_min);

        // Alat yang rentangnya satu titik tidak punya lebar buat diadu.
        if ($lebar <= 0.0) {
            return [];
        }

        $batas = $lebar * self::KOREKSI_MAKS_DARI_LEBAR;
        $temuan = [];

        foreach ($sesi->uncertaintyCalculations()->orderBy('titik_ke')->get() as $h) {
            $titik = (float) $h->titik_ukur;
            $densitas = (float) $h->rata_rata;
            $koreksi = $densitas - $titik;

            if (abs($koreksi) <= $batas) {
                continue;
            }

            $temuan[] = [
                'kode' => 'hydrometer_koreksi_tidak_masuk_akal',
                'pesan' => sprintf(
                    'Tanda skala %s: densitas terbit %s g/ml, meleset %s g/ml (%.0f%% dari lebar skala '
                    .'alat %s-%s). Hydrometer membaca skalanya sendiri, jadi koreksi sebesar ini berarti '
                    .'alatnya rusak berat ATAU ada angka yang salah masuk — yang paling sering koma '
                    .'kegeser di kolom Weight, atau deret Weight & Temperature tertukar. Periksa blok '
                    .'Measurement sebelum menyetujui.',
                    rtrim(rtrim(number_format($titik, 4, ',', ''), '0'), ','),
                    rtrim(rtrim(number_format($densitas, 6, ',', ''), '0'), ','),
                    rtrim(rtrim(number_format($koreksi, 6, ',', ''), '0'), ','),
                    100 * abs($koreksi) / $lebar,
                    rtrim(rtrim(number_format((float) $alat->range_min, 4, ',', ''), '0'), ','),
                    rtrim(rtrim(number_format((float) $alat->range_max, 4, ',', ''), '0'), ','),
                ),
            ];
        }

        return $temuan;
    }

    /**
     * Densitas terbit harus MENGIKUTI titik skala yang diketik teknisi.
     *
     * ## Kegagalan yang dijaga: deret massa & suhu tertukar
     *
     * Massa hasil timbang (21,27 g) dan suhu air (20,6 °C) ber-orde mirip, dan
     * begitu keduanya tertukar tidak ada satu pun gerbang yang menahannya:
     * keduanya angka positif yang masuk akal, ulangannya tetap tiga, dan blok
     * Pre Condition-nya tetap utuh. Diukur dengan sesi contoh master, yang
     * terbit dari deret tertukar:
     *
     *   titik 0,610 → densitas 0,597704   (seharusnya 0,603910)
     *   titik 0,625 → densitas 0,597484   (seharusnya 0,617625)
     *   titik 0,650 → densitas 0,597935   (seharusnya 0,644633)
     *
     * Sertifikatnya **terbit** (201), angkanya kelihatan wajar, dan `U95`-nya
     * 0,007041 — 13,8 kali lebih besar daripada 0,00051 yang benar.
     *
     * ## Kenapa yang diperiksa RENTANGNYA, bukan selisih per titik
     *
     * Yang paling kentara dari ketiga angka di atas bukan besarnya, tapi bahwa
     * ketiganya **hampir sama** (menyebar 0,00045) padahal titik skala yang
     * diukur menyebar 0,040. Itu mustahil secara fisika: hydrometer membaca
     * skalanya sendiri, jadi densitas di tanda 0,650 wajib lebih besar
     * daripada di tanda 0,610, kira-kira sebesar jarak tandanya. Deret yang
     * tertukar kehilangan sifat itu — yang tersisa cuma suhu air yang memang
     * nyaris tetap sepanjang sesi.
     *
     * Selisih per titik TIDAK dipakai: koreksi hydrometer memang bisa beberapa
     * persen dari rentangnya (sesi contoh master sendiri meleset 0,006 dari
     * rentang 0,050), jadi ambang per titik yang cukup ketat buat menangkap ini
     * bakal ikut menahan sesi yang sah.
     *
     * PERINGATAN, bukan penolakan: ambangnya heuristik, dan alat yang memang
     * rusak parah bisa saja jatuh ke sini dengan jujur. Yang dibutuhkan admin
     * melihatnya sebelum menyetujui, bukan kehilangan sesinya.
     *
     * @return list<array<string, mixed>>
     */
    private function peringatanDeretTertukar(CalibrationSession $sesi): array
    {
        $hitungan = $sesi->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->get(['titik_ukur', 'rata_rata']);

        if ($hitungan->count() < 2) {
            return [];
        }

        $titik = $hitungan->map(static fn ($h): float => (float) $h->titik_ukur);
        $densitas = $hitungan->map(static fn ($h): float => (float) $h->rata_rata);

        $rentangTitik = $titik->max() - $titik->min();
        $rentangDensitas = $densitas->max() - $densitas->min();

        // Titik yang semuanya sama (satu tanda skala diukur berulang) tidak
        // punya rentang buat diadu — bukan kegagalan, cuma tidak berlaku.
        if ($rentangTitik <= 0.0) {
            return [];
        }

        if ($rentangDensitas >= $rentangTitik * self::RASIO_RENTANG_MINIMUM) {
            return [];
        }

        return [[
            'kode' => 'hydrometer_densitas_tidak_mengikuti_skala',
            'pesan' => sprintf(
                'Densitas terbit cuma menyebar %.6g g/ml padahal titik skala yang diukur menyebar '
                .'%.6g g/ml. Hydrometer membaca skalanya sendiri, jadi densitas di tanda tertinggi '
                .'wajib lebih besar daripada di tanda terendah — kira-kira sejauh jarak tandanya. '
                .'Penyebab yang paling sering: deret Weight dan deret Temperature tertukar waktu '
                .'diisi. Periksa blok Measurement sebelum menyetujui.',
                $rentangDensitas,
                $rentangTitik,
            ),
        ]];
    }

    /**
     * @param  array<string, mixed>  $h
     * @param  array<string, mixed>  $praolah
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $h, array $praolah, float $u95, ?float $cmc): array
    {
        $budget = array_map(fn (array $k): array => $this->barisAudit($k), $h['komponen_budget']);

        // WAJIB ada — `CalibrationValidator::cmcTitik()` mencarinya untuk
        // gerbang `u95_meledak_dari_cmc`.
        $budget[] = $this->barisPerbandinganCmc(
            (float) $h['ketidakpastian_diperluas'],
            $cmc,
            self::SATUAN,
        );

        $budget[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'ρ udara %.12g g/cm³ · faktor tekanan %.12g · faktor suhu %.12g · M udara %.12g g · '
                .'M cairan %.12g g · πDγx/g %.12g · πDγL/g %.12g · D stem %.12g cm · varian %s · '
                .'densitas per ulangan %s · lantai CMC %s (%s) · U95%% terbit %.12g g/ml',
                $praolah['rho_udara'], $praolah['f_press'], $praolah['f_temp'],
                $praolah['m_air'], $h['massa_di_cairan'], $praolah['pid_yx'], $praolah['pid_yl'],
                $praolah['diameter'],
                $praolah['sinker'] === null ? 'tanpa beban tambahan' : 'beban tambahan '.$praolah['sinker'].' g',
                implode(' · ', array_map(static fn (float $d): string => sprintf('%.12g', $d), $h['densitas_per_ulangan'])),
                $cmc === null ? 'di luar lampiran' : $cmc,
                'lampiran LK-285-IDN (calibration_capabilities)',
                $u95,
            ),
            'distribusi' => 'jejak',
            'nilai' => null,
            'u_baku' => 0.0,
            'ci' => 0.0,
            'kontribusi' => 0.0,
            'satuan' => self::SATUAN,
        ];

        return $budget;
    }

    /**
     * Semua pita CMC hydrometer milik lab ini, dari lampiran akreditasi.
     *
     * `kemampuanSesi()` memulangkan SATU baris — yang pertama cocok nama &
     * kategori — dan itu benar buat tiga puluh dua alat yang pita CMC-nya cuma
     * satu. Hydrometer punya DUA (`database/data/kemampuan-kalibrasi.json`,
     * kelompok Densitas no. 32), jadi yang dibutuhkan seluruh barisnya.
     *
     * @return Collection<int, CalibrationCapability>
     */
    private function pitaKemampuan(Equipment $equipment): Collection
    {
        return CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->when(
                $equipment->equipment_category_id !== null,
                fn ($q) => $q->where('equipment_category_id', $equipment->equipment_category_id),
            )
            ->when(
                $equipment->organization_id !== null,
                fn ($q) => $q->milikOrganisasi($equipment->organization_id),
            )
            ->orderBy('range_min')
            ->get();
    }

    /**
     * Lantai CMC untuk SATU titik skala, atau `null` kalau titiknya di luar
     * semua pita lampiran.
     *
     * `null` di sini berarti **tidak ada lantai**, bukan "tahan sesinya" —
     * preseden Height Gauge & Jangka Sorong: sesi di luar lampiran tetap
     * terbit, U95-nya telanjang (murni hasil budget), dan yang dicabut cuma
     * KLAIM akreditasinya lewat [dalamLingkupAkreditasiSesi].
     *
     * Itu yang membuat hydrometer contoh rentang berat (1,800-2,000 g/mL, di
     * atas pita tertinggi 1,70) tetap bisa direproduksi apa adanya — dan
     * memang harus: sertifikatnya sudah terbit 7 Nov 2025.
     *
     * @param  Collection<int, CalibrationCapability>  $pita
     */
    private function cmcTitik(Collection $pita, float $titikGPerMl): ?float
    {
        foreach ($pita as $p) {
            $min = $p->range_min === null ? null : (float) $p->range_min;
            $maks = $p->range_max === null ? null : (float) $p->range_max;

            if (($min !== null && $titikGPerMl < $min) || ($maks !== null && $titikGPerMl > $maks)) {
                continue;
            }

            return $p->ketidakpastian_terbaik === null ? null : (float) $p->ketidakpastian_terbaik;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function bagianIdentitas(): array
    {
        $kunci = 'spesifikasi_alat.'.HydrometerMentah::KUNCI_SESI;

        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Equipment Identity',
            'field' => [
                $this->field('equipment_id', 'Pilih Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Name', 'teks', sumber: 'otomatis'),
                $this->field('spesifikasi_alat.rentang_ukur', 'Range', 'teks'),
                // Satuan lebih dulu — dia mengubah arti titik skala DAN resolusi.
                // Dropdown, bukan teks bebas: satuan tak dikenal jatuh ke faktor 1
                // dan angkanya salah diam-diam.
                $this->field("{$kunci}.satuan_densitas", 'Satuan Densitas', 'pilihan', pilihan: [
                    ['nilai' => 'g/ml', 'label' => 'g/ml'],
                    ['nilai' => 'kg/m3', 'label' => 'kg/m3'],
                ]),
                // Satuannya SENGAJA tidak dipatok `g/ml`: kotaknya mengikuti
                // dropdown di atas, dan label yang memaksa satu satuan justru
                // menyuruh teknisi yang memilih `kg/m3` mengetik angka dalam
                // satuan yang tidak dia pilih.
                $this->field("{$kunci}.resolusi", 'Resolution', 'angka'),
                // Kotak `Temperature` kertas — dipakai sebagai suhu acuan FAKTOR
                // koreksi, dan itu BUKAN `tr`. Lihat temuan 6 di calculator.
                $this->field("{$kunci}.suhu_acuan_faktor", 'Temperature', 'angka', satuan: '°C'),
                $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                $this->field('alat_model', 'Type/Model', 'teks'),
                $this->field('alat_serial_number', 'Serial Number/LPI', 'teks'),
                $this->field('alat_merk', 'Merk/Manufacture', 'teks'),
                $this->field('suhu_awal', 'Env. Condition — First (°C)', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Env. Condition — End (°C)', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Env. Condition — First (%RH)', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Env. Condition — End (%RH)', 'angka', satuan: '%RH'),
                // Tidak tercetak di kertas Rev.2, tapi rantai hitungnya BUTUH —
                // lihat docblock kelas & pertanyaan §9.
                $this->field('tekanan_awal', 'Tekanan Udara — awal', 'angka', satuan: 'hPa'),
                $this->field('tekanan_akhir', 'Tekanan Udara — akhir', 'angka', satuan: 'hPa'),
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
                $this->field('thermohygro_standard_id', 'TH Used', 'pilihan', sumber: 'master_thermohygro'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianPemilik(): array
    {
        return [
            'kode' => 'pemilik',
            'halaman' => 1,
            'judul' => 'Owner',
            'field' => [
                $this->field('pemilik_nama', 'Name', 'teks'),
                $this->field('pemilik_alamat', 'Address', 'teks_panjang'),
                $this->field('nomor_order', 'Order Number', 'teks'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianStandard(): array
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

    /**
     * Blok **1. Pre Condition** kertas: Sl, Ma, yx + satuannya, tr, dan tiga
     * ukuran diameter stem.
     *
     * Toggle `pakai_beban_tambahan` DIDULUKAN dan kotak `beban_tambahan` cuma
     * muncul kalau menyala. Itu yang membuat "kosong karena tidak perlu" tidak
     * bisa tertukar dengan "kosong karena lupa" — dan bedanya bukan kerapian:
     * rumus yang salah varian memulangkan densitas yang tampak wajar.
     *
     * @return array<string, mixed>
     */
    private function bagianPreCondition(): array
    {
        $kunci = 'spesifikasi_alat.'.HydrometerMentah::KUNCI_SESI;

        return [
            'kode' => 'pre_condition',
            'halaman' => 1,
            'judul' => '1. Pre Condition',
            'field' => [
                // DROPDOWN dua pilihan, bukan saklar boolean — dan itu bukan
                // selera bentuk.
                //
                // Kontrak lembar kerja HP cuma mengenal tujuh tipe field
                // (`TipeField.fromApi`), dan tipe yang tidak dikenal jatuh ke
                // `TipeField.teks` TANPA satu pun error: teknisi melihat kotak
                // ketikan bebas untuk pertanyaan yang menentukan rumus mana yang
                // dipakai, dan apa pun yang dia ketik dibaca server sebagai
                // "tidak". Dropdown `pilihan` sudah punya widget-nya sendiri
                // (`_PilihanTetap`), nilainya mendarat di slot teks yang dibaca
                // `tampil_kalau`, dan pilihannya tidak bisa diketik salah.
                //
                // Dua pilihan yang sama-sama HARUS dipilih, bukan satu centang
                // yang boleh dibiarkan: itu yang membuat "tidak perlu sinker"
                // tidak bisa tertukar dengan "lupa mengisi sinker".
                $this->field("{$kunci}.pakai_beban_tambahan", 'Beban Tambahan (Sinker)', 'pilihan', pilihan: [
                    ['nilai' => 'ya', 'label' => 'Pakai beban tambahan'],
                    ['nilai' => 'tidak', 'label' => 'Tanpa beban tambahan'],
                ]),
                $this->field(
                    "{$kunci}.beban_tambahan", 'Sl — Beban Tambahan', 'angka',
                    satuan: 'g',
                    tampilKalau: ['kode' => "{$kunci}.pakai_beban_tambahan", 'nilai' => ['ya']],
                ),
                $this->field("{$kunci}.massa_udara", 'Ma — Massa Hydrometer di Udara', 'angka', satuan: 'g'),
                $this->field("{$kunci}.tegangan_permukaan", 'yx — Tegangan Permukaan Hydrometer', 'angka'),
                $this->field("{$kunci}.satuan_tegangan", 'Satuan Tegangan Permukaan', 'pilihan', pilihan: [
                    ['nilai' => 'dyne/cm', 'label' => 'dyne/cm'],
                    ['nilai' => 'mN/m', 'label' => 'mN/m'],
                    ['nilai' => 'N/m', 'label' => 'N/m'],
                ]),
                // Dropdown, bukan teks bebas: salah ketik `tr` menggeser SELURUH
                // koreksi tanpa gejala. Daftarnya dari catatan master
                // `PERHITUNGAN!AE39`.
                $this->field("{$kunci}.suhu_acuan_alat", 'tr — Suhu Acuan Hydrometer', 'pilihan', satuan: '°C', pilihan: [
                    ['nilai' => '15', 'label' => '15 °C'],
                    ['nilai' => '20', 'label' => '20 °C'],
                    ['nilai' => '27.5', 'label' => '27,5 °C'],
                ]),
            ],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => 'hydro_diameter',
                    'judul' => 'D Stem (cm) — tiga kali ukur',
                    'satuan' => 'cm',
                    'judul_nilai' => 'D Stem',
                    'judul_pengulangan' => 'Ukur ke',
                    'titik_bisa_diubah' => false,
                    // Beda dari indeks 1..n tabel Measurement — tanpa offset,
                    // baris ini berbagi kunci dengan titik skala pertama.
                    'offset_kunci' => 2000,
                    'simpan_ke' => "{$kunci}.diameter_stem",
                    'baris' => [[
                        'nomor' => 1,
                        'titik_ukur' => null,
                        'label' => 'D Stem',
                        'satuan' => 'cm',
                        // Caliper digital lab resolusinya 0,01 mm = 0,001 cm.
                        'desimal' => 3,
                    ]],
                    'kolom' => [
                        ['kode' => 'pembacaan', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => 'cm'],
                    ],
                    'pengulangan' => range(1, TabelStandarHydrometer::UKUR_DIAMETER_STEM),
                ],
            ],
        ];
    }

    /**
     * Blok **2. Measurement** kertas: dua tabel yang titiknya harus SINKRON.
     *
     * Keduanya memakai `baris` yang sama persis (nomor & label), jadi HP bisa
     * menampilkannya sebagai satu matriks dan mengirim `measurements[i]` yang
     * membawa dua deret sekaligus.
     *
     * ## Barisnya SEBANYAK [TITIK_MAKS], bukan [TITIK_AWAL]
     *
     * Mulanya tabel ini mengirim tiga baris plus `titik_bisa_diubah: true`,
     * dengan maksud teknisi menambah titik ke-4 dan ke-5 sendiri. Kunci itu
     * **tidak pernah bisa jalan di lembar ini**: panel `PengaturTitik` di HP
     * baru dirender kalau `baris.every((b) => b.titikDitentukan)`, dan
     * `titikDitentukan` itu `titik_ukur is num`. Semua baris di sini
     * `titik_ukur: null` — memang harus, karena `Point of Calibration` diketik
     * teknisi — jadi syaratnya tidak pernah terpenuhi dan panelnya tidak pernah
     * muncul. Lembarnya mentok tiga titik, diam-diam.
     *
     * Dan itu memang benar begitu: `PengaturTitik` mengatur NILAI titik lewat
     * daftar chip, sementara lembar ini sudah punya kotak `Point of
     * Calibration` per baris. Dua jalan mengisi satu hal yang sama justru yang
     * dihindari syarat tadi.
     *
     * Jadi slotnya dikirim penuh sejak awal dan `titik_bisa_diubah` dimatikan.
     * Baris yang `Point of Calibration`-nya dibiarkan kosong TIDAK ikut
     * terkirim (`TitikState.siapKirim` di HP), jadi hydrometer bertanda tiga
     * skala tetap mengirim tiga titik — bukan lima, dua di antaranya kosong.
     *
     * @return array<string, mixed>
     */
    private function bagianMeasurement(): array
    {
        $baris = static fn (int $desimal): array => array_map(
            static fn (int $n): array => [
                'nomor' => $n,
                // `null`, dan itu yang MEMBUKA kotak `Point of Calibration`
                // untuk diketik teknisi (`BarisTabelHasil.titikDitentukan =
                // titik is num` di HP). Presedennya kelima tabel Flowmeter,
                // yang titiknya juga tidak dipatok kertas.
                //
                // Bedanya dari Micrometer & Dial Indicator, yang menaruh `0.0`:
                // di sana nominalnya dipatok kertas atau diturunkan dari
                // tumpukan balok, jadi kotak yang bisa diketik justru membuka
                // jalan buat sesi yang nominalnya berbeda dari lembarnya
                // sendiri. Di sini kertas memang membiarkan kolomnya kosong.
                //
                // Akibat sampingannya disengaja: baris yang titiknya belum
                // diketik DITAHAN dari payload (`TitikState.siapKirim`). Massa
                // hasil timbang tanpa titik skala tidak berarti apa-apa, dan
                // server pun menolaknya.
                'titik_ukur' => null,
                'label' => 'Titik '.$n,
                'satuan' => self::SATUAN,
                // Desimal kotak isian, per BARIS — itu satu-satunya tempat
                // kontrak lembar kerja menyatakannya (`BarisTabelHasil.desimal`
                // di HP). Massa ditimbang 4 desimal, suhu air 1 desimal; tanpa
                // ini keduanya jatuh ke desimal resolusi alat (0,0005 → 4) dan
                // kotak suhu menerima ketelitian yang tidak pernah dimiliki
                // termometernya.
                'desimal' => $desimal,
            ],
            range(1, self::TITIK_MAKS),
        );

        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => '2. Measurement',
            'field' => [],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => HydrometerMentah::PERAN_MASSA,
                    // Ketiga tabel lembar ini ber-`tahap` sama dan tidak punya
                    // `peran`, jadi `TabelHasil.kunciTabel` HP jatuh ke `tahap`
                    // untuk ketiganya. Tanpa `offset_kunci` mereka berbagi satu
                    // `Map<double, TitikState>` — dan karena `titik_ukur`
                    // bawaannya 0,0 di semua baris, ketiganya menulis ke kotak
                    // isian yang SAMA: massa yang diketik muncul di kotak suhu,
                    // dan yang terkirim salah satunya saja. Nol error di kedua
                    // sisi. Dijaga
                    // `SemuaProfilLembarKerjaTest::test_tabel_sekunci_tidak_berbagi_kunci_baris`.
                    'offset_kunci' => 1000,
                    'judul' => 'a. Weight — Weight of Hydrometer (gram)',
                    'satuan' => HydrometerMentah::SATUAN_MASSA,
                    'judul_nilai' => 'Point of Calibration',
                    'judul_pengulangan' => 'Timbang ke',
                    // `false`: di lembar yang semua barisnya `titik_ukur: null`
                    // kunci ini tidak pernah terbaca HP (lihat docblock di
                    // atas). Dibiarkan `true`, dia cuma janji yang tidak pernah
                    // ditepati — dan janji yang tidak pernah ditepati di kontrak
                    // lembar kerja itu yang bikin batas tiga titik lolos tanpa
                    // ada yang sadar.
                    'titik_bisa_diubah' => false,
                    // Kedua tabel dikirim HP sebagai `measurements[]` yang
                    // digabung per POSISI baris — jalur `simpan_ke` bernama yang
                    // sama dipakai kelima tabel Flowmeter dan ketiga tabel
                    // Jangka Sorong. Tanpa `simpan_ke`, tabel ini jatuh ke jalur
                    // datar `measurements[].pembacaan` dan kedua deretnya
                    // bertumpuk di satu kunci: yang sampai server salah satunya
                    // saja, tanpa satu pun error.
                    //
                    // `titik_ukur` tiap `measurements[i]` diambil HP dari tabel
                    // PERTAMA yang punya baris itu (`acuan ??= ts` di
                    // `_measurementsDeretBernama`) — jadi tabel massa yang
                    // memegang Point of Calibration, dan tabel suhu di bawahnya
                    // ikut nomor barisnya. Itu yang membuat kedua tabel tetap
                    // sinkron tanpa kunci kontrak baru.
                    'simpan_ke' => 'measurements[].'.HydrometerMentah::PERAN_MASSA,
                    'baris' => $baris(4),
                    'kolom' => [
                        ['kode' => 'pembacaan', 'label' => 'Massa', 'tipe' => 'angka', 'satuan' => HydrometerMentah::SATUAN_MASSA],
                    ],
                    'pengulangan' => range(1, self::PENGULANGAN),
                ],
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => HydrometerMentah::PERAN_SUHU,
                    // Beda dari tabel massa (1000) dan tabel diameter stem
                    // (2000) — lihat alasannya di tabel massa.
                    'offset_kunci' => 3000,
                    'judul' => 'b. Temperature — Temperature (°C)',
                    'satuan' => '°C',
                    'judul_nilai' => 'Point of Calibration',
                    'judul_pengulangan' => 'Baca ke',
                    // `false`, SAMA dengan tabel massa — keduanya wajib sama.
                    // Kalau berbeda, jumlah barisnya bisa menyimpang: baris
                    // massa ke-4 sampai ke server tanpa pasangan suhunya, lalu
                    // ditolak sebagai titik tidak sinkron.
                    'titik_bisa_diubah' => false,
                    'simpan_ke' => 'measurements[].'.HydrometerMentah::PERAN_SUHU,
                    'baris' => $baris(1),
                    'kolom' => [
                        ['kode' => 'pembacaan', 'label' => 'Suhu', 'tipe' => 'angka', 'satuan' => '°C'],
                    ],
                    'pengulangan' => range(1, self::PENGULANGAN),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianPenutup(): array
    {
        return [
            'kode' => 'penutup',
            'halaman' => 1,
            'judul' => 'Catatan & Tanda Tangan',
            'field' => [
                $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Checked by', 'teks', sumber: 'otomatis'),
            ],
        ];
    }

    private function kalk(): HydrometerCalculator
    {
        return $this->kalk ??= new HydrometerCalculator;
    }
}
