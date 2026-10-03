const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
assert.equal(process.env.SPP_DB_NAME,'db_spp_audit_legacy_backend_20261003');assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const page=await browser.newPage({viewport:{width:1440,height:1000}}),base=process.env.SPP_TEST_BASE_URL,errors=[];page.on('pageerror',e=>errors.push(e.message));
 assert.equal((await(await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
 await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await page.locator('#btn-login').click();await page.waitForURL('**/dashboard.php');await page.goto(base+'/backup_restore.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('1')]);
 const config=await page.locator('#legacy-config').evaluate(e=>JSON.parse(e.textContent)),common={action:'upload',csrf_token:config.csrf,unit:'1',category:'students'};
 for(const [name,buffer,status] of [['wrong.sql',Buffer.from('a'),400],['empty.dat',Buffer.alloc(0),400]]){
  const r=await page.request.post(base+'/legacy_import_api.php',{multipart:{...common,backup:{name,mimeType:'application/octet-stream',buffer}},timeout:60000});assert.equal(r.status(),status,name);
 }
 const multiple=await page.request.post(base+'/legacy_import_api.php',{multipart:{...common,backup:{name:'a.dat',mimeType:'application/octet-stream',buffer:Buffer.from('a')},extra:{name:'b.dat',mimeType:'application/octet-stream',buffer:Buffer.from('b')}}});assert.equal(multiple.status(),400);
 const corrupt=await page.request.post(base+'/legacy_import_api.php',{multipart:{...common,backup:{name:'<img src=x onerror=alert(1)>.dat',mimeType:'application/octet-stream',buffer:Buffer.from('bad backup')}}});assert.equal(corrupt.status(),200);const job=await corrupt.json();assert.match(job.id,/^[a-f0-9]{32}$/);assert.ok(!JSON.stringify(job).includes('C:/'));
 const cancelled=await page.request.post(base+'/legacy_import_api.php',{form:{action:'cancel',csrf_token:config.csrf,id:job.id}});assert.equal(cancelled.status(),200);
 assert.equal((await page.request.post(base+'/legacy_import_api.php',{form:{action:'confirm',csrf_token:config.csrf,id:job.id,confirmation:'IMPOR LEGACY'}})).status(),409);
 assert.equal((await page.request.post(base+'/legacy_import_api.php',{form:{action:'confirm',csrf_token:'wrong',id:job.id}})).status(),403);
 assert.equal((await page.request.get(base+'/legacy_import_api.php?action=status&id=../../job')).status(),404);
 const matrix=[];
 for(const unit of [1,2,3]){
  await page.goto(base+'/backup_restore.php');await page.setViewportSize({width:1440,height:1000});await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);await page.goto(base+'/siswa/daftar.php?status=legacy');const href=await page.locator('a[href^="aktivasi_legacy.php?id="]').first().getAttribute('href');await page.goto(base+'/siswa/'+href);assert.ok(await page.locator('#legacy-activation').isVisible());
  for(const theme of ['light','dark'])for(const width of [1440,2560,390]){await page.evaluate(t=>document.documentElement.setAttribute('data-theme',t),theme);await page.setViewportSize({width,height:width===390?844:1100});await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+1);await page.screenshot({path:path.join(process.env.SPP_UI_ARTIFACTS,`activation-${unit}-${theme}-${width}.png`),fullPage:true});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`${unit}/${theme}/${width}`);matrix.push({unit,theme,width});}
 }
 assert.deepEqual(errors,[]);fs.writeFileSync(path.join(process.env.SPP_UI_ARTIFACTS,'activation-matrix.json'),JSON.stringify(matrix));
 console.log('PASS: actual HTTP empty/wrong/multiple upload, cancellation, CSRF, traversal, private payload; 18 activation visual states');
}finally{await browser.close();}})().catch(e=>{console.error(e.stack);process.exitCode=1});
