<?php

namespace App\Http\Controllers\Api\Studio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Studio\CatatPenetapanMetodeRequest;
use App\Http\Requests\Studio\SetujuiPenetapanMetodeRequest;
use App\Http\Requests\Studio\TarikPenetapanMetodeRequest;
use App\Models\ParameterMetodePenetapan;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Acuan\PenetapanMetodeDitolak;
use App\Services\Acuan\PenetapanParameterMetode;
use Illuminate\Http\JsonResponse;

/**
 * Studio Tahap 1 (25 Nov 2026): keputusan metode Lembar Centang ditetapkan dari
 * Studio, bukan lewat PR kode. Kontrak: `docs/perintah-frontend-parameter-metode-studio.md`.
 *
 * Semua rute di grup `studio` (`auth:sanctum` + gerbang `studio`, akun Studio
 * tunggal) — controller ini tidak memeriksa peran. Registri parameternya global
 * (satu lab), jadi tidak ada `organization_id` yang disaring.
 *
 * Yang menentukan angka hanya status `disetujui`; mencatat dan menarik tidak
 * pernah menggeser hasil hitung. Aturan D3 ditegakkan `PenetapanParameterMetode`.
 */
class ParameterMetodeController extends Controller
{
    public function __construct(
        private readonly PenetapanParameterMetode $penetapan,
        private readonly ParameterKeputusanMetode $registri,
    ) {}

    /** `GET /api/studio/parameter-metode` — semua parameter registri + penetapannya (semua status). */
    public function index(): JsonResponse
    {
        $barisDb = $this->penetapan->daftar();
        $data = [];

        foreach ($this->registri->semua() as $p) {
            $kunci = (string) $p['kunci'];

            $data[] = [
                'kunci' => $kunci,
                'alat' => $p['profil'],
                'nama' => $p['judul'],
                'keterangan' => $p['pertanyaan'],
                'lingkup' => $p['lingkup'],
                'butir_tm' => $p['butir_tm'],
                'dampak' => $p['dampak'],
                'pilihan' => array_map(static fn (array $pil): array => [
                    'kode' => $pil['kode'],
                    'label' => $pil['label'],
                    'keterangan' => $pil['perilaku'],
                    'setara_master' => $pil['setara_master'],
                    'bukti_singkat' => $pil['bukti_singkat'],
                ], $p['pilihan']),
                'bawaan' => $p['bawaan'],
                'aktif_sekarang' => $p['berlaku']['nilai'],
                'penetapan' => [
                    ...array_map($this->barisBerkas(...), $this->registri->penetapanBerkas($kunci)),
                    ...array_map($this->baris(...), $barisDb[$kunci] ?? []),
                ],
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** `POST /api/studio/parameter-metode/{kunci}/penetapan` — 201, status `menunggu_tm`. */
    public function catat(CatatPenetapanMetodeRequest $request, string $kunci): JsonResponse
    {
        abort_unless($this->registri->punya($kunci), 404);

        return $this->jalankan(function () use ($request, $kunci): JsonResponse {
            $baris = $this->penetapan->catat(
                $request->user(),
                $kunci,
                $request->validated('nilai'),
                $request->validated('berlaku_mulai'),
                $request->validated('catatan'),
            );

            return response()->json([
                'message' => 'Penetapan dicatat, menunggu keputusan TM. Belum memengaruhi angka.',
                'data' => $this->baris($baris),
            ], 201);
        });
    }

    /** `POST /api/studio/parameter-metode/penetapan/{penetapan}/setujui` */
    public function setujui(SetujuiPenetapanMetodeRequest $request, int $penetapan): JsonResponse
    {
        return $this->jalankan(function () use ($request, $penetapan): JsonResponse {
            $baris = $this->penetapan->setujui(
                $request->user(),
                $penetapan,
                $request->validated('keputusan_oleh'),
                $request->validated('tanggal_keputusan'),
            );

            return response()->json([
                'message' => "Penetapan disetujui. Sesi BARU bertanggal kalibrasi {$baris->berlakuMulaiTeks()} atau sesudahnya memakai pilihan ini.",
                'data' => $this->baris($baris),
            ]);
        });
    }

    /** `POST /api/studio/parameter-metode/penetapan/{penetapan}/tarik` */
    public function tarik(TarikPenetapanMetodeRequest $request, int $penetapan): JsonResponse
    {
        return $this->jalankan(function () use ($request, $penetapan): JsonResponse {
            $baris = $this->penetapan->tarik($request->user(), $penetapan, $request->validated('alasan'));

            $dari = $baris->jejak->last()?->dari_status;
            $sudahBerlaku = $dari === ParameterMetodePenetapan::STATUS_DISETUJUI
                && $baris->berlakuMulaiTeks() <= PenetapanParameterMetode::hariIni();

            return response()->json([
                'message' => 'Penetapan ditarik.',
                // Penetapan yang sudah berlaku lalu ditarik: sesi bertanggal sesudah
                // berlaku_mulai yang DIHITUNG ULANG kembali ke bawaan (hasil tersimpan tidak berubah).
                'peringatan' => $sudahBerlaku
                    ? "Penetapan ini sudah berlaku sejak {$baris->berlakuMulaiTeks()}. Hasil tersimpan tidak berubah, tetapi hitung ulang sesi bertanggal sesudahnya kini kembali ke penetapan sebelumnya atau bawaan kode."
                    : null,
                'data' => $this->baris($baris),
            ]);
        });
    }

    /** @param  callable(): JsonResponse  $aksi */
    private function jalankan(callable $aksi): JsonResponse
    {
        try {
            return $aksi();
        } catch (PenetapanMetodeDitolak $e) {
            return response()->json($e->badan(), $e->status);
        }
    }

    /** @return array<string, mixed> */
    private function baris(ParameterMetodePenetapan $p): array
    {
        return [
            'id' => $p->id,
            'nomor' => $p->nomor,
            'nilai' => $p->nilai,
            'berlaku_mulai' => $p->berlakuMulaiTeks(),
            'dicatat_pada' => $p->dicatat_pada->toIso8601String(),
            'status' => $p->status,
            'keputusan_oleh' => $p->keputusan_oleh,
            'tanggal_keputusan' => $p->tanggalKeputusanTeks(),
            'catatan' => $p->catatan,
            'sumber' => 'db',
            'jejak' => $p->jejak->map(static fn ($j): array => [
                'dari_status' => $j->dari_status,
                'ke_status' => $j->ke_status,
                'oleh' => $j->oleh_email,
                'pada' => $j->terjadi_pada->toIso8601String(),
                'catatan' => $j->catatan,
            ])->all(),
        ];
    }

    /**
     * Entri `penetapan[]` di berkas registri (rekaman sejarah, kosong sejak
     * dibuat): sudah disetujui sejak masuk, tanpa `id` dan tanpa jejak.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function barisBerkas(array $e): array
    {
        return [
            'id' => null,
            'nomor' => $e['nomor'],
            'nilai' => $e['nilai'],
            'berlaku_mulai' => $e['berlaku_mulai'],
            'dicatat_pada' => $e['dicatat_pada'],
            'status' => ParameterMetodePenetapan::STATUS_DISETUJUI,
            'keputusan_oleh' => $e['diputuskan_oleh'],
            'tanggal_keputusan' => $e['tanggal_keputusan'],
            'catatan' => $e['alasan'],
            'sumber' => 'berkas',
            'jejak' => [],
        ];
    }
}
