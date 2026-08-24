<?php
require_once "../config/auth.php";
require_once "../config/database.php";
$vessel_id=(int)($_GET['vessel_id']??0);
if($vessel_id>0){
$pdo->prepare("UPDATE vessels SET status='Completed' WHERE id=?")->execute([$vessel_id]);
header("Location: ../vessels/completed-reports.php");
exit;
}
echo "Invalid Vessel ID";
