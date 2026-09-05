<?php

namespace App\Http\Requests\Concerns;

use Closure;
use Illuminate\Validation\Validator;

/**
 * Aturan data ukur Autoklaf yang dipakai BARENGAN jalur preview
 * (`AutoclaveCalculationRequest`) dan jalur simpan (`AutoclaveStoreRequest`).
 *
 * Ditarik ke trait waktu lembar kerjanya disamain sama kertas
 * `SIDIK-FM-CAL-0539_Rev.4`: aturannya nambah baris kertas baru
 * (`indikator_pressure`, `tekanan_atm_awal` lima kolom), dan dua salinan aturan
 * yang bisa jalan sendiri artinya preview bisa nerima payload yang ditolak
 * waktu disimpan — teknisi lihat angka di layar, lalu kirimannya mental.
 */
trait AturanUkurAutoclave
{
    /**
     * Terima SATU angka atau DERET angka.
     *
     * Baris "Tekanan atm awal" di kertas punya lima kolom, satu per titik
     * waktu. Payload versi pertama ngirimnya satu angka. Klien lama nggak
     * dipaksa ganti — yang lama tetap sah, yang baru boleh ngirim deret.
     */
    protected function angkaAtauDeretAngka(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            foreach (is_array($nilai) ? $nilai : [$nilai] as $satu) {
                if ($satu !== null && ! is_numeric($satu)) {
                    $gagal('Kolom :attribute harus angka, atau deret angka per titik waktu.');

                    return;
                }
            }
        };
    }

    /**
     * Blok suhu yang DIKIRIM harus punya minimal satu angka.
     *
     * Padanan `pastikanAdaBacaanUut()` di bawah — yang buat tekanan sudah ada
     * sejak lembar kerjanya disamakan dengan kertas, yang buat suhu tidak
     * pernah ikut. Ini yang bolong.
     *
     * ## Apa yang terjadi tanpa ini
     *
     * `AutoclaveCalculator::hitungSuhu()` menjaga `$disk === []` — daftar
     * disk-nya kosong. Tapi tiap SEL boleh `null` (`AutoclaveStoreRequest`
     * mengizinkannya, dan itu benar: sel kosong di tengah lembar memang wajar).
     * Jadi tiga disk berisi lima `null` LOLOS penjagaan itu: loopnya
     * `continue` di semua iterasi, `$sensor` tetap `[]`, dan Kestabilan,
     * Keseragaman & Variasi keluar **0**.
     *
     * Nol bukan "tidak ada" — nol itu angka yang sangat bagus. Autoklaf dengan
     * kestabilan 0 °C artinya sempurna, dan angka itu ikut ke sertifikat
     * terakreditasi tanpa satu pun error di jalan. Ini persis pola yang aturan
     * repo ini larang: *sel kosong yang dibaca nol jangan pernah ditiru;
     * blokir titiknya dengan alasan yang kebaca.*
     *
     * ## Kenapa di sini, bukan di kalkulator saja
     *
     * Alasan yang sama yang sudah ditulis di `pastikanAdaBacaanUut()`:
     * *"ditolak di sini (422) biar teknisi dapat pesan yang jelas, bukan 500
     * dari kalkulator."* Kalkulatornya ikut dikeraskan sebagai lapis kedua —
     * dia juga dipanggil `HitungUlangSesi` yang tidak lewat FormRequest.
     *
     * Blok suhu yang memang TIDAK dikirim tetap sah dan tidak disentuh di
     * sini: sesi tekanan-saja itu skenario nyata yang sudah dijanjikan handoff
     * frontend.
     */
    protected function pastikanAdaBacaanSuhu(Validator $validator): void
    {
        $suhu = $this->input('suhu');

        if (! is_array($suhu) || $suhu === []) {
            return;
        }

        $adaAngka = function (mixed $nilai): bool {
            foreach (is_array($nilai) ? $nilai : [$nilai] as $satu) {
                if ($satu !== null && $satu !== '') {
                    return true;
                }
            }

            return false;
        };

        $adaDisk = collect($suhu['disk'] ?? [])->contains($adaAngka);
        $adaIndikator = $adaAngka($suhu['indikator'] ?? []);

        if ($adaDisk && $adaIndikator) {
            return;
        }

        $validator->errors()->add(
            'suhu.disk',
            'Blok suhu dikirim tapi nggak ada angkanya — butuh minimal satu bacaan disk DAN '
                .'satu bacaan indikator. Kosongin blok suhunya kalau sesi ini emang cuma ngukur '
                .'tekanan; kalau dikirim kosong, Kestabilan & Keseragaman kecetak 0 di sertifikat.',
        );
    }

    /**
     * Blok tekanan butuh SATU angka UUT buat dihitung. Di kertas angka itu ada
     * di baris "Indikator Pressure"; di master `INPUT_DATA` dia kesimpen
     * sebagai satu "UUT Reading". Jadi salah satunya boleh kosong — nggak
     * dua-duanya.
     */
    protected function pastikanAdaBacaanUut(Validator $validator): void
    {
        $tekanan = $this->input('tekanan');
        if (! is_array($tekanan) || $tekanan === []) {
            return;
        }

        // Yang butuh angka UUT itu PERHITUNGANNYA, dan perhitungan tekanan baru
        // jalan kalau bacaan Pressure Disk Logger udah masuk. Lembar yang cuma
        // berisi baris kertas (belum ada unduhan disk) sah — jangan dipalang.
        $adaBacaanStandar = collect($tekanan['pembacaan_standar'] ?? [])
            ->contains(fn ($x): bool => $x !== null && $x !== '');
        if (! $adaBacaanStandar) {
            return;
        }

        $adaUut = $tekanan['uut_setting'] ?? null;
        $adaIndikator = collect($tekanan['indikator_pressure'] ?? [])
            ->contains(fn ($x): bool => $x !== null && $x !== '');

        if ($adaUut === null && ! $adaIndikator) {
            $validator->errors()->add(
                'tekanan.indikator_pressure',
                'Blok tekanan butuh bacaan UUT: isi baris Indikator Pressure, atau kirim tekanan.uut_setting.',
            );

            return;
        }

        if ($adaUut !== null) {
            return;
        }

        // Kertas nyediain lima kolom, master cuma nyimpen satu UUT Reading.
        // Kalau kelimanya nggak sama, yang mana yang dipakai itu keputusan
        // orang — bukan rata-rata yang dikarang sistem. Ditolak di sini (422)
        // biar teknisi dapat pesan yang jelas, bukan 500 dari kalkulator.
        $beda = collect($tekanan['indikator_pressure'] ?? [])
            ->reject(fn ($x): bool => $x === null || $x === '')
            ->map(fn ($x): float => (float) $x)
            ->unique();

        if ($beda->count() > 1) {
            $validator->errors()->add(
                'tekanan.indikator_pressure',
                'Indikator Pressure beda antar titik waktu ('.$beda->implode(', ').'). '
                    .'Kirim tekanan.uut_setting yang mau dipakai — sistem nggak milih sendiri.',
            );
        }
    }
}
