<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FormulaVersionResource;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Services\RumusKalibrasi;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rumus kalibrasi berversi (arsitektur-desktop-database.md Keputusan 5).
 *
 * **Admin doang.** Salah ngetik di sini ngubah angka yang masuk sertifikat
 * terakreditasi — ini menu paling berbahaya di seluruh aplikasi.
 *
 * ## Arti `status`, dan kenapa beda dari bacaan naifnya
 *
 * Keputusan 5 nulis "versi lama diarsipkan". Kalau itu dibaca sebagai "statusnya
 * jadi `arsip`", fiturnya justru rusak: pencarian versi per-tanggal cuma ngambil
 * yang `aktif`, jadi sesi tahun lalu nggak nemu versinya lagi — padahal itu tepat
 * yang mau dicegah Keputusan 5.
 *
 * Jadi yang nentuin versi mana berlaku buat suatu tanggal itu **rentang
 * tanggalnya**, bukan statusnya:
 *
 * - `aktif` — bagian dari garis waktu yang sah. Versi yang udah digantikan TETAP
 *   `aktif`, cuma `berlaku_sampai`-nya ditutup. Dia masih jawaban yang BENAR buat
 *   tanggal-tanggal di rentangnya.
 * - `draft` — belum masuk garis waktu. Boleh ada beberapa sekaligus; admin bisa
 *   nyiapin calon sebelum milih.
 * - `arsip` — **dibatalkan**, jangan dipakai buat tanggal apa pun. Ini buat versi
 *   yang kebikin karena salah, bukan buat versi yang digantikan secara wajar.
 */
class FormulaController extends Controller
{
    public function __construct(private readonly RumusKalibrasi $rumus) {}

    /** Daftar rumus + versi yang berlaku sekarang. */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        // Dipanggil biar organisasi yang belum punya rumus langsung kelihatan
        // versi 1-nya, bukan daftar kosong yang bikin admin mikir fiturnya rusak.
        $this->rumus->formulaGumPh((int) $organizationId);

        $formula = Formula::where('organization_id', $organizationId)
            ->with(['versions' => fn ($q) => $q->orderByDesc('nomor_versi')])
            ->orderBy('kode')
            ->get();

        return response()->json([
            'data' => $formula->map(fn (Formula $f): array => [
                'id' => $f->id,
                'kode' => $f->kode,
                'nama' => $f->nama,
                'besaran' => $f->besaran,
                'deskripsi' => $f->deskripsi,
                'jumlah_versi' => $f->versions->count(),
                'versi_berlaku' => ($aktif = $f->versiAktif())
                    ? new FormulaVersionResource($aktif)
                    : null,
            ])->all(),
        ]);
    }

    /** Riwayat versi satu rumus — terbaru dulu. */
    public function versions(Request $request, Formula $formula): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $formula);

        $versi = $formula->versions()
            ->with('pembuat')
            ->orderByDesc('nomor_versi')
            ->get();

        return response()->json(['data' => FormulaVersionResource::collection($versi)]);
    }

    /**
     * Versi yang berlaku pada TANGGAL tertentu.
     *
     * Ini pertanyaan yang bikin fitur ini ada: "sesi 26 Mei dihitung pakai aturan
     * yang mana?" — bukan "aturan apa yang dipakai sekarang".
     */
    public function versiPadaTanggal(Request $request, Formula $formula): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $formula);

        $data = $request->validate([
            'tanggal' => ['sometimes', 'nullable', 'date'],
        ]);

        $tanggal = filled($data['tanggal'] ?? null) ? Carbon::parse($data['tanggal']) : now();
        $versi = $formula->versiPadaTanggal($tanggal);

        return response()->json([
            'data' => $versi ? new FormulaVersionResource($versi->load('pembuat')) : null,
            'penyaring' => ['tanggal' => $tanggal->toDateString()],
        ]);
    }

    /**
     * Terbitin versi BARU: bikin versinya, dan tutup rentang versi sebelumnya.
     *
     * Dua langkah itu satu operasi, bukan dua endpoint. Kalau dipisah, ada jeda di
     * mana dua versi sama-sama berlaku buat satu tanggal — dan di jeda itu
     * pertanyaan "sesi ini pakai versi mana" nggak punya jawaban. Dibungkus
     * transaksi karena separuh jadi lebih buruk daripada nggak jadi.
     */
    public function storeVersion(Request $request, Formula $formula): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $formula);

        $data = $request->validate([
            'sumber' => ['required', Rule::in(FormulaVersion::sumbers())],
            'berlaku_dari' => ['required', 'date'],
            'parameter' => ['sometimes', 'nullable', 'array'],
            'ekspresi' => ['sometimes', 'nullable', 'array'],
            'catatan' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Bikin sebagai draft dulu = boleh, dan disarankan: Keputusan 5 minta
            // "uji coba sebelum disimpan", dan draft yang belum masuk garis waktu
            // itu tempat paling aman buat nyoba.
            'langsung_aktif' => ['sometimes', 'boolean'],
        ], [
            'sumber.in' => 'Sumber cuma bisa: '.implode(', ', FormulaVersion::sumbers()).'.',
        ]);

        // `sumber: database` butuh evaluator ekspresi yang BELUM ADA. Ditolak
        // sekarang, bukan diterima lalu diam-diam nggak ngaruh: versi yang tercatat
        // "dihitung dari database" padahal angkanya tetap dari kode itu riwayat
        // yang bohong, dan itu lebih berbahaya daripada fiturnya belum ada.
        if ($data['sumber'] === FormulaVersion::SUMBER_DATABASE) {
            return response()->json([
                'message' => 'Rumus dari database belum bisa dipakai — evaluator ekspresinya belum ada. '
                    .'Sementara cuma `sumber: "kode"` yang sah, dan itu nyatet parameter buat rumus '
                    .'yang dihitung program. Kalau ini diizinin sekarang, riwayatnya bakal nulis '
                    .'"dihitung dari database" padahal angkanya tetap dari kode.',
                'errors' => ['sumber' => ['Belum didukung.']],
            ], 422);
        }

        $langsungAktif = (bool) ($data['langsung_aktif'] ?? false);
        $mulai = Carbon::parse($data['berlaku_dari'])->startOfDay();

        // Lapis kedua di belakang `lockForUpdate()` di `nomorBerikutnya()`.
        //
        // Locknya yang menyerialkan penomoran; try/catch ini yang memastikan
        // sisa kemungkinan apa pun — beda isolation level, transaksi yang
        // dimulai dari jalur lain — keluar sebagai jawaban yang bisa
        // dimengerti, bukan 500 generik. Admin yang kalah balapan cuma perlu
        // tahu satu hal: simpan ulang.
        try {
            $versi = DB::transaction(function () use ($formula, $data, $mulai, $langsungAktif, $request): FormulaVersion {
                $sebelumnya = $langsungAktif
                    ? $formula->versions()
                        ->where('status', FormulaVersion::STATUS_AKTIF)
                        ->whereNull('effective_until')
                        ->orderByDesc('effective_from')
                        ->lockForUpdate()
                        ->first()
                    : null;

                // Tutup rentang versi sebelumnya SEHARI SEBELUM versi baru mulai —
                // bukan di hari yang sama. Kalau di hari yang sama, tanggal itu punya
                // dua versi aktif.
                if ($sebelumnya !== null) {
                    $sebelumnya->update([
                        'effective_until' => $mulai->copy()->subDay()->toDateString(),
                    ]);
                }

                return $formula->versions()->create([
                    'organization_id' => $formula->organization_id,
                    'nomor_versi' => FormulaVersion::nomorBerikutnya($formula->id),
                    'sumber' => $data['sumber'],
                    'parameter' => $data['parameter'] ?? null,
                    'ekspresi' => $data['ekspresi'] ?? null,
                    'status' => $langsungAktif ? FormulaVersion::STATUS_AKTIF : FormulaVersion::STATUS_DRAFT,
                    'effective_from' => $mulai->toDateString(),
                    'effective_until' => null,
                    'catatan' => $data['catatan'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Yang dijaga di sini CUMA tabrakan nomor versi; pelanggaran lain
            // diteruskan apa adanya supaya nggak ada kegagalan yang tersamar
            // jadi pesan yang salah — pesan yang salah lebih berbahaya
            // daripada pesan generik, karena dia nyuruh admin ngulang sesuatu
            // yang nggak akan pernah berhasil.
            //
            // Dua ejaan, karena dua mesin menyebut pelanggaran yang SAMA
            // dengan cara yang beda:
            //   MySQL  : Duplicate entry '1-1' for key 'fversi_formula_nomor_unik'
            //   SQLite : UNIQUE constraint failed: formula_versions.formula_id,
            //            formula_versions.nomor_versi
            // Produksi MySQL, CI SQLite. Cuma memeriksa nama index-nya bikin
            // penjagaan ini kelihatan jalan padahal cuma jalan di separuh
            // tempat — dan separuh yang nggak jalan justru yang diuji CI.
            // Yang dibaca pesan PDO-nya, BUKAN `$e->getMessage()`.
            //
            // `QueryException::getMessage()` menempelkan SQL-nya, dan SQL insert
            // itu memuat daftar kolom — termasuk `nomor_versi`. Jadi memeriksa
            // pesan lengkapnya bikin kegagalan APA PUN di insert ini kebaca
            // sebagai tabrakan nomor, dan admin disuruh mengulang sesuatu yang
            // nggak akan pernah berhasil. Pesan PDO cuma memuat constraint yang
            // beneran dilanggar.
            $rinci = $e->getPrevious()?->getMessage() ?? '';

            $tabrakanNomor = str_contains($rinci, 'fversi_formula_nomor_unik')
                || str_contains($rinci, 'nomor_versi');

            if (! $tabrakanNomor) {
                throw $e;
            }

            return response()->json([
                'message' => 'Ada versi lain yang tersimpan barengan buat rumus ini. '
                    .'Nomor versinya bentrok — coba simpan lagi, nomornya bakal naik sendiri.',
            ], 409);
        }

        // Diperiksa SESUDAH dibikin, di dalam pemeriksaan yang sama dengan
        // `bentrokRentang()` — jadi aturannya cuma ditulis sekali dan nggak bisa
        // beda antara validasi & pengecekan.
        if ($langsungAktif && ($pesan = $versi->pesanBentrok()) !== null) {
            // Nggak jadi. `forceDelete` supaya nggak ninggalin versi hantu yang
            // makan nomor.
            $versi->forceDelete();

            return response()->json(['message' => $pesan], 422);
        }

        return response()->json([
            'data' => new FormulaVersionResource($versi->fresh()->load('pembuat')),
        ], 201);
    }

    /**
     * Ubah versi: aktifin draft, batalin (arsip), atau ubah catatan.
     *
     * Rentang tanggalnya SENGAJA nggak bisa diubah di sini. Ngubah rentang versi
     * yang udah dipakai berarti ngubah jawaban dari "sesi ini dihitung pakai versi
     * apa" — secara retroaktif, buat sesi yang udah terbit sertifikatnya. Kalau
     * aturannya salah, jalannya terbitin versi baru, bukan ubah yang lama.
     */
    public function updateVersion(Request $request, FormulaVersion $formulaVersion): JsonResponse
    {
        abort_if(
            $formulaVersion->organization_id !== $request->user()->organization_id,
            404,
        );

        $data = $request->validate([
            'status' => ['sometimes', Rule::in([FormulaVersion::STATUS_AKTIF, FormulaVersion::STATUS_ARSIP])],
            'catatan' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], [
            'status.in' => 'Status cuma bisa diubah jadi `aktif` atau `arsip`. '
                .'Balik ke `draft` nggak masuk akal buat versi yang udah pernah berlaku.',
        ]);

        // Versi yang udah dipakai NGGAK boleh diarsipin. Kalau diarsipin, hasil
        // hitung yang nunjuk ke dia jadi nggak bisa dijelasin lagi — dan itu
        // kebalikan dari gunanya fitur ini.
        if (($data['status'] ?? null) === FormulaVersion::STATUS_ARSIP
            && $formulaVersion->jumlahHasilHitung() > 0) {
            return response()->json([
                'message' => 'Versi ini udah kepakai di hasil hitung yang tersimpan, jadi nggak bisa '
                    .'diarsipin — kalau diarsipin, angka yang udah terbit nggak bisa dijelasin lagi. '
                    .'Kalau aturannya salah, terbitin versi baru.',
            ], 422);
        }

        if (($data['status'] ?? null) === FormulaVersion::STATUS_AKTIF) {
            $formulaVersion->status = FormulaVersion::STATUS_AKTIF;

            if (($pesan = $formulaVersion->pesanBentrok()) !== null) {
                return response()->json(['message' => $pesan], 422);
            }
        }

        $formulaVersion->update($data);

        return response()->json([
            'data' => new FormulaVersionResource($formulaVersion->fresh()->load('pembuat')),
        ]);
    }

    /** Jaring pengaman multi-tenant. */
    private function pastikanSatuOrganisasi(Request $request, Formula $formula): void
    {
        abort_if($formula->organization_id !== $request->user()->organization_id, 404);
    }
}
