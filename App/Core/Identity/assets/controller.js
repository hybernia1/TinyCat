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
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  T.accounts = {
    modal: function (url, response, id) { return T.accounts.editorModal ? T.accounts.editorModal(url, response, id) : null; },
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.dataset.url || source.getAttribute("href") || "/", window.location.href);
      var data = response.data;
      if (!/^\/api\/users(?:\/|$)/.test(url.pathname) || !data.user) { return false; }
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
