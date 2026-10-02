<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\User;

/**
 * Pemisahan wewenang: yang ikut mengisi data sesi ≠ yang menyetujui atau
 * mengesahkannya.
 *
 * ISO/IEC 17025:2017 tidak menuliskan "orang A tidak boleh jadi orang B" dengan
 * kalimat itu, tapi §6.2 (personel diberi wewenang khusus untuk meninjau dan
 * mengesahkan hasil) dan §7.8.2 (laporan disahkan oleh yang berwenang) cuma
 * berarti sesuatu kalau peninjaunya orang lain.
 *
 * ## Memblokir, tanpa pengecualian
 *
 * Keputusan K-30-03 (pemilik proyek, 1 Okt 2026): **blokir, berlaku untuk
 * semua peran termasuk super admin, tanpa pengecualian.** Mode "peringatan
 * yang boleh diakui lalu dilanjut" (keputusan 26 Sep) dan sakelar
 * `PEMISAHAN_WEWENANG_MEMBLOKIR` dicabut bersama keputusan itu. Sakelar env
 * sengaja tidak dipertahankan: `render.yaml` memakunya "false", jadi keputusan
 * yang bergantung pada env bisa diam-diam tidak berlaku di produksi.
 *
 * ## Siapa yang dianggap "ikut mengisi"
 *
 * - pengisi lembar kerja (`teknisi_id`);
 * - admin yang MENGOREKSI pembacaannya — baris `audit_logs` berawalan
 *   `AuditLog::CATATAN_KOREKSI_PEMBACAAN`, ditulis `catatKoreksiPembacaan()`;
 * - yang MENGONFIRMASI hasil pindai OCR (`raw_measurements.verified_by`).
 *
 * Mengisi kolom administratif (nomor order, metode, berlaku sampai) TIDAK
 * dihitung: itu memang tugas pemeriksa, bukan mengubah angka orang lain.
 *
 * ## Yang SENGAJA tidak diperiksa di sini
 *
 * "Penyetujunya admin / pengesahnya super admin atau bukan" bukan urusan kelas
 * ini — itu dijaga middleware `role:` di rutenya. Menaruhnya di sini berarti ada
 * dua tempat yang menjawab pertanyaan izin yang sama, dan yang satu bisa basi
 * tanpa ada yang tahu (alasan yang sama kenapa `MatriksIzin` menurunkan
 * jawabannya dari rute, bukan dari daftar boolean).
 */
class PemisahanWewenang
{
    public const KODE_PENYETUJU_SAMA_DENGAN_TEKNISI = 'penyetuju_sama_dengan_teknisi';

    public const KODE_PENYETUJU_MENGOREKSI = 'penyetuju_mengoreksi_pembacaan';

    public const KODE_PENYETUJU_MEMVERIFIKASI_OCR = 'penyetuju_mengonfirmasi_pindai';

    public const KODE_PENGESAH_SAMA_DENGAN_TEKNISI = 'pengesah_sama_dengan_teknisi';

    public const KODE_PENGESAH_SAMA_DENGAN_PEMERIKSA = 'pengesah_sama_dengan_pemeriksa';

    public const KODE_PENGESAH_SAMA_DENGAN_PENGAJU = 'pengesah_sama_dengan_pengaju';

    public const KODE_PENGESAH_MENGOREKSI = 'pengesah_mengoreksi_pembacaan';

    public const KODE_PENGESAH_MEMVERIFIKASI_OCR = 'pengesah_mengonfirmasi_pindai';

    public const KODE_PENANDATANGAN_SAMA_DENGAN_TEKNISI = 'penandatangan_sama_dengan_teknisi';

    /**
     * Periksa satu rencana PERSETUJUAN sesi (API `approve()` dan panel).
     *
     * @return array{
     *     bersih: bool,
     *     memblokir: bool,
     *     temuan: list<array{kode: string, pesan: string}>
     * }
     */
    public function periksaPersetujuan(CalibrationSession $sesi, User $penyetuju): array
    {
        $temuan = [];

        if ($this->sama($sesi->teknisi_id, $penyetuju->id)) {
            $temuan[] = [
                'kode' => self::KODE_PENYETUJU_SAMA_DENGAN_TEKNISI,
                'pesan' => 'Kamu yang mengisi lembar kerja ini, jadi tidak bisa menyetujuinya sendiri. '
                    .'Minta Master Data lain.',
            ];
        }

        if ($this->pernahMengoreksi($sesi, $penyetuju)) {
            $temuan[] = [
                'kode' => self::KODE_PENYETUJU_MENGOREKSI,
                'pesan' => 'Kamu yang mengoreksi angka pembacaan sesi ini, jadi pemeriksaannya harus '
                    .'oleh orang lain.',
            ];
        }

        if ($this->pernahMengonfirmasiPindai($sesi, $penyetuju)) {
            $temuan[] = [
                'kode' => self::KODE_PENYETUJU_MEMVERIFIKASI_OCR,
                'pesan' => 'Kamu yang mengonfirmasi hasil pindai sesi ini, jadi pemeriksaannya harus '
                    .'oleh orang lain.',
            ];
        }

        return $this->hasil($temuan);
    }

    /**
     * Periksa satu rencana PENGESAHAN (`PengesahanController::sahkan()`).
     *
     * @return array{
     *     bersih: bool,
     *     memblokir: bool,
     *     temuan: list<array{kode: string, pesan: string}>
     * }
     */
    public function periksa(
        CalibrationSession $sesi,
        User $pengesah,
        ?int $penandatanganUserId = null,
    ): array {
        $temuan = [];

        if ($this->sama($sesi->teknisi_id, $pengesah->id)) {
            $temuan[] = [
                'kode' => self::KODE_PENGESAH_SAMA_DENGAN_TEKNISI,
                'pesan' => 'Kamu yang mengisi lembar kerja ini, jadi tidak bisa mengesahkannya sendiri.',
            ];
        }

        if ($this->sama($sesi->reviewed_by, $pengesah->id)) {
            $temuan[] = [
                'kode' => self::KODE_PENGESAH_SAMA_DENGAN_PEMERIKSA,
                'pesan' => 'Pemeriksa dan pengesah lembar kerja ini orang yang sama. '
                    .'Pemeriksaan kedua jadi kehilangan gunanya.',
            ];
        }

        // Dipisah dari pemeriksa: sejak gerbang pengesahan ada, "yang memeriksa"
        // dan "yang mengajukan terbit" bisa beda orang kalau adminnya lebih dari
        // satu. Dua-duanya tetap harus beda dari pengesah.
        if (
            $this->sama($sesi->diajukan_oleh, $pengesah->id)
            && ! $this->sama($sesi->diajukan_oleh, $sesi->reviewed_by)
        ) {
            $temuan[] = [
                'kode' => self::KODE_PENGESAH_SAMA_DENGAN_PENGAJU,
                'pesan' => 'Kamu yang mengajukan sertifikat ini untuk diterbitkan, jadi tidak bisa '
                    .'mengesahkannya sendiri.',
            ];
        }

        if ($this->pernahMengoreksi($sesi, $pengesah)) {
            $temuan[] = [
                'kode' => self::KODE_PENGESAH_MENGOREKSI,
                'pesan' => 'Kamu yang mengoreksi angka pembacaan sesi ini, jadi tidak bisa mengesahkannya.',
            ];
        }

        if ($this->pernahMengonfirmasiPindai($sesi, $pengesah)) {
            $temuan[] = [
                'kode' => self::KODE_PENGESAH_MEMVERIFIKASI_OCR,
                'pesan' => 'Kamu yang mengonfirmasi hasil pindai sesi ini, jadi tidak bisa mengesahkannya.',
            ];
        }

        // Ini yang paling kelihatan di luar: nama teknisi terpampang di kotak
        // tanda tangan dokumen yang seharusnya disahkan atasannya. Auditor
        // membacanya dari kertas, tanpa perlu membuka sistem.
        if ($this->sama($penandatanganUserId, $sesi->teknisi_id)) {
            $temuan[] = [
                'kode' => self::KODE_PENANDATANGAN_SAMA_DENGAN_TEKNISI,
                'pesan' => 'Nama yang bakal tercetak di kotak tanda tangan itu teknisi yang '
                    .'mengerjakan alatnya sendiri. Pilih penandatangan lain.',
            ];
        }

        return $this->hasil($temuan);
    }

    /**
     * @param  list<array{kode: string, pesan: string}>  $temuan
     * @return array{bersih: bool, memblokir: bool, temuan: list<array{kode: string, pesan: string}>}
     */
    private function hasil(array $temuan): array
    {
        // `memblokir` tetap dikirim supaya bentuk respons yang dibaca aplikasi
        // tidak berubah; sejak K-30-03 nilainya selalu sama dengan "ada temuan".
        return [
            'bersih' => $temuan === [],
            'memblokir' => $temuan !== [],
            'temuan' => $temuan,
        ];
    }

    /**
     * Dibandingkan sebagai int: kolom id tanpa cast bisa pulang sebagai string
     * di satu driver dan int di driver lain, dan `===` di antara keduanya
     * diam-diam selalu `false` — penjagaannya lolos tanpa error.
     */
    private function sama(int|string|null $a, int|string|null $b): bool
    {
        return $a !== null && $b !== null && (int) $a === (int) $b;
    }

    private function pernahMengoreksi(CalibrationSession $sesi, User $orang): bool
    {
        return AuditLog::query()
            ->where('entity_type', $sesi->getTable())
            ->where('entity_id', $sesi->getKey())
            ->where('changed_by', $orang->getKey())
            ->where('note', 'like', AuditLog::CATATAN_KOREKSI_PEMBACAAN.'%')
            ->exists();
    }

    private function pernahMengonfirmasiPindai(CalibrationSession $sesi, User $orang): bool
    {
        return $sesi->rawMeasurements()->where('verified_by', $orang->getKey())->exists();
    }
}
