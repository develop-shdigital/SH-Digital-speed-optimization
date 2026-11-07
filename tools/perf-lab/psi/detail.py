"""Details of chosen audits for every page: the items behind a finding.

    python3 -I detail.py <dir-with-lhr-json> <mobile|desktop> <audit-id> [<audit-id> ...]

Useful ids: largest-contentful-paint-element, lcp-breakdown-insight, render-blocking-insight,
bootup-time, long-tasks, third-party-summary, unused-javascript, network-requests,
image-delivery-insight, font-display-insight, layout-shifts, cls-culprits-insight, heading-order.
"""
import glob, json, os, sys


def short(v):
    if isinstance(v, dict):
        return v.get('snippet') or v.get('selector') or v.get('url') or v.get('nodeLabel') or v.get('text') or str(v)[:120]
    return v


d, ff, which = sys.argv[1], sys.argv[2], sys.argv[3:]
for f in sorted(glob.glob(os.path.join(d, f'*-{ff}.json'))):
    a = json.load(open(f, encoding='utf-8'))['audits']
    print(f"\n=== {os.path.basename(f)}")
    for aid in which:
        au = a.get(aid)
        if not au:
            print(f'-- {aid}: not in this report')
            continue
        print(f"-- {aid}: {au.get('displayValue', '')}  score={au.get('score')}")
        det = au.get('details') or {}
        groups = det.get('items', []) if det.get('type') == 'list' else [det]
        for g in groups:
            if not isinstance(g, dict):
                continue
            if g.get('type') == 'node':
                print('    node:', g.get('selector'), '|', (g.get('snippet') or '')[:200])
                continue
            for it in (g.get('items') or [])[:10]:
                if isinstance(it, dict):
                    print('   ', {k: short(v) for k, v in it.items() if k != 'subItems'})
