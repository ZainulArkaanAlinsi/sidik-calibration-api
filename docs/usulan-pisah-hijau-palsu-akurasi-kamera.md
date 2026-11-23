# Usulan: memisahkan "hijau palsu" di perintah `ocr:akurasi-kamera`

Dibuat 23 Nov 2026. Status: **USULAN, menunggu persetujuan.** Perintah ukur (`app/Console/Commands/AkurasiKamera.php`)
**tidak diubah.** Dokumen ini hanya menjelaskan masalahnya dan bentuk perubahannya.

## Masalah

`hijauPalsu()` (baris 409-445) menghitung "hijau palsu" = pembacaan dengan skor >= ambang hijau (`ocr.ambang.hijau`,
0,85) DAN `cocok = false`. `cocok = false` mencampur dua keadaan yang berbeda:

| Keadaan | `terbaca` | Yang terjadi di pipeline | Bahayanya |
|---|---|---|---|
| **A. Tidak bisa jadi angka** (huruf, simbol, spasi di tengah, ambigu, pemisah ribuan) | `false` | Sel **merah**, nilai kosong; teknisi mengetik | **Terlihat** oleh teknisi, murah. Bukan hijau di layar |
| **B. Angka murni tetapi salah** (mis. `58.01` padahal 50,01) dengan skor tinggi | `true` | Sel bisa **kuning/hijau** dengan angka yang salah | **Tidak terlihat** sampai sertifikat terbit. Inilah hijau palsu sebenarnya |

Sampai 23 Nov 2026 keadaan A hampir tidak muncul karena `NormalisasiAngka` mengganti huruf jadi digit (`5O.O2` → 50,02 →
"cocok"). Setelah penggantian dicabut, keadaan A masuk hitungan "hijau palsu", sehingga angkanya akan **melebih-lebihkan**
hijau palsu sebenarnya (metrik utama, targetnya mendekati nol). Tes `AkurasiKameraTest::test_hijau_palsu_...` sudah
disesuaikan agar mencerminkan ini, tetapi perintahnya belum memisahkan.

Catatan: angka `58.01` (0,99) dan `5O.O2` (0,97) di tes itu nilai fiksi di fixture, bukan data lapangan.

## Usulan perubahan perintah (belum dikerjakan)

1. Pisahkan tiga hitungan, semuanya terhadap pembacaan berskor >= ambang hijau:
   - **Hijau palsu (B)**: `terbaca = true`, `cocok = false`. **Metrik utama.** Ditampilkan satu per satu seperti sekarang.
   - **Tidak terbaca berkeyakinan tinggi (A)**: `terbaca = false`. Dilaporkan terpisah dengan label "terlihat teknisi
     (merah), bukan hijau palsu". Tetap dihitung sebagai kualitas mesin (mesin yakin pada teks yang bukan angka).
   - **Cocok**: sisanya.
2. Penyebut tiap persentase ditulis eksplisit (jumlah pembacaan berskor >= ambang), dan bila `skor` null di semua baris
   (ML Kit sering tanpa skor) perintah tetap memperingatkan bahwa **hijau palsu belum terukur, bukan nol** (peringatan
   yang sudah ada dipertahankan).
3. Tabel per kolom menambah kolom "terbaca tapi beda" (B) di samping "tidak terbaca" (A) yang sudah ada.
4. Tes baru: (a) `5O.O2` berskor 0,97 masuk daftar A, bukan daftar hijau palsu; (b) `58.01` berskor 0,99 masuk hijau palsu;
   (c) tidak ada skor di semua baris menghasilkan peringatan "belum terukur"; (d) ringkasan tidak menghitung sel yang sama dua kali.

## Yang tidak diubah

Definisi `cocok`, `terbaca`, ambang, dan sumber data. Tidak ada perubahan skema. Tidak menyentuh jalur pindai.

## Risiko

Angka historis yang pernah dilaporkan perintah ini tidak sebanding dengan angka baru (penyebut dan definisi berbeda); catat
tanggal perubahan di laporan mana pun yang membandingkan. Persentase di data nyata belum ada (produksi 2 sesi nyata,
nol `uncertainty_calculations`, per catatan `AGENTS.md`).
