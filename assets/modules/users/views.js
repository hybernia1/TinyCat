import "../../core/dates.js";
import "../../core/components.js";
import "../../core/dom.js";
import "../../core/http.js";
import "../../core/i18n.js";
import "../../core/lifecycle.js";
import "../../ui/avatars.js";
import "../../ui/dialogs.js";
import "../../ui/fields.js";
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
  function profile(data, url) {
    var user = data.user, content = e("div", { "class": "stack", "data-tabs": "" });
    var focus = url.searchParams.get("focus") || "bio";
    var active = /^(security|password|current_password)$/.test(focus) ? "security" : (focus === "email" ? "email" : "profile");
    var tabs = e("div", { "class": "tabs profile-edit-tabs", role: "tablist", "aria-label": U.text("account.profile_settings") });
    content.appendChild(tabs);
    function panel(form, key, label) {
      var panelId = "profile-" + user.id + "-" + key, tabId = panelId + "-tab";
      tabs.appendChild(U.button(label, { "class": "tab", id: tabId, role: "tab", "data-tab": key, "aria-controls": panelId, "aria-selected": key === active ? "true" : "false" }));
      form.id = panelId; form.classList.add("tab-panel"); form.classList.add("profile-edit-form");
      form.setAttribute("role", "tabpanel"); form.setAttribute("aria-labelledby", tabId); form.setAttribute("data-tab-panel", key); form.hidden = key !== active;
      addSubmit(form); content.appendChild(form);
    }
    var form = U.form("/api/users/" + user.id, "PATCH");
    var grid = e("div", { "class": "profile-modal-grid" });
    var languages = {};
    Object.keys(data.options.languages).forEach(function (key) { var value = data.options.languages[key]; languages[key] = typeof value === "object" ? (value.name || value.label || key) : value; });
    grid.appendChild(U.select("common.language", "locale", user.locale, languages));
    grid.appendChild(U.select("account.theme", "theme", user.theme, { system: U.text("account.theme_system"), light: U.text("account.theme_light"), dark: U.text("account.theme_dark") }));
    var bio = U.field("account.bio", "bio", user.bio, "textarea", { maxlength: "500", rows: "6" }); bio.classList.add("profile-modal-span"); grid.appendChild(bio); form.appendChild(grid);
    panel(form, "profile", "account.profile_settings");
    var email = U.form(data.capabilities.password ? "/api/users/me/email" : "/api/users/" + user.id, "PATCH");
    if (!data.capabilities.password) { ["bio", "locale", "theme"].forEach(function (name) { email.appendChild(U.hidden(name, user[name] || "")); }); }
    email.appendChild(U.field("common.email", "email", user.email, "email", { maxlength: "254", autocomplete: "email" }));
    email.appendChild(e("span", { "class": "help" }, U.text("account.email_optional")));
    email.appendChild(U.hidden("email_notifications", "0")); check(email, "email_notifications", "account.email_notifications", user.email_notifications);
    panel(email, "email", "common.email");
    if (data.capabilities.password) {
      var password = U.form("/api/users/me/password", "PUT");
      password.dataset.modalCloseOnSuccess = "true";
      password.appendChild(U.field("common.current_password", "current_password", "", "password", { required: true, autocomplete: "current-password" }));
      password.appendChild(U.field("common.password", "password", "", "password", { required: true, minlength: "8", maxlength: String(data.options.password_max_length), autocomplete: "new-password" }));
      password.appendChild(U.field("common.password_confirm", "password_confirm", "", "password", { required: true, minlength: "8", autocomplete: "new-password" }));
      panel(password, "security", "account.security_settings");
    }
    if (focus) { var field = content.querySelector('[name="' + (active === "security" ? "current_password" : focus.replace(/[^a-z_]/g, "")) + '"]'); if (field) { field.autofocus = true; } }
    return content;
  }
  function avatar(data) {
    var user = data.user, content = e("div", { "class": "stack" });
    var form = U.form("/api/users/" + user.id + "/avatar", "PUT");
    form.enctype = "multipart/form-data";
    var upload = e("div", { "class": "avatar-upload", "data-avatar-upload": "" }), preview = e("div", { "class": "avatar avatar-xl avatar-upload-preview", "aria-label": U.text("account.avatar_preview") });
    preview.appendChild(e("img", { src: user.avatar_url || null, alt: user.username, "data-avatar-upload-preview": "", hidden: !user.avatar_url }));
    var empty = e("span", { "class": "avatar-upload-empty", "data-avatar-upload-empty": "", hidden: Boolean(user.avatar_url) }); empty.appendChild(U.icon("user")); preview.appendChild(empty); upload.appendChild(preview);
    upload.appendChild(e("small", { "class": "avatar-upload-empty-note", "data-avatar-upload-empty-note": "", hidden: Boolean(user.avatar_url) }, U.text("account.avatar_upload_empty")));
    var drop = e("label", { "class": "avatar-upload-drop" });
    drop.append(e("input", { "class": "sr-only", type: "file", name: "avatar", required: true, accept: "image/jpeg,image/png,image/webp", "aria-label": U.text("account.avatar_upload_label"), "data-avatar-upload-input": "", "data-client-image-max-dimension": "400", "data-client-image-max-bytes": "26214400" }), U.icon("upload"), e("span", {}, U.text("account.avatar_upload_label")));
    upload.appendChild(drop); form.appendChild(upload); addSubmit(form, "account.save_avatar"); content.appendChild(form);
    if (user.avatar_url) { var remove = U.form("/api/users/" + user.id + "/avatar", "DELETE"); addSubmit(remove, "account.remove_avatar"); content.appendChild(remove); }
    return content;
  }
  function authentication(data) {
    return T.components.render("users/auth-content", Object.assign({}, data, { register: data.mode === "register", old: {} }));
  }
  function following(data, pagination) {
    var root = e("div", { "class": "stack" }), grid = e("div", { "class": "following-modal-grid" });
    grid.appendChild(T.users.profiles(data.items)); root.appendChild(grid);
    if (pagination.next_url) { root.appendChild(U.button("notifications.load_more", { "data-following-more": "", "data-following-url": pagination.next_url })); }
    return root;
  }
  function adminForm(user, options, create) {
    var form = U.form(create ? "/api/users" : "/api/users/" + user.id, create ? "POST" : "PATCH");
    form.dataset.adminUserForm = "true";
    form.dataset.modalCloseOnSuccess = "true";
    if (!create) { form.appendChild(U.hidden("scope", "administration")); }
    form.enctype = "multipart/form-data";
    form.appendChild(T.components.render("users/admin-fields", { user: user, create: create, protected: Boolean(user.protected), options: Object.assign({}, options.options, options) }));
    addSubmit(form, create ? "common.create" : "common.save"); return form;
  }
  function adminDialog(id, title, content, persistent) {
    var dialog = U.dialog(id, title, content, persistent);
    dialog.classList.add("modal-fullscreen");
    dialog.querySelector(".modal-panel").classList.add("modal-panel-full");
    dialog.querySelector(".modal-panel").classList.add("user-modal-panel");
    return dialog;
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
      if (path === "/api/users/authentication") { return U.dialog(id, data.mode === "register" ? "common.register" : "common.login", authentication(data)); }
      if (/\/following$/.test(path)) { return U.dialog(id, "public.following", following(data, U.pagination(response))); }
      if (/\/avatar$/.test(path)) { var avatarDialog = U.dialog(id, "account.avatar_edit", avatar(data)); avatarDialog.querySelector(".modal-panel").classList.add("avatar-edit-modal-panel"); return avatarDialog; }
      if (/\/management$/.test(path)) {
        if (url.searchParams.get("panel") === "administration") { return adminDialog(id, U.text("users.edit_user", { username: data.user.username }), adminForm(data.user, Object.assign({}, data.administration, { options: data.options }), false)); }
        var profileDialog = U.dialog(id, "account.profile_settings", profile(data, url)); profileDialog.classList.add("modal-form"); profileDialog.querySelector(".modal-panel").classList.add("modal-panel-lg"); return profileDialog;
      }
      return null;
    },
    follow: function (form, data) {
      form.elements.namedItem("action").value = data.following ? "unfollow" : "follow";
      var button = form.querySelector("button"); button.textContent = U.text(data.following ? "public.unfollow" : "public.follow");
      button.classList.toggle("btn-primary", !data.following); button.classList.toggle("btn-secondary", data.following);
    },
    list: function (target, response) {
      var data = response.data, pagination = U.pagination(response);
      var model = Object.assign({}, data, { pagination: pagination });
      var root = T.components.render("users/list", Object.assign({}, model, { state: model }));
      target.replaceChildren(root); root.dataset.usersReady = "true";
      T.users.dialogs(root, data, pagination);
    },
    dialogs: function (root, data, pagination) {
      var filters = document.getElementById("users-filter-modal");
      if (filters) { if (T.dialog.close) { T.dialog.close(filters); } filters.remove(); }
      var filterForm = T.components.render("users/filter-form", Object.assign({}, data, { pagination: pagination }));
      filterForm.querySelectorAll("input, select, textarea").forEach(function (control) {
        control.defaultValue = control.value; control.defaultChecked = control.checked;
        if (control.options) { Array.from(control.options).forEach(function (option) { option.defaultSelected = option.selected; }); }
      });
      filterForm.dataset.modalCloseOnSuccess = "true";
      U.dialog("users-filter-modal", "common.filters", filterForm, true);
      var create = document.getElementById("user-create-modal");
      if (create) { if (T.dialog.close) { T.dialog.close(create); } create.remove(); }
      adminDialog("user-create-modal", "users.new_user", adminForm({}, data, true), true);
    },
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.dataset.url || source.getAttribute("href") || "/", window.location.href);
      if (!/^\/api\/users(?:\/|$)/.test(url.pathname)) { return false; }
      var data = response.data;
      if (url.pathname === "/api/users" && source.dataset.apiMethod === "GET" || source.getAttribute("method") === "get") { T.users.list(target, response); return true; }
      if (target && target.id === "users-list" || source.dataset.adminUserForm) {
        var list = document.getElementById("users-list");
        var query = new URLSearchParams(window.location.search);
        T.api.request("/api/users?" + query).then(function (result) { T.users.list(list, result); if (T.lifecycle.hydrate) { T.lifecycle.hydrate(list); } }).catch(function (error) { T.toasts.show(error.message, "danger"); });
        return true;
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
        if (modal) { T.dialog.close(modal); }
        if (Number(document.body.dataset.currentUserId) === Number(data.user.id) && data.user.locale && data.user.locale !== T.i18n.locale()) {
          window.setTimeout(function () { window.location.reload(); }, 0);
        }
      }
      return true;
    }
  };
  T.users.hydrate = function (root) {
    if (T.users && T.users.dialogs) {
      Array.from((root || document).querySelectorAll("[data-users-view]")).forEach(function (view) {
        if (view.dataset.usersReady === "true") { return; }
        view.dataset.usersReady = "true";
        var state = JSON.parse(view.dataset.usersState);
        T.users.dialogs(view, state, state.pagination || {});
      });
    }
  };
}());
