"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
(async () => {
  const handlers = {}, buttons = ["system", "light", "dark"].map(theme => ({
    tagName: "BUTTON", dataset: { userMenuTheme: theme }, disabled: false, pressed: null,
    hasAttribute: () => true, setAttribute(name, value) { if (name === "aria-pressed") this.pressed = value; },
    closest: () => group
  }));
  const group = {}, menu = { open: true, matches: selector => selector === ".user-menu" };
  let currentTheme = null, finishRequest, requestSettings, notice;
  const document = {
    addEventListener(type, callback) { handlers[type] = callback; },
    documentElement: { getAttribute: () => currentTheme, setAttribute(name, value) { currentTheme = value; }, removeAttribute() { currentTheme = null; } }
  };
  const TinyCat = {
    dom: { all: () => buttons }, lifecycle: { register(name, controller) { controller.init(); } },
    api: { request(url, settings) { requestSettings = { url, ...settings }; return new Promise((resolve, reject) => { finishRequest = { resolve, reject }; }); } },
    toasts: { show(message) { notice = message; } }
  };
  const source = fs.readFileSync(path.join(__dirname, "../../assets/modules/users/user-menu.js"), "utf8").replace(/^import .+;\r?\n/gm, "");
  vm.runInNewContext(source, { window: { TinyCat }, document });
  const click = button => handlers.click({ target: { closest: () => button } });
  const changing = click(buttons[2]);
  assert.equal(currentTheme, "dark"); assert.ok(buttons.every(button => button.disabled));
  assert.equal(requestSettings.url, "/api/users/me"); assert.equal(requestSettings.method, "PATCH"); assert.equal(requestSettings.body.theme, "dark");
  finishRequest.resolve({}); await changing;
  assert.equal(buttons[2].pressed, "true"); assert.ok(buttons.every(button => !button.disabled));
  const failing = click(buttons[1]); finishRequest.reject(new Error("Save failed")); await failing;
  assert.equal(currentTheme, "dark"); assert.equal(buttons[2].pressed, "true"); assert.equal(notice, "Save failed");
  currentTheme = "light"; handlers.toggle({ target: menu });
  assert.equal(buttons[1].pressed, "true"); assert.equal(buttons[2].pressed, "false");
  console.log("PASS account menu theme save, failure rollback and settings synchronization");
})().catch(error => { console.error(error); process.exitCode = 1; });
