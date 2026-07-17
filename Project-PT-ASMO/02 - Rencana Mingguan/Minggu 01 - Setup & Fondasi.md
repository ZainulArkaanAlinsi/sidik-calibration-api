# Minggu 01 — Setup & Fondasi

🏠 [[Dashboard]]

*(minggu pertama)* | [[Minggu 02 - Auth Lengkap, Role & Master Data]]

**Rentang**: 13 Jul – 17 Jul 2026

## Tema Minggu Ini
Setup & Fondasi

## Catatan Harian Minggu Ini
- [[2026-07-13|Monday, 13 Jul]] — Hari pertama: infrastruktur kosong jadi ada titik mulai.
- [[2026-07-14|Tuesday, 14 Jul]] — Nyusun kerangka data & navigasi biar hari-hari berikutnya tinggal isi.
- [[2026-07-15|Wednesday, 15 Jul]] — Database nyata + arah visual mulai kebentuk.
- [[2026-07-16|Thursday, 16 Jul]] — Login end-to-end pertama kali kelar.
- [[2026-07-17|Friday, 17 Jul]] — Review minggu 1 — pastikan fondasi solid sebelum nambah fitur.

## Rekap Minggu (isi manual di hari Jumat)
- **Apa yang berhasil:** Target minggu 1 cuma "login end-to-end + fondasi solid" — kelewatan jauh. Sampai hari ke-5 ini backend udah punya: auth lengkap (Sanctum, register + approval admin), skema 11 tabel, master data (customers, equipment categories, standards, equipment), alur sesi kalibrasi + perhitungan GUM otomatis (guarded acceptance ILAC-G8), generate sertifikat PDF otomatis pas approve + halaman verifikasi QR publik, alur OCR pengukuran, notifikasi jatuh tempo, dan panel admin Filament yang nutup semua 11 model (termasuk halaman detail sertifikat & aksi unduh/retry yang baru kelar hari ini). Suite test tumbuh dari 0 → **153 test / 607 assertion**, semuanya hijau.
- **Apa yang meleset dari rencana:** Bukan meleset ke arah buruk — mengerjakan jauh di depan urutan rencana 12 minggu (fitur yang dijadwalin minggu 4–8 udah kelar minggu 1), jadi checklist mingguan di sini nggak lagi mencerminkan kerjaan riil sejak [[2026-07-14]]. Efek sampingnya: bagian "Progress Hari Ini" di [[2026-07-15]] dan [[2026-07-16]] sempat kelewat nggak diisi pas hari-H, baru dilengkapi retroaktif hari ini dari `DAILY_REPORT.md` & git log. Laravel Extra Intellisense di VS Code juga masih error dari beberapa hari lalu — belum ke-fix karena teks error persisnya belum ada.
- **Penyesuaian buat minggu depan:** (1) Isi "Progress Hari Ini" di hari yang sama, jangan ditunda ke besok/lusa. (2) Karena backend udah jauh di depan, minggu depan fokusnya bukan ngejar checklist Minggu 02 secara harfiah — arahnya ke **bantu mobile** (biar nggak timpang) atau **polish panel admin** (widget dashboard udah ada, tinggal detail/filter tambahan). (3) Tangkap teks error Laravel Extra Intellisense biar bisa di-pin fix-nya. (4) Mulai isi `CertificateFactory`-style factory buat model lain yang masih ketinggalan, biar test baru nggak harus lewat alur API penuh tiap kali butuh data.
