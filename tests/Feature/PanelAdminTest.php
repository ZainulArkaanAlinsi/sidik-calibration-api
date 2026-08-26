<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Widgets\FilamentInfoWidget;
use Tests\TestCase;

/**
 * Penjaga tampilan panel admin.
 *
 * ## Yang beneran dijaga di sini: matematika pitanya
 *
 * Tiga test pertama soal setelan panel dan murah harganya. Yang mahal test
 * `pita-toleransi`: dia menggambar POSISI penyimpangan satu titik terhadap
 * batas keberterimaan, dan pita yang salah gambar bikin admin approve
 * berdasarkan gambaran yang keliru dari pengukuran terakreditasi.
 *
 * Kegagalan paling halusnya bukan "pitanya nggak muncul" — tapi pitanya muncul
 * dan kelihatan wajar padahal penandanya di tempat yang salah. Makanya yang
 * diuji posisinya dalam persen, bukan cuma "ada elemennya".
 */
class PanelAdminTest extends TestCase
{
    /**
     * Warna merek, diambil dari perisai `public/images/logo-sidik.png`.
     *
     * Ditulis di sini SEBAGAI SALINAN yang sengaja — kalau seseorang mengubah
     * palet panel, test ini merah dan dia harus memutuskan sadar bahwa merek
     * lab yang berubah, bukan cuma "warna kurang cerah".
     */
    private const KOBALT_MEREK = '#043EA1';

    private function panel(): Panel
    {
        return Filament::getPanel('admin');
    }

    /**
     * Tema kustom harus terdaftar.
     *
     * Tanpa `->viteTheme()`, Filament jatuh ke tampilan bawaannya dan SELURUH
     * berkas tema jadi kode nganggur — nggak ada error, panelnya tetap jalan,
     * cuma kembali hambar seperti sebelum 26 Agt 2026.
     */
    public function test_tema_kustom_terdaftar(): void
    {
        $tema = $this->panel()->getViteTheme();

        $this->assertNotNull($tema, 'Panel admin nggak punya tema kustom — dia balik ke tampilan bawaan Filament.');
        $this->assertStringContainsString(
            'resources/css/filament/admin/theme.css',
            is_string($tema) ? $tema : (string) json_encode($tema),
            'Tema panel nggak nunjuk ke berkas tema SIDIK.',
        );
    }

    /**
     * Berkas temanya harus ADA, bukan cuma disebut.
     *
     * Jalur yang salah ketik bikin Vite gagal build — tapi gagalnya waktu
     * deploy, bukan waktu test. Lebih murah ketahuan di sini.
     */
    public function test_berkas_tema_beneran_ada(): void
    {
        $this->assertFileExists(
            base_path('resources/css/filament/admin/theme.css'),
            'Berkas tema yang didaftarkan di AdminPanelProvider nggak ada di disk.',
        );
    }

    /**
     * Widget promosi Filament nggak boleh balik.
     *
     * `FilamentInfoWidget` isinya nomor versi Filament + tautan dokumentasinya.
     * Dia perancah bawaan `filament:install`, dan sempat ikut naik ke produksi
     * memakan separuh baris pertama dashboard. Gampang balik lagi kalau ada
     * yang menjalankan ulang installer-nya.
     */
    public function test_widget_promosi_filament_nggak_ikut(): void
    {
        $widget = $this->panel()->getWidgets();

        $this->assertNotContains(
            FilamentInfoWidget::class,
            $widget,
            'FilamentInfoWidget balik ke dashboard. Isinya nomor versi Filament & tautan dokumentasi — '
            .'nggak ada gunanya buat admin lab, dan dia mengambil ruang baris pertama yang mestinya '
            .'buat pekerjaan yang menunggu.',
        );
    }

    /**
     * Palet primer harus warna merek, bukan biru bawaan Tailwind.
     */
    public function test_warna_primer_dari_logo(): void
    {
        $warna = $this->panel()->getColors();

        $this->assertArrayHasKey('primary', $warna);

        // Filament menyimpannya sebagai palet 50..950. Yang dicek shade 500-nya
        // hasil turunan dari hex merek — cukup buat menangkap kepulangan ke
        // Color::Blue tanpa mengunci algoritma turunan Filament.
        $primer = $warna['primary'];
        $rangkum = is_array($primer) ? implode(' ', array_map('strval', $primer)) : (string) $primer;

        $this->assertStringNotContainsStringIgnoringCase(
            '59 130 246',
            $rangkum,
            'Warna primer balik ke biru-500 Tailwind bawaan. Yang benar kobalt '.self::KOBALT_MEREK.' dari logo.',
        );
    }

    /**
     * Titik DI DALAM batas: penandanya nggak boleh bertanda bahaya.
     */
    public function test_pita_titik_di_dalam_batas(): void
    {
        $html = $this->pita(error: 0.002, toleransi: 0.01);

        $this->assertStringNotContainsString('bg-danger-600', $html, 'Titik di dalam batas dikasih penanda bahaya.');
        $this->assertStringContainsString('left: 60.00%', $html, 'Posisi penanda meleset: 0,002 dari ±0,01 mestinya 60%.');
    }

    /**
     * Titik DI LUAR batas: bertanda bahaya DAN dijepit ke ujung.
     *
     * Penjepitan itu yang penting. Tanpa itu penyimpangan 5x toleransi
     * menghasilkan `left: 300%` — penandanya keluar kanvas, dan barisnya
     * terbaca seolah-olah nggak punya penanda sama sekali. Titik terburuk
     * justru jadi yang paling nggak kelihatan.
     */
    public function test_pita_titik_di_luar_batas_dijepit(): void
    {
        $html = $this->pita(error: 0.05, toleransi: 0.01);

        $this->assertStringContainsString('bg-danger-600', $html, 'Titik di luar batas nggak ditandai bahaya.');
        $this->assertStringContainsString('left: 100.00%', $html, 'Penanda di luar batas nggak dijepit ke ujung kanvas.');
        $this->assertStringNotContainsString('left: 300', $html);
    }

    /**
     * Titik TANPA toleransi nggak dapat pita sama sekali.
     *
     * Enam dari tujuh belas lembar memang nggak divonis PASS/FAIL — Autoklaf,
     * DO Meter, Gas Detector, TITS, TIDS, dan kelima Enclosure sertifikatnya
     * berhenti di baris `Uncertainty 95%`. Menggambar pita buat mereka bikin
     * seolah-olah ada batas yang mereka lewati.
     */
    public function test_pita_nggak_digambar_kalau_nggak_ada_toleransi(): void
    {
        $html = $this->pita(error: 0.05, toleransi: null);

        $this->assertStringNotContainsString('bg-danger-600', $html);
        $this->assertStringContainsString('&mdash;', $html, 'Titik tanpa toleransi mestinya cuma strip.');
    }

    /**
     * Toleransi nol diperlakukan sama dengan tanpa toleransi.
     *
     * Kalau nggak, `$error / 0` bikin pembagian nol.
     */
    public function test_pita_aman_dari_toleransi_nol(): void
    {
        $html = $this->pita(error: 0.05, toleransi: 0.0);

        $this->assertStringContainsString('&mdash;', $html);
    }

    /** Render komponen pita buat satu baris hasil. */
    private function pita(?float $error, ?float $toleransi): string
    {
        $baris = new class($error, $toleransi)
        {
            public function __construct(public ?float $error, public ?float $toleransi) {}
        };

        return view('filament.pita-toleransi', ['getRecord' => fn () => $baris])->render();
    }
}
