# UISP first-run and CRM enablement

Nothing here installs or reinstalls anything. These are configuration steps
inside an already-running UISP.

> The exact menu labels depend on the UISP version. Confirm the version from
> the inspection report (`UISP image versions`) and treat the wording below as
> a guide to the right area of the UI, not a literal script.

## 1. Reach the UI

Open `https://178.62.83.12:8443`.

The browser will warn about the certificate. That is expected and correct:
UISP is presenting its own self-signed certificate, because Traefik's
Let's Encrypt certificates belong to EasyPanel and do not cover UISP. Accept
the warning and continue. We fix this properly with a real hostname and
certificate in [05-domain-and-tls-plan.md](05-domain-and-tls-plan.md) — do not
try to fix it before then.

## 2. Create the administrator account

The wizard asks for a username, email and password on first run.

- Use a real, monitored mailbox. Password resets and UISP's own notifications
  go there, and it is awkward to change later.
- Store the password in whatever password manager the team uses **before**
  submitting the form.
- If the wizard has already been completed and the credentials are lost, the
  recovery path is `unms-cli set-superadmin` on the host, not a reinstall.

## 3. Set the UISP hostname

In Settings, there is a hostname/URL field that seeds the connection string
devices are given.

**Leave this as `178.62.83.12` for now.** Change it only as part of the domain
migration in `05`, and only after DNS resolves, because changing it rewrites
the connection string that adopted devices use to call home.

## 4. Enable the CRM

The CRM lives behind the application switcher in the UISP header. Opening it
the first time starts a CRM setup wizard covering organisation details,
currency, tax rates, invoicing defaults and the first service plans.

Two of those are genuinely hard to change afterwards, so decide them before
you start clicking:

- **Currency.** uCRM sets the organisation currency at setup. Changing it later
  once invoices exist is disruptive.
- **Invoicing period and numbering.** Sequence and format are awkward to alter
  once real invoices have been issued.

Everything else — service plans, tax rates, payment methods — can be added and
edited freely afterwards.

## 5. What *not* to do during setup

- Do not enable **client suspension** yet. Suspension redirects suspended
  clients to a page served on port `81`, which is currently closed. Turning it
  on before the port is open and tested gives suspended clients a timeout
  instead of a payment page. See [06-firewall-policy.md](06-firewall-policy.md).
- Do not enable **NetFlow** yet. It needs `2055/udp` and it is the one UISP
  feature that can meaningfully grow disk usage. Enable it deliberately, later,
  when you know which devices will export to it.
- Do not point UISP's built-in Let's Encrypt at a domain. It needs port `80`
  for the HTTP-01 challenge, and port `80` belongs to Traefik. This is covered
  in `05`; attempting it will fail and may leave UISP in a half-configured TLS
  state.

## 6. Verify

```bash
sudo ./scripts/verify-uisp-health.sh
```

Then adopt one test device and confirm it comes online, before adopting the
rest. Device adoption is the function most sensitive to the port layout, so it
is the check that actually proves the setup works.

## Managing UISP from the host

`unms-cli` is the supported entry point. Use it rather than `docker` commands
against individual containers, and never re-run the UISP installer as a way of
fixing something — see
[07-change-control.md](07-change-control.md#the-one-way-this-setup-gets-broken).

```bash
unms-cli --help        # the authoritative list for your version
unms-cli update        # the supported update path; preserves configured ports
unms-cli restart
unms-cli set-superadmin
```
