<?php

namespace Tests\Feature;

use App\Models\DokumenBacaan;
use App\Models\DokumenBacaanNilai;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dokumen\RiwayatKoreksi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Koreksi teknisi jadi pelajaran buat pembacaan berikutnya — TANPA pernah
 * mengubah satu nilai pun.
 *
 * Batas itu inti seluruh berkas ini. Riwayat boleh memanggil teknisi ke satu
 * sel; dia nggak boleh menjawab menggantikan teknisi.
 */
class KoreksiJadiPelajaranTest extends TestCase
{
    use RefreshDatabase;

    private const KODE = 'SIDIK-FM-CAL-0510';

    private const REVISI = 'Rev.5';

    private function teknisi(?Organization $org = null): User
    {
        return User::factory()->create([
            'organization_id' => ($org ?? Organization::factory()->create())->id,
            'role' => User::ROLE_TEKNISI,
            'status' => 'aktif',
        ]);
    }

    /**
     * Bikin riwayat: satu posisi dibaca `$dibaca` kali, meleset `$meleset` kali.
     */
    private function riwayat(
        int $organizationId,
        int $dibaca,
        int $meleset,
        string $label = 'Reading',
        ?string $kode = self::KODE,
    ): void {
        for ($i = 0; $i < $dibaca; $i++) {
            $bacaan = DokumenBacaan::create([
                'organization_id' => $organizationId,
                'kode_dokumen' => $kode,
                'revisi' => self::REVISI,
                'pola' => RiwayatKoreksi::pola($kode, self::REVISI),
                'status' => DokumenBacaan::STATUS_OK,
            ]);

            DokumenBacaanNilai::create([
                'dokumen_bacaan_id' => $bacaan->id,
                'kunci' => 'bagian-0.tabel-0.sel-0-1',
                'jenis' => DokumenBacaanNilai::JENIS_SEL,
                'bagian_nama' => 'Before adjustment Reading',
                'label' => $label,
                'baris_ke' => 0,
                'kolom_ke' => 1,
                'nilai_baca' => '84,1',
                'nilai_final' => $i < $meleset ? '84,7' : '84,1',
                'status' => DokumenBacaanNilai::STATUS_OK,
                'cocok' => $i >= $meleset,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function lembar(): array
    {
        return [
            'document' => [
                'worksheet_code' => self::KODE,
                'revision' => self::REVISI,
                'equipment_name' => 'Conductivity Meter',
            ],
            'sections' => [[
                'name' => 'Before adjustment Reading',
                'fields' => [],
                'tables' => [[
                    'headers' => ['84', 'Reading'],
                    'cells' => [
                        // Keyakinan TINGGI — tanpa riwayat, ini bakal lolos
                        // sebagai OK tanpa dilihat siapa pun.
                        ['row' => 0, 'column' => 1, 'value' => '84,1',
                            'confidence' => 0.99, 'source' => 'handwriting'],
                    ],
                ]],
            ]],
            'warnings' => [],
        ];
    }

    private function pasangJawaban(): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.vision.aktif' => true,
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($this->lembar())]],
        ])]);
    }

    private function baca(User $teknisi): TestResponse
    {
        return $this->actingAs($teknisi)->postJson('/api/dokumen/baca', [
            'foto' => UploadedFile::fake()->image('lembar.jpg'),
        ]);
    }

    /** @return array<string, mixed> */
    private function sel(TestResponse $r): array
    {
        return $r->json('data.bagian.0.tabel.0.baris.0.1');
    }

    public function test_posisi_yang_sering_meleset_ditandai_walau_model_yakin(): void
    {
        $teknisi = $this->teknisi();
        $this->riwayat($teknisi->organization_id, dibaca: 4, meleset: 3);
        $this->pasangJawaban();

        $sel = $this->sel($this->baca($teknisi));

        $this->assertSame('REVIEW_REQUIRED', $sel['status']);
        // Keyakinannya TETAP apa adanya dari model — yang berubah cuma
        // apakah manusia diminta melihatnya.
        $this->assertSame(0.99, $sel['keyakinan']);
    }

    /** INI batasnya. Riwayat memanggil teknisi; dia nggak menjawab buat teknisi. */
    public function test_nilai_bacaan_baru_tidak_pernah_ditimpa_koreksi_lama(): void
    {
        $teknisi = $this->teknisi();
        // Riwayatnya bilang jawaban benarnya `84,7`.
        $this->riwayat($teknisi->organization_id, dibaca: 4, meleset: 4);
        $this->pasangJawaban();

        $sel = $this->sel($this->baca($teknisi));

        $this->assertSame(
            '84,1',
            $sel['nilai'],
            'koreksi lama NGGAK BOLEH menimpa bacaan baru — itu cara paling '
            .'rapi buat merusak data diam-diam',
        );
    }

    public function test_riwayat_terlalu_sedikit_belum_dipercaya(): void
    {
        $teknisi = $this->teknisi();
        // Dua kali, dua-duanya meleset: 100% tapi sampelnya belum cukup.
        // Koreksi pertama sering justru salah ketik teknisi, bukan salah model.
        $this->riwayat($teknisi->organization_id, dibaca: 2, meleset: 2);
        $this->pasangJawaban();

        $this->assertSame('OK', $this->sel($this->baca($teknisi))['status']);
    }

    public function test_posisi_yang_biasanya_benar_tidak_ditandai(): void
    {
        $teknisi = $this->teknisi();
        $this->riwayat($teknisi->organization_id, dibaca: 10, meleset: 1);
        $this->pasangJawaban();

        $this->assertSame('OK', $this->sel($this->baca($teknisi))['status']);
    }

    public function test_yang_belum_pernah_diperiksa_teknisi_bukan_bukti_apa_apa(): void
    {
        $teknisi = $this->teknisi();

        // Empat pembacaan, tapi teknisi nggak pernah melihat selnya sama
        // sekali (`cocok` null). "Belum diperiksa" bukan "salah".
        for ($i = 0; $i < 4; $i++) {
            $bacaan = DokumenBacaan::create([
                'organization_id' => $teknisi->organization_id,
                'kode_dokumen' => self::KODE,
                'revisi' => self::REVISI,
                'pola' => RiwayatKoreksi::pola(self::KODE, self::REVISI),
                'status' => DokumenBacaan::STATUS_OK,
            ]);

            DokumenBacaanNilai::create([
                'dokumen_bacaan_id' => $bacaan->id,
                'kunci' => 'bagian-0.tabel-0.sel-0-1',
                'jenis' => DokumenBacaanNilai::JENIS_SEL,
                'bagian_nama' => 'Before adjustment Reading',
                'label' => 'Reading',
                'baris_ke' => 0,
                'kolom_ke' => 1,
                'nilai_baca' => '84,1',
                'status' => DokumenBacaanNilai::STATUS_PERLU_REVIEW,
                'cocok' => null,
            ]);
        }

        $this->pasangJawaban();

        $this->assertSame('OK', $this->sel($this->baca($teknisi))['status']);
    }

    public function test_riwayat_lab_lain_tidak_ikut_dipakai(): void
    {
        $labA = Organization::factory()->create();
        $labB = Organization::factory()->create();

        // Riwayat buruk ada di lab B; yang membaca teknisi lab A.
        $this->riwayat($labB->id, dibaca: 10, meleset: 10);
        $this->pasangJawaban();

        $sel = $this->sel($this->baca($this->teknisi($labA)));

        $this->assertSame(
            'OK',
            $sel['status'],
            'statistik agregat tetap membocorkan informasi, dan tulisan tangan '
            .'teknisi lab lain bukan bukti apa pun soal lembar di sini',
        );
    }

    public function test_pola_lembar_lain_tidak_ikut_dipakai(): void
    {
        $teknisi = $this->teknisi();
        // Riwayat buruk, tapi di lembar berkode LAIN.
        $this->riwayat($teknisi->organization_id, dibaca: 10, meleset: 10, kode: 'SIDIK-FM-CAL-9999');
        $this->pasangJawaban();

        $this->assertSame('OK', $this->sel($this->baca($teknisi))['status']);
    }

    public function test_ejaan_revisi_yang_bergoyang_tetap_satu_pola(): void
    {
        $this->assertSame(
            RiwayatKoreksi::pola('SIDIK-FM-CAL-0510', 'Rev.5'),
            RiwayatKoreksi::pola('sidik fm cal 0510', 'REV 5'),
        );
    }

    public function test_lembar_tanpa_kode_bukan_pola(): void
    {
        // Dua lembar yang sama-sama nggak berkode belum tentu lembar yang sama.
        $this->assertNull(RiwayatKoreksi::pola(null, 'Rev.5'));
        $this->assertNull(RiwayatKoreksi::pola('', 'Rev.5'));
    }

    public function test_penandaan_dilaporkan_ke_teknisi_bukan_diam_diam(): void
    {
        $teknisi = $this->teknisi();
        $this->riwayat($teknisi->organization_id, dibaca: 4, meleset: 3);
        $this->pasangJawaban();

        $r = $this->baca($teknisi);

        // Sel yang tiba-tiba merah padahal angkanya wajar bikin teknisi curiga
        // aplikasinya rusak — kecuali ada yang menjelaskan kenapa.
        $this->assertNotEmpty(array_filter(
            $r->json('data.peringatan'),
            fn ($p) => str_contains($p, 'sering salah baca')
                && str_contains($p, 'TIDAK diubah'),
        ));
    }

    public function test_ringkasan_ikut_menghitung_yang_ditandai_riwayat(): void
    {
        // Tanpa riwayat dulu, buat dapat angka acuannya. (Sel kolom 0 nggak
        // pernah dilaporkan model, jadi dia SELALU perlu review — itu yang
        // bikin angka acuannya bukan nol.)
        $tanpa = $this->teknisi();
        $this->pasangJawaban();
        $acuan = $this->baca($tanpa)->json('data.ringkasan.perlu_review');

        $dengan = $this->teknisi();
        $this->riwayat($dengan->organization_id, dibaca: 4, meleset: 3);
        $this->pasangJawaban();
        $sesudah = $this->baca($dengan)->json('data.ringkasan.perlu_review');

        // Ringkasan yang nggak cocok sama isinya bikin teknisi berhenti
        // mempercayai dua-duanya — jadi yang ditandai riwayat WAJIB ikut
        // terhitung, bukan cuma merah di layar.
        $this->assertSame(
            $acuan + 1,
            $sesudah,
            'sel yang ditandai riwayat harus nambah hitungan perlu-review',
        );
    }

    public function test_status_yang_ditandai_ikut_tersimpan(): void
    {
        $teknisi = $this->teknisi();
        $this->riwayat($teknisi->organization_id, dibaca: 4, meleset: 3);
        $this->pasangJawaban();

        $id = $this->baca($teknisi)->json('id');

        $sel = DokumenBacaanNilai::where('dokumen_bacaan_id', $id)
            ->where('kunci', 'bagian-0.tabel-0.sel-0-1')
            ->firstOrFail();

        $this->assertSame(DokumenBacaanNilai::STATUS_PERLU_REVIEW, $sel->status);
        $this->assertSame('84,1', $sel->nilai_baca);
    }

    /**
     * Yang belum diperiksa nggak boleh MENGENCERKAN masalah yang nyata.
     *
     * Ini kegagalan yang sebenarnya, dan lebih halus dari "dihitung salah":
     * tiga pembacaan yang diperiksa teknisi SEMUANYA meleset, tapi ada tujuh
     * lagi yang dia nggak pernah lihat. Kalau yang tujuh itu ikut jadi
     * penyebut, angkanya jatuh dari 100% ke 30% — di bawah ambang, dan posisi
     * yang terbukti selalu salah itu diam-diam berhenti ditandai.
     */
    public function test_yang_belum_diperiksa_tidak_mengencerkan_yang_terbukti_meleset(): void
    {
        $teknisi = $this->teknisi();

        $this->riwayat($teknisi->organization_id, dibaca: 3, meleset: 3);

        // Tujuh pembacaan yang teknisinya nggak pernah melihat selnya.
        for ($i = 0; $i < 7; $i++) {
            $bacaan = DokumenBacaan::create([
                'organization_id' => $teknisi->organization_id,
                'kode_dokumen' => self::KODE,
                'revisi' => self::REVISI,
                'pola' => RiwayatKoreksi::pola(self::KODE, self::REVISI),
                'status' => DokumenBacaan::STATUS_OK,
            ]);

            DokumenBacaanNilai::create([
                'dokumen_bacaan_id' => $bacaan->id,
                'kunci' => 'bagian-0.tabel-0.sel-0-1',
                'jenis' => DokumenBacaanNilai::JENIS_SEL,
                'bagian_nama' => 'Before adjustment Reading',
                'label' => 'Reading',
                'baris_ke' => 0,
                'kolom_ke' => 1,
                'nilai_baca' => '84,1',
                'status' => DokumenBacaanNilai::STATUS_PERLU_REVIEW,
                'cocok' => null,
            ]);
        }

        $this->pasangJawaban();

        $this->assertSame(
            'REVIEW_REQUIRED',
            $this->sel($this->baca($teknisi))['status'],
            'tiga dari tiga yang diperiksa meleset — tujuh yang nggak pernah '
            .'dilihat nggak boleh bikin itu kelihatan cuma 30%',
        );
    }
}
