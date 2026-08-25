DishNet Africa — Live Portal Demo
==================================
Built from production plugin source v4.21.74 (dishnet-hybrid-telecom).

THREE LIVE TENANTS
  index.html               Persona picker landing page

  embassy/                 Embassy of Nordhavn · 4 sites · Starlink-only
                           - Maria Costa, First Secretary
                           - 1 chancery + 3 residences (incl. summer compound in Yei)
                           - Compact mission, mission-critical SLA story
                           18 HTML pages

  ngo/                     Bright Future Initiative · 32 sites · multi-sector
                           - Sarah Achieng, Country Director
                           - 14 clinics + 11 schools + 7 offices
                           - 8 Fiber + 24 Starlink across 5 regions
                           - 1 paused (Yirol — generator repair)
                           - 1 inactive (Renk — programme ended)
                           73 HTML pages

  un/                      UN Mission · UNCOM · 67 sites · countrywide
                           - David Mensah, ICT Coordinator
                           - 12 fiber UN Houses + 55 Starlink field sites
                           - All 11 South Sudan regions covered
                           - Largest fleet for "scale" demo
                           132 HTML pages

EVERY PERSONA INCLUDES
  login.html               OTP-style login (auto-success on Continue)
  home.html                Customer portal home with hybrid pill toggle
  sites.html               Sites list grouped by region, paused/inactive split
  sites/{id}.html          Per-site detail: usage, daily trend, monthly history,
                           GPS coords, uptime, connected devices, actions
  subscriptions.html       Data-report style dark dashboard (mirrors
                           crm.dishnetafrica.com/_plugins/dishnet-data-report/public.php)
  sub-{id}.html            Per-subscription detail in dark mode
  invoices.html            Year-to-date paid summary + invoice list
  account.html             Profile + billing contact + service summary
  service_status.html      Real-time fleet status with 24h uptime visualization
  wifi.html                WiFi credential change picker
  devices.html             Connected devices view
  hotspot.html             Hotspot mode dashboard

DEPLOY
  Upload the entire 'demo' folder to: dishnetafrica.com/demo/
  Pure HTML/CSS - no build step, no server-side code, no APIs called.
  Total: 224 files, 7.7 MB.

NOT INDEXED
  Every page has <meta name="robots" content="noindex,nofollow">.
  /robots.txt explicitly disallows all crawlers.
  Demo banner at top of every page identifies it as illustrative.

DESIGN FIDELITY
  CSS verbatim from production portal.php (380 lines, Barlow Condensed +
  Barlow body fonts, dark hero with red gradient, .home-bal cards, .list-card
  primitives, .pill statuses, .scr-head dark hero).
  All 34 SVG icons inline-extracted from production sprite.
  Site detail / subscription detail pages mirror the production data-report
  screenshots pixel-perfect (KIT302666781 / 163.8 GB / 11.2 today / 30.8
  yesterday / 7-day daily bars / 8-month history).

SHARING
  Best shared by direct URL: dishnetafrica.com/demo/
  Or pointing prospects directly at one persona:
    dishnetafrica.com/demo/ngo/
    dishnetafrica.com/demo/embassy/
    dishnetafrica.com/demo/un/

QUESTIONS / FEEDBACK
  +211 921 443 002 - info@dishnetafrica.com
