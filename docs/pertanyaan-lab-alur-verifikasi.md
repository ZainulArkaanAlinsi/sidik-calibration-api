# Pertanyaan lab — alur verifikasi lembar kerja & Super Admin

Lahir dari §35 `docs/permintaan-user-7.md` (17 Sep 2026). Ditulis sebagai pertanyaan, BUKAN
dikarang jadi persyaratan — delapan hal di bawah menentukan bentuk kodenya dan tidak ada satu pun
yang bisa disimpulkan dengan aman dari permintaan aslinya.

| # | Pertanyaan | Kenapa ini menentukan |
|---|---|---|
| **V1** | Siapa yang berhak MENERBITKAN sertifikat ke pelanggan — Master Data juga, atau super admin saja? | Permintaan menyebut super admin "bisa ngirim ke user customer". Kalau Master Data juga boleh, gerbangnya di peran; kalau tidak, penerbitan jadi leher botol satu orang dan itu keputusan operasional, bukan teknis. |
| **V2** | Waktu teknisi memperbaiki titik yang ditandai, tandanya hilang sendiri atau harus dicabut pemeriksa? | Hilang sendiri = cepat, tapi pemeriksa kehilangan daftar "apa saja yang saya minta". Dicabut manual = jejaknya utuh, tapi menambah satu langkah tiap putaran. |
| **V3** | Sertifikat revisi penomorannya bagaimana — akhiran `-R1`, atau nomor baru sama sekali? | Ini konvensi lab, bukan pilihan programmer. Nomor sertifikat dipakai pelanggan untuk menelusuri, dan sudah tercetak di alat. |
| **V4** | Pelanggan yang SUDAH mengunduh sertifikat lalu sertifikatnya direvisi — diberi tahu atau tidak? | ISO/IEC 17025 §7.8.8.2 menuntut amandemen diberitahukan. Kalau jawabannya "tidak", itu keputusan yang perlu tertulis siapa yang mengambilnya. |
| **V5** | Sesudah revisi terbit, sertifikat versi lama masih bisa diunduh pelanggan? | Kalau masih: dua dokumen beredar dengan angka berbeda. Kalau tidak: pelanggan kehilangan bukti yang mungkin sudah dia pakai untuk audit pihak ketiga. |
| **V6** | Di produksi hari ini ada BERAPA organisasi (laboratorium)? | Menentukan apakah hak lintas-lab super admin itu risiko nyata sekarang atau baru nanti. Kalau cuma satu lab, pekerjaan pencatatan aksesnya tetap dibangun tapi prioritasnya turun. |
| **V7** | Kata "Master Data" menggantikan "Admin" di SEMUA tempat, termasuk aplikasi teknisi di HP? | Kalau iya, repo mobile ikut berubah dan itu rilis APK tersendiri. Kalau cuma panel web, cakupannya satu repo. |
| **V8** | Notifikasi "sertifikat diunduh" masuk ke siapa — penerbitnya saja, semua Master Data, atau super admin juga? | Salah pilih di sini melahirkan notifikasi yang diabaikan semua orang, dan notifikasi yang diabaikan sama saja dengan tidak ada. |

## Yang TIDAK ditanyakan karena sudah diputuskan

Keempatnya dijawab pemilik proyek 17 Sep 2026, tercatat di §35 — jangan dibuka lagi:

- "Master Data" = label tampilan, `users.role` tetap `admin`.
- Super admin melihat lintas laboratorium.
- Super admin boleh mengedit lembar kerja kapan saja, termasuk sesudah sertifikat terbit.
- Notifikasi untuk keempat kejadian, bukan sebagian.
