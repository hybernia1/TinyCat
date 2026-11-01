"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
(async function () {
  const handlers = {}, services = {}, classes = new Set();
  let requests = 0, renders = 0, resolve, reject;
  const sidebar = { dataset: { publicSidebarUrl: "/api/site/sidebar" }, isConnected: true,
    classList: { add: name => classes.add(name), remove: name => classes.delete(name) },
    hasAttribute: name => name === "data-public-sidebar-url", replaceChildren() { renders++; } };
  const document = { addEventListener: (name, fn) => { handlers[name] = fn; } };
  const T = { site: {}, dom: { all: selector => selector === "[data-public-sidebar-url]" ? [sidebar] : [] },
    lifecycle: { register: (name, service) => { services[name] = service; }, hydrate() {} },
    api: { unwrap: r => r.data, request() { requests++; return new Promise((yes, no) => { resolve = yes; reject = no; }); } } };
  T.site.sidebar = () => ({ childNodes: [{}] });
  const window = { TinyCat: T, matchMedia: () => ({ matches: true, addEventListener() {} }), setInterval: () => 1 };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../../assets/modules/site/sidebar-data.js"), "utf8").replace(/^import .*;\r?\n/gm, ""), { window, document, Date, URL });
  const settle = async () => { for (let i = 0; i < 12; i++) { await Promise.resolve(); } };
  const assertReady = () => {
    assert.equal(classes.has("is-loading"), false, "Completed sidebar refresh must restore pointer events");
    assert.equal(sidebar.dataset.sidebarLoading, undefined, "Refresh must release its busy guard");
  };
  services["sidebar-freshness"].init();
  T.site.refresh(document);
  assert.equal(classes.has("is-loading"), true);
  resolve({ data: {} }); await settle(); assertReady();
  for (const action of ["/api/statuses/42/likes/me", "/api/statuses/42/comments", "/api/statuses/42", "/api/statuses", "/api/users/2/followers/me"]) {
    const before = requests;
    const event = { target: { getAttribute: name => name === "action" ? action : null } };
    handlers["tinycat:success"](event); handlers["tinycat:success"](event);
    assert.equal(requests, before + 1, "Concurrent invalidations share one refresh: " + action);
    resolve({ data: {} }); await settle(); assertReady();
  }
  const event = { target: { getAttribute: () => "/api/statuses/42/likes/me" } };
  handlers["tinycat:success"](event); reject(new Error("Network unavailable")); await settle(); assertReady();
  handlers["tinycat:success"](event); resolve({ data: {} }); await settle(); assertReady();
  assert.equal(renders, 7);
  console.log("PASS sidebar refresh restores interaction after likes, comments, post changes, follows and network errors");
})().catch(error => { console.error(error); process.exitCode = 1; });