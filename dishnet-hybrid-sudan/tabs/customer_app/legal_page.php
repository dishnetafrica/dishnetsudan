<?php
/**
 * Public legal page renderer — serves ?page=terms and ?page=privacy.
 * v4.12.19. Brand-consistent styling matching login_web.php and portal.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/LegalContent.php';

$legalKind = $_GET['page'] === 'terms' ? 'terms' : 'privacy';
$docVer = dnLegalVersion();

if ($legalKind === 'terms') {
    $docTitle = 'Terms of Service';
    $docSub   = 'The agreement between you and DishNet Africa.';
    $docBody  = dnTermsContent();
    $docVersionLabel = 'v' . $docVer['tos'];
} else {
    $docTitle = 'Privacy Policy';
    $docSub   = 'What information we collect, how we use it, who we share it with.';
    $docBody  = dnPrivacyContent();
    $docVersionLabel = 'v' . $docVer['privacy'];
}

function pEsc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DishNet Africa &mdash; <?= pEsc($docTitle) ?></title>
<meta name="theme-color" content="#141414">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --red: #D41C1C;
  --dark: #141414;
  --gray: #6B6B6B;
  --gray-light: #EBEBEB;
  --off-white: #F5F5F5;
  --swoosh: linear-gradient(110deg, #D41C1C 0%, #E8521A 60%, #FF7A35 100%);
  --display: 'Barlow Condensed', 'Impact', sans-serif;
  --sans: 'Barlow', -apple-system, sans-serif;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: var(--sans);
  background: var(--off-white);
  color: var(--dark);
  line-height: 1.55;
  -webkit-font-smoothing: antialiased;
}
.topbar {
  background: var(--dark);
  color: #fff;
  padding: 14px 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 1px 0 rgba(255,255,255,.05);
}
.topbar-brand {
  font-family: var(--display);
  font-weight: 900;
  font-size: 22px;
  letter-spacing: -.5px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.topbar-brand::before {
  content: '';
  display: inline-block;
  width: 28px; height: 28px;
  background: var(--swoosh);
  border-radius: 7px;
}
.topbar-back {
  font-family: var(--display);
  font-weight: 800;
  font-size: 12px;
  letter-spacing: 1.2px;
  text-transform: uppercase;
  color: rgba(255,255,255,.6);
  text-decoration: none;
  padding: 6px 12px;
  border: 1px solid rgba(255,255,255,.15);
  border-radius: 8px;
  transition: color .15s, border-color .15s;
}
.topbar-back:hover { color: #fff; border-color: var(--red); }

.hero {
  background: var(--dark);
  color: #fff;
  padding: 56px 24px 72px;
  position: relative;
  overflow: hidden;
}
.hero::after {
  content: '';
  position: absolute;
  bottom: -60px; right: -60px;
  width: 320px; height: 320px;
  background: radial-gradient(circle, rgba(212,28,28,.3) 0%, transparent 70%);
  pointer-events: none;
}
.hero-inner { max-width: 780px; margin: 0 auto; position: relative; z-index: 2; }
.hero-eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-family: var(--display);
  font-weight: 800;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: var(--red);
  margin-bottom: 18px;
}
.hero-eyebrow::before {
  content: '';
  width: 24px; height: 2px;
  background: var(--red);
}
.hero h1 {
  font-family: var(--display);
  font-weight: 900;
  font-size: clamp(40px, 7vw, 72px);
  letter-spacing: -1.5px;
  line-height: 1;
  margin-bottom: 14px;
}
.hero p {
  font-size: 17px;
  color: rgba(255,255,255,.7);
  max-width: 56ch;
}
.hero-meta {
  margin-top: 24px;
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
  font-family: var(--display);
  font-weight: 800;
  font-size: 11px;
  letter-spacing: 1.4px;
  text-transform: uppercase;
  color: rgba(255,255,255,.5);
}
.hero-meta span strong { color: #fff; font-weight: 900; }

.content {
  max-width: 780px;
  margin: -32px auto 0;
  background: #fff;
  border-radius: 18px;
  padding: 48px 40px;
  position: relative;
  z-index: 3;
  box-shadow: 0 12px 32px -8px rgba(0,0,0,.08);
}
@media (max-width: 620px) {
  .content { padding: 32px 22px; margin: -24px 16px 0; }
}
.content section { margin-bottom: 34px; }
.content section:last-child { margin-bottom: 0; }
.content h2 {
  font-family: var(--display);
  font-weight: 900;
  font-size: 26px;
  letter-spacing: -.4px;
  color: var(--dark);
  margin-bottom: 10px;
  line-height: 1.15;
}
.content h2::before {
  content: '';
  display: inline-block;
  width: 20px; height: 3px;
  background: var(--red);
  vertical-align: middle;
  margin-right: 10px;
  margin-bottom: 5px;
}
.content p {
  font-size: 16px;
  color: var(--dark);
  line-height: 1.65;
  max-width: 65ch;
}

.toc {
  background: var(--off-white);
  border-left: 3px solid var(--red);
  padding: 18px 22px;
  border-radius: 0 10px 10px 0;
  margin-bottom: 40px;
}
.toc-label {
  font-family: var(--display);
  font-weight: 800;
  font-size: 10px;
  letter-spacing: 1.4px;
  text-transform: uppercase;
  color: var(--gray);
  margin-bottom: 10px;
}
.toc ol {
  list-style: none;
  counter-reset: toc;
  columns: 2;
  gap: 6px;
}
@media (max-width: 560px) { .toc ol { columns: 1; } }
.toc li {
  counter-increment: toc;
  font-size: 13px;
  padding: 3px 0;
  break-inside: avoid;
}
.toc li::before {
  content: counter(toc, decimal-leading-zero) '.';
  font-family: var(--display);
  font-weight: 800;
  color: var(--red);
  margin-right: 8px;
  font-size: 12px;
}
.toc a {
  color: var(--dark);
  text-decoration: none;
  border-bottom: 1px dotted transparent;
  transition: border-color .15s, color .15s;
}
.toc a:hover { color: var(--red); border-bottom-color: var(--red); }

.cta-bar {
  background: var(--dark);
  color: #fff;
  padding: 32px 24px;
  text-align: center;
  margin-top: 56px;
}
.cta-bar h3 {
  font-family: var(--display);
  font-weight: 900;
  font-size: 26px;
  letter-spacing: -.4px;
  margin-bottom: 8px;
}
.cta-bar p {
  font-size: 14px;
  color: rgba(255,255,255,.6);
  margin-bottom: 18px;
}
.cta-bar-links { display: inline-flex; gap: 14px; flex-wrap: wrap; justify-content: center; }
.cta-bar-link {
  font-family: var(--display);
  font-weight: 800;
  font-size: 13px;
  letter-spacing: 1.2px;
  text-transform: uppercase;
  padding: 12px 20px;
  border-radius: 10px;
  text-decoration: none;
  transition: transform .1s;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.cta-bar-link:hover { transform: translateY(-1px); }
.cta-bar-link.red { background: var(--red); color: #fff; }
.cta-bar-link.ghost { background: transparent; color: #fff; border: 1.5px solid rgba(255,255,255,.25); }

footer {
  background: var(--dark);
  color: rgba(255,255,255,.4);
  padding: 20px 24px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 10px;
  letter-spacing: .5px;
  text-align: center;
  border-top: 1px solid rgba(255,255,255,.05);
}
.red-bar { height: 3px; background: var(--swoosh); }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-brand">DishNet</div>
  <a class="topbar-back" href="?page=customer_login">&larr; Back to sign in</a>
</header>

<section class="hero">
  <div class="hero-inner">
    <div class="hero-eyebrow">Legal</div>
    <h1><?= pEsc($docTitle) ?></h1>
    <p><?= pEsc($docSub) ?></p>
    <div class="hero-meta">
      <span>Version &nbsp;<strong><?= pEsc($docVersionLabel) ?></strong></span>
      <span>Effective &nbsp;<strong><?= pEsc($docVer['dated']) ?></strong></span>
      <span>DishNet Africa Ltd. &middot; Juba, South Sudan</span>
    </div>
  </div>
</section>

<div class="content">
  <nav class="toc" aria-label="Section index">
    <div class="toc-label">On this page</div>
    <ol>
      <?php foreach ($docBody as $i => $sec): ?>
        <li><a href="#s<?= $i ?>"><?= pEsc($sec['heading']) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <?php foreach ($docBody as $i => $sec): ?>
    <section id="s<?= $i ?>">
      <h2><?= pEsc($sec['heading']) ?></h2>
      <p><?= pEsc($sec['body']) ?></p>
    </section>
  <?php endforeach; ?>
</div>

<div class="cta-bar">
  <h3>Questions?</h3>
  <p>Our team replies fastest on WhatsApp.</p>
  <div class="cta-bar-links">
    <a class="cta-bar-link red" href="https://wa.me/211921443002" rel="noopener">💬 &nbsp;WhatsApp +211 921 443 002</a>
    <a class="cta-bar-link ghost" href="mailto:info@dishnetafrica.com">info@dishnetafrica.com</a>
  </div>
</div>

<div class="red-bar"></div>

<footer>
  DishNet Africa Ltd. &nbsp;&middot;&nbsp; <?= pEsc($docTitle) ?> <?= pEsc($docVersionLabel) ?>
  &nbsp;&middot;&nbsp; <?= pEsc($docVer['dated']) ?>
</footer>

</body>
</html>
