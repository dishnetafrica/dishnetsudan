# DishNet Sudan — server operations

Operational documentation and read-only tooling for the DigitalOcean host at
`178.62.83.12`, which runs **EasyPanel (+ Traefik)** and **UISP/uCRM** side by
side as independent stacks.

## Status: nothing here has been verified against the server

I have no SSH access or network path to `178.62.83.12` from where I run — no
credentials are present, and direct connections to `22`, `3000` and `8443` are
blocked by this environment's egress policy. So I could not inspect the running
setup, as you asked me to do first.

What I have built instead is the inspection itself, plus plans for the remaining
work written against the architecture you described. Run
`scripts/inspect-server.sh`, send me the report, and I will replace the
described values with observed ones and firm up the conditional recommendations.

**Start here:** [docs/02-inspection-runbook.md](docs/02-inspection-runbook.md)

## Documentation

| | |
| --- | --- |
| [01 Current architecture](docs/01-current-architecture.md) | Port map and ownership boundaries, as described |
| [02 Inspection runbook](docs/02-inspection-runbook.md) | Read-only audit — **run this first** |
| [03 UISP first run](docs/03-uisp-first-run.md) | Admin account, CRM enablement, and what not to enable yet |
| [04 EasyPanel first run](docs/04-easypanel-first-run.md) | Admin account, panel domain, avoiding port collisions |
| [05 Domain and TLS plan](docs/05-domain-and-tls-plan.md) | `panel.` and `uisp.` over trusted HTTPS, devices untouched |
| [06 Firewall policy](docs/06-firewall-policy.md) | Which ports are actually needed, and which to leave closed |
| [07 Change control](docs/07-change-control.md) | Standing rules and the five-part change form |
| [08 Backup and rollback](docs/08-backup-and-rollback.md) | What to back up, and what has no undo |

## Scripts

All three are safe to run against the live server. None of them start, stop,
create, modify or delete any service, container or rule.

| Script | Purpose |
| --- | --- |
| `scripts/inspect-server.sh` | Full read-only audit of both stacks → `reports/` |
| `scripts/verify-uisp-health.sh` | UISP health check; run before **and** after every change |
| `scripts/backup-configs.sh` | Snapshot config files and runtime state (not a UISP backup) |

```bash
sudo ./scripts/inspect-server.sh
sudo ./scripts/verify-uisp-health.sh
sudo ./scripts/backup-configs.sh
```

## The rules this repo is built around

- UISP/uCRM is already installed, working, and **independently managed**. It is
  not reinstalled, not moved into EasyPanel, and not updated through EasyPanel.
- UISP updates go through `unms-cli update`.
- The `:8443` in device connection keys stays.
- Firewall ports are opened when the feature that needs them is switched on —
  not in advance.

The reasoning behind each is in
[docs/07-change-control.md](docs/07-change-control.md).
