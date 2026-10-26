"use strict";
const fs = require("fs"), path = require("path"), vm = require("vm"), assert = require("assert/strict");
let service, timerId = 0, tasks = new Map(), pending = [], checks = 0;
const check = (ok, message) => { checks++; assert(ok, message); };
const T = { ui: { text: key => key }, forms: { clearErrors: (form, name) => { check(name === "email", "Only this field's errors are cleared"); } }, lifecycle: { register: (_, callbacks) => { service = callbacks; } }, api: { errors: error => error.fields || {}, request: (url, options) => new Promise((resolve, reject) => { pending.push({ resolve, reject, options, url }); }) } };
const container = { children: [], appendChild(node) { this.children.push(node); } };
const document = { createElement: () => ({ setAttribute() {}, textContent: "", remove() { container.children = container.children.filter(node => node !== this); } }) };
const input = { value: "", name: "email", dataset: { emailCheckUrl: "/api/users/registrations/email-checks" }, isConnected: true, form: { handlers: {}, addEventListener(name, fn) { this.handlers[name] = fn; }, removeEventListener(name) { delete this.handlers[name]; } }, attrs: { "aria-describedby": "existing-help" }, handlers: {}, custom: "", closest: () => container,
  get validity() { return { valid: this.value.includes("@") && !this.custom }; }, setCustomValidity(value) { this.custom = value; },
  getAttribute(name) { return this.attrs[name] || ""; }, setAttribute(name, value) { this.attrs[name] = value; }, removeAttribute(name) { delete this.attrs[name]; },
  addEventListener(name, callback) { this.handlers[name] = callback; }, removeEventListener(name) { delete this.handlers[name]; } };
const root = { querySelectorAll: () => [input] };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../../assets/modules/users/email-availability.js"), "utf8").replace(/^import .*;$/mg, ""), { window: { TinyCat: T }, document, WeakMap, AbortController, setTimeout: callback => { const id = ++timerId; tasks.set(id, callback); return id; }, clearTimeout: id => tasks.delete(id) });
async function runTimer() { const callbacks = [...tasks.values()]; tasks.clear(); callbacks.forEach(callback => callback()); await Promise.resolve(); }
async function settle() { await Promise.resolve(); await Promise.resolve(); }
(async () => {
  service.hydrate(root); service.hydrate(root); check(container.children.length === 1, "Repeated hydration does not duplicate status fields");
  const status = container.children[0];
  input.value = "taken@example.test"; input.handlers.input(); input.handlers.input(); check(tasks.size === 1, "Typing is debounced");
  await runTimer(); check(pending.length === 1 && pending[0].options.method === "POST", "Checks use the shared POST API");
  pending.shift().resolve({ data: { available: false } }); await settle();
  check(input.custom && input.attrs["aria-invalid"] === "true", "Occupied addresses show an accessible validation error");
  input.form.handlers.reset(); check(!input.custom && !status.textContent, "Resetting a form clears asynchronous custom validity");
  input.value = "first@example.test"; input.handlers.input(); check(!input.custom, "Editing clears old custom validity immediately");
  await runTimer(); const old = pending.shift();
  input.value = "second@example.test"; input.handlers.input(); check(old.options.signal.aborted, "New input aborts the previous request");
  old.resolve({ data: { available: false } }); await settle(); check(!input.custom && !status.textContent, "Late responses do not overwrite newer input");
  await runTimer(); pending.shift().resolve({ data: { available: true } }); await settle(); check(status.textContent === "account.email_available" && !input.custom, "Available addresses receive feedback without blocking submission");
  input.value = "offline@example.test"; input.handlers.input(); await runTimer(); pending.shift().reject(new Error("offline")); await settle();
  check(status.textContent === "account.email_check_failed" && !input.custom, "Network failures remain advisory");
  input.value = "bad@host"; input.handlers.input(); await runTimer(); pending.shift().reject({ fields: { email: ["invalid email"] } }); await settle();
  check(input.custom === "invalid email" && status.className === "field-error", "Server format errors become field errors instead of network warnings");
  input.value = ""; input.handlers.input(); check(!tasks.size, "Optional empty addresses do not trigger requests");
  input.value = "invalid"; input.handlers.input(); check(!tasks.size, "Invalid syntax does not trigger requests");
  input.value = "late@example.test"; input.handlers.change(); await runTimer(); const late = pending.shift(); service.dispose(root);
  late.resolve({ data: { available: false } }); await settle();
  check(late.options.signal.aborted && !container.children.length && input.attrs["aria-describedby"] === "existing-help", "Disposal cancels checks and restores the original description");
  console.log(`PASS live email validation, debounce, stale replies and disposal (${checks} checks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });
