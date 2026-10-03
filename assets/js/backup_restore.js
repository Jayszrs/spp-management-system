/* UI preview only: no network, SQL parsing, file-content reads or operation submission. */
(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  const tabs = ['backup', 'legacy'].map(mode => byId('tab-' + mode));
  const selectTab = selected => {
    tabs.forEach(tab => {
      const active = tab === selected;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      byId(tab.getAttribute('aria-controls')).hidden = !active;
    });
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => selectTab(tab));
    tab.addEventListener('keydown', event => {
      let target;
      if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') target = tabs[1 - index];
      if (event.key === 'Home') target = tabs[0];
      if (event.key === 'End') target = tabs[1];
      if (target) { event.preventDefault(); selectTab(target); target.focus(); }
    });
  });
  const selectedFiles = {restore: null, legacy: null};
  const legacyReadiness = () => document.dispatchEvent(new CustomEvent('legacy-selection-changed'));
  const updateSelection = (prefix, files) => {
    selectedFiles[prefix] = null;
    byId(prefix + '-summary').hidden = true;
    const status = byId(prefix + '-status');
    status.classList.remove('is-error');
    let error = '';
    if (files.length > 1) error = 'Pilih satu file saja; pilihan ganda tidak diterima.';
    else if (files.length === 1) {
      const file = files[0];
      if (!(prefix === 'legacy' ? /\.dat$/i : /\.sql$/i).test(file.name)) error = prefix === 'legacy' ? 'Pilih satu backup SQL Server (.dat).' : 'Pilih satu file SQL (.sql).';
      else if (file.size === 0) error = 'File kosong tidak dapat digunakan.';
      else if (file.size > 100 * 1024 * 1024) error = 'Ukuran file melebihi batas 100 MB.';
      else {
        selectedFiles[prefix] = file;
        byId(prefix + '-summary').hidden = false;
        byId(prefix + '-name').textContent = file.name;
        byId(prefix + '-size').textContent = (file.size / 1048576).toLocaleString('id-ID', {maximumFractionDigits: 2}) + ' MB';
      }
    }
    status.textContent = error || (selectedFiles[prefix] ? (prefix==='legacy'?'Belum diperiksa. Nama, ukuran, dan jumlah file memenuhi pemeriksaan awal; klik Periksa Backup untuk memeriksa sumber.':'Belum divalidasi. Pemeriksaan awal nama, ukuran, dan jumlah file terpenuhi; isi SQL belum diperiksa.') : 'Belum ada file dipilih.');
    if (error) status.classList.add('is-error');
    if (prefix === 'restore') byId('restore-review').disabled = !selectedFiles.restore;
    legacyReadiness();
    if(prefix === 'legacy') document.dispatchEvent(new CustomEvent('legacy-file-selected', {detail: selectedFiles.legacy}));
  };
  ['restore', 'legacy'].forEach(prefix => {
    const input = byId(prefix + '-file'), zone = byId(prefix + '-dropzone');
    const choose = () => { input.value = ''; input.click(); };
    byId(prefix + '-choose').addEventListener('click', choose);
    byId(prefix + '-change').addEventListener('click', choose);
    input.addEventListener('change', () => updateSelection(prefix, Array.from(input.files)));
    byId(prefix + '-remove').addEventListener('click', () => { input.value = ''; updateSelection(prefix, []); byId(prefix + '-choose').focus(); });
    zone.addEventListener('dragover', event => { event.preventDefault(); zone.classList.add('is-dragging'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('is-dragging'));
    zone.addEventListener('drop', event => {
      event.preventDefault(); zone.classList.remove('is-dragging'); input.value = '';
      updateSelection(prefix, Array.from(event.dataTransfer.files));
    });
  });
  const conditions = {
    students: ['Identitas siswa sebagai Legacy', 'NIS dipertahankan per unit. Duplikasi dalam unit dan identitas yang bermasalah ditahan. Aktivasi dan penempatan dilakukan manual melalui Data Siswa.'],
    classes: ['Histori kelas dan tarif perlu dilengkapi', 'Kelas, rombel, periode, dan tarif harus dipetakan dari bukti sumber. Kelas sebelum penempatan pertama tidak ditebak.'],
    payments: ['Relasi pembayaran perlu dibuktikan', 'Identitas transaksi, periode, siswa, tagihan, potongan, dan nominal perlu dicocokkan. Tidak ada penerimaan fiktif atau pemecahan transaksi otomatis.'],
    savings: ['Saldo dan jurnal perlu direkonsiliasi', 'Saldo Tabungan harus cocok dengan jurnal masuk/keluar. Saldo bukan penerimaan pembayaran sekolah.']
  };
  document.querySelectorAll('[data-category]').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('[data-category]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    const [title, description] = conditions[button.dataset.category];
    document.dispatchEvent(new CustomEvent('legacy-category-selected', {detail: button.dataset.category}));
    byId('legacy-condition-title').textContent = title;
    byId('legacy-condition').textContent = description;
  }));
  byId('legacy-unit').addEventListener('change', legacyReadiness);
  const dialog = byId('restore-dialog');
  byId('restore-review').addEventListener('click', () => {
    if (!selectedFiles.restore) return;
    byId('restore-dialog-file').textContent = selectedFiles.restore.name;
    byId('restore-understood').checked = false;
    byId('restore-confirmation').value = '';
    dialog.showModal();
  });
  byId('restore-cancel').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => byId('restore-review').focus());
  // The native modal supplies keyboard focus containment and Escape cancellation.
})();
