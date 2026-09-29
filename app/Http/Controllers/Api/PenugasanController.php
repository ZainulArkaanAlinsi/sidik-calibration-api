<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PenugasanRequest;
use App\Models\Penugasan;
use App\Models\PenugasanItem;
use App\Models\PenugasanTeknisi;
use App\Models\User;
use App\Notifications\PenugasanBaru;
use App\Services\PenjagaOrganisasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Penugasan teknisi — personal & grup (poin 8).
 *
 * Empat pintu:
 *   index()        GET   /api/penugasan            — papan; teknisi cuma lihat punyanya
 *   store()        POST  /api/penugasan            — super admin membagi
 *   laporProgres() PATCH /api/penugasan/item/{i}   — teknisi melaporkan jumlah tuntas
 *   tandaiDilihat()POST  /api/penugasan/{p}/dilihat — teknisi membuka tugasnya
 */
class PenugasanController extends Controller
{
    private const RELASI = [
        'pembuat:id,name',
        'anggota:id,penugasan_id,user_id,peran,dilihat_pada',
        'anggota.teknisi:id,name,kode_teknisi',
        'item:id,penugasan_id,jenis_alat,equipment_category_id,jumlah,jumlah_selesai,order_id,catatan',
        'item.kategori:id,nama',
        'item.order:id,nomor',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', 'in:aktif,selesai,dibatalkan'],
            'teknisi_id' => ['sometimes', 'nullable', 'integer'],
            'terlambat' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $pemanggil = $request->user();

        $daftar = Penugasan::query()
            ->where('organization_id', $pemanggil->organization_id)
            // Teknisi melihat PUNYANYA saja — bukan karena penugasan orang lain
            // rahasia, tapi karena papan berisi 60 penugasan seluruh lab membuat
            // layar tugasnya tidak bisa dipakai. Saringan ini dipasang di server,
            // bukan dengan menyembunyikan tab di mobile.
            ->when(
                $pemanggil->role === User::ROLE_TEKNISI,
                fn ($q) => $q->whereHas('anggota', fn ($a) => $a->where('user_id', $pemanggil->id)),
            )
            ->when(
                filled($data['teknisi_id'] ?? null) && $pemanggil->role !== User::ROLE_TEKNISI,
                fn ($q) => $q->whereHas('anggota', fn ($a) => $a->where('user_id', $data['teknisi_id'])),
            )
            ->when(filled($data['status'] ?? null), fn ($q) => $q->where('status', $data['status']))
            ->when($request->boolean('terlambat'), fn ($q) => $q
                ->where('status', Penugasan::STATUS_AKTIF)
                ->whereNotNull('tanggal_target')
                ->whereDate('tanggal_target', '<', now()))
            ->with(self::RELASI)
            // Target terdekat duluan; yang tanpa target di belakang. Alasannya
            // sama dengan antrean pengesahan & pelacakan: urutan "terbaru dulu"
            // menyembunyikan yang paling mendesak di dasar daftar.
            ->orderByRaw('tanggal_target IS NULL ASC')
            ->orderBy('tanggal_target')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($daftar->items())->map(fn (Penugasan $p): array => $this->bentuk($p)),
            'meta' => [
                'total' => $daftar->total(),
                'per_page' => $daftar->perPage(),
                'current_page' => $daftar->currentPage(),
                'last_page' => $daftar->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Penugasan $penugasan): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $penugasan);
        $this->pastikanBolehLihat($request, $penugasan);

        return response()->json(['data' => $this->bentuk($penugasan->load(self::RELASI))]);
    }

    public function store(PenugasanRequest $request): JsonResponse
    {
        $data = $request->validated();

        // SATU transaksi. Penugasan tanpa anggota tidak sampai ke siapa pun, dan
        // penugasan tanpa baris item tidak memberi tahu apa yang harus
        // dikerjakan — dua-duanya bentuk data setengah jadi yang tidak bisa
        // dibetulkan dari layar, cuma dihapus. Jadi tiga tulisan ini utuh atau
        // tidak terjadi sama sekali.
        $penugasan = DB::transaction(function () use ($data, $request): Penugasan {
            $penugasan = Penugasan::create([
                'organization_id' => $request->user()->organization_id,
                'judul' => $data['judul'],
                'tipe' => count($data['teknisi']) > 1 ? Penugasan::TIPE_GRUP : Penugasan::TIPE_PERSONAL,
                'tanggal_target' => $data['tanggal_target'] ?? null,
                'catatan' => $data['catatan'] ?? null,
                'dibuat_oleh' => $request->user()->id,
                'status' => Penugasan::STATUS_AKTIF,
            ]);

            foreach ($data['teknisi'] as $urutan => $userId) {
                $penugasan->anggota()->create([
                    'user_id' => $userId,
                    // Yang pertama di daftar jadi ketua. Untuk personal dia
                    // satu-satunya anggota — jadi tidak ada cabang "kalau
                    // personal, ambil dari kolom lain" di seluruh sistem.
                    'peran' => $urutan === 0
                        ? PenugasanTeknisi::PERAN_KETUA
                        : PenugasanTeknisi::PERAN_ANGGOTA,
                ]);
            }

            foreach ($data['item'] as $baris) {
                $penugasan->item()->create([
                    'jenis_alat' => $baris['jenis_alat'],
                    'equipment_category_id' => $baris['equipment_category_id'] ?? null,
                    'jumlah' => $baris['jumlah'],
                    'order_id' => $baris['order_id'] ?? null,
                    'catatan' => $baris['catatan'] ?? null,
                ]);
            }

            return $penugasan;
        });

        $penugasan->load(self::RELASI);

        // Di LUAR transaksi. Notifikasi yang dikirim di dalamnya bisa sampai ke
        // HP teknisi untuk penugasan yang transaksinya kemudian di-rollback —
        // dan teknisi yang membuka notifikasi lalu menemukan halaman kosong akan
        // melaporkannya sebagai aplikasi rusak.
        Notification::send(
            User::query()->whereIn('id', $data['teknisi'])->get(),
            PenugasanBaru::dariPenugasan($penugasan, $request->user()->name),
        );

        return response()->json([
            'message' => $penugasan->tipe === Penugasan::TIPE_GRUP
                ? 'Penugasan grup dibuat. Ketuanya '.($penugasan->ketua->first()?->teknisi?->name ?? '—').'.'
                : 'Penugasan dibuat.',
            'data' => $this->bentuk($penugasan),
        ], 201);
    }

    /**
     * Teknisi melaporkan berapa yang sudah tuntas dari satu baris.
     *
     * Yang dilaporkan boleh LEBIH dari yang direncanakan — paketnya ternyata
     * berisi 12, bukan 10. Menolaknya memaksa teknisi berhenti melaporkan apa
     * adanya, dan angka yang disesuaikan supaya lolos validasi lebih buruk
     * daripada angka yang melebihi rencana.
     */
    public function laporProgres(Request $request, PenugasanItem $penugasanItem): JsonResponse
    {
        $penugasan = $penugasanItem->penugasan;
        PenjagaOrganisasi::pastikanSatu($request, $penugasan);
        $this->pastikanBolehLihat($request, $penugasan);

        if ($penugasan->status !== Penugasan::STATUS_AKTIF) {
            return response()->json([
                'message' => 'Penugasan ini sudah '.$penugasan->status.'. Minta super admin '
                    .'membukanya lagi kalau masih ada yang perlu dilaporkan.',
            ], 422);
        }

        $data = $request->validate([
            'jumlah_selesai' => ['required', 'integer', 'min:0', 'max:9999'],
            'catatan' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        // Angka ini dilaporkan orang dan tidak bisa dihitung ulang dari mana pun
        // (lihat docblock migrasi `penugasan_item`), jadi jejaknya wajib ada.
        // Jejaknya ditulis trait `Diaudit` lewat event `updated` dari save() di
        // bawah — sengaja TIDAK memanggil catatAudit() lagi di sini, karena itu
        // menulis dua baris untuk satu perubahan dan orang yang menyelidiki
        // menghitung dua laporan yang sebenarnya satu.
        $penugasanItem->fill([
            'jumlah_selesai' => $data['jumlah_selesai'],
            'catatan' => $data['catatan'] ?? $penugasanItem->catatan,
        ])->save();

        $penugasan->load(self::RELASI);

        return response()->json([
            'message' => 'Progres tercatat.',
            'data' => $this->bentuk($penugasan),
            // Sengaja TIDAK otomatis menandai penugasan selesai waktu semua
            // barisnya penuh. "Sudah 10 dari 10" berarti angkanya tercapai, bukan
            // bahwa pekerjaannya tuntas — lembar kerjanya masih bisa
            // dikembalikan admin. Yang menutup penugasan tetap orang, dan
            // ini cuma memberi tahu tombolnya sudah pantas dipencet.
            'siap_ditutup' => $penugasan->persenTuntas() >= 100,
        ]);
    }

    /**
     * Teknisi membuka penugasannya.
     *
     * "Notifikasi terkirim" bukan jawaban untuk "dia udah tau belum?". Tanpa
     * kolom ini, penugasan yang tidak dikerjakan tidak bisa dibedakan dari
     * penugasan yang tidak pernah sampai — dan dua hal itu tindak lanjutnya
     * berbeda: yang satu ditanyakan ke orangnya, yang satu diperiksa ke
     * notifikasinya.
     */
    public function tandaiDilihat(Request $request, Penugasan $penugasan): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $penugasan);

        $baris = $penugasan->anggota()->where('user_id', $request->user()->id)->first();

        // Bukan anggotanya → 404, bukan 403. Alasannya sama dengan isolasi
        // antar-lab: 403 mengonfirmasi penugasan itu ada.
        abort_if($baris === null, 404);

        // Waktu PERTAMA membuka yang disimpan, bukan yang terakhir. Yang
        // ditanyakan super admin adalah "sejak kapan dia tahu" — dan yang
        // terakhir membuka menjawab pertanyaan yang tidak ada gunanya.
        if ($baris->dilihat_pada === null) {
            $baris->forceFill(['dilihat_pada' => now()])->save();
        }

        return response()->json(['dilihat_pada' => $baris->dilihat_pada?->toIso8601String()]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function bentuk(Penugasan $p): array
    {
        return [
            'id' => $p->id,
            'judul' => $p->judul,
            'tipe' => $p->tipe,
            'status' => $p->status,
            'tanggal_target' => $p->tanggal_target?->toDateString(),
            'terlambat' => $p->terlambat(),
            'persen_tuntas' => $p->persenTuntas(),
            'catatan' => $p->catatan,
            'dibuat_oleh' => $p->pembuat?->name,

            'teknisi' => $p->anggota->map(fn (PenugasanTeknisi $a): array => [
                'id' => $a->teknisi?->id,
                'nama' => $a->teknisi?->name,
                'kode' => $a->teknisi?->kode_teknisi,
                'peran' => $a->peran,
                'dilihat_pada' => $a->dilihat_pada?->toIso8601String(),
            ])->values(),

            'item' => $p->item->map(fn (PenugasanItem $i): array => [
                'id' => $i->id,
                'jenis_alat' => $i->jenis_alat,
                'kategori' => $i->kategori?->nama,
                'jumlah' => $i->jumlah,
                'jumlah_selesai' => $i->jumlah_selesai,
                'paket' => $i->order?->nomor,
                'catatan' => $i->catatan,
            ])->values(),
        ];
    }

    private function pastikanBolehLihat(Request $request, Penugasan $penugasan): void
    {
        if ($request->user()->role !== User::ROLE_TEKNISI) {
            return;
        }

        abort_if(
            ! $penugasan->anggota()->where('user_id', $request->user()->id)->exists(),
            404,
        );
    }
}
