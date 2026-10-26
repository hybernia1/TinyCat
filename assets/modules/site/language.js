import "../../core/lifecycle.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.lifecycle.register("interface-language", { init: function () {
    var banner = document.querySelector("[data-language-suggestion]");
    var key = banner ? "tinycat-language-hint:" + document.documentElement.lang + ":" + banner.dataset.languageSuggestion : "";
    if (banner) {
      try { if (window.sessionStorage.getItem(key) === "dismissed") { banner.hidden = true; } } catch (_) {}
      banner.querySelector("[data-language-suggestion-close]").addEventListener("click", function () {
        banner.hidden = true;
        try { window.sessionStorage.setItem(key, "dismissed"); } catch (_) {}
      });
    }
    document.addEventListener("click", function (event) {
      document.querySelectorAll("[data-language-menu][open]").forEach(function (menu) {
        if (!menu.contains(event.target) || (event.target.closest && event.target.closest("a[data-language-link]"))) { menu.open = false; }
      });
    });
    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") { return; }
      document.querySelectorAll("[data-language-menu][open]").forEach(function (menu) {
        menu.open = false;
        if (menu.contains(document.activeElement)) { menu.querySelector("summary").focus(); }
      });
    });
  } });
}());
