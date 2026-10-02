<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require __DIR__.'/../koneksi.php';require __DIR__.'/../includes/reports.php';
function all_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$source=['tanggal_awal'=>'2020-01-01','tanggal_akhir'=>'2030-12-31','tahun_ajaran'=>'2026/2027','bulan_awal'=>'07','bulan_akhir'=>'06','tahun_awal'=>2026,'tahun_akhir'=>2027,'siswa_status'=>'all','kategori'=>'semua'];
foreach(array_keys(report_registry()) as $template){
    $input=$source+['template'=>$template];if(in_array($template,['status','per-item'],true))$input['kategori']='spp';
    $expected=[];$count=0;
    foreach([1,2,3] as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);$f=report_filters($koneksi,$input);$r=report_build($koneksi,$template,$f);$count+=count($r['rows']);
        foreach(report_money_totals($r,$template) as $t){$key=$t['key']??$t['label'];$expected[$key]=($expected[$key]??0)+(float)$t['value'];}}
    $_SESSION['active_unit_id']=0;unit_set_context($koneksi,0);$f=report_filters($koneksi,$input);$r=report_build($koneksi,$template,$f);
    $actual=[];foreach(report_money_totals($r,$template) as $t)$actual[$t['key']??$t['label']]=(float)$t['value'];
    foreach($expected as $key=>$sum)all_assert(abs($sum-($actual[$key]??0))<.001,"$template: total $key mismatch ($sum)");
    if(!in_array($template,['setoran','kas-tabungan'],true))all_assert(count($r['rows'])===$count,"$template: missing or duplicate rows");
    foreach($r['rows'] as $row)if(isset($row['nis'])||isset($row['master_kelas_id']))all_assert(in_array($row['unit_id']??0,[1,2,3],true),"$template: missing unit identity");
    echo "OK: $template combined rows and money totals\n";
}
$psb=report_principal_debt_rows([['unit_id'=>1,'kelas'=>'PSB','total_tunggakan'=>100],['unit_id'=>2,'kelas'=>'PSB','total_tunggakan'=>200],['unit_id'=>3,'kelas'=>'PSB','total_tunggakan'=>300]]);
all_assert(count($psb)===3,'PSB fallback groups crossed units');
$classes=class_all($koneksi);foreach($classes as $c)all_assert(str_starts_with($c['label'],unit_label((int)$c['unit_id']).' · '),'Class selector missing unit');
$years=report_years($koneksi);all_assert(count($years)===count(array_unique(array_column($years,'label'))),'Duplicate year labels');
echo "OK: PSB identities, rombel selectors and unique academic-year options\n";
