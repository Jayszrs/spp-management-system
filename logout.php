<?php
// ============================================
// logout.php
// ============================================
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Metode permintaan tidak didukung.');
}
$token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['admin_id'], $_SESSION['csrf_logout'])
    || !is_string($token)
    || !hash_equals($_SESSION['csrf_logout'], $token)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}
session_unset();
session_destroy();
header('Location: login.php', true, 303);
exit;
