# Perintah Frontend — Revisi pemilik 15 Okt 2026, gelombang G1 (API)

Dokumen ini berdiri sendiri. Tempel ke sesi kerja `sidik-calibration-mobile`.
Keputusan: `docs/permintaan-user-7.md` §52 (K-50-01 … K-50-14). Kontrak rinci:
`docs/kontrak-api.md` §5. Menggantikan bagian Tolak/sahkan di
`docs/perintah-frontend-super-admin-order.md`.

## Ringkasan untuk HP & desktop

| Dulu | Sekarang |
|---|---|
| Tombol **Tolak** + `lembar_tolak.dart` | **Hapus.** Rute `POST /calibrations/{id}/reject` → `404`. |
| Menu/layar **Pengesahan**, "Sahkan", "Kembalikan", "Tarik pengajuan", antrean | **Hapus.** Semua rutenya `404`. Setujui langsung menerbitkan. |
| Setujui = (opsional) `berlaku_sampai` | Setujui = **`nomor_sertifikat`** + Order Number (+ `berlaku_sampai` opsional) |
| Admin yang mengoreksi tidak boleh menyetujui | **Boleh.** Koreksi dijaga jejak lama → baru. Pengisi lembar tetap tidak boleh. |
| — | Mode **Edit Admin** + layar **Bandingkan** (asli teknisi vs sekarang) |
| — | Aksi **Tarik ke lembar kerja** pada sertifikat terbit |

Izin dari `GET /api/me/permissions` (jangan hardcode peran): hilang `kalibrasi.tolak`,
`kalibrasi.sahkan`, `kalibrasi.kembalikan-dari-pengesahan`, `kalibrasi.tarik-pengajuan`,
`pengesahan.antrean`; baru `kalibrasi.bandingkan`, `sertifikat.tarik` (admin & super admin).

## 1. Setujui

`POST /api/calibrations/{id}/approve`

```json
{ "nomor_sertifikat": "KAL/2026/0123", "berlaku_sampai": "2029-02-28", "abaikan_peringatan": false }
```

Order Number tidak ada di body: diisi lewat `PATCH /api/calibrations/{id}/admin`
`{ "nomor_order": "…" }` (atau `PUT` sesi) — dialog Setujui sebaiknya menampilkan kedua kotak
dan mengisi Order Number dulu lewat PATCH bila belum ada.

Semua galat `422`, **status sesi tidak berubah**, cabangkan dari `kode` (bukan `message`):

| `kode` | Penyebab | Catatan |
|---|---|---|
| `pemisahan_wewenang` | yang menyetujui = pengisi lembar / pengonfirmasi pindai | `wewenang.temuan[].pesan`; jangan tawarkan "lanjutkan" |
| `nomor_order_wajib` | `nomor_order` kosong | sorot kotak Order Number (`errors.nomor_order`) |
| `nomor_sertifikat_wajib` | kosong / spasi | `errors.nomor_sertifikat` |
| `nomor_sertifikat_tidak_valid` | karakter selain huruf/angka/`. _ / -`, >100 karakter, atau berakhiran `-R<angka>` | validasi lokal boleh meniru, server yang memutuskan |
| `nomor_sertifikat_dipakai` | nomor sudah dipakai di lab ini | `sertifikat: { id, nomor, status, nomor_sesi }` — tampilkan "dipakai sertifikat {nomor}" |
| (tanpa `kode`) `butuh_konfirmasi: true` | peringatan validator | kirim ulang `abaikan_peringatan: true` bila disadari |

Urutan: status → pindai belum dikonfirmasi → pemisahan wewenang → order → nomor sertifikat →
`berlaku_sampai` → validator. `409` = sesi barusan disetujui lewat permintaan lain. `503` =
disetujui tapi penerbitan gagal dimulai (tombol "Terbitkan ulang" di sertifikat, tanpa nomor baru).

**Sesi yang sertifikatnya DITARIK** (lihat §4): jangan tampilkan kotak No. Sertifikat — nomor
`<nomor>-R<n>` dibuat server; `nomor_sertifikat` yang terkirim diabaikan. Penanda: sertifikat
terakhir sesi itu berstatus `ditarik` (`GET /certificates?…` / detail sertifikat).

## 2. Edit Admin (pengganti Tolak)

Admin/super admin membuka sesi `menunggu_approval` dalam form yang sama dengan teknisi dan
menyimpan lewat **`PUT /api/calibrations/{id}`** (Autoklaf: `PUT /api/calibrations/autoclave/{id}`),
body penuh. Bila angka pembacaan berubah, kirim **`alasan_koreksi`** (10–500 karakter; satu alasan
boleh untuk beberapa sel) — kalau tidak `422` galat validasi `alasan_koreksi`. Menyimpan angka yang
sama tidak meminta alasan. Sesi `disetujui` → `422` (jalannya: tarik sertifikat, §4).

Isian asli teknisi tidak pernah hilang (server membekukannya di `input_asli`). Sesi
`menunggu_approval` tetap tampil di antrean setelah disimpan (tidak mundur jadi draft).

## 3. Bandingkan

`GET /api/calibrations/{id}/bandingkan` (admin & super admin; teknisi `403`, lab lain `404`).

```json
{ "data": { "sesi_id": 88, "status": "menunggu_approval", "ada_asli": true, "jenis": "pembacaan",
  "asli_dibekukan_pada": "2026-10-15T09:00:00+00:00", "total_sel": 3, "jumlah_berubah": 1,
  "sel": [ { "kunci": "titik_1.-.sesudah_adjustment.sensor_-.pembacaan_1", "asli": 50.02, "sekarang": 50.05,
             "berubah": true, "diubah_oleh": { "id": 3, "nama": "Admin Pemeriksa" },
             "diubah_pada": "2026-10-15T10:00:00+00:00", "alasan": "Koreksi pembacaan oleh Master Data: …" } ] } }
```

Sorot sel `berubah: true`; `diubah_oleh/diubah_pada/alasan` hanya ada di sel yang berubah.
`ada_asli: false` = sesi lama yang belum pernah dikoreksi (tidak ada yang berubah). `jenis:
"autoclave"` → `kunci` = jalur titik di `lembar` (mis. `lembar.suhu.disk.0.0`). Peta kunci →
kotak layar dibuat klien; `kunci` pembacaan tersusun `titik_<titik_ke>.<peran_sensor>.<tahap>.sensor_<n>.pembacaan_<n>`.

## 4. Tarik ke lembar kerja

`POST /api/certificates/{id}/tarik` — admin & super admin. Dialog: alasan + **sandi akun sendiri**.

```json
{ "alasan": "Angka disk 1 salah salin dari logger.", "password": "…" }
```

- Sukses `200`: `data` = sertifikat dengan `status: "ditarik"` (+ `status_dokumen: "ditarik"`),
  `meta.calibration_session_id`. Sesi kembali `menunggu_approval` → buka Edit Admin → Setujui ulang.
- `422`: `alasan` (wajib, 10–2000), `password` (wajib), `kode: "konfirmasi_sandi_salah"`, atau
  `errors.sertifikat` (bukan `terbit`, sudah ditarik/dibatalkan, sudah punya revisi, sesi bukan
  `disetujui`). Throttle 20/menit.
- Setujui ulang → sertifikat baru `<nomor>-R1` (tarik lagi → `-R2`), `revisi_dari` menunjuk yang
  ditarik. Yang ditarik tetap terlihat di daftar lab (PDF tetap bisa dibuka lab); pelanggan tidak
  melihatnya.
- Tampilkan `ditarik` sebagai status sendiri (bukan "dibatalkan"): `status_dokumen` kini
  `berlaku | digantikan | dibatalkan | ditarik | belum_terbit`.

## 5. QR / verifikasi publik

`GET /api/verify/{token}` untuk sertifikat ditarik → `status_dokumen: "ditarik"`,
`digantikan_oleh` (nomor + tautan) bila penggantinya sudah terbit, `ditarik_pada`. Unduh publik
berkas yang ditarik → `410`.

## 6. Super admin

Tetap admin + teknisi (K-49-14/K-50-14). `User::roles()` tidak memuatnya — tidak ada menu
"jadikan super admin". Pelanggan tidak tersentuh.

## 7. Sesi lama

- `perlu_revisi` lama: teknisi tetap bisa memperbaiki & mengirim ulang (`PUT`); tampilkan
  `catatan_revisi` / `revisi_field` bila ada, tapi tidak ada jalur baru yang membuatnya.
- `menunggu_pengesahan` lama: dipindah server ke `menunggu_approval` (perintah artisan
  `kalibrasi:pindahkan-pengesahan`, dry-run dulu). Klien tidak perlu menangani status itu;
  kalau masih muncul, perlakukan seperti `menunggu_approval`.

## 8. G2 (16 Okt 2026) — Telusuri, Arsip satu pintu, jatuh tempo

- **Telusuri No. Sertifikat** menggantikan layar Pelacakan sebagai pencarian: panggil
  `GET /api/sertifikat/telusuri?q=` (debounce 300 ms, min 2 huruf), tampilkan nomor, PT, alat, seri,
  `status_dokumen` + `revisi_ke` (R-n), `tahap_paket`, tombol PDF dari `pdf_url`. Detail di
  `kontrak-api.md` §15. Izin: `sertifikat.telusuri`.
- **Arsip**: ganti semua panggilan `/folders*` & `/folder-files*` ke `/arsip/*` (daftar di §15).
  Struktur kini 4 tingkat; tampilkan breadcrumb dari respons `show`. Tombol "bikin folder"
  tidak perlu lagi (folder otomatis); pindah/ganti nama/unduh/kirim tetap ada. Setelah HP selesai
  pindah, beri tahu backend agar `/folders*` dicabut.
- **Ubah jatuh tempo** (admin/SA): `POST /api/equipments/{id}/jatuh-tempo` dengan alasan. Baca
  `jalur`: `jadwal_alat` = "jadwal diubah"; `revisi_sertifikat` (202) = "dibuat revisi -R<n>,
  tanggal tercetak berubah". Jelaskan bedanya di dialog sebelum kirim (ada sertifikat aktif →
  revisi). Izin: `alat.ubah-jatuh-tempo`.
- **Pelanggan**: `GET /pelanggan/v1/sertifikat?q=` sudah ada; mencari hanya di PT sendiri, nomor PT
  lain = daftar kosong.

## 9. G3 (16 Okt 2026) — permintaan/koreksi/foto dicabut, aplikasi pelanggan hanya baca

- **Aplikasi lab**: buang menu & layar Permintaan Pelanggan, Koreksi Pelanggan, foto pelat nama, dan
  izin `permintaan.*` (tidak ada lagi di `/me/permissions`). Rute `/permintaan-pelanggan*`,
  `/koreksi-pelanggan*`, `/foto-pelanggan/{id}` kini 404; notifikasi lama dengan tipe itu cukup
  ditampilkan sebagai teks.
- **Aplikasi pelanggan** hanya memakai: auth (undangan, masuk, keluar, keluar-semua, lupa/atur sandi),
  beranda, alat (+ riwayat per alat di `GET /alat/{id}`, status jatuh tempo aman/segera/lewat),
  sertifikat (daftar, `?q=`, detail, `/unduh`), notifikasi, `saya` + `preferensi-notifikasi`,
  `app/status`. Hapus layar Permintaan, Koreksi, foto, tombol "Minta koreksi", dan field
  `bisa_minta_koreksi` / `koreksi_menunggu` / `data_cetak` / `foto` dari parser.
- Rilis: URL produksi lewat `--dart-define`, applicationId final, signing kunci rilis; produksi
  menyalakan `FITUR_PELANGGAN` saat deploy G3 (render.yaml; bukan bagian commit ini).

