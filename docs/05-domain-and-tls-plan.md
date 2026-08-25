# Domain and TLS plan

Goal: reach both stacks by name over trusted HTTPS, without disturbing UISP.

```
panel.yourdomain.com  --443-->  Traefik  -->  EasyPanel
uisp.yourdomain.com   --443-->  Traefik  -->  https://<host>:8443  (UISP)
uisp.yourdomain.com:8443 ------------------>  UISP directly, for devices
```

Note the last line. **Devices keep talking to `:8443` directly.** Only browsers
go through Traefik. This is deliberate and it is the crux of the whole design.

## Why devices must not be proxied

UISP hands each adopted device a connection string containing a host and port,
and the device uses it for a persistent WebSocket back to the controller. That
is why the `:8443` in the connection key must not be removed.

If we moved device traffic onto `443`, it would land on Traefik — a component
managed by EasyPanel, upgraded on EasyPanel's schedule, and shared with every
other app on the box. An EasyPanel upgrade would then be able to take the
network offline. Keeping `8443` as the device path means device connectivity
depends on UISP alone, which is exactly the independence you asked for.

The cost is one extra open port. That is a good trade.

## Option A — Traefik terminates TLS for the browser UI (recommended)

**What is currently configured:** UISP serves its UI on `8443` with a
self-signed certificate. Browsers warn. Traefik on `443` holds Let's Encrypt
certificates but knows nothing about UISP.

**Why change:** a trusted certificate and a memorable URL for staff, without
touching the UISP installation.

**Exactly what changes:**

1. DNS: A record `uisp.yourdomain.com` → `178.62.83.12`.
2. One new file in Traefik's dynamic configuration directory — the path comes
   from the inspection report, commonly `/etc/easypanel/traefik/dynamic/`:

```yaml
# uisp.yml -- routes the browser UI only. Devices still use :8443 directly.
http:
  routers:
    uisp:
      rule: "Host(`uisp.yourdomain.com`)"
      entryPoints: ["websecure"]
      service: uisp
      tls:
        certResolver: letsencrypt      # use the resolver name EasyPanel already defines
  services:
    uisp:
      loadBalancer:
        servers:
          - url: "https://172.17.0.1:8443"   # docker bridge gateway = the host
        serversTransport: uisp-selfsigned
  serversTransports:
    uisp-selfsigned:
      insecureSkipVerify: true         # UISP's own cert is self-signed; this hop is host-local
```

3. Later, and only once the above works: set the UISP hostname (`03`, step 3)
   to `uisp.yourdomain.com` so generated links and new connection strings use
   the name. Connection strings stay on `:8443`.

**Things to get right, from the inspection report:**

- `172.17.0.1` is the usual Docker bridge gateway, but confirm it. Do not use
  `127.0.0.1` — inside the Traefik container that is the container itself.
- Use the certResolver name EasyPanel already defines in its static config.
  Inventing a new one will silently fail to issue a certificate.
- `insecureSkipVerify` is acceptable here and only here: the hop is host-local
  and UISP's certificate is self-signed by design.
- Entrypoint names (`websecure`) vary. Copy the ones EasyPanel actually uses.

**Effect on UISP:** none. No UISP file, container or setting is touched in
steps 1–2. UISP keeps serving `8443` exactly as it does now, and continues to
work by IP throughout.

**Rollback:** delete the file; Traefik reloads dynamic config automatically. If
step 3 was done, set the UISP hostname back. Nothing else to undo.

**Known risk:** the dynamic directory is EasyPanel's. An EasyPanel upgrade
could overwrite or ignore it. This only affects the browser convenience route —
devices and `https://178.62.83.12:8443` are unaffected. Keep a copy of the file
in this repo so restoring it is a `cp`.

## Option B — a real certificate inside UISP

Obtain a certificate by **DNS-01** (not HTTP-01 — port 80 is Traefik's), place
it in UISP's certificate directory, and let UISP serve it on `8443`.

- Upside: total independence from EasyPanel, and devices get a trusted
  certificate too.
- Downside: a renewal mechanism to build and maintain, and the URL keeps the
  `:8443` port.

Worth doing if we later want a certificate on the device-facing port. Not worth
doing just for a nicer browser URL. **Do not** use UISP's built-in Let's Encrypt
button: it uses HTTP-01 on port `80`, which Traefik owns, so it will fail.

## Recommendation

Do Option A. Revisit Option B only if there is a concrete reason to want a
trusted certificate on the device port.

## Order of work

1. Finish `03` and `04` — accounts created, both stacks confirmed healthy on IP.
2. Point DNS at the server; wait for it to resolve.
3. Set the EasyPanel panel domain; confirm `panel.` works over HTTPS.
4. Add the UISP route; confirm `uisp.` works over HTTPS **and** that
   `https://178.62.83.12:8443` still works.
5. Adopt or re-check one device. Only then set the UISP hostname.
6. Close `:3000` (see [06-firewall-policy.md](06-firewall-policy.md)).

Run `scripts/verify-uisp-health.sh` after each step, not just at the end.
