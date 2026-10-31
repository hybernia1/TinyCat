"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), path = require("node:path"), vm = require("node:vm");
const tick = () => new Promise(resolve => setImmediate(resolve));
function setup() {
  const handlers = {}, services = {}, pending = [], applied = [], toasts = [], history = [], loading = [], errors = [], focused = [];
  const confirmation = { run: async () => true };
  const target = { id: "users-list", dataset: {}, isConnected: true };
  const document = { querySelector: () => target, addEventListener: (name, fn) => { handlers[name] = fn; } };
  class FormData extends Map { constructor(form) { super(form.fields || []); } }
  const T = {
    dom: { find: () => target, emit() {} }, lifecycle: { register: (name, value) => { services[name] = value; }, hydrate() {} },
    api: { unwrap: data => data.data, errors: error => error.fields, details: () => ({}), request: (url, options) => new Promise((resolve, reject) => { pending.push({ url, options, resolve, reject }); }) },
    forms: { clearErrors() {}, applyErrors: (_, value) => { errors.push(value); return value; }, focusInvalid: field => { if (field) focused.push(field); }, reset() {}, markClean() {}, loading: (source, busy) => { loading.push([source, busy]); }, dirty: () => [], confirmDiscard: async () => true },
    i18n: { label: key => key }, actions: { confirm: source => confirmation.run(source) }, dialog: {},
    modules: { result: (source, response) => { applied.push(response.data); } }, toasts: { show: message => { toasts.push(message); } }
  };
  T.forms.request = (form, url, options) => T.api.request(url, options);
  const window = { TinyCat: T, location: new URL("https://tinycat.test/admin/users"), history: { pushState: (_, __, url) => { history.push(url); } }, addEventListener() {} };
  const context = vm.createContext({ window, document, URL, FormData, AbortController });
  for (const file of ["assets/core/requests.js", "assets/core/navigation.js", "assets/ui/lists.js", "assets/ui/ajax.js"]) {
    const absolute = path.resolve(__dirname, "../..", file);
    vm.runInContext(fs.readFileSync(absolute, "utf8").replace(/^import .*;\r?\n/gm, ""), context);
  }
  T.ajax.init();
  const link = (url, method = "GET", attrs = {}) => ({ dataset: { ajaxTarget: "#users-list", history: "/admin/users?" + url.split("?")[1], method, ...attrs }, isConnected: true, getAttribute: name => name === "href" ? url : null });
  const click = source => handlers.click({ target: { closest: () => source }, preventDefault() {} });
  const submit = form => handlers.submit({ target: { closest: () => form }, preventDefault() {} });
  return { T, confirmation, target, services, pending, applied, toasts, history, loading, errors, focused, link, click, submit };
}
(async () => {
  let s = setup();
  const old = s.click(s.link("/api/users?q=old")); await tick(); const latest = s.click(s.link("/api/users?q=new")); await tick();
  assert.equal(s.pending[0].options.signal.aborted, true, "The new GET aborts the old read.");
  s.pending[1].resolve({ data: "new" }); await latest;
  s.pending[0].reject(new Error("Old request failed")); await old;
  assert.deepEqual(s.applied, ["new"]); assert.deepEqual(s.toasts, []); assert.equal(s.history.length, 1);

  s = setup(); const removed = s.click(s.link("/api/users")); await tick(); s.target.isConnected = false;
  s.pending[0].resolve({ data: "removed" }); await removed;
  assert.equal(s.applied.length + s.history.length + s.toasts.length, 0, "Detached targets cannot apply results or history.");

  s = setup(); let confirm; s.confirmation.run = () => new Promise(resolve => { confirm = resolve; });
  const action = s.link("/api/users/1", "DELETE", { confirm: "Delete?" }); const first = s.click(action); const duplicate = s.click(action); await tick();
  assert.equal(s.pending.length, 0); confirm(true); await tick();
  assert.equal(s.pending.length, 1, "A double click while waiting for confirmation sends one mutation.");
  s.pending[0].resolve({ data: "deleted" }); await Promise.all([first, duplicate]);
  const retry = s.click(action); await tick(); confirm(true); await tick(); assert.equal(s.pending.length, 2, "Successful completion releases the action lock.");
  s.pending[1].resolve({ data: "retry" }); await retry;

  s = setup(); const write = s.click(s.link("/api/users/1", "DELETE")); const sameWrite = s.click(s.link("/api/users/1", "DELETE"));
  const otherWrite = s.click(s.link("/api/users/2", "DELETE")); await tick();
  assert.equal(s.pending.length, 2, "Two controls for one action share a lock; another entity stays independent.");
  assert.equal(s.pending[0].options.signal, undefined, "A mutation is not aborted as if it were a replaceable read.");
  s.pending.forEach(p => p.resolve({ data: {} })); await Promise.all([write, sameWrite, otherWrite]);

  s = setup(); const source = s.link("/api/users"); const a = s.click(source); await tick(); const b = s.click(source); await tick();
  s.pending[0].resolve({ data: "old" }); await a;
  assert.equal(s.loading.at(-1)[1], true, "Old cleanup cannot clear a newer loading state on the same link.");
  s.pending[1].resolve({ data: "new" }); await b; assert.equal(s.loading.at(-1)[1], false);

  s = setup(); s.T.forms.confirmDiscard = async () => false;
  await s.click(s.link("/api/users/1", "DELETE")); assert.equal(s.pending.length, 0);
  s.T.forms.confirmDiscard = async () => true;
  const accepted = s.click(s.link("/api/users/1", "DELETE")); await tick(); assert.equal(s.pending.length, 1);
  s.pending[0].reject(new Error("Current failure")); await accepted; assert.deepEqual(s.toasts, ["Current failure"]);
  const afterFailure = s.click(s.link("/api/users/1", "DELETE")); await tick(); assert.equal(s.pending.length, 2);
  s.pending[1].resolve({ data: {} }); await afterFailure;

  s = setup(); const form = { dataset: { ajaxTarget: "#users-list", apiMethod: "GET" }, isConnected: true, getAttribute: name => name === "action" ? "/api/users" : "get" };
  const formRequest = s.submit(form); await tick(); const linkRequest = s.click(s.link("/api/users?q=next")); await tick();
  assert.equal(s.pending[0].options.signal.aborted, true, "A newer link invalidates a GET form on the same region.");
  s.pending[1].resolve({ data: "next" }); await linkRequest;
  s.pending[0].reject(Object.assign(new Error("Old validation"), { fields: { q: ["Invalid"] } })); await formRequest;
  assert.equal(s.errors.length + s.focused.length + s.toasts.length, 0);

  s = setup(); const waiting = s.click(s.link("/api/users")); await tick();
  const region = { contains: node => node === s.target };
  Object.values(s.services).forEach(service => { if (service.dispose) service.dispose(region); });
  assert.equal(s.pending[0].options.signal.aborted, true, "Disposing an owner subtree cancels its read.");
  s.pending[0].resolve({ data: "disposed" }); await waiting; assert.equal(s.applied.length, 0);

  s = setup(); const confirmations = [];
  s.confirmation.run = () => new Promise(resolve => confirmations.push(resolve));
  const earlyForm = { dataset: { ajaxTarget: "#users-list", apiMethod: "GET", confirm: "Filter?" }, isConnected: true, getAttribute: name => name === "action" ? "/api/users" : "get" };
  const beforeConfirm = s.submit(earlyForm); await tick();
  const afterConfirm = s.click(s.link("/api/users?q=new")); await tick();
  s.pending[0].resolve({ data: "new" }); await afterConfirm;
  confirmations[0](true); await beforeConfirm;
  assert.equal(s.pending.length, 1, "A GET form waiting for confirmation cannot supersede a newer navigation intent.");

  s = setup(); s.T.navigation.register("/admin/users", { target: "#users-list", url: () => "/api/users", apply: (_, response) => s.applied.push(response.data) });
  const restoring = s.T.navigation.restorePage(); await tick();
  const filtered = s.click(s.link("/api/users?q=filtered")); await tick();
  assert.equal(s.pending[0].options.signal.aborted, true, "History and AJAX links share the region's read ownership.");
  s.pending[1].resolve({ data: "filtered" }); await filtered;
  s.pending[0].resolve({ data: "history" }); await restoring;
  assert.deepEqual(s.applied, ["filtered"]);
  console.log("PASS AJAX stale failures, disconnected targets, mutation locks, scoped reads and loading ownership");
})().catch(error => { console.error(error); process.exitCode = 1; });
