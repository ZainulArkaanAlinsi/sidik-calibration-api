# Pertanyaan lab — adu ANGKA CETAK kelompok B (PK-5)

**Disusun:** 18 November 2026. **Untuk:** Manajer Teknis dan pemilik proyek.
**Cakupan:** Gaya (Load Cell, UTM, Proving Ring), Enclosure (Inkubator Constant Yokogawa, Oven
Recorder), Thermocouple, Termometer Gelas, Sieve, Autoclave, Timer/Stopwatch, pH Meter, Flowmeter
Ultrasonic (Totalizer, Flowrate). Ditahan dan TIDAK disentuh: Tekanan x3, Timbangan gram `xlsm2`,
CMC ruang lingkup baru Flowmeter, data standar Enclosure 2026, Bath, Picnometer.

Yang diadu: teks yang TERCETAK di `sertifikat.pdf` (renderer asli; sesi diterbitkan lewat
`POST /approve`) lawan teks lembar `SERTIFIKAT` workbook master. Perkakasnya milik PK-4
(`tests/Concerns/{PembandingCetakMaster,MenegakkanCetakMaster,MenerbitkanSesiContoh}.php`);
angka masternya `tests/Fixtures/cetak-master/kelompok-b.json` (generator
`docs/skrip/gen-cetak-master-kelompok-b.py`, memakai ulang pembaca dan pembulatan generator PK-4).
Teks cetak hasil emulasi dicocokkan ke `Range.Text` Excel (COM, `cek-teks-cetak-excel.ps1`):
468 sel di 14 master, 467 identik; satu sisanya (`k` Timer, format General) memang tidak
ditebak generator — Excel mencetak `1.9652`. Pengujinya
`tests/Feature/{Suhu,Flowmeter,SieveAutoclaveTimerPh}SesiContohCocokMasterTest.php` dan satu
metode baru di `GayaSesiContohCocokMasterTest.php`.

Pola baku `docs/pertanyaan-lab-*.md`: kejanggalan master ditiru dan ditanyakan; yang berikut
**tidak dibetulkan diam-diam** — tiap butir dikunci di test dua arah (selisih yang hilang juga gagal).

---

## Aturan baru yang ditemukan: baris TERSEMBUNYI tidak dicetak Excel

`Range.Text` dan openpyxl tetap membaca baris yang disembunyikan; Excel tidak mencetaknya.
Master menyembunyikan baris titik nol (Gaya), baris set point kosong (Enclosure, Thermocouple,
Termometer Gelas, Autoclave, Timer), dan baris `#N/A` (Flowmeter). Pembanding yang membacanya
mengira aplikasi "kehilangan baris". Generator PK-5 membuang baris dan kolom tersembunyi
(daftarnya dicatat di fixture sebagai `baris_tersembunyi`). Inilah jawaban untuk catatan audit
16 Nov ("load_cell 0/7, utm 0/4 baris identik"): tidak ada beda angka, yang beda adalah baris
tersembunyi dan satu hal di PK5-1.

**Konsekuensi untuk PK-4 (#272; berkasnya tidak saya ubah).** Fixture `massa-volume.json` membaca
baris tersembunyi. Terdampak (baris tersembunyi di master, hasil `openpyxl` 18 Nov 2026):

| Temuan PK-4 | Baris master | Kenyataan |
|---|---|---|
| PK4-4 "baris ketiga `Penuh`" Timbangan kg & substitusi | 21 | tersembunyi: master TIDAK mencetaknya, jadi bukan selisih |
| 5 baris `#VALUE!` Anak Timbangan F1; keping 0,1 g (PK4-5) dan 10 g (§3) | 20-36 | tersembunyi: tidak tercetak di master |
| 2 baris galat Graduated Volumetric | 24-25 | tersembunyi |

Angka di baris tersembunyi itu tetap benar-salahnya sebagai uji HITUNGAN, hanya bukan "angka yang
tercetak di sertifikat". Usul: regenerasi `massa-volume.json` dengan penyaring yang sama
(`gen-cetak-master-kelompok-b.py::baris_tercetak`), hapus nasib "selisih" yang ternyata tersembunyi,
dan koreksi PK4-4 di dokumen PK-4.

---

## PK5-1 — Drift standar Load Cell & UTM: master selalu membaca kolom "Tekan", aplikasi memilih "Tarik"

Di ketiga master gaya, `PERHITUNGAN U95%!N13` = `VLOOKUP(Z3; Tabel_Drift; 2; 0)` dan
`Tabel_Drift` = `STANDAR_LOADCELL!$Q$5:$R$7` — kolom `STD` dan `Udrift (%) f.s` **Tekan**. Kolom
"Tarik" (`S5:S7`) ada di lembar yang sama tetapi di luar rentang itu: master tidak pernah
membacanya, apa pun isi `Type of Force`.

| | master (R, Tekan) | aplikasi (sesi Tarik) |
|---|---|---|
| Load Cell, standar 100 kN | 0,031834 (`R6`) | **0,034272** (`S6`) |
| UTM, standar 5 kN | 0,069282 (`R5`) | **0** (`S5`) |

Dampak di sesi contoh: Load Cell U95 tersimpan **0,29761 lawan 0,29734 kN** dan `k` **2,0796 lawan
2,0860** (veff 21,28 lawan 20,95: dipotong 21 lawan 20); UTM `k` **1,97166 lawan 1,96986** (veff 204
lawan 242) dan U hitung sebelum lantai CMC **0,3965 % lawan 0,4190 %** (uc 0,2011 lawan 0,2127;
aplikasi 5,4 % lebih kecil, tidak konservatif). Cetak SAMA (0,30 kN; 2,1 kgf karena lantai CMC
menang di kedua sisi; k = 2), sehingga tidak ketahuan di PDF — tetapi pada sesi UTM yang U
hitungnya di atas lantai, selisih ini akan tercetak. Jejak sesi tidak mencatat selisih ini (AGENTS.md §Olah data butir 4); G2
menanyakan NILAI drift Tarik, bukan fakta bahwa master tidak membacanya.

**Ditanyakan:** untuk sesi Tarik, drift yang benar kolom Tekan (seperti master) atau Tarik? Bila
Tarik, apakah sertifikat gaya yang terbit dari master (drift Tekan untuk beban Tarik) perlu ditinjau?
Sampai dijawab, sistem tetap memakai Tarik; catatan di jejak sesi perlu ditambahkan (kode, belum
dikerjakan: di luar paket test).

## PK5-2 — Proving Ring: U95 tercetak 2,3 kgf, master 22,4 kgf (G14 yang sudah ada)

Bukan temuan baru; sekarang terkunci di test (`P38`, baris 38). Jejak sesi
(`penyimpangan_master`) mencatat G3, G6, G8, G11 tetapi **tidak** G14 (pita CMC dari baris yang
salah); `gaya_budget` hanya memuat `cmc_kn`, `u95_kn`, `lantai_cmc_menang`. Keputusan G14 tetap
milik TM — ini hanya menandai bahwa penyimpangan 10x itu belum terbaca di jejak sesi.

## PK5-3 — Enclosure Recorder: blok SET POINT 3 master rusak MENGUBAH Sebaran Suhu tercetak

`pertanyaan-lab-enclosure.md` §5(b) menulis bahwa kerusakan blok SP3 "tidak mengubah sertifikat".
Itu benar untuk U95 (lantai CMC 1,5) tetapi tidak untuk Sebaran Suhu. Master SP3 mencetak
(sensor 1-4) Terukur 67,11 · 67,62 · 68,84 · 67,84 dan Koreksi -0,25 · 0,26 · 1,48 · 0,48;
aplikasi 67,03 · 67,54 · 68,76 · 67,76 dan -0,33 · 0,18 · 1,40 · 0,40 (selisih 0,08 = koreksi
sensor yang di master jatuh ke 0). Variasi Keseluruhan SP3: master 2,72, aplikasi 2,65. Variasi
SP1 dan SP2: master 2,64, aplikasi 2,65 (keputusan §4, 15 Okt 2026: kelima pembacaan dipakai;
bedanya dari sensor 5 pembacaan ke-5 = 66,50). Inkubator Constant Yokogawa: SEMBILAN sel §4
persis seperti yang dicatat 15 Okt (delapan baris), jejak sesi `peta_kolom_master_rusak` ada.

**Ditanyakan:** konfirmasi bahwa blok SP3 Recorder salah sel (§5), dan apakah sertifikat Recorder
yang sudah terbit (Terukur/Koreksi SP3 sensor 1-4 +0,08) perlu ditinjau.

## PK5-4 — Autoclave: keputusan "titik ke-5 ikut" tidak tercatat di hasil sesi

Master membuang titik waktu ke-5 dan menggandakan ke-3 (komentar `AutoclaveCalculator`); sistem
merata-ratakan kelimanya. Akibatnya cetak: Terukur disk 1 121,39 -> **121,40**, disk 2 121,47 ->
**121,46**, Koreksi 0,39/0,47 -> **0,40/0,46**, Keseragaman 0,466 -> **0,464** (tercetak 0,47 ->
0,46). U95 suhu 0,44194 dan tekanan 0,0059, `k` suhu dan tekanan, Kestabilan, Variasi: SAMA persis
master (1e-9). `hasil_autoclave` tidak memuat catatan penyimpangan sama sekali — hanya komentar
kode (§Olah data butir 4: komentar tidak sampai ke orang yang menyetujui sesi).

**Ditanyakan:** tidak ada pertanyaan baru ke lab (keputusan pemilik sudah final). Usul teknis:
tambahkan blok catatan di `hasil_autoclave` (kode, belum dikerjakan).

## PK5-5 — Termometer Gelas: master memformat `J21:J23` General, `J24:J25` `0.0`

Pembacaan UUT master tercetak `30`, `51`, `61`, lalu `82.0`, `99.0`; aplikasi seragam satu desimal
(`30.0`, `51.0`, `61.0`, `82.0`, `99.0`; resolusi alat 1 °C). Kosmetik, bukan angka. U95 tersimpan
1,1262 lawan master 1,1174 = keputusan ÷√5 yang sudah di `pertanyaan-lab-suhu-3alat.md` (catatan
`pengulangan_standar_dibagi_n` ada di jejak); cetak 1,1 sama.

**Ditanyakan:** apakah sertifikat Termometer Gelas boleh mencetak seragam satu desimal (bentuk
aplikasi), atau harus meniru format campuran master?

## PK5-6 — Timer: `k` tercetak 1.964, master 1.9652

Sel `SERTIFIKAT!V29` master berformat General: Excel mencetak `1.9652` (nilai 1,965168). Aplikasi
mencetak `1.964` (tiga desimal, B3a) dari `k` tersimpan 1,96400 (titik 1). Bedanya dua lapis: format
(tiga lawan empat desimal) DAN nilai (1,96400 lawan 1,96517). Penyebab nilai belum dibuktikan —
dugaan: uHRTB `MAX(N21:N22)` yang dibuat sadar berbeda (`WaktuFrekuensiMasterTest`) menggeser
derajat bebas. U95 tercetak 0,8 sama.

**Ditanyakan:** `k` Timer dicetak berapa desimal, dan apakah selisih nilai 0,0012 diterima?

## PK5-7 — Flowmeter: tiga perbaikan tidak punya catatan di jejak sesi

Totalizer titik 2 (Standard Indication 1883,88 / Correction -18,89, master 1883,86 / -18,91) dan
Flowrate titik 2 (U95 1,2 %OR, master 1,0) adalah perbaikan sengaja yang dijelaskan di docblock
`FlowmeterSeeder` dan `pertanyaan-lab-flowmeter.md` §2 dan §12. `type_b_components` sesinya hanya
berisi ringkasan `jejak_titik`, tidak ada catatan bertanda sumber/selisih terhadap master
(§Olah data butir 4). Titik 1 kedua sesi dan `k` SAMA dengan master; U95 Totalizer dan Flowrate
titik 1 sama sebagai %OR terhadap bacaan UUT.

**Ditanyakan:** tidak ada pertanyaan baru; usul teknis: tambahkan catatan `penyimpangan_master`
(kode, belum dikerjakan).

---

## Yang cocok penuh

Thermocouple (5 baris + U95 + `k`), pH Meter "for trial" (4 baris, U95 dan `k` sampai 1e-9), Load Cell
dan UTM (semua baris tabel hasil), Sieve selain dua baris §4 (`pertanyaan-lab-sieve.md`: koreksi
caliper +0,01 mm), Flowmeter titik 1.

## Tidak dikerjakan, dan alasannya

- **Ditahan sesuai tugas:** Tekanan x3 (TM P-1/P-2/P-11), Timbangan gram `xlsm2`, CMC ruang lingkup
  baru Flowmeter, data standar Enclosure 2026 (`Master Olah Data_Suhu_Enclosure_Yokogawa.xlsm`,
  tidak dibuatkan fixture), Bath, Picnometer.
- **Tanpa master sampel / sesi contoh:** Furnace dan Refrigerator (kode cetak sama dengan Oven dan
  Inkubator: `EnclosureProfileBase`); pH IMTE-WQ-129 (G8; fixture cetak sudah dibekukan sebagai
  `ph/imte_wq_129`, sesinya belum ada — termometer standar berbeda, `pertanyaan-lab-ph-dua-master.md`);
  Flowmeter Totalizer 140-2500 L, Flowrate 100-980 Lpm, dan dua master "Weighing metode"
  (gravimetri, G8): seeder memakai master Ultrasonic. Membangun sesi API dari `INPUT DATA`
  masing-masing = pekerjaan tambahan.
- **Komponen budget per `ui x ci`:** tidak diulang di tes cetak; sudah diadu ke master di
  `AutoclaveCalculatorTest`, `EnclosureBudgetTest`, `FlowmeterMasterTest`, `GayaBudgetTest` (UTM),
  `PhMeterMasterTest`, `SieveMasterTest`, `WaktuFrekuensiMasterTest`. Load Cell tidak punya tes budget
  ke master — hanya komponen drift yang dibandingkan di sini (PK5-1).
- **MySQL:** tes baru belum dijalankan di MySQL (gerbang CI).
