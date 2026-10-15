<?php

namespace App\Services\Studio\Workbook;

use App\Models\WorkbookMaster;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Status jenis workbook master di Studio (K-49-13, 15 Okt 2026): satu master
 * AKTIF per (lab, `kunci_jenis`), sisanya RIWAYAT (tersembunyi dari daftar,
 * tetap bisa dibuka) atau ARSIP (dikeluarkan dari Studio). Tidak ada yang
 * dihapus — baris, lembar, dan berkas asli tetap; perubahan status lewat model
 * (`Diaudit` mencatat sebelum → sesudah, pelaku = `Auth::id()`), alasannya
 * ditambahkan ke `catatan`.
 *
 * Satu pintu untuk impor (`untukImpor`), perintah admin
 * `studio:rapikan-workbook` (`aktifkan`, `jadikanRiwayat`, `arsipkan`), dan
 * migrasi data lama (`tataAwal`). `versi` = urutan id di dalam jenis (1 =
 * impor paling awal), dinomori ulang setiap kali isi jenisnya berubah.
 */
final class JenisWorkbook
{
    public const STATUS_AKTIF = 'aktif';

    public const STATUS_RIWAYAT = 'riwayat';

    public const STATUS_ARSIP = 'arsip';

    /**
     * Dipanggil impor DI DALAM transaksi, SEBELUM baris baru dibuat: master
     * aktif jenis itu dikunci & dinonaktifkan (indeks unik aktif), versi baru
     * = terbesar + 1. Sumber csv tidak pernah menggantikan xlsm aktif (csv =
     * ekspor nilai dari master yang sama): ia lahir sebagai riwayatnya.
     *
     * @return array{versi: int, lama: ?WorkbookMaster, aktif: bool}
     */
    public function untukImpor(int $organisasiId, string $kunci, string $sumber): array
    {
        $lama = WorkbookMaster::query()
            ->where('organization_id', $organisasiId)
            ->where('kunci_jenis', $kunci)
            ->where('aktif', true)
            ->lockForUpdate()
            ->first();
        $versi = 1 + (int) WorkbookMaster::query()
            ->where('organization_id', $organisasiId)
            ->where('kunci_jenis', $kunci)
            ->max('versi');

        if ($lama !== null && $sumber === WorkbookMaster::SUMBER_CSV && $lama->sumber === WorkbookMaster::SUMBER_XLSM) {
            return ['versi' => $versi, 'lama' => $lama, 'aktif' => false];
        }

        if ($lama !== null) {
            $lama->aktif = false;
            $lama->save();
        }

        return ['versi' => $versi, 'lama' => $lama, 'aktif' => true];
    }

    /** Sesudah baris baru dibuat: master lama menunjuk penggantinya. */
    public function catatPengganti(WorkbookMaster $lama, WorkbookMaster $baru, bool $baruAktif): void
    {
        if (! $baruAktif) {
            // csv yang lahir sebagai riwayat xlsm aktif.
            $baru->digantikan_oleh_id = $lama->id;
            $baru->save();

            return;
        }

        $lama->digantikan_oleh_id = $baru->id;
        $lama->catatan = self::tambahCatatan($lama->catatan, "Digantikan impor #{$baru->id} (versi {$baru->versi}) sebagai master aktif jenis {$baru->kunci_jenis}.");
        $lama->save();
    }

    /**
     * Jadikan `$w` master aktif — di jenisnya sendiri, atau dipindah ke
     * `$kunci`. Master aktif jenis tujuan jadi riwayat `$w`; arsip dipulihkan.
     * Bila `$w` pindah dari jenis lain tempat ia aktif, master berikutnya di
     * jenis asal (versi terbesar) naik jadi aktif.
     */
    public function aktifkan(WorkbookMaster $w, ?string $kunci, string $alasan): void
    {
        DB::transaction(function () use ($w, $kunci, $alasan): void {
            $w = $this->kunciBaris($w);
            $asal = (string) $w->kunci_jenis;
            $tujuan = $kunci ?? $asal;
            $tadinyaAktif = $w->aktif;

            $aktif = WorkbookMaster::query()
                ->where('organization_id', $w->organization_id)
                ->where('kunci_jenis', $tujuan)
                ->where('aktif', true)
                ->whereKeyNot($w->id)
                ->lockForUpdate()
                ->first();

            if ($aktif !== null) {
                $aktif->aktif = false;
                $aktif->digantikan_oleh_id = $w->id;
                $aktif->catatan = self::tambahCatatan($aktif->catatan, "Jadi riwayat: #{$w->id} diaktifkan sebagai master jenis {$tujuan}. Alasan: {$alasan}");
                $aktif->save();
            }

            $w->kunci_jenis = $tujuan;
            $w->aktif = true;
            $w->digantikan_oleh_id = null;
            $w->diarsipkan_pada = null;
            $w->catatan = self::tambahCatatan($w->catatan, "Diaktifkan sebagai master jenis {$tujuan}".($asal !== $tujuan ? " (pindah dari {$asal})" : '').". Alasan: {$alasan}");
            $w->save();

            if ($asal !== $tujuan) {
                if ($tadinyaAktif) {
                    $this->naikkanPengganti((int) $w->organization_id, $asal, $w->id, $alasan);
                }

                $this->nomoriUlang((int) $w->organization_id, $asal);
            }

            $this->nomoriUlang((int) $w->organization_id, $tujuan);
        });
    }

    /**
     * Jadikan `$w` riwayat dari master `$pengganti`. Bila keduanya beda jenis,
     * SELURUH jenis `$w` (baris non-arsip berkunci sama) pindah ke jenis
     * `$pengganti` sebagai riwayat — itu versi-versi lama master yang sama.
     */
    public function jadikanRiwayat(WorkbookMaster $w, WorkbookMaster $pengganti, string $alasan): void
    {
        if ((int) $w->id === (int) $pengganti->id) {
            throw new RuntimeException('Workbook tidak bisa jadi riwayat dari dirinya sendiri.');
        }

        if ((int) $w->organization_id !== (int) $pengganti->organization_id) {
            throw new RuntimeException('Pengganti harus workbook di lab yang sama.');
        }

        DB::transaction(function () use ($w, $pengganti, $alasan): void {
            $pengganti = $this->kunciBaris($pengganti);

            if ($pengganti->diarsipkan_pada !== null) {
                throw new RuntimeException("Pengganti #{$pengganti->id} diarsipkan; aktifkan dulu.");
            }

            if (! $pengganti->aktif) {
                $this->aktifkan($pengganti, null, $alasan);
                $pengganti = $this->kunciBaris($pengganti);
            }

            $w = $this->kunciBaris($w);
            $org = (int) $w->organization_id;
            $asal = (string) $w->kunci_jenis;
            $tujuan = (string) $pengganti->kunci_jenis;
            $kelompok = $asal === $tujuan
                ? collect([$w])
                : WorkbookMaster::query()->where('organization_id', $org)->where('kunci_jenis', $asal)
                    ->whereNull('diarsipkan_pada')->orderBy('id')->lockForUpdate()->get();

            foreach ($kelompok as $r) {
                $r->kunci_jenis = $tujuan;

                // Kepala rantai jenis asal (yang aktif / tanpa pengganti) dan `$w` sendiri → menunjuk master baru.
                if ($r->aktif || $r->digantikan_oleh_id === null || (int) $r->id === (int) $w->id) {
                    $r->aktif = false;
                    $r->digantikan_oleh_id = $pengganti->id;
                }

                if ((int) $r->id === (int) $w->id || $asal !== $tujuan) {
                    $r->catatan = self::tambahCatatan($r->catatan, "Jadi riwayat dari #{$pengganti->id} (jenis {$tujuan})".($asal !== $tujuan ? " — pindah dari jenis {$asal}" : '').". Alasan: {$alasan}");
                }

                $r->save();
            }

            $this->nomoriUlang($org, $tujuan);

            if ($asal !== $tujuan) {
                $this->nomoriUlang($org, $asal);
            }
        });
    }

    /**
     * Keluarkan `$w` dari Studio (olah data/pekerjaan yang terlanjur diimpor).
     * Baris, lembar, dan berkas asli TIDAK dihapus. Bila `$w` master aktif,
     * versi terbesar berikutnya di jenisnya naik jadi aktif.
     */
    public function arsipkan(WorkbookMaster $w, string $alasan): void
    {
        DB::transaction(function () use ($w, $alasan): void {
            $w = $this->kunciBaris($w);

            if ($w->diarsipkan_pada !== null) {
                return;
            }

            $tadinyaAktif = $w->aktif;
            $w->aktif = false;
            $w->diarsipkan_pada = now();
            $w->catatan = self::tambahCatatan($w->catatan, "Dikeluarkan dari Studio (arsip; baris & berkas tidak dihapus). Alasan: {$alasan}");
            $w->save();

            if ($tadinyaAktif) {
                $this->naikkanPengganti((int) $w->organization_id, (string) $w->kunci_jenis, $w->id, $alasan);
            }
        });
    }

    /** `aktif` | `riwayat` | `arsip`. */
    public static function status(WorkbookMaster $w): string
    {
        return match (true) {
            $w->diarsipkan_pada !== null => self::STATUS_ARSIP,
            $w->aktif => self::STATUS_AKTIF,
            default => self::STATUS_RIWAYAT,
        };
    }

    /**
     * Tata data lama (migrasi K-49-13): baris tanpa `kunci_jenis` mendapat
     * kunci dari namanya; per jenis satu aktif — xlsm lebih dulu, lalu id
     * terbesar — sisanya riwayat darinya; `versi` = urutan id. Lewat query
     * builder (tanpa audit: penataan skema, bukan aksi pengguna). Baris riwayat
     * ditulis SEBELUM baris aktif supaya aman bila indeks unik sudah ada.
     */
    public static function tataAwal(): void
    {
        $baris = DB::table('workbook_master')->whereNull('kunci_jenis')->orderBy('id')
            ->get(['id', 'organization_id', 'kode_profil', 'keluarga', 'nama', 'sumber']);
        $kelompok = [];

        foreach ($baris as $b) {
            $kunci = KunciJenisWorkbook::dari($b->kode_profil, $b->keluarga, (string) $b->nama);
            $kelompok[$b->organization_id."\n".$kunci][] = $b;
        }

        foreach ($kelompok as $kunciKelompok => $anggota) {
            [, $kunci] = explode("\n", $kunciKelompok, 2);
            $xlsm = array_values(array_filter($anggota, static fn (object $b): bool => $b->sumber === WorkbookMaster::SUMBER_XLSM));
            $aktif = end($xlsm) ?: end($anggota);
            $urut = array_column($anggota, 'id');
            sort($urut);
            $versi = array_flip($urut);

            foreach ($anggota as $b) {
                if ((int) $b->id === (int) $aktif->id) {
                    continue;
                }

                DB::table('workbook_master')->where('id', $b->id)->update([
                    'kunci_jenis' => $kunci,
                    'aktif' => null,
                    'versi' => $versi[$b->id] + 1,
                    'digantikan_oleh_id' => $aktif->id,
                ]);
            }

            DB::table('workbook_master')->where('id', $aktif->id)->update([
                'kunci_jenis' => $kunci,
                'aktif' => true,
                'versi' => $versi[$aktif->id] + 1,
                'digantikan_oleh_id' => null,
            ]);
        }
    }

    /** Baris segar yang dikunci untuk ditulis. */
    private function kunciBaris(WorkbookMaster $w): WorkbookMaster
    {
        return WorkbookMaster::query()->whereKey($w->id)->lockForUpdate()->firstOrFail();
    }

    /** Master aktif jenis `$kunci` pergi (`$pergi`): versi terbesar berikutnya naik, penunjuk ke `$pergi` dialihkan. */
    private function naikkanPengganti(int $organisasiId, string $kunci, int $pergi, string $alasan): void
    {
        $naik = WorkbookMaster::query()
            ->where('organization_id', $organisasiId)
            ->where('kunci_jenis', $kunci)
            ->whereNull('diarsipkan_pada')
            ->whereKeyNot($pergi)
            ->orderByDesc('versi')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($naik === null) {
            return;
        }

        $naik->aktif = true;
        $naik->digantikan_oleh_id = null;
        $naik->catatan = self::tambahCatatan($naik->catatan, "Naik jadi master aktif jenis {$kunci}: #{$pergi} keluar dari jenis ini. Alasan: {$alasan}");
        $naik->save();

        WorkbookMaster::query()
            ->where('organization_id', $organisasiId)
            ->where('kunci_jenis', $kunci)
            ->where('digantikan_oleh_id', $pergi)
            ->whereKeyNot($naik->id)
            ->get()
            ->each(static function (WorkbookMaster $r) use ($naik): void {
                $r->digantikan_oleh_id = $naik->id;
                $r->save();
            });
    }

    /** `versi` = urutan id di dalam jenis (termasuk arsip), 1 = paling awal. */
    private function nomoriUlang(int $organisasiId, string $kunci): void
    {
        $n = 0;

        foreach (WorkbookMaster::query()->where('organization_id', $organisasiId)->where('kunci_jenis', $kunci)->orderBy('id')->get() as $r) {
            $n++;

            if ((int) $r->versi !== $n) {
                $r->versi = $n;
                $r->save();
            }
        }
    }

    private static function tambahCatatan(?string $catatan, string $baru): string
    {
        return trim(($catatan ?? '').' ['.now()->format('Y-m-d').'] '.$baru);
    }
}
