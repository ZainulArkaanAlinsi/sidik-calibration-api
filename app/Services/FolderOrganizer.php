<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Folder;
use App\Models\FolderFile;
use Illuminate\Support\Facades\DB;

/**
 * Yang bikin struktur Folder Manager kebentuk SENDIRI (spesifikasi poin 3 & 7).
 *
 * Polanya dua tingkat dan sengaja dangkal: `PT A / 2026 / <file sertifikat>`.
 * Lebih dalam dari itu (bulan, jenis alat, nomor order) kelihatannya rapi di
 * awal, tapi begitu datanya banyak, orang malah kesasar nyari satu sertifikat.
 * Pengelompokan per PT itu yang diminta; tahun ditambahin karena tanpa itu satu
 * folder PT lama bisa nampung ratusan file dalam setahun-dua tahun.
 *
 * Dipanggil dari job penerbitan sertifikat, jadi nggak ada yang perlu bikin
 * folder manual.
 */
class FolderOrganizer
{
    /**
     * Taruh sertifikat yang baru terbit ke folder PT-nya. Aman dipanggil ulang
     * (job bisa di-retry) — folder & entri file-nya `firstOrCreate`.
     */
    public function tautkanSertifikat(Certificate $sertifikat): ?FolderFile
    {
        $pelanggan = $sertifikat->session?->equipment?->customer;

        if ($pelanggan === null) {
            // Alat tanpa pemilik nggak punya PT buat dikelompokin. Sertifikatnya
            // tetap sah & tetap bisa diunduh lewat daftar sertifikat — cuma
            // nggak nongol di Folder Manager.
            return null;
        }

        $tahun = ($sertifikat->diterbitkan_pada ?? now())->format('Y');

        return DB::transaction(function () use ($sertifikat, $pelanggan, $tahun): FolderFile {
            $folderTahun = $this->folderTahun($pelanggan, $tahun);

            return FolderFile::firstOrCreate(
                [
                    'folder_id' => $folderTahun->id,
                    'certificate_id' => $sertifikat->id,
                ],
                [
                    'organization_id' => $sertifikat->organization_id,
                    'nama' => $sertifikat->namaFile('pdf'),
                    'sumber' => FolderFile::SUMBER_SERTIFIKAT,
                    'uploaded_by' => $sertifikat->issued_by,
                ],
            );
        });
    }

    /**
     * Taruh lembar kerja yang baru dikirim teknisi ke folder PT-nya.
     *
     * Kenapa lembar kerja ikut diarsip, bukan cuma sertifikatnya: sertifikat
     * itu KESIMPULAN. Yang ditanya waktu audit justru pembacaan mentahnya —
     * siapa yang ngukur, pakai standar apa, suhu berapa. Tanpa ini, riwayat
     * kalibrasi satu alat nggak lengkap di Folder Manager.
     *
     * Aman dipanggil ulang, dan itu penting: lembar yang ditolak lalu dikirim
     * ulang manggil ini lagi. Kuncinya `calibration_session_id`, jadi revisi
     * NGGAK bikin baris kedua — satu sesi tetap satu entri, isinya ikut
     * keadaan terakhir.
     */
    public function tautkanLembarKerja(CalibrationSession $sesi): ?FolderFile
    {
        $pelanggan = $sesi->equipment?->customer;

        if ($pelanggan === null) {
            // Sama kayak sertifikat: alat tanpa pemilik nggak punya PT buat
            // dikelompokin. Sesinya tetap sah dan tetap kebuka dari Riwayat.
            return null;
        }

        // Tahunnya ikut TANGGAL KALIBRASI, bukan tanggal kirim. Kalibrasi 31
        // Desember yang lembarnya baru dikirim 2 Januari tetap masuk tahun
        // kerjanya — kalau ikut tanggal kirim, satu pekerjaan kepecah dua
        // folder tahun dan riwayat alatnya jadi bolong.
        $tahun = ($sesi->tanggal_kalibrasi ?? $sesi->created_at ?? now())->format('Y');

        return DB::transaction(function () use ($sesi, $pelanggan, $tahun): FolderFile {
            $folderTahun = $this->folderTahun($pelanggan, $tahun);

            return FolderFile::firstOrCreate(
                [
                    'folder_id' => $folderTahun->id,
                    'calibration_session_id' => $sesi->id,
                ],
                [
                    'organization_id' => $sesi->organization_id,
                    'nama' => 'Lembar Kerja '.($sesi->nomor_sesi ?? $sesi->id),
                    'sumber' => FolderFile::SUMBER_LEMBAR_KERJA,
                    'uploaded_by' => $sesi->teknisi_id,
                ],
            );
        });
    }

    /** Folder akar milik satu PT. Dibikin kalau belum ada. */
    public function folderPelanggan(Customer $pelanggan): Folder
    {
        return Folder::firstOrCreate(
            $this->kriteriaFolderAkar($pelanggan),
            [
                'nama' => $pelanggan->nama,
                'tipe' => Folder::TIPE_SISTEM,
            ],
        );
    }

    /**
     * Folder akar PT kalau UDAH ada — nggak bikin baru.
     *
     * Dipakai jalur yang nggak boleh nulis: teknisi buka `/arsip/perusahaan/{id}/folder`
     * buat PT yang dia nggak punya kerjaan di situ. Folder-nya bakal disembunyiin
     * lagi sama aturan privasi Folder Manager, jadi bikin barisnya cuma nyampah.
     */
    public function cariFolderPelanggan(Customer $pelanggan): ?Folder
    {
        return Folder::where($this->kriteriaFolderAkar($pelanggan))->first();
    }

    /**
     * Kriteria folder akar satu PT — ditulis sekali, dipakai cari & bikin.
     *
     * `parent_id => null` aman dilempar ke `where()`: Laravel nerjemahin nilai
     * null jadi `whereNull`, bukan `= null` yang nggak pernah true.
     *
     * @return array<string, mixed>
     */
    private function kriteriaFolderAkar(Customer $pelanggan): array
    {
        return [
            'organization_id' => $pelanggan->organization_id,
            'parent_id' => null,
            'customer_id' => $pelanggan->id,
        ];
    }

    private function folderTahun(Customer $pelanggan, string $tahun): Folder
    {
        $akar = $this->folderPelanggan($pelanggan);

        return Folder::firstOrCreate(
            [
                'organization_id' => $pelanggan->organization_id,
                'parent_id' => $akar->id,
                'nama' => $tahun,
            ],
            [
                'customer_id' => $pelanggan->id,
                'tipe' => Folder::TIPE_SISTEM,
            ],
        );
    }
}
