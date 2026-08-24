<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/page_helpers.php';
require_once __DIR__ . '/includes/service_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = getDB();
$chrome = loadSiteChrome();
$pc = getPageContent($pdo, 'ports');

$ports = [];
try {
    $ports = $pdo->query('SELECT * FROM ports WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$heroImage = !empty($pc['hero_image']) ? $pc['hero_image'] : pageDefaultHero('ports');
$pageTitle = $pc['meta_title'] ?: 'Ports Covered | YMR Marine Survey Attendance';
$pageDescription = $pc['meta_description'] ?: '';
$pageKeywords = $pc['meta_keywords'] ?: '';
$canonicalUrl = 'https://www.ymrmarine.com/ports.php';
$activeNav = 'ports';

$itemList = [];
foreach ($ports as $i => $p) {
    $itemList[] = [
        '@type' => 'ListItem',
        'position' => $i + 1,
        'name' => $p['name'],
    ];
}
$schemaJson = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $pageTitle,
    'description' => $pageDescription,
    'url' => $canonicalUrl,
    'mainEntity' => [
        '@type' => 'ItemList',
        'itemListElement' => $itemList,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

require __DIR__ . '/includes/public_layout_start.php';
?>

<section class="page-hero">
  <div class="section">
    <nav class="page-breadcrumb" aria-label="Breadcrumb">
      <a href="index.php">Home</a><span>/</span><span aria-current="page">Ports</span>
    </nav>
    <div class="page-eyebrow"><i class="fas fa-anchor"></i> Coverage</div>
    <h1 class="page-h1">Major ports covered across India</h1>
    <?php if (!empty($pc['hero_subtitle'])): ?>
    <p class="page-lead"><?= e($pc['hero_subtitle']) ?></p>
    <?php endif; ?>
    <div class="page-cta-row">
      <a href="contact.php" class="btn-primary-hero"><?= e($pc['cta_text'] ?: 'Enquire for Your Port') ?> <i class="fas fa-arrow-right"></i></a>
      <a href="index.php#services" class="btn-ghost-hero">Services</a>
    </div>
  </div>
</section>

<section>
  <div class="section">
    <div class="reveal">
      <div class="tag">Port Network</div>
      <h2 class="sec-h2">Where we mobilise</h2>
      <p class="sec-body"><?= nl2br(e($pc['body'] ?? '')) ?></p>
      <?php if (!empty($pc['body2'])): ?>
      <p class="sec-body" style="margin-top:1rem;"><?= nl2br(e($pc['body2'])) ?></p>
      <?php endif; ?>
    </div>

    <div class="ports-grid" style="margin-top:2rem;">
      <?php if (empty($ports)): ?>
        <p class="sec-body">Port list is being updated. Please contact us for current coverage.</p>
      <?php else: ?>
        <?php foreach ($ports as $i => $p): ?>
        <div class="port-card reveal reveal-delay-<?= ($i % 4) + 1 ?>">
          <div class="port-anchor"><i class="fas fa-anchor"></i></div>
          <div class="port-name"><?= e($p['name']) ?></div>
          <div class="port-state"><?= e($p['state'] ?? '') ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="reveal" style="margin-top:1.5rem;">
      <div class="ports-extra"><i class="fas fa-ship"></i> Extended coverage — additional ports and overseas locations on request</div>
    </div>
  </div>
</section>

<div class="section-alt">
<section>
  <div class="section reveal">
    <div class="tag">Mobilisation</div>
    <h2 class="sec-h2">Planning a call at a listed port?</h2>
    <p class="sec-body" style="margin-bottom:1.5rem;">Send vessel name, ETA and survey type — we confirm attendance quickly.</p>
    <a href="contact.php" class="btn-primary-hero" style="display:inline-flex;"><?= e($pc['cta_text'] ?: 'Contact Operations') ?> <i class="fas fa-arrow-right"></i></a>
  </div>
</section>
</div>

<?php require __DIR__ . '/includes/public_layout_end.php'; ?>
