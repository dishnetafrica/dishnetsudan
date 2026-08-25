# Firewall policy

You asked not to change firewall rules unnecessarily, and to work out which
ports are actually needed first. This is that analysis. **It proposes no
changes yet** — the "verify first" column is what the inspection report answers.

## Two layers, both must allow

A port is only reachable if the **DigitalOcean Cloud Firewall** *and* **UFW**
both permit it. The DO firewall is not visible from inside the droplet, so
`ufw status` showing a port open does not mean it is reachable. Check both.

## Required now

| Port | For | Notes |
| --- | --- | --- |
| `22/tcp` | SSH | Restrict the source to known addresses if practical |
| `80/tcp` | Traefik | Needed for Let's Encrypt HTTP-01 and HTTP→HTTPS redirects |
| `443/tcp` | Traefik | Hosted apps, and the `uisp.` browser route from `05` |
| `8443/tcp` | UISP HTTPS | **Web UI and device WebSocket. The critical one.** Never close it |

## Required now, closeable later

| Port | For | Notes |
| --- | --- | --- |
| `3000/tcp` | EasyPanel dashboard | Plain HTTP on a public IP. Close it once the panel domain works (`04` step 2). This is the biggest easy win available |
| `8080/tcp` | UISP HTTP | Redirects to `8443`. Convenience, not a requirement. Closing it is low-risk but test device adoption afterwards before assuming so |

## Do not open yet

| Port | For | Verify first | Open when |
| --- | --- | --- | --- |
| `81/tcp` | uCRM client suspension page | Is anything listening on 81? | You actually enable suspension in uCRM. Suspended clients need to reach it, so scope it to client networks, not the whole internet |
| `2055/udp` | NetFlow collector | Is anything listening on 2055? | You enable NetFlow. Restrict the source to the exporting devices — a public UDP collector is an amplification target, and NetFlow is the UISP feature most able to fill the disk |
| `8089/tcp` | UISP CRM plugins/integrations | Is anything listening on 8089? | Only if inspection shows a listener **and** we identify a specific external caller that must reach it. Plugins generally run server-side and need no public port |

Three things follow from this table:

- Opening a port with nothing behind it adds attack surface and zero function.
- Every one of these belongs to a feature not enabled yet. Open each one when
  you turn its feature on, in the same change, so cause and effect stay linked.
- Payment gateway webhooks, if you use a gateway that has them, arrive on the
  main HTTPS path — not on any of these. No extra port is needed for billing.

## Proposed sequence

Nothing here is applied until the inspection report is in hand.

1. Confirm the current rules on both layers.
2. Confirm `80, 443, 8443, 22` are allowed. Add only what is genuinely missing.
3. After the panel domain works: close `3000` on both layers. Verify EasyPanel
   is still reachable at `panel.yourdomain.com` **before** logging out.
4. Leave `81`, `8089` and `2055` closed.

## Rollback

Take a snapshot before changing anything:

```bash
sudo ufw status numbered > /root/ufw-before-$(date -u +%Y%m%dT%H%M%SZ).txt
```

Rollback is re-adding the rule you deleted. Two cautions:

- **Locking yourself out.** Keep an open SSH session while changing rules, and
  confirm a second session works before closing the first. On DigitalOcean the
  droplet console is the recovery path if that fails.
- **Never `ufw reset`.** It drops every rule including SSH, and Docker's own
  iptables chains interact with UFW in ways that are awkward to reconstruct.
  Remove rules individually.
