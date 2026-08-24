<?php

session_start();

require_once("../config/auth.php");

require_once("../config/database.php");

$id=$_POST['id'];

$update=$_POST['update'];

$remarks=$_POST['remarks'];

$stmt=$pdo->prepare("
UPDATE vessels
SET
last_updated_by=?,
last_updated_at=NOW()
WHERE id=?
");

$stmt->execute([
$_SESSION['user_id'],
$id
]);
$stmt=$pdo->prepare("
INSERT INTO vessel_updates
(
vessel_id,
vessel_update,
remarks,
updated_by
)
VALUES
(
?,?,?,?
)
");

$stmt->execute([
$id,
$update,
$remarks,
$_SESSION['user_id']
]);
echo "success";

?>