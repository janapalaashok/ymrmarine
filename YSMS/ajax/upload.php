<?php
session_start();
require_once("../config/auth.php");
require_once("../config/database.php");

if($_SERVER['REQUEST_METHOD']==='POST'){
    $id=(int)$_POST['vessel_id'];

    $excelName=time().'_'.basename($_FILES['excel_file']['name']);
    $pdfName=time().'_'.basename($_FILES['pdf_file']['name']);

    move_uploaded_file($_FILES['excel_file']['tmp_name'],"../uploads/excel/".$excelName);
    move_uploaded_file($_FILES['pdf_file']['tmp_name'],"../uploads/pdf/".$pdfName);

    $stmt=$pdo->prepare("INSERT INTO uploaded_files(vessel_id,excel_file,pdf_file,upload_date,remarks)
    VALUES(?,?,?,?,?)");
    $stmt->execute([
        $id,
        $excelName,
        $pdfName,
        date('Y-m-d H:i:s'),
        $_POST['remarks'] ?? ''
    ]);

    $pdo->prepare("UPDATE vessels SET status='Pending Report' WHERE id=?")->execute([$id]);

    header("Location: ../vessels/pending-reports.php");
    exit;
}
?>