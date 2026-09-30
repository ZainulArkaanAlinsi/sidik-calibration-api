<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FotoPelanggan;
use App\Services\PenjagaOrganisasi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto pelat nama dari pelanggan, dibuka orang LAB (§42 A4/A5).
 *
 * Baca saja — orang lab tidak mengunggah/menghapus foto pelanggan. Berkasnya
 * di disk `arsip` privat; foto lab lain dijawab 404 (`PenjagaOrganisasi`).
 */
class FotoPelangganController extends Controller
{
    public function tampil(Request $request, FotoPelanggan $foto): StreamedResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $foto);

        abort_unless(Storage::disk('arsip')->exists($foto->path), 404);

        return Storage::disk('arsip')->response($foto->path, null, [
            'Content-Type' => $foto->mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
