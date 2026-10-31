"use strict";
// Native Chromium + local deterministic HTTP JSON fixtures. No application DB writes.
const fs = require("node:fs"), path = require("node:path"), os = require("node:os"), http = require("node:http");
const zlib = require("node:zlib");
const devtoolsPipe = require("./devtools-pipe.cjs");
const { createHash } = require("node:crypto");
const { spawn, execFileSync } = require("node:child_process");
const root = path.resolve(__dirname, "../.."), out = path.join(root, "storage/performance/ajax-final");
const minutes = Number(process.argv.find(x => x.startsWith("--minutes="))?.split("=")[1] || 10);
const rounds = Number(process.argv.find(x => x.startsWith("--rounds="))?.split("=")[1] || 3);
const mobile = process.argv.includes("--mobile"), label = process.argv.find(x => x.startsWith("--label="))?.split("=")[1] || "";
if (label && !/^[a-z0-9-]+$/.test(label)) throw Error("Invalid output label");
const prefix = (mobile ? "mobile-" : "") + (label ? label + "-" : "");
if (!(minutes > 0 && minutes <= 60 && rounds > 0 && rounds <= 5)) throw Error("Invalid duration or rounds");
const browser = [process.env.TINYCAT_TEST_CHROME, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "/usr/bin/chromium", "/usr/bin/google-chrome"].filter(Boolean).find(fs.existsSync);
if (!browser) throw Error("Set TINYCAT_TEST_CHROME");
const read = name => JSON.parse(fs.readFileSync(path.join(out, name), "utf8").replace(/^\uFEFF/, ""));
const baseline = read("baseline-modules.json"), final = JSON.parse(execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/asset-graph-fixture.php"), "--all"], { encoding: "utf8", maxBuffer: 8e6 }));
fs.writeFileSync(path.join(out, prefix + "final-modules.json"), JSON.stringify(final));
const components = read("components.json"), catalog = read("catalog.json"), styles = read("styles.json");
const fixtures = JSON.parse(fs.readFileSync(path.join(root, "tests/fixtures/components.json"), "utf8"));
const post = fixtures.find(x => x.component === "status/card").data.item;
const scheduler = fixtures.find(x => x.component === "scheduler/list")?.data || { tasks: [], pagination: {} };
function model(route = "/") {
  const url = new URL(route, "http://fixture.test"), kind = url.pathname.startsWith("/author/") ? "author" : url.pathname.startsWith("/status/") ? "status" : "home";
  return { kind, url: url.pathname + url.search, locale: "en", version: "bench", viewer: { id: 0 }, compact: false,
    document: { title: "Benchmark " + kind, url: url.pathname + url.search, description: "Deterministic benchmark" },
    author: post.author, bio: "Benchmark profile", follow_counts: { followers: 5, following: 4 }, activity: { posts: 20 },
    item: post, post, comments: [], items: Array.from({ length: 12 }, (_, i) => ({ ...post, id: i + 101, url: "/status/" + (i + 101) })),
    feed: "all", feed_id: "status-feed-all", sidebar: true, sidebar_url: "/api/site/sidebar", next_url: "/api/statuses?cursor_id=112", all_feed_url: "/", following_feed_url: "/?feed=following", images_enabled: true, composer: false };
}
const records = new Map();
const scripts = { baseline: baseline.map(x => x.source).join("\n;\n"), final: final.map(x => x.source).join("\n;\n") };
const envelope = data => ({ ok: true, status: 200, code: "ok", params: {}, message: null, data, meta: { pagination: { done: true, next_url: "", page: 1, per_page: 20 } } });
const fixtureSource = fs.readFileSync(path.join(__dirname, "ajax-session-fixture.js"), "utf8");
const server = http.createServer((req, res) => {
  const url = new URL(req.url, "http://localhost");
  if (url.pathname.startsWith("/bench/")) {
    const [, , variant, round] = url.pathname.split("/");
    res.setHeader("Content-Type", "text/html; charset=utf-8");
    res.end(`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="fixture"><style>${styles}</style></head><body data-current-user-id="0" data-application-version="bench"><header><div data-notification-menu><button data-notification-button data-notification-interval="5000" data-notification-unread="0" data-notification-latest-id="0">Notifications</button><div data-notification-popover hidden><div data-notification-list></div></div></div><form data-global-search action="/search"><input data-global-search-input><div data-global-search-results hidden></div></form></header><main id="main-content" tabindex="-1"><div class="container"><section data-public-page="home"></section></div></main><script>history.replaceState({},'', '/');window.__fixture=${JSON.stringify({ variant, round, initial: model(), post, scheduler })};</script><script src="/scripts/${variant}"></script><script>window.TinyCat.components.register(${JSON.stringify(components).replace(/</g, "\\u003c")});window.TinyCat.i18n.register('en',${JSON.stringify(catalog).replace(/</g, "\\u003c")});</script><script src="/fixture"></script></body></html>`);
    return;
  }
  if (url.pathname.startsWith("/scripts/")) { res.setHeader("Content-Type", "text/javascript"); res.end(scripts[url.pathname.split("/")[2]]); return; }
  if (url.pathname === "/fixture") { res.setHeader("Content-Type", "text/javascript"); res.end(fixtureSource); return; }
  if (url.pathname.startsWith("/api/")) {
    const key = req.headers["x-bench-session"] || "unknown", rows = records.get(key) || []; records.set(key, rows);
    rows.push({ path: url.pathname, at: Date.now() });
    let data = {};
    if (url.pathname === "/api/site/pages") { const query = new URLSearchParams(url.searchParams); const p = query.get("path"); query.delete("path"); data = model(p + (query.size ? "?" + query : "")); }
    else if (url.pathname === "/api/site/sidebar") data = { tags: [], users: [], active_users: [], stats: {} };
    else if (url.pathname === "/api/statuses") data = { items: Array.from({ length: 6 }, (_, i) => ({ ...post, id: 200 + i, url: "/status/" + (200 + i) })) };
    else if (url.pathname === "/api/search") data = { query: url.searchParams.get("q"), tags: [], users: [], content: [] };
    else if (url.pathname === "/api/notifications") data = { unread_count: 0, latest_id: 0, items: [] };
    else if (url.pathname === "/api/scheduler/tasks") data = scheduler;
    setTimeout(() => { res.setHeader("Content-Type", "application/json"); res.end(JSON.stringify(envelope(data))); }, 20);
    return;
  }
  res.statusCode = 404; res.end("");
});
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
class CDP {
  constructor(ws) { this.ws = ws; this.serial = 0; this.pending = new Map(); ws.addEventListener("close", () => { for (const pending of this.pending.values()) pending.reject(Error("Browser pipe closed")); this.pending.clear(); }); ws.addEventListener("message", event => { const msg = JSON.parse(event.data); if (msg.id) { const p = this.pending.get(msg.id); this.pending.delete(msg.id); if (msg.error) p.reject(Error(JSON.stringify(msg.error))); else p.resolve(msg.result); } }); }
  send(method, params = {}, sessionId) { return new Promise((resolve, reject) => { const id = ++this.serial; this.pending.set(id, { resolve, reject }); this.ws.send(JSON.stringify({ id, method, params, sessionId })); }); }
  async evaluate(session, expression) { const r = await this.send("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true, timeout: 180000 }, session); if (r.exceptionDetails) throw Error(JSON.stringify(r.exceptionDetails)); return r.result.value; }
  async snapshot(session, name) {
    const file = path.join(out, prefix + name + ".heapsnapshot.gz"), stream = fs.createWriteStream(file), gzip = zlib.createGzip(); gzip.pipe(stream);
    const listener = event => { const msg = JSON.parse(event.data); if (msg.sessionId === session && msg.method === "HeapProfiler.addHeapSnapshotChunk") gzip.write(msg.params.chunk); };
    this.ws.addEventListener("message", listener);
    try { await this.send("HeapProfiler.takeHeapSnapshot", { reportProgress: false, captureNumericValue: true }, session); }
    finally { this.ws.removeEventListener("message", listener); gzip.end(); await new Promise((resolve, reject) => { stream.once("finish", resolve); stream.once("error", reject); }); }
    return { file: path.relative(root, file), bytes: fs.statSync(file).size };
  }
}
(async () => {
  const temporary = fs.mkdtempSync(path.join(os.tmpdir(), "tinycat-ajax-run-")); let chrome, cdp;
  const report = { date: "2026-10-31", viewport: mobile ? "390x844" : "1280x900", baseline: "v5.13.0 e73ffb94cde266adcc163a50d6d2e48c44bc26f8", minutes, rounds, method: "Native Chrome, actual module sources, common component/CSS/data fixtures, controlled 20ms HTTP JSON; not live application DB/LCP/INP. Isolated sites in one browser, background throttling disabled. Hidden phase uses controlled document.hidden events. Trace arrays drained before heap comparisons.", runs: [] };
  report.source_sha256 = Object.fromEntries(Object.entries(scripts).map(([name, value]) => [name, createHash("sha256").update(value).digest("hex")]));
  try {
    await new Promise(resolve => server.listen(0, "127.0.0.1", resolve)); const port = server.address().port;
    chrome = spawn(browser, ["--headless", "--disable-gpu", "--no-first-run", "--disable-extensions", "--site-per-process", "--disable-background-timer-throttling", "--disable-renderer-backgrounding", "--disable-backgrounding-occluded-windows", "--remote-debugging-pipe", "--user-data-dir=" + temporary, "about:blank"], { windowsHide: true, stdio: ["ignore", "ignore", "ignore", "pipe", "pipe"] });
    cdp = new CDP(devtoolsPipe(chrome));
    report.browser = await cdp.send("Browser.getVersion");
    const sessions = [];
    for (let round = 0; round < rounds; round++) for (const variant of round % 2 ? ["final", "baseline"] : ["baseline", "final"]) {
      const { targetId } = await cdp.send("Target.createTarget", { url: "about:blank" }); const { sessionId } = await cdp.send("Target.attachToTarget", { targetId, flatten: true });
      await cdp.send("Page.enable", {}, sessionId); await cdp.send("Runtime.enable", {}, sessionId);
      await cdp.send("Emulation.setDeviceMetricsOverride", { width: mobile ? 390 : 1280, height: mobile ? 844 : 900, deviceScaleFactor: 1, mobile }, sessionId);
      await cdp.send("Page.navigate", { url: `http://${variant}${round}.localhost:${port}/bench/${variant}/${round}` }, sessionId);
      let ready; for (let i = 0; i < 400; i++) { ready = await cdp.evaluate(sessionId, "window.__ready || window.__fixtureError || false"); if (ready) break; await sleep(25); }
      if (ready !== true) throw Error("Fixture did not start: " + ready);
      const actualViewport = await cdp.evaluate(sessionId, "({width:innerWidth,height:innerHeight})");
      if (actualViewport.width !== (mobile ? 390 : 1280)) throw Error("Unexpected CSS viewport: " + JSON.stringify(actualViewport));
      await cdp.evaluate(sessionId, "window.setFixtureHidden(false)");
      await cdp.send("HeapProfiler.collectGarbage", {}, sessionId);
      const start = { dom: await cdp.send("Memory.getDOMCounters", {}, sessionId), heap: await cdp.send("Runtime.getHeapUsage", {}, sessionId) };
      const short = await cdp.evaluate(sessionId, "window.runSession()");
      await cdp.evaluate(sessionId, "window.resetTraces()");
      await cdp.send("HeapProfiler.collectGarbage", {}, sessionId);
      const after = { dom: await cdp.send("Memory.getDOMCounters", {}, sessionId), heap: await cdp.send("Runtime.getHeapUsage", {}, sessionId) };
      const run = { variant, round, viewport: actualViewport, start, short, after, samples: [] }; report.runs.push(run); sessions.push({ sessionId, run });
      if (round === 0 && minutes >= 1) run.heapBefore = await cdp.snapshot(sessionId, variant + "-before");
      console.log(variant, round, "cycles complete", JSON.stringify({ failures: short.failures, requests: short.requests.length, nodes: after.dom.nodes, listeners: after.dom.jsEventListeners }));
    }
    const started = Date.now(), duration = minutes * 60000;
    report.polling_started_at = started;
    for (const { sessionId } of sessions) await cdp.evaluate(sessionId, "window.beginPollingPhase()");
    let hidden = false, nextSample = 0;
    while (Date.now() - started < duration) {
      const elapsed = Date.now() - started, hide = elapsed >= duration * .4 && elapsed < duration * .6;
      if (hidden !== hide) { hidden = hide; for (const { sessionId } of sessions) await cdp.evaluate(sessionId, `window.setFixtureHidden(${hide})`); }
      if (elapsed >= nextSample) {
        for (const { sessionId, run } of sessions) { await cdp.send("HeapProfiler.collectGarbage", {}, sessionId); run.samples.push({ elapsed, hidden, dom: await cdp.send("Memory.getDOMCounters", {}, sessionId), heap: await cdp.send("Runtime.getHeapUsage", {}, sessionId), client: await cdp.evaluate(sessionId, "window.sampleSession()") }); }
        fs.writeFileSync(path.join(out, prefix + "long-run-progress.json"), JSON.stringify(report, null, 2)); console.log("polling sample", Math.round(elapsed / 1000), "seconds", hidden ? "hidden" : "visible"); nextSample += Math.max(10000, duration / 5);
      }
      await sleep(1000);
    }
    for (const { sessionId, run } of sessions) { await cdp.evaluate(sessionId, "window.setFixtureHidden(false)"); run.end = await cdp.evaluate(sessionId, "window.sampleSession()"); await cdp.send("HeapProfiler.collectGarbage", {}, sessionId); run.finalDom = await cdp.send("Memory.getDOMCounters", {}, sessionId); run.finalHeap = await cdp.send("Runtime.getHeapUsage", {}, sessionId); if (run.round === 0 && minutes >= 1) run.heapAfter = await cdp.snapshot(sessionId, run.variant + "-after"); }
    report.elapsed_ms = Date.now() - started; report.server_requests = Object.fromEntries(records);
    fs.writeFileSync(path.join(out, prefix + "long-run-results.json"), JSON.stringify(report, null, 2));
    console.log("COMPLETE", path.join(out, prefix + "long-run-results.json"));
  } finally {
    if (cdp) { try { await cdp.send("Browser.close"); } catch (_) {} cdp.ws.close(); }
    if (chrome && chrome.exitCode === null) { chrome.kill(); }
    await new Promise(resolve => server.close(resolve));
    // Preserve the isolated browser profile as a diagnostic artifact; no recursive deletion.
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
