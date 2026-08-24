<?php
session_start();
require_once("../config/database.php");

if($_SERVER['REQUEST_METHOD']!=='POST'){ exit("Invalid Request"); }

$username=trim($_POST['username']??'');
$password=trim($_POST['password']??'');
$role=trim($_POST['role']??'');

$stmt=$pdo->prepare("SELECT * FROM users WHERE username=? AND role=? AND status='Active' LIMIT 1");
$stmt->execute([$username,$role]);
$user=$stmt->fetch(PDO::FETCH_ASSOC);

if(!$user){ exit("Invalid Username"); }

if(!password_verify($password,$user['password'])){
    exit("Incorrect Password");
}

$_SESSION['user_id']=$user['id'];
$_SESSION['first_name']=$user['first_name'];
$_SESSION['role']=$user['role'];

echo "success";
?>