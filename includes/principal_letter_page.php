<?php
if (!isset($report, $filters, $reportUnitId, $exportQuery, $classes, $classLevels)) {
    http_response_code(404);
    exit;
}
$principalRows = $report['rows'];
$principalStudentCount = array_sum(array_column($principalRows, 'jumlah_siswa'));
$principalTotal = array_sum(array_column($principalRows, 'total_tunggakan'));
$scopeLabel = report_principal_scope_label($filters, $classes);
$scope = $reportUnitId === 0 ? 'all' : 'active';
$letterQuery = $exportQuery;
unset($letterQuery['mode'], $letterQuery['nis'], $letterQuery['q']);
$previewBase = array_merge($letterQuery, ['format'=>'preview', 'mode'=>'filtered']);
$filteredPreview = 'export_global.php?' . http_build_query($previewBase);
$allPreview = 'export_global.php?' . http_build_query(array_merge($previewBase, ['mode'=>'all', 'kelas'=>'']));
$excelPreview = 'export_global.php?' . http_build_query(array_merge($letterQuery, ['format'=>'excel', 'mode'=>'filtered']));
?>
<!doctype html>
<html lang="id" data-palette="<?= report_e(unit_palette_for_view($reportUnitId)) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Surat Laporan ke Kepala Sekolah | SistemSPP</title>
    <link rel="icon" href="../assets/img/favicon.png?v=2">
    <link rel="stylesheet" href="../assets/css/style.css?v=principal-summary1">
    <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light')})();</script>
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout"><?php include __DIR__.'/sidebar.php'; ?><main class="main-content">
    <div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka navigasi">☰</button><div class="topbar-title"><h2>Surat Laporan ke Kepala Sekolah</h2><span class="breadcrumb"><a href="surat_laporan.php">Surat Laporan</a> / Kepala Sekolah</span></div><div class="clock-badge" id="liveClock">--:--:--</div></div>
    <div class="letter-list-shell principal-list-shell">
        <a class="letter-back-link" href="surat_laporan.php">&larr; Kembali ke pilihan surat</a>
        <section class="main-card letter-list-card">
            <div class="letter-list-head"><div class="letter-list-heading"><span class="recap-class-overline">SURAT KE KEPALA SEKOLAH · <?= report_e(unit_label($reportUnitId)) ?></span><h1>Rekap total tunggakan</h1><p>Pilih rombel, seluruh rombel pada satu kelas, atau seluruh kelas. Jumlah dihitung sampai <?= report_e(report_date_label($report['as_of_date'])) ?>.</p></div><div class="letter-total"><span><?= report_e($scopeLabel) ?></span><strong><?= report_e(report_money($principalTotal)) ?></strong><span><?= number_format($principalStudentCount) ?> siswa menunggak</span></div></div>
            <form method="get" class="letter-filters principal-letter-filters">
                <input type="hidden" name="template" value="tunggakan-siswa"><input type="hidden" name="unit" value="<?= $scope ?>">
                <div class="field-row"><label class="field-label" for="principal-class">Cakupan Kelas/Rombel</label><select class="field-input field-select" id="principal-class" name="kelas"><option value="">Seluruh Kelas/Rombel</option><?php foreach($classLevels as $level): ?><option value="tingkat:<?= report_e($level) ?>" <?= $filters['kelas']==='tingkat:'.$level?'selected':'' ?>>Seluruh Rombel Kelas <?= report_e($level) ?></option><?php endforeach; ?><?php foreach($classes as $class): ?><option value="rombel:<?= (int)$class['id'] ?>" <?= $filters['kelas']==='rombel:'.(int)$class['id']?'selected':'' ?>>Rombel <?= report_e(class_label($class)) ?></option><?php endforeach; ?></select></div>
                <div class="field-row"><label class="field-label" for="principal-status">Status Siswa</label><select class="field-input field-select" id="principal-status" name="siswa_status"><?php foreach(['active'=>'Aktif','archived'=>'Arsip/Lulus','all'=>'Semua'] as $key=>$label): ?><option value="<?= $key ?>" <?= $filters['siswa_status']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                <div class="principal-filter-buttons"><button class="btn btn-primary" type="submit">Tampilkan Rekap</button><a class="btn btn-ghost" href="template.php?template=tunggakan-siswa&amp;unit=<?= $scope ?>">Reset</a></div>
            </form>
            <div class="letter-actions" aria-label="Pilihan pratinjau surat"><div class="letter-action-copy"><strong><?= report_e($scopeLabel) ?></strong><span>Surat memuat total per rombel dan jumlah keseluruhan sesuai pilihan, tanpa daftar nama siswa.</span></div><div class="letter-action-buttons"><a class="btn btn-primary <?= !$principalRows?'is-disabled':'' ?>" target="_blank" rel="noopener" <?= $principalRows?'':'aria-disabled="true" tabindex="-1"' ?> href="<?= report_e($filteredPreview) ?>">Pratinjau Surat Pilihan</a><?php if($filters['kelas']!==''): ?><a class="btn btn-ghost" target="_blank" rel="noopener" href="<?= report_e($allPreview) ?>">Pratinjau Seluruh Kelas</a><?php endif; ?><a class="btn btn-ghost" target="_blank" rel="noopener" href="<?= report_e($excelPreview) ?>">Preview Excel</a></div></div>
            <div class="principal-table-toolbar"><span>Total tunggakan per rombel</span><span><?= number_format(count($principalRows)) ?> rombel · <?= number_format($principalStudentCount) ?> siswa menunggak</span></div>
            <div class="table-container letter-table-wrap" role="region" tabindex="0" aria-label="Rekap tunggakan per rombel"><table class="data-table principal-summary-table"><thead><tr><th>Kelas/Rombel</th><th>Siswa Menunggak</th><th>Total Tunggakan</th></tr></thead><tbody><?php if(!$principalRows): ?><tr><td colspan="3" class="letter-empty">Tidak ada tunggakan pada pilihan ini.</td></tr><?php else: foreach($principalRows as $row): ?><tr><td><strong><?= report_e($row['kelas']) ?></strong></td><td><?= number_format((int)$row['jumlah_siswa']) ?></td><td class="money"><?= report_e(report_money($row['total_tunggakan'])) ?></td></tr><?php endforeach; endif; ?></tbody><tfoot><tr><th>Total <?= report_e($scopeLabel) ?></th><th><?= number_format($principalStudentCount) ?></th><th class="money"><?= report_e(report_money($principalTotal)) ?></th></tr></tfoot></table></div>
        </section>
    </div>
</main></div>
<script src="../assets/js/app.js?v=10.5"></script>
</body></html>
