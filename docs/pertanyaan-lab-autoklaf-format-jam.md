# Pertanyaan lab: format jam dan format angka berpemisah (Autoklaf SIDIK-FM-CAL-0539, Konduktivitas SIDIK-FM-CAL-0510)

Dibuat 23 Nov 2026, diperluas hari yang sama. Status: **MENUNGGU JAWABAN pemilik lab** untuk format final.
**Pembaruan 23 Nov 2026 (malam):** `NormalisasiJam` sudah dilonggarkan dari semua koreksi: bacaan berisi digit dan `:`
diterima APA ADANYA (kuning), selain itu merah. Itu cara tanpa menunggu yang disetujui pemilik proyek, dengan syarat
pemeriksaan rumus master (hasilnya di bawah). Format final (arti empat kelompok, batas jam) tetap baru boleh diketatkan
setelah jawaban di bawah dicatat di sini. Aturan angka
berpemisah (bagian B) adalah **pertanyaan untuk pemilik lab, bukan keputusan kode**: kode sekarang tidak memihak tafsiran
apa pun.

## A. Format jam di baris `Time` lembar Autoklaf

### Bukti yang sudah ada (dibaca dari berkas di repo, 23 Nov 2026)

| Sumber | Isi |
|---|---|
| Lembar kosong `SIDIK-FM-CAL-0539_Rev.4 - LEMBAR KERJA AUTOCLAVE.pdf` (`Project-PT-Sidik/worksheet_alat_calibration/`) | Baris `Time`, kolom "Pengukuran Berulang UUT Selama Proses Sterilisasi": **lima kolom**, tiap kolom tercetak `____:____:____:____`, yaitu **empat kelompok** |
| Master `Master_Olah_Data_Autoclave` → `INPUT DATA` ("Waktu pengambilan data") | **lima titik** `02:00:00`, `04:00:00`, `06:00:00`, `08:00:00`, `10:00:00`: `HH:MM:SS`, **tiga kelompok** (2/4/6/8/10 jam) |
| `NormalisasiJam` dan `TemplateLembarKerja::aturanJam()` | **Sebelum 23 Nov 2026:** diperlakukan sebagai `HH:mm:ss`, menolak empat kelompok. **Sekarang:** teks digit dan `:` (satu sampai empat kelompok) diterima apa adanya (kuning); `aturanJam()` tidak lagi memuat `format` dan `maks_digit` |
| Komentar `AutoclaveProfile` baris 554 dan satu tes | Menyebut kertas mencetak `__:__:__:__` (empat kelompok) tetapi master menulis tiga |

Jadi: jumlah kolom (lima) cocok dengan lima titik waktu di master; yang tidak cocok adalah **jumlah kelompok per sel**
(kertas empat, master tiga). Arti kelompok keempat tidak bisa disimpulkan dari bukti di repo. Belum ada contoh lembar
Autoklaf yang SUDAH DIISI tangan di disk (hanya lembar kosong), jadi belum terlihat bagaimana teknisi benar-benar menulis.

### Hasil pemeriksaan rumus master (syarat pemilik proyek, 23 Nov 2026)

Master `Master Olah Data_Autoclave.xlsm` dibuka di memori (sandi dari `SIDIK_MASTER_SANDI`, tidak ditulis ke berkas)
dan SEMUA rumusnya dipindai (hanya-baca):

- Baris "Waktu pengambilan data" ada di `INPUT DATA` baris **34** dan **44**. Sel `D34`, `F34`, `H34`, `I34`, `K34` berisi
  **nilai waktu Excel** (`02:00` sampai `10:00`, format `h:mm:ss AM/PM`); baris 44 hanya menyalin baris 34 (`=D34`, dst.).
- Satu-satunya rumus yang merujuk sel-sel itu adalah salinan baris 44 tadi (5 rumus). Sheet `PERHITUNGAN FC` (53 rumus ke
  `INPUT DATA`), `PERHITUNGAN U95%` (6) dan `SERTIFIKAT` (20) **tidak menyentuh baris 34 maupun 44** (rujukan eksplisit, rentang,
  dan pola dinamis `INDIRECT`/`OFFSET`/kolom penuh: nol).
- **Kesimpulan:** tidak ada rumus master yang membaca sel jam sebagai angka untuk perhitungan; jam hanya label titik waktu.
- **Batas:** yang dipindai hanya rumus sel. Makro VBA di `.xlsm` TIDAK dibaca. Yang dipindai adalah salinan di `Downloads`;
  diasumsikan versi terbaru. Kode server hanya memakai indeks titik waktu 1 sampai 5 (`AutoclaveProfile`), tidak membaca teks jam.

### Perilaku sebelum 23 Nov 2026 yang menyentuh nilai (sudah dicabut)

- huruf mirip digit diganti (`O o D`→0, `l I |`→1, `S`→5, `B`→8), maksimal 2 penggantian;
- pemisah `. ; - / spasi` diganti menjadi `:`;
- tanpa pemisah ("020000", "0200") disisipi titik dua;
- "12:30" menjadi "12:30:00" (detik ditambahkan) dan "2:5" menjadi "02:05:00" (nol ditambahkan);
- empat kelompok ("00:02:00:00") ditolak.

### Pertanyaan untuk pemilik lab

1. Kertas Rev.4 mencetak empat kelompok per sel `Time`. Apa arti tiap kelompok (mis. `hari:jam:menit:detik`,
   atau `jam:menit:detik:?`)? Master mencatat `02:00:00`. Bagaimana teknisi mengisi empat kelompok itu?
2. Cara tercepat menjawab: kirim **foto satu lembar Autoklaf yang sudah diisi** (baris `Time`). Itu menjawab 1 sampai 4
   sekaligus dan sekaligus menjadi foto uji.
3. Boleh menulis tanpa detik ("12:30")? Kalau boleh, yang disimpan "12:30" apa adanya atau lab menghendaki detik "00"?
4. Ada waktu yang melewati 23 jam (lama siklus, bukan jam dinding)?
5. Pemisah yang sah di kertas: hanya `:`? Pernah ada teknisi memakai `.` atau `-`?

### Cara memprosesnya TANPA menunggu jawaban (SUDAH DIKERJAKAN 23 Nov 2026 atas persetujuan pemilik proyek, dengan syarat di atas)

Perlakukan sel jam sebagai **teks yang dikonfirmasi teknisi**, bukan sebagai waktu yang dinormalisasi: terima apa adanya
bila hanya berisi digit dan `:` (satu sampai empat kelompok), tanpa mengganti huruf, tanpa menyisipkan atau mengganti
pemisah, tanpa menambah detik atau nol; selalu **kuning** (tulisan tangan); selain itu **merah** dengan teks mentah
tersimpan. Alasan kuat: sel jam tidak punya `nilai` numerik, dan di master serta `AutoclaveProfile` jam tampak hanya
sebagai label titik waktu 2/4/6/8/10 jam (perhitungan memakai suhu). **Belum diverifikasi ke rumus master**; periksa dulu
bahwa tidak ada rumus yang membaca jam sebagai angka sebelum menerapkannya. Konsekuensi: dua tes `PindaiAutoclaveTest` yang mengunci "dilengkapi"
dan "disisipkan" ditulis ulang. Format yang benar tetap harus dicatat dari jawaban lab untuk validasi lanjutan.

## B. Angka berpemisah: tekanan Autoklaf dan konduktivitas

Sejak 23 Nov 2026 server tidak lagi memilih antara desimal dan pemisah ribuan memakai rentang atau jumlah desimal
templat. Akibatnya (belum diputuskan sebagai aturan produk, hanya akibat teknis):

- **diterima:** bilangan bulat tanpa pemisah (`1413`), satu pemisah dengan bagian utuh berawalan 0 (`0,112`, `0.112`),
  satu pemisah dengan jumlah digit pecahan bukan tiga (`1,41`);
- **ditolak sebagai ambigu (sel merah, kosong, teknisi mengetik):** satu pemisah + tepat tiga digit dengan bagian utuh
  1 sampai 3 digit tak berawalan 0 (`1,013`, `1.013`, `1.413`, `10,112`).

### Pertanyaan untuk pemilik lab

1. **Tekanan Autoklaf** (`Indikator Pressure`, `Tekanan atm awal`): berapa digit di belakang koma yang selalu ditulis
   teknisi (tiga, seperti `resolusi_alat.tekanan` 0,001)? Apakah tekanan atmosfer ditulis `1,013` atau `1.013`?
2. **Konduktivitas** (standar 1413 µS/cm): ditulis `1413`, `1.413`, atau `1,413`? Pernah memakai pemisah ribuan?
3. **Viscometer:** master lab menulis `63181.3` dan `59003` (tanpa pemisah ribuan; satu contoh salah ketik `631.74.2`).
   Apakah teknisi di lapangan juga selalu menulis tanpa pemisah ribuan?
4. Bila jumlah desimal suatu kolom **tetap** menurut spesifikasi alat yang disetujui: bolehkah itu dijadikan FAKTA untuk
   menafsirkan `1,013` sebagai desimal (tafsiran dideklarasikan spesifikasi, bukan dipilih lewat rentang nilai)? Itu
   melonggarkan aturan "tidak ada penambahan/penghapusan koma", jadi hanya dengan persetujuan pemilik proyek.

## Draf pesan ke pemilik lab (belum dikirim)

> Mohon bantuannya untuk dua hal, supaya pembacaan foto lembar kerja tidak salah tafsir.
> (1) Baris "Time" di lembar Autoklaf Rev.4 tercetak empat kelompok `__:__:__:__`, tetapi master mencatat `02:00:00`. Apa
> arti kelompok keempat, dan bagaimana teknisi mengisinya? Kalau ada, mohon kirim foto satu lembar yang sudah diisi.
> (2) Angka tekanan Autoklaf dan konduktivitas: biasanya ditulis `1,013` / `1.413` atau tanpa pemisah (`1413`)? Tekanan
> selalu tiga angka di belakang koma?

## Jawaban pemilik lab

_(belum ada; isi di sini bersama tanggal dan siapa yang menjawab)_

## Yang akan dilakukan setelah dijawab

Jam: ikuti jawaban (A) untuk format final (arti empat kelompok, batas jam). Angka berpemisah: tetapkan per kolom menurut
jawaban (B), tertulis sebagai keputusan produk sebelum kode berubah. Dua tes `PindaiAutoclaveTest` yang dulu mengunci
"dilengkapi" dan "disisipkan" sudah ditulis ulang (23 Nov 2026) menjadi tes "apa adanya".
