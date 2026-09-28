#!/usr/bin/env python3
"""
uji-paralel-tekanan-piston.py — percobaan paralel yang menghasilkan BUKTI, bukan kesan.

Untuk tiap workbook sesi (master tekanan / piston yang sudah diisi data sesi
NYATA) di folder masukan:

  1. sha256 berkas dicatat, dan diadu ke manifest template yang dibekukan
     (`database/data/manifest-workbook-tekanan-piston.json`). Workbook sesi
     memang berbeda byte-nya dari template (isinya beda) — yang diadu adalah
     TABEL REFERENSI-nya (konversi, titik standar, drift, CMC, timbangan,
     koreksi suhu) terhadap tabel yang dipakai aplikasi. Beda tabel = beda versi
     rumus yang dipakai lab, dan itu dilaporkan, bukan ditelan.
  2. Data mentah yang SAMA PERSIS (dibaca dari INPUT_DATA/INPUT DATA) masuk ke:
       a. reimplementasi Python (`gen-tabel-standar-*.py`) — yang diadu ke SETIAP
          sel cache Excel sesi itu (FC, U95, SERTIFIKAT), dan
       b. mesin hitung aplikasi (`php artisan kalibrasi:hitung-mentah`), mode
          MASTER (tiru Excel) DAN mode BENAR (yang terbit).
  3. NILAI ANTARA dibandingkan, bukan cuma hasil akhir: AVG up/down, STDEV,
     histeresis, indeks terpilih, koreksi standar, standar terkoreksi, tiap
     komponen budget (U, pembagi, vi, ci, u), uc, v_eff, df, k, U, CMC, U95.
     Dua kesalahan yang saling meniadakan di hasil akhir tetap kelihatan.
  4. Laporan mencatat formula_version (kode rumus aplikasi) yang dipakai.
  5. Kalau ada SATU saja yang beda: status TAHAN — sertifikat sesi itu jangan
     diterbitkan dari aplikasi sampai selisihnya ditelusuri sampai sel. Skrip ini
     tidak memutuskan apa pun di tempat.

Minimal 5 sesi per alat dengan data yang bervariasi (satuan, rentang, varian,
tampilan/rasio jarum, sub-jenis); ringkasan akhir memperingatkan kalau kurang.

Pakai:
    python docs/skrip/uji-paralel-tekanan-piston.py <folder-xlsm> [--kata-sandi spirit285]

Keluaran: laporan-uji-paralel-<YYYY-MM-DD>.json & .md di folder masukan.
Identitas pelanggan TIDAK dibaca dan tidak masuk laporan.
"""
import datetime
import glob
import hashlib
import importlib.util
import json
import os
import subprocess
import sys

AKAR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
TOL = 1e-12


def muat_modul(nama, berkas):
    spec = importlib.util.spec_from_file_location(nama, os.path.join(AKAR, "docs", "skrip", berkas))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


TK = muat_modul("ref_tekanan", "gen-tabel-standar-tekanan.py")
PV = muat_modul("ref_piston", "gen-tabel-standar-piston-volume.py")


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for blok in iter(lambda: fh.read(1 << 20), b""):
            h.update(blok)
    return h.hexdigest()


def kenali(path):
    """Keluarga & varian dari BENTUK workbook, bukan dari nama berkas."""
    wf, wv = TK.buka(path)
    lembar = set(wf.sheetnames)
    try:
        if "PERHITUNGAN FC" in lembar:
            kerja = str(wv["PERHITUNGAN FC"]["I8"].value or "").strip().lower()
            if "STANDAR_SPMK" in lembar:
                return "tekanan", "spmk"
            if "STANDAR_ADDITEL" in lembar:
                return "tekanan", "differential"
            if "STANDAR_DRUCK" in lembar:
                return "tekanan", {"kpa": "druck07g", "psi": "druck13g"}.get(kerja)
        if "PERHITUNGAN" in lembar and "Tabel MPE" in lembar:
            return "piston", ("graduated" if wf["PERHITUNGAN"]["H102"].value is not None else "fixed")
    finally:
        wf.close(); wv.close()
    return None, None


def php(keluarga, varian, masukan):
    """Mesin hitung APLIKASI, dua mode. Tanpa database.

    Perintahnya memang tidak menyentuh database, tapi `.env` mesin kerja
    menunjuk PRODUKSI — jadi koneksinya tetap dialihkan ke SQLite memori di
    sini, supaya tidak ada satu jalur boot pun yang bisa membukanya.
    """
    env = {**os.environ, "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:", "CACHE_STORE": "array",
           "SESSION_DRIVER": "array", "QUEUE_CONNECTION": "sync", "BROADCAST_CONNECTION": "null"}
    hasil = subprocess.run(
        ["php", "artisan", "kalibrasi:hitung-mentah", keluarga, "--varian=" + (varian or "")],
        input=json.dumps(masukan), capture_output=True, text=True, cwd=AKAR, timeout=300, env=env,
    )
    if hasil.returncode != 0:
        raise RuntimeError(hasil.stderr.strip() or hasil.stdout.strip())
    return json.loads(hasil.stdout)


def beda(label, a, b, catat):
    if a is None or b is None:
        if a != b:
            catat.append({"nilai": label, "acuan": a, "aplikasi": b})
        return
    if abs(float(a) - float(b)) > TOL * max(1.0, abs(float(a))):
        catat.append({"nilai": label, "acuan": a, "aplikasi": b, "selisih": abs(float(a) - float(b))})


PETA_TEKANAN = [("E", "E"), ("M", "rata_up"), ("N", "rata_down"), ("dev_up", "deviasi_up"),
                ("dev_down", "deviasi_down"), ("sd_up", "stdev_up"), ("sd_down", "stdev_down"),
                ("hys_avg", "histeresis_rata"), ("indeks", "indeks"), ("koreksi_up", "koreksi_up"),
                ("koreksi_down", "koreksi_down"), ("koreksi_tinggi", "koreksi_tinggi"),
                ("terkoreksi_up", "terkoreksi_up"), ("terkoreksi_down", "terkoreksi_down"),
                ("u95_titik", "u95_titik")]
PETA_PISTON = [("m_rata", "m_rata"), ("stdev", "stdev"), ("indeks_suhu", "indeks_suhu"),
               ("koreksi_meter", "koreksi_meter"), ("koreksi_sensor", "koreksi_sensor"),
               ("t_rata", "t_rata"), ("rho_air", "rho_air"), ("V20", "V20"), ("deviasi", "deviasi")]


def adu_hasil(ref, app, peta, selisih):
    for i, (x, p) in enumerate(zip(ref["per_titik"], app["per_titik"])):
        for a, b in peta:
            beda(f"titik {i + 1} {a}", x.get(a), p.get(b), selisih)
    for i, (k, d) in enumerate(zip(ref["komponen"], app["komponen"])):
        if k["kode"] != d["sumber"]:
            selisih.append({"nilai": f"komponen {i + 1}", "acuan": k["kode"], "aplikasi": d["sumber"]})
        for kn in ("U", "pembagi", "vi", "ci", "u"):
            beda(f"komponen {k['kode']} {kn}", k[kn], d[kn], selisih)
    for kn in ("uc", "veff", "k", "U"):
        beda(kn, ref[kn], app[kn], selisih)
    if ref.get("df") != app.get("df"):
        selisih.append({"nilai": "df", "acuan": ref.get("df"), "aplikasi": app.get("df")})


def uji_tekanan(path, varian):
    tabel_sesi = TK.baca_varian(varian, path)
    m = TK.baca_masukan(varian, path)
    TK.hasil.clear()
    ref = TK.hitung(varian, tabel_sesi, m, "master")
    TK.adu(varian, path, tabel_sesi, m, ref)
    excel = [h for h in TK.hasil if not h[4]]
    tabel_app = json.load(open(os.path.join(AKAR, "database/data/tabel-standar-tekanan.json"), encoding="utf-8"))["varian"][varian]
    beda_tabel = [k for k in ("konversi", "titik_standar", "drift", "cmc_master", "standar") if tabel_sesi[k] != tabel_app[k]]
    app = php("tekanan", varian, m)
    selisih = []
    adu_hasil(ref, app["master"], PETA_TEKANAN, selisih)
    cmc = TK.cmc_kerja(varian, tabel_sesi, m)
    beda("CMC", cmc, app["master"].get("cmc"), selisih)
    beda("U95", max(ref["U"], cmc), app["master"].get("u95"), selisih)
    variasi = {"satuan": m["satuan"], "tampilan": m["tampilan"], "rasio": m["rasio_jarum"], "jenis": m["jenis_tekanan"],
               "rentang_setelan": [min(t["setelan"] for t in m["titik"]), max(t["setelan"] for t in m["titik"])]}
    return excel, beda_tabel, selisih, app, variasi


def uji_piston(path, keluarga):
    tabel_sesi = PV.baca_tabel(path)
    m = PV.masukan_fixed(path) if keluarga == "fixed" else PV.masukan_grad(path)
    PV.hasil.clear()
    ref = PV.hitung(m, tabel_sesi, "master")
    (PV.adu_fixed if keluarga == "fixed" else PV.adu_grad)(path, ref)
    excel = [h for h in PV.hasil if not h[4]]
    tabel_app = json.load(open(os.path.join(AKAR, "database/data/tabel-standar-piston-volume.json"), encoding="utf-8"))
    beda_tabel = [k for k in ("timbangan", "koreksi_meter_suhu", "koreksi_sensor_suhu", "cmc", "mpe") if tabel_sesi[k] != tabel_app[k]]
    app = php("piston", None, m)
    selisih = []
    adu_hasil(ref, app["master"], PETA_PISTON, selisih)
    beda("CMC", ref.get("cmc"), app["master"].get("cmc"), selisih)
    beda("U95", ref.get("u95"), app["master"].get("u95"), selisih)
    variasi = {"jenis": m["jenis"], "sub_jenis": m["sub_jenis"], "satuan": m["satuan"], "kapasitas": m["kapasitas"],
               "timbangan": m["timbangan"], "nominal": [t["nominal"] for t in m["titik"]]}
    return excel, beda_tabel, selisih, app, variasi


def main():
    folder = sys.argv[1]
    if "--kata-sandi" in sys.argv:
        TK.PASSWORD = PV.PASSWORD = sys.argv[sys.argv.index("--kata-sandi") + 1]
    manifest = json.load(open(os.path.join(AKAR, "database/data/manifest-workbook-tekanan-piston.json"), encoding="utf-8"))
    sha_template = {b["sha256"]: b["berkas"] for b in manifest["berkas"]}
    laporan = []

    for path in sorted(glob.glob(os.path.join(folder, "*.xlsm"))):
        nama = os.path.basename(path)
        entri = {"berkas": nama, "sha256": sha256(path)}
        entri["template_beku"] = sha_template.get(entri["sha256"])
        try:
            keluarga, varian = kenali(path)
            if keluarga is None:
                entri["status"] = "DILEWATI — bukan master tekanan/piston"
                laporan.append(entri); continue
            entri.update({"keluarga": keluarga, "varian": varian})
            excel, beda_tabel, selisih, app, variasi = (uji_tekanan(path, varian) if keluarga == "tekanan"
                                                        else uji_piston(path, varian))
            entri.update({
                "versi_rumus": app.get("versi_rumus"),
                "kode_formula": app.get("kode_formula"),
                "variasi": variasi,
                "acuan_python_vs_excel_merah": [{"sel": h[0], "excel": h[1], "python": h[2]} for h in excel],
                "tabel_referensi_beda_dari_aplikasi": beda_tabel,
                "aplikasi_vs_acuan_beda": selisih,
                "benar_vs_master": {"u95_master": app["master"].get("u95"), "u95_benar": app["benar"].get("u95")},
            })
            ok = not excel and not beda_tabel and not selisih
            entri["status"] = "COCOK" if ok else "TAHAN — telusuri sampai sel, jangan terbitkan"
        except Exception as e:  # noqa: BLE001 — satu berkas rusak tidak boleh menghentikan batch
            entri["status"] = f"GAGAL DIBACA — {type(e).__name__}: {e}"
        laporan.append(entri)
        print(f"{entri['status'][:5]:5s}  {nama}")

    # Dikelompokkan per ALAT (kode formula = profil lampiran), bukan per
    # kalibrator: "minimal 5 sesi per alat" berarti Pressure Gauge yang lima
    # sesinya tersebar di DRUCK07G, DRUCK13G, dan SPMK — itu justru variasinya.
    # Keenam alat SELALU muncul — alat dengan nol sesi justru yang paling
    # perlu kelihatan di laporan, bukan hilang diam-diam.
    per_alat = {k: [] for k in ("TEKANAN-PRESSURE_GAUGE", "TEKANAN-VACUUM_GAUGE", "TEKANAN-DIFFERENTIAL_PRESSURE",
                                "PISTON-VOLUME-PISTON_PIPETTE", "PISTON-VOLUME-DISPENSETT", "PISTON-VOLUME-BURET_DIGITAL")}
    for e in laporan:
        if "varian" in e:
            per_alat.setdefault(e.get("kode_formula") or e["varian"], []).append(e)
    ringkas = {}
    for alat, daftar in per_alat.items():
        ragam = {json.dumps(e.get("variasi"), sort_keys=True) for e in daftar}
        ringkas[alat] = {
            "sesi": len(daftar),
            "cocok": sum(1 for e in daftar if e["status"] == "COCOK"),
            "variasi_berbeda": len(ragam),
            "peringatan": ([f"baru {len(daftar)} sesi, minimal 5"] if len(daftar) < 5 else [])
            + ([f"cuma {len(ragam)} bentuk data berbeda — variasikan satuan/rentang/varian"] if len(ragam) < min(5, len(daftar)) else []),
        }

    tgl = datetime.date.today().isoformat()
    keluar = {"dibuat": tgl, "toleransi": f"{TOL} x max(1,|acuan|)", "manifest_template": manifest["dibekukan"],
              "ringkasan": ringkas, "sesi": laporan}
    with open(os.path.join(folder, f"laporan-uji-paralel-{tgl}.json"), "w", encoding="utf-8", newline="\n") as fh:
        json.dump(keluar, fh, ensure_ascii=False, indent=2)
    with open(os.path.join(folder, f"laporan-uji-paralel-{tgl}.md"), "w", encoding="utf-8", newline="\n") as fh:
        fh.write(f"# Laporan uji paralel — {tgl}\n\nToleransi {TOL} × max(1,|acuan|). Nilai ANTARA diadu, bukan cuma U95.\n\n")
        fh.write("| Alat | Sesi | Cocok | Variasi | Peringatan |\n|---|---|---|---|---|\n")
        for alat, r in ringkas.items():
            fh.write(f"| {alat} | {r['sesi']} | {r['cocok']} | {r['variasi_berbeda']} | {'; '.join(r['peringatan']) or '—'} |\n")
        fh.write("\n| Berkas | sha256 | Versi rumus | Status |\n|---|---|---|---|\n")
        for e in laporan:
            fh.write(f"| {e['berkas']} | `{e['sha256'][:16]}…` | {e.get('versi_rumus', '—')} | {e['status']} |\n")
    print(json.dumps(ringkas, ensure_ascii=False, indent=2))
    sys.exit(0 if all(e["status"] in ("COCOK",) or e["status"].startswith("DILEWATI") for e in laporan) else 1)


if __name__ == "__main__":
    main()
