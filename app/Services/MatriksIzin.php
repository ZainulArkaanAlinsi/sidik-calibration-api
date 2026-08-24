<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Matriks izin per role (fase-2 §1) — sumbernya RUTE, bukan daftar tulis tangan.
 *
 * Yang diminta mobile: satu daftar resmi "endpoint × role × boleh/nggak", supaya
 * tombol yang bakal ditolak bisa disembunyiin, bukan dipajang lalu kena `403`.
 * Yang jadi masalah sebelumnya: aturannya ditebak dari `403` yang kejadian di
 * lapangan, dan di mobile di-hardcode (`role.bisaInput`) — tiap backend ganti
 * aturan, mobile ikut basi diam-diam.
 *
 * Makanya kelas ini **nggak nyimpen boolean sama sekali**. Yang disimpen cuma
 * PEMETAAN nama izin → (method, uri). Boleh/nggaknya dibaca langsung dari
 * middleware `role:` di rute yang beneran terdaftar. Jadi begitu ada yang
 * mindahin rute masuk/keluar blok `role:admin`, jawaban endpoint ini ikut
 * berubah di request berikutnya — nggak ada yang perlu diinget buat diupdate.
 *
 * Kalau rute yang dipetakan ilang atau ganti path, `MeIzinTest` gagal. Itu
 * sengaja: lebih baik test merah daripada endpoint yang bilang "boleh" buat
 * sesuatu yang udah nggak ada.
 */
class MatriksIzin
{
    /**
     * Nama izin → rute yang jadi penentunya.
     *
     * Nama izinnya buat konsumsi UI, jadi dijaga STABIL — jangan diganti-ganti,
     * mobile nyimpen ini di kode. Yang boleh berubah bebas itu rutenya.
     *
     * Satu izin = satu rute yang paling mewakili aksinya di layar. Nggak semua
     * rute dipetakan: `PUT`/`DELETE` yang izinnya selalu sama dengan `POST`-nya
     * digabung jadi satu nama `*.kelola`, biar mobile nggak perlu ngecek lima
     * kunci buat nyalain satu tombol.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PETA = [
        // --- alat
        'alat.lihat' => ['GET', 'api/equipments'],
        'alat.tambah' => ['POST', 'api/equipments'],
        'alat.ubah' => ['PUT', 'api/equipments/{equipment}'],
        'alat.hapus' => ['DELETE', 'api/equipments/{equipment}'],

        // --- kalibrasi
        'kalibrasi.lihat' => ['GET', 'api/calibrations'],
        'kalibrasi.buat' => ['POST', 'api/calibrations'],
        'kalibrasi.ubah' => ['PUT', 'api/calibrations/{calibration}'],
        'kalibrasi.hitung-preview' => ['POST', 'api/calibrations/preview'],
        'kalibrasi.pindai-foto' => ['POST', 'api/raw-measurements/extract-from-photo'],
        'kalibrasi.verifikasi-pindai' => ['POST', 'api/calibrations/{calibration}/measurements/verify'],
        'kalibrasi.periksa' => ['GET', 'api/calibrations/{calibration}/validasi'],
        'kalibrasi.lembar-perhitungan' => ['GET', 'api/calibrations/{calibration}/perhitungan'],
        'kalibrasi.setujui' => ['POST', 'api/calibrations/{calibration}/approve'],
        'kalibrasi.tolak' => ['POST', 'api/calibrations/{calibration}/reject'],
        'kalibrasi.ubah-field-admin' => ['PATCH', 'api/calibrations/{calibration}/admin'],

        // --- sertifikat
        'sertifikat.lihat' => ['GET', 'api/certificates'],
        'sertifikat.unduh' => ['GET', 'api/certificates/{certificate}/download'],
        'sertifikat.rekap-excel' => ['GET', 'api/certificates/export/excel'],
        'sertifikat.terbitkan-ulang' => ['POST', 'api/certificates/{certificate}/retry'],

        // --- laporan
        'laporan.lihat' => ['GET', 'api/laporan/kalibrasi'],
        'laporan.export' => ['GET', 'api/laporan/kalibrasi/export'],

        // --- arsip / Folder Manager
        'arsip.lihat' => ['GET', 'api/folders'],
        'arsip.folder.kelola' => ['POST', 'api/folders'],
        'arsip.berkas.unduh' => ['GET', 'api/folder-files/{folderFile}/download'],
        'arsip.berkas.kelola' => ['POST', 'api/folder-files'],

        // --- master data
        'pelanggan.dropdown' => ['GET', 'api/customers/lookup'],
        'pelanggan.kelola' => ['POST', 'api/customers'],
        'standar.lihat' => ['GET', 'api/standards'],
        'standar.kelola' => ['POST', 'api/standards'],
        'ruangan.lihat' => ['GET', 'api/rooms'],
        'ruangan.kelola' => ['POST', 'api/rooms'],
        'metode.lihat' => ['GET', 'api/calibration-methods'],
        'metode.kelola' => ['POST', 'api/calibration-methods'],
        'kategori.lihat' => ['GET', 'api/categories'],
        // Beda dari `*.kelola` master data lain yang admin-only: yang ini
        // TEKNISI juga boleh. Dipetakan biar tombol "+ nama alat baru" di layar
        // pilih-alat nggak perlu ditebak dari role yang di-hardcode di APK —
        // pelajaran yang sama kayak `role.bisaInput` dulu.
        'kategori.kemampuan.tambah' => ['POST', 'api/categories/{kode}/kemampuan'],
        'teknisi.kelola' => ['GET', 'api/technicians'],

        // --- organisasi & pengguna
        'organisasi.lihat' => ['GET', 'api/organization'],
        'organisasi.ubah' => ['PUT', 'api/organization'],
        'organisasi.logo' => ['POST', 'api/organization/logo'],
        'pengguna.kelola' => ['GET', 'api/users'],
        'pengguna.setujui' => ['POST', 'api/users/{user}/approve'],

        // --- lain-lain
        'impor.excel' => ['POST', 'api/imports/excel'],
        'pengingat.kirim' => ['POST', 'api/reminders/jatuh-tempo'],
        'dashboard.lihat' => ['GET', 'api/dashboard'],
        'notifikasi.lihat' => ['GET', 'api/notifications'],
    ];

    /**
     * Bagian data yang dibatesin ke milik sendiri buat role tertentu.
     *
     * Ini BUKAN soal boleh/nggak — teknisi boleh buka `/calibrations`, cuma
     * isinya disaring ke pekerjaannya sendiri. Dipisah dari `boleh` karena kalau
     * digabung, mobile nggak bisa bedain "tombolnya disembunyiin" dari "layarnya
     * kebuka tapi datanya lebih sedikit".
     *
     * Nilainya nggak bisa diturunkan dari middleware (penyaringnya di controller),
     * jadi dikunci test yang beneran manggil endpoint-nya pakai dua akun teknisi.
     *
     * @var list<string>
     */
    public const LINGKUP_SENDIRI = ['kalibrasi', 'sertifikat', 'laporan', 'dashboard'];

    /**
     * @return array{role: string, boleh: list<string>, batasan: array<string, string>}
     */
    public function untuk(User $user): array
    {
        return [
            'role' => $user->role,
            'boleh' => $this->bolehUntuk($user->role),
            'batasan' => $this->batasanUntuk($user->role),
        ];
    }

    /**
     * Izin yang berlaku buat satu role — dibaca dari middleware rutenya.
     *
     * @return list<string>
     */
    public function bolehUntuk(string $role): array
    {
        $boleh = [];

        foreach (self::PETA as $izin => [$method, $uri]) {
            $rute = $this->cariRute($method, $uri);

            // Rute yang nggak ketemu SENGAJA nggak dianggap "boleh". Kalau
            // dianggap boleh, peta yang basi bikin mobile nyalain tombol buat
            // endpoint yang udah nggak ada — kegagalan yang lebih membingungkan
            // daripada tombolnya ilang. `MeIzinTest` yang nangkep ini duluan.
            if (! $rute) {
                continue;
            }

            $roleYangBoleh = $this->roleYangBoleh($rute);

            // null = nggak ada middleware `role:` → semua role yang udah login.
            if ($roleYangBoleh === null || in_array($role, $roleYangBoleh, true)) {
                $boleh[] = $izin;
            }
        }

        return $boleh;
    }

    /**
     * @return array<string, string> `sendiri` = cuma datanya sendiri, `semua` = se-lab
     */
    public function batasanUntuk(string $role): array
    {
        $sendiri = $role === User::ROLE_TEKNISI;

        return array_combine(
            self::LINGKUP_SENDIRI,
            array_fill(0, count(self::LINGKUP_SENDIRI), $sendiri ? 'sendiri' : 'semua'),
        );
    }

    /** Rute yang dipetakan tapi nggak ada di router — dipakai test buat nangkep peta basi. */
    public function petaYangNgawur(): array
    {
        $ngawur = [];

        foreach (self::PETA as $izin => [$method, $uri]) {
            if (! $this->cariRute($method, $uri)) {
                $ngawur[$izin] = "{$method} {$uri}";
            }
        }

        return $ngawur;
    }

    private function cariRute(string $method, string $uri): ?Route
    {
        foreach (Router::getRoutes() as $rute) {
            if ($rute->uri() === $uri && in_array($method, $rute->methods(), true)) {
                return $rute;
            }
        }

        return null;
    }

    /**
     * Role yang dibolehin middleware `role:` di rute ini. `null` = nggak dibatesin.
     *
     * @return list<string>|null
     */
    private function roleYangBoleh(Route $rute): ?array
    {
        foreach ($rute->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                return array_values(array_filter(explode(',', substr($middleware, 5))));
            }
        }

        return null;
    }
}
