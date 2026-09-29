<?php
session_start();
require_once '../koneksi.php'; require_once '../includes/auth.php'; require_once '../includes/reports.php';
requireRole(['admin','bendahara','kasir']);
$registry=report_registry();$template=(string)($_GET['template']??'');if(!isset($registry[$template])){http_response_code(404);exit('Template tidak ditemukan.');}
$format=(string)($_GET['format']??'preview');if(!in_array($format,['preview','print','pdf','excel'],true))$format='preview';
$excelDownload=$format==='excel'&&($_GET['download']??'')==='1';
if($format==='pdf'){require_once __DIR__.'/../includes/pdf.php';require_pdf_library();}
$filters=report_filters($koneksi,$_GET);if(in_array($template,['riwayat-tagihan','tunggakan-siswa'],true)&&!isset($_GET['siswa_status']))$filters['siswa_status']='all';if(!isset($_GET['kategori'])&&$template==='penerimaan')$filters['kategori']='semua';
$report=report_build($koneksi,$template,$filters);$generated=date('d-m-Y H:i:s');$operator=(string)($_SESSION['admin_nama']??$_SESSION['admin_username']??'Pengguna');
$isCashRecap=in_array($template,['setoran','kas-tabungan','titipan-spp'],true);$isSavingsCashRecap=$template==='kas-tabungan';
$billingGroups=$template==='riwayat-tagihan'?report_billing_history_group_students($report['rows']):[];
$billingColumns=$template==='riwayat-tagihan'?report_billing_history_component_columns($billingGroups):[];
$billingColumnWidth=$billingColumns?57/count($billingColumns):0;
$moneyTotals=report_money_totals($report,$template);if($template==='riwayat-tagihan')$moneyTotals=array_values(array_filter($moneyTotals,static fn($total)=>($total['key']??'')==='tagihan'));
function export_cell($value,string $type,array $row=[],string $key=''):string{if($type==='money')return report_e(report_money($value));if($type==='money_optional')return $value===null||$value===''?'-':report_e(report_money($value));if($type==='html'&&is_array($value))return report_e(($value['text']??'').(($value['sub']??'')!==''?' · '.$value['sub']:''));if($type==='nis'||$key==='nis'){$diknas=$row['diknas']??$row['nis_diknas']??$row['NO_induk_diknas']??'';return report_e($value).($diknas!==''?'<br><small>Diknas '.report_e($diknas).'</small>':'');}return report_e($value);}
$logoPath=realpath(__DIR__.'/../assets/img/school-logo.png');
// Excel HTML (.xls) tidak stabil untuk image/base64, jadi logo gambar hanya
// dirender untuk print/PDF. Excel memakai kop teks agar tidak muncul broken logo.
$canRenderLogo=$format!=='excel'&&($format!=='pdf'||extension_loaded('gd'));
$logoData=$logoPath&&$canRenderLogo?'data:image/png;base64,'.base64_encode((string)file_get_contents($logoPath)):'';
ob_start(); ?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title><?= report_e($report['title']) ?></title><style>
@page{margin:12mm;size:<?= $registry[$template]['orientation']==='landscape'?'A4 landscape':'A4 portrait' ?>}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#17231d;font-size:9px;margin:0}.toolbar{padding:10px;background:#eef7f2;margin-bottom:12px}.toolbar button{padding:8px 14px;border:0;background:#108952;color:#fff;border-radius:6px;cursor:pointer}.kop{width:100%;border-bottom:3px double #15543c;padding-bottom:8px;margin-bottom:12px}.kop td{border:0}.kop img{width:58px;height:58px;object-fit:contain}.kop-logo-text{width:58px;height:58px;border:1px solid #15543c;color:#15543c;font-weight:bold;font-size:12px;text-align:center;line-height:58px}.kop h1{font-size:16px;margin:0;text-align:center}.kop p{text-align:center;margin:3px 0}.title{text-align:center;margin:10px 0 12px}.title h2{font-size:14px;margin:0 0 3px}.meta{width:100%;margin-bottom:8px}.meta td{border:0;padding:2px}table.data{border-collapse:collapse;width:100%}.data th,.data td{border:1px solid #9bb9aa;padding:4px;vertical-align:top}.data th{background:#12503a;color:white;text-transform:uppercase;font-size:8px}.data tr:nth-child(even){background:#f4f8f6}.money{text-align:right;white-space:nowrap}.total-table{margin-top:10px;max-width:420px;margin-left:auto}.total-table caption{text-align:left;font-weight:bold;margin-bottom:4px}.footer{position:fixed;bottom:-7mm;left:0;right:0;border-top:1px solid #aaa;padding-top:3px;color:#666;font-size:7px}.footer:after{content:" · Halaman " counter(page)}.signatures{width:100%;margin-top:24px}.signatures td{border:0;text-align:center;width:50%;height:70px;vertical-align:top}.negative{color:#b42318;font-weight:bold}.billing-group-row{page-break-inside:avoid}.billing-student strong{display:block;font-size:10px;margin-bottom:3px}.billing-student small,.billing-class{color:#52645b}.billing-summary div{margin-bottom:3px;white-space:nowrap}.billing-summary b{display:inline-block;min-width:54px}.billing-detail-item{padding:3px 0;border-bottom:1px solid #d8e5de;line-height:1.35}.billing-detail-item:last-child{border-bottom:0}.billing-detail-item b{display:inline-block;min-width:90px}.billing-detail-meta{color:#52645b}.billing-detail-money{white-space:nowrap}@media print{.toolbar{display:none}}
thead{display:table-header-group}tfoot{display:table-row-group}tr{page-break-inside:avoid}.title{padding:8px 10px;border:1px solid #d4e6dc;background:#f4faf7}.title div{color:#52645b;line-height:1.45}.meta{color:#52645b}.data tbody tr:nth-child(odd){background:#fff}.data tbody tr:nth-child(even){background:#f5faf7}.data tfoot th,.data tfoot td{background:#e7f4ed;color:#0c7042;font-weight:bold}.total-table{border-collapse:collapse}.total-table caption{padding:5px 0;color:#173b2d;font-size:9px}.signatures{page-break-inside:avoid}.footer{white-space:nowrap}@media screen{body{padding:28px;background:#fff}.footer{position:static;margin-top:18px}.billing-pdf-student+.billing-pdf-student{page-break-before:auto}}
<?php if($template==='riwayat-tagihan'): ?>
@page{margin:10mm 10mm 14mm;size:A3 landscape}
.billing-export-note{margin:7px 0 10px;padding:6px 8px;border:1px solid #c9e0d4;background:#f1f8f4;color:#52645b;font-size:8px}
.billing-export-overview{width:100%;margin:8px 0;border-collapse:separate;border-spacing:5px 0}
.billing-export-overview td{width:100%;padding:7px 9px;border:1px solid #c9e0d4;background:#f1f8f4}
.billing-export-overview span{display:block;color:#607269;font-size:7px;font-weight:bold;text-transform:uppercase}
.billing-export-overview strong{display:block;margin-top:3px;color:#12503a;font-size:11px}
.billing-export-table{table-layout:fixed}
.billing-export-table th,.billing-export-table td{padding:4px 4px;font-size:7.3px;line-height:1.25;overflow-wrap:anywhere}
.billing-export-table th{font-size:7px;vertical-align:middle}
.billing-export-table .billing-number{width:3%;text-align:center}
.billing-export-table .billing-nis{width:9%}
.billing-export-table .billing-name{width:14%}
.billing-export-table .billing-name strong{display:block;font-size:8px}
.billing-export-table .billing-nis small{display:block;margin-top:2px;color:#52645b;font-size:6.6px}
.billing-export-table .billing-class{width:5%}
.billing-export-table .billing-total{width:12%;background:#e9f5ed}
.billing-export-table thead .billing-total{background:#12503a;color:#fff}
.billing-export-table .billing-component{vertical-align:middle}
.billing-export-amount{display:block;font-size:9px;white-space:nowrap;text-align:right}
.billing-export-empty{color:#879b8f;text-align:center}
.billing-export-table tbody tr{page-break-inside:avoid}
<?php endif; ?>
</style></head><body>
<table class="kop"><tr><td style="width:70px"><?php if($logoData): ?><img src="<?= $logoData ?>" alt="Logo sekolah"><?php elseif($format==='excel'): ?><div class="kop-logo-text">SD MH</div><?php endif; ?></td><td><h1>SEKOLAH DASAR AL-QUR'AN (SDA) MUTIARA HIKMAH</h1><p>Perum Bekasi Griya Asri II, Tambun Selatan · Telp. 021-88363466</p></td><td style="width:70px"></td></tr></table>
<div class="title"><h2><?= report_e(strtoupper($report['title'])) ?></h2><div><?= report_e($report['subtitle']) ?></div></div><table class="meta"><tr><td>Dibuat: <?= report_e($generated) ?></td><td style="text-align:right">Petugas: <?= report_e($operator) ?></td></tr></table>
<?php if($template==='riwayat-tagihan'&&$moneyTotals): ?><table class="billing-export-overview"><tr><?php foreach($moneyTotals as $total): ?><td><span><?= report_e($total['label']) ?></span><strong><?= report_money($total['value']) ?></strong></td><?php endforeach; ?></tr></table><?php endif; ?>
<?php if($isCashRecap):
  $componentRows=$report['component_rows']??($report['component_summary']??[]);
  $componentTitle=$isSavingsCashRecap?'Arus Tabungan':'Komponen Pembayaran';
  $componentTotalLabel=$isSavingsCashRecap?'Mutasi Bersih':'Total Pembayaran';
  $summaryTitle=$isSavingsCashRecap?'Jumlah Transaksi':'Metode Pembayaran';
  $summaryItems=$isSavingsCashRecap
    ? array_map(static fn($item)=>['label'=>$item['label'],'value'=>$item['value'],'type'=>'count'],$report['transaction_summary']??[])
    : array_map(static fn($item)=>['label'=>$item['metode'],'value'=>$item['nominal'],'type'=>'money'],$report['method_summary']??[]);
  $totalLabel=$isSavingsCashRecap?'MUTASI BERSIH':'TOTAL SETORAN';
  $totalValue=$isSavingsCashRecap?(float)($report['mutasi_bersih']??0):(float)($report['total_setoran']??$report['component_total']??0);
?><h3><?= report_e($componentTitle) ?></h3><table class="data"><thead><tr><th>No</th><th><?= report_e($componentTitle) ?></th><th>Nominal</th></tr></thead><tbody><?php if(!$componentRows): ?><tr><td colspan="3" style="text-align:center">Tidak ada transaksi pada filter terpilih.</td></tr><?php else: foreach($componentRows as $index=>$component): ?><tr><td><?= $index+1 ?></td><td><?= report_e($component['komponen']) ?></td><td class="money <?= (float)$component['nominal']<0?'negative':'' ?>"><?= report_money($component['nominal']) ?></td></tr><?php endforeach; endif; ?></tbody><tfoot><tr><th colspan="2"><?= report_e($componentTotalLabel) ?></th><th class="money <?= (float)($report['component_total']??0)<0?'negative':'' ?>"><?= report_money($report['component_total']??0) ?></th></tr></tfoot></table><h3><?= report_e($summaryTitle) ?></h3><table class="data"><thead><tr><th>Ringkasan</th><th>Nilai</th></tr></thead><tbody><?php foreach($summaryItems as $item): ?><tr><td><?= report_e($item['label']) ?></td><td class="money"><?= $item['type']==='money'?report_money($item['value']):number_format((int)$item['value']) ?></td></tr><?php endforeach; ?></tbody></table><table class="data total-table"><caption><?= report_e($totalLabel) ?></caption><tbody><tr><th><?= report_e(ucwords(strtolower($totalLabel))) ?></th><td class="money <?= $totalValue<0?'negative':'' ?>"><?= report_money($totalValue) ?></td></tr></tbody></table><?php endif; ?>
<?php if(!$isCashRecap): ?>
<?php if($template==='riwayat-tagihan'): ?>
<div class="billing-export-note">Tanggal yang dipilih adalah tanggal tagihan dibuat. Untuk Uang Pangkal dan Uang PSB, tanggal mengikuti tanggal data siswa dibuat.</div>
<table class="data billing-export-table">
<thead><tr>
  <th class="billing-number">No</th><th class="billing-nis">NIS</th><th class="billing-name">Nama Siswa</th><th class="billing-class">Kelas</th>
  <?php foreach($billingColumns as $column): ?><th class="billing-component" style="width:<?= $billingColumnWidth ?>%"><?= report_e($column['komponen']) ?></th><?php endforeach; ?>
  <th class="billing-total">Total Tagihan</th>
</tr></thead>
<tbody>
<?php if(!$billingGroups): ?><tr><td colspan="<?= 5+count($billingColumns) ?>" style="text-align:center">Tidak ada data pada filter terpilih.</td></tr>
<?php else: foreach($billingGroups as $index=>$student): $componentMap=array_column($student['components'],null,'komponen_key'); ?>
<tr>
  <td class="billing-number"><?= $index+1 ?></td>
  <td class="billing-nis"><?= report_e($student['nis']) ?><?php if($student['nis_diknas']!==''): ?><small>Diknas <?= report_e($student['nis_diknas']) ?></small><?php endif; ?></td>
  <td class="billing-name"><strong><?= report_e($student['nama']) ?></strong></td>
  <td class="billing-class"><?= report_e($student['kelas']) ?></td>
  <?php foreach($billingColumns as $column): $component=$componentMap[$column['komponen_key']]??null; ?>
  <td class="billing-component">
    <?php if($component): ?>
      <strong class="billing-export-amount"><?= report_money($component['tagihan']) ?></strong>
    <?php else: ?><div class="billing-export-empty">&mdash;</div><?php endif; ?>
  </td>
  <?php endforeach; ?>
  <td class="billing-total">
    <strong class="billing-export-amount"><?= report_money($student['total_tagihan']) ?></strong>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
<?php else: ?><table class="data"><thead><tr><th>No</th><?php foreach($report['columns'] as $column): ?><th><?= report_e($column[1]) ?></th><?php endforeach; ?></tr></thead><tbody><?php if(!$report['rows']): ?><tr><td colspan="<?= count($report['columns'])+1 ?>" style="text-align:center">Tidak ada data pada filter terpilih.</td></tr><?php else: foreach($report['rows'] as $index=>$row): ?><tr><td><?= $index+1 ?></td><?php foreach($report['columns'] as $column): $type=$column[2]??'text';$key=$column[0];$value=$row[$key]??''; ?><td class="<?= in_array($type,['money','money_optional'],true)?'money':'' ?> <?= is_numeric($value)&&(float)$value<0?'negative':'' ?>"><?= export_cell($value,$type,$row,$key) ?></td><?php endforeach; ?></tr><?php endforeach; endif; ?></tbody></table><?php endif; ?>
<?php if($moneyTotals&&$template!=='riwayat-tagihan'): ?><table class="data total-table"><caption>Total Rupiah</caption><tbody><?php foreach($moneyTotals as $total): ?><tr><th><?= report_e($total['label']) ?></th><td class="money <?= (float)$total['value']<0?'negative':'' ?>"><?= report_money($total['value']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?><?php endif; ?>
<?php if($isCashRecap): ?><table class="signatures"><tr><td>Kasir/Petugas,<br><br><br><br>(________________________)</td><td>Bagian Keuangan,<br><br><br><br>(________________________)</td></tr></table><?php endif; ?><div class="footer">SistemSPP · Data laporan bersifat live dan mengikuti koreksi transaksi sampai saat laporan dibuat.</div></body></html>
<?php $html=ob_get_clean();
$safeName=preg_replace('/[^a-z0-9_-]+/i','-',strtolower($template)).'-'.date('Ymd-His');
if(in_array($format,['preview','print'],true)){
    require_once __DIR__.'/../includes/report_preview.php';
    $downloadQuery=$_GET;$downloadQuery['format']='pdf';unset($downloadQuery['preview_action']);
    $backQuery=$_GET;unset($backQuery['format'],$backQuery['preview_action']);
    render_report_pdf_preview($html,[
        'title'=>$report['title'],
        'subtitle'=>$report['subtitle'],
        'generated'=>$generated,
        'row_count'=>$template==='riwayat-tagihan'?count($billingGroups):(int)($report['total']??count($report['rows']??[])),
        'orientation'=>$registry[$template]['orientation'],
        'download_url'=>'export_global.php?'.http_build_query($downloadQuery),
        'back_url'=>'template.php?'.http_build_query(array_merge(['template'=>$template],$backQuery)),
        'auto_print'=>$format==='print'||($_GET['preview_action']??'')==='print',
    ]);
}
if($format==='excel'&&!$excelDownload){
    require_once __DIR__.'/../includes/report_preview.php';
    $downloadQuery=$_GET;$downloadQuery['format']='excel';$downloadQuery['download']='1';
    $backQuery=$_GET;unset($backQuery['format'],$backQuery['download'],$backQuery['preview_action']);
    render_report_export_preview($html,[
        'file_type'=>'EXCEL',
        'show_print'=>false,
        'title'=>$report['title'],
        'subtitle'=>$report['subtitle'],
        'generated'=>$generated,
        'row_count'=>$template==='riwayat-tagihan'?count($billingGroups):(int)($report['total']??count($report['rows']??[])),
        'orientation'=>$registry[$template]['orientation'],
        'download_url'=>'export_global.php?'.http_build_query($downloadQuery),
        'back_url'=>'template.php?'.http_build_query(array_merge(['template'=>$template],$backQuery)),
    ]);
}
if($format==='excel'&&$excelDownload){header('Content-Type: application/vnd.ms-excel; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$safeName.'.xls"');echo "\xEF\xBB\xBF".$html;exit;}
if($format==='pdf'){$options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isHtml5ParserEnabled',true);$options->setDefaultMediaType('print');$options->setChroot(realpath(__DIR__.'/..'));$dompdf=new \Dompdf\Dompdf($options);$dompdf->loadHtml($html,'UTF-8');$dompdf->setPaper($template==='riwayat-tagihan'?'A3':'A4',$registry[$template]['orientation']);$dompdf->render();$dompdf->stream($safeName.'.pdf',['Attachment'=>true]);exit;}
echo $html;
