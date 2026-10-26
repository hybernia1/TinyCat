"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const root = path.resolve(__dirname, "../..");
let checks = 0;
function check(condition, message) { checks++; assert.ok(condition, message); }
class Node {
  constructor(tag, text = "") {
    this.tag = tag; this.attributes = {}; this.children = []; this.value = ""; this._text = text;
    this.dataset = new Proxy({}, { get: (_, key) => this.getAttribute("data-" + String(key).replace(/[A-Z]/g, c => "-" + c.toLowerCase())), set: (_, key, value) => { this.setAttribute("data-" + String(key).replace(/[A-Z]/g, c => "-" + c.toLowerCase()), value); return true; }, deleteProperty: (_, key) => { this.removeAttribute("data-" + String(key).replace(/[A-Z]/g, c => "-" + c.toLowerCase())); return true; } });
    this.classList = { add: value => { this.className += " " + value; }, toggle: (value, enabled) => { const names = this.className.split(/\s+/).filter(x => x !== value); if (enabled) names.push(value); this.className = names.join(" "); } };
  }
  set className(value) { this.setAttribute("class", value); }
  set id(value) { this.setAttribute("id", value); }
  get id() { return this.getAttribute("id") || ""; }
  get className() { return this.getAttribute("class") || ""; }
  set value(value) { this._value = String(value); if (this.tag === "select") this.children.forEach(child => { child.selected = child.getAttribute("value") === this._value; }); }
  get value() { if (this.tag === "select") { const option = this.children.find(child => child.selected) || this.children[0]; return option ? option.getAttribute("value") : ""; } return this._value; }
  set textContent(value) { this._text = String(value); this.children = []; }
  get textContent() { return this._text + this.children.map(x => x.textContent).join(""); }
  set innerHTML(_) { throw new Error("A module renderer must never interpret HTML."); }
  setAttribute(name, value) { this.attributes[name] = String(value); if (name === "value") this._value = String(value); if (name === "selected") this.selected = true; if (name === "disabled") this.disabled = true; }
  getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
  removeAttribute(name) { delete this.attributes[name]; }
  appendChild(node) { if (node.tag === "fragment") { node.children.slice().forEach(child => this.appendChild(child)); return node; } node.parent = this; this.children.push(node); return node; }
  append(...nodes) { nodes.forEach(node => this.appendChild(node)); }
  replaceChildren(...nodes) { this._text = ""; this.children = []; this.append(...nodes); }
  get childNodes() { return this.children; }
  get options() { return this.tag === "select" ? this.children : undefined; }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(x => x !== this); }
  querySelectorAll(selector) {
    const match = node => {
      if (selector.includes(",")) return selector.split(",").some(tag => node.tag === tag.trim());
      const attr = selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
      if (attr) return node.getAttribute(attr[1]) !== null && (attr[2] === undefined || node.getAttribute(attr[1]) === attr[2]);
      return selector[0] === "." ? node.className.split(/\s+/).includes(selector.slice(1)) : node.tag === selector;
    };
    const found = []; const visit = node => { for (const child of node.children) { if (match(child)) found.push(child); visit(child); } }; visit(this); return found;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  cloneNode(deep) { const node = new Node(this.tag); node.attributes = { ...this.attributes }; node.value = this.attributes.value || ""; if (deep) this.children.forEach(child => node.appendChild(child.cloneNode(true))); return node; }
  dispatchEvent() { return true; }
  addEventListener() {}
}
const labels = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/asset-graph-fixture.php"), "--catalog"], { encoding: "utf8" }));
const definitions = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php"), "--templates"], { encoding: "utf8" }));
const document = { querySelector: () => null, body: new Node("body"), createElement: tag => new Node(tag), createElementNS: (_, tag) => new Node(tag), createTextNode: text => new Node("text", text), createDocumentFragment: () => new Node("fragment"), getElementById: id => document.body.querySelector('[id="' + id + '"]') };
document.documentElement = { dataset: JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php"), "--dates"], { encoding: "utf8" })) };
const window = { TinyCat: {}, location: new URL("https://tinycat.test/") };
const context = vm.createContext({ window, document, Node, AbortController, URL, URLSearchParams, console, Set, Element: Node, RadioNodeList: class {}, CustomEvent: class {} });
const moduleAssets = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/asset-graph-fixture.php"), "--all"], { encoding: "utf8", maxBuffer: 8e6 }));
for (const asset of moduleAssets) vm.runInContext(asset.source, context, { filename: asset.path });
const T = window.TinyCat;
T.components.register(definitions);
T.i18n.register("en", labels);
const dateCases = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php"), "--date-fixtures"], { encoding: "utf8" }));
const originalDates = { ...document.documentElement.dataset };
document.documentElement.dataset.dateRelative = "false";
dateCases.absolute.forEach(item => { document.documentElement.dataset.dateFormat = item.format; check(T.dates.format(item.value) === item.expected, "PHP/JS date preset and DST offset parity: " + item.format); });
const csLabels = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/asset-graph-fixture.php"), "--catalog", "cs"], { encoding: "utf8" }));
dateCases.relative.forEach(item => { if (T.i18n.locale() !== item.locale) T.i18n.register(item.locale, item.locale === "cs" ? csLabels : labels); check(T.dates.relative(new Date(item.value), item.base) === item.expected, "PHP/JS relative-time threshold and plural parity: " + item.locale + " " + item.value); });
T.i18n.register("en", labels);
document.documentElement.dataset = originalDates;
const pluralCases = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php"), "--translation-fixtures"], { encoding: "utf8" }));
pluralCases.forEach(item => {
  if (T.i18n.locale() !== item.locale) T.i18n.register(item.locale, item.locale.startsWith("cs") ? csLabels : labels);
  check(T.i18n.pluralCategory(item.count, item.locale) === item.category && T.i18n.plural(item.key, item.count) === item.expected, "PHP/JS plural and regional fallback parity: " + item.locale + " " + item.key + " " + item.count);
});
T.i18n.register("en", labels);
check(T.i18n.format("{{name}} :name {name}", { name: "Zofie" }) === "{{name}} :name Zofie", "Only canonical placeholders are interpolated, with literal legacy examples preserved.");
assert.throws(() => T.i18n.pluralCategory(Infinity, "cs")); checks++;
T.api.registerMessages({ example_saved: "common.save", example_invalid: "common.request_failed" });
check(T.api.message({ code: "example_saved", params: {}, message: "Server fallback" }) === T.i18n.t("common.save"), "Known message codes use the client catalog.");
check(T.api.message({ code: "unknown_event", params: {}, message: "Readable fallback" }) === "Readable fallback", "Unknown codes preserve the readable API fallback.");
check(T.api.errors({ data: { error: { details: { errors: { name: [{ code: "example_invalid", params: {}, message: "Fallback" }] } } } } }).name[0] === T.i18n.t("common.request_failed"), "Field issue codes use the same client message mapping.");
const countedSidebar = T.site.sidebar({ tags: [], authors: [{ id: 2, username: "Member", url: "/author/2", avatar_url: "", posts_count: 5 }], footer: [] }, "/api/site/sidebar");
check(countedSidebar.textContent.includes(T.i18n.plural("public.active_user_posts", 5)), "A translation parameter named count is a value binding, not the count operator.");
const canonicalFieldErrors = T.api.errors;
const unsafe = '<img src=x onerror="window.pwned=true">';
const user = { id: 2, username: unsafe, url: "/author/2", avatar_url: "", email: "test@example.test", bio: unsafe, locale: "en", theme: "system" };
let node = T.users.profiles([user]);
check(node.textContent.endsWith(unsafe), "Following profiles preserve literal names without inserting markup.");
check(node.querySelectorAll("img").length === 0, "Malicious names cannot create images or event handlers.");
node = T.status.segments([{ type: "text", text: unsafe }, { type: "mention", text: "@2", url: "/author/2" }]);
check(node.textContent === unsafe + "@2" && node.querySelector("a").getAttribute("href") === "/author/2", "Structured text preserves literal content and explicit mention targets.");
assert.throws(() => T.status.segments([{ type: "link", text: "unsafe", url: "javascript:alert(1)" }])); checks++;
const post = { id: 10, url: "/status/10", author: user, body: unsafe, editable_body: unsafe, segments: [{ type: "text", text: unsafe }], published_at: "2026-01-01T12:00:00Z", published_label: "Today", image: { url: "", alt: "" }, links: [], likes_count: 2, comments_count: 1, liked: false, capabilities: { edit: false, delete: false, report: false, interact: true } };
node = T.status.card(post);
check(node.dataset.statusId === "10" && node.querySelector(".status-body").textContent === unsafe, "Status cards use resource identity and safe DOM text.");
check(node.id === "status-10" && !T.status.card(post, false).id, "Feed cards keep permalink anchors while modal cards avoid duplicate IDs.");
check(node.querySelectorAll("details").length === 0, "Status menus follow server capabilities.");
check(node.querySelector('[data-status-count="likes"]').textContent === "2", "Resource counts are rendered in the existing status controls.");
check(node.querySelector(".public-content-meta") !== null && node.querySelector(".status-manage").querySelector("a").getAttribute("href") === post.url, "API cards retain the server card's small timestamp and permalink control.");
check(T.ui.avatar(user).querySelector("use").getAttribute("href").endsWith("#user"), "Missing avatars use the same SVG user icon as server-rendered cards.");
const modalPost = Object.assign({}, post, { capabilities: { edit: true, delete: true, report: false, interact: true }, image: { url: "/uploads/statuses/fixture.webp", alt: "Fixture" } });
const detailData = { item: modalPost, viewer: user, comments: [], options: { images_enabled: true } };
const statusDialog = T.status.modal(new URL("https://tinycat.test/api/statuses/10"), { data: detailData }, "status-layout-fixture");
check(statusDialog.querySelector(".modal-backdrop") && statusDialog.querySelector(".status-post-modal-content"), "Status dialogs retain the shared backdrop and original content layout.");
check(statusDialog.querySelector(".status-comment-input-shell") && statusDialog.querySelector(".status-comment-form").children.some(child => child.className.split(/\s+/).includes("avatar")), "The comment form retains a separate avatar column and a full-width editor shell.");
check(statusDialog.querySelector(".status-time-button").getAttribute("data-modal-open") === null, "A status dialog does not recursively open itself from its timestamp.");
const editDialog = T.status.modal(new URL("https://tinycat.test/api/statuses/10?panel=edit"), { data: detailData }, "status-editor-fixture");
check(editDialog.querySelector(".status-compose-main") && editDialog.querySelector('[data-status-image-input]') && editDialog.querySelector('[data-status-image-remove-input]').getAttribute("value") === "0", "Status editing preserves the composer layout, image picker and explicit removal state.");
check(editDialog.querySelector('[name="body"]').value === post.editable_body, "Opening the status editor preserves the exact editable text.");
const profileDialog = T.users.modal(new URL("https://tinycat.test/api/users/2/management?focus=email"), { data: { user, options: { languages: { en: "English" }, password_max_length: 72 }, capabilities: { password: true } } }, "profile-layout-fixture");
check(profileDialog.querySelector('[data-tab-panel="email"]').hidden === false && profileDialog.querySelector('[data-tab-panel="profile"]').hidden === true, "Profile focus selects the requested tab without mixing all settings into one form.");
check(profileDialog.querySelector('[data-tab-panel="email"]').getAttribute("action") === "/api/users/me/email", "The email panel submits to its own endpoint.");
const avatarDialog = T.users.modal(new URL("https://tinycat.test/api/users/2/avatar"), { data: { user } }, "avatar-layout-fixture");
check(avatarDialog.querySelector('[data-avatar-upload-input]') && avatarDialog.querySelector('[data-avatar-upload-preview]'), "The avatar dialog provides the existing upload and preview lifecycle hooks.");
check(avatarDialog.querySelector('.avatar-upload-preview').className.split(/\s+/).includes('avatar'), "The avatar preview retains the shared avatar layout and fallback alignment.");
const comment = { id: 20, content_id: 10, parent_id: 0, author: user, body: unsafe, segments: [{ type: "text", text: unsafe }], editable_body: unsafe, created_at: "2026-01-01T12:00:00Z", created_label: "Today", likes_count: 0, liked: false, capabilities: { edit: true, delete: true, interact: true }, replies: [] };
node = T.status.comment(comment, "modal");
check(node.dataset.contentId === "10" && node.querySelector(".status-comment-body").textContent === unsafe, "Comment DOM retains its parent status and safe text.");
check(node.querySelector('[name="parent_id"]').getAttribute("value") === "20", "Reply forms address the comment parent explicitly.");
const form = new Node("form"); form.appendChild(new Node("button")); form.querySelector("button").setAttribute("aria-pressed", "false");
check(T.status.method(form, "react") === "PUT", "An unliked resource requests PUT.");
form.querySelector("button").setAttribute("aria-pressed", "true");
check(T.status.method(form, "react") === "DELETE", "A liked resource requests DELETE rather than a toggle.");
check(T.status.method(form, "comment_update") === "PATCH" && T.status.method(form, "comment_delete") === "DELETE", "Comment edits and deletions use resource methods.");
node = T.notifications.items([{ id: 1, type: "follow", actor: { username: unsafe }, excerpt: unsafe, url: "/status/10", unread: true, created_at: "2026-01-01T12:00:00+00:00" }], false);
check(node.querySelector("strong").textContent === T.i18n.t("notifications.messages.follow", { actor: unsafe }) && node.querySelectorAll("img").length === 0, "Notifications translate event data and render actors and excerpts as literal text.");
check(node.querySelector('[data-api-method="PATCH"]').getAttribute("action") === "/api/notifications/1", "Notification actions address the individual resource.");
const response = { data: { user, options: { languages: { en: "English" }, password_max_length: 1024 }, capabilities: { password: false } }, meta: {} };
node = T.users.modal(new URL("https://tinycat.test/api/users/2/management"), response, "profile-test");
check(node.getAttribute("role") === "dialog" && node.getAttribute("aria-labelledby") === "profile-test-title", "Profile dialogs retain accessible names.");
check(!node.querySelector('[name="current_password"]'), "Other account profiles never show a password change form.");
check(node.querySelector('[name="bio"]').value === unsafe, "Profile editors retain literal draft data.");
const target = new Node("div");
T.users.list(target, { data: { items: [{ ...user, role: "admin", status: "active", updated_at_formatted: "Today", can_delete: false }], filters: { q: "admin", role: "admin", status: "active", updated_from: "", updated_to: "" }, roles: { admin: "Admin", user: "User" }, statuses: { active: "Active" }, role_options: { "": "All", admin: "Admin", user: "User" }, status_options: { "": "All", active: "Active" }, options: { password_max_length: 1024, username_pattern: "[A-Za-z0-9]+", username_hint: "Letters" } }, meta: { pagination: { page: 1, last_page: 1, per_page: 25 } } });
const filterDialog = document.getElementById("users-filter-modal");
check(filterDialog && filterDialog.querySelector('[name="per_page"]').value === "25" && filterDialog.querySelector('[name="role"]').value === "admin", "AJAX list replacement preserves the filter dialog and its current select values.");
check(filterDialog.dataset.modalDisposeOnClose === "false" && filterDialog.querySelector('[name="per_page"]').children.find(option => option.getAttribute("value") === "25").defaultSelected, "Locally owned filter dialogs remain reusable and discard restores the current filters.");
const createDialog = document.getElementById("user-create-modal");
check(createDialog.dataset.modalDisposeOnClose === "false" && createDialog.querySelector("form").dataset.modalCloseOnSuccess === "true", "Account dialogs can reopen after cancel and close the modal stack after successful writes.");
check(!target.querySelector('[data-api-method="DELETE"]'), "Protected-account capabilities remove deletion controls from AJAX lists.");
const existingBio = new Node("p", "Retained profile bio");
document.querySelectorAll = selector => selector.includes("data-author-bio") ? [existingBio] : [];
const avatarForm = new Node("form"); avatarForm.setAttribute("action", "/api/users/2/avatar"); avatarForm.setAttribute("method", "post"); avatarForm.closest = () => null;
T.users.result(avatarForm, { data: { user: { id: 2, username: "Member", avatar_url: "" } } }, null);
check(existingBio.textContent === "Retained profile bio", "Partial avatar resources never erase profile fields they do not contain.");
let localeReloads = 0;
document.body.dataset.currentUserId = "2";
window.setTimeout = callback => callback();
window.location.reload = () => { localeReloads++; };
T.users.result(avatarForm, { data: { user: { id: 2, locale: "cs" } } }, null);
check(localeReloads === 1, "Changing the current user's locale reloads the document and its hashed catalog.");
T.users.result(avatarForm, { data: { user: { id: 3, locale: "cs" } } }, null);
T.users.result(avatarForm, { data: { user: { id: 2, locale: "en" } } }, null);
check(localeReloads === 1, "Other accounts and unchanged locales do not reload the current catalog.");
delete document.body.dataset.currentUserId;
delete window.location.reload;
const templateKeys = new Set();
function collectLabels(value) { if (!value || typeof value !== "object") return; if (typeof value.t === "string") templateKeys.add(value.t); Object.values(value).forEach(collectLabels); }
collectLabels(definitions);
check([...templateKeys].every(key => Object.hasOwn(labels, key)), "Every shared component label has a real translation.");
const sourceRoot = new Node("div");
T.bots.sources(sourceRoot, { bots: [user], items: [{ id: 7, bot_user_id: 2, bot_username: unsafe, name: unsafe, feed_url: "https://example.test/feed", enabled: true, interval_minutes: 60, last_error: unsafe }] });
check(sourceRoot.textContent.includes(unsafe) && sourceRoot.querySelectorAll("img").length === 0, "Bot source values remain literal text during AJAX rendering.");
const sourceActions = sourceRoot.querySelectorAll("form");
check(sourceActions.some(form => form.getAttribute("action") === "/api/bots/sources/7" && form.dataset.apiMethod === "PATCH" && form.querySelector('[name="enabled"]').getAttribute("value") === "0"), "Source pause requests an explicit disabled state at the resource URL.");
check(sourceActions.some(form => form.getAttribute("action") === "/api/bots/sources/7/runs" && form.dataset.apiMethod === "POST"), "Manual feed imports create a run resource.");
node = T.bots.sourceForm({ id: 7, bot_user_id: 2, name: unsafe, post_template: unsafe, enabled: true }, [user]);
check(node.dataset.apiMethod === "PATCH" && node.querySelector('[name="post_template"]').value === unsafe, "Source editing uses a local form and preserves the template as text.");
const reportRoot = new Node("div");
T.moderation.reports(reportRoot, [{ id: 12, content_id: 9, author_name: unsafe, excerpt: unsafe, open_count: 1, report_count: 1, status: "open" }]);
check(reportRoot.querySelectorAll("form").every(form => form.getAttribute("action") === "/api/moderation/reports/12" && form.dataset.apiMethod === "PATCH"), "Moderation controls address the report by path ID.");
check(reportRoot.querySelectorAll("img").length === 0 && reportRoot.textContent.includes(unsafe), "Report excerpts never become markup.");
const footer = new Node("div");
T.site.rich(footer, [{ type: "text", text: unsafe }, { type: "element", name: "script", children: [] }, { type: "element", name: "a", attributes: { href: "mailto:hello@example.test", onclick: "unsafe" }, children: [{ type: "text", text: "Email" }] }]);
check(footer.querySelectorAll("script").length === 0 && footer.querySelector("a").getAttribute("onclick") === null && footer.textContent.startsWith(unsafe), "Structured footer rendering ignores executable nodes and event attributes.");
assert.throws(() => T.site.rich(new Node("div"), [{ type: "element", name: "a", attributes: { href: "javascript:alert(1)" } }])); checks++;
const parityFixtures = JSON.parse(fs.readFileSync(path.join(root, "tests/fixtures/components.json"), "utf8"));
const serverTrees = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/component-parity.php")], { encoding: "utf8" }));
function semanticTree(rootNode) {
  const nodes = [];
  function visit(node) {
    if (node.tag !== "text" && node.tag !== "fragment") {
      const attributes = {};
      Object.keys(node.attributes).sort().forEach(key => {
        if (key === "focusable" || key.endsWith("-state")) return;
        let value = node.attributes[key];
        if (key === "href" && node.tag === "use") value = value.slice(value.indexOf("#"));
        if (key === "value" && node.attributes.name === "_csrf") value = "[csrf]";
        if (["selected", "disabled", "required", "hidden", "checked"].includes(key)) value = "";
        attributes[key] = value;
      });
      nodes.push({ tag: node.tag, attributes, text: node._text + node.children.filter(child => child.tag === "text").map(child => child.textContent).join("") });
    }
    node.children.forEach(visit);
  }
  visit(rootNode); return nodes;
}
parityFixtures.forEach((fixture, index) => {
  const data = Object.assign({}, fixture.data);
  Object.entries(fixture.slots || {}).forEach(([key, text]) => { data[key] = document.createTextNode(text); });
  assert.deepEqual(semanticTree(T.components.render(fixture.component, data)), serverTrees[index], "PHP and browser component parity: " + fixture.component + " fixture " + index);
  checks++;
});
for (const attributes of [{ onclick: "alert(1)" }, { style: "color:red" }, { srcdoc: "<script>bad</script>" }, { src: "javascript:alert(1)" }, { formaction: "java\nscript:alert(1)" }, { src: "data:text/html,bad" }]) {
  assert.throws(() => T.components.render("ui/field", { label: "Unsafe", attributes })); checks++;
}
assert.throws(() => T.components.render("ui/modal", { root_attributes: {}, panel_attributes: {}, close_icon: "<img onerror=bad>", body: "<script>bad</script>" })); checks++;

(async () => {
  const owner = new Node("section"); owner.dataset.botDetailId = "2";
  const counters = ["sources", "active_sources", "posts", "runs"].map(key => { const node = new Node("strong"); node.dataset.botStat = key; node.textContent = "stale"; return node; });
  document.querySelector = selector => selector === "[data-bot-detail-id]" ? owner : selector === "[data-bot-sources]" ? sourceRoot : null;
  document.querySelectorAll = selector => selector === "[data-bot-stat]" ? counters : [];
  const previousRequest = T.api.request;
  T.api.request = async () => ({ data: { item: { id: 2, username: "Bot", post_count: 4 }, sources: [{ id: 7, enabled: false }], runs: [{ id: 1 }, { id: 2 }] } });
  const sourceAction = new Node("form"); sourceAction.setAttribute("action", "/api/bots/sources/7");
  check(T.bots.result(sourceAction, {}, null), "Bot detail accepts its canonical resource mutation.");
  await Promise.resolve();
  check(counters.map(node => node.textContent).join(",") === "1,0,4,2", "AJAX detail refresh updates all source, post and run counters.");
  check(sourceRoot.querySelector("[data-bot-sources-view]"), "AJAX detail preserves the shared source component.");
  T.api.request = previousRequest;
  const handlers = {};
  document.readyState = "loading";
  document.addEventListener = (name, callback) => { (handlers[name] ||= []).push(callback); };
  document.querySelectorAll = () => [];
  document.querySelector = () => null;
  window.addEventListener = () => {};
  window.setTimeout = setTimeout;
  context.FormData = class extends FormData {
    constructor(form) { super(); (form.fields || []).filter(field => !field.disabled).forEach(field => this.append(field.name, field.value)); }
  };
  let sent;
  T.api = { request: async (url, options) => { sent = { url, options }; return { ok: true, data: { action: "react" }, meta: {} }; }, errors: canonicalFieldErrors };
  const bootAssets = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, "tests/presentation/asset-graph-fixture.php"), "--bootstrap"], { encoding: "utf8" }));
  for (const asset of bootAssets) vm.runInContext(asset.source, context);
  T.status.controllers.actions.initStatusForms();
  const statusForm = new Node("form");
  statusForm.setAttribute("action", "/api/statuses/10/likes/me"); statusForm.setAttribute("method", "POST");
  statusForm.fields = [{ name: "action", value: "react", disabled: false }, { name: "body", value: "Retained draft", disabled: false }];
  statusForm.querySelectorAll = selector => selector === "button, input, select, textarea" ? statusForm.fields : [];
  statusForm.closest = selector => selector === "form[data-status-form]" ? statusForm : null;
  statusForm.elements = { namedItem: name => statusForm.fields.find(field => field.name === name) || null };
  statusForm.fields.forEach(field => { field.dataset = {}; });
  await handlers.submit[0]({ target: statusForm, preventDefault() {}, submitter: { name: "schedule_post", value: "1" } });
  check(sent.options.body.get("body") === "Retained draft" && sent.options.body.get("schedule_post") === "1", "Actual status submit captures fields and the submitter before controls become disabled.");
  check(statusForm.fields.every(field => !field.disabled) && !statusForm.dataset.statusBusy, "Actual status submit restores controls after the JSON result.");
  let notify = 0;
  T.toasts.show = () => { notify++; };
  T.api.request = async () => { throw new Error("Offline"); };
  await handlers.submit[0]({ target: statusForm, preventDefault() {} });
  check(notify === 1 && statusForm.fields[1].value === "Retained draft" && statusForm.fields.every(field => !field.disabled), "Transport failures retain the actual editor draft and restore its controls.");
  let finish, submitted = 0;
  T.api.request = () => { submitted++; return new Promise(resolve => { finish = resolve; }); };
  const pending = handlers.submit[0]({ target: statusForm, preventDefault() {} });
  await handlers.submit[0]({ target: statusForm, preventDefault() {} });
  check(submitted === 1, "Actual status forms block a second submit while pending.");
  finish({ ok: true, data: { action: "react" }, meta: {} }); await pending;
  const invalidBody = new Node("textarea"), fieldContainer = new Node("label");
  invalidBody.name = "body"; invalidBody.value = "Retained draft"; invalidBody.isConnected = true;
  invalidBody.closest = selector => selector === "[data-captcha], .field" ? fieldContainer : null;
  let focused = false; invalidBody.focus = () => { focused = true; };
  statusForm.fields[1] = invalidBody;
  T.api.request = async () => { const error = new Error("Validation failed."); error.data = { error: { details: { errors: { body: ["Too long."] } } } }; throw error; };
  await handlers.submit[0]({ target: statusForm, preventDefault() {} });
  check(invalidBody.getAttribute("aria-invalid") === "true" && fieldContainer.textContent === "Too long." && focused, "Canonical validation errors reach the actual editor field and move focus to it.");
  context.File = File;
  let emptyFileClock = 100;
  context.FormData = class extends FormData {
    constructor() { super(); this.append("body", "Original"); this.append("image", new File([], "", { lastModified: ++emptyFileClock })); }
  };
  const untouched = new Node("form"); untouched.dataset.confirmUnsaved = "true";
  T.lifecycle.start();
  T.forms.hydrate({ querySelectorAll: () => [untouched] });
  handlers.change.forEach(callback => callback({ target: { closest: () => untouched } }));
  check(untouched.dataset.dirty === "false", "An empty upload input with a fresh File timestamp does not mark an unchanged editor dirty.");
  console.log(`PASS domain JSON renderers and request state (${checks} checks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });
