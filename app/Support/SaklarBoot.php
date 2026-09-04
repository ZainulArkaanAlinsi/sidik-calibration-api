<?php

namespace App\Support;

/**
 * Saklar boot dibaca PERSIS seperti `docker/entrypoint.sh` membacanya.
 *
 * ## Kenapa bukan `filter_var(..., FILTER_VALIDATE_BOOL)`
 *
 * Gerbang di entrypoint bentuknya shell, dan shell tidak mengenal "nilai yang
 * mirip benar":
 *
 * ```sh
 * if [ "${BANGUN_ULANG_ON_BOOT}" = "true" ]; then
 * ```
 *
 * Cuma string `true` huruf kecil yang menjalankan kerjanya. `FILTER_VALIDATE_BOOL`
 * jauh lebih murah hati — `1`, `yes`, `on` semuanya jadi `true`. Ditambah `env()`
 * Laravel yang meng-cast `TRUE`/`True` jadi boolean lebih dulu, ada EMPAT nilai
 * yang bikin `/api/health` melapor `true` sementara entrypoint tidak menjalankan
 * apa pun:
 *
 * | `.env`   | health lapor | entrypoint jalan |
 * |----------|--------------|------------------|
 * | `true`   | true         | ya               |
 * | `1`      | true         | **tidak**        |
 * | `yes`    | true         | **tidak**        |
 * | `on`     | true         | **tidak**        |
 * | `TRUE`   | true         | **tidak**        |
 *
 * Empat baris terakhir itu bukan sekadar nilai yang keliru. Endpoint health ini
 * ADA supaya tidak ada yang perlu menebak keadaan container — laporan `true`
 * yang salah justru mengirim orangnya mencari ke tempat yang salah, dan itu
 * lebih buruk daripada tidak melaporkan sama sekali. Repo ini sudah pernah
 * membayar pelajaran yang sama: peringatan palsu melatih orang mengabaikan
 * yang asli.
 *
 * Jadi yang disamakan ke shell adalah SISI PHP-nya, bukan sebaliknya. Perilaku
 * entrypoint sengaja tidak disentuh: `render.yaml` sudah menuliskannya sebagai
 * janji yang dipegang ("yang dibaca docker/entrypoint.sh cuma persamaan dengan
 * string `true`"), dan melonggarkannya berarti mengubah apa yang dijalankan di
 * produksi — bukan sekadar apa yang dilaporkan.
 *
 * ## Kenapa nilai MENTAH, bukan lewat `env()`
 *
 * `env()` meng-cast `true`/`false`/`null` sebelum kita sempat melihatnya, dan
 * cast itu tidak peka huruf besar — jadi `TRUE` sampai ke sini sudah jadi
 * boolean `true` dan tidak bisa dibedakan lagi dari `true`. Shell melihat
 * string aslinya, jadi di sini string aslinya juga yang dibaca.
 */
final class SaklarBoot
{
    /**
     * Apakah saklar bernama [$kunci] benar-benar menyalakan kerjanya di boot.
     */
    public static function nyala(string $kunci): bool
    {
        return self::mentah($kunci) === 'true';
    }

    /**
     * Nilai environment apa adanya, tanpa cast — atau null kalau tidak disetel.
     *
     * Ketiga sumbernya diperiksa karena mana yang terisi bergantung
     * `variables_order` di php.ini dan cara nilainya masuk: Render menyuntik
     * variabel proses betulan, sementara `.env` diisi Dotenv ke `$_ENV` &
     * `$_SERVER`.
     */
    private static function mentah(string $kunci): ?string
    {
        foreach ([$_ENV[$kunci] ?? null, $_SERVER[$kunci] ?? null] as $nilai) {
            if (is_string($nilai)) {
                return $nilai;
            }
        }

        $dariProses = getenv($kunci);

        return is_string($dariProses) ? $dariProses : null;
    }
}
