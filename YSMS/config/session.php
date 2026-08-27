<?php
if(session_status()===PHP_SESSION_NONE){
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime'=>0,
        'path'=>'/',
        'httponly'=>true,
        'samesite'=>'Lax',
        'secure'=>$isHttps
    ]);
    session_start();
}
require_once __DIR__ . '/../../includes/csrf.php';
?>