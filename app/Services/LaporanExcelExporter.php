<?php

namespace App\Services;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Laporan kalibrasi ke Excel (fase-2 §5).
 *
 * Barisnya datang dari `LaporanKalibrasi::baris()` — sumber yang SAMA dengan PDF
 * dan dengan laporan di layar. Yang dikerjain di sini cuma nulis, nggak ada
 * hitungan sama sekali; begitu exporter ikut ngitung, dua format bisa mulai
 * nunjukin angka beda buat periode yang sama.
 */
class LaporanExcelExporter
{
    /** Abu-abu muda buat baris judul — dokumen resmi, bukan poster. */
    private const ABU_HEADER = 'E7E7E7';

    /**
     * @param  list<string>  $judulKolom
     * @param  list<array<string, mixed>>  $baris
     * @param  array<string, mixed>  $ringkasan
     * @param  array<string, mixed>  $penyaring
     */
    public function tulis(
        string $path,
        array $judulKolom,
        array $baris,
        array $ringkasan,
        array $penyaring,
    ): void {
        $opsi = new Options;

        foreach ([18, 16, 30, 28, 20, 22, 20, 18, 12, 14, 22] as $i => $lebar) {
            $opsi->setColumnWidth($lebar, $i + 1);
        }

        $writer = new Writer($opsi);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Laporan Kalibrasi');

        $writer->addRow(Row::fromValues(['LAPORAN KALIBRASI'], (new Style)->setFontBold()->setFontSize(14)));
        $writer->addRow(Row::fromValues([]));

        // Penyaring ikut ditulis di file. Tanpa ini, file yang udah nyebar
        // nggak bisa dijelasin lagi isinya periode/pelanggan mana — dan itu
        // pertanyaan pertama asesor waktu lihat rekap.
        foreach ($this->barisPenyaring($penyaring) as $baris_) {
            $writer->addRow(Row::fromValues($baris_));
        }

        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(['Total sesi', $ringkasan['total']]));
        $writer->addRow(Row::fromValues(['PASS', $ringkasan['pass']]));
        $writer->addRow(Row::fromValues(['FAIL', $ringkasan['fail']]));
        $writer->addRow(Row::fromValues(['Belum ada keputusan', $ringkasan['belum_ada_keputusan']]));
        $writer->addRow(Row::fromValues(['Disetujui', $ringkasan['disetujui']]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues($judulKolom, (new Style)
            ->setFontBold()
            ->setBackgroundColor(self::ABU_HEADER)));

        foreach ($baris as $b) {
            // `array_values` bukan kebetulan: urutannya HARUS ngikutin
            // `judulKolom()`, dan dua-duanya dipegang LaporanKalibrasi biar
            // nggak bisa geser sendiri.
            //
            // U95 ditulis sebagai ANGKA, bukan teks berkoma — begitu jadi teks,
            // difilter/dijumlah di Excel langsung nggak bisa, dan itu justru
            // gunanya export ini.
            $writer->addRow(Row::fromValues(array_values($b)));
        }

        $writer->close();
    }

    /**
     * @param  array<string, mixed>  $penyaring
     * @return list<list<string>>
     */
    private function barisPenyaring(array $penyaring): array
    {
        $label = [
            'dari' => 'Dari tanggal',
            'sampai' => 'Sampai tanggal',
            'pelanggan' => 'Pelanggan',
            'teknisi' => 'Teknisi',
            'kategori' => 'Kategori',
            'status' => 'Status',
            'keputusan' => 'Keputusan',
        ];

        $baris = [];

        foreach ($label as $kunci => $teks) {
            $baris[] = [$teks, (string) ($penyaring[$kunci] ?? 'Semua')];
        }

        $baris[] = ['Dibuat pada', now()->toDateTimeString()];

        return $baris;
    }
}
