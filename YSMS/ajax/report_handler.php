<?php
declare(strict_types=1);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Inspection report synchronized successfully.', 'redirect' => 'index.php?module=reports']);