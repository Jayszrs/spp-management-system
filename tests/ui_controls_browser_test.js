const assert=require('node:assert/strict');
const fs=require('node:fs');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=new URL(process.env.SPP_TEST_BASE_URL||'');
const db=process.env.SPP_DB_NAME||'';
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(db,/^db_spp_audit_[a-z0-9_]+$/);
assert.ok(base.protocol==='http:'&&['127.0.0.1','localhost'].includes(base.hostname));
const ids=JSON.parse(fs.readFileSync(process.env.SPP_UI_IDS_FILE,'utf8'));
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:900}});
  const identity=await(await page.request.get(new URL('/tests/browser_clone_identity.php',base).href)).json();assert.equal(identity.database,db);
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(new URL('/login.php',base).href);
  await page.locator('#username').fill('superadmin');
  await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());
  await page.locator('#btn-login').click();await page.waitForURL('**/dashboard.php');
  for(const unit of [1,2,3]){
   await page.goto(new URL('/dashboard.php',base).href);
   await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
   assert.equal(await page.locator('html').getAttribute('data-palette'),{1:'sd',2:'smp',3:'sma'}[unit]);
   for(const theme of ['dark','light']){
    await page.locator('#btn-theme-toggle').click();
    assert.equal(await page.locator('html').getAttribute('data-theme'),theme);
   }
   await page.locator('#btn-sidebar-toggle').click();assert.ok(await page.locator('#sidebar').evaluate(e=>e.classList.contains('collapsed')));
   await page.locator('#btn-sidebar-toggle').click();
   await page.setViewportSize({width:390,height:844});
   await page.locator('#btn-sidebar-toggle').click();assert.ok(await page.locator('#sidebar').evaluate(e=>e.classList.contains('open')));
   await page.locator('.sidebar-backdrop').click({position:{x:380,y:200}});assert.equal(await page.locator('#sidebar').evaluate(e=>e.classList.contains('open')),false);
   await page.setViewportSize({width:1440,height:900});
   for(const route of ['/pembayaran/form.php',`/pembayaran/edit.php?id=${ids[unit].id}`]){
    await page.goto(new URL(route,base).href);await page.waitForLoadState('networkidle');
    await page.locator('#du-selector-trigger').click();assert.equal(await page.locator('#du-selector-menu').isVisible(),true);
    await page.locator('#du-selector-trigger').click();assert.equal(await page.locator('#du-selector-menu').isVisible(),false);
    const offsets=await page.locator('#du-selector-trigger').evaluate(e=>{
     const arrow=e.querySelector('svg');if(!arrow)return null;const a=arrow.getBoundingClientRect(),b=e.getBoundingClientRect();return Math.abs(a.y+a.height/2-b.y-b.height/2);
    });
    assert.ok(offsets!==null&&offsets<=2,'DU chevron must stay centered');
   }
   await page.goto(new URL('/laporan/index.php',base).href);
   await page.getByRole('button',{name:'Tampilkan Rekap',exact:true}).click();await page.waitForLoadState('networkidle');
   assert.equal(await page.locator('.report-general-shell').count(),1);
   await page.goto(new URL('/laporan/template.php?template=per-item',base).href);
   const scroll=page.locator('.report-template-table-wrap').first();
   if(await scroll.count()){await scroll.evaluate(e=>{e.scrollLeft=e.scrollWidth;});}
   for(const format of ['excel','pdf','preview']){
    const url=new URL('/laporan/export_global.php?template=status&format='+format,base);
    const response=await page.request.get(url.href);assert.equal(response.status(),200);
    const body=await response.body();assert.ok(body.length>100);
    if(format==='pdf')assert.equal(body.subarray(0,5).toString(),'%PDF-');
    else assert.ok(body.toString().includes('<'),'Expected HTML export/preview');
   }
   await page.goto(new URL('/tabungan/cetak.php',base).href);
   const first=await page.locator('#savings-print-list option').first().getAttribute('value');
   await page.locator('#savings-print-search').fill(first);
   await page.locator('.student-search-option').first().click();
   await page.waitForFunction(()=>!document.getElementById('savings-print-action').disabled);
   const preview=await page.request.get(new URL('/tabungan/cetak_buku.php?nis='+encodeURIComponent(ids[unit].NO_INDUK),base).href);
   assert.equal(preview.status(),200);assert.ok((await preview.text()).includes('<iframe'));
   const book=await page.request.get(new URL('/tabungan/cetak_buku.php?output=pdf&nis='+encodeURIComponent(ids[unit].NO_INDUK),base).href);
   assert.equal(book.status(),200);assert.equal((await book.body()).subarray(0,5).toString(),'%PDF-');
   await page.goto(new URL('/laporan/template.php?template=tunggakan-siswa&view=preview',base).href);
   assert.equal(await page.locator('.principal-preview-frame').count(),1);
   console.log(`OK: unit ${unit} switching, themes, sidebar, DU input/edit, filters/scroll, Excel/PDF/preview and savings book`);
  }
  assert.deepEqual(errors,[]);console.log('OK: browser controls across SD/SMP/SMA, no JavaScript errors');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
