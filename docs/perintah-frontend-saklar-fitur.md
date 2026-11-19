# Saklar fitur oleh Super Admin — perintah untuk frontend (19 Nov 2026)

Berdiri sendiri. Server sudah siap; yang dikerjakan aplikasi (Lab, layar Super Admin) hanya tiga hal:
menyembunyikan tombol fitur yang mati, menangani `503 fitur_dimatikan`, dan (untuk Super Admin)
layar pengaturan. Penjagaan ada di server: menyembunyikan tombol bukan kontrol akses.

## Apa ini

Super Admin bisa mematikan **fitur opsional** lab tanpa deploy dan tanpa menyentuh env Render. Daftarnya
**tertutup dan kecil**:

| Kode | Fitur | Rute yang menjawab `503` saat mati | Tidak terpengaruh |
|---|---|---|---|
| `pindai_lembar` | Pindai lembar kerja di server | `GET /worksheet-templates`, `GET /worksheet-templates/{kode}`, `POST /worksheet-scans`, `GET /worksheet-scans/{id}`, `GET /worksheet-scans/{id}/sel/{kunci}/crop`, `POST /worksheet-scans/{id}/koreksi` | Isi manual lembar kerja, hitung, simpan |
| `kirim_email_sertifikat` | Kirim sertifikat lewat surel | `POST /certificates/{id}/kirim-email` | Unduh sertifikat, riwayat surel, catat WhatsApp, penerbitan |

**Tidak pernah bisa dimatikan** (server menolak dengan `422`): pengisian lembar, perhitungan, persetujuan,
penerbitan sertifikat. Itu inti lab, dan saklar ini tidak menyentuhnya.

Satu saklar = satu lab (organisasi). Super Admin hanya mengubah lab sendiri; tidak ada parameter organisasi.

## 1. Menyembunyikan tombol — `GET /api/me/permissions` (semua peran)

Field **baru dan aditif** di `data` (field lama tidak berubah):

```json
{
  "data": {
    "role": "teknisi",
    "boleh": ["..."],
    "batasan": { "...": "..." },
    "fitur_nonaktif": ["pindai_lembar"]
  }
}
```

- Daftar kode fitur yang **sedang mati** untuk lab pengguna (urut seperti tabel di atas). `[]` = semua nyala.
- Kode yang tidak dikenal aplikasi diabaikan. Field tidak ada (server lama) = anggap `[]`.
- Aturan UI: `pindai_lembar` mati -> sembunyikan/nonaktifkan tombol "Pindai" dan arahkan ke isi manual (jalur
  Mulai kalibrasi tetap ada). `kirim_email_sertifikat` mati -> sembunyikan "Kirim surel".
- Ambil ulang saat aplikasi dibuka kembali dan sesudah `503 fitur_dimatikan` (keadaan bisa berubah kapan saja).

## 2. Menangani `503 fitur_dimatikan`

Tombol yang terlanjur ditekan (data basi) dijawab:

```http
HTTP/1.1 503
{
  "kode": "fitur_dimatikan",
  "fitur": "pindai_lembar",
  "message": "Fitur \"Pindai lembar kerja\" sedang dimatikan oleh Super Admin. Hubungi Super Admin lab kalau fitur ini dibutuhkan."
}
```

- Cocokkan `kode` (stabil), bukan kalimat `message`. Tampilkan sebagai pesan biasa, **bukan** galat server
  dan bukan layar "server bermasalah"; perbarui `fitur_nonaktif` di state.
- Urutan gerbang: peran dulu (peran tanpa hak tetap `403`, tidak pernah `503`), lalu saklar, lalu data
  (id yang tidak ada juga `503` saat fitur mati — jangan menyimpulkan keberadaan data dari status).
- Status `503` lain (`belum_tersedia` modul pelanggan, dll.) berbeda `kode`-nya.

## 3. Layar pengaturan (Super Admin saja)

Izin: `fitur.atur` di `GET /me/permissions` (hanya `super_admin`). Admin/teknisi/viewer: `403`.

### `GET /api/organization/fitur`

```json
{
  "data": {
    "fitur": [
      { "kode": "pindai_lembar", "label": "Pindai lembar kerja", "deskripsi": "...", "aktif": true },
      { "kode": "kirim_email_sertifikat", "label": "Kirim sertifikat lewat surel", "deskripsi": "...", "aktif": true }
    ],
    "fitur_nonaktif": []
  }
}
```

Render satu sakelar per baris `fitur` (pakai `label` dan `deskripsi` dari server; daftar bisa bertambah tanpa
rilis aplikasi).

### `PUT /api/organization/fitur`

Menetapkan **seluruh** daftar fitur yang mati (menggantikan, bukan menambah):

```json
{ "fitur_nonaktif": ["pindai_lembar"], "alasan": "OCR salah baca terus sejak kemarin" }
```

- `alasan`: wajib, **5-400 karakter** (dipangkas spasi), dicatat di audit.
- `fitur_nonaktif`: wajib dikirim, boleh `[]` (= nyalakan semua), tanpa duplikat, tiap isi harus kode dari
  `GET`. Kirim daftar lengkap hasil sakelar yang diubah pengguna.
- Sukses `200`:

```json
{
  "message": "Pengaturan fitur disimpan.",
  "perubahan": { "dimatikan": ["pindai_lembar"], "dinyalakan": [] },
  "data": { "fitur": [ "..." ], "fitur_nonaktif": ["pindai_lembar"] }
}
```

  Tidak ada yang berubah: `200`, `message` "Tidak ada perubahan.", `perubahan` kosong, tidak ada jejak audit.
- `422` (bentuk validasi biasa `{message, errors}`): `alasan` kosong/<5/>400; kode tidak dikenal; fitur inti
  (kalimat menyebut "inti"); bentuk salah (bukan larik, bukan string, duplikat). Tampilkan `errors` per field.
- `429`: throttle `fitur-saklar`, 20/menit per akun (hanya `PUT`).
- Konfirmasi di UI sebelum mematikan (efeknya seluruh lab), dan tampilkan siapa/kapan dari riwayat perubahan
  (`GET /audit-logs`, aksi `fitur_dimatikan` / `fitur_dinyalakan`, `new_data.alasan`).

## 4. Yang tidak berubah / jangan dilakukan

- `PUT /organization` (Master Data) **tidak** bisa menyetel atau menghapus saklar ini (kunci tidak ada di
  daftar pengaturannya -> `422`). Jangan menaruh sakelar fitur di layar Pengaturan Organisasi.
- Tidak ada saklar untuk fitur inti dan tidak ada saklar untuk lab lain.
- Layar ini bukan kontrol akses. Peran tetap ditegakkan server.

Kontrak ringkas: `docs/kontrak-api.md` bagian "Operasional: kesehatan dan saklar fitur". Dijaga
`tests/Feature/SaklarFiturTest.php`.
