import "../../core/dom.js";
import "../../core/lifecycle.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.adminNavigation = {};
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var focusFirst = TinyCat.dom.focusFirst;
  TinyCat.adminNavigation.set = function (open) {
    var isOpen = Boolean(open);
    var sidebar = qs("[data-admin-sidebar]");

    document.body.classList.toggle("admin-nav-open", isOpen);

    qsa("[data-admin-nav-toggle]").forEach(function (button) {
      button.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });

    if (isOpen && sidebar) {
      focusFirst(sidebar);
    }
  };
  TinyCat.adminNavigation.toggle = function () {
    TinyCat.adminNavigation.set(!document.body.classList.contains("admin-nav-open"));
  };
  TinyCat.adminNavigation.init = function () {
    document.addEventListener("click", function (event) {
      var toggle = event.target.closest && event.target.closest("[data-admin-nav-toggle]");
      var close = event.target.closest && event.target.closest("[data-admin-nav-close]");
      var navLink = event.target.closest && event.target.closest("a.admin-nav-link");

      if (toggle) {
        event.preventDefault();
        TinyCat.adminNavigation.toggle();
        return;
      }

      if (close || navLink) {
        TinyCat.adminNavigation.set(false);
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        TinyCat.adminNavigation.set(false);
      }
    });
  };
  TinyCat.lifecycle.register("admin-navigation", { init: TinyCat.adminNavigation.init });
}());
