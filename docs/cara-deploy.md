# Cara deploy

Ringkasnya: **biasanya kamu nggak perlu melakukan apa-apa.** Begitu sesuatu
mendarat di `main`, test jalan, dan kalau hijau deploy-nya jalan sendiri.

Dokumen ini buat dua keadaan yang lain: waktu kamu mau **memaksa deploy manual**,
dan waktu deploy-nya **gagal** dan kamu perlu tahu di mana melihatnya.

---

## 1. Yang otomatis (dan biasanya cukup)

```
push/merge ke main
        │
        ▼
  workflow "Tes"  ──► job phpunit (seluruh suite, ~4-5 menit)
        │                    │
        │              merah ├──► BERHENTI. Nggak ada yang naik ke server.
        │                    │
        │              hijau ▼
        └──────────────► job "deploy ke Render"
                             │  ketuk Deploy Hook (POST ke URL rahasia)
                             ▼
                        Render build image (2-10 menit)
                             │
                             ▼
                        container baru jalan
```

**Urutannya disengaja: test dulu, baru deploy.** Sebelum 21 Agustus 2026 Render
deploy sendiri tanpa menunggu test, jadi commit yang test-nya merah tetap naik ke
server yang dipakai teknisi di lokasi. Harganya beberapa menit tunda tiap deploy
— itu murah dibanding satu jam teknisi yang kadung berangkat bawa aplikasi rusak.

Melihat statusnya: **GitHub → tab Actions → workflow "Tes"** pada commit terakhir
di `main`. Job `deploy ke Render` yang hijau artinya Render sudah **diberi tahu**;
build-nya sendiri dipantau di dashboard Render.

> `render.yaml` memasang `autoDeploy: false`, dan itu bukan kelupaan. GitHub App
> Render nggak terpasang di akun ini, jadi Render nggak pernah tahu ada push —
> dibuktikan 21 Agt 2026: PR #73 mendarat, empat belas menit lewat, nol deploy.
> Gantinya Deploy Hook yang diketuk dari GitHub Actions.

---

## 2. Deploy manual — dua cara

### Cara A: lewat dashboard Render (paling gampang, nggak perlu terminal)

1. Buka https://dashboard.render.com
2. Pilih service **`sidik-calibration-api`**
3. Pojok kanan atas → **Manual Deploy**
4. Pilih salah satu:
   - **Deploy latest commit** → ambil `main` terbaru, build ulang
   - **Clear build cache & deploy** → sama, tapi buang cache dulu. Pakai ini
     kalau build-nya aneh (misal aset lama masih kebawa)
5. Tab **Logs** kebuka sendiri — tunggu sampai muncul baris `==> Your service is live`

Ini yang **paling sering kamu butuhkan**. Aman diulang berkali-kali.

### Cara B: ketuk Deploy Hook dari terminal

Kalau kamu sudah punya URL hook-nya:

```bash
curl -X POST "https://api.render.com/deploy/srv-xxxxx?key=yyyyy"
```

Ambil URL-nya di: **Render → service → Settings → Deploy Hook → Copy**.

> ⚠️ **URL itu rahasia.** Siapa pun yang memegangnya bisa memicu deploy kapan
> saja. Jangan ditempel ke chat, issue, atau commit. Kalau terlanjur bocor:
> Render → Settings → Deploy Hook → **Regenerate**, lalu pasang ulang secret-nya
> di GitHub (lihat bagian 4).

### Cara C: jalankan ulang workflow-nya (kalau yang gagal cuma langkah ketuknya)

**GitHub → Actions → pilih run yang gagal → Re-run failed jobs.**

Pakai ini kalau `phpunit`-nya hijau tapi `deploy ke Render` merah — nggak perlu
push commit kosong cuma buat memicu ulang.

---

## 3. Yang terjadi otomatis setiap container baru boot

Kamu **nggak perlu** menjalankan ini manual. Ditulis di sini supaya kamu tahu apa
yang sudah berjalan waktu membaca log:

| Langkah | Keterangan |
|---|---|
| Tunggu database siap | Ngetuk `db:show` berulang sampai nyambung — Aiven kadang lambat bangun |
| `php artisan migrate --force` | Migrasi jalan tiap boot. Aman: migrasi yang sudah pernah jalan dilewati |
| `php artisan db:seed --force` | **CUMA kalau saklarnya dinyalakan.** Sengaja nggak otomatis |
| `storage:link`, `config:cache`, `view:cache` | Rutin optimasi produksi |
| Queue worker & scheduler | Jalan sebagai proses latar |

**Kenapa seeding pakai saklar, bukan otomatis:** seeder di proyek ini idempotent,
tapi "idempotent" itu janji yang gampang basi diam-diam waktu ada seeder baru
ditambahkan. Menyalakannya harus jadi keputusan sadar, bukan efek samping tiap
restart.

---

## 4. Kalau deploy GAGAL — cari di mana rusaknya

Urutkan dari yang paling sering:

**Job `deploy ke Render` merah, pesannya menyebut `RENDER_DEPLOY_HOOK`**
→ Secret-nya belum ada atau hook-nya sudah diregenerasi. Perbaikannya:

```bash
gh secret set RENDER_DEPLOY_HOOK --body "<url-hook-dari-Render>"
```

**Job `deploy ke Render` merah, HTTP 401**
→ Hook-nya sudah diregenerasi di Render tapi secret di GitHub masih yang lama.
Salin ulang, pasang ulang seperti di atas.

**Job `phpunit` merah**
→ Deploy-nya memang **sengaja** nggak jalan. Ini bukan masalah deploy; ada test
yang gagal. Jangan diakali dengan Manual Deploy dari dashboard — itu persis
menghidupkan lagi lubang yang gerbang ini tutup.

**Build di Render gagal**
→ Buka tab **Logs** di dashboard Render. Yang paling sering:
- `npm run build` gagal → masalah aset frontend
- `composer install` gagal → `composer.lock` nggak sinkron
- Extension PHP hilang → `install-php-extensions` di Dockerfile

**Build hijau tapi container mati waktu boot**
→ Tetap di **Logs** Render. Entrypoint mencetak sebabnya dengan jelas —
`APP_KEY` kosong dan database nggak nyambung dua-duanya punya pesan sendiri,
bukan stack trace.

**Service hidup tapi jawab 500**
→ Cek `healthCheckPath` `/up`. Kalau `/up` hijau tapi halaman lain 500,
kemungkinan besar aset frontend: panel admin butuh `public/build/manifest.json`
hasil `npm run build`. Coba **Clear build cache & deploy**.

---

## 5. Mobile itu TERPISAH

Dokumen ini cuma tentang **API** (repo `sidik-calibration-api` → Render).

Aplikasi HP dan desktop hidup di repo `sidik-calibration-mobile`, dengan
workflow-nya sendiri:

- **APK** — GitHub Release, versinya naik otomatis tiap rilis
- **Desktop** — workflow `rilis-desktop.yml`, hasilnya diterbitkan ke halaman
  unduh Firebase

Deploy API **tidak** ikut memperbarui aplikasi di HP teknisi, dan sebaliknya.
Kalau ada perubahan yang butuh dua-duanya (misal parameter API baru yang harus
dipanggil aplikasi), dua-duanya harus dirilis sendiri-sendiri.

---

## Ringkasan sekali lihat

| Mau apa | Lakukan |
|---|---|
| Deploy perubahan baru | **Nggak usah apa-apa** — merge ke `main`, sisanya otomatis |
| Paksa deploy ulang | Render → Manual Deploy → Deploy latest commit |
| Deploy-nya aneh, curiga cache | Render → Manual Deploy → **Clear build cache & deploy** |
| Test hijau tapi ketukan gagal | GitHub → Actions → **Re-run failed jobs** |
| Lihat deploy sudah selesai belum | Dashboard Render → tab Logs → `Your service is live` |
| Test merah | **Jangan deploy.** Perbaiki test-nya dulu |
