#!/usr/bin/env python3
"""Base pays compacte pour la mesure d'audience (api/geo/*.bin).

Source : DB-IP « IP to Country Lite » (CC BY 4.0, https://db-ip.com), mise à jour chaque mois.
Usage : python3 -I scripts/build_geo.py chemin/vers/dbip-country-lite-AAAA-MM.csv.gz
Format : enregistrements triés [début de plage][code pays 2 lettres],
  IPv4 = 4 + 2 octets, IPv6 = 8 premiers octets + 2 octets (suffisant au niveau pays).
Les plages consécutives d'un même pays sont fusionnées.
"""
import gzip, ipaddress, sys
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / 'api' / 'geo'


def build(src):
    v4, v6 = [], []
    with gzip.open(src, 'rt', encoding='ascii', errors='replace') as f:
        for line in f:
            parts = line.strip().split(',')
            if len(parts) != 3:
                continue
            a, b, cc = parts
            cc = cc.strip().upper()
            if len(cc) != 2 or not cc.isalpha():
                continue
            try:
                start = ipaddress.ip_address(a)
            except ValueError:
                continue
            if start.version == 4:
                v4.append((int(start), cc))
            else:
                v6.append((int(start) >> 64, cc))
    for name, rows, size in (('v4', v4, 4), ('v6', v6, 8)):
        rows.sort()
        out, last = bytearray(), None
        n = 0
        for start, cc in rows:
            if cc == last:
                continue
            out += start.to_bytes(size, 'big') + cc.encode('ascii')
            last = cc
            n += 1
        (OUT / f'{name}.bin').write_bytes(bytes(out))
        print(f'{name}: {n} plages, {len(out) // 1024} Ko')


if __name__ == '__main__':
    OUT.mkdir(parents=True, exist_ok=True)
    build(sys.argv[1])
