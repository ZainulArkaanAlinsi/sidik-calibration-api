<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Isi CSV riwayat perubahan tidak dieksekusi sebagai rumus waktu dibuka.
 *
 * ## Kenapa berkas ini ada
 *
 * `fputcsv()` menulis apa adanya, dan Excel/LibreOffice/Google Sheets
 * memperlakukan sel yang diawali `=`, `+`, `-` atau `@` sebagai **rumus**,
 * bukan teks. `teks()` di controller cuma `(string)`/`json_encode()` — tidak
 * ada satu pun pemeriksaan awalan.
 *
 * Isinya bukan data internal: `old_data`/`new_data` berasal dari kolom yang
 * boleh diisi teknisi lewat trait `Diaudit` — `Customer::nama` (masuk lewat
 * `POST /customers/cepat`), `catatan_teknisi`, `pemilik_nama`. Kolom `Pelaku`
 * pun `users.name` yang diisi sendiri waktu mendaftar.
 *
 * Ekspor XLSX di repo ini tidak punya masalah yang sama —
 * `CertificateExcelExporter` dan `LaporanExcelExporter` lewat OpenSpout dan
 * menulis TIPE SEL eksplisit. CSV ditafsir murni dari isi teksnya, jadi
 * penjagaannya harus di teksnya.
 *
 * ## Yang dijaga separuh berkas ini: JANGAN kebablasan
 *
 * Netralisasi yang terlalu rakus merusak justru alasan ekspor ini berbentuk
 * CSV. `-0,02` itu nilai koreksi yang sah dan diawali `-`; kalau ikut diberi
 * awalan, Excel membacanya sebagai teks dan kolomnya tidak bisa disaring atau
 * di-pivot lagi. Berkas ini yang dibawa asesor.
 */
class EksporAuditTidakJadiRumusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['name' => 'Budi Santoso']);
    }

    /**
     * Tanam satu baris audit `customers` dengan nilai baru apa pun, lalu tarik
     * CSV-nya utuh.
     */
    private function csv(string $nilaiBaru, ?string $namaPelaku = null): string
    {
        if ($namaPelaku !== null) {
            $this->admin->forceFill(['name' => $namaPelaku])->save();
        }

        // Ditulis langsung, bukan lewat factory: `AuditLog` sengaja tidak punya
        // factory — baris audit lahir dari trait `Diaudit`, bukan dibikin
        // orang. Yang diuji di sini pintu EKSPORNYA, jadi barisnya cukup
        // ditanam apa adanya.
        AuditLog::query()->create([
            'organization_id' => $this->admin->organization_id,
            'entity_type' => 'customers',
            'entity_id' => 1,
            'action' => AuditLog::ACTION_DIUBAH,
            'changed_by' => $this->admin->id,
            'old_data' => ['nama' => 'PT Tirta Gracia'],
            'new_data' => ['nama' => $nilaiBaru],
        ]);

        $respons = $this->actingAs($this->admin)->get('/api/audit-logs/export');
        $respons->assertOk();

        return $respons->streamedContent();
    }

    /**
     * Satu baris `customers` dari CSV, SESUDAH diurai jadi kolom.
     *
     * Diurai, bukan dicocokkan sebagai substring. `fputcsv` cuma mengutip nilai
     * yang memang butuh kutip, jadi assertion yang mengandaikan tanda kutip
     * merah untuk nilai yang tidak dikutip — walaupun netralisasinya jalan.
     * Yang mau diuji ISI selnya, bukan cara dia dikodekan.
     *
     * @return list<string>
     */
    private function barisPelanggan(string $csv): array
    {
        foreach (explode("\n", $csv) as $baris) {
            $baris = rtrim($baris, "\r");

            if ($baris === '' || ! str_contains($baris, ',customers,')) {
                continue;
            }

            // Escape kosong, sama persis dengan yang dipakai penulisnya.
            return str_getcsv($baris, ',', '"', '');
        }

        $this->fail('Baris audit `customers` nggak ketemu di CSV-nya.');
    }

    /** Isi sel "Nilai Baru" (kolom ke-9). */
    private function selNilaiBaru(string $csv): string
    {
        return (string) ($this->barisPelanggan($csv)[8] ?? '');
    }

    /** @return array<string, array{string}> */
    public static function awalanBerbahaya(): array
    {
        return [
            'sama dengan' => ['=1+1'],
            'plus' => ['+1+1'],
            'minus' => ['-1+1'],
            'at' => ['@SUM(A1)'],
            'hyperlink' => ['=HYPERLINK("http://jahat.example","klik")'],
            // Bentuk klasik command injection lewat DDE. Yang membuatnya
            // berbahaya bukan CSV-nya, tapi bahwa isinya bisa ditulis lewat
            // endpoint yang teknisi mana pun boleh panggil.
            'dde' => ['=cmd|\' /c calc\'!A1'],
            // Awalan tab ikut dijaga: sebagian parser memangkasnya dulu, dan
            // yang tersisa jadi rumus lagi.
            'tab' => ["\t=1+1"],
        ];
    }

    /** INTI bug-nya. */
    #[DataProvider('awalanBerbahaya')]
    public function test_nilai_yang_diawali_karakter_rumus_dinetralkan(string $jahat): void
    {
        $this->assertSame(
            "'".$jahat,
            $this->selNilaiBaru($this->csv($jahat)),
            'Sel-nya masih berangkat tanpa awalan penanda teks.'
        );
    }

    /**
     * Kolom `Pelaku` juga isian orang — `users.name` diisi sendiri waktu
     * mendaftar. Netralisasinya karena itu harus di PINTU TULIS, bukan cuma di
     * `teks()` yang hanya menyentuh `old_data`/`new_data`.
     */
    public function test_nama_pelaku_ikut_dinetralkan(): void
    {
        $kolom = $this->barisPelanggan($this->csv('PT Baru', '=1+1'));

        $this->assertSame("'=1+1", $kolom[4], 'Kolom Pelaku lolos tanpa dinetralkan.');
    }

    /**
     * JANGAN kebablasan #1: angka negatif tetap angka.
     *
     * Kalau test ini merah, kolom koreksi di CSV berubah jadi teks dan asesor
     * tidak bisa lagi menyaring atau mem-pivot-nya — yang justru satu-satunya
     * alasan ekspornya berbentuk CSV, bukan PDF.
     */
    public function test_angka_negatif_tetap_kebaca_sebagai_angka(): void
    {
        $this->assertSame('-0.02', $this->selNilaiBaru($this->csv('-0.02')));
    }

    public function test_angka_berawalan_plus_juga_tetap_angka(): void
    {
        $this->assertSame('+5', $this->selNilaiBaru($this->csv('+5')));
    }

    /**
     * JANGAN kebablasan #2: notasi ilmiah negatif juga angka. Ketidakpastian
     * gabungan di sistem ini rutin ditulis dalam bentuk itu.
     */
    public function test_notasi_ilmiah_negatif_tetap_angka(): void
    {
        $this->assertSame('-1.5e-3', $this->selNilaiBaru($this->csv('-1.5e-3')));
    }

    /**
     * JANGAN kebablasan #3: teks biasa tidak disentuh sama sekali. Ini yang
     * paling banyak isinya di CSV, dan awalan kutip di tiap sel bikin berkasnya
     * jelek dibuka di editor teks biasa.
     */
    public function test_teks_biasa_nggak_disentuh(): void
    {
        $this->assertSame(
            'PT Tirta Gracia',
            $this->selNilaiBaru($this->csv('PT Tirta Gracia')),
        );
    }

    /**
     * Ekspornya tetap ekspor — kalau penjagaannya bikin isinya kosong atau
     * header-nya hilang, yang rusak bukan cuma satu sel.
     */
    public function test_ekspor_tetap_utuh(): void
    {
        $csv = $this->csv('PT Baru');

        $this->assertStringContainsString('Waktu', $csv);
        $this->assertStringContainsString('Nilai Baru', $csv);
        $this->assertStringContainsString('Budi Santoso', $csv);
        $this->assertStringContainsString('PT Tirta Gracia', $csv);
        $this->assertSame('PT Baru', $this->selNilaiBaru($csv));

        // BOM UTF-8 tetap di depan — tanpa itu nama PT non-ASCII kacau di Excel
        // Windows, dan itu yang dibaca asesor.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }
}
