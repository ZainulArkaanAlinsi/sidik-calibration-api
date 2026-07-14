<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Datanya disalin dari lampiran akreditasi asli:
 * `Project-PT-ASMO/LK 285 IDN_PT Sidik_draft.pdf` (9 halaman, 48 alat).
 */
class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        Organization::updateOrCreate(
            ['id' => 1],
            [
                'nama' => 'PT Sistem Dirgantara Inovasi Teknologi (PT Sidik)',
                'alamat' => 'Kawasan Niaga MTC/ MIM Blok J No 25, Buahbatu, Kota Bandung, Jawa Barat',
                'telepon' => '(022)7537623',
                'email' => 'sidikkalibrasi@pt-sidik.com',
                'no_akreditasi' => 'LK-285-IDN',
                'standar_akreditasi' => 'SNI ISO/IEC 17025:2017 (ISO/IEC 17025:2017)',
                'akreditasi_mulai' => '2024-10-28',
                'akreditasi_berakhir' => '2029-10-27',
                'settings' => [
                    'prefix_sertifikat' => 'CAL',
                    'masa_berlaku_sertifikat_bulan' => 12,
                    'faktor_cakupan_default' => 2,

                    // Dua catatan kaki ini WAJIB ikut kecetak di sertifikat/lampiran.
                    // Bunyinya disalin persis dari dokumen akreditasi asli — jangan
                    // diparafrase, ini pernyataan formal ke KAN.
                    'catatan_ketidakpastian' => 'Ketidakpastian yang diperluas dinyatakan pada tingkat kepercayaan 95% dengan faktor cakupan k = 2 yang merupakan ketidakpastian terbaik yang dapat dicapai dalam layanan kalibrasi rutin dengan sumberdaya yang dimiliki laboratorium',
                    'catatan_penggandaan' => 'Lampiran sertifikat akreditasi ini tidak boleh digandakan kecuali seluruhnya, tanpa persetujuan tertulis dari pihak KAN',
                ],
            ],
        );
    }
}
