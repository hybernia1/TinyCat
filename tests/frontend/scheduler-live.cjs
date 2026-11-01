"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
(async function () {
  let tick, resolve, reject, calls = 0, renders = 0, modal = false, focused = false, cleared = false, timers = 0;
  const handlers = {}, initial = { runs: [], pagination: {} };
  const view = { dataset: { schedulerState: JSON.stringify(initial) } };
  const target = { dataset: {}, firstElementChild: view, isConnected: true, contains: () => focused, querySelector: () => null };
  const document = { hidden: false, activeElement: { matches: () => true }, addEventListener: (name, fn) => { handlers[name] = fn; },
    querySelector: selector => selector === "#scheduler-list" ? target : selector === '.modal[data-open="true"]' && modal ? {} : null,
    querySelectorAll: () => [view] };
  const T = { api: { request(url, options) { calls++; assert.equal(url, "/api/scheduler/tasks?task=feeds&page=2"); assert.equal(options.cache, "no-store"); return new Promise((yes, no) => { resolve = yes; reject = no; }); } },
    ui: { pagination: response => response.meta.pagination }, lifecycle: { hydrate() {} } };
  const window = { TinyCat: T, location: { search: "?task=feeds&page=2" }, setInterval(fn, delay) { timers++; assert.equal(delay, 5000); tick = fn; return 1; }, clearInterval() { cleared = true; } };
  vm.runInNewContext(fs.readFileSync(require("node:path").join(__dirname, "../../assets/modules/scheduler/controller.js"), "utf8").replace(/^import .*;\r?\n/gm, ""), { window, document, AbortController, URL });
  T.scheduler.filters = () => {};
  T.scheduler.list = () => { renders++; };
  T.scheduler.init(); T.scheduler.hydrate(document);
  document.hidden = true; await tick(); assert.equal(calls, 0);
  document.hidden = false; modal = true; await tick(); assert.equal(calls, 0);
  modal = false; focused = true; await tick(); assert.equal(calls, 0); focused = false;
  let request = tick(); await tick(); assert.equal(calls, 1);
  resolve({ data: { runs: [] }, meta: { pagination: {} } }); await request; assert.equal(renders, 0);
  request = tick(); target.dataset.apiGeneration = "1";
  resolve({ data: { runs: [1] }, meta: { pagination: {} } }); await request; assert.equal(renders, 0);
  request = tick(); focused = true;
  resolve({ data: { runs: [1] }, meta: { pagination: {} } }); await request; assert.equal(renders, 0); focused = false;
  request = tick(); reject(new Error("offline")); await request;
  request = tick(); resolve({ data: { runs: [1] }, meta: { pagination: {} } }); await request; assert.equal(renders, 1);
  T.scheduler.dispose({ contains: () => true }); assert.equal(cleared, true);
  T.scheduler.hydrate(document); assert.equal(timers, 2, "Returning to an initialized view restarts polling.");
  console.log("PASS scheduler live updates preserve filters, skip editing and hidden tabs, reject stale results and recover after errors");
})().catch(error => { console.error(error); process.exitCode = 1; });
