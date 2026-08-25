#!/usr/bin/env python3
"""Arabic commercial pages under /ar/ — written, not machine-substituted.

Most Sudanese search in Arabic; almost nobody competes there. These five pages
carry the same approved uCRM figures as the English site (the checkers verify
them too) and pair with their English counterparts through hreflang. RTL,
self-hosted Noto Sans Arabic, minimal Arabic chrome — no half-English shell.

Run:  python3 tools/build-ar-pages.py
"""
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
AR = os.path.join(ROOT, 'site', 'ar')
os.makedirs(AR, exist_ok=True)
DOMAIN = 'dishnetsudan.com'
WA = '249900083481'

# EN counterpart for hreflang pairing (ar file -> en path)
PAIR = {
    'index.html': '/',
    'starlink-price-sudan.html': '/starlink-price-sudan.html',
    'starlink-plans-sudan.html': '/starlink-plans-sudan.html',
    'starlink-installation-sudan.html': '/starlink-installation-sudan.html',
    'starlink-home-sudan.html': '/starlink-home-sudan.html',
}

PLANS = [('ستارلينك برايوريتي 500 جيجابايت', '500GB', 112),
         ('ستارلينك برايوريتي 1 تيرابايت', '1TB', 189),
         ('ستارلينك برايوريتي 2 تيرابايت', '2TB', 336),
         ('ستارلينك برايوريتي 3 تيرابايت', '3TB', 483),
         ('ستارلينك برايوريتي 5 تيرابايت', '5TB', 784)]

NAV = [('index.html', 'الرئيسية'), ('starlink-price-sudan.html', 'الأسعار'),
       ('starlink-plans-sudan.html', 'الباقات'),
       ('starlink-installation-sudan.html', 'التركيب'),
       ('starlink-home-sudan.html', 'للمنزل')]

def shell(fname, title, desc, body):
    en = PAIR[fname]
    url = f'https://{DOMAIN}/ar/{fname}' if fname != 'index.html' else f'https://{DOMAIN}/ar/'
    en_url = f'https://{DOMAIN}{en}'
    nav = ''.join(f'<a href="{h}" style="color:inherit;text-decoration:none;margin-inline-start:18px;">{l}</a>'
                  for h, l in NAV)
    return f'''<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<meta name="description" content="{desc}">
<link rel="canonical" href="{url}">
<link rel="alternate" hreflang="ar" href="{url}">
<link rel="alternate" hreflang="en" href="{en_url}">
<link rel="alternate" hreflang="x-default" href="{en_url}">
<meta property="og:title" content="{title}">
<meta property="og:description" content="{desc}">
<meta property="og:url" content="{url}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="DishNet Sudan">
<meta property="og:locale" content="ar_SD">
<meta property="og:image" content="https://dishnetsudan.com/assets/img/og-dishnet.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{title}">
<meta name="twitter:description" content="{desc}">
<meta name="twitter:image" content="https://dishnetsudan.com/assets/img/og-dishnet.png">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<meta name="robots" content="index,follow">
<meta name="geo.region" content="SD">
<link rel="stylesheet" href="/assets/fonts/fonts.css">
<style>
  :root {{ --accent:#C8102E; --ink:#1A1A1A; --muted:#5A5A58; --bg:#FAFAF8; --line:#E5E4E0; }}
  * {{ box-sizing:border-box; }}
  body {{ margin:0; background:var(--bg); color:var(--ink);
         font:16px/1.9 'Noto Sans Arabic','DM Sans',system-ui,sans-serif; }}
  .bar {{ background:#fff; border-bottom:1px solid var(--line); padding:14px 20px;
          display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }}
  .logo {{ font-weight:700; font-size:20px; color:var(--accent); text-decoration:none; }}
  .logo small {{ color:var(--muted); font-weight:500; }}
  .wrap {{ max-width:820px; margin:0 auto; padding:36px 20px 80px; }}
  h1 {{ font-size:clamp(26px,5vw,38px); line-height:1.35; margin:.4em 0; }}
  h2 {{ font-size:22px; margin:1.6em 0 .4em; }}
  p, li {{ max-width:65ch; }}
  .btn {{ display:inline-block; background:var(--accent); color:#fff; text-decoration:none;
          padding:12px 22px; border-radius:100px; font-weight:700; }}
  .ghost {{ background:transparent; color:var(--accent); border:2px solid var(--accent); }}
  table {{ border-collapse:collapse; width:100%; font-size:15px; }}
  th, td {{ padding:10px 12px; border-bottom:1px solid var(--line); text-align:right; }}
  th {{ color:var(--muted); font-size:13px; }}
  .num {{ font-variant-numeric:tabular-nums; direction:ltr; display:inline-block; }}
  .card {{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:20px 22px; margin:16px 0; }}
  .foot {{ border-top:1px solid var(--line); color:var(--muted); font-size:13.5px;
           padding:18px 20px; text-align:center; }}
  a {{ color:var(--accent); }}
  .scroll {{ overflow-x:auto; }}
</style>
</head>
<body>
<div class="bar">
  <a class="logo" href="index.html">DishNet <small>السودان</small></a>
  <nav style="font-size:14.5px;">{nav}<a href="{en}" style="color:var(--muted);text-decoration:none;margin-inline-start:18px;">English</a></nav>
</div>
<div class="wrap">
{body}
<p style="margin-top:40px;"><a class="btn" href="https://wa.me/{WA}?text=%D9%85%D8%B1%D8%AD%D8%A8%D8%A7%D9%8B%20DishNet">راسلنا على واتساب — نرد فوراً</a></p>
</div>
<div class="foot">DishNet Africa Ltd — ستارلينك في السودان &middot; <a href="{en}">English version</a> &middot; <a href="/privacy.html">الخصوصية</a></div>
</body>
</html>'''

def plan_table():
    rows = ''.join(f'<tr><td>{n}</td><td><span class="num">${p}</span> شهرياً</td></tr>'
                   for n, _, p in PLANS)
    return f'<div class="scroll"><table><tr><th>الباقة</th><th>السعر</th></tr>{rows}</table></div>'

pages = {}

pages['index.html'] = ('ستارلينك في السودان — الباقات والأجهزة والتركيب | DishNet',
 'إنترنت ستارلينك الفضائي في السودان: خمس باقات شهرية من 112 دولاراً، أجهزة من 350 دولاراً، وتركيب احترافي بـ50 دولاراً. اطلب عبر واتساب.',
 f'''<h1>إنترنت ستارلينك، في أي مكان في السودان</h1>
<p>طبقٌ على السطح ورؤية واضحة للسماء — هذا كل ما يحتاجه ستارلينك. لا كوابل تنتظرها،
ولا شبكة محلية يعتمد عليها اتصالك. نوفّر الأجهزة والتركيب والباقات الشهرية، وكل الأسعار
تأتي مباشرة من نظام الفوترة لدينا: ما يقوله مساعدنا على واتساب هو ما تدفعه فعلاً.</p>
<div class="card"><h2 style="margin-top:0;">الأسعار باختصار</h2>
{plan_table()}
<p>الأجهزة (تُدفع مرة واحدة): ستارلينك ميني <span class="num">$350</span> &middot;
الجهاز القياسي <span class="num">$600</span> &middot; التركيب الاحترافي <span class="num">$50</span>.</p>
<p><a href="starlink-price-sudan.html">كل الأسعار في صفحة واحدة ←</a></p></div>
<h2>ابدأ من هنا</h2>
<ul>
<li><a href="starlink-plans-sudan.html">مقارنة الباقات الخمس</a> — أيّها يناسب بيتك أو عملك</li>
<li><a href="starlink-installation-sudan.html">التركيب الاحترافي</a> — ماذا يشمل مبلغ الخمسين دولاراً</li>
<li><a href="starlink-home-sudan.html">ستارلينك للمنزل</a> — الحجم المناسب لعائلتك، وحل انقطاع الكهرباء</li>
</ul>''')

pages['starlink-price-sudan.html'] = ('أسعار ستارلينك في السودان — الباقات والأجهزة | DishNet',
 'كل أسعار ستارلينك في السودان: الباقات من 112 إلى 784 دولاراً شهرياً، الأجهزة من 350 دولاراً، والتركيب 50 دولاراً. بلا رسوم خفية.',
 f'''<h1>كم يكلّف ستارلينك في السودان؟</h1>
<p>نوعان من المال لا نخلطهما أبداً: <strong>دفعة واحدة</strong> للجهاز والتركيب،
و<strong>اشتراك شهري</strong> للباقة. كل ما نُحاسب عليه مذكور في هذه الصفحة — وما ليس
مذكوراً هنا لا نُحاسب عليه.</p>
<h2>الباقات الشهرية</h2>
{plan_table()}
<p>كل باقة تشمل بيانات قياسية غير محدودة بعد انتهاء حصة الأولوية — الاتصال لا ينقطع.</p>
<h2>التكاليف لمرة واحدة</h2>
<div class="scroll"><table>
<tr><th>البند</th><th>السعر</th></tr>
<tr><td>جهاز ستارلينك ميني (محمول)</td><td><span class="num">$350</span> مرة واحدة</td></tr>
<tr><td>الجهاز القياسي (للمنازل والمكاتب)</td><td><span class="num">$600</span> مرة واحدة</td></tr>
<tr><td>التركيب الاحترافي</td><td><span class="num">$50</span> مرة واحدة</td></tr>
</table></div>
<div class="card"><strong>مثال محسوب — البدء بباقة 1 تيرابايت:</strong>
الجهاز القياسي <span class="num">$600</span> + التركيب <span class="num">$50</span> =
<span class="num">$650</span> مرة واحدة، ثم <span class="num">$189</span> شهرياً. الأسعار
بالدولار الأمريكي؛ اسألنا على واتساب عن ترتيبات الدفع المحلية لطلبك.</div>''')

pages['starlink-plans-sudan.html'] = ('باقات ستارلينك في السودان — مقارنة صادقة | DishNet',
 'الباقات الخمس من 112 إلى 784 دولاراً شهرياً: ماذا تعني بيانات الأولوية، وأي باقة تناسب استخدامك فعلاً.',
 f'''<h1>خمس باقات. مقارنة واحدة صادقة.</h1>
<p>كل باقة تحمل حصة «أولوية» — من 500 جيجابايت إلى 5 تيرابايت. ما دمت ضمن الحصة،
لبياناتك أولوية على الشبكة. وعند انتهائها لا ينقطع شيء: تستمر على بيانات قياسية غير
محدودة حتى بداية الشهر التالي.</p>
{plan_table()}
<h2>أيّها يناسبك؟</h2>
<ul>
<li><strong>500 جيجابايت</strong> — منزل خفيف الاستخدام أو مكتب لشخص واحد.</li>
<li><strong>1 تيرابايت</strong> — العائلات والمكاتب الصغيرة؛ الباقة التي نوصي بها أكثر من غيرها.</li>
<li><strong>2 تيرابايت</strong> — استخدام كثيف وأجهزة كثيرة والشركات الصغيرة.</li>
<li><strong>3 تيرابايت</strong> — مكاتب متعددة الفرق ودور الضيافة.</li>
<li><strong>5 تيرابايت</strong> — المنظمات والمؤسسات.</li>
</ul>
<p>غير متأكد؟ مساعدنا على واتساب يسأل سؤالين ثم يوصي بالباقة المناسبة — من هذه الباقات
الخمس نفسها وبالأسعار الحية من نظامنا. <a href="starlink-price-sudan.html">كل الأسعار هنا</a>.</p>''')

pages['starlink-installation-sudan.html'] = ('تركيب ستارلينك في السودان — 50 دولاراً | DishNet',
 'تركيب ستارلينك الاحترافي في السودان بـ50 دولاراً: التثبيت والتوجيه والتمديد وإعداد الشبكة وتدريبك على التطبيق.',
 f'''<h1>تركيب ستارلينك الاحترافي</h1>
<p>ستارلينك مصمَّم أصلاً ليركّبه صاحبه بنفسه، ولن ندّعي غير ذلك. لكن التركيب الاحترافي
بـ<span class="num">$50</span> يستحق ثمنه عندما يحتاج الطبق تثبيتاً دائماً على سطح أو
عمود، أو عندما يجب تمديد الكابل عبر المبنى بشكل سليم، أو عندما تريد شبكة المكتب جاهزة
من أول يوم.</p>
<h2>ماذا يشمل مبلغ الخمسين دولاراً؟</h2>
<ul>
<li><strong>فحص الموقع</strong> — إيجاد المكان ذي الرؤية الأوضح للسماء، وهو ما يقرر كل شيء في أداء ستارلينك.</li>
<li><strong>التثبيت</strong> — على الحامل الأرضي أو الجدار أو السطح أو عمود.</li>
<li><strong>التمديد</strong> — كابل الـ15 متراً الملحق بالجهاز، ممدوداً بشكل آمن ونظيف.</li>
<li><strong>إعداد الشبكة</strong> — وضع الراوتر، تسمية الشبكة وتأمينها، وربط أجهزتك.</li>
<li><strong>التسليم</strong> — تطبيق ستارلينك على هاتفك وشرح ما يعرضه.</li>
</ul>
<p>أخبرنا بموقعك على واتساب ونؤكد لك الترتيبات بصدق — نفضّل التأكيد الصادق على الوعد
الأعمى. الأجهزة نفسها في <a href="starlink-price-sudan.html">صفحة الأسعار</a>.</p>''')

pages['starlink-home-sudan.html'] = ('ستارلينك للمنزل في السودان — أي باقة تناسبك | DishNet',
 'إنترنت منزلي عبر ستارلينك في السودان: كيف تختار الباقة بحساب بسيط، وكيف يستمر الإنترنت رغم انقطاع الكهرباء.',
 f'''<h1>إنترنت منزلي لا يعتمد على أي شيء محلي</h1>
<p>عدّ من يشاهدون البث أو يجرون مكالمات فيديو يومياً — هذا هو الحساب كله. منزل خفيف
الاستخدام يكفيه <strong>500 جيجابايت</strong> بـ<span class="num">$112</span> شهرياً.
عائلة تبثّ وتدرس وتتصل تناسبها <strong>1 تيرابايت</strong> بـ<span class="num">$189</span>
— الباقة التي نوصي بها أكثر من غيرها. وإن تجاوزت الحصة شهراً ما، لا ينقطع شيء:
تستمر على بيانات قياسية غير محدودة.</p>
<h2>وسؤال الكهرباء، أولاً</h2>
<p>الجهاز القياسي يعمل على أنظمة الإنفرتر والبطاريات الموجودة في كثير من البيوت
(استهلاكه 75–100 واط). أما <strong>ميني</strong> فيستهلك 25–40 واطاً فقط — أقل من شاحن
حاسوب — ويعمل من باور بانك جيد أو منظومة شمسية صغيرة بالكابل المناسب. انقطاع الكهرباء
لا يعني انقطاع الإنترنت.</p>
<h2>ماذا تدفع للبدء؟</h2>
<p>مرة واحدة: الجهاز (<span class="num">$350</span> ميني أو <span class="num">$600</span>
قياسي) + التركيب <span class="num">$50</span>. ثم الباقة شهرياً. التفاصيل كاملة في
<a href="starlink-price-sudan.html">صفحة الأسعار</a> و<a href="starlink-plans-sudan.html">مقارنة الباقات</a>.</p>''')

for fname, (title, desc, body) in pages.items():
    open(os.path.join(AR, fname), 'w', encoding='utf-8').write(shell(fname, title, desc, body))
print(f"wrote {len(pages)} Arabic pages in /ar/")
