#!/usr/bin/env bash
#
# backup-configs.sh -- snapshot the configuration of both stacks before a change.
#
# Copies configuration only. It does not touch, restart or reconfigure anything,
# and it does not back up UISP's databases -- for that use UISP's own backup
# (UISP -> Settings -> Maintenance -> Backup), which is the only supported way
# to get a restorable UISP backup.
#
# Usage:  sudo ./scripts/backup-configs.sh [/destination/dir]
#
set -uo pipefail

DEST="${1:-/root/dishnet-backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
WORK="$DEST/$STAMP"
mkdir -p "$WORK" || { echo "cannot create $WORK" >&2; exit 1; }

copy() {
  local src="$1"
  [ -e "$src" ] || { printf 'skip (absent): %s\n' "$src"; return 0; }
  local target="$WORK/$(echo "${src#/}" | tr '/' '_')"
  cp -a "$src" "$target" 2>/dev/null && printf 'saved: %s\n' "$src"
}

printf 'Backing up configuration to %s\n\n' "$WORK"

for d in /home/uisp /home/unms /opt/uisp /opt/unms; do
  [ -d "$d" ] || continue
  copy "$d/unms.conf"
  copy "$d/app/unms.conf"
  copy "$d/docker-compose.yml"
  copy "$d/app/docker-compose.yml"
done

copy /etc/easypanel/traefik
copy /etc/ufw

# Record the runtime shape of the system so we can diff against it later.
{
  printf '# docker ps at %s\n' "$STAMP"
  docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null
  printf '\n# listening sockets\n'
  ss -tulpn 2>/dev/null
  printf '\n# ufw\n'
  ufw status verbose 2>/dev/null
} > "$WORK/runtime-state.txt" 2>&1
printf 'saved: runtime-state.txt\n'

chmod -R go-rwx "$WORK" 2>/dev/null

printf '\nDone. These files may contain credentials -- keep the directory root-only\n'
printf 'and do not commit it to git.\n'
printf 'Reminder: this is NOT a UISP backup. Take one from the UISP UI as well.\n'
