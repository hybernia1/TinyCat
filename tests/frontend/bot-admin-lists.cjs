"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

(async function () {
  const calls = [], rendered = [];
  const document = { querySelector: () => null, getElementById: () => null };
  const T = {
    filters: { get: () => null },
    botDetail: { result: () => false },
    ui: { pagination: response => response.meta.pagination },
    components: { render(name, data) { rendered.push({ name, data }); return data; } },
    lifecycle: { hydrate() {} }, toasts: { show(message) { throw new Error(message); } },
    api: { async request(url) { calls.push(url); return { data: { items: [], filters: { q: "active", bot: "3" } }, meta: { pagination: { per_page: 10, page: 2 } } }; } }
  };
  const window = { TinyCat: T, location: new URL("https://tinycat.test/admin/bots/list?q=active&bot=3&per_page=10&page=2&sort=name&direction=desc") };
  vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, "../../assets/modules/bots/views.js"), "utf8").replace(/^import .*;\r?\n/gm, ""), { window, document, URL, URLSearchParams, AbortController });
  const form = { dataset: { apiMethod: "PATCH" }, getAttribute: name => name === "action" ? "/api/bots/sources/7" : name === "method" ? "post" : null };
  const target = { id: "bots-list", isConnected: true, dataset: {}, replaceChildren() {} };
  T.bots.result(form, { data: { items: ["unfiltered mutation response"] } }, target);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls[0], "/api/bots/sources" + window.location.search, "Saving reloads the current filtered, sorted page.");
  assert.equal(rendered[0].data.pagination.page, 2, "The refreshed source view receives pagination metadata.");
  assert.equal(rendered[0].data.items.length, 0, "A source that no longer matches the filters disappears from the list.");
  form.getAttribute = name => name === "action" ? "/api/bots/accounts/3" : name === "method" ? "post" : null;
  target.id = "bot-accounts-list";
  T.bots.result(form, { data: {} }, target);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls[1], "/api/bots/accounts" + window.location.search, "Bot account mutations use the same refresh convention.");
  console.log("PASS bot administration mutations retain list filters, sorting and pagination");
})().catch(error => { console.error(error); process.exitCode = 1; });
