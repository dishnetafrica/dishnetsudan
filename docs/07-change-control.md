# Change control

The UISP/uCRM installation is treated as production infrastructure that happens
to share a host with EasyPanel. This page is the standing rule set.

## The one way this setup gets broken

The whole arrangement depends on one fact: **UISP was installed with
non-default ports (8080/8443), so Traefik can hold 80/443.**

Re-running the UISP installer without the original port flags would make UISP
try to bind 80 and 443. Traefik already holds them, so UISP fails to start, and
depending on ordering after a reboot the two can fight over the ports. That is
the single most likely cause of a serious outage here.

So:

- **Use `unms-cli update` to update UISP.** It preserves the configured ports.
- **Never re-run the UISP install script** as a way of fixing a problem.
- Before any UISP maintenance, record the current ports from `unms.conf` (the
  inspection report captures them) so they can be re-supplied if an install
  ever genuinely becomes necessary.

## Standing rules

Per your instruction, and with the reason each one exists:

| Rule | Why |
| --- | --- |
| Do not install or recreate UISP inside EasyPanel | EasyPanel would own the container lifecycle; a panel upgrade could then take the network offline |
| Do not update UISP containers through EasyPanel or `docker pull` | UISP updates involve database migrations that `unms-cli update` sequences correctly and an image swap does not |
| Do not move UISP behind EasyPanel for device traffic | Device connectivity would inherit Traefik's uptime. Browser UI through Traefik is fine — see `05` |
| Do not replace or reinstall the existing UISP | Above |
| Use EasyPanel for everything *except* UISP | That is what it is good at |

## Procedure for any change

1. **Establish the baseline.** `sudo ./scripts/verify-uisp-health.sh` — save it.
2. **Snapshot.** `sudo ./scripts/backup-configs.sh`, plus a UISP backup from the
   UI if the change touches UISP at all.
3. **Write it down first**, in the five-part form below.
4. **Apply one change.** Not three.
5. **Verify.** `verify-uisp-health.sh` again, plus a real device check if
   anything touched ports, DNS or the UISP hostname.
6. **Commit** the documentation change to this repo, so the repo stays an
   accurate record of the server.

If step 5 does not match step 1, roll back. Do not investigate forward from a
degraded state on a live network.

## The five-part form

Every change gets these written down before it is applied:

1. **What is currently configured** — observed, from the inspection report.
2. **Why the change is necessary** — the problem, not the solution.
3. **Exactly what will change** — files, ports, settings, commands, verbatim.
4. **Effect on UISP/uCRM** — including "none", with the reasoning for why none.
5. **Rollback** — the specific steps, and how long they take.

[05-domain-and-tls-plan.md](05-domain-and-tls-plan.md) is written in this form
as a worked example.

## Change log

| Date | Change | Applied by | Rollback | Status |
| --- | --- | --- | --- | --- |
| — | Repo created: inspection tooling and plans. No server changes. | Claude | n/a | Documentation only |
