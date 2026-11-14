import "../../core/modules.js";
import "../../ui/component-builders.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  T.modules.register("module-packages", {
    modal: function (url, response, id) {
      if (url.pathname !== "/api/modules/packages/creation") { return null; }
      var form = U.form("/api/modules/packages", "POST");
      form.enctype = "multipart/form-data";
      form.dataset.confirm = U.text("modules.packages.confirm");
      form.appendChild(U.field("modules.packages.file", "package", "", "file", { accept: ".zip,application/zip", required: true }));
      form.appendChild(T.dom.element("p", { "class": "help m-0" }, U.text("modules.packages.help")));
      form.appendChild(U.button("modules.packages.install", { type: "submit", disabled: !response.data.permissions.install }));
      return U.adminDialog(id, "modules.packages.upload", form, false);
    }
  });
}());
