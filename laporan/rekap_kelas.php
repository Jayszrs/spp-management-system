<?php
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin','bendahara','kasir']);
$params=['template'=>'per-item'];
if(isset($_GET['bulan']))$params['bulan_awal']=$params['bulan_akhir']=$_GET['bulan'];
if(isset($_GET['tahun']))$params['tahun']=$_GET['tahun'];
if(isset($_GET['q']))$params['q']=$_GET['q'];
if(isset($_GET['kelas'])){
    $level=(int)$_GET['kelas'];
    $stmt=$koneksi->prepare('SELECT id FROM master_kelas WHERE tingkat=? AND is_placeholder=1 LIMIT 1');
    $stmt->bind_param('i',$level);$stmt->execute();$class=$stmt->get_result()->fetch_assoc();$stmt->close();
    if($class)$params['kelas']=(int)$class['id'];
}
header('Location: template.php?'.http_build_query($params),true,302);
exit;
