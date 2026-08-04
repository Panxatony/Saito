#!/usr/bin/env python3
"""Render a mirrored Saito page against two stylesheets and count differing pixels.

The gate for any CSS change whose *output* is allowed to move — the release
purge, a theme edit, a framework swap. Where output must stay identical, `cmp`
on the compiled file is stricter and cheaper; use this when it cannot be.

The page HTML comes from the test forum, so the markup is real — the same
classes, the same nesting, the same content. Only the theme stylesheet is
swapped. Everything else (fonts, images, the JS bundle) is loaded from the test
host through a <base> tag, so it is identical in both runs by construction.

Saito serves a headless browser `ReadPostingsDummy`, so the fetch uses a normal
user-agent; otherwise every posting comes back marked read and the two runs
would differ for a reason that has nothing to do with CSS.
"""
import http.server, os, socketserver, subprocess, sys, threading, re, pathlib

HOST = os.environ.get('PXHOST', 'https://forum.panxatony.net')
UA = 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/128.0'
# Everything this writes — screenshots, browser profiles, the mirrored page, a
# cookie jar — goes to a scratch directory, never next to the script. Writing it
# beside the source once put 491 files and 15.5 MB into a commit, including a
# session cookie for the forum it had just fetched.
ROOT = pathlib.Path(os.environ.get('TMPDIR', '/tmp')) / 'saito-pixel-diff'
ROOT.mkdir(parents=True, exist_ok=True)


def fetch(path: str, out: pathlib.Path, port: int) -> None:
    html = subprocess.run(
        ['curl', '-sL', '-H', f'User-Agent: {UA}', '-b', str(ROOT / 'cookies'),
         '-c', str(ROOT / 'cookies'), f'{HOST}{path}'],
        capture_output=True, text=True, check=True).stdout
    # every relative asset keeps resolving against the test host …
    html = html.replace('<head>', f'<head><base href="{HOST}/">', 1)
    # The theme stylesheet is injected at runtime by boot.bundle.js, reading the
    # reader's choice out of localStorage; the <link> in the markup sits inside
    # <noscript>. So the scripts come out and the <noscript> is unwrapped, which
    # makes that link real and swappable. The island markup is rendered by the
    # server — the bundle only enhances it — so nothing being compared is lost.
    html = re.sub(r'<script\b[^>]*>.*?</script\s*>', '', html, flags=re.S | re.I)
    html = re.sub(r'</?noscript>', '', html, flags=re.I)
    # … and now the one thing under test can be substituted.
    # Absolute, because the <base> above would otherwise resolve a root-relative
    # path against the test host — which silently 404s and renders the page with
    # no theme at all. Both runs then look identical, and the harness reports
    # zero differences while testing nothing.
    target = r'static\.css' if os.environ.get('PXSTATIC') else r'theme\.css'
    html = re.sub(r'<link[^>]+href="[^"]*' + target + r'[^"]*"[^>]*>',
                  f'<link rel="stylesheet" href="http://127.0.0.1:{port}/THEME.css">', html)
    out.write_text(html)


def serve(directory: pathlib.Path, port: int):
    handler = lambda *a, **k: http.server.SimpleHTTPRequestHandler(
        *a, directory=str(directory), **k)
    socketserver.TCPServer.allow_reuse_address = True
    httpd = socketserver.TCPServer(('127.0.0.1', port), handler)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    return httpd


def shot(url: str, out: pathlib.Path, width: int, profile: pathlib.Path) -> None:
    subprocess.run([
        'chromium', '--headless', '--disable-gpu', '--hide-scrollbars',
        '--force-device-scale-factor=1', '--virtual-time-budget=6000',
        f'--user-data-dir={profile}', f'--window-size={width},2400',
        f'--screenshot={out}', url,
    ], capture_output=True)


def compare(a: pathlib.Path, b: pathlib.Path) -> tuple:
    from PIL import Image, ImageChops
    ia, ib = Image.open(a).convert('RGB'), Image.open(b).convert('RGB')
    if ia.size != ib.size:
        return (-1, ia.size, ib.size)
    diff = ImageChops.difference(ia, ib)
    n = sum(1 for px in diff.getdata() if px != (0, 0, 0))
    return (n, ia.size, ib.size)


if __name__ == '__main__':
    page, old_css, new_css, label = sys.argv[1:5]
    width = int(sys.argv[5]) if len(sys.argv) > 5 else 1280
    work = ROOT / 'work'
    work.mkdir(exist_ok=True)
    port = int(os.environ.get("PXPORT", "8899"))
    fetch(page, work / 'page.html', port)
    httpd = serve(work, port)
    results = []
    for tag, css in (('old', old_css), ('new', new_css)):
        (work / 'THEME.css').write_bytes(pathlib.Path(css).read_bytes())
        shot(f'http://127.0.0.1:{port}/page.html', ROOT / f'{label}-{tag}.png',
             width, ROOT / f'profile-{tag}')
        results.append(ROOT / f'{label}-{tag}.png')
    httpd.shutdown()
    n, sa, sb = compare(*results)
    if n < 0:
        print(f'  {label}: GRÖSSE UNTERSCHIEDLICH {sa} vs {sb}')
    else:
        total = sa[0] * sa[1]
        print(f'  {label}: {n} von {total} Pixeln abweichend ({100*n/total:.4f}%)  {sa[0]}x{sa[1]}')
