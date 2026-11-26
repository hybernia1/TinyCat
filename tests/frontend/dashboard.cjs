"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs"), os = require("node:os"), path = require("node:path");
const {execFileSync} = require("node:child_process");
const {pathToFileURL} = require("node:url");
const browser = [process.env.TINYCAT_TEST_CHROME, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "/usr/bin/google-chrome", "/usr/bin/chromium", "/usr/bin/chromium-browser", "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"].filter(Boolean).find(file => fs.existsSync(file));
if (!browser) { console.log("SKIP dashboard viewport: set TINYCAT_TEST_CHROME"); process.exit(0); }
const sources = ['--fixture','--cache-fixture'].map(mode=>execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(__dirname, "../presentation/dashboard.php"),mode], {encoding:"utf8",maxBuffer:8e6}));
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), "tinycat-dashboard-"));
try {
  const fixture = path.join(temporary, "dashboard.html");
  fs.writeFileSync(fixture, `<!doctype html><html><body><script>
    (async function () {
      const sources = ${JSON.stringify(sources).replace(/<\/script/gi, "<\\/script")}, results = [];
      for (const source of sources) for (const width of [320,375,390,760,899,900,1280]) {
        const frame = document.createElement('iframe');
        frame.style.cssText = 'position:fixed;inset:0;border:0;width:'+width+'px;height:812px';
        await new Promise(resolve => {
          frame.srcdoc = source.replace('</body>', '<div id="dashboard-fixture-ready"></div></body>'); document.body.append(frame);
          const timer = setInterval(() => {
            const doc = frame.contentDocument;
            if (!doc.querySelector('#dashboard-fixture-ready')) return;
            clearInterval(timer); resolve();
          }, 10);
        });
        const doc = frame.contentDocument, win = frame.contentWindow, grid = doc.querySelector('.dashboard-grid');
        const cache=!!doc.querySelector('[data-cache-page]');
        doc.querySelectorAll('[data-cache-header]').forEach(node=>node.textContent='header-'+ 'x'.repeat(200));
        const widgets = [...grid.querySelectorAll(':scope > section')];
        results.push({width,cache, documentWidth:doc.documentElement.scrollWidth, widgets:widgets.length,
          rowsFit:cache || [...doc.querySelectorAll('[data-widget-list] .identity-row')].every(row=>{const avatar=row.querySelector('.avatar'),content=row.querySelector('.identity-copy'),text=content.querySelector('p');return content.getBoundingClientRect().height<=avatar.getBoundingClientRect().height+1 && (!text || (win.getComputedStyle(text).textOverflow==='ellipsis' && text.clientHeight<=parseFloat(win.getComputedStyle(text).lineHeight)+1));}),
          columns:win.getComputedStyle(grid).gridTemplateColumns.split(' ').length,
          fits:widgets.every(widget=>widget.getBoundingClientRect().right<=width+1 && widget.scrollWidth<=widget.clientWidth+1),
          noLegacy:!doc.querySelector('#dashboard-cache') && (cache || !doc.querySelector('table')), underlines:!cache || [...doc.querySelectorAll('table')].every(table=>{const cells=table.rows[table.rows.length-1].cells;return win.getComputedStyle(cells[0]).borderBottomWidth===win.getComputedStyle(cells[1]).borderBottomWidth;}), normalized:!cache || [...doc.querySelectorAll('table')].every(table=>[...table.rows].every(row=>row.cells.length===2 && row.cells[0].getAttribute('scope')==='row')), links:[...grid.querySelectorAll('a')].every(link=>link.getBoundingClientRect().right<=width+1)});
        frame.remove();
      }
      const result = document.createElement('pre'); result.id = 'dashboard-metrics'; result.textContent = JSON.stringify(results); document.body.append(result);
    }());
  </script></body></html>`);
  const output = execFileSync(browser, ["--headless=new","--disable-gpu","--no-first-run","--no-default-browser-check","--disable-background-networking","--disable-sync","--hide-scrollbars","--virtual-time-budget=15000","--user-data-dir="+path.join(temporary,"profile"),"--dump-dom",pathToFileURL(fixture).href], {encoding:"utf8",timeout:30000,maxBuffer:8e6,stdio:["ignore","pipe","pipe"]});
  const match = output.match(/<pre id="dashboard-metrics">([\s\S]*?)<\/pre>/);
  assert.ok(match, "Every dashboard viewport must render: " + output.slice(-1500));
  const results = JSON.parse(match[1].replace(/&quot;/g,'"').replace(/&amp;/g,'&'));
  assert.equal(results.length,14);
  for (const item of results) {
    assert.ok(item.documentWidth <= item.width && item.fits && item.links && item.noLegacy && item.normalized && item.underlines && item.rowsFit, 'Widget layout fits: '+JSON.stringify(item));
    assert.equal(item.widgets,item.cache ? 7 : 3);
    assert.equal(item.columns,item.width < 900 ? 1 : 2);

  }
  console.log("PASS native shared dashboard/cache grid, long content and mobile/desktop boundary (14 layouts)");
} finally {
  const resolved = fs.realpathSync(temporary), parent = fs.realpathSync(os.tmpdir());
  assert.equal(path.dirname(resolved),parent); assert.ok(path.basename(resolved).startsWith("tinycat-dashboard-"));
  fs.rmSync(resolved,{recursive:true,maxRetries:5,retryDelay:100});
}
