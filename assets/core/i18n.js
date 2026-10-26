
(function () {
  "use strict";
  var T = window.TinyCat = window.TinyCat || {}, catalog = Object.create(null), locale = null;
  function scalar(value) {
    if (value === null || value === undefined || value === false) { return ""; }
    if (value === true) { return "1"; }
    return typeof value === "object" ? JSON.stringify(value) : String(value);
  }
  function format(text, values) {
    var tokens = Object.create(null);
    Object.keys(values || {}).forEach(function (key) { tokens["{" + key + "}"] = scalar(values[key]); tokens[":" + key] = scalar(values[key]); });
    var keys = Object.keys(tokens).sort(function (a, b) { return b.length - a.length; });
    if (!keys.length) { return text; }
    var pattern = keys.map(function (key) { return key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"); }).join("|");
    return text.replace(new RegExp(pattern, "g"), function (token) { return tokens[token]; });
  }
  function uiText(name, fallback) {
    var keys = { close: "common.close", cancel: "common.cancel", confirm: "common.confirm", confirmTitle: "common.confirm_action", requestFailed: "common.request_failed" };
    var key = keys[name];
    return key && T.i18n.has(key) ? T.i18n.t(key) : fallback;
  }
  T.i18n = {
    label: uiText,
    register: function (language, messages) {
      if (!/^[A-Za-z0-9_-]+$/.test(language) || !messages || Array.isArray(messages) || typeof messages !== "object") { throw new TypeError("Invalid translation catalog."); }
      if (locale !== null && locale !== language) { catalog = Object.create(null); }
      Object.keys(messages).forEach(function (key) {
        if (typeof messages[key] !== "string" || !/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/i.test(key)) { throw new TypeError("Invalid translation entry."); }
        catalog[key] = messages[key];
      });
      locale = language;
    },
    locale: function () { return locale; },
    has: function (key) { return Object.prototype.hasOwnProperty.call(catalog, key); },
    t: function (key, values) { return format(T.i18n.has(key) ? catalog[key] : key, values); },
    format: format
  };
}());
