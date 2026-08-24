# Pertanyaan ke Lab — Enclosure (Oven, Furnace, Bath, Inkubator, Refrigerator)

Status: menunggu jawaban · Dibuat 23 Agustus 2026 · Sumber:
`Master Olah Data_Suhu_Enclosure_Constant_Yokogawa.xlsm` (sesi **0123-CAL-524**,
order 2405.03.AV, PT Freshland Inovasi Sejahtera, Incubator-02 INCUCELL
LSIS-B2Y/IC 55, kalibrator **Yokogawa CA 150** + Thermocouple **Type N**, 4 set
point 15/35/75/100 °C) dan
`Master Olah Data_Suhu_Enclosure_Recorder.xlsm` (sesi **0304-CAL-624**, order
2406.25.AI, PT Gunung Madu Plantations, Oven Memmert UN260, kalibrator
**Recorder Graphtech GL840** + Thermocouple **Type K**, 3 set point @ 67 °C).

Backend Enclosure (alat ke-12, lima jenis) sudah jalan penuh. Budget kedua
master diadu ke workbook sampai digit terakhir — `Tests\Unit\EnclosureBudgetTest`
& `Tests\Feature\EnclosureSesiTest`, hijau di SQLite maupun MySQL. Hal-hal di
bawah **tidak** bisa diputuskan dari berkas yang ada; semuanya sudah diberi
keputusan sementara (reproduksi apa adanya, dengan catatan audit) supaya
pekerjaan tidak berhenti.

Nomor **1–11** berasal dari isi workbook. Nomor **12–13** beda jenisnya: itu
pertanyaan yang muncul justru karena master TIDAK memuatnya — lembar kerja yang
terisi sebagian, yang tidak pernah ada contohnya di berkas.

Ringkasan cara baca: tiap set point enclosure menghitung U95 sendiri dari GRID
9 termokopel × 5 pembacaan + Indikator enclosure. Karena **U95 yang dilaporkan =
MAX(U hitung, CMC)** (lantai CMC ILAC-P14), dan di kedua sesi contoh CMC menang
(Inkubator 1,4 °C, Oven 1,5 °C), sebagian besar kejanggalan di bawah **tidak
mengubah angka yang tercetak di sertifikat** — tapi akan berpengaruh di sesi
lain yang U hitungnya melewati CMC. Yang begitu ditandai jelas.

---

## 1. Dua master, dua budget yang beda STRUKTUR — bukan cuma angka

Budget Constant/Yokogawa punya **11 komponen**, Recorder **10**. Bedanya bukan
salin-angka biasa; beberappa terlihat seperti keputusan, beberapa seperti
salin-tempel yang lupa diselaraskan:

| Komponen | Constant/Yokogawa | Recorder |
|---|---|---|
| Efek Radiasi | **0,6 °C** (`N31`) | **0,1 °C** (`N31`) |
| Efek Pembebanan | **20 % × deviasi maks** (`20/100*R42`) | **0,1 °C** konstan (`N32`) |
| Konduksi Panas | **0,1 °C** (`N33`) | **tidak ada** |
| pembagi drift kalibrator & sensor | `1,73` literal | `1,73` literal |
| pembagi Pengulangan Standar | `√3` benar | **`√(√3)`** (lihat #3) |
| `vi` drift / radiasi / pembebanan | 1.000.000 | 50 / 8 / 8 (kecil) |

**Yang dipakai sekarang:** masing-masing ditiru apa adanya per workbook.

**Yang ditanyakan (paling penting):** mohon konfirmasi mana yang benar untuk
tiap komponen —

- **Efek Radiasi 0,6 vs 0,1:** dua master bicara dua angka untuk pengaruh
  fisik yang sama. Mana yang berlaku untuk enclosure secara umum?
- **Efek Pembebanan:** master Constant/Yokogawa menghitungnya dari data
  (20 % × deviasi spasial maksimum antar termokopel — 0,04…0,11 °C di sesi
  contoh), master Recorder mematoknya 0,1 °C konstan berapa pun keseragaman
  ruangnya. Yang mana aturan lab — dihitung dari data, atau angka baku?
- **Konduksi Panas:** memang tidak diperlukan untuk kalibrasi mode Recorder,
  atau barisnya lupa disalin waktu master Recorder dibuat?

---

## 2. Pembagi komponen drift ditulis `1,73` literal, bukan `√3`

Komponen "Drift Temp. Kalibrator" dan "Drift Sensor" di kedua master memakai
pembagi **`1,73`** (`Q26`/`Q27` diketik konstanta), padahal distribusinya
persegi (rect.) yang pembaginya `√3 = 1,7320508…`, dan seluruh komponen persegi
lain di workbook yang sama (Resolusi, Radiasi, Pembebanan, dst.) memakai
`SQRT(3)` penuh.

**Dampak terukur:** drift kalibrator `u` naik dari 0,28868 (÷√3) ke 0,28902
(÷1,73); drift sensor 0,31763 → 0,31792. Lewat RSS efeknya ke `Uc` cuma ±0,0002
°C — tidak menggeser U95 yang dilaporkan (tetap 1,5 °C, lantai CMC). Bukan salah
yang kelihatan di sertifikat, tapi tetap dua konstanta beda untuk maksud sama.

**Yang dipakai sekarang:** `1,73` literal (`EnclosureCalculator::PEMBAGI_DRIFT`),
dengan catatan audit yang menyebut hasil versi `√3`.

**Yang ditanyakan:** `1,73` itu pembulatan baku lab untuk √3 di prosedur
Enclosure, atau seharusnya `SQRT(3)` presisi penuh?

## 3. Pembagi Pengulangan Standar di master Recorder: `√(√3)`, bukan `√3`

Persis kelas bug yang sama dengan "AC Pick Up" di TITS. Sel Recorder:

```
U29 = N29 / SQRT(Q29)      dengan  Q29 = SQRT(3)
```

Akar diambil DUA KALI, jadi pembaginya `3^0,25 ≈ 1,3161`, bukan `√3 = 1,7321`.
Master Constant/Yokogawa memakai `√3` yang benar di komponen yang sama.

**Dampak terukur:** untuk sesi Oven contoh, komponen ini kecil (½ × spread = 0,02
°C), jadi efek ke `Uc` di bawah 0,001 °C dan U95 dilaporkan tetap 1,5 (lantai
CMC). Tapi di enclosure yang keseragaman/kestabilannya buruk (spread besar),
komponen ini membesar dan pembagi salah jadi berpengaruh.

**Yang dipakai sekarang:** `√(√3)`
(`EnclosureCalculator::PEMBAGI_PENGULANGAN_RECORDER`), dengan catatan audit yang
menyebut hasil versi `√3`.

**Yang ditanyakan:** pembaginya `√3` (yang sesuai label rect.), atau memang
`√(√3)` seperti yang mereproduksi sertifikat 0304-CAL-624?

## 4. Baris termokopel di sheet FC membuang pembacaan ke-5 dan menggandakan ke-3

Di sheet `PERHITUNGAN FC`, tiap baris termokopel menyalin lima kolom pembacaan
`INPUT DATA` (D=1, F=2, H=3, I=4, K=5) jadi **`[1, 2, 3, 3, 4]`** — kolom G & H
sama-sama menunjuk pembacaan ke-3, dan pembacaan ke-5 tidak pernah dibaca:

```
G23 = 'INPUT DATA'!H##   (pembacaan 3)
H23 = 'INPUT DATA'!H##   (pembacaan 3 lagi)   ← seharusnya I## (pembacaan 4)
I23 = 'INPUT DATA'!I##   (pembacaan 4)          ← seharusnya K## (pembacaan 5)
```

Baris **Indikator** (`D37:I37`) justru menyalin kelimanya dengan benar
(`H, I, K`). Jadi kejanggalan ini khusus baris termokopel.

**Dampak:** cuma terasa waktu pembacaan ke-5 ≠ ke-3 dalam satu sensor. Contoh
di sesi Yokogawa SP4 (100 °C) sensor No.5: mentah
`[100,24; 100,1; 100,1; 100,24; 100,22]`, master pakai
`[100,24; 100,1; 100,1; 100,1; 100,24]` → AVG Terkoreksi bergeser ~0,02 °C. Angka
itu **tercetak** di kolom Sebaran Suhu sertifikat. U95 dilaporkan tidak berubah
(lantai CMC).

**Yang dipakai sekarang:** ditiru (`EnclosureCalculator::PETA_KOLOM_PEMBACAAN =
[0,1,2,2,3]`), supaya kolom Sebaran sama dengan sertifikat yang terbit, dengan
catatan audit `peta_kolom_pembacaan`.

**Yang ditanyakan:** ini memang disengaja, atau salah salin rumus di baris
termokopel? Kalau salah, seharusnya kelima pembacaan dipakai apa adanya (dan
sertifikat lama sebaran-nya ikut berubah sedikit).

## 5. Blok SET POINT 3 kedua master rusak sel-nya (tidak mengubah sertifikat)

Set point ke-3 di KEDUA master punya sel yang keliru — dan menariknya
tidak mengubah U95 yang tercetak karena lantai CMC menang:

**(a) Yokogawa SP3 (75 °C):** sel `O75` (komponen "Ketidakpastian Baku
Temperature Kalibrator") berisi rumus **DRIFT** (`VLOOKUP(...Tabel_Drift...)` →
0,035) alih-alih U95 kalibrator (0,24) seperti SP1/SP2/SP4. Akibatnya `Uc`
master SP3 = 0,6234, padahal seharusnya 0,6346.

**(b) Recorder SP3 (67 °C):** meski datanya identik dengan SP1/SP2, (i) koreksi
sensor jatuh ke `0` (seharusnya −0,08 seperti SP1), dan (ii) sel `v_eff`
menghasilkan 1620 padahal komponennya sama persis dengan SP1 (`v_eff` = 298).

**Yang dipakai sekarang:** kalkulator menghitung SP3 **dengan BENAR & konsisten**
(Yokogawa 0,6346; Recorder sama dengan SP1), TIDAK menyalin bug sel per-blok.
Yang tercetak (1,4 dan 1,5, lantai CMC) tetap sama dengan sertifikat.

**Yang ditanyakan:** konfirmasi bahwa blok SP3 di kedua master memang salah sel
(bukan ada maksud yang kami lewatkan), supaya master-nya bisa dibetulkan. Untuk
sesi mendatang yang U hitungnya di atas CMC, versi yang benar (yang dipakai
sistem) yang berlaku.

## 6. `v_eff` tidak dipotong ke bawah sebelum dicari `k`

Master enclosure memakai polinomial t-student atas `v_eff` pecahan apa adanya
(`AC## = 1.95996 + 2.37356/v + …`), sedangkan sepuluh alat lain di sistem ini
memotong ke bawah dulu (GUM G.4.1). Untuk enclosure `v_eff`-nya besar (298 di
Recorder, 1437 di Yokogawa), jadi selisih potong/tidak praktis nol
(`k` beda di desimal ke-5). Beda dari TITS yang `v_eff`-nya kecil.

**Yang dipakai sekarang:** tidak dipotong (`EnclosureCalculator::FLOOR_V_EFF =
false`), dengan catatan audit.

**Yang ditanyakan:** sekadar konfirmasi kebijakan — Enclosure sengaja tidak
memotong (beda dari 10 alat lain), atau mau diseragamkan? Kalau diseragamkan,
`GumCalculator` sudah floor secara default; tinggal ubah satu konstanta.

## 7. `#REF!` PT100 di 300 °C dan 400 °C (master Constant/Yokogawa)

`SENSOR PT100!E16`/`E17` berisi `='FC Prt Pt100'!#REF!` — referensi rusak, menjalar
ke `STANDAR KALIBRATOR!C71`/`C72`. Kalau ada sesi Enclosure yang pakai sensor
PT100/RTD di dua titik itu, koreksinya tidak bisa dihitung dari master ini.

**Yang dipakai sekarang:** modul v1 mendukung **Type N & Type K** saja (dua yang
ada di sesi contoh & lengkap tabelnya). PT100/RTD belum diaktifkan.

**Yang ditanyakan:** (a) apakah Enclosure perlu dukung sensor PT100/RTD; kalau
ya, (b) berapa koreksi PT100 yang benar di 300 °C & 400 °C? (Kemungkinan ada di
file `FC Prt Pt100.xlsx` terpisah yang tidak ikut dikirim.)

## 8. Standar Recorder (GL840) berstatus EXPIRED di master yang jadi acuan

`DATABASE` menandai Temperature Recorder Graphtech GL840 (S/N `C305B1470`) sudah
lewat masa berlaku (~31 hari, dihitung dari `NOW()` saat ekstraksi). Ini bukan
arsip mati seperti `Old_Std_Kalibrator` — instrumen ini dipakai aktif sebagai
kalibrator utama master Recorder yang jadi acuan angka 0304-CAL-624.

**Yang dipakai sekarang:** di data demo masa berlakunya dipanjangkan supaya sesi
bisa jalan; di produksi statusnya ikut master `standards`.

**Yang ditanyakan:** ada sertifikat GL840 terbaru yang belum diperbarui, atau
master Recorder ini memang menunggu re-kalibrasi standarnya? Kalau menunggu,
sesi Enclosure mode Recorder sebaiknya tidak jadi acuan sampai VALID lagi.

## 9. `k` dicetak presisi penuh di sertifikat

`SERTIFIKAT!P37` (Constant/Yokogawa) = `1,9616120828…` — dicetak apa adanya,
bukan dibulatkan ke 2 desimal seperti kebiasaan alat lain (TITS `k=2,40`).
Catatan internal sel menyiratkan format `0` desimal, tapi hasilnya tidak
diformat.

**Yang dipakai sekarang:** dua desimal (`desimalFaktorCakupan()` → 2) supaya
tetap kebaca dan tidak menyesatkan pembaca sertifikat.

**Yang ditanyakan:** berapa desimal `k` yang benar untuk sertifikat Enclosure —
2 (konsisten dengan alat lain) atau presisi penuh seperti master ini?

## 10. U95 sensor & kalibrator tampak DATAR di seluruh rentang suhu

- Recorder `Tabel_u95Rec`: Type N seragam `0,83` dan Type K seragam `0,67` di
  semua kolom suhu.
- Constant/Yokogawa `U95_Sensor` Type N: `0,76` di titik 15/35 (index 25) maupun
  75/100 (index 100) — dua level suhu, angka identik.

**Yang ditanyakan:** U95 sensor/termokopel memang konstan di seluruh rentang per
spesifikasi sertifikat sensor (masuk akal untuk satu sensor), atau ini kolom
lookup yang belum diisi bertingkat per rentang?

## 11. Hal-hal kecil

**(a) Nomor formulir lembar kerja Enclosure belum ada.** Satu-satunya nomor di
berkas `SIDIK-FM-CAL-2403_Rev. 0` (`SERTIFIKAT!B73`) itu formulir SERTIFIKAT
bersama, bukan lembar kerja Enclosure. `kode_dokumen` lembar kerja dikosongkan
(null); mohon nomor formulir lembar kerja Enclosure-nya. (Sama temuan TITS.)

**(b) CMC seragam di seluruh RLK tiap tipe enclosure.** `DATABASE!R5:T9`:
Oven 1,5 (amb–300 °C), Furnace 3,0 (300–1000 °C), Bath 1,2 (0–100 °C),
Inkubator 1,4 (15–100 °C), Refrigerator 1,5 (−20–10 °C) — satu CMC untuk seluruh
rentang. Konfirmasi: memang seragam per lampiran akreditasi, atau ada pemecahan
sub-rentang CMC yang belum tercermin di Excel?

**(c) Resolusi Standar di-hardcode.** Budget membaca `DATABASE!V13`/`W13`
(resolusi kalibrator Constant/Recorder, 0,1 °C) tanpa peduli kalibrator mana yang
dipakai. Kebetulan kedua meter beresolusi 0,1 jadi tidak berdampak; dicatat saja.

**(d) Referensi workbook eksternal terputus.** Master memuat link `[3]`, `[4]`,
`[5]`, `[6]`, `[7]` ke file yang tidak ikut dikirim (nilai cached terakhirnya
masih terbaca). Mohon file-nya, atau konfirmasi nilai cached sudah final dan
link-nya sengaja dilepas jadi riwayat.

**(e) Jumlah set point.** Template `INPUT DATA` Constant/Yokogawa menyediakan 6
set point; sesi contoh mengisi 4 (15/35/75/100), dan baris SP5/SP6 kosong keluar
`#DIV/0!` di `SERTIFIKAT`. Recorder tetap 3 @ 67 °C. Sistem memperlakukan jumlah
set point sebagai data (bebas per kapasitas alat). Konfirmasi: maksimum set
point yang benar-benar didukung — 4, 6, atau tergantung jenis enclosure?

---

## 12. Batas kelengkapan grid: berapa pembacaan & berapa termokopel minimum?

Master selalu **9 termokopel × 5 pembacaan**, jadi berkasnya tidak pernah
menjawab apa yang harus terjadi kalau lembar kerjanya terisi sebagian. Ini bukan
kasus teoretis: teknisi menyimpan lembar setengah jadi, dan set point yang
kurang lengkap tetap punya kolom hasil yang TERCETAK di sertifikat (Sebaran
Suhu, Keseragaman, Kestabilan) — bukan cuma U95 yang bisa tertutup lantai CMC.

**Yang dipakai sekarang — dua ambang, dua-duanya menolak menghitung** (set
point-nya masuk `belum_dihitung` dengan alasan yang menyebut termokopelnya,
sisanya di sesi yang sama tetap dihitung):

**(a) Minimal 4 pembacaan per termokopel.** Peta kolom master menyalin
`[1,2,3,3,4]` — pembacaan ke-5 dibuang, ke-3 digandakan (lihat #4). Jadi grid 4
pembacaan menghasilkan angka yang **persis sama** dengan grid 5 pembacaan, isi
kolom kelimanya berapa pun; 4 itu batas alami, bukan pilihan.

Di bawah 4 tidak ada nilai untuk kolom yang hilang selain menebaknya. Kode
sebelumnya menambal dengan **mengulang pembacaan terakhir** — pada grid 3
pembacaan itu menggeser rata-rata sensor di orde **0,06 °C**, cukup untuk
mengubah kolom Sebaran Suhu yang dicetak satu desimal. Sekarang tidak ditambal.

**(b) Minimal 2 termokopel per set point.** Keseragaman & Variasi Keseluruhan
itu selisih antar-POSISI di dalam chamber. Dengan satu termokopel keduanya
keluar **0,0** — dan `0,0` di kolom Keseragaman dibaca pelanggan sebagai "sudah
dibuktikan seragam", padahal yang benar "belum diukur". Dua adalah batas di mana
besaran itu mulai punya isi sama sekali; bukan batas yang benar secara metrologi.

**Yang ditanyakan:**

1. **Minimum termokopel yang sah menurut lab berapa?** Batas 2 sengaja dipasang
   longgar supaya chamber kecil yang memang dipetakan dengan titik lebih sedikit
   tidak terblokir. Kalau IK `SIDIK-IK-CAL-0501` mewajibkan 9 (atau 5, atau
   bergantung volume chamber), angkanya tinggal diganti — set point dengan
   termokopel di bawah master sekarang cuma dicatat di jejak audit
   (`sensor_kurang_dari_master`), tidak ditolak.
2. **Pembacaan kurang dari 4: tolak (seperti sekarang), atau hitung apa adanya
   dengan catatan?** Menolak berarti teknisi wajib melengkapi kolomnya. Bisa
   dibalik jadi peringatan kalau lab memandang 3 pembacaan tetap sah.
3. **Set point yang cuma terisi baris Indikator** (tanpa termokopel sama sekali)
   sekarang **tetap disimpan** tapi tidak dihitung — supaya lembar setengah jadi
   tidak hilang waktu disimpan ulang. Konfirmasi itu perilaku yang diinginkan.

## 13. Sensor Acuan disimpulkan, bukan dicatat

Keseragaman diukur relatif ke **Sensor Acuan** = baris pertama grid (baris 23
master). Tapi yang tersimpan cuma nomor termokopelnya — tidak ada satu kolom pun
yang menyatakan "ini acuannya". Jadi acuannya harus DISIMPULKAN, dan aturan
penyimpulannya wajib sama di ketiga jalur yang menyusun grid (simpan dari
request, validasi sebelum approve, hitung ulang dari data tersimpan).

**Yang dipakai sekarang:** acuan = **nomor termokopel TERKECIL** yang terisi di
set point itu, diurutkan di dalam kalkulator supaya ketiga jalur tidak mungkin
berbeda. Dipilih karena cocok dengan kedua master: baris "Sensor Acuan" di
dua-duanya memang nomor terkecil (Type N mulai no. 3, Type K mulai no. 1). Nomor
yang benar-benar dipakai ikut dicetak di jejak audit (`sensor_acuan`).

Sebelumnya acuannya diambil dari urutan array yang dikirim, dan itu **bug
nyata**: dua jalur lain membaca balik dari database yang terkelompok per nomor
sensor, jadi grid yang tidak dikirim urut nomor menghasilkan Keseragaman
**0,4 °C di satu jalur dan 0,2 °C di jalur lain** — dua kali lipat, dari data
mentah yang sama.

**Yang ditanyakan (dua hal):**

1. **Apakah acuan memang selalu nomor terkecil?** Kalau Sensor Acuan itu
   sebenarnya **posisi tertentu di chamber** (mis. selalu titik tengah) yang
   nomor termokopelnya berganti tiap sesi, aturan "nomor terkecil" bisa salah
   diam-diam. Kalau begitu, nomornya perlu jadi **field sesi sendiri** — dicatat
   teknisi, bukan disimpulkan.
2. **Kalau termokopel acuan tidak terisi**, sekarang nomor berikutnya yang naik
   jadi acuan. Apakah itu benar, atau set point-nya harus ditolak?

---

## Yang TIDAK ditanyakan karena sudah jelas / tidak berdampak

- **Presisi floating-point kosmetik** (mis. `Tabel_Drift_Yokogawa!AA7 =
  0.034999999999999996`, beberapa sel bernilai `2.84e-15`) — representasi biner
  biasa dari 0,035 / 0. Dibulatkan saat disimpan ke `decimal(20,8)`; sudah
  dibersihkan waktu ekstraksi tabel.
- **`Old_Std_Kalibrator` (Recorder) penuh `#REF!`** — arsip mati device 6100A
  2021 yang sudah diganti GL840. Tidak ada formula aktif yang menariknya.
- **Titik 1400 & 1700 kolom Type N/S bernilai 0** di tabel koreksi & U95 — sel
  kosong yang diisi nol, bukan koreksi nol terukur. Tidak ada sesi yang mencapai
  titik itu; datanya disimpan apa adanya.
- **Nama range `Tabel_Drift_Victor`** (peninggalan kalibrator lama) menunjuk
  kolom Yokogawa yang benar — sudah dikonfirmasi di modul TITS.
