#!/usr/bin/env bash
#
# inspect-server.sh -- READ-ONLY audit of the DishNet Sudan host.
#
# Collects the facts we need about EasyPanel/Traefik and UISP/uCRM so that any
# change can be planned against reality instead of assumptions.
#
# This script does NOT start, stop, create, modify, update or delete anything.
# It runs only read commands. The single thing it writes is its own report file.
#
# Usage:
#   sudo ./scripts/inspect-server.sh
#   sudo ./scripts/inspect-server.sh -o /root/uisp-audit.txt
#
set -uo pipefail

OUT=""
while getopts ":o:h" opt; do
  case "$opt" in
    o) OUT="$OPTARG" ;;
    h) sed -n '2,18p' "$0"; exit 0 ;;
    *) echo "unknown option: -$OPTARG" >&2; exit 2 ;;
  esac
done

if [ -z "$OUT" ]; then
  repo_root="$(cd "$(dirname "$0")/.." && pwd)"
  mkdir -p "$repo_root/reports"
  OUT="$repo_root/reports/inspect-$(date -u +%Y%m%dT%H%M%SZ).txt"
fi

have() { command -v "$1" >/dev/null 2>&1; }
section() { printf '\n\n===== %s =====\n' "$1"; }
sub() { printf '\n--- %s ---\n' "$1"; }

# Blank out anything that looks like a credential before it reaches the report.
redact() {
  sed -E 's/(([A-Za-z_]*(PASS|PASSWORD|SECRET|TOKEN|APIKEY|API_KEY|SALT|PRIVATE)[A-Za-z_]*)[[:space:]]*[=:][[:space:]]*).*/\1<redacted>/I'
}

main() {
  printf 'DishNet Sudan -- server inspection\n'
  printf 'Generated (UTC): %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  printf 'Run as: %s (uid %s)\n' "$(id -un)" "$(id -u)"
  [ "$(id -u)" -ne 0 ] && printf 'WARNING: not running as root -- docker, ss -p and ufw output will be incomplete.\n'

  section "HOST"
  have hostnamectl && hostnamectl 2>/dev/null
  sub "kernel / uptime"; uname -a; uptime
  sub "os-release"; cat /etc/os-release 2>/dev/null
  sub "public IP as seen by the host"; ip -4 addr show scope global 2>/dev/null | awk '/inet /{print $2, $NF}'
  sub "memory"; free -h 2>/dev/null
  sub "disk"; df -hT -x tmpfs -x devtmpfs 2>/dev/null
  sub "docker data usage"; have docker && docker system df 2>/dev/null

  section "DOCKER"
  if ! have docker; then
    printf 'docker not found on PATH -- nothing else in this section applies.\n'
  else
    sub "versions"; docker version --format 'client {{.Client.Version}} / server {{.Server.Version}}' 2>/dev/null
    have docker && docker compose version 2>/dev/null
    sub "running containers"
    docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null
    sub "stopped / exited containers"
    docker ps -a --filter status=exited --filter status=created --filter status=dead \
      --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}' 2>/dev/null
    sub "restart loops (RestartCount > 0)"
    for c in $(docker ps -aq 2>/dev/null); do
      n=$(docker inspect --format '{{.Name}}' "$c" 2>/dev/null | tr -d /)
      r=$(docker inspect --format '{{.RestartCount}}' "$c" 2>/dev/null)
      [ "${r:-0}" -gt 0 ] 2>/dev/null && printf '%-30s restarts=%s\n' "$n" "$r"
    done
    sub "compose projects and where their config files live"
    docker ps -a --format '{{.Label "com.docker.compose.project"}}|{{.Label "com.docker.compose.project.config_files"}}' 2>/dev/null \
      | grep -v '^|$' | sort -u
    sub "networks"; docker network ls 2>/dev/null
    sub "volumes"; docker volume ls 2>/dev/null
  fi

  section "LISTENING SOCKETS"
  if have ss; then
    ss -tulpn 2>/dev/null
  elif have netstat; then
    netstat -tulpn 2>/dev/null
  else
    printf 'neither ss nor netstat available\n'
  fi
  sub "who owns the ports we care about"
  if ! have ss; then
    printf 'ss is not installed -- install iproute2 and re-run, this table is the\n'
    printf 'most important part of the report.\n'
  fi
  for p in 22 80 81 443 3000 8080 8089 8443; do
    owner="$(ss -tlpnH "sport = :$p" 2>/dev/null | tr -s ' ' | cut -d' ' -f4,6- | paste -sd'; ' - || true)"
    printf '%-9s %s\n' "$p/tcp" "${owner:-<nothing listening>}"
  done
  owner="$(ss -ulpnH 'sport = :2055' 2>/dev/null | tr -s ' ' | cut -d' ' -f4,6- | paste -sd'; ' - || true)"
  printf '%-9s %s\n' "2055/udp" "${owner:-<nothing listening>}"

  section "FIREWALL"
  sub "ufw"; have ufw && ufw status verbose 2>/dev/null || printf 'ufw not installed\n'
  sub "iptables filter INPUT (first 60 lines)"
  have iptables && iptables -S INPUT 2>/dev/null | head -60
  sub "docker-managed nat rules (informational)"
  have iptables && iptables -t nat -S DOCKER 2>/dev/null | head -40
  printf '\nNOTE: a DigitalOcean Cloud Firewall sits in front of the droplet and is NOT visible\n'
  printf 'from inside the host. Check it in the DO control panel as well.\n'

  section "UISP / uCRM"
  uisp_dir=""
  for d in /home/uisp /home/unms /opt/uisp /opt/unms; do
    [ -d "$d" ] && uisp_dir="$d" && break
  done
  if [ -z "$uisp_dir" ]; then
    printf 'No UISP directory found in the usual locations.\n'
    printf 'Fall back to the compose-project listing above to find its config_files path.\n'
  else
    printf 'UISP install directory: %s\n' "$uisp_dir"
    sub "directory listing"; ls -la "$uisp_dir" 2>/dev/null
    sub "app subdirectory"; ls -la "$uisp_dir/app" 2>/dev/null
    for cfg in "$uisp_dir/unms.conf" "$uisp_dir/app/unms.conf"; do
      if [ -f "$cfg" ]; then
        sub "config: $cfg (credentials redacted)"
        redact < "$cfg"
      fi
    done
    for yml in "$uisp_dir/docker-compose.yml" "$uisp_dir/app/docker-compose.yml"; do
      if [ -f "$yml" ]; then
        sub "compose file: $yml -- published port mappings only"
        grep -nE '^\s*(-\s*")?[0-9]+:[0-9]+|ports:|image:|container_name:' "$yml" 2>/dev/null
      fi
    done
    sub "custom TLS certificate directory"
    ls -la "$uisp_dir/data/cert" 2>/dev/null || printf 'no %s/data/cert -- UISP is on its self-signed cert\n' "$uisp_dir"
  fi

  sub "UISP containers"
  have docker && docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null \
    | grep -iE 'unms|uisp|ucrm|crm' || printf 'none matched\n'

  sub "UISP image versions"
  if have docker; then
    for c in $(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -iE 'unms|uisp|ucrm'); do
      printf '%-28s %s\n' "$c" "$(docker inspect --format '{{.Config.Image}}' "$c" 2>/dev/null)"
    done
  fi

  sub "unms-cli (the supported management entry point)"
  if have unms-cli; then
    printf 'unms-cli found at: %s\n' "$(command -v unms-cli)"
    unms-cli --help 2>&1 | head -30
  else
    printf 'unms-cli not on PATH; look for %s/unms-cli\n' "${uisp_dir:-/home/uisp}"
    ls -la "${uisp_dir:-/home/uisp}"/unms-cli 2>/dev/null
  fi

  sub "does the UISP UI answer locally on 8443?"
  have curl && curl -sk -o /dev/null -w 'https://127.0.0.1:8443/ -> HTTP %{http_code}\n' --max-time 10 https://127.0.0.1:8443/ 2>&1
  have curl && curl -s  -o /dev/null -w 'http://127.0.0.1:8080/  -> HTTP %{http_code}\n' --max-time 10 http://127.0.0.1:8080/ 2>&1

  sub "certificate currently presented on 8443"
  have openssl && echo | openssl s_client -connect 127.0.0.1:8443 -servername localhost 2>/dev/null \
    | openssl x509 -noout -subject -issuer -dates 2>/dev/null || printf 'could not read certificate\n'

  sub "recent UISP application log (last 40 lines, redacted)"
  have docker && docker logs --tail 40 unms 2>&1 | redact || printf 'no "unms" container\n'

  section "EASYPANEL / TRAEFIK"
  sub "easypanel containers"
  have docker && docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null \
    | grep -iE 'easypanel|traefik' || printf 'none matched\n'
  sub "/etc/easypanel layout (2 levels)"
  find /etc/easypanel -maxdepth 2 2>/dev/null | head -60 || printf 'no /etc/easypanel\n'
  sub "traefik static config"
  for f in /etc/easypanel/traefik/traefik.yml /etc/easypanel/traefik/traefik.yaml /etc/traefik/traefik.yml; do
    [ -f "$f" ] && { printf '%s:\n' "$f"; redact < "$f"; }
  done
  sub "traefik dynamic config directory -- this is where a UISP route would go"
  ls -la /etc/easypanel/traefik/dynamic 2>/dev/null || printf 'no dynamic dir at /etc/easypanel/traefik/dynamic\n'
  for f in /etc/easypanel/traefik/dynamic/*; do
    [ -f "$f" ] && { printf '\n%s:\n' "$f"; redact < "$f"; }
  done
  sub "ACME certificate store (presence and size only, contents never printed)"
  ls -la /etc/easypanel/traefik/acme.json 2>/dev/null || printf 'no acme.json at the default path\n'
  sub "traefik entrypoints as actually launched"
  have docker && docker inspect --format '{{range .Config.Cmd}}{{println .}}{{end}}' \
    "$(docker ps --format '{{.Names}}' 2>/dev/null | grep -i traefik | head -1)" 2>/dev/null
  sub "recent traefik log (last 30 lines)"
  have docker && docker logs --tail 30 "$(docker ps --format '{{.Names}}' 2>/dev/null | grep -i traefik | head -1)" 2>&1 | redact

  section "SYSTEM SERVICES"
  have systemctl && systemctl list-units --type=service --state=running --no-pager 2>/dev/null | head -40

  section "END OF REPORT"
  printf 'Saved to: %s\n' "$OUT"
}

main 2>&1 | tee "$OUT"
