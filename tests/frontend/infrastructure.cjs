"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const root = path.resolve(__dirname, "../..");
let checks = 0;
const check = (value, message) => { checks++; assert.ok(value, message); };

class Element extends EventTarget {
  constructor(tag) {
    super(); this.tag = tag; this.attributes = {}; this.children = []; this.textContent = "";
    this.dataset = new Proxy({}, { get: (_, key) => this.getAttribute("data-" + key.replace(/[A-Z]/g, c => "-" + c.toLowerCase())), set: (_, key, value) => { this.setAttribute("data-" + key.replace(/[A-Z]/g, c => "-" + c.toLowerCase()), value); return true; } });
    this.classList = { add() {}, remove() {}, toggle() {} };
    this.style = { removeProperty() {}, setProperty() {} };
  }
  setAttribute(name, value) { this.attributes[name] = value; }
  getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
  removeAttribute(name) { delete this.attributes[name]; }
  appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
  querySelectorAll(selector) {
    const found = [];
    const visit = root => root.children.forEach(child => { if (selector.startsWith(".") && String(child.getAttribute("class") || "").split(" ").includes(selector.slice(1))) found.push(child); visit(child); });
    visit(this); return found;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  matches(selector) { return selector.startsWith("#") ? this.getAttribute("id") === selector.slice(1) : selector === this.tag; }
  closest() { return null; }
  focus() { document.activeElement = this; }
  remove() { if (this.parentNode) { this.parentNode.children = this.parentNode.children.filter(child => child !== this); this.parentNode = null; } }
}
class TestFormData extends FormData {
  constructor(form) { super(); for (const [key, value] of (form && form.values) || []) { this.append(key, value); } }
}
const definitions = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php"), "--templates"], { encoding: "utf8" }));
const document = { addEventListener() {}, querySelectorAll() { return []; }, getElementById: () => null, createTextNode: text => { const node = new Element("text"); node.textContent = text; return node; }, createDocumentFragment: () => new Element("fragment"), querySelector: () => ({ content: "csrf-token" }), createElement: tag => new Element(tag), body: new Element("body") };
const window = { location: new URL("https://tinycat.test/admin"), TinyCat: {}, addEventListener() {}, setTimeout() {}, requestAnimationFrame(callback) { callback(); }, scrollTo() {} };
let calls = [];
const envelope = (status = 200, data = {}, ok = true) => ({ ok, status, code: ok ? "ok" : "validation_error", params: {}, message: ok ? null : "Invalid.", data, meta: {} });
const response = (data, status = data.status) => new Response(JSON.stringify(data), { status, headers: { "Content-Type": "application/json; charset=utf-8" } });
const context = vm.createContext({ window, document, URL, Headers, FormData: TestFormData, Blob, URLSearchParams, AbortController, CustomEvent, Node: Element, Element, console, fetch: async (url, options) => { calls.push({ url, options }); return response(envelope()); } });
const coreAssets = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/asset-graph-fixture.php"), "--core"], { encoding: "utf8", maxBuffer: 8e6 }));
for (const asset of coreAssets) vm.runInContext(asset.source, context, { filename: asset.path });
window.TinyCat.components.register(definitions);
const tick = () => new Promise(resolve => setImmediate(resolve));

(async () => {
  const api = window.TinyCat.api;
  await api.request("/api/example", { method: "POST", body: { name: "Žaneta" } });
  check(calls[0].options.headers.get("Accept") === "application/json", "Only JSON is negotiated.");
  check(calls[0].options.headers.get("X-CSRF-Token") === "csrf-token", "CSRF is included.");
  check(calls[0].options.headers.get("Content-Type") === "application/json" && JSON.parse(calls[0].options.body).name === "Žaneta", "Objects are JSON encoded.");
  check(calls[0].options.credentials === "same-origin", "Session cookies use same-origin credentials.");
  check(!calls[0].url.includes("view="), "The transport never adds HTML negotiation.");

  const formData = new TestFormData(); formData.set("name", "upload");
  await api.request("/api/example/1", { method: "PATCH", body: formData });
  check(calls[1].options.method === "POST" && calls[1].options.body.get("_method") === "PATCH" && !formData.has("_method"), "Multipart method overrides preserve caller data.");
  await assert.rejects(api.request("https://other.test/api/example"), /current origin/); checks++;
  check(calls.length === 2, "Cross-origin requests cannot leak CSRF tokens.");

  context.fetch = async () => new Response("<form>unexpected page</form>", { headers: { "Content-Type": "text/html" } });
  await assert.rejects(api.request("/api/example"), error => error.data === null && /format/.test(error.message)); checks++;
  context.fetch = async () => response({ ok: true, status: 201, data: {}, meta: {} }, 200);
  await assert.rejects(api.request("/api/example"), /envelope/); checks++;
  context.fetch = async () => new Response("{invalid", { headers: { "Content-Type": "application/json" } });
  await assert.rejects(api.request("/api/example"), /invalid JSON/); checks++;
  const invalid = { ...envelope(422, null, false), error: { details: { errors: { name: [{ code: "invalid_value", params: { field: "name" }, message: "Required." }] } } } };
  context.fetch = async () => response(invalid);
  try { await api.request("/api/example"); assert.fail("Expected validation failure."); } catch (error) {
    check(api.errors(error).name[0] === "Required." && error.response.status === 422, "Errors preserve the canonical field errors and HTTP response.");
  }
  context.fetch = async (_url, options) => { if (options.signal.aborted) { throw new DOMException("Aborted", "AbortError"); } return response(envelope()); };
  const controller = new AbortController(); controller.abort();
  await assert.rejects(api.request("/api/example", { signal: controller.signal }), { name: "AbortError" }); checks++;

  const dom = window.TinyCat.dom;
  check(dom.element("span", {}, "<img onerror=alert(1)>").textContent === "<img onerror=alert(1)>", "User content remains literal text.");
  for (const operation of [() => dom.element("script"), () => dom.element("a", { href: "javascript:alert(1)" }), () => dom.element("div", { onclick: "unsafe" })]) {
    assert.throws(operation); checks++;
  }
  const dialog = window.TinyCat.dialog.create({ id: "example-dialog", title: "<User title>", closeLabel: "Close", content: dom.element("p", {}, "Content") });
  check(dialog.attributes.role === "dialog" && dialog.attributes["aria-labelledby"] === "example-dialog-title", "Dialogs have accessible names and DOM content.");
  assert.throws(() => window.TinyCat.dialog.create({ id: "invalid", title: "Title", content: "<form>fragment</form>" })); checks++;
  window.TinyCat.dialog.open(dialog);
  check(window.TinyCat.dialog.current() === dialog && dialog.dataset.open === "true", "Dialogs own their focus/stack state independently of the application shell.");
  window.TinyCat.dialog.close(dialog);
  check(window.TinyCat.dialog.current() === null && dialog.dataset.open === "false", "Closing the dialog clears the independently owned stack.");

  const form = new Element("form");
  form.action = "https://tinycat.test/api/example"; form.method = "POST"; form.values = [["name", "draft"]];
  form.reportValidity = () => true;
  const buttons = [{ disabled: false }, { disabled: true }];
  const inputControl = { disabled: false };
  form.querySelectorAll = selector => selector.includes("textarea") ? [...buttons, inputControl] : buttons;
  let release;
  context.fetch = async (url, options) => { calls.push({ url, options }); return new Promise(resolve => { release = resolve; }); };
  let applied = 0;
  window.TinyCat.forms.bind(form, { apply: () => { applied++; } });
  const before = calls.length;
  form.dispatchEvent(new Event("submit", { cancelable: true }));
  form.dispatchEvent(new Event("submit", { cancelable: true }));
  check(calls.length === before + 1 && buttons.every(button => button.disabled), "Pending forms prevent duplicate submits.");
  check(inputControl.disabled, "Submitted fields are locked while the request is pending.");
  release(response(envelope(200, { id: 1 }))); await tick();
  check(applied === 1 && !buttons[0].disabled && buttons[1].disabled && form.getAttribute("aria-busy") === null, "Success applies data and restores original controls.");

  let errors = 0;
  context.fetch = async () => response(invalid);
  window.TinyCat.forms.bind(form, { error: (_error, fields) => { if (fields.name) { errors++; } } });
  form.dispatchEvent(new Event("submit", { cancelable: true })); await tick();
  check(errors === 1 && !buttons[0].disabled && form.values[0][1] === "draft", "Rebinding and validation errors retain the draft and restore controls.");

  form.method = "GET";
  form.values.push(["role", "admin"], ["role", "user"]);
  context.fetch = async (url, options) => { calls.push({ url, options }); return response(envelope()); };
  const dispose = window.TinyCat.forms.bind(form, {});
  form.dispatchEvent(new Event("submit", { cancelable: true })); await tick();
  check(calls.at(-1).url.includes("name=draft") && calls.at(-1).options.body === undefined, "GET forms serialize filters into the query instead of a request body.");
  check(new URL(calls.at(-1).url).searchParams.getAll("role").length === 2, "GET forms preserve repeated filter values.");
  const count = calls.length; dispose(); form.dispatchEvent(new Event("submit", { cancelable: true })); await tick();
  check(calls.length === count, "Disposed forms cannot submit again.");
  const pending = [];
  window.TinyCat.api.request = () => new Promise(resolve => pending.push(resolve));
  const cancelled = window.TinyCat.forms.request(form, "/first", {});
  window.TinyCat.forms.cancel(form);
  const replacement = window.TinyCat.forms.request(form, "/second", {});
  pending[0](envelope()); await cancelled;
  check(buttons[0].disabled && form.getAttribute("aria-busy") === "true", "A cancelled request cannot unlock a newer form submission.");
  pending[1](envelope()); await replacement;
  check(!buttons[0].disabled && buttons[1].disabled, "Replacement requests restore the original disabled states.");
  const T = window.TinyCat;
  let initialized = 0, hydrated = 0, disposed = 0;
  T.lifecycle.register("test", { init: () => { initialized++; }, hydrate: () => { hydrated++; }, dispose: () => { disposed++; } });
  T.lifecycle.start(); T.lifecycle.start();
  check(initialized === 1 && hydrated === 1, "Lifecycle initializes services once, independently of the shell.");
  T.lifecycle.hydrate(document);
  check(hydrated === 2 && initialized === 1, "AJAX hydration does not bind global events again.");
  const inFlight = T.forms.request(form, "/dispose", {});
  T.lifecycle.dispose(form);
  check(disposed === 1 && !buttons[0].disabled, "Disposal cancels form work and restores its controls.");
  pending[2](envelope()); await inFlight;
  const target = new Element("section"), restores = [];
  target.setAttribute("id", "restore-target");
  const originalQuery = document.querySelector;
  document.querySelector = selector => selector === "#restore-target" ? target : originalQuery(selector);
  T.api.request = (_url, options) => new Promise(resolve => restores.push({ resolve, signal: options.signal }));
  let rendered = null;
  T.navigation.register("/admin", { target: "#restore-target", url: () => "/api/test", apply: (_target, result) => { rendered = result.data.id; } });
  const oldRestore = T.navigation.restore(), newRestore = T.navigation.restore();
  check(restores[0].signal.aborted, "New history navigation aborts the preceding read.");
  restores[1].resolve(envelope(200, { id: 2 })); await newRestore;
  restores[0].resolve(envelope(200, { id: 1 })); await oldRestore;
  check(rendered === 2, "A stale history response cannot overwrite the current resource.");
  const removedRestore = T.navigation.restore(); T.lifecycle.dispose(target);
  check(restores[2].signal.aborted, "Removing a history target aborts its outstanding read.");
  restores[2].resolve(envelope(200, { id: 3 })); await removedRestore;
  check(rendered === 2, "Disposed history targets cannot receive late data.");
  const readCore = name => fs.readFileSync(path.join(root, name === "modal" ? "assets/ui/dialogs.js" : "assets/core/" + name + ".js"), "utf8");
  check(!/TinyCat\.(?:users|status|openModal|closeModal)/.test(readCore("modal")), "Dialog infrastructure has no domain dispatch or reverse shell dependency.");
  check(!/\/api\/(?:users|statuses)/.test(readCore("navigation")), "History infrastructure does not own domain API routes.");
  check(!readCore("components").includes("T.ui."), "Component rendering depends on primitives instead of widget composition.");
  console.log(`PASS JSON AJAX client and UI primitives (${checks} checks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });
