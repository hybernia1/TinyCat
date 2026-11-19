// @component-owned: structure is declared in owner catalogs.
import "./index.js";
import "../../../../assets/ui/list-navigation.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui, baseResult = T.accounts.result, baseModal = T.accounts.modal;
  function adminForm(user, options, create) {
    var fields = { user: user, create: create, protected: Boolean(user.protected), options: Object.assign({}, options.options, options) };
    return U.form(create ? "/api/users" : "/api/users/" + user.id, create ? "POST" : "PATCH", T.components.render("users/admin-form", { create: create, fields: fields }));
  }
  T.accounts.modal = function (url, response, id) {
    var data = response.data;
    if (url.pathname === "/api/users/creation") { return U.sectionedDialog(id, "users.new_user", adminForm({}, data, true), false); }
    if (/^\/api\/users\/[1-9][0-9]*\/management$/.test(url.pathname) && url.searchParams.get("panel") === "administration") {
      var dialog = U.sectionedDialog(id, data.user.username, adminForm(data.user, Object.assign({}, data.administration, { options: data.options }), false));
      U.accountDialogTitle(dialog, data.user);
      return dialog;
    }
    return baseModal(url, response, id);
  };
  T.accounts.result = function (source, response, target) {
    var definition = (T.adminDefinitions || {})["core.users"];
    if (definition && target && document.querySelector(definition.target) === target) {
      var result = T.lists.result(definition.target, source, response, target);
      if (result !== false) { return result; }
    }
    return baseResult(source, response, target);
  };
}());
