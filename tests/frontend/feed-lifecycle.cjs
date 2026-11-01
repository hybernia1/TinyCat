"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), path = require("node:path"), vm = require("node:vm");
const feed = { id: "feed", dataset: {}, isConnected: true, children: [] };
const section = { matches: () => true, querySelector: () => feed, querySelectorAll: () => [], appendChild() {} };
const T = { dom: { find: (_, scope) => scope === section ? feed : null, all: () => [], element: () => ({}) },
  api: { unwrap: response => response.data }, ui: { text: () => "", pagination: () => ({}) }, lifecycle: { register() {}, hydrate() {}, dispose() {} },
  content: { replaceChildren() {}, remove() {} }, navigation: { compact: url => url.pathname + url.search }, i18n: {}, notifications: {} };
const window = { TinyCat: T, location: new URL("https://tinycat.test/"), clearTimeout() {} };
const context = vm.createContext({ window, document: {}, URL, AbortController });
for (const file of ["core/request-lifecycle.js", "modules/status/controller.js", "modules/status/feed-loader.js"]) vm.runInContext(fs.readFileSync(path.join(__dirname, "../../assets/", file), "utf8").replace(/^import .*;\r?\n/gm, ""), context);
T.status.fragment = () => ({ content: {} });
const C = T.status.controllers.feed;
const old = C.statusFeedStartRequest(feed, "more");
T.status.feed(section, { data: { items: [], feed: "following" }, meta: {} });
assert.equal(old.lease.signal.aborted, true, "Resetting a retained feed cancels its old cursor request.");
const next = C.statusFeedStartRequest(feed, "more");
assert.ok(next, "An obsolete cursor request cannot block the new feed lifetime.");
assert.equal(C.statusFeedRequestActive(feed, next), true);
C.statusFeedFinishRequest(feed, old);
assert.equal(C.statusFeedRequestActive(feed, next), true, "Old cleanup cannot finish the newer cursor read.");
C.dispose(feed); assert.equal(next.lease.signal.aborted, true);
(async () => {
  const waiting = [];
  T.dialog = { current: () => null };
  T.api.request = () => new Promise((_resolve, reject) => waiting.push(reject));
  feed.dataset.statusFeedRefreshUrl = "/api/statuses";
  const obsolete = C.refreshPrunedStatusFeed(feed);
  C.dispose(feed);
  const current = C.refreshPrunedStatusFeed(feed);
  assert.equal(feed.dataset.statusFeedRefreshing, "true");
  waiting[0](Object.assign(new Error("Aborted"), { name: "AbortError" })); await obsolete;
  assert.equal(feed.dataset.statusFeedRefreshing, "true", "Old refresh cleanup cannot clear the new lifetime's busy state.");
  waiting[1](Object.assign(new Error("Aborted"), { name: "AbortError" })); await current;
  assert.equal(feed.dataset.statusFeedRefreshing, undefined);
  console.log("PASS feed reset, cursor cancellation, new lifetime and stale cleanup");
})().catch(error => { console.error(error); process.exitCode = 1; });
