<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||getenv('SPP_DB_NAME')!=='db_spp_audit_legacy_backend_20261003')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_import.php';
function li_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
unit_set_context($koneksi,1);$_SESSION['active_unit_id']=1;
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$before=[];foreach(['bayar','tabungan','transaksi_m','transaksi_k','siswa_tahun_ajaran','tagihan_spp','tagihan_komite','tagihan_daftar_ulang'] as $table)$before[$table]=$koneksi->query('SELECT COUNT(*) FROM '.$table)->fetch_row()[0];
$file=tempnam(legacy_root(),'fixture');file_put_contents($file,'deliberately invalid SQL Server backup');
$job=legacy_enqueue($file,'../../<script>.dat',1,$actor);unlink($file);
// Keep the worker out of this deterministic stage/apply failure fixture.
legacy_update($job['id'],['state'=>'processing']);
$base=(string)random_int(9100000000,9199999990);$second=(string)((int)$base+1);
$rows=[['NO_INDUK'=>$base,'NAMA'=>'Atomic fixture A','KELAS'=>'?'],['NO_INDUK'=>$second,'NAMA'=>'Atomic fixture B','KELAS'=>'LAST'],['NO_INDUK'=>'bad','NAMA'=>'Invalid identity','KELAS'=>'1'],['NO_INDUK'=>'001','NAMA'=>'Staff','KELAS'=>'GK'],['NO_INDUK'=>'002','NAMA'=>'Ambiguous source','KELAS'=>'Admin'],['NO_INDUK'=>'003','NAMA'=>'Duplicate A','KELAS'=>'1'],['NO_INDUK'=>'003','NAMA'=>'Duplicate B','KELAS'=>'1']];
try{
 legacy_stage($koneksi,$job,$rows);$counts=legacy_preview($job['id'])['counts'];li_assert($counts===['accepted'=>2,'held'=>4,'non_student'=>1],'Every source row must have an explained classification');
 $koneksi->query("CREATE TRIGGER audit_legacy_atomic_fail BEFORE INSERT ON siswa_data FOR EACH ROW BEGIN IF NEW.NO_INDUK='$second' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test rollback'; END IF; END");
 $failed=false;try{legacy_apply($koneksi,legacy_job($job['id']));}catch(mysqli_sql_exception $e){$failed=true;}
 li_assert($failed,'Injected second row failure not reached');li_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa WHERE NO_INDUK IN ('$base','$second')")->fetch_row()[0]===0,'Partial students survived rollback');
 li_assert(legacy_job($job['id'])['state']==='ready','Failure falsely reported success');$koneksi->query('DROP TRIGGER audit_legacy_atomic_fail');
 $parallel=legacy_enqueue(legacy_root().'/'.$job['id'].'/source.dat','same-source.dat',1,$actor);legacy_update($parallel['id'],['state'=>'processing']);legacy_stage($koneksi,$parallel,$rows);
 legacy_apply($koneksi,legacy_job($job['id']));legacy_apply($koneksi,legacy_job($parallel['id']));legacy_apply($koneksi,legacy_job($job['id']));
 li_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa WHERE NO_INDUK IN ('$base','$second') AND legacy_pending=1 AND is_active=0 AND KELAS='LEGACY' AND master_kelas_id IS NULL")->fetch_row()[0]===2,'Apply/recovery duplicated or activated identities');
 $fake=legacy_job($job['id']);$fake['hash']=str_repeat('f',64);$failed=false;try{legacy_apply($koneksi,$fake);}catch(RuntimeException $e){$failed=true;}li_assert($failed,'Different backup overwrote identity');
 $fake['actor']=0;$failed=false;try{legacy_apply($koneksi,$fake);}catch(RuntimeException $e){$failed=true;}li_assert($failed,'Revoked/missing actor applied data');
 foreach($before as $table=>$count)li_assert($koneksi->query('SELECT COUNT(*) FROM '.$table)->fetch_row()[0]===$count,'Identity import changed '.$table);
 $bad=false;try{legacy_enqueue(__FILE__,'test.sql',1,$actor);}catch(RuntimeException $e){$bad=true;}li_assert($bad,'Raw SQL accepted');
 echo "PASS: staging taxonomy, original values, rollback at row two, crash replay idempotency, backup collision, actor revocation, filename containment and unchanged operational tables\n";
}finally{
 $koneksi->query('DROP TRIGGER IF EXISTS audit_legacy_atomic_fail');
 $koneksi->query("DELETE FROM legacy_student_import WHERE source_hash='".$job['hash']."'");$koneksi->query("DELETE FROM siswa WHERE NO_INDUK IN ('$base','$second') AND legacy_pending=1");
}
