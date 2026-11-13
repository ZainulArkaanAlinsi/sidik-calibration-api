<?php

namespace App\Services\Pencarian;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\FolderFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

/**
 * Pencarian terpadu dokumen lab: SATU kata cari `q` untuk empat layar.
 *
 * Kata cari dicocokkan ke:
 *  - nomor seri alat (yang terdaftar DAN yang diketik teknisi di lembar kerja),
 *    dinormalisasi seperti nomor dokumen;
 *  - nomor sertifikat, dinormalisasi (`NormalisasiNomor`): `CAL/2026/10/0001`,
 *    `cal-2026-10-0001`, `cal.2026.10.0001`, dan `cal 2026 10 0001` sama;
 *    revisi `-R<n>` ikut;
 *  - nomor order: `orders.nomor` dan `calibration_sessions.nomor_order`;
 *  - nama alat, merek, dan nama PT/pelanggan.
 * Persis diprioritaskan, lalu awalan, lalu mengandung (`RumusCocok`).
 *
 * ## Cara kerja
 *
 * Tiap method membangun subkueri MANDIRI `(cocok_id, cocok_peringkat)` atas
 * tabel sasarannya, disaring `organization_id`, lalu menempelkannya ke kueri
 * pemanggil:
 *  - `$urut = true`  : `joinSub` + `ORDER BY cocok_peringkat` -- peringkat
 *    mendahului urutan lain pemanggil. Pemanggil yang menambah urutan sesudahnya
 *    WAJIB memakai nama kolom berkualifikasi tabel (`certificates.id`).
 *  - `$urut = false` : `whereIn(id, subkueri)` -- hanya menyaring, tidak
 *    mengubah SELECT/ORDER pemanggil (garis waktu, yang mengelompokkan).
 * Subkueri mandiri itu sebabnya kueri pemanggil boleh memakai kolom tak
 * berkualifikasi (`LingkupData::sertifikat()` menulis `status` begitu saja)
 * tanpa jadi ambigu.
 *
 * KONDISI kecocokan (apa yang lolos) disusun `SubkueriCocok` sebagai kolom
 * sendiri + `fk IN (subkueri tak berkorelasi)` -- bukan `OR` di atas join enam
 * tabel. PERINGKAT (urutan) memakai join ke sesi/alat/order, tetapi hanya
 * dievaluasi untuk baris yang sudah lolos kondisi.
 *
 * BIAYA: O(N) per organisasi. `LIKE '%kata%'` dan `REPLACE` tidak bisa memakai
 * indeks, jadi tiap pencarian memindai semua baris lab: kira-kira 1-4 s pada
 * 10.000 baris, cukup untuk ribuan baris. Angka dan jalan keluar skala (kolom
 * nomor ternormalisasi bila data >= +-50 ribu) ada di `SubkueriCocok`. Klien
 * wajib debounce dan mengirim `q` minimal 2 karakter.
 *
 * Tiap method mengurung hasil ke `$organisasiId` (diturunkan dari user oleh
 * pemanggil, bukan dari input). `$pelangganId` (hanya sertifikat) memagari
 * nomor order ke order milik pelanggan itu dan melewati nama PT.
 */
final class CariDokumenLab
{
    /**
     * Nomor seri pada rantai sesi (alias: s = sesi, e = alat). Dinormalisasi
     * seperti nomor dokumen: `SN-12/3`, `sn 12 3`, dan `SN.12.3` sama.
     */
    private const SERIAL = ['e.serial_number', 's.alat_serial_number'];

    /** Nomor order (alias: s = sesi, o = order). Dinormalisasi seperti nomor sertifikat. */
    private const NOMOR_ORDER = ['s.nomor_order', 'o.nomor'];

    /**
     * @param  Builder<Certificate>  $kueri
     * @return Builder<Certificate>
     */
    public function sertifikat(
        Builder $kueri,
        int $organisasiId,
        string $q,
        bool $urut = true,
        ?int $pelangganId = null,
    ): Builder {
        $cocok = $this->dasar($kueri, 'certificates as c', 'c', $organisasiId);
        $sub = new SubkueriCocok($cocok->getGrammar(), $q, $organisasiId, $pelangganId);

        $peringkat = null;
        if ($urut) {
            $cocok->leftJoin('calibration_sessions as s', 's.id', '=', 'c.calibration_session_id');
            $this->rantaiAlat($cocok, 's.equipment_id');
            $this->rantaiOrder($cocok, 's.order_item_id');
            $peringkat = $this->rumus($cocok, $q)->nomor('c.nomor', ...self::NOMOR_ORDER, ...self::SERIAL);
        }

        return $this->tempel($kueri, $cocok, $sub->kondisiSertifikat('c'), 'c.id', $peringkat);
    }

    /**
     * @param  Builder<CalibrationSession>  $kueri
     * @return Builder<CalibrationSession>
     */
    public function sesi(Builder $kueri, int $organisasiId, string $q, bool $urut = true): Builder
    {
        $cocok = $this->dasar($kueri, 'calibration_sessions as s', 's', $organisasiId);
        $sub = new SubkueriCocok($cocok->getGrammar(), $q, $organisasiId);

        $peringkat = null;
        if ($urut) {
            $this->rantaiAlat($cocok, 's.equipment_id');
            $this->rantaiOrder($cocok, 's.order_item_id');
            $peringkat = $this->rumus($cocok, $q)->nomor('s.nomor_sesi', ...self::NOMOR_ORDER, ...self::SERIAL);
        }

        return $this->tempel($kueri, $cocok, $sub->kondisiSesi('s', true), 's.id', $peringkat);
    }

    /**
     * Alat: nomor seri, nama alat, merek, PT. Nomor sertifikat dan nomor order
     * hanya cocok PERSIS (satu alat memegang banyak dokumen; mencocokkan
     * potongan seperti `2026` akan memunculkan hampir semua alat di pemilih).
     *
     * @param  Builder<Equipment>  $kueri
     * @return Builder<Equipment>
     */
    public function alat(Builder $kueri, int $organisasiId, string $q, bool $urut = true): Builder
    {
        $cocok = $this->dasar($kueri, 'equipments as e', 'e', $organisasiId);
        $sub = new SubkueriCocok($cocok->getGrammar(), $q, $organisasiId);

        [$kondisi, $binding] = $sub->kondisiAlat('e');
        $persis = $sub->fragmenAlatPersis('e');

        // Nomor yang persis ikut LOLOS (WHERE) dan ikut peringkat 0.
        foreach ($persis as [$sql, $bindingPersis]) {
            $kondisi = '('.$kondisi.' OR '.$sql.')';
            array_push($binding, ...$bindingPersis);
        }

        $peringkat = null;
        if ($urut) {
            $peringkat = $this->rumus($cocok, $q)->nomor('e.serial_number');
            foreach ($persis as [$sql, $bindingPersis]) {
                $peringkat->lewatRelasi($sql, $bindingPersis, persis: true);
            }
        }

        return $this->tempel($kueri, $cocok, [$kondisi, $binding], 'e.id', $peringkat);
    }

    /**
     * Berkas arsip: nama berkas ditambah semua yang bisa dicari lewat sertifikat
     * atau lembar kerja yang ditautkannya.
     *
     * @param  Builder<FolderFile>  $kueri
     * @return Builder<FolderFile>
     */
    public function berkas(Builder $kueri, int $organisasiId, string $q, bool $urut = true): Builder
    {
        $cocok = $this->dasar($kueri, 'folder_files as ff', 'ff', $organisasiId);
        $sub = new SubkueriCocok($cocok->getGrammar(), $q, $organisasiId);

        $peringkat = null;
        if ($urut) {
            $g = $cocok->getGrammar();
            $cocok->leftJoin('certificates as c', 'c.id', '=', 'ff.certificate_id');
            // Berkas sertifikat menunjuk sesinya lewat sertifikat; berkas lembar
            // kerja menunjuknya langsung.
            $cocok->leftJoin(
                'calibration_sessions as s',
                new Expression('COALESCE('.$g->wrap('ff.calibration_session_id').', '.$g->wrap('c.calibration_session_id').')'),
                '=',
                's.id',
            );
            $this->rantaiAlat($cocok, 's.equipment_id');
            $this->rantaiOrder($cocok, 's.order_item_id');
            $peringkat = $this->rumus($cocok, $q)->nomor('c.nomor', 's.nomor_sesi', ...self::NOMOR_ORDER, ...self::SERIAL);
        }

        return $this->tempel($kueri, $cocok, $sub->kondisiBerkas('ff'), 'ff.id', $peringkat);
    }

    private function dasar(Builder $kueri, string $tabelAlias, string $alias, int $organisasiId): QueryBuilder
    {
        return $kueri->getModel()->getConnection()
            ->table($tabelAlias)
            ->where("{$alias}.organization_id", $organisasiId);
    }

    private function rumus(QueryBuilder $cocok, string $q): RumusCocok
    {
        return new RumusCocok($q, $cocok->getGrammar());
    }

    /** Join alat untuk peringkat. Alat yang dihapus lunak TETAP ikut. */
    private function rantaiAlat(QueryBuilder $cocok, string $kolomAlatId): void
    {
        $cocok->leftJoin('equipments as e', 'e.id', '=', $kolomAlatId);
    }

    /** Join item -> order untuk peringkat. Order yang dihapus lunak tidak ikut. */
    private function rantaiOrder(QueryBuilder $cocok, string $kolomItemId): void
    {
        $cocok->leftJoin('order_items as oi', 'oi.id', '=', $kolomItemId);
        $cocok->leftJoin('orders as o', function (JoinClause $join): void {
            $join->on('o.id', '=', 'oi.order_id')->whereNull('o.deleted_at');
        });
    }

    /**
     * @param  Builder<*>  $kueri
     * @param  array{0: string, 1: list<int|string>}  $kondisi  hasil `SubkueriCocok`
     * @param  RumusCocok|null  $peringkat  null = hanya menyaring
     * @return Builder<*>
     */
    private function tempel(Builder $kueri, QueryBuilder $cocok, array $kondisi, string $kolomId, ?RumusCocok $peringkat): Builder
    {
        [$sql, $binding] = $kondisi;
        $cocok->whereRaw($sql, $binding)->select("{$kolomId} as cocok_id");

        $tabel = $kueri->getModel()->getTable();

        if ($peringkat === null) {
            return $kueri->whereIn("{$tabel}.id", $cocok);
        }

        [$ekspresi, $bindingPeringkat] = $peringkat->peringkat();
        $cocok->selectRaw("{$ekspresi} as cocok_peringkat", $bindingPeringkat);
        // DISTINCT hanya pada turunan yang punya join (sertifikat, sesi, berkas):
        // ia mencegah MySQL menggabungkan turunan ke kueri luar, yang membuat
        // tabel sasaran dipindai DUA kali (luar + dalam): sekitar 2x lebih cepat
        // pada 10.000 sertifikat (ukuran penulis, mesin berisik; kelas biayanya
        // tetap O(N), lihat docblock kelas). Id sudah unik, hasilnya tidak berubah.
        // Alat tidak punya join dan justru melambat bila dimaterialisasi.
        if ($cocok->joins !== null) {
            $cocok->distinct();
        }

        $kueri->joinSub($cocok, 'cocok', 'cocok.cocok_id', '=', "{$tabel}.id");

        // Tanpa pilihan kolom dari pemanggil, `SELECT *` ikut membawa kolom
        // `cocok_*`. Pilihan yang SUDAH ada (mis. `withExists`) dibiarkan --
        // menimpanya menghilangkan kolom hitungan pemanggil.
        if ($kueri->getQuery()->columns === null) {
            $kueri->select("{$tabel}.*");
        }

        return $kueri->orderBy('cocok.cocok_peringkat');
    }
}
