<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||getenv('SPP_DB_NAME')!=='db_spp_audit_legacy_backend_20261003')exit(1);
ob_start();require __DIR__.'/../koneksi.php';require __DIR__.'/../includes/legacy_import.php';require __DIR__.'/http_form_scope.php';
$base=getenv('SPP_TEST_BASE_URL');spp_test_assert_http_clone($base,DB_NAME);
function ax_request($sid,$post=null){global $base;$opts=['header'=>'Cookie: PHPSESSID='.$sid,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>30];if($post){$opts['method']='POST';$opts['header'].="\r\nContent-Type: application/x-www-form-urlencoded";$opts['content']=http_build_query($post);}$body=file_get_contents($base.'/legacy_import_api.php?action=health',false,stream_context_create(['http'=>$opts]));preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$m);return [(int)$m[1],$body];}
function ax_session($id,$role,$unit){$sid='legacyaccess'.bin2hex(random_bytes(8));session_id($sid);session_start();$_SESSION=['admin_id'=>$id,'admin_role'=>$role,'active_unit_id'=>$unit,'csrf_legacy_import'=>str_repeat('a',64)];session_write_close();return $sid;}
function ax_assert($x,$message){if(!$x)throw new RuntimeException($message);}
$admin=$koneksi->query("SELECT id FROM admin WHERE role='admin' AND is_active=1 LIMIT 1")->fetch_row()[0];ax_assert(ax_request(ax_session($admin,'super_admin',1))[0]===403,'Forged/stale role accessed importer');
$username='legacytest'.bin2hex(random_bytes(6));$hash=password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT);$s=$koneksi->prepare("INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,'Owned importer access fixture','super_admin',1,1)");$s->bind_param('ss',$username,$hash);$s->execute();$id=(int)$koneksi->insert_id;$sid=ax_session($id,'super_admin',1);
try{
 ax_assert(ax_request($sid)[0]===200,'Fresh Super Admin access');$koneksi->query("UPDATE admin SET is_active=0 WHERE id=$id");ax_assert(ax_request($sid)[0]===403,'Inactive account retained access');
 $koneksi->query("UPDATE admin SET is_active=1,role='admin' WHERE id=$id");ax_assert(ax_request(ax_session($id,'super_admin',1))[0]===403,'Downgraded role retained access');
 $super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];$all=ax_session($super,'super_admin',0);ax_assert(ax_request($all,['action'=>'confirm','csrf_token'=>str_repeat('a',64),'id'=>str_repeat('a',32)])[0]===409,'All-unit write accepted');
 echo "PASS: fresh role lookup, forged role, disabled/downgraded account and All-unit importer write rejection\n";
}finally{$koneksi->query("DELETE FROM admin WHERE id=$id AND username='$username'");}
