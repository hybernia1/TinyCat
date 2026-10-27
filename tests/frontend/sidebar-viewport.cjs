"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const services = {}, listeners = {}, changes = [], intervals = new Set();
let queries = 0, observers = 0, observed = 0, requests = 0, renders = 0, resolveRequest;
const media = { matches: false, addEventListener(_name, fn) { changes.push(fn); } };
const sidebar = {
  dataset: {}, style: { setProperty() {}, removeProperty() {} },
  getBoundingClientRect: () => ({ height: 300 })
};
const slot = {
  dataset: { sidebarUrl: "/api/site/sidebar" }, isConnected: true,
  classList: { add() {}, remove() {} },
  hasAttribute: () => false, replaceWith() { this.isConnected = false; }
};
const document = { hidden: false, addEventListener() {} };
const T = {
  site: {}, dom: { all(selector) {
    queries++;
    if (selector === ".public-sidebar, .profile-sidebar-stack") { return [sidebar]; }
    if (selector === "[data-public-sidebar-slot][data-sidebar-url]") { return [slot]; }
    return [];
  } },
  lifecycle: { register(name, service) { services[name] = service; }, hydrate() {} },
  api: { unwrap: data => data, request() { requests++; return new Promise(resolve => { resolveRequest = resolve; }); } }
};
T.site.sidebar = () => { renders++; return {}; };
const window = {
  TinyCat: T, matchMedia: () => media, scrollY: 0, innerHeight: 800,
  addEventListener(name, fn) { listeners[name] = fn; },
  removeEventListener(name, fn) { if (listeners[name] === fn) { delete listeners[name]; } },
  setInterval(fn) { intervals.add(fn); return fn; }, clearInterval(fn) { intervals.delete(fn); },
  requestAnimationFrame(fn) { fn(); }
};
class ResizeObserver {
  constructor() { observers++; }
  observe() { observed++; }
  disconnect() { observed = 0; }
}
window.ResizeObserver = ResizeObserver;
const context = vm.createContext({ window, document, ResizeObserver, URL });
for (const file of ["layout", "controller"]) {
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, "../../assets/modules/site/" + file + ".js"), "utf8").replace(/^import .*;\r?\n/gm, ""), context);
}
function resize(desktop) { media.matches = desktop; changes.forEach(fn => fn({ matches: desktop })); }
(async function () {
  services["sidebar-freshness"].init();
  T.siteLayout.hydrate(document); T.site.refresh(document);
  assert.equal(queries, 0, "Mobile initialization does not query sidebar DOM");
  assert.equal(requests, 0); assert.equal(observers, 0); assert.equal(intervals.size, 0);
  assert.equal(listeners.scroll, undefined); assert.equal(listeners.resize, undefined);
  resize(true);
  assert.equal(requests, 1); assert.equal(observed, 1); assert.equal(intervals.size, 1);
  assert.equal(typeof listeners.scroll, "function");
  resize(false);
  assert.equal(observed, 0); assert.equal(intervals.size, 0);
  assert.equal(listeners.scroll, undefined); assert.equal(listeners.resize, undefined);
  resolveRequest({ data: {} });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(renders, 0, "A desktop response arriving on mobile must not render a sidebar");
  const before = queries;
  T.siteLayout.hydrate(document); T.site.refresh(document);
  assert.equal(queries, before); assert.equal(requests, 1);
  resize(true);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(renders, 1, "Returning to desktop restores the sidebar from cached data");
  assert.equal(requests, 1); assert.equal(observed, 1); assert.equal(intervals.size, 1);
  assert.equal(typeof listeners.scroll, "function");
  console.log("PASS mobile sidebar inactivity, desktop activation, cleanup and late response");
}()).catch(error => { console.error(error); process.exitCode = 1; });
