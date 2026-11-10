<?php

namespace App\Http\Requests\Concerns;

use App\Http\Controllers\Api\Studio\WorkbookController;
use App\Models\WorkbookMaster;
use LogicException;

/**
 * Pencarian `{workbook}` (dan `{versi}`) untuk request rute draf data acuan
 * Studio (rencana A1, B3/B4).
 *
 * Parameter rute TIDAK di-bind sebagai model: `SubstituteBindings` jalan
 * sebelum gerbang `studio`, jadi pengikatan model membedakan 404 (tidak ada)
 * dari jawaban lain (ada, milik lab lain) -- celah lintas organisasi. Di sini
 * pencarian dilakukan di `authorize()`, yang Laravel jalankan SEBELUM aturan
 * validasi: workbook lab lain / diarsipkan / tak ada dijawab 404 yang sama
 * (`abort`) apa pun isi bodinya, begitu pula id versi yang bukan angka.
 *
 * Pemeriksaan "versi milik paket workbook ini" ada di service (butuh DB dan,
 * untuk PUT, kunci baris); jawabannya juga `abort(404)` yang sama.
 */
trait MencariWorkbookStudio
{
    private ?WorkbookMaster $workbookStudio = null;

    private ?int $versiIdRute = null;

    /** Isi `workbookStudio` atau `abort(404)`. Panggil dari `authorize()`. */
    protected function cariWorkbookStudio(): void
    {
        $id = (string) $this->route('workbook');
        $workbook = ctype_digit($id) && strlen($id) <= 18 ? WorkbookMaster::query()->find((int) $id) : null;

        abort_if($workbook === null, 404);
        // Lab lain -> 404 (tercatat); diarsipkan -> 404 juga.
        WorkbookController::pastikanDiStudio($this, $workbook);

        $this->workbookStudio = $workbook;

        $versi = $this->route('versi');

        if ($versi !== null) {
            $versi = (string) $versi;
            abort_if(! ctype_digit($versi) || strlen($versi) > 18, 404);
            $this->versiIdRute = (int) $versi;
        }
    }

    /** Workbook yang sudah lolos `authorize()` (organisasi pemanggil, tidak diarsipkan). */
    public function workbookStudio(): WorkbookMaster
    {
        return $this->workbookStudio ?? throw new LogicException('workbookStudio() dipanggil sebelum authorize().');
    }

    /** Id `{versi}` dari rute (angka, sudah divalidasi bentuknya di `authorize()`). */
    public function versiId(): int
    {
        return $this->versiIdRute ?? throw new LogicException('versiId() dipanggil pada rute tanpa {versi}.');
    }
}
