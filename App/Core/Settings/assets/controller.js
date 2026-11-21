// @component-owned: structure is declared in owner catalogs.
import "../../Ui/assets/image-uploads.js";
import "../../Ui/assets/component-builders.js";
import "../../Ui/assets/runtime/modules.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  function prepareField(spec, name, key) {
    name = name || "settings[" + spec.key + "]";
    key = key || spec.key;
    var value = spec.value == null ? "" : spec.value, type = spec.type;
    var grouped = Array.isArray(spec.fields), kind = grouped ? "group" : ({ bool: "bool", email_templates: "templates", site_image: "image", textarea: "textarea" }[type] || "input");
    var model = { root_tag: grouped || ["templates", "image"].includes(kind) ? "div" : "label",
      key: key, kind: kind, name: name, value: type === "password" ? "" : value,
      checked: Boolean(value), fields: [], options: [], attributes: {},
      upload_name: "settings_files[" + spec.key + "]", remove_name: "settings_remove[" + spec.key + "]" };
    if (grouped) {
      model.fields = spec.fields.map(function (child) {
        var childValue = child.type === "password" ? "" : value[child.key] == null ? child.value : value[child.key];
        return prepareField(Object.assign({}, child, { value: childValue }), name + "[" + child.key + "]", key + "." + child.key);
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
        return { value: option.value, label: text, selected: String(value) === String(option.value) };
      });
    } else if (kind === "textarea") {
      model.value = String(value);
      model.attributes = { "class": "textarea", name: name, rows: "8", maxlength: spec.max || 5000, placeholder: U.text("settings.footer_placeholder") };
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
      var sections = (response.data.sections || []).map(function (section) {
        return { key: section.key, fields: section.fields.map(function (spec) {
          return prepareField(spec);
        }) };
      });
      var form = U.form("/api/settings", "PATCH", T.components.render("settings/editor-form", { sections: sections }));
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
