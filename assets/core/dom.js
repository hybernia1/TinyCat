
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};

  function isFocusableVisible(element) {
    var style;

    if (!element || element.disabled || element.hidden || element.closest('[hidden], [aria-hidden="true"]')) {
      return false;
    }

    style = window.getComputedStyle ? window.getComputedStyle(element) : null;
    return !style || (style.display !== "none" && style.visibility !== "hidden");
  }

  function detachModalFromCard(modal, card) {
    if (modal && card && card.contains(modal)) {
      document.body.appendChild(modal);
    }
  }
  TinyCat.dom = {
    detachFromCard: detachModalFromCard,
    visible: isFocusableVisible,
    focusables: function (root) {
      return TinyCat.dom.all('a[href], area[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), iframe, [contenteditable="true"], [tabindex]:not([tabindex="-1"])', root).filter(isFocusableVisible);
    },
    focusFirst: function (root, fallbackSelector) {
      var items = TinyCat.dom.focusables(root);
      var target = items.find(function (item) { return item.hasAttribute("autofocus"); }) || items[0];
      if (!target && fallbackSelector) { target = TinyCat.dom.find(fallbackSelector, root); if (target) { target.setAttribute("tabindex", "-1"); } }
      if (target && target.focus) { target.focus(); }
    },
    find: function (selector, root) { return (root || document).querySelector(selector); },
    all: function (selector, root) { return Array.from((root || document).querySelectorAll(selector)); },
    selectorValue: function (value) {
      value = String(value || "");
      return window.CSS && typeof window.CSS.escape === "function" ? window.CSS.escape(value) : value.replace(/["\\]/g, "\\$&");
    },
    dataSelector: function (name, value) { return "[" + name + "=\"" + TinyCat.dom.selectorValue(value) + "\"]"; },
    emit: function (element, name, detail) { element.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail || {} })); },
    create: function (tag, className, text) { return TinyCat.dom.element(tag, className ? { "class": className } : {}, text); },
    element: function (tag, attributes, text) {
      if (!/^[a-z][a-z0-9-]*$/.test(tag) || /^(script|style|iframe|object|embed|base|link|meta)$/.test(tag)) {
        throw new TypeError("Unsupported UI element.");
      }
      var element = document.createElement(tag);
      Object.keys(attributes || {}).forEach(function (name) {
        var value = attributes[name];
        if (!/^[a-z][a-z0-9:_-]*$/i.test(name) || /^on/i.test(name) || /^(srcdoc|style|innerHTML)$/i.test(name)) {
          throw new TypeError("Unsupported UI attribute.");
        }
        if (/^(href|src|action|formaction)$/i.test(name) && value !== null && value !== false) {
          var url = new URL(String(value), window.location.href);
          if (url.protocol !== "http:" && url.protocol !== "https:" && !(name.toLowerCase() === "href" && /^(mailto:|tel:)$/.test(url.protocol))) {
            throw new TypeError("Unsupported UI URL.");
          }
        }
        if (value !== false && value !== null && value !== undefined) {
          element.setAttribute(name, value === true ? "" : String(value));
        }
      });
      if (text !== undefined && text !== null) {
        element.textContent = String(text);
      }
      return element;
    }
  };
}());
