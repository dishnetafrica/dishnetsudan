#!/usr/bin/env python3
"""Phase 1 commercial pages: price hub, installation, plans hub, five plan pages.

Every figure on these pages is an approved uCRM value; the site-wide checkers
fail the build if any of them drifts. Nothing here states coverage promises,
lead times, payment methods, offices, reviews or performance claims — those
are not confirmed, so they do not exist on these pages.

Run:  python3 tools/build-seo-pages.py   (idempotent — regenerates all eight)
"""
import re, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.path.join(ROOT, 'site')
DOMAIN = 'dishnetsudan.com'
WA = '249900083481'

# The approved catalogue. uCRM is the source of truth; these mirror it and the
# checkers enforce the mirror.
PLANS = [
    ('500gb', 'Starlink Priority 500GB', 112, '500 GB'),
    ('1tb',   'Starlink Priority 1TB',   189, '1 TB'),
    ('2tb',   'Starlink Priority 2TB',   336, '2 TB'),
    ('3tb',   'Starlink Priority 3TB',   483, '3 TB'),
    ('5tb',   'Starlink Priority 5TB',   784, '5 TB'),
]
HW = [('Starlink Mini Kit', 350), ('Starlink Standard Kit', 600), ('Professional Installation', 50)]

shell = open(os.path.join(SITE, 'why-dishnet.html'), encoding='utf-8').read()
cut = re.search(r'<(section|main)\b', shell).start()
TOP, BOTTOM = shell[:cut], shell[shell.index('<footer'):]

def wa_cta(text, label):
    return (f'<a href="https://wa.me/{WA}?text={text.replace(" ", "%20")}" '
            f'class="btn btn-primary">{label}</a>')

def head(fname, title, desc, extra_schema=''):
    url = f'https://{DOMAIN}/{fname}'
    t = TOP
    t = re.sub(r'<title>.*?</title>', f'<title>{title}</title>', t, flags=re.S)
    for k in ('name="description"', 'property="og:description"', 'name="twitter:description"'):
        t = re.sub(rf'(<meta {k} content=")[^"]*(")', lambda m: m.group(1) + desc + m.group(2), t)
    for k in ('property="og:title"', 'name="twitter:title"'):
        t = re.sub(rf'(<meta {k} content=")[^"]*(")', lambda m: m.group(1) + title + m.group(2), t)
    t = re.sub(r'(<link rel="canonical" href=")[^"]*(")', lambda m: m.group(1) + url + m.group(2), t)
    t = re.sub(r'(<meta property="og:url" content=")[^"]*(")', lambda m: m.group(1) + url + m.group(2), t)
    t = re.sub(r'(<link rel="alternate" hreflang="[^"]*" href=")[^"]*(")', lambda m: m.group(1) + url + m.group(2), t)
    if extra_schema:
        t = t.replace('</head>', extra_schema + '\n</head>', 1)
    return t

def breadcrumbs(items):
    """items: [(label, href|None)] — visible trail + BreadcrumbList schema."""
    vis = ' <span style="opacity:.45">›</span> '.join(
        f'<a href="{h}" style="color:var(--text-secondary)">{l}</a>' if h else f'<span>{l}</span>'
        for l, h in items)
    ld = ','.join(
        f'{{"@type":"ListItem","position":{i+1},"name":"{l}"'
        + (f',"item":"https://{DOMAIN}/{h.lstrip("/")}"' if h else '') + '}'
        for i, (l, h) in enumerate(items))
    schema = ('<script type="application/ld+json">{"@context":"https://schema.org",'
              f'"@type":"BreadcrumbList","itemListElement":[{ld}]}}</script>')
    visible = (f'<nav aria-label="Breadcrumb" style="font-size:13px;margin:0 0 14px;">{vis}</nav>')
    return visible, schema

def faq_block(title, qas):
    items = '\n'.join(
        f'    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">{q}</button>\n'
        f'      <div class="faq-a"><div class="faq-a-inner">{a}</div></div></div>' for q, a in qas)
    ld = ','.join(
        '{"@type":"Question","name":"' + q.replace('"', '&quot;') + '","acceptedAnswer":{"@type":"Answer","text":"'
        + re.sub(r'<[^>]+>', '', a).replace('"', '&quot;') + '"}}' for q, a in qas)
    schema = ('<script type="application/ld+json">{"@context":"https://schema.org",'
              f'"@type":"FAQPage","mainEntity":[{ld}]}}</script>')
    html = f'''<section class="section-sm"><div class="container">
    <h2>{title}</h2>
{items}
  </div></section>'''
    return html, schema

def price_row(name, price, unit):
    return (f'<tr><td style="padding:9px 12px;">{name}</td>'
            f'<td style="padding:9px 12px;font-variant-numeric:tabular-nums;"><strong>${price}</strong> {unit}</td></tr>')

def table(head_cols, rows):
    th = ''.join(f'<th style="text-align:left;padding:9px 12px;">{c}</th>' for c in head_cols)
    return (f'<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:14.5px;min-width:420px;">'
            f'<thead><tr style="border-bottom:2px solid var(--border);">{th}</tr></thead>'
            f'<tbody>{"".join(rows)}</tbody></table></div>')

def hero(badge, h1, sub, cta_text, cta_label, crumb_vis):
    return f'''<section class="ug-hero" style="padding:150px 0 44px;">
  <div class="container">
    {crumb_vis}
    <span class="badge-label badge-accent">{badge}</span>
    <h1>{h1}</h1>
    <p style="max-width:640px;">{sub}</p>
    <div style="margin-top:22px;display:flex;gap:12px;flex-wrap:wrap;">
      {wa_cta(cta_text, cta_label)}
      <a href="starlink-kits.html" class="btn btn-ghost">See the kits</a>
    </div>
  </div>
</section>
'''

UPFRONT_NOTE = ('<p style="max-width:70ch;">Two kinds of money, never mixed: the kit and '
                'installation are <strong>one-time</strong>; the plan is <strong>monthly</strong>. '
                'The only one-time charges we have are the ones listed here &mdash; there is '
                'nothing hidden behind the quote.</p>')

pages = {}

# ══════════════════════════ PRICE HUB ══════════════════════════
fname = 'starlink-price-sudan.html'
crumb_vis, crumb_ld = breadcrumbs([('Home', '/'), ('Starlink prices', None)])
plan_rows = [price_row(n, p, '/month') for _, n, p, _ in PLANS]
hw_rows = [price_row(n, p, 'one-time') for n, p in HW]
faq_html, faq_ld = faq_block('Price questions, answered', [
    ('How much do I need to pay to get started?',
     'The one-time part: a kit ($350 Mini or $600 Standard) plus $50 professional installation — so $400 or $650 to start. Then your chosen plan is billed monthly, from $112.'),
    ('What currency are these prices in?',
     'US dollars. Message us on WhatsApp about local payment arrangements for your order.'),
    ('Can prices change?',
     'Plan and kit prices can change with Starlink’s own pricing. The WhatsApp assistant always quotes the current price straight from our billing system — what it says is what you pay.'),
    ('Are there charges not shown on this page?',
     'No. The plans and one-time items listed here are the complete set of charges we have. If something is not listed, we do not charge it.'),
])
body = hero('Pricing', 'What Starlink costs in Sudan',
    'Every price on one page: five monthly Priority plans, two kits, and professional installation. '
    'These figures come from our billing system — the same one the WhatsApp assistant quotes from.',
    'Hello DishNet, I would like a Starlink quote.', 'Get an exact quote on WhatsApp', crumb_vis)
body += f'''<section class="section-sm"><div class="container">
  <h2>Monthly plans</h2>
  {table(['Plan', 'Price'], plan_rows)}
  <p style="max-width:70ch;">Every plan includes unlimited standard data after its priority allowance.
  Full comparison on the <a href="starlink-plans-sudan.html">plans page</a>, or read about each:
  {' &middot; '.join(f'<a href="starlink-priority-{s}-sudan.html">{d}</a>' for s, _, _, d in PLANS)}.</p>
  <h2 style="margin-top:34px;">One-time costs</h2>
  {table(['Item', 'Price'], hw_rows)}
  {UPFRONT_NOTE}
  <p style="max-width:70ch;"><strong>Worked example &mdash; starting with 1TB:</strong> Standard Kit $600
  + installation $50 = <strong>$650 one-time</strong>, then <strong>$189/month</strong>.
  Kit details and full specifications are on the <a href="starlink-kits.html">hardware page</a>;
  what installation includes is on the <a href="starlink-installation-sudan.html">installation page</a>.</p>
</div></section>
''' + faq_html
pages[fname] = (
    'Starlink Prices in Sudan — Monthly Plans &amp; Kit Costs | DishNet',
    'Starlink Sudan prices on one page: Priority plans $112–$784/month, kits $350–$600 one-time, installation $50. What you pay to start, and monthly.',
    crumb_ld + faq_ld, body)

# ══════════════════════════ INSTALLATION ══════════════════════════
fname = 'starlink-installation-sudan.html'
crumb_vis, crumb_ld = breadcrumbs([('Home', '/'), ('Installation', None)])
svc_ld = ('<script type="application/ld+json">{"@context":"https://schema.org","@type":"Service",'
          '"serviceType":"Starlink installation","provider":{"@type":"Organization","name":"DishNet Africa Ltd",'
          f'"url":"https://{DOMAIN}/"}},"areaServed":{{"@type":"Country","name":"Sudan"}},'
          '"offers":{"@type":"Offer","price":"50","priceCurrency":"USD"}}</script>')
faq_html, faq_ld = faq_block('Installation questions', [
    ('Can I install Starlink myself?',
     'Yes — Starlink is designed for self-installation, and the kit includes everything for a basic kickstand setup. Professional installation matters when you want a permanent roof or pole mount, a clean long cable run, or an office network set up properly.'),
    ('What does the $50 installation include?',
     'Site check for a clear sky view, mounting and alignment of the dish, cable routing, router placement and WiFi setup, connecting your devices, and a walkthrough of the Starlink app.'),
    ('What do I need to have ready?',
     'A spot with an unobstructed view of the sky — no roof overhang, tree cover or wall directly above — and a power source. Our team confirms the rest with you before the visit.'),
    ('Do you install outside the big cities?',
     'Starlink itself works anywhere in Sudan with a clear sky view. Tell us where you are on WhatsApp and we will confirm the arrangements for your location — we would rather confirm honestly than promise blindly.'),
])
body = hero('Installation · $50 one-time', 'Professional Starlink installation in Sudan',
    'Mounting, alignment, cabling, WiFi setup and app training — one flat $50, anywhere we can '
    'reach you. Starlink allows self-installation too; here is exactly what the professional visit adds.',
    'Hello DishNet, I would like to book Starlink installation.', 'Book installation on WhatsApp', crumb_vis)
body += f'''<section class="section-sm"><div class="container">
  <h2>What the $50 covers</h2>
  <ol style="max-width:70ch;line-height:2;">
    <li><strong>Site check</strong> &mdash; finding the spot with a truly clear sky view, which decides everything about Starlink performance.</li>
    <li><strong>Mounting</strong> &mdash; kickstand, or a wall, roof or pole mount arranged for your site.</li>
    <li><strong>Cabling</strong> &mdash; the 15&nbsp;m kit cable routed cleanly and safely.</li>
    <li><strong>Network setup</strong> &mdash; router placed for coverage, WiFi named and secured, your devices connected.</li>
    <li><strong>Handover</strong> &mdash; the Starlink app on your phone and a walkthrough of what it shows.</li>
  </ol>
  <h2 style="margin-top:34px;">Honest guidance: self-install or professional?</h2>
  <p style="max-width:70ch;">Starlink ships as a self-install product and we will never pretend
  otherwise. If you are comfortable placing the dish on its kickstand with a clear sky view, the
  <a href="starlink-kits.html">kit</a> alone gets you online. Choose the professional visit when the dish needs to live
  on a roof or pole, when the cable has to cross a building properly, or when an office network
  needs to come up right the first time.</p>
  {UPFRONT_NOTE}
  <p style="max-width:70ch;">See where we work most: <a href="coverage.html">coverage and cities</a>.
  Full pricing is on the <a href="starlink-price-sudan.html">prices page</a>.</p>
</div></section>
''' + faq_html
pages[fname] = (
    'Starlink Installation in Sudan — $50 Professional Install | DishNet',
    'Professional Starlink installation across Sudan for $50: mounting, alignment, cabling, WiFi setup and app training. Kits supplied. Book on WhatsApp.',
    crumb_ld + svc_ld + faq_ld, body)

# ══════════════════════════ PLANS HUB ══════════════════════════
fname = 'starlink-plans-sudan.html'
crumb_vis, crumb_ld = breadcrumbs([('Home', '/'), ('Plans', None)])
fits = {
    '500gb': 'Light households, solo offices',
    '1tb': 'Families and small offices — our most recommended',
    '2tb': 'Heavy use, many devices, SMEs',
    '3tb': 'Multi-team offices, guesthouses',
    '5tb': 'NGOs, agencies, institutions',
}
rows = [(f'<tr><td style="padding:9px 12px;"><a href="starlink-priority-{s}-sudan.html">{n}</a></td>'
         f'<td style="padding:9px 12px;">{d} priority</td>'
         f'<td style="padding:9px 12px;font-variant-numeric:tabular-nums;"><strong>${p}</strong>/month</td>'
         f'<td style="padding:9px 12px;">{fits[s]}</td></tr>') for s, n, p, d in PLANS]
il_ld = ('<script type="application/ld+json">{"@context":"https://schema.org","@type":"ItemList","itemListElement":['
         + ','.join(f'{{"@type":"ListItem","position":{i+1},"name":"{n}",'
                    f'"url":"https://{DOMAIN}/starlink-priority-{s}-sudan.html"}}'
                    for i, (s, n, p, d) in enumerate(PLANS)) + ']}</script>')
faq_html, faq_ld = faq_block('Plan questions', [
    ('What does “Priority data” mean?',
     'Each plan carries a priority allowance — 500GB up to 5TB. While you are within it, your traffic gets priority on the network. After it, you keep unlimited standard data for the rest of the month; nothing switches off.'),
    ('Which plan should I choose?',
     'Roughly: 500GB for light household use, 1TB for a family or small office, 2TB for heavy multi-device use, 3TB and 5TB for organisations. Or skip the guessing — the WhatsApp assistant asks two questions and recommends from these same five plans.'),
    ('Can I change plans later?',
     'Yes — contact us on WhatsApp or through your account and we arrange the change.'),
])
body = hero('Monthly plans', 'Five plans. One honest comparison.',
    'Every Starlink Priority plan we sell in Sudan, from $112 to $784 a month — what the allowance '
    'means, and who each tier genuinely fits. Same prices the WhatsApp assistant quotes.',
    'Hello DishNet, which Starlink plan fits me?', 'Ask which plan fits — on WhatsApp', crumb_vis)
body += f'''<section class="section-sm"><div class="container">
  {table(['Plan', 'Allowance', 'Price', 'Fits'], rows)}
  <p style="max-width:70ch;margin-top:14px;">All plans include unlimited standard data after the
  priority allowance. Add the one-time <a href="starlink-kits.html">kit</a>
  ($350 or $600) and <a href="starlink-installation-sudan.html">installation</a> ($50) to start &mdash;
  the complete picture is on the <a href="starlink-price-sudan.html">prices page</a>.</p>
</div></section>
''' + faq_html
pages[fname] = (
    'Starlink Priority Plans in Sudan — 500GB to 5TB Compared | DishNet',
    'All five Starlink Priority plans in Sudan compared: $112 to $784/month, what Priority data means, and which allowance fits your use. Ask the AI on WhatsApp.',
    crumb_ld + il_ld + faq_ld, body)

# ══════════════════════════ FIVE PLAN PAGES ══════════════════════════
ANGLES = {
    '500gb': ('The starting point',
        'For a light household or a one-person office: browsing, messaging, calls, and a sensible '
        'amount of streaming. At a typical ~3&nbsp;GB per hour of HD video &mdash; an assumption, not a '
        'promise &mdash; 500&nbsp;GB is roughly 160 hours of watching, and everyday browsing uses far less.',
        'If several people stream daily or you work on video calls all day, start at '
        '<a href="starlink-priority-1tb-sudan.html">1TB</a> instead — stepping up later is easy, but '
        'the right first pick saves a month of rationing.'),
    '1tb': ('The family and small-office default',
        'The plan we recommend most. A family with streaming, schoolwork and calls, or a small office '
        'with daily video meetings and cloud files, typically lives comfortably inside 1&nbsp;TB &mdash; '
        'double the 500GB allowance for $77 more.',
        'The complete first payment, worked honestly: Standard Kit $600 + installation $50 = '
        '<strong>$650 one-time</strong>, then <strong>$189/month</strong>. That is the whole list — '
        'there are no charges we have not written down.'),
    '2tb': ('For heavy use and busy teams',
        'Many devices, cloud backup running, video meetings all day, media-heavy work: 2&nbsp;TB of '
        'priority data covers serious daily load. The Standard kit’s WiFi&nbsp;6 router serves up '
        'to 235 devices across 297&nbsp;m&sup2;, so the network side keeps up with the allowance.',
        'Not sure between 1TB and 2TB? Count the people who stream or sit on video calls daily — '
        'past roughly five heavy users, 2TB stops being a luxury.'),
    '3tb': ('For organisations that share one connection',
        'Multi-team offices and guesthouses put dozens of light users on one dish. 3&nbsp;TB keeps a '
        'building of everyday use inside priority data, and the Gen&nbsp;3 router’s two Ethernet '
        'ports feed wired office networks properly.',
        'Sometimes two smaller kits in different buildings beat one big plan — if your site is '
        'spread out, say so on WhatsApp and we will recommend honestly, even when it means selling '
        'you the cheaper setup.'),
    '5tb': ('For institutions',
        'NGOs, agencies and institutions run connectivity as infrastructure: many staff, many '
        'devices, sustained loads. 5&nbsp;TB is the largest priority allowance we sell, and orders '
        'come with proper quotes and invoices through our billing system.',
        'Field operations too? The <a href="starlink-kits.html">Mini kit</a> travels with your teams '
        'at 25–40&nbsp;W from a power bank or solar — many organisations pair a fixed Standard '
        'kit at HQ with Minis in the field.'),
}
for slug, name, price, data in PLANS:
    fname = f'starlink-priority-{slug}-sudan.html'
    h2a, para1, para2 = ANGLES[slug]
    crumb_vis, crumb_ld = breadcrumbs([('Home', '/'), ('Plans', 'starlink-plans-sudan.html'), (data + ' Priority', None)])
    prod_ld = ('<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product",'
               f'"name":"{name}","description":"Starlink Priority plan with {data} of priority data per month '
               f'and unlimited standard data after, in Sudan from DishNet.",'
               '"brand":{"@type":"Brand","name":"Starlink"},'
               f'"offers":{{"@type":"Offer","price":"{price}","priceCurrency":"USD",'
               '"availability":"https://schema.org/InStock",'
               '"seller":{"@type":"Organization","name":"DishNet Africa Ltd"}}}</script>')
    faq_html, faq_ld = faq_block(f'{data} questions', [
        (f'What happens after the {data} priority allowance?',
         'Nothing switches off. You continue on unlimited standard data until the next monthly cycle.'),
        ('What do I pay to get started?',
         f'One-time: a kit ($350 Mini or $600 Standard) plus $50 installation. Then {name} is ${price} each month. One-time and monthly stay separate — always.'),
        ('Is this the right size for me?',
         'The honest answer depends on your people and habits — which is exactly what the WhatsApp assistant asks before recommending. Two questions, then a recommendation from the same five plans on this site.'),
    ])
    others = ' &middot; '.join(f'<a href="starlink-priority-{s}-sudan.html">{d}</a>'
                               for s, _, _, d in PLANS if s != slug)
    body = hero(f'{data} priority · ${price}/month', name,
        f'{data} of priority data every month, unlimited standard data after. ${price}/month, '
        'billed from the same system the WhatsApp assistant quotes from.',
        f'Hello DishNet, I am interested in the {name} plan.', f'Order {data} on WhatsApp', crumb_vis)
    body += f'''<section class="section-sm"><div class="container">
  <h2>{h2a}</h2>
  <p style="max-width:70ch;">{para1}</p>
  <p style="max-width:70ch;">{para2}</p>
  <h2 style="margin-top:34px;">What you pay</h2>
  {table(['', 'Amount'], [
      price_row('Kit (one-time)', '350 or $600', '<a href="starlink-kits.html">compare kits</a>'),
      price_row('Professional installation (one-time)', 50, ''),
      price_row(f'{name} (monthly)', price, '/month'),
  ])}
  {UPFRONT_NOTE}
  <p style="max-width:70ch;">Other allowances: {others} &mdash; or see
  <a href="starlink-plans-sudan.html">all plans compared</a> and
  <a href="starlink-price-sudan.html">every price on one page</a>.</p>
</div></section>
''' + faq_html
    pages[fname] = (
        f'{name} Sudan — ${price}/month | DishNet',
        f'{name} in Sudan: {data} priority data then unlimited standard, ${price}/month. Kit from $350 one-time, installation $50. Order on WhatsApp.',
        crumb_ld + prod_ld + faq_ld, body)

# ══════════════════════════ WRITE ══════════════════════════
for fname, (title, desc, schema, body) in pages.items():
    doc = head(fname, title, desc, schema) + body + BOTTOM
    open(os.path.join(SITE, fname), 'w', encoding='utf-8').write(doc)
print(f"wrote {len(pages)} pages: " + ', '.join(pages))
