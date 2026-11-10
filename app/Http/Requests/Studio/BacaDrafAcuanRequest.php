<?php

namespace App\Http\Requests\Studio;

use App\Http\Requests\Concerns\MencariWorkbookStudio;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/studio/workbook/{workbook}/draf-dasar` dan
 * `GET /api/studio/workbook/{workbook}/draf/{versi}` (rencana A1, B4): hanya
 * membaca, tanpa bodi. Yang dikerjakan di sini cuma pencarian workbook (lab
 * lain / diarsipkan / tak ada = 404 yang sama) dan bentuk id versi; lihat
 * [MencariWorkbookStudio].
 */
class BacaDrafAcuanRequest extends FormRequest
{
    use MencariWorkbookStudio;

    public function authorize(): bool
    {
        $this->cariWorkbookStudio();

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
