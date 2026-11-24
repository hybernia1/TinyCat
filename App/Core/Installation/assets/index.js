import "../../Ui/assets/runtime/lifecycle.js";
import "../../Ui/assets/form-requests.js";
import "../../Ui/assets/toasts.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.lifecycle.register("installation", {
    init: function () {
      document.querySelectorAll("[data-installation-form]").forEach(function (form) {
        T.forms.bind(form, {
          apply: function (data) {
            if (data.redirect) { window.location.assign(T.urls.local(data.redirect)); }
          },
          error: function (error, fields) {
            var data = error && error.data && error.data.data;
            if (data && data.redirect === "/install/recovery") { window.location.assign(T.urls.local(data.redirect)); return; }
            var first = Object.keys(fields)[0];
            T.toasts.show((first && fields[first].join(" ")) || error.message || T.i18n.t("common.request_failed"), "danger");
            var input = first && form.elements.namedItem(first);
            if (input && input.focus) { input.focus(); }
          }
        });
      });
    }
  });
}());
