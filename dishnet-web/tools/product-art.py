#!/usr/bin/env python3
"""Product visuals for the Starlink kits.

Real photographs if we have them, drawings if we do not.

Drop photos into site/assets/img/products/ named standard-kit.* and mini-kit.*
(jpg, png or webp) and run this script: every illustration on the site is
replaced by an <img> pointing at the local file, with width, height and alt
filled in so nothing shifts as the page loads. Nothing is hotlinked -- the
site previously pulled these from dishnetafrica.com's CMS and broke when that
host was unreachable, which is the whole reason this file exists.

Until the photos land, the drawings stand in. They are traced from the real
hardware as it is actually photographed: a very thin flat panel at a shallow
angle on a fold-out kickstand, seen in three-quarter view -- not the thick,
steeply-tilted rectangle an icon set would give you. The Standard is a wide
slab shown back-up, so its face reads white with the dark array edge banding
the rim, and it ships with the separate router. The Mini is nearly square,
shown face-up so the dark array is the top surface over a silver underside,
and has no router because its WiFi is built in.

Run:  python3 tools/product-art.py
"""
import os, re, sys, glob

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from imgsize import size as imgsize

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.path.join(ROOT, 'site')
PHOTOS = os.path.join(SITE, 'assets', 'img', 'products')

DEFS = '''<defs>
  <linearGradient id="top{u}" x1="0.1" y1="0" x2="0.9" y2="1">
    <stop offset="0" stop-color="#FFFFFF"/><stop offset="1" stop-color="#E8E5E0"/>
  </linearGradient>
  <linearGradient id="dark{u}" x1="0.1" y1="0" x2="0.9" y2="1">
    <stop offset="0" stop-color="#3C4047"/><stop offset="1" stop-color="#23262B"/>
  </linearGradient>
  <linearGradient id="rim{u}" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0" stop-color="#2B2E33"/><stop offset="1" stop-color="#1A1C20"/>
  </linearGradient>
  <linearGradient id="silver{u}" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0" stop-color="#F4F2EF"/><stop offset="1" stop-color="#CDC9C3"/>
  </linearGradient>
</defs>'''


def _poly(pts):
    return ' '.join(f'{x:.1f},{y:.1f}' for x, y in pts)


def panel(u, p1, p2, depth=9, face='light', stand=None, seam=True):
    """A flat panel in three-quarter view.

    p1 is the far edge as (x, y) of its left end, p2 the far edge's right end;
    the near edge is derived by the fixed viewing offset, so every panel on the
    site shares one camera. face 'light' shows the white back with the array
    banding the rim; face 'dark' shows the array itself over a silver body.
    """
    (x1, y1), (x2, y2) = p1, p2
    ox, oy = -0.216 * (x2 - x1), 0.47 * (x2 - x1) * 0.30   # near-edge offset
    p4 = (x1 + ox, y1 + oy)
    p3 = (x2 + ox, y2 + oy)
    top = [p1, p2, p3, p4]
    parts = []

    if stand:
        # The leg has to start on the panel's underside and finish on the ground,
        # or it reads as a triangle floating in space. Both ends are derived from
        # the panel rather than positioned by hand, so they cannot drift apart.
        frac, ground, lean = stand
        ax = p4[0] + (p3[0] - p4[0]) * frac
        ay = p4[1] + (p3[1] - p4[1]) * frac + depth * 0.7
        parts.append(
            f'<path d="M{ax - 5:.1f} {ay:.1f} L{ax + 6:.1f} {ay:.1f} '
            f'L{ax + lean + 7:.1f} {ground:.1f} L{ax + lean - 4:.1f} {ground:.1f} Z" '
            f'fill="url(#silver{u})" stroke="#BFBAB3" stroke-width="1.3" '
            f'stroke-linejoin="round"/>')

    top_fill = f'url(#top{u})' if face == 'light' else f'url(#dark{u})'
    edge_fill = f'url(#rim{u})' if face == 'light' else f'url(#silver{u})'

    # The near edge extruded downward: the thin band that makes it read as a slab.
    parts.append(f'<polygon points="{_poly([p4, p3, (p3[0], p3[1] + depth), (p4[0], p4[1] + depth)])}" '
                 f'fill="{edge_fill}"/>')
    parts.append(f'<polygon points="{_poly([p2, p3, (p3[0], p3[1] + depth), (p2[0], p2[1] + depth)])}" '
                 f'fill="{edge_fill}" opacity=".82"/>')
    parts.append(f'<polygon points="{_poly(top)}" fill="{top_fill}" '
                 f'stroke="{"#1F2126" if face == "light" else "#191B1F"}" stroke-width="1.6" '
                 f'stroke-linejoin="round"/>')
    if seam:
        # Inset seam line: the panel edge, a millimetre in from the rim.
        ins = [(x + (top[(i + 2) % 4][0] - x) * 0.035, y + (top[(i + 2) % 4][1] - y) * 0.035)
               for i, (x, y) in enumerate(top)]
        parts.append(f'<polygon points="{_poly(ins)}" fill="none" '
                     f'stroke="{"#C9C4BD" if face == "light" else "#4A4F57"}" stroke-width="1.1" '
                     f'opacity=".8"/>')
    return '\n'.join(parts)


def router(u, x, y, w=126, h=60):
    """Gen 3 router: a plain white slab with its indicator ring."""
    return (f'<g><rect x="{x}" y="{y}" width="{w}" height="{h}" rx="7" '
            f'fill="url(#top{u})" stroke="#C9C4BD" stroke-width="1.5"/>'
            f'<circle cx="{x + w * 0.31:.0f}" cy="{y + h / 2:.0f}" r="{h * 0.24:.0f}" fill="none" '
            f'stroke="#CFCAC3" stroke-width="1.5"/>'
            f'<circle cx="{x + w * 0.31:.0f}" cy="{y + h / 2:.0f}" r="2.4" fill="#C8102E" opacity=".8"/>'
            f'</g>')


def shadow(cx, cy, rx, ry=6):
    return f'<ellipse cx="{cx}" cy="{cy}" rx="{rx}" ry="{ry}" fill="#1A1A1A" opacity=".07"/>'


def wrap(u, vb, inner, label, style):
    return (f'<svg viewBox="{vb}" role="img" aria-label="{label}" style="{style}">'
            + DEFS.replace('{u}', u) + inner + '</svg>')


# ── the drawings ─────────────────────────────────────────────────────────
# Standard: wide slab shown back-up beside its router, as Starlink shoots it.
STANDARD = wrap('s', '0 0 420 300',
    shadow(250, 236, 118) + router('s', 14, 176)
    + panel('s', (150, 112), (400, 184), depth=10, face='light', stand=(0.46, 236, 9)),
    'Starlink Standard kit: dish and WiFi router',
    'width:100%;height:auto;max-width:340px;display:block;margin:0 auto;')

# Mini: near-square, face-up so the array is the top surface, no router.
MINI = wrap('m', '0 0 420 300',
    shadow(214, 232, 88)
    + panel('m', (128, 132), (330, 190), depth=8, face='dark', stand=(0.80, 232, 7)),
    'Starlink Mini: compact dish with built-in WiFi',
    'width:100%;height:auto;max-width:300px;display:block;margin:0 auto;')

HERO = wrap('h', '0 0 460 360',
    '<rect x="8" y="8" width="444" height="344" rx="30" fill="#FCF6F6"/>'
    '<g fill="#C8102E" opacity=".5">'
    '<circle cx="392" cy="56" r="3.6"/><circle cx="336" cy="36" r="2.4"/>'
    '<circle cx="424" cy="100" r="2.4"/></g>'
    '<g stroke="#C8102E" fill="none" stroke-width="3.2" stroke-linecap="round">'
    '<path d="M300 150 q40 -56 92 -74" opacity=".85"/>'
    '<path d="M312 172 q32 -42 70 -56" opacity=".55"/>'
    '<path d="M324 194 q22 -28 47 -38" opacity=".3"/></g>'
    + shadow(250, 282, 108)
    + panel('h', (140, 168), (400, 242), depth=10, face='light', stand=(0.46, 282, 9)),
    'Starlink dish connecting to satellites over Sudan',
    'width:100%;height:auto;max-width:440px;display:block;margin:0 auto;')

STANDARD_SM = wrap('a', '0 0 260 200',
    shadow(146, 158, 76) + router('a', 10, 120, w=76, h=36)
    + panel('a', (96, 74), (248, 118), depth=7, face='light', stand=(0.46, 158, 6)),
    'Starlink Standard dish', 'width:82%;height:auto;display:block;margin:10px auto;')

MINI_SM = wrap('b', '0 0 260 200',
    shadow(136, 156, 58)
    + panel('b', (76, 84), (208, 122), depth=6, face='dark', stand=(0.78, 156, 5)),
    'Starlink Mini dish', 'width:82%;height:auto;display:block;margin:10px auto;')


# ── photographs, when they exist ─────────────────────────────────────────
def find_photo(stem):
    for ext in ('webp', 'jpg', 'jpeg', 'png'):
        hits = sorted(glob.glob(os.path.join(PHOTOS, f'{stem}.{ext}')))
        if hits:
            return hits[0]
    return None


def photo_tag(stem, alt, style, depth):
    """<img> for a real photo, or None. depth = how deep the page is in the tree."""
    p = find_photo(stem)
    if not p:
        return None
    dim = imgsize(p)
    if not dim:
        print(f'  ! {os.path.basename(p)}: unrecognised image format, skipped')
        return None
    w, h, _ = dim
    kb = os.path.getsize(p) / 1024
    if kb > 400:
        print(f'  ! {os.path.basename(p)} is {kb:.0f} KB -- compress it before shipping')
    prefix = '../' * depth
    src = f'{prefix}assets/img/products/{os.path.basename(p)}'
    return (f'<img src="{src}" alt="{alt}" width="{w}" height="{h}" '
            f'loading="lazy" decoding="async" style="{style}">')


def visual(stem, alt, drawing, style, depth=0):
    return photo_tag(stem, alt, style, depth) or drawing


def replace_by_label(text, label_fragment, new_markup):
    """Swap any <svg> or <img> whose aria-label/alt mentions the fragment."""
    frag = re.escape(label_fragment)
    n = 0
    pat_svg = re.compile(r'<svg[^>]*aria-label="[^"]*' + frag + r'[^"]*"[^>]*>.*?</svg>', re.S)
    text, k = pat_svg.subn(lambda m: new_markup, text); n += k
    pat_img = re.compile(r'<img[^>]*alt="[^"]*' + frag + r'[^"]*"[^>]*>')
    text, k = pat_img.subn(lambda m: new_markup, text); n += k
    return text, n


def main():
    # A fixed slot height with object-fit keeps the badge and title rows level
    # across cards whose photos have different aspect ratios.
    card = ('width:100%;height:190px;object-fit:contain;display:block;'
            'margin:2px auto 16px;')
    big = 'width:100%;height:auto;max-width:340px;display:block;margin:0 auto;'
    hero = 'width:100%;height:auto;max-width:440px;display:block;margin:0 auto;'

    std_alt = 'Starlink Standard kit: dish and WiFi router'
    mini_alt = 'Starlink Mini: compact dish with built-in WiFi'

    have = [s for s in ('standard-kit', 'mini-kit') if find_photo(s)]
    print(f'photos found: {", ".join(have) if have else "none -- using drawings"}')

    swaps = [
        ('site/index.html', [
            ('dish connecting to satellites',
             visual('standard-kit', 'Starlink dish connecting to satellites over Sudan', HERO, hero)),
            ('Starlink Mini', visual('mini-kit', mini_alt, MINI_SM, card)),
            ('Starlink Standard', visual('standard-kit', std_alt, STANDARD_SM, card)),
        ]),
        ('site/starlink-kits.html', [
            ('Starlink Mini outline', visual('mini-kit', mini_alt, MINI, big)),
            ('Starlink Mini', visual('mini-kit', mini_alt, MINI, big)),
            ('Starlink Standard dish outline', visual('standard-kit', std_alt, STANDARD, big)),
            ('Starlink Standard', visual('standard-kit', std_alt, STANDARD, big)),
        ]),
        ('site/blog.html', [
            ('Starlink Standard', visual('standard-kit', std_alt, STANDARD_SM, card)),
            ('Starlink dish', visual('standard-kit', std_alt, STANDARD_SM, card)),
        ]),
    ]
    # Cards carry an empty <div class="kit-photo" data-kit="..."> slot; fill it.
    # Label-matching alone cannot reach a slot that has no artwork in it yet.
    slot_art = {'mini': visual('mini-kit', mini_alt, MINI_SM, card),
                'standard': visual('standard-kit', std_alt, STANDARD_SM, card)}

    def fill_slots(text):
        n = 0
        def sub(m):
            nonlocal n
            art = slot_art.get(m.group(1))
            if not art:
                return m.group(0)
            n += 1
            return f'<div class="kit-photo" data-kit="{m.group(1)}">{art}</div>'
        # No lookahead: the slot is now wrapped in an <a> to the product page,
        # so what follows the closing </div> is not what it used to be. The
        # slot holds one <img> or one <svg>, neither of which nests a <div>,
        # so the non-greedy match still stops at the right closing tag.
        text = re.sub(r'<div class="kit-photo" data-kit="([a-z]+)">.*?</div>',
                      sub, text, flags=re.S)
        return text, n

    total = 0
    for rel, jobs in swaps:
        p = os.path.join(ROOT, rel)
        if not os.path.exists(p):
            continue
        t = open(p, encoding='utf-8').read()
        for frag, markup in jobs:
            t, n = replace_by_label(t, frag, markup)
            total += n
        t, n = fill_slots(t)
        total += n
        open(p, 'w', encoding='utf-8').write(t)
    kind = 'photographs' if have else 'drawings'
    print(f'placed {total} product {kind}')


if __name__ == '__main__':
    main()
