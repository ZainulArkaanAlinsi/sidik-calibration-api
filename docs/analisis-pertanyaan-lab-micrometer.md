# Analisis & usulan jawaban — pertanyaan lab Micrometer

**Status dokumen: USULAN, bukan keputusan.** Isinya analisis teknis berikut
rekomendasi. Yang memutuskan tetap manajer teknis lab — beberapa butuh fakta
yang cuma lab punya, dan dua di antaranya butuh tanda tangan yang tidak bisa
diwakilkan sistem.

Dipisah dari `pertanyaan-lab-micrometer.md` supaya jelas mana pertanyaannya dan
mana usulannya. Kalau sebuah usulan disetujui, pindahkan keputusannya ke berkas
itu dan tandai §-nya **[SUDAH DIJAWAB]**.

Tiap kesimpulan diberi label dasar buktinya:

| Label | Artinya |
|---|---|
| **[TERUKUR]** | Dihitung dari master/kode di repo ini; angkanya bisa diulang |
| **[PRINSIP]** | Dari prinsip metrologi/standar yang berlaku umum |
| **[BUTUH FAKTA LAB]** | Perlu data atau praktik lab yang tidak ada di repo |
| **[KEPUTUSAN LAB]** | Konsekuensi akreditasi/hukum — perlu manajer teknis |

Rujukan standar ditulis dengan nama klausanya, bukan cuma nomornya: penomoran
bisa berbeda antar revisi, dan yang mengikat dokumen akreditasi lab sendiri.
Manajer teknis perlu mencocokkannya ke terbitan KAN/ILAC yang berlaku sekarang.

---

## Ringkasan usulan

| § | Pertanyaan | Usulan singkat | Kelas |
|---|---|---|---|
| §1 | Sertifikat lama terbit di bawah lantai CMC | **Ya, tinjau** — angkat sebagai ketidaksesuaian, lalu lingkup & nilai dampaknya | [KEPUTUSAN LAB] |
| §9 | Sertifikat master cetak koreksi `0,000` | **Terbitkan ulang atas permintaan**, dan betulkan format sel master | [KEPUTUSAN LAB] |
| §11 | **BARU** — komponen termal lenyap karena satuan `ci` | **Betulkan satuannya**, lalu tinjau ulang apakah CMC 0,87 µm masih tercapai | [TERUKUR] + [KEPUTUSAN LAB] |

§11 ditemukan waktu menyiapkan analisis §1 dan **mengubah jawaban §1**, jadi
dibaca lebih dulu.

---

## §11 — Komponen termal lenyap karena satuan `ci` (BARU)

### Yang terukur **[TERUKUR]**

Budget Micrometer bekerja dalam **µm**. Dua komponen termalnya:

| # | Komponen | `u` | `ci` |
|---|---|---|---|
| 4 | Perubahan suhu terhadap 20 °C | Δϴ / √3 | α × L |
| 5 | Koefisien muai thermal | α / √3 | L × Δϴ |

`ci` dihitung dengan **L dalam milimeter**, sementara sisanya budget dalam µm.
Sumbangan keduanya pada sesi contoh 25-50 mm:

```
suhu_ruang        u=0,3175      ci=0,00025      u·ci = 7,94e-5 µm    0,000% dari uc²
koefisien_muai    u=5,77e-6     ci=13,75        u·ci = 7,94e-5 µm    0,000% dari uc²
```

**Nol koma nol nol nol persen.** Seluruh budget berdiri di atas enam komponen
lain; dua komponen termal ini tidak menyumbang apa pun.

Kalau `ci` dinyatakan dalam µm — konsisten dengan satuan budgetnya — dengan
rumus yang PERSIS SAMA:

| Perlakuan | u(suhu)·ci | u(muai)·ci | uc | U95 | vs CMC 0,87 |
|---|---|---|---|---|---|
| Master apa adanya (ci mm di budget µm) | 7,9e-5 | 7,9e-5 | 0,4439 | **0,872** | tepat lolos |
| `ci` dikonsistenkan ke µm, `u` tetap = besarannya | 0,1588 | 0,1588 | 0,4974 | **0,978** | **di atas** |
| `ci` konsisten + `u` realistis (u(ϴ)=0,2 °C, u(δα)=1e-6/°C) | 0,0577 | 0,0159 | 0,4479 | **0,880** | **di atas** |

### Kenapa ini penting **[PRINSIP]**

Ini bukan selera penulisan. Dalam setiap perlakuan yang satuannya konsisten,
U95 mendarat **di atas** 0,87 µm. Angka 0,872 yang sekarang terbit cuma bisa
dicapai karena komponen termalnya lenyap.

Artinya pertanyaannya berbalik arah dari §1. §1 bertanya "apakah kita pernah
menerbitkan di BAWAH CMC?" — §11 bertanya "**apakah CMC 0,87 µm itu sendiri
tercapai** dengan metode sebagaimana tertulis?"

### Yang belum bisa dijawab dari sini **[BUTUH FAKTA LAB]**

Baris ketiga tabel di atas memakai angka yang gw asumsikan, dan keduanya milik
lab:

1. **u(ϴ) — ketidakpastian suhu**, bukan simpangan suhunya. Master memakai Δϴ
   (simpangan dari 20 °C) sebagai ketidakpastiannya sendiri. Yang benar
   ketidakpastian PENGUKURAN suhunya: ketelitian thermohygro + gradien ruang +
   beda suhu balok/UUT. Berapa spesifikasi kendali suhu Lab Dimensi?
2. **δα — beda koefisien muai balok ukur vs rangka mikrometer.** Tetapan
   `delta_alpha_per_c = 1e-5` besarnya sama dengan α baja itu sendiri
   (≈11,5e-6/°C), bukan seperti SELISIH dua benda baja (lazimnya ~1e-6/°C).
   Yang dimaksud master α atau δα?

### Usulan

1. Betulkan satuan `ci` supaya konsisten µm. **[TERUKUR]** — ini murni
   dimensional, tidak butuh keputusan.
2. Ganti `u` kedua komponen dengan ketidakpastian yang sebenarnya, sesudah
   lab menjawab dua pertanyaan di atas. **[BUTUH FAKTA LAB]**
3. Hitung ulang U95 keempat rentang, lalu **tinjau apakah CMC di lampiran
   akreditasi masih tercapai**. Kalau tidak, yang perlu direvisi CMC-nya —
   bukan angkanya yang ditekan supaya muat. **[KEPUTUSAN LAB]**

Sampai (2) dijawab, kode **tidak diubah**: mengganti satuan tanpa mengganti `u`
menaikkan U95 ke 0,978 µm atas dasar `u` yang kita sendiri tahu bukan
ketidakpastian sungguhan. Menukar satu kesalahan dengan kesalahan lain yang
lebih besar bukan perbaikan.

---

## §1 — Sertifikat lama terbit di bawah lantai CMC

### Apakah ini ketidaksesuaian? **[PRINSIP]** — ya

CMC (Calibration and Measurement Capability) adalah ketidakpastian **terkecil**
yang boleh dilaporkan lab untuk kalibrasi terakreditasi dalam lingkupnya. Angka
yang lebih kecil dari CMC berarti mengklaim kemampuan di luar yang diakui —
jadi ini bukan soal selera, dan bukan pula sekadar "angkanya beda sedikit".

Yang terbit dari workbook 0-25 mm: **0,735 µm** terhadap CMC **0,83 µm**.

### Arah bahayanya **[PRINSIP]**

Ini bagian yang gampang salah baca. Ketidakpastian yang terlalu KECIL terasa
"lebih aman" — padahal justru sebaliknya buat pelanggan:

- Pada keputusan kesesuaian (pass/fail terhadap toleransi), U yang lebih kecil
  membuat pita penjagaannya lebih sempit, jadi **lebih banyak alat dinyatakan
  LULUS**. Alat yang seharusnya ditolak bisa lolos.
- Pelanggan yang memakai U lab dalam budget ketidakpastiannya sendiri ikut
  meremehkan miliknya.

Jadi sertifikat yang U-nya terlalu kecil bisa berakibat pada keputusan yang
sudah diambil pelanggan — bukan cuma angka di kertas.

### Yang gw TIDAK bisa tentukan **[BUTUH FAKTA LAB]**

- Berapa banyak sertifikat terbit dari workbook keluarga ini dengan U95 di bawah
  pita CMC-nya, dan sejak kapan.
- Apakah pelanggan memakainya untuk keputusan kesesuaian.

Sertifikat-sertifikat itu terbit dari **Excel, bukan dari sistem ini**, jadi
tidak ada kueri yang bisa gw jalankan untuk melingkupinya. Perlu penelusuran
arsip lab.

### Usulan **[KEPUTUSAN LAB]**

Perlakukan sebagai **pekerjaan tidak sesuai** dan jalankan prosedur yang sudah
ada di sistem mutu lab (ISO/IEC 17025 mengatur ini di klausa Pekerjaan Tidak
Sesuai: nilai signifikansinya, ambil tindakan, dan **beritahu pelanggan serta
tarik pekerjaannya bila perlu**). Urutan yang gw usulkan:

1. **Angkat resmi**, jangan diperbaiki diam-diam ke depan saja. Sistem sudah
   memblokir sesi seperti ini, tapi memblokir ke depan bukan tindakan atas yang
   sudah terbit.
2. **Lingkupi**: telusuri arsip, daftar sertifikat Micrometer yang U95-nya di
   bawah pita CMC rentangnya.
3. **Nilai per sertifikat**: adakah pernyataan kesesuaian di situ? Kalau ya,
   apakah dengan U yang benar keputusannya bisa berbalik?
4. **Beritahu & terbitkan ulang** untuk yang keputusannya bisa berbalik.
   Untuk sisanya, catat penilaiannya — keputusan "tidak perlu ditarik" pun
   perlu berkas.
5. Sambungkan ke **§11**: kalau U yang benar ternyata di atas CMC, revisi
   lampiran akreditasinya, bukan angkanya.

Yang bisa gw bantu kerjakan kalau diminta: menyiapkan kueri/laporan untuk
melingkupi sesi yang sudah masuk sistem, dan menyusun draf catatan
ketidaksesuaiannya untuk ditinjau manajer teknis. Yang tidak bisa: memutuskan
lingkup penarikan, dan menandatanganinya.

---

## §9 — Sertifikat master mencetak koreksi `0,000` di kesebelas titik

### Apakah angkanya SALAH? **[TERUKUR]** — tidak

Nilai di dalam selnya benar (0,00027000000000043656). Yang hilang cuma di
lapisan tampilan: format sel `0.000` membulatkannya jadi `0,000`.

Jadi ini beda kelas dari §1. Tidak ada klaim palsu; yang ada **informasi yang
hilang**.

### Kenapa tetap cacat **[PRINSIP]**

Dua alasan, dan yang kedua lebih kuat:

1. **Desimal hasil harus sepadan dengan ketidakpastiannya.** Praktik baku (GUM):
   ketidakpastian dibulatkan ke satu-dua angka penting, dan hasilnya dilaporkan
   sampai desimal yang sama. U95 = 0,00087 mm → hasil harus sampai lima desimal.
   Mencetak tiga desimal membuang digit yang justru dinyatakan berarti oleh
   ketidakpastiannya sendiri.
2. **Kolom koreksi adalah isi utama sertifikat mikrometer.** Yang dibeli
   pelanggan justru angka koreksi untuk dipakai mengoreksi pembacaannya. Kolom
   berisi nol semua tidak memberi apa pun — dan lebih buruk, terbaca sebagai
   "alat saya sempurna di semua titik".

Standar mensyaratkan hasil dilaporkan secara akurat, jelas, tidak ambigu, dan
objektif. Kolom nol yang sebenarnya bukan nol memenuhi "akurat" secara harfiah
tapi gagal di "jelas" dan "tidak ambigu".

### Usulan **[KEPUTUSAN LAB]**

1. **Betulkan format sel master** (`SERTIFIKAT!D18:L28` dan `J29`) ke lima
   desimal. Tanpa ini workbook dan sistem terus mencetak angka berbeda untuk
   sesi yang sama — dan itu sendiri temuan audit menunggu terjadi.
2. **Terbitkan ulang atas permintaan** untuk sertifikat lama. Bedanya dari §1:
   di sini tidak ada klaim yang salah, jadi penarikan proaktif kemungkinan
   berlebihan.
3. **Kecuali** untuk pelanggan yang memang memakai kolom koreksi (mis. yang
   menerapkan koreksi ke pembacaan alatnya). Buat mereka, sertifikat lama tidak
   terpakai sama sekali, dan penerbitan ulang proaktif sebaiknya ditawarkan.
   Siapa saja mereka — **[BUTUH FAKTA LAB]**.

Sisi sistem sudah benar sejak PR #172: lima desimal, dijaga
`MicrometerSertifikatTest::test_desimal_cukup_untuk_koreksi_mikrometer`.

---

## Yang sengaja TIDAK gw jawab

Empat sisanya (§3, §5, §6, §8) memang perlu fakta lab, dan menebaknya lebih
berbahaya daripada membiarkannya terbuka:

- **§3** — apakah alat contoh 0-25 mm benar berskala inch. Cuma bisa dijawab
  dengan melihat alatnya atau arsip ordernya.
- **§5** — apakah komponen suhu & muai yang kembar itu disengaja. Sekarang
  tergabung ke **§11**, yang menunjukkan keduanya bukan sekadar kembar tapi
  juga lenyap. Jawabannya menunggu dua fakta di §11.
- **§6** — dasar `vi = 200`. Kalau dari jumlah pengamatan historis, angkanya
  ada di catatan lab; kalau konvensi, cukup dicatat supaya asesor tidak
  menanyakannya sebagai temuan.
- **§8** — apakah komponen ke-9 yang selalu nol dihapus atau dibiarkan.
  Bergantung pada apakah balok ukur & UUT pernah diukur suhunya terpisah —
  praktik lab, bukan turunan angka.
