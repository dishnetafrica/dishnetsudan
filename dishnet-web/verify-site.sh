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
[ $fail -eq 0 ] && echo "  no South-Sudan-only claims, sitemap well-formed"

echo
[ $fail -eq 0 ] && echo "PASS" || echo "FAIL"
exit $fail
