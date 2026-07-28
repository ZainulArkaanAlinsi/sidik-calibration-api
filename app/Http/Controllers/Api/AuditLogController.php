<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Riwayat perubahan data (arsitektur-desktop-database.md Keputusan 4).
 *
 * **Admin doang, dan baca-saja.** Nggak ada `POST`/`PUT`/`DELETE` di sini — baris
 * audit lahir dari perubahan data itu sendiri (`Diaudit`), bukan dari orang yang
 * ngirim request. Riwayat yang bisa ditulis tangan berhenti jadi bukti.
 *
 * Kenapa admin-only padahal isinya "cuma riwayat": isinya nilai SEBELUM & SESUDAH
 * dari semua data lab — termasuk data pelanggan dan angka kalibrasi. Ini justru
 * tabel yang paling nggak boleh kebuka lebar.
 */
class AuditLogController extends Controller
{
    /** Batas baris export — nahan satu request narik puluhan ribu baris. */
    public const MAKS_BARIS_EXPORT = 10000;

    public function index(Request $request): AnonymousResourceCollection
    {
        $filter = $this->penyaringTervalidasi($request);

        $log = $this->query($request, $filter)
            ->with('pelaku')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return AuditLogResource::collection($log)->additional([
            'penyaring' => $filter,
        ]);
    }

    /**
     * Ekspor riwayat ke CSV ("Bisa dilihat dan diekspor" — Keputusan 4).
     *
     * CSV, bukan xlsx: yang dibawa asesor biasanya dibuka di apa pun yang ada di
     * komputernya, dan CSV nggak butuh pustaka apa pun buat dibaca. Di-stream biar
     * 10 ribu baris nggak numpuk di memori sekaligus.
     */
    public function export(Request $request): StreamedResponse
    {
        $filter = $this->penyaringTervalidasi($request);

        $query = $this->query($request, $filter)
            ->with('pelaku')
            ->latest('id')
            ->limit(self::MAKS_BARIS_EXPORT);

        $namaFile = 'Riwayat-Perubahan-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $keluaran = fopen('php://output', 'w');

            // BOM UTF-8 — tanpa ini Excel di Windows nampilin nama PT yang ada
            // karakter non-ASCII jadi kacau, dan itu yang dibaca asesor.
            fwrite($keluaran, "\xEF\xBB\xBF");

            fputcsv($keluaran, [
                'Waktu', 'Entitas', 'ID', 'Aksi', 'Pelaku', 'Role', 'Kolom', 'Nilai Lama', 'Nilai Baru', 'Catatan',
            ]);

            // `chunkById`, bukan `get()`: 10 ribu baris audit yang bawa dua kolom
            // JSON itu berat kalau ditarik sekaligus.
            $query->chunkById(500, function ($kumpulan) use ($keluaran): void {
                foreach ($kumpulan as $log) {
                    $baris = $this->barisCsv($log);

                    foreach ($baris as $b) {
                        fputcsv($keluaran, $b);
                    }
                }
            });

            fclose($keluaran);
        }, $namaFile, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Satu baris audit jadi SATU BARIS PER KOLOM yang berubah.
     *
     * Sengaja dipecah, bukan satu baris dengan dua kolom JSON: CSV yang selnya isi
     * JSON nggak bisa disaring atau di-pivot di Excel, dan itu satu-satunya alasan
     * orang mau CSV-nya.
     *
     * @return list<list<string>>
     */
    private function barisCsv(AuditLog $log): array
    {
        $umum = [
            $log->created_at?->toDateTimeString() ?? '',
            (string) $log->entity_type,
            (string) $log->entity_id,
            (string) $log->action,
            $log->pelaku?->name ?? '(sistem)',
            $log->pelaku?->role ?? '',
        ];

        $lama = $log->old_data ?? [];
        $baru = $log->new_data ?? [];
        $kolom = array_unique([...array_keys($lama), ...array_keys($baru)]);

        // Hapus & pulihkan nggak punya rincian kolom — tetap satu baris, biar
        // kejadiannya nggak ilang dari CSV.
        if ($kolom === []) {
            return [[...$umum, '', '', '', (string) $log->note]];
        }

        $hasil = [];

        foreach ($kolom as $k) {
            $hasil[] = [
                ...$umum,
                (string) $k,
                $this->teks($lama[$k] ?? null),
                $this->teks($baru[$k] ?? null),
                (string) $log->note,
            ];
        }

        return $hasil;
    }

    private function teks(mixed $nilai): string
    {
        if ($nilai === null) {
            return '';
        }

        if (is_bool($nilai)) {
            return $nilai ? 'true' : 'false';
        }

        return is_scalar($nilai) ? (string) $nilai : json_encode($nilai, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return Builder<AuditLog>
     */
    private function query(Request $request, array $filter): Builder
    {
        return AuditLog::query()
            // Jaring multi-tenant. Riwayat lab lain nggak boleh kebaca dari sini —
            // isinya nilai sebelum & sesudah dari seluruh datanya.
            ->where('organization_id', $request->user()->organization_id)
            ->when(
                filled($filter['entity_type'] ?? null),
                fn (Builder $q) => $q->where('entity_type', $filter['entity_type']),
            )
            ->when(
                filled($filter['entity_id'] ?? null),
                fn (Builder $q) => $q->where('entity_id', (int) $filter['entity_id']),
            )
            ->when(
                filled($filter['action'] ?? null),
                fn (Builder $q) => $q->where('action', $filter['action']),
            )
            ->when(
                filled($filter['changed_by'] ?? null),
                fn (Builder $q) => $q->where('changed_by', (int) $filter['changed_by']),
            )
            // Rentang tanggal dibandingin per-TANGGAL, dan `sampai` inklusif —
            // aturan yang sama kayak `/laporan/kalibrasi`, biar dua layar yang
            // nanya "1–31 Juli" nggak beda hasilnya.
            ->when(
                filled($filter['dari'] ?? null),
                fn (Builder $q) => $q->whereDate('created_at', '>=', $filter['dari']),
            )
            ->when(
                filled($filter['sampai'] ?? null),
                fn (Builder $q) => $q->whereDate('created_at', '<=', $filter['sampai']),
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function penyaringTervalidasi(Request $request): array
    {
        return $request->validate([
            'entity_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'entity_id' => ['sometimes', 'nullable', 'integer'],
            'action' => ['sometimes', 'nullable', Rule::in(AuditLog::actions())],
            'changed_by' => ['sometimes', 'nullable', 'integer'],
            'dari' => ['sometimes', 'nullable', 'date'],
            'sampai' => ['sometimes', 'nullable', 'date', 'after_or_equal:dari'],
        ], [
            'action.in' => 'Aksi cuma bisa: '.implode(', ', AuditLog::actions()).'.',
            'sampai.after_or_equal' => 'Tanggal "sampai" nggak boleh sebelum tanggal "dari".',
        ]);
    }
}
