<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\User;

/**
 * Pemisahan wewenang: yang mengisi angka ≠ yang mengesahkan sertifikat.
 *
 * ISO/IEC 17025:2017 nggak menuliskan "orang A tidak boleh jadi orang B" dengan
 * kalimat itu, tapi §6.2 (personel diberi wewenang khusus untuk meninjau dan
 * mengesahkan hasil) dan §7.8.2 (laporan disahkan oleh yang berwenang) cuma
 * berarti sesuatu kalau peninjaunya orang lain. Lab kecil sering melanggarnya
 * bukan karena curang — karena hari itu cuma dua orang yang masuk.
 *
 * ## Kenapa MEMPERINGATKAN, bukan MEMBLOKIR
 *
 * Keputusan 26 Sep: "Awalnya sebagai peringatan mencolok, belum memblokir."
 * Alasannya praktis, dan sebaiknya nggak diam-diam diubah nanti:
 *
 * - PT SIDIK hari ini punya satu super admin. Kalau dia juga yang kadang
 *   mengisi lembar kerja, memblokir berarti sertifikat berhenti terbit sama
 *   sekali — dan yang terjadi berikutnya bukan kepatuhan, tapi orang saling
 *   pinjam akun. Itu jauh lebih buruk daripada satu baris peringatan, karena
 *   jejak auditnya jadi bohong.
 * - Peringatan yang tercatat di `audit_logs` sudah memberi auditor hal yang dia
 *   cari: buktinya sistem tahu, dan siapa yang tetap melanjutkan.
 *
 * Begitu lab punya dua pengesah, kunci `kalibrasi.pemisahan_wewenang_memblokir`
 * di `config/kalibrasi.php` dinaikkan ke `true` dan temuan yang sama berubah
 * jadi 422. Tidak ada kode yang perlu diubah — cuma sakelarnya. Test
 * `GerbangPengesahanTest::test_pemisahan_wewenang_memblokir_kalau_sakelarnya_nyala`
 * yang menjaga janji itu.
 *
 * ## Yang SENGAJA tidak diperiksa di sini
 *
 * "Pengesahnya super admin atau bukan" bukan urusan kelas ini — itu dijaga
 * middleware `role:super_admin` di rutenya. Menaruhnya di sini berarti ada dua
 * tempat yang menjawab pertanyaan izin yang sama, dan yang satu bisa basi tanpa
 * ada yang tahu (alasan yang sama kenapa `MatriksIzin` menurunkan jawabannya
 * dari rute, bukan dari daftar boolean).
 */
class PemisahanWewenang
{
    public const KODE_PENGESAH_SAMA_DENGAN_TEKNISI = 'pengesah_sama_dengan_teknisi';

    public const KODE_PENGESAH_SAMA_DENGAN_PEMERIKSA = 'pengesah_sama_dengan_pemeriksa';

    public const KODE_PENGESAH_SAMA_DENGAN_PENGAJU = 'pengesah_sama_dengan_pengaju';

    public const KODE_PENANDATANGAN_SAMA_DENGAN_TEKNISI = 'penandatangan_sama_dengan_teknisi';

    /**
     * Periksa satu rencana pengesahan.
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

        if ($sesi->teknisi_id !== null && $sesi->teknisi_id === $pengesah->id) {
            $temuan[] = [
                'kode' => self::KODE_PENGESAH_SAMA_DENGAN_TEKNISI,
                'pesan' => 'Kamu yang mengisi lembar kerja ini, dan kamu juga yang mengesahkannya. '
                    .'Idealnya dua orang berbeda (ISO/IEC 17025 §6.2). Langkah ini tetap dicatat '
                    .'lengkap di riwayat.',
            ];
        }

        if ($sesi->reviewed_by !== null && $sesi->reviewed_by === $pengesah->id) {
            $temuan[] = [
                'kode' => self::KODE_PENGESAH_SAMA_DENGAN_PEMERIKSA,
                'pesan' => 'Pemeriksa dan pengesah lembar kerja ini orang yang sama. '
                    .'Pemeriksaan kedua jadi kehilangan gunanya.',
            ];
        }

        // Dipisah dari pemeriksa: sejak gerbang ini ada, "yang memeriksa" dan
        // "yang mengajukan terbit" bisa beda orang kalau adminnya lebih dari
        // satu. Dua-duanya tetap harus beda dari pengesah.
        if (
            $sesi->diajukan_oleh !== null
            && $sesi->diajukan_oleh === $pengesah->id
            && $sesi->diajukan_oleh !== $sesi->reviewed_by
        ) {
            $temuan[] = [
                'kode' => self::KODE_PENGESAH_SAMA_DENGAN_PENGAJU,
                'pesan' => 'Kamu yang mengajukan sertifikat ini untuk diterbitkan, dan kamu juga '
                    .'yang mengesahkannya.',
            ];
        }

        // Ini yang paling kelihatan di luar: nama teknisi terpampang di kotak
        // tanda tangan dokumen yang seharusnya disahkan atasannya. Auditor
        // membacanya dari kertas, tanpa perlu membuka sistem.
        if ($penandatanganUserId !== null && $penandatanganUserId === $sesi->teknisi_id) {
            $temuan[] = [
                'kode' => self::KODE_PENANDATANGAN_SAMA_DENGAN_TEKNISI,
                'pesan' => 'Nama yang bakal tercetak di kotak tanda tangan itu teknisi yang '
                    .'mengerjakan alatnya sendiri. Pilih penandatangan lain.',
            ];
        }

        return [
            'bersih' => $temuan === [],
            'memblokir' => $temuan !== [] && self::memblokir(),
            'temuan' => $temuan,
        ];
    }

    /**
     * Rangkuman satu baris untuk `audit_logs.note`.
     *
     * Kenapa ada: baris audit yang cuma bilang "disahkan" nggak menjawab
     * pertanyaan auditor berikutnya ("sistemnya tahu nggak kalau orangnya
     * sama?"). Kode temuannya ikut ditulis supaya bisa dicari pakai `LIKE`
     * tanpa perlu membongkar JSON.
     *
     * @param  array{temuan: list<array{kode: string, pesan: string}>}  $hasil
     */
    public function ringkasUntukAudit(array $hasil): ?string
    {
        if ($hasil['temuan'] === []) {
            return null;
        }

        $kode = implode(', ', array_column($hasil['temuan'], 'kode'));

        return 'Pemisahan wewenang dilanggar dan tetap dilanjutkan: '.$kode;
    }

    public static function memblokir(): bool
    {
        return (bool) config('kalibrasi.pemisahan_wewenang_memblokir', false);
    }
}
