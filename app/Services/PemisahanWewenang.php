<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\PaketAcuanVersi;
use App\Models\User;

/**
 * Pemisahan wewenang: yang MENGISI lembar sesi ≠ yang menyetujuinya.
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
 * ## Siapa yang dianggap "ikut mengisi" (diubah 15 Okt 2026)
 *
 * - pengisi lembar kerja (`teknisi_id`) — TETAP tidak boleh menyetujui
 *   lembarnya sendiri (cabang `penyetuju_sama_dengan_teknisi`);
 * - yang MENGONFIRMASI hasil pindai OCR (`raw_measurements.verified_by`).
 *
 * Cabang "admin yang MENGOREKSI pembacaan" DICABUT (K-50-02, pemilik proyek 15
 * Okt 2026): sejak Tolak dicabut (K-50-01) Master Data mengoreksi lembar teknisi
 * sendiri lalu menyetujuinya — koreksinya tercatat lama → baru + alasan
 * (`audit_logs`, `input_asli`, `GET /calibrations/{c}/bandingkan`), jadi
 * independensi review dijaga jejak, bukan larangan. Keputusan pemilik yang
 * melonggarkan independensi ISO/IEC 17025 §7.8; dicatat di AGENTS.md.
 * Pengesahan (`sahkan`) sudah tidak ada (K-50-07), jadi `periksa()` dicabut.
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

    public const KODE_PENYETUJU_MEMVERIFIKASI_OCR = 'penyetuju_mengonfirmasi_pindai';

    /** 03-SDD §3 — kode error pengesahan versi data acuan (BR-08). */
    public const KODE_PENGESAH_IKUT_MENYUNTING = 'pengesah_ikut_menyunting';

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
     * Periksa rencana PENGESAHAN versi data acuan (Studio Data Acuan BR-08,
     * ADR-05): pengesah ≠ pembuat draf, penyunting sel, pengaju, atau pengimpor
     * workbook master sumber draf. Tanpa pengecualian, termasuk super admin.
     *
     * @param  list<int>  $penyunting  user_id di `paket_acuan_suntingan` versi ini
     * @param  ?int  $pengimpor  `workbook_master.diimpor_oleh` sumber draf
     * @return array{bersih: bool, memblokir: bool, temuan: list<array{kode: string, pesan: string}>}
     */
    public function periksaPengesahanVersiAcuan(PaketAcuanVersi $versi, User $pengesah, array $penyunting, ?int $pengimpor): array
    {
        $peran = [];

        if ($this->sama($versi->dibuat_oleh, $pengesah->id)) {
            $peran[] = 'membuat draf';
        }

        if (in_array((int) $pengesah->id, array_map('intval', $penyunting), true)) {
            $peran[] = 'menyunting isinya';
        }

        if ($this->sama($versi->diajukan_oleh, $pengesah->id)) {
            $peran[] = 'mengajukannya';
        }

        if ($this->sama($pengimpor, $pengesah->id)) {
            $peran[] = 'mengimpor workbook master sumbernya';
        }

        return $this->hasil($peran === [] ? [] : [[
            'kode' => self::KODE_PENGESAH_IKUT_MENYUNTING,
            'pesan' => 'Kamu '.implode(', ', $peran).' untuk versi ini, jadi pengesahannya harus oleh '
                .'super admin lain (BR-08).',
        ]]);
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

    private function pernahMengonfirmasiPindai(CalibrationSession $sesi, User $orang): bool
    {
        return $sesi->rawMeasurements()->where('verified_by', $orang->getKey())->exists();
    }
}
