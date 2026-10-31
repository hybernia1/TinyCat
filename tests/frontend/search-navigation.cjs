"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), path = require("node:path"), vm = require("node:vm");
(async function () {
  const handlers = {}, services = {}, requests = [], side = { dataset: {}, remove() {} };
  let route, retained, current, assigned = 0, disposed = 0, pushes = 0, replacements = 0, rollbackDistance;
  const input = { value: "" }, results = { hidden: false };
  const slot = { replaceWith(node) { retained = node; } };
  const target = { dataset: {}, querySelector: () => side, setAttribute() {}, removeAttribute() {}, replaceChildren() {} };
  const head = { querySelector: () => null, querySelectorAll: () => [], appendChild() {} };
  const document = { head, body: { dataset: { currentUserId: "0", applicationVersion: "4.5.0" } }, documentElement: { lang: "cs" },
    querySelector(selector) { if (selector === "[data-global-search-input]") { return input; } return selector === "#main-content > .container" ? target : {}; },
    querySelectorAll: selector => selector === "[data-global-search-results]" ? [results] : [], getElementById: () => ({ focus() {} }),
    createElement: () => ({ setAttribute() {} }), addEventListener: (name, fn) => { handlers[name] = fn; } };
  const data = { version: "4.5.0", locale: "cs", viewer: null, kind: "search", url: "/search?q=quartz&type=users", search: { query: "quartz" }, document: { title: "Search quartz", url: "http://tinycat.test/search?q=quartz&type=users" }, sidebar_url: "/api/site/sidebar" };
  const T = { urls: { split: p => ({ path: p, locale: "" }), prefix: () => "" }, dialog: { current: () => null },
    navigation: { registerPage: r => { route = r; }, go: url => { current = url; }, canLeave: async () => true },
    api: { request: async url => { requests.push(url); return { data }; }, csrfToken: () => "test" },
    lifecycle: { register: (name, service) => { services[name] = service; }, dispose: () => { disposed++; }, hydrate() {} },
    components: { render: () => ({ querySelector: () => slot }) }, site: { activateSidebar() {} } };
  const window = { TinyCat: T, location: { href: "http://tinycat.test/", origin: "http://tinycat.test", assign() { assigned++; } }, history: { replaceState() { replacements++; }, pushState() { pushes++; }, go(distance) { rollbackDistance = distance; } }, matchMedia: () => ({ matches: true }), clearTimeout() {}, setTimeout() {}, addEventListener() {}, scrollTo() {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../../assets/modules/site/navigation.js"), "utf8").replace(/^import .*;\r?\n/gm, ""), { window, document, URL, AbortController });
  assert.equal(route.accepts(new URL("http://tinycat.test/search?q=test&type=users")), true);
  assert.equal(route.accepts(new URL("http://tinycat.test/search?q=test&unexpected=1")), false);
  assert.equal(route.accepts(new URL("http://outside.test/search?q=test")), false);
  assert.equal(route.accepts(new URL("http://tinycat.test/?media=images")), true);
  assert.equal(route.accepts(new URL("http://tinycat.test/?feed=following&media=video")), true);
  assert.equal(route.accepts(new URL("http://tinycat.test/status/10?media=video")), false);
  services["public-pages"].init();
  let prevented = false;
  const form = { action: "http://tinycat.test/search", querySelector: () => ({ value: "quartz" }) };
  handlers.submit({ target: { closest: () => form }, preventDefault() { prevented = true; } });
  assert.equal(prevented, true); assert.equal(current, "http://tinycat.test/search?q=quartz");
  await route.visit(new URL("http://tinycat.test/search?q=quartz&type=users"));
  assert.equal(retained, side, "Search navigation retains the exact sidebar node");
  assert.equal(requests.length, 1); assert.equal(new URL(requests[0]).pathname, "/api/site/pages");
  assert.equal(new URL(requests[0]).searchParams.get("q"), "quartz");
  assert.equal(new URL(requests[0]).searchParams.get("type"), "users");
  assert.equal(input.value, "quartz"); assert.equal(results.hidden, true);
  assert.equal(assigned, 0, "Search does not fall back to a document reload"); assert.equal(disposed, 1);
  data.kind = "home"; data.url = "/?feed=following&media=video";
  await route.visit(new URL("http://tinycat.test/?feed=following&media=video"));
  assert.equal(new URL(requests[1]).searchParams.get("media"), "video");
  assert.equal(new URL(requests[1]).searchParams.get("feed"), "following");
  assert.equal(assigned, 0, "Filtered home navigation does not reload the document");
  const previousPushes = pushes, previousReplacements = replacements;
  await route.visit(new URL("http://tinycat.test/?feed=following&media=video"), null, true);
  assert.equal(pushes, previousPushes, "A visit from a modal does not add a duplicate page entry");
  assert.equal(replacements, previousReplacements + 2, "The visit snapshots scroll and reuses the modal entry without pushing another page");
  const previousRequests = requests.length;
  T.navigation.canLeave = async () => false;
  assert.equal(await route.restore({state:{publicIndex:2}}), false, "A cancelled page restoration explicitly reports that no content changed");
  assert.equal(requests.length, previousRequests); assert.equal(rollbackDistance, 1);
  assert.equal(await route.restore({state:{publicIndex:3}}), false, "The rollback traversal does not load another page");
  T.navigation.canLeave = async () => true;
  await route.restore({state:{publicIndex:1}}, new URL("http://tinycat.test/search?q=restored"));
  assert.equal(new URL(requests.at(-1)).searchParams.get('path'), '/search');
  assert.equal(new URL(requests.at(-1)).searchParams.get('q'), 'restored', "Overlay restoration supplies its clean base-page URL");
  console.log("PASS search uses shared page navigation, preserves sidebar and submits without reload");
})().catch(error => { console.error(error); process.exitCode = 1; });
