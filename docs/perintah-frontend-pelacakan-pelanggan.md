# Kontrak frontend — pelacakan alat untuk pelanggan

Ditulis 13 Nov 2026 untuk **aplikasi Pelanggan** (`sidik-pelanggan-mobile`). PRD-nya
`docs/permintaan-user-7.md` §57; kontrak rute lengkap `docs/kontrak-api-pelanggan.md` §14.

Semuanya **additive**: tidak ada rute baru, tidak ada field yang dihapus atau diganti nama,
aplikasi yang sudah terpasang tetap jalan. Yang di bawah adalah yang BOLEH dan perlu
ditambahkan di sisi aplikasi.

---

## 1. Kunci tampil: alat dikenali dari apa?

Putusan pemilik (13 Nov 2026):

| Peran | Isi | Dipakai untuk |
|---|---|---|
| **Identitas utama** | `nama` + `merk` + `serial` (nomor seri) | judul kartu/baris alat di semua layar pelacakan |
| **Pengait kedua** | Order Number (`nomor` di paket, `nomor_order` di `sedang_dikerjakan`) | baris kecil di bawah identitas: "Order ORD/2026/11/0042" |
| **Kunci teknis** | `order_items.id` (= `id` item di `/paket`, `order_item_id` di `sedang_dikerjakan`) dengan `equipment_id` sebagai penghubung | navigasi dan pencocokan data di kode — **jangan tampilkan ke pengguna** |
| Bukan kunci | nama PT | jangan dipakai membedakan alat; pengguna sudah berada di satu perusahaan (header `X-Perusahaan-Id`) |

Dua alat bisa sama nama dan merknya (dua termometer yang sama). Yang membedakan nomor seri —
tampilkan selalu bila ada. Satu alat bisa muncul di beberapa order (kalibrasi ulang tahun
berikutnya), jadi **`equipment_id` bukan kunci unik daftar item paket**; pakai `id` item.

## 2. Field baru

### 2a. `equipment_id` di tiap item paket

`GET /paket` → `data[].alat[]`, `GET /paket/{id}` → `data.alat[]`, `GET /beranda` →
`data.paket_berjalan[].alat[]`.

```json
{ "id": 981, "equipment_id": 57, "nama": "Termometer Digital", "merk": "Fluke",
  "serial": "SN-A-0001", "tahap": "dikalibrasi", "tahap_label": "Sedang dikalibrasi",
  "diserahkan_kepada": null, "sertifikat_id": null }
```

`equipment_id` = `id` alat di `GET /alat/{id}`. Dipakai untuk membuka kartu alat dari baris
item paket, dan sebaliknya.

### 2b. `sedang_dikerjakan` di objek alat

`GET /alat` → `data[]`, `GET /alat/{id}` → `data`, `GET /beranda` → `data.perlu_perhatian[]`.

```json
"sedang_dikerjakan": {
  "paket_id": 120,
  "order_item_id": 981,
  "nomor_order": "ORD/2026/11/0042",
  "tahap": "menunggu_pemeriksaan",
  "tahap_label": "Hasil sedang diperiksa"
}
```

atau `null`. **Kuncinya selalu ada** di tiga rute di atas; `null` = alat ini tidak sedang berada
di paket yang berjalan. Aturan nilainya:

- hanya paket **perusahaan sendiri** yang **masih berjalan** (bukan `selesai`, bukan dibatalkan);
- alat yang sudah **`diserahkan`** → `null` (sudah di tangan pelanggan, bukan "sedang dikerjakan");
- alat di dua paket berjalan → paket **terbaru** (tanggal masuk, lalu id);
- `tahap` memakai kode yang sama dengan `/paket` (pengesahan sudah dilipat ke
  `menunggu_pemeriksaan`); **tampilkan `tahap_label`**, jangan menerjemahkan kode sendiri.

Penggunaan yang disarankan: lencana kecil di kartu alat ("Sedang dikalibrasi") yang bila diketuk
membuka `GET /paket/{paket_id}`. Alat dengan `sedang_dikerjakan: null` tidak menampilkan lencana.
Aplikasi lama yang tidak membaca kunci ini tidak terpengaruh.

## 3. Yang BOLEH dan TIDAK BOLEH ditampilkan

Boleh: `nama`, `merk`, `serial`, `tahap_label`, `nomor` / `nomor_order`, `tanggal_masuk`,
`tanggal_janji_selesai`, `terlambat`, `jumlah_alat`/`jumlah_selesai`, `garis_waktu[]`, dan —
pada tahap `diserahkan` — `diserahkan_kepada` (lihat catatan di bawah).

**Server tidak pernah mengirim, dan aplikasi tidak boleh meminta atau menebaknya:** nama
teknisi/pereview, kode teknisi, kode internal tahap (`menunggu_pengesahan`), nomor sesi,
kondisi terima, kelengkapan, catatan lab, status/peringatan hitung, PASS/FAIL sebelum
sertifikat terbit.

**Item beralat pelanggan lain tidak pernah muncul.** Bila sebuah paket pernah salah memuat alat
milik perusahaan lain, server menyembunyikan **itemnya** (paketnya tetap tampil), dan `jumlah_alat`,
`tahap`, dan `garis_waktu` paket dihitung dari item yang terlihat saja. Aplikasi tidak perlu
melakukan apa pun — jangan menghitung ulang jumlah dari sumber lain.

> **Perlu konfirmasi lab.** Nama penerima serah terima (`diserahkan_kepada`) **tetap tampil**
> di `/paket` seperti sekarang, sesuai putusan pemilik. Itu nama orang (kurir/petugas
> pelanggan) yang diketik lab di meja depan. Lab perlu mengonfirmasi apakah nama itu boleh
> terlihat oleh SEMUA anggota perusahaan (termasuk staf), atau hanya PIC utama. Selama belum
> dijawab, perilakunya tidak berubah; nama itu **tidak** dimasukkan ke notifikasi.

## 4. Notifikasi perubahan tahap

Server mengirim notifikasi ke **anggota aktif perusahaan pemilik paket** setiap kali tahap
yang DILIHAT pelanggan untuk satu alat berubah — tahap fisik di meja depan ("Siap diambil",
"Sudah diserahkan") dan tahap turunan dari pekerjaan lab ("Sedang dikalibrasi", "Hasil sedang
diperiksa", "Sedang dikerjakan ulang" — **kabar turunan bawaan MATI**, lihat di bawah). Muncul lewat `GET /notifikasi` dan push FCM seperti
notifikasi lain; tidak ada rute baru. **Push FCM ke HP nyata belum dibuktikan** — kode dites dengan
pengirim palsu (hanya perangkat aplikasi pelanggan milik perusahaan pemilik paket yang dituju), dan
aktif tidaknya FCM di server produksi bergantung pada kredensial `FCM_*` di environment-nya; tanpa
itu notifikasi tetap tampil di dalam aplikasi, tetapi tidak di bilah notifikasi HP saat aplikasi tertutup.

| Kolom | Nilai |
|---|---|
| `kategori` | `pelanggan.tahap_alat` |
| `judul` | "Status alat Anda diperbarui" |
| `isi` | `{nama} {merk} (No. seri {serial}): {tahap_label}. Order {nomor}.` — mis. "Termometer Digital Fluke (No. seri SN-A-0001): Siap diambil. Order ORD/2026/11/0042." |
| `tautan` | `{tipe: "pelanggan_paket", id: <paket_id>}` — **tipe baru** |
| Saklar | `tahap_alat` di `GET/PUT /preferensi-notifikasi`, **bawaan menyala** |

Yang perlu dikerjakan aplikasi:

1. **Petakan `pelanggan_paket` + id** → layar detail paket (`GET /paket/{id}`) di `lib/screens/tautan.dart`,
   dipakai bersama oleh ketukan baris kotak masuk dan ketukan push. Hari ini `layarUntukTautan`
   hanya mengenal `pelanggan_sertifikat` dan `pelanggan_alat`; tipe yang belum dikenal menghasilkan
   `null` dan **pemanggil yang memutuskan** — untuk push, jatuh ke kotak masuk; baris kotak masuk
   tidak membuka layar apa pun. Jadi tanpa pemetaan ini notifikasi tetap tampil dan terbaca utuh,
   tetapi diketuk tidak mendarat di paketnya. (Alternatif tanpa mengubah aplikasi: server dapat
   mengirim `pelanggan_alat` + `equipment_id` yang sudah terpetakan ke detail alat — keputusan
   pemilik; sekarang `pelanggan_paket` karena yang berubah adalah tahap di paket.)
2. **Layar preferensi**: tambahkan saklar `tahap_alat`, mis. "Perkembangan alat saya" —
   "Kabari saat alat saya diterima, sedang dikalibrasi, siap diambil, atau sudah diserahkan."
   Bila layar preferensi belum ada di aplikasi, saklar ini baru bisa dimatikan setelah layarnya
   dibuat; bawaannya menyala. `GET /preferensi-notifikasi` kini membawa lima kunci:
   `pengingat_jadwal`, `status_permintaan`, `pesan_lab`, `ringkasan_email_mingguan`, **`tahap_alat`**.
3. Tidak ada kategori ikon khusus yang diwajibkan; kotak masuk boleh memakai tampilan yang sama
   untuk semua baris (seperti kategori lain).

Perilaku yang perlu diketahui desainer/penguji:

- **Satu notifikasi per alat per perubahan.** Paket berisi 12 alat yang ditandai "Siap diambil"
  satu per satu menghasilkan 12 notifikasi (satu per alat), bukan satu per paket. Menggabungkannya
  adalah keputusan produk tersendiri.
- **Tahap "Sertifikat sudah terbit" tidak dikirim lewat kategori ini** — sudah ada notifikasi
  `pelanggan.sertifikat_terbit`. Jangan mengharapkan dua notifikasi untuk kejadian yang sama.
- **Tidak ada notifikasi untuk perpindahan yang tidak terlihat**: lab menyetujui sesi
  (pengesahan dilipat ke "Hasil sedang diperiksa"), sertifikat ditarik/direvisi (itu
  `pelanggan.sertifikat_direvisi`), order dibatalkan, atau item beralat perusahaan lain.
- Penanda ulang: peristiwa yang sama tidak dikirim dua kali ke orang yang sama; tahap yang
  sama pada peristiwa baru (mis. dikerjakan ulang lagi) tetap dikirim.
- **Kabar tahap TURUNAN ("Sedang dikalibrasi", "Hasil sedang diperiksa", "Sedang dikerjakan ulang") bawaan MATI**
  (sakelar server `KABAR_TAHAP_TURUNAN=false`) sampai aplikasi punya layar preferensi untuk saklar `tahap_alat`
  (poin 2 di atas) — satu kabar per alat per perpindahan terlalu ramai tanpa tombol matikan. Yang tetap menyala:
  kabar tahap FISIK ("Siap diambil", "Sudah diserahkan") dan "Sertifikat sudah terbit". Tampilan tahap di
  `/paket` dan `sedang_dikerjakan` tidak bergantung pada sakelar. Memberi tahu pemilik server begitu layar
  preferensi rilis, supaya sakelar dinyalakan.
- Tahap turunan hanya hidup untuk sesi kalibrasi yang tertaut ke item paket
  (`calibration_sessions.order_item_id`). Sejak 13 Nov 2026 server menautkan sesi BARU otomatis saat dibuat
  bila alat itu punya TEPAT SATU item order aktif (`docs/permintaan-user-7.md` §57 bagian F); bila ada dua atau
  lebih, tidak ditautkan (tidak menebak). Sesi yang SUDAH ada, atau yang lahir sebelum order dicatat, tidak
  ditautkan dan tetap tampil sebagai "Alat diterima lab" sampai tahap fisik dicatat.

## 5. Daftar periksa penguji

- [ ] Kartu alat di `/alat` menampilkan lencana tahap hanya bila `sedang_dikerjakan != null`.
- [ ] Ketuk lencana membuka paket yang benar (`paket_id`), baris item yang benar (`order_item_id`).
- [ ] Item paket dengan nama sama dan nomor seri berbeda terbaca sebagai dua alat berbeda.
- [ ] Akun PT A tidak pernah melihat nama/merk/seri alat PT B di `/paket`, `/paket/{id}`, `/beranda`.
- [ ] Matikan saklar `tahap_alat` → tandai alat "Siap diambil" dari aplikasi lab → tidak ada notifikasi.
- [ ] Nyalakan lagi → notifikasi datang, isinya memuat nama, merk, nomor seri, tahap, Order Number —
      dan tidak memuat nama teknisi, nama penerima, atau catatan.
- [ ] Ketuk notifikasi (push, aplikasi tertutup) membuka detail paket.
