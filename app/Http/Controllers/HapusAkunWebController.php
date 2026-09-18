<?php

namespace App\Http\Controllers;

use App\Mail\Pelanggan\PermintaanHapusAkunWeb;
use App\Models\Organization;
use App\Models\User;
use App\Services\PenerimaNotifikasi;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Halaman hapus akun versi WEB (REQ-PRV-03, syarat Google Play).
 *
 * ## Kenapa halaman ini ada padahal aplikasi sudah punya tombolnya
 *
 * Google Play menuntut URL yang bisa dibuka **tanpa memasang aplikasinya** —
 * buat orang yang HP-nya hilang, yang sudah mencopot aplikasinya, atau yang
 * memutuskan berhenti sebelum sempat masuk. Kalau satu-satunya jalan lewat
 * dalam aplikasi, listing-nya ditolak.
 *
 * ## Yang SENGAJA tidak dibuat: tabel antrean
 *
 * Formulir ini mengirim email ke admin lab, dan tidak mencatat apa pun di
 * database. Konsekuensinya jujur dan harus diketahui: **SLA "diproses ≤ 7 hari"
 * dilacak di kotak masuk admin, bukan di aplikasi.** Kalau permintaan lewat
 * jalur ini jadi sering, tabel antrean yang beneran tercatat adalah langkah
 * berikutnya — bukan sesuatu yang bisa disimpulkan dari nol data.
 *
 * Jalur tercepat tetap di dalam aplikasi (`DELETE /saya`), yang langsung jadi
 * tanpa menunggu siapa pun. Halaman ini menyebutnya lebih dulu.
 *
 * ## Balasannya selalu sama
 *
 * Terdaftar atau tidak, jawabannya satu kalimat yang sama. Kalau dibedakan,
 * halaman publik ini jadi alat menyisir email pelanggan PT Sidik dari luar —
 * persis yang sudah dijaga di seluruh jalur auth.
 */
class HapusAkunWebController extends Controller
{
    /**
     * `organization` WAJIB ikut: `layouts.publik` memakainya di judul dan kop
     * halaman, dan tanpa itu halamannya 500 — bukan "logo hilang", tapi
     * `Undefined variable`. Sama seperti `VerificationController::beranda()`.
     */
    public function tampil(): View
    {
        return view('hapus-akun', ['organization' => Organization::query()->first()]);
    }

    public function kirim(Request $request, PenerimaNotifikasi $penerima): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:filter', 'max:254'],
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        // Emailnya cuma dikirim kalau akunnya memang ada — tapi balasan ke
        // pengunjung TIDAK berubah. Yang dihemat pekerjaan admin, bukan
        // informasi yang bocor.
        $akun = User::query()
            ->where('email', $email)
            ->where('role', User::ROLE_PELANGGAN)
            ->whereNull('dianonimkan_pada')
            ->first();

        if ($akun !== null) {
            $this->teruskanKeAdmin($akun, $email, $data['alasan'] ?? null, $penerima);
        }

        return back()->with('terkirim', true);
    }

    private function teruskanKeAdmin(User $akun, string $email, ?string $alasan, PenerimaNotifikasi $penerima): void
    {
        try {
            $admin = $penerima->adminAktif((int) $akun->organization_id)
                ->pluck('email')
                ->filter()
                ->values()
                ->all();

            if ($admin === []) {
                Log::warning('Permintaan hapus akun web tidak punya admin tujuan.', ['user_id' => $akun->getKey()]);

                return;
            }

            Mail::to($admin)->send(new PermintaanHapusAkunWeb($email, $alasan, (int) $akun->getKey()));
        } catch (\Throwable $e) {
            // Halaman publik tidak boleh menampilkan galat teknis, dan
            // pengunjungnya tidak bisa berbuat apa-apa soal SMTP yang mati.
            Log::warning('Gagal meneruskan permintaan hapus akun web.', [
                'user_id' => $akun->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
