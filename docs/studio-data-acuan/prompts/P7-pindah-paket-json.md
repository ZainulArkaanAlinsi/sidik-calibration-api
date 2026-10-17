# P7 — Pola umum: memindahkan satu paket ber-JSON ke Studio

Prompt ini adalah POLA. Yang dijalankan adalah prompt per alat di `prompts/alat/NN-<kode>.md`
(sudah berisi berkas, test, dan pemakai tabel untuk alat itu). Baca ini sekali, lalu jalankan
prompt alat satu per satu sesuai nomor.

## Urutan yang disarankan

Nomor di `prompts/alat/` = urutan: pilot (01 Micrometer, dikerjakan di P1–P2), lalu Panjang
(Dial Indicator, Height Gauge, Jangka Sorong, Sieve), Massa, Waktu, Gaya, Aliran, Volume, Tekanan,
Suhu, lalu dua paket lintas alat (20 `thermohygro_lab`, 21 `cmc_lampiran`), lalu 22–32 jenis
konstanta PHP (pakai P8).

**Perhatikan paket yang dibaca bersama.** `TabelKalibratorSuhu` (paket `tits`) dibaca TIDS,
Enclosure, dan tabel suhu 3 alat; `TabelStandarMicrometer` dibaca Flowmeter, Dial Indicator, dan
Height Gauge. Kerjakan paket penyedia lebih dulu, dan simulasinya wajib menjalankan semua pemakai.

## Langkah tiap paket (ringkas)

1. Baca KARTU + skema; tulis daftar berkas yang akan diubah.
2. `skemaAcuan()` sesuai skema (satuan & asal sel dibuktikan, sisanya `perlu_konfirmasi`).
3. Kelas `Tabel*` lewat `SumberAcuan`; buang cache statis.
4. `acuan:impor-awal <kode>` → versi 1; sha256 = JSON kanonik.
5. `TabelAcuanSetaraJsonTest` kasus `<kode>`; test alat & pemakainya hijau tanpa diubah.
6. Validator katalog 100% untuk paket itu.
7. Satu PR per paket. Laporan sesuai format induk.

## Jangan

Mengerjakan dua paket dalam satu PR; mengubah JSON dengan tangan; mengubah rumus.
