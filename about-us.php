<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/page_helpers.php';
require_once __DIR__ . '/includes/service_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = getDB();
$chrome = loadSiteChrome();
$pc = getPageContent($pdo, 'about');
$about = [];
try { $about = $pdo->query('SELECT * FROM about WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$heroImage = !empty($pc['hero_image']) ? $pc['hero_image'] : pageDefaultHero('about');
$pageTitle = $pc['meta_title'] ?: 'About YMR Marine Solutions | Marine Survey Company';
$pageDescription = $pc['meta_description'] ?: '';
$pageKeywords = $pc['meta_keywords'] ?: '';
$canonicalUrl = 'https://www.ymrmarine.com/about-us.php';
$activeNav = 'about';
$heroTitle = $about['title'] ?? 'Trusted marine expertise since 2006';
$heroSubtitle = $pc['hero_subtitle'] ?? '';

$schemaJson = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'AboutPage',
    'name' => $pageTitle,
    'description' => $pageDescription,
    'url' => $canonicalUrl,
    'mainEntity' => [
        '@type' => 'ProfessionalService',
        'name' => 'YMR Marine Solutions LLP',
        'url' => 'https://www.ymrmarine.com',
        'telephone' => $chrome['phone'],
        'email' => $chrome['email'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

require __DIR__ . '/includes/public_layout_start.php';
?>

<section class="page-hero">
  <div class="section">
    <nav class="page-breadcrumb" aria-label="Breadcrumb">
      <a href="index.php">Home</a><span>/</span><span aria-current="page">About</span>
    </nav>
    <div class="page-eyebrow"><i class="fas fa-info-circle"></i> <?= e($about['tag'] ?? 'Who We Are') ?></div>
    <h1 class="page-h1"><?= e($heroTitle) ?></h1>
    <?php if ($heroSubtitle): ?><p class="page-lead"><?= e($heroSubtitle) ?></p><?php endif; ?>
    <div class="page-cta-row">
      <a href="contact.php" class="btn-primary-hero"><?= e($pc['cta_text'] ?: 'Request a Survey') ?> <i class="fas fa-arrow-right"></i></a>
      <a href="index.php#services" class="btn-ghost-hero">Our Services</a>
    </div>
  </div>
</section>

<section id="content">
  <div class="section">
    <div class="about-grid reveal">
      <div>
        <div class="tag"><?= e($about['tag'] ?? 'Our Story') ?></div>
        <h2 class="sec-h2"><?= e($about['title'] ?? 'About YMR Marine') ?></h2>
        <p class="sec-body"><?= nl2br(e($pc['body'] ?: ($about['body'] ?? ''))) ?></p>
        <?php if (!empty($pc['body2']) || !empty($about['body2'])): ?>
        <p class="sec-body" style="margin-top:1rem;"><?= nl2br(e($pc['body2'] ?: ($about['body2'] ?? ''))) ?></p>
        <?php endif; ?>
        <div class="about-stats" style="margin-top:1.75rem;">
          <div class="astat"><div class="astat-val"><?= e($about['stat1_value'] ?? '18+') ?></div><div class="astat-label"><?= e($about['stat1_label'] ?? '') ?></div></div>
          <div class="astat"><div class="astat-val"><?= e($about['stat2_value'] ?? '5000+') ?></div><div class="astat-label"><?= e($about['stat2_label'] ?? '') ?></div></div>
          <div class="astat"><div class="astat-val"><?= e($about['stat3_value'] ?? '100+') ?></div><div class="astat-label"><?= e($about['stat3_label'] ?? '') ?></div></div>
          <div class="astat"><div class="astat-val"><?= e($about['stat4_value'] ?? '24/7') ?></div><div class="astat-label"><?= e($about['stat4_label'] ?? '') ?></div></div>
        </div>
      </div>
      <div class="about-img-stack reveal reveal-delay-2">
        <?php
          $imgMain = $about['img_main'] ?? '';
          if ($imgMain === '') $imgMain = 'https://images.unsplash.com/photo-1693045734143-e3ee9235ec62?q=80&w=1200&auto=format&fit=crop';
        ?>
        <img src="<?= e($imgMain) ?>" alt="YMR Marine survey operations" class="about-img-main" loading="lazy">
        <?php if (!empty($about['img_secondary'])): ?>
        <img src="<?= e($about['img_secondary']) ?>" alt="Marine operations" class="about-img-secondary" loading="lazy">
        <?php endif; ?>
        <div class="cert-pill">
          <div class="cert-icon"><i class="fas fa-check"></i></div>
          <div class="cert-text">
            <div class="t1"><?= e($about['cert_title'] ?? 'Experienced Surveyors') ?></div>
            <div class="t2"><?= e($about['cert_subtitle'] ?? 'Quality Assured') ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="section-alt">
<section>
  <div class="section reveal">
    <div class="tag">Next Step</div>
    <h2 class="sec-h2">Need a survey at your next port call?</h2>
    <p class="sec-body" style="margin-bottom:1.5rem;">Share vessel details and we will confirm surveyor availability and scope.</p>
    <a href="contact.php" class="btn-primary-hero" style="display:inline-flex;"><?= e($pc['cta_text'] ?: 'Contact Operations') ?> <i class="fas fa-arrow-right"></i></a>
  </div>
</section>
</div>

<?php require __DIR__ . '/includes/public_layout_end.php'; ?>
