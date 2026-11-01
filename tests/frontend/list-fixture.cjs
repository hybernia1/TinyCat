"use strict";
const fs = require("node:fs"), path = require("node:path"), vm = require("node:vm");
class Node {
  constructor(id = "") { this.id = id; this.dataset = {}; this.children = []; this.isConnected = false; }
  contains(node) { return node === this || this.children.some(child => child.contains(node)); }
  replaceChildren(...nodes) { this.children.forEach(node => { node.isConnected = false; }); this.children = nodes; nodes.forEach(node => { node.isConnected = this.isConnected; }); }
  querySelectorAll() { return []; } querySelector() { return null; }
}
module.exports = function setup() {
  const targets = new Map(), pending = [], rendered = [], hydrated = [], emitted = [], services = {}, handlers = {}, saved = [], pushes = [];
  for (const id of ["users-list", "bots-list", "bot-accounts-list", "bot-queue-list", "scheduler-list", "moderation-reports"]) { const node = new Node(id); node.isConnected = true; targets.set(id, node); }
  const document = { activeElement: null, querySelector: selector => targets.get(selector.slice(1)) || null, getElementById: id => targets.get(id) || null, querySelectorAll: () => [], addEventListener: (name, fn) => { handlers[name] = fn; } };
  const T = {
    dom: { find: selector => document.querySelector(selector), all: () => [], emit: (_, name, detail) => { emitted.push({ name, detail }); } },
    lifecycle: { register: (name, service) => { services[name] = service; }, hydrate: root => hydrated.push(root), dispose: root => Object.values(services).forEach(service => { if (service.dispose) service.dispose(root); }) },
    ui: { pagination: response => response.meta.pagination, filterPanel() {} }, filters: { get: () => null, capture: () => null, restore() {} },
    forms: { clearErrors() {}, applyErrors() {}, focusInvalid() {}, loading() {}, reset: source => saved.push(source), markClean() {}, dirty: () => [], confirmDiscard: async () => true },
    actions: {}, dialog: {}, i18n: { label: key => key },
    api: { unwrap: response => response.data, errors: () => null, details: () => ({}), request: (url, options) => new Promise((resolve, reject) => { pending.push({ url, options, resolve, reject }); }) },
    botDetail: { result: () => false, hydrate() {}, dispose() {} },
    components: { render: (name, data) => { rendered.push({ name, data }); return new Node(); } }, toasts: { show() {} }
  };
  T.forms.request = (_, url, options) => T.api.request(url, options);
  const window = { TinyCat: T, location: new URL("https://tinycat.test/admin/users?q=old&page=2&sort=username&direction=asc"), history: { pushState: (_, __, url) => { pushes.push(url); window.location = new URL(url, window.location); } }, addEventListener() {} };
  class FormData extends Map { constructor(source) { super(source.fields || []); } }
  const context = vm.createContext({ window, document, URL, URLSearchParams, AbortController, Node, FormData });
  const load = file => vm.runInContext(fs.readFileSync(path.resolve(__dirname, "../..", file), "utf8").replace(/^import .*;\r?\n/gm, ""), context, { filename: file });
  for (const file of ["assets/core/request-lifecycle.js", "assets/core/navigation.js", "assets/core/modules.js", "assets/ui/list-navigation.js", "assets/ui/content-updates.js", "assets/ui/ajax-bindings.js", "assets/modules/users/controller.js", "assets/modules/bots/controller.js", "assets/modules/moderation/controller.js", "assets/modules/scheduler/controller.js"]) load(file);
  T.users.avatarUpload = { hydrate() {} };
  T.users.list = (target, response) => { rendered.push({ name: "users/list", data: response.data }); T.content.replaceChildren(target, new Node()); };
  for (const feature of ["users", "bots", "moderation", "scheduler"]) load("assets/modules/" + feature + "/index.js");
  T.ajax.init();
  function source(url, method = "GET", target = "users-list") {
    return { dataset: { ajaxTarget: "#" + target, apiMethod: method, method }, isConnected: true,
      getAttribute: name => name === "href" || name === "action" ? url : name === "method" ? method.toLowerCase() : null, closest: () => null };
  }
  const click = source => handlers.click({ target: { closest: () => source }, preventDefault() {} });
  return { T, document, window, targets, pending, rendered, hydrated, emitted, saved, pushes, services, source, click, Node };
};
