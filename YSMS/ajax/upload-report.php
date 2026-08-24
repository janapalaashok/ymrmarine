<?php
require_once "../config/auth.php";
require_once "../config/database.php";

if($_SERVER['REQUEST_METHOD']!=='POST'){ exit('Invalid Request'); }

$vessel_id = (int)$_POST['vessel_id'];
$remarks = $_POST['remarks'] ?? '';

$dir = "../uploads/reports/";
if(!is_dir($dir)){ mkdir($dir,0777,true); }

foreach($_FILES['reports']['tmp_name'] as $k=>$tmp){
    if(!$tmp) continue;
    $name = basename($_FILES['reports']['name'][$k]);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if(!in_array($ext,['doc','docx'])) continue;
    $new = time().'_'.$name;
    move_uploaded_file($tmp,$dir.$new);

    $stmt=$pdo->prepare("INSERT INTO report_uploads(vessel_id,file_name,remarks,uploaded_by,uploaded_at)
    VALUES(?,?,?,?,NOW())");
    $stmt->execute([$vessel_id,$new,$remarks,$_SESSION['user_id']]);
}

$stmt=$pdo->prepare("UPDATE vessels SET status='Completed', completion_date=NOW() WHERE id=?");
$stmt->execute([$vessel_id]);

header("Location: ../vessels/completed-reports.php");
exit;
