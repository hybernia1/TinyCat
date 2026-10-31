"use strict";
const assert = require("node:assert/strict");
const setup = require("./list-fixture.cjs");
const tick = () => new Promise(resolve => setImmediate(resolve));
const response = value => ({ data: { items: [value], filters: { q: "active", bot: "3" } }, meta: { pagination: { per_page: 10, page: 2 } } });

(async function () {
  const s = setup();
  s.window.location = new URL("https://tinycat.test/admin/bots/list?q=active&bot=3&per_page=10&page=2&sort=name&direction=desc");
  for (const [id, endpoint, mutation] of [["bots-list", "/api/bots/sources", "/api/bots/sources/7"], ["bot-accounts-list", "/api/bots/accounts", "/api/bots/accounts/3"]]) {
    const root = s.targets.get(id), start = s.pending.length;
    assert.equal(s.T.bots.result(s.source(mutation, "PATCH", id), { data: {} }, root).length, 0);
    assert.equal(s.pending[start].url, endpoint + s.window.location.search);
    s.pending[start].resolve(response("current")); await tick();
    assert.equal(s.rendered.at(-1).data.pagination.page, 2);
    assert.equal(s.rendered.at(-1).data.items[0], "current");
    assert.equal(s.hydrated.at(-1), root);
    const before = s.rendered.length;
    s.T.bots.result(s.source(mutation, "PATCH", id), { data: {} }, root);
    const refresh = s.pending.at(-1), newer = s.T.lists.refresh(root), latest = s.pending.at(-1);
    assert.equal(refresh.options.signal.aborted, true);
    latest.resolve(response("latest")); await newer;
    refresh.resolve(response("obsolete")); await tick();
    assert.equal(s.rendered.length, before + 1);
    assert.equal(s.rendered.at(-1).data.items[0], "latest");
  }
  console.log("PASS bot administration mutations retain filters, sorting and pagination and reject obsolete refreshes");
})().catch(error => { console.error(error); process.exitCode = 1; });
