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
  const context = vm.createContext({ window, document, URL, URLSearchParams, console, Set, Map });
  for (const asset of bundle.assets) vm.runInContext(asset.source, context, { filename: asset.url });
  window.TinyCat.init();
  check(window.TinyCat.modules.navigationUrl({ dataset: {}, getAttribute() { return null; }, closest() { return null; } }) === "", "Non-status navigation does not require the status module: " + route);
  check(window.TinyCat.i18n.locale() === locale, route + " boots the requested locale with external assets in delivery order");
  return { ...bundle, T: window.TinyCat };
}
const publicPage = load("/");
for (const mode of [false, true, "min"]) {
  for (const route of ["/login", "/register", "/recovery", "/logout", "/privacy", "/search/all", "/notifications"]) {
    const page = load(route, "en", mode);
    check(page.T.accounts && !page.T.status && !page.T.socialUsers && !page.T.site, "Core public pages exclude the Social feed runtime in every delivery variant: " + route);
    check(!page.T.filters && !page.T.adminDefinitions && !page.T.settings, "Public pages exclude administrative controllers: " + route);
    check(!page.definitions.includes("status/card") && !page.definitions.includes("bots/sources") && !page.definitions.includes("users/admin-fields"), "Core public catalogs exclude domain and admin views: " + route);
    check(!Object.keys(page.catalog).some(key => key.startsWith("bots.") || key.startsWith("settings.fields.") || key.startsWith("settings.sections.") || key === "settings.title"), "Core public catalogs exclude administrative editor translations: " + route);
  }
  const queue = load("/admin/bots/queue", "en", mode);
  check(["status/edit-fields", "status/dialog-form"].every(name => queue.definitions.includes(name)), "Bot queue delivers its complete status editor dependency in every asset variant");
  const profile = load("/author/2", "cs", mode);
  const followForm = { dataset: {}, getAttribute: name => name === "action" ? "/api/profiles/2/followers/me" : null };
  const response = { data: { author_id: 2, following: true, followers_count: 1, following_count: 0 } };
  check(profile.T.accounts.result(followForm, response, null) === false, "Core accounts leave relationship responses to their owner in every public asset variant");
  let handled = false;
  profile.T.socialUsers.result = (source, result) => { handled = source === followForm && result === response; return []; };
  profile.T.modules.result(followForm, response, null);
  check(handled, "The actual public module chain delivers follow results to Social");
}
check(publicPage.T.accounts !== publicPage.T.socialUsers && typeof publicPage.T.accounts.follow === "undefined" && typeof publicPage.T.socialUsers.profiles === "function", "Social relationships never mutate the core account service");
check(typeof publicPage.T.accounts.list === "undefined" && publicPage.T.accounts.avatarUpload, "Public account controls keep avatar editing without the administrative list adapter");
const login = load("/login");
check(typeof login.T.accounts.list === "undefined" && login.T.accounts.modal, "Login keeps resource authentication dialogs without user-list code");
check(publicPage.T.status && publicPage.T.socialUsers && !publicPage.T.bots && !publicPage.T.moderation, "Public pages load their own feature scripts");
check(!publicPage.T.filters, "The public feed does not load administrative list filters");
check(!publicPage.definitions.includes("users/admin-fields") && !publicPage.definitions.includes("bots/sources"), "Public bundles exclude administration components");
const users = load("/admin/users");
check(users.T.adminDefinitions["core.users"].component === "users/list", "User administration loads its declared list");
check(users.T.accounts && !users.T.status && !users.T.bots, "User administration boots independently");
check(users.definitions.includes("users/admin-fields"), "User administration receives its form definitions");
const bots = load("/admin/bots/accounts");
check(bots.T.bots && bots.T.accounts && bots.T.status, "Bot administration includes account controls and the shared post editor");
const botDetail = load("/admin/bots/detail");
check(botDetail.T.bots && botDetail.T.status && botDetail.definitions.includes("bots/posts") && botDetail.definitions.includes("status/composer-body"), "Only bot detail loads the post list and shared post editor");
check(bots.definitions.includes("ui/avatar"), "Component dependencies cross module boundaries without loading unrelated scripts");
check(bots.definitions.includes("status/composer-body") && !bots.definitions.includes("status/card") && !bots.definitions.includes("notifications/items"), "Bot administration includes the shared post editor without public feed cards or notification lists.");
check(load("/admin/moderation/reports").T.moderation, "Moderation loads its module");
check(load("/admin/cron").T.scheduler, "Scheduler loads its module");
check(load("/admin").T.settings && load("/admin").T.accounts, "Every admin page includes settings and shared profile dialogs");
check(load("/admin/updates").sources.includes("App/Core/Updates/assets/index.js") && !load("/admin").sources.includes("App/Core/Updates/assets/index.js"), "The release-notes renderer is scoped to the updates page.");
check(!load("/install").T.status, "Installer boots without public modules");
check(!load("/install").definitions.includes("ui/modal") && load("/admin").definitions.includes("ui/modal"), "Installer excludes unused dialog catalogs while administration retains them.");
check(!users.sources.some(s => s.endsWith("/following.js")), "Administration omits public following controller");
check(!load("/install").sources.some(s => s.includes("/Modules/Social/") || s === "App/Core/Ui/assets/ajax-bindings.js") && load("/install").sources.includes("App/Core/Ui/assets/form-requests.js"), "Installer uses shared API form transport without feed and administration controllers");
const schedulerPage = load("/admin/cron/list");
check(schedulerPage.T.scheduler && schedulerPage.T.dates && schedulerPage.definitions.includes("scheduler/filter-form"), "The scheduler page independently loads date formatting and shared filter components.");
check(load("/admin/cron/list", "cs").T.i18n.t("common.filters") === "Filtry", "The cron filter modal receives its Czech title in the independently scoped client catalog.");
for (const mode of [false, true, "min"]) {
  const health = load("/admin/site-health", "cs", mode);
  check(health.T.scheduler && health.T.settings && health.T.adminNavigation && !health.T.adminDefinitions, "Health boots shared navigation and settings with run details but without a scheduler list declaration.");
  check(health.T.scheduler.result({dataset:{},getAttribute:name=>name==="action"?"/api/site-health/checks":null},{data:{}},null) === false, "The detail-only scheduler leaves health actions to the shared AJAX transport.");

  const cron = load("/admin/cron/list", "cs", mode);
  const now = Date.UTC(2026, 9, 27, 12, 0, 0);
  check(cron.T.dates.relative(new Date(now - 2 * 60000), now) === "p\u0159ed 2 minutami", "Filtered cron dates receive Czech minute translations in source and release assets");
  check(cron.T.dates.relative(new Date(now - 3 * 3600000), now) === "p\u0159ed 3 hodinami", "Filtered cron dates receive Czech hour translations in source and release assets");
}
const minifiedPublic = load("/", "cs", "min");
const rawPublic = load("/", "cs", true);
check(minifiedPublic.assets[0].url !== rawPublic.assets[0].url && minifiedPublic.assets[3].url !== rawPublic.assets[3].url && minifiedPublic.styles[0] !== rawPublic.styles[0], "Minification selects distinct runtime, application and CSS variants");
load("/admin", "en", "min");
const cs = load("/", "cs");
for (const route of ["/", "/admin/users", "/admin/bots/accounts", "/admin/bots/detail", "/admin/moderation/reports", "/admin/cron", "/admin/updates", "/admin", "/install"]) {
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
