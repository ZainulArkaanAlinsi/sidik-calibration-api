<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EquipmentRequest;
use App\Http\Resources\EquipmentResource;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Baca: semua role. Tulis: admin & teknisi doang (viewer ditolak 403 lewat
 * middleware `role:admin,teknisi` di routes/api.php).
 */
class EquipmentController extends Controller
{
    public function index(Request $request, CalibrationProfileRegistry $registry): AnonymousResourceCollection
    {
        $organisasi = $request->user()->organization_id;

        $equipments = Equipment::query()
            ->with(['customer', 'category'])
            ->where('organization_id', $organisasi)
            ->when(
                $request->filled('profil'),
                fn (Builder $query) => $this->saringProfil(
                    $query,
                    $registry,
                    (string) $request->string('profil'),
                    $organisasi,
                ),
            )
            ->when($request->filled('search'), function ($query) use ($request) {
                $keyword = '%'.$request->string('search').'%';

                $query->where(fn ($q) => $q
                    ->where('nama_alat', 'like', $keyword)
                    ->orWhere('serial_number', 'like', $keyword)
                    ->orWhere('merk', 'like', $keyword));
            })
            ->when($request->filled('category'), fn ($query) => $query->whereHas(
                'category',
                fn ($q) => $q->where('kode', $request->string('category')),
            ))
            ->when($request->filled('status'), function ($query) use ($request) {
                $status = (string) $request->string('status');

                // `overdue` bukan nilai di DB — dia turunan tanggal jatuh tempo.
                match ($status) {
                    Equipment::STATUS_OVERDUE => $query->overdue(),
                    Equipment::STATUS_AKTIF => $query->where('status', Equipment::STATUS_AKTIF)
                        ->where(fn ($q) => $q->whereNull('tanggal_jatuh_tempo')
                            ->orWhereDate('tanggal_jatuh_tempo', '>=', now())),
                    default => $query->where('status', $status),
                };
            })
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return EquipmentResource::collection($equipments);
    }

    /**
     * Saring daftar alat ke SATU lembar kerja.
     *
     * ## Kenapa ada
     *
     * Sebelum ini penyaringnya cuma `?category=`, dan kategori jauh lebih kasar
     * daripada lembar kerja: "Suhu dan Kelembapan" memuat 11 jenis alat yang
     * memetakan ke TUJUH lembar berbeda. Jadi waktu teknisi membuka lembar
     * TITS, kotak pilih alatnya juga menyodorkan Oven, Bath, Inkubator,
     * Furnace, Refrigerator, dan TIDS. Yang dipilih salah nggak bikin error di
     * mana pun — sesinya tersimpan, dan `untukAlat()` diam-diam menghitungnya
     * pakai aturan alat lain.
     *
     * ## Pencocokannya NUMPANG aturan yang sudah ada, bukan bikin salinan
     *
     * `CalibrationProfileRegistry::cocokkanNama()` itu satu-satunya tempat yang
     * tahu cara mencocokkan nama alat ke profil, dan aturannya bukan
     * perbandingan teks: huruf besar/kecil diabaikan, kunci boleh nempel di
     * TENGAH nama ("Turbidimeter Hach", "pH Meter Mettler Toledo"), dan kunci
     * terpanjang dicoba duluan.
     *
     * Menulis ulang itu sebagai `WHERE nama_alat_kemampuan = ?` bakal
     * menyembunyikan alat pelanggan yang sah cuma karena ejaannya beda — dan
     * kegagalannya diam: alatnya ADA, tapi nggak muncul, teknisi mengira belum
     * terdaftar, lalu menambah duplikat yang nggak punya baris CMC. Docblock
     * `untukNamaAlat()` sudah mewanti hal yang sama: "kalau aturannya mau
     * diubah, ubah di cocokkanNama — jangan bikin salinan ketiga."
     *
     * Jadi caranya: nama-nama DISTINCT milik lab ini ditarik duluan, tiap nama
     * dilewatkan registry sekali, lalu yang cocok dipakai sebagai `whereIn`.
     * Hasilnya tetap satu query berpaginasi — penyaringan sesudah paginasi
     * bakal bikin jumlah halamannya bohong.
     *
     * ## Fallback kolomnya IKUT `untukAlat()`, dan itu disengaja
     *
     * `untukAlat()` membaca `nama_alat_kemampuan` dengan cadangan `nama_alat`.
     * Saringan ini menyalin urutan itu persis. Kalau beda, teknisi bisa melihat
     * alat di daftar lembar A sementara mesin menghitungnya sebagai lembar B —
     * ketidakcocokan yang sama yang docblock registry sebut "diam dan mahal".
     *
     * Akibat yang ikut & sengaja dibiarkan: alat lama yang KEDUA kolom namanya
     * kosong resolve ke profil bawaan (pH), jadi dia muncul di daftar lembar pH.
     * Itu memang yang bakal dipakai mesin hitung buat alat itu, dan
     * menyembunyikannya justru bikin daftar dan hitungan nggak sepakat.
     */
    private function saringProfil(Builder $query, CalibrationProfileRegistry $registry, string $kode, ?int $organisasi): void
    {
        // Kode profil yang nggak dikenal DITOLAK, bukan diabaikan.
        //
        // `when()` yang jatuh ke "nggak nyaring apa-apa" bikin `?profil=tit`
        // (salah ketik) memulangkan SELURUH alat lab — persis daftar tak
        // tersaring yang mau dihilangkan, tapi sekarang kelihatan seperti
        // jawaban yang benar.
        if ($registry->untukKode($kode) === null) {
            abort(422, "Kode lembar kerja `{$kode}` nggak dikenal. Lihat `profil` di GET /api/categories/{kode}.");
        }

        $cocok = [];
        $namaKosongIkut = false;

        foreach ($this->namaAlatTerdaftar($organisasi) as $nama) {
            $profil = $registry->untukNamaAlat($nama);

            if ($profil->kode() !== $kode) {
                continue;
            }

            if ($nama === '') {
                $namaKosongIkut = true;

                continue;
            }

            $cocok[] = $nama;
        }

        $query->where(function (Builder $q) use ($cocok, $namaKosongIkut): void {
            $q->whereIn('nama_alat_kemampuan', $cocok)
                ->orWhere(fn (Builder $r) => $r->whereNull('nama_alat_kemampuan')->whereIn('nama_alat', $cocok));

            // Cuma buat profil bawaan — lihat catatan fallback di docblock.
            if ($namaKosongIkut) {
                $q->orWhere(
                    fn (Builder $r) => $r->whereNull('nama_alat_kemampuan')
                        ->where(fn (Builder $s) => $s->whereNull('nama_alat')->orWhere('nama_alat', '')),
                );
            }
        });
    }

    /**
     * Nama jenis alat DISTINCT milik satu lab, apa adanya.
     *
     * Dua kolom karena `untukAlat()` juga membaca dua: `nama_alat_kemampuan`
     * kalau ada, `nama_alat` kalau nggak. String kosong ikut dipulangkan (bukan
     * dibuang) supaya pemanggil bisa memutuskan sendiri — itu yang menentukan
     * alat tanpa nama masuk daftar profil bawaan atau nggak.
     *
     * @return list<string>
     */
    private function namaAlatTerdaftar(?int $organisasi): array
    {
        $kolom = static fn (string $nama): Collection => Equipment::query()
            ->where('organization_id', $organisasi)
            ->when(
                $nama === 'nama_alat',
                fn (Builder $q) => $q->whereNull('nama_alat_kemampuan'),
            )
            ->distinct()
            ->pluck($nama);

        return $kolom('nama_alat_kemampuan')
            ->merge($kolom('nama_alat'))
            ->map(static fn (?string $n): string => (string) $n)
            ->unique()
            ->values()
            ->all();
    }

    public function store(EquipmentRequest $request): JsonResponse
    {
        $equipment = Equipment::create($this->payload($request));

        return response()->json(
            ['data' => new EquipmentResource($equipment->load(['customer', 'category']))],
            201,
        );
    }

    public function show(Request $request, Equipment $equipment): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $equipment);

        return response()->json([
            'data' => new EquipmentResource($equipment->load(['customer', 'category'])),
        ]);
    }

    public function update(EquipmentRequest $request, Equipment $equipment): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $equipment);

        $equipment->update($this->payload($request));

        return response()->json([
            'data' => new EquipmentResource($equipment->fresh()->load(['customer', 'category'])),
        ]);
    }

    public function destroy(Request $request, Equipment $equipment): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $equipment);

        // Soft delete — riwayat kalibrasi & sertifikat lama harus tetap bisa ditelusuri.
        $equipment->delete();

        return response()->json(['message' => 'Alat dihapus.']);
    }

    /**
     * Mobile ngirim `kategori` (kode) & `pelanggan_id`; DB nyimpennya sebagai FK.
     *
     * @return array<string, mixed>
     */
    private function payload(EquipmentRequest $request): array
    {
        $data = $request->validated();
        $organizationId = $request->user()->organization_id;

        if (isset($data['kategori'])) {
            $data['equipment_category_id'] = EquipmentCategory::where('organization_id', $organizationId)
                ->where('kode', $data['kategori'])
                ->value('id');
        }

        if (isset($data['pelanggan_id'])) {
            $data['customer_id'] = $data['pelanggan_id'];
        }

        unset($data['kategori'], $data['pelanggan_id']);

        return [...$data, 'organization_id' => $organizationId];
    }

    /** Jaring pengaman multi-tenant: jangan sampai PT lain bisa baca/ubah alat kita. */
    private function pastikanSatuOrganisasi(Request $request, Equipment $equipment): void
    {
        abort_if($equipment->organization_id !== $request->user()->organization_id, 404);
    }
}
