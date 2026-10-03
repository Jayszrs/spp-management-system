<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||getenv('SPP_DB_NAME')!=='db_spp_audit_legacy_backend_20261003')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_import.php';
function lw_assert($ok,$why){if(!$ok)throw new RuntimeException($why);}
function lw_worker(){ $p=proc_open([PHP_BINARY,__DIR__.'/../scripts/legacy_worker.php','--once'],[0=>['pipe','r'],1=>['file',legacy_root().'/failure-test-worker.log','a'],2=>['file',legacy_root().'/failure-test-error.log','a']],$pipes);fclose($pipes[0]);lw_assert(proc_close($p)===0,'Worker exited unexpectedly'); }
unit_set_context($koneksi,0);$before=(int)$koneksi->query('SELECT COUNT(*) FROM siswa')->fetch_row()[0];$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$root=legacy_root();$temp=$root.'/failure-fixture.dat';file_put_contents($temp,'not a SQL Server backup');
lw_assert(legacy_upload_error('large.dat',104857601)[0]===413&&legacy_upload_error('edge.dat',104857600)===null,'HTTP size gate boundary');
foreach([['empty.dat',''],['wrong.sql','SQL']] as [$name,$data]){file_put_contents($temp,$data);try{legacy_enqueue($temp,$name,1,$actor);throw new LogicException('Invalid upload accepted');}catch(RuntimeException $e){}}
$f=fopen($temp,'w');ftruncate($f,104857601);fclose($f);try{legacy_enqueue($temp,'large.dat',1,$actor);throw new LogicException('Oversized upload accepted');}catch(RuntimeException $e){}
file_put_contents($temp,'not a SQL Server backup');$j=legacy_enqueue($temp,'../../<bad>.dat',1,$actor);lw_assert($j['name']==='<bad>.dat','Name retained path');lw_worker();lw_assert(legacy_job($j['id'])['state']==='failed','Corrupt backup reported success');
$j=legacy_enqueue('C:/Users/lakch/Downloads/SD-5.dat','SD-5.dat',2,$actor);lw_worker();lw_assert(legacy_job($j['id'])['state']==='failed','Wrong unit profile accepted');
$j=legacy_enqueue($temp,'cancel.dat',1,$actor);legacy_update($j['id'],['cancel'=>1]);lw_worker();lw_assert(legacy_job($j['id'])['state']==='cancelled','Queued cancellation ignored');
$j=legacy_enqueue($temp,'revoked.dat',1,0);lw_worker();lw_assert(legacy_job($j['id'])['state']==='failed','Revoked author applied');
// Stop the parent after the extractor has recorded its identity; restart must reap only its owned child.
$j=legacy_enqueue('C:/Users/lakch/Downloads/SMP-5.dat','SMP-5.dat',2,$actor);
$p=proc_open([PHP_BINARY,__DIR__.'/../scripts/legacy_worker.php','--once'],[0=>['pipe','r'],1=>['file',$root.'/interrupt-worker.log','a'],2=>['file',$root.'/interrupt-error.log','a']],$pipes);fclose($pipes[0]);
$deadline=time()+45;while(!is_file($root.'/'.$j['id'].'/extract-process.json')&&time()<$deadline)usleep(100000);
lw_assert(is_file($root.'/'.$j['id'].'/extract-process.json'),'Extractor identity unavailable');proc_terminate($p);proc_close($p);lw_worker();lw_assert(legacy_job($j['id'])['state']==='failed','Interrupted extraction not held');
// A committed manifest makes an interrupted application replay safe (full atomic test covers commit recovery).
if(!is_dir(__DIR__.'/../tmp'))mkdir(__DIR__.'/../tmp',0700);
lw_assert(!is_file(__DIR__.'/../tmp/financial_migration.lock'),'Existing maintenance not owned by test');
file_put_contents(__DIR__.'/../tmp/financial_migration.lock','owned legacy worker test');
try{$j=legacy_enqueue($temp,'maintenance.dat',1,$actor);lw_worker();lw_assert(legacy_job($j['id'])['state']==='queued','Worker wrote through maintenance');legacy_update($j['id'],['state'=>'cancelled','cancel'=>1]);}finally{unlink(__DIR__.'/../tmp/financial_migration.lock');}
lw_assert((int)$koneksi->query('SELECT COUNT(*) FROM siswa')->fetch_row()[0]===$before,'Failure tests changed students');
unlink($temp);echo "PASS: corrupt/wrong profile, size/extension/empty, safe filename, cancellation, author revocation, parent interruption recovery and maintenance; no students written\n";
