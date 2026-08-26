#!/usr/bin/env bash
# Trim, resize and re-encode a product photo to WebP.
#
# There is no PIL and no ImageMagick in this container, so Chromium does the
# work: it fetches the file over a local server, finds the bounding box of
# everything that is not the white studio backdrop, crops to it, scales down
# and re-encodes. A 715 KB PNG came out of this at 33 KB.
#
#   ./tools/optimise-image.sh /path/to/photo.png site/assets/img/products/standard-kit.webp
set -euo pipefail
SRC="$1"; OUT="$2"; MAXW="${3:-1100}"; Q="${4:-0.86}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"; cp "$SRC" "$TMP/in.${SRC##*.}"
python3 -m http.server 8901 --bind 127.0.0.1 --directory "$TMP" >/dev/null 2>&1 &
SRV=$!; trap 'kill $SRV 2>/dev/null; rm -rf "$TMP"' EXIT
sleep 1
NODE_PATH="${NODE_PATH:-/opt/node22/lib/node_modules}" node "$HERE/tools/optimise-image.js" \
  "[{\"url\":\"/in.${SRC##*.}\",\"out\":\"$OUT\",\"maxW\":$MAXW,\"quality\":$Q,\"trim\":true}]"
