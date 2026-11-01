"use strict";
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const modulePath = process.env.TINYCAT_TEST_PLAYWRIGHT_MODULE || path.join(root, 'storage/ui-webkit-tools/node_modules/playwright');
if (!fs.existsSync(modulePath)) { console.log('SKIP touch comment modals: set TINYCAT_TEST_PLAYWRIGHT_MODULE'); process.exit(0); }
const {webkit} = require(modulePath);
function read(file, ...args) {
  return JSON.parse(execFileSync(process.env.TINYCAT_TEST_PHP || 'php', [path.join(root, file), ...args], {encoding:'utf8', maxBuffer:8e6}));
}
const scripts = read('tests/presentation/asset-graph-fixture.php', '--all').map(asset => asset.source).join('\n');
const templates = read('tests/presentation/component-parity.php', '--templates');
const labels = read('tests/presentation/asset-graph-fixture.php', '--catalog', 'cs');
const css = read('tests/presentation/asset-graph-fixture.php', '--styles');
const html = '<!doctype html><html lang="cs"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style></head><body><main tabindex="-1" id="main-content"></main><script>'+scripts+'\nTinyCat.components.register('+JSON.stringify(templates)+');TinyCat.i18n.register("cs",'+JSON.stringify(labels)+');</script></body></html>';
(async function () {
  const server = http.createServer((request,response) => { response.setHeader('Content-Type','text/html; charset=utf-8'); response.end(html); });
  let browser, checks = 0;
  try {
    await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
    browser = await webkit.launch();
    for (const width of [320,390,844]) for (const action of ['edit','history']) for (const scrolled of [false,true]) {
      const page = await browser.newPage({viewport:{width,height:812},isMobile:true,hasTouch:true});
      await page.goto('http://127.0.0.1:'+server.address().port+'/');
      await page.evaluate(() => {
        const T = TinyCat;
        const comment = {id:20,content_id:10,author:{id:7,username:'Test',url:'/author/7'},capabilities:{edit:true},editable_body:'Test',created_at:'2026-11-01T12:00:00Z',segments:[{type:'text',text:'Test comment body'}],replies:[]};
        window.commentModalReads = []; window.commentModalErrors = [];
        const wrap = document.createElement('div'); wrap.className='status-card'; wrap.dataset.modalParentOpen='status-post-modal-10'; wrap.dataset.modalParentUrl='/api/statuses/10'; wrap.dataset.statusUrl='/status/10';
        wrap.append(T.status.comment(comment)); document.querySelector("main").append(wrap);
        T.api.request = async url => { commentModalReads.push(url); return {data:url.includes('revisions') ? {items:[]} : {item:{id:10},comments:[comment]}}; };
        document.addEventListener('tinycat:dialog-error',event => commentModalErrors.push(event.detail.error.message));
        T.popovers.init(); T.dialog.init();
      });
      if (scrolled) await page.evaluate(() => { document.body.style.minHeight='3000px'; document.querySelector('.status-card').style.marginTop='1500px'; window.scrollTo(0,1200); });
      await page.locator('.status-comment-menu summary').tap();
      if (scrolled) { await page.setViewportSize({width,height:460}); await page.waitForTimeout(100); }
      await page.locator('[data-modal-open*="comment-'+action+'-"]').tap();
      await page.locator('.modal[data-open="true"]').waitFor();
      const state = await page.evaluate(() => ({errors:commentModalErrors,reads:commentModalReads,editor:!!document.querySelector('.modal textarea'),history:!!document.querySelector('.modal .status-comment-history')}));
      assert.deepEqual(state.errors, []); checks++;
      assert.equal(state.reads.length, 1); checks++;
      assert.ok(action === 'edit' ? state.editor : state.history, JSON.stringify({width,action,state})); checks++;
      await page.close();
    }
    console.log('PASS touch comment edit and revision modals use resource loader ('+checks+' checks)');
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
}()).catch(error => { console.error(error); process.exitCode=1; });
