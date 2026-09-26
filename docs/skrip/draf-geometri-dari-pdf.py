#!/usr/bin/env python3
"""
Draf geometri OCR dari PDF lembar kerja ASLI lab (SIDIK-FM-CAL-*).

Kenapa ada: berkas di database/ocr-templates/*.json lahir dari
`ocr:rangka-geometri` = grid rata di kanvas A4 potret 1654x2339, lalu kertasnya
yang dicetak ulang mengikuti JSON. Padahal 41 PDF formulir asli lab punya vektor
kotak + teks tercetak, dan banyak yang Letter LANSKAP (mis. pH 792x612 pt).
Skrip ini membaca vektor itu langsung, jadi koordinatnya hasil UKUR dari
dokumen mutu, bukan rancangan.

Keluarannya DRAF. Tidak ada satu kotak pun yang diberi kunci sel
(`tabel|baris|repeat|field`) di sini: memetakan kotak -> kunci wajib dicek
manusia terhadap CalibrationProfile::bentukLembarKerja(), dan ditulis di
berkas `.peta.json` TERPISAH — draf ini disalin apa adanya, tidak disunting.
`terverifikasi` selalu false.

Isinya:
- `sel`: kotak tabel find_tables(). `jenis` = `tercetak` (ada teks cetak),
  `calon_isian` (kosong), atau `wadah` (kosong tapi membungkus kotak isian
  lain — sel Usage Check yang berisi centang, sel yang sudah jadi isian
  berlabel, sel selebar baris di atas sel-sel kecil).
- `kotak_centang`: `asal` = `vektor` (rect kecil) atau `gambar` (kontrol form
  Excel yang diekspor jadi raster; pikselnya yang dibaca).
- `isian_berlabel`: dari "Label :" / "Label =", dan dari "kata ___ satuan"
  tanpa pemisah (First ___ (°C)). Dipotong di tepi sel/panel, di kotak
  centang, dan dibagi di tengah kalau dua baris terlalu rapat.

Koordinat dinormalkan 0..1 terhadap halaman (x/w dibagi lebar, y/h dibagi
tinggi) supaya tidak terikat dpi maupun orientasi.

Pakai (folder berisi PDF, atau satu PDF):
  python3 docs/skrip/draf-geometri-dari-pdf.py \
      Project-PT-Sidik/worksheet_alat_calibration  keluaran/ocr-draf
Butuh: pip install pdfplumber pillow. Semua berkas ditulis UTF-8 berakhir-baris
LF, jadi keluarannya byte-identik di Windows maupun Linux (tanpa PYTHONUTF8).
"""
import hashlib, json, re, sys
from pathlib import Path

import pdfplumber
from pdfminer.pdftypes import resolve1
from PIL import ImageDraw

MIN_SEL_PT = 8.0          # kotak lebih kecil dari ini = garis/artefak
CENTANG_MIN, CENTANG_MAX = 5.0, 14.0   # kotak centang TH-2, Usage Check, dst.
MIN_ISIAN_PT = 20.0       # isian berlabel lebih sempit dari ini = bukan isian (centang, dsb.)
JEDA_LABEL_PT = 20.0      # jeda antar-kata selebar ini memutus label ("awal ___ akhir")
POLA_KODE = re.compile(r"SIDIK-FM-CAL-\d{4}(?:\.[A-D])?")
POLA_REV = re.compile(r"Revis[ei]\s*:?\s*(\d+)", re.I)
# Satuan penutup isian: °C dengan penanda derajat apa pun yang dipakai formulir
# (°, º, "o" superskrip, ⁰ U+2070 di TITS), %RH, dan % — dengan/tanpa kurung.
POLA_SATUAN = re.compile(r"^\(?(?:°|º|o|⁰)C\)?$|^\(?%\s?RH\)?$|^%$")


def pemisah(teks):
    """Kata yang memisah label dari isiannya: ":" atau "=", menempel atau berdiri."""
    return teks in (":", "=") or teks.endswith((":", "="))


def norm(x0, top, x1, bottom, W, H):
    return {"x": round(x0 / W, 5), "y": round(top / H, 5),
            "w": round((x1 - x0) / W, 5), "h": round((bottom - top) / H, 5)}


def teks_di_dalam(words, x0, top, x1, bottom, pad=1.0):
    isi = [w["text"] for w in words
           if w["x0"] >= x0 - pad and w["x1"] <= x1 + pad
           and w["top"] >= top - pad and w["bottom"] <= bottom + pad]
    return " ".join(isi).strip()


def tumpang(a, b):
    return min(a[2], b[2]) - max(a[0], b[0]) > 0.5 and min(a[3], b[3]) - max(a[1], b[1]) > 0.5


def kanal_gambar(g):
    """Jumlah kanal kalau formatnya terbaca (8-bit Flate, RGB/Gray), atau None."""
    st = g["stream"]
    kanal = {"DeviceRGB": 3, "DeviceGray": 1}.get(str(g.get("colorspace", [""])[0]).strip("/'"))
    if g.get("bits") != 8 or kanal is None or "FlateDecode" not in str(st.attrs.get("Filter")):
        return None
    return kanal


def seukuran_centang(g):
    lebar_pt, tinggi_pt = g["x1"] - g["x0"], g["bottom"] - g["top"]
    return CENTANG_MIN <= lebar_pt <= 60 and CENTANG_MIN <= tinggi_pt <= 60


def kotak_centang_dari_gambar(g):
    """Kotak centang yang tertanam sebagai GAMBAR, atau None.

    Piksel gambarnya sendiri yang dibaca (bukan render halaman), jadi teks label
    dan garis tabel yang tercetak di atasnya tidak ikut. Diterima cuma kalau
    piksel gelapnya membentuk bujursangkar CENTANG_MIN..CENTANG_MAX yang
    BERONGGA — logo atau foto tidak lolos.
    """
    lebar_pt, tinggi_pt = g["x1"] - g["x0"], g["bottom"] - g["top"]
    kanal = kanal_gambar(g)
    if not seukuran_centang(g) or kanal is None:
        return None
    st = g["stream"]
    w, h = g["srcsize"]
    data = st.get_data()
    if len(data) != w * h * kanal:
        return None
    alpha = None
    if st.attrs.get("SMask") is not None:
        a = resolve1(st.attrs["SMask"]).get_data()
        alpha = a if len(a) == w * h else None
    xs, ys = [], []
    for j in range(h):
        for i in range(w):
            k = j * w + i
            if alpha is not None and alpha[k] < 128:
                continue
            if sum(data[kanal * k:kanal * k + kanal]) < kanal * 110:
                xs.append(i)
                ys.append(j)
    if not xs:
        return None
    sx, sy = lebar_pt / w, tinggi_pt / h
    x0, top = g["x0"] + min(xs) * sx, g["top"] + min(ys) * sy
    x1, bottom = g["x0"] + (max(xs) + 1) * sx, g["top"] + (max(ys) + 1) * sy
    kw, kh = x1 - x0, bottom - top
    isi = len(xs) / ((max(xs) - min(xs) + 1) * (max(ys) - min(ys) + 1))
    if not (CENTANG_MIN <= kw <= CENTANG_MAX and CENTANG_MIN <= kh <= CENTANG_MAX
            and abs(kw - kh) < 2.5 and isi < 0.6):
        return None
    return (x0, top, x1, bottom)


def olah(pdf_path: Path, keluar: Path):
    raw = pdf_path.read_bytes()
    with pdfplumber.open(pdf_path) as pdf:
        if len(pdf.pages) != 1:
            print(f"  ! {pdf_path.name}: {len(pdf.pages)} halaman, cuma halaman 1 yang dibaca")
        p = pdf.pages[0]
        W, H = float(p.width), float(p.height)
        words = p.extract_words(keep_blank_chars=False, use_text_flow=False)
        teks = p.extract_text() or ""

        kode = POLA_KODE.search(teks)
        rev = POLA_REV.search(teks)

        # --- kotak tabel (dedup per koordinat dibulatkan)
        sel, lihat = [], set()
        for ti, t in enumerate(p.find_tables()):
            for c in t.cells:
                x0, top, x1, bottom = c
                if (x1 - x0) < MIN_SEL_PT or (bottom - top) < MIN_SEL_PT:
                    continue
                k = tuple(round(v, 1) for v in c)
                if k in lihat:
                    continue
                lihat.add(k)
                isi = teks_di_dalam(words, *c)
                sel.append({"id": f"s{len(sel):03d}", "tabel_ke": ti,
                            "jenis": "tercetak" if isi else "calon_isian",
                            "teks_tercetak": isi or None,
                            **norm(x0, top, x1, bottom, W, H)})

        # --- kotak centang: bujursangkar kecil, dari DUA sumber.
        # Vektor: rect kecil hampir bujursangkar. Gambar: kontrol form Excel
        # yang diekspor jadi raster (Usage Check & TH-n di pH, K/T/R/J/N/S di
        # TITS) — di situ nol rect/garis, jadi dulu tidak satu pun terbaca.
        calon_centang = []
        for r in p.rects:
            w, h = r["x1"] - r["x0"], r["bottom"] - r["top"]
            kotak = (r["x0"], r["top"], r["x1"], r["bottom"])
            # Rect yang sama sering digambar dua kali (isi + garis tepi): satu saja.
            if (CENTANG_MIN <= w <= CENTANG_MAX and CENTANG_MIN <= h <= CENTANG_MAX and abs(w - h) < 2.5
                    and not any(tumpang(kotak, k) for _, k in calon_centang)):
                calon_centang.append(("vektor", kotak))
        # Gambar seukuran centang yang formatnya tidak terbaca (ICCBased,
        # Indexed, 1-bit, DCT) DILAPORKAN, bukan dilewati diam-diam: di situ
        # kotak centangnya tidak terdeteksi. Per 26 Sep 2026 ke-131 gambar
        # seukuran centang di 41 formulir semuanya RGB 8-bit Flate.
        tak_terbaca = [g for g in p.images if seukuran_centang(g) and kanal_gambar(g) is None]
        if tak_terbaca:
            print(f"  ! {pdf_path.name}: {len(tak_terbaca)} gambar seukuran centang formatnya tidak "
                  f"terbaca — kotak centang di situ tidak terdeteksi")
        for g in p.images:
            kotak = kotak_centang_dari_gambar(g)
            if kotak and not any(tumpang(kotak, k) for _, k in calon_centang):
                calon_centang.append(("gambar", kotak))

        centang = []
        for asal, (cx0, ctop, cx1, cbottom) in calon_centang:
            # label = kata terdekat di kanan pada baris yang sama, atau di kiri
            baris = [x for x in words if abs((x["top"] + x["bottom"]) / 2 - (ctop + cbottom) / 2) < 5]
            kanan = sorted([x for x in baris if x["x0"] >= cx1 - 1], key=lambda x: x["x0"])
            kiri = sorted([x for x in baris if x["x1"] <= cx0 + 1], key=lambda x: -x["x1"])
            label = (kanan[0]["text"] if kanan and kanan[0]["x0"] - cx1 < 25
                     else (kiri[0]["text"] if kiri else None))
            centang.append({"id": f"c{len(centang):02d}", "label_dekat": label, "asal": asal,
                            **norm(cx0, ctop, cx1, cbottom, W, H)})

        # --- isian berlabel "Label :" (identitas alat, pemilik, tanggal)
        # Garis formulir digambar sebagai rect tipis, jadi batas sel & panel
        # dibaca dari tepi vektornya (p.edges), bukan dari p.rects yang lebar.
        # Sisi kotak centang ikut jadi batas: centang berupa GAMBAR tidak punya
        # tepi vektor, jadi tanpa ini isian bisa melebar menumpukinya.
        tepi_v = [e for e in p.edges if e["orientation"] == "v"] + [
            {"x0": x, "top": ctop, "bottom": cbottom}
            for _, (cx0, ctop, cx1, cbottom) in calon_centang for x in (cx0, cx1)]
        tepi_h = [e for e in p.edges if e["orientation"] == "h"] + [
            {"x0": cx0, "x1": cx1, "top": y}
            for _, (cx0, ctop, cx1, cbottom) in calon_centang for y in (ctop, cbottom)]

        def potong_tegak(mulai, batas, yc, tinggi):
            """Atas/bawah dipotong di garis mendatar TERDEKAT ke baris yang melintasi kotak.

            Urutan `tepi_h` tidak berpengaruh: tiap garis yang lebih dekat menggeser
            batasnya lagi. Jangan diganti "berhenti di garis pertama yang ketemu".
            """
            atas, bawah = yc - tinggi / 2, yc + tinggi / 2
            for e in tepi_h:
                if e["x0"] < batas and e["x1"] > mulai:
                    if atas < e["top"] <= yc - 1:
                        atas = e["top"] + 0.5
                    elif yc + 1 <= e["top"] < bawah:
                        bawah = e["top"] - 0.5
            return atas, bawah

        def label_ke_kiri(w, sebaris):
            """Kata di kiri `w` (termasuk `w`), berhenti di pemisah sebelumnya.

            Dulu semua kata sampai 140 pt ke kiri ikut, jadi label "Catatan"
            terbaca "Dikalibrasi Oleh: Diperiksa Oleh: Catatan".
            """
            kiri = sorted([x for x in sebaris if x["x1"] <= w["x1"] and w["x0"] - x["x1"] < 140],
                          key=lambda x: x["x0"])
            yc = (w["top"] + w["bottom"]) / 2
            for j in range(len(kiri) - 2, -1, -1):
                # Berhenti juga di satuan (penutup isian sebelumnya) dan di garis
                # vertikal — "First ___ (°C) │ First ___ (%RH)" bukan satu label.
                # Jeda lebih lebar dari satu isian juga pemisah: "awal ___ akhir".
                # KECUALI jeda tepat sebelum ":"/"=" itu sendiri — titik dua yang
                # dirata-kolomkan ("1. Name ······· :") memang jauh dari labelnya.
                garis = any(e["top"] - 1 <= yc <= e["bottom"] + 1 and kiri[j]["x1"] < e["x0"] < kiri[j + 1]["x0"]
                            for e in tepi_v)
                jeda = (kiri[j + 1]["x0"] - kiri[j]["x1"] > JEDA_LABEL_PT
                        and not (kiri[j + 1] is w and pemisah(w["text"])))
                if pemisah(kiri[j]["text"]) or POLA_SATUAN.match(kiri[j]["text"]) or garis or jeda:
                    kiri = kiri[j + 1:]
                    break
            return " ".join(x["text"] for x in kiri).strip(": =").strip()

        calon_isian = []
        for i, w in enumerate(words):
            # "=" setara ":" — T awal = ___ °C, RH akhir = ___ % (TITS dan
            # kawan-kawan). "=" di rumus tercetak ("e = 0,1 mg") gugur sendiri
            # di syarat lebar: kata sesudahnya menempel.
            if not pemisah(w["text"]):
                continue
            yc = (w["top"] + w["bottom"]) / 2
            sebaris = [x for x in words if abs((x["top"] + x["bottom"]) / 2 - yc) < 3.5]
            label = label_ke_kiri(w, sebaris)
            # Minimal dua huruf: "z1 =" di tabel Accuracy Timbangan itu isian;
            # dulu syaratnya tiga karakter, jadi "z1" gugur sementara "m1'" lolos.
            if len(label.replace(" ", "")) < 2:
                continue
            # Batas kanan = yang PALING DEKAT dari kata tercetak berikutnya di
            # baris itu dan tepi vertikal pertama yang melintasi baris ini (batas
            # sel/panel pembungkus). Tanpa tepi vertikal, kotak dulu melebar
            # sampai 0,95 lebar halaman dan menumpuki panel tabel di sebelahnya.
            # Tepi yang menyisakan ruang kurang dari MIN_ISIAN_PT di kanan titik dua
            # adalah tepi KIRI kotak isiannya sendiri ("Material Pipa :│ … │",
            # "Catatan:  │ … │"): dilompati, kotak mulai sesudahnya.
            tepi = sorted(e["x0"] for e in tepi_v
                          if e["top"] - 1 <= yc <= e["bottom"] + 1 and e["x0"] > w["x1"] - 0.5)
            mulai = w["x1"] + 2
            while tepi and tepi[0] - 2 - mulai < MIN_ISIAN_PT:
                mulai = max(mulai, tepi.pop(0) + 1.5)
            kanan = sorted([x for x in sebaris if x["x0"] > w["x1"] + 2], key=lambda x: x["x0"])
            calon = ([kanan[0]["x0"] - 2] if kanan else []) + ([tepi[0] - 2] if tepi else [])
            batas = min(calon) if calon else W * 0.95
            if batas - mulai < MIN_ISIAN_PT:
                continue
            atas, bawah = potong_tegak(mulai, batas, yc, (w["bottom"] - w["top"]) * 1.9)
            calon_isian.append({"label": label, "w": w, "yc": yc,
                                "x0": mulai, "x1": batas, "top": atas, "bottom": bawah})

        # --- isian "kata ___ satuan" TANPA pemisah: blok Env. Condition pH
        # ("First ___ (°C)" / "End ___ (%RH)") yang dulu terbaca SATU kotak di
        # luar bloknya. Celahnya jadi isian cuma kalau tidak ada garis vertikal
        # di antara kata & satuannya — yang dipisah garis itu kepala tabel
        # ("pH │ °C"), bukan tempat menulis.
        for u in words:
            if not POLA_SATUAN.match(u["text"]):
                continue
            yc = (u["top"] + u["bottom"]) / 2
            sebaris = [x for x in words if abs((x["top"] + x["bottom"]) / 2 - yc) < 3.5]
            kiri = sorted([x for x in sebaris if x["x1"] <= u["x0"] - 0.5], key=lambda x: -x["x1"])
            if not kiri:
                continue
            pv = kiri[0]
            mulai, batas = pv["x1"] + 2, u["x0"] - 2
            if batas - mulai < MIN_ISIAN_PT or any(
                    e["top"] - 1 <= yc <= e["bottom"] + 1 and pv["x1"] + 1 < e["x0"] < u["x0"] - 1
                    for e in tepi_v):
                continue
            atas, bawah = potong_tegak(mulai, batas, yc, (pv["bottom"] - pv["top"]) * 1.9)
            # Yang berlabel ":"/"=" sudah lahir di atas ("Suhu : ___ °C").
            # Cuma di BARIS YANG SAMA: kotak baris "First" yang belum dibagi
            # masih menjorok ke baris "End", dan itu bukan kembarannya.
            if any(abs(c["yc"] - yc) < 3.5
                   and tumpang((mulai, atas, batas, bawah), (c["x0"], c["top"], c["x1"], c["bottom"]))
                   for c in calon_isian):
                continue
            label = label_ke_kiri(pv, sebaris)
            calon_isian.append({"label": f"{label} … {u['text']}" if label else u["text"],
                                "w": pv, "yc": yc, "x0": mulai, "x1": batas, "top": atas, "bottom": bawah})

        # Dua baris isian yang terlalu rapat (Received Date / Calibration Date)
        # dibagi di tengah jarak kedua barisnya, supaya tidak ada kotak bertumpuk.
        for a in calon_isian:
            for b in calon_isian:
                if a is b or not (a["yc"] < b["yc"]) or a["x1"] <= b["x0"] or b["x1"] <= a["x0"]:
                    continue
                if a["bottom"] > b["top"]:
                    tengah = (a["yc"] + b["yc"]) / 2
                    a["bottom"], b["top"] = min(a["bottom"], tengah), max(b["top"], tengah)

        isian = []
        for c in calon_isian:
            isian.append({"id": f"f{len(isian):02d}", "label": c["label"],
                          "tercetak_sesudah_titik_dua": teks_di_dalam(words, c["w"]["x1"] + 1, c["top"], c["x1"], c["bottom"]) or None,
                          **norm(c["x0"], c["top"], c["x1"], c["bottom"], W, H)})

        # --- sel kosong yang membungkus kotak isian LAIN adalah WADAH, bukan
        # tempat menulis: sel Usage Check yang berisi kotak centang, sel di kanan
        # "Material Pipa :" yang sudah jadi isian berlabel, atau sel selebar
        # baris yang find_tables() lahirkan di atas sel-sel kecil. Dibiarkan
        # `calon_isian`, dua kotak menunjuk tempat tulis yang sama. Ukurannya
        # >= 50 % luas kotak yang lebih kecil ada di dalam sel: centang yang
        # menonjol sepoin dari selnya tetap terhitung terbungkus. Untuk centang
        # cukup separuh dari yang LEBIH KECIL di antara keduanya: rect centang
        # vektor sering ikut terbaca find_tables() jadi sel seukuran dirinya,
        # kadang sepersepuluh poin lebih kecil dari centangnya sendiri.
        pt = lambda o: (o["x"] * W, o["y"] * H, (o["x"] + o["w"]) * W, (o["y"] + o["h"]) * H)
        luas = lambda k: max(0.0, k[2] - k[0]) * max(0.0, k[3] - k[1])
        iris = lambda a, b: luas((max(a[0], b[0]), max(a[1], b[1]), min(a[2], b[2]), min(a[3], b[3])))
        kotak_centang = [pt(o) for o in centang]
        kotak_lain = [pt(o) for o in isian] + [pt(s) for s in sel if s["jenis"] == "calon_isian"]
        for s in sel:
            if s["jenis"] != "calon_isian":
                continue
            ks = pt(s)
            if (any(iris(ks, k) >= 0.5 * min(luas(k), luas(ks)) for k in kotak_centang)
                    or any(luas(k) < luas(ks) - 0.01 and iris(ks, k) >= 0.5 * luas(k) for k in kotak_lain)):
                s["jenis"] = "wadah"

        # --- jangkar: teks tercetak yang stabil (dipakai HP buat meratakan foto
        # formulir lama TANPA marker). Diambil semua; yang mau dipakai dipilih manusia.
        jangkar = [{"teks": w["text"], **norm(w["x0"], w["top"], w["x1"], w["bottom"], W, H)}
                   for w in words if len(w["text"]) >= 3]

        draf = {
            "_catatan": "DRAF otomatis dari vektor PDF formulir asli (docs/skrip/draf-geometri-dari-pdf.py). "
                        "Jangan disunting tangan: salah di sini dibetulkan di skripnya lalu dijalankan ulang. "
                        "Kotak -> kunci profil dipetakan manusia di berkas .peta.json terpisah; "
                        "adu ke >=20 foto nyata sebelum terverifikasi=true.",
            "sumber": {"pdf": pdf_path.name, "sha256": hashlib.sha256(raw).hexdigest(),
                       "halaman": 1, "ukuran_pt": {"w": W, "h": H},
                       "orientasi": "lanskap" if W > H else "potret"},
            "kode_dokumen": kode.group(0) if kode else None,
            "revisi_tercetak": rev.group(1) if rev else None,
            "terverifikasi": False,
            "koordinat": "ternormal_0_1",
            "sel": sel, "kotak_centang": centang, "isian_berlabel": isian, "jangkar_teks": jangkar,
        }

        nama = pdf_path.stem
        (keluar / f"{nama}.json").write_text(json.dumps(draf, ensure_ascii=False, indent=1), encoding="utf-8", newline="\n")

        # --- overlay buat dicek mata
        dpi = 110
        im = p.to_image(resolution=dpi).original.convert("RGB")
        s = dpi / 72
        d = ImageDraw.Draw(im)
        def kotak(o, warna, tebal=2):
            d.rectangle([o["x"] * W * s, o["y"] * H * s, (o["x"] + o["w"]) * W * s, (o["y"] + o["h"]) * H * s],
                        outline=warna, width=tebal)
        for o in sel:
            kotak(o, (0, 170, 60) if o["jenis"] == "calon_isian" else (170, 170, 170), 2)
        for o in isian:
            kotak(o, (0, 90, 255), 2)
        for o in centang:
            kotak(o, (255, 140, 0), 3)
        im.save(keluar / f"{nama}.png")

        return {"pdf": pdf_path.name, "kode": draf["kode_dokumen"], "rev": draf["revisi_tercetak"],
                "orientasi": draf["sumber"]["orientasi"],
                "calon_isian": sum(o["jenis"] == "calon_isian" for o in sel),
                "tercetak": sum(o["jenis"] == "tercetak" for o in sel),
                "isian_berlabel": len(isian), "centang": len(centang)}


def main():
    if len(sys.argv) != 3:
        print(__doc__); sys.exit(2)
    masuk, keluar = Path(sys.argv[1]), Path(sys.argv[2])
    keluar.mkdir(parents=True, exist_ok=True)
    berkas = [masuk] if masuk.is_file() else sorted(masuk.glob("*.pdf"))
    ringkas = [olah(f, keluar) for f in berkas]
    (keluar / "_ringkasan.json").write_text(json.dumps(ringkas, ensure_ascii=False, indent=1), encoding="utf-8", newline="\n")
    for r in ringkas:
        print(f"{r['kode'] or '?':22} rev={r['rev'] or '?':>2} {r['orientasi']:7} "
              f"isian={r['calon_isian']:3} label={r['isian_berlabel']:2} centang={r['centang']:2}  {r['pdf'][:55]}")
    tanpa_kode = [r["pdf"] for r in ringkas if not r["kode"]]
    if tanpa_kode:
        print("\n! kode dokumen tidak terbaca di:", tanpa_kode); sys.exit(1)


if __name__ == "__main__":
    main()
