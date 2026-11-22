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
      var data = response.data, register = data.mode === "register";
      var content = T.components.render("users/auth-content", Object.assign({}, data, { register: register, old: {} }));
      var title = register ? "common.register" : "common.login";
      return content.querySelector("form") ? T.ui.formDialog(id, title, content, false, { size: "compact", icon: register ? "user-plus" : "login" }) : T.ui.dialog(id, title, content);
    }
  };
  T.modules.register("authentication", T.authentication);
}());
