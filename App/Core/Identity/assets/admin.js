import "./index.js";
import "../../../../assets/ui/list-navigation.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  Object.assign(T.accounts, {
    list: function (target, response) {
      var data = response.data, pagination = U.pagination(response);
      var model = Object.assign({}, data, { pagination: pagination });
      var root = T.components.render("users/list", Object.assign({}, model, { state: model }));
      T.content.replaceChildren(target, root); root.dataset.usersReady = "true";
      T.accounts.filters(data, pagination);
    },
    filters: function (data, pagination) {
      var filterForm = T.components.render("users/filter-form", Object.assign({}, data, { pagination: pagination }));
      U.filterPanel("users-filter-modal", filterForm);
    }
  });
  var baseResult = T.accounts.result;
  T.accounts.result = function (source, response, target) {
    var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.dataset.url || source.getAttribute("href") || "/", window.location.href);
    if (!/^\/api\/users(?:\/|$)/.test(url.pathname)) { return baseResult(source, response, target); }
    if (url.pathname === "/api/users" && target && T.lists.isRead(source)) { T.accounts.list(target, response); return [target]; }
    if (target && target.id === "users-list" || source.dataset.adminUserForm) {
      var list = document.getElementById("users-list");
      T.lists.refresh(list);
      return [];
    }
    return baseResult(source, response, target);
  };
  var baseHydrate = T.accounts.hydrate;
  T.accounts.hydrate = function (root) {
    baseHydrate(root);
    Array.from((root || document).querySelectorAll("[data-users-view]")).forEach(function (view) {
      if (view.dataset.usersReady === "true") { return; }
      view.dataset.usersReady = "true";
      var state = JSON.parse(view.dataset.usersState);
      T.accounts.filters(state, state.pagination || {});
    });
  };
  T.lists.register("/admin/users", {
    target: "#users-list", keepQuery: ["sort", "direction"], url: function () { return "/api/users" + window.location.search; },
    apply: function (target, response) { T.accounts.list(target, response); }
  });
}());
