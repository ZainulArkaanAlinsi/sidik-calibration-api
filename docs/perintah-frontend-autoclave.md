# Perintah Frontend — Autoclave (alat ke-8)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke
sesi kerja frontend — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `merge/develop-ke-main`, commit `07f9de9` |
| Formulir | `SIDIK-FM-CAL-0539_Rev.4` |
| Metode | `SIDIK-IK-CAL-0531_Rev.4` |
| Master | `Master Olah Data_Autoclave.xlsm` (LK-285-IDN) |
| Status backend | Olah data + lembar kerja + endpoint preview **selesai & terverifikasi ke master di MySQL**. Simpan/approve/sertifikat = fase berikut (lihat §7) |

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

Backend modul **Autoclave/Autoklaf** (alat ke-8) sudah selesai untuk bagian olah
data & input. Angka sudah diadu **persis** ke workbook master lab. Tugasmu
menyambungkan layar input teknisi + tampilan hasil.

## 0. Aturan paling penting

1. **Frontend TIDAK menghitung apa pun.** Tidak ada rata-rata, STDEV, koreksi,
   konversi satuan, ketidakpastian, Kestabilan/Keseragaman/Variasi. Semua angka
   datang dari `POST /calibrations/autoclave/preview`. Frontend hanya mengumpulkan
   input mentah dan menampilkan hasil.
2. **Jangan hardcode** jumlah disk, jumlah titik waktu, satuan tekanan, tipe
   display, atau tabel kalibrator. Bentuk lembar datang dari
   `GET /calibrations/lembar-kerja?profil=autoclave`.
3. **Tabel koreksi kalibrator & CMC ada di server** (`config/autoclave.php`).
   Frontend TIDAK mengirimnya. Payload hanya berisi yang diukur teknisi.
4. Kalau ambigu antara dokumen ini dan respons API, **berhenti dan tanya**.

## 1. Kenapa alat ini beda — baca dulu

Beda total dari tujuh alat sebelumnya:

- **Satu sesi = DUA besaran**: Suhu (°C) & Tekanan (bar/MPa). Bukan satu besaran
  banyak titik.
- **Suhu diukur 3 disk sensor sekaligus** (Tecnosoft SterilDisk), tiap disk
  beberapa **titik waktu** (2/4/6/8/10 jam) pada **satu set point** (mis. 121 °C),
  ditambah pembacaan **Indikator** enclosure autoklaf & **Suhu Ruang** tiap titik
  waktu. Keluarannya bukan cuma koreksi: juga **Kestabilan (SS)**,
  **Keseragaman (KS)**, **Variasi Keseluruhan (VK)** — metrik kinerja autoklaf.
- **Tekanan satu titik**: UUT setting dikonversi dari satuan alat (MPa/Psi/…) ke
  bar untuk dihitung, hasil diturunkan lagi ke satuan alat untuk ditampilkan.

Karena bentuk datanya beda, Autoklaf **tidak** lewat `POST /calibrations` biasa.
Ada endpoint sendiri (§3).

## 2. Lembar kerja — `GET /calibrations/lembar-kerja?profil=autoclave`

Bentuknya **mengikuti kertas `SIDIK-FM-CAL-0539_Rev.4` (LK-285-IDN) baris per
baris**, bukan bentuk lembar pH yang dipakai tujuh alat lain. Teknisi mengisi
layar ini sambil memegang kertas yang baru saja ditulis di lapangan, jadi urutan
baris adalah bagian dari kebenarannya — bukan selera tata letak.

Bagian yang dikirim, berurutan:

| `kode` | `grup` | Isi |
|---|---|---|
| `informasi_umum` | General Information | Receive Date, Customer, Addresss, Calibration Date |
| `kondisi_lokasi` | General Information (`kolom: kiri`) | Location of Calibration, T awal/akhir, RH awal/akhir, Thermohygro used |
| `identitas_alat` | General Information (`kolom: kanan`) | Equipment Name, Manufacturer, Type, SN, Range/Resolution Temp., Range/Resolution Pressure, Pressure Unit |
| `hasil_pengukuran` | Data Result | Set Point + **satu** tabel hasil (lihat bawah) |
| `usage_check` | Data Result | Standard Used — **dua** kotak centang |
| `penutup` | Data Result | Catatan, Calibrated by (Name/Sign), Corrected by (Name/Sign) |

Semua bagian `halaman: 1` — kertasnya memang satu halaman.

### `matriks` (di section `hasil_pengukuran`)

Suhu dan tekanan ada di **satu tabel yang sama**, karena di kertas keduanya
dicatat berdampingan pada kolom waktu yang sama.

```json
"matriks": {
  "judul_kolom": "Pengukuran Berulang UUT Selama Proses Sterilisasi",
  "titik_waktu": [1,2,3,4,5],
  "baris_waktu": {"kode":"waktu","label":"Time","tipe":"jam","format":"HH:mm:ss","kode_data":"waktu"},
  "baris": [
    {"kode":"disk_1","label":"Temp. Disk 1","tipe":"disk","satuan":"°C","kode_data":"suhu.disk.0"},
    {"kode":"disk_2","label":"Temp. Disk 2","tipe":"disk","satuan":"°C","kode_data":"suhu.disk.1"},
    {"kode":"disk_3","label":"Temp. Disk 3","tipe":"disk","satuan":"°C","kode_data":"suhu.disk.2"},
    {"kode":"indikator_suhu","label":"Indikator Suhu","tipe":"indikator_suhu","satuan":"°C","kode_data":"suhu.indikator"},
    {"kode":"indikator_pressure","label":"Indikator Pressure","tipe":"indikator_tekanan","satuan_dari":"satuan_tekanan","kurung_satuan":true,"kode_data":"tekanan.indikator_pressure"},
    {"kode":"tekanan_atm_awal","label":"Tekanan atm awal","tipe":"tekanan_atm","satuan_dari":"satuan_tekanan","kurung_satuan":true,"kode_data":"tekanan.tekanan_atm_awal"},
    {"kode":"suhu_ruang","label":"Suhu Ruang","tipe":"suhu_ruang","satuan":"°C","kode_data":"suhu.suhu_ruang"}
  ]
}
```

Render sebagai **tabel**: baris = 7 entri di `baris`, kolom = `titik_waktu`.
Baris `baris_waktu` digambar di atasnya sebagai **input jam** (`HH:mm:ss`) —
kertas menulis `__:__:__:__`, bukan nomor urut 1–5. `kode_data` memberi tahu ke
kunci payload mana tiap baris dikirim, jadi frontend tidak perlu menghafal.

**Perubahan dari versi pertama handoff ini** (kalau layar lama sudah dibuat):
`hasil_suhu` + `hasil_tekanan` → satu section `hasil_pengukuran`; `matriks_suhu`
→ `matriks`; baris `indikator` → `indikator_suhu`; **dua baris baru**
`indikator_pressure` dan `tekanan_atm_awal` (masing-masing 5 kolom).

### `tabel_tekanan` — di luar kertas

```json
"tabel_tekanan": {
  "label": "Pressure Disk Logger — hasil unduh (Bar)",
  "di_luar_kertas": true,
  "kolom": {"kode":"tekanan.pembacaan_standar","label":"Standar Reading","satuan":"Bar"},
  "pengulangan": [1,2,3,4,5]
}
```

Angkanya **diunduh dari Pressure Disk Logger**, tidak pernah ditulis tangan di
lapangan — karena itu tidak ada di kertas. Gambar **terpisah** dari tabel di
atas dan beri label, jangan diselipkan sebagai baris formulir. Boleh kosong:
lembarnya tetap terkirim, hanya olah data tekanannya menunggu.

Section ini juga membawa `field_di_luar_kertas`: `tekanan.uut_setting` dan
`display_tekanan`, dengan penanda yang sama.

### Bacaan UUT tekanan — jangan dirata-rata di layar

Kertas menyediakan **lima** kolom `Indikator Pressure`; master `INPUT_DATA`
menyimpan **satu** `UUT Reading`. Aturannya:

- kelima kolom terisi sama → backend memakai angka itu, `uut_setting` **tidak
  perlu dikirim**;
- kolomnya berbeda-beda → backend menolak (422, error di
  `tekanan.indikator_pressure`) dan meminta `tekanan.uut_setting` yang dipilih
  orang.

Jangan merata-rata atau mengambil kolom pertama di frontend. Metode
`SIDIK-IK-CAL-0531_Rev.4` tidak menyuruh begitu, dan angka karangan yang terlihat
wajar akan lolos sampai ke sertifikat.

### Thermohygro

`pilihan` pada field `thermohygro_standard_id` kini membawa penanda `tercetak`.
Yang `true` (TH-2, TH-6, TH-7) digambar sebagai **kotak centang** seperti di
kertas; sisanya tetap dikirim dan ditawarkan sebagai "unit lain". Jangan
membuang yang `tercetak: false` — pernah terjadi sesi memakai unit yang tidak
tercetak, dan teknisi jadi tidak punya pilihan yang benar sama sekali.

### Standard Used

`baris` berisi **dua** entri (sesuai kertas). Entri pertama membawa `anggota`
berisi tiga Temperature Calibrator lengkap dengan `standard_id`/`serial_number`
— satu kotak centang, tiga standar tertaut.

> Section OCR/pindai foto belum tersedia untuk Autoklaf (`siap_pindai:false`,
> `geometri_belum_diukur`). Input manual saja untuk sekarang.

## 3. Olah data — `POST /calibrations/autoclave/preview`

Auth: `role:admin,teknisi`. Throttle 120/menit (aman dipanggil tiap teknisi
selesai isi satu kolom). Hitung tanpa menyimpan.

### Request body

```json
{
  "set_point": 121.0,
  "suhu": {
    "disk": [
      [121.27, 121.26, 121.26, 121.26, 121.28],
      [121.30, 121.26, 121.26, 121.25, 121.25],
      [121.26, 121.26, 121.28, 121.35, 121.28]
    ],
    "indikator": [121, 121, 121, 121, 121],
    "suhu_ruang": [25, 25, 25, 25, 25],
    "resolusi_alat": 0.01
  },
  "tekanan": {
    "indikator_pressure": [0.112, 0.112, 0.112, 0.112, 0.112],
    "tekanan_atm_awal": [0, 0, 0, 0, 0],
    "satuan": "MPa",
    "display": "Digital",
    "resolusi_alat": 0.001,
    "pembacaan_standar": [1.233, 1.231, 1.225, 1.224, 1.242]
  },
  "waktu": ["02:00:00", "04:00:00", "06:00:00", "08:00:00", "10:00:00"]
}
```

> `tekanan.uut_setting: 0.112` (bentuk lama, satu angka) **masih diterima** dan
> hasilnya identik — klien lama tidak perlu diubah serentak.

Catatan payload:
- `set_point` **wajib**. Sisanya opsional; kirim blok `suhu` saja atau `tekanan`
  saja boleh (untuk "hitung sambil ngetik").
- `suhu.disk` = array of array. Disk ke-i otomatis dipasangkan ke Temperature
  Calibrator ke-i di server. Maksimal 3 disk.
- Sel kosong boleh `null` — tidak ikut dirata-rata.
- `tekanan.pembacaan_standar` **dalam Bar** (Pressure Disk Logger membaca bar);
  boleh kosong — blok tekanan tetap tersimpan, hanya belum dihitung.
  `indikator_pressure` / `uut_setting` dalam satuan `satuan`.
- `waktu` = baris "Time" di kertas, format `HH:mm` atau `HH:mm:ss`. Tidak ikut
  menghitung, tapi disimpan: tanpa jamnya lima kolom angka tidak bisa diadu
  balik ke rekaman disk.
- `resolusi_alat` opsional; kalau kosong pakai default server (suhu 0.01,
  tekanan 0.001).

### Response (`data`) — contoh dari sesi master 0281-CAL-624

```json
{
  "set_point": 121.0,
  "suhu": {
    "indikator_rata": 121.0,
    "stdev_indikator": 0.0,
    "sensor": [
      {"no":1,"rata":121.266,"koreksi_standar":0.13,"standar_terkoreksi":121.396,"koreksi":0.396,"delta_t":0.02},
      {"no":2,"rata":121.264,"koreksi_standar":0.20,"standar_terkoreksi":121.464,"koreksi":0.464,"delta_t":0.05},
      {"no":3,"rata":121.286,"koreksi_standar":0.15,"standar_terkoreksi":121.436,"koreksi":0.436,"delta_t":0.09}
    ],
    "kestabilan": 0.045,
    "keseragaman": 0.464,
    "variasi": 0.10,
    "budget": [ /* 6 komponen: komponen, u, pembagi, vi, ci, ui, uici */ ],
    "uc": 0.2241822142971117,
    "v_eff": 209.38864755710154,
    "k": 1.9713602363081708,
    "u_bentangan": 0.4419439029528431,
    "cmc": 0.34,
    "u95": 0.4419439029528431
  },
  "tekanan": {
    "satuan": "MPa",
    "uut_setting": 0.112,
    "uut_setting_bar": 1.12,
    "standar_rata_bar": 1.231,
    "index_bar": 1.5,
    "koreksi_standar_bar": 0.0,
    "standar_terkoreksi_bar": 1.231,
    "koreksi_bar": 0.111,
    "stdev_bar": 0.00724568837309471,
    "budget": [ /* 5 komponen */ ],
    "uc": 0.004428378183187759,
    "v_eff": 20.38075689639711,
    "k": 2.085963447265865,
    "u_bentangan_bar": 0.009237435020799286,
    "cmc_bar": 0.059,
    "u95_bar": 0.059,
    "standar_terkoreksi": 0.1231,
    "koreksi": 0.0111,
    "u95": 0.0059
  }
}
```

**Untuk ditampilkan di layar/sertifikat, pakai field satuan-display**, bukan
`*_bar`: `tekanan.standar_terkoreksi` (Standard Value), `tekanan.koreksi`
(Correction), `tekanan.u95` (U95%). Field `*_bar` hanya untuk audit.

## 4. Apa arti tiap keluaran (untuk label layar)

**Section A — Sebaran Suhu** (per `suhu.sensor[]`): `standar_terkoreksi` =
pembacaan standar sudah dikoreksi; `koreksi` = selisih ke Indikator; `u95` sesi =
`suhu.u95` (satu angka untuk semua sensor).

**Section B — Kinerja Autoklaf**: `kestabilan` (SS = ½·max ΔT antar titik waktu),
`keseragaman` (KS = |simpangan sensor terbesar vs Indikator|), `variasi`
(VK = Tmax−Tmin seluruh sensor). Coverage factor `k` = `suhu.k`.

**Section C — Tekanan**: UUT `tekanan.uut_setting`, Standard `standar_terkoreksi`,
Correction `koreksi`, U95% `u95` (satuan = `tekanan.satuan`). Coverage `tekanan.k`.

## 5. Satu hal yang perlu kamu tahu soal angka master (sudah diputuskan)

Master Excel punya bug tarik sel di baris disk (titik waktu ke-5 terbuang, ke-3
terhitung dua kali) — dan lebih dari itu, **cache master-nya kontradiksi**: nilai
disk 1 & 2 hanya cocok kalau titik ke-5 dibuang, tapi disk 3 hanya cocok kalau
titik ke-5 ikut. Jadi "persis master" tidak terdefinisi.

**Backend merata-rata semua 5 titik waktu** — satu-satunya nilai konsisten dan
sesuai formulir prosedur `SIDIK-FM-CAL-0539_Rev.4`. Efeknya: `keseragaman` =
**0,464** (sertifikat lama 0281-CAL-624 cetak 0,466). Kestabilan, Variasi, U95
identik master. **Ini keputusan final yang disetujui pemilik repo.** Kalau ada
yang membandingkan ke sertifikat lama dan tanya 0,464 vs 0,466, itu sebabnya —
bukan bug frontend, dan bukan untuk "dibetulkan" ke 0,466.

## 6. Pembulatan tampilan

Angka dari API presisi penuh. Untuk **tampilan**, ikuti master:
- Suhu: koreksi & U95 → 3–4 desimal (ikuti resolusi 0,01 → tampilkan wajar).
- Tekanan (MPa): 4 desimal (`0.1231`, `0.0111`, `0.0059`).

Pembulatan tampilan tidak boleh mengubah angka yang dikirim balik saat simpan
(fase §7). Simpan nilai penuh dari API.

## 7. Yang BELUM ada (fase berikut, jangan diasumsikan)

- Simpan sesi Autoklaf ke DB + status draft/approval + PDF/Excel sertifikat.
- Template OCR/pindai foto Autoklaf (geometri kertas belum diukur).
- Seeder `standards` untuk kalibrator Autoklaf (sekarang di `config/autoclave.php`).

Untuk sekarang: layar input + tombol "Hitung" (panggil preview) + tampilkan
hasil. Itu jalur yang sudah siap dan terverifikasi.
