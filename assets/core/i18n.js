
(function () {
  "use strict";
  var T = window.TinyCat = window.TinyCat || {}, catalog = Object.create(null), locale = null;
  function scalar(value) {
    if (value === null || value === undefined || value === false) { return ""; }
    if (value === true) { return "1"; }
    return typeof value === "object" ? JSON.stringify(value) : String(value);
  }
  function format(text, values) {
    return text.replace(/(?<!\{)\{([A-Za-z_][A-Za-z0-9_]*)\}(?!\})/g, function (token, name) { return Object.prototype.hasOwnProperty.call(values || {}, name) ? scalar(values[name]) : token; });
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
    plural: function (key, count, values) {
      var category = T.i18n.pluralCategory(count, locale || "en");
      var selected = T.i18n.has(key + "." + category) ? catalog[key + "." + category] : (T.i18n.has(key + ".other") ? catalog[key + ".other"] : key);
      return format(selected, Object.assign({}, values || {}, { count: count }));
    },
    pluralCategory: function (count, language) {
      if (typeof count !== "number" || !Number.isFinite(count)) { throw new TypeError("Plural counts must be finite numbers."); }
      count = Math.abs(count);
      var normalized = language.toLowerCase().replace(/_/g, "-"), base = normalized.split("-")[0];
      var integer = Number.isInteger(count), lastDigit = count % 10, lastTwo = count % 100;
      if (base === "cs" || base === "sk") {
        if (!integer) { return "many"; }
        return count === 1 ? "one" : (count >= 2 && count <= 4 ? "few" : "other");
      }
      if (base === "pl") {
        if (!integer) { return "other"; }
        if (count === 1) { return "one"; }
        return lastDigit >= 2 && lastDigit <= 4 && (lastTwo < 12 || lastTwo > 14) ? "few" : "many";
      }
      if (base === "ru" || base === "uk") {
        if (!integer) { return "other"; }
        if (lastDigit === 1 && lastTwo !== 11) { return "one"; }
        return lastDigit >= 2 && lastDigit <= 4 && (lastTwo < 12 || lastTwo > 14) ? "few" : "many";
      }
      if (["es", "fr", "it", "pt"].includes(base)) {
        if ((base === "fr" || base === "pt") && !normalized.startsWith("pt-pt")) {
          if (Math.floor(count) < 2) { return "one"; }
        } else if (count === 1) { return "one"; }
        if (integer && count !== 0 && count % 1000000 === 0) { return "many"; }
        return "other";
      }
      return count === 1 ? "one" : "other";
    },
    format: format
  };
}());
