<?php

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Export sertifikat ke Excel (spesifikasi poin 10 & 13).
 *
 * Isinya diambil dari `certificates.snapshot` — SUMBER YANG SAMA dengan PDF.
 * Ini yang bikin file Excel arsip nggak mungkin beda angka sama PDF yang
 * dikirim ke pelanggan, walaupun dibuatnya beda hari.
 *
 * Angka ditulis sebagai ANGKA (bukan teks berkoma) walaupun sertifikat
 * cetaknya pakai koma. File ini buat arsip & rekap — begitu kolomnya jadi
 * teks, dijumlah/difilter di Excel langsung nggak bisa, dan itu justru
 * gunanya export ini.
 */
class CertificateExcelExporter
{
    /** Abu-abu muda buat baris judul tabel — dokumen resmi, bukan poster. */
    private const ABU_HEADER = 'E7E7E7';

    /** Satu sertifikat = satu file, layoutnya persis sertifikat. */
    public function satu(Certificate $sertifikat, string $path): void
    {
        $writer = $this->writer($path, [40, 34, 22, 22]);
        $writer->getCurrentSheet()->setName('Sertifikat');

        $snapshot = $sertifikat->snapshot ?? [];

        $this->tulisJudul($writer, 'SERTIFIKAT KALIBRASI');
        $this->tulisHeader($writer, $snapshot['header'] ?? []);
        $this->kosong($writer);
        $this->tulisHasil($writer, $snapshot);
        $this->kosong($writer);
        $this->tulisStandar($writer, $snapshot['standar_digunakan'] ?? []);
        $this->kosong($writer);
        $this->tulisFooter($writer, $snapshot['footer'] ?? []);

        $writer->close();
    }

    /**
     * Rekap banyak sertifikat sekaligus (bulanan / per PT).
     *
     * Dua sheet, bukan satu sheet per sertifikat: rekap bulanan bisa ratusan
     * sertifikat, dan Excel dengan 300 sheet nggak kepakai siapa pun. Sheet
     * "Rekap" buat lihat cepat, sheet "Hasil Kalibrasi" buat data mentah
     * per titik yang bisa di-pivot.
     *
     * @param  Collection<int, Certificate>  $sertifikat
     */
    public function rekap(Collection $sertifikat, string $path): void
    {
        $writer = $this->writer($path, [22, 30, 34, 20, 18, 18, 14]);
        $writer->getCurrentSheet()->setName('Rekap');

        $writer->addRow(Row::fromValues([
            'Certificate Number', 'Owner', 'Equipment Name', 'Serial Number',
            'Calibration Date', 'Issuance Date', 'Keputusan',
        ], $this->gayaHeaderTabel()));

        foreach ($sertifikat as $s) {
            $header = $s->snapshot['header'] ?? [];

            $writer->addRow(Row::fromValues([
                $header['certificate_number'] ?? $s->nomor,
                $header['owner'] ?? '',
                $header['equipment_name'] ?? '',
                $header['serial_number'] ?? '',
                $header['calibration_date'] ?? '',
                $s->snapshot['footer']['issuance_date'] ?? $s->diterbitkan_pada?->toDateString() ?? '',
                $s->snapshot['meta']['keputusan'] ?? $s->session?->keputusan ?? '',
            ]));
        }

        $sheetHasil = $writer->addNewSheetAndMakeItCurrent();
        $sheetHasil->setName('Hasil Kalibrasi');

        $writer->addRow(Row::fromValues([
            'Certificate Number', 'Owner', 'Equipment Name', 'Serial Number',
            'Standard Value', 'Unit Under Test', 'Correction', 'U95% (±)',
        ], $this->gayaHeaderTabel()));

        foreach ($sertifikat as $s) {
            $header = $s->snapshot['header'] ?? [];

            foreach ($s->snapshot['hasil'] ?? [] as $baris) {
                $writer->addRow(Row::fromValues([
                    $header['certificate_number'] ?? $s->nomor,
                    $header['owner'] ?? '',
                    $header['equipment_name'] ?? '',
                    $header['serial_number'] ?? '',
                    $baris['standard_value'],
                    $baris['unit_under_test'],
                    $baris['correction'],
                    $baris['u95'],
                ]));
            }
        }

        $writer->close();
    }

    /** @param  list<float>  $lebarKolom */
    private function writer(string $path, array $lebarKolom): Writer
    {
        $opsi = new Options;

        foreach ($lebarKolom as $i => $lebar) {
            $opsi->setColumnWidth($lebar, $i + 1);
        }

        $writer = new Writer($opsi);
        $writer->openToFile($path);

        return $writer;
    }

    private function tulisJudul(Writer $writer, string $judul): void
    {
        $writer->addRow(Row::fromValues([$judul], (new Style)
            ->setFontBold()
            ->setFontSize(14)));
        $this->kosong($writer);
    }

    /** @param  array<string, mixed>  $header */
    private function tulisHeader(Writer $writer, array $header): void
    {
        // Urutan & label persis struktur baku di spesifikasi — jangan diacak,
        // orang lab nyocokinnya baris per baris sama PDF-nya.
        $label = [
            'certificate_number' => 'Certificate Number',
            'page' => 'Page',
            'owner' => 'Owner',
            'order_number' => 'Order Number',
            'address' => 'Address',
            'received_date' => 'Received Date',
            'equipment_name' => 'Equipment Name',
            'manufacturer' => 'Manufacturer',
            'calibration_location' => 'Calibration Location',
            'model_type' => 'Model/Type',
            'calibration_date' => 'Calibration Date',
            'serial_number' => 'Serial Number',
            'calibration_method' => 'Calibration Method',
            'capacity_graduation' => 'Capacity/Graduation',
            'env_condition' => 'Env. Condition',
            'technician_id' => 'Technician ID',
        ];

        foreach ($label as $kunci => $teks) {
            $writer->addRow(Row::fromValuesWithStyles(
                [$teks, $header[$kunci] ?? ''],
                null,
                [0 => (new Style)->setFontBold()],
            ));
        }
    }

    /** @param  array<string, mixed>  $snapshot */
    private function tulisHasil(Writer $writer, array $snapshot): void
    {
        $writer->addRow(Row::fromValues(['CALIBRATION REPORT'], (new Style)->setFontBold()));

        $writer->addRow(Row::fromValues(
            ['Standard Value', 'Unit Under Test', 'Correction', 'U95% (±)'],
            $this->gayaHeaderTabel(),
        ));

        foreach ($snapshot['hasil'] ?? [] as $baris) {
            $writer->addRow(Row::fromValues([
                $baris['standard_value'],
                $baris['unit_under_test'],
                $baris['correction'],
                $baris['u95'],
            ]));
        }

        $this->kosong($writer);

        // Dua catatan baku di bawah tabel — bagian dari dokumen, bukan hiasan.
        foreach ($snapshot['catatan'] ?? [] as $catatan) {
            $writer->addRow(Row::fromValues([$catatan], (new Style)->setFontItalic()));
        }
    }

    /** @param  list<array<string, mixed>>  $standar */
    private function tulisStandar(Writer $writer, array $standar): void
    {
        $writer->addRow(Row::fromValues(['STANDARD USED'], (new Style)->setFontBold()));

        $writer->addRow(Row::fromValues(
            ['Name', 'Merk/Type', 'Serial Number', 'Traceable to SI through'],
            $this->gayaHeaderTabel(),
        ));

        foreach ($standar as $s) {
            $writer->addRow(Row::fromValues([
                $s['name'] ?? '',
                $s['merk_type'] ?? '',
                $s['serial_number'] ?? '',
                $s['traceable_to'] ?? '',
            ]));
        }
    }

    /** @param  array<string, mixed>  $footer */
    private function tulisFooter(Writer $writer, array $footer): void
    {
        $label = [
            'issuance_date' => 'Issuance Date',
            'penandatangan' => 'Penandatangan',
            'jabatan' => 'Jabatan',
            'kode_dokumen' => 'Kode Dokumen',
        ];

        foreach ($label as $kunci => $teks) {
            $writer->addRow(Row::fromValuesWithStyles(
                [$teks, $footer[$kunci] ?? ''],
                null,
                [0 => (new Style)->setFontBold()],
            ));
        }
    }

    private function kosong(Writer $writer): void
    {
        $writer->addRow(Row::fromValues(['']));
    }

    private function gayaHeaderTabel(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setBackgroundColor(self::ABU_HEADER)
            ->setCellAlignment(CellAlignment::CENTER);
    }
}
