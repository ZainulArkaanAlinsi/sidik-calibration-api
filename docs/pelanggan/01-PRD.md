# 01 — PRD: SIDIK Pelanggan

> Versi 0.1 · 16 Sep 2026 · Dokumen berikutnya: `02-SRS.md`

## 1. Latar belakang

PT Sidik (lab kalibrasi terakreditasi SNI ISO/IEC 17025:2017, LK-285-IDN) sudah punya sistem internal CertiCal untuk admin dan teknisi: input data kalibrasi, perhitungan ketidakpastian, approval, dan sertifikat digital. Sampai sekarang, pelanggan tidak punya jalur digital ke lab. Pengajuan, jadwal, dan pertanyaan status lewat telepon atau WhatsApp. Sertifikat dikirim manual, dan pengingat kalibrasi ulang tergantung ingatan pelanggan.

SIDIK Pelanggan adalah aplikasi Android yang dipasang pelanggan (umumnya perusahaan besar dan PT) untuk menutup celah itu. Datanya memakai backend yang sama dengan aplikasi internal.

## 2. Tujuan (Goals)

| ID | Tujuan | Ukuran berhasil |
|---|---|---|
| G1 | Pelanggan tahu status semua alatnya tanpa menghubungi lab | ≥ 80% pertanyaan status dari pelanggan pilot beralih ke aplikasi dalam 2 bulan |
| G2 | Tidak ada alat pelanggan yang lewat jadwal kalibrasi ulang tanpa diingatkan | 100% alat dengan jadwal terkirim pengingat H-30 (diukur dari log pengiriman) |
| G3 | Pengajuan kalibrasi masuk rapi dan tercatat | ≥ 90% permintaan dikonfirmasi atau ditolak ≤ 1 hari kerja (angka final ikut K5) |
| G4 | Sertifikat bisa diakses mandiri, kapan pun | Pelanggan bisa mengunduh semua sertifikat terbit miliknya, termasuk revisi |
| G5 | Kerahasiaan antar pelanggan terjaga (ISO/IEC 17025 §4.2) | Nol insiden kebocoran data lintas pelanggan; test IDOR selalu hijau di CI |
| G6 | Mendorong kalibrasi ulang ke PT Sidik | Persentase alat yang diajukan ulang lewat aplikasi setelah pengingat (dipantau, belum ada target) |

## 3. Bukan tujuan MVP (Non-goals)

Hal-hal ini sengaja **tidak** dikerjakan di rilis pertama supaya MVP selesai dan aman:

- Penawaran harga, invoice, dan pembayaran online.
- Chat umum di luar konteks permintaan. Pesan hanya ada di dalam permintaan.
- Pelanggan mengedit data kalibrasi atau sertifikat. Pelanggan hanya melihat.
- Aplikasi iOS dan portal web pelanggan (tergantung K8; lihat risiko R-D09).
- Pelacakan kurir otomatis via API ekspedisi. MVP cukup nomor resi manual.
- Login SSO perusahaan dan 2FA wajib. Arsitektur tidak menutup kemungkinan ini.
- Multi-lab / multi-tenant untuk lab lain. Skema `organization_id` tetap dijaga.

## 4. Persona

**P1 — Bu Rina, Supervisor QA pabrik (PIC utama).** Bertanggung jawab ke audit ISO 9001/IATF. Mengelola ±150 alat ukur di dua gedung. Butuh daftar alat dengan jadwal kalibrasi yang bisa dipertanggungjawabkan saat audit, dan tidak mau ada alat yang lewat jadwal. Memakai HP kantor dan laptop. Kadang ganti staf.

**P2 — Dimas, teknisi maintenance pelanggan (staf).** Yang benar-benar memegang alat. Mendaftarkan alat baru, memotret nameplate, mengemas alat untuk dikirim ke lab, dan menerima teknisi PT Sidik saat onsite. HP Android kelas menengah, sinyal di area pabrik kadang lemah.

**P3 — Pak Hendra, Manajer Procurement pelanggan.** Jarang membuka aplikasi. Butuh ringkasan dan PDF sertifikat untuk dokumen pembayaran vendor. (Fitur khusus procurement tidak masuk MVP.)

**P4 — Sari, Admin layanan pelanggan PT Sidik.** Salah satu dari ±5 admin. Menerima permintaan, mengecek alat masuk ruang lingkup akreditasi, mengatur jadwal teknisi, dan menjawab pesan.

**P5 — Manajer teknis PT Sidik.** Mengesahkan hasil kalibrasi dan menentukan jadwal kalibrasi ulang. Tidak ingin admin layanan bisa ikut mengesahkan (K4).

## 5. Fitur MVP

Prioritas: **W** = wajib rilis pertama, **S** = sebaiknya ada di rilis pertama.

| ID | Fitur | Untuk | Prioritas |
|---|---|---|---|
| F01 | Daftar perusahaan baru + verifikasi email + menunggu verifikasi admin | P1 | W |
| F02 | Aktivasi akun lewat kode undangan dari admin (untuk pelanggan lama) | P1 | W |
| F03 | Masuk, lupa sandi, keluar, keluar dari semua perangkat | P1, P2 | W |
| F04 | Kelola anggota: PIC utama mengundang dan menonaktifkan staf | P1 | W |
| F05 | Alat saya: daftar, cari, filter status, detail alat | P1, P2 | W |
| F06 | Tambah & ubah alat (identitas, range, resolusi, lokasi, foto nameplate) dengan status "belum diverifikasi lab" | P2 | W |
| F07 | Status kalibrasi per alat: belum pernah, dalam proses, terkalibrasi, mendekati jadwal, lewat jadwal | P1 | W |
| F08 | Riwayat sertifikat per alat, unduh PDF, tanda revisi, tautan verifikasi QR | P1, P3 | W |
| F09 | Ajukan kalibrasi: pilih alat, pilih metode onsite / kirim ke lab, isi detail | P1, P2 | W |
| F10 | Lacak permintaan dengan timeline status per permintaan dan per alat | P1, P2 | W |
| F11 | Batalkan permintaan (pada status yang diizinkan) dan isi resi pengiriman | P2 | W |
| F12 | Pesan di dalam permintaan, dengan lampiran foto | P1, P2 | W |
| F13 | Notifikasi push + kotak notifikasi di aplikasi | P1, P2 | W |
| F14 | Pengingat jadwal kalibrasi ulang H-30, H-7, H-1, H-0, dan setelah lewat | P1 | W |
| F15 | Preferensi notifikasi per anggota | P1 | S |
| F16 | Hapus akun dari dalam aplikasi (wajib Google Play) | Semua | W |
| F17 | Pemeriksa versi minimum + layar wajib update + layar maintenance | Semua | W |
| F18 | Beranda ringkasan: jumlah alat, mendekati jadwal, lewat jadwal, permintaan aktif | P1 | S |

**Fitur sisi lab (app internal & API) yang ikut dibutuhkan MVP:**

| ID | Fitur | Untuk |
|---|---|---|
| A01 | Antrean pengajuan akun pelanggan: setujui dan tautkan ke `customers`, atau tolak | P4 |
| A02 | Buat kode undangan untuk pelanggan lama | P4 |
| A03 | Inbox permintaan bersama: ambil alih, alihkan, konfirmasi per alat, tolak, jadwalkan onsite, tandai alat diterima, kirim balik | P4 |
| A04 | Verifikasi alat yang diinput pelanggan (pemetaan `nama_alat_kemampuan`, cek ruang lingkup LK-285-IDN) | P4 |
| A05 | PIC admin default per pelanggan | P4 |
| A06 | Saat approve: sinkron tanggal ke alat dan kirim notifikasi "Sertifikat terbit" | P5 |
| A07 | Pemisahan izin otorisasi sertifikat (K4) | P5 |

## 6. Fitur fase berikutnya (bukan MVP)

Scan QR pada label alat fisik untuk langsung membuka halaman alat · Ekspor daftar alat & jadwal ke Excel untuk audit pelanggan · Survei kepuasan setelah permintaan selesai (ISO/IEC 17025 §8.6.2) · Formulir keluhan terstruktur (§7.9) · Penawaran harga & invoice · Kalender jatuh tempo · iOS / portal web · Multi-lokasi / cabang per perusahaan · Laporan bulanan ke email PIC utama.

## 7. Alur besar

```
PELANGGAN                          SISTEM / LAB PT SIDIK
─────────                          ─────────────────────
Daftar / pakai kode undangan ────► Admin verifikasi & tautkan ke data pelanggan
Tambah alat (belum diverifikasi) ► Admin verifikasi alat & cek ruang lingkup
Ajukan kalibrasi ───────────────► Inbox bersama → admin ambil alih
                                   ├─ Tolak (alasan)            ──► pelanggan dikabari
                                   └─ Konfirmasi per alat → jadi Order internal
      ONSITE                              KIRIM KE LAB
      Jadwal onsite dikabari              Pelanggan kirim alat + isi resi
      Teknisi datang & kerja              Lab tandai "alat diterima" (+ kondisi)
                                          Teknisi kerja di lab
                         ▼                          ▼
                 Sesi kalibrasi → menunggu approval → manajer teknis approve
                 + tentukan jadwal kalibrasi ulang → sertifikat terbit
                         ▼
Notif "Sertifikat terbit" ◄─────── Tanggal disinkron ke alat
Unduh PDF                          (kirim ke lab: lab isi resi pengiriman balik)
Pengingat H-30 … H-0 ◄──────────── Scheduler harian
Ajukan kalibrasi ulang (siklus ulang)
```

## 8. Asumsi & batasan

- Satu organisasi lab (PT Sidik). Pelanggan selalu berada di `organization_id` PT Sidik.
- Pelanggan memakai Android. Target API mengikuti syarat Google Play: mulai 31 Agu 2026, aplikasi baru dan update wajib target Android 16 (API 36).
- Rilis pertama berbahasa Indonesia.
- Data alat dan sertifikat pelanggan lama sudah ada di database produksi. Aplikasi pelanggan membuka akses ke data itu, tidak menyalinnya.
- Tim inti dua orang developer magang (Arkaan, Raihan), Senin–Jumat. Estimasi di 05-Task memakai asumsi ini.
- MVP internal CertiCal (akhir Sep 2026) tetap prioritas. Pekerjaan pelanggan yang menyentuh produksi internal hanya M0 sampai MVP internal aman.
