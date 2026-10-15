# Perintah Frontend — Super admin semua aksi + Order Number wajib (K-49-14)

Dokumen ini berdiri sendiri. Tempel ke sesi kerja `sidik-calibration-mobile`.
Rincian keputusan: `docs/permintaan-user-7.md` §51. Kontrak: `docs/kontrak-api.md` §2
(`/me/permissions`) dan §5 (`approve`).

> **DIPERBARUI 15 Okt 2026 oleh revisi pemilik (K-50-01/02/04/07/13).** Baca
> `docs/perintah-frontend-revisi-1510.md` — yang berlaku. Di dokumen ini TIDAK berlaku lagi:
> tombol **Tolak** (dicabut), **sahkan** / `kalibrasi.sahkan` (pengesahan dicabut), dan kode temuan
> `penyetuju_mengoreksi_pembacaan` (admin/super admin yang mengoreksi kini boleh menyetujui).
> **Setujui** kini juga wajib `nomor_sertifikat`. Yang masih berlaku: super admin = admin +
> teknisi, Order Number wajib, pengisi lembar tidak boleh menyetujui lembarnya sendiri.

## Status server (15 Okt 2026, branch `feat/super-admin-semua-aksi`)

- **Super admin boleh SEMUA aksi tulis admin (Master Data) + teknisi** di organisasinya
  sendiri. Tidak ada endpoint baru — endpoint yang sama, gerbangnya yang dibuka.
- **Order Number (`nomor_order`) wajib sebelum Setujui.** `POST /calibrations/{id}/approve`
  menjawab `422 nomor_order_wajib` kalau kosong.
- Panel web `/admin` untuk super admin **tetap baca saja**. Aplikasi = satu tempat menulis.
- Belum ada migrasi; bentuk respons yang sudah ada tidak berubah.

## 1. Menu & tombol super admin = admin + teknisi

Jangan hardcode dari `role`. Ambil dari `GET /api/me/permissions` (sekali sesudah login):

- `boleh` super admin = semua izin admin + semua izin teknisi. (Hitungan lama
  "63/61/28/18" sudah bergeser sesudah izin tolak/sahkan dicabut dan `kalibrasi.bandingkan` +
  `sertifikat.tarik` ditambah — baca dari `/me/permissions`, jangan dari angka ini.)
- `batasan` super admin = `semua` (melihat semua sesi lab, bukan cuma miliknya).
- Yang praktis berubah di HP: `bisaInput` (isi lembar kerja, pindai, verifikasi pindai) harus
  `true` untuk super admin; menu Master Data (pelanggan, standar, ruangan, metode, teknisi,
  pengguna, organisasi, tanda tangan, impor, pengingat), kartu Setujui/Tolak, Data
  administrasi, retry/revisi/batalkan/kirim sertifikat, permintaan & koreksi pelanggan —
  semuanya ikut nyala untuk super admin.
- Sesi yang dibuat super admin tercatat dengan **`teknisi_id` = super admin itu** (dia yang
  mengisi). Bukan menyamar sebagai teknisi lain.

**`boleh` bukan jaminan sesi ini boleh diputus.** Lihat §3.

## 2. Order Number — siapa mengisi, kapan wajib

| Peran | Isi `nomor_order`? |
|---|---|
| admin (Master Data), super_admin | **ya** — `PATCH /api/calibrations/{id}/admin` `{ "nomor_order": "…" }`, atau ikut di body `POST`/`PUT` sesi |
| teknisi, viewer | **tidak** — kalau ikut terkirim di `POST`/`PUT`, server **membuangnya diam-diam** (tidak 422); `PATCH …/admin` → `403` |

- Detail sesi (`GET /api/calibrations/{id}`) dan respons simpan/approve/tolak membawa
  `nomor_order` untuk semua peran (sudah sejak lama). Tampilkan read-only untuk teknisi.
- `GET /api/calibrations/lembar-kerja` untuk super admin memulangkan **bentuk admin** (dengan
  bagian administratif), sama persis dengan admin.
- `PATCH …/admin` boleh selama sesi belum `disetujui` (`draft`, `perlu_revisi`,
  `menunggu_approval`, `menunggu_pengesahan`). Sesi `disetujui` → `422`.

### Setujui tanpa Order Number → `422`

```json
{
  "message": "Order Number belum diisi. Master Data (atau super admin) wajib mengisinya di Data administrasi sebelum sesi disetujui — nomor itu tercetak di sertifikat.",
  "kode": "nomor_order_wajib",
  "errors": { "nomor_order": ["…pesan yang sama…"] }
}
```

- Status sesi **tidak berubah** (tetap `menunggu_approval`).
- Cabangkan dari **`kode`**, bukan dari teks `message`.
- Saran UI: kartu "Data administrasi" dengan kotak Order Number di layar periksa; tombol
  Setujui nonaktif (atau menyorot kotak itu) selama kosong. Tetap tangani `422` dari server —
  validasi HP itu kenyamanan, penjagaannya di server.
- Spasi saja dianggap kosong.

## 3. Pemisahan wewenang tetap berlaku untuk super admin (K-30-03)

Super admin yang **mengisi** lembar (membuat sesinya), **mengoreksi** angka pembacaan
(`PUT` lembar `menunggu_approval` dengan angka berubah + `alasan_koreksi`), atau
**mengonfirmasi hasil pindai** (`measurements/verify`) **tidak bisa** menyetujui maupun
mengesahkan sesi itu:

```json
{ "kode": "pemisahan_wewenang", "wewenang": { "temuan": [{ "kode": "penyetuju_sama_dengan_teknisi", "pesan": "…" }] } }
```

- Kode temuan approve: `penyetuju_sama_dengan_teknisi`, `penyetuju_mengoreksi_pembacaan`,
  `penyetuju_mengonfirmasi_pindai`. Sahkan: `pengesah_*` (termasuk
  `pengesah_sama_dengan_pemeriksa` kalau dia juga yang menyetujui).
- `abaikan_peringatan` **tidak** menembusnya. Tampilkan `wewenang.temuan[].pesan`; jangan
  tawarkan "lanjutkan". Orang lain (admin atau super admin lain) yang menyetujui/mengesahkan.
- Urutan `422` di approve: status → hasil pindai belum dikonfirmasi → `pemisahan_wewenang` →
  `nomor_order_wajib` → galat validasi body → temuan validator (`butuh_konfirmasi`).

## 4. Edit lembar teknisi oleh Master Data / super admin

- Pakai form lembar kerja yang sama (`PUT /api/calibrations/{id}` atau
  `PUT /api/calibrations/autoclave/{id}`), boleh untuk `draft`, `perlu_revisi`, dan
  `menunggu_approval`.
- Kalau angka pembacaan lembar `menunggu_approval` berubah, **`alasan_koreksi` wajib**
  (10–500 karakter) → tanpa itu `422 errors.alasan_koreksi`. Server menyimpan nilai lama + baru
  + alasan di jejak audit, atas nama yang mengoreksi.
- Sesudah mengoreksi, orang itu tidak bisa menyetujui sesi tersebut (§3).
- Sesi `disetujui`: tidak bisa disunting. Perubahan sesudah terbit lewat
  `POST /api/certificates/{id}/revisi` (sekarang juga terbuka untuk super admin).

## 5. Yang tetap tertutup

| Aksi | Jawaban |
|---|---|
| Sesi / data lab lain | `404` (tulis lintas organisasi tidak dibuka) |
| Rute aplikasi pelanggan `/api/pelanggan/v1/*` dengan token internal | `403 bukan_aplikasi_ini` |
| Mengubah akun super admin lain lewat `/api/users/{id}` | `404` |
| Memberi role `super_admin` lewat `PUT /users/{id}` atau `POST /users/{id}/approve` | `422` |

## Error code ringkas

| `kode` | Status | Arti |
|---|---|---|
| `nomor_order_wajib` | 422 | Order Number kosong saat Setujui. Isi lewat `PATCH …/admin`, lalu setujui lagi. |
| `pemisahan_wewenang` | 422 | Penyetuju/pengesah ikut mengisi/mengoreksi/mengonfirmasi pindai sesi ini. Minta orang lain. |
| `bukan_aplikasi_ini` | 403 | Token internal dipakai ke rute pelanggan (atau sebaliknya). |
