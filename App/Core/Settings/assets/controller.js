import "../../../../assets/ui/image-uploads.js";
import "../../../../assets/ui/component-builders.js";
import "../../../../assets/core/modules.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  function prepareField(spec, display, name, examples) {
    if (!display) { throw new Error("Missing settings field presentation: " + spec.key); }
    name = name || "settings[" + spec.key + "]";
    examples = examples || {};
    var value = spec.value == null ? "" : spec.value, type = spec.type;
    var grouped = Array.isArray(spec.fields), kind = grouped ? "group" : ({ bool: "bool", email_templates: "templates", site_image: "image", textarea: "textarea" }[type] || "input");
    var model = { root_tag: grouped || ["templates", "image"].includes(kind) ? "div" : "label",
      root_class: "field min-w-0" + (display.span ? " grid-span-full" : ""), kind: kind, label: display.label,
      help: display.help || "", name: name, value: type === "password" ? "" : value,
      checked: Boolean(value), fields: [], options: [], attributes: {},
      upload_name: "settings_files[" + spec.key + "]", remove_name: "settings_remove[" + spec.key + "]" };
    if (grouped) {
      model.fields = spec.fields.map(function (child) {
        var childValue = child.type === "password" ? "" : value[child.key] == null ? child.value : value[child.key];
        return prepareField(Object.assign({}, child, { value: childValue }), (display.fields || {})[child.key], name + "[" + child.key + "]", examples);
      });
    } else if (type === "email_smtp") {
      throw new Error("Missing settings group fields: " + spec.key);
    } else if (kind === "templates") {
      model.options = (spec.options || []).map(function (option) {
        return { name: name + "[" + option.value + "]", value: option.value, selected: Boolean(value[option.value]),
          label: option.value.replace(/_/g, " ").replace(/\b\w/g, function (letter) { return letter.toUpperCase(); }) };
      });
    } else if (["select", "language", "timezone", "date_format", "time_format", "datetime_format"].includes(type)) {
      model.kind = "select";
      model.attributes = { "class": "select", name: name, required: type !== "language" && !spec.optional };
      model.options = (spec.options || []).map(function (option) {
        var text = option.name || option.value;
        if (type === "language") { text = option.value.toUpperCase() + " - " + text; }
        if (type === "timezone") { text = "(UTC" + (option.offset === "+00:00" ? "" : option.offset) + ") " + option.value; }
        if (examples[option.value]) { text = option.value + " - " + examples[option.value]; }
        return { value: option.value, label: text, selected: String(value) === String(option.value) };
      });
    } else if (kind === "textarea") {
      model.value = String(value);
      model.attributes = { "class": "textarea", name: name, rows: "8", maxlength: spec.max || 5000, placeholder: display.placeholder || U.text("settings.footer_placeholder") };
    } else if (kind === "input") {
      var inputType = { int: "number", email: "email", site_url: "url", password: "password" }[type] || "text";
      model.attributes = { "class": "input", name: name, type: inputType, value: model.value,
        required: !spec.optional && (type === "text" || type === "int"), maxlength: type === "int" ? null : spec.max || (type === "site_url" ? 2048 : 190),
        min: type === "int" ? spec.min || 0 : null, max: type === "int" ? spec.max || 2147483647 : null,
        autocomplete: type === "password" ? "new-password" : type === "site_url" ? "url" : null, placeholder: type === "site_url" ? "https://example.com" : null };
    }
    return model;
  }
  T.settings = {
    open: function (section) {
      return T.dialog.openResource("modal-settings-editor", "/api/settings/editor", { tab: section });
    },
    modal: function (url, response, id) {
      if (url.pathname !== "/api/settings/editor") { return null; }
      var presentation = JSON.parse(document.body.dataset.settingsPresentation || "{}");
      var sections = (response.data.sections || []).map(function (section) {
        var view = (presentation.sections || {})[section.key];
        if (!view) { throw new Error("Missing settings section presentation: " + section.key); }
        return { key: section.key, label: view.label, icon: view.icon, fields: section.fields.map(function (spec) {
          return prepareField(spec, (presentation.fields || {})[spec.key], null, presentation.examples);
        }) };
      });
      var form = U.form("/api/settings", "PATCH");
      form.setAttribute("enctype", "multipart/form-data");
      form.append(T.components.render("settings/sections", { sections: sections }), U.button("common.save", { type: "submit", "class": "btn btn-primary" }));
      return U.sectionedDialog(id, "settings.title", form, false);
    },
    result: function (source, response) {
      var action = source.getAttribute("action");
      if (!action || new URL(action, window.location.href).pathname !== "/api/settings") { return false; }
      var images = response.data.images || {};
      source.querySelectorAll("input").forEach(function (input) {
        var name = input.name || input.getAttribute("name") || "";
        var key = name.startsWith("settings[") ? name.slice(9, -1) : "";
        if (Object.prototype.hasOwnProperty.call(images, key)) { input.value = images[key]; }
        if (input.type === "file" || input.type === "password") { input.value = ""; }
        if (name.startsWith("settings_remove[")) { input.checked = false; }
      });
      return [];
    }
  };
  T.modules.register("settings", T.settings);
}());
