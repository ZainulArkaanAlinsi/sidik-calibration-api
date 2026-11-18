<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalibrationMethodResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\EquipmentResource;
use App\Http\Resources\RoomResource;
use App\Http\Resources\StandardResource;
use App\Models\AuditLog;
use App\Models\CalibrationMethod;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Room;
use App\Models\Standard;
use App\Services\PenjagaOrganisasi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pulihkan data master yang sudah dihapus (soft-delete) —
 * `POST /api/{entitas}/{id}/pulihkan`, admin & super admin.
 *
 * Ada karena (BUG-5 & BUG-7 uji E2E-QA, 18 Nov 2026) salah hapus tidak bisa
 * dibatalkan dari aplikasi, sementara indeks unik di database tetap memegang
 * kode/nama/seri baris yang terhapus: kodenya terkunci dan tidak ada jalan
 * keluar. Baris lama TIDAK dipulihkan otomatis oleh siapa pun — hanya lewat
 * aksi ini, dengan jejak audit.
 *
 * ## Aturan
 *
 *  - lab lain → 404, sama seperti `DELETE`; baris tetap terhapus;
 *  - baris yang tidak sedang terhapus → 422 `kode: tidak_terhapus`;
 *  - alat yang PT pemiliknya masih terhapus → 422 `kode: pt_terhapus` + `id` PT
 *    itu: alat tanpa pemilik hidup jadi yatim. Pulihkan PT-nya dulu;
 *  - memulihkan tidak pernah bentrok dengan indeks unik, karena barisnya
 *    sendiri yang memegang kuncinya sejak dulu.
 *
 * ## Jejak audit
 *
 * SATU baris `audit_logs` per pemulihan: `action = dipulihkan`, pelaku, waktu,
 * nilai sebelum `{deleted_at: <waktu hapus>}` dan sesudah `{deleted_at: null}`,
 * dan `note` berisi alasan bila dikirim. Pencatatan otomatis event `restored`
 * dimatikan di sini supaya yang tercatat bukan dua baris dan alasannya ikut.
 */
class PemulihanDataController extends Controller
{
    /** Panjang alasan dijaga di bawah kolom `audit_logs.note` (500). */
    private const MAKS_ALASAN = 300;

    /**
     * slug rute → model, resource, relasi yang dimuat sebelum dibalas.
     *
     * Daftar ini sama dengan rute di `routes/api.php` (grup `role:admin`).
     *
     * @var array<string, array{model: class-string<Model>, resource: class-string, muat: list<string>}>
     */
    public const ENTITAS = [
        'rooms' => ['model' => Room::class, 'resource' => RoomResource::class, 'muat' => []],
        'calibration-methods' => [
            'model' => CalibrationMethod::class, 'resource' => CalibrationMethodResource::class, 'muat' => ['category'],
        ],
        'standards' => ['model' => Standard::class, 'resource' => StandardResource::class, 'muat' => []],
        'equipments' => [
            'model' => Equipment::class, 'resource' => EquipmentResource::class, 'muat' => ['customer', 'category'],
        ],
        'customers' => ['model' => Customer::class, 'resource' => CustomerResource::class, 'muat' => []],
    ];

    /**
     * Urutan `$id` lalu `$entitas` mengikuti urutan parameter rute: `{id}` ada
     * di URI, `entitas` datang dari `->defaults()` dan selalu paling akhir.
     */
    public function pulihkan(Request $request, int $id, string $entitas): JsonResponse
    {
        $konfig = self::ENTITAS[$entitas] ?? abort(404);

        /** @var class-string<Model> $kelas */
        $kelas = $konfig['model'];

        // Termasuk yang terhapus — implicit binding tidak melihatnya.
        $baris = $kelas::withTrashed()->find($id);
        abort_if($baris === null, 404);

        // Satu tempat yang memutuskan lab pemilik, dan mencatat percobaannya.
        PenjagaOrganisasi::pastikanSatu($request, $baris);

        $alasan = $request->validate([
            'alasan' => ['nullable', 'string', 'max:'.self::MAKS_ALASAN],
        ])['alasan'] ?? null;

        if (! $baris->trashed()) {
            return $this->tolak('tidak_terhapus', 'Data ini tidak sedang terhapus, jadi tidak ada yang perlu dipulihkan.');
        }

        if ($baris instanceof Equipment) {
            $pemilik = Customer::withTrashed()->find($baris->customer_id);

            if ($pemilik !== null && $pemilik->trashed()) {
                return $this->tolak(
                    'pt_terhapus',
                    'PT pemilik alat ini masih terhapus. Pulihkan PT-nya dulu, baru alatnya.',
                    ['id' => $pemilik->getKey(), 'entitas' => 'customers'],
                );
            }
        }

        $waktuHapus = $baris->deleted_at;

        DB::transaction(function () use ($kelas, $baris, $waktuHapus, $alasan): void {
            // Event `restored` otomatis (Diaudit) dimatikan: baris auditnya
            // ditulis sekali di bawah, lengkap dengan alasan dan nilai sebelum.
            $kelas::tanpaAudit(fn () => $baris->restore());

            $baris->catatAudit(
                AuditLog::ACTION_DIPULIHKAN,
                ['deleted_at' => $waktuHapus?->toIso8601String()],
                ['deleted_at' => null],
                $alasan !== null ? 'Dipulihkan: '.$alasan : null,
            );
        });

        $baris->refresh()->load($konfig['muat']);

        if ($baris instanceof Customer) {
            $baris->loadCount('equipments');
        }

        return response()->json(['data' => new $konfig['resource']($baris)]);
    }

    /**
     * @param  array<string, mixed>  $tambahan
     */
    private function tolak(string $kode, string $pesan, array $tambahan = []): JsonResponse
    {
        return response()->json(['message' => $pesan, 'kode' => $kode, ...$tambahan], 422);
    }
}
