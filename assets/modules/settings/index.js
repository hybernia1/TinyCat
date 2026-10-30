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
        form.addEventListener("tinycat:success", function () {
          var selected = Array.from(dialog.querySelectorAll("[data-tab]")).find(function (tab) { return tab.getAttribute("aria-selected") === "true"; });
          try { window.sessionStorage.setItem("tinycat.settings.section", selected ? selected.dataset.tab : ""); } catch (_) {}
          window.location.reload();
        });
        T.lifecycle.hydrate(dialog);
      }
      return dialog;
    },
    hydrate: function (root) {
      Array.from((root || document).querySelectorAll("[data-settings-editor]")).forEach(function (template) {
        if (template.dataset.settingsReady === "true") { return; }
        template.dataset.settingsReady = "true";
        T.settings.editor(template);
        var section = null;
        try { section = window.sessionStorage.getItem("tinycat.settings.section"); window.sessionStorage.removeItem("tinycat.settings.section"); } catch (_) {}
        if (section !== null) { T.settings.open(section); }
      });
    }
  };
  T.modules.register("settings", T.settings);
}());
