<?php
if (PHP_SAPI !== 'cli') exit(1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$action=$argv[1]??'fingerprint';
$target=$argv[2]??'db_spp';
if($target!=='db_spp' && !preg_match('/^db_spp_audit_[a-z0-9_]+$/D',$target))throw new RuntimeException('Unsafe database');
$db=new mysqli('localhost','root','',$target);$db->set_charset('utf8mb4');
if($action==='setup'){
    if($target==='db_spp'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')throw new RuntimeException('Clone setup only');
    $hash=password_hash(trim(file_get_contents(getenv('SPP_TEST_ADMIN_PASSWORD_FILE'))),PASSWORD_DEFAULT);
    $stmt=$db->prepare("UPDATE admin SET password=? WHERE username='superadmin' AND role='super_admin'");$stmt->bind_param('s',$hash);$stmt->execute();
    $ids=[];
    foreach([1,2,3] as $unit){$db->query('SET @app_unit_id='.$unit);$ids[$unit]=$db->query('SELECT id,NO_INDUK FROM bayar ORDER BY id DESC LIMIT 1')->fetch_assoc();}
    file_put_contents(getenv('SPP_UI_IDS_FILE'),json_encode($ids,JSON_THROW_ON_ERROR));
    echo "Clone credentials and IDs prepared\n";exit;
}
$hashes=[];
foreach($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC) as $table){
    $name=$table['TABLE_NAME'];$rows=$db->query("SELECT * FROM `$name`")->fetch_all(MYSQLI_ASSOC);
    $encoded=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$rows);sort($encoded,SORT_STRING);
    $hashes[$name]=hash('sha256',implode("\n",$encoded));
}
$db->query('SET @app_unit_id=0');
echo json_encode(['database'=>$target,'students'=>(int)$db->query('SELECT COUNT(*) FROM siswa')->fetch_row()[0],
    'payments'=>(int)$db->query('SELECT COUNT(*) FROM bayar')->fetch_row()[0],
    'receipts'=>(float)$db->query('SELECT SUM(total_jumlah) FROM bayar')->fetch_row()[0],
    'savings_accounts'=>(int)$db->query('SELECT COUNT(*) FROM tabungan')->fetch_row()[0],
    'savings_balance'=>(float)$db->query('SELECT SUM(SALDO) FROM tabungan')->fetch_row()[0],
    'hashes'=>$hashes],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
