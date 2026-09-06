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

## Yang TIDAK bisa dijawab dari sini **[TIDAK BISA]**

Bukan karena malas — karena jawabannya berupa fakta yang cuma ada di lab, dan
mengarangnya menghasilkan angka yang kelihatan wajar tapi salah.

| | Kenapa mustahil dari sini |
|---|---|
| **§1 §3 §9** tanda tangan | Perlakuan atas sertifikat yang sudah di tangan pelanggan adalah tindakan Manajer Teknis di bawah klausa Pekerjaan Tidak Sesuai ISO/IEC 17025. Analisis & rekomendasinya sudah siap di `docs/keputusan-lab-micrometer.md` |
| **§10** Muka Ukur | "Dihapus dari metode" lawan "lupa ikut ke kertas Rev.1" — itu riwayat keputusan lab, tidak ada jejaknya di data |
| **K13 K14 K15** standar | Menambah baris standar kalibrasi atau menebak nomor seri = mengarang telusur. Ini jenis karangan yang paling berbahaya: kelihatan wajar dan lolos semua pemeriksaan |
| **K19** empat penyimpangan TIDS | Sudah ditiru + catatan audit + peringatan. Yang tersisa keputusan apakah dipertahankan — dan itu wewenang Manajer Teknis |
| **K20** konstanta Interpolasi | **Sudah dicoba dibongkar dari angkanya.** `0,19788162882115856` tidak terurai jadi konvensi apa pun — bukan `x/√3`, `x/√12`, bukan pecahan sederhana (penyebut < 10⁵ meleset). Artinya dia **angka turunan data**, jadi cuma bisa dari workbook sumbernya |
| **K22** PRT PT100 + recorder | Apakah kombinasi itu pernah dipakai adalah riwayat pemakaian. Blokirnya sudah benar sebagai bawaan |
| **F1 K12 G3 K24** | Barang fisik: foto, hasil ukur, kertas, berkas arsip. Tidak ada penggantinya |

---

## Ringkasan perubahan

| Sebelum | Sesudah |
|---|---|
| 23 butir terbuka | **12 butir terbuka** |
| 2 butir "bisa menggeser lampiran akreditasi" | **1** — §11 gugur sebagai temuan akreditasi |
| §6 menunggu jawaban | Terjawab: konvensi GUM G.4.2, nol perubahan |
| §5 menunggu jawaban | Terjawab: kesalahan kategori, bukan pilihan |
| K21 menunggu jawaban | Terjawab dari keputusan proyek yang sudah ada |
| 5 keputusan produk menggantung | Diputuskan, siap dikerjakan |

**Nol baris kode diubah.** Semua yang di atas temuan dan keputusan; yang
menyentuh angka tercetak tetap menunggu lab, sesuai aturan proyek.
