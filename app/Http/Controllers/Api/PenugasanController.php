<?php

namespace App\Http\Controllers\Api;

use App\Events\PerubahanDataOrganisasi;
use App\Http\Controllers\Controller;
use App\Http\Requests\PenugasanRequest;
use App\Http\Requests\PenugasanUbahRequest;
use App\Models\AuditLog;
use App\Models\Penugasan;
use App\Models\PenugasanItem;
use App\Models\PenugasanTeknisi;
use App\Models\User;
use App\Notifications\PenugasanBaru;
use App\Notifications\PenugasanDibatalkan;
use App\Services\JejakAlasan;
use App\Services\PenjagaOrganisasi;
use App\Support\CatatanAlasan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $batal = $this->jejakBatal($pemanggil->organization_id, $daftar->items());

        return response()->json([
            'data' => collect($daftar->items())->map(fn (Penugasan $p): array => $this->bentuk($p, $batal)),
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

        $penugasan->load(self::RELASI);

        return response()->json([
            'data' => $this->bentuk($penugasan, $this->jejakBatal($penugasan->organization_id, [$penugasan])),
        ]);
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
        //
        // Dan dibungkus `try`: `PenugasanBaru` ikut saluran `broadcast`, jadi
        // Reverb yang mati melempar exception DI SINI — sesudah penugasannya
        // tersimpan. Tanpa `try`, pembuatnya dapat 500, menekan Kirim lagi, dan
        // lahir penugasan kembar. Kotak masuk di aplikasi (saluran `database`)
        // tetap sumber kebenarannya; pola yang sama dengan
        // `CalibrationController::kirimKabar()`.
        try {
            Notification::send(
                User::query()->whereIn('id', $data['teknisi'])->get(),
                PenugasanBaru::dariPenugasan($penugasan, $request->user()->name),
            );
        } catch (\Throwable $e) {
            Log::warning('Notifikasi penugasan gagal dikirim.', [
                'penugasan_id' => $penugasan->id,
                'pesan' => $e->getMessage(),
            ]);
        }

        PerubahanDataOrganisasi::siarkanAman($penugasan->organization_id, 'penugasan', 'dibuat', $penugasan->id);

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

        // Anggota grup lain dan admin yang memantau melihat angka yang sama,
        // dari HP mana pun mereka masuk.
        PerubahanDataOrganisasi::siarkanAman($penugasan->organization_id, 'penugasan', 'diubah', $penugasan->id);

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
     * Edit penugasan yang masih `aktif` (admin & super admin).
     *
     * PENGGANTI untuk judul, teknisi, dan daftar item; field opsional
     * (`tanggal_target`, `catatan`, kategori/paket/catatan baris) yang TIDAK
     * dikirim dibiarkan -- `penugasan_item.catatan` juga dipakai teknisi
     * untuk catatan progresnya, jadi mengosongkannya diam-diam menghapus
     * tulisan orang lain. `jumlah_selesai` TIDAK PERNAH disentuh di sini
     * (angka laporan teknisi), dan baris yang sudah punya progres tidak boleh
     * dibuang. Semua perubahan satu transaksi di atas baris yang dikunci, dan
     * dibaca ulang statusnya SESUDAH kunci: penugasan yang dibatalkan/selesai
     * di antara validasi dan penyimpanan tidak ikut tertimpa.
     */
    public function update(PenugasanUbahRequest $request, Penugasan $penugasan): JsonResponse
    {
        // Guard organisasi sudah jalan di `PenugasanUbahRequest::authorize()`
        // (sebelum validasi, supaya lab lain tidak bisa membedakan 404 dari 422).
        $data = $request->validated();
        $pemanggil = $request->user();

        $hasil = DB::transaction(function () use ($data, $penugasan): array {
            $terkunci = Penugasan::query()->whereKey($penugasan->id)->lockForUpdate()->firstOrFail();

            if ($terkunci->status !== Penugasan::STATUS_AKTIF) {
                return ['tidak_aktif', $terkunci->status];
            }

            $lamaItem = $terkunci->item()->get()->keyBy('id');
            $idDisebut = collect($data['item'])->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();

            // Periksa SEBELUM menulis apa pun: baris yang sudah punya progres
            // tidak boleh hilang dari daftar.
            $terlarang = $lamaItem
                ->reject(fn (PenugasanItem $i): bool => in_array($i->id, $idDisebut, true))
                ->filter(fn (PenugasanItem $i): bool => $i->jumlah_selesai > 0);

            if ($terlarang->isNotEmpty()) {
                return ['item_sudah_dikerjakan', $terlarang->map(fn (PenugasanItem $i): string => $i->jenis_alat)->values()->all()];
            }

            // Urutan bermakna (ketua dulu); `id` hanya pemecah seri.
            $teknisiLama = $terkunci->anggota()
                ->orderByRaw("CASE WHEN peran = 'ketua' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $teknisiBaru = array_map('intval', $data['teknisi']);

            // Header. Eloquent `save()`: `Diaudit` mencatat kolom yang berubah
            // (lama -> baru) dan tidak menulis apa pun kalau tidak ada.
            $header = [
                'judul' => $data['judul'],
                'tipe' => count($teknisiBaru) > 1 ? Penugasan::TIPE_GRUP : Penugasan::TIPE_PERSONAL,
            ];
            foreach (['tanggal_target', 'catatan'] as $kolom) {
                if (array_key_exists($kolom, $data)) {
                    $header[$kolom] = $data[$kolom];
                }
            }
            $terkunci->fill($header)->save();

            // Anggota: yang bertahan dipertahankan (termasuk `dilihat_pada`),
            // yang keluar dihapus barisnya, yang baru dibuat. Yang pertama
            // di daftar jadi ketua.
            $terkunci->anggota()->whereNotIn('user_id', $teknisiBaru)->delete();
            $barisAnggota = $terkunci->anggota()->get()->keyBy('user_id');

            foreach ($teknisiBaru as $urutan => $userId) {
                $peran = $urutan === 0 ? PenugasanTeknisi::PERAN_KETUA : PenugasanTeknisi::PERAN_ANGGOTA;

                if (isset($barisAnggota[$userId])) {
                    if ($barisAnggota[$userId]->peran !== $peran) {
                        $barisAnggota[$userId]->forceFill(['peran' => $peran])->save();
                    }

                    continue;
                }

                $terkunci->anggota()->create(['user_id' => $userId, 'peran' => $peran]);
            }

            if ($teknisiLama !== $teknisiBaru) {
                // `penugasan_teknisi` tidak ber-`Diaudit`; perubahan anggotanya
                // dicatat di sini, di entitas penugasannya.
                $terkunci->catatAudit(
                    AuditLog::ACTION_DIUBAH,
                    ['teknisi' => $teknisiLama],
                    ['teknisi' => $teknisiBaru],
                    'Ubah penugasan: daftar teknisi',
                );
            }

            // Baris yang tidak disebut dan belum punya progres: dibuang lewat
            // query builder + catatan eksplisit yang MEMUAT isinya (hard
            // delete tanpa isi di jejak = riwayat tak bisa direkonstruksi).
            foreach ($lamaItem->reject(fn (PenugasanItem $i): bool => in_array($i->id, $idDisebut, true)) as $buang) {
                $buang->catatAudit(
                    AuditLog::ACTION_DIHAPUS,
                    $buang->only(['jenis_alat', 'jumlah', 'equipment_category_id', 'order_id', 'catatan']),
                    null,
                    'Ubah penugasan: baris dibuang',
                );
                PenugasanItem::query()->whereKey($buang->id)->delete();
            }

            foreach ($data['item'] as $baris) {
                $atribut = [
                    'jenis_alat' => $baris['jenis_alat'],
                    'jumlah' => $baris['jumlah'],
                ];
                foreach (['equipment_category_id', 'order_id', 'catatan'] as $kolom) {
                    if (array_key_exists($kolom, $baris)) {
                        $atribut[$kolom] = $baris[$kolom];
                    }
                }

                if (! empty($baris['id'])) {
                    // `jumlah_selesai` sengaja tidak ada di `$atribut`.
                    $lamaItem[(int) $baris['id']]->fill($atribut)->save();

                    continue;
                }

                $terkunci->item()->create($atribut);
            }

            return ['ok', array_values(array_diff($teknisiBaru, $teknisiLama))];
        });

        if ($hasil[0] === 'tidak_aktif') {
            return $this->galatTidakAktif($hasil[1]);
        }

        if ($hasil[0] === 'item_sudah_dikerjakan') {
            return response()->json([
                'message' => 'Baris ini sudah ada progresnya ('.implode(', ', $hasil[1]).'), jadi tidak bisa dibuang. '
                    .'Ubah jumlahnya saja.',
                'kode' => 'item_sudah_dikerjakan',
                'errors' => ['item' => ['Baris yang sudah ada progresnya tidak bisa dibuang.']],
            ], 422);
        }

        $penugasan->refresh()->load(self::RELASI);

        // Notifikasi di LUAR transaksi dan dibungkus `try`, alasan yang sama
        // dengan `store()`. Hanya anggota BARU yang dikabari; yang keluar cuma
        // melihat daftar tugasnya menyegar.
        $baru = $hasil[1];
        if ($baru !== []) {
            try {
                Notification::send(
                    User::query()->whereIn('id', $baru)->get(),
                    PenugasanBaru::dariPenugasan($penugasan, $pemanggil->name),
                );
            } catch (\Throwable $e) {
                Log::warning('Notifikasi penugasan (anggota baru) gagal dikirim.', [
                    'penugasan_id' => $penugasan->id,
                    'pesan' => $e->getMessage(),
                ]);
            }
        }

        PerubahanDataOrganisasi::siarkanAman($penugasan->organization_id, 'penugasan', 'diubah', $penugasan->id);

        return response()->json([
            'message' => 'Penugasan diubah.',
            'data' => $this->bentuk($penugasan),
        ]);
    }

    /**
     * Batalkan penugasan `aktif` dengan ALASAN (admin & super admin).
     *
     * Tidak ada yang dihapus: anggota, item, dan progres tetap terbaca; yang
     * berubah cuma `status`. Alasan, pelaku, dan waktunya di `audit_logs`
     * (aksi `dibatalkan`) dan dibaca balik lewat `JejakAlasan`. Idempoten:
     * sudah `dibatalkan` -> 200 tanpa baris audit atau notifikasi kedua.
     */
    public function batalkan(Request $request, Penugasan $penugasan): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $penugasan);

        $data = $request->validate(['alasan' => CatatanAlasan::aturan()]);
        $pemanggil = $request->user();

        $hasil = DB::transaction(function () use ($penugasan, $data): array {
            $terkunci = Penugasan::query()->whereKey($penugasan->id)->lockForUpdate()->firstOrFail();

            if ($terkunci->status === Penugasan::STATUS_DIBATALKAN) {
                return ['sudah', null];
            }

            if ($terkunci->status !== Penugasan::STATUS_AKTIF) {
                return ['tidak_aktif', $terkunci->status];
            }

            $sekarang = now();

            // Query builder bersyarat + satu baris audit yang MEMBAWA alasan
            // (pola `approve()` / `JatuhTempoAlatController`), bukan `save()`
            // yang menulis baris `diubah` tanpa alasan.
            $berubah = Penugasan::query()
                ->whereKey($terkunci->id)
                ->where('status', Penugasan::STATUS_AKTIF)
                ->update(['status' => Penugasan::STATUS_DIBATALKAN, 'updated_at' => $sekarang]);

            if ($berubah === 1) {
                $terkunci->catatAudit(
                    AuditLog::ACTION_DIBATALKAN,
                    ['status' => Penugasan::STATUS_AKTIF],
                    ['status' => Penugasan::STATUS_DIBATALKAN, 'alasan' => $data['alasan']],
                    CatatanAlasan::note(CatatanAlasan::AWALAN_BATALKAN_PENUGASAN, $data['alasan']),
                );
            }

            return ['ok', null];
        });

        if ($hasil[0] === 'tidak_aktif') {
            return response()->json([
                'message' => 'Penugasan ini sudah '.$hasil[1].', jadi tidak bisa dibatalkan.',
                'kode' => 'penugasan_sudah_selesai',
                'status' => $hasil[1],
            ], 422);
        }

        $penugasan->refresh()->load(self::RELASI);

        if ($hasil[0] === 'ok') {
            try {
                Notification::send(
                    User::query()->whereIn('id', $penugasan->anggota->pluck('user_id'))->get(),
                    PenugasanDibatalkan::dariPenugasan($penugasan, $pemanggil->name, $data['alasan']),
                );
            } catch (\Throwable $e) {
                Log::warning('Notifikasi pembatalan penugasan gagal dikirim.', [
                    'penugasan_id' => $penugasan->id,
                    'pesan' => $e->getMessage(),
                ]);
            }

            PerubahanDataOrganisasi::siarkanAman($penugasan->organization_id, 'penugasan', 'diubah', $penugasan->id);
        }

        return response()->json([
            'message' => $hasil[0] === 'sudah' ? 'Penugasan sudah dibatalkan.' : 'Penugasan dibatalkan.',
            'data' => $this->bentuk($penugasan, $this->jejakBatal($penugasan->organization_id, [$penugasan])),
        ]);
    }

    private function galatTidakAktif(string $status): JsonResponse
    {
        return response()->json([
            'message' => 'Penugasan ini sudah '.$status.', jadi tidak bisa diubah.',
            'kode' => 'penugasan_tidak_aktif',
            'status' => $status,
        ], 422);
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

    /**
     * Alasan/pelaku/waktu pembatalan untuk penugasan yang berstatus
     * `dibatalkan`, dibaca dari `audit_logs` (tidak ada kolom `alasan_batal`).
     * Satu kueri untuk seluruh halaman.
     *
     * @param  iterable<Penugasan>  $daftar
     * @return array<int, array{alasan: string|null, oleh: array{id: int|null, nama: string|null}, pada: string|null}>
     */
    private function jejakBatal(int $organizationId, iterable $daftar): array
    {
        $ids = [];

        foreach ($daftar as $p) {
            if ($p->status === Penugasan::STATUS_DIBATALKAN) {
                $ids[] = (int) $p->id;
            }
        }

        return JejakAlasan::terakhir($organizationId, 'penugasan', $ids, AuditLog::ACTION_DIBATALKAN);
    }

    /**
     * @param  array<int, array{alasan: string|null, oleh: array{id: int|null, nama: string|null}, pada: string|null}>  $batal
     * @return array<string, mixed>
     */
    private function bentuk(Penugasan $p, array $batal = []): array
    {
        $jejak = $batal[$p->id] ?? null;

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

            // Additive (paket CRUD 18 Nov 2026): null selama belum dibatalkan.
            // `oleh` = NAMA pembatal (string), sama bentuknya dengan
            // `dibuat_oleh`. Penugasan lama yang dibatalkan lewat panel (tanpa
            // baris audit `dibatalkan`) tetap `dibatalkan`, `batal`-nya
            // berisi alasan null.
            'batal' => $p->status !== Penugasan::STATUS_DIBATALKAN ? null : [
                'alasan' => $jejak['alasan'] ?? null,
                'oleh' => $jejak['oleh']['nama'] ?? null,
                'pada' => $jejak['pada'] ?? null,
            ],

            // Ketua dulu, lalu urutan masuk. Sebelum edit, ketua selalu baris
            // pertama (dibuat lebih dulu); sesudah edit anggota yang bertahan bisa
            // lebih tua dari ketua barunya, jadi urutannya ditegaskan di sini.
            'teknisi' => $p->anggota
                ->sortBy(fn (PenugasanTeknisi $a): array => [$a->peran === PenugasanTeknisi::PERAN_KETUA ? 0 : 1, $a->id])
                ->map(fn (PenugasanTeknisi $a): array => [
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
