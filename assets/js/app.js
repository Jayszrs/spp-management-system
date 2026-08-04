// ============================================
// SistemSPP - app.js
// ============================================

/* ── Theme Init (run ASAP to avoid flash) ─── */
(function () {
  const saved = localStorage.getItem('spp_theme') || 'dark';
  document.documentElement.setAttribute('data-theme', saved);
})();

/* ── Theme Toggle ────────────────────────── */
function toggleTheme() {
  const html    = document.documentElement;
  const current = html.getAttribute('data-theme') || 'dark';
  const next    = current === 'dark' ? 'light' : 'dark';

  html.setAttribute('data-theme', next);
  localStorage.setItem('spp_theme', next);
  updateThemeUI(next);
}

function updateThemeUI(theme) {
  const isDark = (theme !== 'light');

  // Sidebar toggle button label
  const lblEl  = document.getElementById('theme-label');
  const iconEl = document.getElementById('theme-icon');
  if (lblEl)  lblEl.textContent  = isDark ? 'Mode Gelap'  : 'Mode Terang';
  if (iconEl) iconEl.textContent = isDark ? '🌙'           : '☀️';

  // Login page floating button
  const loginBtn   = document.getElementById('login-theme-btn');
  const loginLabel = document.getElementById('login-theme-label');
  const loginIcon  = document.getElementById('login-theme-icon');
  if (loginBtn)   loginBtn.setAttribute('title', isDark ? 'Aktifkan Mode Terang' : 'Aktifkan Mode Gelap');
  if (loginLabel) loginLabel.textContent = isDark ? 'Mode Terang' : 'Mode Gelap';
  if (loginIcon)  loginIcon.textContent  = isDark ? '☀️' : '🌙';
}

// Apply UI labels once DOM is ready
document.addEventListener('DOMContentLoaded', function () {
  const saved = localStorage.getItem('spp_theme') || 'dark';
  updateThemeUI(saved);
});

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.clickable-payment-row[data-edit-url]').forEach(row => {
    const openEdit = () => {
      const url = row.dataset.editUrl;
      if (url) window.location.href = url;
    };

    row.addEventListener('click', function (event) {
      if (event.target.closest('a, button, input, select, textarea, form')) return;
      openEdit();
    });

    row.addEventListener('keydown', function (event) {
      if (!['Enter', ' '].includes(event.key)) return;
      if (event.target.closest('a, button, input, select, textarea, form')) return;
      event.preventDefault();
      openEdit();
    });
  });
});

/* ── Live Clock ──────────────────────────── */
function updateClock() {
  const el = document.getElementById('liveClock');
  if (!el) return;
  const now = new Date();
  el.textContent = now.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
}
setInterval(updateClock, 1000);
updateClock();

/* ── Sidebar Toggle ──────────────────────── */
function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  const mc = document.querySelector('.main-content');
  if (!sb) return;
  if (window.innerWidth <= 768) {
    sb.classList.toggle('open');
  } else {
    sb.classList.toggle('collapsed');
    mc && mc.classList.toggle('expanded');
  }
}

/* ── Toggle Password Visibility ───────────── */
function togglePw() {
  const pw = document.getElementById('password');
  if (!pw) return;
  pw.type = pw.type === 'password' ? 'text' : 'password';
}

/* ── Tab Switching ───────────────────────── */
function switchTab(name) {
  document.querySelectorAll('.tab').forEach(t => {
    t.classList.remove('active');
    t.setAttribute('aria-selected', 'false');
  });
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));

  const tabEl = document.getElementById('tab-' + name);
  const panelEl = document.getElementById('panel-' + name);
  if (tabEl)   { tabEl.classList.add('active'); tabEl.setAttribute('aria-selected', 'true'); }
  if (panelEl) panelEl.classList.add('active');

  if (name === 'lihat') renderLihatTable();
}

/* ── Pilih Siswa (auto-fill NIS & Kelas) ─── */
function pilihSiswa(sel) {
  const opt = sel.options[sel.selectedIndex];
  const nisEl   = document.getElementById('disp-nis');
  const kelasEl = document.getElementById('disp-kelas');
  if (nisEl)   nisEl.value   = opt.dataset.nis   || '';
  if (kelasEl) kelasEl.value = opt.dataset.kelas  || '';
}

/* ── Pilih Siswa Datalist (Search & Auto-fill) ── */
function pilihSiswaDatalist(input) {
  const val = input.value.trim();
  const list = document.getElementById('siswa-list');
  if (!list) return;
  const options = list.options;
  
  if (val === '') {
    document.getElementById('disp-nis').value = '';
    document.getElementById('disp-nama').value = '';
    document.getElementById('disp-kelas').value = '';
    clearPaymentDetails();
    return;
  }
  
  let found = false;
  for (let i = 0; i < options.length; i++) {
    const opt = options[i];
    const nis = opt.dataset.nis;
    const nama = opt.dataset.nama;
    
    // Match if exact match or if user is backspacing but NIS is still at the beginning
    if (val === opt.value || val === nis || val.startsWith(nis + ' —') || val.startsWith(nis)) {
      document.getElementById('disp-nis').value = nis || '';
      document.getElementById('disp-nama').value = nama || '';
      document.getElementById('disp-kelas').value = opt.dataset.kelas || '';
      applyDefaultDaftarUlangClass(opt);
      applyStudentPaymentDetails(opt);
      found = true;
      break;
    }
  }
  
  if (!found) {
    document.getElementById('disp-nis').value = '';
    document.getElementById('disp-nama').value = '';
    document.getElementById('disp-kelas').value = '';
    clearPaymentDetails();
  }
}

function applyDefaultDaftarUlangClass(opt) {
  const select = document.getElementById('kelas-du') || document.querySelector('[name="kelas_du"]');
  if (!select || !opt) return;
  if (select.dataset.userChanged === '1') return;
  if (select.dataset.preserveDefault === '1' && select.value) return;

  const studentClass = String(opt.dataset.kelas || '').match(/[1-6]/)?.[0] || '';
  if (!studentClass) return;
  if (Array.from(select.options).some(option => option.value === studentClass)) {
    select.value = studentClass;
  }
}

function selectedPaymentPeriod() {
  const bulan = document.getElementById('bulan-bayar')?.value || '';
  const tahun = document.getElementById('tahun-bayar')?.value || '';
  return bulan && tahun ? bulan + '-' + tahun : '';
}

function datasetNumber(opt, prefix, key) {
  if (!opt) return 0;
  const name = prefix + key.charAt(0).toUpperCase() + key.slice(1);
  return parseNumber(opt.dataset[name] || 0);
}

function selectedStudentOption() {
  const input = document.getElementById('siswa-search');
  const list = document.getElementById('siswa-list');
  const nis = document.getElementById('disp-nis')?.value || '';
  if (!input || !list || !nis) return null;
  return Array.from(list.options).find(opt => opt.dataset.nis === nis || opt.value === input.value) || null;
}

function paidForPeriod(opt, key) {
  if (!opt) return 0;
  try {
    const datasetKey = key === 'komite' ? 'paidKomitePeriods' : 'paidSppPeriods';
    const periods = JSON.parse(opt.dataset[datasetKey] || '{}');
    return parseNumber(periods[selectedPaymentPeriod()] || 0);
  } catch (_) {
    return 0;
  }
}

function selectedDaftarUlangKey() {
  const kelas = document.getElementById('kelas-du')?.value || document.querySelector('[name="kelas_du"]')?.value || '';
  const tahun = document.getElementById('tahun-ajaran-du')?.value || document.querySelector('[name="tahun_ajaran_du"]')?.value || '';
  return kelas && tahun ? kelas + '|' + tahun : '';
}

function totalDaftarUlangForContext(opt) {
  const fallbackTotal = datasetNumber(opt, 'total', 'du');
  const periodKey = selectedDaftarUlangKey();
  const masters = window.sppDaftarUlangMasters || {};
  const hasMasters = window.sppDaftarUlangHasMasters === true;
  const masterTotal = periodKey ? parseNumber(masters[periodKey] || 0) : 0;
  if (hasMasters) return masterTotal > 0 ? masterTotal : 0;
  return masterTotal > 0 ? masterTotal : fallbackTotal;
}

function refreshDaftarUlangMasterWarning(opt) {
  const warning = document.getElementById('du-master-warning');
  const input = document.getElementById('du-input');
  if (!warning && !input) return;

  const hasMasters = window.sppDaftarUlangHasMasters === true;
  const periodKey = selectedDaftarUlangKey();
  const masters = window.sppDaftarUlangMasters || {};
  const masterTotal = periodKey ? parseNumber(masters[periodKey] || 0) : 0;
  const isMissingMaster = !!opt && hasMasters && !!periodKey && masterTotal <= 0;

  if (input) {
    const missingMessage = 'Master daftar ulang untuk pilihan kelas dan tahun ajaran ini belum diatur.';
    input.readOnly = isMissingMaster;
    input.classList.toggle('tbl-readonly', isMissingMaster);
    if (isMissingMaster) {
      input.title = missingMessage;
      input.setCustomValidity(parseNumber(input.value || 0) > 0 ? missingMessage : '');
    } else if (input.title === missingMessage) {
      input.setCustomValidity('');
      input.removeAttribute('title');
    }
  }

  if (!warning) return;
  if (!isMissingMaster) {
    warning.hidden = true;
    warning.textContent = '';
    return;
  }

  const [kelas, tahun] = periodKey.split('|');
  warning.hidden = false;
  warning.textContent = 'Master daftar ulang kelas ' + kelas + ' tahun ajaran ' + tahun + ' belum diatur. Lengkapi master daftar ulang agar nominal DU bisa dipakai.';
}

function paidDaftarUlangForContext(opt) {
  if (!opt) return 0;
  const periodKey = selectedDaftarUlangKey();
  if (!periodKey) return 0;
  try {
    const paidPeriods = JSON.parse(opt.dataset.paidDuPeriods || '{}');
    return parseNumber(paidPeriods[periodKey] || 0);
  } catch (_) {
    return 0;
  }
}

function setPaymentComponent(key, total, paid) {
  const totalEl = document.getElementById(key + '-total');
  const paidEl = document.getElementById(key + '-bayar');
  if (totalEl) totalEl.value = formatRupiahString(total || 0);
  if (paidEl) paidEl.value = formatRupiahString(paid || 0);
  hitungSisa(key);
}

function applyStudentPaymentDetails(opt) {
  if (!opt) return;
  ['pangkal','bangunan','seragam','kegiatan','spp','komite','makan','sorga','infaq','du'].forEach(key => {
    const total = key === 'du' ? totalDaftarUlangForContext(opt) : datasetNumber(opt, 'total', key);
    const paid = key === 'du'
      ? paidDaftarUlangForContext(opt)
      : (['spp', 'komite'].includes(key) ? paidForPeriod(opt, key) : datasetNumber(opt, 'paid', key));
    setPaymentComponent(key, total, paid);
  });
  refreshDaftarUlangMasterWarning(opt);
  document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
  updateTotal();
}

function clearPaymentDetails() {
  ['pangkal','bangunan','seragam','kegiatan','spp','komite','makan','sorga','infaq','du'].forEach(key => {
    ['total','bayar','sisa'].forEach(part => {
      const el = document.getElementById(key + '-' + part);
      if (el) el.value = '0';
    });
  });
  refreshDaftarUlangMasterWarning(null);
  document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
  updateTotal();
}

const paymentComponentLabels = {
  pangkal: 'Uang Pangkal',
  bangunan: 'Uang Bangunan',
  seragam: 'Uang Seragam',
  kegiatan: 'Uang Kegiatan',
  spp: 'Uang SPP',
  komite: 'Uang Komite',
  makan: 'Uang Makan',
  sorga: 'Uang Sorga',
  infaq: 'Uang Infaq',
  du: 'Uang Daftar Ulang'
};

function refreshOverpaidWarnings() {
  const alertEl = document.getElementById('payment-overpaid-alert');
  const overpaid = [];

  Object.keys(paymentComponentLabels).forEach(key => {
    const totalEl = document.getElementById(key + '-total');
    const paidEl = document.getElementById(key + '-bayar');
    const inputEl = document.getElementById(key + '-input');
    const row = totalEl?.closest('tr');
    if (!totalEl || !paidEl || !inputEl) return;

    const total = parseNumber(totalEl.value || 0);
    const paid = parseNumber(paidEl.value || 0);
    const isOverpaid = total > 0 && paid > total;

    row?.classList.toggle('row-overpaid', isOverpaid);
    if (isOverpaid) {
      inputEl.value = '0';
      inputEl.disabled = true;
      inputEl.title = 'Input dikunci karena pembayaran sebelumnya sudah melebihi total tagihan.';
      overpaid.push(paymentComponentLabels[key] + ' (total Rp ' + formatRupiah(total) + ', sudah terbayar Rp ' + formatRupiah(paid) + ')');
    } else {
      inputEl.disabled = false;
      inputEl.removeAttribute('title');
    }
  });

  if (!alertEl) return overpaid;
  if (overpaid.length === 0) {
    alertEl.hidden = true;
    alertEl.textContent = '';
    return overpaid;
  }

  alertEl.hidden = false;
  alertEl.textContent = 'Perhatian: ada pembayaran yang sudah melebihi total tagihan, yaitu ' + overpaid.join('; ') + '. Sisa pembayaran dianggap Rp 0 dan input komponen tersebut dikunci. Silakan cek ulang data pembayaran sebelumnya.';
  return overpaid;
}

function refreshPaymentInputOverlimitWarnings() {
  const alertEl = document.getElementById('payment-input-overlimit-alert');
  const warnings = [];

  Object.keys(paymentComponentLabels).forEach(key => {
    const totalEl = document.getElementById(key + '-total');
    const paidEl = document.getElementById(key + '-bayar');
    const inputEl = document.getElementById(key + '-input');
    const row = inputEl?.closest('tr');
    if (!totalEl || !paidEl || !inputEl) return;

    if (inputEl.disabled) {
      row?.classList.remove('row-input-overlimit');
      inputEl.classList.remove('is-input-overlimit');
      inputEl.setCustomValidity('');
      return;
    }

    const total = parseNumber(totalEl.value || 0);
    const paid = parseNumber(paidEl.value || 0);
    const input = parseNumber(inputEl.value || 0);
    const remainingBeforeInput = Math.max(0, total - paid);
    const isTooMuch = input > remainingBeforeInput + 0.001;

    row?.classList.toggle('row-input-overlimit', isTooMuch);
    inputEl.classList.toggle('is-input-overlimit', isTooMuch);

    if (isTooMuch) {
      const message = paymentComponentLabels[key] + ' melebihi sisa tagihan. Sisa Rp ' + formatRupiah(remainingBeforeInput) + ', input Rp ' + formatRupiah(input) + '.';
      inputEl.setCustomValidity(message);
      inputEl.title = message;
      warnings.push(message);
    } else {
      inputEl.setCustomValidity('');
      inputEl.removeAttribute('title');
    }
  });

  if (!alertEl) return warnings;
  if (warnings.length === 0) {
    alertEl.hidden = true;
    alertEl.textContent = '';
    return warnings;
  }

  alertEl.hidden = false;
  alertEl.textContent = 'Perhatian: input pembayaran melebihi sisa tagihan. ' + warnings.join(' ');
  return warnings;
}

function clearOverpaidUiState() {
  ['payment-overpaid-alert', 'payment-input-overlimit-alert', 'biaya-lain-overpaid-alert'].forEach(id => {
    const alertEl = document.getElementById(id);
    if (!alertEl) return;
    alertEl.hidden = true;
    alertEl.textContent = '';
  });

  document.querySelectorAll('.row-overpaid').forEach(row => row.classList.remove('row-overpaid'));
  document.querySelectorAll('.row-input-overlimit').forEach(row => row.classList.remove('row-input-overlimit'));
  Object.keys(paymentComponentLabels).forEach(key => {
    const inputEl = document.getElementById(key + '-input');
    if (!inputEl) return;
    inputEl.disabled = false;
    inputEl.removeAttribute('title');
    inputEl.setCustomValidity('');
    inputEl.classList.remove('is-input-overlimit');
  });
  document.querySelectorAll('.biaya-lain-nominal').forEach(input => {
    input.removeAttribute('title');
    input.setCustomValidity('');
  });
}

function refreshSelectedStudentPaymentDetails() {
  const opt = selectedStudentOption();
  if (opt) applyStudentPaymentDetails(opt);
}

function showMonthLabels(select) {
  if (!select) return;
  Array.from(select.options).forEach(opt => {
    if (opt.value && opt.dataset.label) opt.textContent = opt.dataset.label;
  });
}

function showSelectedMonthCode(select) {
  if (!select) return;
  showMonthLabels(select);
  const opt = select.options[select.selectedIndex];
  if (opt && opt.value) opt.textContent = opt.value;
}

// Helper to parse formatted rupiah string back to raw number
function parseNumber(val) {
  if (typeof val === 'number') return val;
  if (!val) return 0;
  const clean = val.toString().replace(/\./g, '');
  return parseFloat(clean) || 0;
}

// Helper to format number to rupiah string with dots
function formatRupiahString(val) {
  if (val === null || val === undefined || val === '') return '0';
  const clean = val.toString().replace(/\D/g, '');
  if (!clean) return '0';
  return parseInt(clean, 10).toLocaleString('id-ID');
}

function formatNumericInput(input) {
  let cursorPosition = input.selectionStart;
  const originalLength = input.value.length;
  const cleanVal = input.value.replace(/\D/g, '');

  input.value = cleanVal === '' ? '0' : parseInt(cleanVal, 10).toLocaleString('id-ID');

  const newLength = input.value.length;
  cursorPosition = cursorPosition + (newLength - originalLength);
  if (input.setSelectionRange) {
    input.setSelectionRange(cursorPosition, cursorPosition);
  }
}

function bindNumericInput(input) {
  if (!input || input.dataset.numericBound === '1') return;
  input.dataset.numericBound = '1';
  if (input.value && input.value !== '0') {
    input.value = formatRupiahString(input.value);
  }
  input.addEventListener('input', function () {
    formatNumericInput(this);

    if (this.id.endsWith('-total') || this.id.endsWith('-bayar') || this.id.endsWith('-input')) {
      const key = this.id.split('-')[0];
      hitungSisa(key);
    }
    if (this.classList.contains('biaya-lain-nominal')) {
      refreshBiayaLainRow(this.closest('.biaya-lain-row'), true);
    }
    updateTotal();
  });
}

// Auto-fill on page load (edit page) & bind number formatting
document.addEventListener('DOMContentLoaded', function () {
  const sel = document.getElementById('siswa-select');
  if (sel && sel.value) pilihSiswa(sel);
  const siswaSearch = document.getElementById('siswa-search');
  if (siswaSearch && siswaSearch.value.trim() !== '') {
    pilihSiswaDatalist(siswaSearch);
  }

  // Set today's date if empty
  const tgl = document.getElementById('tgl-bayar');
  if (tgl && !tgl.value) {
    tgl.value = new Date().toISOString().split('T')[0];
  }

  document.querySelectorAll('#bulan-bayar, #tahun-bayar, #kelas-du, #tahun-ajaran-du, [name="kelas_du"], [name="tahun_ajaran_du"]').forEach(el => {
    if (el.name === 'kelas_du') {
      el.addEventListener('change', () => { el.dataset.userChanged = '1'; });
    }
    el.addEventListener('change', refreshSelectedStudentPaymentDetails);
  });

  document.querySelectorAll('.month-code-select').forEach(select => {
    showSelectedMonthCode(select);
    select.addEventListener('pointerdown', () => showMonthLabels(select));
    select.addEventListener('focus', () => showMonthLabels(select));
    select.addEventListener('keydown', () => showMonthLabels(select));
    select.addEventListener('change', () => {
      window.setTimeout(() => showSelectedMonthCode(select), 0);
    });
    select.addEventListener('blur', () => showSelectedMonthCode(select));
  });

  // Format all numeric fields dynamically
  const numericInputs = document.querySelectorAll(
    '.tbl-input, #potongan-spp, #tab-wajib, #kewajiban-spp, .biaya-lain-nominal'
  );
  
  numericInputs.forEach(input => {
    bindNumericInput(input);
  });

  // Clean all formatted numeric inputs right before form submission so the backend gets raw numbers
  const form = document.getElementById('form-bayar');
  if (form) {
    form.addEventListener('submit', function () {
      document.querySelectorAll('.tbl-input, #potongan-spp, #tab-wajib, #kewajiban-spp, .biaya-lain-nominal').forEach(input => {
        input.value = input.value.replace(/\./g, '');
      });
    });
    form.addEventListener('reset', function () {
      window.setTimeout(resetForm, 0);
    });
  }

  updateTotal();
  autoHideFlash();
});

function renumberBiayaLainRows() {
  document.querySelectorAll('#biaya-lain-list .biaya-lain-row').forEach((row, index) => {
    const number = row.querySelector('.ll-num');
    if (number) number.textContent = index + 1;
  });
}

function paidBiayaLainForSelectedStudent(masterId) {
  const opt = selectedStudentOption();
  if (!opt || !masterId) return 0;
  try {
    const paidMap = JSON.parse(opt.dataset.paidBiayaLain || '{}');
    return parseNumber(paidMap[masterId] || paidMap[String(masterId)] || 0);
  } catch (_) {
    return 0;
  }
}

function refreshBiayaLainRow(row, preserveInput) {
  const select = row?.querySelector('.biaya-lain-select');
  const totalEl = row?.querySelector('.biaya-lain-total');
  const paidEl = row?.querySelector('.biaya-lain-paid');
  const sisaEl = row?.querySelector('.biaya-lain-sisa');
  const nominal = row?.querySelector('.biaya-lain-nominal');
  if (!select || !nominal) return;

  const option = select.options[select.selectedIndex];
  const masterId = option?.value || '';
  const masterTotal = parseNumber(option?.dataset.nominal || 0);
  const alreadyPaid = masterId ? paidBiayaLainForSelectedStudent(masterId) : 0;
  const remainingBeforeInput = Math.max(0, masterTotal - alreadyPaid);
  const currentInput = parseNumber(nominal.value || 0);

  if (!masterId) {
    if (totalEl) totalEl.value = '0';
    if (paidEl) paidEl.value = '0';
    if (sisaEl) sisaEl.value = '0';
    if (!preserveInput) nominal.value = '0';
    nominal.setCustomValidity('');
    nominal.removeAttribute('title');
    row?.classList.remove('row-overpaid');
    return;
  }

  if (totalEl) totalEl.value = formatRupiahString(masterTotal);
  if (paidEl) paidEl.value = formatRupiahString(alreadyPaid);
  if (!preserveInput) {
    nominal.value = formatRupiahString(remainingBeforeInput);
  }

  const inputValue = parseNumber(nominal.value || 0);
  const isTooMuch = inputValue > remainingBeforeInput + 0.001;
  if (sisaEl) sisaEl.value = formatRupiahString(Math.max(0, remainingBeforeInput - inputValue));
  row?.classList.toggle('row-overpaid', isTooMuch);

  if (isTooMuch) {
    const message = 'Input bayar biaya lain melebihi sisa. Sisa hanya Rp ' + formatRupiah(remainingBeforeInput) + '.';
    nominal.setCustomValidity(message);
    nominal.title = message;
  } else {
    nominal.setCustomValidity('');
    nominal.removeAttribute('title');
  }
}

function refreshBiayaLainWarnings() {
  const alertEl = document.getElementById('biaya-lain-overpaid-alert');
  const warnings = [];

  document.querySelectorAll('.biaya-lain-row').forEach(row => {
    const select = row.querySelector('.biaya-lain-select');
    const input = row.querySelector('.biaya-lain-nominal');
    const total = parseNumber(row.querySelector('.biaya-lain-total')?.value || 0);
    const paid = parseNumber(row.querySelector('.biaya-lain-paid')?.value || 0);
    const inputValue = parseNumber(input?.value || 0);
    const remaining = Math.max(0, total - paid);
    const isTooMuch = select?.value && inputValue > remaining + 0.001;
    if (!isTooMuch) return;

    const selectedText = select.options[select.selectedIndex]?.textContent?.trim() || 'Biaya lain';
    warnings.push(selectedText + ' melebihi sisa. Sisa Rp ' + formatRupiah(remaining) + ', input Rp ' + formatRupiah(inputValue) + '.');
  });

  if (!alertEl) return warnings;
  if (warnings.length === 0) {
    alertEl.hidden = true;
    alertEl.textContent = '';
    return warnings;
  }

  alertEl.hidden = false;
  alertEl.textContent = 'Perhatian: pembayaran biaya lain melebihi sisa tagihan. ' + warnings.join(' ');
  return warnings;
}

function setBiayaLainNominal(row, preserveLegacy) {
  const nominal = row?.querySelector('.biaya-lain-nominal');
  if (!nominal) return;
  if (!preserveLegacy) {
    nominal.value = '0';
  }
  refreshBiayaLainRow(row, preserveLegacy);
  updateTotal();
}

function addBiayaLainRow() {
  const list = document.getElementById('biaya-lain-list');
  const template = document.getElementById('biaya-lain-row-template');
  if (!list || !template) return;
  list.appendChild(template.content.cloneNode(true));
  const row = list.lastElementChild;
  bindNumericInput(row?.querySelector('.biaya-lain-nominal'));
  refreshBiayaLainRow(row, false);
  renumberBiayaLainRows();
  updateTotal();
}

document.addEventListener('DOMContentLoaded', function () {
  const list = document.getElementById('biaya-lain-list');
  const addButton = document.getElementById('btn-add-biaya-lain');
  if (!list || !addButton) return;

  addButton.addEventListener('click', addBiayaLainRow);
  list.addEventListener('change', function (event) {
    if (event.target.classList.contains('biaya-lain-select')) {
      setBiayaLainNominal(event.target.closest('.biaya-lain-row'), false);
    }
  });
  list.addEventListener('click', function (event) {
    const removeButton = event.target.closest('.btn-remove-biaya-lain');
    if (!removeButton) return;
    const row = removeButton.closest('.biaya-lain-row');
    if (list.querySelectorAll('.biaya-lain-row').length === 1) {
      row.querySelector('.biaya-lain-select').value = '';
      row.querySelector('[name="biaya_lain_detail_id[]"]').value = '';
      row.querySelector('.biaya-lain-keterangan').value = '';
      row.querySelector('.biaya-lain-total').value = '0';
      row.querySelector('.biaya-lain-paid').value = '0';
      row.querySelector('.biaya-lain-sisa').value = '0';
      row.querySelector('.biaya-lain-nominal').value = '0';
      row.querySelector('.biaya-lain-nominal').setCustomValidity('');
      row.classList.remove('row-overpaid');
    } else {
      row.remove();
    }
    renumberBiayaLainRows();
    updateTotal();
  });
  renumberBiayaLainRows();
  document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
});

/* ── Hitung Sisa ─────────────────────────── */
function hitungSisa(key) {
  const total  = parseNumber(document.getElementById(key + '-total')?.value  || 0);
  const bayar  = parseNumber(document.getElementById(key + '-bayar')?.value  || 0);
  const input  = parseNumber(document.getElementById(key + '-input')?.value  || 0);
  const sisaEl = document.getElementById(key + '-sisa');
  if (sisaEl) sisaEl.value = formatRupiahString(Math.max(0, total - bayar - input));
}

/* ── Update Total ────────────────────────── */
function updateTotal() {
  refreshOverpaidWarnings();
  refreshPaymentInputOverlimitWarnings();
  document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
  refreshBiayaLainWarnings();
  const ids = ['pangkal-input','bangunan-input','seragam-input','kegiatan-input','spp-input','komite-input','makan-input','sorga-input','infaq-input','du-input'];
  let total = 0;
  ids.forEach(id => {
    const el = document.getElementById(id);
    if (el) total += parseNumber(el.value || 0);
  });

  document.querySelectorAll('.biaya-lain-nominal').forEach(input => {
    total += parseNumber(input.value || 0);
  });

  // Subtract potongan
  const pot = parseNumber(document.getElementById('potongan-spp')?.value || 0);
  total = Math.max(0, total - pot);

  const totalEl   = document.getElementById('totalJumlah');
  const hiddenEl  = document.getElementById('hidden-total');
  if (totalEl)  totalEl.textContent = 'Rp ' + formatRupiah(total);
  if (hiddenEl) hiddenEl.value = total;

  // Hitung kewajiban SPP
  const sppInput = parseNumber(document.getElementById('spp-input')?.value || 0);
  const tabWajib = parseNumber(document.getElementById('tab-wajib')?.value || 0);
  const kewEl    = document.getElementById('kewajiban-spp');
  if (kewEl) {
    kewEl.value = formatRupiahString(Math.max(0, sppInput - pot - tabWajib));
  }
}

/* ── Format Rupiah ───────────────────────── */
function formatRupiah(num) {
  return new Intl.NumberFormat('id-ID').format(num);
}

/* ── Reset Form ──────────────────────────── */
function resetForm() {
  clearOverpaidUiState();
  const totalEl = document.getElementById('totalJumlah');
  if (totalEl) totalEl.textContent = 'Rp 0';
  const hiddenEl = document.getElementById('hidden-total');
  if (hiddenEl) hiddenEl.value = 0;

  ['pangkal','bangunan','seragam','kegiatan','spp','komite','makan','sorga','infaq','du'].forEach(k => {
    ['total','bayar','sisa','input'].forEach(s => {
      const el = document.getElementById(k + '-' + s);
      if (el) el.value = '0';
    });
  });

  const list = document.getElementById('biaya-lain-list');
  const template = document.getElementById('biaya-lain-row-template');
  if (list && template) {
    list.innerHTML = '';
    list.appendChild(template.content.cloneNode(true));
    const row = list.lastElementChild;
    bindNumericInput(row?.querySelector('.biaya-lain-nominal'));
    refreshBiayaLainRow(row, false);
    renumberBiayaLainRows();
  }
  clearOverpaidUiState();
  updateTotal();
}

/* ── Cari Siswa (standalone page only) ────── */
function cariSiswa() {
  const nis = document.getElementById('no-induk')?.value?.trim();
  if (!nis) return;
  // In the PHP version, search is via dropdown. This is fallback.
  alert('Silakan gunakan dropdown "Pilih Siswa" untuk memilih siswa.');
}

/* ── Auto-hide Flash Messages ────────────── */
function autoHideFlash() {
  const flash = document.getElementById('flash-msg');
  if (flash) {
    setTimeout(() => {
      flash.style.transition = 'opacity 0.5s ease';
      flash.style.opacity = '0';
      setTimeout(() => flash.remove(), 500);
    }, 4000);
  }
}

/* ── Table Filter (client-side) ──────────── */
function filterTable() {
  const query  = (document.getElementById('search-lihat')?.value || '').toLowerCase();
  const rows   = document.querySelectorAll('#tbl-lihat tbody tr');
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(query) ? '' : 'none';
  });
}

/* ── localStorage data store (fallback tab) */
let dataStore = JSON.parse(localStorage.getItem('spp_data') || '[]');

function renderLihatTable() {
  const tbody   = document.getElementById('tbl-lihat-body');
  const emptyEl = document.getElementById('empty-lihat');
  if (!tbody) return;

  if (dataStore.length === 0) {
    tbody.innerHTML = '';
    if (emptyEl) emptyEl.style.display = 'flex';
    return;
  }
  if (emptyEl) emptyEl.style.display = 'none';

  tbody.innerHTML = dataStore.map((row, i) => `
    <tr>
      <td>${i + 1}</td>
      <td><span class="badge-nis">${row.nis || '-'}</span></td>
      <td>${row.nama || '-'}</td>
      <td>${row.kelas || '-'}</td>
      <td>${row.bulan || '-'}</td>
      <td class="nominal">Rp ${formatRupiah(row.total)}</td>
      <td>${row.tanggal || '-'}</td>
      <td><span class="badge-count">✓</span></td>
    </tr>
  `).join('');
}

/* ── Standalone (non-PHP) functions ────────── */
function inputData() {
  const form = document.getElementById('form-bayar');
  if (!form) return;
  const siswa = document.getElementById('siswa-select');
  const nama  = siswa?.options[siswa?.selectedIndex]?.text || 'Siswa';
  const nis   = document.getElementById('disp-nis')?.value || '-';
  const kelas = document.getElementById('disp-kelas')?.value || '-';
  const bulan = document.getElementById('bulan-bayar')?.value || '-';
  const total = parseFloat(document.getElementById('hidden-total')?.value || 0);
  const tgl   = document.getElementById('tgl-bayar')?.value || new Date().toLocaleDateString('id-ID');

  const entry = { nama, nis, kelas, bulan, total, tanggal: tgl };
  dataStore.push(entry);
  localStorage.setItem('spp_data', JSON.stringify(dataStore));

  showToast('✓', 'Data pembayaran berhasil disimpan!', 'success');
  resetForm();
  form.reset();
  updateTotal();
}

function editData()  { showToast('✏', 'Mode edit — pilih data dari tab Lihat terlebih dahulu.', 'info'); }
function hapusData() { showModal('Konfirmasi Hapus', 'Yakin ingin menghapus data ini?'); }
function keluarForm() { if (confirm('Keluar dari form?')) window.location.href = '../dashboard.php'; }
function cetakLaporan() { window.print(); }

/* ── Toast ───────────────────────────────── */
function showToast(icon, msg, type = 'success') {
  const toast   = document.getElementById('toast');
  const iconEl  = document.getElementById('toast-icon');
  const msgEl   = document.getElementById('toast-msg');
  if (!toast) return;

  iconEl.textContent = icon;
  msgEl.textContent  = msg;
  toast.className    = 'toast toast-' + type + ' show';
  setTimeout(() => toast.classList.remove('show'), 3500);
}

/* ── Modal ───────────────────────────────── */
function showModal(title, body) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').textContent  = body;
  document.getElementById('modal-overlay').classList.add('show');
}
function closeModal() {
  document.getElementById('modal-overlay')?.classList.remove('show');
}
function konfirmasiHapus() {
  closeModal();
  showToast('🗑', 'Data berhasil dihapus!', 'success');
}

// Close modal on overlay click
document.addEventListener('click', function (e) {
  const overlay = document.getElementById('modal-overlay');
  if (e.target === overlay) closeModal();
});
