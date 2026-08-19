<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperStandard
 */
#[Fillable([
    'organization_id', 'nama', 'merk', 'model', 'serial_number', 'no_sertifikat',
    'tertelusur_ke', 'berlaku_sampai', 'ketidakpastian', 'satuan_ketidakpastian',
    'faktor_cakupan', 'drift', 'koefisien_suhu', 'parameter_kondisi',
])]
class Standard extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'berlaku_sampai' => 'date',
            'ketidakpastian' => 'float',
            'faktor_cakupan' => 'float',
            'drift' => 'float',
            'koefisien_suhu' => 'array',
            'parameter_kondisi' => 'array',
        ];
    }

    public const STATUS_VALID = 'valid';

    public const STATUS_WARNING = 'warning';

    public const STATUS_EXPIRED = 'expired';

    /** Standar yang sertifikatnya kadaluarsa nggak boleh dipakai kalibrasi. */
    public function masihBerlaku(): bool
    {
        return $this->berlaku_sampai === null || $this->berlaku_sampai->isFuture();
    }

    /**
     * Sisa hari sampai sertifikat standar ini kadaluarsa.
     *
     * **Bertanda**: positif = masih ada sisa, `0` = habis hari ini, **negatif =
     * udah lewat** (mis. `-12` artinya kadaluarsa 12 hari lalu). Sengaja bukan
     * di-nol-in, biar layar bisa bilang "lewat 12 hari" bukan cuma "kadaluarsa".
     *
     * `null` kalau standar itu nggak punya tanggal berlaku sama sekali — beda
     * artinya dari `0`.
     */
    public function hariMenujuKadaluarsa(): ?int
    {
        if ($this->berlaku_sampai === null) {
            return null;
        }

        // Dibandingin per HARI, bukan per jam: sertifikat berlaku sampai akhir
        // tanggalnya. Tanpa startOfDay, standar yang habis hari ini bisa kebaca
        // "-1" cuma gara-gara jam request-nya lewat tengah hari.
        return (int) now()->startOfDay()->diffInDays($this->berlaku_sampai->startOfDay(), false);
    }

    /**
     * Badge status sertifikat standar buat kepala lembar kerja:
     * `valid` / `warning` / `expired`.
     *
     * Ambang `warning` DITENTUKAN BACKEND (per organisasi, lihat
     * `Organization::ambangPeringatanHari()`) — bukan mobile. Kalau HP nentuin
     * sendiri, dia bisa nampilin VALID buat standar yang bakal ditolak backend
     * waktu approve, dan teknisi baru tahu setelah kerjaannya kelar.
     *
     * `expired` di sini artinya sama sama yang bikin `POST /calibrations` ditolak
     * `422` — satu definisi, dua tempat.
     */
    public function statusKalibrasi(int $ambangHari): string
    {
        if (! $this->masihBerlaku()) {
            return self::STATUS_EXPIRED;
        }

        $sisa = $this->hariMenujuKadaluarsa();

        return $sisa !== null && $sisa <= $ambangHari
            ? self::STATUS_WARNING
            : self::STATUS_VALID;
    }

    /**
     * Nilai standar pada suhu larutan tertentu, dari persamaan di sertifikat
     * buffer: y = a·x² + b·x + c.
     *
     * Contoh nyata (buffer pH 4, sertifikat Merck): a = 3e-5, b = -0,0023,
     * c = 4,0455. Di suhu 22,2 °C hasilnya 4,0092252 — persis angka yang
     * dipakai lembar perhitungan lab.
     *
     * null kalau standarnya nggak punya persamaan (mis. gauge block, yang
     * nilainya nggak bergantung suhu larutan). Yang manggil harus siap balik ke
     * nilai nominal titiknya.
     */
    public function nilaiPadaSuhu(?float $suhu): ?float
    {
        $k = $this->koefisien_suhu;

        if ($suhu === null || ! is_array($k)) {
            return null;
        }

        // Bentuk TABEL didahulukan — lihat [nilaiDariTabelSuhu]. Standar yang
        // punya dua-duanya nggak ada, dan kalau suatu saat ada, tabel yang
        // menang: dia data sertifikat langsung, polinom cuma kecocokan kurva.
        if (is_array($k['tabel'] ?? null)) {
            return $this->nilaiDariTabelSuhu($k['tabel'], $suhu);
        }

        if (! isset($k['a'], $k['b'], $k['c'])) {
            return null;
        }

        return (float) $k['a'] * $suhu ** 2 + (float) $k['b'] * $suhu + (float) $k['c'];
    }

    /**
     * Bentuk kedua `koefisien_suhu`: TABEL sertifikat, bukan polinom.
     *
     * ## Kenapa ada dua bentuk
     *
     * Sertifikat buffer pH ngasih persamaan kuadratik jadi — tinggal dimasukin
     * suhunya. Sertifikat larutan standar viskositas nggak: dia ngasih DAFTAR
     * pengukuran (20 °C → 134 cP, 25 °C → 99,65 cP, 37,78 °C → 51,1 cP, …),
     * plus persen ketidakpastian per baris. Master lab bacanya dengan
     * interpolasi linier antar dua baris pengapit, dan angka itu yang kecetak
     * di kolom "Standard Value" sertifikat viscometer.
     *
     * Master viscometer JUGA nyetak trendline kubik di bawah tabelnya
     * (`y = -0,0007x³ + 0,1522x² - 11,693x + 313,28`). Itu hiasan grafik dan
     * BUKAN yang dipakai: pada 26,52 °C dia ngasih 97,16 cP sementara sel
     * masternya 93,88 cP — beda 3,5 %, cukup buat mbalik vonis MPE. Jangan
     * kepancing nyocokin ke situ.
     *
     * ## Kenapa di luar jangkauan tabel balik null, bukan diekstrapolasi
     *
     * Ekstrapolasi linier di ujung kurva viskositas meleset jauh — larutan
     * 60000 cP jatuh dari 95192 cP (20 °C) ke 411,3 cP (100 °C), jadi
     * kemiringan di satu ruas nggak berlaku di ruas sebelahnya sama sekali.
     * Balik `null` bikin pemanggil jatuh ke nilai nominal titiknya, persis
     * kayak standar yang emang nggak punya kurva. Yang ngasih tau teknisi
     * KENAPA nilainya nggak kegeser itu `peringatanSesi()` profil alatnya —
     * bukan angka karangan yang kelihatan sah.
     *
     * @param  list<array{suhu: float|int|string, nilai: float|int|string}>  $tabel
     */
    private function nilaiDariTabelSuhu(array $tabel, float $suhu): ?float
    {
        $baris = [];

        foreach ($tabel as $b) {
            if (! is_array($b) || ! isset($b['suhu'], $b['nilai'])) {
                continue;
            }

            $baris[] = ['suhu' => (float) $b['suhu'], 'nilai' => (float) $b['nilai']];
        }

        if (count($baris) < 2) {
            return null;
        }

        // Diurutkan sendiri, nggak percaya urutan JSON-nya. Tabel yang kebalik
        // atau keselip satu baris bikin interpolasinya diam-diam salah ruas.
        usort($baris, static fn (array $a, array $b): int => $a['suhu'] <=> $b['suhu']);

        foreach ($baris as $b) {
            // Suhu yang PERSIS jatuh di baris tabel dipakai apa adanya —
            // jangan lewat rumus interpolasi, biar 25,0 °C selalu ngasih
            // 99,65 dan bukan 99,64999999999999.
            if ($b['suhu'] === $suhu) {
                return $b['nilai'];
            }
        }

        for ($i = 0; $i < count($baris) - 1; $i++) {
            $bawah = $baris[$i];
            $atas = $baris[$i + 1];

            if ($suhu < $bawah['suhu'] || $suhu > $atas['suhu']) {
                continue;
            }

            $rentang = $atas['suhu'] - $bawah['suhu'];

            if ($rentang <= 0.0) {
                continue;
            }

            return $bawah['nilai']
                + ($suhu - $bawah['suhu']) / $rentang * ($atas['nilai'] - $bawah['nilai']);
        }

        return null;
    }

    /**
     * Satu BARIS tabel sertifikat pada suhu tertentu, kalau suhunya memang
     * salah satu titik ukur di tabel itu. `null` kalau standarnya nggak
     * bertabel atau suhunya nggak ada barisnya.
     *
     * Beda dari [nilaiPadaSuhu] yang menginterpolasi: yang ini sengaja NGGAK
     * mau nebak. Dipakai buat baca angka yang cuma sah di titik tabelnya —
     * budget ketidakpastian viscometer ngambil `%U` dan nilai acuan dari baris
     * 25 °C, dan `%U` di tabel itu berubah per baris (0,17 % di 20-25 °C,
     * 0,15 % di 37,78 °C) tanpa ada dasar buat ngerata-ratain di antaranya.
     *
     * @return array<string, float>|null
     */
    public function barisTabelSuhu(float $suhu): ?array
    {
        $tabel = $this->koefisien_suhu['tabel'] ?? null;

        if (! is_array($tabel)) {
            return null;
        }

        foreach ($tabel as $b) {
            if (! is_array($b) || ! isset($b['suhu'])) {
                continue;
            }

            if ((float) $b['suhu'] !== $suhu) {
                continue;
            }

            return array_map(static fn ($n): float => (float) $n, $b);
        }

        return null;
    }

    /**
     * Data sertifikat thermohygro buat satu parameter (`suhu`/`kelembaban`):
     * nilai terindeks, koreksi, dan U95%-nya.
     *
     * @return array{indexed_value: float|null, correction: float|null, u95: float|null}|null
     */
    /**
     * Koreksi thermohygro buat satu parameter, DI TITIK YANG PALING DEKAT
     * dengan [$pembacaan].
     *
     * Thermohygro dikalibrasi di BEBERAPA titik, bukan satu. Master data lab
     * nyimpen lima per alat — TH-6 misalnya:
     *
     *   Instrument | Correction
     *   15         | -0,32
     *   19,6       | -0,23
     *   29,4       | +0,35
     *   39,1       | +0,20
     *   49,2       | +0,73
     *
     * Sebelum ini cuma SATU titik yang kesimpen, dan titik itu dipakai buat
     * suhu ruangan berapa pun. Akibatnya ruangan 25,7°C dikoreksi pakai angka
     * titik 19,6 (-0,23) dan kecetak 25,47, padahal Excel lab pakai titik 29,4
     * (+0,35) dan dapat 26,05. Selisih 0,58°C di dokumen terakreditasi, dan
     * kena SEMUA sertifikat — bukan cuma turbidimeter.
     *
     * [$pembacaan] null = nggak tau lagi di suhu berapa; ambil titik pertama
     * biar perilakunya sama kayak dulu daripada nebak.
     *
     * Bentuk lama (satu objek langsung) tetap dibaca — data yang belum
     * dimigrasi nggak boleh mendadak kehilangan koreksinya.
     */
    public function parameterKondisi(string $parameter, ?float $pembacaan = null): ?array
    {
        $data = $this->parameter_kondisi[$parameter] ?? null;

        if (! is_array($data)) {
            return null;
        }

        // Bentuk baru: daftar titik di `titik[]`. Bentuk lama: objek tunggal.
        $titik = is_array($data['titik'] ?? null) && $data['titik'] !== []
            ? $data['titik']
            : [$data];

        $pilih = $titik[0];

        if ($pembacaan !== null) {
            $jarakTerdekat = null;
            foreach ($titik as $t) {
                if (! is_array($t)) {
                    continue;
                }
                // Dicocokin ke INSTRUMENT INDICATION — itu angka yang kebaca di
                // layar thermohygro, jadi itu yang sebanding sama pembacaan
                // teknisi. Standard indication itu nilai acuannya.
                $acuan = $t['instrument'] ?? $t['indexed_value'] ?? null;
                if ($acuan === null) {
                    continue;
                }
                $jarak = abs($pembacaan - (float) $acuan);
                if ($jarakTerdekat === null || $jarak < $jarakTerdekat) {
                    $jarakTerdekat = $jarak;
                    $pilih = $t;
                }
            }
        }

        return [
            'indexed_value' => isset($pilih['indexed_value']) ? (float) $pilih['indexed_value'] : null,
            'instrument' => isset($pilih['instrument']) ? (float) $pilih['instrument'] : null,
            'correction' => isset($pilih['correction']) ? (float) $pilih['correction'] : null,
            'u95' => isset($pilih['u95']) ? (float) $pilih['u95'] : null,
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
