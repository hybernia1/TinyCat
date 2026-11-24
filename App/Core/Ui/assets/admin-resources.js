import "./component-builders.js";
import "./filters.js";
import "./content-updates.js";
import "./list-navigation.js";
import "./runtime/modules.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui, definitions = T.adminDefinitions || {}, ready = new WeakSet();
  Object.keys(definitions).forEach(function (id) {
    var definition = definitions[id];
    if (!definition.path) { return; }
    T.lists.register(definition.path, {
      target: definition.target, api: definition.api, keepQuery: ["sort", "direction"],
      url: function () { return definition.endpoint + window.location.search; },
      apply: function (target, response) {
        var data = Object.assign({}, response.data, { pagination: U.pagination(response) });
        T.content.replaceChildren(target, T.components.render(definition.component, Object.assign({}, data, { state: data })));
      }
    });
  });
  T.modules.register("admin-resources", {
    modal: function (url, response, id) {
      for (var key of Object.keys(definitions)) {
        var definition = definitions[key], editor = definition.editor;
        if (!editor) { continue; }
        var item = response.data.item || {}, editing = Boolean(item.id);
        var resource = editing && editor.management ? editor.management.replace("{id}", String(item.id)) : editor.creation;
        if (url.pathname !== resource) { continue; }
        var form = U.form(definition.api + (editing ? "/" + item.id : ""), editing ? editor.method || "PUT" : "POST");
        form.dataset.ajaxTarget = definition.target;
        form.dataset.modalCloseOnSuccess = "true";
        form.appendChild(T.components.render(editor.fields, Object.assign({}, response.data, { dialog_id: id })));
        form.appendChild(U.button(editor.submit || "common.save", { type: "submit", "class": "btn btn-primary" }));
        return U.adminDialog(id, editing ? "common.edit" : editor.title, form, false);
      }
      return null;
    },
    result: function (source, response, target) {
      for (var key of Object.keys(definitions)) {
        var definition = definitions[key];
        if (!definition.path || !target || document.querySelector(definition.target) !== target) { continue; }
        var result = T.lists.result(definition.target, source, response, target);
        if (result !== false) { return result; }
      }
      return false;
    },
    hydrate: function (root) {
      (root || document).querySelectorAll("[data-admin-list]").forEach(function (view) {
        if (ready.has(view)) { return; }
        var definition = definitions[view.dataset.adminList];
        if (!definition || !definition.filter) { return; }
        ready.add(view);
        var state = JSON.parse(view.dataset.adminState || "{}");
        var form = T.components.render(definition.filter, state);
        form.querySelectorAll("input, select").forEach(function (control) {
          if (Object.prototype.hasOwnProperty.call(state.filters || {}, control.name)) { control.value = String(state.filters[control.name]); }
        });
        U.filterPanel(definition.filterId, form);
      });
    }
  });
}());
