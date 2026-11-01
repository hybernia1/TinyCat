"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), path = require("node:path"), vm = require("node:vm");
const source = fs.readFileSync(path.join(__dirname, "../../assets/modules/notifications/controller.js"), "utf8").replace(/^import .*;\r?\n/gm, "");
function harness() {
  const classes = initial => { const s = new Set(initial); return { contains: n => s.has(n), remove: n => s.delete(n), toggle(n, on) { if (on) { s.add(n); } else { s.delete(n); } } }; };
  const action = () => ({ removed: false, remove() { this.removed = true; }, closest() { return this; } });
  const row = () => ({ dataset: { notificationId: "7" }, classList: classes(["is-unread"]), removed: false, read: action(), remove() { this.removed = true; }, querySelector() { return this.read.removed ? null : this.read; } });
  const rows = [row(), row()], badges = [{}, {}], readAll = { hidden: false, removed: false, remove() { this.removed = true; } };
  const list = { replaceChildren() {} }, handlers = {}, timers = [];
  const button = { dataset: { notificationUnread: "2", notificationLatestId: "8" }, classList: classes(["has-unread"]), attrs: {}, setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return this.attrs[k]; }, querySelector() { return badges[0]; } };
  const document = { hidden: false, addEventListener(n, fn) { (handlers[n] ||= []).push(fn); },
    querySelector(s) { return this.querySelectorAll(s)[0] || null; },
    querySelectorAll(s) {
      if (s === "[data-notification-button]") { return [button]; }
      if (s.includes("[data-notification-count]")) { return badges; }
      if (s === "[data-notification-list]") { return [list]; }
      if (s.includes('form[action="/api/notifications"]')) { return readAll.removed ? [] : [readAll]; }
      if (s.includes('data-notification-id="7"')) { return rows.filter(n => !n.removed); }
      if (s.includes(".is-unread")) { return rows.filter(n => !n.removed && n.classList.contains("is-unread")); }
      return [];
    }
  };
  let requests = 0, resolve, reject;
  const T = { ui: {}, dom: {}, components: { render: (_name, data) => data.items }, i18n: { plural: () => "new notifications" }, toasts: { show() {} },
    api: { request() { requests++; return new Promise((yes, no) => { resolve = yes; reject = no; }); } } };
  const window = { TinyCat: T, setInterval(fn) { timers.push(fn); } };
  vm.runInNewContext(source, { window, document });
  const result = data => T.notifications.result({ dataset: {}, getAttribute: () => "/api/notifications/7" }, { data });
  return { T, rows, badges, readAll, button, handlers, timers, result, count: () => requests, resolve: r => resolve(r), reject: e => reject(e) };
}
(async function () {
  const h = harness();
  h.result({ id: 7, deleted: false, unread_count: 1, latest_id: 8 });
  assert.ok(h.rows.every(n => !n.classList.contains("is-unread")), "Reading a notification updates both preview and full-page copies");
  assert.ok(h.rows.every(n => n.read.removed), "Read controls disappear from every copy");
  h.result({ id: 7, deleted: true, unread_count: 0, latest_id: 8 });
  assert.ok(h.rows.every(n => n.removed), "Deleting a notification removes every rendered copy");
  assert.equal(h.button.classList.contains("has-unread"), false, "Read/delete updates the navbar state immediately");
  assert.equal(h.button.dataset.notificationUnread, "0");
  assert.equal(h.readAll.removed, false, "The mark-all control remains reusable for later notifications");
  assert.equal(h.readAll.hidden, true);
  h.T.notifications.update({ unread_count: 3, latest_id: 9 });
  assert.equal(h.readAll.hidden, false);
  assert.equal(h.button.classList.contains("has-unread"), true);
  const p = harness(); p.T.notifications.init(); p.T.notifications.init();
  assert.equal(p.timers.length, 1, "Repeated initialization must not create extra polling timers");
  const pending = p.timers[0](); await p.timers[0]();
  assert.equal(p.count(), 1, "Polling requests cannot overlap");
  p.result({ id: 7, deleted: false, unread_count: 1, latest_id: 8 });
  p.resolve({ data: { unread_count: 5, latest_id: 8, items: [] } }); await pending;
  assert.equal(p.button.dataset.notificationUnread, "1", "A late poll cannot undo a completed read/delete action");
  const failed = p.timers[0](); p.reject(new Error("Network unavailable")); await failed;
  const recovered = p.timers[0](); p.resolve({ data: { unread_count: 3, latest_id: 9, items: [] } }); await recovered;
  assert.equal(p.count(), 3, "Polling recovers after network errors");
  assert.equal(p.button.dataset.notificationUnread, "3");
  // Exercise the same form-request lifecycle used by notification actions.
  const field = { disabled: false }, fixed = { disabled: true }, loading = new Set(), attrs = {};
  const form = { querySelectorAll: () => [field, fixed], classList: { contains: n => loading.has(n), add: n => loading.add(n), toggle(n, on) { if (on) { loading.add(n); } else { loading.delete(n); } } }, getAttribute: n => attrs[n] ?? null, setAttribute(n, v) { attrs[n] = v; }, removeAttribute(n) { delete attrs[n]; } };
  let finish, fail;
  const formsT = { dom: { all: (_s, root) => root.querySelectorAll() }, api: { request: () => new Promise((yes, no) => { finish = yes; fail = no; }) } };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../../assets/ui/form-requests.js"), "utf8").replace(/^import .*;\r?\n/gm, ""), { window: { TinyCat: formsT }, AbortController: class { constructor() { this.signal = {}; } abort() {} } });
  for (const outcome of ['success', 'error', 'success']) {
    const request = formsT.forms.request(form, '/api/notifications/7', {});
    assert.equal(formsT.forms.request(form, '/api/notifications/7', {}), request, 'Duplicate action submissions share one request');
    assert.equal(field.disabled, true);
    if (outcome === 'error') { fail(new Error('Network unavailable')); } else { finish({ data: {} }); }
    await request.catch(() => {});
    assert.equal(field.disabled, false, 'Notification action buttons unlock after success and failure');
    assert.equal(fixed.disabled, true, 'Originally disabled controls retain their state');
    assert.equal(loading.has('is-loading'), false);
    assert.equal(form.getAttribute('aria-busy'), null);
  }
  console.log("PASS notification copies, badges, reusable actions, polling races, network recovery");
})().catch(error => { console.error(error); process.exitCode = 1; });