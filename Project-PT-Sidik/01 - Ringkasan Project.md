# Ringkasan Project — PT ASMO

🏠 [[Dashboard]]

## ⚠️ Catatan penting
Rencana ini disusun berdasarkan requirement yang disampaikan lisan dari atasan magang, **bukan dari brief tertulis**. Karena domainnya (kalibrasi alat ukur, sertifikat, satu aplikasi buat teknisi & admin) sama persis dengan project CertiCal yang sudah pernah dikerjakan sebelumnya, rencana ini **reuse fondasi teknis yang sudah terbukti**: struktur ERD, rumus perhitungan ketidakpastian (GUM), decision rule PASS/FAIL (ILAC-G8), skema penomoran sertifikat, dan pendekatan OCR kamera.

Kalau ternyata ada requirement PT ASMO yang beda dari asumsi ini, kabarin — rencana di folder ini gampang disesuaikan.

## Apa yang mau dibangun
Aplikasi mobile (satu APK, Flutter) buat mengelola data kalibrasi alat ukur dan menerbitkan sertifikat kalibrasi otomatis. Admin dan teknisi pakai aplikasi yang sama, dibedakan lewat role — **tidak ada web admin panel terpisah**, semua dikontrol lewat HP.

## Kenapa dibangun (tujuan bisnis)
- Bantu mempercepat & mengakuratkan proses input hasil kalibrasi (prioritas: input via kamera/OCR, bukan cuma ketik manual)
- Kalau berhasil, produk ini punya potensi ditawarkan/dijual ke perusahaan lain — jadi desain (UI/UX) dan kualitas kode harus di level yang layak jual, bukan cuma "asal jalan"

## Tim & jadwal kerja
- 2 orang (kamu + 1 teman), magang Senin–Jumat, ±jam 8/9 pagi – 3 sore
- Estimasi 2-3 bulan pengerjaan, mulai Senin 13 Juli 2026
- Pembagian default di rencana ini: 1 orang fokus Backend (Laravel), 1 orang fokus Mobile (Flutter) — sesuaikan PIC di tiap catatan harian kalau pembagian beneran beda

## Fitur inti (urutan pengerjaan, lihat [[Dashboard]] buat detail per minggu)
1. Login & role (admin/teknisi/viewer)
2. Master data (PT, alamat, pelanggan) & data alat per kategori
3. Input kalibrasi manual (fallback yang wajib selalu ada)
4. **Input kalibrasi via kamera/OCR (prioritas utama, dikerjain lebih awal dari rencana project sebelumnya)**
5. Perhitungan otomatis (ketidakpastian GUM, keputusan PASS/FAIL ILAC-G8)
6. Approval & generate sertifikat PDF + QR code
7. Laporan (riwayat, rekap, export) & notifikasi jatuh tempo
8. Polish UI/UX (karena target akhirnya mau dijual)
9. Testing menyeluruh
10. Build APK & demo ke atasan

## Prinsip yang dipegang selama pengerjaan
- **Kamera itu mempercepat input, bukan menggantikan verifikasi** — selalu ada layar review sebelum data final tersimpan, nggak ada auto-submit
- **Input manual harus selalu jadi fallback yang berfungsi** — jangan sampai OCR jadi satu-satunya jalan
- **Desain harus rapi dari awal**, bukan ditambal di akhir — karena rencananya mau ditawarkan ke PT lain
- **Progress dicatat tiap hari** di catatan harian masing-masing — bukan cuma rencana, tapi juga realisasinya
