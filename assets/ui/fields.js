import "../core/components.js";
import "../core/dom.js";
import "../core/i18n.js";
import "../core/icons.js";
import "../core/lifecycle.js";
import "./dialogs.js";
import "./filters.js";
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
    dialog: function (id, title, content, persistent, footer, options) {
      options = options || {};
      var dialog = T.dialog.create({ id: id, title: T.ui.text(title), closeLabel: T.ui.text("common.close"), closeIcon: T.ui.icon("close"), content: content, footer: footer, disposeOnClose: persistent !== true, kind: options.kind, size: options.size, mobile: options.mobile, icon: options.icon });
      return dialog;
    },

    editorActions: function (submit, destructive) {
      return T.components.render("ui/editor-actions", { cancel_label: T.ui.text("common.cancel"), primary: submit, destructive: destructive });
    },

    accountDialogTitle: function (dialog, user) {
      var title = dialog.querySelector('[id="' + dialog.getAttribute("aria-labelledby") + '"]');
      var status = e("span");
      status.appendChild(T.components.render("ui/account-status", { status: user.status, label: T.ui.text("users.statuses." + user.status) }));
      title.classList.add("cluster", "gap-2");
      title.replaceChildren(status, e("span", {}, "@" + user.username));
      return status;
    },

    formDialog: function (id, title, content, persistent, options) {
      var form = content.tagName === "FORM" ? content : content.querySelector("form");
      if (!form) { throw new TypeError("An editor dialog requires a form."); }
      form.dataset.confirmUnsaved = "true";
      var submit = Array.from(form.querySelectorAll("button")).filter(function (button) { return button.getAttribute("type") === "submit"; }).pop();
      if (!submit) { throw new TypeError("An editor dialog requires a submit action."); }
      if (!form.id) { form.id = id + "-form"; }
      submit.setAttribute("form", form.id);
      submit.className = "btn btn-primary";
      if (!submit.textContent.trim()) { submit.appendChild(e("span", {}, T.ui.text("common.save"))); }
      submit.remove();
      return T.ui.dialog(id, title, content, persistent, T.ui.editorActions(submit), Object.assign({}, options, { kind: "editor" }));
    },

    tabbedContent: function (id, sections, selected, title) {
      var names = sections.map(function (section) { return section.name; });
      var initial = names.includes(selected) ? selected : names[0];
      var content = e("div", { "class": "stack stack-gap-16", "data-tabs": "" });
      var navigation = e("div", { "class": "tabs", role: "tablist", "aria-label": title });
      var panels = {}, buttons = {};
      content.appendChild(navigation);
      sections.forEach(function (section) {
        var panelId = id + "-" + section.name;
        var button = T.ui.button(section.label, { "class": "tab cluster gap-2", id: panelId + "-tab", role: "tab", "data-tab": section.name, "aria-controls": panelId, "aria-selected": section.name === initial ? "true" : "false" });
        button.replaceChildren(T.ui.icon(section.icon), e("span", {}, T.ui.text(section.label)));
        navigation.appendChild(button); buttons[section.name] = button;
        var panel = e("div", { "class": "stack stack-gap-16", id: panelId, role: "tabpanel", "data-tab-panel": section.name, "aria-labelledby": panelId + "-tab" });
        panel.hidden = section.name !== initial;
        if (section.content) { panel.appendChild(section.content); }
        content.appendChild(panel); panels[section.name] = panel;
      });
      return { content: content, panels: panels, buttons: buttons, initial: initial };
    },

    sectionedDialog: function (id, title, form, persistent, selected) {
      var fields = form.querySelector("[data-editor-sections]");
      var sections = fields ? Array.from(fields.querySelectorAll("[data-editor-section]")).map(function (section) {
        return { name: section.dataset.editorSection, label: section.dataset.editorLabel, icon: section.dataset.editorIcon, content: section, editable: section.dataset.editorEditable !== "false" };
      }) : [];
      if (sections.length < 2) { return T.ui.adminDialog(id, title, form, persistent); }
      var tabs = T.ui.tabbedContent(id, sections, selected || sections[0].name, T.ui.text(title));
      fields.replaceChildren(tabs.content);
      var separateForms = form.tagName !== "FORM", dialog;
      if (separateForms) {
        var active = sections.find(function (item) { return item.name === tabs.initial; });
        var activeForm = active.content.tagName === "FORM" ? active.content : active.content.querySelector("form");
        var submit = T.ui.button("common.save", { type: "submit", "class": "btn btn-primary", "data-tab-submit": "", form: activeForm ? activeForm.id : null, disabled: activeForm ? null : true });
        dialog = T.ui.editorFrame(T.ui.dialog(id, title, form, persistent, T.ui.editorActions(submit)));
      } else {
        dialog = T.ui.adminDialog(id, title, form, persistent);
      }
      var footer = dialog.querySelector(".modal-footer");
      footer.hidden = !sections.find(function (item) { return item.name === tabs.initial; }).editable;
      tabs.content.addEventListener("tinycat:tab", function (event) {
        var section = sections.find(function (item) { return item.name === event.detail.name; });
        footer.hidden = Boolean(section && !section.editable);
      });
      return dialog;
    },

    adminDialog: function (id, title, content, persistent) {
      var dialog = T.ui.formDialog(id, title, content, persistent);
      return T.ui.editorFrame(dialog);
    },

    editorFrame: function (dialog) {
      dialog.dataset.modalKind = "editor";
      dialog.dataset.modalSize = "workspace";
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
