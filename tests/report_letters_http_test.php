<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/reports.php';

function letter_http_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function letter_http_get(string $url,string $cookie=''):array{
    $options=['http'=>['method'=>'GET','ignore_errors'=>true,'follow_location'=>0,'timeout'=>20,'header'=>$cookie!==''?'Cookie: '.session_name().'='.$cookie."\r\n":'']];
    $body=file_get_contents($url,false,stream_context_create($options));
    $headers=$http_response_header??[];
    return [$headers,$body===false?'':$body];
}
function letter_http_header(array $headers,string $name):string{
    foreach($headers as $header)if(stripos($header,$name.':')===0)return trim(substr($header,strlen($name)+1));
    return '';
}
function letter_http_end_session(string $id):void{session_id($id);session_start();$_SESSION=[];session_destroy();session_write_close();}

try{
    $base=rtrim((string)(getenv('SPP_HTTP_BASE')?:'http://localhost/spp-management-system'),'/').'/';
    $students=report_student_debt_groups($koneksi,report_filters($koneksi,['siswa_status'=>'active']),'',[],report_letter_today());
    letter_http_assert((bool)$students,'Butuh satu siswa dengan tunggakan untuk uji HTTP.');
    $debtorIds=array_fill_keys(array_column($students,'nis'),true);
    $paidOrNotDue=null;
    foreach($koneksi->query('SELECT NO_INDUK FROM siswa WHERE is_active=1') as $studentRow){
        if(!isset($debtorIds[$studentRow['NO_INDUK']])){$paidOrNotDue=$studentRow['NO_INDUK'];break;}
    }
    [$headers]=letter_http_get($base.'laporan/surat_laporan.php');
    letter_http_assert(str_contains($headers[0]??'','302'),'Katalog surat tanpa login tidak ditolak.');
    [$headers]=letter_http_get($base.'laporan/surat_orang_tua.php');
    letter_http_assert(str_contains($headers[0]??'','302'),'Halaman surat tanpa login tidak ditolak.');
    foreach(['admin','bendahara','kasir'] as $role){
        $id='letterhttp'.bin2hex(random_bytes(8));
        session_id($id);session_start();
        $_SESSION=['admin_id'=>-1,'admin_role'=>$role,'admin_nama'=>'Uji Surat'];
        session_write_close();
        [$headers,$body]=letter_http_get($base.'laporan/surat_laporan.php',$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_contains($body,'href="surat_orang_tua.php"')&&str_contains($body,'href="template.php?template=tunggakan-siswa"')&&str_contains($body,'href="../laporan/surat_laporan.php" class="nav-item active"'),"Katalog surat gagal untuk $role.");
        [$headers,$body]=letter_http_get($base.'laporan/surat_orang_tua.php',$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_contains($body,'Cetak Surat ke Orang Tua')&&str_contains($body,'Kembali ke pilihan surat')&&str_contains($body,'href="../laporan/surat_laporan.php" class="nav-item active"'),"Halaman surat gagal untuk $role.");
        [$headers,$body]=letter_http_get($base.'laporan/template.php?template=tunggakan-siswa',$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_contains($body,'Kembali ke pilihan surat')&&str_contains($body,'href="../laporan/surat_laporan.php" class="nav-item active"'),'URL rekap lama tidak mengarah ke Surat Laporan.');
        if($role!=='kasir'){letter_http_end_session($id);continue;}
        $single=$base.'laporan/surat_orang_tua_pdf.php?'.http_build_query(['mode'=>'single','nis'=>$students[0]['nis']]);
        [$headers,$body]=letter_http_get($single,$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_starts_with($body,'%PDF-'),'PDF orang tua gagal.');
        letter_http_assert(str_contains(strtolower(letter_http_header($headers,'Content-Type')),'application/pdf'),'Tipe PDF orang tua salah.');
        letter_http_assert(str_contains(strtolower(letter_http_header($headers,'Content-Disposition')),'inline'),'PDF orang tua tidak inline.');
        if(count($students)>1){
            $selectedIds=[$students[0]['nis'],$students[count($students)-1]['nis']];
            [$headers,$body]=letter_http_get($base.'laporan/surat_orang_tua_pdf.php?'.http_build_query(['mode'=>'selected','nis'=>implode(',',$selectedIds)]),$id);
            letter_http_assert(str_starts_with($body,'%PDF-')&&str_contains(letter_http_header($headers,'Content-Disposition'),'dipilih-2-siswa'),'PDF pilihan lintas halaman gagal.');
        }
        [$headers,$body]=letter_http_get($base.'laporan/surat_orang_tua_pdf.php?'.http_build_query(['mode'=>'class','kelas'=>'rombel:'.$students[0]['master_kelas_id'],'q'=>$students[0]['nis']]),$id);
        letter_http_assert(str_starts_with($body,'%PDF-')&&str_contains(letter_http_header($headers,'Content-Disposition'),'rombel-'),'PDF rombel sesuai filter gagal.');
        [$headers,$body]=letter_http_get($base.'laporan/surat_orang_tua_pdf.php?mode=all',$id);
        letter_http_assert(str_starts_with($body,'%PDF-')&&str_contains(letter_http_header($headers,'Content-Disposition'),'semua-rombel'),'PDF semua rombel gagal.');
        if($paidOrNotDue!==null){
            [$headers]=letter_http_get($base.'laporan/surat_orang_tua_pdf.php?'.http_build_query(['mode'=>'single','nis'=>$paidOrNotDue]),$id);
            letter_http_assert(str_contains($headers[0]??'','404'),'Siswa tanpa tunggakan tetap mendapat surat.');
        }
        [$headers,$body]=letter_http_get($base.'laporan/export_global.php?template=tunggakan-siswa&format=pdf&siswa_status=active',$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_starts_with($body,'%PDF-'),'PDF kepala sekolah gagal.');
        letter_http_assert(str_contains(strtolower(letter_http_header($headers,'Content-Disposition')),'inline'),'PDF kepala sekolah tidak inline.');
        [$headers,$body]=letter_http_get($base.'laporan/export_global.php?template=tunggakan-siswa&format=excel&download=1&siswa_status=active',$id);
        letter_http_assert(str_contains(strtolower(letter_http_header($headers,'Content-Disposition')),'attachment'),'Excel tidak langsung diunduh.');
        letter_http_assert(str_contains($body,htmlspecialchars($students[0]['nama'],ENT_QUOTES,'UTF-8')),'Excel tidak memuat data rekap.');
        letter_http_end_session($id);
    }
    echo "OK: akses tiga peran, PDF inline, Excel lampiran, dan akses tanpa login.\n";
}catch(Throwable $error){fwrite(STDERR,'FAILED: '.$error->getMessage().PHP_EOL);exit(1);}
