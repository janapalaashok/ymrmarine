<?php
session_start();
require_once "../config/auth.php";
require_once "../config/database.php";

if($_SERVER['REQUEST_METHOD']!=='POST'){exit('Invalid Request');}

$stmt=$pdo->prepare("INSERT INTO vessels
(vessel_name,survey_type,survey_place,client,agent,assigned_surveyor,remarks,status)
VALUES (?,?,?,?,?,?,?,'Pending Vessel')");

$ok=$stmt->execute([
trim($_POST['vessel_name']),
trim($_POST['survey_type']),
trim($_POST['survey_place']),
trim($_POST['client']),
trim($_POST['agent']),
(int)$_POST['assigned_surveyor'],
trim($_POST['remarks'] ?? '')
]);

echo $ok ? "success" : "Database Error";
?>