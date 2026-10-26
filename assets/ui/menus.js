import "../core/dom.js";
import "../core/lifecycle.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.menus = {};
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  TinyCat.menus.init = function () {
    if (TinyCat.__dismissibleMenuEventsBound === true) {
      return;
    }

    TinyCat.__dismissibleMenuEventsBound = true;

    function closeMenus(except) {
      qsa("[data-dismissible-menu][open]").forEach(function (openMenu) {
        if (openMenu !== except) {
          openMenu.open = false;
        }
      });
    }

    document.addEventListener("click", function (event) {
      var menu = event.target.closest && event.target.closest("[data-dismissible-menu]");
      var action = event.target.closest && event.target.closest("[data-dismissible-menu-action]");

      closeMenus(menu);

      if (menu && action) {
        menu.open = false;
      }
    });

    document.addEventListener("keydown", function (event) {
      var activeMenu;
      var summary;

      if (event.key !== "Escape") {
        return;
      }

      activeMenu = document.activeElement && document.activeElement.closest
        ? document.activeElement.closest("[data-dismissible-menu][open]")
        : null;
      closeMenus(null);

      if (activeMenu) {
        summary = qs("summary", activeMenu);

        if (summary) {
          summary.focus();
        }
      }
    });

    document.addEventListener("toggle", function (event) {
      var menu = event.target;

      if (!menu || !menu.matches || !menu.matches("[data-dismissible-menu]") || !menu.open) {
        return;
      }

      closeMenus(menu);
    }, true);
  };
  TinyCat.lifecycle.register("menus", { init: TinyCat.menus.init });
}());
