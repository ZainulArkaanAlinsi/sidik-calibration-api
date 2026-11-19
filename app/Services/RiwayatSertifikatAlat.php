<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\User;
use App\Support\KursorId;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat sertifikat per ALAT untuk layar lab (kontrak:
 * `docs/perintah-frontend-super-admin.md` §3.4 dan §3.5).
 *
 * ## Satu bentuk, dua pintu
 *
 * Daftar alat satu pelanggan (`GET /customers/{c}/alat`) dan riwayat satu alat
 * (`GET /equipments/{e}/sertifikat`) menampilkan sertifikat yang SAMA. Kalau tiap
 * pintu menurunkan status & penggantinya sendiri, suatu hari satu layar bilang
 * "berlaku" untuk dokumen yang layar lain bilang "digantikan". Makanya
 * penurunannya di sini, dan label dokumennya dari `Certificate::labelDokumen()`
 * — fungsi yang sama dengan yang dipakai aplikasi pelanggan.
 *
 * ## Yang dijaga
 *
 * - **Organisasi** disaring di tiap query, juga yang anak (revisi & sertifikat
 *   asal): `organization_id` pemanggil, bukan milik baris yang sedang dibaca.
 * - **Tidak pernah memuat `snapshot`/`validasi`** — kolomnya dipilih eksplisit.
 *   Satu snapshot bisa puluhan KB; lima puluh baris per halaman × riwayat
 *   bertahun-tahun adalah beban memori yang tidak ada gunanya untuk layar
 *   daftar.
 * - **Tidak ada N+1**: berapa pun barisnya, jumlah query tetap (revisi, asal,
 *   dan agregat dikumpulkan per halaman).
 * - **Teknisi hanya sesinya sendiri**, aturan yang sama dengan `GET /certificates`.
 */
class RiwayatSertifikatAlat
{
    /** Kolom ringan; tanpa `snapshot`, `validasi`, `qr_payload`, `pdf_path`. */
    private const KOLOM = [
        'id', 'calibration_session_id', 'revision_of', 'revisi_ke', 'nomor', 'status',
        'diterbitkan_pada', 'berlaku_sampai', 'dibatalkan_pada', 'ditarik_pada',
    ];

    /**
     * Satu halaman riwayat sertifikat satu alat, terbaru dibuat dulu.
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null} [baris, kursor berikutnya]
     */
    public function halaman(Equipment $alat, User $pemanggil, ?int $setelahId, int $limit): array
    {
        $query = Certificate::query()
            ->select(self::KOLOM)
            ->where('organization_id', $pemanggil->organization_id)
            ->whereIn('calibration_session_id', fn ($sub) => $sub
                ->select('id')
                ->from('calibration_sessions')
                ->where('equipment_id', $alat->getKey())
                ->when(
                    $pemanggil->role === User::ROLE_TEKNISI,
                    fn ($q) => $q->where('teknisi_id', $pemanggil->getKey()),
                ));

        [$baris, $berikutnya] = KursorId::potong($query, $setelahId, $limit);

        return [array_values($this->bentukBanyak($baris, (int) $pemanggil->organization_id)), $berikutnya];
    }

    /**
     * Jumlah sertifikat dan sertifikat terbaru untuk banyak alat sekaligus.
     * Empat query tetap, berapa pun jumlah alatnya (satu halaman ≤ 50).
     *
     * @param  list<int>  $idAlat
     * @return array<int, array{jumlah: int, terbaru: array<string, mixed>|null}> kunci = id alat
     */
    public function ringkasanPerAlat(array $idAlat, int $organisasi): array
    {
        $hasil = [];

        foreach ($idAlat as $id) {
            $hasil[$id] = ['jumlah' => 0, 'terbaru' => null];
        }

        if ($idAlat === []) {
            return $hasil;
        }

        $agregat = DB::table('certificates')
            ->join('calibration_sessions', 'calibration_sessions.id', '=', 'certificates.calibration_session_id')
            ->where('certificates.organization_id', $organisasi)
            ->whereIn('calibration_sessions.equipment_id', $idAlat)
            ->groupBy('calibration_sessions.equipment_id')
            ->selectRaw('calibration_sessions.equipment_id as alat_id, count(certificates.id) as jumlah, max(certificates.id) as terbaru_id')
            ->get();

        $baris = $agregat->isEmpty()
            ? new Collection
            : Certificate::query()
                ->select(self::KOLOM)
                ->where('organization_id', $organisasi)
                ->whereIn('id', $agregat->pluck('terbaru_id')->all())
                ->get();

        // Ringkasan hanya memakai id/nomor/tanggal/status: `keputusan` tidak dibaca.
        $bentuk = $this->bentukBanyak($baris, $organisasi, denganKeputusan: false);

        foreach ($agregat as $satu) {
            $terbaru = $bentuk[(int) $satu->terbaru_id] ?? null;

            $hasil[(int) $satu->alat_id] = [
                'jumlah' => (int) $satu->jumlah,
                'terbaru' => $terbaru === null ? null : [
                    'id' => $terbaru['id'],
                    'nomor' => $terbaru['nomor'],
                    'diterbitkan_pada' => $terbaru['diterbitkan_pada'],
                    'status' => $terbaru['status'],
                    'status_dokumen' => $terbaru['status_dokumen'],
                ],
            ];
        }

        return $hasil;
    }

    /**
     * @param  iterable<Certificate>  $baris
     * @return array<int, array<string, mixed>> kunci = id sertifikat
     */
    private function bentukBanyak(iterable $baris, int $organisasi, bool $denganKeputusan = true): array
    {
        $baris = collect($baris);

        if ($baris->isEmpty()) {
            return [];
        }

        // Revisi atas baris-baris ini, urut id naik → `last()` = yang terbaru.
        $revisi = Certificate::query()
            ->select(['id', 'revision_of', 'nomor', 'status', 'diterbitkan_pada'])
            ->where('organization_id', $organisasi)
            ->whereIn('revision_of', $baris->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->groupBy('revision_of');

        $idAsal = $baris->pluck('revision_of')->filter()->unique()->values()->all();

        $asal = $idAsal === []
            ? collect()
            : Certificate::query()
                ->select(['id', 'nomor'])
                ->where('organization_id', $organisasi)
                ->whereIn('id', $idAsal)
                ->get()
                ->keyBy('id');

        // Keputusan PASS/FAIL = kolom sesi yang TERSIMPAN (bukan dihitung ulang dari
        // titik hasil) — sumber yang sama dengan detail sertifikat, halaman
        // verifikasi, dan PDF. Satu query per halaman, tanpa N+1.
        //
        // `withTrashed()`: sertifikat yang pernah terbit tetap milik sesinya walau
        // sesi itu kemudian dihapus lunak (jangkar yang sama dengan `halaman()` dan
        // `ringkasanPerAlat()`, yang menggabung `calibration_sessions` tanpa memfilter
        // `deleted_at`). Tanpa ini barisnya muncul dengan `keputusan` null -- lencana
        // PASS/FAIL hilang untuk sertifikat yang sah.
        //
        // Ringkasan per alat TIDAK memuat `keputusan`, jadi tidak membayar query ini.
        $keputusan = ! $denganKeputusan
            ? collect()
            : CalibrationSession::query()
                ->withTrashed()
                ->where('organization_id', $organisasi)
                ->whereIn('id', $baris->pluck('calibration_session_id')->unique()->values()->all())
                ->pluck('keputusan', 'id');

        return $baris
            ->mapWithKeys(fn (Certificate $c) => [
                $c->id => $this->bentuk($c, $revisi->get($c->id, collect()), $asal, $keputusan->get($c->calibration_session_id)),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Certificate>  $revisi  revisi baris ini, id naik
     * @param  Collection<int, Certificate>  $asal  sertifikat asal, kunci = id
     * @param  string|null  $keputusan  `PASS` | `FAIL` | null (jenis alat tanpa vonis)
     * @return array<string, mixed>
     */
    private function bentuk(Certificate $c, Collection $revisi, Collection $asal, ?string $keputusan = null): array
    {
        // Sama dengan `Certificate::revisiPenentuStatus()` / `penggantiSah()`,
        // dihitung dari revisi yang sudah dimuat (tanpa query per baris):
        // `penentu` membuat baris ini `digantikan` (terbit ATAU dibatalkan);
        // `pengganti` hanya yang TERBIT — revisi yang dibatalkan bukan dokumen
        // yang berlaku, jadi tidak ditunjuk sebagai pengganti.
        $penentu = $revisi->last(fn (Certificate $r) => in_array($r->status, Certificate::STATUS_PENGGANTI, true));
        $pengganti = Certificate::yangSah($penentu);
        $induk = $c->revision_of === null ? null : $asal->get($c->revision_of);

        return [
            'id' => $c->id,
            'nomor' => $c->nomor,
            'revisi_ke' => (int) $c->revisi_ke,
            'status' => $c->status,
            'status_dokumen' => Certificate::labelDokumen((string) $c->status, $penentu?->status),
            // ADITIF (P-1): dibaca aplikasi sebagai lencana PASS/FAIL
            // (`SertifikatRingkas.keputusan`); null = alat jenis ini tidak divonis.
            'keputusan' => $keputusan,
            'diterbitkan_pada' => $c->diterbitkan_pada?->toDateString(),
            'berlaku_sampai' => $c->berlaku_sampai?->toDateString(),
            'tahun' => $c->diterbitkan_pada === null ? null : (int) $c->diterbitkan_pada->format('Y'),
            'bulan' => $c->diterbitkan_pada === null ? null : (int) $c->diterbitkan_pada->format('n'),
            'revisi_dari' => $c->revision_of === null ? null : [
                'id' => $c->revision_of,
                'nomor' => $induk?->nomor,
            ],
            'digantikan_oleh' => $pengganti === null ? null : [
                'id' => $pengganti->id,
                'nomor' => $pengganti->nomor,
                'diterbitkan_pada' => $pengganti->diterbitkan_pada?->toDateString(),
            ],
            'ditarik_pada' => $c->ditarik_pada?->toIso8601String(),
            'dibatalkan_pada' => $c->dibatalkan_pada?->toIso8601String(),
        ];
    }
}
