"use strict";
const assert = require('node:assert/strict');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path');
const { execFileSync, spawn } = require('node:child_process');
const browser = [process.env.TINYCAT_TEST_CHROME, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].filter(Boolean).find(file => fs.existsSync(file));
if (!browser) { console.log('SKIP native UI consistency: set TINYCAT_TEST_CHROME'); process.exit(0); }
const root = path.resolve(__dirname, '../..'), php = process.env.TINYCAT_TEST_PHP || 'php';
const assets = JSON.parse(execFileSync(php, [path.join(root, 'tests/presentation/fixtures/asset-graph-fixture.php'), '--all'], { encoding: 'utf8', maxBuffer: 8e6 }));
const templates = JSON.parse(execFileSync(php, [path.join(root, 'tests/presentation/fixtures/component-parity.php'), '--templates'], { encoding: 'utf8', maxBuffer: 8e6 }));
const labels = JSON.parse(execFileSync(php, [path.join(root, 'tests/presentation/fixtures/asset-graph-fixture.php'), '--catalog'], { encoding: 'utf8', maxBuffer: 8e6 }));
const installation = JSON.parse(execFileSync(php, [path.join(root, 'tests/presentation/fixtures/asset-graph-fixture.php'), '--installation'], { encoding: 'utf8', maxBuffer: 8e6 }));
const avatarOptions = JSON.parse(fs.readFileSync(path.join(root, 'App/Core/Identity/avatar-options.json'), 'utf8'));
const adminDefinitions = JSON.parse(execFileSync(php, [path.join(root, 'tests/presentation/fixtures/component-parity.php'), '--admin-definitions'], {encoding:'utf8'}));
const notificationAssemblies = Object.fromEntries(['core'].map(name => [name, JSON.parse(execFileSync(php, [path.join(root, 'tests/presentation/fixtures/asset-graph-fixture.php'), '--notifications-' + name], {encoding:'utf8', maxBuffer:8e6}))]));
const source = 'window.TinyCat = {adminDefinitions:'+JSON.stringify(adminDefinitions)+'};' + assets.map(asset => asset.source).join('\n') + '\nTinyCat.components.register(' + JSON.stringify(templates).replace(/</g, '\\u003c') + ');TinyCat.i18n.register(\"en\",' + JSON.stringify(labels).replace(/</g, '\\u003c') + ');window.installationFixture=' + JSON.stringify(installation).replace(/</g, '\\u003c') + ';window.avatarFixtureOptions=' + JSON.stringify(avatarOptions) + ';';
const css = JSON.parse(execFileSync(php, [path.join(root, 'tests/presentation/fixtures/asset-graph-fixture.php'), '--styles'], { encoding: 'utf8', maxBuffer: 8e6 }));
const fixture = 'window.notificationAssemblySources=' + JSON.stringify(notificationAssemblies).replace(/</g, '\\u003c') + ';window.notificationTemplates=' + JSON.stringify(templates).replace(/</g, '\\u003c') + ';window.notificationLabels=' + JSON.stringify(labels).replace(/</g, '\\u003c') + ';' + fs.readFileSync(path.join(__dirname, 'fixtures/ui-consistency-fixture.js'), 'utf8');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'tinycat-ui-consistency-'));
(async function () {
let server;
try {
  const target = path.join(temporary, 'fixture.html');
  fs.writeFileSync(target, `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body><pre id="results"></pre><script>${source}\n${fixture}</script></body></html>`);
  const wrapper = path.join(temporary, 'wrapper.html');
  fs.writeFileSync(wrapper, '<!doctype html><html><body><pre id="results"></pre><script>const frame=document.createElement("iframe");frame.style.cssText="border:0;width:"+new URL(location.href).searchParams.get("width")+"px;height:600px";frame.src="/inner";document.body.append(frame);const timer=setInterval(()=>{const result=frame.contentDocument&&frame.contentDocument.getElementById("results");if(result&&result.textContent){document.getElementById("results").textContent=result.textContent;clearInterval(timer);}},25);</script></body></html>');
  server = spawn(process.execPath, ['-e', 'const fs=require("node:fs"),http=require("node:http");http.createServer((req,res)=>{if(req.url==="/notification-frame"){res.setHeader("Content-Type","text/html");res.end("<!doctype html><html><body></body></html>");return;}if(req.url==="/avatar-fixture.svg"){res.setHeader("Content-Type","image/svg+xml");res.end(`<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="96" height="96" fill="orange"/></svg>`);return;}res.setHeader("Content-Type","text/html");res.end(fs.readFileSync(process.argv[req.url==="/inner"?1:2]));}).listen(0,"127.0.0.1",function(){process.stdout.write(String(this.address().port)+"\\n");});', target, wrapper], { stdio: ['ignore', 'pipe', 'pipe'] });
  const port = await new Promise((resolve, reject) => { server.once('error', reject); server.stdout.once('data', data => resolve(Number(String(data).trim()))); });
  let checks = 0;
  for (const width of [320, 375, 1024]) {
    const output = execFileSync(browser, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--hide-scrollbars', '--window-size=1200,800', '--virtual-time-budget=8000', '--user-data-dir='+path.join(temporary, 'profile-'+width), '--dump-dom', 'http://127.0.0.1:'+port+'/?width='+width], { encoding: 'utf8', timeout: 30000, maxBuffer: 8e6, stdio: ['ignore', 'pipe', 'pipe'] });
    const match = output.match(/<pre id="results">([\s\S]*?)<\/pre>/);
    assert.ok(match && match[1], 'Native UI consistency fixture must finish');
    const result = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
    assert.ok(result.ok, JSON.stringify(result)); checks += result.checks.length;
  }
  console.log(`PASS native UI consistency: search popovers, empty states, identity rows and load-more (${checks} checks)`);
} finally {
  if (server) server.kill();
  const resolved = fs.realpathSync(temporary), parent = fs.realpathSync(os.tmpdir());
  assert.equal(path.dirname(resolved), parent); assert.ok(path.basename(resolved).startsWith('tinycat-ui-consistency-'));
  fs.rmSync(resolved, { recursive: true, maxRetries: 5, retryDelay: 100 });
}
}()).catch(error => { console.error(error); process.exitCode = 1; });
