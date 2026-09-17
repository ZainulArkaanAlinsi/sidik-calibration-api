<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Samain `equipments.tanggal_kalibrasi_terakhir` & `tanggal_jatuh_tempo` sama
 * sertifikat aktif alat itu.
 *
 * ## Masalah yang diselesaikan
 *
 * Tanggal jatuh tempo dipilih admin waktu approve dan disimpan di
 * `certificates.berlaku_sampai` — tapi [PengingatJatuhTempo] membaca
 * `equipments.tanggal_jatuh_tempo`, kolom yang cuma berubah lewat form manual
 * dan impor Excel. Dua angka yang seharusnya sama, diisi dari dua jalan yang
 * nggak pernah ketemu.
 *
 * Akibatnya sunyi: sertifikat terbit dengan masa berlaku baru, kolom di alatnya
 * tetap yang lama, dan pengingat pagi mengabari admin soal tanggal yang sudah
 * nggak berlaku — atau nggak mengabari sama sekali. Nggak ada error di mana
 * pun, karena dua-duanya kolom yang sah berisi tanggal yang sah.
 *
 * ## Yang TIDAK dilakukan, dan itu disengaja
 *
 * Kalau alat nggak punya sertifikat aktif, tanggal yang sudah ada **nggak
 * ditimpa**. Alat lama yang masuk lewat impor Excel tanggalnya diisi tangan
 * dari sertifikat kertas yang nggak pernah masuk sistem ini; menimpanya dengan
 * `null` berarti menghapus satu-satunya jadwal yang dipunya alat itu, dan alat
 * tanpa `tanggal_jatuh_tempo` HILANG dari pengingat (lihat `whereNotNull` di
 * [PengingatJatuhTempo::untukOrganisasi]) — bukan muncul sebagai masalah.
 */
class SinkronJadwalAlat
{
    /**
     * Tulis jadwal alat dari sertifikat aktifnya. Diam kalau nggak ada.
     */
    public function untuk(Equipment $alat): void
    {
        $rencana = $this->rencana($alat);

        if ($rencana === null) {
            return;
        }

        // Nggak nembak UPDATE kalau nilainya sudah sama.
        //
        // [Diaudit] memang sudah menahan baris riwayat kosong, tapi query-nya
        // tetap jalan. Perintah `alat:sinkron-jadwal` menyapu seluruh alat lab,
        // dan sebagian besarnya sudah benar — ribuan UPDATE yang nggak mengubah
        // apa pun cuma ongkos.
        if ($alat->tanggal_kalibrasi_terakhir?->toDateString() === $rencana['tanggal_kalibrasi_terakhir']
            && $alat->tanggal_jatuh_tempo?->toDateString() === $rencana['tanggal_jatuh_tempo']) {
            return;
        }

        $alat->update([
            'tanggal_kalibrasi_terakhir' => $rencana['tanggal_kalibrasi_terakhir'],
            'tanggal_jatuh_tempo' => $rencana['tanggal_jatuh_tempo'],
        ]);
    }

    /**
     * Hitung tanggal yang SEHARUSNYA dipakai alat ini, tanpa menulis apa pun.
     *
     * Dipisah dari [untuk] supaya `alat:sinkron-jadwal --dry-run` menampilkan
     * angka dari perhitungan yang SAMA dengan yang nantinya ditulis. Kalau
     * pratinjaunya punya hitungan sendiri, suatu hari yang ditampilkan dan yang
     * ditulis bakal beda — dan seluruh gunanya dry-run (ditinjau manusia sebelum
     * menyentuh data produksi) hilang tanpa ada yang sadar.
     *
     * @return array{sertifikat: Certificate, nomor: string|null, tanggal_kalibrasi_terakhir: string, tanggal_jatuh_tempo: string}|null
     */
    public function rencana(Equipment $alat): ?array
    {
        $sertifikat = $this->sertifikatAktif($alat);

        if ($sertifikat === null) {
            return null;
        }

        $tanggalKalibrasi = $sertifikat->session?->tanggal_kalibrasi;

        // `berlaku_sampai` nullable di skema, dan sertifikat lama hasil impor
        // bisa terbit tanpa pernah punya masa berlaku. Diperlakukan sama dengan
        // "nggak ada sertifikat aktif": lebih baik jadwal lamanya bertahan
        // daripada ditimpa `null` dan alatnya raib dari pengingat.
        if ($tanggalKalibrasi === null || $sertifikat->berlaku_sampai === null) {
            return null;
        }

        return [
            'sertifikat' => $sertifikat,
            'nomor' => $sertifikat->nomor,
            'tanggal_kalibrasi_terakhir' => $tanggalKalibrasi->toDateString(),
            'tanggal_jatuh_tempo' => $sertifikat->berlaku_sampai->toDateString(),
        ];
    }

    /**
     * Sertifikat yang jadi sumber jadwal alat ini — atau `null`.
     *
     * Tiga syarat, dan yang ketiga yang paling gampang kelewat:
     *
     * 1. `status = terbit`. Yang `menunggu_generate` belum jadi dokumen, dan
     *    yang `gagal` nggak pernah jadi.
     * 2. Belum digantikan revisi yang SUDAH TERBIT. Revisinya sendiri yang
     *    harus terbit — revisi yang gagal dirender nggak boleh mematikan
     *    sertifikat asalnya, karena kalau begitu alatnya kehilangan jadwal
     *    gara-gara dokumen yang nggak pernah ada.
     * 3. Kalau masih lebih dari satu, yang `diterbitkan_pada`-nya paling baru.
     *
     * `orderByDesc('id')` di belakangnya BUKAN hiasan: `diterbitkan_pada`
     * bertipe `date`, bukan `datetime` (lihat migrasi `create_certificates_table`).
     * Dua sertifikat yang terbit di hari yang sama — persis yang terjadi waktu
     * sertifikat direvisi di hari terbitnya — nilainya seri, dan tanpa tie-break
     * urutannya ditentukan MySQL. Alatnya lalu bisa mengambil tanggal dari
     * sertifikat yang salah, bergantian tiap kali perintah sapuannya dijalankan.
     *
     * Disaring `organization_id` walaupun sudah lewat sesi milik alat ini.
     * Sabuk kedua: satu `equipment_id` yang salah di data nggak boleh bisa
     * menarik sertifikat lab lain (lihat skill `sidik-query-organisasi`).
     */
    public function sertifikatAktif(Equipment $alat): ?Certificate
    {
        return Certificate::query()
            ->with('session')
            ->where('certificates.organization_id', $alat->organization_id)
            ->where('certificates.status', Certificate::STATUS_TERBIT)
            ->whereHas('session', fn (Builder $sesi) => $sesi->where('equipment_id', $alat->getKey()))
            ->whereNotExists(fn (QueryBuilder $revisi) => $revisi
                ->selectRaw('1')
                ->from('certificates as pengganti')
                ->whereColumn('pengganti.revision_of', 'certificates.id')
                ->where('pengganti.status', Certificate::STATUS_TERBIT))
            ->orderByDesc('diterbitkan_pada')
            ->orderByDesc('id')
            ->first();
    }
}
