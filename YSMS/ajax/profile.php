<?php
session_start();
require_once("../config/auth.php");
require_once("../config/database.php");

$id=$_SESSION['user_id'];

$photo=null;
if(!empty($_FILES['profile_photo']['name'])){
    $photo=time().'_'.basename($_FILES['profile_photo']['name']);
    move_uploaded_file($_FILES['profile_photo']['tmp_name'],"../uploads/profile/".$photo);
    $pdo->prepare("UPDATE users SET profile_photo=? WHERE id=?")->execute([$photo,$id]);
}

$stmt=$pdo->prepare("UPDATE users
SET first_name=?,last_name=?,email=?,mobile=?,address=?
WHERE id=?");

$stmt->execute([
$_POST['first_name'],
$_POST['last_name'],
$_POST['email'],
$_POST['mobile'],
$_POST['address'],
$id
]);

echo "success";
?>