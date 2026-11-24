"use strict";
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const modulePath = process.env.TINYCAT_TEST_PLAYWRIGHT_MODULE || path.join(root, '.work/tools/ui-webkit-tools/node_modules/playwright');
if (!fs.existsSync(modulePath)) { console.log('SKIP touch popover matrix: set TINYCAT_TEST_PLAYWRIGHT_MODULE'); process.exit(0); }
const {webkit} = require(modulePath);
function read(file, ...args) { return JSON.parse(execFileSync(process.env.TINYCAT_TEST_PHP || 'php', [path.join(root,file),...args], {encoding:'utf8',maxBuffer:8e6})); }
const fixture = read('tests/presentation/fixtures/popover-sizing-fixture.php');
const scripts = read('tests/presentation/fixtures/asset-graph-fixture.php','--all').map(asset => asset.source).join('\n');
const adminDefinitions = read('tests/presentation/fixtures/component-parity.php','--admin-definitions');
const templates = read('tests/presentation/fixtures/component-parity.php','--templates');
const labels = read('tests/presentation/fixtures/asset-graph-fixture.php','--catalog','cs');
const html = '<!doctype html><html lang="cs"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+fixture.styles+'</style></head><body><script>window.TinyCat = {adminDefinitions:'+JSON.stringify(adminDefinitions)+'};'+scripts+'\nTinyCat.components.register('+JSON.stringify(templates)+');TinyCat.i18n.register("cs",'+JSON.stringify(labels)+');</script></body></html>';
const cases = [
  {name:'profile',source:'account',selector:'a:not([data-modal-open])',closed:true},
  {name:'settings',source:'account',selector:'[data-modal-open]',closed:true},
  ...['system','light','dark'].map(theme => ({name:'theme-'+theme,source:'account',selector:'[data-user-menu-theme="'+theme+'"]'})),
  {name:'logout',source:'account',selector:'button[type="submit"]',submit:true},
  {name:'language',source:'public',extract:'[data-language-menu]',selector:'.language-menu-link',closed:true},
  {name:'archive',source:'archive',selector:'a',closed:true},
  {name:'notifications',source:'notifications',selector:'.notification-preview-more'},
  {name:'post-editor',source:'post',selector:'[data-modal-open]',closed:true},
  {name:'date-from',source:'users',selector:'[name="updated_from"]',date:true},
  {name:'date-to',source:'users',selector:'[name="updated_to"]',date:true},
  {name:'user-select',source:'users',selector:'select',select:true},
  ...Object.keys(fixture.filters).map(name => ({name,filter:name,selector:'select',select:true})),
  {name:'search',source:'public',extract:'[data-global-search]',selector:'[data-global-search-link]',search:true,closed:true},
  {name:'search-empty',source:'public',extract:'[data-global-search]',selector:'.global-search-all',search:true,empty:true,closed:true},
];
(async function () {
  const server = http.createServer((request,response) => { response.setHeader('Content-Type','text/html; charset=utf-8'); response.end(html); });
  let browser, checks = 0;
  try {
    await new Promise(resolve => server.listen(0,'127.0.0.1',resolve)); browser = await webkit.launch();
    for (const width of [320,390,844]) {
      const session = await browser.newContext({viewport:{width,height:812},isMobile:true,hasTouch:true});
      try {
        // A new document resets globals/listeners; persistent state is cleared before fixture scripts.
        await session.addInitScript(() => {
          if (location.protocol === 'http:' || location.protocol === 'https:') { localStorage.clear(); sessionStorage.clear(); }
        });
        const page = await session.newPage();
        for (const context of ['page','modal']) for (const test of cases) {
          await session.clearCookies();
          await page.goto('http://127.0.0.1:'+server.address().port+'/');
          await page.evaluate(() => {
            if (window.touchClicks !== undefined || localStorage.length || sessionStorage.length || document.querySelector('#test-host')) {
              throw Error('Touch scenario did not start with an isolated document.');
            }
          });
          await page.evaluate(({fixture,test,context}) => {
            const host = document.createElement('div');
            host.innerHTML = context === 'page' ? '<main tabindex="-1" id="test-host"></main>' : '<div class="modal" data-open="true"><div class="modal-panel" tabindex="-1"><div class="modal-body" id="test-host"></div></div></div>';
            document.body.append(host); const target = document.querySelector('#test-host');
            if (test.filter) target.innerHTML = '<details data-popover><summary>Filter</summary><div class="popover-panel filter-menu-panel">'+fixture.filters[test.filter]+'</div></details>';
            else if (test.extract) target.append(new DOMParser().parseFromString(fixture[test.source],'text/html').querySelector(test.extract));
            else target.innerHTML = fixture[test.source];
            window.touchClicks = 0; window.touchChanges = 0; window.touchSubmits = 0;
            target.addEventListener('submit',event => { event.preventDefault(); touchSubmits++; });
            target.addEventListener('change',() => touchChanges++); target.addEventListener('input',() => touchChanges++);
            TinyCat.popovers.init();
            if (test.search) {
              TinyCat.api.request = async () => ({ok:true,data:{query:test.empty?'x'.repeat(80):'alpha',collections:[{id:'users',name:'People'}],users:test.empty?[]:[{type:'user',title:'Alpha',url:'/@alpha'}]}});
              TinyCat.search.hydrate(document);
            }
          },{fixture,test,context});
          if (test.search) { await page.locator('[data-global-search-input]').tap(); await page.locator('[data-global-search-input]').fill(test.empty?'x'.repeat(80):'alpha'); }
          else await page.locator('#test-host summary, #test-host [data-popover-trigger]').first().tap();
          if(test.empty) {
            await page.waitForSelector('.global-search-all');
            const state=await page.evaluate(()=>{const empty=document.querySelector('.empty-state'),icon=empty.querySelector('.empty-state-icon'),box=empty.getBoundingClientRect(),bounds=icon.getBoundingClientRect(),action=document.querySelector('.global-search-all');return {position:getComputedStyle(icon).position,centered:Math.abs(bounds.left+bounds.width/2-box.left-box.width/2)<2,stacked:bounds.bottom<=empty.querySelector('.empty-state-label').getBoundingClientRect().top,actionIcon:getComputedStyle(action.querySelector('svg')).position,avatar:!!action.querySelector('.avatar'),fits:action.scrollWidth<=action.clientWidth+1,link:action.getAttribute('href')};});
            assert.ok(state.position==='static'&&state.centered&&state.stacked&&state.actionIcon==='static'&&!state.avatar&&state.fits&&state.link.includes('x'.repeat(80)),JSON.stringify({width,context,...state}));checks++;
          }

          const panel = page.locator('#test-host .popover-panel'); await panel.waitFor({state:'visible'});
          const control = panel.locator(test.selector).first();
          await control.evaluate(node => node.addEventListener('click',event => { touchClicks++; if (node.tagName === 'A') event.preventDefault(); }));
          await control.tap();
          if (test.date) await control.fill('2027-02-02');
          if (test.select) { await control.evaluate(node => { const option=document.createElement('option'); option.value='touch-test'; option.textContent='Touch test'; node.append(option); }); await control.selectOption('touch-test'); }
          const result = await page.evaluate(() => ({clicks:touchClicks,changes:touchChanges,submits:touchSubmits,open:document.querySelector('#test-host .popover-panel').matches(':popover-open')}));
          const diagnostic = JSON.stringify({width,context,name:test.name,...result});
          if (test.select || test.date) { assert.ok(result.changes >= 1 && result.open,diagnostic); checks++; }
          else { assert.equal(result.clicks,1,diagnostic); assert.equal(result.open,!test.closed,diagnostic); checks+=2; }
          if (test.submit) { assert.equal(result.submits,1,diagnostic); checks++; }
        }
        for (const context of ['page','modal']) {
          await page.goto('http://127.0.0.1:'+server.address().port+'/');
          await page.evaluate(context => {
            const T=TinyCat; document.documentElement.dataset.uiDensity='compact';
            const form=T.ui.form('/api/help-test','POST');
            form.append(T.components.render('ui/field',{label:'Flag',help:'Information <img src=x> remains literal.',attributes:{name:'flag',type:'checkbox'}}),T.ui.button('common.save',{type:'submit'}));
            window.helpSubmits=0;form.addEventListener('submit',event=>{event.preventDefault();helpSubmits++;});
            T.lifecycle.start();
            if(context==='modal') { const dialog=T.ui.adminDialog('help-touch','common.edit',form,false);document.body.append(dialog);T.dialog.open(dialog); }
            else { const main=document.createElement('main');main.tabIndex=-1;main.append(form);document.body.append(main);T.lifecycle.hydrate(main);main.focus(); }
          },context);
          const hint=page.locator('[data-help]');await hint.tap();
          let result=await page.evaluate(()=>{const h=document.querySelector('[data-help]'),tip=document.getElementById(h.getAttribute('aria-controls'));return {expanded:h.getAttribute('aria-expanded'),checked:document.querySelector('[name="flag"]').checked,submits:helpSubmits,text:tip&&tip.textContent,html:tip&&tip.querySelector('img')!==null};});
          assert.ok(result.expanded==='true'&&!result.checked&&!result.submits&&!result.html&&result.text.includes('<img src=x>'),'Native help touch opens exactly once without activating a label: '+JSON.stringify({width,context,...result}));checks++;
          await hint.tap();assert.equal(await hint.getAttribute('aria-expanded'),'false','Second native touch closes help');checks++;
          await hint.press('Enter');assert.equal(await hint.getAttribute('aria-expanded'),'true','Native keyboard opens help');checks++;
          await hint.press('Escape');assert.equal(await hint.getAttribute('aria-expanded'),'false','Escape dismisses help');checks++;
          if(context==='modal') {assert.equal(await page.locator('.modal[data-open="true"]').count(),1,'Help Escape preserves its modal');checks++;}
        }
      } finally { await session.close(); }
    }
    for(const width of [390,899,900,1280]) for(const touch of [false,true]) for(const variant of ["editor","workspace"]) {
      const session=await browser.newContext({viewport:{width,height:900},hasTouch:touch,isMobile:touch});
      try {
        const page=await session.newPage();await page.goto('http://127.0.0.1:'+server.address().port+'/');
        const state=await page.evaluate(variant=>{
          const T=TinyCat,U=T.ui;T.lifecycle.start();
          document.documentElement.dataset.uiDensity=variant==="workspace"?"compact":"regular";
          const tabs=U.tabbedContent('overlay-matrix',[{name:'one',label:'One',content:T.dom.element('p',{},'One')},{name:'two',label:'Two',content:T.dom.element('p',{},'Two')}],'one','Tabs');
          const dialog=U.dialog('overlay-matrix','Details',tabs.content,false,null,{kind:variant==='workspace'?'content':'editor',size:variant==='workspace'?'workspace':'compact'});
          U.pinDialogTabs(dialog,tabs.content);T.dialog.open(dialog);
          const panel=dialog.querySelector('.modal-panel').getBoundingClientRect();
          const result={mobile:innerWidth<900,full:Math.abs(panel.width-innerWidth)<2,locked:document.body.style.position==='fixed',selector:getComputedStyle(dialog.querySelector('.modal-tab-switch')).display!=='none'};
          const focused=document.activeElement;const toast=T.toasts.show('Long message '.repeat(16),'warning',60000);
          result.focusKept=document.activeElement===focused;result.role=toast.getAttribute('role');
          result.toastFits=toast.getBoundingClientRect().left>=-1&&toast.getBoundingClientRect().right<=innerWidth+1;
          const initial=toast.querySelector('.toast-close').getBoundingClientRect().width;
          document.documentElement.style.setProperty('--ui-scale','1.5');
          result.scaled=toast.querySelector('.toast-close').getBoundingClientRect().width>initial*1.25;
          result.scaledFits=toast.getBoundingClientRect().left>=-1&&toast.getBoundingClientRect().right<=innerWidth+1;
          for(const options of [{kind:'other'},{size:'other'},{mobile:'other'},{kind:'content',size:'confirm'}]) {
            try { T.dialog.create(Object.assign({id:'invalid-variant',title:'Title',closeLabel:'Close',content:document.createElement('p')},options));result.invalidAccepted=true; }catch(error){if(!(error instanceof TypeError))throw error;}
          }
          return result;
        },variant);
        assert.equal(state.full,state.mobile, 'Modal width follows the same boundary regardless of pointer: '+JSON.stringify({width,touch,...state}));checks++;
        assert.equal(state.locked,state.mobile);assert.equal(state.selector,state.mobile);checks+=2;
        assert.ok(state.focusKept&&state.role==='alert'&&state.toastFits&&state.scaled&&state.scaledFits&&!state.invalidAccepted,JSON.stringify({width,touch,...state}));checks++;
        const resizedWidth=width<900?1024:844;await page.setViewportSize({width:resizedWidth,height:900});
        await page.waitForFunction(mobile=>(document.body.style.position==='fixed')===mobile,resizedWidth<900);
        const resized=await page.evaluate(()=>{const d=document.querySelector('.modal[data-open="true"]');return {full:Math.abs(d.querySelector('.modal-panel').getBoundingClientRect().width-innerWidth)<2,selector:getComputedStyle(d.querySelector('.modal-tab-switch')).display!=='none'};});
        assert.equal(resized.full,resizedWidth<900);assert.equal(resized.selector,resizedWidth<900);checks+=2;
        await page.keyboard.press('Escape');assert.equal(await page.locator('.modal[data-open="true"]').count(),0);checks++;
        assert.equal(await page.evaluate(()=>document.body.style.position),'');checks++;
      } finally {await session.close();}
    }
    for(const width of [390,1024]) for(const compact of [false,true]) {
      const session=await browser.newContext({viewport:{width,height:900},isMobile:true,hasTouch:true});
      try {
        const page=await session.newPage();await page.goto('http://127.0.0.1:'+server.address().port+'/');
        await page.evaluate(compact=>{
          document.documentElement.dataset.uiDensity=compact?'compact':'regular';
          const form=document.createElement('form'),pre=document.createElement('pre'),code=document.createElement('code'),inline=document.createElement('code');
          code.textContent='  line <b>& '+String.fromCodePoint(382)+'\nsecond line\n';pre.append(code);inline.textContent='inline';form.append(pre,inline);document.body.append(form);
          window.codeWrites=[];window.codeSubmits=0;form.addEventListener('submit',event=>{event.preventDefault();codeSubmits++;});
          Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async value=>{codeWrites.push(value);}}});
          TinyCat.lifecycle.start();TinyCat.lifecycle.hydrate(form);TinyCat.lifecycle.hydrate(form);
        },compact);
        const buttons=page.locator('[data-copy-code]');assert.equal(await buttons.count(),2);checks++;
        await buttons.first().press('Enter');await page.waitForFunction(()=>codeWrites.length===1);
        await buttons.last().press('Space');await page.waitForFunction(()=>codeWrites.length===2);
        const state=await page.evaluate(()=>{const pre=document.querySelector('pre'),button=pre.querySelector('button'),p=pre.getBoundingClientRect(),b=button.getBoundingClientRect(),before=b.width;document.documentElement.style.setProperty('--ui-scale','1.5');return {writes:codeWrites,source:pre.querySelector('code').textContent,submits:codeSubmits,contained:b.right<=p.right&&b.bottom<=p.bottom,scaled:button.getBoundingClientRect().width>before*1.4,overflow:document.documentElement.scrollWidth>innerWidth};});
        assert.equal(state.writes[0],state.source);assert.equal(state.writes[1],'inline');assert.ok(!state.submits&&state.contained&&state.scaled&&!state.overflow,JSON.stringify({width,compact,...state}));checks+=3;
      } finally {await session.close();}
    }
    for (const width of [390,1024]) {
      const session=await browser.newContext({viewport:{width,height:900}});
      try {
        const page=await session.newPage();
        await page.route('**/api/notifications/preview',async route=>{
          await new Promise(resolve=>setTimeout(resolve,300));
          await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,status:200,code:'ok',params:{},message:null,meta:{},data:{unread_count:1,latest_id:8,items:[{id:8,type:'generic',message:'New fixture',icon:'bell',actor:{id:0,username:'',avatar_url:''},url:'/notifications',unread:true,excerpt:'',created_at:'2026-01-01T00:00:00Z'}]}})});
        });
        await page.goto('http://127.0.0.1:'+server.address().port+'/');
        await page.evaluate(html=>{
          const host=document.createElement('main');host.id='notification-polling-fixture';host.innerHTML=html;document.body.append(host);
          TinyCat.notifications.update({unread_count:1,latest_id:7,items:[{id:7,type:'generic',message:'Initial fixture',icon:'bell',actor:{id:0,username:'',avatar_url:''},url:'/notifications',unread:true,excerpt:'',created_at:'2026-01-01T00:00:00Z'}]});
          host.querySelector('[data-notification-list] a').id='polling-focus';
          const interval=window.setInterval;window.setInterval=(fn,delay)=>{window.notificationPoll=fn;return interval(fn,60000);};
          const update=TinyCat.notifications.update;window.pollUpdates=0;TinyCat.notifications.update=state=>{update(state);pollUpdates++;};
          TinyCat.popovers.init();TinyCat.popovers.prepare(host.querySelector('[data-popover]'));TinyCat.notifications.init();window.pollUpdates=0;
        },fixture.notifications);
        await page.locator('[data-notification-button]').press('ArrowDown');
        await page.locator('#polling-focus').focus();
        await page.waitForFunction(()=>pollUpdates>0);
        assert.ok(await page.evaluate(()=>document.activeElement.id==='polling-focus'&&document.querySelector('[data-notification-id="7"]')&&document.querySelector('[data-popover]').dataset.popoverOpen==='true'),'Preview polling must preserve focused row and open state');checks++;
        await page.evaluate(async()=>{document.querySelector('[data-notification-button]').focus();await notificationPoll();});
        assert.ok(await page.evaluate(()=>document.querySelector('[data-notification-id="8"]')&&document.activeElement.hasAttribute('data-notification-button')),'Preview catches up after focus leaves the list');checks++;
        await page.evaluate(()=>TinyCat.notifications.dispose(document.querySelector('#notification-polling-fixture')));
      } finally {await session.close();}
    }
    console.log('PASS touch activation across all popover families in focusable pages and modals ('+cases.length+' variants; '+checks+' checks)');
  } finally { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); }
}()).catch(error => { console.error(error); process.exitCode=1; });
