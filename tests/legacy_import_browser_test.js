const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE);
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION, '1');
assert.equal(process.env.SPP_DB_NAME, 'db_spp_audit_legacy_backend_20261003');
const base = process.env.SPP_TEST_BASE_URL;
const artifacts = process.env.SPP_UI_ARTIFACTS;
fs.mkdirSync(artifacts, {recursive: true});
(async () => {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  try {
    const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
    const errors = [];page.on('pageerror', e => errors.push(e.message));
    assert.equal((await (await page.request.get(base + '/tests/browser_clone_identity.php')).json()).database, process.env.SPP_DB_NAME);
    assert.equal((await page.request.get(base + '/legacy_import_api.php?action=health')).status(), 403);
    await page.goto(base + '/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE, 'utf8').trim());await page.locator('#btn-login').click();await page.waitForURL('**/dashboard.php');
    await page.goto(base + '/backup_restore.php');
    await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('1')]);
    await page.locator('#tab-legacy').click();await page.locator('#legacy-unit').selectOption('SD');
    await page.locator('#legacy-file').setInputFiles({name:'invalid.sql',mimeType:'text/plain',buffer:Buffer.from('a')});assert.equal(await page.locator('#legacy-start').isDisabled(), true);
    await page.locator('#legacy-file').setInputFiles({name:'empty.dat',mimeType:'application/octet-stream',buffer:Buffer.alloc(0)});assert.match(await page.locator('#legacy-status').textContent(),/kosong/);
    const csrf = await page.locator('#legacy-config').evaluate(e => JSON.parse(e.textContent).csrf);
    assert.equal((await page.request.post(base+'/legacy_import_api.php',{form:{action:'upload',csrf_token:'wrong',unit:'1',category:'students'}})).status(),403);
    assert.equal((await page.request.post(base+'/legacy_import_api.php',{form:{action:'upload',csrf_token:csrf,unit:'2',category:'students'}})).status(),409);
    assert.equal((await page.request.get(base+'/legacy_import_api.php?action=status&id=../../x')).status(),404);
    await page.locator('#legacy-file').setInputFiles('C:/Users/lakch/Downloads/SD-5.dat');
    await page.waitForFunction(() => !document.getElementById('legacy-start').disabled);
    await page.locator('#legacy-start').click();
    assert.equal(await page.locator('#legacy-dialog').evaluate(e => e.open),true);
    await page.keyboard.press('Escape');assert.equal(await page.locator('#legacy-dialog').evaluate(e => e.open),false);
    await page.waitForFunction(() => !document.getElementById('legacy-reopen').hidden);
    await page.locator('#legacy-reopen').click();
    await page.waitForFunction(() => document.getElementById('legacy-progress-status').textContent.includes('Siap Ditinjau'),null,{timeout:180000});
    assert.match(await page.locator('#legacy-counts').textContent(),/accepted|already_imported/);
    assert.equal(await page.locator('#legacy-confirm').isDisabled(),true);
    await page.locator('#legacy-confirmation').fill('IMPOR LEGACY');await page.locator('#legacy-confirm').click();
    await page.waitForFunction(() => document.getElementById('legacy-progress-status').textContent.includes('Identitas Legacy disimpan'),null,{timeout:60000});
    await page.screenshot({path:path.join(artifacts,'sd-imported.png'),fullPage:true});await page.locator('#legacy-close').click();
    await page.goto(base+'/siswa/daftar.php?status=legacy');assert.ok((await page.locator('tbody').textContent()).includes('Legacy'));
    await page.goto(base+'/backup_restore.php');
    const matrix=[];
    for (const unit of [0,1,2,3]) {
      await page.setViewportSize({width:1440,height:1000});await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
      for(const theme of ['light','dark'])for(const width of [1440,2560,390]) {
        await page.evaluate(t => {localStorage.setItem('spp_theme',t);document.documentElement.setAttribute('data-theme',t);},theme);await page.setViewportSize({width,height:width===390?844:1100});await page.locator('#tab-legacy').click();
        const overflow=await page.evaluate(() => document.documentElement.scrollWidth>innerWidth+1);assert.equal(overflow,false,`${unit}/${theme}/${width}`);
        await page.screenshot({path:path.join(artifacts,`legacy-${unit}-${theme}-${width}.png`),fullPage:true});matrix.push({unit,theme,width,overflow});
      }
    }
    const token=await page.locator('#legacy-config').evaluate(e=>JSON.parse(e.textContent).csrf);
    await page.setViewportSize({width:1440,height:1000});await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('0')]);
    assert.equal((await page.request.post(base+'/legacy_import_api.php',{form:{action:'upload',csrf_token:token,unit:'1',category:'students'}})).status(),409);
    assert.deepEqual(errors,[]);fs.writeFileSync(path.join(artifacts,'matrix.json'),JSON.stringify(matrix,null,2));console.log('PASS: real .dat upload, worker progress, reopen, explicit apply, Legacy list, CSRF/unit/access guards and 24 visual states');
  }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
