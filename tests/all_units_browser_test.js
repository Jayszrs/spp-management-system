const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const db=process.env.SPP_DB_NAME||'',base=new URL(process.env.SPP_TEST_BASE_URL||'');
assert.match(db,/^db_spp_audit_[a-z0-9_]+$/);assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.ok(['127.0.0.1','localhost'].includes(base.hostname));
const ids=JSON.parse(fs.readFileSync(process.env.SPP_UI_IDS_FILE,'utf8'));
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const page=await browser.newPage({viewport:{width:1440,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 assert.equal((await(await page.request.get(new URL('/tests/browser_clone_identity.php',base).href)).json()).database,db);
 await page.goto(new URL('/login.php',base).href);await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await page.locator('#btn-login').click();await page.waitForURL('**/dashboard.php');
 for(const unit of [1,2,3]){
  await page.goto(new URL('/dashboard.php',base).href);await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
  for(const route of ['/pembayaran/form.php','/tabungan/masuk.php','/tabungan/keluar.php','/otorisasi_transaksi.php']){await page.goto(new URL(route,base).href);assert.equal(await page.locator('#sidebar-unit-select option[value="0"]').count(),0);assert.equal(await page.locator('#sidebar-unit-select').inputValue(),String(unit));}
 }
 await page.goto(new URL('/laporan/global.php',base).href);
 // The legacy report control now updates the sidebar session through CSRF POST.
 await Promise.all([page.waitForNavigation(),page.locator('select[name="unit"]').selectOption('all')]);
 assert.equal(await page.locator('#sidebar-unit-select').inputValue(),'0');
 await page.goto(new URL('/laporan/template.php?template=tunggakan-siswa',base).href);
 for(const unit of ['SD','SMP','SMA']){
  await page.goto(new URL('/laporan/template.php?template=tunggakan-siswa',base).href);
  const row=page.locator('tr[data-principal-row]').filter({has:page.locator('td[data-label="Kelas/Rombel"] strong',{hasText:new RegExp('^'+unit+' · ')})}).first();
  const expected=parseInt(await row.locator('td[data-label="Siswa Menunggak"]').innerText(),10);
  await row.locator('a.principal-row-action').click();
  assert.equal(await page.locator('tr[data-principal-row]').count(),expected,'Principal detail lost students for '+unit);
  assert.match(await page.locator('.principal-page-header h1').innerText(),new RegExp(unit+' · '));
  assert.ok((await page.locator('#principal-class option').count())===0,'Detail unexpectedly loaded summary');
 }
 for(const route of ['/siswa/daftar.php','/master_kelas.php','/master_spp.php','/master_daftar_ulang.php','/master_biaya_lain.php','/pembayaran/lihat.php','/pembayaran/riwayat_daftar_ulang.php','/tabungan/riwayat.php','/tabungan/cetak.php','/role_management.php','/laporan/global.php']){
  const response=await page.goto(new URL(route,base).href);assert.equal(response.status(),200);await page.waitForLoadState('networkidle');assert.equal(await page.locator('#sidebar-unit-select').inputValue(),'0');assert.equal(await page.locator('html').getAttribute('data-palette'),'super');
  assert.doesNotMatch(await page.content(),/Fatal error|Warning:|Gagal memuat laporan/);
  assert.equal(await page.locator('form[method="post" i]:not(.sidebar-unit-form)').evaluateAll(forms=>forms.filter(f=>!f.action.includes('logout.php')&&getComputedStyle(f).display!=='none').length),0);
 }
 for(const route of ['/pembayaran/form.php',`/pembayaran/edit.php?id=${ids[1].id}`,'/tabungan/masuk.php','/tabungan/keluar.php','/otorisasi_transaksi.php']){
  await page.goto(new URL(route,base).href);assert.equal(await page.locator('#sidebar-unit-select option[value="0"]').count(),0);assert.equal(await page.locator('#sidebar-unit-select').inputValue(),'');assert.equal(await page.locator('#form-bayar,#form-tabungan').count(),0);
 }
 await page.goto(new URL('/dashboard.php',base).href);
 for(const unit of [1,2,3]){
  const receipt=await page.request.get(new URL('/laporan/cetak_struk.php?id='+ids[unit].id,base).href);assert.equal(receipt.status(),200);assert.ok((await receipt.text()).includes({1:'SEKOLAH DASAR',2:'SEKOLAH MENENGAH PERTAMA',3:'SEKOLAH MENENGAH ATAS'}[unit]));
  const book=await page.request.get(new URL('/tabungan/cetak_buku.php?nis='+ids[unit].NO_INDUK,base).href);assert.equal(book.status(),200);assert.ok((await book.text()).includes('Unit '+{1:'SD',2:'SMP',3:'SMA'}[unit]));
 }
 await page.goto(new URL('/pembayaran/form.php',base).href);await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('2')]);assert.equal(await page.locator('#form-bayar').count(),1);
 await page.locator('#du-selector-trigger').click();assert.equal(await page.locator('#du-selector-menu').isVisible(),true);
 await page.goto(new URL('/dashboard.php',base).href);
 await Promise.all([page.waitForNavigation(),page.locator('.dashboard-scope-option',{hasText:'Semua Unit'}).click()]);
 assert.equal(await page.locator('#sidebar-unit-select').inputValue(),'0','Dashboard scope did not persist');
 assert.deepEqual(errors,[]);console.log('OK: all-unit persistence, read-only controls, explicit transaction choice, receipts/books and DU dropdown');
}finally{await browser.close()}})().catch(e=>{console.error(e.stack);process.exitCode=1});
