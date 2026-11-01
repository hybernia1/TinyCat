import "../../ui/image-uploads.js";
import "../../ui/component-builders.js";
import "../../core/modules.js";
import "./field-presentation.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui, e = T.dom.element;
  function field(spec, name) {
    name = name || "settings[" + spec.key + "]";
    var grouped = ["email_smtp", "email_templates", "site_image"].includes(spec.type);
    var root = e(grouped ? "div" : "label", { "class": "field min-w-0" + (spec.span ? " grid-span-full" : "") });
    root.appendChild(e("span", { "class": "label" }, spec.label));
    var value = spec.value == null ? "" : spec.value, type = spec.type;
    if (type === "bool") {
      root.appendChild(U.hidden(name, "0"));
      var check = e("span", { "class": "check-line" });
      check.append(e("input", { type: "checkbox", name: name, value: "1", checked: Boolean(value) }), e("span", {}, U.text("settings.enabled")));
      root.appendChild(check);
    } else if (type === "email_smtp") {
      var grid = e("div", { "class": "grid sm:grid-2" }), smtp = value || {};
      [["host", "text", "", 190], ["port", "int", 587], ["username", "text", "", 190], ["password", "password", "", 190], ["encryption", "text", "tls", 20], ["from_address", "email", "", 190], ["from_name", "text", "TinyCat", 120]].forEach(function (item) {
        var key = item[0], label = key === "from_address" ? "email_from" : key === "from_name" ? "email_from_name" : "smtp_" + key;
        grid.appendChild(field({ type: item[1], label: U.text("settings.fields." + label), value: key === "password" ? "" : smtp[key] == null ? item[2] : smtp[key], max: key === "port" ? 65535 : item[3], min: 1, optional: key !== "port" }, name + "[" + key + "]"));
      });
      root.appendChild(grid);
    } else if (type === "email_templates") {
      root.appendChild(U.hidden(name + "[_present]", "1"));
      var list = e("div", { "class": "stack gap-2" });
      (spec.options || []).forEach(function (option) {
        var label = e("label", { "class": "check-line" });
        label.append(e("input", { type: "checkbox", name: name + "[" + option.value + "]", value: "1", checked: Boolean(value[option.value]) }), e("span", {}, option.value.replace(/_/g, " ").replace(/\b\w/g, function (letter) { return letter.toUpperCase(); })));
        list.appendChild(label);
      });
      root.appendChild(list);
    } else if (type === "site_image") {
      root.appendChild(U.hidden(name, value));
      var preview = e("div", { "class": "content-image-preview image-preview-contain", "data-empty": value ? null : "true" });
      if (value) { preview.appendChild(e("img", { src: value, alt: "", loading: "lazy" })); }
      else { var empty = e("span", { "class": "content-image-preview-empty" }); empty.append(U.icon("image"), e("span", {}, U.text("settings.image_empty"))); preview.appendChild(empty); }
      var actions = e("div", { "class": "content-image-actions" }), upload = e("label", { "class": "btn btn-secondary btn-sm" });
      upload.append(U.icon("upload"), e("span", {}, U.text("settings.image_upload")), e("input", { "class": "sr-only", type: "file", name: "settings_files[" + spec.key + "]", accept: "image/jpeg,image/png,image/gif,image/webp" }));
      actions.appendChild(upload);
      if (value) { var remove = e("label", { "class": "check-line mb-0" }); remove.append(e("input", { type: "checkbox", name: "settings_remove[" + spec.key + "]", value: "1" }), e("span", {}, U.text("settings.image_remove"))); actions.appendChild(remove); }
      root.append(preview, actions);
    } else if (["select", "language", "timezone", "date_format", "time_format", "datetime_format"].includes(type)) {
      var select = e("select", { "class": "select", name: name, required: type !== "language" });
      (spec.options || []).forEach(function (option) {
        var text = option.name || option.value;
        if (type === "language") { text = option.value.toUpperCase() + " - " + text; }
        if (type === "timezone") { text = "(UTC" + (option.offset === "+00:00" ? "" : option.offset) + ") " + option.value; }
        if (T.settingsPresentation.examples[option.value]) { text = option.value + " - " + T.settingsPresentation.examples[option.value]; }
        select.appendChild(e("option", { value: option.value, selected: String(value) === String(option.value) }, text));
      });
      root.appendChild(select);
    } else if (type === "textarea") {
      root.appendChild(e("textarea", { "class": "textarea", name: name, rows: "8", maxlength: spec.max || 5000, placeholder: spec.placeholder || U.text("settings.footer_placeholder") }, String(value)));
    } else {
      var inputType = { int: "number", email: "email", site_url: "url", password: "password" }[type] || "text";
      root.appendChild(e("input", { "class": "input", name: name, type: inputType, value: type === "password" ? "" : value,
        required: !spec.optional && (type === "text" || type === "int"), maxlength: type === "int" ? null : spec.max || (type === "site_url" ? 2048 : 190),
        min: type === "int" ? spec.min || 0 : null, max: type === "int" ? spec.max || 2147483647 : null,
        autocomplete: type === "password" ? "new-password" : type === "site_url" ? "url" : null, placeholder: type === "site_url" ? "https://example.com" : null }));
    }
    if (spec.help) { root.appendChild(e("span", { "class": "help" }, spec.help)); }
    return root;
  }
  T.settings = {
    open: function (section) {
      return T.dialog.openResource("modal-settings-editor", "/api/settings/editor", { tab: section });
    },
    modal: function (url, response, id) {
      if (url.pathname !== "/api/settings/editor") { return null; }
      var form = U.form("/api/settings", "PATCH"), sections = e("div", { "class": "stack stack-gap-16", "data-editor-sections": "" });
      form.setAttribute("enctype", "multipart/form-data");
      (response.data.sections || []).forEach(function (section) {
        var view = T.settingsPresentation.sections[section.key];
        var panel = e("div", { "class": "grid sm:grid-2", "data-editor-section": section.key, "data-editor-label": U.text(view.label), "data-editor-icon": view.icon });
        section.fields.forEach(function (spec) {
          var display = Object.assign({}, T.settingsPresentation.fields[spec.key]);
          ["label", "help", "placeholder"].forEach(function (key) { if (display[key]) display[key] = U.text(display[key]); });
          panel.appendChild(field(Object.assign({}, spec, display)));
        });
        sections.appendChild(panel);
      });
      form.append(sections, U.button("common.save", { type: "submit", "class": "btn btn-primary" }));
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
