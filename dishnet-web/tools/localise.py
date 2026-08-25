#!/usr/bin/env python3
"""Apply a country config to the site copy.

Three rules govern every edit here:

  1. Renaming a claim does not make it true. "Offices in 7 cities" describes
     South Sudan; on a Sudan site it is false, so it is removed, not translated.
  2. Company history stays factual. DishNet's record IS in South Sudan, and
     saying so is both honest and the strongest credential for a new market.
     Those sentences are protected from the country rename.
  3. Anything commercial that nobody has confirmed - prices, coverage, phone
     numbers, addresses - is reported, never guessed.

Run:  python3 tools/localise.py [--check]
"""
import json, os, re, sys, glob, html
from datetime import date

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.path.join(ROOT, 'site')
CFG  = json.load(open(os.path.join(ROOT, 'countries', 'sudan.json')))
DOMAIN = CFG['country']['site_domain']
CHECK = '--check' in sys.argv

# demo/ is 229 NGO/UN dashboards built on South Sudan state codes, and it is
# Disallow'd in robots.txt so it carries no search weight. Rebuilding it is a
# separate decision, so it is left alone rather than half-renamed.
FILES = [f for f in glob.glob(os.path.join(SITE, '**', '*.html'), recursive=True)
         if '/demo/' not in f]

# ── 1. Sentences that must keep "South Sudan": real company history ──────────
PROTECT = [
    "South Sudan's first Fiber-to-the-Home (FTTH) broadband service",
    "South Sudan's first Fiber-to-the-Home broadband",
    "Expanded operations to all 10 states of South Sudan",
    "in South Sudan, starting with VSAT satellite internet services",
    "South Sudan has some of the lowest internet penetration rates in the world",
    # The registered address is real and must stay real. "Juba, Sudan" is not a
    # place, and this text sits in the privacy policy and the terms.
    "Airport Road, Kololo Area, Tomping, Juba, South Sudan",
    "Airport Road, Kololo, Juba, South Sudan",
    "Juba, Central Equatoria — South Sudan",
    "courts of Juba, Central Equatoria State",
    "South Sudan's First FTTH",
    "South Sudan's first FTTH",
]

# Pages whose content is South Sudan-specific and whose Sudan equivalent is an
# open commercial question. They stay on the site but out of the index and out
# of the sitemap, so the new domain is not associated with Juba fibre or with
# South Sudan customers. Delete a line here to publish that page.
HOLD = {
  "fiber.html":      "fibre in Sudan undecided; page is Juba coverage and South Sudan prices",
  "coverage.html":   "coverage checker is Juba fibre areas",
  "testimonials.html":"named South Sudan customers",
  "blog-starlink-south-sudan.html": "post is South Sudan market analysis",
}

# ── 2. Claims that would be false about Sudan ────────────────────────────────
# Present-tense operational claims describing the South Sudan business.
CLAIMS = [
    # (pattern, replacement, why)
    (r"Headquartered in Juba with offices in 7 cities, we deliver enterprise-grade internet and technology services nationwide\.",
     "We deliver enterprise-grade internet and technology services across Sudan.",
     "offices in 7 cities is South Sudan"),
    (r"We offer professional installation across all 10 states, with offices in 7 cities\. ",
     "", "10 states / 7 cities are South Sudan"),
    (r"Local presence across 7 cities for faster support and installations\.",
     "Support and installation coordinated over WhatsApp, seven days a week.",
     "7 cities is South Sudan"),
    (r"Fast dispatch from our Juba warehouse to all 10 states\. No waiting for international shipping\.",
     "Equipment dispatched locally. No waiting for international shipping.",
     "Juba warehouse / 10 states are South Sudan"),
    (r"Starlink works everywhere in South Sudan\. 7 offices nationwide\.",
     "Starlink works anywhere in Sudan with a clear view of the sky.",
     "7 offices is South Sudan"),
    (r"Our headquarters is in Juba, with regional offices in Bor, Wau, Malakal, Bentiu, Rumbek, and Aweil\.",
     "DishNet has operated across South Sudan since 2013 and is now bringing that experience to Sudan.",
     "those are South Sudan offices"),
    (r"and install to all major towns in South Sudan including Bor, Wau, Malakal, Bentiu, Rumbek, and Aweil\. Delivery times outside Juba may vary\.",
     "and install across Sudan.", "South Sudan town list"),
    # Support copy: drop the city, keep the promise.
    (r"Our Juba-based support team is available", "Our support team is available", "city-bound"),
    (r"Juba-based team available 24/7", "Team available 24/7", "city-bound"),
    (r"Our team in Juba is on WhatsApp", "Our team is on WhatsApp", "city-bound"),
    (r"message our team in Juba on WhatsApp", "message our team on WhatsApp", "city-bound"),
    (r"8 AM to 8 PM Juba time", "8 AM to 8 PM local time", "city-bound"),
    (r"\(Juba load-shedding\)", "(load-shedding)", "city-bound"),
    (r"across Juba\.", "across Sudan.", "city-bound"),
    # Indexed pages: remove the city, keep the meaning. No Sudanese city is
    # substituted -- which one DishNet can install in is not established.
    (r"and install to all major towns in South Sudan including Bor, Wau, Malakal, Bentiu, Rumbek, and Aweil\. Delivery times outside Juba may vary\. ",
     "across Sudan. ", "South Sudan town list"),
    (r"Do you deliver outside Juba\?", "Where do you deliver?", "city-bound"),
    (r"We deliver and install to all major towns in South Sudan including Bor, Wau, Malakal, Bentiu, Rumbek, and Aweil\. Delivery times outside Juba may vary\.",
     "We deliver and install across Sudan.", "South Sudan town list"),
    (r"Starlink, fiber, and LTE internet with offices in Juba, Bor, Wau, Malakal, Bentiu, Rumbek, Aweil\.",
     "Starlink satellite internet for homes, businesses and organisations across Sudan.",
     "South Sudan office list"),
    (r"unlimited data for homes and businesses in Juba\.",
     "unlimited data for homes and businesses.", "city-bound"),
    (r"📍 Juba", "📍 Sudan", "job location"),
    (r"including Bor, Wau, Malakal, Bentiu, Rumbek, and Aweil\. Delivery times outside Juba may vary\. Contact us for details on your location\.",
     "\u002e Contact us for details on your location.", "South Sudan town list"),
    (r"Small homes and apartments in Juba", "Small homes and apartments", "city-bound"),
    (r"Homes and apartments in Juba", "Homes and apartments", "city-bound"),
    (r"Free Juba delivery", "Free delivery", "city-bound"),
    (r"Residential · Juba", "Residential", "city-bound"),
    (r"Residential &middot; Juba", "Residential", "city-bound"),
    (r"Starlink &middot; Juba", "Starlink", "city-bound"),
    (r"FTTH broadband in Juba", "FTTH broadband", "city-bound"),
    (r"24/7 support from Juba", "24/7 support", "city-bound"),
    (r"24/7 local support from Juba", "24/7 local support", "city-bound"),
    (r"from DishNet Africa Ltd in Juba\.", "from DishNet Africa Ltd in Sudan.", "city-bound"),
    (r", or by visiting our Juba office on Airport Road, Kololo Area", "", "no Sudan office to visit"),
    (r", phone, email, or visit our office in Juba\.", ", phone or email.", "no Sudan office to visit"),
    (r"available for on-site visits in Juba and all cities where we have offices",
     "available for on-site visits in the areas we serve", "South Sudan offices"),
    (r"select areas of Juba including Thongpiny, Hai Malakal, Kololo, and Juba Town Center",
     "select areas", "Juba fibre neighbourhoods"),
    (r"select areas of Juba", "select areas", "Juba fibre areas"),
    (r"support in Juba and nationwide", "support across Sudan", "city-bound"),
    (r"Airport Road, Kololo, Juba<", "Sudan<", "no Sudan address yet"),
    (r"Connecting South Sudan Since 2013",
     "12 Years of Connectivity Experience, Now in Sudan", "false as Sudan history"),
]

# ── 3. Straight renames ──────────────────────────────────────────────────────
RENAMES = [
    ("South Sudan", "Sudan"),          # also turns South Sudanese -> Sudanese
    ("https://dishnetafrica.com", f"https://{DOMAIN}"),
    ("crm.dishnetafrica.com", "crm.dishnetsudan.com"),   # live, valid cert
]

# Postal address in schema.org data: Google penalises a wrong address, so the
# South Sudan one is removed rather than relabelled SD. A real Sudan address,
# once there is one, is the single biggest local-search win available here.
SCHEMA_ADDR = [
    (r'"streetAddress":"Airport Road, Kololo Area, Tomping","addressLocality":"Juba","addressRegion":"Central Equatoria","addressCountry":"SS"',
     '"addressCountry":"SD"'),
    (r'"streetAddress":"Airport Road, Kololo Area, Tomping","addressLocality":"Juba","addressCountry":"SS"',
     '"addressCountry":"SD"'),
]

# ── 4. Left for the owner to decide - reported, never guessed ────────────────
REPORT = [
    ("Juba",                  "city / coverage claim"),
    (r"\+211",                "South Sudan phone number"),
    ("SSP",                   "South Sudan pound"),
    ("portal.dishnetss.com",  "no Sudan portal decided"),
    ("info@dishnetafrica.com","mailbox must exist before it is advertised"),
    ("Central Equatoria",     "South Sudan state"),
]

SEO_HEAD = """<meta name="robots" content="index,follow,max-image-preview:large">
<meta name="geo.region" content="SD">
<meta name="geo.placename" content="Sudan">
<link rel="alternate" hreflang="en" href="https://{d}{p}">
<link rel="alternate" hreflang="x-default" href="https://{d}{p}">
"""

def url_path(f):
    rel = os.path.relpath(f, SITE).replace(os.sep, '/')
    return '/' if rel == 'index.html' else '/' + rel

def localise(text, path):
    notes = []
    # protect true history
    for i, s in enumerate(PROTECT):
        text = text.replace(s, f"\x00P{i}\x00")
    for pat, rep, why in CLAIMS:
        new, n = re.subn(pat, rep, text)
        if n: notes.append(f"{n}x {why}"); text = new
    for pat, rep in SCHEMA_ADDR:
        new, n = re.subn(pat, rep, text)
        if n: notes.append(f"{n}x removed South Sudan postal address from schema"); text = new
    for a, b in RENAMES:
        text = text.replace(a, b)
    for i, s in enumerate(PROTECT):
        text = text.replace(f"\x00P{i}\x00", s)

    u = f"https://{DOMAIN}{url_path(path)}"
    text = re.sub(r'(<link rel="canonical" href=")[^"]*(")', lambda m: m.group(1)+u+m.group(2), text)
    text = re.sub(r'(<meta property="og:url" content=")[^"]*(")', lambda m: m.group(1)+u+m.group(2), text)
    text = re.sub(r'(<meta property="og:site_name" content=")[^"]*(")', r'\1DishNet Sudan\2', text)
    held = os.path.basename(path) in HOLD
    if held:
        text = re.sub(r'<meta name="robots"[^>]*>', '', text)
        text = text.replace('</head>',
            '<meta name="robots" content="noindex,follow">\n</head>', 1)
    if not held and 'name="robots"' not in text:
        blk = SEO_HEAD.format(d=DOMAIN, p=url_path(path))
        text = text.replace('</head>', blk + '</head>', 1)
    # keywords meta is ignored by Google and was on one page only
    text = re.sub(r'\s*<meta name="keywords"[^>]*>', '', text)
    return text, notes

def main():
    changed, all_notes, unresolved = 0, [], {}
    for f in sorted(FILES):
        src = open(f, encoding='utf-8').read()
        out, notes = localise(src, f)
        rel = os.path.relpath(f, SITE)
        if notes: all_notes.append((rel, notes))
        for pat, why in REPORT:
            n = len(re.findall(pat, out))
            if n: unresolved.setdefault(why, {}).setdefault(rel, 0)
            if n: unresolved[why][rel] += n
        if out != src:
            changed += 1
            if not CHECK: open(f, 'w', encoding='utf-8').write(out)

    if not CHECK:
        pages = [f for f in FILES if '/tutorials/' not in f]
        pri = {'index.html':'1.0','fiber.html':'0.9','coverage.html':'0.8','services.html':'0.8',
               'about.html':'0.7','contact.html':'0.7'}
        today = date.today().isoformat()
        rows = []
        for f in sorted(pages) + sorted(x for x in FILES if '/tutorials/' in x):
            p = url_path(f); base = os.path.basename(f)
            if base in HOLD: continue
            rows.append(f'  <url><loc>https://{DOMAIN}{p}</loc><lastmod>{today}</lastmod>'
                        f'<changefreq>monthly</changefreq><priority>{pri.get(base,"0.6")}</priority></url>')
        open(os.path.join(SITE,'sitemap.xml'),'w').write(
            '<?xml version="1.0" encoding="UTF-8"?>\n'
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
            + '\n'.join(rows) + '\n</urlset>\n')
        open(os.path.join(SITE,'robots.txt'),'w').write(
            f"User-agent: *\nAllow: /\nDisallow: /demo/\n\nSitemap: https://{DOMAIN}/sitemap.xml\n")

    print(f"{'would change' if CHECK else 'changed'}: {changed} of {len(FILES)} files\n")
    print("== claims rewritten rather than renamed ==")
    for rel, notes in all_notes:
        print(f"  {rel}: {'; '.join(notes)}")
    print("\n== held back from search (noindex + out of sitemap) ==")
    for f, why in sorted(HOLD.items()):
        print(f"  {f}: {why}")
    print("\n== still needs your decision ==")
    for why, files in sorted(unresolved.items()):
        tot = sum(files.values())
        top = ', '.join(f"{k}({v})" for k, v in sorted(files.items(), key=lambda x:-x[1])[:4])
        print(f"  {why}: {tot} across {len(files)} files — {top}")

main()
