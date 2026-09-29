<?php
session_start();
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/report_letters.php';
require_once __DIR__.'/../includes/pdf.php';
requireRole(['admin','bendahara','kasir']);

$mode=(string)($_GET['mode']??'single');
if(!in_array($mode,['single','selected','class','all'],true)){http_response_code(400);exit('Pilihan cetak tidak dikenal.');}
$filters=report_filters($koneksi,$_GET);
$filters['status']='';
$filters['q']=$mode==='class'?$filters['q']:'';
$filters['kelas']=$mode==='class'?$filters['kelas']:'';
if($mode==='class'&&$filters['kelas']===''){http_response_code(400);exit('Pilih kelas atau rombel terlebih dahulu.');}
$today=report_letter_today();
$students=report_student_debt_groups($koneksi,$filters,'',[],$today);

if(in_array($mode,['single','selected'],true)){
    $raw=mb_substr((string)($_GET['nis']??''),0,10000);
    $requested=array_filter(array_map('trim',explode(',',$raw)),static fn($nis)=>$nis!=='');
    if($mode==='single')$requested=array_slice($requested,0,1);
    $lookup=array_fill_keys(array_slice($requested,0,1000),true);
    $students=array_values(array_filter($students,static fn($student)=>isset($lookup[$student['nis']])));
}
if(!$students){http_response_code(404);header('Content-Type: text/html; charset=utf-8');exit('<!doctype html><html lang="id"><meta charset="utf-8"><title>Surat tidak tersedia</title><body><p>Tidak ada tunggakan yang masih terbuka untuk pilihan ini. Perbarui daftar surat lalu coba lagi.</p></body></html>');}

$scope=match($mode){
    'single'=>'siswa-'.$students[0]['nis'],
    'selected'=>'dipilih-'.count($students).'-siswa',
    'class'=>str_starts_with($filters['kelas'],'rombel:')?'rombel-'.preg_replace('/[^a-z0-9]/i','',strtolower($students[0]['kelas'])):'kelas-'.preg_replace('/[^0-9]/','',$filters['kelas']),
    default=>'semua-rombel',
};
$safeName=preg_replace('/[^a-z0-9_-]+/i','-',strtolower('surat-tunggakan-orang-tua-'.$scope.'-'.str_replace('-','',$today)));
require_pdf_library();
// Satu PDF massal dapat berisi ratusan lembar; naikkan batas hanya untuk permintaan ini.
$memoryLimit=trim((string)ini_get('memory_limit'));
if(count($students)>50&&$memoryLimit!=='-1'){
    $unit=strtoupper(substr($memoryLimit,-1));
    $factor=match($unit){'G'=>1073741824,'M'=>1048576,'K'=>1024,default=>1};
    if((int)$memoryLimit*$factor<384*1048576)ini_set('memory_limit','384M');
}
$options=new \Dompdf\Options();
$options->set('isRemoteEnabled',false);
$options->set('isHtml5ParserEnabled',true);
$options->setChroot(realpath(__DIR__.'/..'));
$pdf=new \Dompdf\Dompdf($options);
$pdf->loadHtml(report_parent_letters_html($students,$today),'UTF-8');
$pdf->setPaper('A4','portrait');
$pdf->render();
header('Cache-Control: no-store, private');
$pdf->stream($safeName.'.pdf',['Attachment'=>false]);
