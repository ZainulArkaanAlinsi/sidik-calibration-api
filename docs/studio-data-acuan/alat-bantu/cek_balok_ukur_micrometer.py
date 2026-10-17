import csv, json
import sys
akar=sys.argv[1] if len(sys.argv)>1 else '.'
js=json.load(open(f'{akar}/database/data/tabel-standar-micrometer.json'))
bu={float(k):v for k,v in js['balok_ukur'].items()}
def f(x):
    try: return float(x)
    except: return None
for var in ['0-25mm','25-50mm','50-75mm','75-100mm']:
    p=f'{akar}/Project-PT-Sidik/alat-alat-Pt-Sidik/Panjang_CSV/Master_Olah_Data_Micrometer_{var}/Standar_GB.csv'
    rows=list(csv.reader(open(p,encoding='utf-8-sig')))
    csvb={}; geser=set()
    for i,r in enumerate(rows[9:132]):
        for j in range(0,len(r)-3):
            a,b,c=f(r[j]),f(r[j+1]),f(r[j+3])
            # pola: nominal, koreksi_mm, koreksi_um, nilai_uut  -> nilai = nominal + koreksi
            if a is not None and b is not None and c is not None and a>0 and abs(a+b-c)<1e-9 and abs(b*1000-(f(r[j+2]) or 1e9))<1e-6:
                csvb[a]=c; geser.add(j)
    beda=[(n,csvb[n],bu.get(n)) for n in csvb if bu.get(n) is None or abs(csvb[n]-bu[n])>1e-9]
    lebih=[k for k in bu if k not in csvb]
    print(var,'kolom_nominal_ditemukan_di',sorted(geser),'csv',len(csvb),'json',len(bu),'beda',beda,'json_tidak_ada_di_csv',lebih)
