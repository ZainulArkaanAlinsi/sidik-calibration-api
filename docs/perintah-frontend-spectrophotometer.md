# Perintah Frontend — Spectrophotometer

Tempel bagian di bawah garis ini ke sesi kerja frontend. Sudah lengkap; tidak
perlu menjelaskan ulang konteksnya.

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

Backend modul **Spectrophotometer** (alat ke-6, UV-Vis / Visible, metode
`SIDIK-IK-CAL-0508_Rev.4`) sudah selesai dan sudah lulus test di SQLite maupun
MySQL. Tugasmu menyambungkan sisi frontend. Kontrak lengkapnya ada di
`docs/handoff-backend-spectrophotometer.md` di repo API — **baca itu lebih dulu
dan perlakukan sebagai satu-satunya sumber kebenaran.** Dokumen ini hanya
ringkasan kerjanya.

## ATURAN PALING PENTING

1. **Frontend TIDAK menghitung apa pun.** Tidak ada rata-rata, tidak ada STDEV,
   tidak ada koreksi, tidak ada ketidakpastian, tidak ada pembulatan yang
   mengubah nilai. Semua angka datang dari API. Kalau ada angka yang belum
   tersedia di respons, laporkan — jangan hitung sendiri.
2. **Jangan hardcode titik ukur, satuan, resolusi, jumlah kolom pengulangan,
   atau jumlah desimal.** Semuanya datang dari
   `GET /api/calibrations/lembar-kerja`. Alat ini akan bertambah sampai 48
   jenis; layar yang menebak bentuknya akan pecah di alat berikutnya.
3. Kalau ada yang ambigu antara kontrak dan kenyataan respons API, **berhenti
   dan tanya**. Jangan menambal dengan asumsi.

## YANG BEDA DARI LIMA ALAT SEBELUMNYA — ini sumber bug-nya

### 1. Satu lembar berisi TIGA tabel, dan bentuk tiap tabel berbeda

`bagian` dengan `kode: "hasil"` mengirim `tabel[]` berisi tiga blok:

| Judul                                    | Baris | Satuan | Kolom pengulangan |
| ---------------------------------------- | ----- | ------ | ----------------- |
| `Wave Length ( λ ) - Filter Holmium`     | 10    | `nm`   | 3                 |
| `Wave Length ( λ ) - Filter Didynium`    | 9     | `nm`   | 3                 |
| `Accuracy %T and Linierity at λ = 560nm` | 5     | `%T`   | **6**             |

Jumlah kolom pengulangan **wajib** dibaca dari `tabel[].pengulangan` (array
`[1,2,3]` atau `[1..6]`). Field `jumlah_pengulangan` di level lembar bernilai
`3` dan **tidak berlaku untuk tabel %T** — kalau kamu pakai itu untuk merender
ketiga tabel, tiga kolom terakhir blok %T hilang dari layar dan teknisi tidak
punya tempat mengetik separuh datanya.

### 2. Satuan mengikat per TABEL dan per BARIS, bukan per lembar

Lembar mengirim `satuan: "nm"` di level atas **dan** `satuan_campuran:
["nm","%T"]`. Kalau `satuan_campuran` ada isinya, jangan pernah pakai satuan
level lembar untuk melabeli kolom. Ambil dari `tabel[].satuan` (judul kolom) dan
`baris[].satuan` (tiap baris).

### 3. Satu U95 dipakai bersama oleh seluruh titik dalam satu kelompok

Ini perbedaan terbesarnya dari alat lain. Ketidakpastian di alat ini lahir per
**kelompok**, bukan per titik: satu tabel budget per kelompok, diberi makan
STDEV terbesar dari seluruh titik kelompok itu. Akibatnya sepuluh titik Holmium
pulang dengan `ketidakpastian_diperluas` dan `faktor_cakupan_k` yang **sama
persis**.

Itu benar, bukan data duplikat. Jangan di-dedupe, jangan digabung barisnya,
jangan ditandai sebagai anomali di layar.

### 4. `standard_id` sudah menempel di tiap baris — jangan bikin pemilih filter

Tiap `baris[]` membawa `standard_id` + `standard_nama` sendiri (`Filter Standard
1/2/3`). Kirim balik apa adanya di `measurements[].standard_id`.

Jangan menyediakan dropdown "pilih filter" per titik. Rentang Holmium (283–641
nm) dan Didynium (474–810 nm) tumpang tindih 167 nm, jadi pemilihan manual
gampang salah dan salahnya tidak kelihatan dari dokumen hasilnya.

### 5. Desimal INPUT dan desimal HASIL tidak sama — jangan disatukan

Dua angka berbeda, dan sejak 14 Agt 2026 nilainya memang berbeda:

| Field | Nilai | Dipakai untuk |
|---|---|---|
| `baris[].desimal` (lembar kerja) | **2** untuk nm, **3** untuk %T | kotak ketik teknisi — ini resolusi baca alatnya (0,01 nm & 0,001 %T) |
| `titik[].desimal` (hasil & sertifikat) | **1** untuk ketiganya | tabel hasil, layar approval, tampilan sertifikat |

Pad angka ke jumlah desimalnya, **jangan buang nol belakang** — `279,60` tetap
`279,60` di kotak input, dan `334,0` tetap `334,0` di tabel hasil (bukan `334`).

Yang hasil ikut 1 desimal bukan penyederhanaan tampilan: sel `SERTIFIKAT` di
workbook master lab diformat 1 desimal, jadi dokumen yang dipegang pelanggan
menulis `333,7`, bukan `333,74`. Layar yang menulis lebih panjang dari
sertifikatnya bikin teknisi ragu waktu mencocokkan.

Baris `Uncertainty U95%` punya angkanya sendiri lagi: `titik[].desimal_u95` =
**2** (`0,43` · `0,40` · `0,50`).

### 6. `keputusan` dan `toleransi` SELALU `null`

Master alat ini tidak punya batas keberterimaan, jadi tidak ada vonis PASS/FAIL
yang bisa dibuat. Jangan tampilkan badge kalau null — pakai strip (`—`) atau
sembunyikan kolomnya. Alat lain tetap mengirim `"PASS"`/`"FAIL"`, jadi
komponennya harus menangani tiga keadaan. Periksa juga daftar sesi dan layar
sertifikat, bukan hanya layar detail.

### 7. Bagian `sre` berstatus `sumber_belum_ada` — render, tapi jangan beri input

Lembar mengirim bagian keempat:

```jsonc
{
  "kode": "sre",
  "judul": "SRE (Stray Radiant Energy)",
  "status": "sumber_belum_ada",
  "catatan": "Belum diimplementasikan: di master, nilai standar SRE hilang …",
  "field": []
}
```

Tampilkan judul + `catatan` sebagai blok informasi yang tidak bisa diisi.
Jangan sembunyikan diam-diam (teknisi akan mencarinya, karena ada di lembar
kertas), dan jangan bikin field kosong yang kelihatan bisa diketik. Perlakukan
`status` sebagai kunci umum: bagian mana pun yang punya `status`
`sumber_belum_ada` diperlakukan sama, sekarang dan untuk alat berikutnya.

### 8. `tanda_nol` — baru, dan berlaku untuk SEMUA alat

Respons titik dan snapshot sertifikat sekarang membawa `tanda_nol` (boolean).
Artinya: koreksi negatif yang membulat ke nol dicetak `-0,0` (`true`) atau `0,0`
(`false`). Spectrophotometer mengirim `true`; Conductivity `false`.

Baca flagnya, jangan hardcode salah satu, dan jangan menghapus tanda minus
sendiri "karena kelihatan aneh". Sertifikat lama tidak punya kunci ini — kalau
absen, anggap `true`.

### 9. Pindai foto: kertas Spectrophotometer bentuknya lain, dan itu wajib diberitahukan

Pindai foto (`POST /api/raw-measurements/extract-from-photo`) semula dibangun di
atas bentuk lembar pH: standar sebagai KOLOM, Repeat sebagai BARIS, dan tiap sel
berisi sepasang angka (pembacaan + suhu °C).

Kertas Spectrophotometer melanggar dua-duanya:

- **Satu angka per sel.** Tidak ada kolom °C di tabel mana pun — suhu ruang
  dicatat sekali di blok Env. Condition.
- **Standar turun ke bawah.** Nilai standar (279,6 nm … 637,9 nm) berdiri di kiri
  tiap baris, dan Repeat X1..X3 berjajar ke kanan.

Karena itu ekstraksinya selalu gagal sebelum ini: model diminta membaca kolom
suhu yang tidak ada, lalu mengarang isinya supaya jawabannya cocok dengan skema.

Bentuk kertas sekarang dikirim bersama lembar kerjanya:

```jsonc
// GET /api/calibrations/lembar-kerja?equipment_id=7
{ "data": { "pindai_foto": { "kolom_suhu": false, "standar_di_baris": true } } }
```

**Teruskan dua nilai itu apa adanya** ke `extract-from-photo` sebagai field
`kolom_suhu` dan `standar_di_baris`. Jangan menyimpan daftar "alat mana yang
kertasnya beda" di sisi aplikasi — daftar itu pasti basi begitu ada profil baru.

Kalau keduanya tidak dikirim, server menebaknya dari alat pada
`calibration_session_id` (jadi aplikasi versi lama tetap jalan) — tapi tebakan
itu tidak ada kalau sesinya belum dibuat.

Bentuk responsnya **tidak berubah**: tetap satu entri per Repeat, dan
transposisinya dikerjakan server.

```jsonc
{
  "baris": [
    // Repeat 1: pembacaan tiap standar, urut atas→bawah seperti di kertas
    { "ph": [279.4, 287.6, 334.1], "suhu": [],
      "ph_keyakinan": ["high", "high", "high"], "suhu_keyakinan": [], "repeat": 1 }
  ],
  // BARU: nilai standar yang terbaca, urutannya sejajar isi tiap "ph"
  "standard_value": [279.6, 287.7, 334.0],
  "meta": { "model": "...", "perlu_dicek": false }
}
```

Dua hal yang harus dipegang saat menampilkannya:

- `suhu` dan `suhu_keyakinan` **kosong**, bukan berisi `null`. Jangan render
  kolom suhu untuk lembar ini.
- Petakan tiap angka lewat `standard_value`, jangan lewat urutan array saja. Sel
  yang tidak terbaca dikirim `null` **di slotnya** — kalau digeser untuk menutup
  lubang, pembacaan mendarat di panjang gelombang yang salah dan tidak ada satu
  pun gejala yang terlihat.

## ENDPOINT

Semua di bawah `auth:sanctum`. Menulis sesi butuh peran `admin` atau `teknisi`;
approve butuh `admin`.

```
GET  /api/calibrations/lembar-kerja?equipment_id={id}
       (atau ?profil=spectrophotometer kalau alatnya belum dipilih)
POST /api/raw-measurements/extract-from-photo   ← pindai foto lembar kerja;
       kirim `kolom_suhu` & `standar_di_baris` dari `pindai_foto` (lihat §9)
POST /api/calibrations/preview        ← hitung tanpa simpan, body identik store
POST /api/calibrations                ← simpan sesi + hasil hitung
GET  /api/calibrations/{id}
POST /api/calibrations/{id}/approve   ← admin; body {"abaikan_peringatan": true}
                                        kalau ada standar berstatus WARNING
GET  /api/certificates/{id}/download  ← PDF
```

**Selalu kirim `equipment_id` kalau alatnya sudah dipilih** — resolusi dan
satuan per titik ikut alat pelanggan, bukan template generik.

Panggil `preview` tiap teknisi selesai mengisi satu baris. Bodinya sama persis
dengan `POST /calibrations`, jadi tidak ada dua bentuk payload yang harus
dirawat.

Contoh body:

```jsonc
{
  "equipment_id": 7,
  "standard_id": 12,
  "input_method": "manual",
  "tanggal_kalibrasi": "2023-07-21T00:00:00Z",
  "suhu_awal": 22.0, "suhu_akhir": 22.0,
  "kelembaban_awal": 57.0, "kelembaban_akhir": 58.0,
  "measurements": [
    { "titik_ukur": 279.6, "satuan": "nm", "standard_id": 12,
      "pembacaan": [280, 280, 280] },
    { "titik_ukur": 9.9, "satuan": "%T", "standard_id": 14,
      "pembacaan": [9.668, 9.661, 9.666, 9.668, 9.661, 9.666] }
  ]
}
```

Respons memisahkan tiga hal: `mentah` (apa yang diketik teknisi, apa adanya),
`titik` (hasil hitung), dan `belum_dihitung` (titik yang tidak bisa dihitung
plus alasannya, mis. `"Butuh minimal 2 pembacaan terisi"`).

**Tampilkan `belum_dihitung` di layar**, jangan dibuang. Lembar boleh dikirim
walau sebagian kosong (`semua_kolom_opsional: true`), tapi tiap titik kosong
mengurangi dasar hitung kelompoknya — itu sebabnya alasannya dikirim per titik.

## DATA UJI YANG SUDAH TER-SEED

Sesi `DEMO-SPECTRO-LDC`, data asli: PT LDC Indonesia, Perkin Elmer Lambda 25 s/n
`501S13102801`, 21 Juli 2023, 24 titik. Statusnya `menunggu_approval`, jadi
alur approve → sertifikat bisa diuji sampai habis.

Angka acuan (kalau layarmu menampilkan angka lain, masalahnya di pemetaan data,
bukan di format):

| Kelompok   | U95 tiap titik | k          |
| ---------- | -------------- | ---------- |
| Holmium    | 0,43255708     | 3,18244631 |
| Didynium   | 0,4            | 2,36462425 |
| Akurasi %T | 0,5            | 2,00855911 |

Didynium dan %T memakai lantai CMC, jadi U-nya angka bulat — itu benar, bukan
placeholder.

Titik contoh: Holmium 279,6 nm → rata-rata 280,00, koreksi −0,40. %T 9,9 →
rata-rata 9,665, koreksi 0,235.

## CARA KERJA

1. Tarik bentuk lembar dari API, render dari data itu — jangan dari konstanta.
   Satu komponen tabel yang menerima `tabel[]`, bukan tiga layar hardcode.
2. Kerjakan layar teknisi dulu (isi + preview), baru layar approval admin, baru
   tampilan sertifikat.
3. Uji dengan sesi `DEMO-SPECTRO-LDC` dan adu ke tabel acuan di atas.
4. Kalau ada angka yang berbeda dari tabel acuan itu, **jangan perbaiki
   tampilannya** — laporkan selisihnya.

## LABEL KELOMPOK — pakai `remark`, jangan tebak dari angkanya

`titik[]` di respons sesi (`GET /api/calibrations/{id}`, `POST /calibrations`,
`POST /calibrations/preview`) membawa `remark` berisi judul kelompoknya, sama
persis dengan yang dibekukan di snapshot sertifikat. Kesamaan itu dikunci test,
jadi tabel di layar dan tabel di PDF tidak bisa berbeda label.

Pakai itu untuk memisahkan blok hasil di layar riwayat dan approval. **Jangan
menebak kelompok dari besar angkanya** — rentang Holmium (283–641 nm) dan
Didynium (474–810 nm) tumpang tindih 167 nm, jadi titik 513,7 nm terlihat
seperti Holmium padahal dia Didynium, dan U95 yang tampil jadi punya kelompok
lain.

Alat yang titiknya tidak punya keterangan tetap mengirim kuncinya dengan nilai
`null` — kosongkan kolomnya, jangan tulis strip kelompok palsu.
