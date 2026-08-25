# Inspection runbook

Run this first, before any change. It is entirely read-only.

## 1. Take an inventory

```bash
git clone <this repo> ~/dishnetsudan && cd ~/dishnetsudan
sudo ./scripts/inspect-server.sh
```

The report lands in `reports/inspect-<timestamp>.txt`. Values that look like
passwords, secrets, tokens or keys are replaced with `<redacted>` before they
reach the file, but read the report before sharing it anywhere — redaction by
pattern is a safety net, not a guarantee. `reports/` is gitignored.

## 2. Establish a health baseline

```bash
sudo ./scripts/verify-uisp-health.sh
```

Keep the output. This is the "before" that every later change gets compared
against. If this already fails, stop and fix that before doing anything else.

## 3. Snapshot the configuration

```bash
sudo ./scripts/backup-configs.sh
```

This copies config files and the runtime state to `/root/dishnet-backups/`. It
is **not** a UISP backup — take one of those from the UISP UI
(Settings → Maintenance → Backup) as well, and download it off the server.

## What to read in the report

Work through these questions in order. Each one decides something later.

| Question | Where in the report | Why it matters |
| --- | --- | --- |
| Where does UISP actually live? | `UISP / uCRM` → install directory, and `DOCKER` → compose config_files | Every later command needs the real path, not a guessed one |
| Which ports did UISP get installed with? | the `unms.conf` dump | Confirms 8080/8443 and tells us what an update will re-apply |
| Is the CRM module present? | UISP containers list | Decides whether "enable CRM" is a setting or a bigger job |
| Which container owns 80/443? | `LISTENING SOCKETS` → port table | Confirms Traefik, not UISP, holds them |
| Where does Traefik read dynamic config? | `EASYPANEL / TRAEFIK` → dynamic config directory | Determines how we add a `uisp.` route later |
| Does UFW match the DO Cloud Firewall? | `FIREWALL` → ufw, plus the DO panel | Two layers; a port is only open if both allow it |
| Is anything actually listening on 81, 8089, 2055? | port table | Answers whether opening them would even do anything |
| Is anything restart-looping? | `DOCKER` → restart loops | A latent problem we should not build on top of |

## Reporting back

Once you have the report, paste it here (or push it to a private branch) and I
will fill in [01-current-architecture.md](01-current-architecture.md) with
observed values, and adjust the plans in `05` and `06` to match what is really
there. Several of the recommendations in those documents are explicitly
conditional on what the inspection finds.
