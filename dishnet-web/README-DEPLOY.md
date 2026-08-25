# DishNet Sudan — website deployment

Static site, served by nginx, behind the Traefik that already runs on this
server. No database, no runtime, no build step.

## What this is, and is not

This deploys the **existing site, unchanged**, to `dishnetsudan.com` so hosting
is proven before any content work starts. **It still says South Sudan
throughout.** That is deliberate — one problem at a time.

The South Sudan site is untouched. This is a separate copy in `site/`, and
`dishnetafrica.com` is not affected by anything here.

## Deploy

In EasyPanel: **+ Service → App**

| Field | Value |
| --- | --- |
| Name | `dishnet-web-sudan` |
| Source | Upload this folder, or point at the repo |
| Build | Dockerfile |
| Port | `8080` |
| Domain | `dishnetsudan.com` |

EasyPanel writes the Traefik route and requests the certificate, the same way
it did for Evolution. Add `www.dishnetsudan.com` as a second domain if you want
it to resolve.

DNS is already correct: `dishnetsudan.com` → `178.62.83.12`.

### Verify

```bash
curl -sI https://dishnetsudan.com | head -1
echo | openssl s_client -connect dishnetsudan.com:443 -servername dishnetsudan.com 2>/dev/null \
  | openssl x509 -noout -issuer

# every converted redirect — all should say 301
for p in /faqs /about-us /contact-us /services /fiber /terms-of-use \
         /device /device/1/view /device/2/view /device/4/view /device/6/view \
         /business /application /demo /demo/un/sites; do
  printf '%-22s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}' "https://dishnetsudan.com$p")"
done
```

## The `_redirects` conversion

The source was built for Netlify. Its `_redirects` file does nothing under
nginx, so all 18 rules are reproduced in `nginx.conf` — verified one by one
against the original. Without that, `/faqs`, `/about-us`, `/contact-us`,
`/device/1/view` and ten others would 404 on day one.

**One rule is worth revisiting.** Netlify's last line is `/* /index.html 200`:
any unknown URL serves the homepage with a **200**, not a 404. Search engines
read that as thousands of duplicate pages, and a visitor who mistypes gets the
homepage with no explanation. It is reproduced faithfully for now so behaviour
does not change under you, but a real 404 page would be better. Say the word.

## Performance

`nginx.conf` enables gzip on everything textual, caches assets for 30 days and
pages for 10 minutes, and serves the APK with the MIME type Android needs.

Two things it cannot fix, both of which matter on Sudanese connections:

**536 Google Fonts references.** Every page blocks on `fonts.googleapis.com`
before it renders. Self-hosting the two families removes a third-party
round-trip from every visit.

**Six hotlinked images**, all from `dishnetafrica.com` and
`portal.dishnetss.com`. The Sudan site currently depends on South Sudan
infrastructure being up. They should be copied in.

Neither is hard. Both are worth doing before launch rather than after.

## Multi-country

`countries/*.json` holds every value that differs between markets — names,
numbers, domains, coverage, currency, products. `sudan.json` is the working
file; `south-sudan.json` records the existing site's values as a reference.

The site's HTML is not yet tokenised, so this is groundwork, not a working
generator. But it means the localisation phase has a single place to record
decisions, and adding Uganda later is a third JSON file rather than a third
site.

## Before this becomes the real Sudan site

`sudan.json` marks **18 decisions** that are yours, not mine. The ones that
carry commercial or legal risk:

- **Currency.** Are the sales prices USD or SDG? Quoted in 158 places.
- **Coverage.** Which Sudanese cities can you genuinely install in? The site
  makes coverage claims in 222 places, and a wrong one produces a refund.
- **Terminal charge.** Monthly or one-off? It is folded into the monthly total
  in the spreadsheet; if it is one-off, that price is wrong.
- **Fibre and LTE.** Do you sell either in Sudan? `fiber.html` is 49 KB of
  South Sudan fibre content naming a supplier and per-plan prices.
- **Phone numbers.** The paired sales WhatsApp is `+211…`, which reads as South
  Sudan to a Sudanese customer.
- **Testimonials.** Named South Sudan customers. Selling in Sudan with those
  would be misleading.

Never published, in any form: the Starlink subscription cost, the terminal
charge, and the DishNet selling price from the spreadsheet. Those are margin.
Only the Sudan selling price is customer-facing.
