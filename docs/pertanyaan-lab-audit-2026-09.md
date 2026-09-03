# Pertanyaan lab dari audit bug 2 Sep 2026

Lima hal yang **tidak bisa diputuskan dari kode**. Dua yang pertama menyangkut
angka atau tulisan di dokumen terakreditasi, dan menebaknya berarti menulis
sesuatu ke sertifikat pelanggan berdasarkan tebakan. Tiga terakhir soal
infrastruktur dan desain backend — T4 & T5 menyusul dari review otomatis pada
PR audit ini.

Status: **menunggu jawaban.** Selama belum dijawab, kodenya berperilaku seperti
yang ditulis di bawah — dan perilaku itu dipilih supaya yang salah kelihatan,
bukan supaya diam.

---

## T1 — Termometer Gelas: satu pembacaan UUT itu sah atau tidak?

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
(`count($uut) < 2`). Titik yang belum punya dua pembacaan **ditahan dari
perhitungan dengan alasan yang kebaca** — bukan menolak kirimannya: sesinya
tetap tersimpan, teknisi tidak terhalang di lapangan, dan penerbitan
sertifikatnya yang ketahan.

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

**Yang sedang berlaku.** Tidak ada yang berubah — tekanannya tetap tidak
tercetak. Itu keadaan sebelum audit, dan sengaja dibiarkan: mencetak angka yang
formatnya salah lebih buruk daripada tidak mencetak.

Yang perlu dari lab: satu contoh sertifikat Gas Detector yang sudah terbit, atau
baris Environmental Condition dari master-nya.

---

## T3 — Realtime: plan Render-nya sanggup menahan satu proses WebSocket lagi?

**Ini pertanyaan infrastruktur, bukan pertanyaan lab** — ditaruh di sini karena
tempatnya sama: yang menahannya bukan kode.

**Konteks.** `laravel/reverb` terpasang di `composer.json:14`, tapi rollout-nya
berhenti di tengah — dependency masuk, konfigurasi produksi dan proses server
tidak menyusul:

```
grep -c "REVERB\|BROADCAST" render.yaml        → 0   (sebelum audit)
grep -c "reverb"           docker/entrypoint.sh → 0
config/broadcasting.php:17   'default' => env('BROADCAST_CONNECTION', 'log')
```

Akibatnya fitur "realtime sync mobile ↔ desktop" **tidak berfungsi di deployment
Render**, dan degradasinya senyap: tidak ada error, pengguna cuma tidak pernah
melihat pembaruan sampai menarik data manual.

**Yang sudah dilakukan, dan sengaja berhenti di situ.** Nilainya tidak diubah —
`BROADCAST_CONNECTION=log` sekarang ditulis **eksplisit** di `render.yaml`
dengan alasannya, jadi keadaannya keputusan yang kebaca, bukan bawaan yang
kebetulan. Dan statusnya bisa diperiksa dari luar:

```bash
curl -s https://<api>/api/health | jq .realtime
# → { "driver": "log", "nyala": false, "paket_terpasang": true }
```

Klaim usang di `docs/realtime-sync.md` ("paketnya belum terpasang di repo ini")
ikut dibetulkan.

**Yang ditanyakan.** Plan Render yang dipakai sanggup menahan **satu proses
WebSocket panjang tambahan** di container yang sama — di samping `queue:work`
dan `schedule:work` yang sudah jalan?

- **Kalau IYA** — tiga hal harus jalan bareng, dan satu saja kurang berarti
  gagalnya senyap lagi:
  1. `BROADCAST_CONNECTION=reverb` di `render.yaml`;
  2. `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` (`sync: false`,
     diisi lewat dashboard);
  3. `php artisan reverb:start` ikut di-loop `docker/entrypoint.sh`.
- **Kalau TIDAK** — pilihannya layanan terkelola (`pusher`; catatan:
  `pusher/pusher-php-server` **belum** terpasang, jadi itu satu langkah lagi),
  atau realtime-nya memang ditunda dan `docs/realtime-sync.md` diberi tanggal
  keputusannya.

Yang perlu: nama plan Render-nya, atau satu kalimat "boleh/tidak nambah proses".

---

## T4 — `sumber=direktori` boleh dipercaya tanpa diverifikasi?

**Ini pertanyaan desain backend, bukan pertanyaan lab** — sama seperti T3,
ditaruh di sini karena yang menahannya bukan kode.

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
`direktori` pada baris yang diketik tangan. Bentuknya persis kekhawatiran yang
sudah ditulis di kontrak untuk `sumber` sendiri — cuma lewat pintu sebelah.

**Yang TIDAK bocor, dan sudah diperiksa.** `kandidatMirip()` menyaring
`organization_id` dengan benar, dan `orWhere('direktori_ref', …)`-nya terbungkus
`where(fn …)`. Jadi ref tebakan **tidak** bisa menarik pelanggan lab lain ke
layar teknisi ini. Yang dipertaruhkan cuma label asal-usulnya.

**Kenapa tidak langsung dibetulkan.** Perbaikan yang diminta review — "pastikan
`direktori_ref` benar-benar menunjuk hasil direktori" — berarti server memanggil
penyedianya lagi waktu pelanggan disimpan, dan itu menabrak dua hal yang sudah
diputuskan:

1. **Endpointnya ditagih per request.** Menyimpan satu pelanggan jadi dua
   panggilan berbayar, bukan satu — dan yang kedua tidak menghasilkan data baru
   apa pun.
2. **Ketersediaannya jadi ikut menentukan.** Kontrak sengaja membedakan `503`
   (key belum disetel) dari `502` (direktorinya mati) supaya layar bisa
   **mengarahkan teknisi ke ketik tangan** waktu direktorinya mati. Kalau
   penyimpanan ikut menunggu penyedia, direktori yang mati bikin pendaftaran
   pelanggan ikut mati — jalan keluarnya yang justru tertutup.

**Yang berlaku sekarang.** Tidak ada yang berubah. Melabeli sesuatu
"terverifikasi" berdasarkan pemeriksaan yang sebenarnya tidak dilakukan lebih
buruk daripada tidak memverifikasi sama sekali — dan validasi BENTUK ref
(misalnya "harus mulai `ChIJ`") persis jenis pemeriksaan palsu itu: dia menahan
salah ketik, bukan pemalsuan, sambil terbaca seperti menahan dua-duanya.

**Yang ditanyakan.** Label `direktori` dipakai buat apa — dan seberapa dalam?

- **Kalau cuma catatan asal-usul** (siapa mengetik dari mana, buat menelusuri
  data kembar) — tidak ada yang perlu diubah. Teknisi memang boleh menyunting
  hasil direktori sebelum menyimpan, jadi labelnya sejak awal tidak pernah
  menjanjikan isinya tidak disentuh.
- **Kalau dia pernah dipakai memutuskan sesuatu** — baris `direktori` dianggap
  lebih tepercaya, dilewatkan dari peninjauan admin, atau ikut menentukan
  tampilan — maka harus diverifikasi, dan cara yang tidak menambah tagihan
  maupun ketergantungan: **server mengingat ref yang baru saja ia berikan**
  lewat `GET /api/customers/direktori` (per pengguna, umur pendek), lalu
  `cepat()` mengadu ke ingatan itu. Ref yang tidak dikenali turun ke
  `admin`/`teknisi` — **bukan ditolak**, supaya pendaftarannya tetap jalan.

Yang perlu: satu kalimat "label itu dipakai buat apa". Kalau jawabannya "belum
dipakai apa-apa", tulis begitu — supaya yang berikutnya tidak memakainya sebagai
jaminan yang tidak pernah ada.

---

## T5 — Pengingat kembar: dikunci, atau dibiarkan?

**Pertanyaan infrastruktur, sama seperti T3 dan T4.**

**Datangnya dari mana.** Review otomatis (CodeRabbit) pada PR audit ini.

**Konteks.** `PenjagaNotifikasiUlang::bolehKirim()` **membaca** notifikasi
terakhir, lalu pemanggilnya mengirim. Di antara dua langkah itu tidak ada apa
pun yang menahan pemanggil kedua:

```
scheduler harian  ──► bolehKirim() = true ──┐
                                            ├─► dua baris yang sama di lonceng
tombol manual admin ► bolehKirim() = true ──┘
```

Dua pemicunya nyata: `CekJatuhTempo` (scheduler) dan
`ReminderController::jatuhTempo()` (tombol admin). Tabel `notifications` tidak
punya unique index yang menahan pasangan (penerima, kelas, tanda tangan).

**Yang berlaku sekarang, dan kenapa berhenti di situ.** Tidak ada yang berubah.
Tiga alasan, dan yang ketiga yang menentukan:

1. **Akibatnya satu baris kembar di lonceng** — bukan angka yang salah, bukan
   sertifikat, bukan pekerjaan yang hilang. Dan kembarnya tidak berulang:
   pengiriman berikutnya sudah melihat keduanya.
2. **Jendelanya menuntut kebetulan** — admin harus menekan tombol manual persis
   pada milidetik scheduler mengerjakan organisasi yang sama.
3. **Penjagaannya tidak bisa dibuktikan di repo ini.** `phpunit.xml` menyetel
   `CACHE_STORE=array`, dan lock pada store itu tidak pernah berebut dengan
   siapa pun. Jadi `Cache::lock` yang ditambahkan ke sini akan hijau di semua
   test tanpa satu pun di antaranya benar-benar menjalankan locknya —
   penjagaan yang tidak ada yang tahu bekerja atau tidak, dipasang di jalur
   yang dipanggil scheduler tiap pagi.

Menukar satu baris kembar yang jarang dengan lock yang tak teruji di jalur
terjadwal bukan pertukaran yang jelas menguntungkan. Itu keputusan pemilik
proyek, bukan keputusan yang pantas diambil diam-diam di PR perbaikan bug.

**Yang ditanyakan.** Baris pengingat kembar yang sesekali itu masalah nyata
buat admin, atau bukan?

- **Kalau BUKAN** — tulis begitu di sini, dan tidak ada yang perlu diubah.
- **Kalau IYA** — bentuknya sudah jelas dan ongkosnya kecil, tinggal
  diputuskan:
  1. `Cache::lock("pengingat-jatuh-tempo:{org}")` di sekeliling
     `untukOrganisasi()`. Produksi memakai `CACHE_STORE=database`, jadi
     `cache_locks` sudah ada dan locknya atomik lintas proses;
  2. `phpunit.xml` disetel `CACHE_STORE=database` **untuk berkas test itu
     saja**, supaya locknya benar-benar dijalankan sesuatu;
  3. keputusan apa yang terjadi waktu locknya tidak didapat — didiamkan
     (organisasi itu dilewat ronde ini) atau ditunggu. Yang pertama lebih aman
     buat scheduler: pekerjaan yang dilewat toh diulang besok pagi.

Yang perlu: satu kalimat "kembarnya mengganggu / tidak".

---

## Cara menjawab

Balas di isu/PR-nya, atau langsung sunting berkas ini: ganti judul
pertanyaannya jadi `## T1 — SUDAH DIJAWAB: <ringkasan>` dan tulis jawabannya di
bawah. Yang penting jawabannya tinggal di repo, bukan di percakapan — supaya
orang berikutnya tidak menanyakan hal yang sama.
