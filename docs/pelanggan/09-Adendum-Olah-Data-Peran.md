# Adendum Desain — Olah Data Ber-versi, Master Data, Super Admin

> Tambahan untuk paket `docs/pelanggan/` (00–08 + ADR-001). Ditulis 19 Sep 2026.
> Status: **usulan**. Tiga hal di §2 harus diputuskan sebelum Fase 0 dijalankan.

---

## 1. Apa yang berubah dari paket dokumen yang ada

Paket `docs/pelanggan/` sudah lengkap untuk modul pelanggan. Permintaan baru menambah empat hal yang **belum ada** di paket itu:

1. Rumus olah data bisa diubah tanpa deploy, per jenis alat, hanya oleh admin/super admin
2. Peran `admin` diganti nama jadi **Master Data**, dengan alur periksa → tandai kesalahan → koreksi → kembalikan ke teknisi
3. **Super Admin** sebagai peran baru dengan pandangan penuh dan wewenang penuh
4. Tracking gaya Shopee dari awal sampai sertifikat sampai ke HP pelanggan

Yang **sudah** terjawab di paket dan tidak perlu dirancang ulang: pengiriman sertifikat ke aplikasi pelanggan (F08, R-D05 — URL bertanda tangan 5 menit, bucket privat), notifikasi push (F13, REQ-NTF-10), dan pemisahan repo (ADR-001).

---

## 2. Tiga keputusan yang harus diambil sebelum koding

### 2.1 Rumus tidak boleh jadi editor bebas — tapi parameternya memang harus bisa diubah

Permintaannya masuk akal: rumus memang berubah. Master direvisi, standar direkalibrasi, pita CMC diperbarui. Sekarang setiap perubahan butuh deploy, dan itu rapuh.

Tapi kalau yang bisa diubah lewat UI adalah **rumusnya**, sistem ini kehilangan satu-satunya hal yang membuat angkanya bisa dipercaya: kemampuan diadu ke master dan ke lampiran akreditasi. Orang yang mengetik rumus di form tidak punya cara membuktikan hasilnya masih cocok dengan sertifikat yang sudah terbit. Di lab terakreditasi, metode perhitungan harus divalidasi sebelum dipakai (ISO/IEC 17025 klausul 7.2.1.5 dan 7.11) — dan "divalidasi" tidak bisa berarti "seseorang mengetik dan menekan simpan".

Sesi-sesi audit kemarin sudah menunjukkan kenapa ini bukan kekhawatiran teoretis: satu rujukan sel yang meleset di workbook (§14), satu pita CMC yang terbaca salah (0,0007 vs 0,00051) — dua-duanya lolos bertahun-tahun karena tidak ada yang mengadu ulang. Editor rumus tanpa penjaga akan menghasilkan kelas kesalahan yang sama, tapi lebih cepat dan lebih sering.

**Jalan keluarnya: pisahkan tiga lapis, yang bisa diubah cuma lapis pertama.**

| Lapis | Isi | Bisa diubah lewat UI? |
|---|---|---|
| **1. Parameter & tabel referensi** | Pita CMC, tabel koreksi standar, tabel MPE, daftar nominal gauge block, identitas & tanggal due standar, konstanta metode (α, π, g, κ, ρ_wt, err_bal), batas jumlah titik | **Ya** — lewat alur persetujuan di §3 |
| **2. Struktur perhitungan** | Rantai Cuckow, struktur budget ketidakpastian, ekspresi koefisien sensitivitas, urutan konversi satuan | **Tidak** — butuh perubahan kode + test rekonsiliasi master + review PR |
| **3. Bentuk lembar kerja** | Jumlah titik, label field, satuan yang tersedia, mana yang wajib | **Ya, terbatas** — dengan validasi bahwa perubahan tidak membuat perhitungan kehilangan input |

Hampir semua perubahan nyata yang pernah terjadi di lab ini ada di lapis 1. Rekalibrasi standar, pita CMC, tabel koreksi — semuanya parameter, bukan struktur. Jadi lapis 1 saja sudah menjawab sebagian besar kebutuhan "bisa diubah tanpa deploy".

### 2.2 Ganti nama peran: ubah labelnya, jangan ubah kuncinya

Mengganti nilai `users.role` dari `admin` jadi `master_data` menyentuh: middleware `role:admin,teknisi,viewer`, `MatriksIzin::PETA`, seluruh gerbang Fase 2, semua test yang sudah ada, dan paket `docs/pelanggan/` yang menulis `role:admin` di banyak tempat. Itu perubahan berisiko tinggi tanpa manfaat teknis apa pun.

**Usulan:** kunci peran di database dan kode tetap `admin`. Yang diganti hanya label tampilan di UI jadi "Master Data". Nol migrasi, nol perubahan perilaku, dan nama yang dilihat orang tetap sesuai keinginan.

### 2.3 Super Admin bisa segalanya — ini bagian yang perlu dibatasi

Permintaannya: super admin bisa melakukan semua yang dikerjakan teknisi dan Master Data, dan bisa mengirim ke pelanggan.

Untuk **melihat**, itu tidak masalah dan memang berguna. Untuk **bertindak**, ada satu kombinasi yang harus diblokir sistem: satu orang yang memasukkan data kalibrasi, lalu memeriksanya sendiri, lalu mengesahkan sertifikatnya sendiri. Tidak ada pemeriksaan sama sekali di situ, dan sertifikatnya tetap terbit membawa logo akreditasi.

Ini bukan tambahan dari saya — `00-BACA-DULU.md` sudah menandainya sebagai **K4** (pemisahan wewenang, diputuskan manajer teknis sebelum M4), dan `06-Risk-Register.md` sudah mencatatnya sebagai **R-E08** dengan level 🔴 kritis.

**Usulan konkret:**

- Super Admin boleh membaca semuanya, tanpa batas. Ini yang membuat fitur tracking berguna.
- Super Admin boleh bertindak atas nama peran lain, tapi setiap aksi tercatat sebagai "dilakukan oleh Super Admin", bukan menyamar jadi orang lain.
- **Sistem memblokir** satu `user_id` yang sama menjadi pengirim lembar kerja *dan* pengesah sertifikat pada sesi yang sama — termasuk untuk Super Admin.
- Kalau lab benar-benar butuh pengecualian (misal cuma satu orang yang hadir), itu harus jadi pengecualian yang tercatat dengan alasan, bukan sesuatu yang bisa terjadi diam-diam.

PT Sidik punya sekitar lima admin menurut persona P4, jadi pemisahan ini realistis, bukan menyulitkan.

---

## 3. Desain: olah data sebagai konfigurasi ber-versi

Sistem sudah punya pondasinya — tabel `formulas` dan `formula_versions` sudah ada, dan setiap `uncertainty_calculations` sudah menyimpan `formula_version_id`. Yang perlu ditambah adalah alur perubahannya.

### 3.1 Siklus hidup versi

```
draf ──► diajukan ──► disetujui ──► aktif ──► pensiun
 │                        │
 └──► dibuang             └──► ditolak (dengan alasan)
```

- **draf** — Master Data mengubah parameter, belum berlaku
- **diajukan** — dikirim untuk ditinjau, wajib menyertakan alasan dan rujukan sumber (nomor IK, halaman lampiran akreditasi, nomor sertifikat standar)
- **disetujui → aktif** — hanya Super Admin, dan hanya setelah simulasi di §3.2 ditinjau
- **pensiun** — digantikan versi lebih baru, tapi tetap tersimpan selamanya

### 3.2 Simulasi wajib sebelum aktif — ini penjaga utamanya

Sebelum sebuah versi bisa diaktifkan, sistem menjalankan versi baru itu terhadap **semua test vector master dan semua sesi yang sudah tersimpan**, lalu menampilkan:

- sesi mana saja yang angkanya berubah
- berubah berapa, di kolom mana
- berapa yang **angka cetaknya** ikut berubah setelah pembulatan

Kalau ada sertifikat yang sudah terbit ikut berubah angkanya, itu ditampilkan mencolok — karena artinya perubahan ini menyentuh dokumen yang sudah di tangan pelanggan, dan itu urusan ketidaksesuaian, bukan urusan konfigurasi.

Tanpa layar ini, fitur ubah-parameter berbahaya. Dengan layar ini, justru lebih aman daripada mengedit Excel — karena Excel tidak pernah memberi tahu apa yang ikut berubah.

### 3.3 Sertifikat lama tidak pernah ikut berubah

Setiap sertifikat menyimpan `formula_version_id` yang dipakai saat menghitung. Versi baru hanya berlaku untuk sesi baru. Sertifikat lama tetap bisa dihitung ulang persis seperti saat terbit, kapan pun, bertahun-tahun kemudian.

Ini syarat ketertelusuran, dan kebetulan juga yang membuat perubahan parameter jadi aman dilakukan.

### 3.4 Siapa boleh apa

| Aksi | Teknisi | Master Data | Super Admin |
|---|---|---|---|
| Lihat parameter aktif | ✓ | ✓ | ✓ |
| Buat draf perubahan | — | ✓ | ✓ |
| Ajukan untuk ditinjau | — | ✓ | ✓ |
| Lihat hasil simulasi | — | ✓ | ✓ |
| **Setujui & aktifkan** | — | — | ✓ |
| Ubah struktur perhitungan (lapis 2) | — | — | — (lewat PR kode) |

---

## 4. Desain: Master Data — periksa, tandai, koreksi, kembalikan

### 4.1 Yang sudah benar dari usulanmu

Instruksi "kalau ada yang salah jangan dihapus isinya, tapi yang salah dikasih tanda" itu tepat, dan kebetulan persis yang diminta ISO/IEC 17025 klausul 7.5.2: perubahan pada rekaman teknis harus dapat ditelusuri ke versi sebelumnya, dan **data asli maupun data hasil perubahan harus sama-sama disimpan**.

Jadi ini bukan sekadar preferensi UI — ini memang cara yang benar.

### 4.2 Dua hal berbeda yang sering tertukar

| | Menandai | Mengoreksi |
|---|---|---|
| Siapa | Master Data | Master Data |
| Efek ke nilai | **Tidak mengubah apa pun** | Nilai berubah |
| Yang dicatat | field mana, jenis kesalahan, catatan, siapa, kapan | nilai lama, nilai baru, alasan, siapa, kapan |
| Terlihat oleh | teknisi + Super Admin | teknisi + Super Admin |

Nilai asli teknisi **tidak pernah hilang** di kedua kasus. Kalau Master Data mengoreksi, yang tersimpan adalah dua-duanya, plus alasannya.

### 4.3 Tabel yang perlu ditambah

**`lembar_kerja_temuan`** — penanda kesalahan
```
id, calibration_session_id, jalur_field (mis. "titik.3.massa.2"),
jenis (salah_ketik | di_luar_rentang | tidak_konsisten | satuan_keliru | lainnya),
catatan, ditandai_oleh (users.id), ditandai_pada,
status (terbuka | diperbaiki | diabaikan), diselesaikan_pada
index(calibration_session_id, status)
```

**`lembar_kerja_revisi`** — jejak koreksi nilai
```
id, calibration_session_id, jalur_field,
nilai_lama, nilai_baru, alasan,
diubah_oleh (users.id), diubah_pada
index(calibration_session_id)
```

Keduanya additive, tidak menyentuh tabel yang ada.

### 4.4 Yang dilihat Master Data saat lembar kerja masuk

Siapa yang mengirim (nama teknisi), kapan, alat apa, nomor sesi, dan berapa kali sesi ini sudah dikembalikan sebelumnya. Yang terakhir itu penting — sesi yang bolak-balik tiga kali adalah tanda ada yang perlu dibereskan di luar sistem (pelatihan, alat rusak, lembar kerja yang membingungkan).

### 4.5 Saat dikembalikan ke teknisi

Teknisi menerima notifikasi, membuka sesi, dan **field yang bertanda muncul dengan penandanya** — bukan cuma pesan umum "ada yang salah". Nilai lamanya masih ada, jadi dia tahu persis apa yang dikomentari.

Teknisi memperbaiki → status temuan jadi `diperbaiki` → sesi dikirim ulang. Temuan lama tetap tersimpan sebagai riwayat.

---

## 5. Desain: Super Admin — tracking penuh

### 5.1 Alur status, dan kenapa tidak bisa lurus seperti Shopee

Shopee linear karena pesanan tidak pernah dikembalikan ke penjual untuk diperbaiki. Alur kalibrasi punya putaran:

```
Draf ─► Dikirim teknisi ─► Diperiksa Master Data ─┬─► Disetujui ─► Sertifikat terbit
  ▲                                                │                      │
  └──────── Diperbaiki ◄── Dikembalikan ◄──────────┘                      ▼
                                                          Dikirim ke pelanggan ─► Diunduh pelanggan
```

Tampilannya tetap bisa berbentuk garis waktu seperti contoh yang dikirim, dengan putaran ditampilkan sebagai simpul tambahan ("Dikembalikan 2×"). Justru angka putaran itu informasi paling berguna untuk Super Admin.

### 5.2 Isi tiap simpul

Waktu, siapa, dan apa yang terjadi. Untuk simpul "Dikembalikan": berapa temuan dan jenisnya. Untuk "Disetujui": siapa yang mengesahkan. Untuk "Diunduh pelanggan": kapan dan oleh anggota mana.

### 5.3 Sumber datanya sudah ada

`calibration_sessions.status`, `reviewed_at`, `reviewed_by`, `certificates.diterbitkan_pada`, plus trait `Diaudit` yang sudah dipakai di repo. Yang perlu ditambah cuma dua tabel di §4.3 dan satu catatan waktu unduh pelanggan.

Jadi tracking ini sebagian besar **menampilkan data yang sudah tersimpan**, bukan menambah pencatatan baru. Itu kabar baik untuk risiko.

---

## 6. Sertifikat sampai ke HP pelanggan

Sudah dirancang di paket pelanggan, tidak perlu desain baru:

- `F08` — riwayat sertifikat per alat, unduh PDF, tanda revisi, tautan verifikasi QR
- `R-D05` — URL bertanda tangan 5 menit, bucket privat, supaya tautan yang tersebar tidak bisa dibuka orang lain
- `BR-01`/`BR-02` + `IsolasiPerusahaanTest` — pelanggan A tidak bisa melihat sertifikat pelanggan B, dijawab 404 bukan 403
- `A06` — notifikasi "Sertifikat terbit" saat approve

Yang perlu ditambah cuma satu: **catatan waktu unduh**, supaya Super Admin tahu sertifikat sudah sampai. Tambahkan `diunduh_pada` dan `diunduh_oleh` (nullable) di sisi pelanggan, dan notifikasi ke Master Data saat unduhan pertama.

Kaitan alat ↔ sertifikat ↔ pelanggan sudah terjamin lewat `certificates → calibration_sessions → equipments → customers`, jadi sertifikat tidak bisa nyasar ke perusahaan lain selama isolasi di `KonteksPerusahaan` ditegakkan.

---

## 7. Notifikasi

Peristiwa yang memicu push:

| Peristiwa | Ke siapa |
|---|---|
| Lembar kerja dikirim teknisi | Master Data |
| Lembar kerja dikembalikan | Teknisi pengirim |
| Sesi disetujui | Super Admin |
| Sertifikat terbit | Pelanggan (anggota perusahaan itu) |
| Sertifikat diunduh pertama kali | Master Data + Super Admin |
| Versi parameter diajukan | Super Admin |

Isi push tetap minim sesuai `REQ-NTF-10` dan `R-D04` — tidak ada nama pelanggan, nomor sertifikat, atau angka hasil di badan notifikasi. Cukup "Ada lembar kerja baru menunggu diperiksa". Detailnya dibuka di dalam aplikasi setelah login.

Infrastrukturnya sudah ada: `device_tokens`, `PenerimaNotifikasi`, FCM.

---

## 8. Dampak ke rencana fase yang ada

Prompt M0–M1 di `08-Prompt-Claude-Code-M0-M1.md` masih berlaku, dengan tiga penyesuaian:

**Fase 0** — bagian "Modul Pelanggan — aturan keras" di `CLAUDE.md` ditambah dua butir:
- Struktur perhitungan (lapis 2 di §2.1) tidak boleh diubah lewat konfigurasi; hanya lewat PR dengan test rekonsiliasi master.
- Setiap sertifikat wajib menyimpan `formula_version_id`; versi yang sudah dipakai sertifikat terbit tidak boleh diubah atau dihapus.

**Fase 2** — gerbang `role:admin,teknisi,viewer` ditambah `super_admin`. Ini harus ikut sekarang, bukan nanti, karena menambah peran setelah gerbangnya jadi berarti menyentuh ulang seluruh rute.

**Fase baru (setelah Fase 6)** — parameter ber-versi (§3), alur periksa Master Data (§4), dan tracking Super Admin (§5). Ini pekerjaan modul internal, bukan modul pelanggan, jadi tidak menghalangi M1.

Urutan yang saya sarankan: selesaikan M0–M1 sesuai paket dulu, karena M0-06 (gerbang rute) menutup celah kerahasiaan yang sudah terbuka sekarang. Fitur Master Data dan tracking penting, tapi tidak ada yang bocor karena menundanya dua minggu.

---

## 9. Yang harus dibawa ke PT Sidik, bukan diputuskan sendiri

Tiga di antaranya sudah ada di `00-BACA-DULU.md`, tinggal ditagih:

| Kode | Pertanyaan | Kenapa mendesak sekarang |
|---|---|---|
| **K4** | Siapa boleh mengesahkan sertifikat vs siapa yang hanya melayani pelanggan | Menentukan apakah blokir di §2.3 diterima atau perlu bentuk lain |
| **K1** | Akun Google Play atas nama organisasi PT Sidik (butuh D-U-N-S) | Butuh waktu; aplikasi yang dirilis dari akun pribadi magang akan tersandera saat magang selesai |
| **K3** | Kebijakan interval kalibrasi ulang (klausul 7.8.4.3) | Menentukan istilah "berlaku sampai" di aplikasi pelanggan |
| **baru** | Siapa yang berhak menyetujui perubahan parameter olah data | §3.1 mengasumsikan Super Admin, tapi ini wewenang mutu, bukan wewenang sistem |

---

## 10. Dua hal yang tidak bisa menunggu

**U1 — verifikasi developer Android, batas 30 September 2026.** Sebelas hari dari sekarang. Aplikasi dari developer yang belum terverifikasi tidak bisa dipasang atau diperbarui lewat jalur normal di perangkat Android bersertifikat. Yang kena duluan bukan aplikasi pelanggan, tapi **aplikasi internal yang dipakai teknisi sekarang** — teknisi yang ganti HP atau perlu update bisa mentok di lokasi kerja.

**U2 — repo mobile masih bisa di-clone tanpa login.** Waktu saya coba di sesi ini, `sidik-calibration-mobile` berhasil di-clone tanpa autentikasi, sementara `sidik-calibration-api` sudah tertutup. Sebelum modul pelanggan hidup, repo mobile perlu diperiksa statusnya dan seluruh riwayat git-nya dipindai secret.
