<?php

namespace App\Services\Acuan;

use App\Models\PaketAcuan;
use App\Models\PaketAcuanLog;
use App\Models\PaketAcuanSuntingan;
use App\Models\PaketAcuanVersi;
use App\Models\SimulasiAcuan;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\PemisahanWewenang;
use App\Services\Studio\PetaSel\DataBersamaVarian;
use App\Support\JsonKanonik;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jalur resmi versi data acuan lapis 1 dari draf ke aktif (AGENTS.md §Olah data
 * butir 3; Studio Data Acuan T2.2/T2.3, 02-SRS FR-12…17, BR-01/03/08).
 *
 *   draf ──ajukan──► diajukan ──sahkan──► aktif (versi aktif lama → pensiun)
 *     │                 │
 *     └──tolak──► dibuang └──tolak──► ditolak
 *
 * Simulasi boleh dijalankan pada `draf` atau `diajukan`; laporannya dibekukan
 * di `simulasi_acuan` bersama sha256 isi saat jalan. Pengesahan menolak
 * simulasi yang tidak ada / kedaluwarsa / gagal, dan menuntut PENGAKUAN
 * tertulis (alasan per butir) bila simulasi menunjukkan angka cetak sertifikat
 * terbit bergeser, daun data bersama antar varian master berubah, atau ada
 * sesi yang gagal disimulasikan. Tidak pernah diam.
 *
 * Tiap transisi menulis satu baris `paket_acuan_log` (append-only) — sumber
 * workbook master (id + sha256) disalin dari baris `dibuat`. Tidak ada yang
 * dihapus.
 */
final class AlurVersiAcuan
{
    /** Butir yang wajib diakui saat sahkan, bila simulasi menunjukkannya. */
    public const AKUI_TERBIT = 'terbit_cetak_berubah';

    public const AKUI_BERSAMA = 'bersama_antar_varian';

    public const AKUI_SESI_GAGAL = 'sesi_gagal';

    public function __construct(
        private readonly SimulasiVersiAcuan $simulasi,
        private readonly DataBersamaVarian $bersama,
        private readonly PemisahanWewenang $pemisahan,
    ) {}

    /**
     * Sumber versi dari log `dibuat` (null bila versi tidak lahir dari master).
     *
     * @return array<string, mixed>|null
     */
    public static function sumber(PaketAcuanVersi $versi): ?array
    {
        $log = PaketAcuanLog::query()
            ->where('paket_acuan_versi_id', $versi->id)
            ->where('aksi', PaketAcuanLog::AKSI_DIBUAT)
            ->orderBy('id')
            ->first();

        return $log?->sumber;
    }

    /**
     * @param  array<string, mixed>|null  $sumber
     * @param  array<string, mixed>|null  $rincian
     */
    public static function catat(
        PaketAcuanVersi $versi,
        string $aksi,
        ?string $dari,
        ?string $ke,
        ?int $userId,
        ?string $alasan = null,
        ?array $sumber = null,
        ?array $rincian = null,
    ): PaketAcuanLog {
        return PaketAcuanLog::create([
            'organization_id' => $versi->organization_id,
            'paket_acuan_versi_id' => $versi->id,
            'aksi' => $aksi,
            'status_dari' => $dari,
            'status_ke' => $ke,
            'isi_sha256' => PenentuVersiAcuan::sha256($versi),
            'user_id' => $userId,
            'alasan' => $alasan,
            'sumber' => $sumber ?? ($aksi === PaketAcuanLog::AKSI_DIBUAT ? null : self::sumber($versi)),
            'rincian' => $rincian,
        ]);
    }

    /**
     * draf → diajukan. Isi dibekukan: `isi_sha256` dikunci (BR-01).
     *
     * @param  list<array{jenis: string, nilai: string, catatan?: ?string}>  $rujukanTambahan
     */
    public function ajukan(PaketAcuanVersi $versi, User $oleh, string $alasan, array $rujukanTambahan = []): PaketAcuanVersi
    {
        return DB::transaction(function () use ($versi, $oleh, $alasan, $rujukanTambahan): PaketAcuanVersi {
            $v = $this->kunci($versi);
            $this->pastikanJenisData($v);
            $this->pastikanStatus($v, [PaketAcuanVersi::STATUS_DRAF], 'Hanya draf yang bisa diajukan.');

            $v->forceFill([
                'status' => PaketAcuanVersi::STATUS_DIAJUKAN,
                'isi_sha256' => JsonKanonik::sha256((string) $v->isi),
                'alasan' => $alasan,
                'rujukan' => [...($v->rujukan ?? []), ...$rujukanTambahan],
                'diajukan_oleh' => $oleh->id,
                'diajukan_pada' => now(),
                'lock_versi' => (int) $v->lock_versi + 1,
            ])->save();

            self::catat($v, PaketAcuanLog::AKSI_AJUKAN, PaketAcuanVersi::STATUS_DRAF, PaketAcuanVersi::STATUS_DIAJUKAN, $oleh->id, $alasan, rincian: [
                'rujukan_tambahan' => $rujukanTambahan,
            ]);

            return $v;
        });
    }

    /**
     * Simulasikan versi (draf/diajukan) ke semua sesi tersimpan profilnya;
     * laporannya dibekukan dan menjadi `simulasi_terakhir_id`.
     */
    public function simulasikan(PaketAcuanVersi $versi, User $oleh): SimulasiAcuan
    {
        $v = $versi->fresh(['paketAcuan']) ?? $versi;
        $this->pastikanJenisData($v);
        $this->pastikanStatus($v, [PaketAcuanVersi::STATUS_DRAF, PaketAcuanVersi::STATUS_DIAJUKAN], 'Hanya draf atau versi diajukan yang bisa disimulasikan.');

        $sha = PenentuVersiAcuan::sha256($v);
        $mulai = now();
        $hasil = $this->simulasi->jalankan($v);

        $sumber = self::sumber($v);
        $dasar = $v->dasar_versi_id === null ? null : PaketAcuanVersi::query()->find($v->dasar_versi_id);
        $bersama = $this->bersama->periksa($v, (string) $v->paketAcuan?->kode_profil, $dasar?->isiTerurai(), $sumber);

        $ringkasan = $hasil['ringkasan'] + [
            'bersama_antar_varian' => count($bersama['berubah']),
            'master_varian_lain_tidak_sepakat' => count(array_filter(
                $bersama['master_lain'],
                static fn (array $m): bool => ($m['beda'] ?? []) !== [] || isset($m['galat']),
            )),
        ];

        return DB::transaction(function () use ($v, $oleh, $sha, $mulai, $hasil, $bersama, $ringkasan): SimulasiAcuan {
            // Simulasi berjalan di luar transaksi (lama); status bisa berubah
            // selama itu — diperiksa ulang di bawah kunci paket.
            $v = $this->kunci($v);
            $this->pastikanStatus($v, [PaketAcuanVersi::STATUS_DRAF, PaketAcuanVersi::STATUS_DIAJUKAN], 'Status versi berubah selama simulasi berjalan.');

            $sim = SimulasiAcuan::create([
                'paket_acuan_versi_id' => $v->id,
                'organization_id' => $v->organization_id,
                'isi_sha256_saat_jalan' => $sha,
                'status' => SimulasiAcuan::STATUS_SELESAI,
                'ringkasan' => $ringkasan,
                'laporan' => json_encode([
                    'sesi' => $hasil['sesi'],
                    'bersama_antar_varian' => $bersama,
                ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'dijalankan_oleh' => $oleh->id,
                'mulai' => $mulai,
                'selesai' => now(),
            ]);

            $v->forceFill(['simulasi_terakhir_id' => $sim->id])->save();

            self::catat($v, PaketAcuanLog::AKSI_SIMULASI, $v->status, $v->status, $oleh->id, rincian: [
                'simulasi_id' => $sim->id,
                'ringkasan' => $ringkasan,
            ]);

            return $sim;
        });
    }

    /**
     * Status simulasi terakhir terhadap isi versi sekarang.
     *
     * @return array{simulasi: ?SimulasiAcuan, status: string}
     */
    public static function statusSimulasi(PaketAcuanVersi $versi): array
    {
        $sim = $versi->simulasi_terakhir_id === null ? null : SimulasiAcuan::query()
            ->where('id', $versi->simulasi_terakhir_id)
            ->where('paket_acuan_versi_id', $versi->id)
            ->first();

        return match (true) {
            $sim === null => ['simulasi' => null, 'status' => 'belum_ada'],
            $sim->status !== SimulasiAcuan::STATUS_SELESAI => ['simulasi' => $sim, 'status' => 'gagal'],
            ! hash_equals($sim->isi_sha256_saat_jalan, PenentuVersiAcuan::sha256($versi))
                || ($versi->isi_sha256 !== null && ! hash_equals($versi->isi_sha256, JsonKanonik::sha256((string) $versi->isi))) => ['simulasi' => $sim, 'status' => 'kedaluwarsa'],
            // Sesi/sertifikat profil ini berubah sesudah simulasi (koordinator
            // 22 Okt 2026): laporan tidak lagi menggambarkan data sekarang.
            // `!=` bukan `!==`: kolom json MySQL mengurutkan ulang kunci.
            ($sim->ringkasan['jejak_data'] ?? null) != app(SimulasiVersiAcuan::class)->jejakData($versi) => ['simulasi' => $sim, 'status' => 'kedaluwarsa'],
            default => ['simulasi' => $sim, 'status' => 'terbaru'],
        };
    }

    /**
     * Butir yang wajib diakui menurut simulasi.
     *
     * @return list<string>
     */
    public static function butirWajibDiakui(SimulasiAcuan $sim): array
    {
        $r = $sim->ringkasan ?? [];
        $wajib = [];

        if ((int) ($r['terbit_cetak_berubah'] ?? 0) > 0) {
            $wajib[] = self::AKUI_TERBIT;
        }

        if ((int) ($r['bersama_antar_varian'] ?? 0) > 0) {
            $wajib[] = self::AKUI_BERSAMA;
        }

        if ((int) ($r['sesi_gagal'] ?? 0) > 0) {
            $wajib[] = self::AKUI_SESI_GAGAL;
        }

        return $wajib;
    }

    /**
     * diajukan → aktif, berlaku mulai `$berlakuMulai` (≥ hari ini). Versi aktif
     * sebelumnya → pensiun dengan `berlaku_sampai` = sehari sebelumnya; sesi
     * yang sudah distempel tetap memegang versinya (BR-10/11).
     *
     * @param  array<string, string>  $pengakuan  butir => alasan
     */
    public function sahkan(PaketAcuanVersi $versi, User $oleh, string $berlakuMulai, array $pengakuan = [], ?string $catatan = null): PaketAcuanVersi
    {
        return DB::transaction(function () use ($versi, $oleh, $berlakuMulai, $pengakuan, $catatan): PaketAcuanVersi {
            $v = $this->kunci($versi);
            $this->pastikanJenisData($v);
            $this->pastikanStatus($v, [PaketAcuanVersi::STATUS_DIAJUKAN], 'Hanya versi yang sudah diajukan yang bisa disahkan.');

            $sumber = self::sumber($v);
            $penyunting = PaketAcuanSuntingan::query()
                ->where('paket_acuan_versi_id', $v->id)
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $pengimpor = isset($sumber['workbook_master_id'])
                ? WorkbookMaster::query()->whereKey($sumber['workbook_master_id'])->value('diimpor_oleh')
                : null;

            $wewenang = $this->pemisahan->periksaPengesahanVersiAcuan($v, $oleh, $penyunting, $pengimpor === null ? null : (int) $pengimpor);

            if ($wewenang['memblokir']) {
                throw new AlurVersiAcuanDitolak(PemisahanWewenang::KODE_PENGESAH_IKUT_MENYUNTING, $wewenang['temuan'][0]['pesan']);
            }

            $mulai = CarbonImmutable::createFromFormat('!Y-m-d', $berlakuMulai);

            if ($mulai->lt(CarbonImmutable::today())) {
                throw new AlurVersiAcuanDitolak('berlaku_mulai_lampau', 'Tanggal berlaku tidak boleh sebelum hari ini; berlaku mundur menyentuh sesi yang sudah dihitung (FR-18 belum dibuka).');
            }

            ['simulasi' => $sim, 'status' => $statusSim] = self::statusSimulasi($v);

            if ($sim === null) {
                throw new AlurVersiAcuanDitolak('simulasi_belum_ada', 'Jalankan simulasi dulu; versi tidak boleh aktif sebelum dampaknya ditinjau.');
            }

            if ($statusSim === 'kedaluwarsa') {
                throw new AlurVersiAcuanDitolak('simulasi_kedaluwarsa', 'Isi versi, atau sesi/sertifikat terbit profil ini, berubah sesudah simulasi terakhir; jalankan simulasi ulang.');
            }

            if ($statusSim === 'gagal') {
                throw new AlurVersiAcuanDitolak('simulasi_gagal', 'Simulasi terakhir gagal; jalankan ulang.');
            }

            $wajib = self::butirWajibDiakui($sim);
            $kurang = array_values(array_filter(
                $wajib,
                static fn (string $b): bool => mb_strlen(trim((string) ($pengakuan[$b] ?? ''))) < 10,
            ));

            if ($kurang !== []) {
                throw new AlurVersiAcuanDitolak('pengakuan_wajib', 'Simulasi menunjukkan hal yang wajib diakui dengan alasan (min. 10 karakter): '
                    .implode(', ', $kurang).'.', ['butir' => $kurang]);
            }

            $lainAktif = PaketAcuanVersi::query()
                ->where('paket_acuan_id', $v->paket_acuan_id)
                ->where('jenis', PaketAcuanVersi::JENIS_DATA)
                ->where('status', PaketAcuanVersi::STATUS_AKTIF)
                ->where('id', '!=', $v->id)
                ->lockForUpdate()
                ->get();

            foreach ($lainAktif as $lama) {
                if ($lama->berlaku_mulai !== null && ! $lama->berlaku_mulai->lt($mulai)) {
                    throw new AlurVersiAcuanDitolak('rentang_bentrok', 'Versi aktif #'.$lama->id.' (v'.$lama->nomor_versi.') berlaku mulai '
                        .$lama->berlaku_mulai->toDateString().'; versi baru harus mulai sesudahnya (BR-03).');
                }
            }

            $sampai = $mulai->subDay();
            $dipensiunkan = [];

            foreach ($lainAktif as $lama) {
                $baruSampai = $lama->berlaku_sampai === null || $lama->berlaku_sampai->gt($sampai)
                    ? $sampai->toDateString()
                    : $lama->berlaku_sampai->toDateString();

                $lama->forceFill([
                    'status' => PaketAcuanVersi::STATUS_PENSIUN,
                    'berlaku_sampai' => $baruSampai,
                ])->save();

                self::catat($lama, PaketAcuanLog::AKSI_PENSIUN, PaketAcuanVersi::STATUS_AKTIF, PaketAcuanVersi::STATUS_PENSIUN, $oleh->id,
                    'Digantikan versi #'.$v->id.' (v'.$v->nomor_versi.') mulai '.$mulai->toDateString().'.',
                    rincian: ['digantikan_oleh' => $v->id, 'berlaku_sampai' => $baruSampai]);

                $dipensiunkan[] = $lama->id;
            }

            $diakui = array_intersect_key($pengakuan, array_flip($wajib));

            $v->forceFill([
                'status' => PaketAcuanVersi::STATUS_AKTIF,
                'berlaku_mulai' => $mulai->toDateString(),
                'berlaku_sampai' => null,
                'disahkan_oleh' => $oleh->id,
                'disahkan_pada' => now(),
                'catatan_keputusan' => $catatan,
                'lock_versi' => (int) $v->lock_versi + 1,
            ])->save();

            self::catat($v, PaketAcuanLog::AKSI_SAHKAN, PaketAcuanVersi::STATUS_DIAJUKAN, PaketAcuanVersi::STATUS_AKTIF, $oleh->id, $catatan, rincian: [
                'berlaku_mulai' => $mulai->toDateString(),
                'simulasi_id' => $sim->id,
                'simulasi_isi_sha256' => $sim->isi_sha256_saat_jalan,
                'ringkasan_simulasi' => $sim->ringkasan,
                'pengakuan' => $diakui,
                'versi_dipensiunkan' => $dipensiunkan,
            ]);

            return $v;
        });
    }

    /**
     * draf → dibuang, diajukan → ditolak. Alasan wajib; tidak ada yang dihapus.
     * Menolak versi diajukan hanya super admin (02-SRS §3).
     */
    public function tolak(PaketAcuanVersi $versi, User $oleh, string $alasan): PaketAcuanVersi
    {
        return DB::transaction(function () use ($versi, $oleh, $alasan): PaketAcuanVersi {
            $v = $this->kunci($versi);
            $this->pastikanJenisData($v);
            $this->pastikanStatus($v, [PaketAcuanVersi::STATUS_DRAF, PaketAcuanVersi::STATUS_DIAJUKAN], 'Hanya draf atau versi diajukan yang bisa ditolak.');

            $dari = $v->status;

            if ($dari === PaketAcuanVersi::STATUS_DIAJUKAN && ! $oleh->isSuperAdmin()) {
                throw new AlurVersiAcuanDitolak('hanya_super_admin', 'Versi yang sudah diajukan hanya bisa ditolak super admin.', status: 403);
            }

            $ke = $dari === PaketAcuanVersi::STATUS_DRAF ? PaketAcuanVersi::STATUS_DIBUANG : PaketAcuanVersi::STATUS_DITOLAK;

            $v->forceFill([
                'status' => $ke,
                'ditolak_oleh' => $oleh->id,
                'ditolak_pada' => now(),
                'catatan_keputusan' => $alasan,
                'lock_versi' => (int) $v->lock_versi + 1,
            ])->save();

            self::catat($v, $ke === PaketAcuanVersi::STATUS_DIBUANG ? PaketAcuanLog::AKSI_BUANG : PaketAcuanLog::AKSI_TOLAK, $dari, $ke, $oleh->id, $alasan);

            return $v;
        });
    }

    /**
     * Kunci baris PAKET dulu, baru versinya — urutan yang sama di semua
     * transisi. Tanpa kunci paket, dua pengaktifan versi berbeda dalam satu
     * paket tidak saling melihat (masing-masing hanya mengunci versinya
     * sendiri) dan dua versi bisa sama-sama `aktif` (BR-03).
     */
    private function kunci(PaketAcuanVersi $versi): PaketAcuanVersi
    {
        $paketId = PaketAcuanVersi::query()->whereKey($versi->id)->value('paket_acuan_id');

        PaketAcuan::withTrashed()->whereKey($paketId)->lockForUpdate()->first();

        return PaketAcuanVersi::query()->with('paketAcuan')->lockForUpdate()->findOrFail($versi->id);
    }

    private function pastikanJenisData(PaketAcuanVersi $v): void
    {
        if ($v->jenis !== PaketAcuanVersi::JENIS_DATA) {
            throw new AlurVersiAcuanDitolak('jenis_tidak_didukung', 'Alur ini baru untuk versi data (lapis 1).');
        }
    }

    /** @param  list<string>  $boleh */
    private function pastikanStatus(PaketAcuanVersi $v, array $boleh, string $pesan): void
    {
        if (! in_array($v->status, $boleh, true)) {
            throw new AlurVersiAcuanDitolak('status_tidak_sesuai', $pesan.' Status sekarang: '.$v->status.'.', ['status' => $v->status]);
        }
    }
}
