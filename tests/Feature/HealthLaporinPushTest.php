<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kesiapan push FCM kelihatan dari luar lewat `GET /api/health` -> `push`.
 *
 * ## Kenapa berkas ini ada
 *
 * `AppServiceProvider` memilih pengirim push: kalau `FCM_PROJECT_ID` kosong,
 * `FCM_CREDENTIALS` kosong, atau berkasnya tidak ada, dia diam-diam jatuh ke
 * `PengirimPushMati`. Notifikasi tetap masuk lonceng dan tetap disiarkan
 * Reverb, jadi TIDAK ADA yang kelihatan rusak — HP yang aplikasinya ketutup
 * cuma tidak pernah dapat apa-apa. Satu-satunya cara memeriksanya dulu: dashboard
 * Render, atau menunggu keluhan.
 *
 * ## Batasnya
 *
 * Endpoint ini publik tanpa auth, dan berkas itu kunci server. Yang keluar
 * HANYA tiga boolean — jalur, `client_email`, `project_id`, dan materi kunci
 * tidak pernah ikut. Berkasnya di sini SINTETIS (kunci palsu), dibuat di
 * direktori sementara dan dihapus lagi.
 *
 * Yang TIDAK dibuktikan berkas ini: bahwa Google menerima kredensialnya. Itu
 * butuh request keluar; `siap` cuma berarti "bentuknya benar dan cocok dengan
 * FCM_PROJECT_ID".
 */
class HealthLaporinPushTest extends TestCase
{
    use RefreshDatabase;

    private const PROYEK = 'proyek-uji-sintetis';

    private const EMAIL = 'sintetis@proyek-uji-sintetis.iam.gserviceaccount.example';

    private const MATERI_KUNCI = 'MATERI-KUNCI-PALSU-JANGAN-BOCOR';

    private string $direktori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->direktori = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sidik-push-health-'.bin2hex(random_bytes(6));
        mkdir($this->direktori);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->direktori.DIRECTORY_SEPARATOR.'*') ?: [] as $berkas) {
            @unlink($berkas);
        }
        @rmdir($this->direktori);

        parent::tearDown();
    }

    /**
     * Service account SINTETIS — bentuk sama dengan berkas Google, isinya palsu.
     *
     * @param  array<string, mixed>  $timpa
     * @param  list<string>  $buang
     * @return array<string, mixed>
     */
    private static function berkasSah(array $timpa = [], array $buang = []): array
    {
        $berkas = array_merge([
            'type' => 'service_account',
            'project_id' => self::PROYEK,
            'private_key_id' => 'sintetis-0000',
            'private_key' => "-----BEGIN PRIVATE KEY-----\n".self::MATERI_KUNCI."\n-----END PRIVATE KEY-----\n",
            'client_email' => self::EMAIL,
            'client_id' => '000000000000000000000',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], $timpa);

        return array_diff_key($berkas, array_flip($buang));
    }

    private function tulis(string $isi, string $nama = 'akun-layanan.json'): string
    {
        $jalur = $this->direktori.DIRECTORY_SEPARATOR.$nama;
        file_put_contents($jalur, $isi);

        return $jalur;
    }

    /**
     * @param  array<string, mixed>  $berkas
     */
    private function tulisJson(array $berkas): string
    {
        return $this->tulis(json_encode($berkas, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function push(?string $jalur, ?string $projectId = self::PROYEK): array
    {
        config([
            'services.fcm.project_id' => $projectId,
            'services.fcm.credentials' => $jalur,
        ]);

        return $this->getJson('/api/health')->assertOk()->json('push');
    }

    /**
     * @param  array<string, mixed>  $push
     */
    private function assertPush(bool $siap, bool $proyek, bool $kredensial, array $push, string $pesan = ''): void
    {
        // `kredensial_alasan` (kata dari daftar tertutup) dijaga tes tersendiri di
        // bawah; di sini hanya tiga boolean aslinya, dan alasannya harus
        // sepadan dengan `kredensial_terbaca`.
        $this->assertSame($kredensial, ($push['kredensial_alasan'] ?? null) === 'ok', $pesan.' (alasan vs terbaca)');
        unset($push['kredensial_alasan']);

        $this->assertSame(
            ['siap' => $siap, 'proyek_terisi' => $proyek, 'kredensial_terbaca' => $kredensial],
            $push,
            $pesan,
        );
    }

    /** Keadaan test/CI/mesin developer: tidak ada setelan FCM sama sekali. */
    public function test_env_kosong_dilaporin_nggak_siap(): void
    {
        $this->assertPush(false, false, false, $this->push(null, null));
    }

    public function test_string_kosong_sama_dengan_nggak_disetel(): void
    {
        $this->assertPush(false, false, false, $this->push('', ''));
        $this->assertPush(false, false, false, $this->push('   ', '   '));
    }

    /** INTI-nya: dua-duanya benar → siap. */
    public function test_berkas_sah_dan_proyek_terisi_dilaporin_siap(): void
    {
        $this->assertPush(true, true, true, $this->push($this->tulisJson(self::berkasSah())));
    }

    /** `project_id` di berkas itu opsional dalam syarat — kalau nggak ada, nggak diadu. */
    public function test_berkas_tanpa_project_id_tetap_sah(): void
    {
        $jalur = $this->tulisJson(self::berkasSah(buang: ['project_id']));

        $this->assertPush(true, true, true, $this->push($jalur));
    }

    public function test_berkas_nggak_ada_dilaporin_kredensial_nggak_terbaca(): void
    {
        $push = $this->push($this->direktori.DIRECTORY_SEPARATOR.'nggak-ada.json');

        $this->assertPush(false, true, false, $push);
    }

    /** Jalur yang menunjuk direktori, bukan berkas — sama dengan gerbang `is_file` di AppServiceProvider. */
    public function test_jalur_ke_direktori_bukan_kredensial(): void
    {
        $this->assertPush(false, true, false, $this->push($this->direktori));
    }

    /**
     * `FCM_CREDENTIALS` nggak boleh bikin endpoint publik ini membuka stream
     * selain berkas lokal (`http://`, `ftp://`, `data://`). `is_file` menolak
     * semuanya; `file_get_contents` telanjang akan membacanya.
     */
    public function test_stream_wrapper_bukan_berkas_dan_nggak_dibaca(): void
    {
        $isi = json_encode(self::berkasSah(), JSON_THROW_ON_ERROR);
        $push = $this->push('data://text/plain;base64,'.base64_encode($isi));

        $this->assertPush(false, true, false, $push, 'Stream wrapper dibaca sebagai berkas kredensial.');
    }

    public function test_jalur_ber_null_byte_nggak_bikin_500(): void
    {
        $this->assertPush(false, true, false, $this->push("/tmp/fcm\0.json"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function isiBerkasTidakSah(): array
    {
        return [
            'JSON rusak' => ['{"type": "service_account", "client_email": '],
            'bukan JSON' => ['ini bukan json sama sekali'],
            'berkas kosong' => [''],
            'JSON skalar' => ['"service_account"'],
            'JSON daftar' => ['[]'],
            'tipe salah (authorized_user)' => [json_encode(self::berkasSah(['type' => 'authorized_user']))],
            'tanpa type' => [json_encode(self::berkasSah(buang: ['type']))],
            'type bukan string' => [json_encode(self::berkasSah(['type' => ['service_account']]))],
            'tanpa client_email' => [json_encode(self::berkasSah(buang: ['client_email']))],
            'client_email kosong' => [json_encode(self::berkasSah(['client_email' => '  ']))],
            'client_email bukan string' => [json_encode(self::berkasSah(['client_email' => 123]))],
            'tanpa private_key' => [json_encode(self::berkasSah(buang: ['private_key']))],
            'private_key kosong' => [json_encode(self::berkasSah(['private_key' => '']))],
            'private_key bukan string' => [json_encode(self::berkasSah(['private_key' => ['x']]))],
            'project_id beda dari FCM_PROJECT_ID' => [json_encode(self::berkasSah(['project_id' => 'proyek-lain']))],
            'project_id bukan string' => [json_encode(self::berkasSah(['project_id' => 42]))],
            // Batas baca 64 KB: berkas yang salah tunjuk (log, dump) nggak dibaca habis.
            'melebihi batas baca' => [json_encode(self::berkasSah(['isi_berlebih' => str_repeat('x', 70000)]))],
        ];
    }

    #[DataProvider('isiBerkasTidakSah')]
    public function test_berkas_nggak_sah_dilaporin_kredensial_nggak_terbaca(string $isi): void
    {
        $push = $this->push($this->tulis($isi));

        $this->assertPush(false, true, false, $push, 'Berkas yang bentuknya salah dilaporkan terbaca.');
    }

    /** Berkas sah tapi `FCM_PROJECT_ID` kosong: `siap` wajib false. */
    public function test_berkas_sah_tanpa_project_id_di_env_nggak_siap(): void
    {
        $jalur = $this->tulisJson(self::berkasSah(buang: ['project_id']));

        $this->assertPush(false, false, true, $this->push($jalur, null));
    }

    /** `project_id` ada di berkas tapi env kosong: nggak ada yang bisa dicocokkan. */
    public function test_project_id_berkas_dengan_env_kosong_nggak_cocok(): void
    {
        $this->assertPush(false, false, false, $this->push($this->tulisJson(self::berkasSah()), null));
    }

    /** Proyek terisi tapi kredensial belum disetel — setengah disetel, keadaan paling berbahaya. */
    public function test_proyek_terisi_tanpa_kredensial_nggak_siap(): void
    {
        $this->assertPush(false, true, false, $this->push(null));
    }

    /**
     * Nilai apa pun yang SENSITIF nggak boleh ada di badan respons — pada
     * keadaan sah MAUPUN gagal (jalur salah, JSON rusak).
     */
    public function test_respons_nggak_memuat_jalur_atau_materi_kunci(): void
    {
        $sah = $this->tulisJson(self::berkasSah());
        $rusak = $this->tulis('{"client_email": "'.self::EMAIL.'", "private_key": "'.self::MATERI_KUNCI, 'rusak.json');
        $nggakAda = $this->direktori.DIRECTORY_SEPARATOR.'nggak-ada.json';

        foreach ([$sah, $rusak, $nggakAda] as $jalur) {
            config(['services.fcm.project_id' => self::PROYEK, 'services.fcm.credentials' => $jalur]);

            $badan = (string) $this->getJson('/api/health')->assertOk()->getContent();

            foreach ([
                $jalur,
                str_replace('\\', '\\\\', $jalur), // jalur Windows di dalam JSON
                str_replace('\\', '/', $jalur),
                basename($jalur),
                $this->direktori,
                self::EMAIL,
                self::MATERI_KUNCI,
                'PRIVATE KEY',
                self::PROYEK,
            ] as $terlarang) {
                $this->assertStringNotContainsString(
                    $terlarang,
                    $badan,
                    "Respons /api/health memuat nilai sensitif: {$terlarang}",
                );
            }
        }
    }

    /** Blok `push`: tiga boolean + satu kata alasan dari daftar tertutup — bukan isi setelan, bukan panjangnya. */
    public function test_blok_push_tiga_boolean_dan_satu_kata_alasan(): void
    {
        $push = $this->push($this->tulisJson(self::berkasSah()));

        $this->assertSame(['siap', 'proyek_terisi', 'kredensial_terbaca', 'kredensial_alasan'], array_keys($push));

        foreach (['siap', 'proyek_terisi', 'kredensial_terbaca'] as $kunci) {
            $this->assertIsBool($push[$kunci], "push.{$kunci} bukan boolean.");
        }

        $this->assertSame('ok', $push['kredensial_alasan']);
    }

    /**
     * Tiap cara `kredensial_terbaca` bisa false punya SATU kata sendiri, supaya
     * "push belum jalan, kenapa?" terjawab dari /api/health tanpa membuka
     * dashboard. Daftar tertutup: tidak ada jalur, email, atau isi berkas.
     * (`berkas_tidak_terbaca` = berkas ada tapi izin baca ditolak; tidak bisa
     * dibuat andal lintas OS di test, jadi cabangnya tidak dijaga di sini.)
     */
    public function test_alasan_kredensial_satu_kata_dari_daftar_tertutup(): void
    {
        $kasus = [
            'env_kosong' => fn () => $this->push(null),
            'berkas_tidak_ada' => fn () => $this->push($this->direktori.DIRECTORY_SEPARATOR.'tidak-ada.json'),
            'bukan_json' => fn () => $this->push($this->tulis('ini bukan json')),
            'bukan_service_account' => fn () => $this->push($this->tulisJson(self::berkasSah(['type' => 'authorized_user']))),
            'kunci_tidak_lengkap' => fn () => $this->push($this->tulisJson(self::berkasSah([], ['private_key']))),
            'proyek_beda' => fn () => $this->push($this->tulisJson(self::berkasSah(['project_id' => 'proyek-lain']))),
            'ok' => fn () => $this->push($this->tulisJson(self::berkasSah())),
        ];

        foreach ($kasus as $alasan => $jalankan) {
            $push = $jalankan();

            $this->assertSame($alasan, $push['kredensial_alasan'], "Alasan untuk kasus `{$alasan}`.");
            $this->assertSame($alasan === 'ok', $push['kredensial_terbaca'], "kredensial_terbaca untuk `{$alasan}`.");
        }
    }

    /** Blok lama nggak boleh ilang gara-gara nambah blok baru. */
    public function test_kunci_health_yang_lama_nggak_berubah(): void
    {
        $this->push(null, null);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'app',
                'time',
                'direktori_perusahaan' => ['disetel', 'driver', 'bisa_ditagih', 'lokal' => ['aktif', 'baris']],
                'deploy' => ['lingkungan', 'versi', 'arsip' => ['awet'], 'seed_saat_boot', 'bangun_ulang_saat_boot'],
                'pelanggan' => ['fitur', 'maintenance'],
                'realtime' => ['driver', 'nyala', 'paket_terpasang'],
                'data_acuan' => ['sumber'],
                'append_only_db',
                'php_versi',
                'json_float_shortest',
                'push' => ['siap', 'proyek_terisi', 'kredensial_terbaca', 'kredensial_alasan'],
            ]);
    }
}
