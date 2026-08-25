#!/usr/bin/env bash
# Crawl the LIVE site and print PASS/FAIL per production criterion.
# Run on the server (or any machine that can reach dishnetsudan.com):
#   bash tools/verify-production.sh
BASE="${1:-https://dishnetsudan.com}"
NUMBER="249900083481"
PRICES="112 189 336 483 784"
fail=0; warn=0
ok()   { printf '  PASS  %s\n' "$1"; }
bad()  { printf '  FAIL  %s\n' "$1"; fail=$((fail+1)); }
note() { printf '  WARN  %s\n' "$1"; warn=$((warn+1)); }

echo "== HTTPS and domain =="
code=$(curl -sS -o /dev/null -w '%{http_code}' -m 20 "$BASE/") || code=000
[ "$code" = 200 ] && ok "homepage over HTTPS ($code)" || bad "homepage returned $code"
proto=$(curl -sSI -m 20 "$BASE/" | head -1 | awk '{print $1}')
ok "protocol $proto"
wcode=$(curl -sS -o /dev/null -w '%{http_code}' -m 20 "https://www.dishnetsudan.com/") || wcode=000
case "$wcode" in 200|301|308) ok "www resolves ($wcode)";; *) note "www.dishnetsudan.com returned $wcode — add it as a domain or a redirect";; esac
hcode=$(curl -sS -o /dev/null -w '%{http_code}' -m 20 "http://dishnetsudan.com/" -L --max-redirs 3 -w '%{url_effective} %{http_code}' 2>/dev/null | awk '{print $2}')
first=$(curl -sS -o /dev/null -w '%{redirect_url}' -m 20 "http://dishnetsudan.com/")
case "$first" in https://*) ok "plain http redirects to https";; *) note "http:// did not redirect to https ($first)";; esac

echo "== security headers =="
H=$(curl -sSI -m 20 "$BASE/")
for h in x-content-type-options x-frame-options referrer-policy; do
  echo "$H" | grep -qi "^$h" && ok "$h" || bad "$h missing"
done
echo "$H" | grep -qi '^strict-transport-security' && ok "HSTS" || note "no HSTS header (Traefik option; not blocking)"

echo "== hardening =="
for p in /_redirects /.git/config /.env; do
  c=$(curl -sS -o /dev/null -w '%{http_code}' -m 20 "$BASE$p")
  body=$(curl -sS -m 20 "$BASE$p" | head -c 40)
  case "$body" in \[build\]*|\[core\]*|*=*KEY*) bad "$p is served";; *) ok "$p not exposed ($c)";; esac
done

echo "== redirects =="
for pair in "/faqs /faq.html" "/about-us /about.html" "/contact-us /contact.html" \
            "/services /services.html" "/terms-of-use /terms.html" "/application /contact.html" \
            "/device/1/view /starlink-kits.html" "/device/6/view /starlink-kits.html" \
            "/business /index.html#order" "/device /index.html#products"; do
  from=${pair%% *}; want=${pair##* }
  loc=$(curl -sSI -m 20 "$BASE$from" | tr -d '\r' | awk 'tolower($1)=="location:"{print $2}')
  [ "$loc" = "$want" ] && ok "$from -> $want" || bad "$from -> ${loc:-none} (want $want)"
done

echo "== every page in the sitemap =="
tmp=$(mktemp)
curl -sS -m 30 "$BASE/sitemap.xml" | grep -o '<loc>[^<]*</loc>' | sed 's/<[^>]*>//g' > "$tmp"
total=$(wc -l < "$tmp"); nbad=0
while read -r u; do
  c=$(curl -sS -o /dev/null -w '%{http_code}' -m 20 "$u")
  [ "$c" = 200 ] || { bad "$u -> $c"; nbad=$((nbad+1)); }
done < "$tmp"
[ "$nbad" = 0 ] && ok "$total sitemap pages all 200"

echo "== content rules on the live pages =="
pages="/ /faq.html /services.html /coverage.html /starlink-khartoum.html /starlink-kits.html /why-dishnet.html /about.html /contact.html"
allbodies=$(for p in $pages; do curl -sS -m 20 "$BASE$p"; done)
echo "$allbodies" | grep -q 'UGANDA' && bad "UGANDA branding still live" || ok "no UGANDA branding"
echo "$allbodies" | grep -qE '\bSSP\b' && bad "SSP still live" || ok "no SSP references"
echo "$allbodies" | grep -qE '\$(112|189|336|483|784)\b' && ok "uCRM prices live" || bad "uCRM prices not found — old build still deployed?"
kits=$(curl -sS -m 20 "$BASE/starlink-kits.html")
echo "$kits" | grep -q '\$350' && echo "$kits" | grep -q '\$600' && ok "hardware prices live (350/600)" || bad "hardware prices missing from kits page"
echo "$kits$allbodies" | grep -qE '\$(299|549|550|2,600|2600)\b' && bad "old South Sudan hardware price still live" || ok "no old hardware prices"
echo "$allbodies" | grep -qE '\$(142|218|366|513|814)\b' && bad "sheet prices still live" || ok "no internal sheet prices"
echo "$allbodies" | grep -qE '\$(80|65) ?<small>' && bad "South Sudan plan prices still live" || ok "no South Sudan plan prices"
echo "$allbodies" | grep -q 'dishnet-hybrid-telecom' && bad "old plugin login URL still live" || ok "CRM login link correct"
for n in 211923400000 211921443002 211924332000; do
  echo "$allbodies" | grep -q "$n" && bad "South Sudan number $n still live" || ok "no $n"
done
echo "$allbodies" | grep -q "wa.me/$NUMBER" && ok "AI sales number wired" || bad "wa.me/$NUMBER not found"
echo "$allbodies" | grep -q 'href="https://wa.me/"' && bad "empty wa.me link live" || ok "no empty WhatsApp links"
echo "$allbodies" | grep -q 'fiber.html' && bad "fiber.html still linked" || ok "no fibre links"
echo "$allbodies" | grep -q 'portal-preview' && bad "demo portal still linked" || ok "no demo links"

echo "== images actually load =="
imgs=$(echo "$allbodies" | grep -oE 'src="[^"]+\.(png|jpe?g|webp|avif|svg)"' | sed 's/src="//;s/"//' | sort -u)
ibad=0; iext=0
for i in $imgs; do
  case "$i" in
    http*) u="$i"; case "$i" in "$BASE"*) ;; *) iext=$((iext+1));; esac ;;
    /*) u="$BASE$i" ;;
    *)  u="$BASE/$i" ;;
  esac
  c=$(curl -sS -o /dev/null -w '%{http_code}' -m 20 "$u")
  [ "$c" = 200 ] || { bad "image $c: $u"; ibad=$((ibad+1)); }
done
[ "$ibad" = 0 ] && ok "$(echo "$imgs" | wc -w | tr -d ' ') images all load"
[ "$iext" = 0 ] && ok "no images on external hosts" || note "$iext image(s) still hotlinked externally — run tools/fetch-images.sh and redeploy"

echo "== robots and canonical =="
curl -sS -m 20 "$BASE/robots.txt" | grep -q "dishnetsudan.com/sitemap.xml" && ok "robots.txt sitemap" || bad "robots.txt wrong"
can=$(curl -sS -m 20 "$BASE/" | grep -o 'rel="canonical" href="[^"]*"' | head -1)
case "$can" in *dishnetsudan.com*) ok "canonical on own domain";; *) bad "canonical: $can";; esac

echo
if [ "$fail" = 0 ]; then echo "PRODUCTION CRAWL: PASS ($warn warnings)"; else echo "PRODUCTION CRAWL: FAIL — $fail failures, $warn warnings"; fi
exit "$fail"
