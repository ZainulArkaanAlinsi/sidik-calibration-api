<?php

namespace App\Services\Calibration;

use App\Models\Equipment;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\ChlorineProfile;
use App\Services\Calibration\Profiles\ConductivityProfile;
use App\Services\Calibration\Profiles\PhMeterProfile;
use App\Services\Calibration\Profiles\RefractometerProfile;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\Calibration\Profiles\TurbidimeterProfile;

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

    public function __construct()
    {
        $this->profil = $this->daftarProfil();
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
}
