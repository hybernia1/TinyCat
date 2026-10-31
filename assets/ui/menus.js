import "../core/dom.js";
import "../core/lifecycle.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.menus = {};
  var triggers = new WeakSet();
  TinyCat.menus.prepare = function (root) {
    root.querySelectorAll('[data-list-menu] > summary').forEach(function (summary) {
      if (triggers.has(summary)) return;
      triggers.add(summary);
      // Bind on the actual trigger: do not depend on a bubbled SVG/use target
      // or the browser's native summary activation reaching document.
      summary.addEventListener("click", function (event) {
        event.preventDefault();
        var menu = summary.parentElement;
        menu.open = !menu.open;
        if (menu.open) TinyCat.menus.position(menu);
      });
    });
  };
  TinyCat.menus.capture = function (source) {
    var menu = source && source.closest && source.closest("[data-menu-key]");
    if (!menu || !menu.open) return null;
    var panel = menu.querySelector(".list-menu-panel");
    if (!panel) return null;
    return { key: menu.dataset.menuKey, scroll: panel.scrollTop, focus: Array.from(panel.querySelectorAll("a, button, input, select")).indexOf(document.activeElement) };
  };
  TinyCat.menus.restore = function (state) {
    if (!state) return;
    var menu = Array.from(document.querySelectorAll("[data-menu-key]")).find(function (item) { return item.dataset.menuKey === state.key; });
    if (!menu) return;
    menu.open = true;
    TinyCat.menus.position(menu);
    var panel = menu.querySelector(".list-menu-panel");
    panel.scrollTop = state.scroll;
    var focus = panel.querySelectorAll("a, button, input, select")[state.focus];
    if (focus) focus.focus({ preventScroll: true });
  };
  TinyCat.menus.position = function (menu) {
    var panel = menu.querySelector(".list-menu-panel"), anchor = menu.querySelector("summary");
    if (!panel || !anchor || !menu.open) { return; }
    panel.style.transform = "";
    panel.style.height = "";
    var body = menu.closest(".modal-body"), clip = body && body.getBoundingClientRect();
    var top = Math.max(12, clip ? clip.top + 4 : 12);
    var bottom = Math.min(window.innerHeight - 12, clip ? clip.bottom - 4 : window.innerHeight - 12);
    var trigger = anchor.getBoundingClientRect(), bounds = panel.getBoundingClientRect();
    var aboveBottom = Math.min(trigger.top - 4, bottom);
    var below = Math.max(0, bottom - bounds.top), above = Math.max(0, aboveBottom - top);
    var upwards = bounds.height > below && above > below;
    panel.style.height = Math.floor(Math.min(bounds.height, upwards ? above : below)) + "px";
    bounds = panel.getBoundingClientRect();
    var x = bounds.left < 12 ? 12 - bounds.left : Math.min(0, window.innerWidth - 12 - bounds.right);
    var y = upwards ? aboveBottom - bounds.height - bounds.top : 0;
    panel.style.transform = "translate(" + x + "px, " + y + "px)";
  };
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  TinyCat.menus.init = function () {
    TinyCat.menus.prepare(document);
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
      activeMenu = activeMenu || qs("[data-dismissible-menu][open]");
      if (activeMenu) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
      closeMenus(null);

      if (activeMenu) {
        summary = qs("summary", activeMenu);

        if (summary) {
          summary.focus();
        }
      }
    }, true);

    document.addEventListener("toggle", function (event) {
      var menu = event.target;

      if (!menu || !menu.matches || !menu.matches("[data-dismissible-menu]") || !menu.open) {
        return;
      }

      closeMenus(menu);
      TinyCat.menus.position(menu);
    }, true);
    function reposition(event) {
      if (event.target.closest && event.target.closest(".list-menu-panel")) return;
      qsa("[data-list-menu][open]").forEach(TinyCat.menus.position);
    }
    window.addEventListener("resize", reposition);
    document.addEventListener("scroll", reposition, true);
  };
  TinyCat.lifecycle.register("menus", { init: TinyCat.menus.init, hydrateOrder: 20, hydrate: TinyCat.menus.prepare });
}());
