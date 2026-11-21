import "./runtime/dom.js";
import "./runtime/icons.js";
import "./runtime/i18n.js";
import "./runtime/lifecycle.js";
import "./toasts.js";
(function () {
  "use strict";
  var T = window.TinyCat, targets = new WeakMap(), pending = new WeakSet();
  function fallback(text, code) {
    var active = document.activeElement, selection = window.getSelection(), ranges = [];
    if (selection) for (var index = 0; index < selection.rangeCount; index++) ranges.push(selection.getRangeAt(index).cloneRange());
    var field = T.dom.element("textarea", { "class": "sr-only", readonly: true, tabindex: "-1", "aria-hidden": "true" });
    field.value = text;
    (code.closest(".modal") || document.body).appendChild(field);
    try { field.focus({ preventScroll: true }); field.select(); return document.execCommand("copy"); }
    finally {
      field.remove();
      if (active && active.isConnected) active.focus({ preventScroll: true });
      if (selection) { selection.removeAllRanges(); ranges.forEach(function (range) { selection.addRange(range); }); }
    }
  }
  async function copy(button) {
    var code = targets.get(button);
    if (!code || !code.isConnected || pending.has(button)) return;
    pending.add(button); button.setAttribute("aria-busy", "true");
    try {
      var text = code.textContent, copied = false;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        try { await navigator.clipboard.writeText(text); copied = true; } catch (_error) { /* Try the local selection fallback. */ }
      }
      if (!copied && !code.isConnected) return;
      if (!copied) copied = fallback(text, code);
      T.toasts.show(T.i18n.t(copied ? "common.copied" : "common.copy_failed"), copied ? "success" : "danger");
    } catch (_error) { T.toasts.show(T.i18n.t("common.copy_failed"), "danger"); }
    finally { pending.delete(button); button.removeAttribute("aria-busy"); }
  }
  function hydrate(root) {
    var codes = Array.from(root.querySelectorAll("code"));
    if (root.matches && root.matches("code")) codes.unshift(root);
    codes.forEach(function (code) {
      var next = code.nextElementSibling, button = next && targets.has(next) ? next : null;
      if (code.hidden || !code.textContent.trim() || code.closest("a, button, summary, [contenteditable], textarea")) {
        if (button) { button.remove(); if (code.parentElement && code.parentElement.tagName === "PRE") code.parentElement.removeAttribute("data-code-copy-block"); }
        return;
      }
      if (!button) {
        button = T.dom.element("button", { type: "button", "class": "btn btn-ghost btn-icon", "data-copy-code": "", "aria-label": T.i18n.t("common.copy"), title: T.i18n.t("common.copy") });
        button.appendChild(T.icons.create("copy")); code.after(button);
      }
      targets.set(button, code);
      var pre = code.parentElement;
      if (pre && pre.tagName === "PRE") pre.toggleAttribute("data-code-copy-block", pre.querySelectorAll(":scope > code").length === 1);
    });
  }
  T.lifecycle.register("code-copy", {
    hydrateOrder: -10,
    hydrate: hydrate,
    init: function () {
      document.addEventListener("click", function (event) {
        var button = event.target.closest && event.target.closest("[data-copy-code]");
        if (button) { event.preventDefault(); copy(button); }
      });
    }
  });
}());
