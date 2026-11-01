"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), path = require("node:path"), vm = require("node:vm");
const tick = () => new Promise(resolve => setImmediate(resolve));
class Node {
  constructor(text = "") { this.children = []; this.dataset = {}; this.hidden = true; this.text = text; this.handlers = {}; this.isConnected = true; this.classList = { add() {} }; }
  appendChild(node) { this.children.push(node); }
  replaceChildren(...nodes) { this.children = nodes; }
  get textContent() { return this.text + this.children.map(child => child.textContent).join(""); }
  set textContent(text) { this.text = text; this.children = []; }
  setAttribute() {} removeAttribute() {} blur() {}
  addEventListener(name, callback) { this.handlers[name] = callback; }
}
function setup() {
  const input = new Node(), box = new Node(), root = new Node(), pending = [], timers = new Map(), services = {};
  let timer = 0, clock = Date.now();
  class FixtureDate extends Date { static now() { return clock; } }
  box.parentElement = root;
  root.contains = node => node === input; root.matches = selector => selector === "[data-global-search]"; root.querySelectorAll = () => [];
  const document = { body: { dataset: {} }, documentElement: { lang: "en" }, addEventListener() {} };
  const T = { dom: { find: selector => selector === "[data-global-search-input]" ? input : box, all: (selector, scope) => scope === root ? [] : [root], create: (_, __, text) => new Node(text || "") },
    components: { render: (_name, data) => new Node(data.label || "") },
    popovers: { open() { box.hidden = false; }, close() { box.hidden = true; if (root.handlers["tinycat:popover-close"]) root.handlers["tinycat:popover-close"](); }, restoringFocus: () => false },
    ui: {}, lifecycle: { register: (name, service) => { services[name] = service; }, hydrate() {} }, navigation: { compact: url => url.pathname + url.search },
    api: { unwrap: response => response.data, request: (url, options) => new Promise((resolve, reject) => { pending.push({ url, options, resolve, reject }); }) }, i18n: { label: key => key, t: key => key, plural: key => key }, dates: { format: () => "" } };
  const window = { TinyCat: T, location: new URL("https://tinycat.test/"), AbortController, setTimeout: callback => { timers.set(++timer, callback); return timer; }, clearTimeout: id => timers.delete(id) };
  const context = vm.createContext({ window, document, URL, AbortController, Date: FixtureDate });
  for (const file of ["assets/core/request-lifecycle.js", "assets/modules/search/global-search.js"]) {
    const absolute = path.resolve(__dirname, "../..", file);
    vm.runInContext(fs.readFileSync(absolute, "utf8").replace(/^import .*;\r?\n/gm, ""), context);
  }
  T.search.hydrate(document);
  const flush = () => { const callbacks = [...timers.values()]; timers.clear(); callbacks.forEach(fn => fn()); };
  const type = (value, flushTimer = true) => { input.value = value; input.handlers.input(); if (flushTimer) flush(); };
  return { T, root, input, box, pending, timers, services, type, flush, document, advance: ms => { clock += ms; } };
}
(async () => {
  let s = setup(); s.type("alpha"); s.type("");
  assert.equal(s.pending[0].options.signal.aborted, true);
  s.pending[0].resolve({ data: { query: "alpha" } }); await tick(); assert.equal(s.box.hidden, true, "Cleared input cannot reopen old results.");

  s = setup(); s.type("alpha"); s.type("x"); s.pending[0].resolve({ data: { query: "alpha" } }); await tick();
  assert.equal(s.box.textContent.includes("alpha"), false, "Short query cannot be replaced by an older result.");

  s = setup(); s.type("cached"); s.pending[0].resolve({ data: { query: "cached" } }); await tick();
  s.type("slow"); s.type("cached"); assert.equal(s.pending.length, 2, "Cache hit avoids another HTTP request.");
  s.pending[1].resolve({ data: { query: "slow" } }); await tick(); assert(s.box.textContent.includes("cached")); assert(!s.box.textContent.includes("slow"));

  s = setup(); s.type("alpha"); s.type("beta", false);
  assert.equal(s.pending[0].options.signal.aborted, true, "Input invalidates old intent before debounce fires.");
  s.pending[0].resolve({ data: { query: "alpha" } }); await tick(); assert(!s.box.textContent.includes("alpha"));
  s.flush(); s.pending[1].resolve({ data: { query: "beta" } }); await tick(); assert(s.box.textContent.includes("beta"));

  s = setup(); s.type("alpha"); s.T.popovers.close(s.box.parentElement);
  s.pending[0].resolve({ data: { query: "alpha" } }); await tick(); assert.equal(s.box.hidden, true, "Escape keeps pending results closed.");
  assert.equal(s.timers.size, 0);

  s = setup(); s.type("alpha"); s.type("beta");
  s.pending[0].reject({ data: { error: {}, code: "captcha_required" } }); await tick();
  assert.equal(s.box.children.length, 0, "Stale CAPTCHA errors cannot open UI or change results.");
  s.pending[1].resolve({ data: { query: "beta" } }); await tick(); assert(s.box.textContent.includes("beta"));

  s = setup(); s.type("alpha"); s.root.isConnected = false;
  s.pending[0].resolve({ data: { query: "alpha" } }); await tick(); assert.equal(s.box.hidden, true);

  s = setup(); s.type("alpha"); s.type("beta", false);
  Object.values(s.services).forEach(service => { if (service.dispose) service.dispose(s.root); });
  if (s.T.search.dispose) s.T.search.dispose(s.root);
  s.flush(); assert.equal(s.pending.length, 1, "Disposal clears debounce work.");
  assert.equal(s.pending[0].options.signal.aborted, true); s.pending[0].resolve({ data: { query: "alpha" } }); await tick(); assert.equal(s.box.hidden, true);
  s = setup();
  for (let i = 0; i < 40; i++) { s.type("query" + i); s.pending.at(-1).resolve({ data: { query: "query" + i } }); await tick(); }
  assert.equal(s.root.__tinycatSearchCache.size, 32, "Search cache has a fixed entry limit");
  let count = s.pending.length; s.type("query39"); assert.equal(s.pending.length, count);
  s.advance(61000); s.type("query39"); assert.equal(s.pending.length, count + 1, "Expired cache entries are fetched again");
  s.pending.at(-1).resolve({ data: { query: "query39" } }); await tick();
  s.document.documentElement.lang = "cs"; s.type("query39"); assert.equal(s.pending.length, count + 2, "Language changes do not reuse another context's cached results");
  s.pending.at(-1).resolve({ data: { query: "query39" } }); await tick();
  s.T.search.dispose(s.root); assert.equal(s.root.__tinycatSearchCache.size, 0);
  console.log("PASS search invalidation for clear, short query, cache, debounce, Escape, stale CAPTCHA and disposal");
})().catch(error => { console.error(error); process.exitCode = 1; });
