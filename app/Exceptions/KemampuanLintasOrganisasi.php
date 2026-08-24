<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Satu baris kemampuan kalibrasi mau disimpan dengan `organization_id` yang
 * BEDA dari organisasi kategorinya.
 *
 * ## Kenapa ini dilempar, bukan dibetulin diam-diam
 *
 * Kepemilikan baris kemampuan ditulis di dua tempat:
 * `calibration_capabilities.organization_id` (dipakai panel Filament &
 * `scopeMilikOrganisasi`) dan `equipment_categories.organization_id` (dipakai
 * seluruh jalur baca API + mesin hitung). Selama dua-duanya sama, nggak ada
 * yang kelihatan. Begitu beda, yang terjadi bukan sekadar baris nyasar di
 * daftar: `GumCalculator` nyari kandidat CMC lewat `equipment_category_id`,
 * jadi angka ketidakpastian terbaik lab A kepasang sebagai LANTAI U95 di
 * sertifikat lab B — sertifikat yang ngeklaim kemampuan yang nggak pernah
 * diakreditasi buat lab itu, tanpa satu pun error di mana pun.
 *
 * Nambal otomatis (mis. nimpa `organization_id` pakai punya kategorinya) kelihatan
 * ramah tapi salah: yang lagi kejadian itu satu baris CMC PINDAH pemilik tanpa
 * ada yang minta. Yang bener adalah berhenti dan bikin pemanggilnya sadar —
 * jalur normal (panel admin & `KemampuanKalibrasiController`) nggak akan pernah
 * kena, karena dua-duanya nurunin organisasi dari kategori yang udah disaring.
 */
class KemampuanLintasOrganisasi extends RuntimeException
{
    public static function untuk(?int $organisasiBaris, ?int $organisasiKategori, ?int $kategoriId): self
    {
        return new self(sprintf(
            'Kemampuan kalibrasi nggak boleh beda organisasi dari kategorinya: baris nunjuk organisasi %s, '
            .'sementara kategori %s milik organisasi %s. Pilih kategori milik organisasi yang sama.',
            $organisasiBaris ?? 'null',
            $kategoriId ?? 'null',
            $organisasiKategori ?? 'null',
        ));
    }
}
