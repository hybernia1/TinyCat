import "../../ui/images.js";
import "../../ui/fields.js";
import "../../ui/tabs.js";
import "../../core/modules.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.settings = {
    open: function (section) {
      var dialog = T.settings.editor(document.querySelector("[data-settings-editor]"));
      if (!dialog) { return null; }
      var tab = Array.from(dialog.querySelectorAll("[data-tab]")).find(function (item) { return item.dataset.tab === section; });
      if (tab) { T.tabs.activate(tab, false); }
      T.dialog.open(dialog);
      return dialog;
    },
    editor: function (template) {
      var dialog = document.getElementById("settings-editor-modal");
      if (!dialog) {
        var form = template && template.content.querySelector("form");
        if (!form) { return null; }
        dialog = T.ui.sectionedDialog("settings-editor-modal", "settings.title", form, true);
        T.lifecycle.hydrate(dialog);
      }
      return dialog;
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
    },
    hydrate: function (root) {
      Array.from((root || document).querySelectorAll("[data-settings-editor]")).forEach(function (template) {
        if (template.dataset.settingsReady === "true") { return; }
        template.dataset.settingsReady = "true";
        T.settings.editor(template);
      });
    }
  };
  T.modules.register("settings", T.settings);
}());
