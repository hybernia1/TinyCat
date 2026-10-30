"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const path = require("node:path");
let checks = 0;
function check(value, label) { checks++; assert.ok(value, label); }
const listeners = {};
const source = {};
const handshakes = [];
const frame = { src: "https://tinycat.test/status/1/embed", height: "600", contentWindow: { postMessage(message, origin) { handshakes.push({ message, origin }); } }, addEventListener() {} };
const document = { baseURI: "https://blog.test/", readyState: "complete", querySelectorAll() { return [frame]; } };
const window = { addEventListener(type, handler) { listeners[type] = handler; } };
const context = vm.createContext({ window, document, URL, Number, Math });
const parentScript = fs.readFileSync(path.resolve(__dirname, "../../assets/embed.js"), "utf8");
vm.runInContext(parentScript, context);
check(handshakes.length === 1 && handshakes[0].origin === "https://tinycat.test", "An async helper requests the initial height from the expected origin.");
function resize(origin, eventSource, height) { listeners.message({ origin, source: eventSource, data: { type: "tinycat:embed:resize", height } }); }
resize("https://evil.test", frame.contentWindow, 200);
check(frame.height === "600", "A forged origin cannot resize an iframe.");
resize("https://tinycat.test", source, 200);
check(frame.height === "600", "A different window at the same origin cannot resize an iframe.");
for (const height of [NaN, Infinity, -1, 0, 1001, "240"]) resize("https://tinycat.test", frame.contentWindow, height);
check(frame.height === "600", "Invalid, nonnumeric and oversized dimensions are rejected.");
resize("https://tinycat.test", frame.contentWindow, 240.2);
check(frame.height === "241", "A valid source and origin can resize only its own iframe.");
vm.runInContext(parentScript, context);
check(handshakes.length === 1, "Repeated helper includes do not register duplicate listeners.");
function child(referrer, hash = '#?secret=abc123') {
const childListeners = {}, messages = [], origins = [];
const parent = { postMessage(message, origin) { messages.push(message); origins.push(origin); } };
const childWindow = { parent, location: new URL("https://tinycat.test/status/1/embed" + hash), addEventListener(type, handler) { childListeners[type] = handler; } };
childWindow.window = childWindow;
const childDocument = { referrer, documentElement: { dataset: { embedMaxheight: "300" } }, querySelector() { return { getBoundingClientRect() { return { height: 450 }; } }; }, addEventListener(type, handler) { childListeners[type] = handler; } };
vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, "../../assets/embed-frame.js"), "utf8"), { window: childWindow, document: childDocument, URL, URLSearchParams, Number, Math });
return { childListeners, messages, origins, parent };
}
const { childListeners, messages, origins, parent } = child('https://blog.test/article?private=value');
check(messages.some(m => m.type === "tinycat:embed:resize" && m.height === 300), "Frame height respects the consumer maximum.");
check(messages.some(m => m.message === "height" && m.secret === "abc123" && m.value === 300), "WordPress receives its expected secret-bound height message.");
const before = messages.length;
childListeners.message({ source: source, origin: 'https://blog.test', data: { message: "ready", secret: "abc123" } });
check(messages.length === before, "Non-parent windows cannot trigger the WordPress handshake.");
childListeners.message({ source: parent, origin: 'https://blog.test', data: { message: "ready", secret: "wrong" } });
check(messages.length === before, "A mismatched WordPress secret is ignored.");
childListeners.message({ source: parent, origin: 'https://blog.test', data: { message: "ready", secret: "abc123" } });
check(messages.length === before + 2, "The parent's ready message replays the current height.");
let prevented = false;
childListeners.click({ target: { closest() { return { href: "https://tinycat.test/status/1" }; } }, preventDefault() { prevented = true; } });
check(prevented && messages.at(-1).message === "link" && messages.at(-1).secret === "abc123", "WordPress sandbox navigation requests preserve source-host links.");
check(origins.every(origin => origin === 'https://blog.test'), 'Every resize and navigation reply targets only the parent origin, without referrer paths or query parameters.');
const lockedCount = messages.length;
childListeners.message({ source: parent, origin: 'https://other.test', data: { message: 'ready', secret: 'abc123' } });
check(messages.length === lockedCount, 'Navigating the parent to another origin cannot rebind an established channel.');
for (const referrer of ['', 'invalid', 'data:text/html,test', 'file:///tmp/parent.html']) {
  const peer = child(referrer);
  check(peer.messages.length === 0, 'An unknown or opaque parent receives no unsolicited messages.');
  let prevented = false;
  peer.childListeners.click({ target: { closest: () => ({ href: 'https://tinycat.test/status/1' }) }, preventDefault() { prevented = true; } });
  check(!prevented, 'Without a bound parent, ordinary link navigation is preserved.');
  for (const origin of ['null', '', 'file://', 'data:text/html,test']) {
    peer.childListeners.message({ source: peer.parent, origin, data: { message: 'ready', secret: 'abc123' } });
    check(peer.messages.length === 0, 'Opaque and non-HTTP message origins cannot establish a channel.');
  }
  peer.childListeners.message({ source: source, origin: 'https://evil.test', data: { message: 'ready', secret: 'abc123' } });
  peer.childListeners.message({ source: peer.parent, origin: 'https://blog.test', data: { message: 'ready', secret: 'wrong' } });
  check(peer.messages.length === 0, 'Only the direct parent with the matching secret can establish a channel.');
  peer.childListeners.message({ source: peer.parent, origin: 'https://blog.test', data: { message: 'ready', secret: 'abc123' } });
  check(peer.messages.length === 2 && peer.origins.every(origin => origin === 'https://blog.test'), 'A validated WordPress handshake works without a referrer and uses an exact target origin.');
  peer.childListeners.message({ source: peer.parent, origin: 'https://evil.test', data: { message: 'ready', secret: 'abc123' } });
  check(peer.messages.length === 2, 'A learned parent origin remains pinned.');
}
const plain = child('', '');
plain.childListeners.message({ source: plain.parent, origin: 'https://blog.test', data: { message: 'ready', secret: '' } });
check(plain.messages.length === 1 && plain.origins[0] === 'https://blog.test', 'The TinyCat helper handshake works without a WordPress secret or a referrer.');
function player(url, origin, kind) {
  let load, mounted;
  const button = { addEventListener(name, fn) { load = fn; }, getAttribute() { return "Media"; } };
  const root = { dataset: { playerUrl: url, playerOrigin: origin, playerKind: kind, playerHeight: "166", playerAutoplayParam: "auto_play", playerAutoplayValue: "true" }, querySelector() { return button; }, replaceChildren(frame) { mounted = frame; } };
  const win = {}; win.parent = win;
  const doc = { querySelectorAll() { return [root]; }, getElementById() { return null; }, createElement() { return {}; } };
  vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, "../../assets/embed-frame.js"), "utf8"), { window: win, document: doc, URL, Number, Math });
  check(!mounted, "External players stay unloaded before interaction.");
  load(); return mounted;
}
const audio = player("https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fforss%2Fflickermood", "https://w.soundcloud.com", "audio");
check(audio && audio.height === "166" && new URL(audio.src).searchParams.get("auto_play") === "true", "The audio placeholder mounts the approved compact player on click.");
check(!player("https://evil.test/player", "https://w.soundcloud.com", "audio"), "A media frame cannot change its approved provider origin.");
check(!player("http://w.soundcloud.com/player/", "http://w.soundcloud.com", "audio"), "Media frames require HTTPS.");
console.log("PASS embed resizing, origin/source checks and WordPress protocol (" + checks + " checks)");
