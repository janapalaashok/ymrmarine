<?php
// 📧 SMTP configuration — Gmail (testing on InfinityFree)
// For production later: switch back to Hostinger smtp.hostinger.com + mailbox password.

if (!defined('SMTP_HOST'))       define('SMTP_HOST', getenv('YSMS_SMTP_HOST') ?: 'smtp.gmail.com');
if (!defined('SMTP_PORT'))       define('SMTP_PORT', (int)(getenv('YSMS_SMTP_PORT') ?: 587));
if (!defined('SMTP_SECURE'))     define('SMTP_SECURE', getenv('YSMS_SMTP_SECURE') ?: 'tls'); // 'tls' or 'ssl'
if (!defined('SMTP_USERNAME'))   define('SMTP_USERNAME', getenv('YSMS_SMTP_USERNAME') ?: 'janapalaa@gmail.com');
if (!defined('SMTP_PASSWORD'))   define('SMTP_PASSWORD', getenv('YSMS_SMTP_PASSWORD') ?: '');
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', getenv('YSMS_SMTP_FROM_EMAIL') ?: 'janapalaa@gmail.com');
if (!defined('SMTP_FROM_NAME'))  define('SMTP_FROM_NAME', getenv('YSMS_SMTP_FROM_NAME') ?: 'YMR Survey Management System');

// ── Optional WhatsApp auto-notify (job assign) ──────────────────────────────
if (!defined('WHATSAPP_CALLMEBOT_APIKEY')) {
    define('WHATSAPP_CALLMEBOT_APIKEY', getenv('YSMS_WHATSAPP_CALLMEBOT_APIKEY') ?: '');
}
if (!defined('WHATSAPP_CLOUD_TOKEN')) {
    define('WHATSAPP_CLOUD_TOKEN', getenv('YSMS_WHATSAPP_CLOUD_TOKEN') ?: '');
}
if (!defined('WHATSAPP_CLOUD_PHONE_ID')) {
    define('WHATSAPP_CLOUD_PHONE_ID', getenv('YSMS_WHATSAPP_CLOUD_PHONE_ID') ?: '');
}
