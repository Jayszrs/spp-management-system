<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||getenv('SPP_DB_NAME')!=='db_spp_audit_legacy_backend_20261003')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_import.php';
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
if(($argv[1]??'')==='enqueue'){
    foreach([1=>'SD',2=>'SMP',3=>'SMA'] as $unit=>$name){$j=legacy_enqueue('C:/Users/lakch/Downloads/'.$name.'-5.dat',$name.'-5.dat',$unit,$actor);echo $name.' '.$j['id']."\n";}
}elseif(($argv[1]??'')==='preview'){
    foreach(legacy_queue()->query('SELECT * FROM jobs')->fetchAll(PDO::FETCH_ASSOC) as $j){echo json_encode(legacy_public($j))."\n";echo json_encode(legacy_preview($j['id'])['counts'])."\n";}
}elseif(($argv[1]??'')==='confirm'){
    legacy_queue()->exec("UPDATE jobs SET state='apply_queued' WHERE state='ready'");
}
