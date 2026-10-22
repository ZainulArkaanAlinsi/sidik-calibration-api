<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaketAcuanResource;
use App\Http\Resources\PaketAcuanVersiResource;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanSuntingan;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Acuan\SelAcuan;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\PenjagaOrganisasi;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Studio Data Acuan — BACA saja (CR-48 P1, 02-SRS FR-01/03/04/11/28, 03-SDD §3).
 *
 * Izinnya satu, `data-acuan.lihat`: semua role lab lewat grup rute luar, super
 * admin lewat jalan baca `lolosBacaSuperAdmin`. Sisanya ditegakkan DI SINI,
 * bukan dengan menyembunyikan tombol di aplikasi (02-SRS §4):
 *
 *  - **Teknisi cuma melihat versi `aktif`.** Versi lain di `versi/{versi}` →
 *    404 (bukan 403: 403 mengakui drafnya ada); daftar & detail paket tanpa
 *    hitungan draf dan versi terjadwal; banding & riwayat sel → 404.
 *  - **Organisasi lain → 404.** Paket dicari per `organization_id` pemanggil
 *    + kode profil; versi dari id rute lewat `PenjagaOrganisasi::pastikanSatu`.
 *
 * Tidak ada yang menulis di sini — angka dari isi versi dikirim apa adanya,
 * tanpa pembulatan (BR-05).
 */
class DataAcuanController extends Controller
{
    /** `berlaku` tanpa versi yang boleh dilihat teknisi — tanpa status & tanggal. */
    public const ALASAN_TEKNISI_TANPA_VERSI_AKTIF = 'Tidak ada versi aktif yang berlaku pada tanggal itu.';

    public function __construct(
        private readonly CalibrationProfileRegistry $registry,
        private readonly PenentuVersiAcuan $penentu,
        private readonly SelAcuan $sel,
    ) {}

    /**
     * FR-01 — paket milik lab pemanggil, ditambah profil ber-`skemaAcuan()`
     * yang belum pernah diimpor (`diimpor: false`).
     */
    public function daftarPaket(Request $request): JsonResponse
    {
        $organisasi = $this->organisasi($request);

        $data = PaketAcuan::query()
            ->where('organization_id', $organisasi)
            ->orderBy('kode_profil')
            ->get()
            ->map(fn (PaketAcuan $paket): PaketAcuanResource => new PaketAcuanResource(
                $paket,
                $this->ringkasan($request, $paket),
            ))
            ->all();

        // Paket yang sudah dihapus (soft delete) TIDAK ditampilkan sebagai
        // "belum diimpor": `acuan:impor-awal` menolak mengimpornya ulang, jadi
        // label itu menjanjikan langkah yang tidak bisa dijalankan.
        $pernahAda = PaketAcuan::withTrashed()
            ->where('organization_id', $organisasi)
            ->pluck('kode_profil')
            ->all();

        // Satu baris per PAKET (profil sepaket berbagi satu tabel acuan).
        foreach ($this->registry->paketAcuan() as $kodePaket => $profil) {
            $skema = $profil->skemaAcuan();

            if (in_array($kodePaket, $pernahAda, true)) {
                continue;
            }

            $data[] = new PaketAcuanResource(
                new PaketAcuan([
                    'kode_profil' => $kodePaket,
                    'nama' => $profil->namaPaketAcuan(),
                    'skema_versi' => $skema['skema_versi'],
                ]),
                $this->bolehLihatSemua($request)
                    ? ['versi_aktif' => null, 'versi_terjadwal' => [], 'jumlah_draf' => 0]
                    : ['versi_aktif' => null],
            );
        }

        return $this->json(['data' => $data]);
    }

    /** Paket + skema lembar profil + versi aktif/terjadwal + ringkasan draf. */
    public function paket(Request $request, string $kode): JsonResponse
    {
        $paket = $this->cariPaket($request, $kode);

        $ringkasan = $this->ringkasan($request, $paket, detail: true);
        $ringkasan['skema'] = $this->registry->untukPaketAcuan($paket->kode_profil)?->skemaAcuan();

        return $this->json(['data' => new PaketAcuanResource($paket, $ringkasan)]);
    }

    /**
     * FR-04 — versi yang berlaku pada `tanggal` (aturan `PenentuVersiAcuan::
     * untukProfil`: aktif/pensiun, rentang inklusif, tanpa fallback ke versi
     * aktif hari ini). Tidak ada → `data: null` + `alasan`. Teknisi: versi
     * non-aktif dijawab sama dengan "tidak ada", alasannya generik.
     */
    public function berlaku(Request $request, string $kode): JsonResponse
    {
        // Paket dulu, baru validasi: kode lab lain dijawab 404 apa pun query-nya.
        $paket = $this->cariPaket($request, $kode);

        $data = $request->validate([
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'jenis' => ['sometimes', 'string', Rule::in(PaketAcuanVersi::jenisSemua())],
        ], [
            'tanggal.required' => 'Tanggal wajib diisi (YYYY-MM-DD).',
            'tanggal.date_format' => 'Tanggal harus berformat YYYY-MM-DD, mis. 2026-10-08.',
            'jenis.in' => 'Jenis cuma boleh `data` atau `bentuk`.',
        ]);

        $jenis = $data['jenis'] ?? PaketAcuanVersi::JENIS_DATA;
        $tanggal = CarbonImmutable::createFromFormat('!Y-m-d', $data['tanggal']);

        $versi = $this->penentu->untukProfil($this->organisasi($request), $paket->kode_profil, $tanggal, $jenis);

        if ($versi === null || ! $this->bolehLihatVersi($request, $versi)) {
            return $this->json([
                'data' => null,
                // Teknisi cuma melihat versi aktif (02-SRS §4), jadi alasannya
                // juga tidak boleh membocorkan versi lain: status (`pensiun`)
                // atau tanggal berlaku versi non-aktif tetap informasi versi
                // itu. Kalimatnya sengaja SAMA untuk semua sebab — "sebelum
                // versi pertama", "jatuh di versi pensiun", dan "belum ada
                // versi" tidak boleh bisa dibedakan dari luar (tinjau P1 S-3).
                'alasan' => $this->bolehLihatSemua($request)
                    ? $this->alasanTidakBerlaku($request, $paket, $jenis, $data['tanggal'])
                    : self::ALASAN_TEKNISI_TANPA_VERSI_AKTIF,
            ]);
        }

        return $this->json(['data' => new PaketAcuanVersiResource($versi), 'alasan' => null]);
    }

    /** FR-28 — garis waktu versi paket, urut jenis lalu nomor. Teknisi: aktif saja. */
    public function garisWaktu(Request $request, string $kode): JsonResponse
    {
        $paket = $this->cariPaket($request, $kode);

        $versi = $paket->versi()
            ->where('organization_id', $this->organisasi($request))
            ->when(! $this->bolehLihatSemua($request), fn ($q) => $q->where('status', PaketAcuanVersi::STATUS_AKTIF))
            ->orderBy('jenis')
            ->orderBy('nomor_versi')
            ->get()
            ->map(fn (PaketAcuanVersi $v): PaketAcuanVersiResource => new PaketAcuanVersiResource(
                $v->setRelation('paketAcuan', $paket),
            ))
            ->all();

        return $this->json(['data' => $versi]);
    }

    /** Metadata + isi terurai + `isi_sha256`. Teknisi: versi non-aktif → 404. */
    public function versi(Request $request, PaketAcuanVersi $versi): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $versi);
        abort_unless($this->bolehLihatVersi($request, $versi), 404);

        $this->muatPaket($versi);

        return $this->json(['data' => (new PaketAcuanVersiResource($versi))->denganIsi()]);
    }

    /**
     * FR-11 — banding `{versi}` (lama) → `{pembanding}` (baru) per lembar
     * skema. Bukan untuk teknisi (404).
     */
    public function banding(Request $request, PaketAcuanVersi $versi, PaketAcuanVersi $pembanding): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $versi);
        PenjagaOrganisasi::pastikanSatu($request, $pembanding);
        abort_unless($this->bolehLihatSemua($request), 404);

        if ((int) $versi->paket_acuan_id !== (int) $pembanding->paket_acuan_id) {
            return $this->tolak('versi_beda_paket', 'Dua versi yang dibandingkan harus dari paket yang sama.');
        }

        if ($versi->jenis !== $pembanding->jenis) {
            return $this->tolak('versi_beda_jenis', 'Versi data tidak bisa dibandingkan dengan versi bentuk lembar.');
        }

        if ($versi->jenis !== PaketAcuanVersi::JENIS_DATA) {
            return $this->tolak('jenis_belum_didukung', 'Banding versi bentuk lembar belum tersedia.');
        }

        $paket = $this->muatPaket($versi);
        $pembanding->setRelation('paketAcuan', $paket);
        $skema = $this->registry->untukPaketAcuan((string) $paket?->kode_profil)?->skemaAcuan();

        if ($skema === null) {
            return $this->tolak('skema_tidak_ada', 'Profil paket ini tidak punya skema data acuan di kode.');
        }

        return $this->json(['data' => [
            'paket' => $paket?->kode_profil,
            'skema_versi' => $skema['skema_versi'],
            'lama' => PaketAcuanVersiResource::ringkas($versi),
            'baru' => PaketAcuanVersiResource::ringkas($pembanding),
            ...$this->sel->banding($skema, $versi->isiTerurai(), $pembanding->isiTerurai()),
        ]]);
    }

    /**
     * FR-03 / FR-28 — nilai satu sel di tiap versi paket (urut nomor) +
     * suntingan sel itu. Lembar/kolom di luar skema → 404. Bukan untuk
     * teknisi (404).
     */
    public function riwayatSel(
        Request $request,
        PaketAcuanVersi $versi,
        string $lembar,
        string $kunci,
        string $kolom,
    ): JsonResponse {
        PenjagaOrganisasi::pastikanSatu($request, $versi);
        abort_unless($this->bolehLihatSemua($request), 404);
        abort_unless($versi->jenis === PaketAcuanVersi::JENIS_DATA, 404);

        $paket = $this->muatPaket($versi);
        $skema = $this->registry->untukPaketAcuan((string) $paket?->kode_profil)?->skemaAcuan();

        // Dicocokkan STRICT: `firstWhere` membanding longgar (`==`).
        $defLembar = collect($skema['lembar'] ?? [])->first(fn (array $l): bool => ($l['kode'] ?? null) === $lembar);
        abort_if($defLembar === null, 404, 'Lembar tidak ada di skema paket ini.');

        $defKolom = collect($defLembar['kolom'] ?? [])->first(fn (array $k): bool => ($k['kode'] ?? null) === $kolom);
        abort_if($defKolom === null, 404, 'Kolom tidak ada di lembar ini.');

        // Versi paket yang SAMA, organisasi diadu lagi (pola PenentuVersiAcuan).
        $semua = PaketAcuanVersi::query()
            ->where('paket_acuan_id', $versi->paket_acuan_id)
            ->where('organization_id', $versi->organization_id)
            ->where('jenis', $versi->jenis)
            ->orderBy('nomor_versi')
            ->get();

        $riwayat = $semua->map(fn (PaketAcuanVersi $v): array => [
            'versi' => PaketAcuanVersiResource::ringkas($v),
            ...$this->sel->nilai($defLembar, $v->isiTerurai(), $kunci, $kolom),
        ])->all();

        // Anak tanpa organization_id — diraih lewat versi yang sudah tersaring.
        $suntingan = PaketAcuanSuntingan::query()
            ->whereIn('paket_acuan_versi_id', $semua->modelKeys())
            ->where('lembar', $lembar)
            ->where('kunci_baris', $kunci)
            ->where('kolom', $kolom)
            ->with('user:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (PaketAcuanSuntingan $s): array => [
                'id' => $s->id,
                'paket_acuan_versi_id' => $s->paket_acuan_versi_id,
                'nomor_versi' => $semua->firstWhere('id', $s->paket_acuan_versi_id)?->nomor_versi,
                'nilai_lama' => $s->nilai_lama,
                'nilai_baru' => $s->nilai_baru,
                'sumber' => $s->sumber,
                'alasan' => $s->alasan,
                'user' => $s->user === null ? null : ['id' => $s->user->id, 'nama' => $s->user->name],
                'created_at' => $s->created_at?->toIso8601String(),
            ])
            ->all();

        return $this->json(['data' => [
            'paket' => $paket?->kode_profil,
            'versi' => PaketAcuanVersiResource::ringkas($versi),
            'lembar' => $lembar,
            'kunci' => $kunci,
            'kolom' => $defKolom,
            ...$this->sel->nilai($defLembar, $versi->isiTerurai(), $kunci, $kolom),
            'riwayat' => $riwayat,
            'suntingan' => $suntingan,
        ]]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function organisasi(Request $request): int
    {
        return (int) $request->user()->organization_id;
    }

    /** Admin, viewer, super admin: semua status. Teknisi: aktif saja (02-SRS §4). */
    private function bolehLihatSemua(Request $request): bool
    {
        return $request->user()->role !== User::ROLE_TEKNISI;
    }

    private function bolehLihatVersi(Request $request, PaketAcuanVersi $versi): bool
    {
        return $this->bolehLihatSemua($request) || $versi->status === PaketAcuanVersi::STATUS_AKTIF;
    }

    /** Paket lab pemanggil berkode profil ini, atau 404. */
    private function cariPaket(Request $request, string $kode): PaketAcuan
    {
        $paket = PaketAcuan::query()
            ->where('organization_id', $this->organisasi($request))
            ->where('kode_profil', $kode)
            ->first();

        abort_if($paket === null, 404, 'Paket data acuan tidak ditemukan.');

        return $paket;
    }

    /**
     * Paket pemilik versi — termasuk yang sudah soft delete: versi yang pernah
     * menstempel hasil tetap harus bisa dibaca (pola `SumberAcuan`).
     */
    private function muatPaket(PaketAcuanVersi $versi): ?PaketAcuan
    {
        $versi->load(['paketAcuan' => fn ($q) => $q->withTrashed()]);

        return $versi->paketAcuan;
    }

    /**
     * Ringkasan versi satu paket. Untuk teknisi hanya `versi_aktif` (dan itu
     * pun cuma kalau statusnya `aktif`) — kunci lain tidak disiapkan, jadi
     * tidak ikut terkirim.
     *
     * @return array<string, mixed>
     */
    private function ringkasan(Request $request, PaketAcuan $paket, bool $detail = false): array
    {
        $aktif = $this->penentu->untukProfil($this->organisasi($request), $paket->kode_profil, now());

        if (! $this->bolehLihatSemua($request)) {
            return ['versi_aktif' => $aktif !== null && $this->bolehLihatVersi($request, $aktif) ? $aktif : null];
        }

        $versiData = fn () => $paket->versi()
            ->where('organization_id', $this->organisasi($request))
            ->where('jenis', PaketAcuanVersi::JENIS_DATA);

        $ringkasan = [
            'versi_aktif' => $aktif,
            'versi_terjadwal' => $versiData()
                ->where('status', PaketAcuanVersi::STATUS_TERJADWAL)
                ->orderBy('berlaku_mulai')
                ->orderBy('nomor_versi')
                ->get()
                ->all(),
            'jumlah_draf' => $versiData()->where('status', PaketAcuanVersi::STATUS_DRAF)->count(),
        ];

        if ($detail) {
            $ringkasan['jumlah_diajukan'] = $versiData()->where('status', PaketAcuanVersi::STATUS_DIAJUKAN)->count();
        }

        return $ringkasan;
    }

    private function alasanTidakBerlaku(Request $request, PaketAcuan $paket, string $jenis, string $tanggal): string
    {
        $pertama = $paket->versi()
            ->where('organization_id', $this->organisasi($request))
            ->where('jenis', $jenis)
            ->whereIn('status', [PaketAcuanVersi::STATUS_AKTIF, PaketAcuanVersi::STATUS_PENSIUN])
            ->min('berlaku_mulai');

        if ($pertama === null) {
            return "Paket {$paket->kode_profil} belum punya versi {$jenis} berstatus aktif atau pensiun.";
        }

        $pertama = substr((string) $pertama, 0, 10);

        return $tanggal < $pertama
            ? "Tanggal {$tanggal} sebelum versi {$jenis} pertama berlaku ({$pertama})."
            : "Tidak ada versi {$jenis} aktif/pensiun yang rentang berlakunya memuat {$tanggal}.";
    }

    private function tolak(string $kode, string $pesan): JsonResponse
    {
        return $this->json(['message' => $pesan, 'kode' => $kode], 422);
    }

    /**
     * Respons JSON yang MEMPERTAHANKAN tipe angka: `20.0` tetap `20.0`, bukan
     * `20`. Isi kanonik membedakan int dan float (`JsonKanonik`, tipe itu
     * sampai ke calculator), dan tanpa `JSON_PRESERVE_ZERO_FRACTION`
     * `json_encode` menghapus pecahan nol — klien tidak bisa lagi
     * mengkanonikkan ulang isi yang diterimanya menjadi `isi_sha256` yang sama.
     *
     * @param  array<string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
