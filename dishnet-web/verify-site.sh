#!/usr/bin/env bash
# Serve site/ with the real nginx.conf and check it end to end.
#
# Worth re-running after the localisation pass: that rewrites hundreds of
# links, and a broken one is invisible in production because the Netlify
# catch-all serves the homepage with a 200 instead of a 404.
#
#   ./verify-site.sh          needs nginx on PATH; touches nothing outside $TMP
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
TMP=${TMPDIR:-/tmp}/dishnet-verify.$$
PORT=${PORT:-8099}
B=http://127.0.0.1:$PORT
command -v nginx >/dev/null || { echo "nginx not on PATH"; exit 2; }
mkdir -p "$TMP"/{logs,client,tmp}
trap 'nginx -s stop -c "$TMP/nginx.conf" 2>/dev/null; rm -rf "$TMP"' EXIT

sed -e "s|root         /usr/share/nginx/html;|root         $HERE/site;|" \
    -e "s|listen  *[0-9]*;|listen       $PORT;|" "$HERE/nginx.conf" > "$TMP/server.conf"
cat > "$TMP/nginx.conf" <<EOF
user $(id -un);
worker_processes 1;
error_log $TMP/logs/error.log warn;
pid $TMP/nginx.pid;
events { worker_connections 64; }
http {
  include /etc/nginx/mime.types;
  default_type application/octet-stream;
  access_log off;
  client_body_temp_path $TMP/client;
  proxy_temp_path $TMP/tmp; fastcgi_temp_path $TMP/tmp/f;
  uwsgi_temp_path $TMP/tmp/u; scgi_temp_path $TMP/tmp/s;
  include $TMP/server.conf;
}
EOF
nginx -t -c "$TMP/nginx.conf" >/dev/null 2>&1 || { nginx -t -c "$TMP/nginx.conf"; exit 1; }
nginx -c "$TMP/nginx.conf" || exit 1
sleep 1
fail=0

echo "== _redirects (Netlify rules reproduced in nginx) =="
while read -r from to _; do
  case "$from" in ''|'#'*|'/*') continue;; esac
  # Read the raw Location header, not curl's resolved target. Stripping the
  # base URL off a resolved target hides an absolute Location -- and an
  # absolute one is a production bug: nginx builds it from the scheme and port
  # IT sees (http, 8080) rather than the ones the visitor used, so the redirect
  # points somewhere unreachable behind a TLS-terminating proxy.
  loc=$(curl -sI "$B$from" | tr -d '\r' | awk 'tolower($1)=="location:"{print $2}')
  case "$loc" in
    /*) : ;;
    '') echo "  $from -> no Location header"; fail=1; continue ;;
     *) echo "  $from -> $loc  (absolute Location; must be relative)"; fail=1; continue ;;
  esac
  if [ "$loc" = "$to" ]; then
    tgt=$(curl -s -o /dev/null -w '%{http_code}' "$B${to%%#*}")
    [ "$tgt" = 200 ] || { echo "  $from -> $to but target is HTTP $tgt"; fail=1; }
  else
    echo "  $from -> $loc  (expected $to)"; fail=1
  fi
done < "$HERE/site/_redirects"
[ $fail -eq 0 ] && echo "  all rules fire, all targets resolve"

echo "== headers =="
for p in / /faq /styles.css; do
  n=$(curl -sI "$B$p" | grep -icE 'x-content-type-options|x-frame-options|referrer-policy')
  c=$(curl -sI "$B$p" | grep -ic '^cache-control')
  # nginx drops inherited add_header in any block declaring its own, so check
  # each kind of path rather than trusting the server-level directives.
  [ "$n" = 3 ] || { echo "  $p: only $n/3 security headers"; fail=1; }
  [ "$c" = 1 ] || { echo "  $p: $c Cache-Control headers (want exactly 1)"; fail=1; }
done
[ $fail -eq 0 ] && echo "  3/3 security headers and exactly one Cache-Control everywhere"

echo "== pages =="
bad=0
while IFS= read -r f; do
  case "$f" in */404.html) continue;; esac   # asserted separately below
  c=$(curl -s -o /dev/null -w '%{http_code}' "$B/${f#site/}")
  [ "$c" = 200 ] || { echo "  HTTP $c  /${f#site/}"; bad=$((bad+1)); }
done < <(find "$HERE/site" -name '*.html' | sed "s|$HERE/||" | sort)
echo "  $(find "$HERE/site" -name '*.html' | wc -l) pages, $bad non-200"
[ $bad -gt 0 ] && fail=1

echo "== internal links and assets =="
HOME_SZ=$(curl -s "$B/index.html" | wc -c)
python3 - "$HERE" <<'PY' > "$TMP/refs"
import re,os,glob,sys
root=sys.argv[1]; pat=re.compile(r'(?:src|href)="([^"]+)"')
skip=re.compile(r'^(https?:|//|mailto:|tel:|javascript:|#|data:)'); seen=set()
for f in glob.glob(os.path.join(root,'site','**','*.html'),recursive=True):
    d=os.path.dirname(f)
    for m in pat.findall(open(f,encoding='utf-8',errors='ignore').read()):
        r=m.split('?')[0].split('#')[0]
        if not r or skip.match(r): continue
        u=r if r.startswith('/') else '/'+os.path.relpath(os.path.normpath(os.path.join(d,r)),os.path.join(root,'site'))
        if u.endswith('/'): u += 'index.html'   # a directory link means its index
        elif not u.rsplit('/',1)[-1].count('.'): u += '/index.html'
        if u not in seen: seen.add(u); print(u)
PY
bad=0
while IFS= read -r u; do
  c=$(curl -s -o /dev/null -w '%{http_code}' "$B$u")
  if [ "$c" != 200 ]; then echo "  HTTP $c  $u"; bad=$((bad+1)); continue; fi
  case "$u" in *.html)
    [ "$u" = /index.html ] && continue
    # the catch-all hides missing pages behind a 200 homepage
    [ "$(curl -s "$B$u" | wc -c)" = "$HOME_SZ" ] && { echo "  MASKED 404  $u"; bad=$((bad+1)); };;
  esac
done < "$TMP/refs"
echo "  $(wc -l < "$TMP/refs") references, $bad broken"
[ $bad -gt 0 ] && fail=1

echo "== 404 behaviour =="
c=$(curl -s -o /dev/null -w '%{http_code}' "$B/this-page-does-not-exist")
b=$(curl -s "$B/this-page-does-not-exist" | grep -c "That page isn")
if [ "$c" = 404 ] && [ "$b" -ge 1 ]; then
  echo "  unknown URLs return a real 404 with the branded page"
else
  echo "  FAIL: unknown URL returned $c (branded content: $b)"; fail=1
fi
d=$(curl -s -o /dev/null -w '%{http_code}' "$B/404.html")
[ "$d" = 404 ] && echo "  /404.html not directly reachable (internal)" || { echo "  FAIL: /404.html directly returned $d"; fail=1; }

echo "== seo =="
# A canonical pointing at another domain tells Google not to index this site at
# all, which is how the South Sudan copy arrived. Cheap to check, fatal to miss.
foreign=$(grep -rho 'rel="canonical" href="https\?://[^/"]*' "$HERE/site" --include='*.html' \
  | grep -v '/demo/' | sed 's|.*//||' | sort -u | grep -v '^dishnetsudan\.com$' || true)
[ -n "$foreign" ] && { echo "  canonical points off-domain: $foreign"; fail=1; } \
                  || echo "  all canonicals on dishnetsudan.com"
grep -q 'dishnetsudan.com/sitemap.xml' "$HERE/site/robots.txt" \
  || { echo "  robots.txt sitemap line wrong"; fail=1; }
python3 -c "import xml.dom.minidom,sys;xml.dom.minidom.parse('$HERE/site/sitemap.xml')" 2>/dev/null \
  || { echo "  sitemap.xml is not well-formed"; fail=1; }
# Claims that are true of South Sudan and false of Sudan. Anchored so the
# protected history ("South Sudan's First FTTH") is not matched as a substring.
python3 - "$HERE/site" <<'PYCHK' || fail=1
import sys,re,glob,os
root=sys.argv[1]
BAD=[r"(?<!South )Sudan's First FTTH", r"(?<!South )Sudan's first Fiber",
     r"Juba,\s*Sudan\b", r"offices in 7 cities", r"Juba warehouse"]
bad=0
for f in glob.glob(os.path.join(root,'**','*.html'),recursive=True):
    if '/demo/' in f: continue
    t=open(f,encoding='utf-8',errors='ignore').read()
    for b in BAD:
        if re.search(b,t):
            print(f"  false-for-Sudan claim {b!r} in {os.path.relpath(f,root)}"); bad=1
sys.exit(bad)
PYCHK
# An absolute image URL on our own domain means the domain rename rewrote a
# path that only exists in the South Sudan CMS. It 404s in production and the
# local-link crawl above cannot see it, because it only checks relative refs.
ext=$(grep -rloE '(src|content)="https://(dishnetafrica\.com|portal\.dishnetss\.com)[^"]*"' "$HERE/site" --include='*.html' \
      | grep -vE 'gallery|testimonials|fiber|pay\.|hotspot|security|reseller|blog-starlink-south|404' | head -3 || true)
[ -n "$ext" ] && { echo "  remote South Sudan image host referenced: $ext"; fail=1; } \
              || echo "  no South Sudan image hosts referenced anywhere"
selfabs=$(grep -rhoE 'src="https://dishnetsudan\.com/[^"]+"' "$HERE/site" --include='*.html' | sort -u || true)
[ -n "$selfabs" ] && { echo "  absolute self-URL will 404: $selfabs"; fail=1; }
[ $fail -eq 0 ] && echo "  no South-Sudan-only claims, sitemap well-formed, no self-404 images"

echo "== content integrity =="
# A 30-agent audit on 25 Aug found 133 confirmed content errors that every
# check above passed clean: false market claims, products not sold in Sudan,
# invented lead times and payment methods, a Ugandan flag stripe on 54 pages.
# Each class it found is now a rule, because a checker that only knows the
# last bug is worth very little.
python3 - "$HERE/site" <<'PYCONTENT' || fail=1
import sys, re, glob, os
root = sys.argv[1]
HELD = {'fiber.html','testimonials.html','gallery.html','blog-starlink-south-sudan.html',
        'pay.html','hotspot.html','security.html','reseller.html','404.html'}
bad = 0
def err(rel, msg):
    global bad; bad = 1; print(f'  {rel}: {msg}')

# Claims with nothing behind them. Each string was live on the site.
CLAIMS = [
    (r'leading ISP', 'market-position claim'),
    (r'official reseller', 'undocumented reseller status'),
    (r'authorized reseller', 'undocumented reseller status'),
    (r'Official Reseller', 'undocumented reseller status'),
    (r'most trusted', 'unverifiable superlative'),
    (r'(?:since|in) 2013[^<]{0,40}Sudan|Sudan[^<]{0,40}since 2013', 'years-of-operation-in-Sudan claim'),
    (r'Connecting Sudan since', 'years-of-operation-in-Sudan claim'),
    (r'all major cities', 'blanket coverage claim'),
    (r'every corner of Sudan', 'blanket coverage claim'),
    (r'\d+\+? towns', 'invented service footprint'),
    (r'99\.9\s*%', 'uptime guarantee'),
    (r'[Uu]ptime SLA', 'uptime guarantee'),
    (r'Head Office</span>\s*<strong>Sudan', 'physical office in Sudan'),
    (r'in under \d+ minutes', 'invented lead time'),
    (r'in \d+&ndash;\d+ minutes', 'invented lead time'),
    (r'within \d+ hours', 'invented lead time'),
    (r'\bmobile money\b', 'invented payment method'),
    (r'\bMTN\b|\bAirtel\b', 'payment rail not available in Sudan'),
    (r'[Ff]inancing options', 'invented credit offering'),
    (r'merchant codes', 'invented payment method'),
    (r'Google Analytics', 'analytics claim with no analytics installed'),
    (r'220\\s*Mbps', 'speed figure in no Starlink datasheet'),
    (r'\\d+\\s*&ndash;\\s*\\d+\\s*ms|\\d+\\s*–\\s*\\d+\\s*ms', 'specific latency range claim'),
]

# Fibre and LTE may be COMPARED to, never OFFERED. These are offer-shaped.
OFFERS = [
    (r'fiber (?:plan|plans|connection|service|services|broadband|internet|link)', 'fibre offered as a product'),
    (r'[Pp]rivate LTE', 'LTE offered as a product'),
    (r'LTE / 4G|LTE/4G', 'LTE offered as a product'),
    (r'VSAT [Ss]atellite [Ii]nternet', 'VSAT offered as a product'),
    (r'Managed IT Services', 'IT services offered as a product'),
    (r'Structured Cabling', 'cabling offered as a product'),
    (r'Fiber Connection</option>', 'fibre in an enquiry form'),
    (r'Mbps Fiber', 'fibre speed as a service metric'),
]

for f in glob.glob(os.path.join(root, '**', '*.html'), recursive=True):
    base = os.path.basename(f); rel = os.path.relpath(f, root)
    t = open(f, encoding='utf-8', errors='ignore').read()

    # The Uganda flag stripe and Ugandan locale, on every page including held.
    if 'ug-strip' in t: err(rel, 'Uganda flag stripe (.ug-strip) present')
    if 'en_UG' in t: err(rel, 'og:locale is en_UG on a Sudan site')

    if base in HELD:
        continue   # held pages are noindexed; their content is not published

    for pat, why in CLAIMS + OFFERS:
        m = re.search(pat, t)
        if m: err(rel, f'{why} -> "{m.group(0)[:52]}"')

    # An indexed page must not link to a held page.
    for h in HELD - {'404.html'}:
        if f'href="{h}"' in t:
            err(rel, f'links held page {h}')

sys.exit(bad)
PYCONTENT

# Every class used in the HTML must be defined somewhere. .btn-ghost was used
# on 50 pages and defined nowhere, so a call-to-action rendered as bare text.
python3 - "$HERE/site" <<'PYCSS' || fail=1
import sys, re, glob, os
root = sys.argv[1]
css = open(os.path.join(root, 'styles.css'), encoding='utf-8').read()
for f in glob.glob(os.path.join(root, '**', '*.css'), recursive=True):
    css += open(f, encoding='utf-8', errors='ignore').read()
inline = ''
used = {}
for f in glob.glob(os.path.join(root, '**', '*.html'), recursive=True):
    t = open(f, encoding='utf-8', errors='ignore').read()
    for st in re.findall(r'<style[^>]*>(.*?)</style>', t, re.S):
        inline += st
    for attr in re.findall(r'class="([^"]+)"', t):
        for c in attr.split():
            used.setdefault(c, os.path.relpath(f, root))
defined = set(re.findall(r'\.([A-Za-z][\w-]*)', css + inline))
UTIL = re.compile(r'^(fade|delay|d\d|col|row|is-|js-|has-|active|open|hidden|show)')
missing = {c: f for c, f in used.items()
           if c not in defined and not UTIL.match(c) and len(c) > 3}
if missing:
    for c, f in sorted(missing.items())[:8]:
        print(f'  class .{c} used ({f}) but defined in no stylesheet')
    sys.exit(1)
sys.exit(0)
PYCSS
[ $fail -eq 0 ] && echo "  no unsupportable claims, no unsold products, no undefined classes"

python3 - "$HERE/site" <<'PYIMG' || fail=1
# The site once pulled its product photos from dishnetafrica.com's CMS and lost
# every one of them when that host became unreachable. No <img> may point off
# this site again, and none may point at a file that is not there.
import sys, re, glob, os
root = sys.argv[1]; bad = 0
for f in glob.glob(os.path.join(root, '**', '*.html'), recursive=True):
    rel = os.path.relpath(f, root)
    t = open(f, encoding='utf-8', errors='ignore').read()
    for tag in re.findall(r'<img[^>]*>', t):
        m = re.search(r'src="([^"]+)"', tag)
        if not m:
            print(f'  {rel}: <img> with no src'); bad = 1; continue
        src = m.group(1)
        if re.match(r'https?://|//', src):
            print(f'  {rel}: <img> hotlinks {src[:60]}'); bad = 1; continue
        if src.startswith('data:'):
            continue
        target = os.path.normpath(os.path.join(os.path.dirname(f), src.lstrip('/')))
        if not os.path.exists(target):
            print(f'  {rel}: <img> src not on disk -> {src}'); bad = 1
        if not re.search(r'\salt="', tag):
            print(f'  {rel}: <img> without alt -> {src}'); bad = 1
sys.exit(bad)
PYIMG
[ $fail -eq 0 ] && echo "  every <img> is a local file that exists, with alt text"

python3 - "$HERE/site" <<'PYPRIV' || fail=1
# The chat collects a phone number and an email. A privacy policy that does not
# say so, or that states a retention period the code does not enforce, is worse
# than no policy: it is a promise nobody is keeping.
import sys, re, os
root = sys.argv[1]; bad = 0
p = os.path.join(root, 'privacy.html')
t = open(p, encoding='utf-8').read()
NEED = [
    ('chat assistant',        'the chat assistant is not mentioned at all'),
    ('Anthropic',             'the AI processor is not named'),
    ('OpenAI',                'the second permitted AI processor is not named'),
    ('90 days',               'no retention period stated'),
    ('mailto:',               'no route to request deletion'),
    ('not</strong> included', 'does not say contact details are withheld from the AI provider'),
]
for needle, why in NEED:
    if needle not in t:
        print(f'  privacy.html: {why}'); bad = 1

# The widget must actually link to it, or nobody reads it at the moment it matters.
chat = open(os.path.join(root, 'assets', 'js', 'chat.js'), encoding='utf-8').read()
if 'data-privacy' not in open(os.path.join(root, 'index.html'), encoding='utf-8').read():
    print('  index.html: chat widget is not given the privacy policy URL'); bad = 1
if 'consent' not in chat:
    print('  chat.js: no consent text shown before contact details are submitted'); bad = 1
sys.exit(bad)
PYPRIV
[ $fail -eq 0 ] && echo "  privacy policy covers the chat, names the processor and its retention"

echo "== commercial rules =="
# These are the rules a silent regression would cost money on. All published
# pages, held pages excluded from the branding/price rules where noted.
python3 - "$HERE/site" <<'PYCOM' || fail=1
import sys, re, glob, os, json
root = sys.argv[1]
HELD = {'fiber.html','coverage-old.html','testimonials.html','gallery.html',
        'blog-starlink-south-sudan.html','pay.html','hotspot.html',
        'security.html','reseller.html'}
PRICES = {'112','189','336','483','784'}          # uCRM, the source of truth
NUMBER = '249900083481'                            # the number the AI answers
bad = 0
def err(m):
    global bad; bad = 1; print('  ' + m)

seen_prices = {}
for f in glob.glob(os.path.join(root,'**','*.html'), recursive=True):
    rel = os.path.relpath(f, root); base = os.path.basename(f)
    t = open(f, encoding='utf-8', errors='ignore').read()
    # 1. Every WhatsApp link reaches the AI-answered number.
    for m in re.findall(r'wa\.me/(\d*)', t) + re.findall(r'api\.whatsapp\.com/send/\?phone=\+?(\d+)', t):
        if m != NUMBER:
            err(f'{rel}: WhatsApp link to {m or "<empty>"} (want {NUMBER})')
    # 2a. No login link to the bare CRM root: it redirects to /nms/login,
    #     the UISP admin console. Customers cannot use it, and pointing a
    #     public site at a vendor-branded credential form on a new domain is
    #     what got crm.dishnetsudan.com flagged as deceptive by Chrome.
    #     Uses the loop's own file -- opening a second one here reassigned t
    #     and every check below it silently read the wrong page.
    if re.search(r'https://crm\.dishnetsudan\.com/(?=")', t):
        err(f'{rel}: login link points at the UISP admin console')

    # 2. No login link to the South Sudan plugin path.
    if 'dishnet-hybrid-telecom' in t:
        err(f'{rel}: links the South Sudan plugin URL')
    if base in HELD:
        continue
    # 3. Branding and currency on published pages.
    if 'UGANDA' in t:
        err(f'{rel}: uppercase UGANDA strap')
    if re.search(r'\bSSP\b', t) and not rel.startswith('tutorials/'):
        err(f'{rel}: SSP reference')
    # 4. Plan prices only ever the uCRM five (plan contexts on the money pages).
    MONEY = ('index.html','faq.html','services.html','starlink-price-sudan.html',
             'starlink-plans-sudan.html','starlink-priority-500gb-sudan.html',
             'starlink-priority-1tb-sudan.html','starlink-priority-2tb-sudan.html',
             'starlink-priority-3tb-sudan.html','starlink-priority-5tb-sudan.html',
             'starlink-home-sudan.html','starlink-business-sudan.html',
             'starlink-for-hotels-sudan.html','starlink-rural-sudan.html')
    if base in MONEY:
        for p in re.findall(r'\$([0-9][0-9,]*)(?=\s*(?:<small>)?\s*/mo)', t):
            seen_prices.setdefault(p.replace(',',''), set()).add(base)
for p, where in sorted(seen_prices.items()):
    if p not in PRICES:
        err(f'monthly price ${p} on {sorted(where)} is not a uCRM price')
missing = PRICES - set(seen_prices)
if missing:
    err(f'uCRM prices missing from the site: {sorted(missing)}')
sys.exit(bad)
PYCOM
# One-time hardware prices: every "$N one-time" on the site must be a uCRM
# Products price, and all three must appear somewhere. Same law as the plans.
python3 - "$HERE/site" <<'PYHW' || fail=1
import sys, re, glob, os
root = sys.argv[1]
BASE = [350, 600, 50]
sums = {0}
for b in BASE:
    sums |= {x + b for x in sums}
ALLOWED = {str(x) for x in sums if x}
seen, bad = set(), 0
for f in glob.glob(os.path.join(root, '**', '*.html'), recursive=True):
    t = open(f, encoding='utf-8', errors='ignore').read()
    for m in re.findall(r'\$([0-9,]+)\s*(?:<[^>]*>\s*)*one-time', t):
        v = m.replace(',', '')
        seen.add(v)
        if v not in ALLOWED:
            print(f'  {os.path.relpath(f, root)}: ${v} one-time is not a uCRM hardware price'); bad = 1
REQUIRED = {'350', '600', '50'}
# Arabic pages: every $ figure must be an approved monthly price, hardware
# price, or hardware sum — same law, second language.
import glob as _g
AR_OK = {'112','189','336','483','784'} | ALLOWED
for f in _g.glob(os.path.join(root, 'ar', '*.html')):
    t = open(f, encoding='utf-8', errors='ignore').read()
    for m in re.findall(r'\$([0-9,]+)', t):
        if m.replace(',', '') not in AR_OK:
            print(f'  ar/{os.path.basename(f)}: ${m} is not an approved figure'); bad = 1   # the base items must appear; sums are merely permitted
missing = REQUIRED - seen
if missing:
    print(f'  uCRM hardware prices missing from the site: {sorted(missing)}'); bad = 1
sys.exit(bad)
PYHW
[ $fail -eq 0 ] && echo "  WhatsApp number, login URL, branding, currency, five plan prices and three hardware prices consistent"

echo
[ $fail -eq 0 ] && echo "PASS" || echo "FAIL"
exit $fail
