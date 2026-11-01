import "../../ui/list-navigation.js";
import "../../ui/content-updates.js";
import "../../core/dates.js";
import "../../core/component-renderer.js";
import "../../core/dom.js";
import "../../core/api-client.js";
import "../../core/i18n.js";
import "../../core/lifecycle.js";
import "../../ui/avatar-rendering.js";
import "../../ui/dialogs.js";
import "../../ui/component-builders.js";
import "../../ui/toasts.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui, e = T.dom.element;
  function addSubmit(form, label) { form.appendChild(U.button(label || "common.save", { type: "submit", "class": "btn btn-primary" })); }
  function check(form, name, label, checked) {
    var root = e("label", { "class": "check-line" });
    var input = e("input", { type: "checkbox", name: name, value: "1" });
    input.checked = Boolean(checked);
    root.append(input, e("span", {}, U.text(label))); form.appendChild(root);
  }
  function accountEditor(data, url, id) {
    var user = data.user, content = e("div"), sections = e("div", { "data-editor-sections": "" });
    content.appendChild(sections);
    var focus = url.searchParams.get("focus") || "bio";
    var active = /^(security|password|current_password)$/.test(focus) ? "security" : (focus === "email" ? "email" : (focus === "avatar" ? "avatar" : "account"));
    function panel(form, key, label, icon) {
      form.id = id + "-" + key + "-form";
      form.dataset.editorSection = key; form.dataset.editorLabel = label; form.dataset.editorIcon = icon;
      sections.appendChild(form);
    }
    var form = U.form("/api/users/" + user.id, "PATCH");
    form.dataset.originalLocale = user.locale || "";
    var grid = e("div", { "class": "grid sm:grid-2" });
    grid.appendChild(U.field("common.username", "username", user.username, "text", { disabled: true }));
    var languages = {};
    Object.keys(data.options.languages).forEach(function (key) { var value = data.options.languages[key]; languages[key] = typeof value === "object" ? (value.name || value.label || key) : value; });
    grid.appendChild(U.select("common.language", "locale", user.locale, languages));
    grid.appendChild(U.select("account.theme", "theme", user.theme, { system: U.text("account.theme_system"), light: U.text("account.theme_light"), dark: U.text("account.theme_dark") }));
    var bio = U.field("account.bio", "bio", user.bio, "textarea", { maxlength: "500", rows: "6" }); bio.classList.add("grid-span-full"); grid.appendChild(bio); form.appendChild(grid);
    panel(form, "account", "common.account", "user");
    var email = U.form(data.capabilities.password ? "/api/users/me/email" : "/api/users/" + user.id, "PATCH");
    if (!data.capabilities.password) { ["bio", "locale", "theme"].forEach(function (name) { email.appendChild(U.hidden(name, user[name] || "")); }); }
    email.appendChild(U.field("common.email", "email", user.email, "email", { maxlength: "254", autocomplete: "email", "data-email-check-url": "/api/users/" + user.id + "/email-checks" }));
    email.appendChild(e("span", { "class": "help" }, U.text("account.email_optional")));
    email.appendChild(U.hidden("email_notifications", "0")); check(email, "email_notifications", "account.email_notifications", user.email_notifications);
    panel(email, "email", "common.email", "mail");
    if (data.capabilities.password) {
      var password = U.form("/api/users/me/password", "PUT");
      password.dataset.reset = "true";
      password.appendChild(U.field("common.current_password", "current_password", "", "password", { required: true, autocomplete: "current-password" }));
      password.appendChild(U.field("common.password", "password", "", "password", { required: true, minlength: "8", maxlength: String(data.options.password_max_length), autocomplete: "new-password" }));
      password.appendChild(U.field("common.password_confirm", "password_confirm", "", "password", { required: true, minlength: "8", autocomplete: "new-password" }));
      panel(password, "security", "account.security_settings", "lock");
    }
    var avatarSection = e("div", { "data-editor-section": "avatar", "data-editor-label": "account.avatar", "data-editor-icon": "user", "data-editor-editable": "false" });
    avatarSection.appendChild(T.components.render("users/avatar-control", { user: user })); sections.appendChild(avatarSection);
    var dialog = U.sectionedDialog(id, "@" + user.username, content, false, active);
    dialog.dataset.accountEditor = "true";
    U.accountDialogTitle(dialog, user);
    if (focus) { var field = dialog.querySelector('[name="' + (active === "security" ? "current_password" : focus.replace(/[^a-z_]/g, "")) + '"]'); if (field) { field.autofocus = true; } }
    return dialog;
  }

  function avatar(data) {
    var user = data.user, content = e("div", { "class": "avatar-editor" });
    var form = U.form("/api/users/" + user.id + "/avatar", "PUT");
    form.id = "avatar-upload-form-" + user.id;
    form.dataset.modalCloseOnSuccess = "true";
    form.enctype = "multipart/form-data";
    var upload = e("div", { "class": "avatar-upload", "data-avatar-upload": "" }), preview = e("div", { "class": "avatar avatar-xl avatar-upload-preview", "aria-label": U.text("account.avatar_preview") });
    preview.appendChild(e("img", { src: user.avatar_url || null, alt: user.username, "data-avatar-upload-preview": "", hidden: !user.avatar_url }));
    var empty = e("span", { "class": "avatar-upload-empty", "data-avatar-upload-empty": "", hidden: Boolean(user.avatar_url) }); empty.appendChild(U.icon("user")); preview.appendChild(empty);
    var previewGroup = e("div", { "class": "avatar-editor-preview" });
    previewGroup.appendChild(preview); upload.appendChild(previewGroup);
    if (user.avatar_custom === false) {
      var creatorToggle = U.button("account.avatar_creator", { "class": "btn btn-icon avatar-creator-toggle", "aria-label": U.text("account.avatar_creator"), title: U.text("account.avatar_creator"), "aria-expanded": "false", "aria-controls": "avatar-appearance-form-" + user.id, "data-avatar-creator-toggle": "" });
      creatorToggle.replaceChildren(U.icon("chevron-right")); previewGroup.appendChild(creatorToggle);
    }
    var controls = e("div", { "class": "avatar-editor-controls" });
    controls.appendChild(e("small", { "class": "avatar-upload-empty-note", "data-avatar-upload-empty-note": "", hidden: Boolean(user.avatar_url) }, U.text("account.avatar_upload_empty")));
    var drop = e("label", { "class": "avatar-upload-drop" });
    drop.append(e("input", { "class": "sr-only", type: "file", name: "avatar", required: true, accept: "image/jpeg,image/png,image/webp", "aria-label": U.text("account.avatar_upload_label"), "data-avatar-upload-input": "", "data-client-image-max-dimension": "400", "data-client-image-max-bytes": "26214400" }), U.icon("upload"), e("span", {}, U.text("account.avatar_upload_label")));
    controls.append(drop, e("small", { "class": "text-muted" }, "JPG, PNG, WebP"));
    upload.appendChild(controls); form.appendChild(upload); content.appendChild(form);
    var appearanceForm = null;
    if (user.avatar_custom === false) {
      appearanceForm = U.form("/api/users/" + user.id + "/avatar", "PATCH");
      appearanceForm.id = "avatar-appearance-form-" + user.id;
      appearanceForm.classList.add("avatar-editor-appearance");
      appearanceForm.dataset.avatarAppearanceForm = "";
      appearanceForm.dataset.modalCloseOnSuccess = "true";
      appearanceForm.hidden = true;
      var settings = { version: 1 };
      Object.keys(data.appearance_options || {}).forEach(function (key) {
        var option = data.appearance_options[key], choices = {};
        option.values.forEach(function (value) { var choiceLabel = "account.avatar_" + (key === "gender" ? "gender_" : "") + value; choices[value] = U.text(choiceLabel); });
        settings[key] = user.avatar_appearance && user.avatar_appearance[key] || option.default;
        var fieldLabel = "account.avatar_" + key;
        var field = U.select(fieldLabel, "avatar_" + key, settings[key], choices);
        var select = field.querySelector("select");
        select.dataset.avatarParameter = key; select.dataset.avatarDefault = option.default;
        if (key === "gender") { select.dataset.avatarGender = ""; }
        select.removeAttribute("name"); appearanceForm.appendChild(field);
      });
      appearanceForm.dataset.originalAppearance = JSON.stringify(settings);
      appearanceForm.appendChild(U.hidden("appearance", JSON.stringify(settings)));
      content.appendChild(appearanceForm);
    }
    var remove = null;
    if (user.avatar_url && user.avatar_custom !== false) {
      remove = U.form("/api/users/" + user.id + "/avatar", "DELETE");
      remove.dataset.modalCloseOnSuccess = "true";
      remove.dataset.confirm = U.text("account.remove_avatar_confirm");
      remove.dataset.confirmTitle = U.text("account.remove_avatar_title");
      remove.dataset.confirmVariant = "danger";
      var removeButton = U.button("account.remove_avatar", { type: "submit", "class": "btn avatar-editor-remove" });
      removeButton.replaceChildren(U.icon("trash"), e("span", {}, U.text("account.remove_avatar"))); remove.appendChild(removeButton);
    }
    var footer = U.editorActions(U.button("account.save_avatar", { type: "submit", "class": "btn btn-primary", form: appearanceForm ? appearanceForm.id : form.id, "data-avatar-save": "", disabled: true }), remove);
    footer.classList.add("avatar-editor-actions");
    content.appendChild(footer);
    return content;
  }
  function authentication(data) {
    return T.components.render("users/auth-content", Object.assign({}, data, { register: data.mode === "register", old: {} }));
  }
  function following(data, pagination) {
    var root = e("div", { "class": "stack" }), grid = e("div", { "class": "following-modal-grid" });
    grid.appendChild(T.users.profiles(data.items)); root.appendChild(grid);
    if (pagination.next_url) { root.appendChild(T.components.render("ui/load-more", { attributes: { "class": "load-more" }, button_attributes: { type: "button", "class": "btn btn-secondary", "data-following-more": "", "data-following-url": pagination.next_url }, label: U.text("notifications.load_more"), loading_label: U.text("notifications.loading") })); }
    return root;
  }
  function adminForm(user, options, create) {
    var form = U.form(create ? "/api/users" : "/api/users/" + user.id, create ? "POST" : "PATCH");
    form.dataset.adminUserForm = "true";
    form.dataset.modalCloseOnSuccess = "true";
    if (!create) { form.appendChild(U.hidden("scope", "administration")); }
    form.appendChild(T.components.render("users/admin-fields", { user: user, create: create, protected: Boolean(user.protected), options: Object.assign({}, options.options, options) }));
    addSubmit(form, create ? "common.create" : "common.save"); return form;
  }
  T.users = {
    profiles: function (items) {
      var fragment = document.createDocumentFragment();
      (items || []).forEach(function (user) { fragment.appendChild(T.components.render("users/following-profile", { user: user })); });
      return fragment;
    },
    modal: function (url, response, id) {
      var data = response.data, path = url.pathname;
      if (path.indexOf("/api/users/") !== 0) { return null; }
      if (path === "/api/users/creation") { return U.sectionedDialog(id, "users.new_user", adminForm({}, data, true), false); }
      if (path === "/api/users/authentication") {
        var authContent = authentication(data), authTitle = data.mode === "register" ? "common.register" : "common.login";
        return authContent.querySelector("form") ? U.formDialog(id, authTitle, authContent, false, { size: "compact", icon: data.mode === "register" ? "user-plus" : "login" }) : U.dialog(id, authTitle, authContent);
      }
      if (/\/following$/.test(path)) { return U.dialog(id, "public.following", following(data, U.pagination(response))); }
      if (/\/avatar$/.test(path)) {
        var avatarContent = avatar(data), avatarActions = avatarContent.querySelector(".avatar-editor-actions");
        avatarActions.remove();
        var avatarDialog = U.dialog(id, "account.avatar_edit", avatarContent, false, avatarActions, { kind: "editor", size: "compact" });
        return avatarDialog;
      }
      if (/\/management$/.test(path)) {
        if (url.searchParams.get("panel") === "administration") {
          var adminDialog = U.sectionedDialog(id, data.user.username, adminForm(data.user, Object.assign({}, data.administration, { options: data.options }), false));
          U.accountDialogTitle(adminDialog, data.user);
          return adminDialog;
        }
        return accountEditor(data, url, id);
      }
      return null;
    },
    follow: function (form, data) {
      form.elements.namedItem("action").value = data.following ? "unfollow" : "follow";
      var button = form.querySelector("button");
      button.replaceChildren(U.icon(data.following ? "check" : "plus"), e("span", {}, U.text(data.following ? "public.unfollow" : "public.follow")));
      button.classList.toggle("btn-primary", !data.following); button.classList.toggle("btn-secondary", data.following);
    },
    list: function (target, response) {
      var data = response.data, pagination = U.pagination(response);
      var model = Object.assign({}, data, { pagination: pagination });
      var root = T.components.render("users/list", Object.assign({}, model, { state: model }));
      T.content.replaceChildren(target, root); root.dataset.usersReady = "true";
      T.users.filters(data, pagination);
    },
    filters: function (data, pagination) {
      var filterForm = T.components.render("users/filter-form", Object.assign({}, data, { pagination: pagination }));
      filterForm.querySelectorAll("input, select, textarea").forEach(function (control) {
        control.defaultValue = control.value; control.defaultChecked = control.checked;
        if (control.options) { Array.from(control.options).forEach(function (option) { option.defaultSelected = option.selected; }); }
      });
      U.filterPanel("users-filter-modal", filterForm);
    },
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.dataset.url || source.getAttribute("href") || "/", window.location.href);
      if (!/^\/api\/users(?:\/|$)/.test(url.pathname)) { return false; }
      var data = response.data;
      if (url.pathname === "/api/users" && target && T.lists.isRead(source)) { T.users.list(target, response); return [target]; }
      if (target && target.id === "users-list" || source.dataset.adminUserForm) {
        var list = document.getElementById("users-list");
        T.lists.refresh(list);
        return [];
      }
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
      return true;
    }
  };
  T.users.hydrate = function (root) {
    if (T.users && T.users.filters) {
      Array.from((root || document).querySelectorAll("[data-users-view]")).forEach(function (view) {
        if (view.dataset.usersReady === "true") { return; }
        view.dataset.usersReady = "true";
        var state = JSON.parse(view.dataset.usersState);
        T.users.filters(state, state.pagination || {});
      });
    }
  };
}());
