# Handoff Frontend — perubahan API 29 Juli 2026

Lanjutan dari [`HANDOFF-FRONTEND-28-Jul.md`](HANDOFF-FRONTEND-28-Jul.md). Isinya cuma
yang **kelihatan dari sisi API** — perubahan backend yang murni internal (perbaikan
migrasi, penataan service, penyedia AI) sengaja nggak dibahas di sini.

Semua bentuk request/respons di dokumen ini dicek langsung ke controller, resource,
& form request-nya, bukan disalin dari catatan. Kontrak detail per endpoint tetap di
[`kontrak-api.md`](kontrak-api.md) — dokumen ini peta, bukan pengganti.

Rincian teknis & alasan tiap perubahan ada di sisi backend:
[`HANDOFF-BACKEND-29-Jul-adendum.md`](HANDOFF-BACKEND-29-Jul-adendum.md).

---

## ⚠️ 1. Baca ini duluan — yang bisa ngerusak layar lama

### 1a. Lembar kerja pH dirombak ngikut kertasnya

`GET /calibrations/lembar-kerja` berubah cukup dalam. **Layar yang nge-render
formulirnya dari respons ini ikut berubah sendiri.** Yang perlu disentuh cuma layar
yang nge-hardcode urutan bagian atau daftar fieldnya.

**Urutan bagian sekarang:**

```
EQUIPMENT IDENTITY AND CUSTOMER DATA
OWNER
STANDARD                  <- naik, dulu di bawah CALIBRATION DATA
CALIBRATION DATA
CALIBRATION RESULT        <- Env. Condition pindah ke sini
Catatan & Tanda Tangan
```

**Tiap bagian sekarang bawa `halaman`** (`1` atau `2`), ngikut lembar kerja fisiknya
yang emang dua halaman. Dipakai buat misahin formulir jadi dua langkah.

**Lima field pindah dari otomatis jadi diketik teknisi.** Dulu `sumber: otomatis`
(salinan master), sekarang kolom isian beneran:

| Field | Kolom di lembar kerja |
|---|---|
| `alat_model` | 3. Type/Model |
| `alat_serial_number` | 4. Serial Number |
| `alat_merk` | 5. Merk |
| `pemilik_nama` | OWNER 1. Name |
| `pemilik_alamat` | OWNER 2. Address |

Alasannya: master itu punya admin dan sering beda sama unit fisik yang beneran
datang. Yang megang alatnya teknisi. Kelimanya **opsional** — lembar kerja boleh
dikirim belum lengkap.

Dua perubahan hak akses di bagian yang sama:

- **Thermohygro used** bukan `hanya_admin` lagi — sekarang hak teknisi, pilihannya
  berkelompok Insitu / Inlab.
- **Tabel STANDARD** nggak lagi ngirim seluruh katalog master. Sekarang 5 baris
  tercetak yang dicocokkan ke master.

### 1b. `GET /dashboard/tren` tanpa penyaring: 5 → 6 titik

Bawaannya emang selalu 6 bulan; yang keluar 5 itu bug tanggal yang cuma kambuh di
tanggal 29–31. Sekarang selalu 6, dan angkanya **sama persis** dengan
`grafik_pekerjaan` di `/dashboard` buat bulan yang sama (dulu bisa beda).

Bentuk responsnya nggak berubah sama sekali — nama field, tipe, struktur identik.
Yang perlu dicek cuma layout atau snapshot test yang diitung buat 5 batang.

> Kalau pernah ada laporan *"grafik tren cuma 5 bulan padahal Dashboard 6"* — itu ini,
> dan udah beres. Bukan bug rendering di mobile.

---

## ✅ 2. Baru — boleh langsung didesain & dibangun

### 2a. Kirim sertifikat: tiga format

`POST /certificates/{id}/kirim-email` sekarang nerima `format`:

| `format` | Yang dikirim |
|---|---|
| `pdf` *(bawaan)* | PDF dilampirkan |
| `xlsx` | Lembar kerja Excel dilampirkan, dirakit on-the-fly |
| `tautan` | **Tanpa lampiran** — badan emailnya ganti kalimat, jadi nggak ada kata "terlampir" di email yang nggak punya lampiran |

Syarat berkasnya dicek per format, dan pesan gagalnya spesifik (PDF belum jadi vs
snapshot belum ada vs belum punya `qr_token`) — jadi pesan errornya bisa ditampilin
apa adanya ke admin.

`format` juga ikut di tiap baris `GET /certificates/{id}/riwayat-email`.

### 2b. Kirim lewat WhatsApp — server NGGAK ngirim apa-apa

`POST /certificates/{id}/catat-whatsapp`

```jsonc
// request
{
  "ke": ["+6281234567890"],        // wajib, 1-10 nomor, string apa adanya
  "format": "tautan"               // pdf | xlsx | tautan (bawaan: tautan)
}

// respons
{
  "message": "Pengiriman lewat WhatsApp ke +6281234567890 tercatat.",
  "pesan": "...",                  // teks siap-tempel, PAKAI INI
  "data": { "id": 1, "ke": [...], "format": "whatsapp", "status": "terkirim", ... }
}
```

Pesannya dikirim dari HP admin lewat `wa.me`; endpoint ini cuma nyatet jejaknya.

> **Penting: jangan nyusun tautannya sendiri di mobile.** Pakai field `pesan` apa
> adanya. Tautannya nempel ke `qr_token` dan skema URL yang cuma backend yang tahu —
> kalau mobile nyusun sendiri, satu perubahan rute bikin pelanggan nerima tautan
> mati, dan itu ketahuannya sesudah pesannya kekirim.

Di riwayat, formatnya dicatat `whatsapp` (bukan pdf/xlsx/tautan): yang perlu bisa
dijawab waktu pelanggan ngaku nggak nerima itu **"lewat mana"**, dan itu yang paling
ngebedain. Isi persisnya nempel di `pesan`.

### 2c. Nolak lembar kerja: catatan + kode kolom

`POST /calibrations/{id}/reject` sekarang nerima `revisi_field` di samping
`catatan_revisi` yang udah ada:

```jsonc
{
  "catatan_revisi": "Serial number nggak kebaca, env condition juga kosong.",
  "revisi_field": ["alat_serial_number", "suhu_awal"]   // opsional, maks 40
}
```

Dipakai buat nyorot kolom yang salah waktu sesi dibuka lagi sama teknisi.
`catatan_revisi` tetap **wajib** (min 5 karakter) — kode doang kehilangan alasannya,
dan "kenapa" itu yang bikin teknisi nggak ngulang kesalahan yang sama minggu depan.

`revisi_field` **sengaja nggak divalidasi terhadap daftar kolom yang ada**. Efek
terburuk dari kode yang nggak dikenal cuma: nggak ada kolom yang kesorot, prosanya
tetap kebaca. Dibalikin lagi lewat `CalibrationResource` (`[]` kalau kosong).

### 2d. Halaman verifikasi QR jadi mirip lembar sertifikat

`GET /verify/{qr_token}` sekarang nge-render lembar sertifikat utuh, bukan kartu
ringkas. Alasannya: orang yang nyecan QR lagi **mencocokkan** lembar di tangannya —
kalau bentuknya beda, nggak ada yang bisa dicocokin.

- Sertifikat lama yang `snapshot`-nya kosong tetap pakai kartu ringkas.
- Responsif: di layar sempit tabelnya bisa digeser, header infonya dilipat satu kolom.
- **Nggak nambah data yang kebuka** — tombol unduh PDF di halaman itu emang udah
  tanpa auth dari dulu (spesifikasi poin 13).

---

## 🔄 3. Berubah tapi aman — superset, layar lama tetap jalan

Semua di bawah ini **nambah** field, nggak ngubah atau ngilangin. Layar lama nggak
perlu disentuh; ini bahan buat layar baru.

| Endpoint / resource | Tambahan |
|---|---|
| `CalibrationResource` | 5 field identitas alat & pemilik (lihat §1a) |
| `CalibrationResource` | `pelanggan: { id, nama }` — buat ngelompokkin antrean approval per PT |
| `CalibrationResource` | `revisi_field: []` |
| `CertificateResource` | `pelanggan: { nama, email, telepon }` — biar layar bisa nampilin tombol yang tepat tanpa nebak nomor |
| `riwayat-email` | `format` per baris |

**Catatan penting soal `pelanggan.nama`:** di dua-duanya, `pemilik_nama` isian teknisi
**menang** atas nama master. Biar nama yang dilihat admin di antrean sama dengan yang
bakal kecetak di sertifikat — kalau beda, admin mikir itu dua PT.

Kelima field identitas itu juga dibalikin lagi di respons, bukan cuma diterima. Itu
disengaja: waktu sesi dikembalikan buat revisi, mobile ngisi ulang formulirnya dari
sini. Tanpa itu teknisi ngetik ulang semuanya cuma buat mbenerin satu hal.

### 3a. Approve & retry sekarang langsung, bukan lewat antrean

Dua-duanya nerbitin sertifikat **sinkron** (~1-2 detik), jadi responsnya udah bawa
hasil akhir:

- `POST /calibrations/{id}/approve` → responsnya udah bawa `sertifikat` yang terbit
- `POST /certificates/{id}/retry` → `data.status` udah `terbit` atau `gagal`,
  nggak pernah `menunggu_generate` lagi

**Polling sesudah approve/retry jadi nggak perlu.** Yang udah ada nggak rusak — cuma
mubazir. Kalau mau disederhanain, baca aja status dari respons itu langsung.

> Ini juga nutup satu jalan buntu: dulu retry tanpa pekerja antrean bikin status
> nyangkut di `menunggu_generate` selamanya **dan tombol retry-nya ikut ilang**.
> Sekarang statusnya selalu mendarat di keadaan akhir, jadi yang gagal tetap nawarin
> retry.

### 3b. QR nggak dicetak lagi di PDF

Diatur lewat setelan organisasi `tampilkan_qr_di_pdf`, bukan dihapus dari kode.
`qr_token` & `qr_payload` **tetap ada** di API — layar sertifikat di mobile yang
nge-render QR-nya sendiri nggak kena apa-apa.

---

## 🚧 4. Belum ada — jangan dibangun dulu

- **Master data Ruangan.** Tabel `rooms` masih kosong dan belum ada seeder-nya. Sesi
  lab jalan pakai `lokasi=lab` tanpa `room_id`. Belum nahan apa-apa, tapi "Calibration
  Location" di sertifikat bakal kosong sampai ini ada.
- **Alat & berkas lain milik PT masuk ke folder PT-nya.** Sekarang baru sertifikat
  yang otomatis ketaut ke Folder Manager. Ini fitur baru, bukan perbaikan — bentuknya
  belum diputusin (subfolder per alat? berkas apa aja yang masuk?).

---

## Kalau ada yang kelihatan aneh

Rantai penuh pH → sertifikat bisa diuji ujung ke ujung dari sisi API:

```
python docs/skrip/e2e-ph.py http://127.0.0.1:8000/api sertifikat.pdf
```

Keluarnya `SEMUA MATA RANTAI TERSAMBUNG` atau `PUTUS DI: <langkah>` + sebab +
petunjuk. Berguna buat mastiin "ini bug mobile atau backend?" sebelum ngulik layar —
skripnya nembak API yang sama persis yang dipakai app.
