<?php

namespace Tests\Concerns;

/**
 * Kunci RSA sekali pakai untuk uji (dibangkitkan saat tes jalan; TIDAK ada kunci privat yang
 * ditulis di repo, supaya pemindai rahasia tidak menandainya).
 *
 * PHP di Windows sering tidak menemukan `openssl.cnf` sehingga `openssl_pkey_new()` gagal; coba
 * beberapa lokasi lazim sebelum melewati tes.
 */
trait MembuatKunciUji
{
    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: string} [kunci, PEM kunci privat]
     */
    protected function bangkitkanKunciUji(): array
    {
        $opsi = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $cnf = [
            null,
            dirname(PHP_BINARY).'/extras/ssl/openssl.cnf',
            'C:/Program Files/Git/mingw64/etc/ssl/openssl.cnf',
        ];

        foreach ($cnf as $berkas) {
            if ($berkas !== null && ! is_file($berkas)) {
                continue;
            }
            $tambahan = $berkas === null ? [] : ['config' => $berkas];
            $kunci = @openssl_pkey_new($opsi + $tambahan);
            if ($kunci === false) {
                continue;
            }
            $pem = '';
            if (@openssl_pkey_export($kunci, $pem, null, $tambahan) && $pem !== '') {
                return [$kunci, $pem];
            }
        }

        $this->markTestSkipped('OpenSSL di mesin ini tidak bisa membangkitkan kunci uji (setel OPENSSL_CONF).');
    }
}
