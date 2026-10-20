<?php

namespace App\Services;

use App\Models\Certificate;
use App\Support\Angka;
use App\Support\TataLetakSertifikat;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
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
            'Standard Value', 'Unit Under Test', 'Correction', 'U95% (±)', 'Remark',
        ], $this->gayaHeaderTabel()));

        foreach ($sertifikat as $s) {
            $header = $s->snapshot['header'] ?? [];

            foreach ($s->snapshot['hasil'] ?? [] as $baris) {
                $db = $this->desimalBaris($baris, $s->snapshot ?? []);

                $writer->addRow(Row::fromValues([
                    $header['certificate_number'] ?? $s->nomor,
                    $header['owner'] ?? '',
                    $header['equipment_name'] ?? '',
                    $header['serial_number'] ?? '',
                    $this->bulat($baris['standard_value'] ?? null, $this->desimalSel($baris, 'standar', $db)),
                    $this->bulat($baris['unit_under_test'] ?? null, $this->desimalSel($baris, 'uut', $db)),
                    $this->bulat($baris['correction'] ?? null, $this->desimalSel($baris, 'koreksi', $db)),
                    $this->bulat($baris['u95'] ?? null, $this->desimalSel($baris, 'u95', $this->desimalU95Baris($baris, $s->snapshot ?? []))),
                    // Kolomnya SELALU ada di rekap, beda dari sertifikat satuan:
                    // isinya lintas alat, dan yang punya remark cuma sebagian.
                    // Kolom yang muncul-ilang tergantung isi bikin rekap dua
                    // bulan nggak bisa ditumpuk.
                    $baris['remark'] ?? '',
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
            $nilai = (string) ($header[$kunci] ?? '');
            $gaya = [0 => (new Style)->setFontBold()];

            // Sel bertumpuk (Env. Condition T / %RH / Tekanan Udara sebaris-
            // sebaris sejak K-50-15, Capacity/Graduation bertingkat) dibungkus,
            // supaya barisnya terbaca sama dengan PDF — bukan satu baris
            // panjang dengan karakter baris baru tersembunyi.
            if (str_contains($nilai, "\n")) {
                $gaya[1] = (new Style)->setShouldWrapText()->setCellVerticalAlignment(CellVerticalAlignment::TOP);
            }

            $writer->addRow(Row::fromValuesWithStyles([$teks, $nilai], null, $gaya));
        }
    }

    /**
     * Berapa desimal baris ini ditulis — per baris dulu, baru jatuh ke desimal
     * sertifikat.
     *
     * Sebagian alat resolusinya berubah menurut rentang (Turbidimeter: 0,01 di
     * bawah 10 NTU, 0,1 di 10–100, 1 di atasnya), jadi satu angka buat seluruh
     * tabel bikin titik 100 NTU ketulis `101,00`. Aturan yang sama persis dipakai
     * PDF (`pdf.blade.php`) dan pratinjau di app.
     *
     * @param  array<string, mixed>  $baris
     * @param  array<string, mixed>  $snapshot
     */
    private function desimalBaris(array $baris, array $snapshot): int
    {
        return (int) ($baris['desimal'] ?? $snapshot['desimal'] ?? 2);
    }

    /**
     * Desimal kolom **U95%**, yang buat sebagian alat BEDA dari kolom di
     * sebelahnya.
     *
     * Master Spectrophotometer nulis `0,43 nm` (dua desimal) sementara
     * Standard/UUT/Correction di tabel yang sama cuma satu (`333,7`). PDF udah
     * lama baca `desimal_u95`; Excel belum, jadi berkas yang sama nulis `0,4`.
     * Dua angka, dua berkas, satu sertifikat terakreditasi — persis yang
     * dilarang docblock [bulat()].
     *
     * Bedanya sempat kesembunyi: waktu desimal titik spektro masih 2, `0,43`
     * kebetulan keluar bener lewat jalur yang salah. Begitu desimalnya turun ke
     * 1 (ngikut format sel master), Excel-nya langsung meleset.
     *
     * `null` = alat ini nggak nyatain apa-apa, jadi ikut desimal barisnya —
     * persis perilaku lama buat lima alat lain.
     *
     * @param  array<string, mixed>  $baris
     * @param  array<string, mixed>  $snapshot
     */
    /**
     * Desimal satu sel: format sel master baris itu (`baris.format`, K-50-15)
     * menang bila ada — `null` = General — supaya Excel tidak menulis lima
     * desimal `desimal` sementara PDF mencetak `0.000` master. Tanpa `format`:
     * aturan lama (`$bawaan`).
     *
     * @param  array<string, mixed>  $baris
     */
    private function desimalSel(array $baris, string $kolom, int $bawaan): ?int
    {
        $format = $baris['format'][$kolom] ?? null;

        return is_string($format) ? TataLetakSertifikat::desimalFormat($format) : $bawaan;
    }

    private function desimalU95Baris(array $baris, array $snapshot): int
    {
        return (int) ($baris['desimal_u95'] ?? $this->desimalBaris($baris, $snapshot));
    }

    /**
     * Bulatkan ke desimal sertifikat, tapi **tetap angka** (bukan teks).
     *
     * Dibulatkan karena Excel itu salinan dokumen yang sama: PDF nulis `1,76`,
     * jadi Excel nggak boleh nulis `1,758`. Beda angka di dua berkas buat satu
     * sertifikat terakreditasi itu temuan audit, dan pelanggan yang ngebandingin
     * dua-duanya nggak punya cara tahu mana yang resmi.
     *
     * Tetap `float` (bukan hasil `number_format`) karena Excel dipakai buat
     * ngerekap & ngitung. Jadi teks bikin kolomnya nggak bisa dijumlah, dan
     * separator koma bikin Excel-nya baca sebagai kalimat.
     */
    private function bulat(mixed $nilai, ?int $desimal): ?float
    {
        if ($nilai === null) {
            return null;
        }

        // `null` = format General: angka apa adanya, derau biner dipangkas.
        if ($desimal === null) {
            return round((float) $nilai, 10);
        }

        // Pembulatan TAMPILAN Excel, sama dengan PDF (`Angka::hasil`) — beda
        // jalur pembulatan antara dua berkas satu sertifikat itu temuan audit.
        $hasil = ($nilai < 0 ? -1 : 1) * (float) str_replace(',', '.', Angka::tampilanExcel((float) $nilai, $desimal));

        // `round(-0.2, 0)` di PHP balikin NEGATIF NOL, dan Excel nulisnya `-0`.
        // Nilainya sama dengan nol, tapi di kolom Correction sertifikat itu
        // kebaca kayak salah cetak — pelanggan nggak tahu itu artinya nol.
        //
        // PDF nggak kena karena `number_format()` emang buang tandanya; ini
        // cuma nyamain jalur Excel sama jalur PDF.
        return $hasil == 0.0 ? 0.0 : $hasil;
    }

    /** @param  array<string, mixed>  $snapshot */
    private function tulisHasil(Writer $writer, array $snapshot): void
    {
        $writer->addRow(Row::fromValues(['CALIBRATION REPORT'], (new Style)->setFontBold()));

        // Enclosure punya dua tabel sendiri, bukan tabel empat kolom — dan
        // `hasil`-nya sengaja kosong. Lihat `CertificateSnapshotBuilder::enclosure()`.
        if (is_array($snapshot['enclosure'] ?? null)) {
            $this->tulisEnclosure($writer, $snapshot['enclosure'], $snapshot['catatan'] ?? []);

            return;
        }

        // Bentuk master (K-50-15): tabel Excel = tabel PDF — urutan & judul
        // kolom (`tata_letak.kolom_hasil`), tanda koreksi yang sudah dibekukan
        // di snapshot, dan ANGKA TERCETAK (format sel). Snapshot terbit tanpa
        // `tata_letak` tetap lewat jalur lama di bawah, byte demi byte.
        if (isset($snapshot['tata_letak'])) {
            $this->tulisHasilMaster($writer, $snapshot);

            return;
        }

        $adaRemark = collect($snapshot['hasil'] ?? [])
            ->contains(fn ($b) => filled($b['remark'] ?? null));

        // Sama aturannya kayak PDF: kolom R² cuma ada kalau alatnya emang
        // nyetak kolom itu DAN definisinya udah diputus lab. Selama belum,
        // nggak ada kolom yang muncul kosong di Excel sementara PDF-nya nggak
        // punya kolom sama sekali — dua jalur ini wajib nyetak dokumen yang
        // sama bentuknya.
        $adaR2 = collect($snapshot['hasil'] ?? [])
            ->contains(fn ($b) => ($b['r2'] ?? null) !== null);

        $judulStandar = (string) ($snapshot['judul_standar'] ?? 'Standard');

        $writer->addRow(Row::fromValues(
            array_merge(
                // Judul kolom ikut snapshot, sama kayak PDF: master
                // Spectrophotometer nulis `UUT`, lima alat lain `Unit Under
                // Test`. Excel itu salinan sertifikat yang sama, jadi kepala
                // kolomnya nggak boleh beda dari PDF-nya.
                //
                // Rekap lintas alat SENGAJA nggak ikut (lihat `rekap()`):
                // kolomnya mesti stabil biar rekap dua bulan bisa ditumpuk.
                // `Standard` bawaan tetap dicetak `Standard Value` seperti
                // sejak awal; judul lain dipakai apa adanya. Master Hydrometer
                // nulis `Nominal Value` — `Nominal Value Value` bukan judul
                // yang pernah ada di satu pun sertifikat.
                [$judulStandar === 'Standard' ? 'Standard Value' : $judulStandar, $snapshot['judul_uut'] ?? 'Unit Under Test', 'Correction', 'U95% (±)'],
                $adaRemark ? ['Remark'] : [],
                $adaR2 ? ['R2'] : [],
            ),
            $this->gayaHeaderTabel(),
        ));

        $remarkTerakhir = null;

        // Baris yang disembunyikan master (`dicetak: false`, K-50-15) tidak
        // ditulis — sama dengan PDF (`TataLetakSertifikat::barisDicetak`).
        foreach (TataLetakSertifikat::barisDicetak($snapshot['hasil'] ?? []) as $baris) {
            $db = $this->desimalBaris($baris, $snapshot);

            // R² itu angka SATU KELOMPOK, bukan angka per titik — ditulis sekali
            // di baris pertama kelompoknya, persis kayak PDF & master. Diulang
            // di lima baris, dia kebaca kayak lima R² yang kebetulan sama.
            $remark = $baris['remark'] ?? null;
            $awalKelompok = $remark !== $remarkTerakhir;
            $remarkTerakhir = $remark;

            $writer->addRow(Row::fromValues(array_merge([
                $this->bulat($baris['standard_value'] ?? null, $this->desimalSel($baris, 'standar', $db)),
                $this->bulat($baris['unit_under_test'] ?? null, $this->desimalSel($baris, 'uut', $db)),
                $this->bulat($baris['correction'] ?? null, $this->desimalSel($baris, 'koreksi', $db)),
                $this->bulat($baris['u95'] ?? null, $this->desimalSel($baris, 'u95', $this->desimalU95Baris($baris, $snapshot))),
            ], $adaRemark ? [$baris['remark'] ?? ''] : [], $adaR2 ? [
                // NOL desimal, sama kayak PDF & sel masternya (`0,9359`
                // diformat 0 desimal → tercetak `1`). Empat desimal bikin
                // Excel nulis `0,9359` sementara PDF-nya nulis `1` — dua
                // dokumen buat sertifikat yang sama.
                $awalKelompok && ($baris['r2'] ?? null) !== null
                    ? $this->bulat((float) $baris['r2'], 0)
                    : '',
            ] : [])));
        }

        $this->kosong($writer);

        // Dua catatan baku di bawah tabel — bagian dari dokumen, bukan hiasan.
        foreach ($snapshot['catatan'] ?? [] as $catatan) {
            $writer->addRow(Row::fromValues([$catatan], (new Style)->setFontItalic()));
        }
    }

    /**
     * Sertifikat Enclosure: A) Sebaran Suhu + B) Kinerja Enklosur, susunan
     * yang sama dengan PDF (`pdf.blade.php`, cabang `$enclosure`) dan sheet
     * `SERTIFIKAT` master. Angka dibulatkan ke desimal yang dibekukan di
     * snapshot — sama dengan yang tercetak di PDF — tapi tetap angka.
     *
     * Kalimat baku "Coverage Factor (k) = 2" TIDAK ditulis: `k` alat ini
     * dihitung dari `v_eff` (1,96 di sesi contoh), dan kalimatnya ditulis
     * sendiri di bawah tabel A — persis PDF.
     *
     * @param  array<string, mixed>  $enclosure
     * @param  list<string>  $catatan
     */
    private function tulisEnclosure(Writer $writer, array $enclosure, array $catatan): void
    {
        // Desimal per kolom, format sel master varian ini (`null` = General).
        $fmt = is_array($enclosure['desimal'] ?? null) ? $enclosure['desimal'] : [];
        $d = static fn (string $kolom): ?int => array_key_exists($kolom, $fmt) ? $fmt[$kolom] : 1;
        $posisi = array_values($enclosure['posisi'] ?? []);
        $setPoint = array_values($enclosure['set_point'] ?? []);

        $writer->addRow(Row::fromValues(['A) Sebaran Suhu'], (new Style)->setFontBold()));
        $writer->addRow(Row::fromValues(array_merge(
            ['Pembacaan Indikator Enklosur (°C)', 'Standar dan Koreksi Indikator (°C)'],
            array_map(static fn (array $p): string => 'Sensor No. '.$p['posisi'], $posisi),
            ['U95% ± (°C)'],
        ), $this->gayaHeaderTabel()));

        foreach ($setPoint as $sp) {
            $sensor = $sp['sensor'] ?? [];

            $writer->addRow(Row::fromValues(array_merge(
                [$this->bulat($sp['indikator'] ?? null, $d('indikator')), 'Terukur (°C)'],
                array_map(fn (int $i): ?float => $this->bulat($sensor[$i]['terukur'] ?? null, $d('terukur')), array_keys($posisi)),
                [$this->bulat($sp['u95'] ?? null, $d('u95'))],
            )));
            $writer->addRow(Row::fromValues(array_merge(
                ['', 'Koreksi (°C)'],
                array_map(fn (int $i): ?float => $this->bulat($sensor[$i]['koreksi'] ?? null, $d('koreksi')), array_keys($posisi)),
            )));
        }

        $k = collect($setPoint)->pluck('faktor_cakupan_k')->first(fn ($n): bool => $n !== null);

        if ($k !== null) {
            $writer->addRow(Row::fromValues([
                'The uncertainty is taken at a confidence level 95 % and coverage factor ( k ) =',
                $this->bulat($k, $d('k')),
            ], (new Style)->setFontItalic()));
        }

        $this->kosong($writer);
        $writer->addRow(Row::fromValues(['B) Kinerja Enklosur'], (new Style)->setFontBold()));
        $writer->addRow(Row::fromValues([
            'Suhu Setting (°C)',
            'Suhu yang diukur Indikator Enklosur (°C)',
            'Keseragaman (°C)',
            'Kestabilan (°C)',
            'Variasi Keseluruhan (°C)',
        ], $this->gayaHeaderTabel()));

        foreach ($setPoint as $sp) {
            $writer->addRow(Row::fromValues([
                $this->bulat($sp['set_point'] ?? null, $d('setting')),
                $this->bulat($sp['indikator'] ?? null, $d('indikator_kinerja')),
                $this->bulat($sp['keseragaman'] ?? null, $d('kinerja')),
                $this->bulat($sp['kestabilan'] ?? null, $d('kinerja')),
                $this->bulat($sp['variasi_keseluruhan'] ?? null, $d('kinerja')),
            ]));
        }

        $this->kosong($writer);

        foreach ($catatan as $baris) {
            if (str_contains((string) $baris, 'Coverage Factor')) {
                continue;
            }

            $writer->addRow(Row::fromValues([$baris], (new Style)->setFontItalic()));
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

    /**
     * Tabel hasil bentuk master: tiap sel diturunkan dari teks yang DICETAK
     * PDF (`TataLetakSertifikat::teksSel` / `teksPecah`), lalu ditulis
     * sebagai angka bila teks itu angka — jadi Excel tidak bisa beda urutan,
     * tanda, atau pembulatan dari PDF-nya (Tachometer: kolom Standard/UUT
     * ditukar & koreksi bertanda −1 di profil; tabelnya tetap menjumlah).
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function tulisHasilMaster(Writer $writer, array $snapshot): void
    {
        $tl = TataLetakSertifikat::untuk($snapshot);
        $baris = TataLetakSertifikat::barisDicetak($snapshot['hasil'] ?? []);
        $adaR2 = collect($baris)->contains(fn ($b) => ($b['r2'] ?? null) !== null);
        $kolom = TataLetakSertifikat::kolomHasil($snapshot, $tl, $adaR2);

        // PDF mencetak U95 kelompok di baris ringkas di bawah tabel bila tidak
        // menjadi kolom; tabel Excel tidak punya baris ringkas, jadi U95 tetap
        // satu kolom sesudah Correction — seperti jalur lama.
        if (! in_array('u95', array_column($kolom, 'kolom'), true)) {
            $posisi = array_search('koreksi', array_column($kolom, 'kolom'), true);
            $posisi = $posisi === false ? count($kolom) : $posisi + 1;
            array_splice($kolom, $posisi, 0, [['kolom' => 'u95', 'judul' => null, 'satuan' => null, 'gabung' => false]]);
        }
        // Remark (judul tabel kelompok di PDF) jadi kolom sebelum R², seperti
        // jalur lama — tabel Excel satu lembar, bukan per kelompok.
        if (collect($baris)->contains(fn ($b) => filled($b['remark'] ?? null))
            && ! in_array('remark', array_column($kolom, 'kolom'), true)) {
            $posisiR2 = array_search('r2', array_column($kolom, 'kolom'), true);
            array_splice($kolom, $posisiR2 === false ? count($kolom) : $posisiR2, 0, [['kolom' => 'remark', 'judul' => null, 'satuan' => null, 'gabung' => false]]);
        }
        $desimal = (int) ($snapshot['desimal'] ?? Angka::DESIMAL_DEFAULT);
        // Satu baris satuan hanya bila seluruh tabel satu satuan; tabel
        // bersatuan campuran (Spectro nm/%T, Conductivity µS/mS) tidak diberi
        // satuan tunggal yang salah.
        $satuanBaris = array_values(array_unique(array_map(static fn ($b) => $b['satuan'] ?? null, $baris)));
        $satuanTabel = count($satuanBaris) === 1
            ? ($satuanBaris[0] ?? ($tl['satuan_baris_sendiri'] ? ($snapshot['satuan_cetak'] ?? null) : null))
            : null;

        $judul = [];
        $satuan = [];

        foreach ($kolom as $k) {
            $teks = $k['kolom'] === 'u95' && $k['judul'] === null && $tl['judul_kolom_u95'] !== null
                ? $tl['judul_kolom_u95']
                : TataLetakSertifikat::judulKolom($k, $snapshot);

            foreach ($k['pecah'] ?? [null] as $p) {
                $judul[] = $p === null ? $teks : $teks.' '.$p['judul'];
                $satuan[] = $p === null ? (TataLetakSertifikat::satuanKolom($k, $satuanTabel) ?? '') : '';
            }
        }

        $writer->addRow(Row::fromValues($judul, $this->gayaHeaderTabel()));

        if (array_filter($satuan) !== []) {
            $writer->addRow(Row::fromValues($satuan));
        }

        $sebagaiAngka = static function (string $teks): float|string {
            if (! is_numeric($teks)) {
                return $teks === '—' ? '' : $teks;
            }

            // `-0.00` tercetak sebagai nol bertanda; Excel tidak punya nol
            // negatif yang kebaca, jadi ditulis 0 seperti jalur lama.
            return (float) $teks == 0.0 ? 0.0 : (float) $teks;
        };

        $teksBaris = array_map(static fn (array $b): array => collect($kolom)->mapWithKeys(fn (array $k) => [
            $k['kolom'] => isset($k['pecah'])
                ? TataLetakSertifikat::teksPecah($b, $k, $snapshot)
                : TataLetakSertifikat::teksSel($b, $k['kolom'], $snapshot, $desimal, $k),
        ])->all(), $baris);
        // Sel gabung PDF (R², U95 per rentang) ditulis SEKALI di baris
        // pertamanya, sisanya kosong — seperti sel merge master.
        $rentang = TataLetakSertifikat::rentangGabung($teksBaris, $kolom, $baris);

        foreach ($baris as $i => $b) {
            $sel = [];

            foreach ($kolom as $k) {
                $teks = $teksBaris[$i][$k['kolom']];

                if (is_array($teks)) {
                    foreach ($teks as $t) {
                        $sel[] = $sebagaiAngka($t);
                    }

                    continue;
                }

                $sel[] = ($k['gabung'] && ($rentang[$k['kolom']][$i] ?? 1) === 0) ? '' : $sebagaiAngka($teks);
            }

            $writer->addRow(Row::fromValues($sel));
        }

        $this->kosong($writer);

        foreach ($snapshot['catatan'] ?? [] as $catatan) {
            $writer->addRow(Row::fromValues([$catatan], (new Style)->setFontItalic()));
        }
    }

    private function gayaHeaderTabel(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setBackgroundColor(self::ABU_HEADER)
            ->setCellAlignment(CellAlignment::CENTER);
    }
}
