#!/usr/bin/env python3
"""
Simulasi satu hari lab yang sibuk, termasuk kiriman yang datang BERSAMAAN.

    python docs/skrip/simulasi-lab-serentak.py FIXTURE.json LAPORAN.json

Biasanya tidak dijalankan langsung. `tests/Simulasi/SimulasiLabSerentakTest.php`
yang menyiapkan database tes lokal, orang-orang dummy, beberapa server, dan satu
pekerja antrean, lalu memanggil skrip ini dan memeriksa isi database sesudahnya.

## Kenapa ada

Suite test menjalankan tiap request SATU PER SATU di satu proses. Dia tidak bisa
melihat apa yang terjadi kalau dua admin menekan "Setujui" pada detik yang sama,
atau sepuluh teknisi menekan "Kirim" bareng waktu sinyal di lokasi balik. Server
produksi (FrankenPHP) memang memproses banyak request sekaligus, jadi balapan
seperti itu bisa terjadi di sana.

Skrip ini menembak beberapa server lokal yang berbagi SATU database MySQL, dengan
`threading.Barrier` supaya kirimannya benar-benar berangkat bersamaan.

## Yang TIDAK diukur

Kecepatan. Laptop dan paket Render gratis (0,1 CPU) beda jauh, jadi angka
latensi di laporan cuma pembanding antar skenario, bukan janji kecepatan produksi.

Cuma pustaka standar Python 3.8+, sama alasannya dengan `e2e-ph.py`.
"""

from __future__ import annotations

import itertools
import json
import random
import statistics
import sys
import threading
import time
import urllib.error
import urllib.request
import uuid
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field
from typing import Any, Callable

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

# Titik pH sama dengan `e2e-ph.py` (nominal botol + suhu larutan). Pembacaannya
# digeser sedikit per sesi supaya tiap sesi tidak identik, seperti lab nyata.
TITIK_DASAR = [
    {"buffer": "4", "titik_ukur": 4.01, "suhu": 26.3, "pembacaan": [4.00, 4.00, 4.00, 4.00, 4.00]},
    {"buffer": "7", "titik_ukur": 7.00, "suhu": 25.5, "pembacaan": [7.01, 7.01, 7.00, 7.00, 7.00]},
    {"buffer": "10", "titik_ukur": 10.01, "suhu": 25.3, "pembacaan": [10.11, 10.10, 10.12, 10.11, 10.11]},
]

# Pemilik alat fiktif. Kata "Simulasi" sengaja ikut di nama: repo ini publik,
# dan nama yang terlalu meyakinkan gampang dikira data pelanggan asli.
PEMILIK = [
    ("PT Simulasi Tirta Lestari", "Jl. Contoh Raya No. 12, Bogor"),
    ("CV Simulasi Analitika", "Jl. Percobaan No. 7, Depok"),
    ("RS Simulasi Medika Utama", "Jl. Fiktif No. 3, Jakarta Timur"),
    ("PDAM Simulasi Kota Hujan", "Jl. Uji Alir No. 45, Bogor"),
    ("PT Simulasi Pangan Nusantara", "Kawasan Industri Contoh Blok C2, Bekasi"),
]


@dataclass
class Respons:
    status: int
    data: Any
    ms: float


@dataclass
class Skenario:
    kode: str
    judul: str
    lulus: bool = True
    catatan: list[str] = field(default_factory=list)
    temuan: list[str] = field(default_factory=list)
    risiko: list[str] = field(default_factory=list)

    def gagal(self, pesan: str) -> None:
        self.lulus = False
        self.temuan.append(pesan)


class Klien:
    """HTTP ke beberapa server bergantian, seperti load balancer."""

    def __init__(self, bases: list[str], timeout: int = 90) -> None:
        self._putar = itertools.cycle(bases)
        self._kunci = threading.Lock()
        self.bases = bases
        self.timeout = timeout
        self.catatan: list[dict[str, Any]] = []

    def _base(self) -> str:
        with self._kunci:
            return next(self._putar)

    def panggil(
        self,
        skenario: str,
        metode: str,
        path: str,
        *,
        token: str | None = None,
        body: Any = None,
        ip: str | None = None,
        biner: bool = False,
        base: str | None = None,
    ) -> Respons:
        url = f"{base or self._base()}{path}"
        data = json.dumps(body).encode("utf-8") if body is not None else None
        req = urllib.request.Request(url, data=data, method=metode)
        req.add_header("Accept", "application/json")
        if data is not None:
            req.add_header("Content-Type", "application/json")
        if token:
            req.add_header("Authorization", f"Bearer {token}")
        if ip:
            # Server memercayai proxy (`trustProxies(at: '*')`), jadi header ini
            # yang menentukan IP klien — persis seperti di belakang proxy Render.
            req.add_header("X-Forwarded-For", ip)

        mulai = time.perf_counter()
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                isi = resp.read()
                status = resp.status
                hasil = isi if biner else _urai(isi)
        except urllib.error.HTTPError as e:
            status = e.code
            hasil = _urai(e.read())
        except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
            status = 0
            hasil = {"message": f"tidak tersambung: {e}"}
        ms = (time.perf_counter() - mulai) * 1000

        with self._kunci:
            self.catatan.append({"skenario": skenario, "metode": metode, "path": _pola(path), "status": status, "ms": ms})
        return Respons(status, hasil, ms)


def _urai(isi: bytes) -> Any:
    try:
        return json.loads(isi.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        return {"_mentah": isi[:300].decode("utf-8", errors="replace")}


def _pola(path: str) -> str:
    """`/calibrations/123/approve` -> `/calibrations/{id}/approve` buat statistik."""
    bagian = path.split("?")[0].split("/")
    return "/".join("{id}" if b.isdigit() else b for b in bagian)


def _pesan(data: Any) -> str:
    if isinstance(data, dict):
        teks = str(data.get("message") or data.get("_mentah") or "")
        if data.get("kode"):
            teks = f"[{data['kode']}] {teks}"
        return teks[:200]
    return str(data)[:200]


def _rusak(status: int) -> bool:
    return status == 0 or status >= 500


def serentak(tugas: list[Callable[[], Any]]) -> list[Any]:
    """Jalankan semua tugas di thread sendiri, berangkat di detik yang sama."""
    pagar = threading.Barrier(len(tugas))

    def bungkus(f: Callable[[], Any]) -> Any:
        pagar.wait()
        return f()

    with ThreadPoolExecutor(max_workers=len(tugas)) as pool:
        return list(pool.map(bungkus, tugas))


class Simulasi:
    def __init__(self, fixture: dict[str, Any]) -> None:
        self.f = fixture
        self.api = Klien(fixture["base_urls"])
        self.acak = random.Random(20261002)
        self.token: dict[str, str] = {}
        self.skenario: list[Skenario] = []
        self.sesi_disetujui: list[int] = []
        self.sesi_siap: list[dict[str, Any]] = []
        self.alat = list(fixture["alat"])
        self.sertifikat: list[dict[str, Any]] = []
        self.permintaan: list[int] = []
        self.sesi_dikenal: set[int] = set()
        self.ip_orang = {
            o["email"]: f"10.20.{i // 250}.{i % 250 + 1}"
            for i, o in enumerate([*fixture["admin"], *fixture["teknisi"], fixture["viewer"]])
        }

    # ── pembantu ────────────────────────────────────────────────────────────

    def ip(self, email: str) -> str:
        """Tiap orang punya HP sendiri = IP sendiri (data seluler)."""
        return self.ip_orang[email]

    def ambil_alat(self) -> dict[str, Any]:
        if not self.alat:
            raise RuntimeError("stok alat dummy habis — tambah di fixture")
        return self.alat.pop(0)

    def payload(self, alat: dict[str, Any], client_id: str | None, catatan: str) -> dict[str, Any]:
        pemilik = self.acak.choice(PEMILIK)
        buffer = self.f["buffer"]
        titik = []
        for t in TITIK_DASAR:
            geser = self.acak.choice([-0.01, 0.0, 0.0, 0.01])
            titik.append(
                {
                    "titik_ukur": t["titik_ukur"],
                    "satuan": "pH",
                    "standard_id": buffer[t["buffer"]],
                    "pembacaan": [round(p + geser, 2) for p in t["pembacaan"]],
                    "suhu": [t["suhu"]] * len(t["pembacaan"]),
                }
            )
        isi: dict[str, Any] = {
            "equipment_id": alat["id"],
            "standard_id": buffer["7"],
            "tanggal_kalibrasi": time.strftime("%Y-%m-%d"),
            "lokasi": "lab",
            "input_method": "manual",
            "status": "menunggu_approval",
            "alat_model": "Five Easy",
            "alat_serial_number": alat["serial"],
            "alat_merk": "Mettler Toledo",
            "pemilik_nama": pemilik[0],
            "pemilik_alamat": pemilik[1],
            "suhu_awal": round(24.0 + self.acak.random(), 1),
            "suhu_akhir": round(25.0 + self.acak.random(), 1),
            "kelembaban_awal": round(53 + self.acak.random() * 4, 1),
            "kelembaban_akhir": round(54 + self.acak.random() * 4, 1),
            "catatan_teknisi": catatan,
            "measurements": titik,
        }
        if client_id:
            isi["client_request_id"] = client_id
        return isi

    def kirim(self, kode: str, email: str, alat: dict[str, Any], client_id: str | None, catatan: str) -> Respons:
        r = self.api.panggil(
            kode, "POST", "/calibrations",
            token=self.token[email], body=self.payload(alat, client_id, catatan), ip=self.ip(email),
        )
        # Tiap sesi yang DIJAWAB ke klien dicatat. Test PHP mengadu daftar ini ke
        # isi database: sesi yang tersimpan tapi tidak pernah dijawab ke siapa pun
        # berarti ada jalur yang menyimpan diam-diam.
        sesi_id = ((r.data or {}).get("data") or {}).get("id") if isinstance(r.data, dict) else None
        if r.status in (200, 201) and sesi_id:
            with self.api._kunci:
                self.sesi_dikenal.add(sesi_id)
        return r

    def approve(self, kode: str, email: str, sesi_id: int) -> Respons:
        return self.api.panggil(
            kode, "POST", f"/calibrations/{sesi_id}/approve",
            token=self.token[email], body={"abaikan_peringatan": True}, ip=self.ip(email),
        )

    def baru(self, kode: str, judul: str) -> Skenario:
        s = Skenario(kode, judul)
        self.skenario.append(s)
        print(f"\n[{kode}] {judul}", flush=True)
        return s

    # ── skenario ────────────────────────────────────────────────────────────

    def s0_server_benar(self) -> None:
        s = self.baru("S0", "Semua server menempel ke database tes yang sama (bukan produksi)")
        penanda = self.f["penanda"]
        for i, base in enumerate(self.api.bases):
            r = self.api.panggil(
                "S0", "POST", "/login", base=base, ip=f"10.0.0.{i + 1}",
                body={"identifier": penanda, "password": self.f["sandi"]},
            )
            if r.status != 200:
                s.gagal(f"{base}: akun penanda tidak bisa login (HTTP {r.status} {_pesan(r.data)})")
            else:
                s.catatan.append(f"{base}: akun penanda dikenal, berarti server ini memakai DB tes")
        if not s.lulus:
            raise SystemExit("Server tidak menempel ke DB tes — simulasi DIHENTIKAN demi keamanan.")

    def s1_login_pagi(self) -> None:
        s = self.baru("S1", "Pagi hari: semua orang login hampir bersamaan, tiap orang dari HP sendiri")
        orang = list(self.ip_orang)

        def login(email: str) -> tuple[str, Respons]:
            return email, self.api.panggil(
                "S1", "POST", "/login", ip=self.ip(email),
                body={"identifier": email, "password": self.f["sandi"]},
            )

        for email, r in serentak([lambda e=e: login(e) for e in orang]):
            token = ((r.data or {}).get("data") or {}).get("token") if isinstance(r.data, dict) else None
            if r.status == 200 and token:
                self.token[email] = token
            else:
                s.gagal(f"{email}: HTTP {r.status} {_pesan(r.data)}")
        s.catatan.append(f"{len(self.token)}/{len(orang)} orang berhasil login serentak")
        if len(self.token) < len(orang):
            raise SystemExit("Tidak semua orang bisa login — skenario berikutnya tidak bermakna.")

    def s1b_satu_wifi(self) -> None:
        s = self.baru("S1b", "Satu kantor satu WiFi: 12 orang login berurutan dalam semenit dari IP yang sama")
        emails = [t["email"] for t in self.f["teknisi"]] + [a["email"] for a in self.f["admin"]]
        # Berurutan, bukan serentak: begitu pola jam masuk — satu per satu dalam
        # beberapa detik. Kiriman serentak justru bisa lolos semua karena pembatas
        # memeriksa hitungan SEBELUM menambahnya, dan itu bukan yang mau diukur.
        hasil = [
            self.api.panggil(
                "S1b", "POST", "/login", ip="10.99.0.1",
                body={"identifier": e, "password": self.f["sandi"]},
            )
            for e in emails[:12]
        ]
        ok = sum(1 for r in hasil if r.status == 200)
        ditahan = sum(1 for r in hasil if r.status == 429)
        lain = [r.status for r in hasil if r.status not in (200, 429)]
        s.catatan.append(f"{ok} berhasil, {ditahan} ditahan 429, lainnya {lain or '-'}")
        if ditahan:
            s.risiko.append(
                f"Batas login 10/menit dihitung PER IP. {ditahan} dari 12 orang di WiFi yang sama "
                "ditolak 'Kebanyakan percobaan' walau sandinya benar. Di lab, semua HP lewat satu "
                "IP publik kantor, jadi ini bisa kejadian saat jam masuk."
            )
        if lain:
            s.gagal(f"status tak terduga: {lain}")

    def s2_kirim_serentak(self) -> None:
        s = self.baru("S2", "10 teknisi menekan 'Kirim' lembar kerja pada detik yang sama")
        teknisi = [t["email"] for t in self.f["teknisi"]][:10]
        rencana = [(e, self.ambil_alat(), str(uuid.uuid4())) for e in teknisi]
        hasil = serentak([
            lambda e=e, a=a, c=c: (e, a, c, self.kirim("S2", e, a, c, "Simulasi: kiriman serentak."))
            for e, a, c in rencana
        ])

        nomor: list[str] = []
        gagal_dulu: list[tuple[str, dict[str, Any], str, Respons]] = []
        for e, a, c, r in hasil:
            if r.status == 201:
                sesi = r.data["data"]
                nomor.append(sesi.get("nomor_sesi"))
                self.sesi_siap.append({"id": sesi["id"], "teknisi": e})
            else:
                gagal_dulu.append((e, a, c, r))
                s.gagal(f"{e}: HTTP {r.status} {_pesan(r.data)}")

        if len(set(nomor)) != len(nomor):
            s.gagal(f"nomor sesi KEMBAR: {sorted(nomor)}")
        s.catatan.append(f"{len(nomor)}/10 tersimpan. Nomor: {', '.join(sorted(n for n in nomor if n))}")

        # Yang gagal dikirim ulang dengan client_request_id yang SAMA, persis yang
        # dilakukan aplikasi mobile sesudah gagal. Ini menunjukkan apakah teknisi
        # bisa pulih sendiri atau datanya hilang.
        for e, a, c, r0 in gagal_dulu:
            r = self.kirim("S2-ulang", e, a, c, "Simulasi: kirim ulang sesudah gagal.")
            if r.status in (200, 201):
                sesi = r.data["data"]
                self.sesi_siap.append({"id": sesi["id"], "teknisi": e})
                s.catatan.append(f"{e}: kirim ulang BERHASIL (HTTP {r.status}) sesudah HTTP {r0.status}")
            else:
                s.temuan.append(f"{e}: kirim ulang juga gagal HTTP {r.status} {_pesan(r.data)}")

    def s3_kirim_dobel(self) -> None:
        s = self.baru("S3", "Kirim dobel: tombol ditekan 2x / sinyal putus lalu dikirim ulang")
        e = self.f["teknisi"][0]["email"]
        a = self.ambil_alat()
        cid = str(uuid.uuid4())
        dua = serentak([lambda: self.kirim("S3", e, a, cid, "Simulasi: tekan dua kali."),
                        lambda: self.kirim("S3", e, a, cid, "Simulasi: tekan dua kali.")])
        ulang = self.kirim("S3", e, a, cid, "Simulasi: kirim ulang sesudah sinyal putus.")

        semua = [*dua, ulang]
        ids = {((r.data or {}).get("data") or {}).get("id") for r in semua if r.status in (200, 201)}
        ids.discard(None)
        status = [r.status for r in semua]
        s.catatan.append(f"client_request_id sama, 3 kiriman -> status {status}, sesi tercipta {sorted(ids)}")
        if len(ids) > 1:
            s.gagal(f"kiriman dobel melahirkan {len(ids)} sesi berbeda: {sorted(ids)}")
        rusak = [(r.status, _pesan(r.data)) for r in semua if _rusak(r.status)]
        if rusak:
            s.gagal(f"ada respons rusak: {rusak}")
        if ids:
            self.sesi_siap.append({"id": next(iter(ids)), "teknisi": e})

    def s8_pemisahan_wewenang(self) -> None:
        s = self.baru("S8", "Admin mengisi lembar sendiri lalu mencoba menyetujuinya sendiri")
        admin_a, admin_b = (x["email"] for x in self.f["admin"][:2])
        a = self.ambil_alat()
        r = self.kirim("S8", admin_a, a, str(uuid.uuid4()), "Simulasi: diisi admin sendiri.")
        if r.status != 201:
            s.catatan.append(f"admin tidak bisa mengirim lembar kerja (HTTP {r.status} {_pesan(r.data)}), skenario dilewati")
            return
        sesi_id = r.data["data"]["id"]
        sendiri = self.approve("S8", admin_a, sesi_id)
        if sendiri.status == 422 and isinstance(sendiri.data, dict) and sendiri.data.get("kode") == "pemisahan_wewenang":
            s.catatan.append("menyetujui sesi sendiri DITOLAK 422 pemisahan_wewenang (benar)")
        else:
            s.gagal(f"menyetujui sesi sendiri malah HTTP {sendiri.status} {_pesan(sendiri.data)}")
        lain = self.approve("S8", admin_b, sesi_id)
        if lain.status == 200:
            s.catatan.append("admin lain menyetujui -> 200 (benar)")
            self.sesi_disetujui.append(sesi_id)
        else:
            s.gagal(f"admin lain gagal menyetujui: HTTP {lain.status} {_pesan(lain.data)}")

    def s9_viewer(self) -> None:
        s = self.baru("S9", "Viewer cuma boleh membaca")
        v = self.f["viewer"]["email"]
        baca = self.api.panggil("S9", "GET", "/calibrations", token=self.token[v], ip=self.ip(v))
        sasaran = self.sesi_siap[0]["id"] if self.sesi_siap else 1
        tulis = self.approve("S9", v, sasaran)
        s.catatan.append(f"baca daftar sesi -> {baca.status}, coba approve -> {tulis.status}")
        if baca.status != 200:
            s.gagal(f"viewer tidak bisa membaca: HTTP {baca.status}")
        if tulis.status != 403:
            s.gagal(f"viewer mencoba approve malah HTTP {tulis.status} {_pesan(tulis.data)}")

    def s4_rebutan_approve(self) -> None:
        s = self.baru("S4", "Dua admin menekan 'Setujui' pada sesi yang sama di detik yang sama (3x)")
        admin_a, admin_b = (x["email"] for x in self.f["admin"][:2])
        for _ in range(3):
            if not self.sesi_siap:
                s.catatan.append("sesi habis")
                return
            sesi = self.sesi_siap.pop(0)
            ra, rb = serentak([lambda: self.approve("S4", admin_a, sesi["id"]),
                               lambda: self.approve("S4", admin_b, sesi["id"])])
            pasangan = sorted([ra.status, rb.status])
            s.catatan.append(f"sesi {sesi['id']}: {pasangan}")
            if pasangan.count(200) != 1:
                s.gagal(f"sesi {sesi['id']}: harusnya tepat satu yang 200, dapat {pasangan} ({_pesan(ra.data)} / {_pesan(rb.data)})")
            if any(_rusak(x) for x in pasangan):
                s.gagal(f"sesi {sesi['id']}: ada respons rusak {pasangan} {_pesan(ra.data)} / {_pesan(rb.data)}")
            if 200 in pasangan:
                self.sesi_disetujui.append(sesi["id"])

    def s5_approve_lawan_tolak(self) -> None:
        s = self.baru("S5", "Admin A menyetujui, admin B menolak sesi yang sama, bersamaan (2x)")
        admin_a, admin_b = (x["email"] for x in self.f["admin"][:2])
        for _ in range(2):
            if not self.sesi_siap:
                return
            sesi = self.sesi_siap.pop(0)
            ra, rb = serentak([
                lambda: self.approve("S5", admin_a, sesi["id"]),
                lambda: self.api.panggil(
                    "S5", "POST", f"/calibrations/{sesi['id']}/reject", token=self.token[admin_b],
                    ip=self.ip(admin_b), body={"catatan_revisi": "Simulasi: suhu ruang belum dicatat."},
                ),
            ])
            s.catatan.append(f"sesi {sesi['id']}: approve={ra.status}, tolak={rb.status}")
            menang = (ra.status == 200) + (rb.status == 200)
            if menang != 1:
                s.gagal(f"sesi {sesi['id']}: harusnya tepat satu yang menang, dapat approve={ra.status} tolak={rb.status}")
            if _rusak(ra.status) or _rusak(rb.status):
                s.gagal(f"sesi {sesi['id']}: respons rusak {_pesan(ra.data)} / {_pesan(rb.data)}")
            if ra.status == 200:
                self.sesi_disetujui.append(sesi["id"])

    def s6_approve_massal_dan_baca(self) -> None:
        s = self.baru("S6", "Semua sesi tersisa disetujui serentak, sambil 30 orang membuka daftar sesi")
        admin = [x["email"] for x in self.f["admin"][:2]]
        sisa, self.sesi_siap = self.sesi_siap, []
        pembaca = [t["email"] for t in self.f["teknisi"]] + [self.f["viewer"]["email"]]

        tugas: list[Callable[[], Any]] = [
            (lambda i=i, x=x: ("approve", x["id"], self.approve("S6", admin[i % 2], x["id"])))
            for i, x in enumerate(sisa)
        ]
        for i in range(30):
            e = pembaca[i % len(pembaca)]
            path = "/calibrations" if i % 3 else "/equipments"
            tugas.append(lambda e=e, p=path: ("baca", None, self.api.panggil("S6-baca", "GET", p, token=self.token[e], ip=self.ip(e))))

        for jenis, sesi_id, r in serentak(tugas):
            if jenis == "approve":
                if r.status == 200:
                    self.sesi_disetujui.append(sesi_id)
                else:
                    s.gagal(f"approve sesi {sesi_id}: HTTP {r.status} {_pesan(r.data)}")
            elif r.status != 200:
                s.gagal(f"baca: HTTP {r.status} {_pesan(r.data)}")
        s.catatan.append(f"{len(sisa)} approve serentak + 30 pembacaan selesai")

    def s7_tunggu_sertifikat(self, batas_detik: int = 600) -> None:
        s = self.baru("S7", "Pekerja antrean menerbitkan semua sertifikat; nomor wajib unik")
        admin = self.f["admin"][0]["email"]
        menunggu = set(self.sesi_disetujui)
        mulai = time.time()
        while menunggu and time.time() - mulai < batas_detik:
            for sesi_id in sorted(menunggu):
                r = self.api.panggil("S7", "GET", f"/calibrations/{sesi_id}", token=self.token[admin], ip=self.ip(admin))
                sert = ((r.data or {}).get("data") or {}).get("sertifikat") if r.status == 200 else None
                if sert and sert.get("status") in ("terbit", "gagal"):
                    menunggu.discard(sesi_id)
                    self.sertifikat.append({"sesi": sesi_id, **{k: sert.get(k) for k in ("id", "nomor", "status", "qr_token")}})
            if menunggu:
                time.sleep(3)
        lama = time.time() - mulai
        terbit = [x for x in self.sertifikat if x["status"] == "terbit"]
        gagal = [x for x in self.sertifikat if x["status"] == "gagal"]
        s.catatan.append(f"{len(terbit)} terbit, {len(gagal)} gagal, {len(menunggu)} belum selesai, dalam {lama:.0f} detik")
        if gagal:
            s.gagal(f"sertifikat berstatus gagal: sesi {[x['sesi'] for x in gagal]}")
        if menunggu:
            s.gagal(f"sertifikat belum terbit sesudah {batas_detik} detik: sesi {sorted(menunggu)}")
        nomor = [x["nomor"] for x in terbit]
        if len(set(nomor)) != len(nomor):
            s.gagal(f"nomor sertifikat KEMBAR: {sorted(nomor)}")
        if terbit:
            s.catatan.append(f"nomor: {', '.join(sorted(nomor))}")
            s.catatan.append(f"rata-rata {lama / len(terbit):.1f} detik per sertifikat (satu pekerja, seperti produksi)")

    def s10_verifikasi_publik(self) -> None:
        s = self.baru("S10", "Pelanggan memindai QR sertifikat & admin mengunduh PDF")
        terbit = [x for x in self.sertifikat if x["status"] == "terbit"]
        if not terbit:
            s.gagal("tidak ada sertifikat terbit untuk diperiksa")
            return
        x = terbit[0]
        r = self.api.panggil("S10", "GET", f"/verify/{x['qr_token']}", ip="10.50.0.1")
        isi = json.dumps(r.data, ensure_ascii=False) if r.status == 200 else ""
        if r.status == 200 and x["nomor"] in isi:
            s.catatan.append(f"QR {x['nomor']} -> 200, nomor cocok")
        else:
            s.gagal(f"verifikasi QR: HTTP {r.status} {_pesan(r.data)}")
        admin = self.f["admin"][0]["email"]
        pdf = self.api.panggil(
            "S10", "GET", f"/certificates/{x['id']}/download", token=self.token[admin], biner=True, ip=self.ip(admin),
        )
        if pdf.status == 200 and isinstance(pdf.data, bytes) and pdf.data.startswith(b"%PDF"):
            s.catatan.append(f"PDF terunduh, {len(pdf.data) / 1024:.0f} KB")
        else:
            s.gagal(f"unduh PDF: HTTP {pdf.status}")

    def s11_permintaan_serentak(self) -> None:
        pel = self.f.get("pelanggan") or []
        s = self.baru("S11", f"{len(pel)} pelanggan mengirim permintaan kalibrasi di detik yang sama")

        def ajukan(i: int, p: dict[str, Any]) -> Respons:
            return self.api.panggil(
                "S11", "POST", "/pelanggan/v1/permintaan", token=p["token"], ip=f"10.30.0.{i + 1}",
                body={
                    "metode_pengantaran": self.f["metode_pengantaran"],
                    "alat_id": [p["alat_permintaan"]],
                    "catatan": "Simulasi: mohon kalibrasi rutin tahunan.",
                },
            )

        hasil = serentak([lambda i=i, p=p: (i, p, ajukan(i, p)) for i, p in enumerate(pel)])
        nomor: list[str] = []
        for i, p, r in hasil:
            if r.status == 201:
                data = r.data["data"]
                nomor.append(data.get("nomor"))
                self.permintaan.append(data["id"])
                continue
            s.gagal(f"{p['email']}: HTTP {r.status} {_pesan(r.data)}")
            # Permintaan tidak punya kunci kiriman-ulang, jadi kirim ulang hanya
            # aman karena transaksi yang gagal tadi sudah dibatalkan seluruhnya.
            ulang = ajukan(i, p)
            if ulang.status == 201:
                self.permintaan.append(ulang.data["data"]["id"])
                nomor.append(ulang.data["data"].get("nomor"))
                s.catatan.append(f"{p['email']}: kirim ulang berhasil sesudah HTTP {r.status}")
        if len(set(nomor)) != len(nomor):
            s.gagal(f"nomor permintaan KEMBAR: {sorted(nomor)}")
        s.catatan.append(f"{len(self.permintaan)}/{len(pel)} tersimpan. Nomor: {', '.join(sorted(n for n in nomor if n))}")

    def s12_terima_dan_order_serentak(self) -> None:
        pel = self.f.get("pelanggan") or []
        s = self.baru(
            "S12",
            f"Admin menerima {len(self.permintaan)} permintaan + mencatat {min(6, len(pel))} order di meja depan, bersamaan",
        )
        admin = [x["email"] for x in self.f["admin"][:2]]
        hari_ini = time.strftime("%Y-%m-%d")
        tugas: list[Callable[[], Any]] = []
        for i, pid in enumerate(self.permintaan):
            e = admin[i % 2]
            tugas.append(lambda e=e, pid=pid: ("terima", pid, self.api.panggil(
                "S12", "POST", f"/permintaan-pelanggan/{pid}/terima", token=self.token[e], ip=self.ip(e),
                body={"tanggal_masuk": hari_ini, "catatan": "Simulasi: diterima serentak."},
            )))
        for i, p in enumerate(pel[:6]):
            e = admin[(i + 1) % 2]
            tugas.append(lambda e=e, p=p: ("order", p["customer_id"], self.api.panggil(
                "S12", "POST", "/orders", token=self.token[e], ip=self.ip(e),
                body={
                    "customer_id": p["customer_id"],
                    "tanggal_masuk": hari_ini,
                    "catatan": "Simulasi: alat diantar langsung ke meja depan.",
                    "items": [{"equipment_id": p["alat_order"], "kondisi_terima": "Baik"}],
                },
            )))
        if not tugas:
            s.gagal("tidak ada permintaan atau pelanggan untuk diproses")
            return

        jumlah = {"terima": 0, "order": 0}
        for jenis, sasaran, r in serentak(tugas):
            if r.status in (200, 201):
                jumlah[jenis] += 1
            else:
                s.gagal(f"{jenis} {sasaran}: HTTP {r.status} {_pesan(r.data)}")
        s.catatan.append(f"{jumlah['terima']} permintaan diterima, {jumlah['order']} order dicatat")

    # ── laporan ─────────────────────────────────────────────────────────────

    def statistik(self) -> dict[str, Any]:
        per: dict[str, list[float]] = {}
        status: dict[str, int] = {}
        for c in self.api.catatan:
            per.setdefault(f"{c['metode']} {c['path']}", []).append(c["ms"])
            status[str(c["status"])] = status.get(str(c["status"]), 0) + 1
        ringkas = {}
        for k, v in sorted(per.items()):
            v = sorted(v)
            ringkas[k] = {
                "n": len(v),
                "median_ms": round(statistics.median(v)),
                "p95_ms": round(v[min(len(v) - 1, int(len(v) * 0.95))]),
                "maks_ms": round(v[-1]),
            }
        return {"per_endpoint": ringkas, "status_http": status, "total_request": len(self.api.catatan)}

    def jalan(self) -> dict[str, Any]:
        mulai = time.time()
        langkah = [
            self.s0_server_benar, self.s1_login_pagi, self.s1b_satu_wifi, self.s2_kirim_serentak,
            self.s3_kirim_dobel, self.s8_pemisahan_wewenang, self.s9_viewer, self.s4_rebutan_approve,
            self.s5_approve_lawan_tolak, self.s6_approve_massal_dan_baca, self.s7_tunggu_sertifikat,
            self.s10_verifikasi_publik, self.s11_permintaan_serentak, self.s12_terima_dan_order_serentak,
        ]
        for f in langkah:
            jumlah_awal = len(self.skenario)
            try:
                f()
            except SystemExit:
                self._cetak(self.skenario[-1])
                raise
            except Exception as e:  # noqa: BLE001 — satu skenario rusak tidak boleh menelan laporan
                s = self.skenario[-1] if len(self.skenario) > jumlah_awal else self.baru("?", f.__name__)
                s.gagal(f"skrip error: {type(e).__name__}: {e}")
            self._cetak(self.skenario[-1])

        return {
            "durasi_detik": round(time.time() - mulai, 1),
            "server": self.api.bases,
            "skenario": [s.__dict__ for s in self.skenario],
            "sesi_disetujui": sorted(self.sesi_disetujui),
            "sesi_dikenal": sorted(self.sesi_dikenal),
            "sertifikat": self.sertifikat,
            "statistik": self.statistik(),
        }

    @staticmethod
    def _cetak(s: Skenario) -> None:
        print(f"  -> {'LULUS' if s.lulus else 'GAGAL'}. " + " | ".join(s.catatan), flush=True)
        for t in s.temuan:
            print(f"     TEMUAN: {t}", flush=True)
        for t in s.risiko:
            print(f"     RISIKO: {t}", flush=True)


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 2
    with open(sys.argv[1], encoding="utf-8") as f:
        fixture = json.load(f)
    sim = Simulasi(fixture)
    try:
        laporan = sim.jalan()
    except SystemExit as e:
        laporan = {"dihentikan": str(e), "skenario": [s.__dict__ for s in sim.skenario]}
    with open(sys.argv[2], "w", encoding="utf-8") as f:
        json.dump(laporan, f, ensure_ascii=False, indent=2)
    gagal = [s["kode"] for s in laporan["skenario"] if not s["lulus"]]
    print(f"\nSELESAI. Skenario gagal: {gagal or 'tidak ada'}")
    return 1 if gagal or "dihentikan" in laporan else 0


if __name__ == "__main__":
    sys.exit(main())
