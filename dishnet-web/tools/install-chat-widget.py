#!/usr/bin/env python3
"""Put the chat widget on every published page.

The script tag carries its own configuration, so moving the endpoint later is
one edit here and a re-run rather than a search across ninety files. Held pages
are skipped: they are noindexed and unlinked, and a chat box on them would
answer questions about products we are not publishing.

Run:  python3 tools/install-chat-widget.py
"""
import os, re, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.path.join(ROOT, 'site')

ENDPOINT = ('https://crm.dishnetsudan.com/crm/_plugins/dishnet-hybrid-sudan/'
            'public.php?page=web_chat')
WHATSAPP = '249900083481'

HELD = {'fiber.html', 'coverage-old.html', 'testimonials.html', 'gallery.html',
        'blog-starlink-south-sudan.html', 'pay.html', 'hotspot.html',
        'security.html', 'reseller.html'}

MARK = 'assets/js/chat.js'


def tag(depth: int) -> str:
    prefix = '../' * depth
    return (f'<script src="{prefix}assets/js/chat.js" '
            f'data-endpoint="{ENDPOINT.replace("&", "&amp;")}" '
            f'data-whatsapp="{WHATSAPP}" '
            f'data-privacy="{prefix}privacy.html" defer></script>')


def main():
    added = skipped = refreshed = 0
    for f in sorted(glob.glob(os.path.join(SITE, '**', '*.html'), recursive=True)):
        base = os.path.basename(f)
        if base in HELD:
            skipped += 1
            continue
        depth = os.path.relpath(f, SITE).count(os.sep)
        t = open(f, encoding='utf-8').read()
        want = tag(depth)

        if MARK in t:
            # Re-point an existing tag rather than stacking a second one.
            new = re.sub(r'<script src="[^"]*assets/js/chat\.js"[^>]*></script>', want, t)
            if new != t:
                open(f, 'w', encoding='utf-8').write(new)
                refreshed += 1
            continue

        if '</body>' not in t:
            continue
        open(f, 'w', encoding='utf-8').write(t.replace('</body>', want + '\n</body>', 1))
        added += 1

    print(f'chat widget: {added} added, {refreshed} updated, {skipped} held pages skipped')


if __name__ == '__main__':
    main()
