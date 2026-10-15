<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Folder;
use App\Models\FolderFile;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Yang bikin struktur Arsip kebentuk SENDIRI (spesifikasi poin 3 & 7, dirombak
 * K-50-06): `PT › Tahun › Bulan › Jenis alat › file`, mis.
 * `PT A / 2026 / 10-Oktober / Timbangan / <file>`. Folder dibuat otomatis.
 *
 * Tahun & bulan ikut `diterbitkan_pada` (sertifikat) atau `tanggal_kalibrasi`
 * (lembar kerja). Revisi `-R<n>` mendarat di folder sertifikat ASLINYA — satu
 * alat, satu tempat, walau revisinya terbit bulan lain. Menggantikan keputusan
 * "dua tingkat sengaja" yang dibatalkan pemilik 15 Okt 2026.
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

        // Sudah tertaut = jangan dipindah lagi: retry job tidak menggandakan
        // entri, dan berkas yang sengaja dipindah admin tidak ditarik balik.
        $ada = FolderFile::where('certificate_id', $sertifikat->id)->first();

        if ($ada !== null) {
            return $ada;
        }

        return DB::transaction(function () use ($sertifikat, $pelanggan): FolderFile {
            $folder = $this->folderAsal($sertifikat)
                ?? $this->folderTujuan(
                    $pelanggan,
                    $sertifikat->diterbitkan_pada ?? now(),
                    $sertifikat->session?->equipment,
                );

            return FolderFile::firstOrCreate(
                [
                    'folder_id' => $folder->id,
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
     * Folder sertifikat ASLI untuk revisi `-R<n>` (menelusuri rantai
     * `revision_of` ke atas). Null kalau bukan revisi atau aslinya belum
     * pernah diarsipkan — pemanggil jatuh ke aturan tanggal biasa.
     */
    public function folderAsal(Certificate $sertifikat): ?Folder
    {
        $induk = $sertifikat->revision_of;
        $batas = 10;

        while ($induk !== null && $batas-- > 0) {
            $berkas = FolderFile::where('certificate_id', $induk)->first();

            if ($berkas !== null) {
                return Folder::find($berkas->folder_id);
            }

            $induk = Certificate::query()->whereKey($induk)->value('revision_of');
        }

        return null;
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

        // Tahun & bulan ikut TANGGAL KALIBRASI, bukan tanggal kirim. Kalibrasi
        // 31 Desember yang lembarnya baru dikirim 2 Januari tetap masuk bulan
        // kerjanya — kalau ikut tanggal kirim, satu pekerjaan kepecah dua
        // folder dan riwayat alatnya jadi bolong.
        $ada = FolderFile::where('calibration_session_id', $sesi->id)->first();

        if ($ada !== null) {
            return $ada;
        }

        return DB::transaction(function () use ($sesi, $pelanggan): FolderFile {
            $folder = $this->folderTujuan(
                $pelanggan,
                $sesi->tanggal_kalibrasi ?? $sesi->created_at ?? now(),
                $sesi->equipment,
            );

            return FolderFile::firstOrCreate(
                [
                    'folder_id' => $folder->id,
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

    public const BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /** `10-Oktober` — nomor di depan supaya urut kronologis, bukan alfabetis. */
    public static function namaBulan(CarbonInterface $tanggal): string
    {
        return $tanggal->month.'-'.self::BULAN[$tanggal->month];
    }

    /**
     * Nama tiga tingkat di bawah folder PT: tahun, bulan, jenis alat. Murni
     * (tanpa DB) supaya `arsip:rapikan --dry-run` bisa memperlihatkan tujuan.
     *
     * @return list<string>
     */
    public static function jalurNama(CarbonInterface $tanggal, ?Equipment $alat): array
    {
        $jenis = trim(str_replace(['/', '\\'], '-', (string) ($alat?->category?->nama ?? '')));

        return [$tanggal->format('Y'), self::namaBulan($tanggal), $jenis !== '' ? $jenis : 'Lainnya'];
    }

    /** PT › Tahun › Bulan › Jenis alat, tiap tingkat dibuat kalau belum ada. */
    public function folderTujuan(Customer $pelanggan, CarbonInterface $tanggal, ?Equipment $alat): Folder
    {
        $folder = $this->folderPelanggan($pelanggan);

        foreach (self::jalurNama($tanggal, $alat) as $nama) {
            $folder = Folder::firstOrCreate(
                [
                    'organization_id' => $pelanggan->organization_id,
                    'parent_id' => $folder->id,
                    'nama' => $nama,
                ],
                [
                    'customer_id' => $pelanggan->id,
                    'tipe' => Folder::TIPE_SISTEM,
                ],
            );
        }

        return $folder;
    }
}
