<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

$siteName   = getSetting('site_name', 'YMR Marine');
$phone      = getSetting('phone');
$email      = getSetting('email');
$logo       = getSetting('logo', 'ymr_logo.png');
$empLogin   = getSetting('employee_login_url', '#');
$primary    = getSetting('primary_color', '#02bbff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
$currentUrl = "https://www.ymrmarine.com" . ($_SERVER['REQUEST_URI'] ?? '/');

// Allow per-page overrides before including this file
$pageTitle = $pageTitle ?? "Marine Survey Services | Bunker, Draft, Cargo & Pre-Purchase Inspection | YMR Marine Solutions LLP";

$pageDescription = $pageDescription ?? "YMR Marine Solutions LLP provides professional Pre Purchase Inspection, Bunker Survey, Draft Survey, Cargo Survey, On Hire & Off Hire Inspection services across Indian and international ports.";

$pageKeywords = $pageKeywords ?? "Marine Survey, Bunker Survey, Draft Survey, Cargo Survey, Pre Purchase Inspection, On Hire Survey, Off Hire Survey, Ship Inspection India, Marine Surveyor, YMR Marine Solutions";

$logoUrl = "https://www.ymrmarine.com/" . ltrim($logo, '/');
?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= $pageTitle ?></title>

<meta name="description" content="<?= $pageDescription ?>">
<meta name="keywords" content="<?= $pageKeywords ?>">
<meta name="author" content="YMR Marine Solutions LLP">
<meta name="robots" content="index, follow, max-image-preview:large">
<meta name="language" content="English">
<meta name="revisit-after" content="7 days">

<link rel="canonical" href="<?= $currentUrl ?>">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $pageTitle ?>">
<meta property="og:description" content="<?= $pageDescription ?>">
<meta property="og:url" content="<?= $currentUrl ?>">
<meta property="og:image" content="<?= $logoUrl ?>">
<meta property="og:site_name" content="YMR Marine Solutions LLP">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $pageTitle ?>">
<meta name="twitter:description" content="<?= $pageDescription ?>">
<meta name="twitter:image" content="<?= $logoUrl ?>">

<!-- Theme -->
<meta name="theme-color" content="<?= e($primary) ?>">

<!-- Favicon -->
<!-- Favicon & App Icons -->
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
<link rel="manifest" href="site.webmanifest">
<meta name="msapplication-TileColor" content="#02bbff">
<meta name="msapplication-TileImage" content="android-chrome-192x192.png" type="image/png">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Lexend:wght@100..900&display=swap" rel="stylesheet">

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<link rel="stylesheet" href="assets/css/style.css">

<style>
:root{
    --accent: <?= e($primary) ?>;
}
</style>

<!-- Structured Data -->
<script type="application/ld+json">
{
 "@context":"https://schema.org",
 "@type":"ProfessionalService",
 "name":"YMR Marine Solutions LLP",
 "url":"https://www.ymrmarine.com",
 "logo":"https://www.ymrmarine.com/<?= e($logo) ?>",
 "image":"https://www.ymrmarine.com/<?= e($logo) ?>",
 "telephone":"<?= e($phone) ?>",
 "email":"<?= e($email) ?>",
 "description":"Marine Survey Company providing Pre Purchase Inspection, Bunker Survey, Draft Survey, Cargo Survey, Condition Survey and Marine Consultancy.",
 "areaServed":"Worldwide",
 "serviceType":[
   "Pre Purchase Inspection",
   "Ship Pre Purchase Inspection",
   "Vessel Inspection",
   "Bunker Survey",
   "Draft Survey",
   "Cargo Survey",
   "Condition Survey",
   "On Hire Survey",
   "Off Hire Survey"
 ]
}
</script></head>
<body>
<nav class="nav" id="mainNav">
  <div class="nav-inner">
    <a href="index.php" class="nav-logo">
      <?php if ($logo): ?><img src="<?= e($logo) ?>" width="50" height="50" alt="Logo"><?php endif; ?>
      <span class="accent">YMR</span><span class="rest">MARINE</span>
    </a>
    <ul class="nav-links">
      <li><a href="about-us.php">About</a></li>
      <li><a href="#services">Services</a></li>
      <li><a href="#why-us">Why Us</a></li>
      <li><a href="#team">Team</a></li>
      <li><a href="ports.php">Ports</a></li>
      <li><a href="#testimonials">Clients</a></li>
      <li><a href="contact.php">Contact</a></li>
    </ul>
    <a href="<?= e($empLogin) ?>" class="btn-nav" target="_blank">Employee Login</a>
    <button class="nav-toggle" id="navToggle" aria-label="Open menu"><span></span><span></span><span></span></button>
  </div>
  <div class="nav-mobile" id="navMobile">
    <a href="about-us.php">About</a><a href="#services">Services</a><a href="#why-us">Why Us</a>
    <a href="#team">Team</a><a href="ports.php">Ports</a><a href="#testimonials">Clients</a>
    <a href="contact.php">Contact</a>
    <a href="<?= e($empLogin) ?>" class="btn-nav" target="_blank">Employee Login</a>
  </div>
</nav>
