<?php
/**
 * Dynamic service detail page – loads content from DB by slug.
 * URL: service.php?slug=bunker-survey
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/service_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = getDB();
ensureServicePageColumns($pdo);
seedServicePagesIfNeeded($pdo);

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: index.php#services');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM services WHERE slug = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$slug]);
$svc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$svc) {
    // Try match by slugified title for older rows
    $all = $pdo->query('SELECT * FROM services WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $row) {
        if (serviceSlugify($row['title'] ?? '') === $slug) {
            $svc = $row;
            break;
        }
    }
}

if (!$svc) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Service not found</title></head><body style="font-family:sans-serif;text-align:center;padding:4rem;"><h1>Service not found</h1><p><a href="index.php">Back to home</a></p></body></html>';
    exit;
}

$content = serviceDefaultContent($svc);
$allServices = $pdo->query('SELECT id, title, slug, icon, description FROM services WHERE is_active = 1 ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);

$siteName   = getSetting('site_name', 'YMR Marine Solutions LLP');
$phone      = getSetting('phone', '+91 982 048 2713');
$email      = getSetting('email', 'ops@ymrmarine.com');
$logo       = getSetting('logo', 'ymr_logo.png');
$empLogin   = getSetting('employee_login_url', '#');
$primary    = getSetting('primary_color', '#02bbff');
$address    = getSetting('address');
$footerText = getSetting('footer_text');
$copyright  = getSetting('copyright');

$pageTitle       = $content['meta_title'];
$pageDescription = $content['meta_description'];
$pageKeywords    = $content['meta_keywords'];
$canonicalUrl    = 'https://www.ymrmarine.com/service.php?slug=' . rawurlencode($content['slug']);
$logoUrl         = 'https://www.ymrmarine.com/' . ltrim($logo, '/');
$heroImg         = $content['hero_image'];
$pageImg         = $content['page_image'];
$features        = $content['features'];
$process         = $content['process'];
$faq             = $content['faq'];
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
<meta property="og:image" content="<?= e(strpos($heroImg, 'http') === 0 ? $heroImg : 'https://www.ymrmarine.com/' . ltrim($heroImg, '/')) ?>">
<meta property="og:site_name" content="YMR Marine Solutions LLP">
<meta property="og:locale" content="en_IN">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($pageTitle) ?>">
<meta name="twitter:description" content="<?= e($pageDescription) ?>">

<meta name="theme-color" content="<?= e($primary) ?>">
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
:root { --accent: <?= e($primary) ?>; }
.svc-page-hero {
  position: relative; min-height: 52vh; display: flex; align-items: flex-end;
  padding: 7rem 0 3.5rem; color: #fff;
  background: linear-gradient(135deg, rgba(11,30,45,0.97) 0%, rgba(11,30,45,0.82) 55%, rgba(11,30,45,0.5) 100%),
              url('<?= e($heroImg) ?>') center/cover no-repeat;
}
.svc-page-hero .section { width: 100%; max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
.svc-breadcrumb {
  font-size: 0.82rem; color: rgba(255,255,255,0.7); margin-bottom: 1.25rem;
  display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center;
}
.svc-breadcrumb a { color: rgba(255,255,255,0.85); text-decoration: none; }
.svc-breadcrumb a:hover { color: var(--accent); }
.svc-eyebrow {
  display: inline-flex; align-items: center; gap: 0.5rem;
  font-size: 0.78rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;
  color: var(--accent); margin-bottom: 0.85rem;
}
.svc-page-h1 {
  font-family: 'Space Grotesk', sans-serif;
  font-size: clamp(1.85rem, 4.5vw, 2.75rem);
  font-weight: 700; line-height: 1.15; max-width: 740px; margin-bottom: 1rem;
}
.svc-page-lead {
  font-size: 1.05rem; line-height: 1.65; color: rgba(255,255,255,0.82);
  max-width: 640px; margin-bottom: 1.75rem;
}
.svc-cta-row { display: flex; flex-wrap: wrap; gap: 0.85rem; }

.svc-check-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1.1rem; margin-top: 1.75rem;
}
.svc-check-item {
  background: var(--card); border: 1px solid rgba(0,0,0,0.06); border-radius: var(--radius);
  padding: 1.15rem 1.25rem; display: flex; gap: 0.85rem; align-items: flex-start;
  box-shadow: 0 4px 14px rgba(11,30,45,0.04);
}
.svc-check-item i { color: var(--accent); font-size: 1.1rem; margin-top: 0.15rem; flex-shrink: 0; }
.svc-check-item strong { display: block; font-size: 0.95rem; color: var(--navy); margin-bottom: 0.25rem; }
.svc-check-item p { font-size: 0.84rem; color: #5a7080; line-height: 1.55; margin: 0; }

.svc-steps {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1.25rem; counter-reset: step; margin-top: 1.75rem;
}
.svc-step {
  background: var(--card); border-radius: var(--radius); padding: 1.35rem 1.25rem 1.4rem;
  border: 1px solid rgba(0,0,0,0.05);
}
.svc-step::before {
  counter-increment: step; content: counter(step, decimal-leading-zero);
  font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 1.35rem;
  color: var(--accent); display: block; margin-bottom: 0.6rem;
}
.svc-step h3 { font-size: 1rem; color: var(--navy); margin-bottom: 0.4rem; }
.svc-step p { font-size: 0.86rem; color: #5a7080; line-height: 1.55; margin: 0; }

.svc-faq details {
  background: var(--card); border: 1px solid rgba(0,0,0,0.06); border-radius: 12px;
  margin-bottom: 0.7rem; padding: 0.95rem 1.15rem;
}
.svc-faq summary {
  cursor: pointer; font-weight: 600; font-size: 0.95rem; color: var(--navy);
  list-style: none; display: flex; justify-content: space-between; align-items: center; gap: 1rem;
}
.svc-faq summary::-webkit-details-marker { display: none; }
.svc-faq summary::after { content: '+'; font-size: 1.2rem; color: var(--accent); }
.svc-faq details[open] summary::after { content: '−'; }
.svc-faq details p { margin: 0.75rem 0 0.15rem; font-size: 0.9rem; color: #5a7080; line-height: 1.65; }

.svc-related { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 1rem; }
.svc-related a {
  display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.45rem 0.9rem;
  background: var(--bg-light2); border-radius: 999px; font-size: 0.82rem; color: var(--navy);
  text-decoration: none; border: 1px solid rgba(0,0,0,0.06); transition: background .2s, color .2s;
}
.svc-related a:hover { background: var(--accent); color: #fff; border-color: var(--accent); }
.svc-side-img {
  width: 100%; border-radius: var(--radius); object-fit: cover; max-height: 320px;
  box-shadow: 0 12px 32px rgba(11,30,45,0.12);
}
.overview-split {
  display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 2.5rem; align-items: start;
}
@media (max-width: 800px) { .overview-split { grid-template-columns: 1fr; } }
.nav-links a.active, .nav-mobile a.active { color: var(--accent); }
</style>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Service",
  "name": <?= json_encode($svc['title']) ?>,
  "serviceType": <?= json_encode($svc['title']) ?>,
  "description": <?= json_encode($pageDescription) ?>,
  "provider": {
    "@type": "ProfessionalService",
    "name": "YMR Marine Solutions LLP",
    "url": "https://www.ymrmarine.com",
    "telephone": <?= json_encode($phone) ?>,
    "email": <?= json_encode($email) ?>
  },
  "areaServed": ["India", "Worldwide"],
  "url": <?= json_encode($canonicalUrl) ?>
}
</script>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.ymrmarine.com/" },
    { "@type": "ListItem", "position": 2, "name": "Services", "item": "https://www.ymrmarine.com/index.php#services" },
    { "@type": "ListItem", "position": 3, "name": <?= json_encode($svc['title']) ?>, "item": <?= json_encode($canonicalUrl) ?> }
  ]
}
</script>
<?php if (!empty($faq)): ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    <?php
    $faqLd = [];
    foreach ($faq as $item) {
        $faqLd[] = json_encode([
            '@type' => 'Question',
            'name' => $item['q'] ?? '',
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a'] ?? ''],
        ], JSON_UNESCAPED_UNICODE);
    }
    echo implode(",\n    ", $faqLd);
    ?>
  ]
}
</script>
<?php endif; ?>
</head>
<body>

<nav class="nav" id="mainNav">
  <div class="nav-inner">
    <a href="index.php" class="nav-logo">
      <?php if ($logo): ?><img src="<?= e($logo) ?>" width="50" height="50" alt="YMR Marine logo"><?php endif; ?>
      <span class="accent">YMR</span><span class="rest">MARINE</span>
    </a>
    <ul class="nav-links">
      <li><a href="about-us.php">About</a></li>
      <li><a href="index.php#services" class="active">Services</a></li>
      <li><a href="index.php#why-us">Why Us</a></li>
      <li><a href="index.php#team">Team</a></li>
      <li><a href="ports.php">Ports</a></li>
      <li><a href="index.php#testimonials">Clients</a></li>
      <li><a href="#request">Contact</a></li>
    </ul>
    <a href="<?= e($empLogin) ?>" class="btn-nav" target="_blank" rel="noopener">Employee Login</a>
    <button class="nav-toggle" id="navToggle" aria-label="Open menu"><span></span><span></span><span></span></button>
  </div>
  <div class="nav-mobile" id="navMobile">
    <a href="about-us.php">About</a>
    <a href="index.php#services" class="active">Services</a>
    <a href="index.php#why-us">Why Us</a>
    <a href="index.php#team">Team</a>
    <a href="ports.php">Ports</a>
    <a href="index.php#testimonials">Clients</a>
    <a href="contact.php">Contact</a>
    <a href="<?= e($empLogin) ?>" class="btn-nav" target="_blank" rel="noopener">Employee Login</a>
  </div>
</nav>

<section class="svc-page-hero" id="top">
  <div class="section">
    <nav class="svc-breadcrumb" aria-label="Breadcrumb">
      <a href="index.php">Home</a><span>/</span>
      <a href="index.php#services">Services</a><span>/</span>
      <span aria-current="page"><?= e($svc['title']) ?></span>
    </nav>
    <div class="svc-eyebrow"><i class="fas <?= e($svc['icon'] ?: 'fa-ship') ?>"></i> Marine Survey Service</div>
    <h1 class="svc-page-h1"><?= e($svc['title']) ?></h1>
    <p class="svc-page-lead"><?= e($content['hero_subtitle']) ?></p>
    <div class="svc-cta-row">
      <a href="#request" class="btn-primary-hero"><?= e($content['cta_text']) ?> <i class="fas fa-arrow-right"></i></a>
      <a href="index.php#services" class="btn-ghost-hero">All Services</a>
    </div>
  </div>
</section>

<section id="overview">
  <div class="section">
    <div class="overview-split reveal">
      <div>
        <div class="tag">Service Overview</div>
        <h2 class="sec-h2"><?= e($content['overview_title']) ?></h2>
        <p class="sec-body"><?= nl2br(e($content['overview_body'])) ?></p>
        <?php if (!empty($content['overview_body2'])): ?>
        <p class="sec-body" style="margin-top:1rem;"><?= nl2br(e($content['overview_body2'])) ?></p>
        <?php endif; ?>
      </div>
      <div>
        <img src="<?= e($pageImg) ?>" alt="<?= e($svc['title']) ?>" class="svc-side-img" loading="lazy"
             onerror="this.src='https://images.unsplash.com/photo-1464037866556-6812c9d1c88e?q=80&w=1200&auto=format&fit=crop'">
      </div>
    </div>

    <?php if (!empty($features)): ?>
    <div class="svc-check-grid reveal reveal-delay-1">
      <?php foreach ($features as $f): ?>
      <div class="svc-check-item">
        <i class="fas <?= e($f['icon'] ?? 'fa-check') ?>"></i>
        <div>
          <strong><?= e($f['title'] ?? '') ?></strong>
          <p><?= e($f['body'] ?? '') ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($process)): ?>
<div class="section-alt">
<section id="process">
  <div class="section">
    <div class="reveal">
      <div class="tag">How It Works</div>
      <h2 class="sec-h2">From enquiry to report</h2>
    </div>
    <div class="svc-steps reveal reveal-delay-1">
      <?php foreach ($process as $step): ?>
      <div class="svc-step">
        <h3><?= e($step['title'] ?? '') ?></h3>
        <p><?= e($step['body'] ?? '') ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
</div>
<?php endif; ?>

<?php if (!empty($content['who_body'])): ?>
<section id="who">
  <div class="section">
    <div class="reveal">
      <div class="tag">Who Uses This Service</div>
      <h2 class="sec-h2">Who instructs this survey</h2>
      <p class="sec-body"><?= nl2br(e($content['who_body'])) ?></p>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($faq)): ?>
<div class="section-alt">
<section id="faq">
  <div class="section">
    <div class="reveal">
      <div class="tag">Common Questions</div>
      <h2 class="sec-h2"><?= e($svc['title']) ?> FAQ</h2>
    </div>
    <div class="svc-faq reveal reveal-delay-1" style="max-width:800px;">
      <?php foreach ($faq as $item): ?>
      <details>
        <summary><?= e($item['q'] ?? '') ?></summary>
        <p><?= e($item['a'] ?? '') ?></p>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
</div>
<?php endif; ?>

<section id="related">
  <div class="section">
    <div class="reveal">
      <div class="tag">Related Services</div>
      <h2 class="sec-h2">Other surveys from YMR</h2>
      <div class="svc-related">
        <?php foreach ($allServices as $rel):
          if ((int)$rel['id'] === (int)$svc['id']) continue;
          $rslug = $rel['slug'] ?: serviceSlugify($rel['title']);
        ?>
        <a href="service.php?slug=<?= e(rawurlencode($rslug)) ?>"><i class="fas <?= e($rel['icon'] ?: 'fa-ship') ?>"></i> <?= e($rel['title']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<div class="section-alt">
<section id="request">
  <div class="section">
    <div class="contact-grid">
      <div class="reveal">
        <div class="tag">Get In Touch</div>
        <h2 class="sec-h2"><?= e($content['cta_text']) ?></h2>
        <p class="sec-body" style="margin-bottom:2rem;">Share vessel name, location and preferred dates. Our team responds promptly with availability and scope confirmation.</p>
        <div class="contact-info">
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-building"></i></div>
            <div><div class="ct">Office</div><span class="cv"><?= e($address) ?></span></div>
          </div>
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-envelope"></i></div>
            <div><div class="ct">Email</div><a href="mailto:<?= e($email) ?>" class="cv"><?= e($email) ?></a></div>
          </div>
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-phone"></i></div>
            <div><div class="ct">Phone</div><a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>" class="cv"><?= e($phone) ?></a></div>
          </div>
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-clock"></i></div>
            <div><div class="ct">Availability</div><span class="cv">24 / 7 — including weekends &amp; holidays</span></div>
          </div>
        </div>
      </div>
      <div class="reveal reveal-delay-2">
        <form method="POST" action="process_contact.php">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Your Name</label>
              <input type="text" name="name" class="form-input" placeholder="John Smith" required>
            </div>
            <div class="form-group">
              <label class="form-label">Company</label>
              <input type="text" name="company" class="form-input" placeholder="ABC Shipping Ltd" required>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-input" placeholder="you@company.com" required>
            </div>
            <div class="form-group">
              <label class="form-label">Phone</label>
              <input type="tel" name="phone" class="form-input" placeholder="+91 00000 00000">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Service Required</label>
            <select name="service" class="form-select form-input">
              <option selected><?= e($svc['title']) ?></option>
              <?php foreach ($allServices as $opt):
                if ((int)$opt['id'] === (int)$svc['id']) continue;
              ?>
              <option><?= e($opt['title']) ?></option>
              <?php endforeach; ?>
              <option>Other</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Port / Location</label>
            <input type="text" name="port" class="form-input" placeholder="e.g. Visakhapatnam, Mumbai">
          </div>
          <div class="form-group">
            <label class="form-label">Message</label>
            <textarea name="message" class="form-textarea" placeholder="Vessel name, ETA, and any specific requirements…"></textarea>
          </div>
          <button type="submit" class="btn-submit">Send Request <i class="fas fa-arrow-right"></i></button>
        </form>
      </div>
    </div>
  </div>
</section>
</div>

<footer>
  <div class="footer-inner">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="logo-text">
          <?php if ($logo): ?><img src="<?= e($logo) ?>" width="40" height="40" alt=""><?php endif; ?>
          <span class="a">YMR</span> <span class="b">MARINE</span>
        </div>
        <p><?= e($footerText) ?></p>
      </div>
      <div class="footer-col">
        <h6>Company</h6>
        <ul>
          <li><a href="about-us.php">About Us</a></li>
          <li><a href="index.php#why-us">Why Choose Us</a></li>
          <li><a href="index.php#team">Our Team</a></li>
          <li><a href="#request">Contact</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h6>Services</h6>
        <ul>
          <?php foreach (array_slice($allServices, 0, 6) as $fs):
            $fslug = $fs['slug'] ?: serviceSlugify($fs['title']);
          ?>
          <li><a href="service.php?slug=<?= e(rawurlencode($fslug)) ?>"><?= e($fs['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="footer-col">
        <h6>Contact</h6>
        <ul>
          <li><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li>
          <li><a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>"><?= e($phone) ?></a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <p><?= e($copyright) ?></p>
      <p>Designed for excellence in maritime services</p>
    </div>
  </div>
</footer>

<script>
const nav = document.getElementById('mainNav');
window.addEventListener('scroll', () => nav.classList.toggle('scrolled', window.scrollY > 40), { passive: true });
const toggle = document.getElementById('navToggle');
const mobile = document.getElementById('navMobile');
toggle.addEventListener('click', () => { toggle.classList.toggle('open'); mobile.classList.toggle('open'); });
mobile.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
  toggle.classList.remove('open'); mobile.classList.remove('open');
}));
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const id = a.getAttribute('href');
    if (id === '#') return;
    const t = document.querySelector(id);
    if (!t) return;
    e.preventDefault();
    window.scrollTo({ top: t.getBoundingClientRect().top + scrollY - (nav.offsetHeight + 16), behavior: 'smooth' });
  });
});
const revealEls = document.querySelectorAll('.reveal');
const io = new IntersectionObserver((entries) => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
}, { threshold: 0.12 });
revealEls.forEach(el => io.observe(el));
</script>
</body>
</html>
