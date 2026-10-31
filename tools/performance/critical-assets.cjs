"use strict";
// Experimental delivery only. Does not alter templates, CSP or production assets.
const fs = require("node:fs"), path = require("node:path"), os = require("node:os"), http = require("node:http"), zlib = require("node:zlib");
const { spawn, execFileSync } = require("node:child_process"), { createHash } = require("node:crypto");
const root = path.resolve(__dirname, "../.."), directory = path.resolve(process.argv.find(x => x.startsWith("--directory="))?.slice(12) || path.join(root, "storage/performance/critical-assets-20261031"));
const rounds = Number(process.argv.find(x => x.startsWith("--rounds="))?.slice(9) || 3);
const fixedCritical = process.argv.find(x => x.startsWith("--fixed-critical="))?.slice(17);
if (!Number.isInteger(rounds) || rounds < 1 || rounds > 10) throw Error("Invalid rounds");
const fixture = JSON.parse(fs.readFileSync(path.join(directory, "fixture.json"), "utf8"));
const browser = [process.env.TINYCAT_TEST_CHROME, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "/usr/bin/chromium"].filter(Boolean).find(fs.existsSync);
if (!browser) throw Error("Set TINYCAT_TEST_CHROME");
const hash = value => createHash("sha256").update(value).digest("hex"), gzip = value => zlib.gzipSync(value, { level: 9 });
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let critical = "", rules = null;
const variants = ["external", "full-css", "critical-blocking", "critical-async", "runtime-inline"];
const measuredVariants = process.argv.find(x => x.startsWith("--variants="))?.slice(11).split(",") || variants;
if (measuredVariants.some(variant => !variants.includes(variant))) throw Error("Invalid variants");
const loader = '(()=>{const l=document.querySelector("link[data-async-style]");const apply=()=>{l.rel="stylesheet";};l.addEventListener("load",apply,{once:true});if(l.sheet)apply();})();';
const scriptHash = source => "'sha256-" + createHash("sha256").update(source).digest("base64") + "'";
const scriptEscape = source => source.replace(/<\/script/gi, "<\\/script");
function html(name, variant, theme = "light") {
  const page = fixture.pages[name], css = fixture.assets[page.style], scripts = [...page.scripts];
  let value = page.html.replace(/ data-theme="[^"]*"/g, "").replace(/<html\b/, '<html data-theme="' + theme + '"');
  let count = 0;
  value = value.replace(/<script\b[^>]*\bsrc="[^"]+"[^>]*>\s*<\/script>/g, () => { count++; return ""; });
  if (count !== 4) throw Error("Expected four external SSR scripts, got " + count);
  let style = '<link rel="stylesheet" href="' + page.style + '">', inline = [];
  if (variant === "full-css") style = "<style>" + css + "</style>";
  if (variant.startsWith("critical")) style = "<style>" + critical + "</style>" + (variant === "critical-only" ? "" : style);
  if (variant === "critical-async") {
    inline.push(loader);
    style = '<style>' + critical + '</style><link rel="preload" as="style" href="' + page.style + '" data-async-style><script>' + loader + '</script><noscript><link rel="stylesheet" href="' + page.style + '"></noscript>';
  }
  let styles = 0;
  value = value.replace(/<link\b[^>]*rel="stylesheet"[^>]*>/g, () => { styles++; return style; });
  if (styles !== 1) throw Error("Expected one stylesheet");
  if (variant === "runtime-inline") {
    const source = scriptEscape(fixture.assets[scripts.shift()]); inline.push(source);
    value = value.replace("</body>", () => "<script>" + source + "</script></body>");
  }
  value = value.replace("</head>", scripts.map(url => '<script defer src="' + url + '"></script>').join("") + "</head>");
  return { value, csp: "default-src 'self'; base-uri 'self'; object-src 'none'; script-src 'self' " + inline.map(scriptHash).join(" ") + "; script-src-attr 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; worker-src 'self'" };
}
const zipped = new Map();
function send(res, body, type, cached = true, extra = {}) {
  const buffer = Buffer.isBuffer(body) ? body : Buffer.from(body), key = hash(buffer);
  if (!zipped.has(key)) zipped.set(key, gzip(buffer));
  const data = zipped.get(key);
  res.writeHead(200, { "Content-Type": type, "Content-Encoding": "gzip", "Content-Length": data.length, "Cache-Control": cached ? "public, max-age=31536000, immutable" : "no-store", ...extra }); res.end(data);
}
const server = http.createServer((req, res) => {
  try {
    const url = new URL(req.url, "http://fixture.test"), pathname = url.pathname;
    if (fixture.assets[pathname] !== undefined) return send(res, fixture.assets[pathname], pathname.endsWith(".css") ? "text/css" : pathname.endsWith(".svg") ? "image/svg+xml" : "text/javascript");
    if (pathname.startsWith("/assets/")) {
      const file = path.resolve(root, "." + pathname), allowed = path.join(root, "assets") + path.sep;
      if (file.startsWith(allowed) && fs.existsSync(file) && fs.statSync(file).isFile()) return send(res, fs.readFileSync(file), pathname.endsWith(".svg") ? "image/svg+xml" : "application/octet-stream");
    }
    if (pathname.startsWith("/avatar/")) return send(res, fs.readFileSync(path.join(root, "assets/fallback/avatar.svg")), "image/svg+xml");
    if (pathname === "/api/site/sidebar") return send(res, JSON.stringify(fixture.sidebar), "application/json", false);
    if (pathname.startsWith("/api/")) return send(res, JSON.stringify({ ok: true, data: { items: [] }, meta: { pagination: { done: true } } }), "application/json", false);
    if (pathname.endsWith(".webmanifest")) return send(res, JSON.stringify({ name: "Fixture", start_url: "/" }), "application/manifest+json");
    const entry = Object.entries(fixture.pages).find(([, page]) => page.path === pathname);
    if (entry) { const page = html(entry[0], req.headers["x-critical-variant"] || "external", req.headers["x-critical-theme"] || "light"); return send(res, page.value, "text/html; charset=utf-8", false, { "Content-Security-Policy": page.csp }); }
    res.writeHead(404); res.end();
  } catch (error) { res.writeHead(500); res.end(String(error)); }
});
class CDP {
  constructor(ws) { this.ws = ws; this.serial = 0; this.pending = new Map(); this.events = new Map(); ws.addEventListener("message", event => { const msg = JSON.parse(event.data); if (msg.id) { const p = this.pending.get(msg.id); this.pending.delete(msg.id); if (msg.error) p.reject(Error(JSON.stringify(msg.error))); else p.resolve(msg.result); } else if (msg.sessionId) { const events = this.events.get(msg.sessionId); if (events) events.push(msg); } }); }
  send(method, params = {}, sessionId) { const id = ++this.serial; return new Promise((resolve, reject) => { this.pending.set(id, { resolve, reject }); this.ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) })); }); }
  async evaluate(session, expression) { const result = await this.send("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true }, session); if (result.exceptionDetails) throw Error(JSON.stringify(result.exceptionDetails)); return result.result.value; }
}
const instrumentation = `(()=>{window.__measure={lcp:0,cls:0,longTasks:[],errors:[],csp:[]};for(const type of ['largest-contentful-paint','layout-shift','longtask'])try{new PerformanceObserver(list=>{for(const e of list.getEntries()){if(type==='largest-contentful-paint'){__measure.lcp=e.startTime;__measure.lcpElement=e.element?.tagName;}if(type==='layout-shift'&&!e.hadRecentInput)__measure.cls+=e.value;if(type==='longtask')__measure.longTasks.push(e.duration);}}).observe({type,buffered:true});}catch(e){}addEventListener('error',e=>__measure.errors.push(e.message||e.target?.src||'Resource error'),true);addEventListener('unhandledrejection',e=>__measure.errors.push(String(e.reason)));addEventListener('securitypolicyviolation',e=>__measure.csp.push(e.violatedDirective+':'+e.blockedURI));})();`;
function usedRules() {
  const sheet = Array.from(document.styleSheets).find(s => s.href), used = [];
  function walk(list, prefix) { Array.from(list).forEach((rule, index) => { const key = prefix + index; if (rule.type === 1) { let matches; try { matches = Array.from(document.querySelectorAll(rule.selectorText.replace(/::[\w-]+(?:\([^)]*\))?/g, ""))); } catch (_) { used.push(key); return; } const structural = ["display", "visibility", "opacity", "position", "clip", "clip-path", "transform", "content-visibility", "top", "left", "right", "bottom", "inset", "overflow"].some(p => rule.style.getPropertyValue(p)); if ((structural && matches.length) || matches.some(node => { const r = node.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.bottom >= 0 && r.top <= innerHeight + 120 && r.right >= 0 && r.left <= innerWidth; })) used.push(key); } else if (rule.cssRules) walk(rule.cssRules, key + "/"); else used.push(key); }); }
  walk(sheet.cssRules, ""); return used;
}
function serializeRules() {
  const sheet = Array.from(document.styleSheets).find(s => s.href);
  function walk(list, prefix) { return Array.from(list).map((rule, index) => { const key = prefix + index; if (rule.type === 1) return window.__selected.includes(key) ? rule.cssText : ""; if (rule.cssRules) { const children = walk(rule.cssRules, key + "/").trim(); return children ? rule.cssText.slice(0, rule.cssText.indexOf("{")) + "{" + children + "}" : ""; } return window.__selected.includes(key) ? rule.cssText : ""; }).join("\n"); }
  return walk(sheet.cssRules, "");
}
function fingerprint() {
  const properties = ["display", "position", "fontSize", "fontWeight", "lineHeight", "fontFamily", "color", "backgroundColor", "padding", "margin", "borderWidth", "borderRadius", "gap", "gridTemplateColumns", "alignItems", "whiteSpace", "overflow"];
  return Array.from(document.body.querySelectorAll("*")).map((node, index) => { const r = node.getBoundingClientRect(), s = getComputedStyle(node); if ((node.checkVisibility && !node.checkVisibility({checkOpacity:true,checkVisibilityCSS:true})) || !r.width || !r.height || r.bottom <= 0 || r.top >= innerHeight || r.right <= 0 || r.left >= innerWidth) return null; return { index, tag: node.tagName, rect: [r.x, r.y, r.width, r.height].map(x => Math.round(x * 10) / 10), style: properties.map(p => s[p]) }; }).filter(Boolean);
}
(async () => {
  let chrome, cdp;
  const report = { method: "Actual anonymous SSR static replay, production-minified CSS/JS, gzip and immutable cache; HTTP/1.1 local server with CDP network/CPU simulation, not production backend/LCP field data. Avatar requests use a shared local fallback SVG.", rounds, tool_sha256: hash(fs.readFileSync(__filename)), measuredVariants, validation: [], runs: [], source_sha256: Object.fromEntries(Object.entries(fixture.assets).map(([url, source]) => [url, hash(source)])) };
  try {
    await new Promise(resolve => server.listen(0, "127.0.0.1", resolve)); const origin = "http://127.0.0.1:" + server.address().port;
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), "tinycat-critical-"));
    chrome = spawn(browser, ["--headless", "--disable-gpu", "--no-first-run", "--disable-extensions", "--remote-debugging-port=0", "--user-data-dir=" + temporary, "about:blank"], { windowsHide: true, stdio: "ignore" });
    const active = path.join(temporary, "DevToolsActivePort"); for (let i = 0; i < 200 && !fs.existsSync(active); i++) await sleep(50);
    const [port, endpoint] = fs.readFileSync(active, "utf8").trim().split(/\r?\n/), ws = new WebSocket("ws://127.0.0.1:" + port + endpoint);
    await new Promise((resolve, reject) => { ws.addEventListener("open", resolve, { once: true }); ws.addEventListener("error", reject, { once: true }); }); cdp = new CDP(ws); report.browser = await cdp.send("Browser.getVersion");
    async function target(profile, js = true) {
      const { browserContextId } = await cdp.send("Target.createBrowserContext", { disposeOnDetach: true });
      const { targetId } = await cdp.send("Target.createTarget", { url: "about:blank", browserContextId });
      const { sessionId } = await cdp.send("Target.attachToTarget", { targetId, flatten: true });
      await cdp.send("Page.enable", {}, sessionId); await cdp.send("Runtime.enable", {}, sessionId); await cdp.send("Network.enable", {}, sessionId);
      await cdp.send("Page.addScriptToEvaluateOnNewDocument", { source: instrumentation }, sessionId);
      await cdp.send("Emulation.setDeviceMetricsOverride", { width: profile.width, height: profile.height, deviceScaleFactor: 1, mobile: profile.width < 600 }, sessionId);
      await cdp.send("Emulation.setCPUThrottlingRate", { rate: profile.cpu || 1 }, sessionId);
      await cdp.send("Emulation.setScriptExecutionDisabled", { value: !js }, sessionId);
      await cdp.send("Network.emulateNetworkConditions", { offline: false, latency: profile.latency || 0, downloadThroughput: profile.bandwidth || 1e8, uploadThroughput: profile.bandwidth || 1e8 }, sessionId);
      return { sessionId, close: () => cdp.send("Target.disposeBrowserContext", { browserContextId }) };
    }
    async function visit(t, name, variant, theme = "light", settle = 200) {
      cdp.events.set(t.sessionId, []);
      const previousOrigin = await cdp.evaluate(t.sessionId, "performance.timeOrigin");
      await cdp.send("Network.setExtraHTTPHeaders", { headers: { "X-Critical-Variant": variant, "X-Critical-Theme": theme } }, t.sessionId);
      await cdp.send("Page.navigate", { url: origin + fixture.pages[name].path }, t.sessionId);
      for (let i = 0; i < 800; i++) { if (await cdp.evaluate(t.sessionId, "performance.timeOrigin!==" + previousOrigin + " && document.readyState==='complete' && !!document.querySelector('#main-content')")) break; if (i === 799) throw Error("Load timeout: " + name + "/" + variant); await sleep(25); }
      await sleep(settle);
      return cdp.evaluate(t.sessionId, `({...window.__measure,fcp:performance.getEntriesByName('first-contentful-paint')[0]?.startTime||0,navigation:performance.getEntriesByType('navigation')[0]?.toJSON(),boot:!!window.TinyCat?.__statusFeedClickReady,styleReady:Array.from(document.styleSheets).some(s=>s.href&&s.media.mediaText!=='print'),nodes:document.querySelectorAll('*').length})`);
    }
    // Union selectors across actual SSR and hydrated pages, responsive sizes and themes.
    const selected = new Set();
    if (!fixedCritical) for (const width of [390, 760, 1280]) for (const theme of ["light", "dark"]) for (const js of [false, true]) {
      const t = await target({ width, height: 900 }, js);
      for (const name of Object.keys(fixture.pages)) { await visit(t, name, "external", theme, 50); (await cdp.evaluate(t.sessionId, "(" + usedRules.toString() + ")()")).forEach(key => selected.add(key)); }
      rules = await cdp.evaluate(t.sessionId, "Array.from(document.styleSheets).find(s=>s.href).cssRules.length");
      await cdp.evaluate(t.sessionId, "window.__selected=" + JSON.stringify([...selected])); critical = await cdp.evaluate(t.sessionId, "(" + serializeRules.toString() + ")()"); await t.close();
    }
    if (fixedCritical) { critical = fs.readFileSync(path.resolve(fixedCritical), "utf8"); }
    else {
      const extracted = path.join(directory, "critical-extracted.css"); fs.writeFileSync(extracted, critical);
      critical = execFileSync(process.env.TINYCAT_TEST_PHP || "php", ["-r", "define('TINYCAT',true);require $argv[1];echo TinyCat\\Support\\Minifier::minifyCss(file_get_contents($argv[2]));", path.join(root, "App/autoload.php"), extracted], { encoding: "utf8" });
    }
    fs.writeFileSync(path.join(directory, "critical.css"), critical); report.critical = { raw: Buffer.byteLength(critical), gzip: gzip(critical).length, sha256: hash(critical), selected_rules: fixedCritical ? null : selected.size, top_level_rules: rules, fixed_source: fixedCritical || null };
    console.log("EXTRACTED", JSON.stringify(report.critical));
    for (const width of [390, 760, 1280]) for (const theme of ["light", "dark"]) {
      const t = await target({ width, height: 900 }, false);
      for (const name of Object.keys(fixture.pages)) {
        await visit(t, name, "external", theme, 30); const expected = await cdp.evaluate(t.sessionId, "(" + fingerprint.toString() + ")()");
        const capture = name === "home" && width === 390 && theme === "light" || name === "status" && width === 1280 && theme === "dark";
        if (capture) { const shot = await cdp.send("Page.captureScreenshot", { format: "png" }, t.sessionId); fs.writeFileSync(path.join(directory, `baseline-${name}-${width}-${theme}.png`), Buffer.from(shot.data, "base64")); }
        await visit(t, name, "critical-only", theme, 30); const actual = await cdp.evaluate(t.sessionId, "(" + fingerprint.toString() + ")()");
        if (capture) { const shot = await cdp.send("Page.captureScreenshot", { format: "png" }, t.sessionId); fs.writeFileSync(path.join(directory, `critical-${name}-${width}-${theme}.png`), Buffer.from(shot.data, "base64")); }
        const ok = JSON.stringify(expected) === JSON.stringify(actual); report.validation.push({ name, width, theme, elements: expected.length, ok });
        if (!ok) fs.writeFileSync(path.join(directory, `mismatch-${name}-${width}-${theme}.json`), JSON.stringify({ expected, actual }, null, 2));
      }
      await t.close();
    }
    console.log("FINGERPRINT", report.validation.filter(x => x.ok).length + "/" + report.validation.length);
    if (report.validation.some(x => !x.ok)) { fs.writeFileSync(path.join(directory, "results.json"), JSON.stringify(report, null, 2)); throw Error("Critical CSS geometry/style mismatch; timing not accepted"); }
    if (process.argv.includes("--validate-only")) {
      report.nojs = [];
      for (const width of [390, 760, 1280]) for (const theme of ["light", "dark"]) {
        const t = await target({ width, height: 900 }, false);
        for (const name of Object.keys(fixture.pages)) {
          await visit(t, name, "external", theme, 30); const expected = await cdp.evaluate(t.sessionId, "(" + fingerprint.toString() + ")()");
          await visit(t, name, "critical-async", theme, 30); const actual = await cdp.evaluate(t.sessionId, "(" + fingerprint.toString() + ")()");
          report.nojs.push({ name, width, theme, ok: JSON.stringify(expected) === JSON.stringify(actual) });
        }
        await t.close();
      }
      fs.writeFileSync(path.join(directory, "validation.json"), JSON.stringify(report, null, 2));
      if (report.nojs.some(x => !x.ok)) throw Error("No-JS fallback mismatch");
      console.log("VALIDATED critical and no-JS fallback"); return;
    }
    report.payloads = Object.fromEntries(variants.map(variant => [variant, Object.fromEntries(Object.keys(fixture.pages).map(name => { const value = html(name, variant).value; return [name, { html_raw: Buffer.byteLength(value), html_gzip: gzip(value).length }]; }))]));
    const profiles = [{ name: "desktop", width: 1280, height: 900, cpu: 1, latency: 20, bandwidth: 1250000 }, { name: "mobile", width: 390, height: 844, cpu: 4, latency: 150, bandwidth: 200000 }];
    for (const profile of profiles) for (let round = 0; round < rounds; round++) {
      const order = round % 2 ? [...measuredVariants].reverse() : [...measuredVariants];
      for (const name of ["home", "status"]) for (const variant of order) {
        const t = await target(profile);
        for (const cache of ["cold", "warm"]) {
          const result = await visit(t, name, variant, "light", 500), events = cdp.events.get(t.sessionId);
          const finished = events.filter(e => e.method === "Network.loadingFinished"), responses = events.filter(e => e.method === "Network.responseReceived");
          report.runs.push({ profile: profile.name, round, name, variant, cache, ...result, transferred: finished.reduce((sum, e) => sum + e.params.encodedDataLength, 0), requests: responses.length, fromCache: responses.filter(e => e.params.response.fromDiskCache).length, failures: events.filter(e => e.method === "Network.loadingFailed").map(e => e.params.errorText) });
        }
        await t.close();
      }
      fs.writeFileSync(path.join(directory, "results-progress.json"), JSON.stringify(report, null, 2)); console.log("ROUND", profile.name, round, "complete");
    }
    fs.writeFileSync(path.join(directory, "results.json"), JSON.stringify(report, null, 2)); console.log("COMPLETE", path.join(directory, "results.json"));
  } finally {
    if (cdp) { try { await cdp.send("Browser.close"); } catch (_) {} cdp.ws.close(); }
    if (chrome && chrome.exitCode === null) chrome.kill();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
