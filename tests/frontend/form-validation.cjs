"use strict";
const assert = require('node:assert/strict');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path');
const {execFileSync,spawn} = require('node:child_process');
const browser = [process.env.TINYCAT_TEST_CHROME, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].filter(Boolean).find(file => fs.existsSync(file));
if (!browser) { console.log('SKIP native form validation: set TINYCAT_TEST_CHROME'); process.exit(0); }
const root = path.resolve(__dirname, '../..'), php = process.env.TINYCAT_TEST_PHP || 'php';
const assets = JSON.parse(execFileSync(php, [path.join(root,'tests/presentation/asset-graph-fixture.php'),'--validation'], {encoding:'utf8',maxBuffer:8e6}));
const templates = JSON.parse(execFileSync(php, [path.join(root,'tests/presentation/component-parity.php'),'--templates'], {encoding:'utf8',maxBuffer:8e6}));
const source = assets.map(asset => asset.source).join('\n');
const css = JSON.parse(execFileSync(php, [path.join(root,'tests/presentation/asset-graph-fixture.php'),'--styles'], {encoding:'utf8',maxBuffer:8e6}));
const fixture = fs.readFileSync(path.join(__dirname,'form-validation-fixture.js'),'utf8');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(),'tinycat-form-validation-'));
let server;
(async function () {
try {
  const file = path.join(temporary,'fixture.html');
  fs.writeFileSync(file, `<!doctype html><html lang="en"><head><meta name="csrf-token" content="test"><style>${css}</style></head><body><button id="opener">Open</button><pre id="validation-test-results"></pre><script>${source}\nTinyCat.components.register(${JSON.stringify(templates).replace(/</g,'\\u003c')});\n${fixture}</script></body></html>`);
  server = spawn(process.execPath, ['-e', 'const fs=require("node:fs"),http=require("node:http");http.createServer((req,res)=>{res.setHeader("Content-Type","text/html");res.end(fs.readFileSync(process.argv[1]));}).listen(0,"127.0.0.1",function(){process.stdout.write(String(this.address().port)+"\\n");});', file], {stdio:['ignore','pipe','pipe']});
  const port = await new Promise((resolve,reject) => { server.once('error',reject); server.stdout.once('data',data=>resolve(Number(String(data).trim()))); });
  let checks = 0;
  for (const mode of ['moderation','report','server','native','success','scroll','summary','nested','proxy']) {
    const url = new URL('http://127.0.0.1:'+port+'/fixture.html'); url.searchParams.set('case',mode);
    const output = execFileSync(browser, ['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-sync','--hide-scrollbars','--window-size=375,812','--virtual-time-budget=15000','--user-data-dir='+path.join(temporary,'profile-'+mode),'--dump-dom',url.href], {encoding:'utf8',timeout:30000,maxBuffer:8e6,stdio:['ignore','pipe','pipe']});
    const match = output.match(/<pre id="validation-test-results">([\s\S]*?)<\/pre>/);
    assert.ok(match && match[1], 'The native validation fixture must finish: '+mode);
    const result = JSON.parse(match[1].replace(/&quot;/g,'"').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&'));
    assert.ok(result.ok, JSON.stringify(result)); checks += result.checks.length;
  }
  console.log(`PASS native modal validation (${checks} checks)`);
} finally {
  if (server) server.kill();
  const resolved = fs.realpathSync(temporary), parent = fs.realpathSync(os.tmpdir());
  assert.equal(path.dirname(resolved),parent); assert.ok(path.basename(resolved).startsWith('tinycat-form-validation-'));
  fs.rmSync(resolved,{recursive:true,maxRetries:5,retryDelay:100});
}
}()).catch(error=>{console.error(error);process.exitCode=1;});
