"use strict";
const assert = require("node:assert/strict");
const path = require("node:path");
const vm = require("node:vm");
const { execFileSync } = require("node:child_process");
const fixture = path.resolve(__dirname, "../presentation/client-assets-fixture.php");
let checks = 0;
function check(value, message) { checks++; assert.ok(value, message); }
function load(route, locale = "en", compiled = false) {
  const bundle = JSON.parse(execFileSync(process.env.TINYCAT_TEST_PHP || "php", [fixture, route, locale, compiled === "min" ? "--compiled-min" : compiled ? "--compiled" : ""], { encoding: "utf8", maxBuffer: 8 * 1024 * 1024 }));
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
check(publicPage.T.status && publicPage.T.users && !publicPage.T.bots && !publicPage.T.moderation, "Public pages load their own feature scripts");
check(!publicPage.definitions.includes("users/admin-fields") && !publicPage.definitions.includes("bots/sources"), "Public bundles exclude administration components");
const users = load("/admin/users");
check(users.T.users && !users.T.status && !users.T.bots, "User administration boots independently");
check(users.definitions.includes("users/admin-fields"), "User administration receives its form definitions");
const bots = load("/admin/bots/accounts");
check(bots.T.bots && bots.T.users && !bots.T.status, "Bots load account menu support without the status module");
const botDetail = load("/admin/bots/detail");
check(botDetail.T.bots && botDetail.T.status && botDetail.definitions.includes("bots/posts") && botDetail.definitions.includes("status/composer-body"), "Only bot detail loads the post list and shared post editor");
check(bots.definitions.includes("ui/avatar"), "Component dependencies cross module boundaries without loading unrelated scripts");
check(load("/admin/moderation/reports").T.moderation, "Moderation loads its module");
check(load("/admin/cron").T.scheduler, "Scheduler loads its module");
check(load("/admin/settings").T.users, "Settings include the shared account menu and profile dialogs");
check(!load("/install").T.status, "Installer boots without public modules");
check(!users.sources.some(s => s.endsWith("/following.js")), "Administration omits public following controller");
check(!load("/install").sources.some(s => s.includes("/modules/status/") || s.includes("/ui/ajax.js")) && load("/install").sources.some(s => s.includes("/ui/forms.js") && !s.includes("form-state")), "Installer uses shared API form transport without feed and administration controllers");
const schedulerPage = load("/admin/cron/list");
check(schedulerPage.T.scheduler && schedulerPage.T.dates && schedulerPage.definitions.includes("scheduler/filter-form"), "The scheduler page independently loads date formatting and shared filter components.");
check(load("/admin/cron/list", "cs").T.i18n.t("common.filters") === "Filtry", "The cron filter modal receives its Czech title in the independently scoped client catalog.");
for (const mode of [false, true, "min"]) {
  const cron = load("/admin/cron/list", "cs", mode);
  const now = Date.UTC(2026, 9, 27, 12, 0, 0);
  check(cron.T.dates.relative(new Date(now - 2 * 60000), now) === "p\u0159ed 2 minutami", "Filtered cron dates receive Czech minute translations in source and release assets");
  check(cron.T.dates.relative(new Date(now - 3 * 3600000), now) === "p\u0159ed 3 hodinami", "Filtered cron dates receive Czech hour translations in source and release assets");
}
const minifiedPublic = load("/", "cs", "min");
const rawPublic = load("/", "cs", true);
check(minifiedPublic.assets[0].url !== rawPublic.assets[0].url && minifiedPublic.assets[3].url !== rawPublic.assets[3].url && minifiedPublic.styles[0] !== rawPublic.styles[0], "Minification selects distinct runtime, application and CSS variants");
load("/admin/settings", "en", "min");
const cs = load("/", "cs");
for (const route of ["/", "/admin/users", "/admin/bots/accounts", "/admin/bots/detail", "/admin/moderation/reports", "/admin/cron", "/admin/settings", "/install"]) {
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
