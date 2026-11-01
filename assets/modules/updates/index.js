import "../../core/modules.js";
import "../../ui/component-builders.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.modules.register("updates", {
    modal: function (url, response, id) {
      if (url.pathname !== "/api/updates/release-notes") { return null; }
      var content = T.dom.element("pre", { "class": "code-block" });
      content.appendChild(T.dom.element("code", {}, response.data.notes));
      return T.ui.dialog(id, "updates.changelog", content, false);
    }
  });
}());
