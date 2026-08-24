<?php
require_once 'config/config.php';
$_SESSION = [];
session_destroy();
header("Location: login.php");
exit;
?>