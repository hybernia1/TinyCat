"use strict";
const assert = require('node:assert/strict');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path');
const { pathToFileURL } = require('node:url');
const { execFileSync } = require('node:child_process');
const browser = [process.env.TINYCAT_TEST_CHROME, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].filter(Boolean).find(file => fs.existsSync(file));
if (!browser) { console.log('SKIP native popovers: set TINYCAT_TEST_CHROME'); process.exit(0); }
const root = path.resolve(__dirname, '../..'), php = process.env.TINYCAT_TEST_PHP || 'php';
const source = fs.readFileSync(path.join(root, 'assets/ui/popovers.js'), 'utf8').replace(/^import .*;\r?\n/gm, '');
const css = JSON.parse(execFileSync(php, [path.join(root, 'tests/presentation/asset-graph-fixture.php'), '--styles'], { encoding: 'utf8', maxBuffer: 8e6 }));
const fixture = fs.readFileSync(path.join(__dirname, 'popovers-fixture.js'), 'utf8');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'tinycat-popovers-'));
try {
  const target = path.join(temporary, 'fixture.html');
  const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body><pre id="results"></pre><script>window.TinyCat={lifecycle:{register(name,service){this[name]=service;}}};${source}\n${fixture}</script></body></html>`;
  const sizes = [320, 360, 375, 390, 414].flatMap(width => [320, 480, 812].map(height => [width, height])).concat([[667, 320], [1024, 600]]);
  fs.writeFileSync(target, `<!doctype html><html><body><pre id="all-results"></pre><script>
    (async function () {
      const html=${JSON.stringify(html).replace(/<\/script/gi, '<\\/script')}, sizes=${JSON.stringify(sizes)}, results=[];
      for (const [width,height] of sizes) {
        const frame=document.createElement('iframe'); frame.style.cssText='border:0;width:'+width+'px;height:'+height+'px';
        await new Promise(resolve => { frame.onload=() => {
          const timer=setInterval(() => { const node=frame.contentDocument.querySelector('#results'); if (!node || !node.textContent) return;
            clearInterval(timer); results.push({width,height,actual:[frame.contentWindow.innerWidth,frame.contentWindow.innerHeight],result:JSON.parse(node.textContent)}); resolve(); },10);
        }; frame.srcdoc=html; document.body.append(frame); }); frame.remove();
      }
      document.querySelector('#all-results').textContent=JSON.stringify(results);
    }());
  </script></body></html>`);
  const output = execFileSync(browser, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--hide-scrollbars', '--window-size=1200,900', '--virtual-time-budget=40000', '--user-data-dir='+path.join(temporary, 'profile'), '--dump-dom', pathToFileURL(target).href], { encoding: 'utf8', timeout: 60000, maxBuffer: 8e6, stdio: ['ignore', 'pipe', 'pipe'] });
  const match = output.match(/<pre id="all-results">([\s\S]*?)<\/pre>/);
  assert.ok(match && match[1], 'Every mobile viewport fixture must finish');
  const results = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
  assert.equal(results.length, sizes.length);
  let checks = 0;
  for (const {width,height,actual,result} of results) {
    assert.deepEqual(actual,[width,height], 'Exercise actual CSS viewport dimensions');
    assert.ok(result.ok, JSON.stringify({width,height,...result})); checks += result.checks.length;
  }
  console.log(`PASS native shared popovers, focus, viewport placement, forms, modal priority and disposal (${checks} checks; ${sizes.length} viewports)`);
} finally {
  const resolved = fs.realpathSync(temporary), parent = fs.realpathSync(os.tmpdir());
  assert.equal(path.dirname(resolved), parent); assert.ok(path.basename(resolved).startsWith('tinycat-popovers-'));
  fs.rmSync(resolved, { recursive: true, maxRetries: 5, retryDelay: 100 });
}
