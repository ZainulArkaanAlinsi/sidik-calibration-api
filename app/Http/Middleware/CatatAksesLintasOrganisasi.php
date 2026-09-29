<?php

namespace App\Http\Middleware;

use App\Services\PenjagaOrganisasi;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jaring pengaman: catat percobaan lintas lab yang lolos dari `PenjagaOrganisasi`.
 *
 * ## Kenapa perlu jaring kedua
 *
 * `PenjagaOrganisasi::pastikanSatu()` mencatat kalau dia dipanggil. Yang tidak
 * dia tangkap: controller yang lupa memanggilnya, dan controller yang menolak
 * lewat jalan lain — `->where('organization_id', ...)->findOrFail()`, scope
 * global, atau `firstOrFail()` yang memang sudah tersaring.
 *
 * Jalan-jalan itu semuanya **benar** dari sisi keamanan: datanya tidak bocor.
 * Yang hilang cuma jejaknya. Middleware ini menambal itu dari sisi respons,
 * tanpa menyentuh satu pun controller — jadi ia juga menutupi controller yang
 * ditulis besok oleh orang yang belum pernah membaca berkas ini.
 *
 * ## Kenapa aman ditaruh global
 *
 * Dia cuma bekerja kalau **semua** syarat ini terpenuhi:
 *
 *   1. responsnya 404, dan
 *   2. pemanggilnya sudah login, dan
 *   3. ada parameter rute berupa angka, dan
 *   4. baris dengan id itu memang ADA di tabelnya tapi milik lab lain.
 *
 * Syarat 1 sendirian sudah membuat dia hampir tidak pernah jalan: 404 di API
 * yang sehat itu langka. Kueri di syarat 4 cuma satu `SELECT organization_id
 * WHERE id = ?` — dan itu jalan setelah responsnya sudah selesai dibentuk, jadi
 * dia tidak bisa memperlambat request yang berhasil.
 *
 * ## Yang dia TIDAK lakukan
 *
 * Tidak mengubah respons. Tidak pernah mengubah 404 jadi 403, walau dia tahu
 * barisnya ada. Alasannya di docblock `PenjagaOrganisasi` — 403 mengonfirmasi
 * keberadaan baris, dan itu yang mau ditutup.
 */
class CatatAksesLintasOrganisasi
{
    public function handle(Request $request, Closure $next): Response
    {
        $respons = $next($request);

        if ($respons->getStatusCode() !== 404) {
            return $respons;
        }

        if ($request->user() === null) {
            return $respons;
        }

        // Baris yang dicatat `PenjagaOrganisasi::pastikanSatu()` sudah lengkap.
        // Tanda ini mencegah satu percobaan tercatat dua kali — riwayat yang
        // menggandakan kejadian membuat orang menghitung salah waktu
        // menyelidiki.
        if ($request->attributes->get('lintas_lab_tercatat') === true) {
            return $respons;
        }

        foreach ($this->kandidat($request) as [$tabel, $id, $organisasiPemilik]) {
            PenjagaOrganisasi::catat($request, $tabel, $id, $organisasiPemilik);

            // Satu baris per request cukup. Rute dengan dua parameter model
            // (jarang) dicatat lewat parameter pertama yang cocok — yang
            // diselidiki nanti adalah pathnya, dan path-nya utuh di `new_data`.
            break;
        }

        return $respons;
    }

    /**
     * Parameter rute yang ternyata milik lab lain.
     *
     * @return iterable<array{0: string, 1: int, 2: mixed}>
     */
    private function kandidat(Request $request): iterable
    {
        $rute = $request->route();

        if ($rute === null) {
            return;
        }

        $milikPemanggil = $request->user()->organization_id;

        foreach ($rute->parameters() as $nama => $nilai) {
            // Model yang sudah ter-resolve lewat route binding: langsung kebaca,
            // tanpa kueri tambahan.
            if ($nilai instanceof Model) {
                $pemilik = $nilai->getAttribute('organization_id');

                if ($pemilik !== null && $pemilik !== $milikPemanggil) {
                    yield [$nilai->getTable(), (int) $nilai->getKey(), $pemilik];
                }

                continue;
            }

            // Parameter yang belum ter-resolve (controller memakai
            // `findOrFail()` sendiri, atau binding-nya gagal duluan). Tabelnya
            // diterka dari nama parameter — dan kalau terkaannya salah,
            // hasilnya cuma "tidak ada kandidat", bukan galat.
            if (! is_numeric($nilai)) {
                continue;
            }

            $tabel = $this->tabelDariNamaParameter((string) $nama);

            if ($tabel === null) {
                continue;
            }

            $pemilik = DB::table($tabel)->where('id', (int) $nilai)->value('organization_id');

            if ($pemilik !== null && $pemilik !== $milikPemanggil) {
                yield [$tabel, (int) $nilai, $pemilik];
            }
        }
    }

    /**
     * Nama parameter rute → nama tabel, kalau tabelnya ada & punya `organization_id`.
     *
     * `{calibration}` → `calibrations`? Bukan — tabelnya `calibration_sessions`.
     * Jadi terkaan plural tidak cukup, dan peta kecil ini yang menutup
     * ketidakcocokan yang memang ada di repo ini. Yang tidak ada di peta dan
     * tidak cocok plural-nya cuma tidak tercatat lewat jalur ini — dan itu
     * bukan lubang keamanan, cuma satu baris riwayat yang hilang untuk rute
     * yang controller-nya memakai `findOrFail()` sendiri.
     */
    private function tabelDariNamaParameter(string $nama): ?string
    {
        static $peta = [
            'calibration' => 'calibration_sessions',
            'certificate' => 'certificates',
            'equipment' => 'equipments',
            'customer' => 'customers',
            'standard' => 'standards',
            'folder' => 'folders',
            'folderFile' => 'folder_files',
            'order' => 'orders',
            'room' => 'rooms',
            'formula' => 'formulas',
            'user' => 'users',
            'penugasan' => 'penugasan',
        ];

        if (! isset($peta[$nama])) {
            return null;
        }

        $tabel = $peta[$nama];

        // Tabel yang belum ada (slice lain belum dikerjakan) atau yang tidak
        // punya kolomnya: lewati tanpa galat. Hasilnya di-cache per proses —
        // skema tidak berubah di tengah request, dan tanpa cache ini setiap 404
        // membayar satu kueri `information_schema` yang mahal di MySQL.
        static $punyaKolom = [];

        $punyaKolom[$tabel] ??= Schema::hasTable($tabel)
            && Schema::hasColumn($tabel, 'organization_id');

        return $punyaKolom[$tabel] ? $tabel : null;
    }
}
