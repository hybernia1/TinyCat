"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.resolve(__dirname, "../../assets/modules/site/language.js"), "utf8").replace(/^import .*;\r?\n/gm, "");
const menusSource = fs.readFileSync(path.resolve(__dirname, "../../assets/ui/menus.js"), "utf8").replace(/^import .*;\r?\n/gm, "");
function setup(storage, preferred = "cs") {
  let focused = false;
  const services = [], callbacks = {}, close = {};
  const menu = { open: true, querySelector: selector => selector === "summary" ? { focus: () => { focused = true; } } : null };
  const inner = { closest: selector => selector === "[data-dismissible-menu]" || selector === "[data-dismissible-menu][open]" ? menu : null };
  const action = { closest: selector => selector === "[data-dismissible-menu]" ? menu : selector === "[data-dismissible-menu-action]" ? action : null };
  const banner = { dataset: { languageSuggestion: preferred }, hidden: false, querySelector: () => ({ addEventListener: (event, fn) => { close[event] = fn; } }) };
  const document = { documentElement: { lang: "en" }, activeElement: inner,
    querySelector: selector => selector === "[data-language-suggestion]" ? banner : menu.open ? menu : null,
    querySelectorAll: selector => selector === "[data-dismissible-menu][open]" && menu.open ? [menu] : [],
    addEventListener: (event, fn) => { (callbacks[event] ||= []).push(fn); } };
  const window = { addEventListener() {}, sessionStorage: storage, TinyCat: {
    dom: { find: (selector, root) => (root || document).querySelector(selector), all: (selector, root) => Array.from((root || document).querySelectorAll(selector)) },
    lifecycle: { register: (_name, value) => { services.push(value); } }
  } };
  const context = vm.createContext({ window, document });
  vm.runInContext(menusSource, context); vm.runInContext(source, context);
  services.forEach(service => service.init());
  const handlers = Object.fromEntries(Object.keys(callbacks).map(name => [name, event => {
    event.preventDefault ||= () => {}; event.stopImmediatePropagation ||= () => {};
    callbacks[name].forEach(fn => fn(event));
  }]));
  return { handlers, close, menu, banner, inner, action, focused: () => focused };
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
state = setup(storage); state.handlers.click({ target: state.action });
assert.equal(state.menu.open, false, "Choosing a language closes the menu");
state = setup(storage); state.handlers.click({ target: state.inner });
assert.equal(state.menu.open, true, "Clicks inside the menu retain it");
state.handlers.keydown({ key: "Escape" });
assert.equal(state.menu.open, false); assert.equal(state.focused(), true, "Escape restores keyboard focus to the summary");
console.log("PASS language suggestion dismissal, storage failures and navbar keyboard behavior (11 checks; shared dismissible-menu controller)");
