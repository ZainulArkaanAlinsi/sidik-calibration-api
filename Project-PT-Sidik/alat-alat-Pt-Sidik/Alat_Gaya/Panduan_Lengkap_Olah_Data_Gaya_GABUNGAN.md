# Panduan Teknis Lengkap — Olah Data GAYA
### UTM · Load Cell · Proving Ring

Pendamping `Analisis_Gaya_UTM_LoadCell_ProvingRing.md`.
Kalau dokumen analisis menjawab **"apa rumusnya"**, dokumen ini menjawab **"kenapa begitu"**, **"apa artinya"**, dan **"bagaimana menulis kodenya"** — lengkap dengan contoh perhitungan dari angka mentah sampai angka yang tercetak di sertifikat.

Semua angka di dokumen ini asli dari file master, dan sudah diverifikasi ulang dengan mereimplementasi formula Excel di Python — nol selisih.

---

## Daftar Isi

| Bagian | Isi | Untuk siapa |
|---|---|---|
| I | Latar metrologi — kenapa alat gaya dikalibrasi begini | Semua |
| II | Apa yang sebenarnya diberitahu olah data | Semua, terutama admin |
| III | Contoh perhitungan lengkap, angka demi angka | Developer |
| IV | Data lapangan nyata vs data contoh di master | Developer, QA |
| V | Enam temuan di file master | Semua, dan lab |
| VI | Pseudocode + penanganan kasus tepi | Developer |
| VII | Model data | Developer backend |
| VIII | Aturan validasi lengkap | Developer, admin |
| IX | Alur teknisi → Master Data → sertifikat | Semua |
| X | Urutan implementasi yang disarankan | Developer |
| Lampiran | Test vector siap pakai | Developer, QA |

---

# BAGIAN I — LATAR METROLOGI

Bagian ini bukan formalitas. Developer yang paham *kenapa* tiap langkah ada akan menangkap kesalahan yang tidak tertangkap test — dan di sesi-sesi sebelumnya, justru pemahaman inilah yang menemukan cacat di master.

## 1.1 Kenapa gaya tidak bisa diukur langsung

Panjang bisa dibandingkan ke penggaris. Massa bisa dibandingkan ke anak timbangan. **Gaya tidak punya "anak timbangan gaya"** — gaya adalah interaksi, bukan benda.

Jadi kalibrasi gaya memakai cara tak langsung: **komparator load cell**. Mesin uji (UUT) menekan/menarik sebuah **load cell standar** yang sudah dikalibrasi tertelusur. Karena keduanya menerima gaya fisik yang sama persis pada saat yang sama, selisih pembacaan keduanya adalah kesalahan UUT.

```
    ┌──────────────┐
    │  UUT (mesin) │  ← membaca: 200,0 kgf   (yang di-set operator)
    └──────┬───────┘
           │ gaya fisik yang sama
    ┌──────▼───────┐
    │ Load Cell    │  ← membaca: 200,538 kgf (yang sebenarnya terjadi)
    │  STANDAR     │
    └──────────────┘

    Koreksi = 200,538 − 200,0 = +0,538 kgf
```

**Konsekuensi untuk kode:** selalu ada **dua angka per titik** — nilai UUT dan nilai standar. Model data yang cuma menyimpan satu angka per titik tidak cukup.

## 1.2 Kenapa harus empat posisi (0°/90°/180°/270°)

Ini yang paling khas dari alat gaya, dan tidak ada di alat lain yang sudah dibedah.

Mesin uji punya piringan tempat benda uji diletakkan. Kalau load cell standar diletakkan tidak tepat di sumbu tengah, atau piringannya sedikit miring, gaya tidak jatuh lurus — ada komponen **momen lentur** yang ikut terbaca.

Efeknya: hasil baca berubah tergantung ke mana load cell dihadapkan. Kalau cuma diuji satu posisi, kesalahan ini tersembunyi.

```
Posisi 0°    Posisi 90°   Posisi 180°  Posisi 270°
   ↓            ↓            ↓            ↓
 200,54      200,54       200,54       200,54     ← mesin sehat: konsisten

 200,54      201,20       200,10       199,80     ← ada misalignment
```

Makanya: **4 posisi × 3 replikat = 12 pembacaan per titik beban.** Dan makanya ada komponen ketidakpastian `Misalignment` yang tidak ada di alat lain.

Sebarannya (`MAX − MIN`) juga dilaporkan sebagai **RRPE** (Relative Repeatability Error) di sertifikat — angka yang langsung memberi tahu pelanggan seberapa konsisten mesinnya.

## 1.3 Kenapa Proving Ring berbeda sendiri

Proving Ring itu **cincin baja** yang melengkung sedikit saat dibebani. Lengkungan itu dibaca lewat dial indicator yang menempel di dalamnya.

```
    Tanpa beban          Dibebani
      ╭────╮              ╭───╮
     │      │            │     │     ← cincin memipih
     │  ⊙   │            │  ⊙  │     ⊙ = dial indicator
      ╰────╯              ╰───╯
     dial: 0             dial: 47 Div
```

Dial-nya baca **Divisi**, bukan kN. Divisi itu satuan arbitrer — tidak ada artinya sampai kita tahu **berapa kN per divisi**. Itulah yang dicari: **Calibration Factor (kN/Div)**.

Jadi output Proving Ring bukan "mesinnya meleset berapa", tapi "kalikan bacaan dial dengan angka ini untuk dapat gaya sebenarnya".

```
UTM / Load Cell:  lapor  Correction  = seberapa meleset
Proving Ring   :  lapor  Cal. Factor = pengali untuk memakai alat
```

Dan karena cincin punya **histeresis** (jalur naik ≠ jalur turun akibat sifat elastis logam), Proving Ring diuji **UP 3× dan DOWN 3×**, bukan 4 posisi.

## 1.4 Kenapa ada koreksi suhu 0,00027/°C

Load cell bekerja dengan **strain gauge** — kawat tipis yang hambatan listriknya berubah saat meregang. Hambatan itu juga berubah karena suhu, tidak cuma karena regangan.

Sertifikat kalibrasi load cell standar dibuat pada suhu tertentu (di contoh: 23,15 °C). Kalau dipakai di suhu berbeda (24,4 °C), pembacaannya bergeser sedikit. Koefisien 0,00027 per °C (= 0,027 %/°C) mengoreksi pergeseran itu.

```
Z = Y × (1 + 0,00027 × (T_sertifikat − T_aktual))
```

Di contoh: `(23,15 − 24,4) = −1,25 °C` → faktor `1 − 0,0003375` → hasilnya turun 0,034%.

**Kecil? Iya. Diabaikan? Tidak.** Pada beban 3000 kN, 0,034% = 1 kN. Itu bukan angka yang bisa dibuang.

## 1.5 Kenapa budget ketidakpastian dalam persen, bukan kN

Di alat panjang, ketidakpastian ditulis dalam mm karena kesalahannya **absolut** — micrometer meleset 2 µm ya 2 µm, tidak peduli mengukur 5 mm atau 25 mm.

Gaya tidak begitu. Kesalahan load cell bersifat **proporsional** — 0,13% dari bacaan. Pada 1 kN itu 0,0013 kN; pada 100 kN itu 0,13 kN.

Makanya seluruh budget dihitung dalam **%**, baru di akhir dikonversi:
```
U_kN = U% / 100 × rentang_ukur
```

**Konsekuensi kode:** jangan campur satuan di tengah budget. Semua komponen harus dalam % sebelum dijumlahkan. Komponen yang datangnya dalam satuan absolut (resolusi alat, misalignment dalam mm) harus dikonversi ke % dulu — itulah kenapa rumusnya kelihatan seperti `((K5/K4)*100)/2`.

---

# BAGIAN II — OLAH DATA INI SEBENARNYA MEMBERI TAHU APA?

Bagian ini menjawab pertanyaan yang sering terlewat: setelah semua rumus jalan, sertifikatnya memberi tahu apa tentang alat pelanggan?

## 2.1 Kenapa TIDAK ada verdict PASS/FAIL

Untuk alat gaya (juga Hydrometer, Dial Indicator, Caliper, Volumetric Glassware), sertifikat PT Sidik **tidak memuat kesimpulan lulus/tidak lulus.** Cuma Sieve Mesh yang punya verdict, karena punya acuan toleransi baku ASTM E11.

Ini bukan kekurangan — memang begitu seharusnya:

> **Lab kalibrasi melaporkan seberapa meleset. Pelanggan yang memutuskan apakah itu cukup baik.**

Alasannya: "cukup baik" tergantung pemakaian. Mesin uji dengan koreksi +0,9 kgf pada 200 kgf:
- Untuk uji tarik baja konstruksi (toleransi 1%) → jauh lebih dari cukup
- Untuk uji material presisi (toleransi 0,1%) → tidak memadai

Lab tidak tahu benda apa yang akan diuji pelanggan. Jadi lab melaporkan fakta, pelanggan menilai.

ISO/IEC 17025 klausul 7.8.6.1 mengatur ini: pernyataan kesesuaian hanya diberikan kalau ada aturan keputusan yang disepakati dengan pelanggan. Tanpa kesepakatan itu, lab tidak menyatakan lulus/tidak.

## 2.2 Tiga angka, tiga pertanyaan berbeda

### Correction — "alat saya meleset berapa?"

```
Titik 200 kgf → Correction +0,9 kgf
```

Artinya: waktu mesin menunjukkan 200, gaya sebenarnya 200,9. Pelanggan bisa:
- Mengoreksi hasil ujinya (tambahkan 0,9)
- Menyetel ulang mesinnya
- Menerima apa adanya kalau masih dalam toleransi mereka

### U95% — "seberapa yakin angka koreksi itu?"

```
U95% = ± 2,1 kgf,  k = 2
```

Artinya: koreksi sebenarnya ada di antara `0,9 − 2,1` dan `0,9 + 2,1`, dengan tingkat kepercayaan 95%.

**Ini yang paling sering disalahpahami.** U95% **lebih besar** dari Correction di contoh ini (2,1 vs 0,9). Artinya: koreksinya sendiri tidak signifikan secara statistik — ketidakpastian pengukuran lebih besar daripada kesalahan yang terukur.

Itu informasi berguna: mesinnya sebenarnya bagus, dan angka koreksi 0,9 itu kemungkinan besar cuma noise.

### RRPE — "alat saya konsisten tidak?"

```
RRPE = 0,03 %
```

Ini yang paling dilewatkan orang, padahal paling memberi tahu soal **kesehatan mekanis** mesin.

Correction mengukur *bias* (meleset ke satu arah, bisa dikoreksi). RRPE mengukur *sebaran* (hasil berbeda-beda tiap diukur — tidak bisa dikoreksi).

```
Correction besar, RRPE kecil  → mesin meleset tapi konsisten
                                 → bisa disetel, masih berguna

Correction kecil, RRPE besar  → rata-ratanya pas tapi acak
                                 → ADA MASALAH MEKANIS
                                 → tidak bisa diperbaiki dengan penyetelan
```

Yang kedua jauh lebih serius, dan cuma ketahuan kalau diuji 4 posisi × 3 replikat. Itulah gunanya pengujian yang kelihatan berlebihan itu.

## 2.3 Status alat yang BISA disimpulkan sistem — tanpa memvonis

Meski sertifikat tidak memberi verdict, **sistem internal bisa dan sebaiknya menampilkan indikator** ke Master Data dan Super Admin. Bedanya: ini untuk **pengambilan keputusan internal lab**, bukan pernyataan di sertifikat.

| Indikator | Cara hitung | Artinya |
|---|---|---|
| **Bias sistematis** | semua Correction searah (semua + atau semua −) | Mesin perlu disetel ulang, bukan rusak |
| **Bias acak** | Correction berganti tanda antar titik | Kemungkinan masalah linearitas |
| **Repeatability buruk** | RRPE > 1% di satu titik atau lebih | Masalah mekanis — layak diberi tahu pelanggan |
| **Zero drift** | zero error ≠ 0 setelah preload | Offset; perlu di-nol-kan ulang |
| **Koreksi > U95%** | \|Correction\| > U95% | Kesalahan signifikan secara statistik |
| **Koreksi < U95%** | \|Correction\| ≤ U95% | Kesalahan tidak signifikan — mesin sehat |
| **Non-linear** | Correction tidak proporsional terhadap beban | Load cell/mekanisme mungkin bermasalah |

Ini yang membuat olah data **berguna**, bukan cuma menghasilkan angka. Master Data bisa melihat sekilas: "sesi ini semua koreksinya positif dan naik proporsional — mesinnya perlu kalibrasi ulang faktor skalanya."

## 2.4 Rancangan tampilan yang disarankan

**Untuk teknisi — saat mengisi:** pratinjau langsung, supaya salah ketik ketahuan sebelum dikirim.
```
Titik 200 kgf
  12 bacaan   : 200,538 … 200,600      sebaran 0,062
  Rata-rata   : 200,545 kgf
  Koreksi     : +0,9 kgf
  RRPE        : 0,03 %      ✓ konsisten
```

**Untuk Master Data — saat memeriksa:** ringkasan status, bukan cuma tabel angka.
```
┌─ Ringkasan Sesi ─────────────────────────────────┐
│ Pola koreksi   : semua positif, naik proporsional │
│                  → indikasi bias skala            │
│ RRPE tertinggi : 0,03 % (titik 200)    ✓          │
│ Zero error     : 0,0                   ✓          │
│ Signifikansi   : semua koreksi < U95%             │
│                  → kesalahan tidak signifikan     │
│                                                    │
│ ⚠ 2 titik punya 12 pembacaan identik              │
│   (100 dan 500 kgf) — mohon konfirmasi             │
└───────────────────────────────────────────────────┘
```

**Untuk pelanggan — di aplikasi:** bahasa awam, tanpa istilah lab.
```
Mesin Uji Tarik — Hung Ta 1300
Terkalibrasi 18 Mar 2024

Hasilnya: mesin membaca sedikit lebih rendah dari gaya
sebenarnya, rata-rata 0,4% di seluruh rentang.

Konsistensi: sangat baik (variasi < 0,05%)

[Lihat tabel lengkap]  [Unduh sertifikat PDF]
```

**Yang tidak boleh muncul di sisi pelanggan:** kata "lulus", "layak pakai", "memenuhi syarat". Itu pernyataan kesesuaian, dan butuh aturan keputusan yang disepakati (klausul 7.8.6.1). Sistem menyajikan fakta; pelanggan menilai.

## 2.5 Kenapa akurasi olah data ini penting — inti persoalannya

Pelanggan memakai angka Correction untuk **mengoreksi hasil uji mereka sendiri**. Kalau mesin uji mereka dipakai menguji baja konstruksi, dan koreksinya salah 1%, maka:

```
Baja diuji     : "kuat tarik 400 MPa"
Sebenarnya     : 396 MPa
Dipakai di     : struktur bangunan
```

Kesalahan di olah data **tidak berhenti di kertas sertifikat.** Dia merambat ke keputusan teknik pelanggan, ke bangunan, ke produk. Itu alasan sebenarnya kenapa satu digit pun penting — bukan karena KAN akan menegur, tapi karena ada rantai keputusan di ujung sana.

Dan itu juga alasan kenapa enam temuan di Bagian V harus dibawa ke lab, bukan diperbaiki diam-diam oleh developer.

---

# BAGIAN III — CONTOH PERHITUNGAN LENGKAP

Satu sesi nyata dari master UTM, dari angka mentah sampai angka sertifikat.

## 3.1 Yang diisi teknisi

```
IDENTITAS
  Nama Alat        : Universal Testing Machine
  Merk             : Hung Ta
  No. Seri         : 1300
  Rentang Ukur     : 500           satuan index 4 → kgf
  Kapasitas Max    : 500 kgf
  Resolusi Alat    : 0,1 kgf
  Tipe Beban       : index 2 → Pull

KONDISI LINGKUNGAN
  Suhu Ruangan     : awal 24,5 °C   akhir 24,3 °C
  Kelembaban       : awal 55 %RH    akhir 54 %RH
  Thermohygro      : index 3 → TH-3

STANDAR
  Standar dipakai  : index 3 → Load Cell 5 kN
  Suhu std saat sertifikat : 23,15 °C
  Suhu std saat kalibrasi  : (otomatis = rata-rata suhu ruangan) 24,4 °C

PRELOAD TEST (3 replikat)
  Zero          : 0      | 0      | 0
  Max Capacity  : 999,1  | 998,9  | 999,1

ACCURACY TEST — 6 titik beban × 12 pembacaan
  Set    │ 0°(1,2,3)              │ 90°(1,2,3)             │ 180°(1,2,3)            │ 270°(1,2,3)
  ───────┼────────────────────────┼────────────────────────┼────────────────────────┼─────────────────
     0   │ 0      0      0        │ 0      0      0        │ 0      0      0        │ 0     0     0
   100   │ 100,16 100,16 100,16   │ 100,16 100,16 100,16   │ 100,16 100,16 100,16   │ 100,16 ...
   200   │ 200,538 200,6 200,538  │ 200,538 ×3             │ 200,538 ×3             │ 200,538 ×3
   300   │ 300,3006 ×3            │ 300,2  300,3006 ×2     │ 300,3006 ×3            │ 300,3006 ×3
   400   │ 399,939 399,9 399,939  │ 399,939 ×3             │ 399,939 ×3             │ 399,939 ×3
   500   │ 500,16 ×3              │ 500,16 ×3              │ 500,16 ×3              │ 500,16 ×3
```

> Data contoh ini sangat seragam dan **tidak mewakili data lapangan**. Kenapa itu berbahaya untuk pengujian kode, lihat Bagian IV.

## 3.2 Langkah 1 — Rata-rata kondisi lingkungan

```
T_ruangan = (24,5 + 24,3) / 2 = 24,4 °C
RH        = (55 + 54) / 2     = 54,5 %RH
```

Nilai ini langsung jadi "suhu load cell standar saat kalibrasi" (`K7`) — diasumsikan load cell sudah menyesuaikan dengan suhu ruangan.

## 3.3 Langkah 2 — Koreksi pembacaan thermohygro

Thermohygro juga alat ukur, jadi punya sertifikat kalibrasi sendiri. Bacaannya harus dikoreksi sebelum dipakai.

```
Suhu terbaca      : 24,4 °C
Index nearest     : 19,83     ← titik kalibrasi TH-3 yang terdekat
Koreksi (C)       : −0,43 °C
Δ (sebaran awal-akhir) : |24,3 − 24,5| = 0,2 °C
U95% std TH       : 1,7 °C
U95% dilaporkan   : √(0,2² + 1,7²) = 1,7117242768623688 °C
```

Yang dicetak di sertifikat: `24,4 + (−0,43) = 23,97 °C ± 1,7 °C`

Pola yang sama untuk kelembaban:
```
54,5 %RH + (−2,55) = 51,95 %RH ± 4,903060268852505 %RH
```

**Perhatikan:** lookup indexnya **nearest-match**, bukan interpolasi.

## 3.4 Langkah 3 — Konversi satuan ke kN

Faktor kgf → kN = **0,00981** (dari `Tabel_Satuan`)

```
Nominal  200 kgf × 0,00981 = 1,9619999999999997 kN
```

Semua 12 pembacaan juga dikonversi:
```
200,538 × 0,00981 = 1,96727778 kN
200,600 × 0,00981 = 1,96788600 kN
```

## 3.5 Langkah 4 — Statistik per titik

```
R (rata-rata 12 bacaan)  = 1,9673284649999998 kN
S (STDEV 12 bacaan)      = 0,00017557799036323893 kN
T (RSD = S/R × 100)      = 0,00892...%
AB (RRPE = (MAX−MIN)/B × 100)
   MAX = 200,600 × 0,00981 = 1,967886
   MIN = 200,538 × 0,00981 = 1,96727778
   AB = (1,967886 − 1,96727778) / 1,962 × 100 = 0,0309999999999968 %
```

**RRPE vs RSD — jangan tertukar.** RSD memakai simpangan baku (sebaran statistik). RRPE memakai rentang penuh (MAX−MIN) dibagi nominal. Dua-duanya dihitung, tapi **yang dicetak di sertifikat adalah RRPE**, sementara **yang masuk budget ketidakpastian adalah RSD MAX**.

## 3.6 Langkah 5 — Koreksi standar (nearest-match)

Tabel `standar_5kN_Tarik` berisi sertifikat kalibrasi load cell 5 kN arah tarik. Cari set point terdekat dengan 1,962 kN, ambil koreksinya:

```
Koreksi W = 0,0030400615 kN
```

Algoritmanya:
```
index = argmin( |set_point_tabel − nilai_nominal| )
W = tabel[index].koreksi
```

**Bukan interpolasi linier.** Ini penting — implementasi dengan interpolasi akan menghasilkan angka berbeda dan test rekonsiliasi akan gagal.

## 3.7 Langkah 6 — Standar terkoreksi

```
Y = R + W
  = 1,9673284649999998 + 0,0030400615
  = 1,9703685264999997 kN
```

## 3.8 Langkah 7 — Koreksi termal

```
Z = Y × (1 + 0,00027 × (T_std_sertifikat − T_std_aktual))
  = 1,9703685264999997 × (1 + 0,00027 × (23,15 − 24,4))
  = 1,9703685264999997 × (1 − 0,0003375)
  = 1,969703527122306 kN
```

## 3.9 Langkah 8 — Correction

```
AA = Y − B
   = 1,9703685264999997 − 1,9619999999999997
   = 0,008368526499999973 kN
```

> ⚠️ **Perhatikan baik-baik:** Correction memakai **Y** (sebelum koreksi termal), tapi sertifikat mencetak **Z** (sesudah koreksi termal) di kolom "Standard Value".
>
> Artinya pembaca sertifikat yang menghitung `Standard Value − Unit Under Test` **tidak akan mendapat angka yang tercetak di kolom Correction**:
> ```
> Yang tercetak:  Z = 1,9697035   UUT = 1,9620000   Correction = 0,0083685
> Kalau dihitung: 1,9697035 − 1,9620000 = 0,0077035  ← beda!
> ```
> Selisihnya 0,00066 kN. Ini masuk daftar pertanyaan lab — replikasi apa adanya dulu, jangan "dibetulkan" sendiri.

## 3.10 Langkah 9 — Budget ketidakpastian (8 komponen, dalam %)

Dihitung sekali per sesi (bukan per titik), memakai nilai agregat.

| # | Komponen | U (%) | Divisor | ui (%) | vi | (uici)² |
|---|---|---|---|---|---|---|
| 1 | Sertifikat kalibrator | 0,4 | 2 | 0,2 | 200 | 0,04 |
| 2 | Daya baca UUT | 0,01 | √3 | 0,005773502691896258 | 10⁶ | 3,3333e-05 |
| 3 | Daya baca standar | 0,000981 | √3 | 0,0005663806140750228 | 10⁶ | 3,2079e-07 |
| 4 | Temperature | 0,027 | √3 | 0,015588457268119896 | 50 | 0,000243 |
| 5 | **Drift standar** | 0,0692820323027551 | √3 | **0,0692820323027551** | 50 | 0,0048 |
| 6 | Pengulangan | 0,0037644665166114773 | 1 | 0,0037644665166114773 | 11 | 1,4171e-05 |
| 7 | Zero error | 0 | √3 | 0 | 10⁶ | 0 |
| 8 | Misalignment | 0,021028966278987093 | √3 | 0,012141079341952762 | 50 | 0,00014740580758759213 |

> Baris 5 sengaja ditebalkan: `ui = U`, tidak dibagi divisornya. Itu anomali — lihat Bagian V §5.2.

Asal-usul tiap komponen:

```
(1) dari sertifikat load cell standar: U95% = 0,4% reading, k=2 → u = 0,2%
(2) resolusi UUT / rentang: ((0,000981 / 4,905) × 100) / 2 = 0,01%
(3) resolusi standar / kapasitas std: ((0,0000981 / 5) × 100) / 2 = 0,000981%
(4) konstanta metode 0,027%
(5) dari Tabel_Drift, per standar
(6) RSD MAX / √12 = 0,013040494540325815 / 3,4641 = 0,0037644665166114773%
(7) max zero error / max test × 100 = 0 / 4,905 × 100 = 0%
(8) STDEV misalignment / rata-rata × 100
    = 0,001732050807568772 / 8,2365 × 100 = 0,021028966278987093%
```

Penggabungan:
```
Σ(uici)²       = 0,04523823113607563
uc             = √0,04523823113607563 = 0,21269280931915782 %
Σ(uici)⁴/vi    = 8,46243380720257e-06
v_eff          = uc⁴ / Σ((uici)⁴/vi) = 241,8...
k              = TINV(0,05; v_eff) = 1,9698562125960952
U              = k × uc = 0,4189742518118597 %
U_kN           = 0,4189742518118597 / 100 × 4,905 = 0,020550687051371717 kN
CMC            = 0,020600999999999998 kN
U95% sertifikat= MAX(U_kN, CMC) = 0,020600999999999998 kN   ← CMC menang tipis
```

## 3.11 Yang tercetak di sertifikat

```
Type of Force : Pull

Standard Value │ Unit Under Test │ Correction │ RRPE
    (kgf)      │      (kgf)      │   (kgf)    │  (%)
───────────────┼─────────────────┼────────────┼───────
     0,0       │       0,0       │    0,0     │   −
   100,4       │     100,0       │    0,4     │  0,00
   200,8       │     200,0       │    0,9     │  0,03
   300,7       │     300,0       │    0,8     │  0,03
   400,4       │     400,0       │    0,5     │  0,01
   500,5       │     500,0       │    0,7     │  0,00

Uncertainty U95% = ± 2,1 kgf
k = 2
Env. Condition: T 23,97 °C ± 1,7 °C   %RH 51,95 % ± 4,9 %
```

Angka kN dikonversi balik ke kgf (`/0,00981`), dan `k = 1,9698...` dicetak dibulatkan jadi **2**.

---

# BAGIAN IV — DATA LAPANGAN NYATA vs DATA CONTOH

Angka dari teknisi itu hasil baca alat nyata di lapangan — tidak seragam, tidak bisa ditebak. Bagian ini soal apa yang rusak kalau kode cuma diuji dengan data contoh.

## 4.1 Data contoh di master itu menyesatkan — ini buktinya

Sebaran 12 pembacaan tiap titik di sesi contoh UTM:

| Titik | n | Nilai unik yang muncul | STDEV |
|---|---|---|---|
| 100 kgf | 12 | **[100,16]** — satu nilai saja | **0,0** |
| 200 kgf | 12 | [200,538 ; 200,6] — dua nilai | 0,0179 |
| 300 kgf | 12 | [300,2 ; 300,3006] — dua nilai | 0,0392 |
| 400 kgf | 12 | [399,9 ; 399,939] — dua nilai | 0,0113 |
| 500 kgf | 12 | **[500,16]** — satu nilai saja | **0,0** |

Dua dari lima titik punya **12 pembacaan identik sampai digit terakhir**. Tiga lainnya cuma punya dua nilai berbeda di antara 12 bacaan.

**Mesin uji nyata tidak pernah berperilaku begitu.** Load cell punya noise elektronik, mesin punya gesekan mekanis, operator membaca pada momen sedikit berbeda. Dua belas pembacaan nyata akan punya dua belas angka berbeda — biasanya berbeda di digit ke-3 atau ke-4.

Data contoh itu jelas **template isian, bukan rekaman pengukuran nyata.**

## 4.2 Kenapa ini berbahaya untuk pengujian kode

Kalau test rekonsiliasi cuma memakai data contoh ini, **beberapa cabang kode tidak pernah dijalankan**:

| Yang tidak teruji | Kenapa lolos di data contoh |
|---|---|
| STDEV dengan sebaran nyata | Dua titik ber-STDEV nol — jalur "nilai kecil tapi bukan nol" tidak terlewati |
| RSD dengan pembilang nyata | RSD ikut nol di titik-titik itu |
| Komponen pengulangan di budget | RSD MAX diambil dari titik lain, jadi kebetulan tetap terisi |
| Nearest-match di antara dua set point | Semua nominal contoh kebetulan jatuh persis/dekat set point tabel |
| Pembulatan di batas | Angka contoh jauh dari batas pembulatan |
| Urutan MAX/MIN saat banyak nilai berbeda | Cuma ada dua nilai unik, jadi MAX/MIN sepele |

Artinya: **test bisa hijau semua, lalu jebol di sesi nyata pertama.**

**Yang harus dilakukan:** test rekonsiliasi terhadap data contoh tetap wajib — itu yang membuktikan rumusnya benar. Tapi harus ditambah test data sintetis yang menyerupai lapangan (12 nilai semuanya berbeda, sebaran realistis). Yang satu membuktikan rumusnya benar, yang satu membuktikan kodenya tidak pecah saat data tidak rapi.

## 4.3 🔴 Pembagian nol di titik nol — master menghindarinya dengan hardcode

Baris titik nol (`r30`) **tidak memakai rumus** untuk dua kolom:

```
r30 (titik 0):   T (RSD)  = 0        ← angka mati, BUKAN rumus
                 AB (RRPE) = "-"     ← teks mati, BUKAN rumus

r31 (titik 100): T (RSD)  = IF(ISERROR((S31/R31)*100), "", (S31/R31)*100)
                 AB (RRPE) = IF(ISERROR(((MAX−MIN)/B31)*100), "", ...)
```

Kenapa? Karena di titik nol:
```
RSD  = S / R × 100   → R = 0  → pembagian nol
RRPE = (MAX−MIN)/B   → B = 0  → pembagian nol
```

**Konsekuensi untuk kode — dan ini gampang terlewat:**

```
SALAH:
    rsd = (stdev / rata_rata) * 100        ← meledak di titik nol

JUGA SALAH:
    rsd = (stdev / rata_rata) * 100 if rata_rata else 0
    # kelihatan aman, tapi menyamakan "titik nol" dengan
    # "alat rusak sehingga baca nol di beban 500 kgf"

BENAR:
    kalau nominal == 0:
        rsd  = 0         # titik nol memang tidak punya RSD
        rrpe = null      # tampilkan "-" di sertifikat
    kalau nominal != 0 dan rata_rata == 0:
        # alat baca nol padahal dibebani → ini TEMUAN, bukan
        # kasus yang perlu dibulatkan diam-diam jadi nol
        lempar / tandai: "Pembacaan nol pada beban 500 kgf"
    lainnya:
        rsd = (stdev / rata_rata) * 100
```

Perbedaan dua kasus itu penting. Yang pertama normal. Yang kedua berarti alatnya bermasalah atau teknisi salah isi — dan itu justru informasi yang harus sampai ke Master Data, bukan disembunyikan dengan `?: 0`.

## 4.4 STDEV nol — normal di data contoh, mencurigakan di lapangan

Kalau 12 pembacaan nyata semuanya identik, itu **bukan tanda mesin sempurna** — itu tanda salah satu dari:

- Teknisi menyalin satu angka ke semua kolom (paling sering)
- Display alat macet / tidak ter-refresh
- Data diketik ulang dari catatan yang sudah dirata-ratakan duluan

Sistem tidak bisa tahu yang mana, tapi **bisa menandainya sebagai peringatan** ke Master Data:

```
Peringatan: 12 pembacaan pada titik 500 kgf semuanya identik (500,16).
Mesin uji nyata biasanya bervariasi di digit terakhir.
Mohon konfirmasi data sudah benar sebelum menyetujui.
```

Peringatan, **bukan blok** — karena ada alat berdisplay kasar yang memang menampilkan angka sama. Tapi Master Data perlu tahu supaya bisa memutuskan.

## 4.5 Nearest-match di antara dua set point

Data contoh kebetulan jatuh dekat set point tabel. Data lapangan tidak selalu.

Tabel koreksi standar (contoh, Load Cell 5 kN):
```
set point: 1000, 2000, 3000, 4000, 5000, 6000, ... kg
koreksi  :  4,6   3,6   3,5   3,5   0,4  −2,5  ... kg
```

Kalau teknisi menguji di **2500 kg** — persis di tengah antara 2000 dan 3000:

```
|2500 − 2000| = 500
|2500 − 3000| = 500      ← SERI
```

Excel `MATCH(MIN(...))` akan mengambil **kemunculan pertama** dari nilai minimum, yaitu baris 2000 (koreksi 3,6), bukan 3000 (koreksi 3,5).

**Kode harus meniru aturan tie-break yang sama: ambil yang pertama ditemui dalam urutan tabel.** Kalau implementasi memakai `min()` bawaan bahasa yang mungkin mengambil yang terakhir, hasilnya berbeda — dan bedanya baru ketahuan di sesi yang kebetulan seri.

## 4.6 Beban di luar rentang tabel

Kalau teknisi menguji **9500 kg** sementara tabel berhenti di 9000, nearest-match tetap memberi jawaban — ambil 9000, koreksi −7. **Tidak ada error, tidak ada peringatan.** Padahal itu ekstrapolasi di luar data kalibrasi standar.

Master tidak menjaga ini. **Kode harus:**

```
kalau nilai < min(set_point) atau nilai > max(set_point):
    tandai peringatan:
    "Beban 9500 kg di luar rentang kalibrasi standar (1000–9000 kg).
     Koreksi diambil dari titik terdekat (9000 kg) — bukan interpolasi
     maupun ekstrapolasi tervalidasi."
```

Peringatan, bukan blok — keputusan boleh-tidaknya ada di Master Data. Tapi harus **terlihat**.

## 4.7 Ringkasan kasus tepi

| Kasus | Yang terjadi di master | Yang harus dilakukan kode |
|---|---|---|
| Titik nominal = 0 | RSD & RRPE di-hardcode | Bedakan dari "alat baca nol saat dibebani" |
| Rata-rata = 0 di titik non-nol | `ISERROR` → string kosong | Tandai sebagai temuan, jangan diam |
| STDEV = 0 di semua titik | Lolos tanpa tanda | Peringatan "pembacaan identik" |
| Nilai seri antara 2 set point | Ambil yang pertama | Tiru aturan yang sama, test-kan |
| Beban di luar rentang tabel | Diam-diam ambil terdekat | Peringatan ekstrapolasi |
| Satuan belum dipilih | String `"PILIH SATUAN"` masuk hasil | Error eksplisit sebelum hitung |
| Pembacaan negatif | Tidak dijaga | Peringatan (mungkin salah tanda) |
| Nominal tidak naik monoton | Tidak dijaga | Peringatan urutan titik |

---

# BAGIAN V — ENAM TEMUAN DI FILE MASTER

## 5.1 🔴 Budget Proving Ring menjumlahkan hanya 6 dari 8 komponen

```
UTM         : AC17 = SUM(AC9:AC16)   ← 8 komponen, benar
Load Cell   : AC17 = SUM(AC9:AC16)   ← 8 komponen, benar
Proving Ring: AC17 = SUM(AC9:AC14)   ← 6 komponen ✗
```

Baris 15 (Pengaruh Zero Error) dan 16 (Misalignment) **dihitung lengkap** — punya nilai `u`, divisor, `ci`, dan `(uici)²` — tapi **tidak ikut dijumlahkan**. Jumlah derajat kebebasan (`AG17`) juga sama-sama terpotong.

Dampak terukur:
```
Σ(uici)² baris 9–14 = 0,047032374   ← yang dipakai
Σ(uici)² baris 9–16 = 0,048801244   ← yang seharusnya
uc terpakai   = 0,216869
uc seharusnya = 0,220910            ← understatement ~1,9%
```

Komponen misalignment punya nilai nyata (0,00177), jadi ini bukan kasus "nilainya nol jadi tidak berpengaruh".

Di sesi contoh angka cetaknya tidak berubah karena CMC yang menang. Tapi begitu ada sesi dengan U hitung di atas CMC, U95% yang terbit akan **lebih kecil dari yang seharusnya** — arah yang berbahaya, karena sertifikat terlihat lebih presisi dari kenyataan.

## 5.2 🟠 Komponen Drift tidak dibagi divisornya — di KETIGA file

```
r9  : ui = N9/Q9     ✓
r10 : ui = N10/Q10   ✓
r11 : ui = N11/Q11   ✓
r12 : ui = N12/Q12   ✓
r13 : ui = N13       ✗  ← divisor √3 tertulis di kolomnya, tapi TIDAK dipakai
r14 : ui = N14/Q14   ✓
r15 : ui = N15/Q15   ✓
r16 : ui = N16/Q16   ✓
```

Baris 13 (**Drift Standard**) satu-satunya yang mengambil nilai U langsung jadi ui, tanpa dibagi divisor — padahal kolom Divisor-nya berisi √3 seperti tetangganya.

Dampaknya: kontribusi drift **√3 kali lebih besar** dari kalau divisornya dipakai (0,0693 vs 0,0400). Itu komponen terbesar kedua setelah sertifikat kalibrator.

Ada dua kemungkinan penjelasan, dan **saya tidak bisa memastikan yang mana tanpa lab:**

1. **Nilai di `Tabel_Drift` sudah pre-multiplied.** Nilai UTM = 0,0692820323027551 = **0,04 × √3 persis**. Tapi hipotesis ini tidak sepenuhnya menjelaskan, karena `drift × √3` tetap bukan `drift/√3` yang biasanya dimaksud.
2. **Formulanya memang kelupaan dibagi.** Kolom divisor diisi tapi tidak dipakai.

Dua nilai drift lainnya (0,031834452342817454 dan 0,07428573463572993) **bukan** kelipatan √3 dari angka bulat, jadi pola "pre-multiplied" tidak konsisten di seluruh tabel.

**Replikasi apa adanya, tulis catatan audit, tanyakan ke lab. Jangan dibagi √3 sendiri** — itu mengubah U95% yang terbit.

## 5.3 Empat temuan lainnya

| # | Temuan | File | Sifat | Dampak |
|---|---|---|---|---|
| 3 | Correction pakai Y, sertifikat cetak Z | UTM, Load Cell | 🟡 Konfirmasi | Kolom tidak cocok bila dihitung manual (selisih 0,00066 kN di contoh) |
| 4 | Koreksi suhu ganda — `G52` (vs 23 °C) dan koreksi termal (vs suhu sertifikat) | Proving Ring | 🟡 Konfirmasi | Belum diukur |
| 5 | Cabang CMC tidak lengkap: batas 2000 vs 3000 kN, dan hilangnya cabang Pull 5–88 kN | Load Cell | 🟡 Konfirmasi | Alat tarik di rentang itu → `"cek range"` |
| 6 | Data Misalignment: per-alat atau properti mesin uji lab? | Ketiganya | 🟡 Konfirmasi | Menentukan bentuk form input |

## 5.4 Yang diperiksa dan ternyata wajar

Nilai `vi` = 1.000.000 pada komponen daya baca itu praktik umum untuk komponen yang dianggap punya derajat kebebasan tak hingga — bukan kesalahan ketik.

---

# BAGIAN VI — PSEUDOCODE

Kerangka supaya Claude Code tidak perlu menebak arsitekturnya.

## 6.1 Fungsi inti bersama

```
fungsi konversiKeKN(nilai, satuan):
    faktor = { kN:1, N:0.001, lbf:0.00445, kgf:0.00981, tnf:9.8067 }
    kalau satuan tidak ada di faktor:
        lempar error "satuan wajib dipilih"     ← master balas "PILIH SATUAN"
    kembalikan nilai × faktor[satuan]


fungsi cariKoreksiStandar(nilai_kN, tabel):
    # NEAREST-MATCH, bukan interpolasi
    # tie-break: ambil indeks TERKECIL (meniru MATCH Excel) — lihat §4.5
    terbaik = 0
    untuk i dari 1 sampai panjang(tabel)-1:
        kalau |tabel[i].set_point − nilai_kN| < |tabel[terbaik].set_point − nilai_kN|:
            terbaik = i          # strictly less-than, bukan <=

    kalau nilai_kN di luar [min(set_point), max(set_point)]:
        tandaiPeringatan("ekstrapolasi di luar rentang tabel standar")

    kembalikan tabel[terbaik].koreksi_kN


fungsi pilihTabelStandar(standar, tipe_beban):
    peta = {
      ("100kN","Push") : "Standar_100kN_tekan",
      ("100kN","Pull") : "standar_100kN_tarik",
      ("5kN","Push")   : "standar_5kN_Tekan",
      ("5kN","Pull")   : "standar_5kN_Tarik",
      ("3000kN","Push"): "standar_3000kN",
      # ("3000kN","Pull") TIDAK ADA
    }
    kalau kombinasi tidak ada di peta:
        lempar error jelas, sebut kombinasinya    ← jangan diam-diam kosong
    kembalikan peta[(standar, tipe_beban)]


fungsi koreksiTermal(nilai, T_sertifikat, T_aktual):
    KOEF = 0.00027
    kembalikan nilai × (1 + KOEF × (T_sertifikat − T_aktual))


fungsi hitungRSD(stdev, rata_rata, nominal):
    # lihat §4.3 — tiga kasus berbeda, jangan disamakan
    kalau nominal == 0:
        kembalikan 0                    # titik nol memang tidak punya RSD
    kalau rata_rata == 0:
        tandaiTemuan("pembacaan nol pada beban non-nol")
        kembalikan null
    kembalikan (stdev / rata_rata) × 100


fungsi hitungRRPE(bacaan, nominal):
    kalau nominal == 0:
        kembalikan null                 # dicetak "−" di sertifikat
    kembalikan ((max(bacaan) − min(bacaan)) / nominal) × 100
```

## 6.2 Alur UTM / Load Cell

```
untuk tiap titik_beban:
    B = konversiKeKN(nominal_uut, satuan)

    bacaan_kN = [konversiKeKN(x, satuan) untuk tiap x di 12_bacaan]
    R  = rata_rata(bacaan_kN)
    S  = stdev(bacaan_kN)
    T  = hitungRSD(S, R, B)
    AB = hitungRRPE(bacaan_kN, B)

    tabel = pilihTabelStandar(standar, tipe_beban)
    W = cariKoreksiStandar(B, tabel)

    Y  = R + W
    Z  = koreksiTermal(Y, T_std_sertifikat, T_std_aktual)
    AA = Y − B                    # ← Y, BUKAN Z. lihat §3.9

    periksaPolaData(bacaan_kN)    # peringatan §8.2
    simpan(titik, {B, R, S, T, W, Y, Z, AA, AB})

# budget dihitung SEKALI per sesi, bukan per titik
RSD_MAX  = max(T dari semua titik yang tidak null)
zero_err = max zero error dari preload
budget   = hitungBudget(RSD_MAX, zero_err, misalignment, standar, ...)
```

## 6.3 Alur Proving Ring

```
G52 = 1 + 0.00027 × (23 − T_ruangan)     # suhu acuan 23 °C, bukan 20

untuk tiap titik_beban:
    B = konversiKeKN(set_point_standar, satuan)

    bacaan_div = 6 bacaan dial (UP 3 + DOWN 3)   # TIDAK dikonversi satuan
    J = rata_rata(bacaan_div) × G52
    K = stdev(bacaan_div)
    L = hitungRSD(K, J, B)

    W = cariKoreksiStandar(B, tabel_standar)
    Y = (B + W) × G52
    Z = koreksiTermal(Y, T_std_sertifikat, T_std_aktual)   # koreksi KEDUA
    AA = faktor_kalibrasi(Y, J)                            # kN per Div

hasil_utama = rata_rata(AA dari semua titik)   # Mean Calibration Factor
```

## 6.4 Budget ketidakpastian

```
fungsi hitungBudget(...):
    komponen = [
      { nama:"sertifikat_kalibrator", U: U95_std,          divisor: 2,  vi: 200 },
      { nama:"daya_baca_uut",         U: ((res_uut/rentang)*100)/2,
                                                            divisor: √3, vi: 1e6 },
      { nama:"daya_baca_standar",     U: ((res_std/kap_std)*100)/2,
                                                            divisor: √3, vi: 1e6 },
      { nama:"temperature",           U: 0.027,             divisor: √3, vi: 50 },
      { nama:"drift_standar",         U: drift_dari_tabel,  divisor: √3, vi: 50,
                                      catatan: "MASTER TIDAK MEMBAGI DIVISOR — §5.2" },
      { nama:"pengulangan",           U: RSD_MAX/√12,       divisor: 1,  vi: 11 },
      { nama:"zero_error",            U: (maxZero/maxTest)*100,
                                                            divisor: √3, vi: 1e6 },
      { nama:"misalignment",          U: (stdev_mis/avg_mis)*100,
                                                            divisor: √3, vi: 50 },
    ]

    untuk tiap k di komponen:
        kalau k.nama == "drift_standar":
            k.ui = k.U                  # replikasi master apa adanya, §5.2
        lainnya:
            k.ui = k.U / k.divisor
        k.uici = k.ui × k.ci            # ci = 1 untuk semua di UTM/LoadCell
        k.uici2 = k.uici²
        k.uici4_vi = (k.uici²)² / k.vi

    # PENTING: jumlahkan KEDELAPAN komponen.
    # Master Proving Ring cuma menjumlah 6 — itu cacat, §5.1
    uc    = √( Σ k.uici2 )
    v_eff = uc⁴ / Σ k.uici4_vi
    k_fak = t_inverse(0.05, v_eff)      # BUKAN konstanta 2
    U_pct = uc × k_fak
    U_kN  = U_pct / 100 × rentang_kN

    CMC   = pilihCMC(tipe_beban, rentang_kN)
    kembalikan MAX(U_kN, CMC)
```

---

# BAGIAN VII — MODEL DATA

## 7.1 Tabel baru

```
gaya_titik_beban
  id
  calibration_session_id
  urutan                    # 1..14 (UTM/LC) atau 1..16 (PR)
  nominal_input             decimal — apa adanya dari teknisi
  nominal_satuan            enum: kN|N|lbf|kgf|tnf
  nominal_kn                decimal — hasil konversi, disimpan
  index

gaya_pembacaan
  id
  gaya_titik_beban_id
  posisi                    enum: 0|90|180|270   (UTM/LC)
                            enum: up|down        (Proving Ring)
  replikat                  1|2|3
  nilai_input               decimal
  nilai_kn                  decimal — null untuk Proving Ring (Divisi)
  nilai_div                 decimal — hanya Proving Ring
  ditandai_outlier          boolean — lihat §8.3; TIDAK pernah dihapus
  index(gaya_titik_beban_id, posisi, replikat)

gaya_misalignment
  id
  calibration_session_id
  urutan                    1..4
  nilai_mm                  decimal
  # kalau lab bilang ini properti mesin uji (bukan per-alat),
  # pindahkan jadi tabel referensi, bukan per-sesi. Lihat §5.3 butir 6
```

## 7.2 Yang disimpan sebagai hasil

Ikut pola yang sudah ada (`uncertainty_calculations` dkk), plus per titik:
```
  R_kn, S_kn, RSD_persen, W_koreksi_std_kn,
  Y_terkoreksi_kn, Z_terkoreksi_termal_kn,
  AA_correction_kn, AB_rrpe_persen
```

Dan per sesi:
```
  uc_persen, v_eff, k_faktor, U_persen, U_kn, cmc_kn, u95_sertifikat_kn
  formula_version_id        ← wajib, aturan AGENTS.md
```

**Simpan hasilnya, jangan dihitung ulang saat dibaca.** Ini sudah jadi aturan repo (§Jalur angka) — sertifikat lima tahun lalu wajib tetap sama angkanya.

---

# BAGIAN VIII — VALIDASI INPUT

## 8.1 Wajib (blok submit)

| Aturan | Kenapa | Pesan yang berguna |
|---|---|---|
| Satuan wajib dipilih | Master balas string `"PILIH SATUAN"` yang bisa lolos ke hasil | "Pilih satuan gaya dulu (kN/N/lbf/kgf/tnf)" |
| Kombinasi standar × tipe beban harus punya tabel | Load Cell 3000 kN tidak punya tabel Pull | "Load Cell 3000 kN belum punya data kalibrasi arah tarik. Pilih standar lain atau hubungi admin." |
| Tepat 12 bacaan per titik (UTM/LC) | Divisor √12 di budget mengasumsikan n=12 | "Titik 200 kgf baru terisi 9 dari 12 pembacaan" |
| Tepat 6 bacaan (Proving Ring) | Divisor √6 | sama pola |
| Tepat 4 pengukuran misalignment | STDEV butuh minimal itu | "Misalignment perlu 4 pengukuran" |
| Standar tidak EXPIRED | Sudah ada monitor di master | "Load Cell 5 kN kadaluarsa sejak 12 Agu 2026" |
| Suhu ruangan 10–35 °C | Catatan eksplisit di master | "Suhu 38,2 °C di luar rentang metode (10–35 °C)" |
| Nominal harus naik monoton | Uji gaya bertahap | "Titik ke-4 (300) lebih kecil dari titik ke-3 (400)" |

## 8.2 Peringatan berbasis pola (tidak memblok, tapi wajib terlihat)

```
1. Semua pembacaan identik dalam satu titik
   → "12 pembacaan pada titik X identik — mohon konfirmasi"

2. Sebaran jauh lebih besar dari biasanya
   → "RRPE 3,2% pada titik X (biasanya < 0,5%) — periksa kondisi mesin"

3. Satu pembacaan menyimpang jauh dari 11 lainnya
   → "Bacaan ke-7 pada titik X (250,1) menyimpang jauh dari yang lain
      (rata-rata 200,5) — kemungkinan salah ketik"

4. Beban di luar rentang tabel standar
   → "Beban X di luar rentang kalibrasi standar (min–max)"

5. Koreksi berganti tanda tanpa pola
   → "Pola koreksi tidak konsisten — periksa linearitas"

6. Pembacaan negatif pada beban positif
   → "Bacaan negatif pada titik X — periksa arah beban/pemasangan"

7. Zero error tidak nol setelah preload
   → "Zero error 0,3 kgf — masuk budget, tapi patut dilihat"

8. Sebaran suhu awal-akhir > 2 °C
   → "Kondisi tidak stabil selama pengukuran"
```

Nomor 3 yang paling sering menyelamatkan: salah ketik satu digit (250,1 alih-alih 200,1) akan lolos semua validasi rentang, tapi langsung terlihat kalau dibandingkan dengan 11 pembacaan tetangganya.

## 8.3 Cara mendeteksi outlier tanpa membuang data

**Jangan pernah membuang pembacaan secara otomatis.** Data teknisi tidak boleh hilang — aturan yang sudah ditulis di `AGENTS.md` (ISO/IEC 17025 klausul 7.5.2).

Yang boleh: **menandai** untuk diperiksa manusia.

```
untuk tiap bacaan di titik:
    z = |bacaan − median| / MAD        # MAD lebih tahan outlier dari STDEV
    kalau z > 3.5:
        tandai bacaan itu, jangan dibuang
        munculkan ke Master Data sebagai temuan
```

Master Data yang memutuskan: salah ketik (koreksi, dengan jejak), atau memang begitu bacaannya (biarkan, beri catatan).

## 8.4 Desimal koma/titik

Pembacaan gaya ditulis sampai 4 desimal (`300,3006`). Keyboard HP Indonesia default koma. **Normalisasi di Flutter dan Laravel.** Test wajib: `"300,3006"` → `300.3006`, bukan `3003006` atau `300`.

---

# BAGIAN IX — ALUR TEKNISI → MASTER DATA → SERTIFIKAT

Alur kerjanya **tidak berubah per alat.** Yang berubah cuma bentuk lembar kerjanya.

```
TEKNISI                    MASTER DATA               SUPER ADMIN        PELANGGAN
───────                    ───────────               ───────────        ─────────
Buat sesi
Isi lembar kerja      ┐
  - identitas alat    │
  - kondisi lingkungan│  validasi §8.1 jalan
  - preload test      │  di sini, sebelum
  - 12 bacaan × titik │  boleh submit
  - misalignment      ┘
Lihat pratinjau hasil
  (koreksi, RRPE, U95%
   dihitung real-time)
Submit ──────────────────► Terima notifikasi
                           Buka lembar kerja
                           Lihat: siapa kirim, kapan,
                                  alat apa, sudah
                                  dikembalikan berapa kali
                           Lihat ringkasan status (§2.4)
                           Bandingkan data mentah vs hasil
                           ┌─ Tandai kesalahan per field
                           │    (nilai TIDAK berubah)
                           ├─ Koreksi nilai
                           │    (nilai lama + baru disimpan)
                           └─ Kembalikan ke teknisi
◄──── notifikasi ──────────    dengan temuan yang bertanda
Perbaiki field bertanda
Submit ulang ─────────────► Setujui ──────────────► Lihat seluruh
                                                     jejak: timeline,
                           Sertifikat terbit         berapa kali balik,
                                     │               siapa ubah apa
                                     └──────────────────────────────► Terima notif
                                                                       Unduh PDF
```

Semua ini sudah dirancang di `docs/pelanggan/09-Adendum-Olah-Data-Peran.md` — tabel `lembar_kerja_temuan` dan `lembar_kerja_revisi`, aturan nilai teknisi tidak pernah dihapus, dan tracking Super Admin.

**Yang perlu ditambah khusus alat gaya:** `jalur_field` untuk penanda kesalahan harus bisa menunjuk sel spesifik di matriks 4 posisi:
```
"titik.3.posisi.90.replikat.2"     → titik ke-3, posisi 90°, replikat ke-2
"misalignment.2"                   → pengukuran misalignment ke-2
"preload.max_capacity.1"           → preload max capacity replikat 1
```
Tanpa jalur sesempit ini, Master Data cuma bisa bilang "ada yang salah di sesi ini" — dan teknisi harus menebak yang mana dari **168 angka**.

---

# BAGIAN X — URUTAN IMPLEMENTASI

Kalau dikerjakan sekaligus, susah cari salahnya. Bertahap begini:

**Tahap 1 — Fungsi inti, tanpa UI.** Konversi satuan, nearest-match (termasuk tie-break), koreksi termal, penanganan titik nol. Test langsung ke test vector Lampiran. Kalau ini belum cocok, jangan lanjut.

**Tahap 2 — Satu profil dulu: UTM.** Yang paling lengkap cabang CMC-nya dan paling banyak datanya. Selesaikan sampai sertifikat cocok.

**Tahap 3 — Load Cell.** Sebangun dengan UTM; yang beda cuma cabang CMC dan nama standar ke-3.

**Tahap 4 — Proving Ring.** Paling beda; kerjakan terakhir supaya pola dari dua sebelumnya sudah mapan.

**Tahap 5 — Validasi & peringatan pola** (§8.2, §8.3). Ini yang membuat sistem berguna di lapangan, bukan cuma benar secara rumus.

**Tahap 6 — Catatan audit** untuk temuan §5, lalu bawa ke lab.

Alasan urutan ini: **tiga dari enam temuan ada di Proving Ring.** Kalau dikerjakan duluan, susah membedakan "kode saya salah" dari "master-nya memang begitu". Dengan UTM dulu — yang relatif bersih — pola benarnya sudah terbentuk sebelum menghadapi yang bermasalah.

---

# LAMPIRAN — Test vector siap pakai

## A. Rekonsiliasi terhadap master (wajib)

```
=== UTM, sesi 0169-CAL-324, titik nominal 200 kgf ===
Input:
  satuan = kgf (faktor 0,00981)
  12 bacaan = [200.538, 200.6, 200.538, 200.538, 200.538, 200.538,
               200.538, 200.538, 200.538, 200.538, 200.538, 200.538]
  W (koreksi standar) = 0.0030400615
  T_std_sertifikat = 23.15 °C
  T_std_aktual     = 24.4 °C

Harapan (toleransi 1e-12):
  B  = 1.9619999999999997
  R  = 1.9673284649999998
  Y  = 1.9703685264999997
  Z  = 1.969703527122306
  AA = 0.008368526499999973
  AB = 0.0309999999999968

=== UTM, budget sesi yang sama ===
  uc    = 0.21269280931915782 %
  k     = 1.9698562125960952
  U     = 0.4189742518118597 %
  U_kN  = 0.020550687051371717
  CMC   = 0.020600999999999998
  U95%  = 0.020600999999999998   (CMC menang)

=== Proving Ring, bukti cacat SUM (§5.1) ===
  Σ(uici)² baris 9–14 = 0.04703237424735414   ← master pakai ini
  Σ(uici)² baris 9–16 = 0.048801243938405243  ← yang seharusnya
  uc master      = 0.2168694866673367
  uc seharusnya  = 0.22091003584809188
```

## B. Data lapangan & kasus tepi (wajib juga)

```
TEST DATA SINTETIS LAPANGAN
  12 bacaan acak di sekitar nominal ± 0,05%, semuanya berbeda
  → STDEV > 0, RSD terhitung, RRPE terhitung, budget berubah

TEST TITIK NOL
  nominal = 0, semua bacaan = 0
  → RSD = 0 (bukan error), RRPE = null/"−" (bukan error)

TEST PEMBACAAN NOL PADA BEBAN NON-NOL
  nominal = 500, semua bacaan = 0
  → BUKAN diam-diam jadi 0; harus jadi temuan

TEST SERI NEAREST-MATCH
  nilai persis di tengah dua set point
  → ambil yang indeksnya lebih kecil (meniru MATCH Excel)

TEST DI LUAR RENTANG TABEL
  beban melebihi set point tertinggi
  → tetap menghitung, tapi memunculkan peringatan ekstrapolasi

TEST SEMUA BACAAN IDENTIK
  → menghitung normal, tapi memunculkan peringatan konfirmasi

TEST OUTLIER
  11 bacaan ~200, satu bacaan 250
  → ditandai, TIDAK dibuang, muncul ke Master Data

TEST DESIMAL KOMA
  "300,3006" → 300.3006, bukan 3003006 atau 300

TEST KOMBINASI STANDAR TAK ADA
  Load Cell 3000 kN + Pull → error jelas, bukan hasil kosong

TEST SATUAN KOSONG
  → error eksplisit, bukan string "PILIH SATUAN" masuk hasil
```

Blok A membuktikan rumusnya benar. Blok B membuktikan kodenya tidak pecah saat data tidak rapi. **Keduanya wajib** — blok A saja tidak pernah menyentuh sebagian besar cabang kode.
