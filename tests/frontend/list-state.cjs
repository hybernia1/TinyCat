"use strict";
const assert = require("node:assert/strict"), setup = require("./list-fixture.cjs");
const tick = () => new Promise(resolve => setImmediate(resolve));
const response = label => ({ data: { items: [label], tasks: [], filters: {} }, meta: { pagination: { page: 2, per_page: 10 } } });
(async () => {
  let s = setup(), root = s.targets.get("users-list");
  const url = new URL(s.T.lists.url("/api/users?q=next&page=1", root), s.window.location);
  assert.equal(url.searchParams.get("q"), "next"); assert.equal(url.searchParams.get("page"), "1"); assert.equal(url.searchParams.get("sort"), "username");
  assert.equal(new URL(s.T.lists.url("/api/users?direction=desc", root), s.window.location).searchParams.get("direction"), "desc");
  assert.equal(s.T.lists.url("/unregistered", new s.Node()), "/unregistered");
  const read = s.click(s.source("/api/users?q=next")); await tick(); s.pending[0].resolve(response("read")); await read;
  assert.deepEqual(s.hydrated, [root], "List reads hydrate exactly their changed region.");

  s = setup(); root = s.targets.get("users-list");
  const write = s.click(s.source("/api/users/7", "PATCH")); await tick(); s.pending[0].resolve(response("mutation")); await write;
  assert.equal(s.pending.length, 2); assert.equal(s.pending[1].url, "/api/users" + s.window.location.search);
  assert.equal(s.saved.length, 1, "Successful writes finalize while the refresh proceeds independently.");
  assert.equal(s.hydrated.length, 0, "Starting a refresh does not hydrate old content or the document.");
  const filter = s.click(s.source("/api/users?q=latest")); await tick();
  assert.equal(s.pending[1].options.signal.aborted, true);
  s.pending[2].resolve(response("latest")); await filter; s.pending[1].resolve(response("obsolete")); await tick();
  assert.deepEqual(s.rendered.map(item => item.data.items), [["latest"]]); assert.deepEqual(s.hydrated, [root]);

  s = setup(); root = s.targets.get("users-list");
  const failed = s.T.lists.refresh(root); const next = s.T.lists.refresh(root);
  s.pending[1].resolve(response("new")); await next; s.pending[0].reject(new Error("Old read")); await failed;
  assert.equal(s.emitted.length, 0, "An obsolete refresh failure stays silent.");
  const currentFailure = s.T.lists.refresh(root); s.pending[2].reject(new Error("Current read")); await currentFailure;
  assert.equal(s.emitted.length, 1); assert.equal(s.emitted[0].name, "tinycat:navigation-error");
  for (const scenario of ["removed", "query", "page", "dispose"]) {
    s = setup(); root = s.targets.get("users-list"); const refreshing = s.T.lists.refresh(root);
    if (scenario === "removed") root.isConnected = false;
    if (scenario === "query") s.window.location.search = "?q=new";
    if (scenario === "page") s.window.location.pathname = "/admin/other";
    if (scenario === "dispose") s.T.lifecycle.dispose(root);
    s.pending[0].resolve(response("obsolete")); await refreshing;
    assert.equal(s.rendered.length + s.hydrated.length, 0, "No refresh commit after " + scenario);
  }

  s = setup(); let changed;
  s.T.modules.register("fixture-result", { result: source => source.dataset.fixture ? changed : false });
  root = s.targets.get("users-list"); const child = new s.Node(); root.replaceChildren(child);
  const other = s.targets.get("scheduler-list"), removed = new s.Node();
  changed = [child, root, other, root, removed];
  let source = s.source("/api/fixture"); source.dataset.fixture = "true";
  let applying = s.click(source); await tick(); s.pending[0].resolve(response("patch")); await applying;
  assert.deepEqual(s.hydrated, [root, other], "Roots are connected, deduplicated and reduced to ancestors.");
  changed = []; applying = s.click(source); await tick(); s.pending[1].resolve(response("no DOM")); await applying;
  assert.equal(s.hydrated.length, 2, "An empty handled result never falls back to a document scan.");
  changed = true; applying = s.click(source); await tick(); s.pending[2].resolve(response("legacy")); await applying;
  assert.equal(s.hydrated.length, 3, "Legacy boolean handlers keep their target hydration.");

  s = setup(); root = s.targets.get("users-list");
  const plainLink = s.source("/api/users"); plainLink.dataset = { ajaxTarget: "#users-list" };
  applying = s.click(plainLink); await tick(); s.pending[0].resolve(response("link")); await applying;
  assert.equal(s.pending.length, 1, "Plain GET links render without a second refresh."); assert.deepEqual(s.hydrated, [root]);
  assert.deepEqual(Array.from(s.T.scheduler.result(s.source("/api/scheduler/tokens", "POST"), { data: { token: "new" } })), [], "Token text updates need no hydration.");

  s = setup(); source = s.source("/api/scheduler/tokens", "POST"); delete source.dataset.ajaxTarget;
  applying = s.click(source); await tick(); s.pending[0].resolve({ data: { token: "new" }, meta: {} }); await applying;
  assert.equal(s.hydrated.length, 0, "A token action without an explicit target never hydrates the document.");
  assert.equal(s.saved.length, 1);
  for (const [id, endpoint] of [["scheduler-list", "/api/scheduler/tasks"], ["moderation-reports", "/api/moderation/reports"]]) {
    s = setup(); root = s.targets.get(id);
    applying = s.click(s.source(endpoint, "GET", id)); await tick(); s.pending[0].resolve(response("read")); await applying;
    assert.deepEqual(s.hydrated, [root], id + " reports exactly its changed region");
  }
  s = setup(); root = s.targets.get("users-list"); const focused = new s.Node(); root.replaceChildren(focused); s.document.activeElement = focused;
  const steps = [], render = s.T.users.list;
  s.T.filters.capture = node => { assert.equal(node, focused); steps.push("filter capture"); return {}; };
  s.T.filters.restore = () => steps.push("filter restore");
  s.T.menus = { capture: node => { assert.equal(node, focused); steps.push("menu capture"); return {}; }, restore: () => steps.push("menu restore") };
  s.T.users.list = (...args) => { steps.push("render"); render(...args); };
  s.T.lifecycle.hydrate = node => { assert.equal(node, root); steps.push("hydrate"); };
  const preserving = s.T.lists.refresh(root); s.pending[0].resolve(response("new")); await preserving;
  assert.deepEqual(steps, ["filter capture", "menu capture", "render", "hydrate", "filter restore", "menu restore"]);
  console.log("PASS list-owned URL rules, scoped result roots and post-save refresh races");
})().catch(error => { console.error(error); process.exitCode = 1; });
