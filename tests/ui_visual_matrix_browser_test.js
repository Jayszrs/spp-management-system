// Read-only page matrix on a disposable clone. Screenshots compare the current DOM
// with the original 7647608 stylesheet, retaining the secure logout button styling.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { execFileSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const { referenceCss } = require('./ui_css_reference');
const { PNG } = require(path.join(process.env.SPP_CSS_TOOLS, 'pngjs'));
const { chromium } = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');

const database = process.env.SPP_DB_NAME || '';
const base = new URL(process.env.SPP_TEST_BASE_URL || '');
assert.match(database, /^db_spp_audit_[a-z0-9_]+$/);
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION, '1');
assert.ok(['127.0.0.1','localhost'].includes(base.hostname) && base.protocol === 'http:');
const output = process.env.SPP_UI_OUTPUT;
assert.ok(output, 'SPP_UI_OUTPUT is required');
fs.mkdirSync(output, { recursive: true });
const ids = JSON.parse(fs.readFileSync(process.env.SPP_UI_IDS_FILE, 'utf8'));
const originalCss = execFileSync('git', ['show','7647608:assets/css/style.css'], {cwd:path.join(__dirname,'..'),encoding:'utf8',maxBuffer:8*1024*1024})
  + '\n.logout-btn{border:0;background:none;font:inherit;cursor:pointer}';
const retainedCss = referenceCss();
const stableStyle = '*::before,*::after,*{animation:none!important;transition:none!important;caret-color:transparent!important}';
const menu = ['dashboard.php','pembayaran/form.php','pembayaran/lihat.php','pembayaran/riwayat_daftar_ulang.php',
 'otorisasi_transaksi.php','siswa/daftar.php','master_kelas.php','master_spp.php','master_biaya_lain.php',
 'master_daftar_ulang.php','tabungan/masuk.php','tabungan/keluar.php','tabungan/riwayat.php','tabungan/cetak.php',
 'laporan/index.php','laporan/global.php','laporan/surat_laporan.php','role_management.php'];
const templates=['status','penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan','riwayat-tagihan','setoran','kas-tabungan','tunggakan-siswa'];
const viewports=[{width:1440,height:900},{width:2560,height:1440},{width:390,height:844}];
const results=[];
const failures=[];

async function ready(page) {
 await page.waitForLoadState('networkidle');
 await page.evaluate(async()=>{await document.fonts.ready;const clock=document.getElementById('liveClock');if(clock){clock.id='ui-frozen-clock';clock.textContent='12.00.00';}});
 await page.addStyleTag({content:stableStyle});
 await page.evaluate(()=>window.scrollTo(0,0));
}
async function compare(page,name,settings) {
 await ready(page);
 // Rebuild the layout before both shots. Chromium otherwise reuses fractional
 // collapsed-table border caches for the linked sheet but recalculates them
 // when the reference sheet is inserted, creating a false visual difference.
 async function layoutForScreenshot(){await page.evaluate(async()=>{const root=document.documentElement;const display=root.style.display;root.style.display='none';void root.offsetWidth;root.style.display=display;void root.offsetWidth;await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));window.scrollTo(0,0)});}
 await layoutForScreenshot();
 const metrics=await page.evaluate(()=>{
  const rules=[];function visit(list){for(const r of list){if(r.selectorText)rules.push(r.selectorText);if(r.cssRules)visit(r.cssRules);}}
  for(const sheet of document.styleSheets){if(sheet.href?.includes('/assets/css/style.css'))visit(sheet.cssRules);}
  const panel=document.querySelector('.sidebar-unit-panel');const logo=document.querySelector('.savings-print-visual-border img');
  return {palette:document.documentElement.dataset.palette,theme:document.documentElement.dataset.theme,
   panelPadding:panel?getComputedStyle(panel).padding:null,logoWidth:logo?parseFloat(getComputedStyle(logo).width):null,
   rules:rules.length,tail:rules.some(s=>s.includes('.principal-list-shell')),
   overflow:document.documentElement.scrollWidth>innerWidth+1};
 });
 assert.equal(metrics.palette,settings.palette, name+' palette');
 assert.equal(metrics.theme,settings.theme,name+' theme');
 assert.ok(metrics.rules>1500 && metrics.tail,name+' CSS tail rules missing');
 assert.notEqual(metrics.panelPadding,'0px',name+' unit selector is unstyled');
 if(metrics.logoWidth!==null)assert.ok(metrics.logoWidth<=40,name+' oversized savings logo');
 const routeSlug=name.replace(/[^a-z0-9]+/gi,'-');
 const shortSlug=routeSlug.length>120?routeSlug.slice(0,80)+'-'+createHash('sha256').update(name).digest('hex').slice(0,12):routeSlug;
 const slug=`${settings.palette}-${settings.theme}-${settings.width}-${shortSlug}`;
 const geometry=page=>page.evaluate(()=>[...document.querySelectorAll('table tr,table .kelas-badge,table .badge-nis')].slice(0,30).map(n=>({tag:n.tagName,text:n.textContent.trim(),rect:n.getBoundingClientRect().toJSON(),appearance:Object.fromEntries(['font','color','background-color','border-color','line-height'].map(key=>[key,getComputedStyle(n).getPropertyValue(key)]))})));
 const actualGeometry=await geometry(page);
 const actual=await page.screenshot({path:path.join(output,slug+'-actual.png')});
 await page.evaluate(css=>{
  const link=[...document.querySelectorAll('link[rel="stylesheet"]')].find(l=>l.href.includes('/assets/css/style.css'));
  const style=document.createElement('style');style.textContent=css;link.before(style);link.disabled=true;
 },settings.palette==='super'?retainedCss:originalCss);
 await page.evaluate(async()=>{await document.fonts.ready;window.scrollTo(0,0);await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));window.scrollTo(0,0)});
 await layoutForScreenshot();
 const reference=await page.screenshot({path:path.join(output,slug+'-reference.png')});
 const a=PNG.sync.read(actual),b=PNG.sync.read(reference);
 assert.equal(a.width,b.width);assert.equal(a.height,b.height);
 const {default:pixelmatch}=await import(pathToFileURL(path.join(process.env.SPP_CSS_TOOLS,'pixelmatch/index.js')).href);
 const diff=new PNG({width:a.width,height:a.height});
 const mismatched=pixelmatch(a.data,b.data,diff.data,a.width,a.height,{threshold:0.1});
 const fraction=mismatched/(a.width*a.height);
 if(fraction>0.0001){
  fs.writeFileSync(path.join(output,slug+'-diff.png'),PNG.sync.write(diff));
  const positions={actual:actualGeometry,reference:await geometry(page)};
  fs.writeFileSync(path.join(output,slug+'-geometry.json'),JSON.stringify(positions,null,2));
  throw new Error(`${slug}: reference mismatch ${mismatched} pixels (${(fraction*100).toFixed(4)}%)`);
 }
 results.push({page:name,...settings,...metrics,mismatched});
}

(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const context=await browser.newContext();
  const page=await context.newPage();
  const identity=await(await page.request.get(new URL('/tests/browser_clone_identity.php',base).href)).json();
  assert.equal(identity.database,database,'Wrong HTTP database');
  // Prove browser CSSOM retained every rule of the parsed design reference.
  await page.goto(new URL('/login.php',base).href);
  const css=fs.readFileSync(path.join(__dirname,'../assets/css/style.css'),'utf8');
  const cssom=await page.evaluate(({css,ref})=>{
   function canonical(source){const s=new CSSStyleSheet();s.replaceSync(source);return [...s.cssRules].map(r=>r.cssText);}
   return {actual:canonical(css),expected:canonical(ref)};
  },{css,ref:retainedCss});
  assert.deepEqual(cssom.actual,cssom.expected,'Browser CSSOM differs from retained reference');
  await page.locator('#username').fill(process.env.SPP_TEST_ADMIN_USER||'superadmin');
  await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());
  await page.locator('#btn-login').click();await page.waitForURL('**/dashboard.php');
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const unitCases=process.env.SPP_UI_UNITS?process.env.SPP_UI_UNITS.split(',').map(Number):[0,1,2,3];
  for(const unit of unitCases){
   await page.setViewportSize(viewports[0]);await page.goto(new URL('/dashboard.php',base).href);
   await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
   const palette={0:'super',1:'sd',2:'smp',3:'sma'}[unit];
   await page.goto(new URL('/laporan/template.php?template=tunggakan-siswa',base).href);
   const detailLink=page.locator('a.principal-row-action').first();
   const detailRoute=await detailLink.count()?'laporan/'+await detailLink.getAttribute('href'):'laporan/template.php?template=tunggakan-siswa&view=detail';
   for(const theme of ['light','dark']){
    await page.evaluate(value=>localStorage.setItem('spp_theme',value),theme);
    for(const viewport of viewports.filter(v=>!process.env.SPP_UI_WIDTHS||process.env.SPP_UI_WIDTHS.split(',').includes(String(v.width)))){
     await page.setViewportSize(viewport);
     const routes=[...menu,`pembayaran/edit.php?id=${ids[unit || 1].id}`,...templates.map(id=>'laporan/template.php?template='+id),
      detailRoute,'laporan/template.php?template=tunggakan-siswa&view=preview','laporan/surat_orang_tua.php'];
     for(const route of routes.filter(r=>!process.env.SPP_UI_ROUTE_MATCH||process.env.SPP_UI_ROUTE_MATCH.split(',').some(match=>r.includes(match)))){
      try{const response=await page.goto(new URL('/'+route,base).href);assert.equal(response.status(),200,route+' HTTP');
       assert.ok(!page.url().includes('login.php'),route+' login redirect');
       await compare(page,route,{palette,theme,width:viewport.width});
      }catch(e){failures.push({route,palette,theme,width:viewport.width,error:e.message});}
     }
     console.log(`MATRIX ${palette}/${theme}/${viewport.width}: ${results.length} passed, ${failures.length} failed`);
     fs.writeFileSync(path.join(output,'matrix.json'),JSON.stringify({results,failures,errors},null,2));
    }
   }
  }
  assert.deepEqual(errors,[],'JavaScript page errors');
  assert.deepEqual(failures,[],'Visual reference failures; see matrix.json');
  console.log(`OK: ${results.length} visual comparisons for selected cases and browser CSSOM`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
