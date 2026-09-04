# Pertanyaan lab dari audit bug 2 Sep 2026

Lima hal yang **tidak bisa diputuskan dari kode**. Dua yang pertama menyangkut
angka atau tulisan di dokumen terakreditasi, dan menebaknya berarti menulis
sesuatu ke sertifikat pelanggan berdasarkan tebakan. Tiga terakhir soal
infrastruktur dan desain backend — T4 & T5 menyusul dari review otomatis pada
PR audit ini.

**Status per 4 Sep 2026: T3, T4 dan T5 sudah dijawab. T1 dan T2 masih menunggu
dokumen lab.**

Yang membedakan dua kelompok itu bukan susahnya, tapi **di mana buktinya
tinggal.** T3/T4/T5 bisa dijawab dari repo ini — plan Render tertulis di
`render.yaml`, pemakaian label bisa dihitung dengan `grep`, pemicu scheduler
terdaftar di `routes/console.php`. T1 dan T2 tidak: jawabannya ada di IK dan di
sertifikat terbit, dan tidak ada perintah yang bisa memulangkannya.

Selama T1 & T2 belum dijawab, kodenya berperilaku seperti yang ditulis di bawah
— dan perilaku itu dipilih supaya yang salah kelihatan, bukan supaya diam.

---

## T1 — Termometer Gelas: satu pembacaan UUT itu sah atau tidak?

**MASIH TERBUKA.** Butuh satu kalimat dari IK Termometer Gelas.

**Konteks.** Empat kalkulator suhu di sistem ini menuntut minimal dua pembacaan
di kedua sisi (standar & UUT), kecuali satu:

```
ThermometerGlassCalculator   count($standar) < 2 || count($uut) < 1   ← sebelum diperbaiki
ThermocoupleCalculator       count($standar) < 2 || count($uut) < 2
ThermohygroCalculator        count($standar) < 2 || count($uut) < 2
```

**Kenapa itu masalah, apa pun jawabannya.** `stdev()` memulangkan `0.0` untuk
n < 2, dan nilai itu masuk komponen budget `pengulangan_uut` yang
`'disertakan' => true` tanpa syarat. Jadi satu pembacaan tidak menghasilkan
"tidak ada sebaran" — dia menghasilkan **"sebarannya nol"**, dan U95% yang
tercetak jadi lebih kecil dari yang bisa dipertanggungjawabkan.

**Yang sudah dilakukan.** Ambangnya disamakan dengan tiga saudaranya
(`count($uut) < 2`, `ThermometerGlassCalculator.php:222`). Titik yang belum
punya dua pembacaan **ditahan dari perhitungan dengan alasan yang kebaca** —
bukan menolak kirimannya: sesinya tetap tersimpan, teknisi tidak terhalang di
lapangan, dan penerbitan sertifikatnya yang ketahan.

**Kenapa dibiarkan terbuka, bukan ditebak.** Dua arah salahnya tidak setara.
Kalau ambangnya salah terlalu ketat, akibatnya satu titik ketahan dan seseorang
bertanya — kelihatan, dan gratis diperbaiki. Kalau `pengulangan_uut` dikeluarkan
dari budget padahal labnya memang membaca UUT dua kali, akibatnya **komponen
ketidakpastian yang nyata hilang dari sertifikat terakreditasi**, dan tidak ada
yang bertanya karena tidak ada yang kelihatan. Menebak ke arah kedua berarti
memilih kesalahan yang senyap.

**Ini menjawab dirinya sendiri kalau dipakai.** Pesan penahannya sudah menyebut
angkanya:

> Titik 100,0 °C baru punya 2 pembacaan standar & 1 pembacaan UUT — tiap sisi
> butuh minimal 2 biar STDEV-nya ada artinya.

Jadi begitu ada teknisi yang mendarat di situ, salah satu dari dua hal terjadi,
dan dua-duanya menjawab T1: dia mengambil pembacaan kedua (berarti prosedurnya
memang ≥ 2), atau dia melapor "prosedur kami cuma sekali" (berarti jawabannya
yang satunya). Tidak perlu menunggu rapat.

**Yang ditanyakan.** Apakah prosedur Termometer Gelas memang hanya mengambil
SATU pembacaan UUT (misalnya karena dibaca sekali pada kesetimbangan)?

- **Kalau TIDAK** — tidak ada yang perlu diubah lagi. Ambang 2 sudah benar.
- **Kalau IYA** — yang benar bukan mengembalikan `< 1`, tapi **mengeluarkan
  komponen `pengulangan_uut` dari budget** untuk alat ini (`'disertakan' =>
  false`). Menyimpan nolnya berarti budgetnya mengklaim komponen yang tidak
  pernah diukur, dan itu justru yang bikin U95-nya terlalu kecil.

Yang perlu dari lab: satu kalimat di prosedur/IK Termometer Gelas yang menyebut
berapa kali UUT dibaca per titik.

---

## T2 — Gas Detector: tekanan dicetak di baris Kondisi Lingkungan atau tidak?

**MASIH TERBUKA.** Butuh satu sertifikat Gas Detector yang sudah terbit.

**Konteks.** `CertificateSnapshotBuilder::kondisiLingkungan()` merakit baris
Kondisi Lingkungan dari dua cabang saja — suhu dan kelembaban. Untuk Gas
Detector, `tekanan_udara` **dihitung, disimpan, dan dipakai sebagai komponen
budget ketidakpastian** — lalu tidak ikut tercetak di baris yang justru
menyatakan kondisi lingkungan pengukurannya.

**Kenapa tidak ditebak.** Tiga hal harus datang dari master lab, dan salah satu
saja meleset berarti angka yang salah di dokumen terakreditasi:

1. **Dicetak atau tidak?** Mungkin memang sengaja tidak — sebagian lab hanya
   mencantumkan tekanan di lembar data, bukan di sertifikat.
2. **Satuannya apa?** hPa, mbar, atau kPa. Nilainya tersimpan dalam satu satuan;
   mencetak angkanya dengan label satuan yang berbeda adalah kesalahan yang
   tidak kelihatan.
3. **Berapa desimal, dan pakai `±` atau tidak?** Baris suhu ditulis
   `21,0 °C ± 1,7 °C`. Tekanan mengikuti pola yang sama, atau tanpa
   ketidakpastian?

Ketiganya tidak bisa dijawab dengan `grep`. Yang tersimpan di database cuma
angkanya; **bentuk cetaknya cuma ada di kertas.**

**Yang sedang berlaku.** Tidak ada yang berubah — tekanannya tetap tidak
tercetak. Itu keadaan sebelum audit, dan sengaja dibiarkan: mencetak angka yang
formatnya salah lebih buruk daripada tidak mencetak. Yang hilang cuma satu baris
informasi; yang didapat kalau ditebak bisa satuan yang salah di dokumen
terakreditasi.

Yang perlu dari lab: satu contoh sertifikat Gas Detector yang sudah terbit, atau
baris Environmental Condition dari master-nya. **Satu foto sudah cukup** —
ketiga pertanyaan di atas terjawab sekaligus dari satu baris itu.

---

## T3 — SUDAH DIJAWAB: tidak, plannya `free`. Realtime ditunda.

**Dijawab 4 Sep 2026, dari `render.yaml` sendiri.**

**Jawabannya.** `render.yaml:16` menulis `plan: free`. Instance free Render
**tidur sesudah tidak ada lalu lintas** dan dibatasi memorinya. Proses WebSocket
panjang di instance yang tidur bukan "realtime" — dia socket yang mati tiap kali
sepi, lalu klien menyambung ke sesuatu yang tidak ada. Jadi pertanyaannya tidak
perlu dijawab dengan pertimbangan: plannya sudah menjawab.

`BROADCAST_CONNECTION` tetap `log`, dan itu sekarang **keputusan yang tercatat,
bukan bawaan yang kebetulan.**

**Konteks aslinya.** `laravel/reverb` terpasang di `composer.json:14`, tapi
rollout-nya berhenti di tengah — dependency masuk, konfigurasi produksi dan
proses server tidak menyusul. Akibatnya fitur "realtime sync mobile ↔ desktop"
tidak berfungsi di deployment Render, dan degradasinya senyap: tidak ada error,
pengguna cuma tidak pernah melihat pembaruan sampai menarik data manual.

**Yang bikin ini tidak lagi senyap.** Statusnya bisa diperiksa dari luar tanpa
membuka dashboard:

```bash
curl -s https://<api>/api/health | jq .realtime
# → { "driver": "log", "nyala": false, "paket_terpasang": true }
```

Diverifikasi di produksi 4 Sep 2026 (`deploy.versi` `ac20469`): ketiganya
persis seperti di atas. Jadi "realtime mati" sekarang **fakta yang terbaca**,
bukan dugaan.

**Satu hal yang belum diperiksa, dan sengaja ditulis di sini.** `plan: free` itu
yang tertulis di blueprint. Kalau service-nya pernah dinaikkan lewat dashboard
Render tanpa memperbarui `render.yaml`, blueprintnya basi — dan itu sendiri
temuan yang perlu dibetulkan, bukan alasan mengubah jawaban ini diam-diam.
**Yang membalik jawaban T3 cuma satu hal: plan berbayar yang tidak tidur.**
Kalau itu terjadi, tiga hal harus jalan bareng, dan satu saja kurang berarti
gagalnya senyap lagi:

1. `BROADCAST_CONNECTION=reverb` di `render.yaml`;
2. `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` (`sync: false`,
   diisi lewat dashboard);
3. `php artisan reverb:start` ikut di-loop `docker/entrypoint.sh`.

Alternatif tanpa menambah proses: layanan terkelola (`pusher`). Catatan yang
menghemat waktu orang berikutnya: `pusher/pusher-php-server` **belum** terpasang,
jadi itu satu langkah composer lagi, bukan cuma ganti nilai env.

---

## T4 — SUDAH DIJAWAB: label `direktori` cuma catatan asal-usul.

**Dijawab 4 Sep 2026, dengan dihitung — bukan diputuskan.**

**Datangnya dari mana.** Review otomatis (CodeRabbit) pada PR audit ini,
ditandai CWE-345 (*Insufficient Verification of Data Authenticity*).

**Konteks.** `CustomerController::cepat()` menentukan asal-usul baris pelanggan
dari ADA-TIDAKNYA `direktori_ref`, bukan dari apakah ref itu benar-benar
menunjuk hasil direktori:

```php
$pelanggan->sumber = match (true) {
    $ref !== null   => Customer::SUMBER_DIREKTORI,
    $user->isAdmin() => Customer::SUMBER_ADMIN,
    default          => Customer::SUMBER_TEKNISI,
};
```

Jadi satu `{"direktori_ref": "apa-saja"}` dari HP cukup buat menempelkan label
`direktori` pada baris yang diketik tangan.

**Jawabannya, dan cara membuktikannya.** `Customer::SUMBER_DIREKTORI` muncul di
**empat** tempat di seluruh repo:

```
app/Models/Customer.php:56                       definisi konstanta
app/Models/Customer.php:59                       masuk array SUMBER
app/Http/Controllers/Api/CustomerController.php:229   ← satu-satunya PENULISAN
tests/Feature/PelangganCepatTest.php:215         asersi test
```

**Nol pembacaan.** Tidak ada `where('sumber', …)`, tidak ada cabang yang
membandingkannya, tidak ada Resource atau tabel Filament yang menampilkannya.
Label itu **ditulis dan tidak pernah dibaca lagi** — jadi hari ini dia catatan
asal-usul, titik. Tidak ada yang perlu diubah.

Itu juga sejalan dengan yang sudah berlaku: teknisi memang boleh menyunting
hasil direktori sebelum menyimpan, jadi labelnya **sejak awal tidak pernah
menjanjikan isinya tidak disentuh.** Memverifikasinya sekarang berarti memasang
jaminan untuk sesuatu yang tidak ada yang mengandalkan.

**Yang TIDAK bocor, dan sudah diperiksa.** `kandidatMirip()` menyaring
`organization_id` dengan benar, dan `orWhere('direktori_ref', …)`-nya terbungkus
`where(fn …)`. Jadi ref tebakan **tidak** bisa menarik pelanggan lab lain ke
layar teknisi ini. Yang dipertaruhkan cuma label asal-usulnya.

**Kalau suatu hari label ini mulai dipakai memutuskan sesuatu** — baris
`direktori` dianggap lebih tepercaya, dilewatkan dari peninjauan admin, atau ikut
menentukan tampilan — **jawaban ini kedaluwarsa dan harus diverifikasi.**
Bentuknya sudah dipikirkan, dan yang penting: tanpa menambah tagihan maupun
ketergantungan. Server **mengingat ref yang baru saja ia berikan** lewat
`GET /api/customers/direktori` (per pengguna, umur pendek), lalu `cepat()`
mengadu ke ingatan itu. Ref yang tidak dikenali **turun** ke `admin`/`teknisi` —
bukan ditolak, supaya pendaftarannya tetap jalan.

Dua hal yang **jangan** dipakai, keduanya terlihat seperti perbaikan:

- **Memanggil penyedia lagi waktu menyimpan.** Endpointnya ditagih per request,
  jadi menyimpan satu pelanggan jadi dua panggilan berbayar — dan yang kedua
  tidak menghasilkan data baru apa pun. Lebih buruk: ketersediaan penyedia jadi
  ikut menentukan. Kontrak sengaja membedakan `503` (key belum disetel) dari
  `502` (direktorinya mati) supaya layar bisa mengarahkan teknisi ke ketik
  tangan waktu direktorinya mati. Kalau penyimpanan ikut menunggu penyedia,
  direktori yang mati bikin pendaftaran pelanggan ikut mati — jalan keluarnya
  yang justru tertutup.
- **Validasi BENTUK ref** (misalnya "harus mulai `ChIJ`"). Itu menahan salah
  ketik, bukan pemalsuan, sambil terbaca seperti menahan dua-duanya. Melabeli
  sesuatu "terverifikasi" berdasarkan pemeriksaan yang sebenarnya tidak
  dilakukan lebih buruk daripada tidak memverifikasi sama sekali.

---

## T5 — SUDAH DIJAWAB: dibiarkan. Kembarnya tidak sebanding dengan risiko kuncinya.

**Dijawab 4 Sep 2026.** Ini satu-satunya dari lima yang jawabannya
**pertimbangan, bukan fakta** — jadi alasannya ditulis lengkap supaya bisa
dibantah, dan syarat pembaliknya disebut.

**Datangnya dari mana.** Review otomatis (CodeRabbit) pada PR audit ini.

**Konteks.** `PenjagaNotifikasiUlang::bolehKirim()` **membaca** notifikasi
terakhir, lalu pemanggilnya mengirim. Di antara dua langkah itu tidak ada apa
pun yang menahan pemanggil kedua:

```
alat:cek-jatuh-tempo (scheduler 07:00) ──► bolehKirim() = true ──┐
                                                                 ├─► dua baris kembar
ReminderController::jatuhTempo (tombol admin) ► bolehKirim() = true ──┘
```

Dua pemicunya nyata dan terverifikasi: `routes/console.php:13`
(`Schedule::command('alat:cek-jatuh-tempo')->dailyAt('07:00')`) dan
`ReminderController::jatuhTempo()`. Tabel `notifications` tidak punya unique
index yang menahan pasangan (penerima, kelas, tanda tangan).

**Jawabannya: dibiarkan.** Tiga alasan, dan yang ketiga yang menentukan:

1. **Akibatnya satu baris kembar di lonceng** — bukan angka yang salah, bukan
   sertifikat, bukan pekerjaan yang hilang. Dan kembarnya tidak berulang:
   pengiriman berikutnya sudah melihat keduanya.
2. **Jendelanya menuntut kebetulan** — admin harus menekan tombol manual persis
   pada saat scheduler mengerjakan organisasi yang sama, jam 07:00.
3. **Arah salahnya tidak setara.** Lock yang dipasang di jalur terjadwal punya
   mode gagal sendiri: organisasi yang locknya tidak didapat **dilewat**. Untuk
   lab kalibrasi, pengingat jatuh tempo yang TIDAK terkirim lebih mahal daripada
   pengingat yang terkirim dua kali — yang pertama bisa berujung alat dipakai
   lewat jatuh tempo, yang kedua cuma bikin admin mengerutkan dahi sedetik.
   Menukar kesalahan yang kelihatan dengan kesalahan yang senyap bukan
   pertukaran yang menguntungkan.

Ada alasan keempat yang **bukan** alasan menolak, tapi wajib diketahui kalau
nanti dikerjakan: `phpunit.xml` menyetel `CACHE_STORE=array`, dan lock pada store
itu tidak pernah berebut dengan siapa pun. Jadi `Cache::lock` yang ditambahkan
akan **hijau di semua test tanpa satu pun benar-benar menjalankan locknya.**

**Jebakan — `withoutOverlapping()` terlihat seperti obatnya, dan bukan.**
`ocr:bersihkan-citra` sudah memakainya di `routes/console.php:30`, jadi idiomnya
sudah dikenal di repo ini dan gampang sekali ditiru ke dua baris di atasnya.
Jangan: `withoutOverlapping()` cuma menahan satu command dari dirinya sendiri.
Balapan T5 itu **scheduler lawan tombol HTTP** — dua proses berbeda, command
yang berbeda. Memasangnya menghasilkan rasa aman tanpa menutup apa pun.

**Yang membalik jawaban ini.** Salah satu saja cukup:

- admin melaporkan kembarnya benar-benar mengganggu (berarti asumsi "jarang"
  saya salah); atau
- tombol manual kedua ditambahkan — khususnya untuk `standar:cek-kadaluarsa`,
  yang hari ini **cuma** punya pemicu scheduler (`routes/console.php:20`) dan
  karena itu tidak balapan sama sekali. Begitu dia punya tombol, dia mewarisi
  balapan yang sama persis.

**Kalau dibalik, bentuknya sudah jelas:**

1. `Cache::lock("pengingat-jatuh-tempo:{org}")` di sekeliling
   `untukOrganisasi()`. Produksi memakai `CACHE_STORE=database`, jadi
   `cache_locks` sudah ada dan locknya atomik lintas proses;
2. `CACHE_STORE=database` disetel **untuk berkas test itu saja**, supaya locknya
   benar-benar dijalankan sesuatu — kalau tidak, poin 1 tidak teruji;
3. organisasi yang locknya tidak didapat **dilewat** ronde ini, bukan ditunggu.
   Pekerjaan yang dilewat toh diulang besok pagi, dan menunggu di jalur
   terjadwal bikin satu organisasi yang macet menahan sisanya.

---

## Cara menjawab

Balas di isu/PR-nya, atau langsung sunting berkas ini: ganti judul
pertanyaannya jadi `## T1 — SUDAH DIJAWAB: <ringkasan>` dan tulis jawabannya di
bawah. Yang penting jawabannya tinggal di repo, bukan di percakapan — supaya
orang berikutnya tidak menanyakan hal yang sama.

Untuk yang sudah dijawab, **tulis juga apa yang membalik jawabannya.** Jawaban
tanpa syarat pembalik berumur pendek: keadaan bergeser, tidak ada yang sadar,
dan yang tertinggal cuma kalimat percaya diri yang sudah tidak benar.
