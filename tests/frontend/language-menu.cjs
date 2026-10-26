"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.resolve(__dirname, "../../assets/modules/site/language.js"), "utf8").replace(/^import .*;\r?\n/gm, "");
function setup(storage, preferred = "cs") {
  let service, focused = false;
  const handlers = {}, close = {}, inner = {};
  const menu = { open: true, contains: target => target === inner, querySelector: () => ({ focus: () => { focused = true; } }) };
  const banner = { dataset: { languageSuggestion: preferred }, hidden: false, querySelector: () => ({ addEventListener: (event, fn) => { close[event] = fn; } }) };
  const document = { documentElement: { lang: "en" }, activeElement: inner, querySelector: () => banner,
    querySelectorAll: () => menu.open ? [menu] : [], addEventListener: (event, fn) => { handlers[event] = fn; } };
  const window = { sessionStorage: storage, TinyCat: { lifecycle: { register: (_name, value) => { service = value; } } } };
  vm.runInNewContext(source, { window, document }); service.init();
  return { handlers, close, menu, banner, focused: () => focused };
}
const values = new Map();
const storage = { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) };
let state = setup(storage);
assert.equal(state.banner.hidden, false);
state.close.click(); assert.equal(state.banner.hidden, true);
assert.equal(values.get("tinycat-language-hint:en:cs"), "dismissed");
assert.equal(setup(storage).banner.hidden, true, "Dismissal persists for this language pair during the visit");
assert.equal(setup(storage, "de").banner.hidden, false, "A different browser-language pair remains independent");
state = setup({ getItem() { throw new Error("Blocked"); }, setItem() { throw new Error("Blocked"); } });
state.close.click(); assert.equal(state.banner.hidden, true, "Blocked storage cannot prevent dismissing the offer");
state.handlers.click({ target: {} }); assert.equal(state.menu.open, false, "Outside clicks close the menu");
state = setup(storage); state.handlers.click({ target: { closest: () => ({}) } });
assert.equal(state.menu.open, false, "Choosing a language closes the menu");
state = setup(storage); state.handlers.keydown({ key: "Escape" });
assert.equal(state.menu.open, false); assert.equal(state.focused(), true, "Escape restores keyboard focus to the summary");
console.log("PASS language suggestion dismissal, storage failures and navbar keyboard behavior (10 checks)");
