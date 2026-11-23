# OCR tanpa koreksi otomatis — catatan keputusan dan pengukuran

Tanggal: 23 Nov 2026. Status: **belum di-commit** (branch `fix/ocr-tanpa-koreksi-otomatis`, worktree
`sidik-api-ocr-tanpa-koreksi`). Keputusan pemilik proyek; asal temuan: audit koreksi otomatis.

## Aturan

Hasil OCR tidak boleh diubah agar cocok dengan templat, rentang, atau pola angka. Validasi hanya boleh
menolak atau menandai (sel ragu), dan teks mentah selalu disimpan apa adanya.

## Yang dicabut dari `NormalisasiAngka` (jalur angka)

| Sebelumnya | Sekarang |
|---|---|
| Huruf/simbol diganti digit (`O o Q D`→0, `I l i \| ! /`→1, `Z z`→2, `A`→4, `S s`→5, `G b`→6, `T`→7, `B`→8, `g q`→9), maks 2, status kuning `ada_koreksi_karakter` | Ditolak: `karakter_di_luar_whitelist`, nilai kosong, status merah. `substitusi` selalu 0 |
| Spasi di tengah dibuang ("12 .5" → 12.5) | Ditolak: `spasi_di_dalam_angka`. Spasi di awal/akhir tetap dipangkas |
| Pemisah ribuan dibuang ("1.234,56", "1.234.567") | Ditolak: `pemisah_ribuan_tidak_didukung` |
| Titik/koma ambigu ("1.413") dipilih memakai rentang dan jumlah desimal templat | Ditolak: `desimal_ambigu`. Bagian utuh diawali 0 ("0,112") tetap desimal (aturan bentuk teks, bukan bukti templat) |

Masih diterima apa adanya: angka murni (`4,01`, `4.01`, `25`, `0,112`), satu pemisah desimal bila tidak ambigu, minus di
depan bila kolom mengizinkan. Penolakan lain tidak berubah (minus di tengah, digit kebanyakan, pemisah tidak wajar,
rentang/rasio di `ValidasiSel`, yang semuanya hanya menolak/menandai).

## Belum diubah (sengaja)

**DIUBAH 23 Nov 2026 (jam tanpa menunggu, disetujui pemilik dengan syarat; pemeriksaan rumus master di bawah):**
`NormalisasiJam` kini menerima bacaan berisi digit dan `:` apa adanya (kuning) dan menolak sisanya; tidak ada lagi
huruf→digit, penggantian pemisah, sisipan titik dua, atau detik/nol tambahan. Format final menunggu jawaban
pemilik lab: sebelumnya "belum diubah" karena menunggu jawaban
pemilik lab soal format kertas Autoklaf, lihat `docs/pertanyaan-lab-autoklaf-format-jam.md`. Sisi mobile
(`peta_tabel_foto._angka`, alfabet model) tidak berubah; audit lengkap di `docs/ocr-audit-koreksi-otomatis.md`.

## Kode mati yang dihapus (23 Nov 2026, disetujui pemilik; commit terpisah)

- `ValidasiSel`: pengurangan skor `substitusi x ocr.penalti.substitusi` (di jalur angka dan jam), alasan
  `ada_koreksi_karakter` (jalur angka dan jam), dan alasan `pemisah_jam_disisipkan`. Semuanya tidak bisa tercapai
  sejak koreksi dicabut.
- `config/ocr.php`: `ocr.penalti.substitusi` (env `OCR_PENALTI_SUBSTITUSI`) dan `ocr.penalti.maks_substitusi`
  (env `OCR_MAKS_SUBSTITUSI`; dulu hanya dibaca normalizer untuk alasan `terlalu_banyak_substitusi`, yang ikut dicabut). Tidak ada di `render.yaml`; env yang kebetulan
  disetel di dashboard kini diabaikan.
- **Field `substitusi` SELALU 0 dan `catatan` SELALU `[]`.** Keluaran `NormalisasiAngka::proses()` dan
  `NormalisasiJam::proses()` sengaja tetap memuat keduanya supaya bentuk keluaran tidak patah, tetapi tidak ada lagi
  penggantian atau penyisipan yang bisa mengisinya. `ValidasiSel` tidak membacanya lagi untuk keputusan apa pun;
  jangan membangun logika baru di atasnya. Di respons API sel, `normalisasi` (= `catatan`) selalu `[]`, dan
  `substitusi` tidak diekspos.
- Dijaga tes: `NormalisasiAngkaTest` (`substitusi` = 0, termasuk 4000 masukan acak), `ValidasiSelTanpaKoreksiTest`,
  dan `PindaiAutoclaveTest` (tidak ada alasan `ada_koreksi_karakter` atau `pemisah_jam_disisipkan`).

## Versi aturan

`ocr.aturan_versi` dinaikkan `val-1.0.0` -> `val-1.1.0` (config/ocr.php): vonis sel berubah untuk teks yang sama,
jadi scan lama dan baru harus bisa dibedakan saat ditelusuri. Jangan menyetel `OCR_ATURAN_VERSI` di env Render kecuali
sengaja menimpa; `FormulirAsli::aturanVersi()` ikut berubah menjadi `val-1.1.0+asli-1` (muat di kolom string(30)).

## Kontrak respons `template`: `aturan` sel jam

`aturan.format` untuk sel `tipe: jam` kini `null` dan `maks_digit` tidak ada lagi (dulu `HH:mm:ss` dan `6`). Mobile main
tidak membacanya (dicek 23 Nov 2026); klien yang membacanya harus menganggap jam sebagai teks bebas digit dan `:`.

## Dampak yang ditemukan regresi luas (23 Nov 2026): Viscometer

`PindaiViscometerTest::test_pemisah_ribuan_dibaca_lewat_bukti_pita` GAGAL setelah perubahan; sudah ditulis ulang untuk opsi A (hasil riset di bawah). Tes itu mengunci
perilaku lama: "63.181" di baris 60000 cP dibaca 63181 karena rentang pita baris menyingkirkan tafsiran 63,181. Itu
persis pemilihan titik/koma lewat rentang templat yang dicabut. Akibatnya, nilai Viscometer berpemisah ribuan (nominal
per baris 99,65 / 1018 / 3987 / 59003 / 99613, jadi empat dari lima baris) kini ragu (merah, nilai kosong) sampai
teknisi mengetik. Belum terukur berapa persen sel nyata yang ditulis dengan pemisah ribuan. Menunggu keputusan pemilik:
(A) terima, (B) izinkan format pemisah ribuan DIDEKLARASIKAN spesifikasi alat per kolom (menafsirkan, bukan memilih lewat
rentang; ini melonggarkan aturan "tidak ada penghapusan koma"), atau (C) tampilkan angka tafsiran hanya sebagai saran
tanpa mengisi sel.

### Riset Viscometer (23 Nov 2026) — bukti yang ada dan batasnya

- **PRD** (`docs/PRD-viscometer.md` §6.2-6.3) sendiri mewajibkan `631.74.2` → MERAH dan "ribuan vs desimal harus jatuh ke
  ambigu bila bukti tidak menyingkirkan salah satunya" (bukti: resolusi dan pita nominal). Frontend diperintahkan
  "jangan format angka dengan pemisah ribuan di kotak input; `63.181` ambigu buat manusia maupun mesin".
- **Master lab** (`Master_Olah_Data_Viscometer` → `INPUT DATA`), kolom 60000 cP: pembacaan ditulis `63181.3` dan `631.74.2`
  (yang kedua salah ketik, dua titik desimal), nominal baris `59003`, `1018`. **Tidak ada satu pun yang berpemisah ribuan**
  dan tidak ada yang berbentuk "satu pemisah + tiga digit". Contoh "63.181" di tes dan PRD adalah ilustrasi pengembang,
  bukan data yang teramati.
- **Lembar kosong** `SIDIK-FM-CAL-0524_Rev.3` mencetak label standar bulat (`30000`, `60000`) tanpa pemisah.
- **Kesimpulan sementara:** dampak opsi A pada tulisan lab diperkirakan kecil, dan opsi A konsisten dengan PRD yang sama.
  **Batas bukti:** satu master (5 baris x 5 Repeat), bukan foto lembar yang diisi di lapangan. Belum ada foto Viscometer
  terisi di disk. Tetap diperlukan konfirmasi pemilik lab (`docs/pertanyaan-lab-autoklaf-format-jam.md` bagian B butir 3).
- **Diterapkan:** opsi A. Tes `PindaiViscometerTest` ditulis ulang (24 tes lulus); PRD §6.3, `perintah-frontend-viscometer.md`
  dan `perintah-frontend-ocr-lembar-kerja.md` diperbarui. Opsi B dan C belum diputuskan.

## Petunjuk arah dari foto uji (BUKAN pengukuran, jangan dipakai sebagai klaim) — foto UV/VIS 16 Nov, tabel Holmium, 18 sel

Dijalankan lewat `ValidasiSel::periksa` (jalur server yang sama dengan pindai) dengan templat `spectrophotometer`,
sebelum dan sesudah perubahan. Baris kertas i = baris profil i+1 (profil punya 10 baris, kertas 9; dicek lewat
`titik_ukur`).

| Pembaca | Sel berteks | Kuning lama | Kuning → merah | Perubahan status lain |
|---|---|---|---|---|
| Mesin angka (PP-OCR) | 18 | 17 | **0** (0%) | 0 |
| OCR umum, keyakinan diasumsikan 0,90 | 9 | 3 | **1** (33% dari yang kuning lama; 11% dari yang berteks) | 0 |
| OCR umum, tanpa keyakinan (seperti ML Kit) | 9 | 0 | 0 (semuanya sudah merah) | 0 |

Satu-satunya sel yang berubah: `S3G.2` (kolom X2, baris 8). Dulu diubah menjadi 536,2 dan kuning; kebetulan itu angka yang
benar. Sekarang merah dan kosong, teknisi mengetik sendiri.

**Hanya petunjuk arah.** Satu foto, 18 sel, jawaban benar dibaca dari gambar oleh model AI (BUKAN manusia, belum diverifikasi),
bacaan OCR umum dari Windows OCR (bukan ML Kit di HP), keyakinan 0,90 hanya asumsi. Angka "0%" dan "5%" di atas tidak
boleh dikutip sebagai akurasi atau dampak.

**Tekanan dan konduktivitas (belum terukur, menunggu pemilik lab).** Sel yang ditulis satu pemisah + tepat tiga digit
dengan bagian utuh 1-3 digit tak berawalan 0 (mis. tekanan "1,013", konduktivitas "1.413") kini selalu ambigu: merah dan
kosong. Yang ditulis tanpa pemisah ("1413") atau berawalan 0 ("0,112") tetap diterima. Berapa persen sel nyata yang
terkena belum diketahui. Apakah kertas Autoklaf dan alat lain punya jumlah desimal tetap yang boleh dijadikan FAKTA
(bukan tebakan) adalah keputusan pemilik lab; belum diputuskan, dan kode tidak memihak tafsiran apa pun.

## Catatan: "HIJAU PALSU" 58.01 di `AkurasiKameraTest`

Angka `58.01` dengan keyakinan 0,99 (dan `5O.O2` dengan 0,97) adalah **nilai fiksi yang ditulis di tes**
(`AkurasiKameraTest` baris 95, 123-124), bukan hasil foto dan bukan dari asumsi keyakinan 0,90 di atas. Daftar itu hanya
membuktikan logika perintah `ocr:akurasi-kamera`, bukan temuan lapangan. Satu catatan metodologi: perintah itu
menghitung "hijau palsu" sebagai skor >= ambang hijau DAN tidak cocok, termasuk teks yang tidak bisa menjadi angka
(`terbaca=false`). Di pipeline, teks seperti itu tampil MERAH, bukan hijau, jadi untuk data nyata hitungan itu bisa
melebih-lebihkan hijau palsu sebenarnya. Memisahkan keduanya = perubahan perintah ukur, belum dikerjakan (menunggu persetujuan).
