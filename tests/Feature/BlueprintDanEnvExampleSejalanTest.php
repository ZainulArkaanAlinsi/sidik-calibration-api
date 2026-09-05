<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Tiap kunci di `render.yaml` punya barisnya di `.env.example`.
 *
 * ## Aturan proyek, dan kenapa dia ada
 *
 * `CLAUDE.md` §9: key baru wajib ikut ke `.env.example` DAN blueprint. Lahir
 * dari kejadian nyata — kunci yang cuma ada di salah satunya bikin pemasangan
 * baru jatuh ke bawaan diam-diam, tanpa error dan tanpa cara memeriksanya dari
 * luar.
 *
 * ## Kenapa test ini ada, padahal sudah ada penjaganya
 *
 * `EntrypointBootTest::test_saklar_boot_terdaftar_di_env_example_dan_blueprint`
 * menjaga aturan yang sama — tapi cuma untuk DUA kunci yang disebut namanya
 * (`SEED_ON_BOOT`, `BANGUN_ULANG_ON_BOOT`). Penjaga berdaftar-nama seperti itu
 * cuma menutup kunci yang sudah kepikiran waktu daftarnya ditulis.
 *
 * Dan memang bocor: `DB_SSL_CA_B64` ada di `render.yaml` sejak lama, tidak
 * pernah masuk `.env.example`, dan tidak ada satu pun test yang menyadarinya.
 * Ketahuannya waktu menyisir env untuk keperluan lain — bukan karena ada yang
 * menjaganya.
 *
 * Test ini membaca daftarnya dari blueprint, jadi kunci yang ditambahkan
 * BESOK ikut terjaga tanpa ada yang perlu ingat memperbarui daftar.
 *
 * ## Kalau suatu saat ini merah untuk kunci yang memang tidak perlu di `.env`
 *
 * Ada kunci yang wajar cuma hidup di sisi platform — nilai yang disuntik Render
 * sendiri, misalnya. Kalau itu terjadi, yang benar menambahkannya ke
 * [PENGECUALIAN] berikut alasannya di komentar, BUKAN melonggarkan
 * pemeriksaannya. Hari ini daftarnya kosong, dan itu memang keadaannya.
 */
class BlueprintDanEnvExampleSejalanTest extends TestCase
{
    /**
     * Kunci yang sengaja TIDAK ada di `.env.example`.
     *
     * Kosong hari ini. Tiap penambahan wajib menyebut alasannya di sini.
     *
     * @var list<string>
     */
    private const PENGECUALIAN = [];

    public function test_tiap_kunci_blueprint_punya_baris_di_env_example(): void
    {
        $blueprint = (string) file_get_contents(base_path('render.yaml'));
        $envExample = (string) file_get_contents(base_path('.env.example'));

        preg_match_all('/^\s*-\s*key:\s*([A-Z0-9_]+)\s*$/m', $blueprint, $cocok);
        $kunci = array_values(array_unique($cocok[1]));

        $this->assertNotEmpty(
            $kunci,
            'Nol kunci terbaca dari render.yaml — polanya yang rusak, bukan '
            .'blueprint-nya kosong. Test ini jadi hijau tanpa menguji apa pun.',
        );

        foreach ($kunci as $k) {
            if (in_array($k, self::PENGECUALIAN, true)) {
                continue;
            }

            // Diadu ke BARIS PENUGASANNYA, bukan ke kemunculan namanya —
            // alasannya sama dengan penjaga saklar boot: nama yang cuma
            // kesebut di komentar penjelas tidak bikin kunci itu kebaca siapa
            // pun yang menyalin berkasnya.
            $this->assertMatchesRegularExpression(
                '/^\s*#?\s*'.preg_quote($k, '/').'=/m',
                $envExample,
                "{$k} ada di render.yaml tapi tidak punya baris penugasan di "
                .'.env.example. Aturan proyek (CLAUDE.md §9): key baru wajib '
                .'ikut ke dua-duanya. Kalau kunci ini memang cuma milik '
                .'platform, tambahkan ke PENGECUALIAN berikut alasannya.',
            );
        }
    }
}
