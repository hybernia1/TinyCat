"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), path = require("node:path"), vm = require("node:vm");
(async function () {
  const handlers = {}, services = {}, requests = [], side = { dataset: {}, remove() {} };
  let route, retained, current, assigned = 0, disposed = 0, pushes = 0, replacements = 0, rollbackDistance;
  const input = { value: "" }, results = { hidden: false };
  const slot = { replaceWith(node) { retained = node; } };
  const target = { dataset: {}, querySelector: () => side, setAttribute() {}, removeAttribute() {}, replaceChildren() {} };
  const tagNodes = [], metaNodes = [];
  const head = { querySelector: selector => { const match = /^meta\[(property|name)="([^"]+)"\]$/.exec(selector); return match ? metaNodes.find(node => node[match[1]] === match[2]) || null : null; }, querySelectorAll: selector => selector === 'meta[property="article:tag"]' ? tagNodes.slice() : [], appendChild(node) { metaNodes.push(node); if (node.property === "article:tag") { tagNodes.push(node); } } };
  const routes = JSON.parse(require('node:child_process').execFileSync(process.env.TINYCAT_TEST_PHP || 'php', [path.join(__dirname,'../presentation/fixtures/component-parity.php'),'--page-routes'], {encoding:'utf8'}));
  const publicPage = {dataset:{publicRoutes:JSON.stringify(routes)}};
  const document = { head, body: { dataset: { currentUserId: "0", applicationVersion: "4.5.0" } }, documentElement: { lang: "cs" },
    querySelector(selector) { if (selector === "[data-public-page]") { return publicPage; } if (selector === "[data-global-search-input]") { return input; } return selector === "#main-content > .container" ? target : {}; },
    querySelectorAll: selector => selector === "[data-global-search-results]" ? [results] : [], getElementById: () => ({ focus() {} }),
    createElement: () => ({ setAttribute(key, value) { this[key] = value; }, remove() { const metaIndex = metaNodes.indexOf(this); if (metaIndex >= 0) { metaNodes.splice(metaIndex, 1); } const index = tagNodes.indexOf(this); if (index >= 0) { tagNodes.splice(index, 1); } } }), addEventListener: (name, fn) => { handlers[name] = fn; } };
  const data = { version: "4.5.0", locale: "cs", viewer: null, kind: "tag", url: "/tag/quartz", document: { title: "Search quartz", url: "http://tinycat.test/tag/quartz" }, sidebar_url: "/api/site/sidebar" };
  const T = { urls: { split: p => ({ path: p, locale: "" }), prefix: () => "" }, dialog: { current: () => null },
    navigation: {},
    api: { request: async url => { requests.push(url); return { data }; }, csrfToken: () => "test" },
    lifecycle: { register: (name, service) => { services[name] = service; }, dispose: () => { disposed++; }, hydrate() {} },
    components: { render: () => ({ querySelector: () => slot }) }, site: { activateSidebar() {} } };
  const window = { TinyCat: T, location: { href: "http://tinycat.test/", origin: "http://tinycat.test", assign() { assigned++; } }, history: { replaceState(state) { this.state = state; replacements++; }, pushState(state) { this.state = state; pushes++; }, go(distance) { rollbackDistance = distance; } }, matchMedia: () => ({ matches: true }), clearTimeout() {}, setTimeout() {}, addEventListener() {}, scrollTo() {} };
  for (const file of ["App/Core/Ui/assets/runtime/navigation.js", "App/Core/Ui/assets/runtime/page-navigation.js", "App/Core/Ui/assets/document-navigation.js", "App/Modules/Social/assets/site/page-navigation.js"]) {
    if (file.startsWith("App/Modules/Social/")) { const register = T.navigation.registerPage; T.navigation.registerPage = r => { route = r; register(r); }; }
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../..", file), "utf8").replace(/^import .*;\r?\n/gm, ""), { window, document, URL, AbortController });
  }
  assert.equal(route.accepts(new URL("http://tinycat.test/search?q=test&type=users")), false);
  assert.equal(route.accepts(new URL("http://tinycat.test/search?q=test&media=video")), false);
  assert.equal(route.accepts(new URL("http://tinycat.test/search?q=test&unexpected=1")), false);
  assert.equal(route.accepts(new URL("http://outside.test/search?q=test")), false);
  assert.equal(route.accepts(new URL("http://tinycat.test/?media=images")), true);
  assert.equal(route.accepts(new URL("http://tinycat.test/?feed=following&media=video")), true);
  assert.equal(route.accepts(new URL("http://tinycat.test/status/10?media=video")), false);
  services.navigation.init();
  services["page-navigation"].init();
  const go = T.navigation.go; T.navigation.go = url => { current = url; };
  assert.equal(route.accepts(new URL("http://tinycat.test/search/all?q=test")), false, "Core search is not intercepted by the social page renderer");
  const link = {href:"http://tinycat.test/tag/clicked",hasAttribute:()=>false};
  let intercepted = false;
  handlers.click({button:0,target:{closest:()=>link},preventDefault(){intercepted=true;}});
  assert.equal(intercepted,true,"Core intercepts ordinary links accepted by the page provider");
  assert.equal(current,link.href);
  intercepted=false;
  handlers.click({button:0,ctrlKey:true,target:{closest:()=>link},preventDefault(){intercepted=true;}});
  assert.equal(intercepted,false,"Modified clicks retain native browser navigation");
  await go(new URL("http://tinycat.test/tag/quartz"));
  assert.equal(retained, side, "Search navigation retains the exact sidebar node");
  assert.equal(requests.length, 1); assert.equal(new URL(requests[0]).pathname, "/api/site/pages");
  assert.equal(new URL(requests[0]).searchParams.get("path"), "/tag/quartz");
  assert.equal(input.value, ""); assert.equal(results.hidden, true);
  assert.equal(assigned, 0, "Search does not fall back to a document reload"); assert.equal(disposed, 1);
  data.kind = "home"; data.url = "/?feed=following&media=video";
  await go(new URL("http://tinycat.test/?feed=following&media=video"));
  assert.equal(new URL(requests[1]).searchParams.get("media"), "video");
  assert.equal(new URL(requests[1]).searchParams.get("feed"), "following");
  assert.equal(assigned, 0, "Filtered home navigation does not reload the document");
  const previousPushes = pushes, previousReplacements = replacements;
  T.navigation.registerOverlay({ leave: () => true });
  await go(new URL("http://tinycat.test/?feed=following&media=video"));
  assert.equal(pushes, previousPushes, "A visit from a modal does not add a duplicate page entry");
  assert.equal(replacements, previousReplacements + 2, "The visit snapshots scroll and reuses the modal entry without pushing another page");
  const previousRequests = requests.length;
  T.navigation.guard(async () => false);
  assert.equal(await T.navigation.restorePage({state:{pageIndex:2}}), false, "A cancelled page restoration explicitly reports that no content changed");
  assert.equal(requests.length, previousRequests); assert.equal(rollbackDistance, 1);
  assert.equal(await T.navigation.restorePage({state:{pageIndex:3}}), false, "The rollback traversal does not load another page");
  T.navigation.guard(async () => true);
  await T.navigation.restorePage({state:{pageIndex:1}}, new URL("http://tinycat.test/tag/restored"));
  assert.equal(new URL(requests.at(-1)).searchParams.get('path'), '/tag/restored');
  assert.equal(new URL(requests.at(-1)).searchParams.get('path'), '/tag/restored', "Overlay restoration supplies its clean base-page URL");
  data.kind = "status"; data.url = "/status/10"; data.document.type = "article"; data.document.tags = ["cats", "news"]; data.document.meta_title = "Clean post title"; data.document.locale = "cs_CZ";
  await go(new URL("http://tinycat.test/status/10"));
  assert.deepEqual(tagNodes.map(node => node.content), ["cats", "news"], "Post navigation publishes all article tags");
  assert.equal(head.querySelector('meta[property="og:title"]').content, "Clean post title", "Navigation uses the same normalized social title as HTML");
  assert.equal(head.querySelector('meta[name="twitter:title"]').content, "Clean post title");
  assert.equal(head.querySelector('meta[property="og:locale"]').content, "cs_CZ", "Navigation retains the document Open Graph locale");
  data.document.tags = ["updated"];
  await go(new URL("http://tinycat.test/status/11"));
  assert.deepEqual(tagNodes.map(node => node.content), ["updated"], "Next post replaces previous tags without duplicates");
  data.kind = "home"; data.document.type = "website"; delete data.document.tags;
  await go(new URL("http://tinycat.test/"));
  assert.equal(tagNodes.length, 0, "Leaving a post removes its article tags");
  assert.equal(assigned, 0, "Metadata updates preserve client navigation");
  console.log("PASS social page navigation preserves sidebar and leaves shared search to core");
  const modulePath = process.env.TINYCAT_TEST_PLAYWRIGHT_MODULE || path.resolve(__dirname, '../../.work/tools/ui-webkit-tools/node_modules/playwright');
  if (!fs.existsSync(modulePath)) { console.log('SKIP native public workspace: set TINYCAT_TEST_PLAYWRIGHT_MODULE'); return; }
  const fixture = JSON.parse(require('node:child_process').execFileSync(process.env.TINYCAT_TEST_PHP || 'php', [path.join(__dirname, '../presentation/fixtures/asset-graph-fixture.php'), '--search-workspace'], {encoding:'utf8', maxBuffer:8e6}));
  const browser = await require(modulePath).webkit.launch({headless:true});
  let cases = 0;
  try {
    for (const width of [320,390,899,900,1440]) {
      const page = await browser.newPage({viewport:{width,height:900}});
      await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+fixture.styles+'</style></head><body><main class="container">'+fixture.content+'</main></body></html>');
      for (const scale of [1,1.5]) {
        await page.evaluate(scale => document.documentElement.style.setProperty('--ui-scale',scale), scale);
        const geometry = await page.evaluate(() => {
          const nav = document.querySelector('.public-workspace-nav'), content = document.querySelector('.public-workspace > div');
          return {overflow:document.documentElement.scrollWidth > innerWidth, direction:getComputedStyle(nav).flexDirection, nav:nav.getBoundingClientRect().toJSON(), content:content.getBoundingClientRect().toJSON(), selected:nav.querySelectorAll('[aria-current="page"]').length, search:document.querySelectorAll('input[type="search"]').length};
        });
        assert.equal(geometry.overflow,false,'Workspace fits '+width+' at scale '+scale);
        assert.equal(geometry.direction,width < 900 ? 'row' : 'column');
        assert.equal(geometry.selected,1);
        assert.equal(geometry.search,0,'Workspace does not add a second search control');
        assert.ok(width < 900 ? geometry.content.top >= geometry.nav.bottom : geometry.content.left >= geometry.nav.right);
        const links = page.locator('.public-workspace-nav a');
        await links.nth(1).focus();
        assert.equal(await links.nth(1).evaluate(node => node === document.activeElement),true,'Source links remain focusable without a custom navigation controller');
        assert.ok(await links.nth(1).getAttribute('href').then(url => url.includes('q=fixture&collection=')));
        cases++;
      }
      await page.close();
    }
  } finally { await browser.close(); }
  console.log('PASS native public workspace at mobile/desktop boundary and enlarged scale ('+cases+' cases)');
})().catch(error => { console.error(error); process.exitCode = 1; });
