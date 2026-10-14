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
| Simpan ulang (§8) | `PUT /calibrations/autoclave/{id}` — `main` `56668b1` (PR #235 = `b8b2ca0` + `29c8868`), **live di produksi 15 Okt 2026**. Mobile sudah memakainya (PR mobile #205, rilis 1.0.695) |

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

- ~~Simpan sesi Autoklaf ke DB + status draft/approval~~ — **sudah ada**:
  `POST` & `PUT /calibrations/autoclave`, lihat §8. Status PDF/Excel sertifikat
  Autoklaf tidak diperiksa ulang waktu §8 ditulis — cek `docs/BACA-DULU-BACKEND.md`.
- Template OCR/pindai foto Autoklaf (geometri kertas belum diukur).
- Seeder `standards` untuk kalibrator Autoklaf (sekarang di `config/autoclave.php`).

Untuk sekarang: layar input + tombol "Hitung" (panggil preview) + tampilkan
hasil. Itu jalur yang sudah siap dan terverifikasi.

## 8. Simpan ulang sesi yang sudah ada — `PUT /calibrations/autoclave/{id}`

> **Live di produksi 15 Okt 2026.** `main` `56668b1` (PR #235: `b8b2ca0` +
> `29c8868`); run CI `Tes` untuk SHA itu hijau, termasuk langkah yang memastikan
> versi barunya tersaji. Dijaga `tests/Feature/AutoclaveUpdateTest.php` (21 test).
> Ringkasan kontraknya juga ada di `docs/kontrak-api.md` §4.

### 8.1 Kapan `POST`, kapan `PUT`

| Lembar di HP | Panggil | Jawaban |
|---|---|---|
| Baru, belum pernah tersimpan di server (belum punya id sesi) | `POST /calibrations/autoclave` | **201** `{data}` |
| Dibuka lagi dan **sudah punya id sesi** — `draft`, atau `perlu_revisi` (dikembalikan admin) | `PUT /calibrations/autoclave/{id}` | **200** `{data, meta}` |

Kenapa harus dibedakan: `POST` **selalu** melahirkan sesi baru. `client_request_id`
cuma menyaring kiriman ulang yang persis sama (retry jaringan → 200 replay); lembar
yang dibuka lagi membawa `client_request_id` baru, jadi tiap "Simpan Draf" lewat
`POST` menghasilkan draft kembar. `PUT` menunjuk sesinya lewat URL —
`client_request_id` di body `PUT` boleh ikut tapi **diabaikan** (tidak ada replay,
nilai lama tidak ditimpa). `nomor_sesi` dan pengisi lembar (`teknisi_id`) juga tidak
berubah, termasuk kalau yang menyimpan admin.

Jangan menyimpan sesi Autoklaf lewat `PUT /calibrations/{id}` generik — jalur itu
memvalidasi body `measurements[]`, bukan body Autoklaf. Sebaliknya, `PUT
/calibrations/autoclave/{id}` ke sesi yang bukan Autoklaf dijawab 422 (§8.9).

### 8.2 Body — sama persis dengan `POST /calibrations/autoclave`

Satu FormRequest untuk keduanya (`AutoclaveStoreRequest`). Auth `role:admin,teknisi`.
Tidak ada throttle khusus (beda dari preview).

**Identitas sesi**

| Field | Aturan |
|---|---|
| `equipment_id` | **wajib**, alat milik lab sendiri yang belum dihapus |
| `status` | `"draft"` atau `"menunggu_approval"`; tidak dikirim = `menunggu_approval` |
| `tanggal_kalibrasi` | wajib kecuali `status: "draft"`; tidak boleh di masa depan |
| `tanggal_terima` | opsional, ≤ `tanggal_kalibrasi` |
| `input_method` | `manual` \| `ocr` |
| `lokasi` | `lab` \| `onsite`; `lokasi_nama` teks |
| `room_id`, `calibration_method_id`, `thermohygro_standard_id` | opsional, milik lab sendiri |
| `suhu_awal`, `suhu_akhir`, `kelembaban_awal`, `kelembaban_akhir` | angka; kelembaban 0–100 |
| `waktu_awal`, `waktu_akhir` | `HH:mm` atau `HH:mm:ss` |
| `catatan_teknisi`, `alat_model`, `alat_serial_number`, `alat_merk`, `pemilik_nama`, `pemilik_alamat` | teks |
| `client_request_id` | UUID; dipakai `POST` saja |
| `nomor_order`, `suhu_ketidakpastian`, `kelembaban_ketidakpastian` | **khusus admin** — lihat §8.4 |

**Data ukur** — `set_point`, `suhu`, `tekanan`, `waktu`: bentuk dan aturannya persis
§3 (preview), dengan satu beda: `set_point` boleh kosong untuk draft (§8.5).

**`ocr` (opsional)** — tebakan mesin per sel, **bercermin ke jalur nilainya** dengan
awalan `ocr.`: `ocr.suhu.disk.<i>`, `ocr.suhu.indikator`, `ocr.suhu.suhu_ruang`,
`ocr.tekanan.indikator_pressure`, `ocr.tekanan.pembacaan_standar`. Tiap jalur deret
maksimal 20 sel, **sejajar indeks** dengan deret nilainya (termasuk `null`-nya); tiap
sel `{"raw_text": "...", "confidence": 0..1}` atau `null`. Baris `waktu` tidak punya
padanan `ocr`. Contoh (dari `TebakanMesinAutoclaveTest`):

```json
"ocr": { "suhu": { "disk": [ [ {"raw_text": "121.27", "confidence": 0.9} ] ] } }
```

**`lembar` BUKAN field body.** Server sendiri yang menyalin data ukur kiriman
(`set_point`, `waktu`, `suhu`, `tekanan` — yang `null` dibuang) ke
`hasil_autoclave.lembar`, dan blok `ocr` ke `hasil_autoclave.ocr` di sebelahnya.
Kunci `lembar` di body diabaikan. Untuk mengisi ulang formulir draft yang dibuka
lagi, baca `hasil_autoclave.lembar` dari respons atau `GET /calibrations/{id}`.

**Kirim lembar UTUH di tiap `PUT`, bukan selisihnya.**
- `hasil_autoclave` dihitung ulang **hanya** dari kiriman ini. Blok `tekanan` yang
  tidak ikut terkirim = hilang dari snapshot baru.
- Beberapa kolom identitas selalu ditulis ulang: `equipment_id`, `input_method`
  (tidak dikirim → `manual`), `lokasi` (tidak dikirim → `lab`), `tanggal_kalibrasi`
  (tidak dikirim → kosong). Kolom identitas lain yang tidak dikirim dibiarkan.

### 8.3 Siapa boleh menyimpan, dan status apa

| Status sesi sekarang | Teknisi pengisi | Admin (lab sama) | Teknisi lain / lab lain | Viewer, super_admin |
|---|---|---|---|---|
| `draft` | boleh | boleh | **404** | **403** |
| `perlu_revisi` | boleh | boleh | **404** | **403** |
| `menunggu_approval` | **422** | boleh, tapi tidak bisa jadi draft lagi | **404** | **403** |
| `disetujui` | **422** (terkunci) | **422** (terkunci) | **404** | **403** |
| status lain (mis. `menunggu_pengesahan`) | **422** | **422** | **404** | **403** |

Perpindahan status:

| Status sekarang | Kirim `status: "draft"` | Kirim tanpa `status` / `"menunggu_approval"` |
|---|---|---|
| `draft` | tetap `draft`, `submitted_at: null` | `menunggu_approval`, `submitted_at` = waktu kirim ini |
| `perlu_revisi` | `draft`, `submitted_at: null` | `menunggu_approval`, `submitted_at` = waktu kirim ini |
| `menunggu_approval` (admin) | **422** — tidak ada jalan mundur ke draft | tetap `menunggu_approval`, `submitted_at` **lama dipertahankan** |
| `disetujui` | **422** | **422** |

`submitted_at` cuma terisi waktu **mengirim**; simpan draft selalu `null`. Sunting
admin atas sesi yang sudah antre bukan pengiriman baru, jadi waktunya tidak bergeser.
Mengembalikan lembar ke teknisi itu tugas **Tolak** (`POST /calibrations/{id}/reject`),
bukan Simpan Draf.

Tiap kiriman yang berakhir `menunggu_approval` memicu efek yang sama dengan `POST`:
admin dikabari dan lembar diarsip ke folder PT.

**`catatan_revisi` & `revisi_field` dikosongkan** di tiap `PUT` yang berhasil —
termasuk Simpan Draf pertama atas lembar `perlu_revisi`. Kalau layar revisi
menyorot kolom dari `revisi_field`, simpan catatannya di sisi HP sebelum menyimpan;
sesudah `PUT`, server tidak memulangkannya lagi.

Sesinya dikunci (`lockForUpdate`) selama simpan, dan status dibaca dari baris yang
terkunci — approve yang balapan dengan `PUT` tidak bisa membuat sesi `disetujui`
tertimpa.

### 8.4 Field khusus admin dibuang dari kiriman non-admin

`nomor_order`, `suhu_ketidakpastian`, `kelembaban_ketidakpastian` yang dikirim akun
**non-admin dibuang diam-diam** (bukan 422), di `POST` maupun `PUT`: kirimannya
tetap 200/201, nilainya tidak sampai ke database, dan nomor order yang sudah diisi
admin tetap utuh. Jangan tampilkan ketiganya di form teknisi. Dari admin, dua field
ketidakpastian divalidasi angka ≥ 0 (gagal → 422 `errors.<field>`).

### 8.5 Draft tanpa `set_point` diterima

Draft boleh disimpan sebelum set point diketahui, di `POST` maupun `PUT`.
`hasil_autoclave` tetap dihitung dari bacaan yang ada dengan
`hasil_autoclave.set_point: null` — server **tidak mengarang** set point (tidak ada
angka olahan yang bergantung padanya; dia cuma dicetak di sertifikat), dan
`hasil_autoclave.lembar` tidak memuat kunci `set_point`. Kiriman **non-draft** tanpa
`set_point` tetap **422** `errors.set_point`, dan sesinya tetap `draft`.

### 8.6 `PUT` kosong ke lembar yang sudah berisi → 422 `errors.lembar`

Kalau kiriman tidak memuat **satu bacaan pun**, padahal lembar tersimpan sudah
punya bacaan, server menolak dan **tidak menghapus apa-apa**:

```json
{
  "message": "Lembar Autoklaf yang dikirim nggak berisi satu bacaan pun, padahal lembar ini sudah punya bacaan — nggak ada yang dihapus. Muat ulang lembarnya dulu, lalu simpan lagi.",
  "errors": {
    "lembar": ["Lembar Autoklaf yang dikirim nggak berisi satu bacaan pun, padahal lembar ini sudah punya bacaan — nggak ada yang dihapus. Muat ulang lembarnya dulu, lalu simpan lagi."]
  }
}
```

"Bacaan" = sel berisi di `suhu.disk[*]`, `suhu.indikator`, `suhu.suhu_ruang`,
`tekanan.indikator_pressure`, `tekanan.pembacaan_standar`. Setelan (`set_point`,
`satuan`, `display`, `resolusi_alat`, `uut_setting`, `tekanan_atm_awal`, `waktu`)
bukan bacaan.

Yang wajib dilakukan HP: **jangan mengulang kiriman yang sama** (hasilnya tetap
422). Simpan isian lokal, muat ulang sesi (`GET /calibrations/{id}`), isi formulir
dari `hasil_autoclave.lembar`, baru simpan lagi. Kasus khasnya antrean draft offline
yang mengirim formulir yang belum termuat ulang. Draft yang memang **belum pernah**
berisi bacaan tetap boleh disimpan kosong lagi.

### 8.7 Koreksi admin atas lembar yang sudah dikirim

Admin yang **mengubah angka** di lembar sesi `menunggu_approval` wajib mengirim
`alasan_koreksi` (10–500 karakter); tanpa itu **422** `errors.alasan_koreksi` dan
lembar teknisi tetap utuh. Yang dibandingkan isi `lembar` sesudah dibakukan, jadi
beda urutan kunci atau `121` lawan `121.0` tidak dihitung koreksi; menyimpan ulang
tanpa mengubah angka (mis. cuma nomor seri) tidak meminta alasan.

Koreksi tercatat di jejak audit (nilai `lembar` lama & baru, alasan, pelaku), dan
admin itu **tidak bisa menyetujui** sesi yang sama
(`penyetuju_mengoreksi_pembacaan`).

### 8.8 Respons 200

`{data: CalibrationResource, meta: {belum_dihitung: []}}` — `data` sama bentuknya
dengan `GET /calibrations/{id}`. `meta.belum_dihitung` selalu `[]` (Autoklaf tidak
punya titik GUM); kuncinya ada supaya bentuknya sama dengan `PUT /calibrations/{id}`.
`POST` tidak membawa `meta`.

Kerangka di bawah dirangkai dari `CalibrationResource::toArray()` dan fixture
`AutoclaveUpdateTest`, **bukan** disalin dari respons produksi. `id`, `nomor_sesi`,
dan tanggal ilustratif; field lain `CalibrationResource` (equipment, teknisi,
pelanggan, kondisi lingkungan, dst.) dipangkas.

```json
{
  "data": {
    "id": 101,
    "nomor_sesi": "KAL/2026/10/0003",
    "nomor_order": null,
    "status": "draft",
    "input_method": "manual",
    "tanggal_kalibrasi": "2026-10-14",
    "submitted_at": null,
    "catatan_revisi": null,
    "revisi_field": [],
    "hasil_autoclave": {
      "set_point": 121.0,
      "suhu": { "kestabilan": 0.045, "keseragaman": 0.464, "variasi": 0.10, "u95": 0.4419439029528431 },
      "tekanan": { "satuan": "MPa", "standar_terkoreksi": 0.1231, "koreksi": 0.0111, "u95": 0.0059 },
      "lembar": {
        "set_point": 121.0,
        "waktu": ["02:00:00", "04:00:00", "06:00:00", "08:00:00", "10:00:00"],
        "suhu": { "disk": [[121.27, 121.26, 121.26, 121.26, 121.28], [121.30, 121.26, 121.26, 121.25, 121.25], [121.26, 121.26, 121.28, 121.35, 121.28]], "indikator": [121, 121, 121, 121, 121], "suhu_ruang": [25, 25, 25, 25, 25] },
        "tekanan": { "indikator_pressure": [0.112, 0.112, 0.112, 0.112, 0.112], "tekanan_atm_awal": [0, 0, 0, 0, 0], "satuan": "MPa", "display": "Digital", "pembacaan_standar": [1.233, 1.231, 1.225, 1.224, 1.242] }
      }
    }
  },
  "meta": { "belum_dihitung": [] }
}
```

`hasil_autoclave.suhu` & `.tekanan` di atas **dipangkas** — isinya lengkap persis
respons preview §3 untuk data yang sama (POST, PUT, dan preview memakai jalur hitung
yang sama; `AutoclaveUpdateTest` mengunci U95 suhu `0.4419439029528431` untuk data
ini). `hasil_autoclave.ocr` cuma ada kalau blok `ocr` dikirim.

Dua catatan parsing:
- Angka bulat bisa pulang tanpa desimal (`121`, bukan `121.0`) sesudah lewat kolom
  JSON — dicatat di `AutoclaveUpdateTest`. Parse sebagai `num`, jangan di-cast
  langsung ke `double`.
- `submitted_at` ISO 8601 penuh, bukan string tampilan.

### 8.9 Kode galat

Dua bentuk 422: validasi (`{message, errors: {<field>: [...]}}`) dan penolakan
status/jenis sesi (`{message}` saja, tanpa `errors`). Tampilkan `message` apa adanya.

| Keadaan | Jawaban |
|---|---|
| Tidak login | **401** |
| Viewer, super_admin (gerbang rute `role:admin,teknisi`) | **403** |
| Id tidak ada; teknisi yang bukan pengisi lembar; sesi lab lain | **404** |
| Body tidak lolos `AutoclaveStoreRequest` (termasuk non-draft tanpa `set_point` → `errors.set_point`, field admin bukan angka/negatif → `errors.<field>`) | **422** `errors.*` |
| Sesi bukan sesi Autoklaf | **422** `message`: "Sesi ini bukan sesi Autoklaf — simpan lewat PUT /calibrations/{id}, bukan jalur Autoklaf." |
| Sesi `disetujui` (semua peran) | **422** `message`: "Sesi yang udah disetujui nggak bisa diubah — sertifikatnya udah terbit." |
| Teknisi atas sesi `menunggu_approval`; semua peran atas status lain selain `draft`/`perlu_revisi`/`menunggu_approval`/`disetujui` | **422** `message`: "Sesi yang lagi nunggu approval nggak bisa diubah teknisi. Minta admin yang ngedit, atau minta lembarnya dibalikin dulu." |
| `status: "draft"` untuk sesi `menunggu_approval` | **422** `message`: "Lembar ini sedang menunggu approval, jadi nggak bisa disimpan balik jadi draft. Simpan tanpa `status: draft`, atau kembalikan lembarnya ke teknisi lewat Tolak." |
| Kiriman tanpa bacaan ke lembar yang sudah berisi | **422** `errors.lembar` (§8.6) |
| Admin mengubah angka lembar `menunggu_approval` tanpa alasan | **422** `errors.alasan_koreksi` (§8.7) |

Sesudah gerbang rute, urutannya: validasi body → kepemilikan (404) → jenis sesi →
status → larangan mundur ke draft → lembar kosong → alasan koreksi. Akibatnya body
yang tidak valid ke sesi milik orang lain dijawab **422**, bukan 404 — validasi
FormRequest jalan sebelum isi controller.

### 8.10 Status sisi mobile — sudah jalan

- PR mobile #205 (merge `114c0eb`), rilis **1.0.695** (tag `v1.0.695+695` →
  `114c0eb`, terbit 15 Okt 2026 WIB).
- `_kirimMatriks` di `lib/screens/calibration/lembar_kerja_screen.dart` memanggil
  `AutoclaveService.perbarui` (`PUT`) kalau lembar sudah punya `sesiId`, dan
  `simpan` (`POST`) kalau belum. Kalau kiriman sebelumnya sudah pernah dicoba
  (mis. putus jaringan, mungkin sudah sampai), HP memanggil `POST` draft dulu —
  dijawab replay kalau sesinya ternyata sudah ada — lalu memastikan isinya lewat
  `PUT` ke id yang didapat.
- Galat 422 tampil sebagai snackbar umum (`lkGagalKirim`). **Belum** ada penanganan
  khusus `errors.lembar` (muat ulang otomatis) dan **belum** ada isian
  `alasan_koreksi` di HP (grep `alasan_koreksi` di `lib/` = 0 hasil) — koreksi angka
  oleh admin atas sesi antre dari HP akan selalu 422.

### 8.11 Celah yang diketahui — belum ditutup, sengaja ditulis

1. **Sesi lama tanpa `lembar` tidak terdeteksi sebagai koreksi admin.** `lembar`
   baru ikut disimpan sejak 20 Agt 2026 (`2b64fec`). Untuk sesi Autoklaf yang
   tersimpan sebelum itu, admin yang mengubah angkanya **tidak** diminta
   `alasan_koreksi`, penanda koreksinya tidak tertulis, sehingga pemisahan wewenang
   tidak tahu admin itu ikut mengubah angka. Penjaga `errors.lembar` (§8.6) juga
   tidak melindungi sesi semacam ini. Nilai lama `hasil_autoclave` tetap terekam di
   jejak audit umum, tapi tanpa penanda koreksi. Jumlah sesi semacam ini di produksi
   belum dihitung.
2. **Kabar admin bisa berulang.** Tiap `PUT` yang berakhir `menunggu_approval`
   mengirim ulang notifikasi "sesi menunggu approval" ke semua admin aktif (kecuali
   teknisi pengisi) — termasuk sunting admin atas sesi yang sudah antre, dan admin
   yang menyunting ikut dikabari.
3. **Tidak ada versi — simpan terakhir yang menang.** `PUT` tidak memeriksa
   `updated_at`/ETag dan tidak pernah menjawab 409. Dua perangkat (atau antrean
   offline teknisi + sunting admin) yang menyimpan sesi yang sama: yang terakhir
   menimpa tanpa peringatan. Nilai sebelumnya tetap ada di jejak audit, tapi tidak
   ada yang diberi tahu. Satu-satunya kasus yang dijaga server adalah kiriman tanpa
   bacaan (§8.6). Di sisi HP: muat ulang sesi sebelum menyunting lembar yang lama
   terbuka, dan jangan memutar ulang antrean offline yang sudah basi tanpa memuat
   ulang.
