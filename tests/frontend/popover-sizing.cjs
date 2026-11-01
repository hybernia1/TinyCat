"use strict";
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const modulePath = process.env.TINYCAT_TEST_PLAYWRIGHT_MODULE || path.join(root, 'storage/ui-webkit-tools/node_modules/playwright');
if (!fs.existsSync(modulePath)) { console.log('SKIP WebKit popover sizing: set TINYCAT_TEST_PLAYWRIGHT_MODULE'); process.exit(0); }
const { webkit } = require(modulePath);
const fixture = JSON.parse(execFileSync(process.env.TINYCAT_TEST_PHP || 'php', [path.join(root, 'tests/presentation/popover-sizing-fixture.php')], { encoding: 'utf8', maxBuffer: 8e6 }));
const html = `<!doctype html><html lang="cs"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${fixture.styles}</style></head><body><div id="account" style="position:fixed;top:60px;right:12px">${fixture.account}</div><div id="archive" style="position:fixed;top:130px;right:12px">${fixture.archive}</div><div id="users" style="position:fixed;top:20px;right:12px">${fixture.users}</div><script>${fixture.scripts}\nTinyCat.popovers.init();</script></body></html>`;
(async function () {
  const server = http.createServer((request, response) => { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); });
  let browser;
  try {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    browser = await webkit.launch({ headless: true });
    let checks = 0;
    for (const [width, height] of [[320,568],[375,667],[390,844],[430,932],[844,390]]) {
      const context = await browser.newContext({ viewport: {width,height}, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
      const page = await context.newPage();
      await page.goto('http://127.0.0.1:'+server.address().port+'/');
      for (const kind of ['account','archive','users']) {
        await page.locator('#'+kind+' summary').tap();
        const metrics = await page.locator('#'+kind+' .popover-panel').evaluate(panel => ({
          height: panel.getBoundingClientRect().height,
          width: panel.getBoundingClientRect().width,
          top: panel.getBoundingClientRect().top,
          bottom: panel.getBoundingClientRect().bottom,
          rows: Array.from(panel.children).map(node => node.getBoundingClientRect().height),
          open: panel.matches(':popover-open'),
          scrollHeight: panel.scrollHeight,
          dates: Array.from(panel.querySelectorAll('input[type="date"]')).map(node => ({left:node.getBoundingClientRect().left,right:node.getBoundingClientRect().right,height:node.getBoundingClientRect().height})),
          left: panel.getBoundingClientRect().left,
          right: panel.getBoundingClientRect().right,
        }));
        assert.ok(metrics.open && metrics.height <= (kind === 'users' ? height - 24 : 280), 'Popover must fit its actual content: '+JSON.stringify({viewport:[width,height],kind,...metrics})); checks++;
        assert.ok(kind === 'users' || metrics.rows.every(row => row <= 80), 'Action and theme rows must not stretch to fill the viewport'); checks++;
        assert.ok(metrics.top >= 11 && metrics.bottom <= height - 11, 'Popover remains within the visible viewport'); checks++;
        if (kind === 'users') {
          assert.equal(metrics.dates.length, 2);
          assert.ok(metrics.dates.every(date => date.left >= metrics.left && date.right <= metrics.right && date.height <= 48), 'Date inputs fit the filter panel: '+JSON.stringify(metrics)); checks++;
        }
        await page.locator('#'+kind+' summary').tap();
      }
      for (const [name, content] of Object.entries(fixture.filters)) {
        await page.locator('#users').evaluate((node, content) => {
          node.innerHTML = '<details data-popover><summary>Filter</summary><div class="popover-panel filter-menu-panel">'+content+'</div></details>';
          node.querySelectorAll('select').forEach(select => {
            const option = document.createElement('option'); option.textContent = 'Long filter value '.repeat(20); option.value = 'long'; select.append(option); select.value = 'long';
          });
          TinyCat.popovers.prepare(node);
        }, content);
        await page.locator('#users summary').tap();
        const layout = await page.locator('#users .popover-panel').evaluate(panel => {
          const bounds = panel.getBoundingClientRect();
          return {wide:Array.from(panel.querySelectorAll('*')).filter(node=>node.scrollWidth>node.clientWidth+2).map(node=>({tag:node.tagName,cls:node.className,w:node.clientWidth,scroll:node.scrollWidth})),width:bounds.width, overflow:panel.scrollWidth-panel.clientWidth, controls:Array.from(panel.querySelectorAll('input:not([type="hidden"]),select,textarea,button')).filter(node => node.getClientRects().length).every(node => {
            const rect = node.getBoundingClientRect(); return rect.left >= bounds.left && rect.right <= bounds.right + 1;
          })};
        });
        assert.ok(layout.width <= 300 && layout.overflow <= 1 && layout.controls, 'Filter controls fit panel: '+JSON.stringify({name,viewport:[width,height],...layout})); checks++;
        await page.locator('#users summary').tap();
      }
      await context.close();
    }
    console.log(`PASS real account, archive and user-filter popovers fit mobile WebKit viewports (${checks} checks)`);
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
}()).catch(error => { console.error(error); process.exitCode = 1; });
