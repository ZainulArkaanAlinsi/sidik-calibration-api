<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /api/worksheet-templates` (JAMAK) — pintu KETIGA dengan bentuk yang sama.
 *
 * ## Bentuk cacatnya
 *
 * `CalibrationProfile::masterStandarTertaut()` menyaring organisasi lewat
 * `when($equipment?->organization_id !== null, …)`. `when(false, …)` tidak
 * memasang `where` apa pun — jadi `$equipment` null berarti profilnya memilih
 * baris `standards` milik **semua lab**, lengkap dengan `no_sertifikat`,
 * `tertelusur_ke`, `serial_number`, dan `id` yang bisa dipakai.
 *
 * Dua pintu sudah ditutup dan dijaga `LembarKerjaTidakBocorLintasLabTest`:
 * `GET /api/calibrations/lembar-kerja` dan `GET /api/worksheet-templates/{kode}`,
 * dua-duanya dengan alat semu pembawa `organization_id` pemanggil.
 *
 * Yang jamak lolos karena `templates()` tidak menerima `Request` sama sekali,
 * jadi tidak ada `organization_id` untuk dititipkan — dan `daftar()` memanggil
 * `dariProfil($profil)` tanpa konteks buat KEDUA PULUH EMPAT profil sekaligus.
 * Terukur sebelum diperbaiki: **65 query ke `standards` tanpa saringan
 * organisasi dalam satu panggilan.**
 *
 * ## Kenapa dijaga di tingkat QUERY, bukan tingkat respons
 *
 * `daftar()` hari ini membuang kolom-kolom itu dan cuma memulangkan hitungan
 * sel, jadi assertion pada isi respons akan HIJAU walaupun kebocorannya ada.
 * Yang membedakan aman dari belum-terlihat cuma satu baris `$hasil[]` yang
 * kebetulan tidak menyalin field apa pun dari `$template`. Test ini menjaga
 * penyebabnya, bukan gejalanya: sekali ada yang menambah satu field dari
 * `$template`, kebocorannya jadi nyata tanpa satu pun test lain berubah warna.
 */
class DaftarTemplateOcrTidakBocorLintasLabTest extends TestCase
{
    use RefreshDatabase;

    /** Lantai jumlah profil — sama alasannya dengan sapuan registry lain. */
    private const LANTAI_TEMPLATE = 20;

    /**
     * @return array{0: User, 1: Organization}
     */
    private function duaLab(): array
    {
        // `createOne()`, bukan `create()`: yang kedua bertipe
        // `TModel|Collection<TModel>`, jadi tipe kembalian `duaLab()` tidak bisa
        // dibuktikan alat analisis mana pun. Bedanya cuma ketepatan tipe —
        // perilakunya sama persis.
        $labSaya = Organization::factory()->createOne();
        $labLain = Organization::factory()->createOne();

        // Baris milik lab lain, lengkap dengan identitas yang tidak boleh
        // sampai ke mana pun: nomor sertifikat & ketertelusurannya.
        Standard::factory()->create([
            'organization_id' => $labLain->id,
            'nama' => 'Kalibrator Rahasia Lab Sebelah',
            'no_sertifikat' => 'JANGAN-SAMPAI-KELUAR/2026',
        ]);

        $teknisi = User::factory()->createOne([
            'organization_id' => $labSaya->id,
            'role' => User::ROLE_TEKNISI,
            'status' => 'aktif',
        ]);

        return [$teknisi, $labLain];
    }

    /**
     * Tidak boleh ada satu pun query ke `standards` yang jalan tanpa saringan
     * `organization_id` selama permintaan ini dilayani.
     */
    public function test_tidak_ada_query_standards_tanpa_saringan_organisasi(): void
    {
        [$teknisi] = $this->duaLab();

        $tanpaSaringan = [];
        DB::listen(function ($q) use (&$tanpaSaringan): void {
            $dariStandards = str_contains($q->sql, 'from "standards"')
                || str_contains($q->sql, 'from `standards`');

            if ($dariStandards && ! str_contains($q->sql, 'organization_id')) {
                $tanpaSaringan[] = $q->sql;
            }
        });

        $this->actingAs($teknisi)->getJson('/api/worksheet-templates')->assertOk();

        $this->assertSame(
            [],
            $tanpaSaringan,
            count($tanpaSaringan).' query ke `standards` jalan TANPA saringan `organization_id`. '
            .'Contoh: '.($tanpaSaringan[0] ?? '-'),
        );
    }

    /**
     * Sisi lain dari penjagaan yang sama: query yang jalan memang menyaring ke
     * lab PEMANGGIL, bukan ke lab mana pun yang kebetulan ada.
     *
     * Tanpa ini, `where organization_id = <lab lain>` yang salah tetap lolos
     * test di atas — dia menyaring, cuma menyaring ke tempat yang keliru.
     */
    public function test_saringannya_ke_lab_pemanggil_bukan_lab_lain(): void
    {
        [$teknisi, $labLain] = $this->duaLab();

        // Bindings dikumpulkan PER QUERY, bukan dituang ke satu keranjang.
        //
        // Satu keranjang bikin penjagaannya bocor ke arah yang halus: query yang
        // disaring ke lab KETIGA tetap lolos, asal query LAIN di permintaan yang
        // sama kebetulan menyebut id lab pemanggil. Yang harus benar tiap query
        // sendiri-sendiri, bukan gabungannya.
        $perQuery = [];
        DB::listen(function ($q) use (&$perQuery): void {
            $dariStandards = str_contains($q->sql, 'from "standards"')
                || str_contains($q->sql, 'from `standards`');

            if ($dariStandards && str_contains($q->sql, 'organization_id')) {
                $perQuery[] = array_map(
                    intval(...),
                    array_filter($q->bindings, is_numeric(...)),
                );
            }
        });

        $this->actingAs($teknisi)->getJson('/api/worksheet-templates')->assertOk();

        $this->assertNotEmpty(
            $perQuery,
            'Nggak ada satu pun query `standards` bersaringan yang ketangkep — testnya nggak nguji apa-apa.',
        );

        foreach ($perQuery as $ke => $bindings) {
            $this->assertContains(
                $teknisi->organization_id,
                $bindings,
                "Query `standards` ke-{$ke} nyaring `organization_id`, tapi bukan ke lab pemanggil.",
            );

            $this->assertNotContains(
                $labLain->id,
                $bindings,
                "Query `standards` ke-{$ke} disaring ke `organization_id` milik lab LAIN.",
            );
        }
    }

    /**
     * Perbaikannya tidak boleh mengosongkan daftarnya.
     *
     * Endpoint ini yang dipakai HP buat mutusin tombol kamera nyala atau nggak.
     * "Aman" yang dicapai dengan memulangkan nol template artinya kamera mati
     * di semua alat — dan itu bukan perbaikan, itu kerusakan yang lain.
     */
    public function test_daftarnya_tetap_utuh_sesudah_disaring(): void
    {
        [$teknisi] = $this->duaLab();

        $data = $this->actingAs($teknisi)
            ->getJson('/api/worksheet-templates')
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(
            self::LANTAI_TEMPLATE,
            count($data),
            'Daftar template menyusut di bawah lantai '.self::LANTAI_TEMPLATE
            .' — HP kehilangan tombol kamera buat alat yang hilang.',
        );

        foreach ($data as $baris) {
            $this->assertArrayHasKey('template_id', $baris);
            $this->assertArrayHasKey('siap_pindai', $baris);
        }
    }
}
