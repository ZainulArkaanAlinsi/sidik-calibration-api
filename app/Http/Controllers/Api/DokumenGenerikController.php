<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalibrationSession;
use App\Models\DokumenBacaan;
use App\Models\DokumenBacaanNilai;
use App\Models\User;
use App\Services\Dokumen\EkstraktorDokumenGenerik;
use App\Services\Dokumen\PembuatSkemaDinamis;
use App\Services\Dokumen\PenandaDariRiwayat;
use App\Services\Dokumen\PenyimpanBacaanDokumen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Baca lembar kerja APA PUN jadi skema form — termasuk yang belum pernah
 * dilihat sistem.
 *
 * ## Kenapa ini ada
 *
 * `POST /worksheet-scans` menolak lembar yang belum punya profil: "Template
 * lembar kerja nggak dikenal." Artinya sistem cuma sanggup membaca lembar yang
 * dia cetak sendiri, dan lembar baru butuh dua kerjaan manual — satu kelas
 * profil, plus koordinat tiap sel yang diukur tangan dari kertasnya.
 *
 * Endpoint ini jalur satunya: baca dokumennya dulu, bentuk formnya menyusul
 * dari isi kertas. Bukan pengganti jalur template — yang template lebih teliti
 * justru karena dia tahu bentuk yang dicari. Ini yang menjawab lembar yang
 * belum dikenal, biar jawabannya bukan "nggak didukung".
 *
 * ## SATU SAKLAR SAMA dengan AI Vision, dan itu disengaja
 *
 * Endpoint ini MENGIRIM FOTO LEMBAR KERJA PELANGGAN KE LAYANAN PIHAK KETIGA,
 * dan cakupannya LEBIH LUAS dari jalur AI Vision yang sudah ada: yang itu
 * mengirim foto tabel, yang ini mengirim seluruh halaman — kop surat, nama
 * pelanggan, dan nomor sertifikat ikut di dalamnya.
 *
 * Jadi dia nempel di `VISION_AKTIF` yang sama. Bikin saklar kedua berarti lab
 * yang sudah mematikan pengiriman foto lewat `VISION_AKTIF=false` tetap
 * mengirim foto lewat jalur ini tanpa sadar — dan jalur keluar data yang
 * nggak keliatan itu persis yang paling gampang lolos waktu ditinjau.
 *
 * ## Yang disimpan, dan yang TIDAK
 *
 * Pembacaannya disimpan (`dokumen_bacaan` + `dokumen_bacaan_nilai`), persis
 * seperti `POST /worksheet-scans` menyimpan hasil pindainya. Yang TIDAK lahir
 * di sini: `raw_measurements`. Itu tetap dari `POST/PUT /calibrations` sesudah
 * teknisi mengoreksi.
 *
 * Bedanya penting: menyimpan PEMBACAAN bukan berarti mengesahkan
 * PENGUKURANNYA. Baris di `dokumen_bacaan` itu catatan "kamera membaca ini",
 * bukan "angka ini benar".
 */
class DokumenGenerikController extends Controller
{
    public function baca(
        Request $request,
        EkstraktorDokumenGenerik $ekstraktor,
        PembuatSkemaDinamis $pembuatSkema,
        PenyimpanBacaanDokumen $penyimpan,
        PenandaDariRiwayat $penanda,
    ): JsonResponse {
        $data = $request->validate([
            'foto' => ['required', 'image', 'max:8192'],
            // Nama alat itu KONTEKS opsional. Sengaja string bebas, bukan
            // pilihan dari daftar tetap: sistem harus menerima nama alat apa
            // pun, dan daftar yang menentukan kemampuan sistem itu persis yang
            // bikin lembar baru mentok.
            'nama_alat' => ['sometimes', 'nullable', 'string', 'max:255'],
            'calibration_session_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'foto.required' => 'Fotonya belum ada.',
            'foto.image' => 'File-nya harus berupa gambar.',
            'foto.max' => 'Foto maksimal 8 MB.',
        ]);

        // Divalidasi SEBELUM fotonya berangkat ke mana pun. Id sesi datang
        // dari input, dan input yang menunjuk baris lab lain itu parameter
        // serangan — bukan data.
        $sesi = $this->sesiTervalidasi($data['calibration_session_id'] ?? null, $request->user());

        if (! (bool) config('services.vision.aktif', true)) {
            return response()->json([
                'ok' => false,
                'status' => 'dimatikan',
                'pesan' => 'Baca dokumen dimatikan di server (`VISION_AKTIF=false`). '
                    .'Pakai pindai lembar bertemplate atau isi manual.',
            ], 503);
        }

        $berkas = $request->file('foto');

        try {
            $hasil = $ekstraktor->ekstrak(
                base64_encode((string) file_get_contents($berkas->getRealPath())),
                (string) $berkas->getMimeType(),
                $data['nama_alat'] ?? null,
            );
        } catch (RuntimeException $e) {
            // Kunci API belum diisi itu salah SETUP, bukan salah teknisi.
            // Dibedain dari kegagalan baca supaya yang di lapangan nggak
            // motret ulang buat sesuatu yang mustahil berhasil.
            return response()->json([
                'ok' => false,
                'status' => 'salah_setup',
                'pesan' => $e->getMessage(),
            ], 503);
        }

        if (! $hasil['ok']) {
            return response()->json([
                'ok' => false,
                'status' => $hasil['status'],
                'pesan' => $hasil['error'],
            ], 422);
        }

        // Riwayat koreksi dipakai SEBELUM skema dibangun, supaya ringkasannya
        // ikut menghitung yang ditandai. Yang ditandai cuma STATUS-nya —
        // nilainya nggak pernah disentuh.
        $skema = $pembuatSkema->dari(
            $penanda->tandai($hasil['dokumen'], $request->user()->organization_id),
        );

        $bacaan = $penyimpan->simpan(
            $request->user(),
            $skema,
            $data['nama_alat'] ?? null,
            $hasil['model'],
            $hasil['usage'],
            $sesi?->id,
        );

        return response()->json([
            'ok' => true,
            // Id-nya dipakai HP buat ngirim koreksi & buka ulang layar review.
            'id' => $bacaan->id,
            'data' => $skema,
            'model' => $hasil['model'],
            'usage' => $hasil['usage'],
        ]);
    }

    /**
     * Buka ulang satu hasil baca tanpa manggil AI lagi.
     *
     * Skemanya diambil dari yang TERSIMPAN, bukan dibaca ulang dari fotonya.
     * Baca ulang bukan cuma mahal — hasilnya bisa beda dari yang barusan
     * dikoreksi teknisi, dan koreksinya hilang tanpa ada yang tahu.
     */
    public function show(Request $request, DokumenBacaan $dokumenBacaan): JsonResponse
    {
        $this->pastikanBolehLihat($request, $dokumenBacaan);

        return response()->json([
            'ok' => true,
            'id' => $dokumenBacaan->id,
            'status' => $dokumenBacaan->status,
            'data' => $dokumenBacaan->skema,
            'nilai' => $dokumenBacaan->nilai()
                ->orderBy('id')
                ->get([
                    'kunci', 'jenis', 'label', 'baris_ke', 'kolom_ke',
                    'nilai_baca', 'nilai_final', 'status', 'keyakinan', 'cocok',
                ]),
        ]);
    }

    /**
     * Catat koreksi teknisi.
     *
     * Yang disimpan PASANGANNYA — `nilai_baca` nggak pernah ditimpa. Ditimpa,
     * yang hilang justru satu-satunya hal yang bikin baris ini berharga: bukti
     * bahwa modelnya salah, dan salahnya jadi apa. Itu bahan latih yang paling
     * mahal dikumpulkan, dan sekali ketimpa nggak bisa direkonstruksi.
     */
    public function koreksi(Request $request, DokumenBacaan $dokumenBacaan): JsonResponse
    {
        $this->pastikanBolehLihat($request, $dokumenBacaan);

        $data = $request->validate([
            'koreksi' => ['required', 'array', 'min:1', 'max:600'],
            'koreksi.*.kunci' => ['required', 'string', 'max:120'],
            // String, bukan numeric: jalur generik membaca nama alat, tanggal,
            // dan checkbox juga. `numeric` di sini bakal menolak koreksi yang
            // sah buat separuh isi lembarnya.
            'koreksi.*.nilai_final' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], [
            'koreksi.required' => 'Nggak ada koreksi yang dikirim.',
        ]);

        // Lewat relasi induk yang SUDAH tersaring organisasinya — bukan
        // `DokumenBacaanNilai::query()`, yang bakal lintas-organisasi walau
        // nggak ada kolom yang kelihatan salah.
        $nilai = $dokumenBacaan->nilai()->get()->keyBy('kunci');

        $tidakDikenal = [];
        $cocok = 0;
        $meleset = 0;

        DB::transaction(function () use (
            $data, $nilai, $request, &$tidakDikenal, &$cocok, &$meleset
        ): void {
            foreach ($data['koreksi'] as $k) {
                /** @var DokumenBacaanNilai|null $baris */
                $baris = $nilai->get($k['kunci']);

                if ($baris === null) {
                    // Kunci asing DILAPORKAN, bukan dibuang diam-diam: kalau HP
                    // dan server beda versi skema, gejalanya "koreksi saya
                    // nggak kesimpen" tanpa satu pun error.
                    $tidakDikenal[] = $k['kunci'];

                    continue;
                }

                $final = array_key_exists('nilai_final', $k) && $k['nilai_final'] !== null
                    ? trim((string) $k['nilai_final'])
                    : null;

                $sama = $final !== null && $baris->nilai_baca !== null
                    && $this->samaSetelahDirapikan($final, $baris->nilai_baca);

                if ($final !== null) {
                    $sama ? $cocok++ : $meleset++;
                }

                $baris->update([
                    'nilai_final' => $final,
                    // Nilai yang dikosongkan teknisi = bacaannya memang salah,
                    // bukan "belum diperiksa". Itu sebabnya `cocok` di situ
                    // `false`, bukan tetap `null`.
                    'cocok' => $final === null && $baris->nilai_baca === null ? true : $sama,
                    'dikoreksi_oleh' => $request->user()->id,
                    'dikoreksi_pada' => now(),
                ]);
            }
        });

        return response()->json([
            'ok' => true,
            'cocok' => $cocok,
            'meleset' => $meleset,
            'kunci_tidak_dikenal' => $tidakDikenal,
        ]);
    }

    /**
     * Bandingkan tanpa terganggu koma vs titik dan spasi.
     *
     * `25,4` dan `25.4` itu angka yang SAMA — teknisi yang mengetik ulang pakai
     * titik bukan sedang mengoreksi apa pun. Dihitung meleset, statistik
     * akurasinya jadi bohong ke arah yang bikin model kelihatan lebih jelek
     * dari kenyataannya.
     */
    private function samaSetelahDirapikan(string $a, string $b): bool
    {
        $rapikan = static fn (string $s): string => str_replace(
            [',', ' '], ['.', ''], trim($s),
        );

        return $rapikan($a) === $rapikan($b);
    }

    /**
     * Sesi opsional, tapi kalau dikirim WAJIB milik lab & teknisi yang benar.
     *
     * Opsional di sini beda artinya dari di jalur AI Vision, dan bedanya
     * disengaja. Di sana sesi yang hilang membuka gerbang bentuk kertas —
     * lembar yang sengaja ditolak bisa dikirim ke penyedia pihak ketiga cukup
     * dengan menghilangkan satu kolom. Di jalur ini nggak ada gerbang seperti
     * itu buat dilewati: menerima lembar APA PUN memang tujuannya, dan yang
     * mengendalikan pengiriman fotonya `VISION_AKTIF`.
     *
     * Yang tetap berlaku penuh: id yang DIKIRIM harus lolos pemeriksaan
     * kepemilikan. Teknisi bisa saja menautkan bacaannya ke sesi lab lain
     * cuma dengan menebak angka.
     */
    private function sesiTervalidasi(?int $sesiId, User $user): ?CalibrationSession
    {
        if ($sesiId === null) {
            return null;
        }

        $sesi = CalibrationSession::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($sesiId);

        abort_if(
            $user->role === User::ROLE_TEKNISI && $sesi->teknisi_id !== $user->id,
            403,
            'Cuma teknisi yang ngerjain sesi ini yang boleh nautin bacaan ke situ.',
        );

        return $sesi;
    }

    /**
     * Penjaga kepemilikan DUA lapis, dan bedanya disengaja.
     *
     * Organisasi lain -> 404: 403 mengakui barisnya ada, dan itu membocorkan
     * keberadaan data lab lain walau isinya nggak ikut keluar.
     *
     * Teknisi lain di organisasi yang sama -> 403: barisnya memang ada dan
     * dia berhak tahu itu, cuma bukan miliknya.
     */
    private function pastikanBolehLihat(Request $request, DokumenBacaan $bacaan): void
    {
        $user = $request->user();

        abort_if(
            $bacaan->organization_id !== $user->organization_id,
            404,
            'Hasil baca nggak ketemu.',
        );

        abort_if(
            $user->role === User::ROLE_TEKNISI && $bacaan->user_id !== $user->id,
            403,
            'Hasil baca ini punya teknisi lain.',
        );
    }
}
