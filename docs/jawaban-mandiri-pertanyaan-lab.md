# Jawaban mandiri atas pertanyaan lab yang terbuka

**Disusun:** 6 September 2026
**Sumber jawaban:** data di repo, GUM/EA-4/02, dan sifat bahan — **bukan** keterangan lab.

Pemilik proyek meminta pertanyaan-pertanyaan terbuka dikerjakan sejauh yang bisa
dikerjakan sendiri. Berkas ini hasilnya, dengan satu aturan yang dipegang keras:

> Yang bisa **dibuktikan** dijawab. Yang cuma bisa **ditebak** ditandai sebagai
> usulan, lengkap dengan asumsinya. Yang menyangkut **identitas benda fisik**
> (nomor seri standar, hasil ukur, isi arsip) **tidak dikarang** — di lab
> terakreditasi, angka karangan yang kelihatan wajar lebih berbahaya daripada
> kolom kosong.

Kelas bukti tiap butir:

| Tanda | Artinya |
|---|---|
| **[TERBUKTI]** | Diturunkan dari data/standar/fisika. Tidak butuh keterangan lab. |
| **[USULAN]** | Ada bawaan yang bisa dipertahankan + asumsinya tertulis. Lab boleh membatalkan. |
| **[TIDAK BISA]** | Butuh fakta lab atau barang fisik. Mengarangnya merugikan. |

---

## §11 Micrometer — komponen termal **[TERBUKTI: pertanyaannya gugur]**

Ini yang paling banyak berubah. Dua pertanyaan ke lab **tidak lagi menahan
kesimpulannya** — keduanya cuma mengubah seberapa jauh, bukan ke arah mana.

### Bagian yang terbukti: `1e-5` itu α, bukan δα

| Besaran | Orde wajar | Tetapan master |
|---|---|---|
| α baja perkakas | 11,5 × 10⁻⁶ /°C | — |
| δα, selisih dua benda baja | ~1 × 10⁻⁶ /°C | — |
| `delta_alpha_per_c` | — | **1 × 10⁻⁵ /°C** |

`1e-5` duduk persis di orde **α**, bukan di orde selisih dua benda baja. Ini
sifat bahan, bukan praktik lab — jadi bisa dijawab dari sini: **yang dimaksud
master α.**

Akibatnya: struktur master sebenarnya **benar** (dia mengikuti model termal dua
suku EA-4/02 — suku `α·Δϴ` dan suku `δα·ϴ`), tapi slot `δα` **diisi α**. Jadi
komponen itu berlebih ±10× pada `u`-nya, sementara bug satuan `ci` justru
menihilkannya. **Dua kesalahan berlawanan arah** yang kebetulan saling menutup.

### Bagian yang menggugurkan pertanyaannya

Pertanyaan aslinya: *"apakah CMC 0,87 µm tercapai dengan metode sebagaimana
tertulis?"* — dan itu bisa dijawab **tanpa** tahu kendali suhu Lab Dimensi.

Alasannya tiga baris:

1. Komponen ketidakpastian dijumlah **kuadratis** — menambah komponen cuma bisa
   **menaikkan** `uc`, tidak pernah menurunkan.
2. `uc` tanpa sumbangan termal sama sekali = **0,4439 µm**.
3. `k` punya lantai **1,960** (Welch-Satterthwaite, `veff → ∞`).

Jadi `U95 ≥ 1,960 × 0,4439 = **0,8700 µm** = **persis lantai CMC**.

Disapu ke seluruh ruang parameter yang masuk akal:

| u(ϴ) °C | u(δα) /°C | uc µm | U95 µm | vs CMC 0,87 |
|---|---|---|---|---|
| 0,05 | 1×10⁻⁷ | 0,4441 | 0,8705 | di atas |
| 0,20 | 1×10⁻⁶ | 0,4478 | 0,8777 | di atas |
| 0,29 | 1×10⁻⁶ | 0,4519 | 0,8857 | di atas |
| 0,58 | 5×10⁻⁶ | 0,4791 | 0,9391 | di atas |
| 1,00 | 1×10⁻⁵ | 0,5465 | 1,0710 | di atas |

Terendah di seluruh sapuan: **0,8705 µm** — pada nilai yang secara fisika sudah
tidak masuk akal optimisnya (ruang terkendali ±0,09 °C, δα 1×10⁻⁷).

**Kesimpulan: U95 tidak pernah jatuh di bawah lantai CMC, apa pun jawaban lab.**

### Yang berubah dari ini

- §11 **bukan** temuan yang bisa menggeser lampiran akreditasi. Turun dari
  "taruhan tertinggi" jadi "konsistensi metode".
- **Nol paparan sertifikat.** Tidak ada sesi rentang ini yang bisa terbit di
  bawah CMC gara-gara komponen termal — arah kesalahannya menaikkan, bukan
  menurunkan.
- Dua pertanyaan ke lab **dicabut sebagai penghalang**. Keduanya masih berguna
  untuk menyetel angka, tapi tidak ada yang menunggu jawabannya.

### Yang masih keputusan lab **[TIDAK BISA]**

Apakah satuan `ci` dibetulkan sama sekali. Membetulkannya menaikkan U95 semua
rentang, jadi sertifikat baru tidak sebanding dengan yang lama. Itu perubahan
metode — dan sesuai aturan proyek, master ditiru sampai lab memutuskan lain.
**Kode tidak diubah.**

---

## §6 Micrometer — `vi = 200` **[TERBUKTI: konvensi, ada rumusnya]**

Bukan angka karangan. GUM Lampiran G.4.2 memberi derajat kebebasan Type B dari
seberapa yakin kita pada `u`-nya sendiri:

```
vi ≈ ½ · [Δu(xi) / u(xi)]⁻²
```

| Ketidakyakinan pada `u` | `vi` |
|---|---|
| 2 % | 1250 |
| **5 %** | **200** |
| 10 % | 50 |
| 25 % | 8 |

`vi = 200` **persis** setara dengan menyatakan *"nilai `u` Type B ini diketahui
dalam 5 %"*. Itu asumsi lazim dan konservatif.

**Tidak ada yang perlu diubah.** Yang perlu cuma tercatat — dan sekarang
tercatat di sini, supaya asesor tidak menanyakannya lagi sebagai temuan.

---

## §5 Micrometer — `u(Δϴ)` dan `u(α)` kembar **[TERBUKTI: kesalahan kategori]**

Master memakai **besaran itu sendiri sebagai ketidakpastiannya**:

```
komponen suhu :  u = Δϴ / √3      ci = α · L
komponen muai :  u = α  / √3      ci = L · Δϴ
```

`Δϴ` itu **simpangan suhu terukur dari 20 °C** — sebuah nilai, bukan
ketidakpastian. Begitu juga `α`. Yang benar `u(ϴ)` (ketidakpastian *pengukuran*
suhunya) dan `u(δα)` (ketidakpastian selisih koefisien).

Jadi jawabannya bukan "disengaja atau tersalin" — **dua-duanya salah kategori**,
dan kembarnya cuma akibat. Struktur `ci`-nya sendiri benar dan cocok EA-4/02.

**[USULAN]** Kalau nanti dibetulkan: `u(ϴ)` = ketidakpastian thermohygro +
gradien ruang; `u(δα)` ≈ 1×10⁻⁶ /°C untuk baja-lawan-baja. Tapi lihat §11 —
membetulkannya menaikkan U95, jadi tetap keputusan lab.

---

## §8 Micrometer — selisih suhu mikrometer–balok **[USULAN: pertahankan nol]**

Komponen ini nol menurut konstruksi karena lembar kerjanya cuma memungut suhu
ruangan, bukan suhu kedua benda terpisah.

**Usulan: biarkan nol, dan catat asumsinya.** Alasannya bisa dipertahankan —
praktik baku metrologi dimensi (EA-4/02, ISO 1) memang merendam UUT dan balok
ukur sampai satu suhu sebelum diukur, dan master yang mengonstruksi komponen ini
sebagai nol adalah bukti tidak langsung bahwa itu praktik di sini.

**Yang tidak boleh:** mengarang angka selisih suhu. Kalau UUT pernah diukur
langsung dari lapangan tanpa perendaman, yang dibutuhkan dua kotak baru di
kertas — bukan tebakan di kode.

Tempatnya sudah ada di budget (komponen ke-9), jadi begitu lab mulai mengukur
terpisah, tidak ada yang perlu dibongkar.

---

## K21 — drift Type K 0,55 lawan 0,5 **[TERBUKTI: sudah ada aturannya]**

Pertanyaan ini sebenarnya sudah dijawab keputusan proyek yang lebih dulu ada:

> Beda antar-workbook untuk hal yang sama **disimpan keduanya, dipilih per
> sesi**. Memilih satu sebagai "yang benar" berarti diam-diam menggeser angka
> yang sudah tercetak di sertifikat pelanggan.

Jadi: **0,55 untuk sesi yang memakai workbook Recorder, 0,5 untuk sesi yang
memakai Constant/Yokogawa.** Bukan diseragamkan.

Yang tersisa buat lab bukan "mana yang benar" tapi "apakah keduanya memang dua
sertifikat standar yang berbeda" — dan kalau iya, tidak ada yang perlu
dikerjakan sama sekali.

---

## §7 Waktu-Frekuensi — penandaan titik di luar lingkup **[USULAN]**

**Usulan: tandai barisnya, dengan catatan kaki, jangan cuma peringatan sesi.**

ISO/IEC 17025 §7.8.2.2 meminta hasil di luar lingkup akreditasi dibedakan dengan
jelas di laporan. Peringatan yang cuma muncul di layar teknisi tidak sampai ke
pelanggan yang memegang sertifikatnya.

Bentuk yang diusulkan: tanda pada baris yang bersangkutan + satu kalimat kaki
yang menyebut titik itu di luar lingkup akreditasi KAN, dan U-nya memakai pita
terdekat.

---

## Keputusan produk **[DIPUTUSKAN — bukan wewenang lab]**

Kelimanya tidak menyentuh satu angka pun, dan tidak butuh keterangan siapa pun.
Diputuskan di sini; tinggal dikerjakan kalau pemilik proyek setuju.

### K8 — ruangan wajib atau boleh kosong

**Boleh kosong.** Mewajibkannya menolak semua aplikasi versi lama dengan galat
validasi yang tidak bisa dipahami teknisi di lapangan. Kalau ruangan penting
untuk telusur, tempatnya peringatan saat menyetujui — bukan penolakan saat
mengirim.

### K10 — pintu masuk layar Draf, dan admin boleh lihat draf teknisi lain?

**Pintu masuk: tab Kalibrasi, bukan Profil.** Draf itu pekerjaan yang belum
selesai, jadi dia milik alur kerja, bukan pengaturan akun.

**Admin TIDAK boleh melihat draf teknisi lain.** Draf itu catatan yang belum
diserahkan; membukanya ke admin membuat teknisi berhenti menyimpan draf dan
mulai menahan pekerjaan di kertas sampai yakin — persis kebalikan dari gunanya.
Begitu dikirim, barulah jadi milik lab.

### K11 — tombol hapus draf

**Perlu, tapi hapus lunak dan hanya oleh pemiliknya.** Draf yang tidak bisa
dihapus menumpuk sampai daftarnya tidak berguna. Hapus lunak karena "draf" dan
"terkirim" cuma beda satu status — jejaknya tetap perlu ada kalau ternyata salah
tekan.

### K25 — siapa yang memutuskan baris `perlu_tinjau`

**Admin, lewat laporan — bukan panel aksi gabung.** Membangun panel gabung
sebelum ada yang pernah meninjau satu baris pun berarti menebak alurnya. Mulai
dari laporan; kalau ternyata sering dipakai, panelnya menyusul dengan bentuk
yang sudah terbukti dibutuhkan.

### K26 — status verifikasi alamat + peringatan saat terbit

**Jangan dibangun sekarang.** Peringatan cuma layak kalau alamatnya memang akan
diverifikasi oleh seseorang. Peringatan yang selalu menyala melatih admin
menekan "terbitkan saja" tanpa membaca — dan itu merusak peringatan lain yang
sungguh penting. Ini kegagalan yang sudah terbukti di proyek ini.

---

## HG§2 Height Gauge — pembagi `√6` di komponen berlabel `rect.` **[TERBUKTI: labelnya yang salah, bukan pembaginya]**

Pertanyaan aslinya: *"`√6` itu disengaja, atau salah ketik dari `√3`?"*

Itu bisa dijawab tanpa lab, karena `√6` **bukan angka yang lahir dari salah
ketik** — dia pembagi bernama di GUM/EA-4/02:

| Distribusi | Pembagi | Nilai |
|---|---|---|
| rectangular | √3 | 1,7320508 |
| **triangular** | **√6** | **2,4494897** |
| U-shaped | √2 | 1,4142136 |

`√3` dan `√6` tidak bertetangga di papan ketik dan tidak mirip di layar. Yang
mengetik `SQRT(6)` sedang menyebut **distribusi segitiga** — pilihan yang wajar
untuk selisih muai dua benda sejenis, karena nilai di tengah rentang lebih
mungkin daripada nilai di ujung.

**Kesimpulan:** pembaginya konsisten dengan sebuah distribusi nyata; yang tidak
konsisten adalah **label `rect.` di kolom J9**. Pertanyaannya turun kelas dari
*"mana yang benar?"* jadi *"tolong betulkan labelnya jadi `tri.`"*.

**Nol perubahan angka.** Sumbangannya ke `Σ(ui·ci)²` tetap ~1,5·10⁻⁸ dari total
5,64·10⁻⁵ — U yang terbit tidak bergeser di digit mana pun yang tercetak.

---

## HG§9 Height Gauge — tabel **Inside** yang tidak terpakai **[TERBUKTI: milik standarnya, bukan milik metodenya]**

Pertanyaan aslinya: *"tabel Inside tidak dipakai, atau ada mode kalibrasi yang
belum masuk master?"*

Terjawab dari struktur workbook-nya sendiri, tiga langkah:

1. Tabel itu duduk di sheet **`Std_CaliperCek`** — sheet **standarnya**, bukan
   sheet perhitungan Height Gauge.
2. Standarnya bernama **Caliper Checker** (Metrology/CMG-9060C). Alat itu
   standar **bersama**: dipakai mengkalibrasi jangka sorong *dan* height gauge.
3. Jangka sorong punya rahang **dalam** dan **luar** — makanya sertifikat
   standarnya memuat dua tabel. Height gauge mengukur tinggi dengan scriber di
   atas meja rata; **tidak punya rahang dalam sama sekali**.

Jadi tabel Inside itu bagian dari **sertifikat standarnya**, yang disalin utuh
apa adanya. Ketiadaan pemakaian bukan kelalaian — memang tidak ada yang bisa
memakainya di jalur Height Gauge.

**Yang sudah benar:** tabelnya tetap disalin ke
`database/data/tabel-standar-height-gauge.json` tapi tidak disambungkan. Itu
perlakuan yang tepat dan tidak perlu diubah.

---

## HG§3 Height Gauge — dua nilai muai **[TERBUKTI: dua besaran BERBEDA, dan satu di antaranya 10× kekecilan]**

Pertanyaan aslinya menduga keduanya mungkin besaran berbeda. **Benar** — dan itu
bisa dibuktikan dari sifat bahan, persis seperti §11 Micrometer di atas.

| Sel | Nilai | Orde yang wajar | Vonis |
|---|---|---|---|
| `INPUT DATA!S24` → Δα | 2,0·10⁻⁶ /°C | δα dua benda baja ~1·10⁻⁶ | **duduk pas — ini δα yang benar** |
| `PERHITUNGAN!P35`=`Q35` → αs, αt | 1,2·10⁻⁶ /°C | α baja **11,5·10⁻⁶** | **10× kekecilan** |

`P35`/`Q35` dipakai sebagai **α mutlak** (koefisien muai bahannya), bukan
selisih. Nilai α untuk baja perkakas 11,5–12 × 10⁻⁶ /°C. Yang tertulis
1,2 × 10⁻⁶ — dan **1,2 × 10⁻⁵ = 12 × 10⁻⁶ duduk persis di angka baja.**
Pola kesalahannya sama persis dengan §11 Micrometer: pangkat sepuluh meleset satu.

**Kenapa ini belum menggigit hari ini:** `αs` dan `αt` diisi nilai yang **sama**,
dan `δϴ = 0` (lihat HG§10). Suku `αs·Δϴs − αt·Δϴt` karena itu nol menurut
konstruksi — besar α-nya tidak berpengaruh selama kedua sisinya kembar.

**Kapan ini menggigit:** begitu lab mulai mencatat suhu UUT terpisah dari suhu
standar (pertanyaan HG§10), suku itu hidup — dan koreksi tiap titik jadi
**10× lebih kecil dari yang seharusnya**. Dua pertanyaan itu karena itu **satu
paket**: jangan jawab HG§10 "diukur terpisah" tanpa membetulkan HG§3 di kalimat
yang sama.

> **Yang perlu dikonfirmasi lab tinggal satu kalimat:** apakah `P35`/`Q35`
> dimaksud 1,2·10⁻⁵. Kalau ya, itu perbaikan sebelum jalur suhu terpisah dinyalakan.

---

## HG§5 Height Gauge — paralelisme `STDEV` lawan `Max − Min` **[USULAN kuat: ini satu-satunya yang arah salahnya merugikan]**

Sepuluh pertanyaan Height Gauge, sembilan di antaranya soal seberapa besar angka
yang **terbit**. Yang ini beda sendiri: dia menentukan **vonis lulus/tidak**, dan
arah kesalahannya menguntungkan alat yang seharusnya gagal.

**Bukti dari definisi besarannya.** Paralelisme/kerataan di metrologi dimensi
(ISO 1101, dan JIS B 7517 untuk keluarga jangka sorong/height gauge) didefinisikan
sebagai **jarak dua bidang sejajar yang mengapit permukaannya** — yaitu
**rentang**, `Max − Min`. Simpangan baku bukan definisi yang dipakai di mana pun
untuk besaran ini; dia besaran sebaran, bukan besaran geometri.

**Besarnya penyimpangan bisa dihitung tepat.** `STDEV` atas dua angka =
`|Max − Min| / √2`, jadi rumus master **selalu melaporkan 29,3 % lebih kecil**
dari rentang sebenarnya — bukan kadang-kadang, tapi setiap kali, dengan faktor
tetap.

**Akibatnya pada vonis** (batas 0,01 mm):

| Rentang sebenarnya | `Max − Min` | `STDEV` | Vonis berbeda? |
|---|---|---|---|
| 0,008 mm | Good | Good | — |
| **0,012 mm** | **Not Good** | Good (0,0085) | **ya** |
| **0,014 mm** | **Not Good** | Good (0,0099) | **ya** |
| 0,015 mm | Not Good | Not Good | — |

Ada pita **0,0100–0,0141 mm** tempat alat yang menurut definisi standar
**gagal** diluluskan oleh master.

**Kenapa tetap ditandai [USULAN], bukan langsung diubah:** mengubahnya menggeser
vonis kelulusan — itu perubahan metode, wewenang Manajer Teknis. Yang sudah
dikerjakan: ditiru apa adanya, dan **selisihnya ditulis** supaya keputusannya
bisa diambil dengan angka di tangan, bukan dengan kesan.

> **Rekomendasi:** dari sepuluh butir Height Gauge, **prioritaskan yang ini
> sesudah HG§6 (akreditasi)**. Sembilan lainnya menggeser angka; yang ini
> meluluskan alat yang seharusnya ditolak.

---

## HG§1 Height Gauge — pembagi drift `/12` lawan `/365` **[USULAN: labelnya sendiri menunjuk `/365`, tapi tetap pertahankan `/12`]**

Tidak butuh lab untuk menunjukkan **arah** ketidakkonsistenannya — workbook itu
membantah dirinya sendiri di tiga tempat:

1. **Satuan komponennya sendiri** ditulis `mm/th` — laju drift per **tahun**.
   Laju per tahun dikali umur harus memakai umur dalam **tahun** supaya hasilnya
   mm. Argumennya (`X11 − W13`) menghasilkan **hari**. Pembagi yang membuat
   satuannya menutup adalah **`/365`**; `/12` cuma benar kalau argumennya bulan.
2. **Master Micrometer dari lab yang sama** memakai `/365` untuk komponen yang
   sebangun.
3. `12` adalah angka yang wajar tertinggal dari templat berbasis **bulan** —
   pola salin-tempel, bukan pilihan metode.

**Tapi rekomendasinya tetap: pertahankan `/12`.** Alasannya aturan proyek, bukan
metrologi — `/365` membuat U yang terbit **lebih kecil** (0,0154996 vs
0,0156680 mm, −1,1 %), dan penyimpangan yang diam-diam mengecilkan ketidakpastian
tidak boleh diambil sendiri. Versi konservatif dipertahankan sampai lab memutuskan.

**Nol paparan:** Height Gauge di luar lingkup akreditasi, jadi tidak ada lantai
CMC yang bisa ditembus dari arah mana pun oleh selisih 1,1 % ini.

---

## Yang TIDAK bisa dijawab dari sini **[TIDAK BISA]**

Bukan karena malas — karena jawabannya berupa **fakta yang cuma ada di lab**
atau **keputusan yang cuma boleh diambil Manajer Teknis**. Mengarang yang
pertama menghasilkan angka yang kelihatan wajar tapi salah; mengambil sendiri
yang kedua berarti menggeser metode tanpa sepengetahuan yang berwenang.

| | Kenapa mustahil dari sini |
|---|---|
| **§1 §3 §9** tanda tangan | Perlakuan atas sertifikat yang sudah di tangan pelanggan adalah tindakan Manajer Teknis di bawah klausa Pekerjaan Tidak Sesuai ISO/IEC 17025. Analisis & rekomendasinya sudah siap di `docs/keputusan-lab-micrometer.md` |
| **§10** Muka Ukur | "Dihapus dari metode" lawan "lupa ikut ke kertas Rev.1" — itu riwayat keputusan lab, tidak ada jejaknya di data |
| **§11** satuan `ci` termal | Membetulkannya menaikkan U95 SEMUA rentang, jadi sertifikat baru tidak sebanding dengan yang lama — itu perubahan metode. Nol paparan sertifikat: sapuan sensitivitasnya membuktikan tidak ada sesi yang bisa terbit di bawah CMC gara-gara komponen ini |
| **K13 K14 K15** standar | Menambah baris standar kalibrasi atau menebak nomor seri = mengarang telusur. Ini jenis karangan yang paling berbahaya: kelihatan wajar dan lolos semua pemeriksaan |
| **K19** empat penyimpangan TIDS | Sudah ditiru + catatan audit + peringatan. Yang tersisa keputusan apakah dipertahankan — dan itu wewenang Manajer Teknis |
| **K20** konstanta Interpolasi | **Sudah dicoba dibongkar dari angkanya.** `0,19788162882115856` tidak terurai jadi konvensi apa pun — bukan `x/√3`, `x/√12`, bukan pecahan sederhana (penyebut < 10⁵ meleset). Artinya dia **angka turunan data**, jadi cuma bisa dari workbook sumbernya |
| **K22** PRT PT100 + recorder | Apakah kombinasi itu pernah dipakai adalah riwayat pemakaian. Blokirnya sudah benar sebagai bawaan |
| **F1 K12 G3 K24** | Barang fisik: foto, hasil ukur, kertas, berkas arsip. Tidak ada penggantinya |
| **HG§4** tabel Muka Ukur `#REF!` | Sembilan sel menunjuk blok yang **sudah tidak ada** di sheet `PERHITUNGAN`. Yang hilang bukan angkanya — blok pengambilannya. Mengarang tiga posisi Atas/Tengah/Bawah berarti mengarang hasil ukur |
| **HG§6** sertifikat yang sudah terbit | Snapshot beku, dan cetak ulang membaca snapshot. Perlakuan atas dokumen yang sudah di tangan pelanggan adalah tindakan Manajer Teknis di bawah klausa Pekerjaan Tidak Sesuai ISO/IEC 17025 — sama kelasnya dengan §1 §3 §9 Micrometer di atas |
| **HG§7** jeda terbit 4 atau 9 hari | Tiga tanggal di master (05 Mei sidik, 11 Mei Presisi, 14 Mei terbit) tidak menentukan mana yang jadi acuan jeda. Itu praktik penomoran lab |
| **HG§8** empat catatan lepas | `global 1.7 um`, `bblm 4.4 um`, `gmi 2.5 mm/m`, `gis 0.57 mm` — **sudah dicoba diurai.** Keempatnya tidak cocok dengan komponen budget mana pun, tidak dengan CMC lampiran, dan tidak dengan spesifikasi pabrikan Insize. Singkatannya kemungkinan nama merek/standar pembanding; tanpa itu cuma tebakan |
| **HG§10** suhu UUT diturunkan atau diukur | Riwayat praktik lapangan. Jalur suhu terpisah sudah hidup & teruji di kode, jadi jawabannya cuma menyalakan sisi pemanggil — **tapi baca HG§3 dulu**, keduanya satu paket |

---

## Ringkasan perubahan

Jumlahnya ditulis sebagai **daftar**, bukan sebagai angka — supaya bisa dihitung
ulang sambil dibaca, bukan dipercaya begitu saja:

| Kelas | Jumlah | Butirnya |
|---|---|---|
| **Tertutup** — terjawab di sini, tidak butuh lab | **11** | §5 §6 K21 · K8 K10 K11 K25 K26 · **HG§2 HG§9 HG§3** |
| **Bawaannya bisa dipertahankan** — lab boleh membatalkan, tapi tidak ada yang menunggu | **4** | §7 §8 · **HG§1 HG§5** |
| **Masih menunggu lab** | **20** | §1 §3 §9 §10 §11 · K12 K13 K14 K15 K19 K20 K22 K24 · F1 G3 · **HG§4 HG§6 HG§7 HG§8 HG§10** |
| **Jumlah** | **35** | |

| Sebelum | Sesudah |
|---|---|
| 2 butir "bisa menggeser lampiran akreditasi" | **1** — §11 gugur sebagai temuan akreditasi |
| §6 menunggu jawaban | Terjawab: konvensi GUM G.4.2, nol perubahan |
| §5 menunggu jawaban | Terjawab: kesalahan kategori, bukan pilihan |
| K21 menunggu jawaban | Terjawab dari keputusan proyek yang sudah ada |
| 5 keputusan produk menggantung | Diputuskan, siap dikerjakan |
| 10 butir Height Gauge menunggu semua | **5 menunggu** — HG§2 HG§9 HG§3 tertutup, HG§1 HG§5 punya bawaan yang bisa dipertahankan |

**Nol baris kode diubah.** Semua yang di atas temuan dan keputusan; yang
menyentuh angka tercetak tetap menunggu lab, sesuai aturan proyek.

## Yang paling layak dikerjakan lab duluan

Dari 20 yang masih menunggu, dua yang **bukan sekadar menggeser angka**:

1. **HG§6** — sertifikat Height Gauge & Gas Detector yang sudah terbit membawa
   klaim akreditasi untuk lingkup yang di luar LK-285-IDN. Kodenya sudah
   diperbaiki 7 Sep 2026; yang tersisa perlakuan atas dokumen yang sudah beredar.
2. **HG§5** — paralelisme `STDEV` meluluskan alat pada pita 0,0100–0,0141 mm yang
   menurut definisi ISO 1101 seharusnya ditolak. Satu-satunya butir di seluruh
   berkas ini yang arah kesalahannya **merugikan penerima sertifikat**.

Sisanya bisa menunggu revisi master berikutnya tanpa risiko.
