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
  <h2 style="margin-top:34px;">Installation, city by city</h2>
  <p style="max-width:70ch;">Every city below has its own page — what makes satellite fit that place,
  and the questions we hear there most:</p>
  <p style="max-width:75ch;margin:4px 0;"><strong>Blue nile valley:</strong> <a href="starlink-ed-damazin.html">Ed Damazin</a> &middot; <a href="starlink-sennar.html">Sennar</a> &middot; <a href="starlink-singa.html">Singa</a></p><p style="max-width:75ch;margin:4px 0;"><strong>Darfur:</strong> <a href="starlink-ed-daein.html">Ed Daein</a> &middot; <a href="starlink-el-fasher.html">El Fasher</a> &middot; <a href="starlink-el-geneina.html">El Geneina</a> &middot; <a href="starlink-nyala.html">Nyala</a> &middot; <a href="starlink-zalingei.html">Zalingei</a></p><p style="max-width:75ch;margin:4px 0;"><strong>Gezira:</strong> <a href="starlink-wad-madani.html">Wad Madani</a></p><p style="max-width:75ch;margin:4px 0;"><strong>Kordofan:</strong> <a href="starlink-el-fula.html">El Fula</a> &middot; <a href="starlink-el-obeid.html">El Obeid</a> &middot; <a href="starlink-kadugli.html">Kadugli</a></p><p style="max-width:75ch;margin:4px 0;"><strong>Nile confluence:</strong> <a href="starlink-bahri.html">Bahri</a> &middot; <a href="starlink-khartoum.html">Khartoum</a> &middot; <a href="starlink-omdurman.html">Omdurman</a></p><p style="max-width:75ch;margin:4px 0;"><strong>Nubia:</strong> <a href="starlink-dongola.html">Dongola</a> &middot; <a href="starlink-merowe.html">Merowe</a> &middot; <a href="starlink-wadi-halfa.html">Wadi Halfa</a></p><p style="max-width:75ch;margin:4px 0;"><strong>Red sea coast:</strong> <a href="starlink-port-sudan.html">Port Sudan</a></p><p style="max-width:75ch;margin:4px 0;"><strong>White nile:</strong> <a href="starlink-kosti.html">Kosti</a> &middot; <a href="starlink-rabak.html">Rabak</a></p><p style="max-width:75ch;margin:4px 0;"><strong>Eastern sudan:</strong> <a href="starlink-gedaref.html">Gedaref</a> &middot; <a href="starlink-kassala.html">Kassala</a></p><p style="max-width:75ch;margin:4px 0;"><strong>Northern nile valley:</strong> <a href="starlink-atbara.html">Atbara</a> &middot; <a href="starlink-ed-damer.html">Ed Damer</a> &middot; <a href="starlink-shendi.html">Shendi</a></p>
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



# ══════════════════════════ PHASE 2: AUDIENCE PAGES ══════════════════════════
AUD = {}

AUD['starlink-home-sudan.html'] = dict(
    badge='For your home', crumb='Starlink for homes',
    title='Starlink for Your Home in Sudan — Which Plan Fits | DishNet',
    desc='Starlink home internet in Sudan: which Priority plan fits your household, what the kit costs, and how installation works. From $112/month.',
    h1='Home internet that depends on nothing local',
    sub=('A dish on your roof, a clear view of the sky, and your home is online — no cable to '
         'wait for, no local network to depend on. Here is how to size it honestly.'),
    cta=('Hello DishNet, I need Starlink for my home.', 'Ask about your home on WhatsApp'),
    sections=[
        ('Sizing a household without guesswork',
         'Count the people who stream or sit on video calls daily. A light household — browsing, '
         'messaging, some evening streaming — lives comfortably on <a href="starlink-priority-500gb-sudan.html">500GB at $112/month</a>. '
         'A family where several people stream and study online fits <a href="starlink-priority-1tb-sudan.html">1TB at $189/month</a>, '
         'our most recommended plan. At a typical ~3&nbsp;GB per hour of HD video — an assumption, '
         'not a promise — 1TB is roughly 330 hours of watching, which is a lot of household.'),
        ('The power-cut question, answered first',
         'The Standard kit runs on the inverter-and-battery setups many homes already have; it '
         'draws 75–100&nbsp;W. The <a href="starlink-kits.html">Mini</a> draws 25–40&nbsp;W — less than a laptop charger — and '
         'runs from a power bank or small solar setup with the right cable. Power cuts do not '
         'have to mean internet cuts.'),
        ('What starting actually costs',
         'One-time: a kit ($350 Mini or $600 Standard) plus $50 <a href="starlink-installation-sudan.html">professional installation</a>. '
         'Monthly: your plan, from $112. The two never mix, and there are no charges we have not '
         'listed on the <a href="starlink-price-sudan.html">prices page</a>.'),
    ],
    faqs=[
        ('Which kit should a home get?',
         'The Standard kit, for almost every home: stronger WiFi (covers up to 297 m², up to 235 devices) and a dish built to live permanently on a roof. The Mini suits homes that also want to travel with their internet.'),
        ('Do I need a technician?',
         'Starlink is designed for self-installation, and the kickstand setup is genuinely simple. The $50 professional visit is worth it for roof mounting, clean cabling and getting the whole household connected properly on day one.'),
        ('What if my plan turns out too small?',
         'Nothing switches off when you pass your priority allowance — you continue on unlimited standard data. If that keeps happening, we move you up a plan.'),
    ])

AUD['starlink-business-sudan.html'] = dict(
    badge='For business', crumb='Starlink for business',
    title='Starlink for Business in Sudan — Offices, Teams &amp; Sites | DishNet',
    desc='Starlink business internet in Sudan: Priority plans to 5TB, WiFi 6 for 235 devices, professional installation and proper invoicing. Talk on WhatsApp.',
    h1='Business internet for Sudan, from orbit',
    sub=('Connectivity that does not share the fate of any local infrastructure, sized for teams '
         'and billed properly, with quotes and invoices from our CRM.'),
    cta=('Hello DishNet, I need Starlink for my business.', 'Describe your business on WhatsApp'),
    sections=[
        ('Sizing by team, honestly',
         'Roughly: a handful of staff on email, documents and calls fits <a href="starlink-priority-1tb-sudan.html">1TB</a>; '
         'a team of about twenty with daily video meetings fits 1TB to <a href="starlink-priority-2tb-sudan.html">2TB</a>; '
         'multi-team offices belong at <a href="starlink-priority-3tb-sudan.html">3TB</a> and institutions at '
         '<a href="starlink-priority-5tb-sudan.html">5TB</a>. The WhatsApp assistant applies this same logic and quotes '
         'live prices — its recommendation is the one we stand behind.'),
        ('The network side',
         'The Standard kit&rsquo;s Gen&nbsp;3 router is WiFi&nbsp;6 with two Ethernet LAN ports — it feeds a wired '
         'office network directly and serves up to 235 devices across 297&nbsp;m&sup2;. Larger or '
         'multi-building sites: ask us — sometimes two kits beat one big plan, and we will say so '
         'even when it is the cheaper sale.'),
        ('Continuity, stated carefully',
         'A satellite link is independent of local exchanges, long-haul routes and terrestrial '
         'outages — when they have problems, your dish does not inherit them. We do not promise '
         'uptime figures; we state the architecture and let it speak.'),
        ('Procurement without friction',
         'Formal quotes, proper invoices, and account history — all from the same billing system '
         'that prices this website. Starlink <a href="starlink-kits.html">Performance kits</a> for higher-throughput sites are '
         'available on request.'),
    ],
    faqs=[
        ('Can we get a formal quotation?',
         'Yes — say so on WhatsApp and we issue a proper quote and invoice from our billing system.'),
        ('What does a business pay to start?',
         'The same honest arithmetic as everyone: kit ($350 or $600) plus $50 installation one-time, then the plan monthly. Multi-site orders are quoted per site.'),
        ('Do you serve NGOs and hotels specifically?',
         'Yes — see the dedicated pages for <a href="starlink-for-ngos-sudan.html">NGOs</a> and <a href="starlink-for-hotels-sudan.html">hotels and guesthouses</a>.'),
    ])

AUD['starlink-for-ngos-sudan.html'] = dict(
    badge='For NGOs &amp; agencies', crumb='Starlink for NGOs',
    title='Starlink for NGOs in Sudan — Field &amp; Office Connectivity | DishNet',
    desc='Starlink for NGOs and agencies in Sudan: office plans to 5TB, portable Mini kits for field teams, formal quotes and invoices. Request a quote on WhatsApp.',
    h1='Connectivity for organisations that work where networks fail',
    sub=('Offices on the Standard kit, field teams on the Mini, procurement handled with proper '
         'paperwork — this is the setup we quote most for NGOs and agencies.'),
    cta=('Hello DishNet, our organisation needs a Starlink quote.', 'Request a formal quote on WhatsApp'),
    sections=[
        ('The HQ-plus-field pattern',
         'A fixed <a href="starlink-kits.html">Standard kit</a> at the office on <a href="starlink-priority-3tb-sudan.html">3TB</a> or '
         '<a href="starlink-priority-5tb-sudan.html">5TB</a>, and <a href="starlink-kits.html">Mini kits</a> that travel with field teams — '
         '1.1&nbsp;kg, backpack-sized, 25–40&nbsp;W from a power bank or solar. Each Mini carries its own '
         'plan, so field connectivity does not drain the office allowance.'),
        ('Procurement, the unexciting part done right',
         'Formal quotations, itemised invoices, one-time hardware separated from recurring service '
         '— all issued from our billing system. Multi-kit orders are quoted per site with the '
         'one-time and monthly lines kept apart, the way finance teams need them.'),
        ('Why satellite for this work',
         'A dish with a clear sky view has no dependency on any local infrastructure — which is '
         'precisely the property that matters where networks are damaged or absent. That is an '
         'architectural fact, not an availability promise: tell us your locations and we confirm '
         'arrangements honestly.'),
    ],
    faqs=[
        ('Can you supply multiple kits at once?',
         'Yes — multi-kit orders are quoted per site, with hardware and monthly service itemised separately on the invoice.'),
        ('Can field kits move between locations?',
         'Yes. The kit is yours and portable; the Mini is built for exactly that. Teams move between sites with the same dish.'),
        ('Who answers when something breaks?',
         'Message the same WhatsApp number — a human takes over the moment a conversation needs one, and your account history is in our system, not in someone&rsquo;s memory.'),
    ])

AUD['starlink-for-hotels-sudan.html'] = dict(
    badge='For hotels &amp; guesthouses', crumb='Starlink for hotels',
    title='Starlink for Hotels in Sudan — Guest WiFi That Works | DishNet',
    desc='Starlink for hotels and guesthouses in Sudan: guest WiFi from one dish, WiFi 6 coverage, plans sized by occupancy. Ask on WhatsApp.',
    h1='Guest WiFi that actually works',
    sub=('Guests judge a hotel by its WiFi within the first hour. One dish, sized honestly to your '
         'rooms, changes that review.'),
    cta=('Hello DishNet, I run a hotel and need Starlink.', 'Ask about your property on WhatsApp'),
    sections=[
        ('Sizing by occupancy, not optimism',
         'Guests stream. A small guesthouse fits <a href="starlink-priority-2tb-sudan.html">2TB</a>; larger properties with '
         'full rooms belong at <a href="starlink-priority-3tb-sudan.html">3TB</a> or above. When guests pass the priority '
         'allowance the connection continues on unlimited standard data — busy months degrade '
         'gracefully instead of cutting off.'),
        ('One dish, a whole property',
         'The Gen&nbsp;3 router covers up to 297&nbsp;m&sup2; and 235 devices — a strong start for a compact '
         'property. Larger buildings need additional access points wired from the router&rsquo;s two '
         'Ethernet ports; tell us the layout on WhatsApp and we will spec it with you.'),
        ('The arithmetic for a property',
         'One-time: kit ($600 Standard) + $50 <a href="starlink-installation-sudan.html">installation</a>. Monthly: the plan. '
         'All of it on one page: <a href="starlink-price-sudan.html">prices</a>.'),
    ],
    faqs=[
        ('Can guests overwhelm the connection?',
         'The plan&rsquo;s priority allowance is the lever: size it to your occupancy and heavy months continue on unlimited standard data rather than stopping.'),
        ('Can we have separate staff and guest networks?',
         'The router supports the essentials; proper multi-network setups for larger properties are specced per site — describe yours on WhatsApp.'),
        ('What about power cuts?',
         'The Standard kit runs on the inverter systems most properties already operate; it draws 75–100 W.'),
    ])

AUD['starlink-remote-sites-sudan.html'] = dict(
    badge='For remote sites', crumb='Starlink for remote sites',
    title='Starlink for Remote Sites in Sudan — Field-Ready Internet | DishNet',
    desc='Starlink for remote sites and field teams in Sudan: the Mini kit at 25–40 W runs from power banks or solar, moves between sites, and needs only sky.',
    h1='Internet for places the map calls empty',
    sub=('Field camps, survey teams, project sites: if it has sky, it can have internet — and the '
         'kit fits in a backpack.'),
    cta=('Hello DishNet, I need Starlink for a remote site.', 'Describe your site on WhatsApp'),
    sections=[
        ('Built around the Mini',
         'The <a href="starlink-kits.html">Starlink Mini</a> weighs 1.1&nbsp;kg, is IP67-sealed against dust and rain, and '
         'draws 25–40&nbsp;W from a 12–48&nbsp;V DC source — a decent power bank or a small solar '
         'panel keeps it running. Rated −30&deg;C to 50&deg;C and operational in 96+&nbsp;kph wind, per '
         'Starlink&rsquo;s own specification sheets.'),
        ('Sites that move',
         'The kit is not tied to an address. Teams carry one dish between camps; the connection '
         'comes up wherever there is sky. Pair it with a plan sized to the team — '
         '<a href="starlink-priority-500gb-sudan.html">500GB</a> for comms and reporting, more for data-heavy work.'),
        ('The fixed-site option',
         'A longer-lived site earns the <a href="starlink-kits.html">Standard kit</a>: stronger WiFi for a larger camp and '
         'a dish built for a permanent mount, with $50 <a href="starlink-installation-sudan.html">professional installation</a> '
         'when the site justifies it.'),
    ],
    faqs=[
        ('Does it work everywhere in Sudan?',
         'Starlink is a satellite service: the question is a clear view of the sky, not whether a network reaches the place. Tell us where the site is and we confirm the practical arrangements honestly.'),
        ('How much power does it really need?',
         'The Mini averages 25–40 W — comparable to a laptop. The Standard kit draws 75–100 W and suits generator or inverter power.'),
        ('Can one kit serve rotating teams?',
         'Yes. The kit belongs to the organisation, not a location; whoever is on site uses it.'),
    ])

AUD['starlink-rural-sudan.html'] = dict(
    badge='For rural areas', crumb='Starlink in rural Sudan',
    title='Starlink in Rural Sudan — Internet Without the Tower | DishNet',
    desc='Starlink brings internet to rural Sudan without towers or cables: a dish, a clear sky view, and a plan from $112/month. Order from anywhere on WhatsApp.',
    h1='No tower. No cable. Just sky.',
    sub=('Rural connectivity has always waited for infrastructure to arrive. A satellite dish ends '
         'the waiting: if you can see the sky, you can be online.'),
    cta=('Hello DishNet, I need Starlink in a rural area.', 'Tell us where you are on WhatsApp'),
    sections=[
        ('Why satellite fits rural Sudan',
         'Terrestrial networks reach towns first and villages late or never. A Starlink dish needs '
         'no tower and no cable — the signal comes from orbit, the same service in a village as in '
         '<a href="starlink-khartoum.html">Khartoum</a>. See <a href="coverage.html">the cities we are asked about most</a> — and note the '
         'point of that page: absence from the list has never meant absence of service.'),
        ('Power, solved simply',
         'Many rural sites run on solar already. The <a href="starlink-kits.html">Mini</a>&rsquo;s 25–40&nbsp;W works with modest '
         'solar-and-battery setups; the Standard kit&rsquo;s 75–100&nbsp;W suits a household inverter. '
         'Ask us and we will size the power alongside the kit.'),
        ('Ordering from far away',
         'The whole process runs on WhatsApp — plan advice, exact prices from our billing system, '
         'and delivery arrangements confirmed per order, honestly, for your location.'),
    ],
    faqs=[
        ('Is the speed worse in remote areas?',
         'Distance from the city makes no difference to a satellite link — the signal goes up and comes down, it does not travel overland.'),
        ('What does it cost to start?',
         'The same as anywhere: kit ($350 or $600) plus $50 installation where we arrange it, one-time; then the plan from $112/month. Delivery for distant locations is confirmed per order — we quote it before you commit, never after.'),
        ('Can a village share one connection?',
         'One kit can serve a compound or small cluster within WiFi range; beyond that, ask us — shared setups are specced honestly per site.'),
    ])

for fname, a in AUD.items():
    crumb_vis, crumb_ld = breadcrumbs([('Home', '/'), (a['crumb'], None)])
    svc_ld = ('<script type="application/ld+json">{"@context":"https://schema.org","@type":"Service",'
              f'"serviceType":"{a["crumb"]}","provider":{{"@type":"Organization","name":"DishNet Africa Ltd",'
              f'"url":"https://{DOMAIN}/"}},"areaServed":{{"@type":"Country","name":"Sudan"}}}}</script>')
    faq_html, faq_ld = faq_block('Questions we hear', a['faqs'])
    body = hero(a['badge'], a['h1'], a['sub'], a['cta'][0], a['cta'][1], crumb_vis)
    mids = '\n'.join(
        f'<h2 style="margin-top:30px;">{h}</h2>\n<p style="max-width:70ch;">{t}</p>' for h, t in a['sections'])
    body += f'<section class="section-sm"><div class="container">\n{mids}\n</div></section>\n' + faq_html
    pages[fname] = (a['title'], a['desc'], crumb_ld + svc_ld + faq_ld, body)



# ══════════════════════════ PHASE 3: GUIDES ══════════════════════════
def guide(fname, crumb, title, desc, h1, sub, sections, faqs, cta):
    crumb_vis, crumb_ld = breadcrumbs([('Home', '/'), ('Guides', 'guides.html'), (crumb, None)])
    art_ld = ('<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article",'
              f'"headline":"{h1}","author":{{"@type":"Organization","name":"DishNet Africa Ltd"}},'
              f'"publisher":{{"@type":"Organization","name":"DishNet Africa Ltd"}},'
              f'"mainEntityOfPage":"https://{DOMAIN}/{fname}"}}</script>')
    faq_html, faq_ld = faq_block('Quick answers', faqs)
    body = hero('Guide', h1, sub, cta[0], cta[1], crumb_vis)
    mids = '\n'.join(f'<h2 style="margin-top:30px;">{h}</h2>\n<p style="max-width:70ch;">{t}</p>'
                      for h, t in sections)
    body += f'<section class="section-sm"><div class="container">\n{mids}\n</div></section>\n' + faq_html
    pages[fname] = (title, desc, crumb_ld + art_ld + faq_ld, body)

guide('guide-priority-data.html', 'Priority data explained',
    'What Priority Data Means on Starlink — Explained | DishNet Sudan',
    'Priority data on Starlink, explained simply: what the allowance does, what happens when it ends, and how to pick the right amount for Sudan.',
    'Priority data, explained without the jargon',
    'Every plan we sell carries a priority allowance — 500GB to 5TB. Here is what that number '
    'actually does, and what happens when you pass it.',
    [('While you are inside the allowance',
      'Your traffic carries priority on the network. That is the whole mechanism: the allowance is '
      'not a cap on existence, it is a claim to precedence.'),
     ('When the allowance ends',
      'Nothing switches off. You continue on unlimited standard data until the monthly cycle '
      'resets. A household that overshoots one busy month keeps working — it just no longer '
      'carries priority for the remainder.'),
     ('Choosing the number',
      'Count daily streamers and video-callers, not devices in a drawer. The honest mapping is on '
      'the <a href="starlink-plans-sudan.html">plans page</a>, and the WhatsApp assistant applies the same logic in '
      'two questions. Prices live on <a href="starlink-price-sudan.html">one page</a>, from $112/month.')],
    [('Is standard data slow?', 'It is the non-priority tier of the same network. We quote no speed figures for it — what changes is precedence, and busy areas feel that more than quiet ones.'),
     ('Does unused priority data roll over?', 'No — the allowance resets each monthly cycle.'),
     ('Can I change my allowance later?', 'Yes, message us and we arrange the plan change.')],
    ('Hello DishNet, help me pick a data allowance.', 'Ask about allowances on WhatsApp'))

guide('guide-how-much-data.html', 'How much data you need',
    'How Much Data Does Your Home or Office Need? | DishNet Sudan',
    'Honest data arithmetic for Sudan: what browsing, calls and streaming actually use, and how to size a Starlink plan without guessing.',
    'How much data you actually need',
    'Sizing a plan is arithmetic, not mystery. Here are the working numbers — stated as the '
    'assumptions they are — and how to apply them to your household or office.',
    [('The working assumptions',
      'Typical figures, not promises: browsing and messaging are light, well under 1&nbsp;GB per '
      'hour; video calls run roughly 0.5&ndash;1&nbsp;GB per hour; HD streaming roughly '
      '3&nbsp;GB per hour. Your habits are the variable that matters.'),
     ('A worked household',
      'Two people streaming two hours nightly is roughly 360&nbsp;GB a month on the streaming '
      'alone — comfortably inside <a href="starlink-priority-1tb-sudan.html">1TB</a> once browsing, calls and updates join '
      'in, and tight inside <a href="starlink-priority-500gb-sudan.html">500GB</a>. That single comparison decides most homes.'),
     ('A worked office',
      'Twenty staff on documents, mail and daily video meetings typically lands around '
      '<a href="starlink-priority-1tb-sudan.html">1TB</a>&ndash;<a href="starlink-priority-2tb-sudan.html">2TB</a>. Media-heavy work moves you up a tier. '
      'The <a href="starlink-business-sudan.html">business page</a> maps team sizes to plans honestly.'),
     ('When the guess is wrong',
      'Passing the allowance never cuts you off — unlimited standard data continues. If it happens '
      'monthly, that is the signal to move up a plan, and we will say so rather than let you '
      'ration.')],
    [('What uses the most data at home?', 'Video, overwhelmingly — streaming and video calls. Everything else is small next to them.'),
     ('Do updates and backups matter?', 'Yes — phones, laptops and cloud backups sync quietly in the background. It is part of why we recommend the next tier up when a household sits at a boundary.'),
     ('Can the WhatsApp assistant do this for me?', 'Yes — it asks about people and habits, then recommends from the same five plans, with live prices.')],
    ('Hello DishNet, help me size a plan.', 'Get sized on WhatsApp'))

guide('guide-starlink-power-solar.html', 'Starlink on solar and inverters',
    'Running Starlink on Solar and Inverter Power | DishNet Sudan',
    'Starlink through Sudan power cuts: real wattage from the spec sheets, and honest arithmetic for running the Mini or Standard kit on solar or inverters.',
    'Starlink when the power goes out',
    'The dish does not care about the grid — only about its watts. Spec-sheet numbers and honest '
    'sizing arithmetic for keeping Starlink alive on solar and battery power.',
    [('The two numbers that matter',
      'From Starlink&rsquo;s specification sheets: the <a href="starlink-kits.html">Mini</a> averages 25&ndash;40&nbsp;W on a '
      '12&ndash;48&nbsp;V DC input; the Standard kit averages 75&ndash;100&nbsp;W on mains power. '
      'Everything below is arithmetic on those figures.'),
     ('Sizing, worked honestly',
      'A 40&nbsp;W average draw is roughly 1&nbsp;kWh per 24 hours — a modest power bank runs a '
      'Mini for hours, and a small solar-plus-battery setup runs it continuously. The Standard '
      'kit at ~100&nbsp;W wants the household inverter-and-battery systems many Sudanese homes '
      'and offices already run. These are sizing estimates from the spec-sheet wattages, not '
      'guarantees — your installer confirms the final setup.'),
     ('What we do about it',
      'Tell us your power situation when you order and we size the backup power with the kit — '
      'it is part of the <a href="starlink-installation-sudan.html">installation</a> conversation, not an afterthought.')],
    [('Can the Mini really run from a power bank?', 'Yes — with the right cable for its 12–48 V DC input, a capable power bank runs it for hours. Ask us for the cable details for your power bank.'),
     ('Does a power cut break the connection?', 'Only if the dish loses power. Keep the dish and router fed and the link stays up — the satellite side never went anywhere.'),
     ('Which kit for a solar-only site?', 'The Mini, almost always — 25–40 W is the difference between a small panel and a serious array. The remote-sites page covers this world.')],
    ('Hello DishNet, I need Starlink on solar power.', 'Ask about power setups on WhatsApp'))

guide('guide-how-to-order.html', 'How ordering works',
    'How to Order Starlink in Sudan — Start to Online | DishNet Sudan',
    'Ordering Starlink in Sudan, step by step: WhatsApp, a plan recommendation with live prices, the kit, installation, and going online.',
    'From first message to first megabit',
    'The whole journey runs on WhatsApp, and the prices you are quoted come live from our billing '
    'system. Here is each step, exactly as it happens.',
    [('1 — Say hello',
      'Message <a href="https://wa.me/249900083481">+249&nbsp;900&nbsp;083&nbsp;481</a>. Our assistant answers immediately, '
      'asks what the connection is for — home or business, roughly how many people — and '
      'recommends from the <a href="starlink-plans-sudan.html">five plans</a> with the current price. A human joins the '
      'conversation whenever it needs one.'),
     ('2 — The honest quote',
      'One-time and monthly, always separate: the <a href="starlink-kits.html">kit</a> ($350 Mini or $600 Standard) plus '
      '$50 <a href="starlink-installation-sudan.html">installation</a>, then the plan from $112/month. Every figure is on '
      'the <a href="starlink-price-sudan.html">prices page</a>, and nothing is added that is not listed there.'),
     ('3 — Kit and installation',
      'We confirm delivery arrangements for your location per order — honestly, before you '
      'commit. Installation covers mounting, alignment, cabling, WiFi setup and the app on your '
      'phone.'),
     ('4 — Online, with an account that knows you',
      'Your service lives in our billing system: proper invoices, and support that sees your '
      'history when you message. The <a href="tutorials/index.html">app tutorials</a> cover everyday management.')],
    [('How long does the whole process take?', 'It depends on kit availability and your location, and we tell you honestly when you order rather than promising a number here.'),
     ('Do I pay before I understand the costs?', 'No. The full quote — one-time and monthly, separated — comes first. There are no charges beyond the listed ones.'),
     ('Can I do the whole thing in Arabic?', 'Yes — message in Arabic and the assistant answers in Arabic. Arabic pages: see /ar/.')],
    ('Hello DishNet, I want to order Starlink.', 'Start your order on WhatsApp'))

guide('guide-satellite-vs-mobile.html', 'Satellite vs mobile data',
    'Satellite vs Mobile Data in Sudan — An Architecture Comparison | DishNet',
    'Starlink satellite versus mobile data in Sudan, compared by architecture: what each depends on, where each fits, without vendor claims.',
    'Satellite and mobile data: what each depends on',
    'Not a takedown — an architecture comparison. Each technology has a dependency chain, and '
    'knowing it tells you where each fits.',
    [('The dependency chains',
      'Mobile data depends on a tower near you, the power feeding it, and the backhaul connecting '
      'it onward. A satellite dish depends on a clear view of the sky and its own power. Neither '
      'chain is better everywhere; they fail differently, and that difference is the whole '
      'decision.'),
     ('Where mobile data fits',
      'Phones move; SIM data follows them. In well-served towns, mobile data is the natural '
      'companion in your pocket — we sell fixed connectivity, not a story that phones are '
      'obsolete.'),
     ('Where satellite fits',
      'Fixed places that need capacity and independence: homes, offices, sites beyond tower '
      'range, and anywhere that cannot inherit the failures of local infrastructure. That '
      'independence is an architectural property, not a performance claim — and it is why '
      '<a href="starlink-rural-sudan.html">rural</a> and <a href="starlink-remote-sites-sudan.html">remote</a> Sudan is where satellite stops '
      'being an alternative and becomes the option.'),
     ('The practical pairing',
      'Many customers run both: Starlink as the fixed line for the household or office, mobile '
      'data on the move. Sizing the fixed side is the <a href="guide-how-much-data.html">data guide</a>&rsquo;s job.')],
    [('Is Starlink faster than mobile data?', 'We publish no comparative speed claims — networks vary by place and hour. The kits page carries Starlink&rsquo;s own typical ranges from its spec sheets; judge your local mobile experience yourself.'),
     ('Does weather affect satellite?', 'Heavy rain can attenuate any satellite link while it passes. The dish is built for outdoor conditions — IP67, wind-rated — per the spec sheets.'),
     ('Do I still need a SIM if I have Starlink?', 'For your phone away from home, yes. The two solve different problems.')],
    ('Hello DishNet, which option fits my situation?', 'Talk it through on WhatsApp'))

# ══════════════════════════ WRITE ══════════════════════════
for fname, (title, desc, schema, body) in pages.items():
    doc = head(fname, title, desc, schema) + body + BOTTOM
    open(os.path.join(SITE, fname), 'w', encoding='utf-8').write(doc)
print(f"wrote {len(pages)} pages: " + ', '.join(pages))
