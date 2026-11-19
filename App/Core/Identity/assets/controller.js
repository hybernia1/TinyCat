// @component-owned: structure is declared in owner catalogs.
import "../../../../assets/ui/content-updates.js";
import "../../../../assets/core/dates.js";
import "../../../../assets/core/component-renderer.js";
import "../../../../assets/core/dom.js";
import "../../../../assets/core/api-client.js";
import "../../../../assets/core/i18n.js";
import "../../../../assets/core/lifecycle.js";
import "../../../../assets/ui/avatar-rendering.js";
import "../../../../assets/ui/dialogs.js";
import "../../../../assets/ui/component-builders.js";
import "../../../../assets/ui/toasts.js";
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
    var user = data.user, generated = user.avatar_custom === false, appearance = { version: 1 };
    var parameters = generated ? Object.keys(data.appearance_options || {}).map(function (key) {
      var option = data.appearance_options[key], choices = {};
      option.values.forEach(function (value) { var choiceLabel = "account.avatar_" + (key === "gender" ? "gender_" : "") + value; choices[value] = U.text(choiceLabel); });
      appearance[key] = user.avatar_appearance && user.avatar_appearance[key] || option.default;
      var fieldLabel = "account.avatar_" + key;
      return { key: key, label: U.text(fieldLabel), value: appearance[key], choices: choices, default: option.default };
    }) : [];
    var model = Object.assign({}, data, { generated: generated, appearance: appearance, parameters: parameters });
    var upload = U.form("/api/users/" + user.id + "/avatar", "PUT", T.components.render("users/avatar-upload", model));
    var creator = generated ? U.form("/api/users/" + user.id + "/avatar", "PATCH", T.components.render("users/avatar-appearance", model)) : null;
    var remove = user.avatar_url && !generated ? U.form("/api/users/" + user.id + "/avatar", "DELETE", T.components.render("users/avatar-remove", model)) : null;
    var save = T.components.render("users/avatar-save", { form_id: creator ? creator.id : upload.id });
    var actions = T.components.render("users/avatar-actions", { save_action: save, remove_form: remove });
    return T.components.render("users/avatar-editor", { generated: generated, upload_form: upload, appearance_form: creator, actions: actions });
  }
  function authentication(data) {
    return T.components.render("users/auth-content", Object.assign({}, data, { register: data.mode === "register", old: {} }));
  }

  T.accounts = {

    modal: function (url, response, id) {
      var data = response.data, path = url.pathname;
      if (path.indexOf("/api/users/") !== 0) { return null; }
      if (path === "/api/users/authentication") {
        var authContent = authentication(data), authTitle = data.mode === "register" ? "common.register" : "common.login";
        return authContent.querySelector("form") ? U.formDialog(id, authTitle, authContent, false, { size: "compact", icon: data.mode === "register" ? "user-plus" : "login" }) : U.dialog(id, authTitle, authContent);
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
    },

    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.dataset.url || source.getAttribute("href") || "/", window.location.href);
      if (!/^\/api\/users(?:\/|$)/.test(url.pathname)) { return false; }
      var data = response.data;
      if (data.user) {
        if (Object.prototype.hasOwnProperty.call(data.user, "bio")) {
          document.querySelectorAll('[data-author-id="' + Number(data.user.id) + '"][data-author-bio]').forEach(function (node) { node.textContent = data.user.bio || ""; node.classList.add("is-resource-text"); });
        }
        var roots = new Set();
        document.querySelectorAll('[data-avatar-user-id="' + Number(data.user.id) + '"]').forEach(function (node) { roots.add(node.tagName === "IMG" || node.classList.contains("avatar-fallback") ? node.parentElement : node); });
        roots.forEach(function (root) { root.dataset.avatarUserId = data.user.id; root.querySelectorAll("[data-user-avatar-image], [data-user-avatar-fallback]").forEach(function (node) { node.remove(); }); var avatar = U.avatar(data.user); while (avatar.firstChild) { root.appendChild(avatar.firstChild); } });
        if (Number(document.body.dataset.currentUserId) === Number(data.user.id) && data.user.theme) {
          if (data.user.theme === "system") { document.documentElement.removeAttribute("data-theme"); }
          else { document.documentElement.setAttribute("data-theme", data.user.theme); }
        }
        var modal = source.closest(".modal");
        if (modal && modal.dataset.accountEditor !== "true") { T.dialog.close(modal); }
        var localeChanged = source.dataset.originalLocale !== undefined && data.user.locale !== source.dataset.originalLocale;
        if (Number(document.body.dataset.currentUserId) === Number(data.user.id) && data.user.locale && data.user.locale !== T.i18n.locale() && (!T.urls.prefix() || localeChanged)) {
          window.setTimeout(function () {
            Promise.resolve(T.navigation.canLeave()).then(function (allowed) {
              if (!allowed) { return; }
              if (T.urls.prefix()) { window.location.assign(T.urls.local(window.location.pathname + window.location.search, data.user.locale)); }
              else { window.location.reload(); }
            });
          }, 0);
        }
      }
      return Array.from(roots || []);
    }
  };
}());
