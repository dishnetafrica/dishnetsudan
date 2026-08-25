#!/usr/bin/env bash
# Host the site's images locally instead of hotlinking them.
#
# Every image on this site is currently loaded from dishnetafrica.com or
# portal.dishnetss.com. Two consequences: the Sudan site goes partly blank if
# South Sudan infrastructure is down, and every visitor pays a DNS lookup and
# TLS handshake to a third host before an image appears - which is the part
# that hurts on a slow connection.
#
# Run this ON THE SERVER, where those hosts are reachable:
#   cd /path/to/dishnet-web && ./tools/fetch-images.sh
# then redeploy. Safe to re-run; it only rewrites what it successfully fetched.
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
IMG="$HERE/site/assets/img"
mkdir -p "$IMG"
ok=0; fail=0

urls=$(grep -rhoE '(src|href|content)="https://(dishnetafrica\.com|portal\.dishnetss\.com)/[^"]+\.(jpg|jpeg|png|gif|webp|avif|svg)"' \
        "$HERE/site" --include='*.html' | sed -E 's/^[a-z]+="//; s/"$//' | sort -u)
[ -z "$urls" ] && { echo "nothing hotlinked - already local"; exit 0; }

for u in $urls; do
  n=$(basename "$u")
  if [ -s "$IMG/$n" ] || curl -sSfL -m 30 -o "$IMG/$n" "$u"; then
    ok=$((ok+1))
    # rewrite every reference to this file, in place
    grep -rlF "$u" "$HERE/site" --include='*.html' | while read -r f; do
      rel=$(python3 -c "import os,sys;print(os.path.relpath('$IMG/$n',os.path.dirname(sys.argv[1])))" "$f")
      python3 - "$f" "$u" "$rel" <<'PY'
import sys
f,old,new=sys.argv[1],sys.argv[2],sys.argv[3]
t=open(f,encoding='utf-8').read()
open(f,'w',encoding='utf-8').write(t.replace(old,new))
PY
    done
    echo "  localised $n ($(du -h "$IMG/$n" | cut -f1))"
  else
    fail=$((fail+1)); rm -f "$IMG/$n"; echo "  FAILED   $n - left hotlinked"
  fi
done
echo
echo "$ok localised, $fail still remote"
[ $fail -gt 0 ] && echo "Re-run when those hosts are reachable; nothing is broken meanwhile."
exit 0
