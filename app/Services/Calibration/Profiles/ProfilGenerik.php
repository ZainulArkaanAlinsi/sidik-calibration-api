<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;
use LogicException;

/**
 * Profil buat alat yang **nggak punya jalur khusus** — jawaban jalur hitung
 * buat nama alat yang `CalibrationProfileRegistry::kodeProfilDariNama()`
 * jawabnya `null`.
 *
 * ## Kenapa ini lahir
 *
 * Sebelum ini `untukNamaAlat()` jatuh ke **PhMeterProfile** buat nama apa pun
 * yang nggak dikenali. Fallback itu ditulis buat SATU keadaan yang wajar —
 * alat lama yang `nama_alat_kemampuan`-nya belum keisi sama sekali, yang
 * nggak boleh bikin request meledak — tapi yang kena jauh lebih luas: Buret
 * Digital, Termometer Gelas, Pressure Gauge, dan 27 alat lampiran akreditasi
 * lain semuanya dihitung sebagai pH Meter. Yang bocor bukan cuma label:
 *
 *  - jejak audit sesinya distempel rumus `gum-ph` (`RumusKalibrasi`), jadi
 *    pertanyaan asesor "sesi buret ini dihitung pakai aturan apa" dijawab
 *    dengan aturan alat lain — dan nomor versi yang SALAH lebih buruk
 *    daripada nomor yang nggak ada;
 *  - `PhMeterProfile::komponenBudget()` bakal masang LIMA komponen budget pH
 *    begitu baris kemampuannya kebetulan punya konstanta budget lengkap;
 *  - `standarPerTitik()`-nya nyodorin buffer pH 4/7/10, dan `desimalSuhuEnv()`
 *    maksa format sel master pH (`21,0`) ke sertifikat alat yang master-nya
 *    nggak pernah ada.
 *
 * ## Kenapa "generik", bukan melempar exception
 *
 * `untukAlat()` juga kepanggil di jalur BACA — `CalibrationResource`,
 * `CertificateSnapshotBuilder`, `CalibrationValidator` — buat sesi yang udah
 * ada di produksi. Melempar di situ nggak nutup lubang apa pun; dia cuma
 * ngubah kegagalan diam jadi HTTP 500 di layar orang yang lagi buka sertifikat
 * lama. Jadi yang ditolak cuma hal yang emang nggak punya jawaban benar:
 * [bentukLembarKerja] melempar, karena "lembar kerja alat ini" itu pertanyaan
 * yang jawabannya `null` di sisi HP dan nggak boleh dijawab pakai lembar pH.
 *
 * ## Angkanya nggak digeser
 *
 * [komponenBudget] balik `null`, jadi `GumCalculator::hitungTitik()` jalan
 * lewat jalur CMC generik — PERSIS jalur yang udah dilewati alat-alat ini
 * sekarang, karena baris kemampuan mereka datang dari
 * `CalibrationCapabilitySeeder` (salinan lampiran akreditasi) yang cuma ngisi
 * CMC dan nggak pernah ngisi `u_temperature`/`ci_suhu`/`u_perbedaan_suhu`.
 * Tanpa konstanta itu `punyaBudgetPenuh()` false dan `PhMeterProfile` juga
 * balik `null`. Yang berubah stempel rumus & format cetaknya, bukan U95-nya.
 *
 * Kelas ini SENGAJA nggak didaftarin di `CalibrationProfileRegistry::
 * daftarProfil()`: dia bukan jenis alat, dia jawaban "nggak ada jenisnya".
 * Masuk ke daftar bikin dia ikut kena sapuan template OCR & picker lembar
 * kerja, dan itu persis kebalikan dari gunanya.
 */
class ProfilGenerik extends CalibrationProfile
{
    /** Kode stabilnya. Dipakai test & log buat mbedain dari `ph_meter`. */
    public const KODE = 'generik';

    public function kode(): string
    {
        return self::KODE;
    }

    /**
     * Bukan nama jenis alat — kalimat keadaan.
     *
     * Nilainya kepakai `RumusKalibrasi::formulaUntukProfil()` buat nyusun nama
     * baris `formulas`, jadi dia tetap harus kebaca manusia. Dan dia nggak
     * pernah masuk indeks ejaan registry karena profil ini nggak terdaftar.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Alat tanpa profil khusus';
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_GENERIK;
    }

    public function besaran(): string
    {
        return 'generik';
    }

    /**
     * Nggak ada lembar kerja khusus, dan itu jawaban — bukan kegagalan.
     *
     * Melempar di sini disengaja: satu-satunya alternatif yang pernah jalan
     * adalah nyodorin lembar pH buat alat yang bukan pH, dan itu yang bikin
     * teknisi ngisi buffer 4/7/10 buat buret tanpa satu pun error muncul.
     * Pemanggil satu-satunya yang bisa kesampe ke sini,
     * `CalibrationController::lembarKerja()`, udah nyaring profil ini duluan
     * dan mulangin 422 yang kebaca.
     *
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        throw new LogicException(
            'Alat ini nggak punya lembar kerja khusus (profil generik). '
            .'Pemanggil harus nyaring `ProfilGenerik` dulu, bukan manggil bentukLembarKerja().',
        );
    }

    /**
     * Nggak ada budget khusus — `GumCalculator` jatuh ke jalur CMC generik.
     *
     * Lihat "Angkanya nggak digeser" di docblock kelas: ini jalur yang SAMA
     * dengan yang udah dilewati alat-alat ini waktu masih jatuh ke pH.
     *
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>|null
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
}
