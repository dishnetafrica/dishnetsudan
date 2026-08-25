#!/usr/bin/env bash
#
# verify-uisp-health.sh -- READ-ONLY health check for the UISP/uCRM stack.
#
# Run this BEFORE and AFTER every change we make to the server, including
# changes that are supposedly unrelated to UISP. If the "after" run does not
# match the "before" run, roll the change back.
#
# Exits 0 when healthy, 1 when something is wrong.
#
set -uo pipefail

fail=0
ok()   { printf '  [ OK ]  %s\n' "$1"; }
bad()  { printf '  [FAIL]  %s\n' "$1"; fail=1; }
warn() { printf '  [warn]  %s\n' "$1"; }

printf 'UISP/uCRM health check -- %s\n\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"

if ! command -v docker >/dev/null 2>&1; then
  bad "docker is not available"
  exit 1
fi

printf 'Containers\n'
mapfile -t uisp_containers < <(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -iE 'unms|uisp|ucrm' | sort)
if [ "${#uisp_containers[@]}" -eq 0 ]; then
  bad "no UISP containers found at all"
else
  for c in "${uisp_containers[@]}"; do
    state=$(docker inspect --format '{{.State.Status}}' "$c" 2>/dev/null)
    health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$c" 2>/dev/null)
    restarts=$(docker inspect --format '{{.RestartCount}}' "$c" 2>/dev/null)
    label="$c (state=$state health=$health restarts=$restarts)"
    if [ "$state" != "running" ]; then
      bad "$label"
    elif [ "$health" = "unhealthy" ]; then
      bad "$label"
    elif [ "${restarts:-0}" -gt 3 ] 2>/dev/null; then
      warn "$label -- restarting repeatedly, check its logs"
    else
      ok "$label"
    fi
  done
fi

printf '\nPorts\n'
if ! command -v ss >/dev/null 2>&1; then
  warn "ss is not installed (apt install iproute2) -- skipping port checks"
fi
for p in 8080 8443; do
  command -v ss >/dev/null 2>&1 || continue
  if ss -tlnH "sport = :$p" 2>/dev/null | grep -q .; then
    ok "$p/tcp is bound"
  else
    bad "$p/tcp is NOT bound -- UISP is not reachable on its expected port"
  fi
done

printf '\nHTTP endpoints\n'
code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 https://127.0.0.1:8443/ 2>/dev/null)
case "$code" in
  200|301|302|307|308) ok "https://127.0.0.1:8443/ -> HTTP $code" ;;
  *)                   bad "https://127.0.0.1:8443/ -> HTTP ${code:-no-response}" ;;
esac

printf '\nDevice connectivity (as UISP reports it)\n'
if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'unms'; then
  errs=$(docker logs --since 10m unms 2>&1 | grep -ciE 'error|fatal' || true)
  if [ "${errs:-0}" -gt 20 ]; then
    warn "$errs error/fatal lines in the last 10 minutes of the unms log -- review them"
  else
    ok "unms log looks normal ($errs error lines in the last 10 minutes)"
  fi
else
  warn "no container named exactly 'unms' -- adjust this check to the real name"
fi

printf '\nDisk\n'
avail=$(df -P / | awk 'NR==2{print $4}')
if [ "${avail:-0}" -lt 2097152 ] 2>/dev/null; then
  bad "less than 2 GB free on / -- UISP's Postgres and SiriDB will start failing"
else
  ok "$(df -Ph / | awk 'NR==2{print $4}') free on /"
fi

printf '\n'
if [ "$fail" -eq 0 ]; then
  printf 'RESULT: healthy\n'
else
  printf 'RESULT: PROBLEMS FOUND -- do not proceed with further changes, roll back instead\n'
fi
exit "$fail"
