# Adendum — Handoff Backend 29 Juli 2026

Tambahan buat dokumen *"Perubahan di repo backend — 29 Juli 2026"*. Isinya tiga hal:
**dua perbaikan di luar cakupan handoff**, **koreksi buat angka & instruksi di
dokumen induknya**, dan **satu lubang yang ketemu justru waktu ngecek koreksi itu**
— yang kedua ini nggak bakal ketemu kalau instruksi yang kelihatan cuma "salah tulis"
nggak dicek sampai ke kodenya.

Ditulis setelah suite test dijalanin ulang penuh. Semua angka & klaim di sini dicek
langsung ke kode dan ke hasil test, bukan disalin dari dokumen induk.

Branch masih `feat/penanda-tangan-objek-sertifikat`, masih **belum di-commit**.

---

## 1. Status test: 604/604 — dan kenapa angkanya beda dari dokumen induk

Dokumen induk nutup dengan **"595 dari 597 lolos"**. Sekarang:

```
604 test · 604 lolos · 0 gagal · 2376 assertion · 62 detik
```

Angkanya naik bukan karena ada test yang muncul entah dari mana. Baris status itu
ditulis di akhir **Bagian 2**, sementara **§12 (Bagian 3)** nambah
`WorksheetExtractionGeminiTest` yang isinya persis **7 test**:

| | dokumen induk | + Gemini (§12) | sekarang |
|---|---|---|---|
| total | 597 | +7 | **604** |
| lolos | 595 | +7 | **604** |
| gagal | 2 | — | **0** |

Dua yang tadinya gagal itu udah diperbaiki — lihat bagian 2 di bawah.

> Kalau nanti angkanya nggak cocok lagi, cek dulu apakah ada test baru yang ditambah
> setelah baris status terakhir ditulis. Dokumen yang disusun kronologis gampang
> bikin angka penutupnya basi sebelum dokumennya selesai.

---

## 2. Perbaikan baru: grafik tren keluar 5 bulan, bukan 6

**Di luar cakupan handoff 29 Juli.** Dokumen induk nyebut dua kegagalan
`DashboardTrenTest` sebagai "udah gagal sebelum semua perubahan ini" — itu bener,
tapi penyebabnya lebih licik dari sekadar "bug tanggal", dan sekarang udah beres.

### Akar masalahnya: kalender

`DashboardController::awalDefault()` ngitung titik awal grafik pakai `subMonths()`
polos. Dari tanggal 29–31, itu **nyelonong maju** kalau bulan sasarannya lebih pendek:

```
sampai       : 2026-07-29
subMonths(5) : 2026-03-01   ← 29 Feb 2026 nggak ada, overflow ke 1 Maret
NoOverflow   : 2026-02-28   ← yang dimaui
```

`TrenPekerjaan::awalPeriode()` nge-`startOfMonth()`-in hasil itu jadi **Maret**, dan
grafiknya keluar Mar–Jul = **5 bulan**, bukan 6.

Dua hal yang bikin ini susah ketangkep:

1. **Cuma salah di tanggal ujung bulan.** Dijalanin tanggal 15, `subMonths(5)` =
   15 Feb, hasilnya bener. Jadi bug-nya ilang-timbul ngikut kalender dan gampang
   dikira flaky — termasuk "sembuh sendiri" tanggal 1 Agustus nanti, lalu balik lagi
   di ujung bulan berikutnya.
2. **`grafikPekerjaan()` kebal.** Dia `startOfMonth()` duluan baru `subMonths()`,
   jadi tanggalnya selalu 1 dan overflow nggak mungkin kejadian. Akibatnya
   `/dashboard` ngasih 6 bulan sementara `/dashboard/tren` ngasih 5 — **dua grafik
   beda buat rentang yang sama**, persis hal yang `TrenPekerjaan` dibikin buat
   nyegahnya.

Ironisnya `TrenPekerjaan::majukan()` udah pakai `addMonthNoOverflow()` lengkap sama
komentar yang njelasin bahaya yang sama persis, cuma arah sebaliknya. Penjaganya
dipasang di tempat kursornya maju, tapi nggak di tempat titik awalnya dihitung.

### Yang diubah

Satu baris efektif, `app/Http/Controllers/Api/DashboardController.php`:

```php
- default => $sampai->copy()->subMonths(self::BULAN_GRAFIK - 1),
+ default => $sampai->copy()->subMonthsNoOverflow(self::BULAN_GRAFIK - 1),
```

Plus komentar alasannya, ngikut gaya komentar `TrenPekerjaan::majukan()`.

### Verifikasi

- `DashboardTrenTest` — **19/19** (sebelumnya 17/19)
- Suite penuh — **604/604**
- Disapu **730 tanggal berturut-turut** (2026–2027) lewat logika bucket yang sama:
  semuanya keluar 6 bulan, nol pengecualian. Perbaikannya nggak cuma kebetulan bener
  di tanggal dokumen ini ditulis.

### ⚠️ Yang perlu dicek di sisi mobile

Bentuk responsnya **nggak berubah sama sekali** — nama field, tipe, struktur identik.
Ini murni perbaikan nilai, jadi **nggak ada kode mobile yang wajib diubah**. Yang
kelihatan:

| | sebelum | sesudah |
|---|---|---|
| `GET /dashboard/tren` tanpa penyaring | 5 titik data | **6 titik data** |
| `penyaring.dari` (satuan `bulan`, per 29 Jul) | `2026-03-01` | `2026-02-28` |
| `/dashboard` `grafik_pekerjaan` vs `/dashboard/tren` | beda (6 vs 5) | **sama** |

Dua catatan:

- Kalau ada snapshot test atau layout yang diitung buat 5 batang, itu perlu
  disesuaikan ke 6.
- **Kalau pernah ada laporan "grafik tren cuma 5 bulan padahal Dashboard 6"** — itu
  ini, dan udah beres. Bukan bug rendering di mobile. Dan kalau dulu sempat dicoba
  reproduce di tanggal tengah bulan terus hasilnya normal, itu bukan salah lihat;
  jangan ditutup sebagai "nggak bisa direproduksi".

---

## 3. `queue:listen` masih wajib — dan retry-nya punya lubang (udah ditambal)

Dokumen induk §8 mindahin penerbitan sertifikat dari antrean ke pemanggilan langsung,
tapi §"Cara ngetes ulang" masih nulis `queue:listen` **WAJIB**. Waktu dicek, ternyata
yang salah bukan instruksinya — **instruksinya masih bener, alasannya yang berubah.**

`GenerateCertificate::dispatch()` masih dipakai di **tiga tempat**:

| Tempat | Jalur |
|---|---|
| `CertificateController::retry()` | `POST /certificates/{id}/retry` |
| `CalibrationSessionsTable.php:180` | Panel admin Filament |
| `CertificatesTable.php:119` | Panel admin Filament |

Jadi yang lepas dari antrean cuma jalur **approve**. Buat skrip uji rantai penuh
(login → kirim → validasi → approve → unduh), `queue:listen` emang nggak dibutuhin
lagi. Buat sisanya, masih.

### Lubangnya: retry tanpa worker bikin sertifikat nyangkut permanen

`retry()` ngebalik status ke `menunggu_generate` **sebelum** nge-dispatch:

```php
$certificate->update(['status' => Certificate::STATUS_MENUNGGU_GENERATE]);
GenerateCertificate::dispatch(...);
```

Kalau nggak ada worker yang jalan, urutannya jadi:

1. Sertifikat statusnya `gagal` → tombol retry muncul di layar
2. Admin nekan retry → 200 OK, status jadi `menunggu_generate`
3. Job-nya ngendon di tabel `jobs`, nggak ada yang ngerjain
4. Mobile nunjukin "lagi diproses" — **selamanya**
5. Tombol retry **ilang**, karena komentarnya sendiri bilang statusnya sengaja
   diubah biar "nggak nawarin retry lagi selagi job jalan"

Ini persis mode kegagalan yang §8 dibikin buat ngilangin, cuma pindah dari approve ke
retry — dan di retry efeknya lebih parah, karena dari sisi UI perubahan statusnya
**satu arah**: sertifikat yang tadinya bisa dicoba ulang jadi nggak bisa disentuh
sama sekali tanpa masuk ke database manual.

Yang bikin runcing: retry itu **jalur pemulihan**. Dia dipencet justru waktu udah ada
yang gagal.

### ✅ Udah ditambal

`retry()` sekarang manggil `handle()` langsung dalam `try`, sama kayak `approve()`
di §8:

```php
try {
    (new GenerateCertificate($certificate->calibration_session_id, $request->user()->id))->handle();
} catch (\Throwable $e) {
    Log::warning('Sertifikat gagal dibikin waktu retry.', [...]);
}
```

**Pre-update ke `menunggu_generate` dibuang, bukan dipindah.** Job-nya udah nge-set
status itu sendiri di dalam transaksi tepat sebelum ngerender
(`GenerateCertificate::handle()`), jadi baris di controller emang redundan — dan
justru baris redundan itu yang bikin state-nya satu arah. Dengan dibuang, status
sertifikat **selalu mendarat di keadaan akhir**: `terbit` kalau jadi, `gagal` kalau
nggak, bahkan kalau prosesnya mati total di tengah. Yang gagal tetap nawarin retry.

Responsnya tetap **200 dengan status apa adanya**, termasuk waktu generate-nya gagal
lagi. Yang jawab "berhasil nggak?" itu field `status` di data, bukan kode HTTP-nya —
dan sertifikat yang gagal dua kali tetap butuh tombol retry, bukan halaman error.

**Buat mobile:** respons retry sekarang langsung ngasih hasil akhir. Layar yang dulu
harus nge-poll sesudah nekan retry bisa langsung baca `data.status` dari respons itu
juga. Polling yang udah ada nggak rusak — cuma jadi nggak perlu.

Test `CertificateApiTest::test_admin_retry_sertifikat_gagal_nge_dispatch_ulang`
diganti jadi `..._nerbitin_ulang_langsung`: ngunci status akhir `terbit`, `pdf_path`
keisi, dan `Queue::assertNotPushed()` — biar kalau suatu saat ini balik ke
`dispatch()`, mode macet di atas ketangkep test, bukan ketangkep pelanggan.

> **Dua pemanggil Filament masih lewat antrean** (`CalibrationSessionsTable:180`,
> `CertificatesTable:119`). Sengaja nggak diikutin: itu panel admin desktop yang
> emang jalan di mesin dengan worker, dan aksinya bukan jalur pemulihan satu arah
> kayak retry. Tapi artinya `queue:listen` **tetap wajib** buat panel admin.

---

## 4. `docs/skrip/e2e-ph.py` nggak ada di repo

Dokumen induk §"Cara ngetes ulang" nyuruh jalanin:

```
python docs/skrip/e2e-ph.py http://127.0.0.1:8000/api sertifikat.pdf
```

Folder `docs/skrip/` **nggak pernah kebikin**, dan `e2e-ph.py` nggak ada di mana pun
di repo (dicek juga: `docs/` nggak ke-ignore, jadi bukan soal gitignore). Kayaknya
kelewat nggak jadi disalin dari scratchpad, dan scratchpad sesinya sekarang udah
kosong — file aslinya hilang.

Efeknya: siapa pun yang ngikutin instruksi ngetes ulang bakal mentok di langkah
pertama.

### ✅ Udah ditulis ulang

`docs/skrip/e2e-ph.py` sekarang ada, disusun ulang dari deskripsi rantai ujinya di
dokumen induk dan **diverifikasi jalan** lawan server dev.

```
python docs/skrip/e2e-ph.py [BASE_URL] [FILE_PDF]
python docs/skrip/e2e-ph.py http://127.0.0.1:8000/api sertifikat.pdf
```

Sebelas mata rantai: login teknisi & admin → nyari alat pH + 3 buffer → kirim lembar
kerja 3 titik → cek ketidakpastian tersimpan → buka lembar perhitungan → validasi →
approve → tunggu sertifikat terbit → unduh PDF. Keluarnya `SEMUA MATA RANTAI
TERSAMBUNG` atau `PUTUS DI: <langkah>` + sebab + petunjuk, exit code 0/1.

Empat keputusan yang sengaja diambil:

- **Pustaka standar Python doang**, nggak pakai `requests`. Skrip ini bakal dijalanin
  di mesin orang lain justru waktu ada yang rusak; "pip install dulu" itu rintangan
  tambahan persis di saat orangnya lagi buru-buru.
- **Alat & standar dicari lewat SERIAL, bukan id.** Id auto-increment beda di tiap
  mesin — di mesin ini alat pH-nya `id=6`, di mesin lain bisa apa aja. Serial itu
  identitas barang fisiknya dan sama di mana-mana.
- **`queue:listen` nggak dibutuhin lagi** buat skrip ini, karena approve nerbitin
  langsung (§8). Loop tunggunya tetap dipasang: kalau suatu saat ada yang balikin ke
  `dispatch()`, pesan habis-waktunya yang bakal nunjukin, bukan gantung tanpa sebab.
- **Tiga titik sengaja beda sebaran.** Sesudah perbaikan GUM §7, ketiganya mestinya
  keluar U95 yang beda. Kalau ketiganya identik, skripnya ngasih peringatan yang
  nunjuk langsung ke `GumCalculator::hitungDariKemampuan()` — itu tanda Type A
  kebuang lagi, regresi yang paling gampang lolos dari test.

Hasil jalan terakhir (29 Juli, sore):

```
 6. cek ketidakpastian tersimpan ... OK  -> t1=0.02343 PASS, t2=0.02167 PASS, t3=0.03164 PASS
10. tunggu sertifikat terbit     ... OK  -> CAL/2026/07/0011 (id=12)
11. unduh PDF sertifikat         ... OK  -> sertifikat.pdf (1304 KB)
```

Baris 6 itu bukti §7 hidup di server beneran, bukan cuma di test: tiga titik dengan
sebaran beda ngeluarin tiga U95 beda. Titik 1 (pembacaan `4.00` lima kali, rapat
sempurna) mendarat tepat di lantai CMC `0.02343` — persis perilaku yang dimaui.

> Jalur gagalnya ikut diuji, bukan cuma jalur suksesnya: server mati → `PUTUS DI:
> nyambung ke server`, password salah → `PUTUS DI: login teknisi` + petunjuk
> `php artisan db:seed`. Skrip diagnosis yang jalur gagalnya nggak pernah dicoba itu
> setengah skrip — dia dipakai justru waktu ada yang rusak.

---

## Ringkasan buat yang buru-buru

| # | Hal | Status |
|---|---|---|
| 1 | Status test 604/604 (dokumen induk nulis 595/597) | ✅ dikoreksi di sini |
| 2 | Grafik tren 5 bulan → 6 bulan | ✅ diperbaiki & diverifikasi |
| 3 | Retry tanpa worker bikin sertifikat nyangkut permanen | ✅ ditambal & diverifikasi |
| 4 | `queue:listen` masih wajib buat **panel admin Filament** | ⚠️ sengaja dibiarin, lihat §3 |
| 5 | `docs/skrip/e2e-ph.py` hilang | ✅ ditulis ulang & diverifikasi |

Suite penuh sesudah semua perbaikan di dokumen ini: **604/604**. Uji rantai lawan
server dev: **11/11 tersambung**.

> Catatan kebersihan: `e2e-ph.py` bikin data beneran tiap dijalanin. Dua kali jalan
> sore ini ninggalin sesi `KAL/2026/07/0016` & `0017` + sertifikat `CAL/2026/07/0010`
> & `0011` di `sidik_db`. Itu database dev, tapi tetap perlu tau kalau nanti ada yang
> heran kenapa antrean approval-nya nambah sendiri.
