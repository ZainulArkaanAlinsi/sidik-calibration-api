<?php

namespace App\Http\Requests\Concerns;

use App\Models\CalibrationSession;
use App\Services\PenjagaOrganisasi;

/**
 * Kepemilikan sesi diperiksa SEBELUM badan disentuh apa pun (R2-5, dilanjutkan
 * QA ronde 2-B).
 *
 * Laravel menjalankan `prepareForValidation()` SEBELUM `authorize()`
 * (`ValidatesWhenResolvedTrait::validateResolved()`). R2-5 menaruh pemeriksaan
 * organisasi di `authorize()`, tetapi `prepareForValidation()` sudah bisa
 * menjawab lebih dulu -- 422 untuk badan yang terlalu besar
 * (`CalibrationRequest::tolakPayloadTerlaluBesar()`) atau galat untuk bentuk
 * yang tak terduga -- jadi `PUT /calibrations/{sesi lab lain}` dengan badan
 * tertentu tetap berbeda dari ID yang tidak ada (404): oracle keberadaan ID.
 *
 * Trait ini memajukan pemeriksaan ke `validateResolved()`, SEBELUM
 * `prepareForValidation()`. Hasilnya sama dengan `PenjagaOrganisasi::
 * pastikanSesiBolehDilihat()` di controller: sesi lab lain 404 (tercatat), dan
 * teknisi hanya sesinya sendiri. `POST` tanpa `{calibration}` di rute tidak
 * tersentuh. `authorize()` di kelas pemakai cukup `return true`: memanggilnya
 * lagi di sana hanya menggandakan jejak audit.
 */
trait PeriksaSesiSebelumPersiapan
{
    public function validateResolved()
    {
        $sesi = $this->route('calibration');

        if ($sesi instanceof CalibrationSession) {
            PenjagaOrganisasi::pastikanSesiBolehDilihat($this, $sesi);
        }

        parent::validateResolved();
    }
}
