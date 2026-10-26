"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.resolve(__dirname, "../../assets/modules/status/translation.js"), "utf8").replace(/^import .*;\r?\n/gm, "");
function node(value = "") {
  return {
    dataset: {}, hidden: false, textContent: value, innerText: value, children: [], attributes: {}, handlers: {},
    append(...children) { this.children.push(...children); },
    setAttribute(key, value) { this.attributes[key] = value; },
    removeAttribute() { delete this.dataset.translationReady; },
    addEventListener(type, fn) { this.handlers[type] = fn; },
    closest() { return null; },
    cloneNode() { return { textContent: value, querySelectorAll() { return []; } }; },
    after(controls) { this.controls = controls; },
    remove() { this.removed = true; }
  };
}
async function flush() { for (let i = 0; i < 12; i++) { await Promise.resolve(); } }
async function run() {
  let translations = 0, destroyed = 0, fail = false;
  const bodies = ["12345", "!!!", "https://example.com", "#testing @user", "Czech post", "English post", "uncertain"].map(node);
  const detector = { detect: async value => [{ detectedLanguage: value === "Czech post" ? "cs" : "en", confidence: value === "uncertain" ? 0.1 : 0.99 }] };
  const T = { i18n: { t: key => key, locale: () => "cs" }, dom: { all: () => bodies }, icons: { create: name => ({ name }) }, status: { controllers: {} } };
  const window = { TinyCat: T, isSecureContext: false,
    LanguageDetector: { availability: async () => "available", create: async () => detector },
    Translator: { availability: async () => "available", create: async () => ({
      translate: async () => { translations++; if (fail) { throw new Error("Unavailable"); } return "<b>Translated text</b>"; },
      destroy() { destroyed++; }
    }) }
  };
  vm.runInNewContext(source, { window, document: { createElement: () => node(), addEventListener() {}, documentElement: { lang: "en" } }, navigator: { language: "en", userActivation: { isActive: true } } });
  T.status.controllers.translation.hydrate({});
  assert.ok(bodies.every(body => !body.controls), "HTTP does not offer translation");
  window.isSecureContext = true;
  T.status.controllers.translation.hydrate({});
  await flush();
  assert.ok(bodies.slice(0, 4).every(body => !body.controls), "Nonlinguistic posts do not offer translation");
  assert.equal(bodies[4].controls.hidden, true, "Matching interface language is hidden");
  assert.equal(bodies[6].controls.hidden, true, "Uncertain language is hidden");
  const body = bodies[5], controls = body.controls, [button, state, output] = controls.children;
  assert.equal(controls.hidden, false);
  assert.equal(output.lang, "cs", "Interface locale wins over browser and document fallbacks");
  assert.ok(button.className.includes("badge"));
  assert.equal(button.children[0].name, "translate");
  await button.handlers.click();
  assert.equal(body.hidden, false, "Original remains visible");
  assert.equal(output.hidden, false);
  assert.equal(output.textContent, "<b>Translated text</b>", "Translation is assigned as text");
  assert.equal(destroyed, 1);
  await button.handlers.click();
  assert.equal(output.hidden, true);
  assert.equal(body.hidden, false);
  await button.handlers.click();
  assert.equal(translations, 1, "Reopening reuses the in-memory translation");
  assert.equal(button.children[0].name, "translate", "Toggling preserves the icon");
  const retry = node("Another English post");
  bodies.push(retry); T.status.controllers.translation.hydrate({}); await flush();
  fail = true;
  await retry.controls.children[0].handlers.click();
  assert.equal(retry.controls.children[0].disabled, false, "Failure allows retry");
  assert.equal(retry.hidden, false);
  assert.equal(retry.controls.children[1].textContent, "account.translation_failed");
  console.log("PASS local translation eligibility, target language, original preservation, badge, toggle and failure recovery (19 checks)");
}
run().catch(error => { console.error(error); process.exitCode = 1; });
