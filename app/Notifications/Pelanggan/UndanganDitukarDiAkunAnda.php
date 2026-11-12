<?php

namespace App\Notifications\Pelanggan;

use App\Notifications\NotifikasiSistem;

/**
 * Pemilik akun dikabari: sebuah undangan baru saja DITUKAR di akunnya yang
 * sudah ada, sehingga akunnya jadi anggota perusahaan lain.
 *
 * Nadanya netral dengan sengaja. Pada saat ini server sudah memeriksa sandi
 * akun itu, jadi hampir pasti pemiliknya sendiri yang menukar — kabar ini
 * bukan tuduhan. Tapi "hampir pasti" bukan "pasti" (sandinya bisa bocor), dan
 * satu-satunya cara pemilik yang tidak menukar tahu ada yang masuk lewat pintu
 * ini adalah diberi tahu.
 *
 * Isinya tidak menyebut nama perusahaan, IP, perangkat, atau siapa
 * pengundangnya: push mendarat di layar kunci (sama seperti kabar sertifikat
 * terbit, kontrak §8). Rinciannya ada di jejak audit (`audit_logs`, aksi
 * `undangan_ditukar`) dan di daftar keanggotaan akun itu.
 *
 * Dikirim langsung ke akun yang bersangkutan, TIDAK lewat `PreferensiNotifikasi`:
 * saklar itu mengatur kabar pekerjaan, bukan kabar keamanan akun.
 */
class UndanganDitukarDiAkunAnda extends NotifikasiSistem
{
    public static function baru(): self
    {
        return new self;
    }

    protected function judul(): string
    {
        return 'Akun Anda ditautkan ke perusahaan lain';
    }

    protected function isi(): string
    {
        return 'Akun Anda baru saja ditambahkan sebagai anggota sebuah perusahaan lewat kode undangan. '
            .'Kalau ini bukan Anda, segera ganti sandi dan hubungi PT Sidik.';
    }

    protected function kategori(): string
    {
        return 'pelanggan.akun_ditautkan';
    }

    protected function ikon(): string
    {
        return 'heroicon-o-shield-check';
    }

    protected function warna(): string
    {
        return 'warning';
    }
}
