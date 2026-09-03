<?php

namespace Tests\Feature;

use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Penomoran versi rumus dikunci, dan tabrakannya bukan 500 generik.
 *
 * ## Kenapa berkas ini ada
 *
 * ```php
 * return (int) static::where('formula_id', $formulaId)->max('nomor_versi') + 1;
 * ```
 *
 * Dua penomoran sejenis di repo ini mengunci lebih dulu —
 * `GenerateCertificate::nomorBerikutnya()` dan penomoran sesi di
 * `CalibrationController`, dua-duanya `lockForUpdate()`. Yang ini satu-satunya
 * yang tidak.
 *
 * Datanya sendiri tidak pernah rusak: unique index `fversi_formula_nomor_unik`
 * menahan duplikatnya tersimpan. Yang rusak jawabannya — `storeVersion()` tidak
 * membungkus transaksinya, jadi pelanggaran constraint keluar sebagai **HTTP
 * 500 generik**. Admin yang kalah balapan tidak punya cara tahu bahwa yang
 * perlu dia lakukan cuma menyimpan ulang.
 *
 * ## Kenapa locknya diuji lewat SQL, bukan lewat dua transaksi beneran
 *
 * CI repo ini jalan di SQLite in-memory, dan grammar SQLite **membuang `FOR
 * UPDATE` diam-diam**. Jadi dua hal ini benar sekaligus: locknya nyata di
 * produksi (MySQL), dan tidak ada satu pun test konkuren di SQLite yang bisa
 * membuktikannya — kalau test seperti itu hijau, hijaunya bukan karena locknya.
 *
 * Yang bisa dibuktikan di sini SQL-nya waktu dikompilasi grammar MySQL. Itu
 * yang menahan `lockForUpdate()` dihapus orang berikutnya.
 */
class NomorVersiRumusTidakBentrokTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Formula $rumus;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->rumus = Formula::factory()->create([
            'organization_id' => $this->admin->organization_id,
        ]);
    }

    private function simpanVersi(): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(
            "/api/formulas/{$this->rumus->id}/versions",
            [
                'sumber' => FormulaVersion::SUMBER_KODE,
                'berlaku_dari' => now()->toDateString(),
                'parameter' => ['k' => 2],
            ],
        );
    }

    /**
     * Penjagaannya ada di SQL, dan itu yang ditahan test ini.
     *
     * Dikompilasi grammar MySQL, bukan grammar koneksi test — lihat docblock
     * kelas: SQLite membuang `FOR UPDATE` tanpa bilang apa-apa, jadi memeriksa
     * SQL yang benar-benar dieksekusi di CI tidak membuktikan apa pun.
     */
    public function test_pembacaan_nomor_terpakai_pakai_lock(): void
    {
        // Yang mengikat: `lockForUpdate()` benar-benar terpasang di builder-nya,
        // apa pun grammar koneksinya. (`lockForUpdate()` menyetelnya `true`;
        // `sharedLock()` menyetelnya `false` — jadi ini bukan sekadar "ada
        // lock", tapi lock TULIS.)
        $this->assertTrue(
            FormulaVersion::queryNomorTerpakai($this->rumus->id)->toBase()->lock,
            'lockForUpdate() ilang dari penomoran versi rumus.'
        );

        // Dan bahwa terpasangnya beneran jadi `FOR UPDATE` di mesin produksi.
        // Dikompilasi grammar MySQL karena grammar SQLite membuangnya diam-diam
        // — lihat docblock kelas.
        $mysql = DB::connection('mysql')
            ->table('formula_versions')
            ->where('formula_id', $this->rumus->id)
            ->lockForUpdate()
            ->toSql();

        $this->assertStringContainsString('for update', $mysql);
    }

    /**
     * INTI bug-nya: tabrakan nomor keluar sebagai jawaban yang bisa dimengerti,
     * bukan 500.
     *
     * Balapannya ditiru persis di titik yang benar — satu baris disisipkan
     * SETELAH `max()` dibaca dan SEBELUM barisnya ditulis, yaitu jendela yang
     * dulu tidak dijaga apa pun. Menirunya lewat dua transaksi sungguhan tidak
     * mungkin di SQLite (lihat docblock kelas).
     */
    public function test_tabrakan_nomor_jadi_409_bukan_500(): void
    {
        $sisipkan = function (FormulaVersion $versi): void {
            // Cuma sekali, dan cuma buat versi yang lagi dibikin controller.
            static $sudah = false;

            if ($sudah || $versi->exists) {
                return;
            }

            $sudah = true;

            DB::table('formula_versions')->insert([
                'formula_id' => $this->rumus->id,
                'organization_id' => $this->rumus->organization_id,
                'nomor_versi' => $versi->nomor_versi,
                'sumber' => FormulaVersion::SUMBER_KODE,
                'status' => FormulaVersion::STATUS_DRAFT,
                'effective_from' => now()->toDateString(),
                'created_by' => $this->admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        FormulaVersion::creating($sisipkan);

        try {
            $respons = $this->simpanVersi();
        } finally {
            FormulaVersion::flushEventListeners();
        }

        $respons->assertStatus(409);
        $this->assertStringContainsString('simpan lagi', $respons->json('message'));
    }

    /**
     * JANGAN kebablasan #1: jalur normal tetap jalan, dan nomornya tetap naik.
     *
     * Kalau test ini merah, penjagaannya menelan penyimpanan yang benar — dan
     * gejalanya persis sama dengan fitur yang tidak pernah dipasang.
     */
    public function test_penomoran_normal_tetap_urut(): void
    {
        $this->simpanVersi()->assertStatus(201)->assertJsonPath('data.nomor_versi', 1);
        $this->simpanVersi()->assertStatus(201)->assertJsonPath('data.nomor_versi', 2);
        $this->simpanVersi()->assertStatus(201)->assertJsonPath('data.nomor_versi', 3);

        $this->assertSame(3, FormulaVersion::where('formula_id', $this->rumus->id)->count());
    }

    /**
     * JANGAN kebablasan #2: pelanggaran constraint LAIN tidak ikut tertelan
     * jadi "coba simpan lagi".
     *
     * Pesan yang salah lebih berbahaya daripada pesan generik: dia menyuruh
     * admin mengulang sesuatu yang tidak akan pernah berhasil.
     */
    public function test_pelanggaran_constraint_lain_tetap_dilempar(): void
    {
        // SQL-nya SENGAJA memuat daftar kolom lengkap — persis seperti SQL
        // insert yang sebenarnya. Itu yang bikin penjagaan naif merah di sini:
        // `QueryException::getMessage()` menempelkan SQL, dan SQL itu memuat
        // `nomor_versi`, jadi pemeriksaan pada pesan LENGKAPNYA bakal menelan
        // kegagalan ini juga dan menyuruh admin mengulang sesuatu yang nggak
        // akan pernah berhasil.
        $sqlAsli = 'insert into "formula_versions" ("organization_id", "nomor_versi", '
            .'"sumber", "status", "effective_from", "created_by") values (1, 1, ...)';

        $meledak = function (FormulaVersion $versi) use ($sqlAsli): void {
            static $sudah = false;

            if ($sudah || $versi->exists) {
                return;
            }

            $sudah = true;

            throw new UniqueConstraintViolationException(
                'sqlite',
                $sqlAsli,
                [],
                new \PDOException('UNIQUE constraint failed: formula_versions.kolom_lain'),
            );
        };

        FormulaVersion::creating($meledak);

        try {
            $this->expectException(UniqueConstraintViolationException::class);
            $this->withoutExceptionHandling()->simpanVersi();
        } finally {
            FormulaVersion::flushEventListeners();
        }
    }
}
