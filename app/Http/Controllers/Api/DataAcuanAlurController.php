<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaketAcuanVersiResource;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanLog;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Services\Acuan\AlurVersiAcuan;
use App\Services\Acuan\AlurVersiAcuanDitolak;
use App\Services\PenjagaOrganisasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Alur versi data acuan lapis 1 (AGENTS.md §Olah data butir 3; Studio Data
 * Acuan T2.2/T2.3): daftar alur + simulasi (baca), ajukan, simulasikan, sahkan,
 * tolak (tulis). Aturannya di `AlurVersiAcuan`; di sini izin & bentuk respons.
 *
 *  - Baca: admin, viewer, super admin. Teknisi → 404 (draf & simulasi bukan
 *    untuk teknisi, 02-SRS §4 — 404, bukan 403, supaya tidak mengakui ada).
 *  - Ajukan/simulasikan/tolak draf: Master Data (admin) & super admin
 *    (`role:admin` + `lolosTulisSuperAdmin`).
 *  - Sahkan: grup `role:super_admin` (02-SRS §3/§4) + konfirmasi sandi +
 *    `PemisahanWewenang::periksaPengesahanVersiAcuan` (BR-08).
 *  - Organisasi lain → 404 (`PenjagaOrganisasi`).
 */
class DataAcuanAlurController extends Controller
{
    public function __construct(private readonly AlurVersiAcuan $alur) {}

    /** Versi data satu paket + status + ringkasan simulasi terakhir + log. */
    public function daftar(Request $request, string $kode): JsonResponse
    {
        $this->tolakTeknisi($request);

        $paket = PaketAcuan::query()
            ->where('organization_id', (int) $request->user()->organization_id)
            ->where('kode_profil', $kode)
            ->first();

        abort_if($paket === null, 404, 'Paket data acuan tidak ditemukan.');

        $data = $paket->versi()
            ->where('jenis', PaketAcuanVersi::JENIS_DATA)
            ->orderByDesc('nomor_versi')
            ->get()
            ->map(function (PaketAcuanVersi $v) use ($request, $paket): array {
                $v->setRelation('paketAcuan', $paket);

                return [
                    ...(new PaketAcuanVersiResource($v))->toArray($request),
                    'simulasi' => $this->ringkasSimulasi($v),
                    'log' => $this->log($v),
                ];
            })
            ->all();

        return $this->json(['data' => $data]);
    }

    /** Laporan simulasi terakhir versi ini, utuh (yang dilihat pengesah). */
    public function simulasi(Request $request, PaketAcuanVersi $versi): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $versi);
        $this->tolakTeknisi($request);

        ['simulasi' => $sim] = AlurVersiAcuan::statusSimulasi($versi);

        return $this->json(['data' => [
            ...$this->ringkasSimulasi($versi),
            'laporan' => $sim?->laporanTerurai(),
        ]]);
    }

    public function ajukan(Request $request, PaketAcuanVersi $versi): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $versi);

        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:10', 'max:2000'],
            'rujukan' => ['sometimes', 'array', 'max:20'],
            'rujukan.*.jenis' => ['required', 'string', 'in:sertifikat_standar,ik,lampiran,sel_master,lain'],
            'rujukan.*.nilai' => ['required', 'string', 'max:255'],
            'rujukan.*.catatan' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->jalankan(fn () => $this->alur->ajukan($versi, $request->user(), $data['alasan'], array_values($data['rujukan'] ?? [])),
            fn (PaketAcuanVersi $v) => ['message' => "Versi v{$v->nomor_versi} diajukan.", 'data' => $this->versi($request, $v)]);
    }

    public function simulasikan(Request $request, PaketAcuanVersi $versi): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $versi);

        return $this->jalankan(fn () => $this->alur->simulasikan($versi, $request->user()),
            fn () => ['message' => 'Simulasi selesai.', 'data' => $this->ringkasSimulasi($versi->fresh() ?? $versi)], 201);
    }

    public function sahkan(Request $request, PaketAcuanVersi $versi): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $versi);

        $data = $request->validate([
            'berlaku_mulai' => ['required', 'date_format:Y-m-d'],
            'password' => ['required', 'string'],
            'catatan' => ['nullable', 'string', 'max:2000'],
            'pengakuan' => ['sometimes', 'array'],
            'pengakuan.*' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            return $this->json([
                'message' => 'Sandi konfirmasi salah.',
                'kode' => 'konfirmasi_sandi_salah',
                'errors' => ['password' => ['Sandi konfirmasi salah.']],
            ], 422);
        }

        return $this->jalankan(
            fn () => $this->alur->sahkan($versi, $request->user(), $data['berlaku_mulai'], array_map('strval', array_filter($data['pengakuan'] ?? [], 'is_string')), $data['catatan'] ?? null),
            fn (PaketAcuanVersi $v) => [
                'message' => "Versi v{$v->nomor_versi} disahkan, berlaku mulai {$v->berlaku_mulai?->toDateString()}.",
                'data' => $this->versi($request, $v),
            ],
        );
    }

    public function tolak(Request $request, PaketAcuanVersi $versi): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $versi);

        $data = $request->validate(['alasan' => ['required', 'string', 'min:10', 'max:2000']]);

        return $this->jalankan(fn () => $this->alur->tolak($versi, $request->user(), $data['alasan']),
            fn (PaketAcuanVersi $v) => ['message' => "Versi v{$v->nomor_versi} {$v->status}.", 'data' => $this->versi($request, $v)]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  callable(): mixed  $aksi
     * @param  callable(mixed): array<string, mixed>  $respons
     */
    private function jalankan(callable $aksi, callable $respons, int $status = 200): JsonResponse
    {
        try {
            $hasil = $aksi();
        } catch (AlurVersiAcuanDitolak $e) {
            return $this->json([
                'message' => $e->getMessage(),
                'kode' => $e->kode,
                ...($e->rincian === [] ? [] : ['rincian' => $e->rincian]),
            ], $e->status);
        }

        return $this->json($respons($hasil), $status);
    }

    private function tolakTeknisi(Request $request): void
    {
        abort_if($request->user()->role === User::ROLE_TEKNISI, 404);
    }

    /** @return array<string, mixed> */
    private function versi(Request $request, PaketAcuanVersi $v): array
    {
        return [
            ...(new PaketAcuanVersiResource($v->loadMissing('paketAcuan')))->toArray($request),
            'simulasi' => $this->ringkasSimulasi($v),
        ];
    }

    /** @return array<string, mixed> */
    private function ringkasSimulasi(PaketAcuanVersi $v): array
    {
        ['simulasi' => $sim, 'status' => $status] = AlurVersiAcuan::statusSimulasi($v);

        return [
            'status' => $status,
            'id' => $sim?->id,
            'isi_sha256' => $sim?->isi_sha256_saat_jalan,
            'dijalankan_oleh' => $sim?->dijalankan_oleh,
            'selesai' => $sim?->selesai?->toIso8601String(),
            'ringkasan' => $sim?->ringkasan,
            'wajib_diakui' => $sim === null ? [] : AlurVersiAcuan::butirWajibDiakui($sim),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function log(PaketAcuanVersi $v): array
    {
        return PaketAcuanLog::query()
            ->where('paket_acuan_versi_id', $v->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (PaketAcuanLog $l): array => [
                'aksi' => $l->aksi,
                'status_dari' => $l->status_dari,
                'status_ke' => $l->status_ke,
                'isi_sha256' => $l->isi_sha256,
                'user_id' => $l->user_id,
                'alasan' => $l->alasan,
                'sumber' => $l->sumber,
                'rincian' => $l->rincian,
                'pada' => $l->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
