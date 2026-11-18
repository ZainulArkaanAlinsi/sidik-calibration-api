<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\Pelanggan\AksiPelangganDitolak;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\User;
use App\Services\PemindahanAlatPelanggan;
use App\Services\PenjagaOrganisasi;
use App\Support\AlasanAksi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/customers/{customer}/pindahkan-alat` — pindahkan alat (dan sertifikat
 * yang menempel lewat alat) dari PT `{customer}` ke PT lain di lab yang sama.
 * Kontrak: `docs/perintah-frontend-super-admin.md` §3.7. Aturan dan akibatnya
 * dijelaskan di `PemindahanAlatPelanggan`; controller ini hanya menjaga URUTAN
 * pemeriksaan.
 *
 * ## Urutan (dan kenapa urutannya penting)
 *
 * 1. PT asal di path — 404 lab lain.
 * 2. BENTUK id saja (`alat_ids`, `ke_customer_id`, `dry_run`): salah tipe → 422.
 * 3. PT tujuan dan SEMUA alat harus milik lab pemanggil — selain itu 404, tidak
 *    dibedakan dari "tidak ada".
 * 4. Baru aturan bisnis & isian yang bisa dikoreksi pemanggil (`alasan`,
 *    `konfirmasi`, PT sama, alat milik PT lain).
 *
 * Kalau 4 mendahului 3, pemanggil dari lab A yang menunjuk PT/alat lab B dan
 * lupa `alasan` mendapat 422 sedangkan id yang tidak ada mendapat 404 — perbedaan
 * itu membuktikan baris lab B ada.
 */
class PemindahanAlatController extends Controller
{
    public function __construct(private readonly PemindahanAlatPelanggan $pemindahan) {}

    public function __invoke(Request $request, Customer $customer): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $customer);

        $data = $request->validate([
            'alat_ids' => ['required', 'array', 'min:1', 'max:100'],
            'alat_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'ke_customer_id' => ['required', 'integer', 'min:1'],
            'dry_run' => ['sometimes', 'boolean'],
        ], [
            'alat_ids.required' => 'Pilih minimal satu alat.',
            'alat_ids.min' => 'Pilih minimal satu alat.',
            'alat_ids.max' => 'Maksimal 100 alat sekali pindah.',
            'alat_ids.*.distinct' => 'Ada alat yang dipilih dua kali.',
            'ke_customer_id.required' => 'PT tujuan wajib dipilih.',
        ]);

        // `findOrFail` → 404 untuk id yang tidak ada; `pastikanSatu` → 404 (dan jejak)
        // untuk yang milik lab lain. Dua-duanya jawaban yang sama dari luar.
        $tujuan = Customer::query()->findOrFail($data['ke_customer_id']);
        PenjagaOrganisasi::pastikanSatu($request, $tujuan);

        $idAlat = array_map('intval', $data['alat_ids']);
        $alat = Equipment::query()->whereIn('id', $idAlat)->get();

        abort_unless($alat->count() === count($idAlat), 404);

        foreach ($alat as $satu) {
            PenjagaOrganisasi::pastikanSatu($request, $satu);
        }

        if ((int) $tujuan->getKey() === (int) $customer->getKey()) {
            throw AksiPelangganDitolak::ptTujuanSama();
        }

        $bukanMilikAsal = $alat
            ->filter(fn (Equipment $a) => (int) $a->customer_id !== (int) $customer->getKey())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($bukanMilikAsal !== []) {
            throw AksiPelangganDitolak::alatBukanMilikPtAsal($bukanMilikAsal);
        }

        if ($request->boolean('dry_run')) {
            return response()->json(['data' => $this->pemindahan->pratinjau($customer, $tujuan, $alat)]);
        }

        $alasan = AlasanAksi::baca($request);

        if (! $request->boolean('konfirmasi')) {
            throw AksiPelangganDitolak::konfirmasiWajib();
        }

        /** @var User $pelaku */
        $pelaku = $request->user();

        $hasil = $this->pemindahan->jalankan($customer, $tujuan, $idAlat, $pelaku, (string) $alasan);

        return response()->json([
            'message' => $hasil['jumlah_alat'].' alat dipindahkan ke '.$tujuan->nama.'.',
            'data' => $hasil,
        ]);
    }
}
