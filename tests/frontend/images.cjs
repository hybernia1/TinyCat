"use strict";
const fs = require("fs"), vm = require("vm"), assert = require("assert");
let handler, hydrate;
const document = { body: { dataset: { fallbackAvatar: "/cache/assets/avatar.fixture.svg", fallbackImage: "/cache/assets/image.fixture.svg" } }, addEventListener: (name, callback) => { if (name === "error") handler = callback; } };
const window = { TinyCat: { lifecycle: { register: (name, callbacks) => { hydrate = callbacks.hydrate; callbacks.init(); } } } };
vm.runInNewContext(fs.readFileSync(require("path").join(__dirname, "../../App/Core/Ui/assets/image-placeholders.js"), "utf8").replace(/^import .*;$/mg, ""), { window, document });
function image(avatar = false) { return { tagName: "IMG", dataset: {}, hidden: false, complete: true, naturalWidth: 0, src: "/missing.webp", removed: [], closest: () => null, hasAttribute: name => avatar && name === "data-user-avatar-image", removeAttribute(name) { this.removed.push(name); } }; }
const photo = image(); handler({ target: photo });
assert.equal(photo.src, "/cache/assets/image.fixture.svg"); assert(photo.removed.includes("srcset"));
photo.src = "/failed-placeholder"; handler({ target: photo }); assert.equal(photo.src, "/failed-placeholder");
const avatar = image(true); handler({ target: avatar }); assert.equal(avatar.src, "/cache/assets/avatar.fixture.svg");
const early = image(); hydrate({ querySelectorAll: () => [early] }); assert.equal(early.src, "/cache/assets/image.fixture.svg");
const preview = image(); preview.closest = () => ({}); handler({ target: preview }); assert.equal(preview.src, "/missing.webp");
console.log("PASS image placeholders, early failures and recursion protection (6 checks)");
