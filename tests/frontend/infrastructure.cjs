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
    this.style = { removeProperty(name) { delete this[name]; }, setProperty(name, value) { this[name] = value; } };
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
  matches(selector) {
    if (selector.includes(',')) return selector.split(',').some(item => this.matches(item.trim()));
    const attr = selector.match(/^\[([^=]+)="([^"]*)"\]$/);
    if (attr) return this.getAttribute(attr[1]) === attr[2];
    return selector.startsWith("#") ? this.getAttribute("id") === selector.slice(1) : selector === this.tag;
  }
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
  const controlOptions = { "class": "custom-control input", required: true, disabled: true, "aria-describedby": "help", "data-example": "value" };
  for (const [label, render, base] of [
    ["input", () => window.TinyCat.ui.field("name", "name", "draft", "text", controlOptions), "input"],
    ["textarea", () => window.TinyCat.ui.field("name", "name", "draft", "textarea", controlOptions), "textarea"],
    ["select", () => window.TinyCat.ui.select("name", "name", "one", { one: "One" }, controlOptions), "select"]
  ]) {
    const control = render().querySelector("." + base);
    check(control !== null, label + " preserves its base class when attributes add classes.");
    check(control.getAttribute("class").split(" ").filter(name => name === base).length === 1, label + " deduplicates the base class.");
    check(control.getAttribute("class").includes("custom-control"), label + " accepts additional classes.");
    check(control.getAttribute("required") !== null && control.getAttribute("disabled") !== null, label + " accepts validation and disabled attributes.");
    check(control.getAttribute("aria-describedby") === "help" && control.getAttribute("data-example") === "value", label + " preserves accessibility and data attributes.");
    check(control.getAttribute("name") === "name", label + " retains its default name.");
  }
  check(controlOptions.class === "custom-control input", "Control helpers do not mutate caller attributes.");
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
  context.fetch = async () => ({ headers: new Headers({ "Content-Type": "application/json" }), json: async () => { throw new DOMException("Body cancelled", "AbortError"); } });
  await assert.rejects(api.request("/api/example"), error => error.name === "AbortError", "Cancelling the response body preserves cancellation instead of reporting invalid JSON"); checks++;
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

  window.matchMedia = () => ({ matches: true });
  window.visualViewport = { width: 390, height: 260, offsetLeft: 0, offsetTop: 34 };
  window.scrollY = 180;
  let restoredScroll;
  window.scrollTo = (_x, y) => { restoredScroll = y; };
  document.body.style.position = 'relative';
  const mobileEditor = window.TinyCat.dialog.create({ id: 'mobile-editor', title: 'Editor', closeLabel: 'Close', kind: 'editor', content: dom.element('form') });
  window.TinyCat.dialog.open(mobileEditor);
  check(document.body.style.position === 'fixed' && mobileEditor.style['--tinycat-modal-viewport-height'] === '260px' && mobileEditor.style['--tinycat-modal-viewport-top'] === '34px', 'Editor dialogs follow the visible keyboard viewport and retain the page scroll.');
  const nested = window.TinyCat.dialog.create({ id: 'nested-confirm', title: 'Confirm', closeLabel: 'Close', kind: 'confirm', content: dom.element('p') });
  window.TinyCat.dialog.open(nested); window.TinyCat.dialog.close(nested);
  check(window.TinyCat.dialog.current() === mobileEditor && document.body.style.position === 'fixed' && mobileEditor.getAttribute('aria-hidden') === 'false', 'Closing a nested dialog restores its editor without unlocking the page.');
  window.TinyCat.dialog.close(mobileEditor);
  check(document.body.style.position === 'relative' && restoredScroll === 180 && mobileEditor.style['--tinycat-modal-viewport-height'] === undefined, 'Closing the final editor restores the original page scroll and clears viewport overrides.');
  delete window.visualViewport; delete window.matchMedia;

  const form = new Element("form");
  form.action = "https://tinycat.test/api/example"; form.method = "POST"; form.values = [["name", "draft"]];
  form.reportValidity = () => true;
  const buttons = [{ disabled: false }, { disabled: true }];
  const inputControl = { disabled: false };
  const footerSave = { disabled: false };
  form.elements = [...buttons, inputControl, footerSave];
  form.querySelectorAll = selector => selector.includes("textarea") ? [...buttons, inputControl] : buttons;
  let release;
  context.fetch = async (url, options) => { calls.push({ url, options }); return new Promise(resolve => { release = resolve; }); };
  let applied = 0;
  window.TinyCat.forms.bind(form, { apply: () => { applied++; } });
  const before = calls.length;
  form.dispatchEvent(new Event("submit", { cancelable: true }));
  form.dispatchEvent(new Event("submit", { cancelable: true }));
  check(calls.length === before + 1 && buttons.every(button => button.disabled), "Pending forms prevent duplicate submits.");
  check(footerSave.disabled, "An associated modal footer submit is locked with the form.");
  check(inputControl.disabled, "Submitted fields are locked while the request is pending.");
  release(response(envelope(200, { id: 1 }))); await tick();
  check(applied === 1 && !buttons[0].disabled && buttons[1].disabled && form.getAttribute("aria-busy") === null, "Success applies data and restores original controls.");

  check(!footerSave.disabled, "Footer submit actions are restored after a request completes.");
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
  let externalSignal, completeExternal;
  const owner = new AbortController();
  window.TinyCat.api.request = (_url, options) => { externalSignal = options.signal; return new Promise(resolve => { completeExternal = resolve; }); };
  const ownedRequest = window.TinyCat.forms.request(form, "/owned", { signal: owner.signal });
  owner.abort();
  check(externalSignal.aborted, "A region read cancellation reaches the form transport's own controller.");
  completeExternal(envelope()); await ownedRequest;
  check(!buttons[0].disabled && buttons[1].disabled, "Cancelled owned reads preserve the form's initial disabled states.");
  window.TinyCat.api.request = () => new Promise(resolve => pending.push(resolve));
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
  const visits = [], pageRestores = [], documentVisits = [];
  window.location.assign = url => documentVisits.push(url);
  T.navigation.registerPage({ accepts: url => url.pathname === "/public", visit: url => visits.push(url.pathname), restore: event => pageRestores.push(event.state) });
  T.navigation.guard(() => false);
  await T.navigation.go("/public");
  check(visits.length === 0 && documentVisits.length === 0, "The shared leave guard blocks both document and page navigation.");
  T.navigation.guard(() => true);
  await T.navigation.go("/public");
  check(visits.length === 1 && documentVisits.length === 0, "Supported pages use their registered navigation provider.");
  await T.navigation.go("/other");
  check(documentVisits[0] === "/other", "Unregistered pages retain native document navigation.");
  window.location = new URL("https://tinycat.test/public");
  await T.navigation.restore({ state: { scroll: [0, 250] } });
  check(pageRestores[0].scroll[1] === 250, "History restoration forwards the original state to the page provider.");
  const readCore = name => fs.readFileSync(path.join(root, name === "modal" ? "assets/ui/dialogs.js" : "assets/core/" + name + ".js"), "utf8");
  check(!/TinyCat\.(?:users|status|openModal|closeModal)/.test(readCore("modal")), "Dialog infrastructure has no domain dispatch or reverse shell dependency.");
  check(!/\/api\/(?:users|statuses)/.test(readCore("navigation")), "History infrastructure does not own domain API routes.");
  check(!readCore("component-renderer").includes("T.ui."), "Component rendering depends on primitives instead of widget composition.");
  let blocked = 0;
  T.lifecycle.register("modal-policy-fixture", { hydrate: () => {
    try { T.dialog.create({ id: "forbidden-eager", title: "Editor", closeLabel: "Close", content: new Element("div") }); }
    catch (error) { if (/initialization or hydration/.test(error.message)) blocked++; else throw error; }
  } });
  T.lifecycle.hydrate(document);
  check(blocked === 1 && !T.lifecycle.preparing(), "Shared dialog creation rejects eager hydration and restores the lifecycle phase.");
  await assert.rejects(T.dialog.openResource("foreign", "https://other.test/api/example"), /same-origin API URL/);
  await assert.rejects(T.dialog.openResource("non-api", "/page"), /same-origin API URL/);
  console.log(`PASS JSON AJAX client and UI primitives (${checks} checks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });
