# Perintah frontend — Height Gauge (alat ke-26)

**Tempel dokumen ini apa adanya ke sesi kerja `sidik-calibration-mobile`.** Dia berdiri
sendiri: tidak ada satu pun hal di bawah yang perlu dicari balik ke repo backend.

> **STATUS: SUDAH DIKERJAKAN, 7 September 2026.** Backend hijau, mobile hijau
> (1618/1618, `dart analyze` bersih). Dokumen ini sekarang jadi CATATAN apa yang
> mendarat — bukan lagi daftar tugas. Yang mengerjakannya ulang dari nol tetap bisa
> memakainya sebagai panduan.
>
> Dikerjakan di branch `height-gauge` (dibuat dari `origin/main`), **bukan** `main`:
> `main` lokal punya 3 commit soal setup terminal yang bentrok dengan `origin/main`
> #159, dan konflik itu bukan urusan Height Gauge.

Lembar kerjanya digerakkan JSON dari server, jadi sebagian besar jalan sendiri — yang
perlu disentuh cuma lima hal di bawah, dan **dua di antaranya menggerakkan ANGKA**.

---

## 0. Ringkasan satu paragraf

Height Gauge 600 mm, kelompok Panjang, IK `SIDIK-IK-CAL-0539_Rev.0`. Satu sesi punya
**tiga blok pengukuran yang tidak sebangun**: paralelisme (3 pembacaan, tingkat sesi),
Evaluation (10 pembacaan berulang, tingkat sesi), dan Measurement (10 titik ukur
ber-nominal pra-cetak, 3 pembacaan tiap titik). Ketidakpastiannya lahir **sekali per
sesi**, bukan per titik — sertifikatnya mencetak satu baris `Uncertainty U95% = ±` di
bawah sepuluh titik.

Yang bikin alat ini beda dari 25 lainnya: **dia tidak punya lantai CMC**. Height Gauge
di luar lampiran akreditasi LK-285-IDN. Di alat lain, komponen budget yang hilang
mendarat di lantai CMC dan hasilnya masih di atas kemampuan terakreditasi — salah, tapi
tertampung. Di sini **tidak ada yang menampung maupun menahan**: U95 langsung terbit
terlalu kecil, dan tidak ada satu pun angka di jalurnya yang terlihat ganjil.

Itu sebabnya poin 3 di bawah bukan kerapian.

---

## 1. `lib/services/contoh_lembar_kerja_panjang.dart` — TAMBAH fungsi

> **Bukan berkas baru.** Repo mobile menamai contoh per **kelompok pengukuran**
> (`contoh_lembar_kerja_panjang.dart` sudah memuat Micrometer), bukan per alat. Height
> Gauge kelompok Panjang, jadi dia jadi fungsi kedua di berkas yang sama:
> `contohBentukLembarKerjaHeightGauge()`.

**Salinan APA ADANYA** dari respons

```
GET /api/calibrations/lembar-kerja?equipment_id=<id alat contoh>
```

untuk alat contoh ter-seed (S/N `1610232804`, "Height Gauge" Insize Digital).

**Digenerate dari JSON-nya, jangan disusun ulang tangan.** Menyusun ulang berarti mode
mock memajang bentuk yang berbeda dari yang dikirim server, dan bedanya baru ketahuan
di lapangan.

Cara menggenerate ulang kalau bentuk servernya berubah:

```bash
# di sidik-calibration-api, pakai sqlite sementara — JANGAN .env (itu produksi)
DB_CONNECTION=sqlite DB_DATABASE=/tmp/dump.sqlite php artisan migrate --force
DB_CONNECTION=sqlite DB_DATABASE=/tmp/dump.sqlite php artisan db:seed --force
DB_CONNECTION=sqlite DB_DATABASE=/tmp/dump.sqlite php artisan tinker --execute='
  $a = App\Models\Equipment::where("serial_number","1610232804")->firstOrFail();
  echo json_encode(app(App\Services\Calibration\CalibrationProfileRegistry::class)
    ->untukAlat($a)->bentukLembarKerja(false, $a));'
```

> **Jangan jalankan `dart format` pada berkas utuh.** Repo ini tidak
> `dart format`-clean di lebar bawaan, jadi memformat seluruh berkas merapikan kode
> yang tidak kamu sentuh dan mengotori diff — 806 baris churn di
> `lembar_kerja_service.dart` waktu ini pertama dicoba.

---

## 2. `lib/services/lembar_kerja_service.dart` — tambah cabang

```dart
'height_gauge' => contohBentukLembarKerjaHeightGauge(...),
```

**Tanpa cabang ini, mode mock memajang lembar pH tiga titik buffer.** Tidak ada error —
cuma lembar yang salah. Persis yang sudah kejadian di TIDS dan Timbangan.

---

## 3. `lib/screens/calibration/lembar_kerja_state.dart` — `_kodePenentuAngka` ⚠️ ANGKA

Tambahkan **dua**:

```
spesifikasi_alat.height_gauge.satuan
spesifikasi_alat.height_gauge.resolusi_mm
```

Alasannya sama dengan Micrometer, tapi **akibatnya lebih berat**:

- **Satuan yang kelupaan dipilih** jatuh ke `mm` di server. Sesi berskala `inch` jadi
  salah **25,4×** — tanpa satu pun error.
- **Resolusi kosong** terbaca nol, komponen budget #2 lenyap.

Di Micrometer keduanya masih ditutupi lantai CMC (U95 mendarat di lantai dan tampak
wajar). **Di sini tidak ada lantai.** Yang terbit langsung terlalu kecil.

Backend memang menahan sesi ber-resolusi kosong (tidak menerbitkan satu pun baris
hitungan), tapi teknisi baru tahu **sesudah** menekan kirim. Penentu angka di sisi HP
yang mencegahnya sebelum itu.

---

## 4. `lib/services/category_service.dart` — entri `CalibrationCapability`

> **Ada test yang menjaganya**, dan dia bakal MERAH kalau entrinya ditambah tanpa
> memikirkan vonisnya: `test/vonis_toleransi_mock_test.dart` mewajibkan tiap baris
> kemampuan mock punya entri di tabel vonisnya. Height Gauge `punyaToleransi: false`.
>
> Waktu ini dikerjakan, test itu ikut menemukan **drift lama**: `Micrometer` masih
> ditulis `true` di mock DAN di tabel vonisnya, padahal server berbalik ke `false`
> sejak `MicrometerProfile` lahir 4 Sep. Di build `USE_MOCK=true` Micrometer memaksa
> teknisi mengisi toleransi yang masternya tidak punya. Sudah dibetulkan bareng.

```dart
CalibrationCapability(
  namaAlat: 'Height Gauge',
  kelompok: 'Panjang',
  metode: 'SIDIK-IK-CAL-0539_Rev.0',
  satuan: 'mm',
  ketidakpastianTerbaik: null,   // ⚠️ null/nol — JANGAN angka karangan
  satuanKetidakpastian: 'mm',
)
```

**`ketidakpastianTerbaik` wajib null atau nol.** Height Gauge di luar lampiran
akreditasi; mengisi angka apa pun di situ berarti memajang klaim kemampuan yang tidak
diakui KAN. Server memang mengirim `0` (baris kemampuannya sengaja dibuat ber-CMC nol
supaya jalur budget penuh tetap jalan) — jangan diterjemahkan jadi "0,000 mm" yang
terbaca seperti klaim sempurna. Tampilkan sebagai **"—"** atau "di luar lingkup
akreditasi".

---

## 5. `instrument_picker_screen.dart` — ikon

Tambahkan `n.contains('height')` ke cabang `Icons.straighten_outlined` yang sudah memuat
caliper / micrometer / dial.

---

## 6. Bentuk lembarnya — TIGA tabel

Semua digerakkan JSON dari server; ini cuma supaya kamu tahu apa yang bakal datang.

| Tabel | `kode` bagian | Baris × kolom | `simpan_ke` | `offset_kunci` |
|---|---|---|---|---|
| Paralelisme | `paralelisme` | 1 × 3 | `spesifikasi_alat.height_gauge.paralelisme` | **2000** |
| Evaluation | `evaluasi` | 1 × 10 | `spesifikasi_alat.height_gauge.pra_evaluasi` | **1000** |
| Measurement | `hasil` | 10 × 3 | — (jalur `measurements` biasa) | — |

Ketiganya `titik_bisa_diubah: false`. Nominal kesepuluh titiknya **pra-cetak**
(25, 50, 100, 150, 200, 300, 400, 500, 550, 600 mm) dan ditentukan Instruksi Kerja —
teknisi tidak memilih, tidak menambah, tidak mengurangi.

### ⚠️ `offset_kunci` itu WAJIB dihormati, bukan kerapian

Ketiga tabel ber-`tahap` **sama** (`sesudah_adjustment`), jadi kunci barisnya bisa
bertabrakan di layar. Tabrakan seperti itu **sudah nyata di Timbangan** (Accuracy 50 kg
vs Repeatability Middle 50 kg): angka yang diketik di satu kotak muncul di kotak lain,
tanpa satu pun error.

Di sini akibatnya lebih mahal: baris **Evaluation** yang tertimpa membuat keterulangan
lahir dari angka titik ukur, dan **U95 seluruh sesi** ikut salah.

---

## 7. Satuan — dua aturan yang BERBEDA di satu lembar

Ini yang paling gampang salah, jadi ditulis eksplisit.

| Blok | Satuan | Kenapa |
|---|---|---|
| Measurement (10 titik) | **ikut satuan alat** | dibaca pada Height Gauge-nya |
| Evaluation (10 pembacaan) | **ikut satuan alat** | dibaca pada Height Gauge-nya |
| Paralelisme (3 pembacaan) | **SELALU mm** | dibaca pada **Dial Indicator** standar (res. 0,001 mm), dan batas kelulusannya (≤ 0,01 mm) ditulis dalam mm |

Kalau paralelisme ikut diseret ke satuan alat, sesi berskala `inch` mengubah pembacaan
0,002 jadi 0,0508 mm → hasilnya 0,0359 mm → lewat batas 0,01 → kaki sertifikat mencetak
**"Not Good" palsu** untuk alat yang paralelismenya baik.

### Konversi TIDAK dilakukan di HP

Kirim **angka mentah yang diketik teknisi** apa adanya, berikut
`spesifikasi_alat.height_gauge.satuan`. Server yang mengubahnya ke mm **di tempat
pakai**.

Jangan mengonversi sebelum kirim: itu tidak idempoten. Teknisi yang menyimpan draft lalu
membukanya lagi akan mengirim balik angka yang sudah dikonversi (HP tidak punya konversi
balik), jadi tiap simpan mengalikannya 25,4 lagi. **Sudah terbukti di Micrometer** —
1 inch jadi 645,16 mm pada simpanan kedua, nol error di seluruh jalur.

Dijaga `HeightGaugeSesiTest::test_simpan_dua_kali_menghasilkan_baris_identik`.

---

## 8. Field baru di `identitas_alat`

Selain yang biasa, lembar ini punya satu yang khas:

```
spesifikasi_alat.height_gauge.kerataan_muka_ukur  → pilihan: 'baik' | 'buruk'
```

**SATU dropdown, bukan dua checkbox.** Master lab memakai dua checkbox terpisah, dan di
sesi contohnya **dua-duanya tercentang sekaligus** — tanpa satu pun sel yang memprotes.
Dua boolean yang saling meniadakan itu bentuk yang tidak bisa divalidasi.

Field lain yang perlu ada (server sudah mengirimnya di JSON):

```
spesifikasi_alat.height_gauge.satuan          pilihan: mm | inch | µm
spesifikasi_alat.height_gauge.kapasitas_mm    angka
spesifikasi_alat.height_gauge.resolusi_mm     angka
```

---

## 9. Yang bakal ditolak server (supaya tidak jadi kejutan di lapangan)

Sesi tetap **tersimpan** (201), tapi **nol baris hitungan** kalau salah satu dari ini:

1. Blok Evaluation berisi **kurang dari 2** pembacaan.
2. Sepuluh pembacaan Evaluation **semuanya sama persis** (sebaran nol → keterulangan
   hilang dari budget).
3. `resolusi_mm` kosong atau nol.

Yang **TIDAK** menahan, dan sengaja:

- **Paralelisme "Not Good"** — itu hasil ukur, bukan cacat data. Sertifikatnya tetap
  terbit dan mencetak "Not Good" apa adanya di catatan kaki.
- **Umur drift negatif** (sesi mendahului tanggal kalibrasi standar tersimpan) —
  dicatat, driftnya nol, sesi tetap terbit.

Layar sebaiknya memberi tahu teknisi sebelum kirim, terutama nomor 2 — sepuluh angka
identik itu tanda tangan "disalin", dan biasanya berarti blok Evaluation belum
benar-benar diulang.

---

## 10. Yang TIDAK perlu disentuh

- **Jalur kamera / OCR.** `bentuk_pindai_foto.didukung = false`. Kertas lembar kerjanya
  belum turun dari lab, jadi geometri di `height_gauge-v1.json` masih grid rata hasil
  generator (`terverifikasi: false`) dan belum pernah diadu ke foto formulir asli. Input
  manual dulu.
- **Nomor formulir.** `kode_dokumen` sengaja `null` — sapuan `SIDIK-FM-` di seluruh
  workbook cuma menemukan satu nomor, dan itu formulir **sertifikat** bersama, bukan
  lembar kerja.
- **Nomor lingkup akreditasi.** Sengaja tidak dikirim. Jangan menambahkannya di sisi HP.
- **Toleransi / vonis PASS-FAIL.** `punya_toleransi = false`. Masternya tidak menyebut
  satu pun batas keberterimaan per titik.

---

## 11. Klaim akreditasi — sudah dibetulkan di sisi server

Sertifikat sempat **membawa klaim akreditasi** (`Terakreditasi … No. LK-285-IDN`) untuk
alat yang tidak diakreditasi, karena klaim itu dicetak tanpa syarat di tingkat
organisasi — bukan per alat. Gas Detector sudah kena hal yang sama sejak alat ke-10.

Sejak 7 Sep 2026 klaimnya **bersyarat** (`CalibrationProfile::dalamLingkupAkreditasi()`),
dibekukan ke snapshot, dan kop banner ikut disetop untuk sesi di luar lingkup.

**Yang perlu diketahui sisi HP:** sertifikat Height Gauge & Gas Detector sekarang jatuh
ke **kop teks** (nama + alamat lab, tanpa baris akreditasi), bukan kop banner. Kalau
layar HP menampilkan preview sertifikat, jangan menambah maupun menghapus klaim itu dari
sisi HP — dia sepenuhnya ditentukan snapshot server.

Yang masih menunggu lab: sertifikat kedua alat yang **sudah terbit** tetap membawa klaim
lamanya (snapshot beku). Lihat `docs/pertanyaan-lab-height-gauge.md` §6.

---

## Lampiran — angka sesi contoh, buat mengadu tampilan

Alat contoh ter-seed: S/N `1610232804`, sesi `001-UBLK-05.26`.

| Titik | Nominal (mm) | Standard | UUT | Koreksi |
|---|---|---|---|---|
| 1 | 25 | 25,00080 | 24,98667 | 0,01413 |
| 2 | 50 | 50,00040 | 49,98667 | 0,01373 |
| 3 | 100 | 100,00050 | 99,98000 | 0,02050 |
| 4 | 150 | 150,00030 | 149,97667 | 0,02363 |
| 5 | 200 | 199,99930 | 199,98333 | 0,01597 |
| 6 | 300 | 299,99955 | 299,97667 | 0,02288 |
| 7 | 400 | 399,99820 | 399,97333 | 0,02487 |
| 8 | 500 | 499,99865 | 499,97000 | 0,02865 |
| 9 | 550 | 549,99780 | 549,96667 | 0,03113 |
| 10 | 600 | 599,99730 | 599,96333 | 0,03397 |

**U95 = ± 0,01563 mm** — satu baris untuk kesepuluh titik, lima desimal, satuan `mm`.

Paralelisme sesi: Max 0,002 · Min 0 · Hasil 0,0014142 mm · **Good**.
