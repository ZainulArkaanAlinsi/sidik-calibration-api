<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\OrderItem;

/**
 * Tahap perjalanan satu alat di dalam satu paket — DITURUNKAN, bukan disimpan.
 *
 * Kenapa diturunkan dijelaskan panjang di migrasi
 * `2026_09_27_110000_tambah_pelacakan_ke_orders.php`. Ringkasnya: enam dari
 * delapan tahapnya sudah punya sumber kebenaran (`calibration_sessions.status`,
 * `certificates.status`), dan kolom yang menyalinnya pasti melenceng lewat jalan
 * yang tidak kelihatan — `reject()` yang tidak tahu ada kolom `tahap`.
 *
 * Harga yang dibayar: satu paket berisi 12 alat butuh sesi & sertifikatnya
 * ter-eager-load. Itu dua kueri, bukan 24 — dan jauh lebih murah daripada satu
 * layar pelanggan yang menampilkan tahap yang salah.
 *
 * ## Urutan tahapnya PENTING
 *
 * `URUTAN` bukan daftar hiasan: dia yang dipakai UI menggambar garis waktu, dan
 * dia yang dipakai `tahapPaket()` menentukan "paket ini secara keseluruhan sudah
 * sampai mana" — yaitu tahap alat yang PALING TERTINGGAL. Paket dengan 11 alat
 * selesai dan 1 masih dikalibrasi belum selesai, dan pelanggan yang datang
 * mengambil karena layarnya bilang "selesai" adalah kegagalan yang nyata.
 */
class TahapPaket
{
    public const DITERIMA = 'diterima';

    public const DIKALIBRASI = 'dikalibrasi';

    public const PERLU_DIULANG = 'perlu_diulang';

    public const MENUNGGU_PEMERIKSAAN = 'menunggu_pemeriksaan';

    public const MENUNGGU_PENGESAHAN = 'menunggu_pengesahan';

    public const SERTIFIKAT_TERBIT = 'sertifikat_terbit';

    public const SIAP_DIAMBIL = 'siap_diambil';

    public const DISERAHKAN = 'diserahkan';

    /**
     * Dari paling awal ke paling akhir.
     *
     * `PERLU_DIULANG` diletakkan sesudah `DIKALIBRASI` dan bukan di ujung: dari
     * sisi pelanggan itu kemunduran, bukan kegagalan akhir — alatnya masih di
     * lab dan masih dikerjakan.
     *
     * @var list<string>
     */
    public const URUTAN = [
        self::DITERIMA,
        self::DIKALIBRASI,
        self::PERLU_DIULANG,
        self::MENUNGGU_PEMERIKSAAN,
        self::MENUNGGU_PENGESAHAN,
        self::SERTIFIKAT_TERBIT,
        self::SIAP_DIAMBIL,
        self::DISERAHKAN,
    ];

    /**
     * Label yang dibaca PELANGGAN.
     *
     * Beda dari label internal, dan bedanya disengaja. Pelanggan tidak perlu tahu
     * lab ini punya dua lapis persetujuan — yang dia perlu tahu alatnya sedang
     * diperiksa dan kapan bisa diambil. Istilah internal yang bocor ke layar
     * pelanggan memunculkan pertanyaan yang tidak ada gunanya dijawab
     * ("pengesahan apa? kok belum disahkan?").
     *
     * `PERLU_DIULANG` khususnya: dari sisi pelanggan itu "sedang dikerjakan
     * ulang", bukan "ditolak". Kata "ditolak" di layar pelanggan memicu telepon
     * ke lab untuk sesuatu yang normal terjadi.
     *
     * @var array<string, string>
     */
    public const LABEL_PELANGGAN = [
        self::DITERIMA => 'Alat diterima lab',
        self::DIKALIBRASI => 'Sedang dikalibrasi',
        self::PERLU_DIULANG => 'Sedang dikerjakan ulang',
        self::MENUNGGU_PEMERIKSAAN => 'Hasil sedang diperiksa',
        self::MENUNGGU_PENGESAHAN => 'Hasil sedang diperiksa',
        self::SERTIFIKAT_TERBIT => 'Sertifikat sudah terbit',
        self::SIAP_DIAMBIL => 'Siap diambil',
        self::DISERAHKAN => 'Sudah diserahkan',
    ];

    /** @var array<string, string> */
    public const LABEL_INTERNAL = [
        self::DITERIMA => 'Diterima',
        self::DIKALIBRASI => 'Dikalibrasi',
        self::PERLU_DIULANG => 'Perlu revisi',
        self::MENUNGGU_PEMERIKSAAN => 'Menunggu pemeriksaan admin',
        self::MENUNGGU_PENGESAHAN => 'Menunggu pengesahan',
        self::SERTIFIKAT_TERBIT => 'Sertifikat terbit',
        self::SIAP_DIAMBIL => 'Siap diambil',
        self::DISERAHKAN => 'Diserahkan',
    ];

    /**
     * Tahap satu alat.
     *
     * Urutan pemeriksaannya dari BELAKANG ke depan, dan itu bukan gaya: tahap
     * fisik yang sudah dicatat petugas selalu menang atas tahap yang diturunkan
     * dari data. Alat yang sudah diserahkan ke pelanggan tetap "diserahkan"
     * walau sesinya kemudian direvisi — karena alatnya memang sudah tidak ada
     * di lab, dan itu fakta yang tidak dibatalkan oleh revisi sertifikat.
     *
     * @param  OrderItem  $item  butuh relasi `sesiTerakhir` & `sesiTerakhir.certificate`
     *                           ter-load; tanpa itu jadi N+1
     */
    public function untukItem(OrderItem $item): string
    {
        if ($item->tahap_fisik !== null) {
            return $item->tahap_fisik;
        }

        $sesi = $item->sesiTerakhir;

        if ($sesi === null) {
            // Alat sudah masuk paket tapi belum ada sesi kalibrasinya sama sekali.
            return self::DITERIMA;
        }

        // Sertifikat dulu, sebelum status sesi. Sesi `disetujui` yang sudah punya
        // sertifikat terbit itu tahap yang berbeda dari sesi `disetujui` yang
        // sertifikatnya masih dicetak — dan bedanya persis hal yang ditunggu
        // pelanggan.
        if ($sesi->certificate?->status === Certificate::STATUS_TERBIT) {
            return self::SERTIFIKAT_TERBIT;
        }

        return match ($sesi->status) {
            CalibrationSession::STATUS_DRAFT => self::DIKALIBRASI,
            CalibrationSession::STATUS_PERLU_REVISI => self::PERLU_DIULANG,
            CalibrationSession::STATUS_MENUNGGU_APPROVAL => self::MENUNGGU_PEMERIKSAAN,
            // Konstanta dari slice A. Kalau slice A belum dikerjakan, cabang ini
            // tidak pernah tercapai dan tidak mengganggu apa pun.
            CalibrationSession::STATUS_MENUNGGU_PENGESAHAN => self::MENUNGGU_PENGESAHAN,
            // `disetujui` tanpa sertifikat terbit = PDF-nya masih dicetak, atau
            // penerbitannya gagal dan menunggu retry. Dari sisi pelanggan
            // dua-duanya "masih diperiksa" — dia tidak perlu tahu antrean job
            // lab ini sedang macet.
            CalibrationSession::STATUS_DISETUJUI => self::MENUNGGU_PENGESAHAN,
            default => self::DITERIMA,
        };
    }

    /**
     * Tahap satu PAKET = tahap alat yang paling tertinggal.
     *
     * Bukan rata-rata, bukan yang terbanyak, bukan yang terdepan. Paket dengan
     * 11 alat selesai dan 1 masih dikalibrasi **belum selesai**, dan pelanggan
     * yang berangkat mengambil karena layarnya bilang "siap diambil" sudah
     * kehilangan satu perjalanan.
     *
     * @param  iterable<OrderItem>  $item
     */
    public function untukPaket(iterable $item): string
    {
        $paling = null;

        foreach ($item as $satu) {
            $posisi = array_search($this->untukItem($satu), self::URUTAN, true);

            if ($posisi === false) {
                continue;
            }

            $paling = $paling === null ? $posisi : min($paling, $posisi);
        }

        // Paket tanpa item sama sekali: barang sudah didaftarkan tapi isinya
        // belum diinput. `DITERIMA` jujur; `SELESAI` akan bohong.
        return self::URUTAN[$paling ?? 0];
    }

    /**
     * Garis waktu untuk UI: tiap tahap + apakah sudah dilewati.
     *
     * Dibentuk di server, bukan di mobile. Kalau mobile yang menyusunnya, dua
     * aplikasi (apk lab & apk pelanggan) punya dua salinan urutan tahap yang
     * bisa berbeda versi — dan garis waktu yang beda antar aplikasi untuk paket
     * yang sama adalah bug yang sangat sulit dipercaya waktu dilaporkan.
     *
     * @return list<array{kode: string, label: string, lewat: bool, sekarang: bool}>
     */
    public function garisWaktu(string $tahapSekarang, bool $untukPelanggan = false): array
    {
        // Pelanggan tidak melihat dua lapis persetujuan lab: "diperiksa admin"
        // dan "menunggu pengesahan" di matanya satu langkah yang sama ("Hasil
        // sedang diperiksa"). Tanpa penggabungan ini garis waktunya punya dua
        // langkah berlabel identik, dan yang kedua kebaca seperti pengulangan.
        if ($untukPelanggan && $tahapSekarang === self::MENUNGGU_PENGESAHAN) {
            $tahapSekarang = self::MENUNGGU_PEMERIKSAAN;
        }

        $posisiSekarang = array_search($tahapSekarang, self::URUTAN, true) ?: 0;
        $label = $untukPelanggan ? self::LABEL_PELANGGAN : self::LABEL_INTERNAL;
        $hasil = [];

        foreach (self::URUTAN as $posisi => $kode) {
            if ($untukPelanggan && $kode === self::MENUNGGU_PENGESAHAN) {
                continue;
            }

            // `PERLU_DIULANG` disembunyikan dari garis waktu kecuali sedang
            // terjadi. Menampilkannya sebagai langkah tetap membuat setiap paket
            // terlihat seperti punya tahap "gagal" yang menunggu — padahal
            // sebagian besar paket tidak pernah melewatinya.
            if ($kode === self::PERLU_DIULANG && $tahapSekarang !== self::PERLU_DIULANG) {
                continue;
            }

            $hasil[] = [
                'kode' => $kode,
                'label' => $label[$kode],
                'lewat' => $posisi < $posisiSekarang,
                'sekarang' => $posisi === $posisiSekarang,
            ];
        }

        return $hasil;
    }
}
