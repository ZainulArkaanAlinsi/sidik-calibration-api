# Perintah frontend: kelola ALAT dari Sidik Studio Web

Status: **API SELESAI (26 Nov 2026, cabang `feat/studio-alat`)**. Kontrak final untuk Sidik Studio Web.

## Yang berubah untuk pengguna

Studio (akun Super Admin) bisa melihat daftar alat, membuka detail, menambah, mengubah, dan menghapus
alat lab. Sebelumnya token Studio ditolak 403 `bukan_aplikasi_ini` di `/api/equipments` (rute itu milik
aplikasi lab dan tetap begitu, tidak dilonggarkan). Jalur ini rute BARU di grup Studio. Isi respons,
aturan validasi, penyaring `organization_id`, 404 lintas lab, dan jejak audit sama dengan jalur Lab,
karena controller dan `EquipmentRequest` yang sama dipakai ulang.

## Akses, header, dan batas

- Prefix `/api/studio/equipments`. Gerbang: `auth:sanctum` + `studio` (token Studio dari akun `STUDIO_AKUN`)
  + **`role:super_admin`**. Akun Studio yang berperan admin, teknisi, atau viewer mendapat 403 di rute ini,
  tetapi tetap bisa memakai rute Studio lain (`/api/studio/me`, workbook, dan seterusnya).
- Header wajib seperti rute Studio lain: `Authorization: Bearer <token studio>`, `Accept: application/json`,
  `X-Klien: sidik-studio`, `X-Versi-Klien: <versi build>`.
- Jatah: `GET` ikut ember `studio-baca` (300/menit per pengguna). `POST`, `PUT`, `PATCH`, `DELETE` memakai
  ember `studio-alat-tulis`: **60 per menit per pengguna**. Lewat batas: 429 dengan header `Retry-After`.

Galat akses:

| Status | `kode` | Arti |
|---|---|---|
| 401 | (tanpa) | Tanpa token. Akun nonaktif: `akun_nonaktif` |
| 403 | `bukan_token_studio` | Token bukan token Studio (mis. token lab) |
| 403 | `bukan_akun_studio` / `studio_tertutup` | Akun bukan `STUDIO_AKUN`, atau Studio tertutup |
| 403 | (pesan `Kamu nggak punya akses ke sini.`) | Akun Studio sah, tetapi perannya bukan `super_admin` |
| 404 | (tanpa) | Alat tidak ada, sudah dihapus, atau milik lab lain (tidak dibedakan) |

Peran dan akun diperiksa SEBELUM alat dicari: 403 sama untuk ID yang ada, tidak ada, atau milik lab lain.

## Endpoint

| Metode | URI | Hasil |
|---|---|---|
| GET | `/api/studio/equipments` | 200 `{data:[alat], links, meta}` (paginasi) |
| GET | `/api/studio/equipments/{id}` | 200 `{data: alat}` |
| POST | `/api/studio/equipments` | 201 `{data: alat}` |
| PUT atau PATCH | `/api/studio/equipments/{id}` | 200 `{data: alat}` |
| DELETE | `/api/studio/equipments/{id}` | 200 `{message: "Alat dihapus."}` |

Semua query dibatasi `organization_id` akun Studio. `organization_id` dari body diabaikan.

### Daftar

Query opsional (sama dengan `GET /api/equipments`): `search` (maks 100 huruf; serial persis duluan, lalu nama,
merek, nama PT), `category` (kode kategori), `status` (`aktif`, `nonaktif`, `overdue`), `customer_id`,
`profil` (kode lembar kerja; kode tak dikenal 422), `jatuh_tempo_dalam` (hari), `termasuk_lewat`, `urut`
(`terbaru` bawaan, atau `jatuh_tempo`), `per_page` (bawaan 15, maks 100), `page`.

### Tambah dan ubah

Body JSON. Pada POST kolom bertanda `*` wajib. Pada PUT dan PATCH semua kolom opsional (hanya yang dikirim
yang berubah; kolom yang tidak dikirim tidak disentuh, termasuk `resolusi_rentang`).

| Kolom | Aturan |
|---|---|
| `nama_alat` * | string, maks 255 |
| `serial_number` * | string, maks 100, unik per lab (alat yang sudah dihapus tetap memegang nomornya: 422) |
| `kategori` * | KODE kategori milik lab dan aktif (daftar: `GET /api/categories` di aplikasi lab). Alat lama boleh tetap di kategorinya walau nonaktif |
| `pelanggan_id` * | id PT pelanggan milik lab yang sama |
| `nama_alat_kemampuan` | nama jenis alat dari kemampuan kalibrasi kategori itu (opsional, boleh null) |
| `merk`, `model`, `no_identifikasi`, `satuan`, `lokasi` | string opsional |
| `range_min`, `range_max` | angka, `range_max` tidak boleh di bawah `range_min` (juga terhadap nilai tersimpan pada PATCH) |
| `resolusi` | angka lebih dari 0 |
| `resolusi_rentang` | daftar `{titik atau maks, resolusi > 0, satuan}`; `[]` mengosongkan |
| `toleransi` | angka 0 atau lebih |
| `tanggal_kalibrasi_terakhir`, `tanggal_jatuh_tempo` | tanggal; jatuh tempo tidak boleh sebelum kalibrasi terakhir |
| `status` | `aktif` atau `nonaktif` saja (`overdue` dihitung server dari jatuh tempo, mengirimnya 422) |
| `catatan` | string, maks 2000 |

Kegagalan validasi: 422 `{message, errors:{kolom:[pesan]}}`. Tampilkan pesan server apa adanya.

### Bentuk `alat` (respons)

`id`, `nama_alat`, `serial_number`, `kategori` (kode), `merk`, `pelanggan {id, nama, alamat}`,
`tanggal_kalibrasi_terakhir`, `tanggal_jatuh_tempo`, `status`, `model`, `no_identifikasi`, `range_min`,
`range_max`, `satuan`, `rentang_ukur`, `resolusi`, `resolusi_rentang`, `toleransi`, `lokasi`,
`nama_alat_kemampuan`. Sama dengan respons aplikasi lab.

## Hapus = hapus-lunak

`DELETE` mengisi `deleted_at`. Baris, riwayat kalibrasi, dan sertifikat lama tetap ada dan tetap bisa
ditelusuri. Alat yang sudah dihapus hilang dari daftar dan dijawab 404 di detail, ubah, dan hapus ulang.
Nomor serinya tetap dipegang alat itu, jadi menambah alat baru dengan serial yang sama dijawab 422.
**Belum ada endpoint pulihkan untuk alat.** Jangan menampilkan tombol "pulihkan".

## Jejak audit

Tiap tambah, ubah, dan hapus masuk `audit_logs` (`entity_type = equipments`, aksi `dibikin`, `diubah`,
`dihapus`, nilai lama dan baru untuk `diubah`, `changed_by` = id akun Studio, lab = lab akun itu).
Percobaan menyentuh alat lab lain: 404 dan baris `akses_lintas_lab` di lab PEMANGGIL.

Catatan: baris audit belum membedakan sumber (Studio atau aplikasi Lab) dan tidak punya kolom alasan.
Sama dengan jalur Lab hari ini.

## Efek ke aplikasi HP

Alat yang dibuat atau diubah dari Studio ada di tabel yang sama dengan yang dibaca aplikasi lab, jadi HP
melihatnya tanpa rilis baru. HP TIDAK didorong: alat tidak menyiarkan sinyal realtime. HP mengambil
`GET /api/equipments` (15 per halaman) saat tab Alat dibuka pertama kali sesudah masuk, saat tarik-untuk-
segarkan, saat mencari atau memfilter, dan saat memilih alat di lembar kerja. Tidak ada polling berkala.
Teknisi bisa melihat alat baru setelah menarik untuk menyegarkan atau masuk ulang.
