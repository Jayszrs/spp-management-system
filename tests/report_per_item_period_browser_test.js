// Browser check for category changes on the Per Item report. Use a local clone.
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');

if (process.env.SPP_TEST_ALLOW_MUTATION !== '1'
    || !/^db_spp_audit_[a-z0-9_]+$/.test(process.env.SPP_DB_NAME || '')) {
  throw new Error('Tes browser hanya untuk clone audit dengan flag tes.');
}
const base = new URL(process.env.SPP_TEST_BASE_URL || '');
if (!['localhost', '127.0.0.1'].includes(base.hostname)) {
  throw new Error('Server tes harus lokal.');
}
const php = process.env.SPP_TEST_PHP;
if (!php) throw new Error('SPP_TEST_PHP wajib menunjuk PHP CLI untuk sesi clone.');
const { chromium } = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');

(async () => {
  // Seed a CLI-only test session; this test checks report controls, not login.
  const seed = spawnSync(php, ['-r', `session_id(bin2hex(random_bytes(16))); session_start();
    $_SESSION['admin_id']=1; $_SESSION['admin_role']='super_admin';
    $_SESSION['active_unit_id']=1; session_write_close();
    echo session_name().'='.session_id();`], { encoding: 'utf8', env: process.env });
  if (seed.status !== 0) throw new Error(`Sesi clone gagal dibuat: ${seed.stderr}`);
  const match = /^([A-Za-z0-9_-]+)=([a-f0-9]{32})$/.exec(seed.stdout.trim());
  if (!match) throw new Error('Identitas sesi tes tidak valid.');
  const [, sessionName, sessionId] = match;
  let browser;
  try {
    browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();
    await context.addCookies([{ name: sessionName, value: sessionId, url: base.href, httpOnly: true }]);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(new URL('/laporan/template.php?template=per-item&kategori=daftar_ulang', base).href);
    const period = kind => page.locator(`[data-per-item-period="${kind}"]`).first();
    assert.equal(await period('academic-year').isVisible(), true, 'DU memerlukan pilihan tahun ajaran.');
    assert.equal(await period('date').isVisible(), false, 'DU tidak memakai tanggal kas sebagai periode tagihan.');
    assert.equal(await period('month').isVisible(), false, 'DU bukan tagihan bulanan.');

    await page.locator('[data-report-item-category]').selectOption('komite');
    assert.equal(await period('month').isVisible(), true, 'Komite memerlukan pilihan bulan tagihan.');
    assert.equal(await period('academic-year').isVisible(), false, 'Komite memakai rentang bulan tagihan.');
    assert.equal(await period('date').isVisible(), false, 'Komite tidak memakai tanggal kas sebagai periode tagihan.');

    await page.locator('[data-report-item-category]').selectOption('daftar_ulang');
    assert.equal(await period('academic-year').isVisible(), true, 'Pilihan DU harus memulihkan filter tahun ajaran.');
    await page.getByRole('button', { name: 'Tampilkan Rekap' }).click();
    await page.waitForURL(url => url.searchParams.get('kategori') === 'daftar_ulang');
    assert.equal(await period('academic-year').isVisible(), true, 'Filter DU hilang setelah submit.');
    assert.deepEqual(errors, [], `Kesalahan JavaScript: ${errors.join('; ')}`);
    console.log('PASS: browser Per Item memakai filter DU tahunan dan Komite bulanan.');
  } finally {
    if (browser) await browser.close();
    spawnSync(php, ['-r', `session_id('${sessionId}'); session_start(); session_destroy();`],
      { encoding: 'utf8', env: process.env });
  }
})().catch(error => { console.error(error.stack || String(error)); process.exitCode = 1; });
