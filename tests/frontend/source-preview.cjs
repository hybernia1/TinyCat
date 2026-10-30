"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), vm = require("node:vm"), path = require("node:path");
(async function () {
  let service, dialogs = [], requests = [], saves = 0, cancelled = 0;
  class Element {
    constructor(tag, attrs = {}, text = "") { this.tag = tag; this.attrs = attrs; this.textContent = text; this.children = []; this.handlers = {}; this.dataset = {}; this.value = attrs.value || ""; this.files = []; }
    get isConnected() { return this === document.body || !!(this.parent && this.parent.isConnected); }
    appendChild(child) { child.parent = this; this.children.push(child); return child; }
    replaceChildren(...children) { this.children = []; children.forEach(child => this.appendChild(child)); }
    closest(selector) { return this.tag === selector || (selector === ".modal" && this.tag === "modal") ? this : this.parent && this.parent.closest(selector); }
    getAttribute(name) { return this.attrs[name] ?? null; }
    querySelectorAll(selector) {
      const found = [];
      const match = node => selector === "form[action]" ? node.tag === "form" && node.attrs.action : selector === '[data-bot-preview-check]:checked' ? node.attrs["data-bot-preview-check"] !== undefined && node.checked : selector.startsWith('[name="') ? node.attrs.name === selector.slice(7, -2) : node.attrs[selector.slice(1, -1)] !== undefined;
      const visit = node => node.children.forEach(child => { if (match(child)) found.push(child); visit(child); }); visit(this); return found;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    addEventListener(name, callback) { (this.handlers[name] ||= []).push(callback); }
    fire(name) { const event = { stopped: false, preventDefault() { this.stopped = true; }, stopImmediatePropagation() {} }; (this.handlers[name] || []).forEach(callback => callback(event)); return event; }
    reportValidity() { return true; }
    requestSubmit() { if (!this.fire("submit").stopped) saves++; }
  }
  const document = { body: new Element("body"), getElementById() { return null; } };
  const T = { lifecycle: { register(name, value) { service = value; } }, dom: { element: (tag, attrs, text) => new Element(tag, attrs, text) },
    ui: { text: key => key, button: (key, attrs) => new Element("button", attrs, key), editorActions() { const actions = new Element("div"); actions.appendChild(new Element("button")); return actions; }, dialog(id, title, content, persistent, footer) { const dialog = new Element("modal"); dialog.appendChild(content); if (footer) dialog.appendChild(footer); document.body.appendChild(dialog); dialogs.push(dialog); return dialog; } },
    dialog: { open() {}, close(dialog) { dialog.fire("tinycat:modal-close"); dialog.parent = null; } },
    forms: { request(form, url, options) { return new Promise((resolve, reject) => requests.push({ form, url, options, resolve, reject })); }, cancel() { cancelled++; }, markClean() {} },
    components: { render(name, resource) { const result = new Element("div"); result.resource = resource; return result; } },
    toasts: { show() {} }, bots: { result() {} } };
  class FormData {
    constructor(form) { this.form = form; }
    forEach(callback) { this.form.children.filter(child => child.attrs.name).forEach(child => callback(child.value, child.attrs.name)); }
  }
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../../assets/modules/bots/source-preview.js"), "utf8").replace(/^import .*;\r?\n/gm, ""), { window: { TinyCat: T, location: { search: "" } }, document, FormData });
  const flush = async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); };
  const form = document.body.appendChild(new Element("form", { action: "/api/bots/sources" }));
  for (const [name, value] of Object.entries({ feed_url: "https://public.example/feed", name: "Feed", post_template: "{{title}}" })) form.appendChild(new Element("input", { name, value }));
  const button = form.appendChild(new Element("button", { "data-bot-source-preview": "" }));
  form.appendChild(new Element("input", { name: "_method", value: "PATCH" }));
  service.hydrate(document.body);
  form.requestSubmit(); assert.equal(saves, 0, "Saving cannot bypass the preview.");
  assert.equal(requests[0].url, "/api/bots/sources/previews");
  assert.equal(requests[0].options.body._method, undefined, "Edit form method overrides never leak into the POST preview request.");
  form.requestSubmit(); assert.equal(requests.length, 1, "Repeated submits share the pending preview.");
  const feed = { format: "rss", title: "Feed", feed_url: "https://public.example/redirected-feed", items: [{ body: "Sample" }], sources: [] };
  requests[0].resolve({ data: feed }); await flush();
  assert.equal(form.querySelector('[name="feed_url"]').value, "https://public.example/feed", "A preview redirect must not change source identity or reset GUID history.");
  dialogs.at(-1).children.at(-1).children.at(-1).fire("click");
  assert.equal(saves, 1, "Confirming the reviewed form permits exactly one save.");
  form.requestSubmit(); assert.equal(requests.length, 2, "A later save requires a new review.");
  form.querySelector('[name="post_template"]').value = "Changed";
  requests[1].resolve({ data: feed }); await flush();
  assert.equal(dialogs.at(-1).children[0].textContent, "bots.preview_changed", "Stale responses cannot approve edited settings.");
  button.fire("click"); T.dialog.close(dialogs.at(-1)); requests[2].resolve({ data: feed }); await flush();
  assert.equal(saves, 1, "Closing a preview never saves a source.");
  assert.ok(cancelled > 0, "Closing previews cancels pending work.");
  const run = document.body.appendChild(new Element("form", { action: "/api/bots/sources/1/runs" }));
  for (const [name, value] of Object.entries({ feed_url: "https://public.example/feed", name: "Feed", post_template: "{{title}}" })) run.appendChild(new Element("input", { name, value }));
  const requestsBeforeRun = requests.length, dialogsBeforeRun = dialogs.length;
  service.hydrate(document.body); run.requestSubmit();
  assert.equal(saves, 2, "Run now submits the original import action immediately.");
  assert.equal(requests.length, requestsBeforeRun, "Manual runs never request a source preview.");
  assert.equal(dialogs.length, dialogsBeforeRun, "Manual runs never open a preview modal.");
  const editModal = document.body.appendChild(new Element("modal"));
  const editForm = editModal.appendChild(new Element("form", { action: "/api/bots/sources/1" }));
  for (const [name, value] of Object.entries({ feed_url: "https://public.example/feed", name: "Existing source", post_template: "{{title}}", _method: "PATCH" })) editForm.appendChild(new Element("input", { name, value }));
  const editPreview = editForm.appendChild(new Element("button", { "data-bot-source-preview": "" }));
  service.hydrate(editModal);
  editPreview.fire("click");
  assert.equal(requests.length, requestsBeforeRun + 1, "Hydrating a newly created edit modal activates its preview button.");
  assert.equal(requests.at(-1).options.body.name, "Existing source");
  assert.equal(requests.at(-1).options.body._method, undefined);
  requests.at(-1).resolve({ data: feed }); await flush();
  assert.equal(dialogs.at(-1).children.at(-1).children.at(-1).textContent, "bots.preview_confirm", "The edit modal preview loads its sample and confirmation action.");
  console.log("PASS source form previews, direct manual runs, stale data rejection and cancellation");
})().catch(error => { console.error(error); process.exitCode = 1; });
