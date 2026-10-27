#!/usr/bin/env python3
"""Offline analysis of the asustor-control behaviour log (docs/hwlog-format.md).

Answers: does the CPU ever approach throttling; which drives pull the fan and
how fast do they recover; what PWM the system idles at and for how long.
"""
import csv, sys, statistics as st, datetime as dt, collections

WARN_HDD, WARN_SSD, CPU_CRIT = 47, 60, 105   # Unraid defaults; override with -w/-s/-c

def parse_pairs(s):
    out = {}
    if not s:              # torn/short row: csv.DictReader fills missing fields with None
        return out
    for kv in s.split(';'):
        if '=' in kv:
            k, v = kv.split('=', 1)
            out[k] = int(v) if v.isdigit() else None
    return out

def load(paths):
    rows = []
    for p in paths:
        with open(p) as f:
            for r in csv.DictReader(f):
                try:
                    rows.append({
                        'ts': int(r['ts']), 'pwm': int(r['pwm'] or 0), 'rpm': int(r['rpm'] or 0),
                        'pkg': int(r['pkg_c']) if r['pkg_c'] else None,
                        'core': int(r['core_max_c']) if r['core_max_c'] else None,
                        'khz': int(r['cpu_khz']) if r['cpu_khz'] else None,
                        'thr': int(r['throttle_pkg']) if r['throttle_pkg'] else None,
                        'fcp': r['fcp_temp'], 'src': r['fcp_src'],
                        'nvme': parse_pairs(r['nvme']), 'disks': parse_pairs(r['disks']),
                    })
                except (KeyError, ValueError, TypeError, AttributeError) as e:
                    print(f'skip malformed row in {p}: {e}', file=sys.stderr)
    rows.sort(key=lambda r: r['ts'])
    return rows

def fmt(ts): return dt.datetime.fromtimestamp(ts).strftime('%Y-%m-%d %H:%M:%S')

def pct(v, q): v = sorted(v); return v[min(len(v)-1, int(len(v)*q))]

def main(paths, warn_hdd=WARN_HDD, warn_ssd=WARN_SSD, cpu_crit=CPU_CRIT):
    rows = load(paths)
    if not rows: sys.exit('no rows')
    n = len(rows); span = rows[-1]['ts'] - rows[0]['ts']
    print(f'{n} samples, {fmt(rows[0]["ts"])} .. {fmt(rows[-1]["ts"])} ({span/3600:.1f} h)')
    gaps = [b['ts']-a['ts'] for a, b in zip(rows, rows[1:])]
    print(f'sample interval median {st.median(gaps):.0f} s, gaps > 60 s: {sum(g > 60 for g in gaps)}')

    print('\n== CPU vs throttling ==')
    pk = [r['pkg'] for r in rows if r['pkg'] is not None]
    if pk:
        print(f'package temp: median {st.median(pk):.0f}, p99 {pct(pk, .99)}, max {max(pk)} (crit {cpu_crit}, margin at max {cpu_crit-max(pk)})')
    th = [r['thr'] for r in rows if r['thr'] is not None]
    if th:
        events = [(rows[i]['ts'], rows[i]['thr']-rows[i-1]['thr']) for i in range(1, n)
                  if rows[i]['thr'] is not None and rows[i-1]['thr'] is not None and rows[i]['thr'] > rows[i-1]['thr']]
        print(f'throttle counter: {th[0]} -> {th[-1]}, events in window: {len(events)}')
        for ts, d in events[:20]: print(f'  {fmt(ts)} +{d}')
    kz = [r['khz'] for r in rows if r['khz']]
    if kz:
        print(f'cpu clock: median {st.median(kz)/1000:.0f} MHz, p1 {pct(kz, .01)/1000:.0f} MHz, min {min(kz)/1000:.0f} MHz')

    print('\n== Fan ==')
    pw = [r['pwm'] for r in rows]
    floor = min(pw)
    print(f'pwm: min {floor}, median {st.median(pw):.0f}, p90 {pct(pw, .9)}, max {max(pw)}; at floor {100*sum(p == floor for p in pw)/n:.0f}% of the time')
    src = collections.Counter(r['src'] for r in rows)
    print('controller source share:', ', '.join(f'{k or "?"} {100*v/n:.0f}%' for k, v in src.most_common()))
    rp = [r['rpm'] for r in rows if r['rpm']]
    if rp: print(f'rpm: min {min(rp)}, median {st.median(rp):.0f}, max {max(rp)}')

    print('\n== Drives ==')
    names = sorted({k for r in rows for k in list(r['disks']) + list(r['nvme'])})
    for name in names:
        vals = [(r['disks'].get(name) if name in r['disks'] else r['nvme'].get(name)) for r in rows]
        known = [v for v in vals if v is not None]
        if not known: continue
        is_ssd = name.startswith('nvme') or name.startswith('cache')
        warn = warn_ssd if is_ssd else warn_hdd
        above = sum(v >= warn for v in known)
        unknown = len(vals) - len(known)
        print(f'{name:8} median {st.median(known):.0f} p99 {pct(known, .99)} max {max(known)} | >= warn {warn}: {100*above/len(vals):.1f}% | unknown/spun down {100*unknown/len(vals):.0f}%')

    print('\n== Fan ramp episodes (pwm rises >= 20 above the floor) ==')
    ep = []; start = None
    for i, r in enumerate(rows):
        hot = r['pwm'] >= floor + 20
        if hot and start is None: start = i
        if not hot and start is not None: ep.append((start, i)); start = None
    if start is not None: ep.append((start, n-1))
    for a, b in ep[:30]:
        seg = rows[a:b+1]
        peak = max(seg, key=lambda r: r['pwm'])
        hottest = {}
        for r in seg:
            for k, v in list(r['disks'].items()) + list(r['nvme'].items()):
                if v is not None and v > hottest.get(k, -1): hottest[k] = v
        top = sorted(hottest.items(), key=lambda kv: -kv[1])[:2]
        print(f'  {fmt(rows[a]["ts"])} for {(rows[b]["ts"]-rows[a]["ts"])/60:.0f} min: peak pwm {peak["pwm"]} src {peak["src"] or "?"} temp {peak["fcp"]}; hottest {top}; pkg max {max((r["pkg"] or 0) for r in seg)}')
    if not ep: print('  none')

def print_events(paths):
    lines = []
    for p in paths:
        with open(p) as f:
            for l in f:
                l = l.rstrip('\n')
                if not l: continue
                ts, _, rest = l.partition(' ')
                lines.append((int(ts) if ts.isdigit() else 0, rest))
    lines.sort()
    print(f'\n== PWM change events ({len(lines)}) ==')
    for ts, rest in lines: print(f'  {fmt(ts)}  {rest}')

if __name__ == '__main__':
    args = [a for a in sys.argv[1:] if not a.startswith('-')]
    events = [a for a in args if a.endswith('.log')]
    args = [a for a in args if not a.endswith('.log')]
    if events and not args:
        print_events(events); sys.exit(0)
    if events:
        import atexit; atexit.register(print_events, events)
    if not args: sys.exit(__doc__ + '\nusage: hwlog-report.py hwlog-*.csv')
    main(args)
