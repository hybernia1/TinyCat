"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), path = require("node:path"), vm = require("node:vm");
(async function () {
  let prepended = 0, reset = 0, clean = 0, restored = [], action = "create", toolbar = true;
  const feed = { prepend() { prepended++; } };
  const form = { closest: () => null, reset() { reset++; } };
  const T = {
    dom: { find: selector => selector === ".home-feed-toolbar" ? toolbar : selector === "[data-status-feed]" ? feed : null, all: () => [], emit() {} },
    lifecycle: { hydrate() {} }, i18n: {}, api: { unwrap: data => data.data }, actions: {},
    forms: { markClean() { clean++; } }, dialog: { close() {} },
    navigation: { async restore(event) { assert.ok(clean > 0, "The form is clean before shared navigation runs"); restored.push(event.state); } },
    status: { card: item => item, controllers: { feed: { statusFeedForForm: () => feed }, forms: { statusFormAction: () => action }, editor: {} } }
  };
  const window = { TinyCat: T, location: { href: "https://tinycat.test/?feed=following&media=images" }, history: { state: { publicIndex: 3 } }, scrollX: 0, scrollY: 480 };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../../assets/modules/status/actions.js"), "utf8").replace(/^import .*;\r?\n/gm, ""), { window, document: {}, URL });
  const handle = T.status.controllers.actions.handleStatusJsonResponse;
  await handle(form, { data: { action, item: { id: 20 } } });
  assert.equal(prepended, 0, "Filtered results are determined by the server after publishing");
  assert.equal(reset, 1);
  assert.equal(restored.length, 1);
  assert.equal(restored[0].publicIndex, 3);
  assert.deepEqual(Array.from(restored[0].scroll), [0, 480]);
  action = "update";
  await handle(form, { data: { action, item: { id: 20 } } });
  assert.equal(restored.length, 2, "Editing also rechecks membership through shared navigation");
  window.location.href = "https://tinycat.test/"; action = "create";
  await handle(form, { data: { action, item: { id: 21 } } });
  assert.equal(prepended, 1, "Unfiltered feeds retain immediate insertion");
  assert.equal(restored.length, 2);
  window.location.href = "https://tinycat.test/author/2?media=images"; toolbar = false;
  await handle(form, { data: { action: "react", status: null } });
  assert.equal(restored.length, 2, "Other status actions do not refresh the page");
  console.log("PASS filtered home mutations reuse server filtering and shared history navigation");
})().catch(error => { console.error(error); process.exitCode = 1; });
