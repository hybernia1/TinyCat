"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const root = path.resolve(__dirname, "../..");
let checks = 0;
const check = (value, message) => { checks++; assert.ok(value, message); };

class Element extends EventTarget {
  constructor(tag) { super(); this.tag = tag; this.attributes = {}; this.children = []; this.textContent = ""; }
  setAttribute(name, value) { this.attributes[name] = value; }
  getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
  removeAttribute(name) { delete this.attributes[name]; }
  appendChild(child) { this.children.push(child); return child; }
}
class TestFormData extends FormData {
  constructor(form) { super(); for (const [key, value] of (form && form.values) || []) { this.append(key, value); } }
}
const definitions = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php"), "--templates"], { encoding: "utf8" }));
const document = { getElementById: () => null, createTextNode: text => { const node = new Element("text"); node.textContent = text; return node; }, createDocumentFragment: () => new Element("fragment"), querySelector: () => ({ content: "csrf-token" }), createElement: tag => new Element(tag), body: new Element("body") };
const window = { location: new URL("https://tinycat.test/admin"), TinyCat: {} };
let calls = [];
const envelope = (status = 200, data = {}, ok = true) => ({ ok, status, message: ok ? null : "Invalid.", data, meta: {} });
const response = (data, status = data.status) => new Response(JSON.stringify(data), { status, headers: { "Content-Type": "application/json; charset=utf-8" } });
const context = vm.createContext({ window, document, URL, Headers, FormData: TestFormData, Blob, URLSearchParams, AbortController, CustomEvent, Node: Element, console, fetch: async (url, options) => { calls.push({ url, options }); return response(envelope()); } });
for (const filename of ["api-client.js", "dom.js", "forms.js", "components.js", "modal.js"]) {
  vm.runInContext(fs.readFileSync(path.join(root, "assets/js/core", filename), "utf8"), context, { filename });
}
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
  const invalid = { ...envelope(422, null, false), error: { code: "validation_error", details: { errors: { name: ["Required."] } } } };
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
  let opened = null; window.TinyCat.openModal = value => { opened = value; };
  window.TinyCat.dialog.open(dialog);
  check(opened === dialog, "Dialogs reuse the existing shared focus/stack manager.");

  const form = new Element("form");
  form.action = "https://tinycat.test/api/example"; form.method = "POST"; form.values = [["name", "draft"]];
  form.reportValidity = () => true;
  const buttons = [{ disabled: false }, { disabled: true }];
  form.querySelectorAll = () => buttons;
  let release;
  context.fetch = async (url, options) => { calls.push({ url, options }); return new Promise(resolve => { release = resolve; }); };
  let applied = 0;
  window.TinyCat.forms.bind(form, { apply: () => { applied++; } });
  const before = calls.length;
  form.dispatchEvent(new Event("submit", { cancelable: true }));
  form.dispatchEvent(new Event("submit", { cancelable: true }));
  check(calls.length === before + 1 && buttons.every(button => button.disabled), "Pending forms prevent duplicate submits.");
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
  console.log(`PASS JSON AJAX client and UI primitives (${checks} checks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });
