<?php

namespace App\Services\Vision;

use App\Services\Ocr\NormalisasiAngka;
use App\Services\Ocr\ValidasiSel;

/**
 * Satu sel hasil Google menjadi bentuk jawaban per sel OCR awan, dipakai `OcrSelUnggahController`
 * (`POST /api/ocr/sel-awan`).
 *
 * Bentuk jawabannya SAMA dengan jalur hasil pindai (`POST /api/worksheet-scans/{id}/ocr-awan`,
 * `OcrSelAwanController::susunHasil`): `kunci, teks_awan, keyakinan, status, teks_normal, nilai_awan,
 * dinormalkan, alasan`. Logikanya disalin, bukan dipindah, karena jalur 22A tidak boleh berubah dalam
 * pekerjaan ini. Utang teknis: bila salah satu berubah, ubah keduanya, atau pindahkan 22A ke kelas ini
 * sebagai pekerjaan tersendiri. `OcrSelUnggahTest::test_bentuk_jawaban_per_sel_sama_dengan_jalur_hasil_pindai`
 * menjaga agar keduanya tidak melenceng diam-diam.
 *
 * Aturannya (keputusan pemilik, `AGENTS.md` §OCR awan butir 5 dan §Aturan yang Lahir dari Kesalahan Nyata):
 *  - `teks_awan` selalu teks mentah dari Google, tidak pernah diubah;
 *  - sel yang Google tidak melihat tulisannya tetap KOSONG, tidak diisi angka karangan;
 *  - tulisan tangan tidak pernah hijau: status tertinggi KUNING;
 *  - teks yang tidak lolos `NormalisasiAngka` tetap dikirim mentah dan berstatus merah.
 */
final class SusunHasilSelAwan
{
    /** Hasil tulisan tangan tidak pernah hijau (`config/ocr.php` `tulisan_tangan.status_maks`). */
    private const STATUS_TERTINGGI = ValidasiSel::KUNING;

    public function __construct(private readonly NormalisasiAngka $normalisasi) {}

    /**
     * @param  array{ok: bool, teks: string, keyakinan: ?float, jumlah_kata: int}|null  $g
     * @return array<string, mixed>
     */
    public function susun(string $kunci, ?array $g): array
    {
        $dasar = [
            'kunci' => $kunci,
            'teks_awan' => null,
            'keyakinan' => null,
            'status' => ValidasiSel::MERAH,
            'teks_normal' => null,
            'nilai_awan' => null,
            'dinormalkan' => false,
            'alasan' => [],
        ];

        if ($g === null || ! $g['ok']) {
            // Google menolak gambar sel ini (panggilan lain di batch yang sama bisa berhasil).
            return [...$dasar, 'alasan' => ['google_gagal_sel']];
        }

        if ($g['jumlah_kata'] === 0 || trim($g['teks']) === '') {
            // Google tidak melihat tulisan: sel tetap kosong, bukan angka karangan.
            return [...$dasar, 'teks_awan' => '', 'status' => ValidasiSel::KOSONG];
        }

        $norm = $this->normalisasi->proses($g['teks']);
        $dasar['teks_awan'] = $g['teks'];
        $dasar['keyakinan'] = $g['keyakinan'];

        if (! $norm['ok']) {
            return [...$dasar, 'alasan' => $norm['alasan']];
        }

        $dasar['teks_normal'] = $norm['teks_normal'];
        $dasar['nilai_awan'] = $norm['nilai'];
        // Bentuk angkanya (koma desimal, spasi tepi dipangkas) bisa beda dari teks Google. Klien
        // wajib menampilkan keduanya bila `dinormalkan` true; tidak ada yang ditukar diam-diam.
        $dasar['dinormalkan'] = $norm['teks_normal'] !== $g['teks'];

        if ($g['keyakinan'] === null) {
            return [...$dasar, 'alasan' => ['keyakinan_tidak_ada']];
        }

        if ($g['keyakinan'] < (float) config('ocr.ambang.kuning', 0.60)) {
            return [...$dasar, 'alasan' => ['keyakinan_rendah']];
        }

        return [...$dasar, 'status' => self::STATUS_TERTINGGI];
    }
}
