// @component-owned: structure is declared in owner catalogs.
import "../../Ui/assets/runtime/modules.js";
import "../../Ui/assets/runtime/component-renderer.js";
import "../../Ui/assets/component-builders.js";
import "./email-availability.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.authentication = {
    modal: function (url, response, id) {
      if (url.pathname !== "/api/users/authentication") { return null; }
      var content = T.components.render("users/auth-content", Object.assign({}, response.data, { modal: true }));
      var heading = content.querySelector("[data-auth-heading]"), title = heading.textContent.trim();
      heading.remove();
      return content.querySelector("form") ? T.ui.formDialog(id, title, content, false, { size: "compact", refresh: false }) : T.ui.dialog(id, title, content);
    }
  };
  T.modules.register("authentication", T.authentication);
}());
