<?php
if (!isset($report, $filters, $reportUnitId, $exportQuery, $classes, $classLevels)) {
    http_response_code(404);
    exit;
}
$principalRows = $report['rows'];
$principalCount = count($principalRows);
$principalTotal = array_sum(array_map(static fn($row) => (float)$row['total_tunggakan'], $principalRows));
$allFilters = $filters;
$allFilters['kelas'] = '';
$allFilters['q'] = '';
$allRows = report_student_debt_data($koneksi, $allFilters)['rows'];
$scope = $reportUnitId === 0 ? 'all' : 'active';
$previewBase = array_merge($exportQuery, ['format'=>'preview']);
$classPreview = 'export_global.php?' . http_build_query(array_merge($previewBase, ['mode'=>'class']));
$allPreview = 'export_global.php?' . http_build_query(array_merge($previewBase, ['mode'=>'all', 'kelas'=>'', 'q'=>'']));
$selectedPreview = 'export_global.php?' . http_build_query(array_merge($previewBase, ['mode'=>'selected']));
$excelPreview = 'export_global.php?' . http_build_query(array_merge($exportQuery, ['format'=>'excel']));
?>
<!doctype html>
<html lang="id" data-palette="<?= report_e(unit_palette_for_view($reportUnitId)) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Surat Laporan ke Kepala Sekolah | SistemSPP</title>
    <link rel="icon" href="../assets/img/favicon.png?v=2">
    <link rel="stylesheet" href="../assets/css/style.css?v=unitpalette5">
    <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light')})();</script>
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout"><?php include __DIR__.'/sidebar.php'; ?><main class="main-content">
    <div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka navigasi">☰</button><div class="topbar-title"><h2>Surat Laporan ke Kepala Sekolah</h2><span class="breadcrumb"><a href="surat_laporan.php">Surat Laporan</a> / Kepala Sekolah</span></div><div class="clock-badge" id="liveClock">--:--:--</div></div>
    <div class="letter-list-shell principal-list-shell">
        <a class="letter-back-link" href="surat_laporan.php">&larr; Kembali ke pilihan surat</a>
        <section class="main-card letter-list-card">
            <div class="letter-list-head"><div class="letter-list-heading"><span class="recap-class-overline">SURAT KE KEPALA SEKOLAH · <?= report_e(unit_label($reportUnitId)) ?></span><h1>Pilih data untuk laporan tunggakan</h1><p>Rekap siswa dengan tunggakan sampai <?= report_e(report_date_label($report['as_of_date'])) ?>. Periksa siswa dan jumlahnya sebelum membuka pratinjau surat.</p></div><div class="letter-total"><span>Siswa sesuai filter</span><strong><?= number_format($principalCount) ?></strong><span>Total <?= report_e(report_money($principalTotal)) ?></span></div></div>
            <form method="get" class="letter-filters principal-letter-filters">
                <input type="hidden" name="template" value="tunggakan-siswa"><input type="hidden" name="unit" value="<?= $scope ?>"><input type="hidden" name="per_page" id="principal-filter-per-page" value="<?= (int)$filters['per_page'] ?>">
                <div class="field-row"><label class="field-label" for="principal-class">Kelas/Rombel</label><select class="field-input field-select" id="principal-class" name="kelas"><option value="">Semua Rombel</option><?php foreach($classLevels as $level): ?><option value="tingkat:<?= report_e($level) ?>" <?= $filters['kelas']==='tingkat:'.$level?'selected':'' ?>>Semua Kelas <?= report_e($level) ?></option><?php endforeach; ?><?php foreach($classes as $class): ?><option value="rombel:<?= (int)$class['id'] ?>" <?= $filters['kelas']==='rombel:'.(int)$class['id']?'selected':'' ?>><?= report_e(class_label($class)) ?></option><?php endforeach; ?></select></div>
                <div class="field-row"><label class="field-label" for="principal-status">Status Siswa</label><select class="field-input field-select" id="principal-status" name="siswa_status"><?php foreach(['active'=>'Aktif','archived'=>'Arsip/Lulus','all'=>'Semua'] as $key=>$label): ?><option value="<?= $key ?>" <?= $filters['siswa_status']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                <div class="field-row letter-search"><label class="field-label" for="principal-q">Cari Siswa</label><input class="field-input" id="principal-q" name="q" value="<?= report_e($filters['q']) ?>" placeholder="Nama, NIS, atau NIS Diknas"></div>
                <div class="principal-filter-buttons"><button class="btn btn-primary" type="submit">Tampilkan siswa</button><a class="btn btn-ghost" href="template.php?template=tunggakan-siswa&amp;unit=<?= $scope ?>">Reset</a></div>
            </form>
            <div class="letter-actions" aria-label="Pilihan pratinjau surat"><div class="letter-action-copy"><strong><?= number_format($principalCount) ?> siswa sesuai filter</strong><span>Pilih siswa di tabel, cetak satu rombel, atau buat rekap seluruh rombel. Semua tindakan membuka pratinjau lebih dahulu.</span></div><div class="letter-action-buttons"><button type="button" class="btn btn-ghost" id="principal-print-selected" disabled>Cetak Dipilih (0)</button><?php if($filters['kelas']===''): ?><button class="btn btn-ghost" type="button" disabled title="Pilih kelas atau rombel dahulu">Cetak Kelas/Rombel</button><?php else: ?><a class="btn btn-ghost <?= !$principalCount?'is-disabled':'' ?>" target="_blank" rel="noopener" <?= $principalCount?'':'aria-disabled="true" tabindex="-1"' ?> href="<?= report_e($classPreview) ?>">Cetak Kelas/Rombel (<?= $principalCount ?>)</a><?php endif; ?><a class="btn btn-primary <?= !$allRows?'is-disabled':'' ?>" target="_blank" rel="noopener" <?= $allRows?'':'aria-disabled="true" tabindex="-1"' ?> href="<?= report_e($allPreview) ?>">Cetak Semua Rombel (<?= count($allRows) ?>)</a><a class="btn btn-ghost" target="_blank" rel="noopener" href="<?= report_e($excelPreview) ?>">Preview Excel</a></div></div>
            <div class="principal-table-toolbar"><span>Daftar siswa dan jumlah tunggakan</span><label for="principal-page-size">Baris/Halaman <select id="principal-page-size" class="field-input field-select"><?php foreach([25,50,100] as $size): ?><option value="<?= $size ?>" <?= $filters['per_page']===$size?'selected':'' ?>><?= $size ?></option><?php endforeach; ?></select></label></div>
            <p class="letter-table-hint">Geser tabel ke samping untuk melihat seluruh kolom dan tombol cetak.</p>
            <div class="table-container letter-table-wrap" role="region" tabindex="0" aria-label="Siswa dengan tunggakan, dapat digeser ke samping"><table class="data-table letter-student-table principal-student-table"><thead><tr><th class="letter-select-cell"><label class="letter-select-all"><input type="checkbox" id="principal-select-page" aria-label="Pilih semua siswa pada halaman ini"><span>Pilih</span></label></th><th>Siswa</th><th>Kelas</th><th>Tagihan</th><th>Sudah Dibayar</th><th>Total Tunggakan</th><th>Surat</th></tr></thead><tbody><?php if(!$principalRows): ?><tr><td colspan="7" class="letter-empty">Tidak ada siswa dengan tunggakan pada filter ini.</td></tr><?php else: foreach($principalRows as $student): $singlePreview='export_global.php?'.http_build_query(array_merge($previewBase,['mode'=>'single','nis'=>$student['nis']])); ?><tr class="letter-student-row"><td class="letter-select-cell"><input type="checkbox" class="letter-select" value="<?= report_e($student['nis']) ?>" aria-label="Pilih <?= report_e($student['nama']) ?>"></td><td class="letter-student-name"><strong><?= report_e($student['nama']) ?></strong><small>NIS <?= report_e($student['nis']) ?><?= ($student['nis_diknas']??'')!==''?' · Diknas '.report_e($student['nis_diknas']):'' ?></small></td><td><?= report_e($student['kelas']) ?></td><td><?= count($student['items']) ?> tagihan</td><td class="money"><?= report_e(report_money($student['sudah_dibayar'])) ?></td><td class="money principal-debt-total"><?= report_e(report_money($student['total_tunggakan'])) ?></td><td><a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="<?= report_e($singlePreview) ?>">Cetak Surat</a></td></tr><?php endforeach; endif; ?></tbody></table></div>
            <div class="letter-pages"><span id="principal-page-info"></span><div><button type="button" class="btn btn-ghost btn-sm" id="principal-page-prev">Sebelumnya</button><button type="button" class="btn btn-ghost btn-sm" id="principal-page-next">Berikutnya</button></div></div>
        </section>
    </div>
</main></div>
<script src="../assets/js/app.js?v=10.5"></script>
<script>
(()=>{
  const rows=[...document.querySelectorAll('.principal-student-table .letter-student-row')];
  const boxes=[...document.querySelectorAll('.principal-student-table .letter-select')];
  const selected=document.getElementById('principal-print-selected');
  const all=document.getElementById('principal-select-page');
  const info=document.getElementById('principal-page-info');
  const prev=document.getElementById('principal-page-prev');
  const next=document.getElementById('principal-page-next');
  const sizeSelect=document.getElementById('principal-page-size');
  const sizeTarget=document.getElementById('principal-filter-per-page');
  let page=1;
  function draw(){
    const size=Number(sizeSelect.value)||25,max=Math.max(1,Math.ceil(rows.length/size));
    page=Math.min(page,max);
    rows.forEach((row,index)=>{row.hidden=Math.floor(index/size)+1!==page});
    info.textContent=rows.length?`${(page-1)*size+1}–${Math.min(page*size,rows.length)} dari ${rows.length} siswa`:'0 siswa';
    prev.disabled=page===1;next.disabled=page===max;
    const visible=boxes.filter((_,index)=>Math.floor(index/size)+1===page);
    all.checked=visible.length>0&&visible.every(box=>box.checked);
    all.indeterminate=!all.checked&&visible.some(box=>box.checked);
    const count=boxes.filter(box=>box.checked).length;
    selected.textContent=`Cetak Dipilih (${count})`;selected.disabled=count===0;
    sizeTarget.value=String(size);
  }
  all.addEventListener('change',()=>{const size=Number(sizeSelect.value)||25;boxes.forEach((box,index)=>{if(Math.floor(index/size)+1===page)box.checked=all.checked});draw()});
  boxes.forEach(box=>box.addEventListener('change',draw));
  sizeSelect.addEventListener('change',()=>{page=1;draw()});
  prev.addEventListener('click',()=>{page--;draw()});next.addEventListener('click',()=>{page++;draw()});
  selected.addEventListener('click',()=>{const nis=boxes.filter(box=>box.checked).map(box=>box.value);if(!nis.length)return;const url=new URL(<?= json_encode($selectedPreview) ?>,location.href);url.searchParams.set('nis',nis.join(','));window.open(url.toString(),'_blank','noopener')});
  draw();
})();
</script>
</body></html>
