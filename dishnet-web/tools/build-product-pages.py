#!/usr/bin/env python3
"""One page per kit, in the layout dishnetafrica.com uses for device-mini.html.

The layout is borrowed. The content is not. The South Sudan page carries a
$299 price, an SSP conversion, "tax included", 220 Mbps, 20-40 ms latency, a
12-month warranty, free Juba delivery, "certified" technicians, $65 Roam and
$80 Residential plans, "authorized Starlink reseller" in its Product schema,
and a footer claiming service across all major cities since 2013. None of that
is true for Sudan and most of it was the substance of the content audit. What
is here instead: uCRM's prices, the kit copy already vetted on this site, and
nothing that has not been confirmed.

Run:  python3 tools/build-product-pages.py
"""
import re, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.path.join(ROOT, 'site')
DOMAIN = 'dishnetsudan.com'
WA = '249900083481'

MINI_PRICE, STD_PRICE, INSTALL = 350, 600, 50
PLAN_LOW, PLAN_HIGH = 112, 784

shell = open(os.path.join(SITE, 'why-dishnet.html'), encoding='utf-8').read()
cut = re.search(r'<(section|main)\b', shell).start()
TOP, BOTTOM = shell[:cut], shell[shell.index('<footer'):]


def head(fname, title, desc, schema=''):
    url = f'https://{DOMAIN}/{fname}'
    t = TOP
    t = re.sub(r'<title>.*?</title>', f'<title>{title}</title>', t, flags=re.S)
    for k in ('name="description"', 'property="og:description"', 'name="twitter:description"'):
        t = re.sub(rf'(<meta {k} content=")[^"]*(")', lambda m: m.group(1) + desc + m.group(2), t)
    for k in ('property="og:title"', 'name="twitter:title"'):
        t = re.sub(rf'(<meta {k} content=")[^"]*(")', lambda m: m.group(1) + title + m.group(2), t)
    t = re.sub(r'(<link rel="canonical" href=")[^"]*(")', lambda m: m.group(1) + url + m.group(2), t)
    t = re.sub(r'(<meta property="og:url" content=")[^"]*(")', lambda m: m.group(1) + url + m.group(2), t)
    t = re.sub(r'(<link rel="alternate" hreflang="[^"]*" href=")[^"]*(")',
               lambda m: m.group(1) + url + m.group(2), t)
    t = re.sub(r'(<meta property="og:image" content=")[^"]*(")',
               lambda m: m.group(1) + f'https://{DOMAIN}/assets/img/products/' + IMG + m.group(2), t)
    if schema:
        t = t.replace('</head>', schema + '\n</head>', 1)
    return t


def wa(text, label, cls='btn btn-primary'):
    return (f'<a href="https://wa.me/{WA}?text={text.replace(" ", "%20").replace(",", "%2C")}" '
            f'class="{cls}">{label}</a>')


def crumbs(name, fname):
    trail = [('Home', 'index.html'), ('Starlink kits', 'starlink-kits.html'), (name, None)]
    vis = ' <span style="opacity:.45">&rsaquo;</span> '.join(
        f'<a href="{h}" style="color:var(--text-secondary)">{l}</a>' if h else f'<span>{l}</span>'
        for l, h in trail)
    ld = ','.join(
        f'{{"@type":"ListItem","position":{i+1},"name":"{l}"'
        + (f',"item":"https://{DOMAIN}/{h}"' if h else '') + '}'
        for i, (l, h) in enumerate(trail))
    return (f'<nav aria-label="Breadcrumb" style="font-size:13px;margin:0 0 18px;">{vis}</nav>',
            '<script type="application/ld+json">{"@context":"https://schema.org",'
            f'"@type":"BreadcrumbList","itemListElement":[{ld}]}}</script>')


def spec_boxes(items):
    cells = ''.join(
        f'<div style="text-align:center;padding:18px 10px;background:var(--surface-alt,#F3F2EE);'
        f'border-radius:10px;">'
        f'<div style="font-family:var(--font-display);font-size:19px;font-weight:700;'
        f'line-height:1.2;">{v}</div>'
        f'<div style="font-size:12px;color:var(--text-secondary);margin-top:3px;">{l}</div></div>'
        for v, l in items)
    return (f'<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;'
            f'margin:22px 0 26px;">{cells}</div>')


def included(items):
    lis = ''.join(
        f'<li style="display:flex;gap:12px;padding:10px 0;font-size:15px;'
        f'border-bottom:1px solid var(--surface-alt,#F3F2EE);">'
        f'<span aria-hidden="true" style="color:var(--accent);font-weight:700;">&check;</span>'
        f'<span>{i}</span></li>' for i in items)
    return f'<ul style="list-style:none;margin:0 0 26px;padding:0;">{lis}</ul>'


def spec_table(rows):
    trs = ''.join(
        f'<tr style="border-bottom:1px solid var(--surface-alt,#F3F2EE);">'
        f'<td style="padding:12px 24px 12px 0;color:var(--text-secondary);width:220px;'
        f'vertical-align:top;">{k}</td>'
        f'<td style="padding:12px 0;font-weight:500;">{v}</td></tr>' for k, v in rows)
    return (f'<div style="overflow-x:auto;"><table style="width:100%;max-width:700px;'
            f'border-collapse:collapse;font-size:15px;min-width:420px;">{trs}</table></div>')


def steps(items):
    cards = ''.join(
        f'<div class="card" style="padding:24px 18px;text-align:center;">'
        f'<div style="width:38px;height:38px;border-radius:50%;background:var(--accent);'
        f'color:#fff;font-family:var(--font-display);font-weight:700;display:flex;'
        f'align-items:center;justify-content:center;margin:0 auto 12px;">{i+1}</div>'
        f'<h3 style="font-size:15px;margin:0 0 6px;">{t}</h3>'
        f'<p style="font-size:13.5px;color:var(--text-secondary);margin:0;">{b}</p></div>'
        for i, (t, b) in enumerate(items))
    return (f'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));'
            f'gap:18px;max-width:760px;">{cards}</div>')


def faq(qas):
    items = '\n'.join(
        f'<div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">{q}</button>'
        f'<div class="faq-a"><div class="faq-a-inner">{a}</div></div></div>' for q, a in qas)
    ld = ','.join(
        '{"@type":"Question","name":"' + q.replace('"', "'") + '","acceptedAnswer":{"@type":"Answer","text":"'
        + re.sub(r'<[^>]+>', '', a).replace('"', "'") + '"}}' for q, a in qas)
    return (items, '<script type="application/ld+json">{"@context":"https://schema.org",'
            f'"@type":"FAQPage","mainEntity":[{ld}]}}</script>')


def build(cfg):
    global IMG
    IMG = cfg['img']
    fname = cfg['fname']
    crumb_vis, crumb_ld = crumbs(cfg['name'], fname)

    # Product schema. No "authorized reseller", no availability promise we
    # cannot keep, no rating: we have no reviews and will not invent any.
    product_ld = (
        '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product",'
        f'"name":"{cfg["name"]}","sku":"{cfg["sku"]}",'
        f'"image":"https://{DOMAIN}/assets/img/products/{IMG}",'
        f'"description":"{cfg["schema_desc"]}",'
        '"brand":{"@type":"Brand","name":"Starlink"},'
        '"offers":{"@type":"Offer","priceCurrency":"USD",'
        f'"price":"{cfg["price"]}",ّ"url":"https://{DOMAIN}/{fname}",'
        '"seller":{"@type":"Organization","name":"DishNet Africa Ltd"}}}</script>'
    ).replace('ّ', '')
    faq_html, faq_ld = faq(cfg['faqs'])

    order = f"Hi DishNet, I would like to order the {cfg['name']} at ${cfg['price']}. My location: "
    ask = f"Hi DishNet, I have a question about the {cfg['name']}."

    body = f'''<section class="ug-hero" style="padding:132px 0 30px;">
  <div class="container">{crumb_vis}</div>
</section>

<section class="section-sm" style="padding-top:0;">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:52px;align-items:start;"
         class="pd-layout">

      <div class="card" style="padding:26px;display:flex;align-items:center;
           justify-content:center;min-height:320px;">
        <img src="assets/img/products/{IMG}" alt="{cfg['alt']}"
             width="{cfg['w']}" height="{cfg['h']}"
             style="width:100%;height:auto;max-width:460px;display:block;">
      </div>

      <div>
        <span class="badge-label badge-accent">{cfg['badge']}</span>
        <h1 style="font-size:clamp(27px,3.4vw,36px);margin:14px 0 10px;">{cfg['name']}</h1>
        <p style="color:var(--text-secondary);max-width:56ch;">{cfg['sub']}</p>

        <div style="display:flex;align-items:baseline;gap:14px;margin:22px 0 4px;">
          <span style="font-family:var(--font-display);font-size:38px;font-weight:800;">
            ${cfg['price']}</span>
          <span style="font-size:14px;color:var(--text-secondary);">USD &middot; one-time</span>
        </div>
        <p style="font-size:14px;color:var(--text-secondary);margin:0;">
          The kit is bought once. A monthly Starlink plan is billed separately &mdash;
          <a href="starlink-plans-sudan.html">${PLAN_LOW} to ${PLAN_HIGH} a month</a>
          depending on how much data you need.</p>

        {spec_boxes(cfg['boxes'])}

        <h2 style="font-size:16px;margin:0 0 12px;">In the box</h2>
        {included(cfg['included'])}

        <h2 style="font-size:16px;margin:0 0 12px;">Installation</h2>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px;"
             class="pd-install">
          <div class="card" style="padding:16px;">
            <h3 style="font-size:14px;margin:0 0 3px;">Set it up yourself</h3>
            <p style="font-size:12.5px;color:var(--text-secondary);margin:0;">
              Starlink is designed for self-installation.</p>
            <div style="font-family:var(--font-display);font-size:14px;font-weight:700;
                 margin-top:8px;color:var(--accent);">No extra cost</div>
          </div>
          <div class="card" style="padding:16px;">
            <h3 style="font-size:14px;margin:0 0 3px;">We install it</h3>
            <p style="font-size:12.5px;color:var(--text-secondary);margin:0;">
              Site check, mounting, alignment, cabling and WiFi setup.</p>
            <div style="font-family:var(--font-display);font-size:14px;font-weight:700;
                 margin-top:8px;color:var(--accent);">+${INSTALL}</div>
          </div>
        </div>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;">
          {wa(order, f"Order on WhatsApp &mdash; ${cfg['price']}")}
          {wa(ask, 'Ask a question', 'btn btn-ghost')}
        </div>

        <p style="font-size:12.5px;color:var(--text-secondary);margin:0;">
          Genuine Starlink hardware &middot; professional installation available &middot;
          support on WhatsApp</p>
      </div>
    </div>
  </div>
</section>

<section class="section-sm">
  <div class="container" style="max-width:820px;">
    <h2>{cfg['overview_h']}</h2>
    {cfg['overview']}

    <h2 style="margin-top:38px;">Specifications</h2>
    <p style="color:var(--text-secondary);font-size:14px;">
      From Starlink&rsquo;s published specifications for this kit.</p>
    {spec_table(cfg['specs'])}

    <h2 style="margin-top:38px;">Getting it working</h2>
    <p style="color:var(--text-secondary);max-width:70ch;">{cfg['install_note']}</p>
    {steps(cfg['steps'])}
  </div>
</section>

<section class="section-sm">
  <div class="container" style="max-width:820px;">
    <h2>Questions</h2>
    {faq_html}
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <h2>The other kit</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
         gap:20px;max-width:760px;">
      <a href="{cfg['other_href']}" class="card" style="padding:22px;display:block;">
        <img src="assets/img/products/{cfg['other_img']}" alt="{cfg['other_alt']}"
             width="{cfg['other_w']}" height="{cfg['other_h']}" loading="lazy"
             style="width:100%;height:150px;object-fit:contain;margin-bottom:12px;">
        <h3 style="font-size:16px;margin:0 0 4px;">{cfg['other_name']}</h3>
        <div style="font-family:var(--font-display);font-size:19px;font-weight:700;
             color:var(--accent);">${cfg['other_price']}
          <small style="font-size:13px;font-weight:400;color:var(--text-secondary);">
            one-time</small></div>
      </a>
      <a href="starlink-installation-sudan.html" class="card" style="padding:22px;display:block;">
        <h3 style="font-size:16px;margin:0 0 4px;">Professional installation</h3>
        <div style="font-family:var(--font-display);font-size:19px;font-weight:700;
             color:var(--accent);">${INSTALL}
          <small style="font-size:13px;font-weight:400;color:var(--text-secondary);">
            one-time</small></div>
        <p style="font-size:13.5px;color:var(--text-secondary);margin:10px 0 0;">
          What the visit covers, and when you genuinely need one.</p>
      </a>
    </div>
  </div>
</section>

<style>
@media (max-width: 860px) {{
  .pd-layout {{ grid-template-columns: 1fr !important; gap: 30px !important; }}
}}
@media (max-width: 460px) {{
  .pd-install {{ grid-template-columns: 1fr !important; }}
}}
</style>

'''
    html = head(fname, cfg['title'], cfg['desc'], product_ld + crumb_ld + faq_ld) + body + BOTTOM
    open(os.path.join(SITE, fname), 'w', encoding='utf-8').write(html)
    return fname


MINI = dict(
    fname='starlink-mini-kit-sudan.html', sku='starlink-mini-kit',
    name='Starlink Mini Kit', price=MINI_PRICE,
    img='mini-kit.webp', w=1100, h=640,
    alt='Starlink Mini kit with the kickstand extended',
    badge='Portable', 
    title=f'Starlink Mini Kit in Sudan &mdash; ${MINI_PRICE} | DishNet',
    desc=(f'The Starlink Mini kit for ${MINI_PRICE} one-time from DishNet in Sudan. '
          f'About 1.1 kg with WiFi built into the dish. Professional installation ${INSTALL}. '
          f'Monthly plans from ${PLAN_LOW}.'),
    schema_desc=('Starlink Mini satellite internet kit supplied and installed in Sudan by '
                 'DishNet Africa Ltd.'),
    sub=('The size of a laptop and about 1.1&nbsp;kg, with the WiFi router built into the '
         'dish. It travels in a backpack and is online in minutes &mdash; made for field '
         'teams, aid work, travel between cities, and any site that moves.'),
    boxes=[('1.1 kg', 'Weight'), ('50&ndash;150+', 'Mbps download'), ('IP67', 'Dust and rain')],
    included=['Starlink Mini dish, with WiFi built in',
              'Kickstand',
              'Pipe adapter',
              'DC power cable',
              'Power supply and plug'],
    overview_h='Internet you can carry',
    overview=(
        '<p style="max-width:70ch;">The Mini is the smallest terminal Starlink makes. There is '
        'no separate router to find a shelf for and no cable run to plan &mdash; the WiFi is '
        'inside the dish, so the whole installation is the dish, its power lead, and a clear '
        'view of the sky.</p>'
        '<p style="max-width:70ch;">That matters most where the connection has to move. A team '
        'working a week in one place and a week in another does not want a fixed install in '
        'either. Neither does a vehicle, a temporary office, or a site that has not decided '
        'where it is putting the roof yet.</p>'
        '<p style="max-width:70ch;">It draws little enough power to run from a decent power '
        'bank or a small solar setup with the right cable, which is the other reason field '
        'teams choose it over the Standard.</p>'),
    specs=[('Dimensions', '298.5 &times; 259 &times; 38.5&nbsp;mm'),
           ('Weight', '1.1&nbsp;kg'),
           ('Download speed', 'Typically 50&ndash;150+&nbsp;Mbps'),
           ('WiFi', 'Built into the dish &mdash; no separate router'),
           ('Power draw', '25&ndash;40&nbsp;W'),
           ('Weather rating', 'IP67 &mdash; sealed against dust and rain'),
           ('Mounting', 'Kickstand included; pipe adapter in the box')],
    install_note=('Starlink designed the Mini to be set up by the person who bought it, and for '
                  'most sites that is the honest answer. Our installation is worth paying for '
                  'when the dish needs to go somewhere awkward, or when a site survey matters '
                  'more than the setup itself.'),
    steps=[('Power it', 'Unfold the kickstand and connect the power lead.'),
           ('Give it sky', 'Put it where nothing blocks the view upward. The Starlink app '
                           'checks for obstructions before you commit to a spot.'),
           ('Join the WiFi', 'Connect to the Starlink network from your phone or laptop and '
                             'finish setup in the app.')],
    faqs=[('Does the Starlink Mini need a separate router?',
           'No. The WiFi router is built into the dish, which is one of the main differences '
           'between the Mini and the Standard kit.'),
          ('Can I run the Mini from solar or a power bank?',
           'It draws 25&ndash;40&nbsp;W, which is low enough for a good power bank or a small '
           'solar setup, provided you have the right cable for the source you are using.'),
          (f'What do I pay in total to start with the Mini?',
           f'${MINI_PRICE} for the kit, once. Then a monthly Starlink plan, from ${PLAN_LOW} to '
           f'${PLAN_HIGH} depending on data. Installation by us is ${INSTALL} if you want it; '
           f'self-installation costs nothing extra.'),
          ('Is the Mini strong enough for a house or an office?',
           'It can be, for a small one. The Standard kit is faster and its WiFi 6 router covers '
           'far more floor area, so for a home, an office or a guesthouse the Standard is '
           'usually the better fit. The Mini is chosen for portability.')],
    other_href='starlink-standard-kit-sudan.html', other_name='Starlink Standard Kit',
    other_price=STD_PRICE, other_img='standard-kit.webp', other_w=1100, other_h=704,
    other_alt='Starlink Standard kit: dish and WiFi router',
)

STANDARD = dict(
    fname='starlink-standard-kit-sudan.html', sku='starlink-standard-kit',
    name='Starlink Standard Kit', price=STD_PRICE,
    img='standard-kit.webp', w=1100, h=704,
    alt='Starlink Standard kit: dish and WiFi router',
    badge='Most popular',
    title=f'Starlink Standard Kit in Sudan &mdash; ${STD_PRICE} | DishNet',
    desc=(f'The Starlink Standard kit for ${STD_PRICE} one-time from DishNet in Sudan. '
          f'Current-generation kickstand dish with a WiFi 6 router. Professional installation '
          f'${INSTALL}. Monthly plans from ${PLAN_LOW}.'),
    schema_desc=('Starlink Standard satellite internet kit supplied and installed in Sudan by '
                 'DishNet Africa Ltd.'),
    sub=('The current-generation kickstand dish &mdash; no motors, nothing to jam, tougher than '
         'the old actuated design. This is the kit for homes, offices, guesthouses and WiFi '
         'zones, and it comes with a WiFi 6 router.'),
    boxes=[('100&ndash;250', 'Mbps download'), ('297 m&sup2;', 'WiFi coverage'),
           ('IP67', 'Dust and rain')],
    included=['Starlink dish with fixed kickstand',
              'Starlink WiFi 6 router',
              'Starlink cable, dish to router',
              'AC power cable'],
    overview_h='The kit for a building',
    overview=(
        '<p style="max-width:70ch;">The current Standard dish has no moving parts. The older '
        'design motored itself into position, which meant a mechanism that could seize, and in '
        'dust that was a real failure mode. This one is a fixed kickstand: you aim it once and '
        'nothing in it can jam afterwards.</p>'
        '<p style="max-width:70ch;">Its router is the other half of why it suits a building. '
        'WiFi&nbsp;6, roughly 297&nbsp;m&sup2; of coverage and up to 235 devices &mdash; enough '
        'for a family house, an office floor, a guesthouse, or a paid WiFi zone, without adding '
        'access points on day one.</p>'
        '<p style="max-width:70ch;">It is rated to work from &minus;30&deg;C to 50&deg;C and '
        'sealed to IP67, and it is specified to hold up in winds over 96&nbsp;kph.</p>'),
    specs=[('Download speed', 'Typically 100&ndash;250&nbsp;Mbps'),
           ('Router', 'WiFi&nbsp;6'),
           ('WiFi coverage', 'Up to 297&nbsp;m&sup2;, up to 235 devices'),
           ('Operating temperature', '&minus;30&deg;C to 50&deg;C'),
           ('Wind rating', '96+&nbsp;kph'),
           ('Weather rating', 'IP67 &mdash; sealed against dust and rain'),
           ('Mounting', 'Fixed kickstand included; other mounts sold separately')],
    install_note=('Starlink allows self-installation and plenty of Standard kits go in that '
                  'way. The visit earns its keep on a roof or a mast, where getting the '
                  'position right the first time is worth more than the fee.'),
    steps=[('Find the sky', 'Pick a spot with nothing overhead. The Starlink app checks for '
                            'obstructions from where you are standing.'),
           ('Run the cable', 'Dish to router on the supplied cable, then the router to power.'),
           ('Set up the WiFi', 'Name the network and set a password in the Starlink app, and '
                               'the building is online.')],
    faqs=[('What is the difference between the Standard and the Mini?',
           f'The Standard is faster, has a separate WiFi 6 router covering far more floor area, '
           f'and is built to stay where it is put. The Mini is about 1.1&nbsp;kg with its WiFi '
           f'built in, and is meant to move. The Standard is ${STD_PRICE}, the Mini '
           f'${MINI_PRICE}.'),
          ('Does the Standard dish move by itself?',
           'No, and that is deliberate. The current generation uses a fixed kickstand instead '
           'of the motorised mount the older design used, so there is no mechanism to seize.'),
          (f'What do I pay in total to start with the Standard?',
           f'${STD_PRICE} for the kit, once. Then a monthly Starlink plan, from ${PLAN_LOW} to '
           f'${PLAN_HIGH} depending on data. Installation by us is ${INSTALL} if you want it; '
           f'self-installation costs nothing extra.'),
          ('Will one router cover a whole house?',
           'It is rated for up to 297&nbsp;m&sup2; and 235 devices, which covers most homes and '
           'small offices. Thick walls and long buildings can still leave gaps, and those are '
           'solved by adding access points rather than by a second kit.')],
    other_href='starlink-mini-kit-sudan.html', other_name='Starlink Mini Kit',
    other_price=MINI_PRICE, other_img='mini-kit.webp', other_w=1100, other_h=640,
    other_alt='Starlink Mini kit with the kickstand extended',
)


def main():
    made = [build(MINI), build(STANDARD)]

    # Link them from the kit cards, so the pages are reachable and not orphans.
    # The anchor has to wrap the whole slot and close after it -- opening one
    # before the div and never closing it left the page with unbalanced tags.
    p = os.path.join(SITE, 'starlink-kits.html')
    t = open(p, encoding='utf-8').read()
    t = re.sub(r'<a href="starlink-(?:mini|standard)-kit-sudan\.html" aria-label="[^"]*">'
               r'(?=<div class="kit-photo")', '', t)          # drop any earlier attempt
    hrefs = {'mini': (MINI['fname'], 'Mini'), 'standard': (STANDARD['fname'], 'Standard')}

    def wrap(m):
        href, label = hrefs[m.group(1)]
        return (f'<a href="{href}" aria-label="{label} kit details" '
                f'style="display:block;">{m.group(0)}</a>')

    t, n = re.subn(r'<div class="kit-photo" data-kit="(mini|standard)">.*?</div>',
                   wrap, t, flags=re.S)
    open(p, 'w', encoding='utf-8').write(t)
    print(f'kit cards: {n} photo slots linked to their product pages')

    # Sitemap.
    sp = os.path.join(SITE, 'sitemap.xml')
    s = open(sp, encoding='utf-8').read()
    for f in made:
        if f not in s:
            s = s.replace('</urlset>', f'  <url><loc>https://{DOMAIN}/{f}</loc>'
                                       f'<changefreq>monthly</changefreq>'
                                       f'<priority>0.9</priority></url>\n</urlset>')
    open(sp, 'w', encoding='utf-8').write(s)
    print('built: ' + ', '.join(made))


if __name__ == '__main__':
    main()
