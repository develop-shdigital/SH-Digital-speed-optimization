"""Download PageSpeed Insights results and save each Lighthouse report (LHR) as JSON.

Two ways, both without running Lighthouse yourself:

  reports: stored report links (https://pagespeed.web.dev/analysis/...). The report page
           embeds the mobile and the desktop result; no API key and no quota needed.
      python3 -I fetch.py reports <out-dir> <report-url> [<report-url> ...]

  api:     the PageSpeed Insights API for page addresses, mobile and desktop. Needs an API
           key in PSI_API_KEY (without one, Google allows only a few requests per day: 429).
      PSI_API_KEY=... python3 -I fetch.py api <out-dir> <page-url> [<page-url> ...]

Files: <out-dir>/<page-slug>-mobile.json and -desktop.json (read them with summary.py).
Downloads use curl, which is set up for the session's proxy.
"""
import json, os, re, subprocess, sys, urllib.parse

UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'


def curl(url):
    out = subprocess.run(['curl', '-sS', '--fail', '-A', UA, '--max-time', '180', url], capture_output=True)
    if out.returncode != 0:
        raise RuntimeError(f'{url}: {out.stderr.decode(errors="replace").strip()}')
    return out.stdout.decode('utf-8', errors='replace')


def slug(page_url):
    p = urllib.parse.urlsplit(page_url)
    path = p.path.strip('/').replace('/', '_')
    return path or 'home'


def read_js_string(s, i):
    """The JavaScript string literal starting at s[i] (a double quote), quotes included."""
    j = i + 1
    while True:
        c = s[j]
        if c == '\\':
            j += 2
            continue
        if c == '"':
            return s[i:j + 1]
        j += 1


def reports_in(html):
    """Every Lighthouse report embedded in a stored PageSpeed Insights page."""
    for m in re.finditer(r'"\{\\"fetchTime\\"', html):
        lit = read_js_string(html, m.start())
        lit = re.sub(r'(?<!\\)((?:\\\\)*)\\x([0-9a-fA-F]{2})', lambda g: g.group(1) + '\\u00' + g.group(2), lit)
        yield json.loads(json.loads(lit))


def save(out_dir, lhr):
    ff = lhr.get('configSettings', {}).get('formFactor', 'unknown')
    name = f"{slug(lhr.get('finalDisplayedUrl') or lhr.get('finalUrl') or lhr['requestedUrl'])}-{ff}.json"
    with open(os.path.join(out_dir, name), 'w', encoding='utf-8') as f:
        json.dump(lhr, f)
    score = lhr['categories']['performance']['score']
    print(f"{name:45s} performance {round(score * 100) if score is not None else '?'}  ({lhr.get('fetchTime', '')})")


def main():
    if len(sys.argv) < 4 or sys.argv[1] not in ('reports', 'api'):
        print(__doc__)
        sys.exit(1)
    mode, out_dir, targets = sys.argv[1], sys.argv[2], sys.argv[3:]
    os.makedirs(out_dir, exist_ok=True)
    for target in targets:
        if mode == 'reports':
            found = 0
            for lhr in reports_in(curl(target)):
                save(out_dir, lhr)
                found += 1
            if not found:
                print(f'{target}: no report found (expired link, or the page layout changed)')
            continue
        key = os.environ.get('PSI_API_KEY', '')
        for strategy in ('mobile', 'desktop'):
            q = urllib.parse.urlencode([('url', target), ('strategy', strategy), ('category', 'performance'), ('category', 'accessibility'), ('category', 'best-practices'), ('category', 'seo')] + ([('key', key)] if key else []))
            data = json.loads(curl('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' + q))
            save(out_dir, data['lighthouseResult'])


if __name__ == '__main__':
    main()
