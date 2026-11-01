"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const dataset = { interfacePrefix: "pl", interfaceLocales: "cs,en,de,es,fr,pt,ru,it,nl,pl,tr,uk" };
const document = { documentElement: { dataset }, querySelector: () => null };
const window = { location: new URL("https://tinycat.test/pl/"), TinyCat: {} };
const source = name => fs.readFileSync(path.resolve(__dirname, "../../assets/core/" + name + ".js"), "utf8").replace(/^import .*;\r?\n/gm, "");
let lastHeaders;
const context = vm.createContext({ window, document, URL, Headers, FormData, Blob, URLSearchParams, fetch: async (_url, settings) => {
  lastHeaders = settings.headers;
  return { ok: true, status: 200, headers: new Headers({ "Content-Type": "application/json" }), json: async () => ({ ok: true, status: 200, code: "ok", params: {}, data: {}, message: null, meta: {} }) };
} });
vm.runInContext(source("urls"), context);
vm.runInContext(source("i18n"), context);
vm.runInContext(source("api-client"), context);
const urls = window.TinyCat.urls;
let checks = 0;
const cases = [
  ["/", "/pl/"], ["/author/2", "/pl/author/2"], ["/status/10?compact=1#comments", "/pl/status/10?compact=1#comments"],
  ["/tag/a%20b", "/pl/tag/a%20b"], ["/login?next=%2Fpl%2F", "/pl/login?next=%2Fpl%2F"], ["/privacy", "/pl/privacy"],
  ["/de/status/10", "/de/status/10"], ["/api/statuses/10", "/api/statuses/10"], ["/admin", "/admin"],
  ["/author/2/feed", "/author/2/feed"], ["/assets/icons.svg", "/assets/icons.svg"], ["/uploads/image.webp", "/uploads/image.webp"],
  ["/zz/privacy", "/zz/privacy"], ["//other.test/", "//other.test/"], ["https://other.test/privacy", "https://other.test/privacy"], ["#content", "#content"]
];
const serverUrls = JSON.parse(require("node:child_process").execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.resolve(__dirname, "../presentation/asset-graph-fixture.php"), "--localized-urls", JSON.stringify(cases.map(item => item[0]))], { encoding: "utf8" }));
cases.forEach(([input, expected], index) => { assert.equal(urls.local(input), expected); assert.equal(serverUrls[index], expected, "PHP and JS URL policies agree: " + input); checks += 2; });
assert.equal(urls.local("/pl/status/10?compact=1", "en"), "/en/status/10?compact=1"); checks++;
assert.equal(urls.split("/pl/status/10").path, "/status/10"); checks++;
async function run() {
  await window.TinyCat.api.request("/api/site/sidebar");
  assert.equal(lastHeaders.get("X-TinyCat-Locale"), "pl"); checks++;
  dataset.interfacePrefix = "";
  assert.equal(urls.local("/status/10"), "/status/10"); checks++;
  await window.TinyCat.api.request("/api/site/sidebar");
  assert.equal(lastHeaders.has("X-TinyCat-Locale"), false); checks++;
  console.log("PASS localized URLs, global service URLs and API interface propagation (" + checks + " checks)");
}
run().catch(error => { console.error(error); process.exitCode = 1; });
