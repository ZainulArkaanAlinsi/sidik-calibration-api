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
 * aplikasi menyembunyikan tombolnya. Server tidak mengarang nomor atau tautan.
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
