import "../core/dom.js";
import "../core/lifecycle.js";
import "./menus.js";
import "./forms.js";
(function () {
  "use strict";
  var T = window.TinyCat, forms = new Map();
  function summaries() {
    document.body.querySelectorAll("[data-list-summary-filters]").forEach(function (root) {
      var target = root.dataset.listSummaryFilters;
      var form = Array.from(forms.values()).find(function (item) { return item.dataset.ajaxTarget === target; });
      root.replaceChildren();
      if (!form) { return; }
      form.querySelectorAll("input, select").forEach(function (field) {
        var name = field.getAttribute("name");
        if (!name || field.getAttribute("type") === "hidden" || ["q", "page", "per_page", "sort", "direction", "scope"].includes(name) || !field.value || field.value === "0") { return; }
        var container = field.closest && field.closest(".field");
        var label = container && container.querySelector(".label");
        var selected = field.options && Array.from(field.options).find(function (option) { return option.selected; });
        var value = selected ? selected.textContent.trim() : field.value;
        var text = label ? label.textContent.trim() + ": " + value : value;
        root.appendChild(T.dom.element("span", { "class": "badge" }, text));
      });
    });
  }
  function hydrate() {
    document.body.querySelectorAll("[data-filter-content]").forEach(function (form) {
      forms.set(form.id, form);
      form.removeAttribute("data-modal-close-on-success");
      form.querySelectorAll("[data-modal-close]").forEach(function (control) { control.removeAttribute("data-modal-close"); });
      form.querySelectorAll("input, select").forEach(function (field) {
        if (field.getAttribute("name") && !["hidden", "submit", "button", "reset"].includes(field.getAttribute("type"))) {
          field.setAttribute("data-submit-on-change", "");
        }
      });
    });
    document.body.querySelectorAll("[data-filter-panel]").forEach(function (panel) {
      var form = forms.get(panel.dataset.filterPanel);
      if (!form) { return; }
      if (form.parentElement !== panel) { form.remove(); panel.appendChild(form); }
      form.hidden = false;
      var summary = panel.parentElement && panel.parentElement.querySelector("summary");
      if (summary) {
        var active = Array.from(form.querySelectorAll("input, select")).some(function (field) {
          var name = field.getAttribute("name");
          return name && !["q", "page", "per_page", "sort", "direction", "scope", "_csrf", "_method"].includes(name)
            && field.value !== "" && field.value !== "0";
        });
        summary.classList.toggle("btn-secondary", active);
        summary.classList.toggle("btn-ghost", !active);
      }
    });
    summaries();
  }
  T.filters = {
    get: function (id) { return forms.get(id); },
    capture: function (source) {
      var menu = source && source.closest && source.closest("[data-filter-menu]");
      var form = source && source.closest && source.closest("[data-filter-content]");
      if (!menu || !menu.open || !form) { return null; }
      var panel = menu.querySelector("[data-filter-panel]");
      return { id: form.id, field: form.dataset.filterFocus || "", scroll: panel.scrollTop };
    },
    restore: function (state) {
      if (!state) { return; }
      var panel = Array.from(document.body.querySelectorAll("[data-filter-panel]")).find(function (item) { return item.dataset.filterPanel === state.id; });
      var form = forms.get(state.id);
      if (!panel || !panel.parentElement || !form) { return; }
      panel.parentElement.open = true;
      panel.scrollTop = state.scroll;
      var field = Array.from(form.querySelectorAll("input, select")).find(function (item) { return item.getAttribute("name") === state.field; });
      if (field) { field.focus({ preventScroll: true }); }
    },
    remove: function (id) {
      var form = forms.get(id) || document.getElementById(id);
      if (form) { T.forms.dispose(form); form.remove(); }
      forms.delete(id);
    },
    hydrate: hydrate
  };
  T.ui = T.ui || {};
  T.ui.filterPanel = function (id, form) {
    T.filters.remove(id);
    form.id = id;
    form.dataset.filterContent = "";
    form.hidden = true;
    forms.set(id, form);
    document.body.appendChild(form);
    hydrate();
    return form;
  };
  T.lifecycle.register("filters", {
    hydrateOrder: 5,
    hydrate: hydrate,
    init: function () {
      document.addEventListener("toggle", function (event) {
        var menu = event.target;
        if (!menu.matches || !menu.matches("[data-filter-menu]") || !menu.open) { return; }
        var panel = menu.querySelector("[data-filter-panel]");
        panel.style.transform = "";
        var bounds = panel.getBoundingClientRect();
        var offset = bounds.left < 12 ? 12 - bounds.left : Math.min(0, window.innerWidth - 12 - bounds.right);
        if (offset) { panel.style.transform = "translateX(" + offset + "px)"; }
      }, true);
    }
  });
}());
