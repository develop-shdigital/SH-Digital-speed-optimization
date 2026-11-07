"""Copy the live site's stylesheets (and optionally fonts) that saved pages use into the local
WordPress, at the same paths, so the plugin can read them when it optimizes those pages
(joining stylesheets, finding background images, font metrics).

    python3 -I mirror-assets.py <site> <saved-pages-dir> <wordpress-folder> <uploads-mirror> [--fonts]

    site            live origin, for example https://www.example.ch
    saved-pages-dir folder with the live pages saved as .html (curl)
    uploads-mirror  folder that MIRROR_UPLOADS points to (files under /wp-content/uploads/ go there)

Only static files of the site itself are fetched (GET, like a browser loading the page).
"""
import os, re, subprocess, sys, urllib.parse

UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'


def fetch(url, dest):
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    r = subprocess.run(['curl', '-sSL', '--fail', '-A', UA, '--max-time', '60', '-o', dest, url], capture_output=True)
    return r.returncode == 0


def main():
    if len(sys.argv) < 5:
        print(__doc__)
        sys.exit(1)
    site, pages, wp, uploads = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3], sys.argv[4]
    fonts = '--fonts' in sys.argv
    host = urllib.parse.urlsplit(site).netloc
    urls = set()
    for name in os.listdir(pages):
        if not name.endswith('.html'):
            continue
        html = open(os.path.join(pages, name), encoding='utf-8', errors='replace').read()
        for tag in re.findall(r'<link\b[^>]*>', html, re.I):
            if re.search(r'rel=["\']?stylesheet', tag, re.I):
                m = re.search(r'href=["\']([^"\']+)', tag)
                if m:
                    urls.add(urllib.parse.urljoin(site + '/', m.group(1).replace('&#038;', '&').replace('&amp;', '&')))
    done = failed = 0
    css_files = []
    for url in sorted(urls):
        p = urllib.parse.urlsplit(url)
        if p.netloc != host or not p.path.endswith('.css'):
            continue
        path = urllib.parse.unquote(p.path)
        dest = os.path.join(uploads, path[len('/wp-content/uploads/'):]) if path.startswith('/wp-content/uploads/') else os.path.join(wp, path.lstrip('/'))
        if fetch(url.split('?')[0] + ('?' + p.query if p.query else ''), dest):
            done += 1
            css_files.append((url, dest))
        else:
            failed += 1
            print('failed:', url)
    if fonts:
        for url, dest in css_files:
            css = open(dest, encoding='utf-8', errors='replace').read()
            for ref in re.findall(r'url\(\s*["\']?([^"\')]+\.(?:woff2?|ttf|otf))', css, re.I):
                furl = urllib.parse.urljoin(url, ref)
                fp = urllib.parse.urlsplit(furl)
                if fp.netloc != host:
                    continue
                fpath = urllib.parse.unquote(fp.path)
                fdest = os.path.join(uploads, fpath[len('/wp-content/uploads/'):]) if fpath.startswith('/wp-content/uploads/') else os.path.join(wp, fpath.lstrip('/'))
                if not os.path.exists(fdest) and fetch(furl, fdest):
                    done += 1
    print(f'{done} file(s) copied, {failed} failed')


if __name__ == '__main__':
    main()
