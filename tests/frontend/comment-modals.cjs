"use strict";
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const modulePath = process.env.TINYCAT_TEST_PLAYWRIGHT_MODULE || path.join(root, '.work/tools/ui-webkit-tools/node_modules/playwright');
if (!fs.existsSync(modulePath)) { console.log('SKIP touch comment modals: set TINYCAT_TEST_PLAYWRIGHT_MODULE'); process.exit(0); }
const {webkit} = require(modulePath);
function read(file, ...args) {
  return JSON.parse(execFileSync(process.env.TINYCAT_TEST_PHP || 'php', [path.join(root, file), ...args], {encoding:'utf8', maxBuffer:8e6}));
}
const adminDefinitions = read('tests/presentation/fixtures/component-parity.php','--admin-definitions');
const scripts = 'window.TinyCat = {adminDefinitions:'+JSON.stringify(adminDefinitions)+'};' + read('tests/presentation/fixtures/asset-graph-fixture.php', '--all').map(asset => asset.source).join('\n');
const templates = read('tests/presentation/fixtures/component-parity.php', '--templates');
const constraints = read('tests/presentation/fixtures/component-parity.php', '--social-constraints');
const labels = read('tests/presentation/fixtures/asset-graph-fixture.php', '--catalog', 'cs');
const css = read('tests/presentation/fixtures/asset-graph-fixture.php', '--styles');
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
      await page.evaluate(constraints => {
        const T = TinyCat;
        const comment = {id:20,content_id:10,author:{id:7,username:'Test',url:'/author/7'},capabilities:{edit:true},editable_body:'Test',created_at:'2026-11-01T12:00:00Z',segments:[{type:'text',text:'Test comment body'}],replies:[]};
        window.commentModalReads = []; window.commentModalErrors = [];
        const wrap = document.createElement('div'); wrap.className='status-card'; wrap.dataset.modalParentOpen='status-post-modal-10'; wrap.dataset.modalParentUrl='/api/statuses/10'; wrap.dataset.statusUrl='/status/10';
        wrap.append(T.status.comment(comment)); document.querySelector("main").append(wrap);
        T.api.request = async url => { commentModalReads.push(url); return {data:url.includes('revisions') ? {items:[]} : {constraints,item:{id:10},comments:[comment]}}; };
        document.addEventListener('tinycat:dialog-error',event => commentModalErrors.push(event.detail.error.message));
        T.popovers.init(); T.dialog.init();
      }, constraints);
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
    const page = await browser.newPage({viewport:{width:390,height:812},isMobile:true,hasTouch:true});
    await page.goto('http://127.0.0.1:'+server.address().port+'/');
    await page.evaluate(async constraints => {
      const viewer = {id:7,username:'Test',url:'/author/7'}, make = (id, parent) => ({id,content_id:10,parent_id:parent,author:viewer,created_at:'2026-01-01T00:00:00Z',segments:[{type:'text',text:'Comment '+id}],capabilities:{interact:true},constraints,replies:[],has_replies:id===1});
      window.commentFixture = {viewer,make};
      document.querySelector('main').append(TinyCat.components.render('status/thread', {item:{id:10,constraints},viewer,comments:[make(1,0)],comments_next_url:'/api/statuses/10/comments?cursor_id=1'}));
      TinyCat.status.init(); TinyCat.lifecycle.hydrate(document);
      TinyCat.api.request = () => new Promise(resolve => { window.finishCommentRequest = resolve; });
      const field = document.querySelector('[data-status-editor-input], textarea.status-comment-input'); field.focus();
      if (field.isContentEditable) field.textContent = 'Draft'; else field.value = 'Draft';
      document.querySelector('[data-comment-parent="0"]').click();
      const hydrated = []; TinyCat.lifecycle.register('test-comment-roots', {hydrate: root => hydrated.push(root)});
      const form = document.querySelector('form[data-status-form]');
      const roots = await TinyCat.status.controllers.actions.handleStatusJsonResponse(form, {data:{action:'comment',comment:make(2,0)}});
      if (hydrated.length || roots.length !== 1 || roots[0].dataset.commentId !== '2') throw new Error('Comment mutation must return the new root without hydrating it');
      roots.forEach(TinyCat.lifecycle.hydrate);
      if (hydrated.length !== 1 || hydrated[0] !== roots[0]) throw new Error('The caller hydrates exactly the inserted comment');
      window.finishCommentRequest({data:{items:[make(2,0),make(3,0)],viewer},meta:{pagination:{next_url:'',done:true}}});
    }, constraints);
    await page.waitForFunction(() => document.querySelectorAll('.status-comment').length === 3);
    const state = await page.evaluate(() => ({ids:Array.from(document.querySelectorAll('.status-comment')).map(n=>n.dataset.commentId),draft:document.activeElement.value || document.activeElement.textContent,focus:document.activeElement.matches('[data-status-editor-input], textarea.status-comment-input')}));
    assert.deepEqual(state.ids, ['1','2','3']); checks++;
    assert.equal(state.draft, 'Draft'); checks++;
    assert.equal(state.focus, true); checks++;
    await page.evaluate(() => {
      const root = document.querySelector('.status-comment[data-comment-id="1"]');
      root.querySelector('.status-reply-details').open = true;
      const field = root.querySelector('.status-reply-form-container textarea.status-comment-input');
      field.value = 'Reply draft'; field.focus();
      root.querySelector('[data-comment-parent="1"]').click();
      window.finishCommentRequest({data:{items:[commentFixture.make(4,1)],viewer:commentFixture.viewer},meta:{pagination:{next_url:'/api/statuses/10/comments?parent_id=1&cursor_id=4',done:false}}});
    });
    await page.waitForFunction(() => !!document.querySelector('.status-comment[data-comment-id="4"]'));
    const replyDraft = await page.evaluate(() => ({value:document.activeElement.value,focused:document.activeElement.matches('.status-reply-form-container textarea.status-comment-input')}));
    assert.equal(replyDraft.value, 'Reply draft'); checks++;
    assert.equal(replyDraft.focused, true); checks++;
    await page.evaluate(() => {
      document.querySelector('[data-comment-parent="1"]').click();
      TinyCat.status.controllers.comments.removeStatusComment(1);
      window.finishCommentRequest({data:{items:[commentFixture.make(5,1)],viewer:commentFixture.viewer},meta:{pagination:{next_url:'',done:true}}});
    });
    await page.waitForTimeout(50);
    assert.equal(await page.locator('.status-comment[data-comment-id="5"]').count(), 0); checks++;
    await page.close();
    console.log('PASS touch comment edit and revision modals use resource loader ('+checks+' checks)');
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
}()).catch(error => { console.error(error); process.exitCode=1; });
