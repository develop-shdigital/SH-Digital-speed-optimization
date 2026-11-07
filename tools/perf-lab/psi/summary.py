"""Per page: score, the five metrics with their scores, points lost per metric, failing audits.

    python3 -I summary.py <dir-with-lhr-json> [mobile|desktop]

Points lost = weight x (1 - metric score); the weights (Lighthouse 10+): TBT 30, LCP 25,
CLS 25, FCP 10, Speed Index 10. Fix the biggest loss first.
"""
import glob, json, os, sys

M = ['first-contentful-paint', 'largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index']
d = sys.argv[1]
ff = sys.argv[2] if len(sys.argv) > 2 else 'mobile'
for f in sorted(glob.glob(os.path.join(d, f'*-{ff}.json'))):
    lhr = json.load(open(f, encoding='utf-8'))
    a = lhr['audits']
    perf = lhr['categories']['performance']
    print(f"\n=== {os.path.basename(f)[:-len(ff) - 6]}  {ff} {round((perf['score'] or 0) * 100)}")
    print('   ' + ' | '.join(f"{m.split('-')[0][:5]} {a[m].get('displayValue', '')} ({round((a[m]['score'] or 0) * 100)})" for m in M))
    loss = []
    for ref in perf['auditRefs']:
        if ref.get('weight'):
            s = a[ref['id']]['score'] or 0
            loss.append((round(ref['weight'] * (1 - s), 1), ref['id']))
    print('   points lost:', ', '.join(f"{i.split('-')[0]}:{w}" for w, i in sorted(loss, reverse=True) if w > 0) or 'none')
    fails = []
    for ref in perf['auditRefs']:
        au = a.get(ref['id'], {})
        if ref.get('weight'):
            continue
        sc = au.get('score')
        if au.get('scoreDisplayMode') in ('notApplicable', 'manual', 'informative') and not au.get('displayValue'):
            continue
        if sc is not None and sc >= 0.9:
            continue
        fails.append(f"{ref['id']}[{au.get('displayValue', '')}]")
    print('   failing:', '; '.join(fails) or 'none')
