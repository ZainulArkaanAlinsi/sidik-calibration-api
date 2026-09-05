# Realtime Sync Mobile ↔ Desktop (spec poin 12D)

Tujuan: mobile (teknisi/admin) dan panel desktop admin nunjukin **data yang sama,
barengan** — begitu ada perubahan (sesi kalibrasi dibuat/disetujui, sertifikat
terbit, notifikasi baru), dua-duanya ke-update tanpa refresh manual. Diminta klien.

## Arsitektur

Satu database = satu sumber kebenaran. Realtime-nya lewat **Laravel Broadcasting**:
server nge-*push* sinyal tipis lewat websocket, klien nangkep lalu nge-refresh
data lewat REST biasa. Angka/isi sensitif **tidak** ikut di payload broadcast —
broadcast cuma "sinyal ada perubahan", datanya tetap ditarik lewat endpoint
ber-otorisasi.

```
Aksi (mis. admin approve sesi)
  → PerubahanDataOrganisasi di-broadcast ke channel privat organisasi.{id}
  → HP & desktop yang subscribe channel itu dapat sinyal {jenis, aksi, id}
  → keduanya re-fetch lewat REST → tampil data sama, barengan
Notifikasi baru
  → NotifikasiSistem broadcast ke channel privat App.Models.User.{id}
  → lonceng nyala barengan di HP & desktop
```

## Yang sudah ada di backend

| Bagian | File |
|---|---|
| Event sinkron data | `app/Events/PerubahanDataOrganisasi.php` (channel `organisasi.{id}`, event `data.berubah`, payload `{jenis, aksi, id}`) |
| Notifikasi realtime | `app/Notifications/NotifikasiSistem.php` — channel `database` + `broadcast` |
| Otorisasi channel | `routes/channels.php` (`organisasi.{id}` & `App.Models.User.{id}` — cek keanggotaan org / kepemilikan user) |
| Endpoint auth channel | `POST /api/broadcasting/auth` (Sanctum) — Echo `authEndpoint` di klien |
| Titik broadcast | `CalibrationController` (dibuat/diubah/disetujui/ditolak) & `GenerateCertificate` (sertifikat diterbitkan) |
| Config | `config/broadcasting.php`, `.env.example` (Reverb) |

Default driver `log` → aman jalan tanpa server websocket (dev/test). Sinyalnya
kebentuk & ke-log; belum ke-push sampai Reverb dinyalakan.

## Ngaktifin realtime (produksi)

```bash
composer require laravel/reverb
php artisan reverb:install     # isi REVERB_* di .env
# set BROADCAST_CONNECTION=reverb
php artisan reverb:start       # jalanin server websocket (supervisor/pm2 di prod)
php artisan queue:work         # broadcast & notifikasi jalan lewat queue
```

Alternatif tanpa self-host: `pusher` (isi `PUSHER_*`, set `BROADCAST_CONNECTION=pusher`).

> ⚠️ **Status per 3 Sep 2026: paketnya SUDAH terpasang, realtime-nya BELUM nyala.**
>
> `laravel/reverb` ada di `composer.json:14`. Yang belum: `render.yaml` menyetel
> `BROADCAST_CONNECTION=log` (eksplisit, sejak audit — sebelumnya jatuh ke bawaan yang
> sama tanpa ada yang menulisnya), dan `docker/entrypoint.sh` tidak menjalankan
> `reverb:start` sama sekali; yang di-loop cuma `queue:work` & `schedule:work`.
>
> Akibatnya: event broadcast ditulis ke berkas log dan **tidak pernah sampai ke klien** —
> tanpa error, tanpa gagal. Fitur "realtime sync mobile ↔ desktop" tidak berfungsi di
> deployment Render, dan degradasinya senyap.
>
> Keadaannya sekarang bisa diperiksa dari luar: `GET /api/health` → `realtime`
> (`driver`, `nyala`, `paket_terpasang`).
>
> **Keputusan 4 Sep 2026 — realtime DITUNDA, dan ini bukan penundaan tanpa batas
> waktu: dia punya syarat pembalik yang jelas.**
>
> Yang menahan penyalaannya bukan kode, tapi plan Render-nya. `render.yaml:16` menulis
> `plan: free`, dan instance free **tidur sesudah tidak ada lalu lintas**. Proses
> WebSocket panjang di instance yang tidur bukan "realtime" — dia socket yang mati tiap
> kali sepi, lalu klien menyambung ke sesuatu yang tidak ada. Menyalakan Reverb di plan
> ini menghasilkan fitur yang gagalnya lebih membingungkan daripada sekarang: bukan
> "tidak pernah update", tapi "kadang update, kadang tidak".
>
> Jadi `BROADCAST_CONNECTION=log` itu sekarang **pilihan yang tercatat, bukan bawaan
> yang kebetulan.** Ditulis lengkap sebagai T3 di `pertanyaan-lab-audit-2026-09.md`.
>
> **Yang membalik keputusan ini cuma satu hal: plan berbayar yang tidak tidur.** Bukan
> permintaan fitur, bukan tenggat — plannya. Begitu itu terjadi, tiga hal di daftar
> "Ngaktifin realtime" di atas harus jalan bareng, dan satu saja kurang berarti gagalnya
> senyap lagi.
>
> Catatan buat yang membaca `render.yaml` nanti: `plan: free` itu yang tertulis di
> blueprint. Kalau service-nya pernah dinaikkan lewat dashboard tanpa memperbarui
> blueprintnya, yang basi blueprintnya — betulkan itu dulu, jangan diam-diam memakai
> plan sebenarnya sebagai alasan menyalakan Reverb tanpa mengubah keputusan ini.
>
> `pusher/pusher-php-server` masih belum terpasang. Jadi kalau `BROADCAST_CONNECTION`
> diset ke `pusher` (bukan `reverb`), `/api/broadcasting/auth` tetap gagal dengan
> `Class "Pusher\Pusher" not found` — itu bukan salah kodenya.

> ### Catatan 25 Juli 2026 — bug di `/api/broadcasting/auth`, udah dibetulin
>
> `Illuminate\Http\Request` nggak ke-import di `routes/api.php`, jadi type-hint di
> closure-nya resolve ke alias global = **facade**, bukan HTTP request-nya.
> Akibatnya `$request->channel_name` selalu `null`, dan begitu driver-nya diganti
> ke `reverb`/`pusher`, **semua subscribe kena `403`** — tanpa satu pun error di log.
>
> Kenapa nggak kelihatan berbulan-bulan: driver `log` (dev) & `null` (test) bikin
> `auth()` jadi no-op yang balikin `200` body kosong, jadi salah apa pun di dalam
> closure kelihatan "lolos". Test yang ada waktu itu cuma nyentuh jalur `401`
> tanpa token — itu dicegat middleware **sebelum** closure-nya jalan.
>
> Sekarang ada `RealtimeSyncTest::test_endpoint_auth_channel_nerima_http_request_asli`
> yang masang driver penangkap dan mastiin yang keinjeksi beneran HTTP request
> lengkap dengan `channel_name` & `user()`. Jadi kalau ada yang ngapus import-nya
> lagi, testnya merah duluan sebelum kejadian di produksi.

## Sisi klien (mobile & desktop — repo terpisah)

Pakai Laravel Echo. Contoh (JS/desktop; mobile pakai paket Echo Flutter yang setara):

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const echo = new Echo({
  broadcaster: 'reverb',
  key: REVERB_APP_KEY,
  wsHost: REVERB_HOST, wsPort: REVERB_PORT, forceTLS: false,
  authEndpoint: `${API_BASE_URL}/broadcasting/auth`,   // /api/broadcasting/auth
  auth: { headers: { Authorization: `Bearer ${sanctumToken}` } },
});

// Sinkron data per organisasi → re-fetch begitu ada sinyal
echo.private(`organisasi.${organizationId}`)
  .listen('.data.berubah', (e) => {
    // e = { jenis, aksi, id } → refresh list terkait (kalibrasi/sertifikat/...)
  });

// Notifikasi realtime (lonceng)
echo.private(`App.Models.User.${userId}`)
  .notification((notif) => {
    // notif = payload dari NotifikasiSistem::toBroadcast() (title, body, kategori, tautan, ...)
  });
```

> `.data.berubah` pakai titik di depan karena event-nya `broadcastAs('data.berubah')`.

## Catatan

- Broadcast **melengkapi**, bukan mengganti REST + polling. Kalau websocket putus,
  klien tetap konsisten via refresh biasa.
- Channel semuanya **privat** + di-otorisasi per organisasi/user — data lab bersifat
  internal (sesuai pemisahan privasi teknisi/admin).
