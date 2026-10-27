"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const handlers = {}, calls = [], applied = [], target = { id: "users-list", dataset: {} };
const document = { querySelector: () => target, addEventListener(name, fn) { handlers[name] = fn; } };
class FormData extends Map { constructor(form) { super(form.fields); } }
const T = {
  dom: { find: () => target, emit() {} },
  lifecycle: { register() {}, hydrate() {} },
  api: { unwrap: response => response.data, errors: () => null, details: () => ({}), async request(url, options) { calls.push({ url, options }); return { data: { url } }; } },
  forms: { clearErrors() {}, applyErrors() {}, reset() {}, markClean() {}, loading() {}, dirty: () => [], confirmDiscard: async () => true },
  i18n: { label: key => key }, actions: {}, dialog: {},
  modules: { result(_source, response) { applied.push(response.data.url); } }, toasts: { show() {} }
};
T.forms.request = (_source, url, options) => T.api.request(url, options);
const window = {
  TinyCat: T, location: new URL("https://tinycat.test/admin/users?q=old&sort=username&direction=asc&page=3"),
  history: { pushState(_state, _title, url) { window.location = new URL(url, window.location); } }, addEventListener() {}
};
const context = vm.createContext({ window, document, URL, FormData, AbortController });
for (const file of ["assets/core/navigation.js", "assets/ui/ajax.js"]) {
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, "../..", file), "utf8").replace(/^import .*;\r?\n/gm, ""), context);
}
T.ajax.init();
function link(url, history, method = "GET") {
  const source = { dataset: { ajaxTarget: "#users-list", history, method }, getAttribute: name => name === "href" ? url : null };
  return { target: { closest: () => source }, preventDefault() {} };
}
(async function () {
  await handlers.click(link("/api/users?q=old&sort=username&direction=desc&page=1", "/admin/users?q=old&sort=username&direction=desc&page=1"));
  assert.equal(window.location.searchParams.get("direction"), "desc");
  assert.equal(window.location.searchParams.get("page"), "1");
  const form = { fields: [["q", "new"], ["role", "user"]], dataset: { ajaxTarget: "#users-list", apiMethod: "GET", history: "/admin/users" }, getAttribute: name => name === "action" ? "/api/users" : "get" };
  await handlers.submit({ target: { closest: () => form }, preventDefault() {} });
  const filtered = new URL(calls.at(-1).url, window.location);
  assert.equal(filtered.searchParams.get("q"), "new");
  assert.equal(filtered.searchParams.get("role"), "user");
  assert.equal(filtered.searchParams.get("sort"), "username");
  assert.equal(filtered.searchParams.get("direction"), "desc");
  assert.equal(window.location.searchParams.get("sort"), "username");
  await handlers.click(link("/api/users/1", "", "DELETE"));
  assert.equal(calls.at(-1).options.method, "DELETE");
  assert.equal(new URL(calls.at(-1).url, window.location).searchParams.get("direction"), "desc");
  const pending = [];
  T.api.request = url => new Promise(resolve => pending.push(() => resolve({ data: { url } })));
  const first = handlers.click(link("/api/users?sort=role&direction=asc", "/admin/users?sort=role&direction=asc"));
  const second = handlers.click(link("/api/users?sort=status&direction=desc", "/admin/users?sort=status&direction=desc"));
  await new Promise(resolve => setImmediate(resolve));
  pending[1](); await second; pending[0](); await first;
  assert.equal(window.location.searchParams.get("sort"), "status");
  assert.match(applied.at(-1), /sort=status/);
  T.modules.register = function () {};
  T.bots = { accounts() {}, sources(_target, data) { applied.push(data.url); } };
  T.moderation = { reports(_target, items) { applied.push(items[0]); } };
  for (const feature of ["bots", "moderation"]) {
    vm.runInContext(fs.readFileSync(path.resolve(__dirname, "../../assets/modules/" + feature + "/index.js"), "utf8").replace(/^import .*;\r?\n/gm, ""), context);
  }
  T.api.request = async url => ({ data: { url, items: [url] } });
  for (const [route, api] of [["/admin/bots/list", "/api/bots/sources"], ["/admin/moderation/reports", "/api/moderation/reports"]]) {
    window.location = new URL("https://tinycat.test" + route + "?sort=status&direction=desc");
    await T.navigation.restore();
    assert.equal(applied.at(-1), api + "?sort=status&direction=desc", "History restoration retains sorting: " + route);
  }
  console.log("PASS AJAX sorting, filter and mutation persistence, history and stale responses");
}()).catch(error => { console.error(error); process.exitCode = 1; });
