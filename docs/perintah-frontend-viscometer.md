# Perintah Frontend — Viscometer (alat ke-7)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke
sesi kerja frontend — tidak perlu membuka dokumen lain, tidak perlu menjelaskan
ulang konteksnya.

| | |
|---|---|
| Repo API | `sidik-calibration-api` |
| Formulir | `SIDIK-FM-CAL-0524_Rev.3` (kertasnya ketinggalan dua revisi standar — lihat adendum) |
| Metode | `SIDIK-IK-CAL-0517_Rev.3` |
| Master | **20 Agu 2026: `5. Viscometer 86068360 terbaru .xlsm`**, sesi asli `0817-CAL-726` |
| Status backend | Selesai & terverifikasi di MySQL |
| Referensi lain (opsional) | `docs/handoff-backend-viscometer.md`, `docs/PRD-viscometer.md`, `docs/pertanyaan-lab-viscometer.md` |

> **ADENDUM 20 Agustus 2026 — baca ini sebelum yang lain.** Lab mengirim master
> baru dan TIGA hal berubah di API. Kalau layar Viscometer sudah dibuat dari
> versi dokumen sebelumnya, yang perlu disesuaikan cuma ketiganya:
>
> 1. **Lima baris per tabel, bukan tiga.** Larutan yang dulu ditulis "30000 cP"
>    ternyata **3000 cP**, dan ada satu larutan baru **100000 cP**. Titiknya
>    sekarang 100 / 1000 / 3000 / 60000 / 100000 cP. Blok nonaktif "30000 cP"
>    yang dulu diminta ditampilkan **sudah tidak ada** — hapus.
> 2. **Jumlah desimal cetak BEDA-BEDA per baris** (2 / 1 / 0 / 1 / 1), dibaca
>    dari format sel master. Jangan pakai satu angka desimal untuk selembar.
> 3. **Botol larutan 100 cP ganti lot**, jadi `U95` titik pertama bergeser
>    sedikit (0,49 → 0,48 cP di sesi contoh). Bukan bug.
>
> Butir 1 dan 2 itu persis dua hal yang dokumen ini sejak awal minta JANGAN
> dihardcode. Kalau nasihat itu diikuti, tidak ada kode frontend yang perlu
> disentuh sama sekali.

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

Backend modul **Viscometer** (alat ke-7, viscometer rotasi Brookfield, satuan
cP) sudah selesai, sudah diadu ke workbook master lab, dan sudah lulus test di
SQLite maupun MySQL. Tugasmu menyambungkan sisi frontend.

## 0. Aturan paling penting

1. **Frontend TIDAK menghitung apa pun.** Tidak ada rata-rata, tidak ada STDEV,
   tidak ada interpolasi suhu, tidak ada MPE, tidak ada pembulatan yang mengubah
   nilai. Semua angka datang dari API. Kalau ada angka yang belum tersedia di
   respons, laporkan — jangan hitung sendiri.
2. **Jangan hardcode** titik ukur, satuan, resolusi, jumlah kolom pengulangan,
   jumlah desimal, atau daftar spindle/model. Semuanya datang dari
   `GET /api/calibrations/lembar-kerja`. Alat ini akan bertambah sampai 48
   jenis; layar yang menebak bentuknya akan pecah di alat berikutnya.
3. **Jangan pasang validasi rentang angka sendiri.** Backend sudah mengirim pita
   per baris, dan pitanya bukan `nominal ±10 %` — lihat bagian 5. Pita buatan
   sendiri akan menolak pembacaan yang sah.
4. Kalau ada yang ambigu antara dokumen ini dan kenyataan respons API,
   **berhenti dan tanya**. Jangan menambal dengan asumsi.

## 1. Kenapa alat ini beda — baca ini dulu

Tiga hal yang tidak ada di enam alat sebelumnya, dan ketiganya masuk ke angka
yang tercetak di sertifikat.

**Nilai acuan bergerak mengikuti suhu.** Larutan standar viskositas berubah
tajam: larutan 60000 cP itu 95192 cP pada 20 °C dan 19259 cP pada 37,78 °C —
turun 80 % dalam 18 °C. Karena itu setiap sel pembacaan **berpasangan dengan sel
suhu**, dan sel suhu itu bukan hiasan.

**Batas keberterimaan lahir per titik.** Bukan satu angka di data alat — kolom
`toleransi` alat ini memang `NULL`. Batasnya MPE, dihitung dari spindle & RPM
titik itu:

```
Fullscale = TK × SMC × 10000 / RPM
MPE       = 1 % × Fullscale + 1 % × rata-rata pembacaan
```

**Satu lembar memuat tiga orde magnitudo.** 96 cP, 918 cP, 63181 cP — dalam satu
lembar, ditulis tangan, difoto pakai HP. Ini yang menentukan lebar kolom di
layar dan seluruh perilaku hasil pindai.

## 2. Endpoint

### 2.1 Ambil bentuk lembar kerja

```
GET /api/calibrations/lembar-kerja?profil=viscometer&equipment_id={id}
```

Balasannya `data` berisi delapan bagian:

| `kode` | Judul | Isi |
|---|---|---|
| `identitas` | General Information | identitas alat, rentang, resolusi, thermohygro (7 pilihan) |
| `pemilik` | OWNER | nama & alamat pelanggan |
| `usage_check` | Standard Used | centang standar yang dipakai |
| `data_kalibrasi` | CALIBRATION DATA | lokasi, teknisi, ruangan |
| `model_visco` | Model Viscometer (menentukan TK) | **1 field pilihan, 12 model** |
| `hasil` | Data Result | **13 field + 2 tabel** |
| `penutup` | Catatan & Tanda Tangan | catatan teknisi, nama teknisi & reviewer |

Juga ada `kode_dokumen` (`SIDIK-FM-CAL-0524_Rev.3`) dan `kode_metode`
(`SIDIK-IK-CAL-0517_Rev.3`) di level lembar — cetak apa adanya di kop layar.

### 2.2 Kirim sesi

```
POST /api/calibrations
```

Bentuk minimal yang khas Viscometer:

```json
{
  "equipment_id": 12,
  "standard_id": 28,
  "input_method": "manual",
  "tanggal_kalibrasi": "2026-07-31T00:00:00Z",
  "suhu_awal": 25.2,
  "suhu_akhir": 25.3,
  "kelembaban_awal": 57.0,
  "kelembaban_akhir": 58.0,
  "spesifikasi_alat": { "model_visco": "DV2THA" },
  "measurements": [
    {
      "titik_ukur": 99.65,
      "standard_id": 28,
      "satuan": "cP",
      "spindle": "HA1",
      "rpm": 63,
      "pembacaan": [97.3, 96.9, 96.8, 95.9, 96.7],
      "suhu":      [26.6, 26.5, 26.5, 26.6, 26.4]
    },
    {
      "titik_ukur": 1018,
      "standard_id": 29,
      "satuan": "cP",
      "spindle": "HA2",
      "rpm": 62,
      "pembacaan": [919.6, 918.7, 917.4, 916.3, 916.3],
      "suhu":      [27.3, 27.4, 27.2, 27.3, 27.3]
    },
    {
      "titik_ukur": 59003,
      "standard_id": 30,
      "satuan": "cP",
      "spindle": "HA7",
      "rpm": 62,
      "pembacaan": [63181.3, 63079.8, 63172.1, 63174.2],
      "suhu":      [24.6, 24.6, 24.6, 24.6]
    }
  ]
}
```

Perhatikan titik ketiga: **empat** pembacaan, bukan lima. Kolom yang dikosongkan
teknisi memang boleh kosong; backend menghitung dari yang terisi. Jangan
memaksa lima kolom terisi sebelum tombol kirim aktif.

## 3. Yang wajib benar — daftar per bagian

### 3.1 Spindle dan RPM: WAJIB, dan berbeda PER TITIK

Ini yang paling penting di alat ini. Sesi contoh dari lab memakai **tiga spindle
berbeda dan dua RPM berbeda** dalam satu sesi: HA1 @63 rpm, HA2 @62 rpm,
HA7 @62 rpm. `SMC` spindle HA1 itu 1 dan HA7 itu 400 — salah pilih menggeser
Fullscale **400 kali lipat**, dan vonis PASS/FAIL ikut terbalik.

- Kirim per baris pengukuran: `measurements[i].spindle` dan `measurements[i].rpm`.
- Boleh juga lewat `spesifikasi_alat.spindle_titik_1` dst. (bentuk yang ditulis
  lembar kerja); kalau dua-duanya dikirim, **yang per baris menang**.
- **Wajib dropdown, bukan isian bebas.** 63 pilihan datang dari
  `bagian[kode=hasil].field[spesifikasi_alat.spindle_titik_N].pilihan`, dan
  labelnya sudah menyebut SMC-nya (`HA7 (SMC 400)`).
- RPM angka bebas, harus `> 0`. Brookfield DV2T bisa serendah 0,01 rpm, jadi
  jangan dipaksa bulat.

Kalau salah satunya kosong, titik itu **tetap dihitung** tapi `toleransi` dan
`keputusan` keluar `null`. Tampilkan sebagai **"belum divonis"** — jangan
sebagai PASS, jangan sebagai FAIL, jangan sebagai error. Backend sengaja tidak
mengarang batas.

### 3.2 Model viscometer: sekali per sesi, menentukan TK

`bagian[kode=model_visco]` mengirim satu field pilihan berisi 12 model
(`spesifikasi_alat.model_visco`). Labelnya `DV2THA / HA (TK 2)` — nama badan
alat, nama di layar alat, nilai TK-nya. Wajib dropdown, alasan sama dengan
spindle.

Kertas Rev.3 menulisnya `MODEL (on body) / MODEL (on screen display)`; di
lembar cetak label sepanjang itu terpotong, jadi labelnya sekarang
`Model — badan / layar`. Pakai label dari API, jangan tulis ulang.

### 3.3 Setiap sel minta DUA angka

Tabel di `bagian[kode=hasil].tabel[]` punya `kolom` berisi dua entri:

```json
"kolom": [
  { "kode": "pembacaan", "label": "cP",  "tipe": "angka", "satuan": "cP" },
  { "kode": "suhu",      "label": "°C",  "tipe": "angka", "satuan": "°C" }
]
```

Kirim sebagai dua array sejajar per titik (`pembacaan` dan `suhu`), panjangnya
sama, indeksnya sejajar. Suhu kosong = nilai acuan meleset jauh, dan
melesetnya tidak kelihatan sebagai error.

### 3.4 Dua tabel: Before dan After Adjustment

`tabel[]` berisi dua blok dengan `tahap` = `sebelum_adjustment` dan
`sesudah_adjustment`. Masing-masing **5 baris × 5 pengulangan**, bentuknya
identik. Jumlah baris DAN jumlah kolom pengulangan **wajib** dibaca dari
`tabel[].baris` & `tabel[].pengulangan`, jangan dari konstanta.

### 3.5 Larutan "30000 cP" tidak pernah ada — yang benar 3000 cP

Versi dokumen sebelumnya meminta blok nonaktif `standar_30000` ditampilkan,
karena seluruh baris budgetnya `#DIV/0!` di master lama dan tidak bisa
dibedakan dari blok mati. **Master 20 Agu 2026 menjawabnya: larutan itu 3000 cP**
(Paragon Scientific/N1400, S/N 2241502068), bloknya hidup penuh, dan sekarang
jadi baris ketiga tabel biasa.

`bagian[kode=standar_30000]` **sudah tidak dikirim API** — hapus penanganannya.
Kalau teknisi yang memegang kertas Rev.3 mencari "30000 cP", jawabannya kertas
itu keliru satu nol; nilainya 3987 cP pada 25 °C, bukan sesuatu di sekitar
30000.

### 3.6 Angka di satu lembar bedanya tiga orde magnitudo

Konsekuensi untuk layar:

- Jangan pakai satu lebar kolom untuk kelima baris. Baris 100000 cP butuh ruang
  enam-tujuh karakter (`110487`); baris 100 cP hanya empat.
- Jangan format angka dengan pemisah ribuan di kotak input. `63.181` ambigu
  buat manusia maupun mesin.
- Jangan pasang `min`/`max` sendiri di input. Lihat bagian 5.

### 3.7 Resolusi ditulis PER TITIK

Kertas Rev.3 eksplisit: *"Resolusi tuliskan pada masing-masing titik
kalibrasi"*. Field `spesifikasi_alat.resolusi_titik_N` ada di
`bagian[kode=hasil]`. Ini catatan lapangan — belum dipakai menghitung sampai lab
memastikan ada alat yang resolusinya memang berbeda per titik.

## 4. Bentuk respons hasil hitung

Setiap titik di `data.titik[]`:

```json
{
  "titik_ke": 1,
  "titik_ukur": 93.8756651,
  "desimal": 2,
  "satuan": null,
  "rata_rata": 96.72,
  "koreksi": -2.8443349,
  "standar_deviasi": 0.51185936,
  "jumlah_pengulangan": 5,
  "type_b_components": [
    { "sumber": "ketidakpastian_standar", "nilai": 0.0847025,
      "keterangan": "Sertifikat kalibrator Viscosity Standard Solution 100 cP (U=0.169405 cP, k=2) pada 25 °C" },
    { "sumber": "resolusi_alat", "nilai": 0.02886751345948129,
      "keterangan": "Daya baca alat 0.1 cP" },
    { "sumber": "ketidakpastian_temperature", "nilai": 0.01877012662240105,
      "keterangan": "UTemperature 0.36124784 °C (÷√3), ci (0.36124784/400)·99.65" },
    { "sumber": "pengulangan_pembacaan", "nilai": 0.22891046284519068,
      "keterangan": "Pengulangan 5 pembacaan (Type A)" },
    { "sumber": "perbandingan_cmc", "nilai": 0.2,
      "keterangan": "U hitung 0.63363755 vs CMC 0.20000000 → dilaporkan hitung" }
  ],
  "ketidakpastian_gabungan": 0.24649577,
  "faktor_cakupan_k": 2.57058184,
  "derajat_kebebasan_efektif": 5.37614444,
  "ketidakpastian_diperluas": 0.63363755,
  "toleransi": 4.14180317,
  "keputusan": "PASS"
}
```

Catatan baca:

- `titik_ukur` yang dibalikkan **bukan** yang kamu kirim. Kamu kirim `99.65`
  (nominal botol), yang kembali `93.8756651` (nilai larutan pada suhu terukur).
  Itu benar, dan itu yang tercetak di kolom `Standard Value` sertifikat.
- `toleransi` = MPE titik itu. `null` berarti spindle/RPM belum diisi.
- `keputusan` `null` berarti belum divonis, bukan gagal.
- `desimal` = jumlah desimal untuk menampilkan angka titik itu. **Pakai ini,
  jangan hardcode 2** — di master sekarang bunyinya BEDA-BEDA per baris
  (2 / 1 / 0 / 1 / 1), dibaca dari format sel sertifikatnya.

Nilai sesi contoh lab (`0817-CAL-726`, nomor sesi `2607.59.W` di seeder), kalau
kamu perlu data uji yang angkanya bisa diadu:

| Titik | Acuan (cP) | UUT (cP) | Koreksi | `U95` | MPE | Vonis | `desimal` |
|---|---|---|---|---|---|---|---|
| 1 (100 cP) | 79,8956964 | 79,64 | +0,255696 | 0,20 | 2,46306667 | PASS | 2 |
| 2 (1000 cP) | 755,74647887 | 779,50 | −23,753521 | 2,3733017 | 24,18844262 | FAIL | 1 |
| 3 (3000 cP) | 2891,02190923 | 2709,80 | +181,221909 | 10,0881294 | — | *belum divonis* | 0 |

Tiga hal yang sengaja ada di tabel itu dan bagus untuk menguji layar:
**koreksi POSITIF** (alat membaca rendah), satu titik **FAIL**, dan satu titik
**tanpa MPE sama sekali** — lembar masternya tidak mengisi spindle & RPM untuk
titik ketiga, jadi batasnya tidak bisa dihitung dan vonisnya `null`.

## 5. Pindai foto (OCR) — dan status verifikasinya

### 5.1 Lembar ini SATU-SATUNYA yang lanskap

Ukuran referensinya **2339 × 1654 @200 dpi**, sementara enam alat lain
1654 × 2339. Ambil dari `ukuran_referensi` di template — **jangan
mengasumsikan potret**, karena perbaikan perspektif (homography) memakai ukuran
itu sebagai ruang tujuan. Salah orientasi = seluruh grid meleset dan tidak ada
gejala yang jelas.

Alasannya satu angka: baris 60000 cP menulis tujuh karakter. Di grid potret
kotaknya 15,7 mm = **2,2 mm per digit tulisan tangan**. Lanskap memberi 29,2 mm
(4,2 mm per digit). Kotak suhu sengaja lebih sempit, 17,5 mm.

### 5.2 Template masih `terverifikasi: false` — ini penting

Koordinat selnya hasil hitung grid, **belum diukur dari foto kertas nyata**.
Selama flag ini `false`, backend menolak semua pindai untuk alat ini, jadi:

- **Jangan bangun jalur auto-isi dari hasil pindai untuk Viscometer sekarang.**
- Bangun layar review-nya, tapi siapkan supaya jalur pindainya bisa dinyalakan
  belakangan tanpa mengubah bentuk layar.

Yang harus terjadi sebelum flag itu jadi `true` (pekerjaan lab + backend, bukan
frontend):

1. `php artisan ocr:cetak-lembar viscometer --versi=1 --keluar=...`
2. Kertasnya dicetak, diisi tangan oleh teknisi
3. Difoto pakai HP yang dipakai di lapangan
4. Diadu: setiap angka harus mendarat di sel yang benar
5. Baru `terverifikasi` disetel `true`

### 5.3 Perilaku yang harus ditampilkan apa adanya

Sudah dijamin backend dan dikunci test (`PindaiViscometerTest`). Tugas frontend
hanya menampilkannya, tidak "membantu".

| Yang dibaca kamera | Yang terjadi | Yang ditampilkan |
|---|---|---|
| `631.74.2` (dua titik desimal) | ditolak, nilai `null`, alasan `pemisah_desimal_tidak_wajar` | sel **merah**, minta teknisi ketik ulang |
| `63.181` di baris 60000 cP | dibaca `63181` lewat bukti pita | sel kuning, nilai terisi |
| `63181.3` di baris 100 cP | ditolak (`di_luar_rentang`, `magnitudo_meleset`) | sel **merah** |
| `96.7` di baris 60000 cP | ditolak, alasan sama | sel **merah** |
| `51.1` di baris 100 cP (larutan pada 37,78 °C) | **diterima** | sel kuning, nilai terisi |

**Jangan menampilkan tebakan untuk sel merah.** Sel merah artinya backend
menolak mengarang angka. Layar yang "membantu" dengan menawarkan tebakan
membatalkan seluruh gunanya — dan `631.74.2` itu sel nyata dari master lab,
bukan kasus karangan.

Angka hasil tulisan tangan **tidak pernah hijau**, paling tinggi kuning. Itu
disengaja: skor OCR untuk tulisan tangan tetap tinggi waktu salah, jadi skor
tidak bisa jadi penjaga — yang bisa cuma mata teknisi.

### 5.4 Pita per baris — jangan diduplikasi di frontend

Pitanya **bukan** `nominal ±10 %`, dan ini yang paling gampang salah kalau
frontend memasang validasinya sendiri:

| Baris | Nominal | Pita yang berlaku |
|---|---|---|
| 100 cP | 99,65 | 42,58 – 160,80 |
| 1000 cP | 1018 | 349,58 – 1804,80 |
| 3000 cP | 3987 | 1344,17 – 6098,40 |
| 60000 cP | 59003 | 16049,17 – 114230,40 |
| 100000 cP | 99613 | 59849,21 – 132584,40 |

Pita itu jangkauan tabel sertifikat larutan pada suhu kerja 20–37,78 °C. Pita
`nominal ±10 %` untuk baris 1000 cP akan jadi 916,2–1119,8 — sementara
pembacaan master yang paling kecil **916,3**, hanya 0,1 cP di atas batasnya. Sesi
yang sama diukur pada 30 °C akan ditolak seluruh barisnya, dengan pesan yang
terbaca seperti kamera gagal padahal angkanya benar.

## 6. Sertifikat

Kolom tabelnya:

| Standard Value (cP) | Unit Under Test (cP) | Correction (cP) | U95%, k=2 (cP) |
|---|---|---|---|

Dua desimal — **ambil dari field `desimal` di respons, jangan hardcode**. Angka
yang disimpan tetap presisi penuh; yang dibulatkan hanya bentuk cetaknya.

Di luar tabel, sertifikat mencetak `Spindel No.` (mis. `1,2,7`) dan
`Speed (rpm)` (mis. `63,62,62`) — keduanya dirangkai backend dari spindle & RPM
per titik yang kamu kirim. Satu alasan lagi kenapa dua field itu tidak boleh
kosong.

## 7. Yang masih menunggu jawaban lab

Tidak ada yang memblokir pekerjaan frontend — semuanya sudah punya keputusan
sementara dan API-nya sudah stabil. Tapi kalau nanti ada angka yang berubah,
inilah sumbernya, supaya tidak dikira bug frontend. Rinciannya di
`docs/pertanyaan-lab-viscometer.md`.

| # | Pertanyaan | Yang berubah kalau lab menjawab lain |
|---|---|---|
| 1 | Angka asli sel `631.74.2` (pembacaan ke-5 titik 60000 cP, master lama) | `jumlah_pengulangan` titik itu jadi 5, `U95`-nya bergeser |
| 2 | `k`: dua aturan dalam satu workbook (2 di dua blok, t-student di tiga blok baru) | `faktor_cakupan_k` & `U95` titik 3000/60000/100000 |
| 4 | Lingkup KAN diperluas dari 58021 cP? | Titik di atas batas dapat lantai CMC |
| 7 | Dari mana angka ketikan 3437 & 1398 di blok interpolasi 3000 cP | Nilai acuan & **tanda koreksi** titik 3000 cP |
| 8 | Resolusi alat: `INPUT DATA` menulis 1, empat blok budget memakai 0,1 | `uc` & `U95` titik 1000 cP |

Butir 3 (larutan 30000 cP) dan 5 (desimal sertifikat) **sudah terjawab** oleh
master 20 Agu 2026 — lihat adendum di kepala dokumen.

Butir 7 yang paling kelihatan di layar kalau lab menjawab lain: koreksi titik
3000 cP bisa **berbalik tanda** (+181 cP jadi −214 cP). Jangan bikin asumsi arah
koreksi di frontend.

## 8. Selesai berarti

1. Lembar kerja Viscometer tampil sesuai bentuk dari API — dua tabel, **lima**
   baris, lima kolom pengulangan, tiap sel dua angka.
2. Spindle & model dipilih dari dropdown, bukan diketik.
3. Titik tanpa spindle/RPM tampil "belum divonis", bukan PASS/FAIL/error.
4. Jumlah desimal tiap baris dibaca dari `desimal` baris itu — **bukan satu
   angka untuk selembar**. Di master sekarang bunyinya 2 / 1 / 0 / 1 / 1.
5. Jumlah baris, kolom pengulangan, satuan, dan desimal tidak ada yang
   dihardcode — semuanya dari respons API.
6. Tidak ada validasi rentang angka buatan frontend.
7. Layar review hasil pindai menampilkan merah/kuning apa adanya, tanpa
   menawarkan tebakan untuk sel merah.
