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
    "South Sudan has some of the lowest internet penetration rates in the world",
    # The registered address is real and must stay real. "Juba, Sudan" is not a
    # place, and this text sits in the privacy policy and the terms.
    "Airport Road, Kololo Area, Tomping, Juba, South Sudan",
    "courts of Juba, Central Equatoria State",
    "South Sudan's First FTTH",
    "South Sudan's first FTTH",
    # Image URLs must NOT follow the domain rename. These files live in the
    # South Sudan CMS; rewritten to dishnetsudan.com they 404, because that
    # path does not exist on the new domain. tools/fetch-images.sh replaces
    # them with local copies, which also drops the external dependency.
    "https://dishnetafrica.com/admin/public/uploads",
    "https://dishnetafrica.com/public/uploads",
]

# Pages whose content is South Sudan-specific and whose Sudan equivalent is an
# open commercial question. They stay on the site but out of the index and out
# of the sitemap, so the new domain is not associated with Juba fibre or with
# South Sudan customers. Delete a line here to publish that page.
HOLD = {
  "fiber.html":      "fibre in Sudan undecided; page is Juba coverage and South Sudan prices",
  "testimonials.html":"named South Sudan customers",
  "gallery.html":    "photographs of South Sudan installations",
  "404.html":        "error page - served with 404 status, never indexed, never in the sitemap",
  "blog-starlink-south-sudan.html": "post is South Sudan market analysis",
  # Ported from the Uganda build. Each describes a service or a payment rail
  # that nobody has confirmed for Sudan.
  "pay.html":      "runs on MTN MoMo and Airtel Money, neither of which operates in Sudan",
  "hotspot.html":  "hotspot business service not confirmed for Sudan",
  "security.html": "CCTV and firewall service not confirmed for Sudan",
  "reseller.html": "reseller programme implies a coverage footprint not established",
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
    # Experience wording: East Africa, as on the Uganda site. The history is
    # true, but presenting it to Sudanese customers as South Sudan credentials
    # is a commercial problem -- and two lines cannot survive a relabel:
    # "South Sudan's first FTTH" and "all 10 states" are false under any other
    # country name, so the superlative and the specifics go, not just the name.
    (r"Established as a licensed ISP in South Sudan, starting with VSAT satellite internet services for businesses and NGOs in Juba\.",
     "Established as a licensed ISP in East Africa, starting with VSAT satellite internet services for businesses and NGOs.",
     "experience wording: East Africa"),
    (r"Expanded operations to all 10 states of South Sudan\. ",
     "Expanded to nationwide operations in our first East African market. ",
     "experience wording: East Africa"),
    (r"Began deploying fiber-optic infrastructure in Juba\. Launched South Sudan's first Fiber-to-the-Home \(FTTH\) broadband service",
     "Began deploying fiber-optic infrastructure and launched a Fiber-to-the-Home (FTTH) broadband service",
     "experience wording: first-claim removed"),
    (r"Juba, Central Equatoria — South Sudan",
     "Regional head office — Juba, East Africa",
     "head office labelled as regional"),
    (r"Head office: Airport Road, Kololo, Juba, South Sudan\.",
     "Regional head office: Airport Road, Kololo, Juba.",
     "head office labelled as regional"),
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

# ── 2b. Pages ported from the Uganda build ──────────────────────────────────
UGANDA = [
    # Ugandan cities go the same way Juba did: removed, not swapped for a
    # Sudanese one, because which cities DishNet can serve is not established.
    (r'"addressLocality": "Kampala", "addressCountry": "UG"', '"addressCountry": "SD"',
     "Kampala postal address"),
    (r'"areaServed":\s*\[[^\]]*\]', '"areaServed": "SD"', "Ugandan city list"),
    (r"than send a truck from Kampala", "than dispatch from a central depot", "city-bound"),
    (r"Kampala, Entebbe, Wakiso, Mukono[^<\"]*", "across Sudan", "Ugandan city list"),
    (r"\bKampala\b", "Sudan", "city-bound"),
    # The Uganda build ships with unreplaced placeholders. Carrying a fake
    # number onto a live site is worse than carrying none.
    (r"\+256 700 000 000", "", "Uganda placeholder number"),
    (r"256700000000", "", "Uganda placeholder number"),
    (r"uganda@dishnetafrica\.com", "info@dishnetafrica.com", "placeholder mailbox"),
    (r"https://uganda\.dishnetafrica\.com", "https://dishnetsudan.com", "domain"),
    # "Ugandans" first: a \b rule on "Ugandan" would leave the plural behind.
    # The logo strap is uppercase, which a rule on "Uganda" never matched.
    # It sat on 34 live pages, right under the DishNet logo.
    (r"\bUGANDA\b", "SUDAN", "uppercase country strap"),
    (r"\bUgandans\b", "Sudanese", "country"),
    (r"\bUgandan\b", "Sudanese", "country"),
    # Payment rails, on a page that does get published. MTN and Airtel Money
    # do not operate in Sudan, and naming a rail nobody can use costs a payment.
    (r"MTN MoMo and Airtel Money for everything", "One account for everything", "Ugandan payment rail"),
    (r"Mobile Money", "mobile payment", "Ugandan payment rail"),
    # URL-encoded text has no word boundary after %20, so \bUganda\b misses it.
    # Footer strapline on every ported page. It sells fibre, WiFi zones and
    # CCTV -- all held back here -- so it is trimmed to what is confirmed.
    (r"Connecting and protecting Sudan — Starlink, fiber, managed WiFi zones, CCTV and network security with professional installation",
     "Connecting Sudan — Starlink satellite internet with professional installation",
     "footer sold held-back services"),
    # "in every region" is a nationwide installation claim, which is exactly
    # what has not been established.
    (r"with professional installation in every region\.",
     "with professional installation.", "unverified nationwide claim"),
    (r"%20Uganda", "%20Sudan", "encoded country"),
    (r"\bUganda\b", "Sudan", "country"),
]

# ── 2c. WhatsApp and login wiring ────────────────────────────────────────────
# Every conversation CTA goes to the number the AI actually answers
# (dishnet_sales, 211924332000). The site was split three ways: the South
# Sudan office number, the AI number nowhere, and 74 links stripped down to
# a bare wa.me/ that opens an error. One number, everywhere, changed in one
# place when a +249 number exists.
SALES_WA = "249900083481"   # the Sudanese number — sales, support and accounts in one
WIRING = [
    (r'href="https://wa\.me/"', f'href="https://wa.me/{SALES_WA}"', "empty wa.me link"),
    (r'wa\.me/\?text=', f'wa.me/{SALES_WA}?text=', "empty wa.me link"),
    # Migration from the interim +211 AI number to the Sudanese line.
    (r'wa\.me/211924332000', f'wa.me/{SALES_WA}', "interim +211 AI number"),
    (r'phone=\+?211924332000', f'phone={SALES_WA}', "interim number in form JS"),
    (r'\+211 924 332 000', '+249 900 083 481', "displayed interim number"),
    (r'211924332000', SALES_WA, "remaining interim-number references"),
    (r'wa\.me/211923400000', f'wa.me/{SALES_WA}', "wa.me to South Sudan office"),
    # A third number, found by the commercial regression check: the South
    # Sudan support line, on the app page and one tutorial.
    (r'wa\.me/211921443002', f'wa.me/{SALES_WA}', "wa.me to South Sudan support line"),
    (r'\+211 921 443 002', '+249 900 083 481', "displayed support number"),
    (r'211921443002', SALES_WA, "remaining support-number references"),
    (r'phone=\+?211923400000', f'phone={SALES_WA}', "form JS to South Sudan office"),
    (r'\+211 923 400 000', '+249 900 083 481', "displayed number matches the link"),
    (r'211923400000', SALES_WA, "remaining old-number references"),
    # Customer Login pointed at the SOUTH SUDAN plugin's portal path on the
    # Sudan CRM — a plugin that is not installed there, so every click 404'd.
    (r'https://crm\.dishnetsudan\.com/crm/_plugins/dishnet-hybrid-telecom/public\.php\?page=customer_portal',
     'https://crm.dishnetsudan.com/', "login to nonexistent plugin path"),
]

# ── 2d. Prices that belonged to South Sudan ─────────────────────────────────
# The Sudan lineup is the five Priority plans from the 25 Aug sales sheet, in
# USD. Hardware and installation fees have no confirmed Sudan price yet, so
# the numbers come out and the service stays.
PRICES = [
    (r"Residential \(\$80/mo unlimited\), Priority plans from \$112&ndash;\$336/mo with faster peak speeds, and Roam \(\$65/mo for 50GB mobile use\)\. All plans include unlimited standard data\.",
     "Starlink Priority plans: 500GB at $112, 1TB at $189, 2TB at $336, 3TB at $483 and 5TB at $784 per month (USD). Every plan includes unlimited standard data after the priority allowance.",
     "old plan lineup in FAQ"),
    (r"Residential \(\$80/mo unlimited\), Priority plans from \$112\S{0,8}\$336/mo with faster peak speeds, and Roam \(\$65/mo for 50GB mobile use\)\. All plans include unlimited standard data\.",
     "Starlink Priority plans: 500GB at $112, 1TB at $189, 2TB at $336, 3TB at $483 and 5TB at $784 per month (USD). Every plan includes unlimited standard data after the priority allowance.",
     "old plan lineup in FAQ"),
    ("For most homes: the Mini or the V4 Standard\\..*?ask us for today's price\\.",
     "Starlink currently sells two consumer kits, and we stock both. The Standard is the kit for homes and offices — current-generation kickstand dish, WiFi 6 router, sealed against dust. The Mini is the size of a laptop, runs on 25–40 W and travels in a backpack — ideal for field teams and anyone on the move. Performance kits for large organisations are available on request. Kit pricing changes with supply, so ask us for today's price.",
     "kit lineup updated to current generation"),
    ("Kit\\ pricing\\ changes\\ with\\ supply,\\ so\\ ask\\ us\\ for\\ today's\\ price\\.",
     'The Standard kit is $600 and the Mini is $350, one-time; professional installation is $50 one-time. Your monthly plan is charged separately.',
     "hardware prices published from uCRM"),
    # Claims we cannot document are not published. "Official reseller" and
    # "certified" survive only when the paperwork exists; until then the site
    # says what is verifiably true: we sell and install Starlink in Sudan.
    # "Official Starlink datasheets" stays -- those ARE Starlink's documents.
    (r"the official Starlink reseller for Sudan",
     "a Starlink supplier and installer for Sudan", "unsupported reseller claim"),
    (r"we are the official Starlink reseller and installer for Sudan",
     "we supply and install Starlink across Sudan", "unsupported reseller claim"),
    (r"Became an official Starlink reseller and installer for Sudan",
     "Began supplying and installing Starlink", "unsupported reseller claim"),
    (r"Official Starlink Reseller — Sudan",
     "Starlink Sales &amp; Installation — Sudan", "unsupported reseller claim"),
    (r"Official Starlink reseller and installer for Sudan",
     "Starlink supply and professional installation for Sudan", "unsupported reseller claim"),
    (r"Official Starlink reseller and ISP for Sudan\. Fiber, Starlink, and LTE internet services\.",
     "Starlink internet supply and professional installation across Sudan.", "unsupported reseller claim in schema"),
    (r"certified Starlink installation, mobile money billing",
     "professional Starlink installation, straightforward billing", "unsupported certification + payment claim"),
    (r"[Cc]ertified technicians", "our technicians", "unsupported certification claim"),
    (r"Certified installation", "Professional installation", "unsupported certification claim"),
    (r"For most homes: the Mini Kit \(\$299\) or V4 Standard \(\$550\)\. For businesses needing maximum performance: the Flat High Performance \(\$2,600\)\. The Standard Actuated \(\$650\) is great for permanent outdoor installations\.",
     "Starlink currently sells two consumer kits, and we stock both. The Standard is the kit for homes and offices \u2014 current-generation kickstand dish, WiFi 6 router, sealed against dust. The Mini is the size of a laptop, runs on 25\u201340 W and travels in a backpack \u2014 ideal for field teams and anyone on the move. Performance kits for large organisations are available on request. Kit pricing changes with supply, so ask us for today's price.",
     "South Sudan hardware prices in FAQ"),
    (r"we offer professional installation \(\$50\) to ensure",
     "we offer professional installation to ensure", "unconfirmed install fee"),
    (r"DishNet offers professional installation for \$50\.",
     "DishNet offers professional installation.", "unconfirmed install fee"),
    (r"Plans from \$65/mo", "Plans from \$142/mo", "old starting price"),
    (r"quick answers about Starlink, fiber, billing", "quick answers about Starlink, billing", "fibre in support scope"),
    # The country rename turned "South Sudanese Pounds (SSP)" into "Sudanese
    # Pounds (SSP)" -- but SSP is South Sudan's currency; Sudan's is SDG, and
    # whether local-currency payment is accepted has not been decided. Claim
    # nothing beyond the USD pricing.
    (r"Can I pay in Sudanese Pounds \(SSP\)\?", "Can I pay in Sudanese pounds?", "wrong currency"),
    (r"Yes\. We accept payments in both USD and SSP at the current exchange rate\. Payment methods include cash, bank transfer, and mobile money\.",
     "Our plans are priced in US dollars. Message us on WhatsApp to ask about paying in Sudanese pounds and which payment methods are available in your city.",
     "unconfirmed currency and payment methods"),
    (r"Pay in USD or SSP\. We accept cash, bank transfer, and mobile money\.",
     "Plans are priced in USD. Ask us on WhatsApp about local payment options.",
     "unconfirmed currency and payment methods"),
    (r"All prices are quoted in US Dollars \(USD\)\. Sudanese Pound \(SSP\) equivalents[^<]*",
     "All prices are quoted in US Dollars (USD). ",
     "wrong currency in terms"),
    (r"Yes\. We accept payments in both USD and SSP\. The SSP equivalent is calculated at the current exchange rate\. We accept cash, bank transfer, and mobile money\.",
     "Our plans are priced in US dollars. Message us on WhatsApp to ask about paying in Sudanese pounds and which payment methods are available in your city.",
     "unconfirmed currency and payment methods"),
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
    # The bare CRM root lands on the UISP admin console, not the customer
    # portal. Customers must never be sent there.
    ("https://crm.dishnetsudan.com/\"", "login points at the UISP admin console"),
    ("info@dishnetafrica.com","mailbox must exist before it is advertised"),
    ("Central Equatoria",     "South Sudan state"),
    ("MTN|Airtel",            "Ugandan payment rail"),
    (r"\bUganda",             "Uganda reference left over"),
]

# One LocalBusiness block, on every content page. The description names only
# what is confirmed for Sudan -- the Uganda original advertised fibre, CCTV and
# WiFi zones, all of which are held back here, and telephone was left empty,
# which is worse than absent.
LOCALBUSINESS = """<script type="application/ld+json">
{"@context":"https://schema.org","@type":"LocalBusiness",
"name":"DishNet Africa Ltd \u2014 Sudan","url":"https://{d}/",
"image":"https://portal.dishnetss.com/uploads/images/1727669803.png",
"email":"info@dishnetafrica.com",
"address":{"@type":"PostalAddress","addressCountry":"SD"},
"areaServed":"SD",
"description":"Starlink satellite internet for homes, businesses and organisations across Sudan."}
</script>
"""

SEO_HEAD = """<meta name="robots" content="index,follow,max-image-preview:large">
<meta name="geo.region" content="SD">
<meta name="geo.placename" content="Sudan">
<link rel="alternate" hreflang="en" href="https://{d}{p}">
<link rel="alternate" hreflang="x-default" href="https://{d}{p}">
"""

import hashlib as _hl
def _bust(path):
    try:
        return _hl.md5(open(path, "rb").read()).hexdigest()[:8]
    except OSError:
        return "0"
_VERS = None
def cache_bust(text):
    """cache-bust stylesheets: 30-day asset caching plus changed CSS under an
    unchanged filename served stale styles to returning visitors (25 Aug).
    Content-hash query strings end that class of bug permanently."""
    global _VERS
    if _VERS is None:
        _VERS = {"styles.css": _bust("site/styles.css"),
                 "tutorial-shared.css": _bust("site/tutorials/tutorial-shared.css"),
                 "fonts.css": _bust("site/assets/fonts/fonts.css")}
    for name, v in _VERS.items():
        text = re.sub(r'href="([^"]*' + re.escape(name) + r')(\?v=[0-9a-f]*)?"',
                      r'href="\1?v=' + v + '"', text)
    return text

def rebuild_faq_schema(text):
    items = re.findall(
        r'<div class="faq-item"><button[^>]*>(.*?)</button><div class="faq-a"><div class="faq-a-inner">(.*?)</div></div></div>',
        text, re.S)
    if not items:
        return text, False
    qa = []
    for q, a in items:
        q = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', q)).strip()
        a = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', a)).strip()
        qa.append({"@type": "Question", "name": q,
                   "acceptedAnswer": {"@type": "Answer", "text": a}})
    block = ('<script type="application/ld+json">'
             + json.dumps({"@context": "https://schema.org", "@type": "FAQPage",
                           "mainEntity": qa}, ensure_ascii=False)
             + '</script>')
    new = re.sub(r'<script type="application/ld\+json">[^<]*"@type":\s*"FAQPage".*?</script>',
                 lambda m: block, text, count=1, flags=re.S)
    return new, new != text

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
    # No fibre in Sudan, so every fibre OFFER goes: the homepage section, the
    # nav entries, the plan CTAs, the product lists. Comparative mentions stay
    # -- "Starlink reaches where fibre does not" is a reason to buy Starlink.
    new, n = re.subn(r'<!-- Fiber CTA -->.*?(?=<!-- How to Order -->)', '', text, flags=re.S)
    if n: notes.append("removed homepage fibre section"); text = new
    new, n = re.subn(r'<a[^>]*href="[^"]*(fiber\.html|blog-fiber-vs-starlink\.html)[^"]*"[^>]*>.*?</a>', '', text, flags=re.S)
    if n: notes.append(f"{n}x removed fibre link"); text = new
    new, n = re.subn(r'<a[^>]*>[^<]*Request Fiber in My Area[^<]*</a>', '', text)
    if n: notes.append("removed fibre request CTA"); text = new
    for a, b in [
        ("Starlink satellite internet, fiber, and LTE connectivity", "Starlink satellite internet"),
        ("Buy Starlink kits, fiber internet, and LTE services", "Buy Starlink kits and professional installation"),
        ("Buy Starlink kits, fiber broadband from $50/mo, and LTE services.", "Buy Starlink kits with professional installation."),
        ("Starlink kits, fiber internet, and LTE services", "Starlink kits and professional installation"),
        ("Starlink, fiber, and LTE internet", "Starlink satellite internet"),
        ("from a $50/month fiber plan in Juba to a portable Starlink Mini for field operations in Jonglei",
         "from a fixed rooftop install to a portable Starlink Mini for field operations"),
    ]:
        if a in text: text = text.replace(a, b); notes.append("trimmed fibre from a product list")

    # Fibre service card, and any FAQ entry that quotes a fibre price. Both are
    # fixed structures, so they come out whole rather than leaving orphan markup.
    new, n = re.subn(r'<!-- Fiber -->.*?(?=\n\s*<!-- )', '', text, flags=re.S)
    if n: notes.append("removed fibre service card"); text = new
    def drop_fiber_faq(m):
        return '' if re.search(r'[Ff]iber', m.group(0)) else m.group(0)
    new, n2 = re.subn(
        r'<div class="faq-item"><button[^>]*>.*?</button><div class="faq-a"><div class="faq-a-inner">.*?</div></div></div>',
        drop_fiber_faq, text, flags=re.S)
    if new != text: notes.append("removed fibre FAQ entries"); text = new

    # Nav entry for the coverage page, which indexes the 26 city pages. Without
    # it they are reachable only from the sitemap, which is not a real link.
    if 'href="coverage.html"' not in text and 'href="../coverage.html"' not in text:
        up = '../' if '/tutorials/' in path else ''
        text = re.sub(r'(<a href="' + up + r'tutorials/index\.html">Tutorials</a>)',
                      r'<a href="' + up + r'coverage.html">Coverage</a>\1', text, count=1)

    # Held pages are noindexed; leaving them in the navigation defeats that.
    if os.path.basename(path) not in HOLD:
        new, n = re.subn(r'<a[^>]*href="[^"]*\b(security|hotspot|pay|reseller)\.html"[^>]*>.*?</a>',
                         '', text, flags=re.S)
        if n: notes.append(f"{n}x removed nav link to a held page"); text = new

    # The Uganda shell calls toggleFaq() but never defines it, so the FAQ
    # accordions on the coverage and city pages were dead buttons.
    if 'toggleFaq(' in text and 'function toggleFaq' not in text:
        text = text.replace('</body>', """<script>
function toggleFaq(btn) {
  const item = btn.parentElement;
  const wasOpen = item.classList.contains('open');
  document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('open'));
  if (!wasOpen) item.classList.add('open');
}
</script>
</body>""", 1)
        notes.append("injected missing toggleFaq")

    # Stripping held-page links leaves empty <li></li> holes in footers.
    new, n = re.subn(r'\s*<li>\s*</li>', '', text)
    if n: text = new

    # portal-preview.html existed only to showcase the demo tenants, which are
    # gone with demo/. Its "Live Demo" nav entry sits on 41 pages.
    new, n = re.subn(r'<a[^>]*href="[^"]*portal-preview[^"]*"[^>]*>.*?</a>', '', text, flags=re.S)
    if n: notes.append(f"{n}x removed Live Demo nav link"); text = new
    for pat, rep, why in PRICES:
        new, n = re.subn(pat, rep, text)
        if n: notes.append(f"{n}x {why}"); text = new
    for pat, rep, why in WIRING:
        new, n = re.subn(pat, rep, text)
        if n: notes.append(f"{n}x {why}"); text = new
    for pat, rep, why in UGANDA:
        new, n = re.subn(pat, rep, text)
        if n: notes.append(f"{n}x {why}"); text = new
    for pat, rep in SCHEMA_ADDR:
        new, n = re.subn(pat, rep, text)
        if n: notes.append(f"{n}x removed South Sudan postal address from schema"); text = new
    for a, b in RENAMES:
        text = text.replace(a, b)
    for i, s in enumerate(PROTECT):
        text = text.replace(f"\x00P{i}\x00", s)

    # self-contained: no remote images on published pages. Runs AFTER the
    # protect-restore so the upload URLs are visible to it; gallery.html is
    # held (noindexed South Sudan photos awaiting real Sudan ones) and its
    # src attributes are left alone until those photos exist.
    text, n = re.subn(r'<img src="https://portal\.dishnetss\.com/uploads/images/1727669803\.png"[^>]*>',
                      '<span class="logo-text">DishNet<small>SUDAN</small></span>', text)
    if n: notes.append(f"{n}x remote logo -> text")
    text, n = re.subn(r'content="https://(?:portal\.dishnetss\.com|dishnetafrica\.com)/[^"]*\.(?:png|jpe?g|avif|webp)"',
                      'content="https://dishnetsudan.com/assets/img/og-dishnet.png"', text)
    if n: notes.append(f"{n}x og image -> own")
    text, n = re.subn(r'"(logo|image)":"https://(?:portal\.dishnetss\.com|dishnetafrica\.com)/[^"]*"',
                      r'"\1":"https://dishnetsudan.com/assets/img/og-dishnet.png"', text)
    if n: notes.append(f"{n}x schema image -> own")
    text, n = re.subn(r'<link rel="(icon|apple-touch-icon|shortcut icon)"[^>]*href="https://(?:portal\.dishnetss\.com|dishnetafrica\.com)[^"]*"[^>]*>',
                      '<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">'
                      '<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">', text)
    if n: notes.append(f"{n}x favicon -> own")


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
    # Normalise a ported block that carried an empty telephone or Uganda's
    # service list, then add one to any content page that has none.
    text = text.replace('"telephone": "",', '').replace('"telephone":"",', '')
    text = text.replace(
        "Starlink satellite internet, fiber, managed WiFi zones, CCTV and network security across Sudan.",
        "Starlink satellite internet for homes, businesses and organisations across Sudan.")
    if 'LocalBusiness' not in text and '/tutorials/' not in path:
        text = text.replace('</head>', LOCALBUSINESS.replace('{d}', DOMAIN) + '</head>', 1)

    text = cache_bust(text)
    # logo-text into inline styles: index and the other original pages carry
    # their whole stylesheet inline and never link styles.css, so a rule added
    # only to the external file never reaches them -- which is exactly how the
    # brand wordmark rendered as plain dark text on the live homepage.
    if '.logo-text' not in text and 'logo-text' in text and '</style>' in text:
        text = text.replace('</style>',
            '.logo-text{font-family:var(--font-display),sans-serif;font-weight:800;'
            'font-size:26px;color:#C8102E;letter-spacing:-1px;line-height:1;'
            'display:inline-flex;align-items:baseline;}'
            '.logo-text small{font-size:10px;letter-spacing:.22em;font-weight:700;'
            'color:var(--text-secondary);margin-left:8px;}\n</style>', 1)


    if os.path.basename(path) == 'faq.html':
        text, did = rebuild_faq_schema(text)
        if did: notes.append("rebuilt FAQPage schema from visible questions")

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
            f"User-agent: *\nAllow: /\n\nSitemap: https://{DOMAIN}/sitemap.xml\n")

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
