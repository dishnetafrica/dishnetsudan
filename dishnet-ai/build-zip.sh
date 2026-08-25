#!/usr/bin/env bash
#
# build-zip.sh — package this plugin for upload to UISP/uCRM.
#
# uCRM looks for manifest.json at the ROOT of the archive. Zipping the
# containing folder instead of its contents produces
# "Plugin manifest could not be found in the ZIP archive." That is the only
# thing this script exists to get right.
#
set -euo pipefail
cd "$(dirname "$0")"

OUT="${1:-../dishnet-ai.zip}"

# Never ship runtime state: the database, logs, or a config file holding secrets.
rm -f data/* 2>/dev/null || true
touch data/.gitkeep

rm -f "$OUT"
zip -qr "$OUT" . -x '*.DS_Store' 'build-zip.sh' '.gitkeep'

# Verify rather than assume.
if ! unzip -l "$OUT" | grep -qE ' manifest\.json$'; then
  echo "FAIL: manifest.json is not at the archive root" >&2
  exit 1
fi
echo "Built $OUT ($(du -h "$OUT" | cut -f1))"
unzip -l "$OUT" | tail -1
