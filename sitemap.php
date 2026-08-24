<?php
/**
 * Dynamic XML sitemap – visit /sitemap.php or set rewrite to sitemap.xml
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/service_helpers.php';

header('Content-Type: application/xml; charset=utf-8');

$base = 'https://www.ymrmarine.com';
$urls = [
    ['loc' => $base . '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
    ['loc' => $base . '/about-us.php', 'priority' => '0.8', 'changefreq' => 'monthly'],
    ['loc' => $base . '/ports.php', 'priority' => '0.8', 'changefreq' => 'monthly'],
    ['loc' => $base . '/contact.php', 'priority' => '0.7', 'changefreq' => 'monthly'],
    ['loc' => $base . '/index.php', 'priority' => '0.9', 'changefreq' => 'weekly'],
];

try {
    $pdo = getDB();
    ensureServicePageColumns($pdo);
    seedServicePagesIfNeeded($pdo);
    $services = $pdo->query('SELECT slug, title FROM services WHERE is_active = 1 ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($services as $s) {
        $slug = $s['slug'] ?: serviceSlugify($s['title']);
        $urls[] = [
            'loc' => $base . '/service.php?slug=' . rawurlencode($slug),
            'priority' => '0.85',
            'changefreq' => 'monthly',
        ];
    }
} catch (Exception $e) {
    // still output static URLs
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= htmlspecialchars($u['loc'], ENT_XML1) ?></loc>
    <changefreq><?= $u['changefreq'] ?></changefreq>
    <priority><?= $u['priority'] ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
