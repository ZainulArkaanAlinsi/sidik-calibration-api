# 04 — UI/UX Flow: SIDIK Pelanggan

> Versi 0.1 · 16 Sep 2026 · Turunan dari `02-SRS.md` · Desain visual dikerjakan di Google Stitch dengan design system **Precision Clean** (teal-navy `#0E5C68`), sama dengan app internal supaya terasa satu keluarga.

## 1. Prinsip

1. **Status dulu, detail belakangan.** PIC membuka aplikasi untuk satu pertanyaan: "ada yang perlu saya urus?". Beranda menjawab itu dalam satu layar.
2. **Setiap layar punya empat keadaan:** memuat (skeleton, bukan spinner kosong), kosong (dengan ajakan tindakan), gagal (pesan + coba lagi), offline (data cache + penanda waktu sinkron).
3. **Bahasa pelanggan, bukan bahasa lab.** Tidak ada istilah `menunggu_approval`, `nama_alat_kemampuan`, atau budget ketidakpastian.
4. **Tindakan yang tidak bisa dibatalkan selalu dikonfirmasi** dan menyebutkan akibatnya (batalkan permintaan, nonaktifkan anggota, hapus akun).
5. **Tidak ada jalan buntu.** Setiap status yang tidak bisa diubah pelanggan punya tombol "Tanya lab".

## 2. Peta navigasi

```
Belum login                         Sudah login (navigasi bawah, 4 tab)
───────────                         ───────────────────────────────────
Sambutan                            [Beranda] [Alat] [Permintaan] [Akun]
 ├─ Masuk                              │        │        │           │
 │   └─ Lupa sandi → OTP → sandi baru  │        │        │           ├─ Profil saya
 ├─ Punya kode undangan                │        │        │           ├─ Perusahaan (ganti)
 │   └─ Kode → buat sandi → masuk      │        │        │           ├─ Anggota tim
 └─ Daftar perusahaan baru             │        │        │           ├─ Preferensi notifikasi
     ├─ Akun → Perusahaan → OTP        │        │        │           ├─ Ubah sandi
     └─ Menunggu verifikasi            │        │        │           ├─ Bantuan & kontak
                                       │        │        │           ├─ Privasi & ketentuan
Global (bisa muncul kapan saja):       │        │        │           ├─ Hapus akun
 · Wajib update                        │        │        │           └─ Keluar
 · Maintenance                         │        │        └─ Detail permintaan
 · Sesi berakhir → Masuk               │        │            ├─ Tab Status (timeline)
 · Lonceng notifikasi (app bar)        │        │            ├─ Tab Alat (per item)
                                       │        │            └─ Tab Pesan
                                       │        ├─ Detail alat
                                       │        │   ├─ Riwayat sertifikat → Detail sertifikat → PDF
                                       │        │   └─ Ajukan kalibrasi (alat ini terpilih)
                                       │        └─ Tambah / ubah alat
                                       └─ Kartu "Perlu tindakan" → tujuan masing-masing
                                    FAB "Ajukan kalibrasi" di tab Alat & Permintaan
```

## 3. Alur utama

### Flow A — Pelanggan lama diundang

```
Admin buat undangan ─► email berisi kode + tautan Play Store
Pelanggan pasang app ─► Sambutan ─► "Punya kode undangan"
 ─► isi email + kode ─► (valid) isi nama, HP, sandi, centang privasi ─► Beranda
                     └► (salah/kedaluwarsa) pesan jelas + "Minta kode baru ke PT Sidik" (buka WA/email lab)
Beranda langsung berisi alat lama perusahaan (data sudah ada di lab)
```

### Flow B — Perusahaan baru daftar sendiri

```
Sambutan ─► Daftar
 Langkah 1 Akun: nama, email, HP, sandi, jabatan
 Langkah 2 Perusahaan: nama PT, alamat, (opsional) kota
 Langkah 3 OTP email (kirim ulang setelah 60 dtk)
 ─► Layar "Menunggu verifikasi"
      isi: "Tim PT Sidik sedang memeriksa data perusahaan Anda. Biasanya ≤ 1 hari kerja."
      tombol: Hubungi PT Sidik · Keluar
 ─► Push "Akun disetujui" ─► buka app ─► Beranda (kosong → ajakan "Tambah alat pertama")
 ─► atau Push "Pengajuan ditolak" ─► layar alasan + kontak
```

### Flow C — Tambah alat

```
Tab Alat ─► (+) ─► Form: nama alat*, merk, model, no. seri, no. identifikasi internal,
                         range min/max + satuan, resolusi, lokasi, catatan, foto nameplate (≤3)
 ─► Simpan
     ├─ no. seri kembar ─► sheet "Alat dengan nomor seri ini sudah ada" [Lihat alat itu] [Tetap simpan]
     └─ sukses ─► Detail alat, badge "Menunggu verifikasi lab"
                  (pertama kali) minta izin notifikasi dengan penjelasan manfaat
```

### Flow D — Ajukan kalibrasi

```
FAB / Detail alat ─► Langkah 1 Pilih alat (checklist, cari, alat tak bisa dipilih diberi alasan)
 ─► Langkah 2 Metode:  ( ) Teknisi datang ke lokasi   ( ) Kirim alat ke lab PT Sidik
 ─► Langkah 3a Onsite: alamat (bisa pakai alamat perusahaan), kontak di lokasi, rentang tanggal, catatan
     Langkah 3b Kirim: alamat lab + petunjuk pengemasan + (opsional) kurir & resi sekarang
 ─► Langkah 4 Tinjau ─► Kirim
     ├─ offline/timeout ─► draf tetap tersimpan, tombol "Kirim ulang" (client_request_id sama)
     └─ sukses ─► Detail permintaan, status "Diajukan", info target respon (K5)
```

### Flow E — Memantau & menyelesaikan

```
Push "Permintaan dikonfirmasi" ─► Detail permintaan
  Tab Alat: tiap alat → Diterima / Di luar ruang lingkup (catatan) / Ditolak (catatan)
Onsite:  Push "Jadwal teknisi: Sel 20 Okt, 09.00" ─► H-1 pengingat ─► status Dikerjakan
Kirim:   Isi resi ─► Push "Alat diterima di lab" (kondisi + foto) ─► Dikerjakan
Push "Sertifikat terbit" (per alat) ─► Detail sertifikat ─► Unduh PDF
Kirim:   Push "Alat dikirim balik, resi …" ─► [Alat sudah kami terima] ─► Selesai
```

### Flow F — Pengingat jadwal

```
07.00 WIB Push "2 alat mendekati jadwal kalibrasi ulang (30 hari lagi)"
 ─► Tab Alat terfilter "Mendekati jadwal" ─► centang ─► "Ajukan kalibrasi" (Flow D dengan alat terpilih)
```

### Flow G — PIC utama mengelola tim

```
Akun ─► Anggota tim ─► daftar (nama, peran, terakhir aktif)
  (+) Undang: email + peran ─► status "Undangan terkirim" (bisa dibatalkan)
  Tap anggota ─► Nonaktifkan ─► dialog "Dia tidak bisa lagi melihat data perusahaan. Sesi di semua HP-nya langsung berakhir."
```

## 4. Daftar layar & state

| # | Layar | Isi utama | Kosong | Gagal / offline | Catatan |
|---|---|---|---|---|---|
| S01 | Sambutan | Logo, 1 kalimat manfaat, 3 tombol | — | — | Tanpa carousel onboarding panjang |
| S02 | Masuk | Email, sandi, lupa sandi | — | "Email atau sandi salah" (tidak membedakan); terkunci 15 menit dengan hitung mundur | Tombol lihat sandi |
| S03 | Lupa sandi (3 langkah) | Email → OTP → sandi baru | — | OTP salah/kedaluwarsa | Semua sesi lain dicabut |
| S04 | Terima undangan | Email, kode, nama, HP, sandi, persetujuan | — | Kode salah / kedaluwarsa / sudah dipakai | |
| S05 | Daftar (3 langkah) | Stepper, simpan progres lokal | — | Email sudah terdaftar → tawarkan Masuk | Persetujuan privasi di langkah 1 |
| S06 | Menunggu verifikasi | Status, estimasi, kontak | — | — | Tarik untuk refresh |
| S07 | Beranda | Kartu: Lewat jadwal, Mendekati jadwal, Permintaan aktif, Perlu tindakan (resi belum diisi, pesan belum dibaca) | "Belum ada alat" + tombol Tambah | Cache + "Terakhir diperbarui 08.12" | Banner izin notifikasi kalau ditolak |
| S08 | Daftar alat | Cari, chip filter status, kartu alat (nama, SN, lokasi, badge, sisa hari) | Per filter: "Tidak ada alat yang mendekati jadwal 👍" | Cache | Paginasi infinite scroll |
| S09 | Detail alat | Identitas (ikon gembok kalau terkunci), status & jadwal, verifikasi lab, riwayat sertifikat, permintaan aktif, foto | Riwayat kosong: "Belum pernah dikalibrasi di PT Sidik" | Cache | Tombol "Minta koreksi ke lab" pada field terkunci |
| S10 | Form alat | Field REQ validasi, foto | — | Error per field (422); upload foto gagal bisa diulang per foto | Kamera/galeri, kompres di klien sebelum upload |
| S11 | Detail sertifikat | Nomor, tanggal kalibrasi, tanggal terbit, jadwal ulang, keputusan, status revisi, tombol Unduh / Buka / Verifikasi QR | — | Unduh gagal → coba lagi; offline → tombol nonaktif | Label "Digantikan oleh …" untuk revisi |
| S12 | Ajukan (4 langkah) | Stepper | "Belum ada alat yang bisa diajukan" + Tambah alat | Kirim gagal → draf aman | Tombol kembali tidak menghapus pilihan |
| S13 | Daftar permintaan | Tab Aktif/Selesai, kartu (nomor, metode, jumlah alat, status, tanggal) | "Belum ada permintaan" | Cache | |
| S14 | Detail permintaan | Header status + tindakan kontekstual, tab Status/Alat/Pesan | — | Cache (pesan tidak bisa dikirim offline) | Badge pesan belum dibaca |
| S15 | Pesan | Gelembung chat, lampiran, penanda "Tim PT Sidik · Sari" | "Belum ada pesan. Tanyakan apa pun soal permintaan ini." | Pesan gagal → ikon ulang per pesan | Nonaktif setelah REQ-PSN-01 |
| S16 | Notifikasi | Daftar per tanggal, tanda belum dibaca | "Belum ada notifikasi" | Cache | Tandai semua dibaca |
| S17 | Akun | Menu | — | — | Versi app + ID pengguna singkat (untuk dukungan) |
| S18 | Anggota tim | Daftar + undangan tertunda | — | — | Aksi hanya untuk pic_utama |
| S19 | Preferensi notifikasi | 4 sakelar + tautan ke setelan notifikasi HP | — | Simpan gagal → kembalikan sakelar | |
| S20 | Hapus akun | Penjelasan apa yang dihapus vs disimpan lab, input sandi, konfirmasi | — | Sandi salah | |
| S21 | Wajib update | Pesan + tombol ke Play Store | — | — | Tidak bisa ditutup |
| S22 | Maintenance | Pesan dari server + perkiraan selesai | — | — | Cek ulang otomatis tiap 60 dtk |
| S23 | Ganti perusahaan | Daftar keanggotaan | — | — | Hanya muncul kalau > 1 |

## 5. Label status untuk pelanggan

**Status permintaan**

| Status sistem | Label pelanggan | Warna | Keterangan singkat di layar |
|---|---|---|---|
| diajukan | Diajukan | abu | Menunggu ditinjau Tim PT Sidik |
| ditinjau | Sedang ditinjau | biru | Tim PT Sidik sedang memeriksa permintaan Anda |
| dikonfirmasi | Dikonfirmasi | biru | Permintaan diterima |
| terjadwal | Teknisi dijadwalkan | biru | {tanggal, jam} di {lokasi} |
| menunggu_alat | Menunggu alat tiba | kuning | Kirim alat ke alamat lab, lalu isi nomor resi |
| alat_diterima | Alat diterima di lab | biru | Diterima {tanggal}, kondisi {ringkas} |
| dikerjakan | Sedang dikalibrasi | biru | {n} dari {m} alat selesai |
| menunggu_pengiriman_balik | Siap dikirim balik | biru | Semua sertifikat terbit, alat segera dikirim |
| dikirim_balik | Dalam pengiriman | biru | {kurir} · {resi} |
| selesai | Selesai | hijau | |
| ditolak | Ditolak | merah | Alasan: … |
| dibatalkan | Dibatalkan | abu | Oleh {pihak}: alasan |

**Status alat**

| Sistem | Label | Warna |
|---|---|---|
| belum_pernah | Belum pernah dikalibrasi | abu |
| dalam_proses | Sedang diproses | biru |
| terkalibrasi | Terkalibrasi | hijau |
| mendekati_jadwal | Kalibrasi ulang dalam {n} hari | kuning |
| lewat_jadwal | Lewat jadwal {n} hari | merah |
| nonaktif | Tidak aktif | abu |

Warna tidak pernah jadi satu-satunya penanda: selalu disertai teks dan ikon.

## 6. Copy penting

| Situasi | Teks |
|---|---|
| 404 lintas perusahaan / data hilang | "Data ini tidak ditemukan atau Anda tidak punya akses." |
| Sesi berakhir | "Sesi Anda berakhir. Silakan masuk lagi. Draf Anda tetap tersimpan." |
| Server lambat (staging tidur) | "Server sedang bersiap, mohon tunggu sebentar…" (hanya di build staging) |
| Offline | "Anda sedang offline. Menampilkan data terakhir ({jam})." |
| Keputusan FAIL | "Tidak memenuhi toleransi. Alat ini berada di luar batas toleransi pada saat dikalibrasi. Lihat sertifikat untuk detail atau tanyakan ke Tim PT Sidik." |
| Di luar ruang lingkup | "Alat ini di luar ruang lingkup akreditasi PT Sidik. Tim kami akan menjelaskan pilihan yang tersedia." |
| Izin notifikasi ditolak | "Notifikasi mati. Anda bisa melewatkan pengingat jadwal kalibrasi ulang. [Nyalakan]" |
| Konfirmasi batal | "Batalkan permintaan PMT/2026/10/0012? Tim PT Sidik akan dikabari. Tindakan ini tidak bisa dibatalkan." |

## 7. Layar baru di app internal (admin)

| Layar | Isi | Catatan |
|---|---|---|
| Inbox permintaan | Tab: Belum diambil (badge) · Saya · Semua aktif · Arsip; kartu: perusahaan, metode, jumlah alat, umur permintaan, penangan | Umur > 4 jam kerja diberi warna peringatan |
| Detail permintaan (admin) | Tombol Ambil/Alihkan, tinjauan per item (dropdown hasil + catatan), Konfirmasi/Tolak, Jadwal, Terima alat (kondisi + foto), Kirim balik, tab Pesan, riwayat | Tombol menyesuaikan status |
| Pengajuan akun | Data pemohon, saran pelanggan mirip, Setujui (pilih/buat pelanggan) / Tolak | |
| Verifikasi alat pelanggan | Daftar alat `belum`, form pemetaan kategori + `nama_alat_kemampuan` + hasil | |
| Detail pelanggan (tambahan) | Anggota aktif, undangan, PIC admin, tombol Undang | |

## 8. Catatan untuk prompt Google Stitch

Minta Stitch menghasilkan: S07 Beranda, S08 Daftar alat, S09 Detail alat, S12 Ajukan langkah 2 & 4, S14 Detail permintaan tab Status, dan S15 Pesan. Masing-masing dalam state normal **dan** kosong. Sebutkan: Precision Clean, primer `#0E5C68`, Android, bahasa Indonesia, target pengguna PIC QA perusahaan, dan kepadatan informasi sedang (bukan dashboard padat).
