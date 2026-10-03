<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')exit(1);
require_once __DIR__.'/../includes/legacy_schema.php';
$dump=getenv('SPP_LEGACY_TEST_DUMP');$hash=getenv('SPP_LEGACY_TEST_DUMP_HASH');if(!is_file($dump)||!hash_equals($hash,hash_file('sha256',$dump)))throw new RuntimeException('Verified external backup required');
$name='db_spp_audit_legacy_migration_'.bin2hex(random_bytes(5));$db=new mysqli('localhost','root','');$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
function lm_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
function lm_data($db){$data=[];foreach($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_NUM) as [$t]){$rows=$db->query("SELECT * FROM `$t`")->fetch_all(MYSQLI_ASSOC);foreach($rows as &$r)unset($r['legacy_pending']);unset($r);$strings=array_map(fn($r)=>json_encode($r),$rows);sort($strings);$data[$t]=hash('sha256',implode("\n",$strings));}return $data;}
try{
 $sql=str_replace('`db_spp`','`'.$name.'`',file_get_contents($dump));$working=dirname($dump).'/'.$name.'.sql';file_put_contents($working,$sql);
 $p=proc_open(['C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe','--user=root','--host=localhost',$name],[0=>['file',$working,'r'],1=>['file',$working.'.log','w'],2=>['file',$working.'.error','w']],$pipes);lm_assert(proc_close($p)===0,'Backup restore failed');$db->select_db($name);$db->query('SET @app_unit_id=0');$before=lm_data($db);
 $fk=$db->query("SELECT k.TABLE_NAME,k.COLUMN_NAME,k.CONSTRAINT_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME='siswa_data' AND k.REFERENCED_COLUMN_NAME='NO_INDUK' LIMIT 1")->fetch_assoc();
 $db->query("ALTER TABLE `{$fk['TABLE_NAME']}` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");$failed=false;try{legacy_schema_apply($db);}catch(RuntimeException $e){$failed=true;}lm_assert($failed,'Missing relation not blocked');lm_assert(lm_data($db)===$before,'Failed preflight changed data');
 $db->query("ALTER TABLE `{$fk['TABLE_NAME']}` ADD CONSTRAINT `{$fk['CONSTRAINT_NAME']}` FOREIGN KEY (`{$fk['COLUMN_NAME']}`) REFERENCES siswa_data(NO_INDUK) ON DELETE {$fk['DELETE_RULE']} ON UPDATE {$fk['UPDATE_RULE']}");
 // Simulate a stopped first DDL stage: marker exists but constraints/indices are absent.
 $db->query('ALTER TABLE siswa_data ADD legacy_pending TINYINT(1) NOT NULL DEFAULT 0');legacy_schema_apply($db);lm_assert(legacy_schema_ready($db),'Partial migration not completed');
 $after=lm_data($db);foreach($before as $t=>$h)lm_assert($after[$t]===$h,'Existing data changed: '.$t);
 legacy_schema_apply($db);lm_assert(lm_data($db)===$after,'Replay changed data');
 $db->query('ALTER TABLE siswa_data DROP CHECK chk_siswa_legacy_pending');lm_assert(!legacy_schema_ready($db),'Missing CHECK health not detected');legacy_schema_apply($db);lm_assert(legacy_schema_ready($db),'CHECK not restored');
 echo "PASS: verified full backup restore, preflight missing-FK refusal, partial DDL completion, idempotent replay, CHECK repair and all original row fingerprints unchanged\n";
}finally{$db->select_db('mysql');$db->query("DROP DATABASE `$name`");}
