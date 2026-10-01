# Kontrak frontend — revisi & pembatalan sertifikat, koreksi pelanggan, foto, resi & jadwal

Ditulis 1 Okt 2026 untuk dua klien: aplikasi lab (`sidik-calibration-mobile`) dan
aplikasi pelanggan (`sidik-pelanggan-mobile`). PRD-nya `docs/permintaan-user-7.md`
§38 (revisi/batal) dan §42 (koreksi, foto, resi/jadwal, email mingguan).

Semua tanggal polos `YYYY-MM-DD`; semua cap waktu ISO-8601 Zulu
(`2026-10-01T02:15:00Z`). Galat validasi = 422 bentuk Laravel
(`{message, errors: {field: [pesan]}}`). Galat keadaan (sertifikat sudah
digantikan, koreksi sudah diputus, dst.) = 422 dengan `message` yang siap
ditampilkan; sebagian membawa `errors` berkunci umum (`sertifikat`, `koreksi`,
`perubahan`) — tampilkan `message`, jangan bergantung pada ada/tidaknya `errors`.

---

## A. Aplikasi LAB — prefix `/api`

### A1. Sertifikat: bentuk `Certificate` bertambah

Semua field lama tetap. Tambahan:

| Field | Tipe | Isi |
|---|---|---|
| `status` | string | sekarang bisa juga `dibatalkan` (selain `menunggu_generate`/`terbit`/`gagal`) |
| `status_dokumen` | string | `berlaku` · `digantikan` · `dibatalkan` · `belum_terbit` — label yang ditampilkan |
| `revisi_ke` | int | 0 = asli; 1 = `-R1`, dst. |
| `revisi_dari` | `{id, nomor}` \| null | pendahulu langsung |
| `digantikan_oleh` | `{id, nomor, status}` \| null | revisi terbaru atas sertifikat ini, **status apa pun** (jadi revisi yang masih `menunggu_generate` ikut tampil) |
| `alasan_revisi` | string \| null | internal — hanya di aplikasi lab |
| `dibatalkan_pada` | ISO \| null | |
| `dibatalkan_oleh` | `{id, nama}` \| null | |
| `alasan_pembatalan` | string \| null | internal |
| `catatan_pelanggan` | string \| null | teks yang dibaca pelanggan |
| `bisa_direvisi` | bool | `terbit` dan belum punya revisi yang sedang/berhasil jalan |
| `bisa_dibatalkan` | bool | `terbit` dan belum digantikan |
| `pdf_url` | string \| null | sekarang juga terisi untuk `dibatalkan` (arsip lab tetap bisa diunduh) |
| `data_cetak` | object \| null | nilai yang TERCETAK di sertifikat ini, dari snapshot — isian awal formulir revisi: `{pemilik, alamat, merk, tipe, nomor_seri, lokasi_kalibrasi, tanggal_kalibrasi, berlaku_sampai}`. Null kalau snapshot kosong (sertifikat lama) — revisinya ditolak server |

Hanya di `GET /certificates/{id}` (detail), kalau `bisa_dibatalkan`:

```json
"dampak_pembatalan": {
  "jadwal_dikosongkan": true,
  "jatuh_ke": null
}
```

`jatuh_ke` = `{id, nomor, berlaku_sampai}` sertifikat sah sebelumnya yang akan
jadi sumber jadwal alat. `jadwal_dikosongkan: true` → tampilkan peringatan di
modal konfirmasi: "Ini satu-satunya sertifikat sah alat ini. Jadwal kalibrasi
ulangnya akan dikosongkan."

### A2. `POST /certificates/{id}/revisi` — admin

```json
{
  "perubahan": {
    "pemilik": "PT Contoh Jaya",
    "alamat": "Jl. Contoh 1, Bandung",
    "merk": "Hanna",
    "tipe": "HI2211",
    "nomor_seri": "HI2211-0419",
    "lokasi_kalibrasi": "Lab PT Sidik",
    "tanggal_kalibrasi": "2026-09-24",
    "berlaku_sampai": "2027-09-24"
  },
  "alasan": "Salah ketik nomor seri (wajib, internal)",
  "catatan_pelanggan": "Nomor seri diperbaiki sesuai pelat nama. (opsional)"
}
```

- Isi `perubahan` minimal satu kunci, cuma kunci di atas. Kunci lain → 422
  `errors["perubahan.<kunci>"]`. Nilai yang sama dengan yang tercetak tidak
  dihitung sebagai perubahan; kalau semuanya sama → 422 "Tidak ada yang berubah".
- `berlaku_sampai` harus sesudah `tanggal_kalibrasi` (yang baru, atau yang lama
  kalau tidak diubah).
- **202** `{message, data: Certificate}` — `data` = baris REVISI baru
  (`status: menunggu_generate`, nomor `CAL/…/0011-R1`). PDF-nya dirender di
  antrean; layar menunggu sinyal realtime `sertifikat`/`direvisi` atau
  menyegarkan.
- 422 `{message}` kalau sertifikat bukan `terbit`, sudah digantikan, atau
  revisinya sedang diproses.
- Teknisi/viewer/super admin → 403. Lab lain → 404.
- Revisi yang `gagal` dirender pakai tombol **Terbitkan ulang** yang sama
  (`POST /certificates/{id}/retry`).

### A3. `POST /certificates/{id}/batalkan` — admin

```json
{ "alasan": "wajib, internal", "catatan_pelanggan": "opsional, dibaca pelanggan" }
```

**200** `{message, data: Certificate}`. 422 `{message}` kalau bukan `terbit`
atau sudah digantikan. Final — tidak ada "batalkan pembatalan".

Pembatalan TIDAK menghidupkan pendahulu yang sudah digantikan (K38-2).

### A4. Koreksi dari pelanggan — admin

| Metode | Rute |
|---|---|
| GET | `/koreksi-pelanggan?status=menunggu\|diterima\|ditolak\|semua&per_page=20` |
| GET | `/koreksi-pelanggan/{id}` |
| POST | `/koreksi-pelanggan/{id}/terima` |
| POST | `/koreksi-pelanggan/{id}/tolak` |

Daftar: `{data: [Koreksi], meta: {total, per_page, current_page, last_page, jumlah: {menunggu}}}`.
Bawaan `status=menunggu`.

Bentuk `Koreksi` (lab):

```json
{
  "id": 7,
  "jenis": "alat",
  "status": "menunggu",
  "pelanggan": {"id": 3, "nama": "PT Contoh Jaya"},
  "diajukan_oleh": {"id": 41, "nama": "Budi"},
  "diajukan_pada": "2026-10-01T02:15:00Z",
  "alat": {"id": 12, "nama": "pH Meter", "serial": "HI2211-0419"},
  "sertifikat": null,
  "perubahan": [
    {"field": "serial_number", "label": "Nomor seri", "lama": "HI2211-0491", "baru": "HI2211-0419"}
  ],
  "catatan": "Nomor seri tertukar dua digit",
  "foto": [{"id": 5, "url": "https://…/api/foto-pelanggan/5"}],
  "tanggapan": null,
  "ditinjau_oleh": null,
  "ditinjau_pada": null,
  "revisi": null
}
```

`jenis: sertifikat` → `alat` berisi alat sertifikat itu dan `sertifikat` =
`{id, nomor}`.

**Terima** — body opsional:

```json
{ "tanggapan": "Sudah kami perbaiki.", "perubahan": {"serial_number": "HI2211-0419"}, "alasan": "…" }
```

- `perubahan` (opsional) menimpa nilai yang diminta pelanggan — admin boleh
  membetulkan sebelum menerapkan. Kuncinya harus kunci yang ada di koreksi itu.
- Jenis `alat`: kolom alat langsung diubah (tercatat di riwayat audit alat).
  Nomor seri bentrok → 422 `errors["perubahan.serial_number"]`.
- Jenis `sertifikat`: server MENERBITKAN REVISI (A2) dengan perubahan itu;
  `alasan` bawaannya "Koreksi dari pelanggan #7". `data.revisi` = `{id, nomor, status}`.
- **200** `{message, data: Koreksi}`. Sudah diputus → 422 `{message}`.

**Tolak** — `{ "tanggapan": "wajib, dibaca pelanggan" }` → 200.

Foto: `GET /foto-pelanggan/{id}` memulangkan gambar (bearer token lab). Lab lain → 404.

### A5. Permintaan pelanggan — tahap, resi, jadwal

Bentuk permintaan lab bertambah:

| Field | Isi |
|---|---|
| `tahap` | `diajukan` · `ditolak` · `dibatalkan` · `menunggu_alat` · `dalam_pengiriman` · `menunggu_jadwal` · `teknisi_dijadwalkan` · `alat_di_lab` · `sedang_dikalibrasi` · `selesai` |
| `tahap_label` | label siap tampil |
| `resi` | `{kurir, nomor, diisi_pada}` \| null — diisi pelanggan |
| `jadwal` | `{pada (ISO), lokasi, catatan}` \| null — diisi admin |
| `alat_tiba_pada` | ISO \| null |
| `progres` | `{selesai, total}` \| null — dari paket |
| `alat[].foto` | `[{id, url}]` foto pelat nama alat baru |

| Metode | Rute | Syarat |
|---|---|---|
| POST | `/permintaan-pelanggan/{id}/jadwal` `{jadwal_pada, lokasi?, catatan?}` | `diterima` + `diambil_lab` |
| POST | `/permintaan-pelanggan/{id}/alat-tiba` (tanpa body) | `diterima`, belum ditandai |

Keduanya 200 `{message, data}`; 422 `{message}` di luar syarat.

### A6. Notifikasi & realtime baru (lab)

| `kategori` | `tautan` |
|---|---|
| `koreksi_pelanggan_baru` | `{tipe: "koreksi_pelanggan", id}` |
| `resi_diisi` | `{tipe: "permintaan_pelanggan", id}` |

Sinyal `PerubahanDataOrganisasi`: jenis `sertifikat` (aksi `direvisi`,
`dibatalkan`), `koreksi_pelanggan` (`dibuat`, `diterima`, `ditolak`),
`permintaan` (`resi`, `jadwal`, `alat_tiba`).

---

## B. Aplikasi PELANGGAN — prefix `/api/pelanggan/v1`

### B1. Sertifikat

Bentuk `Sertifikat` (daftar & detail) bertambah:

| Field | Isi |
|---|---|
| `status` | `berlaku` · `digantikan` · `dibatalkan` |
| `digantikan_oleh` | `{id, nomor, diterbitkan_pada}` \| null (sekarang juga di daftar) |
| `dibatalkan_pada` | tanggal \| null |
| `catatan_pelanggan` | catatan lab (untuk `digantikan`: catatan di revisinya; untuk `dibatalkan`: catatan pembatalan) |
| `bisa_diunduh` | `false` untuk `dibatalkan` |
| `bisa_minta_koreksi` | `true` kalau `berlaku` dan belum ada koreksi `menunggu` |
| `koreksi_menunggu` | `{id}` \| null |
| `data_cetak` (detail saja) | `{pemilik, alamat, merk, tipe, nomor_seri, lokasi_kalibrasi, tanggal_kalibrasi}` — nilai tercetak, isian awal formulir minta koreksi |

- Daftar bawaan menyembunyikan yang `digantikan`
  (`?termasuk_digantikan=1` membuka). Yang `dibatalkan` TETAP tampil.
- `GET /sertifikat/{id}/unduh` untuk `dibatalkan` → **410** `{message}`.
- `POST /sertifikat/{id}/minta-koreksi`:

```json
{ "perubahan": {"nomor_seri": "HI2211-0419"}, "catatan": "opsional" }
```

Kunci boleh: `pemilik`, `alamat`, `merk`, `tipe`, `nomor_seri`,
`lokasi_kalibrasi`, `tanggal_kalibrasi`. **201** `{message, data: Koreksi}`.
422 `{message}` kalau bukan `berlaku` atau sudah ada koreksi `menunggu`.

### B2. Alat

Bentuk `Alat` detail bertambah:

| Field | Isi |
|---|---|
| `resolusi` | number \| null — isian awal formulir ubah alat |
| `catatan` | string \| null — catatan MILIK PELANGGAN (bukan catatan internal lab) |
| `terkunci` | bool — sudah punya sertifikat terbit |
| `field_terkunci` | `["nama_alat","merk","model","serial_number","no_identifikasi","range_min","range_max","satuan","resolusi"]` atau `[]` |
| `foto` | `[{id, url}]` maks 3 |
| `koreksi_menunggu` | `{id}` \| null |

| Metode | Rute | Body |
|---|---|---|
| PATCH | `/alat/{id}` | subset `lokasi`, `catatan`, dan — HANYA kalau tidak terkunci — `nama_alat`, `merk`, `model`, `serial_number`, `no_identifikasi`, `range_min`, `range_max`, `satuan`, `resolusi` |
| POST | `/alat/{id}/minta-koreksi` | `{perubahan: {…kunci identitas di atas}, catatan?}` → 201 `{message, data: Koreksi}` |
| POST | `/alat/{id}/foto` | multipart `foto` → 201 `{data: {id, url}}` |

PATCH dengan field terkunci → 422 `{message, kode: "field_terkunci", errors: {<field>: [...]}}`.
PATCH → 200 `{message, data: Alat}`.

### B3. Foto (pelat nama)

Aturan satu untuk semua: jpg/png/webp, maks 5 MB (kompres di HP dulu, buang
EXIF/GPS), **maks 3 foto per pemilik**. Lebih → 422 `errors.foto`.

| Metode | Rute | Syarat |
|---|---|---|
| POST | `/alat/{id}/foto` | kapan saja |
| POST | `/permintaan/{id}/item/{item}/foto` | permintaan `baru`, item alat BARU |
| POST | `/koreksi/{id}/foto` | koreksi `menunggu` |
| GET | `/foto/{id}` | gambar; butuh bearer + `X-Perusahaan-Id` |
| DELETE | `/foto/{id}` | 204; pemiliknya masih boleh diubah (alat: selalu; permintaan: `baru`; koreksi: `menunggu`) |

`url` di respons sudah absolut ke `GET /foto/{id}`. Unduh gambarnya dengan
header yang sama dengan panggilan API lain (bukan URL publik).

Permintaan: `alat[].foto` = `[{id, url}]`.

### B4. Koreksi

| Metode | Rute |
|---|---|
| GET | `/koreksi?status=menunggu\|diterima\|ditolak\|semua` |
| GET | `/koreksi/{id}` |

Bentuk `Koreksi` pelanggan = bentuk lab TANPA `pelanggan` dan `ditinjau_oleh`
(nama orang lab tidak pernah dikirim); `diajukan_oleh` = `{nama}`.
`revisi` = `{id, nomor}` sertifikat pengganti kalau koreksi sertifikat diterima.
Bawaan `status=semua`, terbaru dulu.

### B5. Permintaan: tahap, resi, jadwal

Bentuk permintaan pelanggan mendapat `tahap`, `tahap_label`, `resi`, `jadwal`,
`alat_tiba_pada`, `progres` (sama dengan A5) plus `perlu_tindakan` (bool) dan
`pesan_tindakan` (string \| null, mis. "Kirim alatnya ke lab, lalu isi nomor resi.").
Daftar mengurutkan yang `perlu_tindakan` ke atas.

`POST /permintaan/{id}/resi` `{kurir, nomor_resi}` → 200 `{message, data}`.
Syarat: `diterima`, `diantar_sendiri`, alat belum ditandai tiba. Boleh diisi
ulang (salah ketik) selama syarat itu masih berlaku.

### B6. Notifikasi pelanggan baru

| `kategori` | `tautan` |
|---|---|
| `pelanggan.sertifikat_direvisi` | `{tipe: "pelanggan_sertifikat", id: <id revisi>}` |
| `pelanggan.sertifikat_dibatalkan` | `{tipe: "pelanggan_sertifikat", id}` |
| `pelanggan.koreksi_diterima` / `pelanggan.koreksi_ditolak` | `{tipe: "pelanggan_koreksi", id}` |
| `pelanggan.jadwal_teknisi` | `{tipe: "pelanggan_permintaan", id}` |

### B7. Ringkasan email mingguan

Saklar `ringkasan_email_mingguan` di `/preferensi-notifikasi` sekarang punya
pengirim: tiap **Senin 07.15 WIB**, ke email akun anggota yang saklarnya
menyala. Isinya alat jatuh tempo ≤30 hari & lewat, sertifikat terbit 7 hari
terakhir, paket berjalan, permintaan aktif. Minggu tanpa isi tidak dikirimi.
Tidak ada rute baru; layar preferensi cukup menampilkan kalimat jadwalnya.

---

## C. Halaman verifikasi publik `/verify/{token}` (web, bukan aplikasi)

- Sertifikat `digantikan`: bilah kuning "Sertifikat ini sudah direvisi. Yang
  berlaku: <nomor> (terbit <tgl>)" + tautan ke halaman verifikasi revisi. Lembar
  lama tetap tampil & bisa diunduh sebagai riwayat.
- `dibatalkan`: bilah merah "Sertifikat ini DIBATALKAN pada <tgl>." Tanpa alasan,
  tanpa lembar, unduh → 410.
- Kartu ringkas di atas lembar: nama pemilik **disamarkan sebagian**
  (`PT Con•• Jaya Abadi`); lembar lengkap (nama utuh) dibuka lewat
  "Tampilkan lembar lengkap".
