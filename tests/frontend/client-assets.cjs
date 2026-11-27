"use strict";
const assert = require("node:assert/strict");
const path = require("node:path");
const vm = require("node:vm");
const { execFileSync } = require("node:child_process");
const fixture = path.resolve(__dirname, "../presentation/fixtures/client-assets-fixture.php");
let checks = 0;
let preparedBuild;
const bundles = new Map();
function check(value, message) { checks++; assert.ok(value, message); }
function load(route, locale = "en", compiled = false) {
  const key = JSON.stringify([route, locale, compiled]);
  if (!bundles.has(key)) {
    const php = process.env.TINYCAT_TEST_PHP || "php";
    if (compiled && !preparedBuild) preparedBuild = execFileSync(php, [fixture, "--build"], { maxBuffer: 64 * 1024 * 1024 });
    bundles.set(key, execFileSync(php, [fixture, route, locale, compiled === "min" ? "--compiled-min" : compiled ? "--compiled" : "", compiled ? "--prepared" : ""], { input: compiled ? preparedBuild : undefined, encoding: "utf8", maxBuffer: 8 * 1024 * 1024 }));
  }
  // Every call still boots a fresh VM; runtime mutations cannot leak between cases.
  const bundle = JSON.parse(bundles.get(key));
  check(bundle.assets.length === 4 && bundle.styles.length === 1, "Exactly four scripts and one stylesheet: " + route);
  check(new Set(bundle.assets.map(a => a.url)).size === 4, "No duplicate scripts");
  const emptyRoot = { dataset: {}, classList: { add() {}, remove() {}, toggle() {} }, querySelectorAll() { return []; }, querySelector() { return null; } };
  const document = { readyState: "loading", body: emptyRoot, documentElement: emptyRoot, addEventListener() {}, querySelectorAll() { return []; }, querySelector() { return null; }, getElementById() { throw new Error("Catalogs must not be read from DOM"); } };
  const window = { TinyCat: {}, location: new URL("https://tinycat.test" + route), addEventListener() {}, setInterval() {} };
  const context = vm.createContext({ window, document, URL, URLSearchParams, TextEncoder, console, Set, Map });
  for (const asset of bundle.assets) vm.runInContext(asset.source, context, { filename: asset.url });
  window.TinyCat.init();
  check(window.TinyCat.modules.navigationUrl({ dataset: {}, getAttribute() { return null; }, closest() { return null; } }) === "", "Non-status navigation does not require the status module: " + route);
  check(window.TinyCat.i18n.locale() === locale, route + " boots the requested locale with external assets in delivery order");
  return { ...bundle, T: window.TinyCat, context, document };
}
const publicPage = load("/");
for (const mode of [false, true, "min"]) {
  for (const route of ["/login", "/register", "/recovery", "/logout", "/privacy", "/notifications"]) {
    const page = load(route, "en", mode);
    check(page.T.authentication && !page.T.status && !page.T.socialUsers && !page.T.site, "Core public pages exclude the Social feed runtime in every delivery variant: " + route);
    check(!page.T.filters && !page.T.adminDefinitions && !page.T.settings, "Public pages exclude administrative controllers: " + route);
    check(!page.definitions.includes("status/card") && !page.definitions.includes("bots/sources") && !page.definitions.includes("users/admin-fields"), "Core public catalogs exclude domain and admin views: " + route);
    check(!Object.keys(page.catalog).some(key => key.startsWith("bots.") || key.startsWith("settings.fields.") || key.startsWith("settings.sections.") || key === "settings.title"), "Core public catalogs exclude administrative editor translations: " + route);
  }
  const search = load("/search/all", "en", mode);
  check(search.T.status && !search.T.socialUsers && search.definitions.includes("search/posts") && search.definitions.includes("status/card"), "Search delivers the owner's SSR card and interactive dependencies in every delivery variant");
  check(search.sources.includes("App/Modules/Social/assets/status/index.js"), "Search cards use the same status controller as feed cards");
  check(search.style_sources.includes("App/Modules/Social/assets/status/styles/status-header.css") && search.style_sources.includes("App/Modules/Social/assets/status/styles/status-content-mobile.css"), "Search SSR cards receive the owner's desktop and mobile styles");
  check(new Set(search.sources).size===search.sources.length && new Set(search.style_sources).size===search.style_sources.length, "Search dependencies are initialized and styled once");
  const queue = load("/admin/social/bots/queue", "en", mode);
  check(["status/edit-fields", "status/dialog-form"].every(name => queue.definitions.includes(name)), "Bot queue delivers its complete status editor dependency in every asset variant");
  const profile = load("/author/2", "cs", mode);
  const followForm = { dataset: {}, getAttribute: name => name === "action" ? "/api/social/profiles/2/followers/me" : null };
  const response = { data: { author_id: 2, following: true, followers_count: 1 } };
  const followers = { textContent: "4" }, following = { textContent: "7" };
  profile.document.querySelectorAll = selector => selector.includes("data-author-stat='followers'") ? [followers] : selector.includes("data-author-stat='following'") ? [following] : [];
  followForm.matches = selector => selector === "form[data-follow-form]";
  const action = { value: "follow" };
  followForm.elements = { namedItem: () => action };
  followForm.querySelector = () => ({ replaceChildren() {}, classList: { toggle() {} } });
  profile.T.components.render = () => ({});
  profile.T.socialUsers.result(followForm, response);
  check(followers.textContent === "1" && following.textContent === "7" && action.value === "unfollow", "Follow updates followers but preserves the author's following count");
  profile.T.socialUsers.result(followForm, { data: { author_id: 2, following: false, followers_count: 0 } });
  check(followers.textContent === "0" && following.textContent === "7" && action.value === "follow", "Unfollow updates followers but preserves the author's following count");
  check(profile.T.accounts.result(followForm, response, null) === false, "Core accounts leave relationship responses to their owner in every public asset variant");
  let handled = false;
  profile.T.socialUsers.result = (source, result) => { handled = source === followForm && result === response; return []; };
  profile.T.modules.result(followForm, response, null);
  check(handled, "The actual public module chain delivers follow results to Social");
}
check(publicPage.T.accounts !== publicPage.T.socialUsers && typeof publicPage.T.accounts.follow === "undefined" && typeof publicPage.T.socialUsers.follow === "function", "Social relationships never mutate the core account service");
check(typeof publicPage.T.accounts.list === "undefined" && !publicPage.T.accounts.avatarUpload && publicPage.T.deferredAssets.script, "Public account controls defer avatar editing without the administrative list adapter");
for (const mode of [false, true, "min"]) {
  for (const route of ["/login", "/account", "/access/notifications"]) {
    const access = load(route, "en", mode);
    check(!access.sources.some(source => source.startsWith("App/Modules/")), "Access delivery excludes optional module assets in source and compiled variants: " + route);
    check(!access.T.search && !access.T.siteAnalytics, "Access delivery omits public search and analytics: " + route);
    check(Boolean(access.T.authentication.modal), "Access delivery preserves lazy account and authentication dialogs: " + route);
  }
}
const login = load("/login");
check(!login.sources.some(source => source.endsWith("avatar-editor.js") || source.endsWith("Identity/assets/controller.js")), "Login omits account and avatar editors");
check(!login.T.accounts && login.T.authentication.modal, "Login keeps resource authentication dialogs without user-list code");
check(publicPage.T.status && publicPage.T.socialUsers && !publicPage.T.bots && !publicPage.T.moderation, "Public pages load their own feature scripts");
check(!publicPage.T.filters, "The public feed does not load administrative list filters");
check(!publicPage.definitions.includes("users/admin-fields") && !publicPage.definitions.includes("bots/sources"), "Public bundles exclude administration components");
const users = load("/admin/users");
check(users.T.adminDefinitions["core.users"].component === "users/list", "User administration loads its declared list");
check(users.T.accounts && !users.T.status && !users.T.bots, "User administration boots independently");
check(users.definitions.includes("users/admin-fields"), "User administration receives its form definitions");
const bots = load("/admin/social/bots/accounts");
check(bots.T.bots && bots.T.accounts && bots.T.status, "Bot administration includes account controls and the shared post editor");
const botDetail = load("/admin/social/bots/detail");
check(botDetail.T.bots && botDetail.T.status && botDetail.definitions.includes("bots/posts") && botDetail.definitions.includes("status/composer-body"), "Only bot detail loads the post list and shared post editor");
check(bots.definitions.includes("ui/avatar"), "Component dependencies cross module boundaries without loading unrelated scripts");
check(bots.definitions.includes("status/composer-body") && !bots.definitions.includes("status/card"), "Bot administration includes the shared post editor without public feed cards.");
check([users, bots, load('/admin')].every(page => page.definitions.includes('notifications/items') && page.sources.includes('App/Core/Notifications/assets/polling.js') && page.T.notifications), 'Administration shares the public notification list, controller and polling lifecycle.');
check(load("/admin/social/moderation/reports").T.moderation, "Moderation loads its module");
check(load("/admin/cron").T.scheduler, "Scheduler loads its module");
check(load("/admin").T.settings && load("/admin").T.accounts, "Every admin page includes settings and shared profile dialogs");
check(load("/admin/updates").sources.includes("App/Core/Updates/assets/index.js") && !load("/admin").sources.includes("App/Core/Updates/assets/index.js"), "The release-notes renderer is scoped to the updates page.");
check(!load("/install").T.status, "Installer boots without public modules");
check(!load("/install").definitions.includes("ui/modal") && load("/admin").definitions.includes("ui/modal"), "Installer excludes unused dialog catalogs while administration retains them.");
check(!users.sources.some(s => s.endsWith("/following.js")), "Administration omits public following controller");
check(!load("/install").sources.some(s => s.includes("/Modules/Social/") || s === "App/Core/Ui/assets/ajax-bindings.js") && load("/install").sources.includes("App/Core/Ui/assets/form-requests.js"), "Installer uses shared API form transport without feed and administration controllers");
check(load("/install").sources.includes("App/Core/Ui/assets/form-constraints.js") && !load("/install").sources.includes("App/Core/Ui/assets/form-state.js"), "Installer shares byte constraints without loading draft management");
{
  const installer = load('/install').T;
  const field = {value:'éééé', name:'password', dataset:{minBytes:'8'}, matches:()=>true, hasAttribute:name=>name==='data-min-bytes', setCustomValidity(message){this.error=message;}};
  installer.forms.syncConstraints(field); check(!field.error, 'Installer accepts the eight-byte Unicode boundary');
  field.value='ééé'; installer.forms.syncConstraints(field); check(Boolean(field.error), 'Installer rejects a password below the byte boundary');
}
const schedulerPage = load("/admin/cron");
check(schedulerPage.T.scheduler && schedulerPage.T.dates && schedulerPage.definitions.includes("scheduler/filter-form"), "The scheduler page independently loads date formatting and shared filter components.");
check(load("/admin/cron", "cs").T.i18n.t("common.filters") === "Filtry", "The cron filter modal receives its Czech title in the independently scoped client catalog.");
for (const mode of [false, true, "min"]) {
  const health = load("/admin/cron", "cs", mode);
  check(health.T.scheduler && health.T.settings && health.T.adminNavigation && health.T.adminDefinitions['core.scheduler-runs'] && !health.definitions.includes('health/report') && health.definitions.includes('ui/detail-table'), "The unified scheduler delivers shared history, configuration and shared result tables without Health components.");
  check(health.T.scheduler.result({dataset:{},getAttribute:name=>name==="action"?"/api/scheduler/tasks/site-health/runs":null},{data:{}},null) !== false, "Health task actions use the shared scheduler list transport.");

  const cron = load("/admin/cron", "cs", mode);
  const now = Date.UTC(2026, 9, 27, 12, 0, 0);
  check(cron.T.dates.relative(new Date(now - 2 * 60000), now) === "p\u0159ed 2 minutami", "Filtered cron dates receive Czech minute translations in source and release assets");
  check(cron.T.dates.relative(new Date(now - 3 * 3600000), now) === "p\u0159ed 3 hodinami", "Filtered cron dates receive Czech hour translations in source and release assets");
}
const minifiedPublic = load("/", "cs", "min");
const rawPublic = load("/", "cs", true);
check(minifiedPublic.assets[0].url !== rawPublic.assets[0].url && minifiedPublic.assets[3].url !== rawPublic.assets[3].url && minifiedPublic.styles[0] !== rawPublic.styles[0], "Minification selects distinct runtime, application and CSS variants");
load("/admin", "en", "min");
const cs = load("/", "cs");
for (const route of ["/", "/admin/users", "/admin/social/bots/accounts", "/admin/social/bots/detail", "/admin/social/moderation/reports", "/admin/cron", "/admin/updates", "/admin", "/install"]) {
  const compiled = load(route, "cs", true);
  check(compiled.assets.every(item => item.url.startsWith("/assets/build/")), "Release assets use the signed precompiled inventory: " + route);
  check(compiled.T.i18n.has("common.close"), "Compiled catalogs initialize before modules: " + route);
}
const asset = (page, name) => page.assets.find(item => item.url.includes("/client-" + name + ".") || item.url.includes("/client-" + name + "-")).url;
check(asset(publicPage, "components") === asset(cs, "components"), "Component content hashes are independent of language");
check(asset(publicPage, "i18n") !== asset(cs, "i18n"), "Translation URLs vary with language content");
check(asset(publicPage, "components") === asset(load("/"), "components"), "Asset hashes are deterministic");
const i18n = publicPage.T.i18n;
check(i18n.format("{a} {b} {count} {counter}", { a: "{b}", b: "$&\\", count: 2, counter: 3 }) === "{b} $&\\ 2 3", "Interpolation is single-pass, longest-token first, with literal replacement values");
check(i18n.format("{yes}/{no}/{nil}", { yes: true, no: false, nil: null }) === "1//", "Scalar interpolation agrees with PHP");
check(i18n.t("missing.translation") === "missing.translation", "Unknown translations retain their key");
i18n.register("test", { "test.label": "Test" });
check(!i18n.has("common.close") && i18n.t("test.label") === "Test", "Changing locales clears stale messages");
console.log("PASS scoped external client assets and translation runtime (" + checks + " checks)");

(async function () {
  for (const mode of [false, true, "min"]) {
    for (const route of ["/", "/tag/games", "/author/8"]) {
      const page = load(route, "cs", mode), T = page.T;
      check(!T.publishing.controllers.editor && !T.accounts.avatarUpload, "Reading a public page excludes editor implementations: " + route);
      if (mode) check(T.deferredAssets.script.includes("-min.") === (mode === "min") && T.deferredAssets.style.includes("-min.") === (mode === "min"), "Deferred compiled assets respect JS and CSS minification settings");
      let requests = 0, failOnce = route.startsWith("/author");
      page.document.createElement = tag => ({ tagName: tag, remove() {} });
      page.document.head = { appendChild(node) {
        requests++;
        Promise.resolve().then(() => {
          if (node.src && failOnce) { failOnce = false; node.onerror(); return; }
          if (node.src) vm.runInContext(page.deferred_assets[new URL(node.src).pathname], page.context);
          node.onload();
        });
      } };
      await T.deferred.resource(new URL("https://tinycat.test/api/users-other/8/avatar"));
      check(requests === 0, "Resource prefixes match complete path segments");
      const resource = new URL("https://tinycat.test" + (route.startsWith("/tag") ? "/api/social/moderation/report-options" : route.startsWith("/author") ? "/api/users/8/avatar" : "/api/social/statuses/8"));
      if (failOnce) {
        await assert.rejects(T.deferred.resource(resource));
        check(!T.accounts.avatarUpload, "A failed script load does not initialize editor modules");
      }
      await Promise.all([T.deferred.resource(resource), T.deferred.resource(resource)]);
      check(T.publishing.controllers.editor && T.accounts.avatarUpload, "Editor implementations become available on demand: " + route);
      check(T.components.render && T.i18n.t("account.avatar_edit") !== "account.avatar_edit", "Deferred editors retain their locale and component runtime");
      const previous = requests;
      await T.deferred.resource(new URL("https://tinycat.test/api/users/8/avatar"));
      check(requests === previous && requests === (route.startsWith("/author") ? 3 : 2), "Concurrent editor opens share assets and failed scripts can retry without reloading CSS");
    }
  }
  console.log("PASS deferred editor loading in source, compiled and minified public bundles");
})().catch(error => { console.error(error); process.exitCode = 1; });
