// Browser smoke against an isolated PHP server. Install playwright-core outside the
// repository and pass its absolute module path through SPP_PLAYWRIGHT_CORE.
const assert = require('node:assert/strict');
const fs = require('node:fs');

if (process.env.SPP_TEST_ALLOW_MUTATION !== '1'
    || !/^db_spp_(audit|test)_/.test(process.env.SPP_DB_NAME || '')) {
  throw new Error('Browser smoke hanya boleh memakai database latihan dan flag tes.');
}

const base = process.env.SPP_TEST_BASE_URL || '';
const parsed = new URL(base);
if (!['127.0.0.1', 'localhost'].includes(parsed.hostname)
    || !['http:', 'https:'].includes(parsed.protocol)) {
  throw new Error('Browser smoke hanya boleh memakai server lokal.');
}

const passwordPath = process.env.SPP_TEST_ADMIN_PASSWORD_FILE;
if (!passwordPath) throw new Error('SPP_TEST_ADMIN_PASSWORD_FILE wajib diisi.');
const password = fs.readFileSync(passwordPath, 'utf8').trim();
if (!password) throw new Error('Kata sandi tes kosong.');
const { chromium } = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');

async function verifyFixture(page, path) {
  await page.goto(new URL(path, base).href, { waitUntil: 'load' });
  await page.waitForFunction(() => document.title.startsWith('DU_SELECTOR_'));
  assert.equal(await page.title(), 'DU_SELECTOR_OK');
}

async function pickStudent(page, predicate) {
  const candidate = await page.locator('#siswa-list option').evaluateAll((options, kind) => {
    for (const option of options) {
      const bills = JSON.parse(option.dataset.duBills || '[]');
      if (kind === 'open' && bills.some(b => Number(b.sisa) > 0 && !b.is_arrear)) return option.value;
      if (kind === 'settled' && bills.length && bills.every(b => Number(b.sisa) <= 0)) return option.value;
    }
    return null;
  }, predicate);
  assert.ok(candidate, `Tidak ada fixture siswa untuk ${predicate}.`);
  await page.locator('#siswa-search').fill(candidate);
  await page.waitForFunction(() => !!document.querySelector('#disp-nis')?.value);
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    await verifyFixture(page, '/tests/du_selector_browser_smoke.html');
    await verifyFixture(page, '/tests/du_selector_browser_smoke.html?theme=dark');

    await page.goto(new URL('/login.php', base).href);
    await page.locator('#username').fill(process.env.SPP_TEST_ADMIN_USER || 'admin');
    await page.locator('#password').fill(password);
    await page.locator('#btn-login').click();
    await page.waitForURL(url => !url.pathname.endsWith('/login.php'));

    await page.goto(new URL('/pembayaran/form.php', base).href);
    assert.match(await page.title(), /Input Pembayaran/);
    assert.equal(await page.locator('#du-selector-trigger').isVisible(), true);
    await page.locator('#du-selector-trigger').click();
    assert.equal(await page.locator('#du-selector-menu').isVisible(), true);
    assert.match(await page.locator('#du-selector-menu').innerText(), /Pilih siswa/);
    await page.locator('#du-selector-trigger').click();

    await pickStudent(page, 'open');
    assert.equal(await page.locator('#du-selector-trigger').isVisible(), true);
    assert.equal(await page.locator('#du-arrear-warning').isVisible(), false);
    await page.locator('#du-selector-trigger').click();
    assert.ok(await page.locator('#du-selector-menu .du-selector-option').count() >= 1);
    await page.locator('#du-selector-trigger').click();
    assert.equal(await page.locator('#du-input').getAttribute('readonly'), null);

    await pickStudent(page, 'settled');
    assert.equal(await page.locator('#du-selector-trigger').isVisible(), true);
    assert.notEqual(await page.locator('#du-input').getAttribute('readonly'), null);

    const editId = Number(process.env.SPP_BROWSER_EDIT_ID || 0);
    if (!Number.isSafeInteger(editId) || editId <= 0) {
      throw new Error('SPP_BROWSER_EDIT_ID wajib menunjuk transaksi latihan yang ada.');
    }
    await page.goto(new URL(`/pembayaran/edit.php?id=${editId}`, base).href);
    assert.match(await page.title(), /Edit Pembayaran/);
    assert.equal(await page.locator('#du-selector-trigger').isVisible(), true);
    await page.locator('#du-selector-trigger').click();
    assert.equal(await page.locator('#du-selector-menu').isVisible(), true);
    assert.ok(await page.locator('#du-selector-menu').innerText());

    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('#du-selector-trigger').click();
    await page.locator('#du-selector-trigger').click();
    assert.equal(await page.locator('#du-selector-menu').isVisible(), true);

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(new URL('/siswa/daftar.php', base).href);
    assert.match(await page.title(), /Data Siswa/);
    assert.equal(await page.locator('#form-master-siswa').isVisible(), true);

    await page.goto(new URL('/master_kelas.php', base).href);
    assert.match(await page.title(), /Master Kelas/);
    assert.equal(await page.getByText('Proses Tahun Ajaran', { exact: true }).isVisible(), true);

    await page.goto(new URL('/laporan/template.php?template=per-item', base).href);
    assert.equal(await page.locator('select[name="siswa_status"]').isVisible(), true);
    await page.locator('select[name="siswa_status"]').selectOption('archived');
    await page.getByRole('button', { name: 'Tampilkan Rekap' }).click();
    await page.waitForURL(url => url.searchParams.get('siswa_status') === 'archived');
    assert.equal(await page.locator('select[name="siswa_status"]').inputValue(), 'archived');

    assert.deepEqual(errors, [], `JavaScript error di browser: ${errors.join('; ')}`);
    console.log('OK: Chrome dropdown Daftar Ulang, edit, viewport ponsel, Data Siswa, Proses Tahun Ajaran, dan filter Per Item.');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error.stack || String(error));
  process.exitCode = 1;
});
