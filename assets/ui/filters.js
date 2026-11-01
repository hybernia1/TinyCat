import "../core/dom.js";
import "../core/lifecycle.js";
import "../core/component-renderer.js";
import "./popovers.js";
import "./form-requests.js";
(function () {
  "use strict";
  var T = window.TinyCat, forms = new Map();
  function neutral(field) {
    if (field.options && !Array.from(field.options).some(function (option) { return option.value === ""; })
      && Array.from(field.options).some(function (option) { return option.value === "0"; })) return "0";
    return "";
  }
  function active(field) {
    var name = field.getAttribute("name"), type = field.getAttribute("type");
    return name && !["q", "page", "per_page", "sort", "direction", "scope", "_csrf", "_method"].includes(name)
      && type !== "hidden" && !field.disabled && (!["checkbox", "radio"].includes(type) || field.checked)
      && field.value !== neutral(field);
  }
  function summaries() {
    document.body.querySelectorAll("[data-list-summary-filters]").forEach(function (root) {
      var target = root.dataset.listSummaryFilters;
      var form = Array.from(forms.values()).find(function (item) { return item.dataset.ajaxTarget === target; });
      root.replaceChildren();
      if (!form) { return; }
      form.querySelectorAll("input, select").forEach(function (field) {
        var name = field.getAttribute("name");
        if (!active(field)) { return; }
        var container = field.closest && field.closest(".field");
        var label = container && container.querySelector(".label");
        var selected = field.options && Array.from(field.options).find(function (option) { return option.selected; });
        var value = selected ? selected.textContent.trim() : field.value;
        var text = label ? label.textContent.trim() + ": " + value : value;
        root.appendChild(T.components.render("ui/filter-chip", { target: target, name: name, label: text }));
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
        var hasActive = Array.from(form.querySelectorAll("input, select")).some(active);
        summary.classList.toggle("btn-secondary", hasActive);
        summary.classList.toggle("btn-ghost", !hasActive);
      }
    });
    summaries();
  }
  T.filters = {
    get: function (id) { return forms.get(id); },
    capture: function (source) {
      var menu = source && source.closest && source.closest("[data-filter-menu]");
      var form = source && source.closest && (source.closest("[data-filter-content]") || source.closest(".admin-search-form"));
      if (form && form.dataset.filterRemoveFocus) {
        delete form.dataset.filterRemoveFocus;
        return { removed: true, target: form.dataset.ajaxTarget };
      }
      if (!menu || !menu.open || !form) { return null; }
      var panel = menu.querySelector("[data-filter-panel]");
      return { id: form.id, field: form.dataset.filterFocus || "", scroll: panel.scrollTop };
    },
    restore: function (state) {
      if (!state) { return; }
      if (state.removed) {
        var summary = Array.from(document.body.querySelectorAll("[data-list-summary]")).find(function (item) { return item.dataset.listSummary === state.target; });
        var search = Array.from(document.body.querySelectorAll(".admin-search-form")).find(function (item) { return item.dataset.ajaxTarget === state.target; });
        var focus = summary && summary.querySelector("[data-filter-remove]");
        focus = focus || (search && search.querySelector('[name="q"]'));
        if (focus) focus.focus({ preventScroll: true });
        return;
      }
      var panel = Array.from(document.body.querySelectorAll("[data-filter-panel]")).find(function (item) { return item.dataset.filterPanel === state.id; });
      var form = forms.get(state.id);
      if (!panel || !panel.parentElement || !form) { return; }
      T.popovers.open(panel.parentElement);
      panel.scrollTop = state.scroll;
      var field = Array.from(form.querySelectorAll("input, select")).find(function (item) { return item.getAttribute("name") === state.field; });
      if (field) { field.focus({ preventScroll: true }); }
    },
    remove: function (id) {
      var form = forms.get(id) || document.getElementById(id);
      if (form) { T.forms.dispose(form); form.remove(); }
      forms.delete(id);
    },
    hydrate: hydrate,
    init: function () {
      document.addEventListener("click", function (event) {
        var chip = event.target.closest && event.target.closest("[data-filter-remove]");
        if (!chip) return;
        var target = chip.dataset.filterTarget, name = chip.dataset.filterRemove;
        var form = Array.from(forms.values()).find(function (item) { return item.dataset.ajaxTarget === target; });
        if (!form) form = Array.from(document.body.querySelectorAll(".admin-search-form")).find(function (item) { return item.dataset.ajaxTarget === target; });
        if (!form || form.dataset.apiBusy === "true") return;
        var field = Array.from(form.querySelectorAll("input, select")).find(function (item) { return item.getAttribute("name") === name; });
        if (!field) return;
        event.preventDefault();
        field.value = neutral(field);
        if (field.type === "checkbox" || field.type === "radio") field.checked = false;
        if (name === "q") {
          Array.from(document.body.querySelectorAll(".admin-search-form")).filter(function (item) { return item.dataset.ajaxTarget === target; }).forEach(function (item) {
            var input = item.querySelector('[name="q"]'); if (input) input.value = "";
          });
        }
        var page = form.querySelector('[name="page"]'); if (page) page.value = "1";
        if (event.detail === 0 && document.activeElement === chip) form.dataset.filterRemoveFocus = "true";
        form.requestSubmit();
      });
    }
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
    init: T.filters.init,
    hydrate: hydrate
  });
}());
