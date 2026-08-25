#!/usr/bin/env bash
# Dependency-free tests for the AI platform layer. No PHPUnit, matching the
# plugin's zero-dependency convention. Run from anywhere.
set -uo pipefail
cd "$(dirname "$0")"
fail=0
for t in test_*.php; do
  printf '\n=== %s ===\n' "$t"
  php "$t" || fail=1
done
exit "$fail"
