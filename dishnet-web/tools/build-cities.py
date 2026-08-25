#!/usr/bin/env python3
"""Generate one landing page per Sudanese city.

City pages are the highest-value local SEO available here, and the fastest way
to waste them is to spin twelve near-identical pages from one template. Google
calls that a doorway page: it picks one and filters the rest, so you get a
fraction of the traffic you built for. The Uganda build measured 88.6% identical
between cities.

So the differentiating text lives in countries/sudan-cities.json, one entry per
city -- terrain, economy, state, why satellite specifically suits that place --
and this only assembles it. Nothing here asserts an install time, an office or
a customer count, because none of those are established.

Run:  python3 tools/build-cities.py
"""
import json, os, re, sys

ROOT  = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE  = os.path.join(ROOT, 'site')
CFG   = json.load(open(os.path.join(ROOT, 'countries', 'sudan-cities.json')))
DOMAIN = 'dishnetsudan.com'
SHELL  = os.path.join(SITE, 'why-dishnet.html')

src = open(SHELL, encoding='utf-8').read()
cut = re.search(r'<(section|main)\b', src).start()
top, bottom = src[:cut], src[src.index('<footer'):]

def head(t, city, title, desc):
    slug = city['slug']
    url = (f"https://{DOMAIN}/coverage.html" if slug == 'coverage'
           else f"https://{DOMAIN}/starlink-{slug}.html")
    t = re.sub(r'<title>.*?</title>', f'<title>{title}</title>', t, flags=re.S)
    for k in ('name="description"', 'property="og:description"', 'name="twitter:description"'):
        t = re.sub(rf'(<meta {re.escape(k)} content=")[^"]*(")', lambda m: m.group(1)+desc+m.group(2), t)
    for k in ('property="og:title"', 'name="twitter:title"'):
        t = re.sub(rf'(<meta {re.escape(k)} content=")[^"]*(")', lambda m: m.group(1)+title+m.group(2), t)
    t = re.sub(r'(<link rel="canonical" href=")[^"]*(")', lambda m: m.group(1)+url+m.group(2), t)
    t = re.sub(r'(<meta property="og:url" content=")[^"]*(")', lambda m: m.group(1)+url+m.group(2), t)
    t = re.sub(r'(<link rel="alternate" hreflang="[^"]*" href=")[^"]*(")', lambda m: m.group(1)+url+m.group(2), t)
    # Service schema scoped to this city, alongside the site-wide LocalBusiness.
    svc = ('<script type="application/ld+json">{"@context":"https://schema.org",'
           '"@type":"Service","serviceType":"Starlink satellite internet installation",'
           f'"provider":{{"@type":"Organization","name":"DishNet Africa Ltd","url":"https://{DOMAIN}/"}},'
           f'"areaServed":{{"@type":"City","name":"{city["name"]}","alternateName":"{city["ar"]}",'
           f'"containedInPlace":{{"@type":"AdministrativeArea","name":"{city["state"]} State"}},'
           '"address":{"@type":"PostalAddress","addressCountry":"SD"}},'
           f'"description":"{desc}"}}</script>\n')
    return t.replace('</head>', svc + '</head>', 1)

def band(c):
    k = c['km']
    return 'capital' if k <= 10 else 'near' if k < 350 else 'mid' if k < 700 else 'far'

def faqs(c):
    """Pick four questions that actually fit this city.

    A fixed set repeated across 26 pages is what makes them read as generated.
    Selection is deterministic -- same city, same questions -- and every answer
    names something true of that place."""
    n, st, reg = c['name'], c['state'], c['region']
    pool = []
    pool.append((f"Does Starlink actually work in {n}?",
        f"Yes. It is a satellite service, so it does not depend on the terrestrial network in {n} "
        f"or on the long route back to Khartoum. What it does need is an unobstructed view of the "
        f"sky — no roof overhang, no tall trees, nothing directly above the dish."))
    if band(c) in ('mid', 'far'):
        pool.append((f"Is the connection slower this far from Khartoum?",
            f"No, and this is the part people find hardest to believe. {n} is {distance(c)}, but a "
            f"satellite link goes up and comes back down — it never travels overland. A subscriber "
            f"in {n} and one in central Khartoum are on the same service."))
    if band(c) == 'capital':
        pool.append((f"Why use satellite in {n} when there is a fixed network?",
            f"Because it is independent of it. When the local exchange, the power to it, or the "
            f"route out of the city is disrupted, a dish on your roof is unaffected — it has no "
            f"dependency on any of them."))
    if c['conflict_affected']:
        pool.append((f"What happens if the network in {n} goes down?",
            f"Nothing, as far as your connection is concerned. That independence is the main reason "
            f"organisations in {n} choose satellite: there is no local infrastructure between you "
            f"and the service that can fail."))
    if reg in ('Darfur', 'Kordofan'):
        pool.append((f"Do you really reach {reg}?",
            f"Coverage is not something we build out to {n} — it is already there. Starlink covers "
            f"all of Sudan, so serving {n} is a question of getting the kit to you and installing "
            f"it properly, not of extending a network."))
    if reg in ('Nubia', 'northern Nile valley', 'Red Sea coast'):
        pool.append((f"Can it handle the heat and dust around {n}?",
            f"The hardware is built for outdoor installation and rated for the conditions. What "
            f"matters more is the install: proper mounting, a cable route that is not left exposed, "
            f"and a position where wind-blown sand is not driven straight at the dish."))
    pool.append((f"Can I take the kit with me if I leave {n}?",
        f"Yes. The kit is yours and it is portable — people move between sites across {st} State "
        f"and further with the same dish. The Mini in particular is built to be moved."))
    pool.append((f"Who installs it in {n}?",
        f"We supply the kit and arrange professional installation: mounting, alignment and getting "
        f"your devices onto the network. Message us on WhatsApp and say where in {n} you are."))
    # Deterministic, and different per city because the pool itself differs.
    seed = sum(ord(x) for x in c['slug'])
    picked, seen = [], set()
    order = list(range(len(pool)))
    order = order[seed % len(order):] + order[:seed % len(order)]
    for i in order:
        if pool[i][0] not in seen:
            picked.append(pool[i]); seen.add(pool[i][0])
        if len(picked) == 4: break
    return picked

def distance(c):
    if not c['km']:
        return "the capital itself"
    if c['km'] <= 10:
        return f"minutes {c['dir']}"
    return f"roughly {c['km']:,} km {c['dir']}"

def neighbours_line(c, cities):
    same = [x for x in cities if x['region'] == c['region'] and x['slug'] != c['slug']]
    if not same:
        return f"{c['name']} is our reach into the {c['region']} — the full list of cities is on the <a href=\"coverage.html\">coverage page</a>."
    links = ' &middot; '.join(f'<a href="starlink-{x["slug"]}.html">{x["name"]}</a>' for x in same[:5])
    return (f"Also in the {c['region']}: {links}. "
            f"The full directory is on the <a href=\"coverage.html\">coverage page</a>.")

def page(c):
    n, ar, st = c['name'], c['ar'], c['state']
    # The capital has no 'distance from the capital'; saying so read as a
    # dangling fragment on its own page.
    place_clause = '' if not c['km'] else f", {distance(c)}"
    # "Starlink in Khartoum, Khartoum" read as a bug in the tab. When the city
    # names its own state, the state adds nothing.
    place = n if n == st else f"{n}, {st}"
    title = f"Starlink in {place} — Installation &amp; Support | DishNet Sudan"
    # Google truncates around 165 characters; long city characters blew past it.
    desc = f"Starlink satellite internet in {n}, {st} State — kit supply, professional installation and local support from DishNet Sudan."
    areas = ''.join(f'<span class="badge-label" style="margin:0 6px 6px 0;display:inline-block;">{a}</span>' for a in c['areas'])
    faq_html = '\n'.join(
        f'    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">{q}</button>\n'
        f'      <div class="faq-a"><div class="faq-a-inner">{a}</div></div></div>'
        for q, a in faqs(c))
    body = f'''<section class="ug-hero" style="padding:150px 0 40px;">
  <div class="container">
    <span class="badge-label">{st} State · {c['region']}</span>
    <h1>Starlink in {n}<br><span style="font-size:.62em;opacity:.75;font-weight:500;">{ar}</span></h1>
    <p style="max-width:640px;">{n} is {c['character']}{place_clause}. Starlink needs no cable,
       no exchange and no local network — just a clear view of the sky.</p>
    <div style="margin-top:22px;">
      <a href="contact.html" class="btn btn-primary">Get a quote for {n}</a>
      <a href="starlink-kits.html" class="btn btn-ghost">See the kits</a>
    </div>
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <h2>Why satellite works in {n}</h2>
    <p style="max-width:720px;">{c['why']}</p>
    <p style="max-width:720px;">Because the connection comes from orbit rather than
       from a local exchange, the speed you get in {n} is the same as anywhere else
       in {st} State — distance from Khartoum makes no difference to it.</p>
    <p style="max-width:720px;">{c['local_note']}</p>
    <h3 style="margin-top:26px;">Areas we are asked about most in {n}</h3>
    <div style="margin-top:10px;">{areas}</div>
    <p style="max-width:720px;margin-top:14px;font-size:.94em;opacity:.8;">
       Anywhere in and around {n} with an unobstructed view of the sky can be installed.
       If you are outside these areas, ask — the list reflects demand, not a boundary.</p>
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <h2>{n} questions, answered</h2>
{faq_html}
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <h2>Nearby, and next steps</h2>
    <p style="max-width:720px;">{{neighbours}}</p>
    <p style="max-width:720px;">See <a href="starlink-plans-sudan.html">the five plans compared</a>,
       <a href="starlink-price-sudan.html">every price on one page</a>, and
       <a href="starlink-installation-sudan.html">what professional installation includes</a>.</p>
  </div>
</section>

<section class="section-sm">
  <div class="container" style="text-align:center;">
    <h2>Get connected in {n}</h2>
    <p style="max-width:560px;margin:0 auto 20px;">Tell us where you are and what you need it for,
       and we will tell you which kit fits and what it costs.</p>
    <a href="contact.html" class="btn btn-primary">Talk to us about {n}</a>
  </div>
</section>

'''
    body = body.replace('{neighbours}', neighbours_line(c, CFG['cities']))
    return head(top, c, title, desc) + body + bottom

def main():
    cities = [c for c in CFG['cities'] if c.get('publish', True)]
    for c in cities:
        open(os.path.join(SITE, f"starlink-{c['slug']}.html"), 'w', encoding='utf-8').write(page(c))
    print(f"  wrote {len(cities)} city pages")

    # Directory of every city, grouped by region, for coverage.html to embed.
    by = {}
    for c in cities: by.setdefault(c['region'], []).append(c)
    out = ['<section class="section-sm"><div class="container">',
           '<h2>Where we install</h2>',
           '<p style="max-width:720px;">Starlink covers all of Sudan. These are the cities we are '
           'asked about most often — if yours is not listed, it does not mean we cannot reach it.</p>']
    for region in sorted(by):
        out.append(f'<h3 style="margin-top:26px;">{region.capitalize()}</h3><p>')
        out.append(' · '.join(
            f'<a href="starlink-{c["slug"]}.html">{c["name"]}</a> <span style="opacity:.6;">{c["ar"]}</span>'
            for c in sorted(by[region], key=lambda x: x['name'])))
        out.append('</p>')
    out.append('</div></section>')
    directory = "\n".join(out)

    # coverage.html was a fibre availability checker listing Juba
    # neighbourhoods. With no fibre in Sudan it becomes the Starlink coverage
    # page, and the index every city page links back to.
    title = "Starlink Coverage in Sudan &mdash; Every City | DishNet Sudan"
    desc = ("Starlink covers all of Sudan. Kit supply, professional installation and "
            f"support in {len(cities)} cities across all 18 states, from Khartoum to Wadi Halfa.")
    hero = (
      '<section class="ug-hero" style="padding:150px 0 40px;">\n'
      '  <div class="container">\n'
      '    <span class="badge-label">Coverage</span>\n'
      '    <h1>Starlink covers<br>all of Sudan</h1>\n'
      '    <p style="max-width:660px;">There is no coverage map to check and no waiting list to\n'
      '       join. Starlink is a satellite service, so the question is never whether the network\n'
      '       reaches your town &mdash; it is whether you have a clear view of the sky. It works the\n'
      '       same in Wadi Halfa as it does in Khartoum.</p>\n'
      '    <div style="margin-top:22px;">\n'
      '      <a href="contact.html" class="btn btn-primary">Ask about your location</a>\n'
      '      <a href="starlink-kits.html" class="btn btn-ghost">See the kits</a>\n'
      '    </div>\n'
      '  </div>\n'
      '</section>\n\n')
    stub = {'slug': 'coverage', 'name': 'Sudan', 'ar': 'السودان', 'state': 'Sudan'}
    cov = head(top, stub, title, desc)
    open(os.path.join(SITE, 'coverage.html'), 'w', encoding='utf-8').write(cov + hero + directory + bottom)
    print(f"  rebuilt coverage.html: {len(by)} regions, {len(cities)} cities")

main()
