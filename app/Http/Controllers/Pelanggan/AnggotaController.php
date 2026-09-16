<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pelanggan\UndangAnggotaRequest;
use App\Http\Resources\Pelanggan\AnggotaResource;
use App\Mail\Pelanggan\UndanganEmail;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\UndanganPelanggan;
use App\Services\Pelanggan\Keanggotaan;
use App\Services\Pelanggan\KodeUndangan;
use App\Support\Pelanggan\Konteks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `/anggota` — daftar, undang, batalkan undangan, nonaktifkan (REQ-ANG-01..03).
 *
 * Perusahaannya SELALU dari `Konteks`, tidak pernah dari parameter rute atau
 * badan permintaan. Itu yang membuat seluruh controller ini tidak punya satu
 * pun tempat di mana pelanggan bisa menyebut perusahaan yang bukan miliknya —
 * bukan karena setiap query ingat menyaring, tapi karena tidak ada ID lain yang
 * bisa masuk.
 */
class AnggotaController extends Controller
{
    /**
     * `Konteks` SENGAJA tidak di constructor — dia state per-request.
     *
     * `Route::getController()` menyimpan instance controller di objek Route:
     *
     *     if (! $this->controller) { $this->controller = $this->container->make(...); }
     *
     * Objek Route hidup selama aplikasinya hidup. Di produksi itu satu request,
     * jadi tidak kelihatan. Di test satu instance aplikasi melayani semua
     * request dalam satu method, jadi controller request KEDUA masih memegang
     * `Konteks` milik request PERTAMA — dan yang dijawab data perusahaan yang
     * salah, tanpa satu pun error.
     *
     * Ketahuan dari test yang mengadu dua perusahaan berurutan. Argumen method
     * di-resolve tiap dispatch, jadi dia selalu konteks request yang sekarang.
     * Dua service di bawah stateless, jadi aman di constructor.
     */
    public function __construct(
        private readonly Keanggotaan $keanggotaan,
        private readonly KodeUndangan $kodeUndangan,
    ) {}

    /** REQ-ANG-03 — semua peran boleh MELIHAT. */
    public function index(Request $request, Konteks $konteks): JsonResponse
    {
        $anggota = CustomerMember::query()
            ->with('user')
            ->where('customer_id', $konteks->customerId)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [CustomerMember::STATUS_AKTIF])
            ->orderBy('id')
            ->get();

        $undangan = UndanganPelanggan::query()
            ->where('customer_id', $konteks->customerId)
            ->whereNull('dipakai_pada')
            ->whereNull('dibatalkan_pada')
            ->where('kedaluwarsa_pada', '>', now())
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'anggota' => AnggotaResource::collection($anggota)->resolve(),
                // Undangan yang belum ditukar ikut ditampilkan: tanpa itu PIC
                // utama tidak punya cara tahu dia sudah mengundang seseorang,
                // dan yang dia lakukan mengundang lagi — yang justru
                // membatalkan kode yang sudah terkirim.
                'undangan_menunggu' => $undangan->map(fn (UndanganPelanggan $satu) => [
                    'id' => $satu->getKey(),
                    'email' => $satu->email,
                    'peran' => $satu->peran,
                    'kedaluwarsa_pada' => $satu->kedaluwarsa_pada?->toIso8601String(),
                ])->values(),
                'maks_anggota' => (int) ($this->perusahaan($konteks)->maks_anggota ?: 0),
                'saya' => $konteks->toArray(),
            ],
        ]);
    }

    /** REQ-ANG-01 — PIC utama mengundang. */
    public function undang(UndangAnggotaRequest $request, Konteks $konteks): JsonResponse
    {
        $perusahaan = $this->perusahaan($konteks);
        $email = $request->string('email')->toString();

        $this->keanggotaan->pastikanMuat($perusahaan);

        $sudah = CustomerMember::query()
            ->where('customer_id', $perusahaan->getKey())
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->whereHas('user', fn ($kueri) => $kueri->where('email', $email))
            ->exists();

        if ($sudah) {
            throw ValidationException::withMessages(['email' => 'Orang ini sudah jadi anggota aktif.']);
        }

        $hasil = $this->kodeUndangan->terbitkan($perusahaan, $email, $request->string('peran')->toString(), $request->user());

        UndanganEmail::kirim($hasil['undangan'], $hasil['kode'], $perusahaan);

        return response()->json([
            'message' => 'Undangan dikirim ke '.$email.'.',
            'data' => [
                'id' => $hasil['undangan']->getKey(),
                'email' => $hasil['undangan']->email,
                'peran' => $hasil['undangan']->peran,
                'kedaluwarsa_pada' => $hasil['undangan']->kedaluwarsa_pada?->toIso8601String(),
            ],
        ], 201);
    }

    public function batalkanUndangan(UndanganPelanggan $undangan, Konteks $konteks): JsonResponse
    {
        // 404 buat undangan perusahaan lain, bukan 403 — alasan yang sama
        // dengan seluruh modul ini.
        abort_unless((int) $undangan->customer_id === $konteks->customerId, 404);

        $undangan->forceFill(['dibatalkan_pada' => now()])->save();

        return response()->json(['message' => 'Undangan dibatalkan.']);
    }

    /** REQ-ANG-02 — PIC utama menonaktifkan anggota. */
    public function nonaktifkan(Request $request, CustomerMember $anggota, Konteks $konteks): JsonResponse
    {
        abort_unless((int) $anggota->customer_id === $konteks->customerId, 404);

        if ($anggota->status !== CustomerMember::STATUS_AKTIF) {
            return response()->json([
                'kode' => 'sudah_nonaktif',
                'message' => 'Anggota ini sudah nonaktif.',
            ], 422);
        }

        // `Keanggotaan::nonaktifkan()` yang menegakkan REQ-ANG-02 (PIC utama
        // terakhir) dan REQ-AUTH-09 (cabut token & perangkat) — dua aturan yang
        // sengaja tidak ditulis di sini supaya jalur admin lab dan jalur PIC
        // utama tidak bisa berbeda.
        $this->keanggotaan->nonaktifkan($anggota, $request->user());

        return response()->json([
            'message' => 'Anggota dinonaktifkan. Seluruh sesinya dicabut.',
            'data' => (new AnggotaResource($anggota->fresh()->load('user')))->resolve(),
        ]);
    }

    private function perusahaan(Konteks $konteks): Customer
    {
        return Customer::query()->findOrFail($konteks->customerId);
    }
}
