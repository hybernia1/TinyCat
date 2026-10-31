import "../../ui/menus.js";
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
  } });
}());
