import "../core/dom.js";
import "../core/lifecycle.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.tabs = {};
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var emit = TinyCat.dom.emit;
  TinyCat.tabs.activate = function (tab, focus) {
    var root = tab.closest("[data-tabs]");

    if (!root) {
      return;
    }

    var name = tab.dataset.tab;

    qsa("[data-tab]", root).forEach(function (item) {
      var selected = item === tab;
      item.setAttribute("aria-selected", selected ? "true" : "false");
      item.tabIndex = selected ? 0 : -1;
    });

    qsa("[data-tab-panel]", root).forEach(function (panel) {
      panel.hidden = panel.dataset.tabPanel !== name;
    });
    qsa("[data-tab-select]", root).forEach(function (select) { select.value = name; });
    qsa("[data-tab-select-icon]", root).forEach(function (holder) {
      var icon = tab.querySelector("svg");
      holder.replaceChildren();
      if (icon) holder.appendChild(icon.cloneNode(true));
    });

    qsa("[data-tab-submit]", root.closest(".modal-panel") || root).forEach(function (button) {
      var panel = qsa("[data-tab-panel]", root).find(function (item) {
        return item.dataset.tabPanel === name;
      });

      var form = panel && (panel.tagName === "FORM" ? panel : panel.querySelector("form"));
      if (form && form.id) {
        button.setAttribute("form", form.id);
        button.disabled = false;
      } else {
        button.removeAttribute("form");
        button.disabled = true;
      }
    });

    if (focus) {
      tab.focus();
    }

    emit(root, "tinycat:tab", { tab: tab, name: name });
  };
  TinyCat.tabs.reveal = function (field) {
    var panel = field && field.closest ? field.closest("[data-tab-panel]") : null;
    while (panel) {
      var root = panel.closest("[data-tabs]");
      if (!root) { break; }
      if (panel.hidden) {
        var tab = qsa("[data-tab]", root).find(function (item) { return item.dataset.tab === panel.dataset.tabPanel; });
        if (tab) { TinyCat.tabs.activate(tab, false); }
      }
      panel = root.parentElement && root.parentElement.closest("[data-tab-panel]");
    }
  };
  TinyCat.tabs.hydrate = function () {
    qsa("[data-tabs]").forEach(function (root) {
      var selected = qs('[data-tab][aria-selected="true"]', root) || qs("[data-tab]", root);

      if (selected) {
        TinyCat.tabs.activate(selected, false);
      }
    });

    if (TinyCat.__tabsEventsBound === true) {
      return;
    }

    TinyCat.__tabsEventsBound = true;

    document.addEventListener("change", function (event) {
      var select = event.target;
      if (!select.matches || !select.matches("[data-tab-select]")) return;
      var tab = qsa("[data-tab]", select.closest("[data-tabs]")).find(function (item) { return item.dataset.tab === select.value; });
      if (tab) TinyCat.tabs.activate(tab, false);
    });

    document.addEventListener("invalid", function (event) { TinyCat.tabs.reveal(event.target); }, true);

    document.addEventListener("click", function (event) {
      var tab = event.target.closest && event.target.closest("[data-tab]");

      if (!tab) {
        return;
      }

      event.preventDefault();
      TinyCat.tabs.activate(tab, false);
    });

    document.addEventListener("keydown", function (event) {
      var tab = event.target.closest && event.target.closest("[data-tab]");

      if (!tab) {
        return;
      }

      var root = tab.closest("[data-tabs]");
      var tabs = qsa("[data-tab]", root);
      var index = tabs.indexOf(tab);
      var next = null;

      if (event.key === "ArrowRight" || event.key === "ArrowDown") {
        next = tabs[(index + 1) % tabs.length];
      } else if (event.key === "ArrowLeft" || event.key === "ArrowUp") {
        next = tabs[(index - 1 + tabs.length) % tabs.length];
      } else if (event.key === "Home") {
        next = tabs[0];
      } else if (event.key === "End") {
        next = tabs[tabs.length - 1];
      }

      if (next) {
        event.preventDefault();
        TinyCat.tabs.activate(next, true);
      }
    });
  };
  TinyCat.lifecycle.register("tabs", { hydrate: TinyCat.tabs.hydrate });
}());
