<?php

namespace App\Http\Controllers\Api\Studio;

use App\Http\Controllers\Controller;
use App\Http\Resources\Studio\WorkbookMasterResource;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\PenjagaOrganisasi;
use App\Services\Studio\Workbook\ImporWorkbook;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Sidik Studio — "Workbook Alat", mode Asli Excel, BACA-SAJA (S1b,
 * `docs/permintaan-user-7.md` §49 K-49-08..11, `docs/kontrak-api.md`
 * §Studio Workbook).
 *
 * Izin `workbook.lihat`: admin, teknisi, viewer lewat grup rute; super admin
 * lewat jalan baca `lolosBacaSuperAdmin` (lab sendiri saja — API belum lintas
 * organisasi, AGENTS.md §Keadaan nyata super_admin). Pelanggan ditolak gerbang
 * `aplikasi:internal` + `role:`.
 *
 *  - Workbook lab lain → 404 (`PenjagaOrganisasi`, tercatat di lab pemanggil).
 *  - Lembar yang bukan milik workbook di URL → 404.
 *  - K-49-12: isi DATABASE / SERTIFIKAT dikirim apa adanya (bertanda
 *    `tautan_tabel`), terkurung organisasi oleh dua aturan di atas. Lembar
 *    lama ber-`dikecualikan` (format 1-2) tetap `sel: []`.
 *
 * Tahap C: `gambar()` menyajikan byte gambar lembar (PNG/JPEG). Tetap tidak
 * ada endpoint tulis maupun unduh berkas asli. Angka sel
 * dikirim apa adanya dari ekstraksi — tidak dibulatkan, tidak dihitung ulang.
 *
 * K-49-13 (15 Okt 2026): daftar hanya memuat master AKTIF (satu per jenis);
 * versi lama (riwayat) dibuka lewat `riwayat()` dan tetap bisa dibaca/dihitung
 * lewat id-nya. Workbook yang diarsipkan (dikeluarkan dari Studio) → 404 di
 * semua rute Studio (`pastikanDiStudio`).
 */
class WorkbookController extends Controller
{
    /** Batas baris per halaman sel. */
    public const MAKS_BARIS_HALAMAN = 500;

    /** Panjang halaman bila `baris_sampai` tidak dikirim: `baris_dari .. baris_dari + 199`. */
    public const BARIS_HALAMAN_BAWAAN = 200;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kode_profil' => ['sometimes', 'nullable', 'string', 'max:64'],
            'cari' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], [
            'kode_profil.max' => 'Kode profil maksimal 64 karakter.',
            'cari.max' => 'Kata cari maksimal 100 karakter.',
        ]);

        // K-49-13: hanya master aktif; riwayat lewat GET …/{workbook}/riwayat.
        $query = WorkbookMaster::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('aktif', true)
            ->whereNull('diarsipkan_pada');

        if (($data['kode_profil'] ?? null) !== null && $data['kode_profil'] !== '') {
            $query->where('kode_profil', $data['kode_profil']);
        }

        if (($data['cari'] ?? null) !== null && trim($data['cari']) !== '') {
            $query->where('nama', 'like', '%'.trim($data['cari']).'%');
        }

        // xlsm dulu (versi asli, tata letak lengkap), lalu nama. Baris csv
        // yang punya versi xlsm membawa `versi_xlsm_id` (tahap A).
        $workbook = $query
            ->orderByRaw('case when sumber = ? then 0 else 1 end', [WorkbookMaster::SUMBER_XLSM])
            ->orderBy('nama')
            ->orderBy('id')
            ->get();

        return $this->json([
            'data' => WorkbookMasterResource::daftar($workbook, (int) $request->user()->organization_id, $request),
        ]);
    }

    public function show(Request $request, WorkbookMaster $workbook): JsonResponse
    {
        self::pastikanDiStudio($request, $workbook);

        return $this->json(['data' => WorkbookMasterResource::detail($workbook, $request)]);
    }

    /**
     * Semua versi di jenis yang sama dengan `$workbook` (master aktif + riwayat,
     * tanpa arsip): master aktif dulu, lalu riwayat dengan versi terbesar dulu.
     * Baca saja; `$workbook` boleh versi mana pun di jenis itu.
     */
    public function riwayat(Request $request, WorkbookMaster $workbook): JsonResponse
    {
        self::pastikanDiStudio($request, $workbook);

        // Tinjau C-3 #3: master AKTIF dulu, lalu versi terbesar. `versi` = urutan
        // impor dan tidak pernah dinomori ulang — admin bisa mengaktifkan versi
        // lama, jadi "terbesar dulu" saja menaruh yang aktif di tengah daftar.
        // `aktif` bernilai true|NULL (lihat model), jadi NULL = riwayat.
        $versi = WorkbookMaster::query()
            ->where('organization_id', $workbook->organization_id)
            ->where('kunci_jenis', $workbook->kunci_jenis)
            ->whereNull('diarsipkan_pada')
            ->orderByRaw('case when aktif is null then 1 else 0 end')
            ->orderByDesc('versi')
            ->orderByDesc('id')
            ->get();

        return $this->json([
            'data' => WorkbookMasterResource::daftar($versi, (int) $workbook->organization_id, $request),
            'kunci_jenis' => $workbook->kunci_jenis,
        ]);
    }

    /**
     * Lab lain → 404 (`PenjagaOrganisasi`, tercatat); diarsipkan → 404 juga:
     * workbook itu sudah dikeluarkan dari Studio (K-49-13), barisnya tetap ada.
     */
    public static function pastikanDiStudio(Request $request, WorkbookMaster $workbook): void
    {
        PenjagaOrganisasi::pastikanSatu($request, $workbook);
        abort_if($workbook->diarsipkan_pada !== null, 404);
    }

    /**
     * Sel satu lembar dalam rentang baris `[baris_dari, baris_sampai]`
     * (inklusif, 1-basis). `baris_sampai` tidak dikirim → 200 baris mulai
     * `baris_dari` (halaman berikutnya cukup menggeser `baris_dari`). Rentang
     * > 500 baris dipangkas ke 500 dan `baris_sampai` di jawaban menyebut
     * batas yang benar-benar dipakai.
     */
    public function lembar(Request $request, WorkbookMaster $workbook, WorkbookMasterLembar $lembarWorkbook): JsonResponse
    {
        // Organisasi dulu, baru validasi: lembar lab lain dijawab 404 apa pun query-nya.
        self::pastikanDiStudio($request, $workbook);
        abort_if((int) $lembarWorkbook->workbook_master_id !== (int) $workbook->id, 404);

        $data = $request->validate([
            'baris_dari' => ['sometimes', 'integer', 'min:1'],
            'baris_sampai' => ['sometimes', 'integer', 'min:1'],
        ], [
            'baris_dari.integer' => 'baris_dari harus bilangan bulat ≥ 1.',
            'baris_dari.min' => 'baris_dari harus bilangan bulat ≥ 1.',
            'baris_sampai.integer' => 'baris_sampai harus bilangan bulat ≥ 1.',
            'baris_sampai.min' => 'baris_sampai harus bilangan bulat ≥ 1.',
        ]);

        $dari = (int) ($data['baris_dari'] ?? 1);
        $sampai = isset($data['baris_sampai'])
            ? (int) $data['baris_sampai']
            : $dari + self::BARIS_HALAMAN_BAWAAN - 1;

        if ($sampai < $dari) {
            return $this->json([
                'message' => 'baris_sampai tidak boleh lebih kecil dari baris_dari.',
                'errors' => ['baris_sampai' => ['baris_sampai tidak boleh lebih kecil dari baris_dari.']],
            ], 422);
        }

        $sampai = min($sampai, $dari + self::MAKS_BARIS_HALAMAN - 1);

        $sel = array_values(array_filter(
            $lembarWorkbook->daftarSel(),
            static fn (array $s): bool => $s['b'] >= $dari && $s['b'] <= $sampai,
        ));

        return $this->json(['data' => [
            'sel' => $sel,
            'baris_dari' => $dari,
            'baris_sampai' => $sampai,
            'jumlah_baris' => $lembarWorkbook->jumlah_baris,
        ]]);
    }

    /**
     * Byte satu gambar lembar (tahap C). Id = 32 hex sha256 gambar, dari
     * `tata_letak.gambar` lembar mana pun di workbook ini — id yang tidak
     * terdaftar di workbook ini → 404 (tidak bisa menebak gambar workbook
     * lain). PNG/JPEG dikirim apa adanya; jenis lain (EMF/WMF…) 415 + jenisnya.
     * Izin = baca workbook.
     */
    public function gambar(Request $request, WorkbookMaster $workbook, string $gambar): Response|JsonResponse
    {
        self::pastikanDiStudio($request, $workbook);
        abort_unless(preg_match('/^[0-9a-f]{32}$/', $gambar) === 1, 404);

        $entri = null;

        foreach ($workbook->lembar()->select(['id', 'tata_letak'])->orderBy('urutan')->get() as $l) {
            foreach ((array) (($l->tata_letak ?? [])['gambar'] ?? []) as $g) {
                if (is_array($g) && ($g['id'] ?? null) === $gambar) {
                    $entri = $g;
                    break 2;
                }
            }
        }

        abort_if($entri === null, 404);

        if (! in_array($entri['jenis'] ?? null, ['png', 'jpeg'], true)) {
            return $this->json([
                'message' => 'Jenis gambar ini tidak didukung Studio (hanya PNG/JPEG).',
                'kode' => 'jenis_gambar_tidak_didukung',
                'jenis' => (string) ($entri['ekstensi'] ?? $entri['jenis'] ?? 'tidak-dikenal'),
            ], 415);
        }

        $jalur = ImporWorkbook::jalurGambar((int) $workbook->organization_id, (string) $workbook->sha256, $gambar);
        $disk = Storage::disk((string) ($workbook->berkas_disk ?? ImporWorkbook::diskBerkas()));
        abort_unless($disk->exists($jalur), 404);

        try {
            $byte = Crypt::decryptString((string) $disk->get($jalur));
        } catch (DecryptException) {
            abort(404);
        }

        return response($byte, 200, [
            'Content-Type' => $entri['jenis'] === 'png' ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Angka dikirim dengan tipenya: `60.0` tetap `60.0` (float), `60` tetap
     * `60` — pola `DataAcuanController`. Tanpa flag ini sel angka bulat
     * kehilangan pecahannya di kabel dan klien tidak bisa membedakan `n`
     * float dari hitungan bulat.
     *
     * @param  array<string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
