import "../../core/dates.js";
import "../../core/components.js";
import "../../core/http.js";
import "../../ui/avatars.js";
import "../../ui/dialogs.js";
import "../../ui/fields.js";
import "../../ui/form-state.js";
import "../../ui/forms.js";
import "../../ui/toasts.js";
import "./source-preview.js";
import "./detail.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  var pendingPosts = new WeakMap();
  function sourceUrl(suffix) {
    var workspace = document.querySelector("[data-bot-workspace-id]");
    var bot = workspace ? workspace.dataset.botWorkspaceId : new URLSearchParams(window.location.search).get("bot");
    return "/api/bots/sources" + (suffix || "") + (Number(bot) > 0 ? "?bot=" + encodeURIComponent(bot) : "");
  }
  function submit(form, key) { form.appendChild(U.button(key || "common.save", { type: "submit", "class": "btn btn-primary btn-sm" })); return form; }
  function sourceForm(source, bots) {
    var form = U.form(sourceUrl(source.id ? "/" + source.id : ""), source.id ? "PATCH" : "POST");
    form.dataset.ajaxTarget = document.querySelector("[data-bot-workspace-id]") ? "#bot-detail-sources-list" : "#bots-list"; form.dataset.modalCloseOnSuccess = "true";
    form.appendChild(T.components.render("bots/source-fields", { source: source, bots: bots }));
    return submit(form);
  }
  function sources(root, data) {
    var view = T.components.render("bots/sources", data);
    root.replaceChildren(view);
    if (!data.scoped) { syncFilter("bots-filter-modal", Object.assign({}, data.filters || { bot: data.filter_bot_user_id }, data.pagination ? { per_page: data.pagination.per_page } : {})); }
  }
  function posts(root, response, source) {
    var data = Object.assign({}, response.data, { pagination: U.pagination(response) });
    root.dataset.botPostsUrl = data.url;
    root.dataset.botPostsRevision = String(Number(root.dataset.botPostsRevision || 0) + 1);
    root.replaceChildren(T.components.render("bots/posts", Object.assign({}, data, { state: data })));
    T.botDetail.pagination(root, source);
    T.bots.hydrate(root);
    var count = document.querySelector('[data-bot-stat="posts"]');
    if (count && Number.isFinite(Number(data.total_count))) { count.textContent = String(data.total_count); }
  }
  function refreshPosts() {
    var root = document.querySelector("#bot-detail-posts-list");
    if (!root || !root.dataset.botPostsUrl) { return; }
    if (pendingPosts.has(root)) { pendingPosts.get(root).abort(); }
    var controller = new AbortController(), url = root.dataset.botPostsUrl, revision = root.dataset.botPostsRevision;
    pendingPosts.set(root, controller);
    return T.api.request(url, { signal: controller.signal }).then(function (response) {
      if (!root.isConnected || root.dataset.botPostsUrl !== url || root.dataset.botPostsRevision !== revision) { return; }
      posts(root, response);
      if (T.forms.hydrate) { T.forms.hydrate(root); }
    }).catch(function (error) { if (error.name !== "AbortError") { T.toasts.show(error.message, "danger"); } })
      .finally(function () { if (pendingPosts.get(root) === controller) { pendingPosts.delete(root); } });
  }
  function syncFilter(id, filters) {
    var modal = document.getElementById(id) || T.filters.get(id);
    if (!modal) { return; }
    modal.querySelectorAll("input, select").forEach(function (control) {
      if (!Object.prototype.hasOwnProperty.call(filters, control.name)) { return; }
      control.value = control.name === "bot" && !Number(filters[control.name]) ? "0" : String(filters[control.name]); control.defaultValue = control.value;
      if (control.options) { Array.from(control.options).forEach(function (option) { option.defaultSelected = option.selected; }); }
    });
  }
  function accounts(root, response) {
    var data = response.data, pagination = U.pagination(response);
    var model = Object.assign({}, data, { pagination: pagination });
    root.replaceChildren(T.components.render("bots/accounts", model));
    syncFilter("bot-accounts-filter-modal", Object.assign({}, data.filters, { per_page: pagination.per_page }));
  }
  function reloadList(root, path, render) {
    T.api.request(path + window.location.search).then(function (response) {
      if (!root.isConnected) { return; }
      render(root, response);
      T.lifecycle.hydrate(root);
    }).catch(function (error) { T.toasts.show(error.message, "danger"); });
  }
  T.bots = {
    queue: function (target, response) {
      var data = Object.assign({}, response.data, { pagination: U.pagination(response) });
      target.replaceChildren(T.components.render("bots/queue", Object.assign({}, data, { state: data })));
    },
    modal: function (url, response, id) {
      if (/^\/api\/bots\/accounts\/[0-9]+$/.test(url.pathname) && url.searchParams.get("panel") === "detail") { return T.botDetail.modal(url, response, id); }
      if (/^\/api\/bots\/accounts\/[0-9]+\/posts\/[0-9]+\/management$/.test(url.pathname)) {
        var editUrl = new URL("/api/statuses/" + response.data.item.id + "?panel=edit", window.location.href);
        var dialog = T.status.modal(editUrl, response, id);
        dialog.addEventListener("tinycat:success", function () {
          refreshPosts();
          var queue = document.querySelector("#bot-queue-list");
          if (queue) { reloadList(queue, "/api/bots/queue", T.bots.queue); }
        });
        return dialog;
      }
      if (/^\/api\/bots\/sources\/(?:[0-9]+\/)?management$/.test(url.pathname)) {
        return U.sectionedDialog(id, response.data.item.id ? "bots.edit_source" : "bots.new_source", sourceForm(response.data.item, response.data.bots));
      }
      return null;
    },
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", window.location.href);
      if (!url.pathname.startsWith("/api/bots/")) { return false; }
      if (T.botDetail.result(source, response, target)) { return true; }
      if (target && target.id === "bot-queue-list") { T.bots.queue(target, response); return true; }
      if (target && target.id === "bot-accounts-list") {
        if ((source.dataset.apiMethod || "").toUpperCase() === "GET" || source.getAttribute("method") === "get" || source.getAttribute("href") && !source.dataset.method) { accounts(target, response); }
        else { reloadList(target, "/api/bots/accounts", accounts); }
        return true;
      }
      if (target && target.id === "bots-list") {
        if ((source.dataset.apiMethod || "").toUpperCase() === "GET" || source.getAttribute("method") === "get" || source.getAttribute("href") && !source.dataset.method) { sources(target, Object.assign({}, response.data, { pagination: U.pagination(response) })); }
        else { reloadList(target, "/api/bots/sources", function (root, result) { sources(root, Object.assign({}, result.data, { pagination: U.pagination(result) })); }); }
        return true;
      }
      if (target && target.id === "bot-detail-posts-list") { posts(target, response, source); return true; }
      return true;
    }, sources: sources, posts: posts, accounts: accounts
  };
  T.bots.dispose = function (root) { T.botDetail.dispose(root); };
  T.bots.hydrate = function (root) {
    T.botDetail.hydrate(root);
    Array.from((root || document).querySelectorAll("[data-bot-queue-view]")).forEach(function (view) {
      if (view.dataset.botQueueReady === "true") { return; }
      view.dataset.botQueueReady = "true";
      var form = T.components.render("bots/queue-filter-form", JSON.parse(view.dataset.botQueueState));
      form.querySelectorAll("input, select").forEach(function (field) {
        field.defaultValue = field.value;
        if (field.options) { Array.from(field.options).forEach(function (option) { option.defaultSelected = option.selected; }); }
      });
      U.filterPanel("bot-queue-filter-modal", form);
    });
    Array.from((root || document).querySelectorAll("[data-bot-posts-view]")).forEach(function (view) {
      if (view.dataset.botPostsReady === "true") { return; }
      view.dataset.botPostsReady = "true";
      var state = JSON.parse(view.dataset.botPostsState);
      var postList = document.querySelector(state.list_target || "#bot-detail-posts-list");
      if (postList) { postList.dataset.botPostsUrl = JSON.parse(view.dataset.botPostsState).url; }
      var filterId = state.filter_modal || "bot-detail-posts-filter-modal";
      var form = T.components.render("bots/post-filter-form", JSON.parse(view.dataset.botPostsState));
      form.querySelectorAll("input, select").forEach(function (field) {
        field.defaultValue = field.value;
        if (field.options) { Array.from(field.options).forEach(function (option) { option.defaultSelected = option.selected; }); }
      });
      U.filterPanel(filterId, form);
    });
    var list = document.querySelector("#bot-detail-posts-list");
    if (list && !list.dataset.botPostsReady) {
      list.dataset.botPostsReady = "true";
      list.addEventListener("tinycat:success", refreshPosts);
    }

  };
}());
