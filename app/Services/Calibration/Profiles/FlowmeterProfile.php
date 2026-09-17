<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\FlowmeterCalculator;
use App\Services\Calibration\FlowmeterGravimetriCalculator;
use App\Services\Calibration\TabelStandarFlowmeter;
use App\Services\Calibration\TabelStandarFlowmeterGravimetri;
use App\Services\Calibration\VarianMetodeFlowmeter;
use App\Support\FlowmeterMentah;
use Carbon\Carbon;

/**
 * Dasar bersama **Flowmeter Ultrasonic** — alat ke-27 (Totalizer) & ke-28
 * (Flowrate), lampiran akreditasi LK-285-IDN no. 30 & 31, kelompok Aliran.
 *
 * ## Kenapa SATU kelas dasar, bukan dua profil sejajar
 *
 * Kedua alat memakai standar yang sama (Krohne UFC300), kertas yang sama
 * (`SIDIK-FM-CAL-0538_Rev.0`), Instruksi Kerja yang sama, dan mesin hitung yang
 * sama ([FlowmeterCalculator]). Yang beda cuma tiga hal, dan ketiganya dipagari
 * sebagai method abstrak: `mode()`, `namaAlatKemampuan()`, `satuanHasil()`.
 *
 * Menyalin lembar kerjanya jadi dua berkas berarti dua tempat yang harus ingat
 * diperbarui bareng — dan yang ketinggalan **tidak menerbitkan error**, cuma
 * satu alat yang lembarnya beda diam-diam. Pola yang sama sudah dipakai
 * `Profiles\Enclosure\EnclosureProfileBase` (lima jenis) dan `ProfilPutaran`
 * (Tachometer + Centrifuge).
 *
 * ## Budget PER TITIK, dan jalurnya `hitungPerGrup()`
 *
 * [komponenBudget] sengaja `null`. Itu bukan meniru tetangga: dia cuma menerima
 * SATU `$typeA` skalar dari SATU deret pembacaan, sementara satu titik flowmeter
 * punya **dua** deret (UUT dan standar) yang selisih berpasangannya melahirkan
 * komponen pengulangan, plus blok tingkat-sesi (geometri pipa) yang melahirkan
 * `u_A`. Tidak ada satu angka pun yang bisa dioper lewat lubang itu tanpa
 * kehilangan salah satunya.
 *
 * ## Delapan komponen lawan sembilan — TIDAK diseragamkan
 *
 * Totalizer 8, Flowrate 9. `FORM VALIDASI` Flowrate punya baris kedua (20 Mei
 * 2026, PIC `NR`) berbunyi *"Merubah all budget ketidakpastian ; menambahkan
 * stdev untuk UUT ; menambahkan keterangan spek pipa pada sheet sertifikat"* —
 * dan workbook Totalizer belum ikut revisi itu. Ditiru masing-masing apa adanya;
 * menambahkan komponen ke-9 ke Totalizer berarti menggeser U95 yang sudah
 * tercetak di sertifikat pelanggan. Diangkat sebagai pertanyaan lab §3.
 *
 * ## Tujuh unit thermohygro, bukan dua
 *
 * `ThermohygroSemuaLembarTest::test_ketujuh_unit_master_kepilih` menuntut
 * ketujuhnya kecuali profilnya masuk daftar `DIKECUALIKAN`, dan tabel master
 * kedua workbook memang memuat TH-1..TH-7 lengkap. Yang disunat cuma
 * [THERMOHYGRO_TERCETAK] — kotak yang tercetak di kertas. Presedennya TIDS.
 *
 * ## Empat kolom kertas yang TIDAK masuk budget
 *
 * `material_pipa`, `jenis_fluida`, `path_configuration`, dan liner dipungut dari
 * kertas `SIDIK-FM-CAL-0538` dan tidak ada di master mana pun. Mereka dicatat
 * dan dicetak, tapi **tidak melahirkan komponen ketidakpastian** — mengarang
 * komponen untuk kolom yang masternya sendiri tidak punya berarti menerbitkan
 * angka yang tidak bisa dipertanggungjawabkan. Pertanyaan lab §13.
 */
abstract class FlowmeterProfile extends CalibrationProfile
{
    /**
     * Nomor Instruksi Kerja, sama untuk kedua alat — lampiran akreditasi no. 30
     * & 31 menulis nomor yang identik.
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0528_Rev.4';

    /**
     * Nomor formulir lembar kerja — kertasnya SATU untuk kedua alat.
     *
     * `SemuaProfilLembarKerjaTest::test_nomor_formulir_nggak_dipakai_dua_profil`
     * MENUNTUT nomor unik antar-profil, dengan pengecualian yang di-hardcode
     * (sebelumnya cuma `SIDIK-FM-CAL-0504_Rev.3`, lima profil enclosure). Nomor
     * ini harus ikut ditambahkan ke pengecualian itu — bukan karena testnya
     * salah, tapi karena lab memang mencetak satu kertas untuk dua alat:
     * berkasnya ada di repo, `SIDIK-FM-CAL-0538-Rev.0 LEMBAR KERJA FLOWMETER
     * (Perbandingan Langsung dengan UFM).pdf`.
     */
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0538_Rev.0';

    /**
     * Kertas varian GRAVIMETRI — satu per mode, dan Rev.**3**, bukan Rev.0.
     *
     * Ketiganya ada di `Project-PT-Sidik/worksheet_alat_calibration/`:
     *
     *   SIDIK-FM-CAL-0538-Rev.0   LEMBAR KERJA FLOWMETER (Perbandingan Langsung dengan UFM)
     *   SIDIK-FM-CAL-0538.A-Rev.3 LEMBAR KERJA FLOWMETER-FLOWRATE
     *   SIDIK-FM-CAL-0538.B-Rev.3 LEMBAR KERJA FLOWMETER-TOTALIZER
     *
     * Jadi varian gravimetri BUKAN cuma metode lain — dia punya kertas sendiri,
     * satu per mode, dan revisinya tiga tingkat di depan kertas UFM. Sempat
     * ditulis di dokumen serah-terima bahwa nomornya "belum bisa dipisah"; itu
     * salah, nomornya sudah ada sejak awal dan cuma belum dibuka.
     */
    public const KODE_DOKUMEN_GRAVIMETRI_TOTALIZER = 'SIDIK-FM-CAL-0538.B_Rev.3';

    public const KODE_DOKUMEN_GRAVIMETRI_FLOWRATE = 'SIDIK-FM-CAL-0538.A_Rev.3';

    /** Tiga ulangan tiap titik — `PERHITUNGAN` kedua master punya tiga kolom. */
    public const PENGULANGAN = 3;

    /**
     * Tiga titik ukur, dan `titik_bisa_diubah = true`.
     *
     * Beda dari Height Gauge yang nominalnya dipatok Instruksi Kerja: di sini
     * koreksi standar dipungut lewat `cocokTerdekat()` atas RATA-RATA pembacaan
     * standar, jadi teknisi mengukur di titik mana pun yang bisa dicapai
     * pompanya. Tabel `std_*` itu sertifikat standarnya, bukan daftar titik yang
     * wajib didatangi.
     */
    public const TITIK = 3;

    /**
     * Standar yang tercetak di kotak "Standard Used" kertasnya.
     *
     * Kelimanya dari `STANDAR KALIBRATOR` kedua workbook. Caliper & thickness
     * gauge ikut karena geometri pipa masuk budget lewat `u_A` — mereka bukan
     * pelengkap.
     */
    public const STANDARD_TERCETAK = [
        [
            'label' => 'Ultrasonic Flowmeter/Krohne/UFC300',
            'cocok' => ['Ultrasonic Flowmeter', 'UFC300', 'A18P045140'],
        ],
        [
            'label' => 'Temperature Calibrator/Yokogawa/CA 150 Handy Cal',
            'cocok' => ['Temperature Calibrator', 'CA 150 Handy Cal', '23P1005'],
        ],
        [
            'label' => 'Thermocouple Type K',
            'cocok' => ['Thermocouple Type K', 'Type K', 'TC-01,02'],
        ],
        [
            'label' => 'Digital Caliper/Tesa/Cal-IP67',
            // DUA nomor seri, dan itu bukan kelalaian: `DATABASE` &
            // `STANDAR KALIBRATOR` menulis `LPI-0368` sementara
            // `PERHITUNGAN U95%!F13` menulis `CLP-130990` untuk keping yang
            // sama. Keduanya ikut dicocokkan supaya baris standar lab ketemu
            // dari sisi mana pun dia terdaftar; selisihnya diangkat sebagai
            // pertanyaan lab.
            'cocok' => ['Digital Caliper', 'Cal-IP67', 'LPI-0368', 'CLP-130990'],
        ],
        [
            'label' => 'Ultrasonic Thickness Gauge/TM-8812',
            'cocok' => ['Ultrasonic Thickness Gauge', 'TM-8812', 'N889479'],
        ],
    ];

    /**
     * KETUJUH unit, bukan dua.
     *
     * Konstanta ini bukan "yang tercetak di kertas" — dia sumber DROPDOWN-nya
     * (`CalibrationProfile::isiPilihanThermohygro()` menyapunya langsung), dan
     * `ThermohygroSemuaLembarTest::test_ketujuh_unit_master_kepilih` menuntut
     * ketujuhnya kecuali profilnya masuk daftar `DIKECUALIKAN` berikut
     * alasannya.
     *
     * Sempat diisi dua (`TH-4`, `TH-5`) mengira ini daftar cetak. Itu salah dan
     * akibatnya nyata: teknisi yang membawa TH-1 ke lapangan tidak menemukan
     * unitnya di dropdown, lalu memilih unit yang bukan dia pakai — dan
     * sertifikatnya mencatat telusur yang keliru tanpa satu pun error. Tabel
     * master kedua workbook sendiri memuat TH-1..TH-7 lengkap.
     *
     * @var list<string>
     */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    /**
     * Offset kunci baris — kelima tabel di blok pengukuran ber-`tahap` SAMA.
     *
     * Tanpa offset yang berbeda, kunci barisnya bertabrakan DI LAYAR: angka yang
     * diketik di kotak suhu muncul di kotak densitas, tanpa satu pun error.
     * Sudah nyata di Timbangan dan di Height Gauge.
     */
    public const OFFSET_STD = 1000;

    public const OFFSET_SUHU_AWAL = 2000;

    public const OFFSET_SUHU_AKHIR = 3000;

    public const OFFSET_DENSITAS = 4000;

    public const OFFSET_PIPA = 5000;

    /**
     * Tiga tabel varian GRAVIMETRI. Offsetnya lanjut dari 5000, bukan memakai
     * ulang 1000/4000 milik tabel varian UFM — kedua varian hidup di satu blok
     * `tahap` yang sama, dan kunci baris yang bertabrakan memindahkan angka
     * antar-tabel tanpa satu pun error.
     */
    public const OFFSET_BERAT_ISI = 6000;

    public const OFFSET_BERAT_KOSONG = 7000;

    public const OFFSET_WAKTU = 8000;

    /** Berapa kali diameter & ketebalan pipa dibaca — `u_A` lahir dari sebarannya. */
    public const BACAAN_PIPA = 3;

    private ?FlowmeterCalculator $kalk = null;

    private ?FlowmeterGravimetriCalculator $kalkGravimetri = null;

    private ?TabelStandarFlowmeterGravimetri $tabelGravimetri = null;

    private ?TabelStandarFlowmeter $tabel = null;

    /** `totalizer` atau `flowrate` — lihat [TabelStandarFlowmeter]. */
    abstract public function mode(): string;

    /** Satuan hasil hitungnya: `L` (Totalizer) atau `Lpm` (Flowrate). */
    abstract public function satuanHasil(): string;

    /** Nomor baris lampiran akreditasi — 30 Totalizer, 31 Flowrate. */
    abstract public function nomorLingkupAkreditasi(): int;

    public function kodeFormula(): string
    {
        return 'flowmeter_'.$this->mode();
    }

    public function besaran(): string
    {
        return 'aliran';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function butuhBlokFlowmeter(): bool
    {
        return true;
    }

    /**
     * Alat ini DI DALAM lampiran akreditasi — beda dari Height Gauge & Gas
     * Detector. Baris CMC-nya no. 30 & 31 di `kemampuan-kalibrasi.json`, sudah
     * di-seed `CalibrationCapabilitySeeder`; **jangan** bikin seeder kemampuan
     * terpisah untuknya.
     */
    public function dalamLingkupAkreditasi(): bool
    {
        return true;
    }

    /**
     * Tidak ada kolom toleransi di kedua master, dan tidak ada vonis
     * PASS/FAIL — sertifikatnya cuma mencetak deviasi dan U95.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /** U95 dicetak PER TITIK: tiap titik punya blok budget penuh sendiri. */
    public function u95PerTitik(): bool
    {
        return true;
    }

    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return $this->satuanHasil();
    }

    /**
     * Sertifikat dicetak dalam satuan ALAT PELANGGAN, bukan satuan budget.
     *
     * Seluruh mesin hitung flowmeter hidup dalam L (Totalizer) / Lpm
     * (Flowrate): pembacaan `m3/h`, `usg/min`, `kg/h` diubah ke situ lebih
     * dulu (`FlowmeterCalculator::konversi()`), dan `uncertainty_calculations`
     * menyimpan hasilnya apa adanya. Itu benar untuk budget — satu mesin, satu
     * satuan — tapi salah untuk dokumen yang dipegang pelanggan: alat yang
     * layarnya menunjukkan `3,0 m3/h` terbit dengan `50,0 Lpm` di kolom Unit
     * Under Test. Angkanya setara, dan JUSTRU ITU masalahnya — tidak ada satu
     * pun yang terlihat ganjil, karena kolom satuannya ikut berubah.
     *
     * Master membagi balik dengan faktor yang sama:
     * `SERTIFIKAT!E26 = 'PERHITUNGAN FC'!D63 / DATABASE!$S$22`.
     *
     * `null` = cetak apa adanya, persis perilaku lama. Tiga sebabnya:
     *
     *  1. Sesi tanpa blok flowmeter yang sah, atau blok yang modenya bukan
     *     mode lembar ini. [hitungPerGrup] sudah menolak SELURUH titiknya,
     *     jadi di sini tidak ada angka yang perlu dibalik.
     *  2. Faktornya 1,0 (`L`, `LPM`) — konversinya identitas. Dipulangkan
     *     `null`, bukan closure identitas, supaya LABELNYA juga tidak
     *     tersentuh: `satuanHasil()` menulis `Lpm` sementara blok sesinya
     *     menulis `LPM`. Sertifikat yang sudah terbit memang beku dan tidak
     *     ikut bergeser, tapi yang terbit BESOK akan berubah ejaan tanpa satu
     *     pun angka berubah — dan sertifikat sebelum/sesudah yang bunyinya
     *     beda tanpa sebab itu tepat jenis pertanyaan yang mahal dijawab
     *     waktu asesmen. Beda huruf besar tidak sepadan dengan itu.
     *  3. Satuan yang TIDAK DIKENAL tabel master. Titiknya juga sudah ditolak
     *     waktu dihitung; mengarang faktor di sini berarti menerbitkan angka
     *     dari konversi yang tidak pernah dipakai siapa pun.
     */
    public function cetakDalamSatuanAlat(CalibrationSession $sesi): ?array
    {
        $blok = FlowmeterMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null || $blok['mode'] !== $this->mode()) {
            return null;
        }

        // Satuannya dioper APA ADANYA, tanpa `trim()`/`strtolower()`.
        // `FlowmeterCalculator::konversi()` juga memungutnya apa adanya, dan
        // dua normalisasi yang beda antara jalur hitung dan jalur cetak
        // melahirkan sertifikat yang faktornya bukan faktor yang dipakai
        // budget-nya — tanpa satu pun error.
        $satuan = $blok['satuan'];
        $mode = $blok['mode'];
        $tabel = $this->tabel();

        $berbasisMassa = $tabel->satuanBerbasisMassa($mode, $satuan);
        $faktor = $tabel->faktorSatuan($mode, $satuan);

        if (! $berbasisMassa && ($faktor === null || $faktor == 1.0)) {
            return null;
        }

        $kalk = $this->kalk();
        $densitas = $berbasisMassa ? $this->densitasCetakPerTitik($sesi, $blok['varian_metode']) : [];

        return [
            'satuan' => $satuan,
            'ubah' => static fn (float $nilai, int $titikKe): ?float => $kalk->konversiBalik(
                $mode,
                $nilai,
                $satuan,
                $densitas[$titikKe] ?? null,
            ),
        ];
    }

    /**
     * Densitas pembagi per titik — sumbernya BEDA per varian, dan itu bukan
     * detail: memungut yang salah menggeser seluruh kolom sertifikat sambil
     * tetap terlihat masuk akal.
     *
     * UFM membagi dengan densitas fluida UUT yang DIKETIK teknisi
     * (`flow_densitas_uut`); fluidanya belum tentu air, jadi menebaknya dari
     * suhu berarti mengarang angka. Gravimetri membagi dengan densitas AIR
     * pada suhu titik itu — di varian itu fluidanya memang air dan densitasnya
     * sudah diukur piknometer.
     *
     * Keduanya memanggil FUNGSI YANG SAMA yang dipakai waktu menghitung, bukan
     * salinannya: [FlowmeterMentah::dari] untuk memungut deretnya, dan
     * `FlowmeterGravimetriCalculator::densitasTerkoreksi()` untuk tabel
     * piknometernya.
     *
     * Titik yang densitasnya tidak ketemu sengaja TIDAK masuk peta — closure
     * di atas lalu memulangkan `null` untuk titik itu, dan pemanggilnya wajib
     * memblokirnya. Jatuh diam-diam ke densitas air adalah persis kekeliruan
     * yang membuat `FlowmeterCalculator::konversi()` memblokir titiknya sejak
     * awal.
     *
     * @return array<int, float>
     */
    private function densitasCetakPerTitik(CalibrationSession $sesi, ?string $varian): array
    {
        $gravimetri = $varian === VarianMetodeFlowmeter::GRAVIMETRI->value;
        $peta = [];

        foreach ($sesi->rawMeasurements->groupBy(static fn ($b): int => (int) $b->titik_ke) as $titikKe => $baris) {
            $mentah = FlowmeterMentah::dari($baris);

            if ($mentah === []) {
                continue;
            }

            $rho = $gravimetri
                ? $this->densitasAirTitik($mentah)
                : $this->rataDeret($mentah[FlowmeterMentah::PERAN_DENSITAS] ?? []);

            if ($rho !== null && $rho > 0.0) {
                $peta[(int) $titikKe] = $rho;
            }
        }

        return $peta;
    }

    /**
     * Densitas air titik ini — rata-rata suhu DULU, baru dikoreksi, lalu awal
     * dan akhir dirata-ratakan. Urutannya disamakan dengan
     * `FlowmeterGravimetriCalculator::hitungTitik()`; dibalik, hasilnya beda di
     * digit yang tercetak.
     *
     * @param  array<string, mixed>  $mentah
     */
    private function densitasAirTitik(array $mentah): ?float
    {
        $awal = $this->rataDeret($mentah[FlowmeterMentah::PERAN_SUHU_AWAL] ?? []);
        $akhir = $this->rataDeret($mentah[FlowmeterMentah::PERAN_SUHU_AKHIR] ?? []);

        if ($awal === null || $akhir === null) {
            return null;
        }

        $kalk = $this->kalkGravimetri();
        $rhoAwal = $kalk->densitasTerkoreksi($awal);
        $rhoAkhir = $kalk->densitasTerkoreksi($akhir);

        if ($rhoAwal === null || $rhoAkhir === null) {
            return null;
        }

        return ($rhoAwal + $rhoAkhir) / 2;
    }

    /**
     * Rata-rata deret, `null` kalau kosong — BUKAN nol.
     *
     * Nol di sini jadi pembagi, dan pembagian nol yang dibungkus `?:` pulang
     * sebagai angka yang kelihatan wajar.
     *
     * @param  list<float>  $deret
     */
    private function rataDeret(array $deret): ?float
    {
        return $deret === [] ? null : array_sum($deret) / count($deret);
    }

    /** Lihat docblock kelas — jalurnya [hitungPerGrup]. */
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
     * Tiap titik satu budget penuh.
     *
     * Titik yang tidak bisa dihitung masuk `belum_dihitung` berikut ALASANNYA —
     * tidak dibuang diam-diam. Itu bedanya dari `IFERROR(...; "")` master, yang
     * membuat titiknya hilang dari sertifikat tanpa jejak.
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        // Blok tingkat-sesi DISAPU, bukan diambil dari `$titik[0]`: jalur hitung
        // ulang mengelompokkan per `titik_ke` lewat `groupBy` dan urutannya tidak
        // dijamin. Bertumpu pada elemen pertama berarti sesi yang titik
        // pertamanya kebetulan tersaring pulang tanpa blok — diam-diam, dengan
        // SELURUH titiknya "belum dihitung".
        $konteksSesi = [];

        foreach ($titik as $t) {
            if (isset($t['konteks']['spesifikasi_alat'])) {
                $konteksSesi = $t['konteks'];

                break;
            }
        }

        $blok = FlowmeterMentah::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi Flowmeter belum punya blok tingkat-sesi di `spesifikasi_alat.'
                        .FlowmeterMentah::KUNCI_SESI.'`, atau `mode`-nya bukan `totalizer`/`flowrate`. '
                        .'Mode menentukan budgetnya 8 komponen atau 9, satuan menentukan faktor '
                        .'konversinya, dan geometri pipa melahirkan komponen `u_A` — ketiganya lahir '
                        .'di blok itu, bukan per titik.',
                ], $titik),
            ];
        }

        // Mode blok WAJIB sama dengan mode profilnya. Sesi Totalizer yang
        // mendarat di profil Flowrate akan terhitung dengan budget generasi yang
        // salah DAN pita CMC yang salah — dan tidak ada satu pun error yang
        // menandainya, karena kedua mode sama-sama sah.
        if ($blok['mode'] !== $this->mode()) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Blok sesi bermode `%s` tapi lembar yang dipakai `%s`. Keduanya beda jumlah '
                        .'komponen budget (8 lawan 9) dan beda pita CMC, jadi titiknya tidak '
                        .'diterbitkan daripada terbit dengan budget generasi yang salah.',
                        $blok['mode'],
                        $this->mode(),
                    ),
                ], $titik),
            ];
        }

        // Varian metode MENENTUKAN ANGKA — dan salah pilih tidak menghasilkan
        // error, cuma budget generasi lain dengan rantai hitung yang lain.
        // `blokSesi()` sudah memulangkan `null` untuk nilai yang ADA tapi tidak
        // dikenal; sesi begitu diblokir, tidak jatuh ke bawaan.
        $varian = $blok['varian_metode'] === null
            ? null
            : VarianMetodeFlowmeter::from($blok['varian_metode']);

        if ($varian === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Varian metode Flowmeter di `spesifikasi_alat.%s.varian_metode` tidak dikenal. '
                        .'Yang sah: `%s` (perbandingan langsung UFM) dan `%s` (penimbangan statis '
                        .'ISO 4185). Varian menentukan budgetnya 8/9 komponen atau 9/11 DAN rantai '
                        .'hitungnya — menebaknya berarti menerbitkan angka dari metode yang tidak '
                        .'dipakai teknisi.',
                        FlowmeterMentah::KUNCI_SESI,
                        VarianMetodeFlowmeter::UFM->value,
                        VarianMetodeFlowmeter::GRAVIMETRI->value,
                    ),
                ], $titik),
            ];
        }

        $gravimetri = $varian === VarianMetodeFlowmeter::GRAVIMETRI;
        $masukan = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            // Deretnya datang lewat `konteks`, bukan level atas — jalur simpan
            // dan jalur hitung ulang sama-sama menaruhnya di situ.
            $k = $t['konteks'] ?? [];
            $uut = $k[FlowmeterMentah::PERAN_UUT] ?? [];
            // Sisi standar bercabang varian: UFM membaca totalizer standar,
            // gravimetri MENIMBANG. Perannya beda supaya sesi yang variannya
            // tertukar berhenti di sini, bukan menghitung kilogram sebagai liter.
            $std = $gravimetri
                ? ($k[FlowmeterMentah::PERAN_BERAT_ISI] ?? [])
                : ($k[FlowmeterMentah::PERAN_STD] ?? []);

            // Sesi yang baris mentahnya belum ber-`peran_sensor` DITOLAK dengan
            // alasan yang kebaca, bukan diam-diam dihitung dari `pembacaan`
            // datar. Deret datar tidak bisa dibedakan mana UUT mana standar, dan
            // deviasi yang lahir dari situ — selisih keduanya — tidak berarti
            // apa-apa.
            if ($uut === [] && $std === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d nggak punya baris ber-peran `%s`/`%s`. Lembar Flowmeter nyimpen deret '
                        .'UUT dan deret standar terpisah — deret datar nggak bisa dipakai.',
                        $t['titik_ke'],
                        FlowmeterMentah::PERAN_UUT,
                        $gravimetri ? FlowmeterMentah::PERAN_BERAT_ISI : FlowmeterMentah::PERAN_STD,
                    ),
                ];

                continue;
            }

            $masukan[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'uut' => $uut,
                'std' => $std,
                'suhu_awal' => $k[FlowmeterMentah::PERAN_SUHU_AWAL] ?? [],
                'suhu_akhir' => $k[FlowmeterMentah::PERAN_SUHU_AKHIR] ?? [],
                'densitas_uut' => $k[FlowmeterMentah::PERAN_DENSITAS] ?? [],
                'berat_kosong' => $k[FlowmeterMentah::PERAN_BERAT_KOSONG] ?? [],
                'waktu_menit' => $k[FlowmeterMentah::PERAN_WAKTU] ?? [],
                'standard' => $t['standard'] ?? null,
            ];
        }

        if ($masukan === []) {
            usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

            return ['hitungan' => [], 'belum_dihitung' => $belumDihitung];
        }

        $kemampuan = $this->kemampuanSesi($equipment);

        // Dua varian, dua mesin. Yang TIDAK dilakukan: menumpuk sumbu ketiga ke
        // dalam `FlowmeterCalculator`. Rantai gravimetri tidak berbagi satu pun
        // besaran turunan dengan rantai UFM — tidak ada geometri pipa, tidak ada
        // Tanaka/Kell, tidak ada tabel sertifikat UFM — jadi menggabungkannya
        // berarti satu kelas dengan dua jalur yang cuma bertemu di namanya, dan
        // tiap perbaikan di satu jalur harus dibaca ulang untuk memastikan tidak
        // menyenggol jalur lain. Sertifikat UFM yang sudah terbit wajib tetap
        // menghasilkan angka yang sama persis.
        $hasil = $gravimetri
            ? $this->kalkGravimetri()->hitungSesi(
                array_map(static fn (array $m): array => [
                    'titik_ke' => $m['titik_ke'],
                    'uut' => $m['uut'],
                    'berat_isi' => $m['std'],
                    'berat_kosong' => $m['berat_kosong'],
                    'waktu_menit' => $m['waktu_menit'],
                    'suhu_awal' => $m['suhu_awal'],
                    'suhu_akhir' => $m['suhu_akhir'],
                ], $masukan),
                [
                    'mode' => $blok['mode'],
                    'satuan' => $blok['satuan'],
                    'resolusi' => $blok['resolusi'],
                    'kode_timbangan' => $blok['kode_timbangan'],
                ],
            )
            : $this->kalk()->hitungSesi(
                array_map(static fn (array $m): array => [
                    'titik_ke' => $m['titik_ke'],
                    'uut' => $m['uut'],
                    'std' => $m['std'],
                    'suhu_awal' => $m['suhu_awal'],
                    'suhu_akhir' => $m['suhu_akhir'],
                    'densitas_uut' => $m['densitas_uut'],
                ], $masukan),
                [
                    'mode' => $blok['mode'],
                    'satuan' => $blok['satuan'],
                    'resolusi' => $blok['resolusi'],
                    'diameter_pipa_mm' => $blok['diameter_pipa_mm'],
                    'ketebalan_pipa_mm' => $blok['ketebalan_pipa_mm'],
                ],
            );

        $standarPerTitik = collect($masukan)->keyBy('titik_ke');
        $sekarang = Carbon::now();
        $hitungan = [];

        foreach ($hasil['titik'] as $h) {
            $hitungan[] = [
                // Null-safe: sesi yang standarnya di-soft-delete memulangkan
                // null, dan tanpa `?->` perintah hitung ulang mati total.
                'standard_id' => ($standarPerTitik[$h['titik_ke']]['standard'] ?? null)?->id,
                'titik_ke' => $h['titik_ke'],
                'titik_ukur' => $h['std_terkoreksi'],
                'rata_rata' => $h['uut_rata'],
                // `koreksi` = standar − UUT, `error` kebalikannya. Arahnya
                // BUKAN selera: sertifikat master menamai kolomnya `Correction`
                // dan mengisinya `=H26-E26` (Standard Indication − Unit Under
                // Test), dan konvensi repo sama (`HeightGaugeProfile`:
                // `koreksi = standarTerkoreksi − rata`). Tertukar, sertifikat
                // menyuruh pelanggan menggeser alatnya ke arah yang salah — dan
                // besarnya tetap benar, jadi tidak ada satu pun angka yang
                // terlihat ganjil.
                'error' => -$h['deviasi'],
                'koreksi' => $h['deviasi'],
                // Simpangan baku pasangan standar−UUT, bukan simpangan baku UUT:
                // yang kedua `null` di varian Totalizer (komponennya baru lahir
                // di revisi 20 Mei 2026 dan cuma ada di Flowrate), jadi kolom
                // ini bakal kosong separuh alat tanpa sebab yang kebaca.
                'standar_deviasi' => $h['simpangan_baku_standar'],
                'jumlah_pengulangan' => $h['jumlah_pengulangan'],
                'type_a' => $h['type_a'],
                'type_b_components' => $gravimetri
                    ? $this->jejakAuditGravimetri($hasil, $h)
                    : $this->jejakAudit($hasil, $h),
                'type_b' => $h['type_b'],
                'ketidakpastian_gabungan' => $h['ketidakpastian_gabungan'],
                'faktor_cakupan_k' => $h['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $h['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $h['u95_sertifikat'],
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
     * Jejak audit satu titik — seluruh komponen budget, lantai CMC, dan satu
     * baris ringkasan yang bisa dibaca tanpa membuka Excel.
     *
     * @param  array<string, mixed>  $hasil  keluaran `hitungSesi()` (tingkat sesi)
     * @param  array<string, mixed>  $h  satu titik dari `$hasil['titik']`
     * @return list<array<string, mixed>>
     */
    /**
     * Jejak audit varian GRAVIMETRI — besaran turunannya lain sama sekali.
     *
     * Bukan cabang `if` di dalam [jejakAudit]: yang dicetak di situ (rata-rata
     * standar, baris tabel sertifikat UFM, geometri pipa) tidak punya padanan
     * di sini, dan yang dicetak di sini (massa bersih, koreksi apung, waktu
     * terkoreksi, kode timbangan) tidak punya padanan di sana. Satu fungsi
     * dengan dua nasib berarti separuh barisnya selalu bohong.
     *
     * @param  array<string, mixed>  $hasil
     * @param  array<string, mixed>  $h
     * @return list<array<string, mixed>>
     */
    private function jejakAuditGravimetri(array $hasil, array $h): array
    {
        $budget = array_map(fn (array $k): array => $this->barisAudit($k), $h['budget']);

        // Baris `perbandingan_cmc` WAJIB ada — lihat alasannya di [jejakAudit].
        // Yang dibandingkan `U` yang SUDAH dikonversi ke satuan volume, bukan
        // `U` dalam kilogram: lantainya persen dari nilai bersatuan volume, dan
        // mengadu kilogram ke liter menggeser perbandingannya 0,36 %.
        $budget[] = $this->barisPerbandinganCmc(
            (float) $h['ketidakpastian_diperluas_terkonversi'],
            (float) $h['lantai_cmc'],
            $this->satuanHasil(),
        );

        $timbangan = $hasil['timbangan'] ?? [];

        $budget[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'Rata-rata UUT %s %s · berat isi %s kg · wadah kosong %s kg · massa bersih %s kg · '
                .'koreksi titik tabel %s kg (titik %s kg) · massa terkoreksi %s kg · koreksi apung E %s '
                .'· Mt %s kg · densitas air %s kg/L%s · hasil %s %s · deviasi %s · U %s kg -> %s %s · '
                .'lantai CMC %s %s%s · timbangan %s %s (%s, U95 %s kg, kestabilan %s kg, drift %s kg) '
                .'· u(suhu) %s °C',
                $h['uut_rata'], $this->satuanHasil(),
                $h['berat_isi_rata'],
                $h['berat_kosong_rata'],
                $h['massa_bersih'],
                $h['koreksi_standar'],
                $h['titik_tabel'],
                $h['std_terkoreksi'],
                $h['koreksi_apung'],
                $h['massa_terapung'],
                $h['densitas_standar'],
                $h['waktu_terkoreksi'] === null
                    ? ''
                    : sprintf(
                        ' · waktu %s min + koreksi %s = %s min',
                        $h['waktu_rata'], $h['koreksi_timer'], $h['waktu_terkoreksi'],
                    ),
                $h['hasil'], $this->satuanHasil(),
                $h['deviasi'],
                $h['ketidakpastian_diperluas'],
                $h['ketidakpastian_diperluas_terkonversi'], $this->satuanHasil(),
                $h['lantai_cmc'], $this->satuanHasil(),
                $h['lantai_cmc_dipakai'] ? ' — DIPAKAI, budget hitung di bawahnya' : '',
                $timbangan['merk'] ?? '?',
                $timbangan['tipe'] ?? '?',
                $timbangan['seri'] ?? '?',
                $timbangan['u95_kg'] ?? '?',
                $timbangan['kestabilan_kg'] ?? '?',
                $timbangan['drift_kg'] ?? '?',
                $hasil['u_temperature'],
            ),
            'distribusi' => 'jejak',
            'nilai' => null,
            'u_baku' => 0.0,
            'ci' => 0.0,
            'vi' => 0.0,
        ];

        return $budget;
    }

    private function jejakAudit(array $hasil, array $h): array
    {
        $budget = array_map(fn (array $k): array => $this->barisAudit($k), $h['budget']);

        // Baris `perbandingan_cmc` WAJIB ada: `CalibrationValidator::cmcTitik()`
        // menyapu `type_b_components` mencari `sumber` ini, dan yang tidak ketemu
        // mematikan gerbang ERROR `u95_meledak_dari_cmc` — penjagaan yang lahir
        // dari satu pembacaan salah ketik yang menerbitkan U95 212x CMC lab.
        //
        // Beda dari Height Gauge: di sini CMC-nya NYATA (alat ini di dalam
        // lampiran), dan lantainya benar-benar dipakai —
        // `u95_sertifikat = max(u, lantai)`.
        $budget[] = $this->barisPerbandinganCmc(
            (float) $h['ketidakpastian_diperluas'],
            (float) $h['lantai_cmc'],
            $this->satuanHasil(),
        );

        $geo = $hasil['geometri'] ?? null;

        $budget[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'Rata-rata UUT %s %s · rata-rata standar %s · koreksi titik tabel %s (baris standar %s) '
                .'· standar terkoreksi %s · deviasi %s · U95 %s %s (%s) · lantai CMC %s %s%s · '
                .'geometri pipa %s · u(suhu) %s °C',
                $h['uut_rata'], $this->satuanHasil(),
                $h['std_rata'],
                $h['koreksi_standar'],
                $h['baris_tabel']['standard'],
                $h['std_terkoreksi'],
                $h['deviasi'],
                $h['u95_sertifikat'], $this->satuanHasil(),
                $h['u95_persen_of_reading'] === null
                    ? 'persen tak terdefinisi, rata-rata UUT nol'
                    : sprintf('%.3f %% of reading', $h['u95_persen_of_reading']),
                $h['lantai_cmc'], $this->satuanHasil(),
                $h['lantai_cmc_dipakai'] ? ' — DIPAKAI, budget hitung di bawahnya' : '',
                $geo === null
                    ? 'tidak dicatat'
                    : sprintf(
                        'Ø luar %s mm, tebal %s mm, Ø dalam %s mm, A %s mm², u(A) %s',
                        $geo['diameter_luar'], $geo['ketebalan'], $geo['diameter_dalam'],
                        $geo['a'], $geo['u_a'],
                    ),
                $hasil['u_temperature'],
            ),
            'distribusi' => 'jejak',
            'nilai' => null,
            'u_baku' => 0.0,
            'ci' => 0.0,
            'vi' => 0.0,
        ];

        return $budget;
    }

    /**
     * Peringatan sesi.
     *
     * `CalibrationValidator::periksaPeringatanProfil()` membungkus apa pun yang
     * dipulangkan di sini jadi temuan tingkat PERINGATAN yang boleh dilewati
     * admin lewat `abaikan_peringatan` — jadi ini menjelaskan, bukan menahan.
     * Yang benar-benar menahan ketiadaan baris di [hitungPerGrup].
     *
     * @return list<string>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $blok = FlowmeterMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null) {
            return [];
        }

        $varian = $blok['varian_metode'] === null
            ? null
            : VarianMetodeFlowmeter::from($blok['varian_metode']);

        // Bentuknya `['kode' => ..., 'pesan' => ...]`, BUKAN deret string.
        // `CalibrationValidator::periksaPeringatanProfil()` memetakannya dengan
        // `fn (array $p) => temuan(..., $p['kode'], $p['pesan'])`, jadi deret
        // string melempar `TypeError` dan seluruh endpoint `/validasi` pulang
        // 500. Sampai 10 Sep 2026 tidak ada yang tahu: kedua sesi contoh varian
        // UFM selalu punya geometri pipa DAN path configuration, jadi kedua
        // cabang di bawah tidak pernah menyala sekali pun.
        $pesan = [];

        if ($varian === null) {
            return [[
                'kode' => 'flowmeter_varian_tak_dikenal',
                'pesan' => 'Varian metode Flowmeter tidak dikenal, jadi sesi ini tidak bisa dihitung sama '
                    .'sekali. Yang sah `ufm` atau `gravimetri`.',
            ]];
        }

        if ($varian === VarianMetodeFlowmeter::GRAVIMETRI) {
            // Geometri pipa dan path configuration milik varian UFM. Menuntutnya
            // di sesi gravimetri melahirkan peringatan yang TIDAK BISA dipenuhi
            // teknisi — kotaknya memang tidak ada di lembarnya — dan peringatan
            // yang tidak bisa dipenuhi persis yang melatih admin menekan
            // "setujui tetap" tanpa membaca.
            if (($blok['kode_timbangan'] ?? 0) <= 0) {
                $pesan[] = [
                    'kode' => 'flowmeter_timbangan_belum_dipilih',
                    'pesan' => 'Timbangan standar belum dipilih. Kode timbangan menentukan tabel koreksi, '
                        .'U95, kestabilan, DAN drift sekaligus — tanpa dia tidak ada satu titik pun '
                        .'yang bisa dihitung.',
                ];
            }

            return $pesan;
        }

        // --- varian UFM ------------------------------------------------------
        $pesan[] = [
            'kode' => 'flowmeter_varian_ufm_belum_divalidasi',
            'pesan' => 'Sesi ini memakai varian metode UFM (perbandingan langsung dengan Krohne UFC300). '
                .'Kolom VALIDATION kedua workbook masternya KOSONG, sementara master gravimetri '
                .'(ISO 4185) sudah divalidasi Technical Manager 21 Mei 2026 — dan lampiran '
                .'akreditasi LK-285-IDN menyebut static weighing method, bukan perbandingan '
                .'langsung. Lihat docs/pertanyaan-lab-flowmeter-gravimetri.md §14.',
        ];

        if ($blok['diameter_pipa_mm'] === [] || $blok['ketebalan_pipa_mm'] === []) {
            $pesan[] = [
                'kode' => 'flowmeter_geometri_pipa_kosong',
                'pesan' => 'Geometri pipa (diameter luar & ketebalan) belum diisi. Komponen '
                    .'`Cross Section Area` lahir dari sebarannya, jadi tanpa itu budget kehilangan '
                    .'satu komponen — dan U95 yang terbit lebih KECIL dari yang seharusnya.',
            ];
        }

        if ($blok['path_configuration'] === null) {
            $pesan[] = [
                'kode' => 'flowmeter_path_configuration_kosong',
                'pesan' => 'Path Configuration (Z/V/W) belum dipilih. Dia tidak masuk budget, tapi '
                    .'tercetak di sertifikat sebagai cara pemasangan sensor — dan sertifikat tanpa '
                    .'itu tidak bisa diulang orang lain.',
            ];
        }

        return $pesan;
    }

    /**
     * Blok spesifikasi pipa untuk SERTIFIKAT — yang di master ada labelnya tapi
     * tidak ada isinya.
     *
     * ## Kenapa method sendiri, bukan `ringkasanSertifikat()`
     *
     * `CertificateSnapshotBuilder` menaruh hasil `ringkasanSertifikat()` di
     * kunci `timbangan`, dan blade merendernya sebagai delapan bagian bergaya
     * Timbangan. Dipakai untuk Flowmeter, isinya mendarat di tata letak alat
     * lain — nol error, lembar yang salah.
     *
     * ## Yang dicetak, dan kenapa master tidak mencetaknya
     *
     * Sertifikat Flowrate punya LABEL-nya (`B19` Material of pipe, `B20`
     * Outside Diameter, `B21` Thickness of Pipe, `B22` Methode UFM Clamp On —
     * hasil revisi 20 Mei 2026) tapi **sel isinya KOSONG, tanpa rumus**.
     * Sertifikat Totalizer bahkan tidak punya labelnya. Jadi kertas
     * `SIDIK-FM-CAL-0538` memungut keempat besaran ini, sertifikat menyediakan
     * tempatnya, dan tidak ada satu pun yang menyambungkan.
     *
     * Diameter dalam dan luas penampang ikut dicetak karena keduanya yang
     * benar-benar masuk hitungan (`u_A` dan `ci` komponen cross-sectional);
     * mencetak diameter LUAR saja membuat pembaca sertifikat tidak bisa
     * memeriksa ulang angkanya.
     *
     * Balik `null` kalau sesinya bukan varian ini atau bloknya belum ada —
     * blade-nya lalu tidak mencetak apa pun, bukan mencetak baris kosong.
     *
     * @return array<string, mixed>|null
     */
    public function spesifikasiPipaSertifikat(CalibrationSession $sesi): ?array
    {
        $blok = FlowmeterMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null || $blok['mode'] !== $this->mode()) {
            return null;
        }

        $geometri = $this->kalk()->geometriPipa(
            $blok['diameter_pipa_mm'],
            $blok['ketebalan_pipa_mm'],
        );

        return [
            'mode' => $blok['mode'],
            'satuan' => $blok['satuan'],
            'material_pipa' => $blok['material_pipa'],
            'jenis_fluida' => $blok['jenis_fluida'],
            // `Methode UFM Clamp On` di sertifikat master — kertasnya menyebutnya
            // "Path Configuration (Sensor Mounting Methode)".
            'path_configuration' => $blok['path_configuration'],
            'liner_material' => $blok['liner_material'],
            'liner_ketebalan_mm' => $blok['liner_ketebalan_mm'],
            'diameter_luar_mm' => $geometri['diameter_luar'] ?? null,
            'ketebalan_mm' => $geometri['ketebalan'] ?? null,
            'diameter_dalam_mm' => $geometri['diameter_dalam'] ?? null,
            'luas_penampang_mm2' => $geometri['a'] ?? null,
        ];
    }

    public function bentukPindaiFoto(): array
    {
        return [
            // `false`: suhu AIR punya tabelnya SENDIRI (`flow_suhu_awal` /
            // `flow_suhu_akhir`, per titik per ulangan) — bukan kolom suhu di
            // dalam tabel pengukuran, yang justru yang dimaksud penanda ini.
            // Dibiarkan `true`, prompt pembaca foto meminta kolom yang nggak ada
            // di kertasnya, dan yang balik bukan error melainkan angka karangan
            // yang wajar.
            'kolom_suhu' => false,
            // `false`: pembacaan standar juga tabelnya sendiri
            // (`flow_std_pembacaan`), bukan baris di dalam tabel UUT.
            'standar_di_baris' => false,
            // `false` walau `database/ocr-templates/flowmeter_*-v1.json` SUDAH
            // ada: geometrinya masih grid rata hasil `ocr:rangka-geometri` dan
            // belum pernah diadu ke foto formulir asli. Membuka jalur kamera
            // dengan geometri karangan berarti pembaca foto memungut sel yang
            // salah — dan di lembar ini sel yang salah berarti pembacaan standar
            // mendarat di kolom UUT, yang membuat deviasinya NOL.
            //
            // Ditulis EKSPLISIT, bukan dibiarkan jatuh ke bawaan
            // `WorksheetExtractionController::bentukKertas()`: gerbang yang
            // menentukan foto lembar pelanggan boleh keluar HP atau tidak harus
            // diputuskan di profil yang tahu kertasnya.
            'didukung' => false,
            'lokal' => true,
        ];
    }

    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $flowrate = $this->mode() === TabelStandarFlowmeter::MODE_FLOWRATE;

        $bentuk = [
            // Nomor formulir BAWAAN = varian bawaan = gravimetri. Sesi baru
            // memakai gravimetri, jadi lembar yang dicetak tanpa memilih apa
            // pun harus menyebut kertas gravimetri — bukan kertas UFM yang
            // kotaknya beda isi.
            'kode_dokumen' => $this->kodeDokumenVarian()[VarianMetodeFlowmeter::bawaan()->value],
            // Peta lengkapnya ikut dikirim: sisi HP memilihnya dari varian yang
            // dipilih teknisi. Diturunkan di SERVER, bukan disalin ke HP —
            // daftar nomor formulir yang disalin selalu ketinggalan begitu lab
            // merevisi kertasnya, dan gagalnya diam: lembar tercetak mengaku
            // formulir yang bukan dirinya, dan yang ketahuan duluan auditor.
            'kode_dokumen_varian' => $this->kodeDokumenVarian(),
            'kode_metode' => self::KODE_METODE,
            // Nomor LINGKUP yang tercetak di kop kertas (`LK-285-IDN`), bukan
            // nomor BARIS lampiran (30/31) yang dipulangkan
            // [nomorLingkupAkreditasi]. Keduanya angka akreditasi, tapi cuma
            // yang pertama yang dicetak — dan nomor baris di kop lembar kerja
            // kebaca seperti nomor lingkup yang salah.
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => 'Calibration Work Sheet - '.$this->namaAlatKemampuan(),
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => $this->satuanHasil(),
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Isi MODE dan SATUAN alat lebih dulu — mode menentukan budgetnya '
                .($flowrate ? '9' : '8').' komponen, satuan menentukan faktor konversinya ke '
                .$this->satuanHasil().'. Satuan berbasis MASSA (kg, kg/h, kg/min) WAJIB disertai '
                .'densitas fluida UUT; tanpa itu pembacaannya tidak bisa diubah ke '
                .$this->satuanHasil().' sama sekali dan titiknya tidak diterbitkan. Diameter luar & '
                .'ketebalan pipa dibaca '.self::BACAAN_PIPA.' kali — dari sebarannya komponen '
                .'`Cross Section Area` lahir, jadi mengosongkannya MENGECILKAN U95.'
                .($flowrate
                    ? ' Tiap ulangan diisi '.self::PENGULANGAN.' durasi; simpangan bakunya dihitung '
                        .'atas ketiga durasi ulangan ITU, bukan antar-ulangan.'
                    : ''),
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => $flowrate
                    ? 'Master olda Ultrasonic Flowrate (100-300 lpm) 2026.xlsm'
                    : '1.3 Master Olah Data Flowmeter_Ultrasonic Totalizer (1000-1900L) 2026.xlsm',
                'catatan' => ($flowrate ? 'Sembilan' : 'Delapan').' komponen PER TITIK, berlantai CMC '
                    .'`% of reading` dari lampiran LK-285-IDN no. '.($flowrate ? '31' : '30').'. '
                    .($flowrate
                        ? 'Komponen ke-9 "Pengulangan Pembacaan UUT" lahir dari revisi 20 Mei 2026 '
                            .'yang belum masuk workbook Totalizer — lihat pertanyaan lab §3.'
                        : 'Belum memuat komponen "Pengulangan Pembacaan UUT" yang lahir di revisi '
                            .'Flowrate 20 Mei 2026 — ditiru apa adanya, lihat pertanyaan lab §3.'),
            ],
            'bagian' => [
                $this->bagianIdentitas($flowrate),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianPipa(),
                $this->bagianDataKalibrasi($flowrate),
                $this->bagianPenutup(),
            ],
        ];

        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($bentuk, $equipment),
            $equipment,
        );
    }

    /** @return array<string, mixed> */
    private function bagianIdentitas(bool $flowrate): array
    {
        $satuan = $this->tabel()->satuanDikenal($this->mode());

        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Identitas Alat',
            'field' => [
                $this->field('equipment_id', 'Pilih Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Nama Alat', 'teks', sumber: 'otomatis'),
                $this->field('alat_merk', 'Merk', 'teks'),
                $this->field('alat_model', 'Type', 'teks'),
                $this->field('alat_serial_number', 'No. Seri', 'teks'),
                // Mode dikunci ke profilnya, tapi tetap DIKIRIM: `blokSesi()`
                // balik null tanpa dia, dan jalur hitung ulang membaca blok itu
                // apa adanya tanpa tahu profil mana yang memanggilnya.
                $this->field(
                    'spesifikasi_alat.flowmeter.mode', 'Mode', 'pilihan',
                    pilihan: [['nilai' => $this->mode(), 'label' => $flowrate ? 'Flowrate' : 'Totalizer']],
                ),
                // VARIAN METODE — kotak yang MENENTUKAN ANGKA, dan yang paling
                // gampang dilewati karena bawaannya sudah benar. Gravimetri
                // memakai 9/11 komponen budget dan rantai penimbangan; UFM
                // memakai 8/9 komponen dan rantai perbandingan langsung.
                // Kelupaan diisi tidak menghasilkan error — cuma angka dari
                // metode yang tidak dipakai teknisi.
                $this->field(
                    'spesifikasi_alat.flowmeter.varian_metode', 'Metode Kalibrasi', 'pilihan',
                    pilihan: array_map(
                        static fn (VarianMetodeFlowmeter $v): array => [
                            'nilai' => $v->value,
                            'label' => $v->label().($v->tervalidasi() ? '' : ' — master BELUM divalidasi'),
                        ],
                        VarianMetodeFlowmeter::cases(),
                    ),
                ),
                // Kode timbangan cuma punya arti di varian gravimetri, dan dia
                // memilih tabel koreksi, U95, kestabilan, DAN drift sekaligus.
                $this->field(
                    'spesifikasi_alat.flowmeter.kode_timbangan', 'Timbangan Standar', 'pilihan',
                    pilihan: $this->pilihanTimbangan(),
                    tampilKalau: [
                        'kode' => 'spesifikasi_alat.flowmeter.varian_metode',
                        'nilai' => [VarianMetodeFlowmeter::GRAVIMETRI->value],
                    ],
                ),
                // Kotak "Volume Pipa dari Std. ke UUT (V)" di kertas FM-0538.A/.B.
                // Dibaca `FlowmeterMentah` dan dicetak, BELUM masuk budget —
                // pertanyaan lab §5. Tanpa kotak ini nilainya tidak pernah sampai.
                $this->field(
                    'spesifikasi_alat.flowmeter.volume_pipa_l', 'Volume Pipa dari Std. ke UUT (V)', 'angka',
                    satuan: 'L',
                    tampilKalau: [
                        'kode' => 'spesifikasi_alat.flowmeter.varian_metode',
                        'nilai' => [VarianMetodeFlowmeter::GRAVIMETRI->value],
                    ],
                ),
                $this->field(
                    'spesifikasi_alat.flowmeter.satuan', 'Satuan Alat', 'pilihan',
                    pilihan: array_map(
                        static fn (string $s): array => ['nilai' => $s, 'label' => $s],
                        $satuan,
                    ),
                ),
                $this->field('spesifikasi_alat.rentang_ukur', 'Rentang Ukur', 'teks'),
                $this->field('spesifikasi_alat.flowmeter.kapasitas', 'Kapasitas Max.', 'angka', satuan: $this->satuanHasil()),
                $this->field('spesifikasi_alat.flowmeter.resolusi', 'Resolusi Alat', 'angka', satuan: $this->satuanHasil()),
                // Empat kolom kertas yang TIDAK masuk budget — lihat docblock
                // kelas. Dicatat & dicetak, bukan dihitung.
                $this->field('spesifikasi_alat.flowmeter.material_pipa', 'Material Pipa', 'teks'),
                $this->field('spesifikasi_alat.flowmeter.jenis_fluida', 'Jenis Fluida', 'teks'),
                $this->field(
                    'spesifikasi_alat.flowmeter.path_configuration', 'Path Configuration', 'pilihan',
                    pilihan: array_map(
                        static fn (string $p): array => ['nilai' => $p, 'label' => $p.'-Method'],
                        FlowmeterMentah::PATH_CONFIGURATION,
                    ),
                ),
                $this->field('spesifikasi_alat.flowmeter.liner_material', 'Liner — Material', 'teks'),
                $this->field('spesifikasi_alat.flowmeter.liner_ketebalan_mm', 'Liner — Ketebalan', 'angka', satuan: 'mm'),
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
                // Dua kotak lokasi yang saling meniadakan — tanpa `tampil_kalau`,
                // dropdown Ruangan tetap menyimpan pilihan lama walau sedang
                // Insitu, dan sertifikatnya mencetak nama ruang lab yang tidak
                // pernah didatangi.
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
    private function bagianPemilik(): array
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
     * Geometri pipa — blok tingkat-SESI, bukan titik.
     *
     * Diameter luar & ketebalan dibaca `BACAAN_PIPA` kali masing-masing; dari
     * sebaran keduanya `u_A` lahir (`FlowmeterCalculator::geometriPipa`).
     * Satuannya SELALU mm — caliper dan thickness gauge standarnya bersertifikat
     * mm, dan tidak ikut satuan aliran alat pelanggan.
     *
     * @return array<string, mixed>
     */
    private function bagianPipa(): array
    {
        $kolom = [['kode' => 'pembacaan', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => 'mm']];

        return [
            'kode' => 'pipa',
            'halaman' => 1,
            'judul' => 'Geometri Pipa',
            'field' => [],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => 'pipa_diameter',
                    'judul' => 'Diameter Luar Pipa (Digital Caliper)',
                    'satuan' => 'mm',
                    'judul_nilai' => 'Diameter Luar',
                    'judul_pengulangan' => 'Pembacaan',
                    'titik_bisa_diubah' => false,
                    // Berdampingan dengan tabel ketebalan di SATU pita cetak —
                    // keduanya cuma satu baris tiga kolom, dan lembar ini
                    // punya tujuh tabel. Tanpa pengelompokan pita, tabel
                    // terakhirnya turun ke area penanda QR bawah dan lembarnya
                    // tidak bisa dipindai sama sekali
                    // (`CetakLembarKerjaOcrTest::test_sel_nggak_nabrak_penanda_qr_atau_blok_kepala`).
                    'pita_cetak' => 1,
                    'offset_kunci' => self::OFFSET_PIPA,
                    'simpan_ke' => 'spesifikasi_alat.flowmeter.diameter_pipa_mm',
                    'baris' => [[
                        'nomor' => 1, 'titik_ukur' => null,
                        'label' => 'Diameter Luar', 'satuan' => 'mm',
                    ]],
                    'kolom' => $kolom,
                    'pengulangan' => range(1, self::BACAAN_PIPA),
                ],
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => 'pipa_ketebalan',
                    'judul' => 'Ketebalan Dinding Pipa (Ultrasonic Thickness Gauge)',
                    'satuan' => 'mm',
                    'judul_nilai' => 'Ketebalan',
                    'judul_pengulangan' => 'Pembacaan',
                    'titik_bisa_diubah' => false,
                    'pita_cetak' => 1,
                    'offset_kunci' => self::OFFSET_PIPA + 100,
                    'simpan_ke' => 'spesifikasi_alat.flowmeter.ketebalan_pipa_mm',
                    'baris' => [[
                        'nomor' => 1, 'titik_ukur' => null,
                        'label' => 'Ketebalan', 'satuan' => 'mm',
                    ]],
                    'kolom' => $kolom,
                    'pengulangan' => range(1, self::BACAAN_PIPA),
                ],
            ],
        ];
    }

    /**
     * Blok pengukuran — lima tabel ber-`tahap` SAMA, offset kunci berbeda.
     *
     * @return array<string, mixed>
     */
    private function bagianDataKalibrasi(bool $flowrate): array
    {
        $satuan = $this->satuanHasil();
        $baris = array_map(
            static fn (int $n): array => [
                'nomor' => $n,
                'titik_ukur' => null,
                'label' => 'Titik '.$n,
                'satuan' => $satuan,
            ],
            range(1, self::TITIK),
        );

        // UUT Flowrate BERSARANG: `pengulangan` = ulangan (`pembacaan_ke`),
        // `kolom` = durasi (`sensor_ke`). Simpangan bakunya dihitung atas ketiga
        // durasi ulangan ITU — tercampur, komponen "Pengulangan Pembacaan UUT"
        // jadi sebaran antar-ulangan dan keluar jauh lebih besar. Lihat
        // [FlowmeterMentah::deretBersarang].
        $kolomUut = $flowrate
            ? array_map(
                static fn (int $d): array => [
                    'kode' => 'durasi_'.$d,
                    'label' => 'Durasi '.$d,
                    'tipe' => 'angka',
                    'satuan' => $satuan,
                ],
                range(1, self::PENGULANGAN),
            )
            : [['kode' => 'pembacaan', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => $satuan]];

        $tabelSederhana = static fn (
            string $grup, string $judul,
            int $offset, string $satuanKolom, int $ulang, int $pita,
        ): array => [
            'tahap' => 'sesudah_adjustment',
            'grup' => $grup,
            'judul' => $judul,
            'satuan' => $satuanKolom,
            'judul_nilai' => 'Titik',
            'judul_pengulangan' => 'Pembacaan',
            'titik_bisa_diubah' => true,
            'pita_cetak' => $pita,
            'offset_kunci' => $offset,
            // Tujuan isinya dinyatakan EKSPLISIT, dan tanpa ini angkanya hilang
            // diam-diam. Sisi HP data-driven: dia menyusun `measurements[]`
            // dari tabel yang menyebut tujuannya, dan tabel yang diam dianggap
            // nggak punya tempat simpan — kotaknya kegambar, teknisi ngisi
            // penuh, payloadnya terkirim tanpa satu pun pembacaan.
            //
            // Kunci di sini sama persis dengan yang dibaca
            // `CalibrationController::susunBlokFlowmeter()` dan
            // `FlowmeterMentah::dari()`. Ketiganya harus sepakat; yang menjaga
            // kesepakatannya `flowmeter_lembar_test.dart` di repo mobile.
            'simpan_ke' => 'measurements[].'.$grup,
            'baris' => $baris,
            'kolom' => [[
                'kode' => 'pembacaan', 'label' => 'Nilai',
                'tipe' => 'angka', 'satuan' => $satuanKolom,
            ]],
            'pengulangan' => range(1, $ulang),
        ];

        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => 'Measurement',
            'field' => [],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => FlowmeterMentah::PERAN_UUT,
                    'judul' => 'Pembacaan UUT',
                    'satuan' => $satuan,
                    'judul_nilai' => 'Titik',
                    'judul_pengulangan' => 'Ulangan',
                    'titik_bisa_diubah' => true,
                    // Pita SENDIRI: pada mode flowrate tabel ini tiga kolom
                    // durasi kali tiga ulangan — paling lebar di lembar ini,
                    // dan menempelkannya ke tabel lain mendorong keduanya
                    // keluar margin kanan.
                    'pita_cetak' => 2,
                    // Ditulis EKSPLISIT walau nilainya nol — dia tabel dasar,
                    // dan keempat tabel lain digeser darinya (1000..5100).
                    //
                    // Dibiarkan hilang, nilainya tetap nol dan hari ini tetap
                    // benar; yang berbahaya tabel BERIKUTNYA. Kelima tabel di
                    // blok ini ber-`tahap` sama, jadi tabel baru yang juga lupa
                    // menulis offset langsung bertabrakan kuncinya dengan tabel
                    // ini — dan tabrakan itu nggak menerbitkan error, cuma
                    // angka yang diketik di satu kotak muncul di kotak lain.
                    // Di lembar ini akibatnya pembacaan standar tertimpa
                    // pembacaan UUT, dan deviasinya jadi NOL di seluruh sesi.
                    'offset_kunci' => 0,
                    // Sama seperti keempat tabel di bawah — lihat
                    // `$tabelSederhana`. Bedanya cuma bentuk yang lahir di
                    // sisi HP: tabel ini punya TIGA kolom durasi pada varian
                    // Flowrate, jadi deretnya BERSARANG (ulangan → durasi),
                    // sementara yang berkolom satu keluar datar.
                    'simpan_ke' => 'measurements[].'.FlowmeterMentah::PERAN_UUT,
                    'baris' => $baris,
                    'kolom' => $kolomUut,
                    'pengulangan' => range(1, self::PENGULANGAN),
                ],
                // --- varian UFM ---------------------------------------------
                $this->hanyaVarian($tabelSederhana(
                    FlowmeterMentah::PERAN_STD, 'Pembacaan Standar (Krohne UFC300)',
                    self::OFFSET_STD, $satuan, self::PENGULANGAN, 3,
                ), VarianMetodeFlowmeter::UFM),
                // --- varian GRAVIMETRI (ISO 4185) ----------------------------
                // Satuannya KILOGRAM, bukan satuan aliran alat. Itu sebabnya
                // perannya baru dan bukan memakai ulang `flow_std_pembacaan`:
                // dua deret bersatuan beda di kunci yang sama menerbitkan liter
                // yang sebenarnya kilogram, tanpa satu pun error.
                $this->hanyaVarian($tabelSederhana(
                    FlowmeterMentah::PERAN_BERAT_ISI, 'Berat Isi (air tertimbang)',
                    self::OFFSET_BERAT_ISI, 'kg', self::PENGULANGAN, 3,
                ), VarianMetodeFlowmeter::GRAVIMETRI),
                // Blok yang di master bernilai NOL di seluruh sesi, jadi jalur
                // pengurangannya nol kali teruji di sana. Kotaknya tetap ada:
                // sesi pertama yang benar-benar memakai wadah harus punya tempat
                // menuliskannya, bukan menuliskan berat kotor ke kolom isi.
                $this->hanyaVarian($tabelSederhana(
                    FlowmeterMentah::PERAN_BERAT_KOSONG, 'Berat Wadah Kosong (tara)',
                    self::OFFSET_BERAT_KOSONG, 'kg', self::PENGULANGAN, 3,
                ), VarianMetodeFlowmeter::GRAVIMETRI),
                // Ketiganya berdampingan di satu pita: dua tabel suhu bentuknya
                // kembar, dan densitas cuma satu kolom.
                $tabelSederhana(
                    FlowmeterMentah::PERAN_SUHU_AWAL, 'Suhu Air — awal',
                    self::OFFSET_SUHU_AWAL, '°C', self::PENGULANGAN, 4,
                ),
                $tabelSederhana(
                    FlowmeterMentah::PERAN_SUHU_AKHIR, 'Suhu Air — akhir',
                    self::OFFSET_SUHU_AKHIR, '°C', self::PENGULANGAN, 4,
                ),
                // Densitas SATU nilai per titik — dia sifat fluida, bukan
                // pembacaan berulang. Wajib kalau satuannya berbasis massa.
                //
                // Varian GRAVIMETRI tidak punya kotak ini: fluidanya air dan
                // densitasnya sudah DIUKUR piknometer di lab (50,3139 ml, empat
                // titik suhu), bukan diketik teknisi. Kotak yang tetap muncul
                // di situ akan diisi orang, dan angkanya tidak akan dibaca
                // siapa pun.
                $this->hanyaVarian($tabelSederhana(
                    FlowmeterMentah::PERAN_DENSITAS,
                    'Densitas Fluida UUT (wajib kalau satuan berbasis massa)',
                    self::OFFSET_DENSITAS, 'kg/L', 1, 4,
                ), VarianMetodeFlowmeter::UFM),
                ...($flowrate ? [$this->hanyaVarian($tabelSederhana(
                    FlowmeterMentah::PERAN_WAKTU, 'Durasi Penimbangan',
                    self::OFFSET_WAKTU, 'menit', self::PENGULANGAN, 4,
                ), VarianMetodeFlowmeter::GRAVIMETRI)] : []),
            ],
        ];
    }

    /**
     * Tandai satu tabel cuma muncul di varian tertentu.
     *
     * Sisi HP memilih cabangnya dari `spesifikasi_alat.flowmeter.varian_metode`,
     * BUKAN dari nama alat: nama alatnya sama persis untuk kedua varian, dan
     * memilih dari nama berarti selalu memajang cabang yang sama.
     *
     * Kuncinya `tampil_kalau` dengan bentuk yang PERSIS sama dengan yang
     * dipakai `field()` — satu kosakata untuk field dan tabel, supaya sisi HP
     * tidak perlu dua penafsir.
     *
     * @param  array<string, mixed>  $tabel
     * @return array<string, mixed>
     */
    private function hanyaVarian(array $tabel, VarianMetodeFlowmeter $varian): array
    {
        $tabel['tampil_kalau'] = [
            'kode' => 'spesifikasi_alat.flowmeter.varian_metode',
            // Bentuknya `['kode' => .., 'nilai' => [..]]`, bukan `sama_dengan`:
            // itu kontrak yang sudah dipakai `field()` dan ditegakkan
            // `LokasiLembarKerjaSemuaProfilTest`. `nilai` DAFTAR, bukan skalar —
            // varian ketiga yang mendarat nanti tinggal menambah anggotanya.
            'nilai' => [$varian->value],
        ];

        return $tabel;
    }

    /**
     * Timbangan standar yang tersedia untuk MODE ini.
     *
     * Diturunkan dari tabel, bukan diketik: daftar yang ditulis tangan akan
     * ketinggalan begitu lab menambah timbangan, dan yang ketinggalan tidak
     * menerbitkan error — teknisi cuma tidak menemukan alat yang dia pakai lalu
     * memilih yang lain.
     *
     * Mode Totalizer punya empat, Flowrate cuma tiga — Fujitsu tidak ada di
     * workbook Flowrate.
     *
     * @return list<array{nilai: int, label: string}>
     */
    private function pilihanTimbangan(): array
    {
        $pilihan = [];

        foreach ($this->tabelGravimetri()->semuaTimbangan($this->mode()) as $t) {
            // Nama KERTAS ikut dicetak kalau berbeda dari nama workbook.
            // Kertas `SIDIK-FM-CAL-0538.A/B_Rev.3` menyebut slot ketiga
            // `Excellent`; kedua sheet workbook menyebutnya `Mettler`. Teknisi
            // memegang kertas — dropdown yang cuma menyebut satu nama membuat
            // dia tidak menemukan yang dia lihat lalu memilih yang lain, dan
            // pilihan timbangan menentukan tabel koreksi, U95, kestabilan, DAN
            // drift sekaligus. Angkanya tetap keluar dan tetap terlihat wajar.
            // Mana yang benar: pertanyaan lab §15.
            $kertas = $t['nama_kertas'] ?? null;

            $pilihan[] = [
                'nilai' => (int) $t['kode'],
                'label' => sprintf(
                    '%d — %s %s%s (U95 %s kg)',
                    $t['kode'],
                    $t['merk'],
                    $t['tipe'],
                    $kertas === null ? '' : sprintf(' / di kertas: %s', $kertas),
                    $t['u95_kg'],
                ),
            ];
        }

        return $pilihan;
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
                $this->field('teknisi.nama', 'Dikalibrasi Oleh', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Diperiksa Oleh', 'teks', sumber: 'otomatis'),
            ],
        ];
    }

    /** Mesin varian gravimetri — malas, alasannya sama dengan [kalk]. */
    protected function kalkGravimetri(): FlowmeterGravimetriCalculator
    {
        return $this->kalkGravimetri ??= new FlowmeterGravimetriCalculator;
    }

    /**
     * Nomor formulir per VARIAN — kertasnya memang beda, bukan cuma metodenya.
     *
     * Varian UFM: satu kertas untuk dua mode, kotak modenya dicentang teknisi.
     * Varian gravimetri: satu kertas per mode, dan isinya memang beda —
     * Totalizer punya baris `Floware` dan blok `Reading of Standard` tanpa
     * durasi, Flowrate punya `Time ( )` dan tiga kolom durasi per set point.
     *
     * @return array<string, string>
     */
    public function kodeDokumenVarian(): array
    {
        return [
            VarianMetodeFlowmeter::UFM->value => self::KODE_DOKUMEN,
            VarianMetodeFlowmeter::GRAVIMETRI->value => $this->mode() === TabelStandarFlowmeter::MODE_FLOWRATE
                ? self::KODE_DOKUMEN_GRAVIMETRI_FLOWRATE
                : self::KODE_DOKUMEN_GRAVIMETRI_TOTALIZER,
        ];
    }

    /** Tabel standar varian gravimetri — malas, alasannya sama dengan [kalk]. */
    protected function tabelGravimetri(): TabelStandarFlowmeterGravimetri
    {
        return $this->tabelGravimetri ??= new TabelStandarFlowmeterGravimetri;
    }

    protected function kalk(): FlowmeterCalculator
    {
        // Malas, bukan di parameter bawaan konstruktor: `new FlowmeterCalculator`
        // di situ memanggil GumCalculator -> CalibrationProfileRegistry -> profil
        // ini lagi, dan gejalanya `Maximum call stack size` jauh dari sebabnya.
        return $this->kalk ??= new FlowmeterCalculator;
    }

    protected function tabel(): TabelStandarFlowmeter
    {
        return $this->tabel ??= new TabelStandarFlowmeter;
    }
}
