import "../core/components.js";
import "../core/dom.js";
import "../core/i18n.js";
import "../core/icons.js";
import "../core/lifecycle.js";
import "./dialogs.js";
(function () {
  "use strict";
  var T = window.TinyCat = window.TinyCat || {};
  var e = T.dom.element;
  T.ui = T.ui || {};
  Object.assign(T.ui, {
    icon: T.icons.create,

    text: function (key, values) {
      return T.i18n.t(key, values);
    },
    field: function (label, name, value, type, attributes) {
      var textarea = type === "textarea", attrs = Object.assign({ "class": textarea ? "textarea" : "input", name: name }, textarea ? {} : { type: type || "text", value: value == null ? "" : String(value) }, attributes || {});
      return T.components.render("ui/field", { label: T.ui.text(label), value: value == null ? "" : String(value), textarea: textarea, attributes: attrs });
    },
    select: function (label, name, value, choices) {
      return T.components.render("ui/select", { label: T.ui.text(label), value: value == null ? "" : String(value), choices: choices, attributes: { "class": "select", name: name } });
    },
    hidden: function (name, value) { return e("input", { type: "hidden", name: name, value: value }); },
    button: function (key, attributes) { return e("button", Object.assign({ type: "button", "class": "btn btn-secondary" }, attributes || {}), T.ui.text(key)); },
    form: function (url, method) {
      var form = e("form", { action: url, method: "post", "class": "stack", "data-ajax-form": "", "data-api-method": method, "data-confirm-unsaved": "true" });
      form.dataset.confirmUnsavedTitle = T.ui.text("common.unsaved_title");
      form.dataset.confirmUnsavedMessage = T.ui.text("common.unsaved_message");
      form.dataset.confirmUnsavedOk = T.ui.text("common.leave");
      form.dataset.confirmUnsavedCancel = T.ui.text("common.stay");
      return form;
    },
    dialog: function (id, title, content, persistent) {
      var dialog = T.dialog.create({ id: id, title: T.ui.text(title), closeLabel: T.ui.text("common.close"), closeIcon: T.ui.icon("close"), content: content, disposeOnClose: persistent !== true });
      return dialog;
    },

    pagination: function (data) { return (data.meta && data.meta.pagination) || {}; },
    captcha: function (form, config) {
      var old = form.querySelector("[data-captcha]");
      if (old) { old.remove(); }
      if (!config || !config.enabled) { return; }
      form.appendChild(T.components.render("ui/captcha", { config: config }));
      T.lifecycle.hydrate(form);
    }
  });
}());
