"use strict";
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const modulePath = process.env.TINYCAT_TEST_PLAYWRIGHT_MODULE || path.join(root, 'storage/ui-webkit-tools/node_modules/playwright');
if (!fs.existsSync(modulePath)) { console.log('SKIP touch popover matrix: set TINYCAT_TEST_PLAYWRIGHT_MODULE'); process.exit(0); }
const {webkit} = require(modulePath);
function read(file, ...args) { return JSON.parse(execFileSync(process.env.TINYCAT_TEST_PHP || 'php', [path.join(root,file),...args], {encoding:'utf8',maxBuffer:8e6})); }
const fixture = read('tests/presentation/popover-sizing-fixture.php');
const scripts = read('tests/presentation/asset-graph-fixture.php','--all').map(asset => asset.source).join('\n');
const templates = read('tests/presentation/component-parity.php','--templates');
const labels = read('tests/presentation/asset-graph-fixture.php','--catalog','cs');
const html = '<!doctype html><html lang="cs"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+fixture.styles+'</style></head><body><script>'+scripts+'\nTinyCat.components.register('+JSON.stringify(templates)+');TinyCat.i18n.register("cs",'+JSON.stringify(labels)+');</script></body></html>';
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
];
(async function () {
  const server = http.createServer((request,response) => { response.setHeader('Content-Type','text/html; charset=utf-8'); response.end(html); });
  let browser, checks = 0;
  try {
    await new Promise(resolve => server.listen(0,'127.0.0.1',resolve)); browser = await webkit.launch();
    for (const width of [320,390,844]) for (const context of ['page','modal']) for (const test of cases) {
      const page = await browser.newPage({viewport:{width,height:812},isMobile:true,hasTouch:true});
      await page.goto('http://127.0.0.1:'+server.address().port+'/');
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
          TinyCat.api.request = async () => ({ok:true,data:{query:'alpha',users:[{type:'user',title:'Alpha',url:'/@alpha'}]}});
          TinyCat.search.hydrate(document);
        }
      },{fixture,test,context});
      if (test.search) { await page.locator('[data-global-search-input]').tap(); await page.locator('[data-global-search-input]').fill('alpha'); }
      else await page.locator('#test-host summary, #test-host [data-popover-trigger]').first().tap();
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
      await page.close();
    }
    console.log('PASS touch activation across all popover families in focusable pages and modals ('+cases.length+' variants; '+checks+' checks)');
  } finally { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); }
}()).catch(error => { console.error(error); process.exitCode=1; });
