// @component-owned: structure is declared in owner catalogs.
import "../../Ui/assets/runtime/modules.js";
import "../../Ui/assets/component-builders.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.modules.register("updates", {
    modal: function (url, response, id) {
      if (url.pathname !== "/api/updates/release-notes") { return null; }
      var content = T.components.render("ui/code-block", { text: response.data.notes });
      return T.ui.dialog(id, "updates.changelog", content, false);
    }
  });
}());
