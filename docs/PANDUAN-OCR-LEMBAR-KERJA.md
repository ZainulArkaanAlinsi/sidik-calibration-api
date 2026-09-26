# Panduan OCR Lembar Kerja — pegangan agen & pengembang

**Taruh di:** `sidik-calibration-api/docs/PANDUAN-OCR-LEMBAR-KERJA.md`
**Ditulis:** 25 Sep 2026, dari telusur repo `sidik-calibration-api` (711b7f5) dan
`sidik-calibration-mobile` (1fe6f9a) plus 41 PDF di
`Project-PT-Sidik/worksheet_alat_calibration/`.
**Diperbarui:** 26 Sep 2026 — pilot pH (§5 langkah 1–4): skrip pindah ke
`docs/skrip/`, empat perbaikannya di §8, konvensi draf/peta di §5.
**Status:** usulan arah + resep kerja. Bagian yang butuh keputusan lab ditandai
**[KEPUTUSAN LAB]** dan TIDAK boleh diputuskan sendiri oleh agen.

Baca berkas ini SEBELUM menyentuh apa pun yang berbau kamera, pindai, foto,
OCR, atau vision. Kalau isi berkas ini bertentangan dengan kode, **kode yang
benar** — betulkan berkas ini di commit yang sama.

---

## 0. Kenapa berkas ini ada

Repo ini sudah punya EMPAT jalur kamera yang hidup berdampingan, dokumennya
saling mengoreksi, dan satu saklar (`PINDAI_LEMBAR`) menggerbangi dua tombol yang
mesinnya beda. Agen yang masuk tanpa peta ini hampir pasti membangun jalur
kelima, atau "membetulkan" jalur yang memang sengaja dimatikan.

Target dari pemilik proyek (25 Sep 2026): teknisi memotret **satu lembar kerja
kertas**, lalu SEMUA isian di lembar kerja aplikasi terisi — identitas alat,
pemilik, kondisi lingkungan, tabel pembacaan, centang — teknisi mengecek dan
membetulkan, lalu kirim ke admin. Berlaku untuk **41 formulir** yang ada.

---

## 1. Peta jalur kamera yang ADA hari ini

Cek ulang tiap mulai kerja — peta ini membusuk:

```bash
grep -rn "pindaiLembarAktif\|lkPindaiLembar\|lkFotoTabel" ../sidik-calibration-mobile/lib --exclude-dir=l10n
grep -n "extract-from-photo\|worksheet-scans\|dokumen/baca" routes/api.php
grep -n "VISION_DRIVER\|VISION_AKTIF" config/services.php render.yaml
```

Perintah pertama membuang FOLDER `l10n` (berkas terjemahan hasil generate),
bukan baris yang memuat kata `l10n`. Versi lama (`| grep -v l10n`) ikut
membuang baris pemakaian seperti `l10n.lkFotoTabelGagal`: diukur 26 Sep 2026,
dia menampilkan 10 baris dari 34, dan jalur B tampak cuma hidup di satu layar
padahal dipakai di layar tabel, grid, dan matriks.

| # | Jalur | Mesin baca | Di mana | Status 25 Sep 2026 |
|---|---|---|---|---|
| A | `PINDAI LEMBAR KERJA` — satu lembar penuh | ML Kit **per crop sel**, geometri + marker + QR | mobile `pindai_lembar.dart`, `jalankan_pindai.dart`, `pembaca_sel.dart`; API `POST /worksheet-scans`, `app/Services/Ocr/*` | **Tombol dicabut** dari layar 26 Agt 2026 atas permintaan pemilik lab. Mesin & test utuh, sengaja dibiarkan (`lembar_kerja_screen.dart` ~baris 1864) |
| B | `FOTO TABEL INI` — satu tabel per jepretan | ML Kit **sehalaman**, dijangkar teks tercetak | mobile `ambil_foto_tabel.dart`, `pembaca_halaman.dart`, `peta_tabel_foto.dart` | **Hidup** di lembar titik×Repeat, grid Enclosure, matriks Autoklaf. TIDS belum. Tidak mengisi identitas/kepala lembar |
| C | Kamera AI cloud | Gemini (produksi), OpenAI/Anthropic cadangan | API `POST /raw-measurements/extract-from-photo`, `WorksheetVisionExtractor` | Endpoint hidup; komentar rute bilang mobile tidak memanggilnya lagi. Driver cadangan ditambah 23 Sep |
| D | Baca dokumen generik | AI cloud, SELURUH halaman | API `POST /dokumen/baca`; mobile `dokumen_generik_service.dart` | Hidup, di saklar `VISION_AKTIF` yang sama dengan C |

Pendukung yang sudah jalan dan WAJIB dipakai ulang, bukan ditulis ulang:

- `NormalisasiAngka` — teks → angka, koma/titik ditafsir dari bukti template, koma tidak pernah disisipkan.
- `ValidasiSel` — vonis hijau/kuning/merah/kosong; tulisan tangan mentok kuning.
- Perekam tebakan mesin (`measurements[i].ocr[]` → `ocr_raw_text`, `ocr_confidence`) + `php artisan ocr:akurasi-kamera` — akurasi per kolom & **hijau palsu**.
- Gerbang approve `is_verified` (`CalibrationController`) — baris dari kamera tidak bisa di-approve sebelum dikonfirmasi.
- Pipeline latih pengenal angka TFLite (mobile PR #130) — belum ada model layak pakai.

---

## 2. Temuan yang mengubah arah (diverifikasi 25 Sep 2026)

1. **Geometri yang ada bukan geometri formulir lab.** Ke-40 berkas
   `database/ocr-templates/*.json` memakai kanvas **A4 potret 1654×2339**,
   lahir dari `ocr:rangka-geometri` (grid rata), dan kertasnya dicetak ulang
   mengikuti JSON. Padahal **20 dari 41** formulir asli lab berukuran Letter/A4
   **lanskap** (mis. pH 792×612 pt). Artinya jalur A cuma bisa membaca kertas
   buatan sistem — bukan formulir SIDIK-FM-CAL yang dipegang teknisi. Ini
   penjelasan paling masuk akal kenapa jalur A terasa "gk jelas" di lapangan.
2. **Ke-41 PDF formulir asli punya vektor kotak + teks tercetak.**
   `pdfplumber` menemukan tabel & sel di semua 41 berkas. Jadi koordinat bisa
   **diukur dari dokumen mutu**, bukan dirancang. Skripnya:
   `docs/skrip/draf-geometri-dari-pdf.py` (lihat §5). Salinan lama di folder
   lokal `draf-geometri-41-formulir/` (tidak ter-track) **jangan dipakai** —
   belum memuat perbaikan 26 Sep 2026 di §8.
3. **Kode dokumen tercetak di kaki SEMUA 41 formulir** (`SIDIK-FM-CAL-0509`
   dst., termasuk varian `.A–.D`). Teks cetak dibaca ML Kit dengan baik, jadi
   **formulir lama tetap bisa dikenali tanpa QR**.
4. **Cakupan:** cuma 23 dari 41 kode formulir yang tertaut ke berkas geometri
   lewat `kode_dokumen`; 8 berkas geometri `kode_dokumen`-nya kosong
   (gas_detector, height_gauge, micrometer, proving_ring, thermocouple,
   thermohygro, thermometer_glass, timer_stopwatch — dihitung ulang 26 Sep 2026,
   sebelumnya tertulis 9); 6 yang
   `terverifikasi: true` (pH, conductivity, chlorine, refractometer,
   spectrophotometer, turbidimeter — semuanya versi kertas bermarker).
5. **Konflik revisi:** berkas `SIDIK-FM-CAL-0525_Rev.3 - LEMBAR KERJA
   THERMOHYGRO.pdf` mencetak `Revise : 2` di kakinya. **[KEPUTUSAN LAB]** mana
   yang berlaku. 12 formulir lain tidak mencetak revisi dengan pola
   `Revise : N` sama sekali — revisi untuk mereka harus diambil dari nama
   berkas yang disahkan, bukan dari foto.
6. **Kebijakan cloud bertabrakan.** `SPEC-ocr-template-lokal.md` §0 melarang
   Gemini/Claude/OpenAI membaca foto; produksi tetap menjalankan jalur C dan D
   dengan driver cloud. **[KEPUTUSAN LAB]** — lihat §7.

---

## 3. Arah yang diusulkan: satu jalur, formulir ASLI

Jangan bikin jalur kelima. Jalur A dihidupkan lagi, tapi diarahkan ke formulir
asli lab dan diperluas ke seluruh isian lembar:

```
FOTO satu lembar (formulir SIDIK-FM-CAL asli, tanpa cetak ulang)
  │
  ├─ 1. KENALI   ML Kit baca teks cetak → cari kode "SIDIK-FM-CAL-xxxx(.X)"
  │              beda dengan formulir profil sesi ini → BERHENTI
  │
  ├─ 2. RATAKAN  cocokkan teks cetak hasil ML Kit ke `jangkar_teks` dari PDF
  │              → homography (RANSAC), syarat: ≥8 jangkar, menyebar di 4 kuadran,
  │              residual < ambang. Marker opsional, cuma penguat buat cetakan baru
  │
  ├─ 3. POTONG   tiap isian dipotong PER KOTAK dari geometri PDF. Kunci sel
  │              ditentukan SEBELUM dibaca (prinsip lama jalur A — tetap)
  │
  ├─ 4. BACA     pembaca dipilih per JENIS isian (§4)
  │
  ├─ 5. SERVER   NormalisasiAngka + ValidasiSel + 7 tahap penjagaan (sudah ada)
  │
  └─ 6. REVIEW   layar review: angka hasil baca DI SEBELAH crop aslinya,
                 teknisi konfirmasi/betulkan → simpan draf → kirim ke admin
```

Jalur B tetap hidup sebagai cadangan per tabel sampai jalur baru terbukti
per formulir. Jangan cabut B di commit yang sama dengan menyalakan A.

---

## 4. Pembaca per jenis isian — ini inti akurasinya

| Jenis isian | Contoh | Cara isi | Aturan |
|---|---|---|---|
| Angka tabel | pembacaan pH Repeat 1–5, °C | OCR per crop, karakter dibatasi `0-9 , . -` | Selalu lewat `NormalisasiAngka`. Tulisan tangan tidak pernah hijau |
| Identitas dari order | Owner Name, Address, nama alat, merk, S/N | **Diisi dari data order/alat di database**, BUKAN dari OCR | OCR cuma membandingkan. Beda dengan kertas → sel kuning "kertas bilang X, sistem bilang Y". Nama pelanggan tidak pernah dibuat dari OCR |
| Identitas yang belum ada di sistem | Type/Model baru, Location | OCR = usulan | Wajib dikonfirmasi; kosong lebih baik dari tebakan |
| Tanggal | Received/Calibration Date | OCR + parser format ketat (dd/mm/yyyy, dd-mm-yy) | Tanggal ambigu → merah, bukan ditebak |
| Centang | TH-2/TH-6/TH-7, Usage Check, Type Cal K/T/R | Rasio piksel gelap di dalam kotak, BUKAN OCR | Di antara ambang → kuning |
| Kondisi lingkungan | T awal/akhir, RH | Sama dengan angka tabel | Rentang wajar dicek |
| Tanda tangan, paraf | Calibrated by / Checked by | **Tidak dibaca dan tidak pernah dibuat** | Paling jauh: "ada coretan / kosong" |
| Catatan bebas | Catatan | Tidak di-OCR; teknisi ketik | — |

Contoh pemilik proyek: kertas diisi "Arkaan" di kolom nama → yang muncul di
aplikasi nama dari data order. Kalau OCR membaca "Arkaan" dan order bilang
"Arkaan", sel itu hijau-karena-cocok. Kalau beda, kuning dan teknisi yang
memutuskan. Ini lebih akurat daripada mempercayai OCR tulisan tangan nama orang
— dan nama orang adalah jenis teks yang PALING sering salah dibaca mesin.

### Soal "harus akurat 100%"

Tidak ada pembaca tulisan tangan yang 100% — Tesseract bahkan membaca sebuah
nama orang di video contoh jadi kata lain yang sama sekali tidak mirip (nama
aslinya sengaja tidak ditulis: repo ini publik). Yang BISA dijamin sistem:

1. **Tidak ada angka salah yang sampai sertifikat tanpa dilihat manusia.**
   Gerbang `is_verified` + layar review + tulisan tangan tidak pernah hijau.
2. **Angka tidak mendarat di sel yang salah.** Kunci sel sebelum baca,
   jangkar label baris, tolak seluruh lembar kalau pemetaan ragu.
3. **Koma/titik/nol tidak dikarang.** Aturan `NormalisasiAngka` tetap.
4. **Akurasi terukur per kolom**, dengan hijau palsu sebagai angka penentu.

Penaik akurasi terbesar bukan model, tapi **kertas** — **[KEPUTUSAN LAB]**
karena formulir itu dokumen mutu: kolom angka diganti kotak per digit dengan
titik desimal tercetak (menghapus ambiguitas koma/titik sekaligus), plus aturan
tulis di IK (pulpen hitam, angka cetak, koreksi dicoret satu garis + paraf).

---

## 5. Resep per formulir (dipakai untuk 41 formulir dan alat baru nanti)

Satu formulir = satu PR. Urutan wajib:

1. **Pastikan profilnya ada.** Formulir tanpa `CalibrationProfile` tidak boleh
   dapat jalur pindai — OCR mengisi form, bukan menggantikan profil.
2. **Buat draf geometri dari PDF asli** (folder berisi PDF, atau satu PDF):
   ```bash
   pip install pdfplumber pillow --break-system-packages
   python3 docs/skrip/draf-geometri-dari-pdf.py \
       Project-PT-Sidik/worksheet_alat_calibration  keluaran/ocr-draf
   ```
   Jalan apa adanya di Windows — tidak perlu `PYTHONUTF8` lagi (lihat §8).
   Keluarannya per formulir: `.json` (sel, isian berlabel, kotak centang, jangkar
   teks — koordinat ternormal 0..1) dan `.png` overlay (hijau = calon isian,
   abu = sel tercetak ATAU wadah, biru = isian berlabel, oranye = kotak centang).
   Buka overlay-nya, jangan cuma baca JSON-nya.
3. **Petakan kotak → kunci profil DENGAN TANGAN, di berkas terpisah.** Dua
   berkas per formulir di `database/ocr-templates/asli/`, contoh pilot pH:

   | Berkas | Isi | Boleh disunting tangan? |
   |---|---|---|
   | `ph_meter-0509.draf.json` | keluaran skrip langkah 2, disalin APA ADANYA | **Tidak.** Salah → betulkan skripnya, jalankan ulang, salin lagi |
   | `ph_meter-0509.peta.json` | keputusan manusia: kotak mana jadi kunci apa | Ya — ini satu-satunya tempat keputusan itu |

   Aturan peta:
   - Kotak dirujuk lewat **TITIK TENGAH ternormal `(x, y)`, BUKAN id `s000`**:
     id bergeser begitu skripnya diperbaiki, titik tengah tidak.
   - `sel`: kunci `{tabel_id}|{baris_ke}|{repeat_no}|{field_id}` dari
     `CalibrationProfile::bentukLembarKerja()`.
   - `isian` & `centang`: `kode` field lembar kerja, plus `cara` dari kosakata
     §4 (`angka`, `tanggal`, `banding`, `usulan`, `centang`). Centang per baris
     tabel (Usage Check) membawa `baris_ke` + `label` tercetaknya; centang yang
     memilih satu nilai (TH-n) membawa `pilihan`.
   - `abaikan`: kotak yang tidak punya padanan, dikelompokkan per `jenis` dan
     WAJIB beralasan — bukan dihapus diam-diam.
   - Nama berkasnya sengaja tidak berpola `{kode}-v{n}.json`, jadi
     `TemplateLembarKerja::geometri()` (glob tidak rekursif di folder atas)
     tidak pernah memuatnya sampai formulir itu lulus langkah 5.
4. **Test penjaga (wajib hijau, SQLite + MySQL)** — contohnya
   `tests/Feature/GeometriFormulirAsliPhTest.php`: sha256 PDF = yang tercatat
   di draf & peta (PDF berubah → test merah → geometri diulang); `kode_dokumen`
   tercetak = kode di profil; tiap kunci profil terpetakan tepat satu kali; tiap
   titik peta jatuh di TEPAT SATU kotak draf sejenisnya (nol atau dua → merah);
   tiap kotak isian draf dipetakan atau diabaikan; SEMUA kotak isian draf tidak
   saling tumpang dan tidak memotong sebagian sel tercetak; semua di dalam
   halaman; berkas `{kode}-v{n}.json` yang sudah ada byte-identik (hash di
   test). Uji tidak-bertumpukan tidak boleh dilonggarkan — kalau merah karena
   kotak draf, yang dibetulkan skripnya.
5. **Adu ke foto nyata.** ≥20 foto formulir ASLI yang sudah diisi tangan,
   berbagai HP/cahaya/kemiringan, dengan nilai benar diketik terpisah.
   Jalankan `ocr:akurasi-kamera`. Syarat `terverifikasi: true`: **hijau palsu =
   0**, tidak ada angka di sel yang salah, akurasi per kolom dilaporkan apa
   adanya.
6. **Baru nyalakan** `siap_pindai` untuk formulir itu. Formulir lain tetap
   ketik manual / jalur B.

Urutan pengerjaan yang disarankan (bentuk kertas sejenis dikelompokkan):
pH (pilot) → conductivity, chlorine, DO, turbidimeter, TDS, spectro, refracto →
micrometer 0522.A–D, jangka sorong, dial indicator → timbangan, anak timbangan →
suhu (TITS, TIDS, thermocouple, termometer gelas, thermohygro) → gaya (UTM, load
cell, proving ring) → volumetrik & piston → aliran (0538, .A, .B) → Enclosure &
Autoklaf (grid/matriks, paling akhir).

---

## 6. Larangan (dilanggar = PR ditolak)

1. Jangan mengisi angka yang tidak terbaca. Kosong/merah lebih baik.
2. Jangan menyisipkan/memindah koma supaya angka "masuk akal".
3. Jangan menurunkan ambang (`ocr.ambang.*`, `geometri.marker_min`, residual)
   supaya lolos. Ambang digeser = naikkan `aturan_versi`, dan jangan di minggu
   yang sama dengan pergantian pembaca.
4. Jangan jadikan tulisan tangan hijau.
5. Jangan tebak baris/kolom dari URUTAN teks OCR. Selalu dari kunci geometri
   atau jangkar tercetak.
6. Jangan simpan crop yang memuat kepala lembar (nama/alamat pelanggan) sebagai
   data latih, dan jangan ekspor crop keluar HP tanpa keputusan lab.
7. Jangan buat, baca, atau tiru tanda tangan.
8. Jangan setel `terverifikasi: true` tanpa bukti §5 langkah 5.
9. Jangan hapus jalur B, `pindai_lembar.dart`, atau `jalankan_pindai.dart`
   sebagai "berkas mati".
10. Jangan sunting keluaran skrip generator dengan tangan tanpa memperbarui
    skripnya.

---

## 7. Pertanyaan untuk lab / pemilik proyek

1. **Jalur pindai satu lembar dihidupkan lagi, diarahkan ke formulir asli?**
   (Pencabutan 26 Agt dibuat saat jalur itu cuma bisa membaca kertas bermarker.)
2. **Boleh potongan SEL ANGKA (tanpa kepala lembar) dikirim ke AI cloud** untuk
   dibaca bila pembaca lokal ragu? Kalau tidak, jalur C/D juga perlu diputuskan
   nasibnya supaya kebijakan dan kode sejalan.
3. **Boleh formulir revisi berikutnya memakai kotak per digit** untuk kolom angka?
4. Revisi thermohygro yang berlaku: 3 (nama berkas) atau 2 (tercetak)?
5. Field identitas mana yang **selalu** dari data order (disarankan: pemilik,
   alamat, nama alat, merk, S/N) dan mana yang boleh dari OCR?
6. Siapa yang menyediakan ≥20 foto formulir terisi per alat untuk verifikasi,
   dan apakah foto itu boleh disimpan di server lab?
7. **"1. Location" di formulir pH dipetakan ke kode apa?** Kertasnya teks
   bebas; profil memisah `lokasi` (pilihan lab/onsite) dan `lokasi_nama` (cuma
   tampil untuk insitu). Sampai dijawab, kotaknya `abaikan` di
   `asli/ph_meter-0509.peta.json` — OCR teks bebas ke kolom pilihan itu tebakan.
8. **TH-7 itu Insitu atau Inlab?** Formulir pH mencetak `Insitu: TH-2 TH-6
   TH-7` / `Inlab: TH-4`, sedangkan `LembarKerjaTemplate::THERMOHYGRO_TERCETAK`
   menaruh TH-7 di grup Inlab. Kotak centangnya tetap dipetakan ke pilihan
   `TH-7`; yang berbeda cuma pengelompokan di dropdown HP.

---

## 8. Keterbatasan skrip draf geometri (jujur)

### Sudah dibetulkan 26 Sep 2026 (pilot pH)

Tiap perbaikan dicek lewat overlay pH & TITS, lalu diukur di ke-41 formulir:

1. **Windows.** Skripnya dulu menulis JSON tanpa `encoding` dan gagal di
   karakter `π` (cp1252) — harus dijalankan dengan `PYTHONUTF8=1`. Keluarannya
   juga ber-CRLF di Windows, jadi berkas yang di-commit (dinormalkan git ke LF)
   tidak lagi sama byte dengan keluaran skrip. Sekarang semua berkas ditulis
   UTF-8 + LF; `PYTHONUTF8=1` cuma perlu kalau memakai salinan lama skrip.
2. **Isian berlabel menembus panel.** Kotak identitas dulu melebar sampai 0,95
   lebar halaman: 1153 pasang tumpang-sebagian dengan kotak lain di 41 formulir
   (pH: 84, termasuk sel tabel pembacaan). Sekarang dipotong di tepi vektor
   sel/panel, di kotak centang, dan dibagi di tengah bila dua baris terlalu
   rapat. Yang gugur cuma baris centang (Insitu/Inlab/Thermohygro used/Force
   Type) dan satu `THERMOHYGRO:` di 0508.A yang ruangnya 17 pt ke bingkai —
   bukan isian tulis.
   Sekalian ketemu bug presedensi `if/else` yang membuat kotak pembungkus tidak
   pernah dipakai begitu ada kata di kanan.
3. **Kotak centang.** Dugaan awal "digambar dari empat garis tipis" SALAH:
   Usage Check & TH-n di pH, K/T/R/J/N/S di TITS adalah **gambar** — kontrol
   form Excel yang diekspor jadi raster, nol rect/garis. Sekarang piksel
   gambarnya dibaca langsung (bukan render halaman, jadi teks & garis tabel di
   atasnya tidak ikut) dan cuma bujursangkar berongga 5–14 pt yang diterima.
   Ke-131 gambar seukuran centang di 41 formulir berformat RGB 8-bit Flate —
   format lain dilewati, dan belum pernah ditemui. Centang vektor yang digambar
   dua kali kini satu; sel yang membungkus centang/isian lain jadi `wadah`.
4. **Isian ber-`=` dan blok Env. Condition.** `=` setara `:` (T awal = ___ °C).
   "Kata ___ satuan" tanpa pemisah (First ___ (°C) / End ___ (%RH)) jadi isian
   bila tidak dipisah garis vertikal — blok Env. pH kini empat kotak, bukan satu
   kotak kosong di luar bloknya. Label berhenti di pemisah, satuan, garis, atau
   jeda kosong sebelumnya ("Catatan", bukan "Dikalibrasi Oleh: Diperiksa Oleh:
   Catatan"), dan cukup dua huruf ("z1 =" di tabel Accuracy Timbangan).

Hasil ukur sesudahnya di 41 formulir: nol kotak isian yang saling tumpang; satu
irisan tersisa (butir terakhir di bawah).

### Masih utang

- **Kolom palsu di tepi kanan tabel** (pH: 26 sel tipis di kanan 10,01 °C).
  Di peta pilot diberi `abaikan` beralasan; skripnya belum membuangnya.
- **Teks yang dipotong garis antar-sel tidak tertangkap.** "Solution Standard"
  di pH ditimpa garis di tengah tulisannya, jadi dua selnya terbaca calon isian.
- **Celah di antara dua kotak** ikut terbaca sel (pH: ruang antara kotak Env.
  °C dan %RH).
- **"awal ___ akhir ___ °C" bergaris bawah** (0504, 0506, 0522.A–D, 0526, 0527):
  cuma isian "akhir" yang tertangkap; isian "awal" tidak ditutup satuan maupun
  pemisah. Env. formulir itu belum empat kotak.
- **Label satu huruf gugur** ("π =" di 0504).
- **Isian tanpa pembungkus** ("Standard Used :" di TITS) masih melebar ke 0,95
  lebar halaman — tidak menumpuk apa pun, tapi lebih lebar dari garisnya.
- **0508.A: satu kotak centang tercetak menonjol 1 pt dari selnya** — irisan
  satu-satunya yang tersisa di 41 formulir, dan itu memang bentuk kertasnya.
- Skrip cuma membaca halaman 1; semua 41 formulir memang satu halaman per 25 Sep.
