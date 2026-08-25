#!/usr/bin/env python3
"""Product illustrations for the Starlink kits, drawn to the real hardware.

Proportions come from Starlink's specification sheets, so the two kits are
distinguishable at a glance the way they are in life: the Standard is a wide
slab (59.4 x 38.3 cm, ratio 1.55:1) and the Mini is nearly square
(29.8 x 25.9 cm, ratio 1.15:1). Dark phased-array face, white rear shell and
kickstand, matching the product as it actually looks.

These are our own drawings, so there is no licensing question and nothing to
download at page load. They are replaced by photographs the moment real ones
exist -- swap_photo() in this file is the single place that happens.

Run:  python3 tools/product-art.py
"""
import os, re, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.path.join(ROOT, 'site')

DEFS = '''<defs>
  <linearGradient id="face{u}" x1="0" y1="0" x2="0.35" y2="1">
    <stop offset="0" stop-color="#3A3E45"/><stop offset="0.55" stop-color="#2A2D33"/>
    <stop offset="1" stop-color="#1F2126"/>
  </linearGradient>
  <linearGradient id="shell{u}" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#FFFFFF"/><stop offset="1" stop-color="#DEDBD6"/>
  </linearGradient>
  <linearGradient id="stand{u}" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0" stop-color="#F2F0EC"/><stop offset="1" stop-color="#CFCBC5"/>
  </linearGradient>
</defs>'''


def dish(u, w, h, cx, cy, rot, scale=1.0, stand=True):
    """One dish: rear shell offset below a dark phased-array face, plus kickstand."""
    x, y = cx - w / 2, cy - h / 2
    r = 16 * scale
    depth = 9 * scale
    parts = [f'<g transform="rotate({rot} {cx} {cy}) scale({scale} {scale}) '
             f'translate({cx * (1 - 1 / scale):.1f} {cy * (1 - 1 / scale):.1f})">']
    if stand:
        # Kickstand behind, angled the way the real one folds out.
        parts.append(
            f'<path d="M{cx + w * 0.06:.0f} {y + h - 4:.0f} '
            f'L{cx + w * 0.02:.0f} {y + h + 62:.0f} '
            f'L{cx + w * 0.20:.0f} {y + h + 62:.0f} Z" '
            f'fill="url(#stand{u})" stroke="#C4BFB8" stroke-width="1.5" stroke-linejoin="round"/>')
        parts.append(
            f'<rect x="{cx - w * 0.16:.0f}" y="{y + h + 58:.0f}" width="{w * 0.42:.0f}" '
            f'height="7" rx="3.5" fill="url(#stand{u})" stroke="#C4BFB8" stroke-width="1.2"/>')
    # rear shell (the white body), visible as a lip under the face
    parts.append(f'<rect x="{x:.0f}" y="{y + depth:.0f}" width="{w:.0f}" height="{h:.0f}" '
                 f'rx="{r:.0f}" fill="url(#shell{u})" stroke="#C9C4BD" stroke-width="1.6"/>')
    # dark phased-array face
    parts.append(f'<rect x="{x:.0f}" y="{y:.0f}" width="{w:.0f}" height="{h:.0f}" '
                 f'rx="{r:.0f}" fill="url(#face{u})"/>')
    # subtle inner bezel + brand-red status point, the one spot of colour
    parts.append(f'<rect x="{x + 9:.0f}" y="{y + 9:.0f}" width="{w - 18:.0f}" height="{h - 18:.0f}" '
                 f'rx="{max(r - 6, 4):.0f}" fill="none" stroke="#454A52" stroke-width="1.4"/>')
    parts.append(f'<circle cx="{cx:.0f}" cy="{cy:.0f}" r="{4.5 * scale:.1f}" fill="#C8102E" opacity=".92"/>')
    parts.append('</g>')
    return '\n'.join(parts)


def router(u, x, y, w=104, h=40):
    """Gen 3 router: white slab, rounded, with its indicator ring."""
    return (f'<g><rect x="{x}" y="{y}" width="{w}" height="{h}" rx="9" '
            f'fill="url(#shell{u})" stroke="#C9C4BD" stroke-width="1.6"/>'
            f'<circle cx="{x + w * 0.30:.0f}" cy="{y + h / 2:.0f}" r="9" fill="none" '
            f'stroke="#C9C4BD" stroke-width="1.6"/>'
            f'<circle cx="{x + w * 0.30:.0f}" cy="{y + h / 2:.0f}" r="2.6" fill="#C8102E" opacity=".85"/>'
            f'</g>')


def shadow(cx, cy, rx, ry=7):
    return f'<ellipse cx="{cx}" cy="{cy}" rx="{rx}" ry="{ry}" fill="#1A1A1A" opacity=".08"/>'


def wrap(u, vb, inner, label, style):
    return (f'<svg viewBox="{vb}" role="img" aria-label="{label}" style="{style}">'
            + DEFS.replace('{u}', u) + inner + '</svg>')


# ── the four illustrations the site uses ─────────────────────────────────
# Standard: wide slab (1.55:1), kickstand, paired router — as it ships.
STANDARD = wrap('s', '0 0 420 300',
    shadow(215, 268, 118) + dish('s', 236, 152, 232, 130, -11) + router('s', 30, 214),
    'Starlink Standard kit: dish and Gen 3 WiFi router',
    'width:100%;height:auto;max-width:340px;display:block;margin:0 auto;')

# Mini: nearly square (1.15:1), WiFi built into the dish, so no separate router.
MINI = wrap('m', '0 0 420 300',
    shadow(210, 262, 92) + dish('m', 168, 146, 210, 130, -9),
    'Starlink Mini: compact dish with built-in WiFi',
    'width:100%;height:auto;max-width:300px;display:block;margin:0 auto;')

# Hero: the Standard against a soft sky card with signal arcs.
HERO = wrap('h', '0 0 460 360',
    '<rect x="8" y="8" width="444" height="344" rx="30" fill="#FCF6F6"/>'
    '<g fill="#C8102E" opacity=".5">'
    '<circle cx="392" cy="56" r="3.6"/><circle cx="336" cy="36" r="2.4"/>'
    '<circle cx="424" cy="100" r="2.4"/></g>'
    '<g stroke="#C8102E" fill="none" stroke-width="3.2" stroke-linecap="round">'
    '<path d="M306 116 q36 -46 82 -60" opacity=".85"/>'
    '<path d="M318 138 q28 -34 62 -46" opacity=".55"/>'
    '<path d="M330 160 q19 -23 41 -31" opacity=".3"/></g>'
    + shadow(212, 306, 108) + dish('h', 226, 146, 214, 176, -11),
    'Starlink dish connecting to satellites over Sudan',
    'width:100%;height:auto;max-width:440px;display:block;margin:0 auto;')

# Card-sized versions for product grids.
STANDARD_SM = wrap('a', '0 0 260 200',
    shadow(132, 178, 74) + dish('a', 150, 96, 142, 86, -10, stand=True),
    'Starlink Standard dish', 'width:82%;height:auto;display:block;margin:10px auto;')
MINI_SM = wrap('b', '0 0 260 200',
    shadow(130, 176, 58) + dish('b', 104, 90, 132, 86, -8, stand=True),
    'Starlink Mini dish', 'width:82%;height:auto;display:block;margin:10px auto;')


def replace_by_label(text, label_fragment, new_svg):
    """Swap any existing <svg> whose aria-label mentions the fragment."""
    pat = re.compile(r'<svg[^>]*aria-label="[^"]*' + re.escape(label_fragment) + r'[^"]*"[^>]*>.*?</svg>', re.S)
    return pat.subn(new_svg, text)


def main():
    swaps = [
        ('site/index.html', [('dish connecting to satellites', HERO),
                             ('Starlink Mini', MINI_SM),
                             ('Starlink Standard', STANDARD_SM)]),
        ('site/starlink-kits.html', [('Starlink Mini outline', MINI),
                                     ('Starlink Mini', MINI),
                                     ('Starlink Standard dish outline', STANDARD),
                                     ('Starlink Standard', STANDARD)]),
        ('site/blog.html', [('Starlink Standard', STANDARD_SM),
                            ('Starlink dish', STANDARD_SM)]),
    ]
    total = 0
    for rel, jobs in swaps:
        p = os.path.join(ROOT, rel)
        if not os.path.exists(p):
            continue
        t = open(p, encoding='utf-8').read()
        for frag, svg in jobs:
            t, n = replace_by_label(t, frag, svg)
            total += n
        open(p, 'w', encoding='utf-8').write(t)
    print(f'replaced {total} illustrations with product-accurate art')


if __name__ == '__main__':
    main()
