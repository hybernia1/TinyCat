import "../../core/api-client.js";
import "../../core/dom.js";
import "../../core/lifecycle.js";
import "../../ui/toasts.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qsa = TinyCat.dom.all;
  TinyCat.lifecycle.register("user-menu", { init: function () {
    document.addEventListener("toggle", function (event) {
      var menu = event.target;
      if (!menu.matches || !menu.matches(".user-menu") || !menu.open) { return; }
      var theme = document.documentElement.getAttribute("data-theme") || "system";
      qsa("[data-user-menu-theme]", menu).forEach(function (button) { button.setAttribute("aria-pressed", button.dataset.userMenuTheme === theme ? "true" : "false"); });
    }, true);
    document.addEventListener("click", async function (event) {
      var button = event.target.closest && event.target.closest("button[data-user-menu-theme]");
      if (!button || button.tagName !== "BUTTON" || !button.hasAttribute("data-user-menu-theme")) { return; }
      var group = button.closest(".user-menu-theme-switch");
      var buttons = qsa("[data-user-menu-theme]", group);
      var previous = document.documentElement.getAttribute("data-theme") || "system";
      var next = button.dataset.userMenuTheme;
      if (next === previous || button.disabled) { return; }
      function apply(theme) {
        if (theme === "system") { document.documentElement.removeAttribute("data-theme"); }
        else { document.documentElement.setAttribute("data-theme", theme); }
        buttons.forEach(function (item) { item.setAttribute("aria-pressed", item.dataset.userMenuTheme === theme ? "true" : "false"); });
      }
      buttons.forEach(function (item) { item.disabled = true; });
      apply(next);
      try {
        await TinyCat.api.request("/api/users/me", { method: "PATCH", body: { theme: next } });
      } catch (error) {
        apply(previous);
        TinyCat.toasts.show(error.message, "danger");
      } finally {
        buttons.forEach(function (item) { item.disabled = false; });
      }
    });

  } });
}());
