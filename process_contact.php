<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php#contact');
}

$name    = trim($_POST['name'] ?? '');
$company = trim($_POST['company'] ?? '');
$email   = trim($_POST['email'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$service = trim($_POST['service'] ?? '');
$port    = trim($_POST['port'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name && $email) {
    try {
        $stmt = getDB()->prepare('INSERT INTO contact_submissions (name, company, email, phone, service, port, message) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$name, $company, $email, $phone, $service, $port, $message]);
    } catch (Exception $e) {
        // silent fail for demo
    }
}

// Simple redirect with success (you can enhance with session flash)
header('Location: index.php?sent=1#contact');
exit;
