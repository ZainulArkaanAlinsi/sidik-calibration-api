<?php

namespace App\Observers;

use App\Models\CalibrationSession;
use App\Services\Pelanggan\KabarTahapAlat;

/**
 * Menyambungkan perubahan sesi kalibrasi ke `KabarTahapAlat` (tahap TURUNAN).
 *
 * Satu pintu untuk SEMUA penulis Eloquent sesi (API, panel, job) -- bukan
 * ditempel di tiap controller yang mengubah `status`. Sesi tanpa
 * `order_item_id` (lembar dadakan, sesi lama) berhenti di baris pertama tanpa
 * satu kueri pun. Penulisan lewat query builder (`approve()` memakai
 * `whereKey()->update()`) tidak lewat sini, dan memang tidak perlu: `disetujui`
 * tidak mengubah tahap yang dilihat pelanggan, dan terbitnya sertifikat punya
 * kabar sendiri (`KabarSertifikatTerbit`).
 *
 * ## Sakelar `KABAR_TAHAP_TURUNAN` (config `pelanggan.kabar_tahap_turunan`, bawaan MATI)
 *
 * Kabar turunan menembak satu notifikasi per alat per perpindahan tahap, dan aplikasi
 * pelanggan belum punya layar preferensi untuk mematikannya. Mati = observer ini berhenti
 * di baris pertama (nol kueri). Sakelar ini HANYA untuk kabar turunan: kabar tahap FISIK
 * (`PelacakanController::tandaiTahapFisik`) dan "sertifikat terbit" tidak lewat sini.
 *
 * Didaftarkan di `AppServiceProvider::boot()`.
 */
class SesiKabarTahapAlat
{
    public function __construct(private readonly KabarTahapAlat $kabar) {}

    public function created(CalibrationSession $sesi): void
    {
        if (! $this->menyala() || $sesi->order_item_id === null) {
            return;
        }

        $this->kabar->sesiBerubah($sesi, null);
    }

    public function updated(CalibrationSession $sesi): void
    {
        if (! $this->menyala() || $sesi->order_item_id === null || ! $sesi->wasChanged('status')) {
            return;
        }

        $this->kabar->sesiBerubah($sesi, (string) $sesi->getOriginal('status'));
    }

    private function menyala(): bool
    {
        return (bool) config('pelanggan.kabar_tahap_turunan', false);
    }
}
