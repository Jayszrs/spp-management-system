<?php
/** Retire audited, unused dummy deposits. Default is read-only inspection. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/readiness_migration_guard.php';
require_once __DIR__.'/../koneksi.php';

function retirement_table(mysqli $db, string $name): string {
    $row=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME IN ('$name','{$name}_data') ORDER BY TABLE_NAME DESC")->fetch_assoc();
    if(!$row) throw new RuntimeException('Tabel tidak tersedia: '.$name);
    return $row['TABLE_NAME'];
}
function retirement_hash(mysqli $db, string $table, string $where='1', array $omit=[]): string {
    $columns=$db->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC);
    $names=array_values(array_filter(array_column($columns,'Field'),static fn($name)=>!in_array($name,$omit,true)));
    $fields=implode(',',array_map(static fn($name)=>"`$name`",$names));
    $rows=$db->query("SELECT $fields FROM `$table` WHERE $where")->fetch_all(MYSQLI_ASSOC);
    $encoded=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$rows);
    sort($encoded,SORT_STRING);
    return hash('sha256',implode("\n",$encoded));
}
function retirement_column(mysqli $db,string $table,string $column): bool {
    return (int)$db->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$column'")->fetch_assoc()['c']>0;
}
function retirement_preflight(mysqli $db): array {
    $p=retirement_table($db,'bayar');$b=retirement_table($db,'spp_alokasi_batch');$a=retirement_table($db,'spp_alokasi');
    if(!retirement_column($db,$p,'U_TITIPAN_SPP')){
        $left=(int)$db->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('gunakan_titipan','titipan_digunakan','titipan_baru','nominal_dari_titipan')")->fetch_assoc()['c'];
        $ledger=(int)$db->query("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'titipan_spp_mutasi%'")->fetch_assoc()['c'];
        $enum=$db->query("SHOW COLUMNS FROM keuangan_request LIKE 'aksi'")->fetch_assoc()['Type'];
        if($left||$ledger||str_contains($enum,'titipan')) throw new RuntimeException('Skema parsial: pulihkan backup sebelum mencoba kembali.');
        return ['already_clean'=>true];
    }
    $m=retirement_table($db,'titipan_spp_mutasi');$o=retirement_table($db,'transaksi_otorisasi');
    $rows=$db->query("SELECT * FROM `$p` WHERE U_TITIPAN_SPP>0 ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    $ids=array_column($rows,'id');$list=implode(',',array_map('intval',$ids));
    if(count($ids)!==19||abs(array_sum(array_column($rows,'U_TITIPAN_SPP'))-2475000.0)>.001) throw new RuntimeException('Baseline titipan berubah: harus 19 header / Rp2.475.000.');
    foreach($rows as $row){
        foreach(['U_PANGKAL','U_PSB','U_SPP','U_KOMITE','U_LAIN','JUMLAH1','JUMLAH2','JUMLAH3','JUMLAH4'] as $field)
            if(abs((float)$row[$field])>.001) throw new RuntimeException('Transaksi campuran: '.$row['id']);
        if(abs((float)$row['total_jumlah']-(float)$row['U_TITIPAN_SPP'])>.001) throw new RuntimeException('Total header berbeda.');
    }
    $batches=$db->query("SELECT * FROM `$b` WHERE bayar_id IN ($list) ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    $batchIds=implode(',',array_map('intval',array_column($batches,'id')));
    if(count($batches)!==19||count(array_unique(array_column($batches,'bayar_id')))!==19) throw new RuntimeException('Relasi batch berubah.');
    foreach($batches as $row) if($row['status']!=='active'||(float)$row['titipan_digunakan']!=0||$row['gunakan_titipan']!=0||abs((float)$row['uang_baru']-(float)$row['titipan_baru'])>.001) throw new RuntimeException('Titipan pernah dipakai atau batch berubah.');
    $mutations=$db->query("SELECT * FROM `$m` ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    if(count($mutations)!==19||count(array_unique(array_column($mutations,'bayar_id')))!==19) throw new RuntimeException('Mutasi baru atau relasi baru.');
    foreach($mutations as $row) if($row['jenis']!=='masuk'||!in_array($row['bayar_id'],$ids)||!in_array((string)$row['batch_id'],array_map('strval',array_column($batches,'id')),true)) throw new RuntimeException('Relasi mutasi tidak dikenal.');
    if((int)$db->query("SELECT COUNT(*) c FROM `$a` WHERE nominal_dari_titipan<>0 OR batch_id IN ($batchIds)")->fetch_assoc()['c']) throw new RuntimeException('Ada penggunaan/alokasi pada titipan.');
    if((int)$db->query("SELECT COUNT(*) c FROM `$b` WHERE titipan_digunakan<>0 OR (titipan_baru<>0 AND bayar_id NOT IN ($list)) OR (titipan_baru<>0 AND bayar_id IS NULL)")->fetch_assoc()['c']) throw new RuntimeException('Batch titipan lain ditemukan.');
    if((int)$db->query("SELECT COUNT(*) c FROM `$o` WHERE status='pending' OR bayar_id IN ($list)")->fetch_assoc()['c']) throw new RuntimeException('Otorisasi perlu diperiksa sebelum pembersihan.');
    if((int)$db->query("SELECT COUNT(*) c FROM keuangan_request WHERE aksi='titipan_pengembalian'")->fetch_assoc()['c']) throw new RuntimeException('Pengembalian perlu dipetakan.');
    // Enumerate FK children instead of relying on cascade deletion.
    $children=$db->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN ('$p','$b','$m')")->fetch_all(MYSQLI_ASSOC);
    foreach($children as $child){
        $table=$child['TABLE_NAME'];$column=$child['COLUMN_NAME'];$parent=$child['REFERENCED_TABLE_NAME'];
        $known=['bayar_biaya_lain','bayar_du','bayar_komite','bayar_spp_periode','bayar_tahunan_siswa','spp_alokasi_batch','spp_alokasi','titipan_spp_mutasi','transaksi_m','transaksi_otorisasi'];
        if(!in_array(preg_replace('/_data$/','',$table),$known,true)||!in_array($column,['bayar_id','batch_id'],true)) throw new RuntimeException('Relasi baru belum dipetakan: '.$table.'.'.$column);
        if(($table===$m&&in_array($parent,[$p,$b],true))||($table===$b&&$parent===$p)) continue;
        $check=$parent===$p?$list:($parent===$b?$batchIds:implode(',',array_map('intval',array_column($mutations,'id'))));
        if((int)$db->query("SELECT COUNT(*) c FROM `$table` WHERE `$column` IN ($check)")->fetch_assoc()['c']) throw new RuntimeException('Relasi perlu dipetakan: '.$table.'.'.$column);
    }
    $fingerprint=hash('sha256',retirement_hash($db,$p,"id IN ($list)").retirement_hash($db,$b,"id IN ($batchIds)").retirement_hash($db,$m));
    return ['already_clean'=>false,'payments'=>$list,'batches'=>$batchIds,'tables'=>[$p,$b,$a,$m],'fingerprint'=>$fingerprint];
}
$dataCommitted=false;
try {
    $apply=in_array('--apply',$argv,true);
    if($apply) readiness_migration_assert_apply_allowed($argv,DB_NAME);
    if($apply&&DB_NAME==='db_spp'&&trim((string)@file_get_contents(__DIR__.'/../tmp/financial_migration.lock'))!=='remove_spp_deposit_20261002') throw new RuntimeException('Maintenance milik migrasi belum aktif.');
    $koneksi->begin_transaction();
    $audit=retirement_preflight($koneksi);
    if($audit['already_clean']){ $koneksi->rollback();echo "OK: skema sudah bersih; tidak ada perubahan.\n";exit; }
    echo json_encode(['database'=>DB_NAME,'mode'=>$apply?'apply':'audit','headers'=>19,'amount'=>2475000,'fingerprint'=>$audit['fingerprint']],JSON_THROW_ON_ERROR)."\n";
    if(!$apply){$koneksi->rollback();exit;}
    $expected='c36160d6c11c70da832577d26cc918a7a7da97c6d217bd7abab3c6b470ad73f4';
    if(!hash_equals($expected,$audit['fingerprint'])) throw new RuntimeException('Fingerprint berbeda dari data yang diaudit.');
    [$p,$b,$a,$m]=$audit['tables'];$ids=$audit['payments'];
    $unchanged=[];
    $tables=$koneksi->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetch_all(MYSQLI_ASSOC);
    foreach($tables as $row){$table=$row['TABLE_NAME'];if(!in_array($table,[$p,$b,$a,$m,'keuangan_request'],true))$unchanged[$table]=retirement_hash($koneksi,$table);}
    $normalRequests=retirement_hash($koneksi,'keuangan_request',"NOT (aksi='pembayaran' AND referensi_id IN ($ids)) OR referensi_id IS NULL");
    $normalPayments=retirement_hash($koneksi,$p,"id NOT IN ($ids)",['U_TITIPAN_SPP']);
    $normalBatches=retirement_hash($koneksi,$b,"bayar_id NOT IN ($ids) OR bayar_id IS NULL",['gunakan_titipan','titipan_baru','titipan_digunakan']);
    $normalAllocations=retirement_hash($koneksi,$a,'1',['nominal_dari_titipan']);
    $koneksi->query("DELETE FROM `$m`");if($koneksi->affected_rows!==19)throw new RuntimeException('Jumlah mutasi berubah.');
    $koneksi->query("DELETE FROM `$b` WHERE bayar_id IN ($ids)");if($koneksi->affected_rows!==19)throw new RuntimeException('Jumlah batch berubah.');
    $koneksi->query("DELETE FROM keuangan_request WHERE aksi='pembayaran' AND referensi_id IN ($ids)");
    $koneksi->query("DELETE FROM `$p` WHERE id IN ($ids)");if($koneksi->affected_rows!==19)throw new RuntimeException('Jumlah pembayaran berubah.');
    $koneksi->commit();$dataCommitted=true;echo "DATA_COMMITTED: 19 header, batch, dan mutasi dihapus.\n";
    if(getenv('SPP_TEST_DEPOSIT_FAIL_DDL')==='1' && getenv('SPP_TEST_ALLOW_MUTATION')==='1'
        && preg_match('/^db_spp_audit_[a-z0-9_]+$/D',DB_NAME)) throw new RuntimeException('Injected clone-only DDL failure for restore regression.');
    // MySQL DDL commits implicitly. Keep maintenance active through all DDL and verification.
    if($m!=='titipan_spp_mutasi')$koneksi->query('DROP VIEW titipan_spp_mutasi');
    $koneksi->query("DROP TABLE `$m`");echo "DDL ledger removed\n";
    $koneksi->query("ALTER TABLE `$b` DROP CHECK chk_spp_alokasi_batch_nominal, DROP COLUMN gunakan_titipan, DROP COLUMN titipan_baru, DROP COLUMN titipan_digunakan, ADD CONSTRAINT chk_spp_alokasi_batch_nominal CHECK(uang_baru>=0)");
    $koneksi->query("ALTER TABLE `$a` DROP CHECK chk_spp_alokasi_nominal, DROP COLUMN nominal_dari_titipan, ADD CONSTRAINT chk_spp_alokasi_nominal CHECK(nominal_dari_bayar>0)");
    $koneksi->query("ALTER TABLE `$p` DROP COLUMN U_TITIPAN_SPP");
    foreach(['bayar'=>$p,'spp_alokasi_batch'=>$b,'spp_alokasi'=>$a] as $view=>$table)
        if($view!==$table)$koneksi->query("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `$view` AS SELECT * FROM `$table` WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
    $koneksi->query("ALTER TABLE keuangan_request MODIFY aksi ENUM('pembayaran','tabungan_masuk','tabungan_keluar') NOT NULL");echo "DDL complete\n";
    foreach($unchanged as $table=>$hash)if(!hash_equals($hash,retirement_hash($koneksi,$table)))throw new RuntimeException('Data lain berubah: '.$table);
    foreach([[$p,$normalPayments],[$b,$normalBatches],[$a,$normalAllocations]] as [$table,$hash])if(!hash_equals($hash,retirement_hash($koneksi,$table)))throw new RuntimeException('Pembayaran/alokasi biasa berubah: '.$table);
    if(!hash_equals($normalRequests,retirement_hash($koneksi,'keuangan_request')))throw new RuntimeException('Request lain berubah.');
    retirement_preflight($koneksi);
    echo json_encode(['status'=>'OK','unchanged_tables'=>$unchanged,'payments'=>$koneksi->query("SELECT COUNT(*) jumlah,SUM(total_jumlah) penerimaan FROM `$p`")->fetch_assoc()],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
} catch(Throwable $e){try{$koneksi->rollback();}catch(Throwable $ignored){} fwrite(STDERR,'STOP: '.$e->getMessage()."\n".($dataCommitted?'Pembersihan telah committed; pertahankan maintenance dan pulihkan backup teruji.':'Pembersihan dibatalkan sebelum commit; data tidak dihapus.')."\n");exit(1);}
