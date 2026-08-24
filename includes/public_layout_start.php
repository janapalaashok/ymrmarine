<?php
/**
 * Expects before include:
 * $pageTitle, $pageDescription, $pageKeywords, $canonicalUrl
 * $chrome (from loadSiteChrome), $activeNav (about|services|ports|contact|home)
 * $heroImage, $heroTitle, $heroSubtitle (optional), $schemaJson (optional string)
 */
$c = $chrome;
$logoUrl = 'https://www.ymrmarine.com/' . ltrim($c['logo'], '/');
$ogImage = !empty($heroImage)
    ? (strpos($heroImage, 'http') === 0 ? $heroImage : 'https://www.ymrmarine.com/' . ltrim($heroImage, '/'))
    : $logoUrl;
$activeNav = $activeNav ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($pageDescription) ?>">
<meta name="keywords" content="<?= e($pageKeywords) ?>">
<meta name="author" content="YMR Marine Solutions LLP">
<meta name="robots" content="index, follow, max-image-preview:large">
<link rel="canonical" href="<?= e($canonicalUrl) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($pageDescription) ?>">
<meta property="og:url" content="<?= e($canonicalUrl) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:site_name" content="YMR Marine Solutions LLP">
<meta property="og:locale" content="en_IN">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($pageTitle) ?>">
<meta name="twitter:description" content="<?= e($pageDescription) ?>">
<meta name="theme-color" content="<?= e($c['primary']) ?>">
<!-- Favicon & App Icons -->
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
<link rel="manifest" href="site.webmanifest">
<meta name="msapplication-TileColor" content="#02bbff">
<meta name="msapplication-TileImage" content="android-chrome-192x192.png" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Lexend:wght@100..900&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
:root { --accent: <?= e($c['primary']) ?>; }
.page-hero {
  position: relative; min-height: 42vh; display: flex; align-items: flex-end;
  padding: 7rem 0 3rem; color: #fff;
  background: linear-gradient(135deg, rgba(11,30,45,0.97) 0%, rgba(11,30,45,0.8) 55%, rgba(11,30,45,0.5) 100%),
              url('<?= e($heroImage ?? pageDefaultHero('about')) ?>') center/cover no-repeat;
}
.page-hero .section { width: 100%; max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
.page-breadcrumb {
  font-size: 0.82rem; color: rgba(255,255,255,0.7); margin-bottom: 1.1rem;
  display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center;
}
.page-breadcrumb a { color: rgba(255,255,255,0.85); text-decoration: none; }
.page-breadcrumb a:hover { color: var(--accent); }
.page-eyebrow {
  display: inline-flex; align-items: center; gap: 0.5rem;
  font-size: 0.78rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;
  color: var(--accent); margin-bottom: 0.75rem;
}
.page-h1 {
  font-family: 'Space Grotesk', sans-serif;
  font-size: clamp(1.75rem, 4vw, 2.5rem);
  font-weight: 700; line-height: 1.15; max-width: 720px; margin-bottom: 0.85rem;
}
.page-lead {
  font-size: 1.02rem; line-height: 1.65; color: rgba(255,255,255,0.82);
  max-width: 620px; margin-bottom: 1.5rem;
}
.page-cta-row { display: flex; flex-wrap: wrap; gap: 0.85rem; }
.nav-links a.active, .nav-mobile a.active { color: var(--accent); }
.port-card-link { text-decoration: none; color: inherit; display: block; }
.port-card-link:hover .port-card { border-color: var(--accent); transform: translateY(-2px); }
</style>
<?php if (!empty($schemaJson)): ?>
<script type="application/ld+json"><?= $schemaJson ?></script>
<?php endif; ?>
</head>
<body>
<nav class="nav" id="mainNav">
  <div class="nav-inner">
    <a href="index.php" class="nav-logo">
      <?php if ($c['logo']): ?><img src="<?= e($c['logo']) ?>" width="50" height="50" alt="YMR Marine logo"><?php endif; ?>
      <span class="accent">YMR</span><span class="rest">MARINE</span>
    </a>
    <ul class="nav-links">
      <li><a href="about-us.php" class="<?= $activeNav==='about'?'active':'' ?>">About</a></li>
      <li><a href="index.php#services" class="<?= $activeNav==='services'?'active':'' ?>">Services</a></li>
      <li><a href="index.php#why-us">Why Us</a></li>
      <li><a href="index.php#team">Team</a></li>
      <li><a href="ports.php" class="<?= $activeNav==='ports'?'active':'' ?>">Ports</a></li>
      <li><a href="index.php#testimonials">Clients</a></li>
      <li><a href="contact.php" class="<?= $activeNav==='contact'?'active':'' ?>">Contact</a></li>
    </ul>
    <a href="<?= e($c['empLogin']) ?>" class="btn-nav" target="_blank" rel="noopener">Employee Login</a>
    <button class="nav-toggle" id="navToggle" aria-label="Open menu"><span></span><span></span><span></span></button>
  </div>
  <div class="nav-mobile" id="navMobile">
    <a href="about-us.php" class="<?= $activeNav==='about'?'active':'' ?>">About</a>
    <a href="index.php#services">Services</a>
    <a href="index.php#why-us">Why Us</a>
    <a href="index.php#team">Team</a>
    <a href="ports.php" class="<?= $activeNav==='ports'?'active':'' ?>">Ports</a>
    <a href="index.php#testimonials">Clients</a>
    <a href="contact.php" class="<?= $activeNav==='contact'?'active':'' ?>">Contact</a>
    <a href="<?= e($c['empLogin']) ?>" class="btn-nav" target="_blank" rel="noopener">Employee Login</a>
  </div>
</nav>
