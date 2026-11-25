import "./authentication.js";
// @component-owned: structure is declared in owner catalogs.
import "../../Ui/assets/content-updates.js";
import "../../Ui/assets/runtime/dates.js";
import "../../Ui/assets/runtime/component-renderer.js";
import "../../Ui/assets/runtime/dom.js";
import "../../Ui/assets/runtime/api-client.js";
import "../../Ui/assets/runtime/i18n.js";
import "../../Ui/assets/runtime/lifecycle.js";
import "../../Ui/assets/avatar-rendering.js";
import "../../Ui/assets/dialogs.js";
import "../../Ui/assets/component-builders.js";
import "../../Ui/assets/toasts.js";
import "./controller.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  function accountEditor(data, url, id) {
    var user = data.user, languages = {};
    Object.keys(data.options.languages).forEach(function (key) { var value = data.options.languages[key]; languages[key] = typeof value === "object" ? (value.name || value.label || key) : value; });
    var model = Object.assign({}, data, { dialog_id: id, original_locale: user.locale || "", languages: languages, has_password: Boolean(data.capabilities.password) });
    var account = U.form("/api/users/" + user.id, "PATCH", T.components.render("users/account-form", model));
    var email = U.form(model.has_password ? "/api/users/me/email" : "/api/users/" + user.id, "PATCH", T.components.render("users/email-form", model));
    var password = model.has_password ? U.form("/api/users/me/password", "PUT", T.components.render("users/password-form", model)) : null;
    var content = T.components.render("users/account-editor", Object.assign({}, model, { account_form: account, email_form: email, password_form: password }));
    var focus = url.searchParams.get("focus") || "bio";
    var active = /^(security|password|current_password)$/.test(focus) ? "security" : (focus === "email" ? "email" : (focus === "avatar" ? "avatar" : "account"));
    var dialog = U.sectionedDialog(id, "@" + user.username, content, false, active);
    dialog.dataset.accountEditor = "true";
    U.accountDialogTitle(dialog, user);
    if (focus) { var field = dialog.querySelector('[name="' + (active === "security" ? "current_password" : focus.replace(/[^a-z_]/g, "")) + '"]'); if (field) { field.autofocus = true; } }
    return dialog;
  }
  function avatar(data) {
    var user = data.user, model = data;
    var upload = U.form("/api/users/" + user.id + "/avatar", "PUT", T.components.render("users/avatar-upload", model));
    var remove = user.avatar_custom ? U.form("/api/users/" + user.id + "/avatar", "DELETE", T.components.render("users/avatar-remove", model)) : null;
    var save = T.components.render("users/avatar-save", { form_id: upload.id });
    var actions = T.components.render("users/avatar-actions", { save_action: save, remove_form: remove });
    return T.components.render("users/avatar-editor", { upload_form: upload, actions: actions });
  }
  Object.assign(T.accounts, {
    editorModal: function (url, response, id) {
      var data = response.data, path = url.pathname;
      if (path.indexOf("/api/users/") !== 0) { return null; }
      if (path === "/api/users/authentication") {
        return T.authentication.modal(url, response, id);
      }

      if (/\/avatar$/.test(path)) {
        var avatarContent = avatar(data), avatarActions = avatarContent.querySelector(".avatar-editor-actions");
        avatarActions.remove();
        var avatarDialog = U.dialog(id, "account.avatar_edit", avatarContent, false, avatarActions, { kind: "editor", size: "compact" });
        return avatarDialog;
      }
      if (/\/management$/.test(path)) {
        return accountEditor(data, url, id);
      }
      return null;
    }
  });
}());
