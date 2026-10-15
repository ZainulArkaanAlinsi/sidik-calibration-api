<?php

namespace App\Http\Resources\Studio;

use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\Studio\Workbook\JenisWorkbook;
use App\Services\Studio\Workbook\ProteksiLembar;
use App\Services\Studio\Workbook\VersiXlsmWorkbook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Workbook master untuk Sidik Studio (S1b) — bentuk dikunci
 * `docs/kontrak-api.md` §Studio Workbook. Field dipetakan manual; tidak ada
 * `organization_id`, path disk, atau id pengimpor yang keluar.
 *
 * `versi_xlsm_id` / `ada_versi_xlsm` (tahap A): id workbook `.xlsm` yang
 * sebaiknya dibuka untuk baris ini — dirinya sendiri untuk baris xlsm, versi
 * xlsm setara untuk baris csv (`VersiXlsmWorkbook`), null bila tidak ada.
 *
 * Jenis master (K-49-13): `kunci_jenis`, `aktif`, `status` (`aktif` |
 * `riwayat`), `versi` (urutan impor dalam jenis), `digantikan_oleh_id`, dan
 * `jumlah_riwayat` (versi lama di jenis yang sama, tanpa arsip).
 *
 * @property WorkbookMaster $resource
 */
class WorkbookMasterResource extends JsonResource
{
    public function __construct(WorkbookMaster $resource, private readonly ?int $versiXlsmId = null, private readonly int $jumlahRiwayat = 0)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $w = $this->resource;

        return [
            'id' => $w->id,
            'nama' => $w->nama,
            'kode_profil' => $w->kode_profil,
            'keluarga' => $w->keluarga,
            'sumber' => $w->sumber,
            'sha256' => $w->sha256,
            'ukuran' => $w->ukuran,
            'jumlah_lembar' => $w->jumlah_lembar,
            'berkas_asli_tersimpan' => $w->berkasAsliTersimpan(),
            // true = byte asli terenkripsi; false = .xlsm sumbernya ZIP polos,
            // disimpan apa adanya (K-49-11); null = tidak ada berkas asli (csv).
            'berkas_asli_terenkripsi' => $w->berkasAsliTersimpan() ? $w->berkas_terenkripsi : null,
            'ada_versi_xlsm' => $this->versiXlsmId !== null,
            'versi_xlsm_id' => $this->versiXlsmId,
            'kunci_jenis' => $w->kunci_jenis,
            'aktif' => $w->aktif,
            'status' => JenisWorkbook::status($w),
            'versi' => $w->versi,
            'digantikan_oleh_id' => $w->digantikan_oleh_id,
            'jumlah_riwayat' => $this->jumlahRiwayat,
            'diimpor_pada' => $w->created_at?->toIso8601String(),
        ];
    }

    /**
     * Daftar (satu kueri pasangan xlsm untuk seluruh lab, bukan per baris).
     *
     * @param  Collection<int, WorkbookMaster>  $workbook
     * @return list<array<string, mixed>>
     */
    public static function daftar(Collection $workbook, int $organisasiId, Request $request): array
    {
        $peta = app(VersiXlsmWorkbook::class)->peta($organisasiId);
        $riwayat = self::jumlahRiwayat($organisasiId);

        return $workbook
            ->map(static fn (WorkbookMaster $w): array => (new self($w, $peta[$w->id] ?? null, $riwayat[(string) $w->kunci_jenis] ?? 0))->toArray($request))
            ->values()
            ->all();
    }

    /**
     * Detail: meta + catatan + daftar lembar TANPA sel.
     *
     * @return array<string, mixed>
     */
    public static function detail(WorkbookMaster $workbook, Request $request): array
    {
        $lembar = $workbook->lembar()
            ->select(WorkbookMasterLembar::KOLOM_TANPA_SEL)
            ->orderBy('urutan')
            ->get();

        $peta = app(VersiXlsmWorkbook::class)->peta((int) $workbook->organization_id);
        $riwayat = self::jumlahRiwayat((int) $workbook->organization_id, (string) $workbook->kunci_jenis);

        return (new self($workbook, $peta[$workbook->id] ?? null, $riwayat[(string) $workbook->kunci_jenis] ?? 0))->toArray($request) + [
            'catatan' => $workbook->catatan,
            // K-49-15: pemanggil boleh menyunting sel terkunci lembar yang
            // dilindungi Excel (super_admin) — aturan yang sama dengan `/hitung`.
            'boleh_edit_sel_terkunci' => ProteksiLembar::bolehMelewati($request->user()),
            'lembar' => WorkbookMasterLembarResource::collection($lembar)->toArray($request),
        ];
    }

    /**
     * Jumlah riwayat (bukan aktif, bukan arsip) per kunci jenis — satu kueri
     * untuk seluruh daftar.
     *
     * @return array<string, int>
     */
    private static function jumlahRiwayat(int $organisasiId, ?string $kunci = null): array
    {
        return WorkbookMaster::query()
            ->where('organization_id', $organisasiId)
            ->whereNull('aktif')
            ->whereNull('diarsipkan_pada')
            ->when($kunci !== null, static fn ($q) => $q->where('kunci_jenis', $kunci))
            ->groupBy('kunci_jenis')
            ->selectRaw('kunci_jenis, count(*) as n')
            ->pluck('n', 'kunci_jenis')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();
    }
}
