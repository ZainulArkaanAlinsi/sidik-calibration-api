<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KemampuanKalibrasiRequest;
use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Nambah NAMA ALAT baru ke master kemampuan kalibrasi, dari HP teknisi.
 *
 * ## Kenapa endpoint ini ada
 *
 * Sampai sekarang `calibration_capabilities` cuma bisa diisi seeder. Kalau
 * teknisi ketemu alat yang namanya belum ada di daftar — dan itu wajar, lab
 * nerima alat yang lampiran akreditasinya nggak nyebut — dia mentok: nggak bisa
 * milih jenis alat, nggak bisa nyimpen sesi, kerjaannya berhenti sampai ada
 * admin yang buka laptop. Keputusan pemilik proyek: nama baru dari teknisi
 * LANGSUNG DIPAKAI, tanpa antrean persetujuan, dan masuk master supaya teknisi
 * lain ikut kebagian.
 *
 * ## Yang ditukar, dan gimana ditebusnya
 *
 * Yang ditukar: baris baru itu nggak punya rentang & angka CMC. Konsekuensinya
 * BUKAN "nggak ada CMC-nya" yang netral — konsekuensinya sesi yang pakai nama
 * ini dihitung lewat jalur generik tanpa lantai CMC, dan U95 yang terbit bisa
 * lebih KECIL daripada kemampuan terbaik yang diakreditasi lab. Buat lab
 * terakreditasi itu temuan audit, dan yang bikin dia berbahaya adalah dia nggak
 * ninggalin satu pun error.
 *
 * Tiga lapis penebusnya, sengaja bertumpuk karena satu lapis gampang kelewat:
 *
 *  1. **Di respons ini** — `tanpa_cmc: true` + `alasan_tanpa_cmc` + `peringatan`
 *     di tingkat atas, supaya layar teknisi bisa nampilin apa adanya begitu
 *     nama alatnya kesimpen.
 *  2. **Di mesin hitung** — `GumCalculator::kemampuanUntukTitik()` nolak baris
 *     tanpa CMC jadi kandidat, biar `range_max` NULL nggak kebaca sebagai
 *     "kemampuan di titik 0" dan nempel ke titik ukur sekitar nol.
 *  3. **Di meja admin** — `CalibrationValidator::periksaAlatTanpaCmc()` ngangkat
 *     PERINGATAN, dan approve (API maupun panel) nolak selama admin belum
 *     nyentang `abaikan_peringatan`. Jadi sertifikatnya tetap bisa terbit, tapi
 *     nggak bisa terbit tanpa ada yang lihat.
 *
 * Baca ketiganya sebagai satu penjagaan. Nyabut salah satunya bikin dua sisanya
 * kelihatan cerewet tanpa alasan.
 */
class KemampuanKalibrasiController extends Controller
{
    public function store(KemampuanKalibrasiRequest $request, string $kode): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $kategori = EquipmentCategory::query()
            ->where('organization_id', $user->organization_id)
            ->where('kode', $kode)
            ->firstOrFail();

        $kemampuan = new CalibrationCapability;
        $kemampuan->fill($request->validated());

        // Tiga kolom di bawah diisi SERVER, dan nggak satu pun boleh datang dari
        // payload.
        //
        // `organization_id` & `equipment_category_id`: diturunin dari kategori
        // yang udah disaring ke organisasi pemanggil, jadi nggak ada jalan buat
        // nitipin nama alat ke kategori lab lain lewat body request.
        //
        // `sumber` yang paling penting: dia yang bikin baris tanpa CMC bisa
        // dibedain dari salinan lampiran akreditasi — di panel admin, di
        // `GET /categories/{kode}`, dan di perisai `CalibrationCapabilitySeeder`.
        // Kalau nilainya boleh dikirim klien, satu `{"sumber":"akreditasi"}` dari
        // HP cukup buat bikin baris kosong menyamar jadi baris terakreditasi —
        // dan sesudah itu nggak ada satu pun penjagaan di sistem ini yang bisa
        // ngebedain. Makanya `sumber` juga sengaja nggak mass-assignable di
        // modelnya: dua pintu, dua kunci.
        $kemampuan->organization_id = $user->organization_id;
        $kemampuan->equipment_category_id = $kategori->id;
        $kemampuan->dibuat_oleh_user_id = $user->id;
        $kemampuan->sumber = $user->isAdmin()
            ? CalibrationCapability::SUMBER_ADMIN
            : CalibrationCapability::SUMBER_TEKNISI;

        $kemampuan->save();

        return response()->json([
            'data' => $this->bentuk($kemampuan->fresh(), $kategori),
            // Diulang di tingkat atas, bukan cuma di dalam `data`. Klien yang
            // cuma nampilin `message`/`peringatan` sesudah simpan sukses (dan
            // itu pola paling umum di layar mobile) tetap kebagian — kalau cuma
            // nangkring di `data.alasan_tanpa_cmc`, dia nggak akan pernah
            // kelihatan sampai ada yang sengaja bikin UI-nya.
            'peringatan' => $kemampuan->alasanTanpaCmc(),
        ], 201);
    }

    /**
     * Bentuk satu baris kemampuan buat mobile.
     *
     * Kolom CMC-nya tetap dikirim walaupun isinya NULL semua: layar teknisi
     * butuh bisa nunjukin "kolom ini kosong" apa adanya, bukan nebak dari
     * kunci yang nggak ada.
     *
     * @return array<string, mixed>
     */
    private function bentuk(CalibrationCapability $kemampuan, EquipmentCategory $kategori): array
    {
        return [
            'id' => $kemampuan->id,
            'kategori_kode' => $kategori->kode,
            'kategori_nama' => $kategori->nama,
            'nama_alat' => $kemampuan->nama_alat,
            'parameter' => $kemampuan->parameter,
            'range_min' => $kemampuan->range_min,
            'range_max' => $kemampuan->range_max,
            'range_note' => $kemampuan->range_note,
            'satuan' => $kemampuan->satuan,
            'ketidakpastian_terbaik' => $kemampuan->ketidakpastian_terbaik,
            'satuan_ketidakpastian' => $kemampuan->satuan_ketidakpastian,
            'faktor_cakupan' => $kemampuan->faktor_cakupan,
            'metode' => $kemampuan->metode,
            'keterangan' => $kemampuan->keterangan,
            'sumber' => $kemampuan->sumber,
            'dibuat_oleh' => $kemampuan->pembuat?->name,
            'tanpa_cmc' => $kemampuan->tanpaCmc(),
            'alasan_tanpa_cmc' => $kemampuan->alasanTanpaCmc(),
        ];
    }
}
