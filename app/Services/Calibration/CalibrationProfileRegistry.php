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
use App\Services\Calibration\Profiles\RefractometerProfile;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
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
     * Profil default kalau alat/nama nggak ketemu — pH, karena itu jalur yang
     * paling matang & udah kepakai. Alat lama yang `nama_alat_kemampuan`-nya
     * kosong nggak boleh bikin request meledak; dia jatuh ke pH apa adanya
     * (perilaku persis sebelum ada registry).
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
     * Profil dari nama jenis alat (mis. "Turbidimeter"), tanpa peduli
     * huruf besar/kecil & spasi pinggir. Balik [default] kalau nggak ketemu.
     *
     * Cocoknya HARUS persis (sesudah dirapikan), dan fallback-nya pH. Jangan
     * pakai ini buat ngasih tau HP lembar kerjanya apa — buat itu ada
     * [kodeProfilDariNama] yang boleh balik null.
     */
    public function untukNamaAlat(string $nama): CalibrationProfile
    {
        $cari = mb_strtolower(trim($nama));

        foreach ($this->profil as $p) {
            if (mb_strtolower($p->namaAlatKemampuan()) === $cari) {
                return $p;
            }
        }

        return $this->default();
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
     * Beda TAJAM dari [untukNamaAlat] yang di atas: yang itu SELALU balik profil
     * dan jatuh ke pH kalau nggak ketemu (perilaku lama yang sengaja dijaga buat
     * jalur hitung). Buat mobile fallback itu racun — alat generik bakal dikirim
     * sebagai `ph_meter`, teknisi dapat lembar pH buat Buret, dan nggak ada satu
     * pun error yang muncul. `null` di sini artinya "pakai form generik", dan itu
     * jawaban yang bener, bukan kegagalan.
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
    public function kodeProfilDariNama(string $nama): ?string
    {
        $cari = self::rapikanNama($nama);

        if ($cari === '') {
            return null;
        }

        // Cocok persis duluan: nama yang emang identik nggak perlu diadu ke
        // seluruh indeks, dan hasilnya nggak mungkin beda dari penelusuran di
        // bawah (kunci itu pasti nempel di dirinya sendiri).
        if (isset($this->indeksEjaan[$cari])) {
            return $this->indeksEjaan[$cari]->kode();
        }

        foreach ($this->indeksEjaan as $ejaan => $p) {
            if (str_contains($cari, $ejaan)) {
                return $p->kode();
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
