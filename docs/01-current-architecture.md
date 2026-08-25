# Current architecture (as described, pending verification)

Everything on this page is what I was **told** is running, not what I have
**observed**. I have no network path or SSH access to `178.62.83.12` from where
I run, so nothing here is verified. `scripts/inspect-server.sh` exists to turn
this page from a description into a record. See
[02-inspection-runbook.md](02-inspection-runbook.md).

## Host

| Item | Value |
| --- | --- |
| Public IP | `178.62.83.12` |
| Provider | DigitalOcean droplet |
| Stacks | EasyPanel (+ Traefik) and UISP/uCRM, side by side |

Both stacks are installed **directly on the host**, each with its own Docker
containers. They are peers. Neither manages the other.

## Port map

```
Internet
   |
   +--- 22/tcp -----------> SSH
   |
   +--- 80/tcp -----------> Traefik  (EasyPanel) -- HTTP, ACME challenges
   +--- 443/tcp ----------> Traefik  (EasyPanel) -- HTTPS for hosted apps
   |
   +--- 3000/tcp ---------> EasyPanel dashboard
   |
   +--- 8080/tcp ---------> UISP HTTP  (redirects to 8443)
   +--- 8443/tcp ---------> UISP HTTPS -- web UI *and* device WebSocket
```

There is no conflict, because UISP was installed with non-default ports. That
is the single fact the whole arrangement rests on, and it is also the thing
most likely to be broken by accident later — see
[07-change-control.md](07-change-control.md#the-one-way-this-setup-gets-broken).

## Which stack owns what

| Concern | Owner | Not owned by |
| --- | --- | --- |
| Ports 80/443, TLS certificates for hosted apps | EasyPanel / Traefik | UISP |
| Deploying new applications | EasyPanel | UISP |
| Ports 8080/8443, UISP's own TLS | UISP's bundled nginx | Traefik |
| UISP container lifecycle and updates | `unms-cli` / UISP installer | EasyPanel |
| Network devices, CRM, billing | UISP/uCRM | EasyPanel |

## uCRM

uCRM is not a separate installation. In current UISP releases the CRM is a
module of UISP, served by the same containers on the same port, reached from
the UISP application switcher. Enabling it is a configuration step inside UISP,
not an install step — see [03-uisp-first-run.md](03-uisp-first-run.md).

## Ports that are *not* open, and shouldn't be until we need them

`81/tcp` (client suspension page), `8089/tcp` (CRM plugin/integration
endpoint), and `2055/udp` (NetFlow collector) are all associated with optional
UISP features. None of them are required to finish the initial setup. The
reasoning for each is in [06-firewall-policy.md](06-firewall-policy.md).
