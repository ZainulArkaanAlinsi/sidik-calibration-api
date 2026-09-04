<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\ProfilGenerik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aturan yang harus dipenuhi SETIAP lembar kerja, bukan cuma yang lagi digarap.
 *
 * ## Kenapa disapu semua, bukan diuji satu-satu
 *
 * Tiga kali berturut-turut yang bolong itu profil yang NGGAK lagi disentuh:
 *
 *  - Enclosure nggak punya `equipment_id`, jadi sesinya nggak bisa dikirim
 *    sama sekali — ketahuan berminggu-minggu sesudah profilnya jadi.
 *  - `lokasi_nama`/`room_id` (permintaan 2) awalnya cuma nempel di 2 dari 12
 *    profil, padahal permintaannya "di SEMUA lembar kerja".
 *  - Nomor formulir kosong di dua profil, dan nggak ada yang gagal karenanya.
 *
 * Pola bersamanya: nggak satu pun bikin error. Lembarnya tetap terbit, cuma
 * bolong. Test per-profil nggak pernah menangkap ini karena yang bolong justru
 * profil yang nggak punya test.
 *
 * Jadi daftarnya diambil dari REGISTRY, bukan diketik di sini. Profil ke-13
 * yang ditambahkan besok langsung ikut diuji tanpa ada yang perlu ingat.
 */
class SemuaProfilLembarKerjaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Semua profil BERLEMBAR yang terdaftar.
     *
     * [ProfilGenerik] sengaja NGGAK ikut: dia satu-satunya yang memang nggak
     * punya lembar kerja, dan `bentukLembarKerja()`-nya melempar. Kontraknya
     * diuji sendiri di [test_profil_generik_nggak_punya_lembar_dan_ditolak_endpoint]
     * — bukan dikecualikan diam-diam.
     *
     * Daftarnya dibaca dari REGISTRY, bukan diketik di sini. Profil yang
     * ditambahkan besok langsung ikut diuji tanpa ada yang perlu ingat.
     *
     * @return array<string, array{CalibrationProfile}>
     */
    public static function semuaProfil(): array
    {
        // `semua()` itu jalan resminya, dan docblock-nya menyebut kasus ini
        // persis: "dipakai jalur yang harus nyapu SEMUA jenis alat sekaligus".
        // Dulu di sini Reflection ke properti privat `profil` — memulangkan isi
        // yang sama, tapi lewat pintu yang nggak dijanjikan siapa-siapa.
        $profil = app(CalibrationProfileRegistry::class)->semua();

        $hasil = [];
        foreach ($profil as $p) {
            $hasil[$p->kode()] = [$p];
        }

        // Penjaga lantai. Sweep yang daftarnya datang dari luar punya satu cara
        // gagal yang nggak bersuara: daftarnya menyusut, kasusnya ikut sedikit,
        // dan PHPUnit tetap menulis "OK" — cuma dengan lebih sedikit yang
        // diperiksa. Nol profil malah "lolos" paling meyakinkan, karena nggak
        // ada satu pun assertion yang sempat gagal.
        //
        // Angka 17 bukan target yang harus dikejar, tapi LANTAI: registry cuma
        // boleh nambah. Kalau suatu hari ada profil yang memang dicabut,
        // turunkan angkanya SEKALIAN dengan pencabutannya — supaya penyusutan
        // itu jadi keputusan yang tercatat, bukan kejadian yang kelewat.
        //
        // Pola ini disalin dari `LokasiLembarKerjaSemuaProfilTest`, yang sudah
        // memakainya lebih dulu.
        if (count($hasil) < 17) {
            throw new \RuntimeException(
                'Registry cuma memulangkan '.count($hasil).' profil, di bawah lantai 17. '
                .'Sweep di berkas ini jadi nggak ngecek apa-apa buat yang hilang.',
            );
        }

        return $hasil;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
    }

    /**
     * Semua kode field dalam satu lembar, digabung dari seluruh bagian.
     *
     * @return list<string>
     */
    private function kodeField(CalibrationProfile $profil): array
    {
        $kode = [];

        foreach ($profil->bentukLembarKerja()['bagian'] ?? [] as $bagian) {
            foreach ($bagian['field'] ?? [] as $field) {
                $kode[] = $field['kode'] ?? '(tanpa kode)';
            }
        }

        return $kode;
    }

    /**
     * INTI: tanpa `equipment_id`, sesinya nggak bisa dikirim sama sekali.
     *
     * Tombol kirim menahan kalau alat belum dipilih, jadi lembar tanpa kotak
     * ini bikin teknisi mentok tanpa tahu apa yang kurang.
     */
    #[DataProvider('semuaProfil')]
    public function test_setiap_lembar_punya_kotak_pilih_alat(CalibrationProfile $profil): void
    {
        $this->assertContains(
            'equipment_id',
            $this->kodeField($profil),
            "Profil `{$profil->kode()}` nggak punya `equipment_id` — sesinya nggak bisa dikirim.",
        );
    }

    /**
     * Permintaan 2: Inlab pilih ruangan, Insitu tulis nama PT. **Di semua lembar.**
     */
    #[DataProvider('semuaProfil')]
    public function test_setiap_lembar_punya_kotak_lokasi(CalibrationProfile $profil): void
    {
        $kode = $this->kodeField($profil);

        $this->assertContains('room_id', $kode, "Profil `{$profil->kode()}` nggak punya pilihan ruangan (Inlab).");
        $this->assertContains('lokasi_nama', $kode, "Profil `{$profil->kode()}` nggak punya isian nama tempat (Insitu).");
    }

    /**
     * Dua kotak lokasi nggak boleh tampil barengan.
     *
     * Bug sertifikat Insitu lahirnya persis dari sini: dropdown Ruangan tetap
     * menyimpan pilihan lama walau sedang Insitu, lalu nilai itu ikut terkirim
     * dan tercetak di sertifikat sebagai tempat kalibrasi yang salah.
     */
    #[DataProvider('semuaProfil')]
    public function test_dua_kotak_lokasi_saling_meniadakan(CalibrationProfile $profil): void
    {
        $field = [];
        foreach ($profil->bentukLembarKerja()['bagian'] ?? [] as $bagian) {
            foreach ($bagian['field'] ?? [] as $f) {
                $field[$f['kode'] ?? ''] = $f;
            }
        }

        $this->assertSame(
            ['onsite'],
            $field['lokasi_nama']['tampil_kalau']['nilai'] ?? null,
            "Profil `{$profil->kode()}`: nama tempat harus cuma muncul waktu Insitu.",
        );
        $this->assertSame(
            ['lab'],
            $field['room_id']['tampil_kalau']['nilai'] ?? null,
            "Profil `{$profil->kode()}`: pilihan ruangan harus cuma muncul waktu Inlab.",
        );
    }

    /**
     * Nggak ada kode field kembar dalam satu lembar.
     *
     * Ini yang paling diam dari semuanya. Kotak kembar nggak bikin error apa
     * pun: dua kotak digambar, dua-duanya bisa diketik, dan yang sampai ke
     * server cuma SATU — yang belakangan menimpa yang duluan. Teknisi lihat
     * angkanya masuk, lalu angka itu nggak ada di sertifikat, dan nggak ada
     * satu pun pesan yang menjelaskan ke mana perginya.
     */
    #[DataProvider('semuaProfil')]
    public function test_nggak_ada_kode_field_kembar(CalibrationProfile $profil): void
    {
        $kode = $this->kodeField($profil);

        $kembar = array_keys(array_filter(array_count_values($kode), static fn (int $n): bool => $n > 1));

        $this->assertSame(
            [],
            $kembar,
            "Profil `{$profil->kode()}` punya kode field kembar: ".implode(', ', $kembar),
        );
    }

    /**
     * `tabel[].peran` cuma boleh `standar` atau `uut`.
     *
     * ## Kenapa kunci ini nggak boleh dipakai sebagai label bebas
     *
     * Di HP, `peran` yang bukan null berarti satu hal yang sangat spesifik:
     * *"lembar ini membaca DUA deret per titik — standar & UUT"*
     * (`TabelHasil.berpasangan`, dan lewat dia `LembarKerja.berpasangan`).
     * Nilainya membelokkan SELURUH lembar ke jalur pasangan, yang mengirim
     * `standar`/`uut` per titik dan bukan `pembacaan`, DAN mengunci baris ke
     * offset parameter alih-alih ke titik ukurnya.
     *
     * Lembar Timbangan sempat memakainya sebagai nama blok (`akurasi`,
     * `keterulangan`) — tujuan yang sudah dilayani `grup`, seperti ketiga tabel
     * Spectrophotometer. Akibatnya dua: payload berangkat tanpa satu pun
     * nominal, dan kedua tabelnya bentrok kunci baris lagi karena
     * `_offsetParameter(null)` memulangkan 0 untuk dua-duanya. Nol error di
     * kedua sisi; ketahuan waktu payload HP-nya diadu ke bentuk lembarnya.
     *
     * Ditulis sebagai aturan umum, bukan pengecualian buat satu profil: kunci
     * ini bakal kelihatan seperti label bebas lagi buat alat ke-22.
     */
    #[DataProvider('semuaProfil')]
    public function test_peran_tabel_cuma_buat_lembar_pasangan(CalibrationProfile $profil): void
    {
        foreach ($profil->bentukLembarKerja()['bagian'] ?? [] as $bagian) {
            foreach ($bagian['tabel'] ?? [] as $tabel) {
                if (! array_key_exists('peran', $tabel) || $tabel['peran'] === null) {
                    continue;
                }

                $this->assertContains(
                    $tabel['peran'],
                    ['standar', 'uut'],
                    "Profil `{$profil->kode()}` memakai `peran` = `{$tabel['peran']}` sebagai label blok. "
                    .'Di HP kunci itu berarti lembar pasangan standar/UUT dan membelokkan seluruh jalur '
                    .'kirimnya. Pakai `grup`.',
                );
            }
        }
    }

    /**
     * Nomor formulir ada, atau memang belum ketahuan — dan yang belum ketahuan
     * ditulis di sini, bukan dibiarkan lolos diam-diam.
     *
     * Lembar kerja lab terakreditasi tanpa nomor formulir itu temuan audit.
     * Yang masih `null` cuma boleh yang kertasnya beneran belum ada di tangan;
     * begitu kertasnya datang, hapus dia dari daftar ini — dan test bakal
     * merah kalau ada yang menambahkan `null` baru tanpa alasan.
     */
    #[DataProvider('semuaProfil')]
    public function test_nomor_formulir_ada_kecuali_yang_kertasnya_belum_ada(CalibrationProfile $profil): void
    {
        // Kertasnya beneran belum pernah dikirim lab. Bukan kelupaan.
        //
        // Ketiga alat suhu yang mendarat 26 Agt 2026 masuk sini dengan alasan
        // yang SAMA seperti TITS dulu: ketiga workbook master cuma memuat
        // `SIDIK-FM-CAL-2403_Rev. 0` di footer sheet `SERTIFIKAT`, dan itu
        // formulir SERTIFIKAT yang dipakai bersama semua alat — bukan nomor
        // lembar kerjanya. Menaruh nomor karangan di lembar yang ikut diaudit
        // lebih mahal daripada kolom kosong yang jelas kosong.
        // `timbangan` masuk 31 Agt 2026 dengan alasan yang SAMA, dan sudah
        // dicek: ketiga workbook master Timbangan disapu buat pola
        // `SIDIK-FM-…`, dan yang ketemu cuma SATU — `SIDIK-FM-CAL-2403_Rev. 0`
        // di footer sheet SERTIFIKAT, formulir sertifikat bersama itu lagi.
        // Nomor lembar kerjanya sendiri memang belum pernah dikirim.
        // `timer_stopwatch`, `centrifuge`, dan `tachometer` masuk 1 Sep 2026
        // dengan alasan yang SAMA, dan sudah dicek dengan cara yang sama:
        // ketiga workbook master kelompok "Waktu dan Frekuensi" disapu buat
        // pola `SIDIK-FM-…`, dan yang ketemu cuma SATU di masing-masing —
        // `SIDIK-FM-CAL-2403_Rev. 0`, formulir sertifikat bersama itu lagi
        // (Tachometer & Timer di footer sheet `SERTIFIKAT`, Centrifuge di
        // `INPUT DATA!B78`). Nomor lembar kerjanya sendiri belum pernah
        // dikirim. Yang ADA cuma nomor Instruksi Kerjanya
        // (`SIDIK-IK-CAL-0509_Rev.6` & `SIDIK-IK-CAL-0511_Rev.6`), dan itu
        // masuk lewat `kodeMetode()`, bukan `kode_dokumen`.
        // `micrometer` ikut daftar ini 4 Sep 2026 dengan bukti yang sama:
        // sapuan `SIDIK-FM-` ke DELAPAN sheet × EMPAT workbook master
        // (0-25/25-50/50-75/75-100 mm) cuma memulangkan satu nomor —
        // `SIDIK-FM-CAL-2403_Rev. 0` di footer sheet `SERTIFIKAT`, formulir
        // sertifikat bersama itu lagi. Nomor lembar kerjanya sendiri belum
        // pernah dikirim.
        $belumAdaKertasnya = [
            'gas_detector', 'thermocouple', 'thermometer_glass', 'thermohygro', 'timbangan',
            'timer_stopwatch', 'centrifuge', 'tachometer', 'micrometer',
        ];

        // `timbangan` di daftar itu SETENGAH benar, dan bedanya perlu ditulis.
        //
        // Kertasnya sudah ada untuk metode SUBSTITUSI
        // (`SIDIK-FM-CAL-0508.A_Rev.4`, dikirim 31 Agt 2026), tapi lembar itu
        // memasang nomor formulirnya PER VARIAN — dan variannya diturunkan dari
        // alat. Panggilan tanpa alat di baris bawah memang memulangkan null,
        // jadi dia tetap lolos di sini.
        //
        // Yang menjaga sisi satunya `TimbanganSesiTest::
        // test_nomor_formulir_cuma_di_varian_yang_kertasnya_ada`: di situ
        // nomornya WAJIB muncul buat alat > 200 kg dan WAJIB null buat kg/gram.
        // Keluarkan `timbangan` dari daftar ini kalau kertas kg/gram sudah
        // turun juga — jangan sebelum itu.

        $nomor = $profil->bentukLembarKerja()['kode_dokumen'] ?? null;

        if (in_array($profil->kode(), $belumAdaKertasnya, true)) {
            $this->assertNull(
                $nomor,
                "Profil `{$profil->kode()}` sekarang punya nomor formulir — keluarkan dia dari daftar `belumAdaKertasnya`.",
            );

            return;
        }

        $this->assertIsString($nomor, "Profil `{$profil->kode()}` belum punya nomor formulir.");
        // Akhiran huruf opsional (`0508.A`) — formulir lab ini memakainya buat
        // membedakan varian metode pada nomor dasar yang sama. Bukan pelonggaran
        // spekulatif: `SIDIK-FM-CAL-0508.A_Rev.4` ada di tangan.
        $this->assertMatchesRegularExpression(
            '/^SIDIK-FM-CAL-\d{4}(\.[A-Z])?_Rev\.\d+$/',
            $nomor,
            "Nomor formulir `{$profil->kode()}` nggak berbentuk `SIDIK-FM-CAL-NNNN[.X]_Rev.N`.",
        );
    }

    /**
     * [ProfilGenerik] memang nggak punya lembar — dan yang MENJAGA itu bukan
     * lemparannya, tapi endpoint yang menyaringnya lebih dulu.
     *
     * Sampai 24 Agt 2026 `untukNamaAlat()` jatuh ke pH buat nama apa pun yang
     * nggak dikenali, jadi `?equipment_id=` sebuah Buret memulangkan lembar
     * buffer 4/7/10 dengan status 200. Teknisi mengisi tiga titik pH untuk
     * buret, sesinya terkirim, dan nol error di sepanjang jalur itu.
     *
     * Yang diuji di sini dua-duanya sekaligus:
     *
     *  1. Lemparannya masih ada — kalau seseorang "membetulkannya" jadi
     *     memulangkan lembar kosong, kegagalan diamnya balik lagi.
     *  2. Endpoint nggak pernah SAMPAI ke lemparan itu: dia menjawab 422
     *     dengan alasan yang bisa dibaca teknisi, bukan 500.
     *
     * Alat yang baru ditambahkan teknisi sendiri (permintaan 1) lewat jalur
     * ini, jadi inilah lembar yang paling mungkin dibuka tanpa ada yang pernah
     * memeriksanya.
     */
    /**
     * Sumber master yang WAJIB diisi profilnya sendiri.
     *
     * Cuma satu, dan bedanya penting: daftar thermohygro itu per-LEMBAR —
     * yang boleh dipilih ditentukan cetakan formulirnya, dan cuma profil yang
     * tau isinya. Sisanya data per-organisasi yang ditarik aplikasi dari
     * endpoint master masing-masing.
     *
     * @var array<string, string>
     */
    private const SUMBER_DIISI_PROFIL = [
        'master_thermohygro' => 'daftar per-lembar dari cetakan formulir; cuma profil yang tau isinya',
    ];

    /**
     * Sumber master yang SENGAJA pulang kosong dari `bentukLembarKerja()`.
     *
     * Ketiganya data per-organisasi, jadi bentuk lembar nggak boleh
     * membawanya: dua lab beda bakal dapat lembar yang sama tapi daftar isi
     * yang beda, dan itu berarti bentuknya nggak bisa di-cache bareng.
     *
     * @var array<string, string>
     */
    private const SUMBER_DITARIK_APLIKASI = [
        'master_alat' => 'alat pelanggan per organisasi — GET /api/equipments',
        'master_ruangan' => 'ruangan lab per organisasi — GET /api/rooms',
        'master_metode' => 'metode kalibrasi per organisasi — GET /api/calibration-methods',
    ];

    /**
     * Kerangka bagian yang sama di SEMUA lembar: alat > pemilik > standar >
     * ...pengukuran... > penutup.
     *
     * ## Kenapa keseragamannya diuji, bukan dibiarkan
     *
     * Tiap lembar lahir dari formulir kertasnya sendiri, dan kalau urutan
     * bagiannya ikut kertas apa adanya, tiap lembar punya susunan sendiri. Di
     * kertas itu wajar — orang megang satu formulir. Di layar nggak: teknisi
     * yang sehari nggarap Bath, TIDS, lalu Autoclave dapat tiga susunan beda,
     * dan tiap ganti alat dia nyari letak blok standar dari nol.
     *
     * Dua yang melenceng, dan dua-duanya ketahuan cuma waktu ditaruh
     * bersebelahan — bukan dari baca kodenya:
     *
     *  - TIDS naruh kotak dryblock SEBELUM blok `Standard used:`, ngikut
     *    `SIDIK-FM-CAL-0506 Rev.4`.
     *  - Autoclave naruh General Information paling atas dan blok standar
     *    SESUDAH tabel hasil — jadi satu-satunya lembar yang identitas alatnya
     *    bukan yang pertama dibaca, sekaligus satu-satunya yang nanya "standar
     *    mana yang dipakai" sesudah angkanya terlanjur diketik.
     *
     * ## Yang TIDAK berubah
     *
     * Kertasnya, dan lembar cetak buat dipindai. Jalur cetak punya definisinya
     * sendiri (`bentukPindaiFoto()` + template OCR) dan nggak baca urutan array
     * `bagian` sama sekali. Jadi lembar terkendali tetap kebaca sama persis
     * kayak dokumen yang didaftarkan; yang diseragamkan cuma urutan baca di
     * LAYAR.
     *
     * ## Kenapa disapu, bukan diuji per profil
     *
     * Yang melenceng selalu profil yang nggak lagi disentuh. Dua di atas berdiri
     * berbulan-bulan tanpa satu pun test berubah merah, karena nggak ada test
     * yang pernah mengadu satu lembar ke lembar lain.
     */
    #[DataProvider('semuaProfil')]
    public function test_urutan_bagian_seragam_di_semua_lembar(CalibrationProfile $profil): void
    {
        $kode = array_column($profil->bentukLembarKerja()['bagian'] ?? [], 'kode');

        $this->assertNotEmpty($kode, "Lembar {$profil->kode()} nggak punya bagian sama sekali.");

        $this->assertSame(
            'identitas_alat',
            $kode[0] ?? null,
            "Bagian pertama lembar {$profil->kode()} bukan `identitas_alat`, tapi `".($kode[0] ?? '(kosong)')."`.\n\n"
            .'Alat yang dikalibrasi itu yang dipilih paling awal — dropdown-nya yang nentuin bentuk sisa '
            .'lembarnya. Urutan kebaca: '.implode(' > ', $kode),
        );

        // Autoclave nyebutnya `informasi_umum` karena di kertasnya blok ini
        // judulnya "General Information" dan ikut bawa Receive/Calibration Date.
        // Isinya peran yang sama: pelanggan, alamat, dan lokasi kalibrasi.
        $this->assertContains(
            $kode[1] ?? null,
            ['pemilik', 'informasi_umum'],
            "Bagian kedua lembar {$profil->kode()} bukan blok pemilik. Urutan kebaca: ".implode(' > ', $kode),
        );

        $posisiStandar = array_search('usage_check', $kode, true);

        $this->assertNotFalse(
            $posisiStandar,
            "Lembar {$profil->kode()} nggak punya bagian `usage_check` — nggak ada tempat milih standar "
            .'yang dipakai. Urutan kebaca: '.implode(' > ', $kode),
        );

        // INTI: standar dipilih SEBELUM ngukur, bukan sesudah.
        //
        // Bukan cuma soal rapi. Blok `usage_check` itu yang nentuin kalibrator
        // sesi, dan kalibratornya yang nentuin koreksi tiap pembacaan. Nanya
        // sesudah tabelnya penuh bikin teknisi ngisi puluhan kotak angka dengan
        // asumsi standar yang belum pernah dia nyatakan.
        //
        // Yang diadu ISI bagian sebelum blok standar, bukan indeks tetap. Dua
        // alasan indeks tetap nggak kepakai:
        //
        //  - Autoclave sah punya DUA blok konteks — `informasi_umum` (pelanggan
        //    & tanggal) dan `kondisi_lokasi` (lokasi, kondisi lingkungan,
        //    centang thermohygro) — karena di kertasnya General Information
        //    emang dua blok. Maksa jadi satu bikin ISI formulir terkendalinya
        //    berubah, bukan cuma urutannya.
        //  - "Bagian pengukuran" nggak bisa ditebak dari adanya `tabel`: lima
        //    lembar Enclosure naruh grid termokopelnya di `grid_sensor` tingkat
        //    lembar, bukan di dalam bagian mana pun.
        //
        // Jadi yang dipatok daftar bagian yang BOLEH duluan — semuanya konteks
        // yang dibaca sebelum alat pertama disentuh.
        $konteks = ['identitas_alat', 'pemilik', 'informasi_umum', 'kondisi_lokasi'];

        $duluan = array_slice($kode, 0, $posisiStandar);
        $bukanKonteks = array_values(array_diff($duluan, $konteks));

        $this->assertSame(
            [],
            $bukanKonteks,
            "Lembar {$profil->kode()} nanya standar SESUDAH bagian yang bukan konteks.\n\n"
            .'Yang nyelak di depan blok standar: '.implode(', ', $bukanKonteks)."\n"
            .'Standar dipilih SEBELUM ngukur — dia yang nentuin koreksi tiap pembacaan.'."\n"
            .'Urutan kebaca: '.implode(' > ', $kode),
        );

        $this->assertSame(
            'penutup',
            $kode[array_key_last($kode)],
            "Bagian terakhir lembar {$profil->kode()} bukan `penutup` — tanda tangan & catatan selalu "
            .'di bawah. Urutan kebaca: '.implode(' > ', $kode),
        );
    }

    /**
     * Tiap `sumber: master_*` wajib kepilih salah satu: diisi profil, atau
     * ditarik aplikasi — BERIKUT alasannya.
     *
     * Ini penjaga cakupan, bukan penjaga isi. Yang ditahan bukan dropdown yang
     * kosong hari ini, tapi sumber master KEDELAPAN yang lahir besok tanpa ada
     * yang memutuskan dia masuk golongan mana — persis cara `master_thermohygro`
     * di tujuh lembar suhu berakhir nggak pernah diisi siapa pun: nggak ada
     * yang salah, cuma nggak ada yang merasa kebagian.
     *
     * Kalau ini merah, jawabannya bukan menambahkan nama ke daftar biar hijau.
     * Jawabannya: siapa yang mengisi dropdown ini, dan kalau nggak ada, kenapa.
     */
    public function test_tiap_sumber_master_punya_golongan_dan_alasan(): void
    {
        $tanpaGolongan = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            foreach ($profil->bentukLembarKerja(true)['bagian'] ?? [] as $bagian) {
                foreach ($bagian['field'] ?? [] as $field) {
                    $sumber = $field['sumber'] ?? null;

                    if (! is_string($sumber) || ! str_starts_with($sumber, 'master_')) {
                        continue;
                    }

                    if (isset(self::SUMBER_DIISI_PROFIL[$sumber]) || isset(self::SUMBER_DITARIK_APLIKASI[$sumber])) {
                        continue;
                    }

                    $tanpaGolongan[] = sprintf('%s.%s (sumber: %s)', $profil->kode(), $field['kode'], $sumber);
                }
            }
        }

        $tanpaGolongan = array_values(array_unique($tanpaGolongan));
        sort($tanpaGolongan);

        $this->assertSame(
            [],
            $tanpaGolongan,
            "Ada `sumber: master_*` yang belum digolongkan:\n  ".implode("\n  ", $tanpaGolongan)
            ."\n\nMasukkan ke SUMBER_DIISI_PROFIL (dan beneran isi pilihannya di profilnya) "
            .'atau ke SUMBER_DITARIK_APLIKASI berikut endpoint yang menyediakannya.',
        );
    }

    public function test_profil_generik_nggak_punya_lembar_dan_ditolak_endpoint(): void
    {
        $this->expectException(\LogicException::class);

        try {
            $this->actingAs(User::factory()->create())
                ->getJson('/api/calibrations/lembar-kerja?instrumen=Buret+Digital')
                ->assertStatus(422)
                ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'form generik'));
        } catch (\Throwable $e) {
            $this->fail('Endpoint harus menjawab 422, bukan melempar: '.$e->getMessage());
        }

        // Baru di sini lemparannya dibuktikan — langsung ke profilnya, bukan
        // lewat HTTP.
        (new ProfilGenerik)->bentukLembarKerja();
    }

    /**
     * Nomor formulir nggak boleh dipakai dua profil yang beda.
     *
     * Kalau kembar, lembar tercetaknya mengaku formulir yang bukan dirinya —
     * dan yang ketahuan duluan biasanya auditor, bukan kita.
     */
    public function test_nomor_formulir_nggak_dipakai_dua_profil(): void
    {
        $pakai = [];

        foreach (self::semuaProfil() as [$profil]) {
            $nomor = $profil->bentukLembarKerja()['kode_dokumen'] ?? null;

            if ($nomor === null) {
                continue;
            }

            $pakai[$nomor][] = $profil->kode();
        }

        // Kelima profil enclosure memang SATU formulir (`0504`) — itu yang
        // tertulis di kertasnya, bukan kelalaian. Yang dilarang: dua ALAT BEDA
        // berbagi nomor.
        $kembar = array_filter(
            $pakai,
            static fn (array $kode, string $nomor): bool => count($kode) > 1 && $nomor !== 'SIDIK-FM-CAL-0504_Rev.3',
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertSame([], $kembar, 'Nomor formulir dipakai lebih dari satu profil: '.json_encode($kembar));
    }
}
