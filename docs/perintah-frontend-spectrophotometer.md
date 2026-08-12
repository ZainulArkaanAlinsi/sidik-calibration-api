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

### 5. Desimal berbeda per titik

`baris[].desimal` dan `titik[].desimal`: **2** untuk nm, **3** untuk %T. Pad
angka ke jumlah desimalnya, jangan buang nol belakang — `279,60` tetap
`279,60`, `9,900` tetap `9,900`.

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

## ENDPOINT

Semua di bawah `auth:sanctum`. Menulis sesi butuh peran `admin` atau `teknisi`;
approve butuh `admin`.

```
GET  /api/calibrations/lembar-kerja?equipment_id={id}
       (atau ?profil=spectrophotometer kalau alatnya belum dipilih)
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

## YANG PERLU DIKONFIRMASI KE BACKEND SEBELUM MULAI

Respons `GET /api/calibrations/{id}` mengirim `titik[]` **tanpa label
kelompok** — nama kelompok (`remark`) baru muncul di snapshot sertifikat. Jadi
untuk layar riwayat/approval, kelompok tiap titik harus dipetakan sendiri dari
lembar kerja lewat `titik_ukur`.

Kalau kamu butuh labelnya langsung di respons sesi, **minta backend
menambahkannya** — jangan menebak kelompok dari besar angkanya. Rentang Holmium
dan Didynium tumpang tindih 167 nm; tebakan berdasarkan angka akan salah di
titik yang justru paling gampang tertukar.
