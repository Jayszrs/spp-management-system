<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/report_letters.php';
require_once __DIR__.'/../includes/pdf.php';

function letter_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

try{
    $stressInfo='';
    $today=report_letter_today();
    $filters=report_filters($koneksi,['siswa_status'=>'active']);
    $students=report_student_debt_groups($koneksi,$filters,'',[],$today);
    $principal=report_student_debt_data($koneksi,$filters)['rows'];
    letter_assert(count($students)===count($principal),'Jumlah surat orang tua tidak sama dengan rekap kepala sekolah.');
    $parentTotal=array_sum(array_column($students,'total_tunggakan'));
    $principalTotal=array_sum(array_column($principal,'total_tunggakan'));
    letter_assert(abs($parentTotal-$principalTotal)<.001,'Total kedua surat tidak sama.');
    foreach($students as $student){
        $detailTotal=0.0;
        foreach($student['items'] as $item){
            letter_assert((float)$item['sisa']>.001,'Surat memuat tagihan lunas atau nol.');
            letter_assert(!in_array($item['status'],['Dibatalkan','Potongan Penuh','Tercakup Uang PSB'],true),'Surat memuat tagihan yang dikecualikan.');
            if(in_array($item['komponen_key'],['spp','komite'],true))letter_assert($item['periode_code']<=substr($today,0,7),'Surat memuat bulan mendatang.');
            $itemAcademicStart=report_academic_year_start((string)($item['tahun_ajaran']??''));
            $currentAcademicStart=report_academic_year_start(du_academic_year_label((int)substr($today,5,2),(int)substr($today,0,4)));
            letter_assert($itemAcademicStart===null||$itemAcademicStart<=$currentAcademicStart,'Surat memuat tagihan tahun ajaran mendatang.');
            $detailTotal+=(float)$item['sisa'];
        }
        letter_assert(abs($detailTotal-$student['total_tunggakan'])<.001,'Jumlah rincian surat tidak cocok.');
    }
    $parentPdfPages=0;
    if($students){
        $first=$students[0];
        $classFilters=$filters;$classFilters['kelas']='rombel:'.$first['master_kelas_id'];
        foreach(report_student_debt_groups($koneksi,$classFilters,'',[],$today) as $student)letter_assert($student['master_kelas_id']===$first['master_kelas_id'],'Filter rombel salah.');
        $sampleSize=getenv('SPP_LETTER_FULL_PDF')==='1'?count($students):min(12,count($students));
        $html=report_parent_letters_html(array_slice($students,0,$sampleSize),$today);
        letter_assert(substr_count($html,'class="letter"')===$sampleSize,'Jumlah lembar surat salah.');
        $autoload=__DIR__.'/../vendor/autoload.php';
        if(is_file($autoload)){
            require_once $autoload;
            $options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->setChroot(realpath(__DIR__.'/..'));
            $pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();
            $parentPdfPages=$pdf->getCanvas()->get_page_count();
            letter_assert($parentPdfPages>=$sampleSize,'PDF massal tidak memisahkan lembar siswa.');
            if(($previewPath=getenv('SPP_LETTER_PDF_PREVIEW'))!==false&&$previewPath!=='')file_put_contents($previewPath,$pdf->output());
        }else{
            fwrite(STDERR,"INFO: Dompdf lokal belum terpasang; uji rendering PDF dilewati.\n");
        }
    }
    if($students&&getenv('SPP_LETTER_STRESS')==='1'&&is_file(__DIR__.'/../vendor/autoload.php')){
        $many=[];
        for($index=1;$index<=150;$index++){
            $student=$students[0];$student['nis']='STRESS'.str_pad((string)$index,4,'0',STR_PAD_LEFT);
            $student['nama']='Siswa Uji '.str_pad((string)$index,3,'0',STR_PAD_LEFT);
            $many[]=$student;
        }
        $pdf=new \Dompdf\Dompdf(new \Dompdf\Options());
        $pdf->loadHtml(report_parent_letters_html($many,$today),'UTF-8');
        $pdf->setPaper('A4','portrait');$pdf->render();
        $stressPages=$pdf->getCanvas()->get_page_count();
        letter_assert($stressPages===150,'PDF 150 siswa harus terpisah tepat 150 lembar, hasil: '.$stressPages.'.');
        $principalPdf=new \Dompdf\Dompdf(new \Dompdf\Options());
        $principalPdf->loadHtml(report_principal_letter_html($many,$today),'UTF-8');
        $principalPdf->setPaper('A4','portrait');$principalPdf->render();
        letter_assert($principalPdf->getCanvas()->get_page_count()>=2,'Rekap kepala sekolah 150 siswa tidak terbagi halaman.');
        $stressInfo='INFO: PDF 150 siswa menjadi 150 lembar; '.round(memory_get_peak_usage(true)/1048576).' MB puncak memori.'.PHP_EOL;
    }
    $principalHtml=report_principal_letter_html($principal,$today);
    letter_assert(str_contains($principalHtml,report_money($principalTotal)),'Total surat kepala sekolah tidak cocok.');
    if(is_file(__DIR__.'/../vendor/autoload.php')){
        $pdf=new \Dompdf\Dompdf(new \Dompdf\Options());
        $pdf->loadHtml($principalHtml,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();
        letter_assert($pdf->getCanvas()->get_page_count()>=1,'PDF kepala sekolah kosong.');
    }
    session_id('letter-test-'.bin2hex(random_bytes(6)));
    session_start();
    $_SESSION=['admin_id'=>-1,'admin_role'=>'kasir','admin_nama'=>'Uji Kasir'];
    session_write_close();
    $_SERVER['PHP_SELF']='/laporan/surat_orang_tua.php';
    $_GET=['siswa_status'=>'active'];
    ob_start();
    include __DIR__.'/../laporan/surat_orang_tua.php';
    $page=ob_get_clean();
    if(($htmlPreviewPath=getenv('SPP_LETTER_HTML_PREVIEW'))!==false&&$htmlPreviewPath!==''){
        if(getenv('SPP_LETTER_DARK_PREVIEW')==='1')$page=str_replace('</body>','<script>localStorage.setItem("spp_theme","dark");document.documentElement.setAttribute("data-theme","dark");</script></body>',$page);
        file_put_contents($htmlPreviewPath,str_replace('<head>','<head><base href="http://localhost/spp-management-system/laporan/">',$page));
    }
    letter_assert(str_contains($page,'Cetak Dipilih (0)')&&str_contains($page,'Cetak Semua Rombel'),'Halaman kasir tidak memuat aksi cetak.');
    letter_assert(str_contains($page,'href="../laporan/surat_laporan.php"')&&!str_contains($page,'sidebar-letter-group'),'Sidebar Surat Laporan harus menuju katalog, bukan submenu.');
    letter_assert(str_contains($page,'Kembali ke pilihan surat')&&str_contains($page,'letter-select-all'),'Navigasi kembali atau tabel surat yang rapi tidak tersedia.');
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();
    $_SESSION=[];session_destroy();session_write_close();
    echo $stressInfo.'OK: '.count($students).' surat aktif, total '.report_money($parentTotal).", batas bulan dan isi surat valid; PDF sampel {$parentPdfPages} halaman.\n";
}catch(Throwable $error){fwrite(STDERR,'FAILED: '.$error->getMessage().PHP_EOL);exit(1);}
