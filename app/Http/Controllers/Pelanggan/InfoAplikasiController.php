<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/pelanggan/v1/app/info` — kontak lab, dokumen hukum, alamat, dan
 * petunjuk pengemasan untuk layar Bantuan.
 *
 * Publik dan di LUAR gerbang `fitur.pelanggan`, sama seperti `/app/status`:
 * layar "belum tersedia" dan "pemeliharaan" justru butuh tombol "Hubungi PT
 * Sidik" waktu fiturnya sedang dimatikan. Nol query database — dibaca dari
 * `config('pelanggan.info')`.
 *
 * Field yang belum diisi dijawab `null` (bukan string kosong, bukan 404) dan
 * aplikasi menyembunyikan tombolnya. Server tidak mengarang nomor atau tautan:
 * bawaan kontak di `config/pelanggan.php` disalin dari situs resmi PT SIDIK.
 */
class InfoAplikasiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $privasiUrl = $this->teks('kebijakan_privasi_url');
        $syaratUrl = $this->teks('syarat_url');

        return response()->json([
            'data' => [
                'organisasi' => [
                    'nama' => $this->teks('nama_organisasi'),
                    'alamat' => $this->teks('alamat_organisasi'),
                    'telepon' => $this->teks('telepon_organisasi'),
                ],
                'kontak' => [
                    'whatsapp' => $this->teks('whatsapp'),
                    'whatsapp_url' => $this->teks('whatsapp_url'),
                    'email' => $this->teks('email'),
                    'jam_layanan' => $this->teks('jam_layanan'),
                    // Aditif (17 Nov 2026): aplikasi lama mengabaikan kunci yang
                    // tidak dikenalnya; aplikasi baru menyembunyikan tombol bila null.
                    'email_pemasaran' => $this->teks('email_pemasaran'),
                    'instagram_url' => $this->teks('instagram_url'),
                    'linkedin_url' => $this->teks('linkedin_url'),
                ],
                'dokumen' => [
                    // Versi hanya menyertai dokumen yang tautannya ada — "Versi
                    // 1.0" untuk dokumen yang tidak bisa dibuka menyesatkan.
                    'kebijakan_privasi' => [
                        'url' => $privasiUrl,
                        'versi' => $privasiUrl === null ? null : $this->versiDokumen('kebijakan_privasi'),
                    ],
                    'syarat_ketentuan' => [
                        'url' => $syaratUrl,
                        'versi' => $syaratUrl === null ? null : $this->versiDokumen('syarat_ketentuan'),
                    ],
                    'hapus_akun_url' => $this->teks('hapus_akun_url'),
                    // Ruang lingkup akreditasi KAN (PDF di situs resmi): aditif.
                    'ruang_lingkup_url' => $this->teks('ruang_lingkup_url'),
                ],
                'petunjuk_pengemasan' => $this->teks('petunjuk_pengemasan'),
            ],
        ]);
    }

    /** Teks terisi atau `null` — kosong/spasi/bukan-skalar tidak pernah lolos sebagai "". */
    private function teks(string $kunci): ?string
    {
        $nilai = config('pelanggan.info.'.$kunci);

        if (! is_scalar($nilai)) {
            return null;
        }

        $nilai = trim((string) $nilai);

        return $nilai === '' ? null : $nilai;
    }

    private function versiDokumen(string $dokumen): ?string
    {
        $versi = config('pelanggan.versi_dokumen.'.$dokumen);

        return is_scalar($versi) && trim((string) $versi) !== '' ? trim((string) $versi) : null;
    }
}
