<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalibrationSession;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\WorksheetVisionExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * AI Vision baca tabel lembar kerja dari foto.
 *
 * ## Status: CADANGAN, bukan jalur utama
 *
 * Dulu ini memang pengganti OCR di HP. Sekarang kebalikannya: aplikasi mobile
 * memakai jalur pindai LOKAL (`POST /worksheet-scans`, ML Kit on-device) dan
 * **nggak pernah manggil endpoint ini lagi** — nggak ada satu pun pemanggilan
 * `raw-measurements/extract-from-photo` yang tersisa di repo mobile.
 *
 * Endpointnya sengaja nggak dihapus: dia jalur yang sudah terbukti jalan, dan
 * enam lembar yang jalur lokalnya hidup itu baru terverifikasi belakangan.
 * Tapi karena dia MENGIRIM FOTO LEMBAR KERJA PELANGGAN KE LAYANAN PIHAK KETIGA
 * (Gemini/Anthropic) dan nggak ada yang memanggilnya, dia jadi jalur keluarnya
 * data yang nggak terpakai — dan jalur semacam itu paling gampang lolos dari
 * perhatian waktu ditinjau. Karena itu ada saklarnya: `VISION_AKTIF=false`
 * mematikan endpoint ini tanpa menghapus kodenya.
 *
 * Berlaku buat SEMUA jenis alat (pH, Turbidimeter, Chlorine, Refractometer),
 * bukan cuma pH: bentuk tabelnya sama, yang beda cuma petunjuk `satuan` /
 * `titik_nominal` / `desimal` yang dikirim mobile dari bentuk lembar kerja
 * profil alatnya. Buat Refractometer isinya `satuan=n20D` &
 * `titik_nominal=[1.33659, 1.39986]` — petunjuk nominal itu yang bikin model
 * nggak salah baca "1,3362" jadi "13362".
 *
 * Endpoint: POST /api/raw-measurements/extract-from-photo (SPEC-vision-prompt.md §8).
 * Satu foto = satu tabel (Before ATAU After adjustment). Balik { baris: [...] }
 * + skor keyakinan per sel. Hasilnya SENGAJA nggak disimpen: teknisi
 * konfirmasi/koreksi dulu (sel "low" ditandai), baru submit lewat
 * POST/PUT /calibrations (alur yang udah ada). Kalau AI gagal/nolak, teknisi
 * TETAP bisa isi manual — kamera mempercepat, bukan syarat.
 */
class WorksheetExtractionController extends Controller
{
    public function extract(
        Request $request,
        WorksheetVisionExtractor $extractor,
        CalibrationProfileRegistry $registry,
    ): JsonResponse {
        // Nama field ngikut yang dikirim mobile (ApiWorksheetVisionService):
        // `foto`, `jumlah_titik`, `jumlah_pengulangan`, `calibration_session_id`.
        $data = $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'jumlah_titik' => ['sometimes', 'nullable', 'integer', 'between:1,20'],
            'jumlah_pengulangan' => ['sometimes', 'nullable', 'integer', 'between:1,50'],
            // Petunjuk buat AI biar nangkap angka lebih akurat: satuan pembacaan,
            // nilai nominal tiap kolom (kiri→kanan), & jumlah desimal tipikalnya.
            // Mobile ngambil ini dari bentuk lembar kerja (larutan_standar/satuan).
            'satuan' => ['sometimes', 'nullable', 'string', 'max:16'],
            'titik_nominal' => ['sometimes', 'nullable', 'array', 'max:20'],
            'titik_nominal.*' => ['numeric'],
            'desimal' => ['sometimes', 'nullable', 'array', 'max:20'],
            'desimal.*' => ['integer', 'between:0,8'],
            // Bentuk KERTASNYA, bukan isinya. Dua-duanya default ke bentuk
            // lembar pH biar mobile lama (yang nggak ngirim apa-apa) nggak
            // berubah perilakunya sama sekali.
            //
            // `kolom_suhu=false` + `standar_di_baris=true` = lembar
            // Spectrophotometer: satu angka per sel (nggak ada kolom °C —
            // suhu ruang dicatat sekali di Env. Condition) dan standarnya
            // turun ke bawah sementara Repeat berjajar ke kanan. Tanpa dua
            // penanda ini, prompt & skema yang dikirim ke model masih
            // MEWAJIBKAN suhu per sel, jadi modelnya ngarang angka suhu atau
            // memampatkan tiga Repeat jadi satu baris — dan yang nyampe
            // teknisi cuma "gagal baca, isi manual".
            'kolom_suhu' => ['sometimes', 'nullable', 'boolean'],
            'standar_di_baris' => ['sometimes', 'nullable', 'boolean'],
            // Aturannya `nullable`, TAPI jalur ini menolak yang kosong.
            //
            // Bentuknya sengaja nggak dinaikkan jadi `required`: yang menolak
            // `bentukKertas()`, dan penolakannya pulang 422 `fallback_manual`
            // yang dimengerti aplikasi — bukan 422 validasi yang cuma menyebut
            // nama kolom. Dinaikkan di sini, klien lama dapat pesan yang nggak
            // bisa dia terjemahkan jadi "isi manual aja".
            //
            // Yang PENTING buat perubahan berikutnya: tanpa sesi nggak ada
            // alat, tanpa alat nggak ada profil, dan tanpa profil nggak ada
            // yang tahu kertas ini boleh dikirim ke penyedia AI pihak ketiga
            // atau nggak. Itu dulu lolos karena bawaannya `didukung: true`, dan
            // seluruh lembar yang sengaja ditolak bisa dikirim keluar cukup
            // dengan menghilangkan kolom ini. Jangan dibikin "jalan lagi tanpa
            // sesi" tanpa menyediakan cara lain buat tahu kertasnya apa.
            'calibration_session_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'foto.required' => 'Fotonya wajib ada.',
            'foto.image' => 'File-nya harus berupa gambar.',
            'foto.max' => 'Foto maksimal 8 MB.',
        ]);

        $user = $request->user();
        $sesi = $this->sesiTervalidasi($data['calibration_session_id'] ?? null, $user);

        if (! (bool) config('services.vision.aktif', true)) {
            return $this->tolak(
                $sesi,
                $user,
                'dimatikan',
                'Pindai AI dimatikan di server (`VISION_AKTIF=false`).',
                'Pindai AI lagi dimatikan. Pakai pindai lembar penuh atau isi manual.',
                503,
            );
        }

        $bentukKertas = $this->bentukKertas($data, $sesi, $registry);

        if (! $bentukKertas['didukung']) {
            // Ditolak SEBELUM fotonya dikirim ke mana pun. Bentuk kertas yang
            // nggak bisa dituturkan bukan cuma bikin hasilnya jelek — dia bikin
            // hasilnya SALAH TAPI WAJAR, dan itu yang lolos sampai sertifikat.
            return $this->tolak(
                $sesi,
                $user,
                'bentuk_tidak_didukung',
                'Bentuk kertas alat ini nggak bisa dituturkan ke pembaca foto.',
                'Lembar kerja alat ini belum bisa dibaca pindai AI — bentuk tabelnya beda dari '
                    .'yang dimengerti pembaca foto. Pakai pindai lembar penuh atau isi manual.',
                422,
            );
        }

        $file = $request->file('foto');

        try {
            $hasil = $extractor->extract(
                (string) file_get_contents($file->getRealPath()),
                (string) $file->getMimeType(),
                $data['jumlah_titik'] ?? null,
                $data['jumlah_pengulangan'] ?? null,
                $data['satuan'] ?? null,
                isset($data['titik_nominal'])
                    ? array_map('floatval', array_values($data['titik_nominal']))
                    : null,
                isset($data['desimal'])
                    ? array_map('intval', array_values($data['desimal']))
                    : null,
                $bentukKertas['kolom_suhu'],
                $bentukKertas['standar_di_baris'],
            );
        } catch (RuntimeException $e) {
            // Salah setup server (API key kosong) — bukan salah teknisi.
            report($e);

            // Ikut dicatat: kriteria SPEC-vision-ai-worksheet-extraction.md §5
            // minta log ditulis buat SETIAP percobaan, sukses maupun gagal.
            // Sebelumnya jalur ini return duluan, jadi kalau API key kelupaan
            // diisi di server, tombol foto teknisi mati tanpa nyisain jejak
            // apa pun di DB — yang kelihatan cuma "kok nggak jalan".
            WorksheetExtractionLog::create([
                'calibration_session_id' => $sesi?->id,
                'user_id' => $user->id,
                // Model penyedia yang AKTIF, bukan Anthropic terus-terusan:
                // lab ini jalan di `VISION_DRIVER=gemini`, jadi baris lama
                // nyatat gagalnya `GEMINI_API_KEY` kosong sebagai model Claude.
                'model' => WorksheetVisionExtractor::modelAktif(),
                'status' => 'belum_disetel',
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Fitur pindai AI belum aktif di server. Gunakan input manual dulu.',
            ], 503);
        }

        // Catat SETIAP percobaan (sukses/gagal) buat audit & bahan tuning prompt (SPEC §7).
        WorksheetExtractionLog::create([
            'calibration_session_id' => $sesi?->id,
            'user_id' => $user->id,
            'model' => $hasil['model'],
            'status' => $hasil['status'],
            'raw_model_response' => $hasil['raw'],
            'extracted' => $hasil['data'],
            'input_tokens' => $hasil['usage']['input_tokens'],
            'output_tokens' => $hasil['usage']['output_tokens'],
            // Buat mastiin prompt caching beneran kena (SPEC-vision-prompt.md §5).
            // Harus > 0 mulai panggilan ke-2; kalau selalu 0/null berarti prefix
            // yang di-cache masih di bawah ambang minimum model — API-nya nggak
            // ngasih error buat kasus itu, jadi ini satu-satunya caranya kelihatan.
            'cache_read_input_tokens' => $hasil['usage']['cache_read_input_tokens'],
            'error' => $hasil['error'],
        ]);

        if (! $hasil['ok']) {
            // 422: kebaca tapi hasil nggak kepakai. Mobile nampilin pesan +
            // tombol "Isi manual" / "Foto ulang".
            return response()->json([
                'message' => $hasil['error'] ?? 'Gagal membaca lembar kerja dari foto.',
                'fallback_manual' => true,
            ], 422);
        }

        // `baris` di TOP-LEVEL (bukan dibungkus `data`) — sesuai bentuk yang
        // di-parse ApiWorksheetVisionService (docs/permintaan-backend-2026-07-24.md §4).
        // `meta` tambahan; key asing diabaikan mobile.
        return response()->json([
            'baris' => $hasil['data']['baris'],
            // Nilai standar yang KEBACA di kertas, urut sama dengan tiap
            // `ph`/`suhu` di dalam `baris`. Sebelumnya ini dibaca model, ditulis
            // ke log, lalu dibuang sebelum sampai ke HP — jadi mobile cuma
            // punya urutan kolom buat nebak titik mana yang mana. Di lembar
            // Spectrophotometer tebakan itu nggak bisa dipakai: satu lembar
            // punya belasan panjang gelombang, dan salah satu baris kelewat
            // bikin semua sisanya mendarat di panjang gelombang yang salah.
            'standard_value' => $hasil['data']['standard_value'] ?? [],
            'meta' => [
                'model' => $hasil['model'],
                // Ringkasan buat mobile: ada nggak sel yang perlu dicek manual.
                'perlu_dicek' => $this->adaKeyakinanRendah($hasil['data']['baris'] ?? []),
            ],
        ]);
    }

    /**
     * Bentuk kertas: yang dikirim mobile menang, sisanya ditebak dari sesinya.
     *
     * Kenapa ada tebakan sama sekali: aplikasi yang udah kepasang di HP teknisi
     * nggak ngirim dua penanda ini, dan nggak bisa dipaksa update sebelum
     * kalibrasi berikutnya jalan. Kalau default-nya dibiarin bentuk lembar pH,
     * tiap foto lembar Spectrophotometer dari aplikasi lama tetap gagal —
     * padahal sesinya sendiri udah nyebut alatnya apa.
     *
     * Yang ditanya profil alatnya, bukan daftar nama alat di sini: profil baru
     * yang kertasnya beda tinggal override `bentukPindaiFoto()` dan jalur ini
     * ikut benar tanpa disentuh.
     *
     * `didukung` SENGAJA nggak bisa ditimpa pemanggil. Dua penanda di atas itu
     * soal bentuk yang bisa dipilih; `didukung` soal bentuk yang nggak bisa
     * digambarkan sama sekali — dan klien yang keliru ngirim `kolom_suhu` nggak
     * mengubah kenyataan itu.
     *
     * ## TANPA SESI, `didukung` JATUH KE `false` — dan itu perbaikan keamanan
     *
     * `calibration_session_id` divalidasi `sometimes|nullable`, jadi pemanggil
     * boleh nggak mengirimnya sama sekali. Waktu itu terjadi nggak ada alat,
     * nggak ada profil, dan **nggak ada yang tahu kertas ini bentuknya apa**.
     *
     * Bawaannya dulu `true` di keadaan itu, dan akibatnya bukan sekadar tebakan
     * bentuk yang meleset: gerbang di bawah ikut terbuka, dan SELURUH lembar
     * yang sengaja ditolak — Autoklaf, TIDS, kelima Enclosure — bisa dikirim ke
     * penyedia AI pihak ketiga **cukup dengan menghilangkan satu kolom opsional
     * dari permintaannya.** Pemisahan `didukung`/`lokal` yang baru saja dibuat
     * nggak menutup itu; dia cuma memindahkan pintunya.
     *
     * Sekarang gagalnya MENUTUP. Yang nggak bisa dibuktikan muat, ditolak —
     * dan "nggak tahu kertasnya apa" itu bentuk paling murni dari nggak bisa
     * dibuktikan. Dua penanda bentuk di atas tetap menebak seperti dulu:
     * salah tebak di sana bikin hasilnya jelek, salah tebak di sini bikin foto
     * pelanggan keluar.
     *
     * `lokal` yang ikut pulang dari `bentukPindaiFoto()` SENGAJA dibuang di
     * sini, dan itu inti pemisahannya. Penanda itu menggerbangi tombol kamera
     * ON-DEVICE; jalur ini yang MENGIRIM FOTONYA KELUAR. Membacanya di sini —
     * atau menyatukannya lagi dengan `didukung` — bikin tiap lembar yang
     * kameranya dinyalakan ikut memenuhi syarat dikirim ke penyedia AI pihak
     * ketiga. Persis yang kejadian 27 Agt 2026 waktu TIDS dinyalakan.
     *
     * @param  array<string, mixed>  $data
     * @return array{kolom_suhu: bool, standar_di_baris: bool, didukung: bool}
     */
    private function bentukKertas(
        array $data,
        ?CalibrationSession $sesi,
        CalibrationProfileRegistry $registry,
    ): array {
        $alat = $sesi?->equipment;

        // TANPA ALAT, GAGALNYA MENUTUP — lihat docblock.
        //
        // Ditangani sebagai cabang sendiri, BUKAN dengan menurunkan bawaan
        // `$bawaan` di bawah jadi `false`. Bedanya kelihatan sepele dan nggak:
        // `SpectrophotometerProfile` & `ViscometerProfile` override
        // `bentukPindaiFoto()` TANPA menyebut `didukung`, jadi mereka mewarisi
        // nilainya dari bawaan gabungan ini. Menurunkan bawaannya diam-diam
        // mematikan jalur foto dua lembar yang sebenarnya didukung — sudah
        // kejadian waktu perbaikan ini ditulis, dan yang menangkapnya cuma
        // `WorksheetExtractionSpektroTest`.
        if ($alat === null) {
            return [
                'kolom_suhu' => (bool) ($data['kolom_suhu'] ?? true),
                'standar_di_baris' => (bool) ($data['standar_di_baris'] ?? false),
                'didukung' => false,
            ];
        }

        $bawaan = [
            'kolom_suhu' => true,
            'standar_di_baris' => false,
            'didukung' => true,
            ...$registry->untukAlat($alat)->bentukPindaiFoto(),
        ];

        return [
            'kolom_suhu' => (bool) ($data['kolom_suhu'] ?? $bawaan['kolom_suhu']),
            'standar_di_baris' => (bool) ($data['standar_di_baris'] ?? $bawaan['standar_di_baris']),
            'didukung' => (bool) $bawaan['didukung'],
        ];
    }

    /**
     * Tolak sebelum fotonya dikirim ke mana pun — tetap ninggalin jejak.
     *
     * Percobaan yang ditolak WAJIB kecatat sama kayak yang gagal di tengah
     * jalan (SPEC-vision-ai-worksheet-extraction.md §5). Tanpa barisnya, yang
     * kelihatan cuma "tombol fotonya nggak jalan" tanpa satu pun keterangan
     * kenapa — dan itu persis keadaan yang bikin fitur ini susah ditelusuri
     * dulu.
     */
    private function tolak(
        ?CalibrationSession $sesi,
        User $user,
        string $status,
        string $error,
        string $pesan,
        int $kode,
    ): JsonResponse {
        WorksheetExtractionLog::create([
            'calibration_session_id' => $sesi?->id,
            'user_id' => $user->id,
            'model' => WorksheetVisionExtractor::modelAktif(),
            'status' => $status,
            'error' => $error,
        ]);

        return response()->json([
            'message' => $pesan,
            'fallback_manual' => true,
        ], $kode);
    }

    /**
     * Sesi yang dikirim: pastikan seorganisasi & (buat teknisi) miliknya
     * sendiri. Id yang nggak ketemu di organisasi itu 404; sesi teknisi lain
     * 403.
     *
     * **Nggak dikirim → null, dan pemanggilnya MENOLAK di situ.** Dulu nggak
     * begitu: ekstraksi tetap jalan tanpa tautan sesi, "cuma baca foto, nggak
     * buka data apa pun". Yang nggak ikut ditimbang waktu itu — tanpa sesi
     * nggak ada alat, tanpa alat nggak ada profil, dan gerbang bentuk kertas
     * ikut terbuka. Seluruh lembar yang sengaja ditolak bisa dikirim ke
     * penyedia AI pihak ketiga cukup dengan menghilangkan satu kolom opsional.
     *
     * Lihat `bentukKertas()`. Null di sini sekarang berarti DITOLAK, bukan
     * "jalan tanpa tautan".
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
            'Cuma teknisi yang ngerjain sesi ini yang boleh mindai lembarnya.',
        );

        return $sesi;
    }

    /** @param array<int, array<string, mixed>> $baris */
    private function adaKeyakinanRendah(array $baris): bool
    {
        foreach ($baris as $b) {
            foreach (['ph_keyakinan', 'suhu_keyakinan'] as $kolom) {
                if (in_array('low', (array) ($b[$kolom] ?? []), true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
