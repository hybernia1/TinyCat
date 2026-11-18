"use strict";
const assert = require("node:assert/strict");
const setup = require("./fixtures/list-fixture.cjs");
const tick = () => new Promise(resolve => setImmediate(resolve));
const response = value => ({ data: { items: [value], filters: { q: "active", bot: "3" } }, meta: { pagination: { per_page: 10, page: 2 } } });

(async function () {
  const s = setup();
  s.window.location = new URL("https://tinycat.test/admin/bots/list?q=active&bot=3&per_page=10&page=2&sort=name&direction=desc");
  for (const [id, endpoint, mutation] of [["bots-list", "/api/bots/sources", "/api/bots/sources/7"], ["bot-accounts-list", "/api/bots/accounts", "/api/bots/accounts/3"]]) {
    const root = s.targets.get(id), start = s.pending.length;
    assert.equal(s.T.modules.result(s.source(mutation, "PATCH", id), { data: {} }, root).length, 0);
    assert.equal(s.pending[start].url, endpoint + s.window.location.search);
    s.pending[start].resolve(response("current")); await tick();
    assert.equal(s.rendered.at(-1).data.pagination.page, 2);
    assert.equal(s.rendered.at(-1).data.items[0], "current");
    assert.equal(s.hydrated.at(-1), root);
    const before = s.rendered.length;
    s.T.modules.result(s.source(mutation, "PATCH", id), { data: {} }, root);
    const refresh = s.pending.at(-1), newer = s.T.lists.refresh(root), latest = s.pending.at(-1);
    assert.equal(refresh.options.signal.aborted, true);
    latest.resolve(response("latest")); await newer;
    refresh.resolve(response("obsolete")); await tick();
    assert.equal(s.rendered.length, before + 1);
    assert.equal(s.rendered.at(-1).data.items[0], "latest");
  }
  const generic = setup({'fixture.queue': {path:'/admin/fixture',api:'/api/fixture',endpoint:'/api/fixture',target:'#bot-queue-list',component:'fixture/queue'}});
  generic.window.location = new URL('https://tinycat.test/admin/fixture?q=active&sort=name&direction=desc');
  const target = generic.targets.get('bot-queue-list');
  assert.equal(generic.T.modules.result(generic.source('/api/fixture/9', 'PATCH', 'bot-accounts-list'), {data:{}}, generic.targets.get('bot-accounts-list')), false);
  assert.equal(generic.T.modules.result(generic.source('/api/fixture/9', 'PATCH', target.id), {data:{}}, null), false);
  assert.equal(generic.pending.length, 0, 'A shared API namespace cannot claim another surface or a missing list');
  generic.T.modules.result(generic.source('/api/fixture/9', 'PATCH', target.id), {data:{}}, target);
  assert.equal(generic.pending[0].url, '/api/fixture' + generic.window.location.search);
  generic.pending[0].resolve(response('generic-owner')); await tick();
  assert.equal(generic.rendered.at(-1).name, 'fixture/queue');
  assert.equal(generic.rendered.at(-1).data.items[0], 'generic-owner');
  const moderation = setup();
  moderation.window.location = new URL('https://tinycat.test/admin/moderation/reports?status=open&reason=spam&page=2&sort=reason&direction=desc');
  const reports = moderation.targets.get('moderation-reports');
  for (const [url, method] of [['/api/moderation/reports/12','PATCH'],['/api/moderation/posts/42/publication','POST']]) {
    const count = moderation.pending.length;
    moderation.T.modules.result(moderation.source(url, method, reports.id), {data:{}}, reports);
    assert.equal(moderation.pending[count].url, '/api/moderation/reports' + moderation.window.location.search);
    moderation.pending[count].resolve(response('remaining-reports')); await tick();
    assert.equal(moderation.rendered.at(-1).name, 'moderation/reports');
    assert.equal(moderation.rendered.at(-1).data.pagination.page, 2);
  }
  console.log("PASS bot administration mutations retain filters, sorting and pagination and reject obsolete refreshes");
})().catch(error => { console.error(error); process.exitCode = 1; });
