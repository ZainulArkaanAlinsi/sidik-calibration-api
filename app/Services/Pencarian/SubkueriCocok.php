<?php

namespace App\Services\Pencarian;

use Illuminate\Database\Query\Grammars\Grammar;

/**
 * Merangkai kondisi "kata cari cocok" untuk SATU tabel sebagai
 * `kolom_sendiri LIKE … OR fk IN (SELECT id FROM tabel_lain WHERE …)`.
 *
 * ## Kenapa bukan satu `OR` besar di atas join enam tabel
 *
 * `OR` yang melintasi tabel menutup jalan optimizer: tidak ada tabel yang bisa
 * disaring duluan, jadi tiap sertifikat menjalankan enam pencarian PK lalu
 * mengevaluasi semua `LIKE`. Subkueri `IN` yang TIDAK berkorelasi dievaluasi
 * sekali per tabel kecil (alat, order, PT), lalu dicocokkan ke kolom FK. Itu
 * memangkas sekitar 2-4x, TETAPI tidak mengubah kelasnya.
 *
 * ## Biayanya tetap O(N) per organisasi -- jangan dibaca sebagai "cepat"
 *
 * `LIKE '%kata%'` dan `REPLACE(...)` (normalisasi nomor) tidak bisa memakai
 * indeks, jadi tiap pencarian memindai seluruh baris laboratorium. Patokan jujur
 * di MySQL lokal dengan 10.000 sertifikat/sesi sintetis (mesin berisik, selisih
 * antarputaran sampai 2x): penulis mengukur median 60-340 ms per kueri;
 * peninjau independen mengukur 0,6-1,8 s untuk Telusuri dan pencarian alat,
 * sekitar 1,9 s untuk garis waktu ber-`q`, dan sekitar 3,7 s untuk Riwayat
 * ber-`q` dan tanggal. Pegang kisaran 1-4 s pada 10.000 baris. Cukup untuk
 * ribuan baris (produksi saat ini sekitar 17 sertifikat dan 25 sesi). Bila
 * data mendekati 50 ribu baris atau lebih, jalannya adalah kolom nomor
 * ternormalisasi yang diindeks (kolom baru: keputusan pemilik), bukan
 * menyetel SQL ini lagi. Indeks tanggal (garis waktu, `dari`/`sampai`) benar
 * dan terukur: 2-160 ms.
 *
 * Subkueri bersarang mengikuti rantai kepemilikan: sertifikat -> sesi -> alat
 * -> PT, dan sesi -> item -> order. Alias tiap tingkat berbeda (`sa`, `ea`,
 * `oa`, ...) supaya SQL-nya terbaca; semuanya tidak berkorelasi.
 *
 * Yang dikecualikan menurut tujuan pemanggil, bukan kebetulan:
 *  - `$denganSertifikat = false` (target sertifikat): nomor sertifikat sudah
 *    dicocokkan di tingkat sertifikatnya sendiri. Kalau ikut lewat sesi,
 *    mencari nomor revisi `…-R1` akan menarik sertifikat dasarnya juga
 *    (satu sesi memegang keduanya).
 *  - `$pelangganId` (pelanggan): order hanya milik pelanggan itu; nama PT dan
 *    nama pemilik tidak dicari (pelanggan sudah tahu namanya sendiri).
 *
 * Alat yang sudah dihapus lunak TETAP ikut (tidak ada saringan `deleted_at`);
 * order yang dihapus lunak tidak.
 */
final class SubkueriCocok
{
    private readonly string $kata;

    private readonly string $kunciNomor;

    public function __construct(
        private readonly Grammar $g,
        string $kata,
        private readonly int $organisasiId,
        private readonly ?int $pelangganId = null,
    ) {
        $this->kata = trim($kata);
        $this->kunciNomor = NormalisasiNomor::kunci($this->kata);
    }

    /**
     * Kondisi alat beralias $a: seri, nama, merek, dan nama PT-nya.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public function kondisiAlat(string $a): array
    {
        $rumus = $this->rumus()->kunci("{$a}.serial_number")->deskripsi("{$a}.nama_alat", "{$a}.merk");

        if ($this->pelangganId === null) {
            $rumus->lewatRelasi(
                $this->w("{$a}.customer_id").' IN (SELECT '.$this->w('cua.id').' FROM '.$this->t('customers as cua')
                .' WHERE '.$this->w('cua.organization_id').' = ? AND '.PolaLike::sql($this->w('cua.nama')).')',
                [$this->organisasiId, PolaLike::mengandung($this->kata)],
            );
        }

        return $rumus->kondisi();
    }

    /**
     * Kondisi sesi beralias $a: nomor seri lembar, nomor order, alat (lewat
     * [kondisiAlat]), order (lewat `order_items`), dan -- bila $denganSertifikat
     * -- nomor sesi serta nomor sertifikatnya.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public function kondisiSesi(string $a, bool $denganSertifikat): array
    {
        $rumus = $this->rumus()->kunci("{$a}.alat_serial_number")->nomor("{$a}.nomor_order");

        if ($denganSertifikat) {
            $rumus->nomor("{$a}.nomor_sesi");
        }

        if ($this->pelangganId === null) {
            $rumus->deskripsi("{$a}.pemilik_nama");
        }

        [$kondisiAlat, $bindingAlat] = $this->kondisiAlat('ea');
        $rumus->lewatRelasi(
            $this->w("{$a}.equipment_id").' IN (SELECT '.$this->w('ea.id').' FROM '.$this->t('equipments as ea')
            .' WHERE '.$this->w('ea.organization_id').' = ? AND '.$kondisiAlat.')',
            [$this->organisasiId, ...$bindingAlat],
        );

        if ($this->kunciNomor === '') {
            return $rumus->kondisi();
        }

        // Order dari item order: `orders.nomor` dinormalisasi, order yang dihapus
        // lunak tidak ikut, dan untuk pelanggan dikunci ke order miliknya.
        $bindingOrder = [$this->organisasiId];
        $batasPelanggan = '';
        if ($this->pelangganId !== null) {
            $batasPelanggan = ' AND '.$this->w('oa.customer_id').' = ?';
            $bindingOrder[] = $this->pelangganId;
        }
        $bindingOrder[] = PolaLike::mengandung($this->kunciNomor);

        $rumus->lewatRelasi(
            $this->w("{$a}.order_item_id").' IN (SELECT '.$this->w('oia.id').' FROM '.$this->t('order_items as oia')
            .' WHERE '.$this->w('oia.order_id').' IN (SELECT '.$this->w('oa.id').' FROM '.$this->t('orders as oa')
            .' WHERE '.$this->w('oa.organization_id').' = ? AND '.$this->w('oa.deleted_at').' IS NULL'.$batasPelanggan
            .' AND '.PolaLike::sql(NormalisasiNomor::sqlKolom($this->w('oa.nomor'))).'))',
            $bindingOrder,
        );

        if ($denganSertifikat) {
            $rumus->lewatRelasi(
                $this->w("{$a}.id").' IN (SELECT '.$this->w('ca.calibration_session_id').' FROM '.$this->t('certificates as ca')
                .' WHERE '.$this->w('ca.organization_id').' = ? AND '.PolaLike::sql(NormalisasiNomor::sqlKolom($this->w('ca.nomor'))).')',
                [$this->organisasiId, PolaLike::mengandung($this->kunciNomor)],
            );
        }

        return $rumus->kondisi();
    }

    /**
     * Kondisi sertifikat beralias $a: nomornya sendiri, atau sesinya cocok
     * (tanpa nomor sertifikat -- lihat catatan kelas).
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public function kondisiSertifikat(string $a): array
    {
        $rumus = $this->rumus()->nomor("{$a}.nomor");

        [$kondisiSesi, $bindingSesi] = $this->kondisiSesi('sa', false);
        $rumus->lewatRelasi(
            $this->w("{$a}.calibration_session_id").' IN (SELECT '.$this->w('sa.id').' FROM '.$this->t('calibration_sessions as sa')
            .' WHERE '.$this->w('sa.organization_id').' = ? AND '.$kondisiSesi.')',
            [$this->organisasiId, ...$bindingSesi],
        );

        return $rumus->kondisi();
    }

    /**
     * Kondisi berkas beralias $a: nama berkas, sertifikat yang ditautkan, atau
     * lembar kerja (sesi) yang ditautkan.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public function kondisiBerkas(string $a): array
    {
        $rumus = $this->rumus()->deskripsi("{$a}.nama");

        [$kondisiSertifikat, $bindingSertifikat] = $this->kondisiSertifikat('cb');
        $rumus->lewatRelasi(
            $this->w("{$a}.certificate_id").' IN (SELECT '.$this->w('cb.id').' FROM '.$this->t('certificates as cb')
            .' WHERE '.$this->w('cb.organization_id').' = ? AND '.$kondisiSertifikat.')',
            [$this->organisasiId, ...$bindingSertifikat],
        );

        [$kondisiSesi, $bindingSesi] = $this->kondisiSesi('sb', true);
        $rumus->lewatRelasi(
            $this->w("{$a}.calibration_session_id").' IN (SELECT '.$this->w('sb.id').' FROM '.$this->t('calibration_sessions as sb')
            .' WHERE '.$this->w('sb.organization_id').' = ? AND '.$kondisiSesi.')',
            [$this->organisasiId, ...$bindingSesi],
        );

        return $rumus->kondisi();
    }

    /**
     * Kondisi alat beralias $a untuk nomor sertifikat/order yang PERSIS saja
     * (seluruh nomor, bukan potongan): satu alat memegang banyak dokumen, dan
     * mencocokkan potongan seperti `2026` memunculkan hampir semua alat di
     * pemilih alat. Kosong bila kata cari tak punya huruf/angka.
     *
     * @return list<array{0: string, 1: list<int|string>}> fragmen `IN (…)` beserta binding
     */
    public function fragmenAlatPersis(string $a): array
    {
        if ($this->kunciNomor === '') {
            return [];
        }

        $nomorOrderSesi = 'UPPER('.NormalisasiNomor::sqlKolom($this->w('sx.nomor_order')).') = ?';
        $nomorSertifikat = 'UPPER('.NormalisasiNomor::sqlKolom($this->w('cx.nomor')).') = ?';
        $nomorOrder = 'UPPER('.NormalisasiNomor::sqlKolom($this->w('ox.nomor')).') = ?';

        return [
            [
                $this->w("{$a}.id").' IN (SELECT '.$this->w('sx.equipment_id').' FROM '.$this->t('calibration_sessions as sx')
                .' WHERE '.$this->w('sx.organization_id').' = ? AND ('.$nomorOrderSesi
                .' OR '.$this->w('sx.id').' IN (SELECT '.$this->w('cx.calibration_session_id').' FROM '.$this->t('certificates as cx')
                .' WHERE '.$this->w('cx.organization_id').' = ? AND '.$nomorSertifikat.')))',
                [$this->organisasiId, $this->kunciNomor, $this->organisasiId, $this->kunciNomor],
            ],
            [
                $this->w("{$a}.id").' IN (SELECT '.$this->w('oix.equipment_id').' FROM '.$this->t('order_items as oix')
                .' WHERE '.$this->w('oix.order_id').' IN (SELECT '.$this->w('ox.id').' FROM '.$this->t('orders as ox')
                .' WHERE '.$this->w('ox.organization_id').' = ? AND '.$this->w('ox.deleted_at').' IS NULL AND '.$nomorOrder.'))',
                [$this->organisasiId, $this->kunciNomor],
            ],
        ];
    }

    private function rumus(): RumusCocok
    {
        return new RumusCocok($this->kata, $this->g);
    }

    private function w(string $kolom): string
    {
        return $this->g->wrap($kolom);
    }

    private function t(string $tabelAlias): string
    {
        return $this->g->wrapTable($tabelAlias);
    }
}
