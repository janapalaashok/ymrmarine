<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/page_helpers.php';
require_once __DIR__ . '/includes/service_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = getDB();
$chrome = loadSiteChrome();
$pc = getPageContent($pdo, 'contact');

$services = [];
try {
    ensureServicePageColumns($pdo);
    $services = $pdo->query('SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$heroImage = !empty($pc['hero_image']) ? $pc['hero_image'] : pageDefaultHero('contact');
$pageTitle = $pc['meta_title'] ?: 'Contact YMR Marine Solutions | Request a Survey';
$pageDescription = $pc['meta_description'] ?: '';
$pageKeywords = $pc['meta_keywords'] ?: '';
$canonicalUrl = 'https://www.ymrmarine.com/contact.php';
$activeNav = 'contact';

$schemaJson = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'ContactPage',
    'name' => $pageTitle,
    'description' => $pageDescription,
    'url' => $canonicalUrl,
    'mainEntity' => [
        '@type' => 'ProfessionalService',
        'name' => 'YMR Marine Solutions LLP',
        'telephone' => $chrome['phone'],
        'email' => $chrome['email'],
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => $chrome['address'],
            'addressCountry' => 'IN',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

require __DIR__ . '/includes/public_layout_start.php';
?>

<section class="page-hero">
  <div class="section">
    <nav class="page-breadcrumb" aria-label="Breadcrumb">
      <a href="index.php">Home</a><span>/</span><span aria-current="page">Contact</span>
    </nav>
    <div class="page-eyebrow"><i class="fas fa-envelope"></i> Get In Touch</div>
    <h1 class="page-h1">Start your survey request</h1>
    <?php if (!empty($pc['hero_subtitle'])): ?>
    <p class="page-lead"><?= e($pc['hero_subtitle']) ?></p>
    <?php endif; ?>
  </div>
</section>

<div class="section-alt">
<section id="request">
  <div class="section">
    <?php if (!empty($pc['body'])): ?>
    <p class="sec-body reveal" style="margin-bottom:1.75rem; max-width:720px;"><?= nl2br(e($pc['body'])) ?></p>
    <?php endif; ?>
    <div class="contact-grid">
      <div class="reveal">
        <div class="tag">Contact Details</div>
        <h2 class="sec-h2">Talk to operations</h2>
        <div class="contact-info" style="margin-top:1.25rem;">
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-building"></i></div>
            <div><div class="ct">Office</div><span class="cv"><?= e($chrome['address']) ?></span></div>
          </div>
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-envelope"></i></div>
            <div><div class="ct">Email</div><a href="mailto:<?= e($chrome['email']) ?>" class="cv"><?= e($chrome['email']) ?></a></div>
          </div>
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-phone"></i></div>
            <div><div class="ct">Phone</div><a href="tel:<?= e(preg_replace('/\s+/', '', $chrome['phone'])) ?>" class="cv"><?= e($chrome['phone']) ?></a></div>
          </div>
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-clock"></i></div>
            <div><div class="ct">Availability</div><span class="cv">24 / 7 — including weekends &amp; holidays</span></div>
          </div>
        </div>
        <?php if (!empty($chrome['mapEmbed'])): ?>
        <div class="map-frame" style="margin-top:1.5rem;">
          <iframe src="<?= e($chrome['mapEmbed']) ?>" width="600" height="250" style="border:0;" allowfullscreen loading="lazy" title="YMR Marine office map"></iframe>
        </div>
        <?php endif; ?>
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
              <option value="">Select a service…</option>
              <?php foreach ($services as $s): ?>
              <option><?= e($s['title']) ?></option>
              <?php endforeach; ?>
              <option>Other</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Port / Location</label>
            <input type="text" name="port" class="form-input" placeholder="e.g. Visakhapatnam">
          </div>
          <div class="form-group">
            <label class="form-label">Message</label>
            <textarea name="message" class="form-textarea" placeholder="Vessel name, ETA, survey type…"></textarea>
          </div>
          <button type="submit" class="btn-submit"><?= e($pc['cta_text'] ?: 'Send Request') ?> <i class="fas fa-arrow-right"></i></button>
        </form>
      </div>
    </div>
  </div>
</section>
</div>

<?php require __DIR__ . '/includes/public_layout_end.php'; ?>
