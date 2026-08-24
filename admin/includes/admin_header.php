<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$current = basename($_SERVER['PHP_SELF'], '.php');
$adminName = $_SESSION['admin_name'] ?? 'Admin';
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin | YMR Marine</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="admin-wrap">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <span class="a">YMR</span> <span class="b">Admin</span>
    </div>
    <nav class="sidebar-nav">
      <a href="index.php" class="<?= $current==='index'?'active':'' ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
      <a href="settings.php" class="<?= $current==='settings'?'active':'' ?>"><i class="fas fa-cog"></i> Site Settings</a>
      <a href="hero.php" class="<?= $current==='hero'?'active':'' ?>"><i class="fas fa-home"></i> Hero Section</a>
      <a href="about.php" class="<?= $current==='about'?'active':'' ?>"><i class="fas fa-info-circle"></i> About</a>
      <a href="services.php" class="<?= $current==='services'?'active':'' ?>"><i class="fas fa-ship"></i> Services</a>
      <a href="pages.php" class="<?= $current==='pages'?'active':'' ?>"><i class="fas fa-file-alt"></i> Static Pages</a>
      <a href="whyus.php" class="<?= $current==='whyus'?'active':'' ?>"><i class="fas fa-star"></i> Why Us</a>
      <a href="team.php" class="<?= $current==='team'?'active':'' ?>"><i class="fas fa-users"></i> Team</a>
      <a href="ports.php" class="<?= $current==='ports'?'active':'' ?>"><i class="fas fa-anchor"></i> Ports</a>
      <a href="testimonials.php" class="<?= $current==='testimonials'?'active':'' ?>"><i class="fas fa-quote-left"></i> Testimonials</a>
      <a href="messages.php" class="<?= $current==='messages'?'active':'' ?>"><i class="fas fa-envelope"></i> Messages</a>
      <a href="../index.php" target="_blank"><i class="fas fa-external-link-alt"></i> View Website</a>
    </nav>
    <div class="sidebar-footer">
      <div class="user-info"><i class="fas fa-user-circle"></i> <?= e($adminName) ?></div>
      <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
      <div class="topbar-title"><?= e(ucfirst(str_replace(['.php','_'],['',' '], $current))) ?></div>
      <div class="topbar-right">
        <a href="../index.php" target="_blank" class="btn-sm"><i class="fas fa-eye"></i> Preview</a>
      </div>
    </header>
    <div class="content">
      <?php if ($flash): ?>
      <div class="alert alert-<?= e($flash['type']) ?>">
        <?= e($flash['msg']) ?>
        <button onclick="this.parentElement.remove()">&times;</button>
      </div>
      <?php endif; ?>
