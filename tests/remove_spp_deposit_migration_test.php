<?php
/** Backup restore, tamper rejection, interrupted DDL, apply, and replay on owned clones. */
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')throw new RuntimeException('CLI/test flag required.');
$dump=(string)getenv('SPP_TEST_RETIREMENT_BACKUP');
$mysql=(string)getenv('SPP_TEST_MYSQL_BIN');
$expectedHash=strtolower((string)(getenv('SPP_TEST_RETIREMENT_BACKUP_SHA256')?:'813954ddfd4e19463aab29a6eada24f8c41d8e912020f378da6b85e164698226'));
if(!is_file($dump)||!is_file($mysql)||!preg_match('/^[a-f0-9]{64}$/D',$expectedHash)||!hash_equals($expectedHash,hash_file('sha256',$dump)))throw new RuntimeException('Verified original retirement backup required.');
$name='db_spp_audit_retirement_restore_'.bin2hex(random_bytes(6));
$db=new mysqli(getenv('SPP_DB_HOST')?:'localhost',getenv('SPP_DB_USER')?:'root',getenv('SPP_DB_PASS')?:'', '',(int)(getenv('SPP_DB_PORT')?:3306));
$owned=false;
function retirement_test_run(array $command,?string $input=null):array{
    $process=proc_open($command,[0=>$input!==null?['file',$input,'r']:['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true]);
    if(!is_resource($process))throw new RuntimeException('Cannot launch test child.');
    if($input===null)fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    return [proc_close($process),$out.$err];
}
function retirement_test_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
try{
    $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");$owned=true;
    $restore=static function()use($db,$name,$mysql,$dump):void{
        $db->query("DROP DATABASE `$name`");$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        [$code,$output]=retirement_test_run([$mysql,'-u',getenv('SPP_DB_USER')?:'root',$name],$dump);
        retirement_test_assert($code===0,'Restore failed: '.$output);$db->select_db($name);
    };
    $restore();putenv('SPP_DB_NAME='.$name);
    $command=[PHP_BINARY,dirname(__DIR__).'/sql/remove_spp_deposit.php','--apply'];
    $db->query('UPDATE spp_alokasi_batch_data SET titipan_digunakan=1 WHERE titipan_baru>0 ORDER BY id LIMIT 1');
    [$code,$output]=retirement_test_run($command);
    retirement_test_assert($code!==0&&str_contains($output,'dipakai'),'Used deposit did not stop preflight.');
    retirement_test_assert((int)$db->query('SELECT COUNT(*) FROM bayar_data')->fetch_row()[0]===1037,'Preflight rejection deleted data.');
    $restore();$db->query("UPDATE titipan_spp_mutasi_data SET keterangan=CONCAT(keterangan,' tampered') ORDER BY id LIMIT 1");
    [$code,$output]=retirement_test_run($command);
    retirement_test_assert($code!==0&&str_contains($output,'Fingerprint berbeda'),'Audited data change did not stop apply.');
    $restore();$db->query("UPDATE transaksi_otorisasi_data SET status='pending' ORDER BY id LIMIT 1");
    [$code,$output]=retirement_test_run($command);
    retirement_test_assert($code!==0&&str_contains($output,'Otorisasi perlu diperiksa'),'Pending authorization did not stop preflight.');
    $restore();$db->query('UPDATE bayar_data SET U_LAIN=1,total_jumlah=total_jumlah+1 WHERE U_TITIPAN_SPP>0 ORDER BY id LIMIT 1');
    [$code,$output]=retirement_test_run($command);
    retirement_test_assert($code!==0&&str_contains($output,'Transaksi campuran'),'Mixed transaction did not stop preflight.');
    $restore();$db->query('CREATE TABLE retirement_unmapped(id INT PRIMARY KEY,bayar_id INT,FOREIGN KEY(bayar_id) REFERENCES bayar_data(id)) ENGINE=InnoDB');
    [$code,$output]=retirement_test_run($command);
    retirement_test_assert($code!==0&&str_contains($output,'Relasi baru belum dipetakan'),'Unmapped FK did not stop preflight.');
    $restore();putenv('SPP_TEST_DEPOSIT_FAIL_DDL=1');[$code,$output]=retirement_test_run($command);
    retirement_test_assert($code!==0&&str_contains($output,'DATA_COMMITTED'),'Injected DDL failure not reached.');
    putenv('SPP_TEST_DEPOSIT_FAIL_DDL');$restore();
    retirement_test_assert((int)$db->query('SELECT COUNT(*) FROM bayar_data')->fetch_row()[0]===1037,'Restore did not recover headers.');
    [$code,$output]=retirement_test_run($command);retirement_test_assert($code===0,'Apply failed: '.$output);
    $stats=$db->query('SELECT COUNT(*) n,SUM(total_jumlah) amount FROM bayar_data')->fetch_assoc();
    retirement_test_assert((int)$stats['n']===1018&&(float)$stats['amount']===577145000.0,'Migration baseline mismatch.');
    [$code,$output]=retirement_test_run($command);retirement_test_assert($code===0&&str_contains($output,'sudah bersih'),'Migration replay changed clean schema.');
    echo "OK: verified restore, used-deposit/pending/mixed/unmapped/fingerprint rejection, post-data DDL failure recovery, complete apply, and idempotent replay.\n";
}finally{
    putenv('SPP_TEST_DEPOSIT_FAIL_DDL');
    if($owned&&preg_match('/^db_spp_audit_retirement_restore_[a-f0-9]{12}$/D',$name))$db->query("DROP DATABASE `$name`");
}
