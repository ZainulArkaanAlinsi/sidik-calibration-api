# Pertanyaan lab — mengukur akurasi kamera lembar kerja

**Sumber:** perintah "Model Pembaca Sel Buatan Sendiri (OCR Lokal)", 2 Sep 2026, yang
berhenti di Gerbang Keputusan 0 — lihat `docs/temuan-gerbang0-ocr-model-lokal.md`.

**Statusnya sekarang:** perekam tebakan mesin sudah jalan di **keenam** jalur kamera, dan
`php artisan ocr:akurasi-kamera` sudah bisa menghitung akurasi per kolom berikut **hijau
palsu**. Yang di bawah ini **bukan blokir buat itu** — pengukurannya sudah bisa mulai hari
ini. Ini hal-hal yang cuma pemilik lab / manajer teknis yang boleh memutuskan, dan sampai
diputuskan kode **menolak** daripada menebak.

---

## T1 — Koordinat REGION KEPALA tiap lembar. Ada di mana di kertasnya?

Yang dibutuhkan: kotak (`x`, `y`, `w`, `h`) di ruang `ukuran_referensi` (1654×2339 @ 200 dpi)
yang memuat **identitas pelanggan** — nama PT, nomor order, alamat.

**Kenapa ini ditanya, bukan diukur sendiri.** §14.2 perintah aslinya mewajibkan tiap kotak
sel yang diekspor sebagai data latih **tidak beririsan** dengan region kepala, dan template
yang tidak mendefinisikannya **ditolak ekspornya** — bukan diloloskan dengan asumsi.

Faktanya: **0 dari 23** berkas `database/ocr-templates/*.json` punya kunci itu. Kuncinya
belum ada sama sekali di skemanya.

Koordinat itu tidak saya karang, dan alasannya bukan kehati-hatian berlebihan: **region
kepala yang SALAH lebih berbahaya daripada tidak ada.** Yang tidak ada bikin ekspor
ditolak — kelihatan, dan bisa dibetulkan. Yang salah **lolos** cek privasinya sambil
meloloskan nama pelanggan ke dataset latih, dan tidak ada yang akan tahu.

**Yang dibutuhkan dari lab:** satu formulir cetak per template, atau koordinat blok kepalanya.

> Catatan: sampai T1 dijawab, **belum ada ekspor dataset yang dibangun sama sekali** — jadi
> ini belum menahan apa pun yang berjalan. Lihat T3.

---

## T2 — 17 dari 23 geometri masih `terverifikasi: false`. Mau diverifikasi, atau dibiarkan?

Berkas geometri lahir dari `php artisan ocr:rangka-geometri`, dan `_catatan`-nya jujur:
*"Koordinatnya grid rata, bukan hasil ukur. Ukur dari formulir cetak asli, adu ke foto
nyata, baru setel `terverifikasi = true`."*

Yang sudah `true` cuma 6 — keenamnya lembar kimia.

**Ini cuma menggerbangi jalur lembar bermarker** (`PINDAI LEMBAR KERJA`), yang sendirinya
sudah dicabut permanen dari layar 26 Agt 2026. Jadi hari ini `terverifikasi` **tidak
menahan apa pun yang bisa disentuh teknisi**.

Pertanyaannya jadi: jalur bermarker mau dihidupkan lagi atau tidak?

- **Tidak** → 17 berkas itu dibiarkan apa adanya. Tidak ada ruginya.
- **Ya** → butuh formulir cetak asli tiap alat buat diukur, dan itu pekerjaan lab, bukan
  pekerjaan kode.

---

## T3 — Contoh latih (crop + label) sudah terkumpul di HP. Boleh keluar dari perangkat?

Mesin pengumpulnya **sudah ada dan hidup**: `SimpananContohSel` menyimpan potongan sel
berikut angka yang akhirnya diketik teknisi — persis pasangan (gambar, label) yang
dibutuhkan buat melatih model.

Simpanannya **lokal di HP masing-masing teknisi**. `potong_sel_foto.dart` menulisnya terang:
*"Nggak ada citra yang keluar dari HP. … Ekspor keluar perangkat butuh keputusan eksplisit
pemilik lab dan belum dibangun sama sekali."*

**Rekomendasi: JANGAN dibangun dulu**, dan alasannya bukan teknis:

1. Yang keluar bukan angka, tapi **potongan lembar kerja pelanggan**. Itu keputusan
   kebijakan, bukan keputusan implementasi.
2. T1 belum dijawab, jadi cek privasi yang seharusnya menjaganya **belum bisa jalan**.
3. **Belum ada yang membutuhkannya.** Yang dibutuhkan sekarang mengukur kameranya, dan itu
   sudah bisa tanpa satu citra pun keluar HP.

Kalau nanti dibangun, urutannya wajib: T1 dijawab → cek irisan region kepala jalan dan
**fail-closed** → baru ekspor.

---

## T4 — Ambang mana yang dipakai buat menilai hasilnya nanti?

`config/ocr.php` memasang `ambang.hijau = 0,85` dan `kuning = 0,60`, dan angka-angka itu
ditandai sendiri sebagai **perkiraan awal** yang *"wajib dikalibrasi ulang dari dataset foto
asli sebelum dipakai produksi"*.

`ocr:akurasi-kamera` memakai `ambang.hijau` buat memisahkan hijau palsu. Jadi begitu ada
data 2–4 minggu, angkanya bisa dipakai menyetel ambangnya — bukan sebaliknya.

**Yang tidak boleh:** menggeser ambang supaya angkanya kelihatan bagus. Itu §15.4 perintah
aslinya, dan alasannya tetap berlaku: menaikkan angka dengan melonggarkan gerbang adalah
membohongi metriknya sendiri.

Tiap ambang yang digeser wajib menaikkan `aturan_versi` — dan **jangan** digeser di minggu
yang sama dengan perubahan pembaca, supaya waktu akurasi bergerak, penyebabnya bisa
ditunjuk.
