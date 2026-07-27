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
      found = true;
      break;
    }
  }
  
  if (!found) {
    document.getElementById('disp-nis').value = '';
    document.getElementById('disp-nama').value = '';
    document.getElementById('disp-kelas').value = '';
  }
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

// Auto-fill on page load (edit page) & bind number formatting
document.addEventListener('DOMContentLoaded', function () {
  const sel = document.getElementById('siswa-select');
  if (sel && sel.value) pilihSiswa(sel);

  // Set today's date if empty
  const tgl = document.getElementById('tgl-bayar');
  if (tgl && !tgl.value) {
    tgl.value = new Date().toISOString().split('T')[0];
  }

  // Format all numeric fields dynamically
  const numericInputs = document.querySelectorAll(
    '.tbl-input, #potongan-spp, #tab-wajib, #kewajiban-spp, [id^="ll-nom-"]'
  );
  
  numericInputs.forEach(input => {
    // Format initial value if loaded
    if (input.value && input.value !== '0') {
      input.value = formatRupiahString(input.value);
    }
    
    // Live format as they type
    input.addEventListener('input', function () {
      let cursorPosition = this.selectionStart;
      const originalLength = this.value.length;
      
      const cleanVal = this.value.replace(/\D/g, '');
      if (cleanVal === '') {
        this.value = '0';
      } else {
        this.value = parseInt(cleanVal, 10).toLocaleString('id-ID');
      }
      
      // Adjust cursor position
      const newLength = this.value.length;
      cursorPosition = cursorPosition + (newLength - originalLength);
      if (this.setSelectionRange) {
        this.setSelectionRange(cursorPosition, cursorPosition);
      }
      
      // Trigger logic
      if (this.id.endsWith('-total') || this.id.endsWith('-bayar')) {
        const key = this.id.split('-')[0];
        hitungSisa(key);
      }
      updateTotal();
    });
  });

  // Clean all formatted numeric inputs right before form submission so the backend gets raw numbers
  const form = document.getElementById('form-bayar');
  if (form) {
    form.addEventListener('submit', function () {
      numericInputs.forEach(input => {
        input.value = input.value.replace(/\./g, '');
      });
    });
  }

  updateTotal();
  autoHideFlash();
});

/* ── Hitung Sisa ─────────────────────────── */
function hitungSisa(key) {
  const total  = parseNumber(document.getElementById(key + '-total')?.value  || 0);
  const bayar  = parseNumber(document.getElementById(key + '-bayar')?.value  || 0);
  const sisaEl = document.getElementById(key + '-sisa');
  if (sisaEl) sisaEl.value = formatRupiahString(Math.max(0, total - bayar));
}

/* ── Update Total ────────────────────────── */
function updateTotal() {
  const ids = ['pangkal-input','bangunan-input','seragam-input','kegiatan-input','spp-input','makan-input','sorga-input','infaq-input','lain-input','du-input',
               'll-nom-1','ll-nom-2','ll-nom-3','ll-nom-4'];
  let total = 0;
  ids.forEach(id => {
    const el = document.getElementById(id);
    if (el) total += parseNumber(el.value || 0);
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
  const totalEl = document.getElementById('totalJumlah');
  if (totalEl) totalEl.textContent = 'Rp 0';
  const hiddenEl = document.getElementById('hidden-total');
  if (hiddenEl) hiddenEl.value = 0;

  ['pangkal','bangunan','seragam','kegiatan','spp','makan','sorga','infaq','lain','du'].forEach(k => {
    ['total','bayar','sisa','input'].forEach(s => {
      const el = document.getElementById(k + '-' + s);
      if (el) el.value = '0';
    });
  });
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
