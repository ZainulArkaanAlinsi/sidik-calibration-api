<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\Formula;
use App\Models\FormulaVersion;
use Illuminate\Support\Carbon;

/**
 * Nyediain versi rumus yang berlaku, dan nyetempelnya ke hasil hitung
 * (arsitektur-desktop-database.md Keputusan 5).
 *
 * Versinya dicari per **tanggal kalibrasi**, bukan "yang statusnya aktif sekarang".
 * Itu dua pertanyaan beda, dan yang bener yang pertama: sesi tanggal 26 Mei harus
 * distempel versi yang berlaku 26 Mei, walaupun hari ini rumusnya udah versi 3.
 * Kalau pakai "yang aktif sekarang", sesi lama yang di-revisi bakal distempel versi
 * baru padahal angkanya dihitung pakai aturan lama — dan itu lebih menyesatkan
 * daripada nggak distempel sama sekali.
 *
 * ## Kenapa versi 1 `sumber = kode`
 *
 * Rumus yang jalan sekarang ADA DI KODE (`GumCalculator`), bukan di database.
 * Nyatetnya sebagai "dari database" bikin riwayatnya bohong sejak baris pertama.
 * Jadi versi 1 dicatat apa adanya: dihitung kode program, dengan parameter yang
 * beneran dipakai. Versi 2 ke atas baru boleh `sumber = database` begitu evaluator
 * ekspresinya ada.
 *
 * Efeknya sekarang: `formula_version_id` di hasil hitung belum ngubah CARA
 * ngitungnya sama sekali — dia baru nyatet "dihitung pakai aturan versi berapa".
 * Itu memang fondasinya: tanpa stempel ini, ngubah rumus nanti bikin seluruh
 * riwayat kalibrasi nggak bisa dipertanggungjawabkan.
 */
class RumusKalibrasi
{
    /**
     * Versi rumus yang berlaku buat sesi ini. Dibikin kalau organisasinya belum
     * punya sama sekali.
     */
    public function versiUntukSesi(CalibrationSession $sesi): ?FormulaVersion
    {
        $organizationId = $sesi->organization_id;

        if ($organizationId === null) {
            return null;
        }

        $tanggal = $sesi->tanggal_kalibrasi ?? now();

        return $this->versiBerlaku((int) $organizationId, $tanggal);
    }

    /**
     * Versi yang berlaku buat satu organisasi pada satu tanggal.
     *
     * Kalau organisasinya belum punya rumus sama sekali, dibikinin versi 1
     * (`sumber = kode`) di situ juga. Alasannya: lab yang udah jalan sebelum fitur
     * ini ada nggak boleh dipaksa nyetel rumus dulu sebelum bisa nyimpen kalibrasi.
     * Yang dibikin cuma pencatatan versinya — cara hitungnya nggak berubah.
     */
    public function versiBerlaku(int $organizationId, Carbon|string $tanggal): ?FormulaVersion
    {
        $formula = $this->formulaGumPh($organizationId);

        return $formula->versiPadaTanggal($tanggal) ?? $formula->versiAktif();
    }

    /**
     * Rumus GUM pH milik satu organisasi — dibikin kalau belum ada.
     *
     * `firstOrCreate` di dua tingkat, dan itu aman di-retry: kalau dua request
     * barengan, unique index `(organization_id, kode)` & `(formula_id, nomor_versi)`
     * yang nahan.
     */
    public function formulaGumPh(int $organizationId): Formula
    {
        $formula = Formula::firstOrCreate(
            ['organization_id' => $organizationId, 'kode' => Formula::KODE_GUM_PH],
            [
                'nama' => 'Ketidakpastian GUM (jalur pH)',
                'besaran' => 'ph',
                'deskripsi' => 'Perhitungan ketidakpastian diperluas U95% sesuai GUM, '
                    .'dipakai jalur kalibrasi pH. Versi 1 dihitung kode program.',
            ],
        );

        if (! $formula->versions()->where('status', FormulaVersion::STATUS_AKTIF)->exists()) {
            $this->bikinVersiSatu($formula);
        }

        return $formula->fresh();
    }

    /**
     * Versi 1: pencatatan rumus yang SEKARANG jalan di kode.
     *
     * `effective_from` dipatok jauh ke belakang (2000-01-01), bukan hari ini —
     * kalau dipatok hari ini, sesi lama yang di-revisi nggak nemu versi yang
     * berlaku di tanggalnya dan bakal null. Padahal aturan yang dipakai buat sesi
     * itu ya aturan yang sama ini.
     */
    private function bikinVersiSatu(Formula $formula): FormulaVersion
    {
        return $formula->versions()->create([
            'organization_id' => $formula->organization_id,
            'nomor_versi' => FormulaVersion::nomorBerikutnya($formula->id),
            'sumber' => FormulaVersion::SUMBER_KODE,
            'parameter' => [
                'faktor_cakupan_k' => 2,
                'tingkat_kepercayaan' => '95%',
                'desimal_pembacaan' => 8,
                'desimal_suhu' => 2,
                'desimal_k' => 2,
                'dihitung_oleh' => 'App\\Services\\GumCalculator',
            ],
            'ekspresi' => null,
            'status' => FormulaVersion::STATUS_AKTIF,
            'effective_from' => '2000-01-01',
            'effective_until' => null,
            'catatan' => 'Versi awal — rumus yang ditanam di kode program, dicatat apa adanya '
                .'biar hasil hitung yang udah ada punya versi yang bisa dirujuk.',
            'created_by' => null,
        ]);
    }
}
