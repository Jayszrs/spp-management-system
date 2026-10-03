<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/readiness_migration_guard.php';
require_once __DIR__.'/../includes/legacy_schema.php';
$apply=in_array('--apply',$argv,true);
echo 'PREFLIGHT database='.DB_NAME.' ready='.(int)legacy_schema_ready($koneksi).' mode='.($apply?'apply':'read-only')."\n";
if(!$apply)exit;
readiness_migration_assert_apply_allowed($argv,DB_NAME);
if(DB_NAME==='db_spp'&&!is_file(__DIR__.'/../tmp/financial_migration.lock'))throw new RuntimeException('Main maintenance required');
if(DB_NAME==='db_spp'){
    $storage=getenv('SPP_LEGACY_STORAGE')?:'C:/laragon/data/spp-legacy-import';
    $heartbeat=rtrim($storage,'/\\').'/db_spp/heartbeat.json';
    if(is_file($heartbeat)){$h=json_decode(file_get_contents($heartbeat),true);if(time()-($h['time']??0)<=15)throw new RuntimeException('Hentikan worker importer sebelum DDL database trial.');}
}
legacy_schema_apply($koneksi);
