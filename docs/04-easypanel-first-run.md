# EasyPanel first-run

## 1. Create the administrator account

Open `http://178.62.83.12:3000` and create the admin account.

This is plain HTTP on a public IP, so the credentials you set cross the network
in the clear. Two consequences:

- Set a throwaway-strong password now, complete step 2, then change it once the
  panel is reachable over HTTPS.
- Do this promptly. An EasyPanel with no admin account yet is an open door —
  whoever reaches `:3000` first becomes the administrator.

## 2. Give the panel a domain

In Settings, set the panel domain to something like `panel.yourdomain.com`.
EasyPanel then serves itself through its own Traefik and requests a
Let's Encrypt certificate automatically. This needs:

- an A record for `panel.yourdomain.com` → `178.62.83.12`, resolving already
- ports `80` and `443` reachable from the internet, for the ACME HTTP-01
  challenge and then for the panel itself

Once that works, `:3000` no longer needs to be open to the world. Closing it is
the single largest security improvement available here — see
[06-firewall-policy.md](06-firewall-policy.md).

## 3. What EasyPanel is for here

EasyPanel deploys the *other* services we want alongside UISP: portals,
dashboards, internal tools, databases for those tools.

It is **not** for UISP. The reasoning, and the specific things that would break,
are in [07-change-control.md](07-change-control.md).

## 4. Things to be careful with in EasyPanel

- **Do not add services that publish host ports 80, 443, 8080, 8443, 81, 8089
  or 2055.** EasyPanel apps should be routed through Traefik by domain, which
  needs no host port at all. A service that binds a host port directly is the
  most likely way to collide with UISP.
- **Traefik's dynamic configuration is shared.** If we add a route for UISP
  (see `05`), it lives in the same directory EasyPanel manages. Note it in
  [07-change-control.md](07-change-control.md) so an EasyPanel upgrade that
  rewrites that directory is a known risk, not a surprise outage.
- **Watch disk.** EasyPanel apps, images and build caches share the disk with
  UISP's Postgres and SiriDB. UISP degrades badly when the disk fills.
  `scripts/verify-uisp-health.sh` checks for this.
