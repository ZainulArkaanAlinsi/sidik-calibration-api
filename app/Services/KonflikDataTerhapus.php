<?php

namespace App\Services;

use App\Models\CalibrationMethod;
use App\Models\Customer;
use App\Models\Room;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat yang tahu kenapa sebuah kode/nama ditolak karena
 * dipegang baris yang sudah DIHAPUS.
 *
 * Indeks unik di database (`rooms`, `calibration_methods`, `customers`) tidak
 * mengecualikan baris soft-delete, jadi kode atau nama yang pernah dipakai
 * tetap terkunci sesudah barisnya dihapus. Aturan validasi
 * `Rule::unique()->whereNull('deleted_at')` lebih longgar dari indeks itu:
 * kode lolos validasi, INSERT gagal, dan klien menerima 500 tanpa pesan yang
 * bisa ditindaklanjuti (BUG-5 uji E2E-QA, 18 Nov 2026). Untuk PT, validasi
 * sudah ikut melihat baris terhapus, tapi pesannya generik dan tidak ada jalan
 * memulihkan (BUG-7).
 *
 * Jawabannya sama di semua entitas — 422 berbentuk validasi biasa
 * (`errors.<field>`), ditambah dua kunci di atasnya supaya aplikasi bisa
 * menawarkan "Pulihkan" tanpa mengurai kalimat:
 *
 * ```json
 * { "message": "...", "errors": {"kode": ["..."]},
 *   "kode": "terhapus", "id": 12, "entitas": "rooms" }
 * ```
 *
 * Yang memulihkan `POST /{entitas}/{id}/pulihkan` ([PemulihanDataController]).
 * Baris lama TIDAK dipulihkan otomatis: itu memunculkan kembali data beserta
 * riwayatnya tanpa ada yang meminta.
 *
 * Balapan (dua POST kode sama, indeks yang memutuskan) diterjemahkan lewat
 * [terjemahkan] ke 422 yang sama dengan validasi, bukan 500.
 */
final class KonflikDataTerhapus
{
    public const KODE = 'terhapus';

    /**
     * @param  class-string<Model>  $model
     * @param  string  $slug  nama entitas di URL `/api/{slug}/{id}/pulihkan`
     * @param  string  $field  field yang diberi galat
     * @param  string  $kolomIndeks  kolom yang pasti muncul di pesan galat indeks unik
     * @param  string  $sebutan  "Kode ruangan" — subjek kalimat pesan
     * @param  string  $pengganti  "kode" — apa yang diminta dipakai lain
     * @param  string  $pesanDipakai  pesan bila yang memegang justru baris HIDUP
     */
    private function __construct(
        private readonly string $model,
        private readonly string $slug,
        private readonly string $field,
        private readonly string $kolomIndeks,
        private readonly string $sebutan,
        private readonly string $pengganti,
        private readonly string $pesanDipakai,
    ) {}

    public static function untukRuangan(): self
    {
        return new self(
            Room::class, 'rooms', 'kode', 'kode',
            'Kode ruangan', 'kode', 'Kode ruangan ini sudah dipakai.',
        );
    }

    public static function untukMetodeIk(): self
    {
        return new self(
            CalibrationMethod::class, 'calibration-methods', 'kode', 'kode',
            'Kode IK dengan revisi ini', 'kode atau revisi',
            'Kode IK dengan revisi ini udah ada. Revisi baru = kode sama, nomor revisi beda.',
        );
    }

    public static function untukPelanggan(): self
    {
        return new self(
            Customer::class, 'customers', 'nama', 'nama',
            'Nama PT ini', 'nama',
            'Pelanggan dengan nama ini sudah ada. Nama PT dipakai sebagai kunci folder & berkasnya, '
                .'jadi nggak boleh kembar — buka yang sudah ada, atau bedakan namanya '
                .'(mis. tambah kota atau cabangnya).',
        );
    }

    /**
     * Baris TERHAPUS di lab ini yang masih memegang kunci itu, atau null.
     *
     * @param  array<string, mixed>  $kunci  kolom → nilai yang diperiksa
     */
    public function pemegang(int $organisasiId, array $kunci, ?int $kecualiId = null): ?Model
    {
        // Nilai null tidak pernah bentrok di indeks unik (NULL berbeda dari
        // NULL di MySQL maupun SQLite) — jangan menolak lebih keras dari DB.
        if (in_array(null, $kunci, true)) {
            return null;
        }

        return ($this->model)::onlyTrashed()
            ->where('organization_id', $organisasiId)
            ->where($kunci)
            ->when($kecualiId !== null, fn ($q) => $q->whereKeyNot($kecualiId))
            ->first();
    }

    /**
     * Tolak (422 "terhapus") bila kunci itu dipegang baris yang sudah dihapus.
     *
     * @param  array<string, mixed>  $kunci
     *
     * @throws ValidationException
     */
    public function periksa(int $organisasiId, array $kunci, ?int $kecualiId = null): void
    {
        $terhapus = $this->pemegang($organisasiId, $kunci, $kecualiId);

        if ($terhapus !== null) {
            $this->tolakTerhapus($terhapus);
        }
    }

    /**
     * Ubah galat indeks unik (balapan) jadi 422 yang sama dengan validasi.
     * Galat unik yang BUKAN soal kolom ini dilempar ulang apa adanya.
     *
     * @param  array<string, mixed>  $kunci
     *
     * @throws ValidationException
     * @throws UniqueConstraintViolationException
     */
    public function terjemahkan(UniqueConstraintViolationException $galat, int $organisasiId, array $kunci): never
    {
        if (! str_contains($galat->getMessage(), $this->kolomIndeks)) {
            throw $galat;
        }

        $this->periksa($organisasiId, $kunci);

        throw ValidationException::withMessages([$this->field => [$this->pesanDipakai]]);
    }

    /** @throws ValidationException */
    private function tolakTerhapus(Model $terhapus): never
    {
        $pesan = "{$this->sebutan} pernah dipakai dan sudah dihapus (terhapus): "
            ."pulihkan yang lama atau pakai {$this->pengganti} lain.";

        $galat = ValidationException::withMessages([$this->field => [$pesan]]);
        $galat->response = response()->json([
            'message' => $pesan,
            'errors' => [$this->field => [$pesan]],
            'kode' => self::KODE,
            'id' => $terhapus->getKey(),
            'entitas' => $this->slug,
        ], 422);

        throw $galat;
    }
}
