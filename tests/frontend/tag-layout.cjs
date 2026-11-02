"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), path = require("node:path"), http = require("node:http");
const {execFileSync} = require("node:child_process");
const root = path.resolve(__dirname, "../..");
const modulePath = process.env.TINYCAT_TEST_PLAYWRIGHT_MODULE || path.join(root, "storage/ui-webkit-tools/node_modules/playwright");
if (!fs.existsSync(modulePath)) { console.log("SKIP native tag layout: set TINYCAT_TEST_PLAYWRIGHT_MODULE"); process.exit(0); }
const {webkit} = require(modulePath);
function read(file, ...args) { return JSON.parse(execFileSync(process.env.TINYCAT_TEST_PHP || "php", [path.join(root, file), ...args], {encoding:"utf8", maxBuffer:8e6})); }
const styles = read("tests/presentation/asset-graph-fixture.php", "--styles");
const templates = read("tests/presentation/component-parity.php", "--templates");
const fixtures = read("tests/presentation/tag-layout-fixture.php");
const scripts = ["core/urls.js", "core/dom.js", "core/i18n.js", "core/icons.js", "core/component-renderer.js", "modules/status/translation.js"].map(file => fs.readFileSync(path.join(root, "assets", file), "utf8").replace(/^import .*;\r?\n/gm, "")).join("\n");
(async function () {
  const server = http.createServer((request, response) => {
    response.setHeader("Content-Type", "text/html; charset=utf-8");
    response.end('<!doctype html><html lang="cs"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + styles + '</style></head><body><main style="max-width:760px;margin:12px"></main><script>window.TinyCat={status:{controllers:{}}};' + scripts + '</script></body></html>');
  });
  await new Promise(resolve => server.listen(0, "127.0.0.1", resolve));
  let browser, checks = 0;
  try {
    browser = await webkit.launch({headless:true});
    for (const width of [320, 390, 1024]) {
      for (const theme of ["light", "dark"]) {
        const page = await browser.newPage({viewport:{width, height:812}});
        await page.goto("http://127.0.0.1:" + server.address().port);
        await page.evaluate(({templates, fixtures, theme}) => {
          document.documentElement.dataset.theme = theme;
          const T = TinyCat;
          T.components.register(templates); T.i18n.register("cs", {"account.translation_translate":"Přeložit"});
          window.translationInputs = [];
          window.LanguageDetector = {availability:async () => "available", create:async () => ({detect:async () => [{detectedLanguage:"en", confidence:1}]})};
          window.Translator = {availability:async () => "available", create:async () => ({translate:async text => { translationInputs.push(text); return "Přeložený text"; }, destroy() {}})};
          for (const [index, fixture] of fixtures.entries()) {
            for (const mode of ["server", "client"]) {
              const section = document.createElement("section"); section.id = mode + index;
              if (mode === "server") section.innerHTML = fixture.html;
              else section.append(T.components.render("status/body", {item:fixture.item}));
              document.querySelector("main").append(section);
            }
          }
          T.status.controllers.translation.hydrate(document.querySelector("main"));
        }, {templates, fixtures, theme});
        await page.waitForFunction(() => !document.querySelector("#server0 .status-translation").hidden);
        for (const mode of ["server", "client"]) {
          const section = page.locator("#" + mode + "0"), summary = section.locator("summary");
          assert.equal(await section.locator(".tag-link:visible").count(), 3); checks++;
          assert.equal(await summary.textContent(), "# +99"); checks++;
          const layout = await section.evaluate(node => {
            const tags = node.querySelector(".status-tags"), summary = tags.querySelector("summary"), first = tags.querySelector("a");
            return {marker:getComputedStyle(summary).listStyleType, display:getComputedStyle(summary).display, sameRow:Math.abs(first.getBoundingClientRect().top - summary.getBoundingClientRect().top) < 2,
              controlsBeforeTags:node.querySelector(".status-body").nextElementSibling.classList.contains("status-translation") && node.querySelector(".status-translation").nextElementSibling === tags,
              noOverflow:node.scrollWidth <= node.clientWidth, noTranslate:tags.getAttribute("translate")};
          });
          assert.equal(layout.marker, "none"); assert.equal(layout.display, "inline-flex"); checks += 2;
          assert.ok(layout.controlsBeforeTags && layout.noOverflow); assert.equal(layout.noTranslate, "no"); checks += 2;
          if (width === 1024) { assert.ok(layout.sameRow, "Overflow badge shares the tag baseline on desktop"); checks++; }
          await summary.focus(); await page.keyboard.press("Enter");
          assert.equal(await section.locator(".tag-link:visible").count(), 102); checks++;
          await section.locator(".status-translation-link").click();
          assert.equal(await section.locator(".status-translation-output").textContent(), "Přeložený text"); checks++;
          await summary.focus(); await page.keyboard.press("Enter");
          assert.equal(await section.locator(".tag-link:visible").count(), 3); checks++;
          assert.equal(await page.locator("#" + mode + "1 .status-translation").count(), 0, "Tag-only posts do not offer translation"); checks++;
          await page.locator("#" + mode + "2 .status-translation-link").click();
          assert.equal(await page.locator("#" + mode + "2 .tag-link").getAttribute("translate"), "no"); checks++;
        }
        const inputs = await page.evaluate(() => translationInputs);
        assert.equal(inputs.length, 4); assert.ok(inputs.every(text => !text.includes("#"))); checks += 2;
        assert.ok(inputs.includes("English headline\n\nEnglish paragraph."), "Paragraph boundaries survive translation"); checks++;
        await page.close();
      }
    }
    console.log("PASS native tag badges, keyboard disclosure, PHP/client rendering, mobile overflow and prose-only translation (" + checks + " checks)");
  } finally { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); }
}()).catch(error => { console.error(error); process.exitCode = 1; });
