// Exercises the visible promotion controls against a prepared disposable clone.
const assert = require('node:assert/strict');
const fs = require('node:fs');

if (process.env.SPP_TEST_ALLOW_MUTATION !== '1'
    || !/^db_spp_audit_[a-z0-9_]+$/.test(process.env.SPP_DB_NAME || '')) {
  throw new Error('Tes browser kenaikan hanya boleh memakai clone audit dan flag tes.');
}
const base = new URL(process.env.SPP_TEST_BASE_URL || '');
if (base.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(base.hostname)) {
  throw new Error('SPP_TEST_BASE_URL harus menunjuk server HTTP lokal.');
}
const passwordFile = process.env.SPP_TEST_ADMIN_PASSWORD_FILE || '';
if (!passwordFile || !fs.existsSync(passwordFile)) throw new Error('File sandi admin latihan wajib tersedia.');
const password = fs.readFileSync(passwordFile, 'utf8').trim();
if (!password) throw new Error('Sandi admin latihan kosong.');
const { chromium } = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');

const grade6 = '9988000001';
const grade5First = '9988000002';
const grade5Second = '9988000003';

async function stage(page, expected, remaining) {
  assert.equal((await page.locator('.promotion-stage-overview strong').first().innerText()).trim(), expected);
  assert.equal(await page.locator('input[name="selected_students[]"]').count(), remaining);
}

async function submitStudent(page, nis, targetLabel = '') {
  const row = page.locator('.promotion-student-row').filter({
    has: page.locator(`input[name="selected_students[]"][value="${nis}"]`),
  });
  assert.equal(await row.count(), 1, `Siswa ${nis} tidak ada pada tahap yang ditampilkan.`);
  await row.locator('input[name="selected_students[]"]').check({ force: true });
  if (targetLabel) await row.locator('select[name^="target_master_kelas_id"]').selectOption({ label: targetLabel });
  assert.equal(await page.locator('#promotion-submit-button').isEnabled(), true);
  await page.locator('#promotion-submit-button').click();
  await page.waitForLoadState('domcontentloaded');
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const context = await browser.newContext({
      viewport: { width: 1440, height: 900 },
      extraHTTPHeaders: { 'X-SPP-Test-Current-Year': process.env.SPP_TEST_PROMOTION_SOURCE_YEAR || '2098/2099' },
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());

    const identity = await page.request.get(new URL('/tests/browser_clone_identity.php', base).href);
    assert.equal(identity.status(), 200, 'Server browser tidak terverifikasi sebagai clone latihan.');
    assert.equal((await identity.json()).database, process.env.SPP_DB_NAME,
      'Server browser terhubung ke database yang berbeda dari clone yang diminta.');

    await page.goto(new URL('/login.php', base).href);
    await page.locator('#username').fill('admin');
    await page.locator('#password').fill(password);
    await page.locator('#btn-login').click();
    await page.waitForURL(url => !url.pathname.endsWith('/login.php'));

    const promotionUrl = new URL('/master_kelas.php', base).href;
    await page.goto(promotionUrl);
    await stage(page, 'Kelulusan Kelas 6', 1);
    const staleGraduation = await context.newPage();
    staleGraduation.on('dialog', dialog => dialog.accept());
    await staleGraduation.goto(promotionUrl);

    await submitStudent(page, grade6);
    await stage(page, 'Kenaikan Kelas 5', 2);
    await submitStudent(staleGraduation, grade6);
    assert.match(await staleGraduation.locator('#flash-msg').innerText(), /tahap aktif sudah berubah/i);
    await stage(staleGraduation, 'Kenaikan Kelas 5', 2);
    await staleGraduation.close();

    const stalePromotion = await context.newPage();
    stalePromotion.on('dialog', dialog => dialog.accept());
    await stalePromotion.goto(promotionUrl);
    await stage(stalePromotion, 'Kenaikan Kelas 5', 2);

    await submitStudent(page, grade5First, '6A');
    await stage(page, 'Kenaikan Kelas 5', 1);
    await submitStudent(stalePromotion, grade5First, '6A');
    assert.match(await stalePromotion.locator('.promotion-batch-result').innerText(), /0 berhasil/i);
    await stage(stalePromotion, 'Kenaikan Kelas 5', 1);
    await stalePromotion.close();

    await submitStudent(page, grade5Second, '6A');
    assert.equal(await page.locator('#promotion-batch-form').count(), 0);
    assert.match(await page.locator('.master-promotion-actions').innerText(), /tidak ada siswa reguler aktif yang perlu diproses/i);
    assert.deepEqual(errors, [], `JavaScript error: ${errors.join('; ')}`);
    console.log('OK: Chrome meluluskan kelas 6, menaikkan dua siswa kelas 5 terpisah, dan menolak dua halaman lama.');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error.stack || String(error));
  process.exitCode = 1;
});
