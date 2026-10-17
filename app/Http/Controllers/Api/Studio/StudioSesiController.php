<?php

namespace App\Http\Controllers\Api\Studio;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\MatriksIzin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesi klien Sidik Studio (K-50-12): siapa yang sedang masuk dan izinnya, serta
 * keluar. Pengganti `/me`, `/me/permissions`, dan `/logout` yang tertutup untuk
 * token `studio` (rute lab ber-`aplikasi:internal`).
 *
 * Izin di sini bahan TAMPILAN; penegaknya gerbang `studio` di tiap rute.
 */
class StudioSesiController extends Controller
{
    public function me(Request $request, MatriksIzin $matriks): JsonResponse
    {
        return response()->json(['data' => [
            'user' => new UserResource($request->user()),
            'izin' => $matriks->untukStudio(),
        ]]);
    }

    public function keluar(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar dari Sidik Studio.']);
    }
}
