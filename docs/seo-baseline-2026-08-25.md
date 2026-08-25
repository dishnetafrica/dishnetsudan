# SEO baseline — dishnetsudan.com

Captured 2026-08-25 at commit `7b03216`, BEFORE the Phase 1 SEO build.
Compare future Search Console data against this state.

- Indexable pages: **61** (7 noindexed by design)
- Pages missing H1: **3**
- Meta descriptions over 165 chars: **28**
- Sitemap URLs: **61**
- Schema coverage: `{'LocalBusiness': 42, 'PostalAddress': 42, 'ContactPage': 1, 'Organization': 30, 'City': 27, 'AdministrativeArea': 27, 'Service': 27, 'Answer': 1, 'Question': 1, 'FAQPage': 1, 'Offer': 1, 'Brand': 1, 'Product': 1, 'HowTo': 19, 'HowToTool': 18, 'ItemList': 1}`

## Pages that do not exist yet (Phase 1 creates them)
- /starlink-price-sudan.html
- /starlink-installation-sudan.html
- /starlink-plans-sudan.html
- /starlink-priority-500gb-sudan.html
- /starlink-priority-1tb-sudan.html
- /starlink-priority-2tb-sudan.html
- /starlink-priority-3tb-sudan.html
- /starlink-priority-5tb-sudan.html

## Record in Search Console once verified (before deploy if possible)
- Indexed page count (Pages report)
- Impressions/clicks for: starlink sudan · starlink price sudan · starlink installation sudan
- Homepage average position for 'starlink sudan'

## Known pre-existing issues fixed in Phase 1
- Unknown URLs returned the homepage with HTTP 200 (soft 404)
- 3 pages without H1; 28 over-length metas; 'Khartoum, Khartoum' title bug
- Brand-first homepage title; unsupported 'official reseller'/'certified' wording
- External Google Fonts; 6 hotlinked images (server-side script still required)
