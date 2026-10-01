<?php
session_start();
require_once 'koneksi.php';
require_once 'includes/auth.php';
requireRole(['super_admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_SESSION['csrf_unit_switch'])
    || !hash_equals((string)($_SESSION['csrf_unit_switch'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403); exit('Permintaan tidak valid.');
}
$unitId=filter_input(INPUT_POST,'unit_id',FILTER_VALIDATE_INT);
if (!in_array($unitId,[1,2,3],true)) { http_response_code(422); exit('Unit tidak valid.'); }
$_SESSION['active_unit_id']=$unitId;
unit_set_context($koneksi,$unitId);
$next=(string)($_POST['next'] ?? 'dashboard.php');
$base=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base==='.') $base='';
if (!str_starts_with($next,$base.'/') || str_starts_with($next,'//') || str_contains($next,"\r") || str_contains($next,"\n")) {
    $next=$base.'/dashboard.php';
}
$path=parse_url($next, PHP_URL_PATH);
if (in_array($path, [$base.'/dashboard.php', $base.'/laporan/index.php', $base.'/laporan/global.php', $base.'/laporan/template.php'], true)) {
    parse_str((string)(parse_url($next, PHP_URL_QUERY) ?? ''), $params);
    if (($params['unit'] ?? '') === 'all') {
        $params['unit']='active';
        $next=$path.'?'.http_build_query($params);
    }
}
header('Location: '.$next, true, 303);
