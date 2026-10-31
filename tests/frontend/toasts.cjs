"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
let stack, nextTimer = 0;
const timers = new Map(), viewportEvents = {}, documentEvents = {};
function element() {
  return {
    style: {}, children: [], events: {}, dataset: {},
    setAttribute() {},
    appendChild(child) { this.children.push(child); child.parent = this; },
    addEventListener(name, handler) { this.events[name] = handler; },
    remove() { this.removed = true; }
  };
}
const document = {
  body: { appendChild(node) { stack = node; } }, createElement: element,
  addEventListener(name, handler) { documentEvents[name] = handler; }
};
const viewport = {width: 375, height: 812, offsetTop: 0, offsetLeft: 0,
  addEventListener(name, handler) { viewportEvents[name] = handler; }};
const T = {
  dom: {find: () => stack, all: () => []}, i18n: {label: () => "Close"},
  icons: {create: element}, lifecycle: {register(_name, hooks) { this.hooks = hooks; }}
};
const window = {TinyCat: T, visualViewport: viewport, innerWidth: 375, innerHeight: 812,
  addEventListener() {},
  setTimeout(handler, delay) { timers.set(++nextTimer, {handler, delay}); return nextTimer; },
  clearTimeout(id) { timers.delete(id); }
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../../assets/ui/toasts.js"), "utf8").replace(/^import .*;\r?\n/gm, ""), {window, document});
T.lifecycle.hooks.init();
const toast = T.toasts.show("Saved", "success");
assert.equal(timers.size, 1);
assert.equal([...timers.values()][0].delay, 4200);
// Opening an editor and its keyboard must move an existing toast into the visible area.
viewport.height = 460;
documentEvents["tinycat:modal-open"]();
assert.equal(stack.style.transform, "translate(0px, -352px)");
assert.equal(toast.removed, undefined);
assert.equal(timers.size, 1);
viewport.offsetTop = 60;
viewportEvents.scroll();
assert.equal(stack.style.transform, "translate(0px, -292px)");
// Touch-generated hover events must not shorten the reading time or create extra timers.
toast.events.pointerenter({pointerType: "touch"});
toast.events.pointerleave({pointerType: "touch"});
assert.equal(timers.size, 1);
assert.equal([...timers.values()][0].delay, 4200);
toast.events.pointerenter({pointerType: "mouse"});
assert.equal(timers.size, 0);
toast.events.pointerleave({pointerType: "mouse"});
toast.events.pointerleave({pointerType: "mouse"});
assert.equal(timers.size, 1);
assert.equal([...timers.values()][0].delay, 1800);
viewport.height = 812; viewport.offsetTop = 0;
documentEvents["tinycat:modal-close"]();
assert.equal(stack.style.transform, "translate(0px, 0px)");
viewport.width = 300; viewport.offsetLeft = 20;
viewportEvents.resize();
assert.equal(stack.style.transform, "translate(-55px, 0px)");
assert.equal(stack.style.maxWidth, "280px");
toast.children[2].events.click();
assert.equal(toast.removed, true);
assert.equal(timers.size, 0);
window.visualViewport = null;
const fallback = T.toasts.show("Error", "danger");
assert.equal(stack.style.transform, "");
assert.equal(stack.style.maxWidth, "");
assert.equal([...timers.values()][0].delay, 5600);
[...timers.values()][0].handler();
assert.equal(fallback.removed, true);
console.log("PASS toast mobile viewport, modal opening, touch duration, mouse pause and dismissal");
