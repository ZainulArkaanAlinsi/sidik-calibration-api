<?php

namespace App\Services\Calibration;

use App\Models\Equipment;
use App\Services\Calibration\Profiles\AutoclaveProfile;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\ChlorineProfile;
use App\Services\Calibration\Profiles\ConductivityProfile;
use App\Services\Calibration\Profiles\DoMeterProfile;
use App\Services\Calibration\Profiles\Enclosure\BathProfile;
use App\Services\Calibration\Profiles\Enclosure\FurnaceProfile;
use App\Services\Calibration\Profiles\Enclosure\InkubatorProfile;
use App\Services\Calibration\Profiles\Enclosure\OvenProfile;
use App\Services\Calibration\Profiles\Enclosure\RefrigeratorProfile;
use App\Services\Calibration\Profiles\GasDetectorProfile;
use App\Services\Calibration\Profiles\PhMeterProfile;
use App\Services\Calibration\Profiles\ProfilGenerik;
use App\Services\Calibration\Profiles\RefractometerProfile;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\Calibration\Profiles\ThermocoupleProfile;
use App\Services\Calibration\Profiles\ThermohygroProfile;
use App\Services\Calibration\Profiles\ThermometerGlassProfile;
use App\Services\Calibration\Profiles\TidsProfile;
use App\Services\Calibration\Profiles\TitsProfile;
use App\Services\Calibration\Profiles\TurbidimeterProfile;
use App\Services\Calibration\Profiles\ViscometerProfile;
use LogicException;

/**
 * Daftar semua profil kalibrasi & pencocokannya ke alat.
 *
 * SATU tempat yang tahu semua jenis alat yang didukung. Nambah alat ke-3..48 =
 * tambah satu baris di [daftarProfil]. Nggak ada `switch besaran` yang
 * berserakan di controller / GumCalculator lagi.
 *
 * Pencocokan lewat `nama_alat_kemampuan` (bukan kategori): pH & Turbidimeter
 * satu kategori sama (`instrumen-analitik`), jadi kategori nggak cukup buat
 * misahin — yang misahin jenis alatnya.
 */
class CalibrationProfileRegistry
{
    /** @var list<CalibrationProfile> */
    private readonly array $profil;

    /**
     * Indeks ejaan nama alat -> profil: kunci udah dirapikan ([rapikanNama])
     * dan DIURUT dari yang paling panjang. Urutannya yang bikin
     * [kodeProfilDariNama] aman: "Chlorine Meter" harus dicoba sebelum "Meter"
     * yang lebih pendek, kalau nggak nama panjang mendarat di profil yang salah.
     *
     * @var array<string, CalibrationProfile>
     */
    private readonly array $indeksEjaan;

    public function __construct()
    {
        $this->profil = $this->daftarProfil();
        $this->indeksEjaan = $this->bangunIndeksEjaan();
    }

    /**
     * Registrasi profil. Tambah profil alat baru DI SINI.
     *
     * @return list<CalibrationProfile>
     */
    private function daftarProfil(): array
    {
        return [
            new PhMeterProfile,
            new TurbidimeterProfile,
            new ChlorineProfile,
            new RefractometerProfile,
            new ConductivityProfile,
            new SpectrophotometerProfile,
            new ViscometerProfile,
            new AutoclaveProfile,
            new DoMeterProfile,
            new GasDetectorProfile,
            new TitsProfile,
            // Saudaranya, "dengan sensor". Bentuk lembar kerjanya jalan penuh;
            // budget ketidakpastiannya SENGAJA kosong sampai workbook olah
            // data TIDS turun dari lab — lihat docblock TidsProfile.
            new TidsProfile,
            // Kalibrasi enclosure — lima jenis, satu mesin hitung. Lihat
            // Profiles\Enclosure\EnclosureProfileBase.
            new OvenProfile,
            new FurnaceProfile,
            new BathProfile,
            new InkubatorProfile,
            new RefrigeratorProfile,
            // Tiga alat suhu yang lembar kerjanya berbentuk PASANGAN deret
            // (standar + UUT dibaca bergantian), lampiran akreditasi
            // "Suhu dan Kelembapan" no. 5, 4, dan 11. Lihat ProfilSuhuPasangan.
            //
            // Urutannya menentukan: `bangunIndeksEjaan()` mengurut kunci dari
            // yang paling panjang, jadi "Thermocouple Thermometer" dicoba
            // sebelum "Thermocouple" — tapi `Thermohygrometer` dan
            // `Thermohygro` juga saling memuat, dan yang panjang harus menang.
            // Diserahkan ke indeks ejaan, bukan ke urutan baris ini.
            new ThermocoupleProfile,
            new ThermometerGlassProfile,
            new ThermohygroProfile,
        ];
    }

    /**
     * Semua profil terdaftar.
     *
     * Dipakai jalur yang harus nyapu SEMUA jenis alat sekaligus — mis. daftar
     * template OCR (`App\Services\Ocr\TemplateLembarKerja::daftar()`). Tanpa ini
     * pemanggilnya kepaksa nyalin daftar profil sendiri, dan salinan itu pasti
     * ketinggalan waktu alat ke-7 ditambahin di sini.
     *
     * @return list<CalibrationProfile>
     */
    public function semua(): array
    {
        return $this->profil;
    }

    /**
     * Profil default buat nama yang KOSONG — pH, karena itu jalur yang paling
     * matang & udah kepakai. Alat lama yang `nama_alat_kemampuan`-nya kosong
     * nggak boleh bikin request meledak; dia jatuh ke pH apa adanya (perilaku
     * persis sebelum ada registry).
     *
     * DULU ini juga jadi jawaban buat nama yang keisi tapi nggak dikenali, dan
     * di situ dia racun: Buret Digital dihitung sebagai pH Meter. Sekarang
     * yang itu dapat `ProfilGenerik` — lihat [untukNamaAlat]. Yang masih
     * manggil [default] langsung tinggal jalur yang emang nggak punya alat
     * sama sekali (`RumusKalibrasi::versiBerlaku()`, `formulaGumPh()`,
     * `PerhitunganBuilder` buat sesi tanpa `equipment`).
     */
    public function default(): CalibrationProfile
    {
        return $this->profil[0];
    }

    /**
     * Profil buat satu alat, dicocokin dari `nama_alat_kemampuan` (fallback ke
     * `nama_alat`). Selalu balik profil — nggak pernah null.
     */
    public function untukAlat(Equipment $equipment): CalibrationProfile
    {
        return $this->untukNamaAlat(
            $equipment->nama_alat_kemampuan ?? $equipment->nama_alat ?? '',
        );
    }

    /**
     * Profil yang MENGHITUNG sesi buat sebuah nama jenis alat. Selalu balik
     * profil — nggak pernah null.
     *
     * ## Aturan cocoknya SATU, sama persis dengan [kodeProfilDariNama]
     *
     * Dua-duanya lewat [cocokkanNama]. Ini bukan kerapian: sampai 24 Agt 2026
     * masing-masing punya salinan aturan sendiri, dan salinannya BEDA — yang
     * ini cocok PERSIS & nggak baca `aliasNama()` sama sekali, yang itu baca
     * alias & nerima kunci yang nempel di tengah nama. Akibatnya diam dan
     * mahal: buat "Temperature Indikator With Sensors" — judul lembar kerja
     * TIDS-nya sendiri — HP dapat lembar TIDS sementara server ngitung pakai
     * PhMeterProfile, jadi `TidsProfile::hitungPerGrup()` yang seluruh
     * penjagaan angkanya bertumpu di situ nggak pernah kepanggil dan U95
     * lahir dari lantai CMC. Bentuk yang sama juga kena "Water Bath",
     * "Turbidimeter Hach", "Incubator", dan tiap nama alat pelanggan yang
     * nggak byte-exact. Kalau nanti aturannya mau diubah, ubah di
     * [cocokkanNama] — jangan bikin salinan ketiga.
     *
     * ## Bedanya cuma di yang NGGAK ketemu
     *
     * [kodeProfilDariNama] balik `null` = "pakai form generik". Di sini nggak
     * ada null, jadi padanannya dua:
     *
     *  - **nama KOSONG** → [default] (pH). Kompatibilitas yang sengaja dijaga:
     *    alat lama yang `nama_alat_kemampuan`-nya belum pernah keisi udah ada
     *    di produksi sejak sebelum kolomnya lahir, dan nama kosong itu "belum
     *    ngaku apa-apa", bukan "jelas bukan pH". Perilakunya persis kayak
     *    sebelum registry ada.
     *  - **nama KEISI tapi nggak dikenali** (Buret Digital, Termometer Gelas,
     *    …) → [ProfilGenerik]. Ini yang DICABUT dari pH: alat yang jelas-jelas
     *    bukan pH nggak boleh dicap rumus `gum-ph` di jejak audit, apalagi
     *    dapat lima komponen budget pH. U95-nya sendiri nggak geser — lihat
     *    docblock `ProfilGenerik`.
     */
    public function untukNamaAlat(string $nama): CalibrationProfile
    {
        $cocok = $this->cocokkanNama($nama);

        if ($cocok !== null) {
            return $cocok;
        }

        return self::rapikanNama($nama) === '' ? $this->default() : new ProfilGenerik;
    }

    /**
     * Profil dari kode stabilnya (`ph_meter` / `turbidimeter` / `chlorine_meter`),
     * atau null kalau
     * nggak ada. Dipakai routing yang ngirim kode eksplisit, bukan nama alat.
     */
    public function untukKode(string $kode): ?CalibrationProfile
    {
        foreach ($this->profil as $p) {
            if ($p->kode() === $kode) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Kode profil lembar kerja dari sebuah nama alat, atau **null** kalau nggak
     * ada yang cocok. Ini yang dikirim `GET /api/categories/{kode}` ke HP lewat
     * field `profil`.
     *
     * Aturan cocoknya SAMA PERSIS dengan [untukNamaAlat] — dua-duanya lewat
     * [cocokkanNama]. Yang beda cuma jawaban waktu nggak ketemu: di sini
     * `null` ("pakai form generik", jawaban yang bener, bukan kegagalan),
     * di sana `ProfilGenerik` / pH buat nama kosong. Lihat [untukNamaAlat].
     */
    public function kodeProfilDariNama(string $nama): ?string
    {
        return $this->cocokkanNama($nama)?->kode();
    }

    /**
     * SATU-SATUNYA tempat yang tahu cara nyocokin nama alat ke profil.
     *
     * Dipakai [kodeProfilDariNama] (lembar kerja yang dikirim ke HP) DAN
     * [untukNamaAlat] (profil yang menghitung). Dua pertanyaan itu wajib
     * dijawab sama — kalau nggak, teknisi ngisi lembar satu alat sementara
     * server ngitung pakai aturan alat lain, dan nggak ada satu pun error yang
     * muncul di sepanjang jalur itu.
     *
     * Pencocokannya sengaja dibikin PERSIS sama dengan yang dulu di HP
     * (`profilLembarKerjaUntuk` di `instrument_picker_screen.dart`), karena
     * jawaban server harus sama dengan jawaban APK lama biar rilis mobile
     * berikutnya nggak diam-diam mindahin alat ke lembar lain:
     *
     *  - huruf besar/kecil diabaikan & spasi dobel dirapikan — `nama_alat` itu
     *    teks bebas dari lampiran akreditasi, bukan enum;
     *  - kunci boleh NEMPEL DI TENGAH nama, karena yang nanya kadang ngoper nama
     *    alat pelanggan ("Visible Spectrofotometer", "Turbidimeter Hach", "pH
     *    Meter Mettler Toledo") — nggak ada satu pun yang cocok persis;
     *  - kunci terpanjang dicoba duluan, lihat [indeksEjaan].
     */
    private function cocokkanNama(string $nama): ?CalibrationProfile
    {
        $cari = self::rapikanNama($nama);

        if ($cari === '') {
            return null;
        }

        // Cocok persis duluan: nama yang emang identik nggak perlu diadu ke
        // seluruh indeks, dan hasilnya nggak mungkin beda dari penelusuran di
        // bawah (kunci itu pasti nempel di dirinya sendiri).
        if (isset($this->indeksEjaan[$cari])) {
            return $this->indeksEjaan[$cari];
        }

        foreach ($this->indeksEjaan as $ejaan => $p) {
            if (str_contains($cari, $ejaan)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Bangun [indeksEjaan] dari `namaAlatKemampuan()` + `aliasNama()` tiap
     * profil. Dipanggil sekali di konstruktor.
     *
     * Ejaan kembar antar-profil bikin `LogicException` — bukan menang-menangan
     * diam-diam. Dua profil yang ngaku ejaan sama artinya alat itu bisa mendarat
     * di lembar mana aja tergantung urutan [daftarProfil], dan bug seperti itu
     * cuma kelihatan waktu teknisi udah pegang lembar yang salah di lapangan.
     *
     * @return array<string, CalibrationProfile>
     */
    private function bangunIndeksEjaan(): array
    {
        $indeks = [];

        foreach ($this->profil as $p) {
            foreach ([$p->namaAlatKemampuan(), ...$p->aliasNama()] as $ejaan) {
                $kunci = self::rapikanNama($ejaan);

                if ($kunci === '') {
                    continue;
                }

                if (isset($indeks[$kunci]) && $indeks[$kunci]->kode() !== $p->kode()) {
                    throw new LogicException(
                        "Ejaan nama alat '{$kunci}' diklaim dua profil: "
                        ."{$indeks[$kunci]->kode()} & {$p->kode()}.",
                    );
                }

                $indeks[$kunci] = $p;
            }
        }

        // Terpanjang duluan — alasannya di docblock [indeksEjaan].
        uksort($indeks, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $indeks;
    }

    /**
     * Bentuk baku nama alat buat dibandingin: huruf kecil semua, spasi beruntun
     * jadi satu, pinggirannya dipangkas. Sama persis dengan yang dipakai HP.
     */
    private static function rapikanNama(string $nama): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($nama)));
    }
}
