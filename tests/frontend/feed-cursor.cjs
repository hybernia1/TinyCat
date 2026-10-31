"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.join(__dirname, "../../assets/modules/status/feed.js"), "utf8").replace(/^import .*;\r?\n/gm, "");
(async function () {
  let checks = 0;
  for (const kind of ["posts", "entities", "notifications"]) {
    const errors = [], renders = [];
    const card = id => ({ dataset: kind === "entities" ? { feedId: String(id) } : { statusId: String(id) }, classList: { contains: name => name === "status-card" && kind !== "entities" }, hasAttribute: name => name === "data-feed-item" && kind === "entities" });
    const target = { id: kind === "notifications" ? "notifications-list" : "search-results", dataset: kind === "entities" ? { feedComponent: "search/items" } : {}, children: [card(1)], appendChild(fragment) { this.children.push(...fragment.children); } };
    const control = { dataset: { statusFeedTarget: "#" + target.id, statusFeedUrl: "/api/search/results?q=test&cursor_id=1" }, isConnected: true, classList: { add() {}, remove() {} }, remove() { this.isConnected = false; }, getBoundingClientRect() { return { top: 10000 }; } };
    let response = { data: { items: [{ id: 1 }, { id: 2 }] }, meta: { pagination: { next_url: "/api/search/results?q=test&cursor_id=2", done: false } } };
    const fragment = items => { const f = { children: items.map(i => card(i.id)) }; f.children.forEach(n => { n.remove = () => { f.children = f.children.filter(x => x !== n); }; }); return f; };
    const T = { dom: { find: selector => selector === "#" + target.id ? target : null, all: (selector, scope) => selector.includes("data-status-id") ? (scope.children || []) : selector === "[data-status-feed-more]" ? (control.isConnected ? [control] : []) : [] }, api: { request: async () => response, unwrap: r => r.data }, ui: { pagination: r => r.meta.pagination }, i18n: { label: () => "error" }, lifecycle: { register() {}, dispose() {}, hydrate() {} }, navigation: { compact: u => u.pathname + u.search }, components: { render(name, data) { renders.push(name); return fragment(data.items); } }, status: { controllers: {}, fragment(items) { renders.push("status"); return { content: fragment(items) }; } }, notifications: { items(items) { renders.push("notifications"); return fragment(items); } }, toasts: { show: e => errors.push(e) } };
    const window = { TinyCat: T, location: new URL("https://tinycat.test/search"), innerHeight: 800 };
    T.content = { remove: node => node.remove() };
    const context = vm.createContext({ window, document: {}, URL, console, AbortController });
    vm.runInContext(fs.readFileSync(path.join(__dirname, "../../assets/core/requests.js"), "utf8").replace(/^import .*;\r?\n/gm, ""), context);
    vm.runInContext(source, context, { filename: "feed.js" });
    const feed = T.status.controllers.feed;
    await feed.loadStatusFeedMore(control);
    assert.deepEqual(target.children.map(n => n.dataset.feedId || n.dataset.statusId), ["1", "2"]); checks++;
    assert.equal(control.dataset.statusFeedUrl, response.meta.pagination.next_url); checks++;
    assert.equal(renders[0], kind === "entities" ? "search/items" : kind === "posts" ? "status" : "notifications"); checks++;
    response = { data: { items: [{ id: 3 }] }, meta: { pagination: { next_url: "", done: true } } };
    await feed.loadStatusFeedMore(control);
    assert.equal(control.isConnected, false); checks++;
    assert.equal(target.children.length, 3); checks++;
    assert.deepEqual(errors, []); checks++;
    const first = feed.statusFeedFirstPageUrl("/api/search/results?q=test&type=tags&cursor_at=2026-01-01&cursor_id=3&cursor_rank=1&cursor_name=test");
    assert.equal(first, "/api/search/results?q=test&type=tags"); checks++;
  }
  console.log(`PASS shared feed cursor loader (${checks} checks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });