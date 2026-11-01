"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs"), os = require("node:os"), path = require("node:path");
const {execFileSync} = require("node:child_process");
const {pathToFileURL} = require("node:url");
const browser = [process.env.TINYCAT_TEST_CHROME, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "/usr/bin/google-chrome", "/usr/bin/chromium", "/usr/bin/chromium-browser", "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"].filter(Boolean).find(file => fs.existsSync(file));
if (!browser) { console.log("SKIP dashboard viewport: set TINYCAT_TEST_CHROME"); process.exit(0); }
const source = execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(__dirname, "../presentation/dashboard.php"), "--fixture"], {encoding:"utf8",maxBuffer:8e6});
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), "tinycat-dashboard-"));
try {
  const fixture = path.join(temporary, "dashboard.html");
  fs.writeFileSync(fixture, `<!doctype html><html><body><script>
    (async function () {
      const source = ${JSON.stringify(source).replace(/<\/script/gi, "<\\/script")}, results = [];
      for (const width of [320,375,390,599,600,760,1280]) {
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
        const doc = frame.contentDocument, win = frame.contentWindow, table = doc.querySelector('table');
        const rows = [...table.tBodies[0].rows], mobile = width < 600;
        results.push({width, documentWidth:doc.documentElement.scrollWidth,
          rows:rows.length, cards:table.hasAttribute('data-mobile-cards'),
          stats:doc.querySelectorAll('article h2').length,
          display:win.getComputedStyle(table).display,
          headers:table.tHead.rows[0].cells.length > 0 && win.getComputedStyle(table.tHead).display === 'table-header-group',
          fits:table.closest('.table-wrap').getBoundingClientRect().right <= width, scrollable:['auto','scroll'].includes(win.getComputedStyle(table.closest('.table-wrap')).overflowX),
          clipped:rows.every(row => row.cells[0].querySelector('.table-primary').getBoundingClientRect().height <= 2 * parseFloat(win.getComputedStyle(row.cells[0].querySelector('.table-primary')).lineHeight) + 1),
          noUnrelatedSort:!doc.querySelector('.table-mobile-sort'),
          resetFits:[...doc.querySelectorAll('form button')].every(button => button.getBoundingClientRect().right <= width)
        });
        frame.remove();
      }
      const result = document.createElement('pre'); result.id = 'dashboard-metrics'; result.textContent = JSON.stringify(results); document.body.append(result);
    }());
  </script></body></html>`);
  const output = execFileSync(browser, ["--headless=new","--disable-gpu","--no-first-run","--no-default-browser-check","--disable-background-networking","--disable-sync","--hide-scrollbars","--virtual-time-budget=15000","--user-data-dir="+path.join(temporary,"profile"),"--dump-dom",pathToFileURL(fixture).href], {encoding:"utf8",timeout:30000,maxBuffer:8e6,stdio:["ignore","pipe","pipe"]});
  const match = output.match(/<pre id="dashboard-metrics">([\s\S]*?)<\/pre>/);
  assert.ok(match, "Every dashboard viewport must render: " + output.slice(-1500));
  const results = JSON.parse(match[1].replace(/&quot;/g,'"').replace(/&amp;/g,'&'));
  assert.equal(results.length,7);
  for (const item of results) {
    assert.ok(item.documentWidth <= item.width && item.fits && item.resetFits, "Dashboard overflow: "+JSON.stringify(item));
    assert.ok(item.rows === 8 && item.stats === 10 && !item.cards && item.headers && item.scrollable && item.clipped && item.noUnrelatedSort, "Dashboard content and shared table behavior: "+JSON.stringify(item));
    assert.equal(item.display, "table");
  }
  console.log("PASS dashboard native tables on desktop and mobile, long comments and diagnostics (7 viewports)");
} finally {
  const resolved = fs.realpathSync(temporary), parent = fs.realpathSync(os.tmpdir());
  assert.equal(path.dirname(resolved),parent); assert.ok(path.basename(resolved).startsWith("tinycat-dashboard-"));
  fs.rmSync(resolved,{recursive:true,maxRetries:5,retryDelay:100});
}
