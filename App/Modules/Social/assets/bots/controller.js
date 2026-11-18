import "../../../../../assets/ui/content-updates.js";
import "../../../../../assets/ui/list-navigation.js";
import "../../../../../assets/core/dates.js";
import "../../../../../assets/core/component-renderer.js";
import "../../../../../assets/core/api-client.js";
import "../../../../../assets/ui/avatar-rendering.js";
import "../../../../../assets/ui/dialogs.js";
import "../../../../../assets/ui/component-builders.js";
import "../../../../../assets/ui/form-state.js";
import "../../../../../assets/ui/form-requests.js";
import "../../../../../assets/ui/toasts.js";
import "./source-preview.js";
import "./workspace.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  function sourceUrl(suffix) {
    var workspace = document.querySelector("[data-bot-workspace-id]");
    var bot = workspace ? workspace.dataset.botWorkspaceId : new URLSearchParams(window.location.search).get("bot");
    return "/api/bots/sources" + (suffix || "") + (Number(bot) > 0 ? "?bot=" + encodeURIComponent(bot) : "");
  }
  function submit(form, key) { form.appendChild(U.button(key || "common.save", { type: "submit", "class": "btn btn-primary btn-sm" })); return form; }
  function sourceForm(source, bots, constraints) {
    var form = U.form(sourceUrl(source.id ? "/" + source.id : ""), source.id ? "PATCH" : "POST");
    form.dataset.ajaxTarget = document.querySelector("[data-bot-workspace-id]") ? "#bot-detail-sources-list" : "#bots-list"; form.dataset.modalCloseOnSuccess = "true";
    form.appendChild(T.components.render("bots/source-fields", { source: source, bots: bots, constraints: constraints }));
    return submit(form);
  }
  function sources(root, data) {
    var view = T.components.render("bots/sources", data);
    T.content.replaceChildren(root, view);
  }
  function posts(root, response, source) {
    var data = Object.assign({}, response.data, { pagination: U.pagination(response) });
    root.dataset.botPostsUrl = data.url;
    root.dataset.botPostsRevision = String(Number(root.dataset.botPostsRevision || 0) + 1);
    T.content.replaceChildren(root, T.components.render("bots/posts", Object.assign({}, data, { state: data })));
    T.botDetail.pagination(root, source);
    var count = document.querySelector('[data-bot-stat="posts"]');
    if (count && Number.isFinite(Number(data.total_count))) { count.textContent = String(data.total_count); }
  }
  function refreshPosts() {
    T.botDetail.changed();
    var root = document.querySelector("#bot-detail-posts-list");
    if (!root || !root.dataset.botPostsUrl) { return; }
    var request = T.requests.begin(root), url = root.dataset.botPostsUrl, revision = root.dataset.botPostsRevision;
    return T.api.request(url, { signal: request.signal }).then(function (response) {
      if (!request.current() || root.dataset.botPostsUrl !== url || root.dataset.botPostsRevision !== revision) { return; }
      posts(root, response);
      T.lifecycle.hydrate(root);
    }).catch(function (error) { if (error.name !== "AbortError" && request.current()) { T.toasts.show(error.message, "danger"); } })
      .finally(request.finish);
  }
  T.bots = {
    queue: function (target, response) {
      var data = Object.assign({}, response.data, { pagination: U.pagination(response) });
      T.content.replaceChildren(target, T.components.render("bots/queue", Object.assign({}, data, { state: data })));
    },
    modal: function (url, response, id) {
      if (/^\/api\/bots\/accounts\/[0-9]+$/.test(url.pathname) && url.searchParams.get("panel") === "detail") { return T.botDetail.modal(url, response, id); }
      if (/^\/api\/bots\/accounts\/[0-9]+\/posts\/[0-9]+\/management$/.test(url.pathname)) {
        var editUrl = new URL("/api/statuses/" + response.data.item.id + "?panel=edit", window.location.href);
        var dialog = T.status.modal(editUrl, response, id);
        dialog.addEventListener("tinycat:success", function () {
          refreshPosts();
          var queue = document.querySelector("#bot-queue-list");
          if (queue) { T.lists.refresh(queue); }
        });
        return dialog;
      }
      if (/^\/api\/bots\/sources\/(?:[0-9]+\/)?management$/.test(url.pathname)) {
        return U.sectionedDialog(id, response.data.item.id ? "bots.edit_source" : "bots.new_source", sourceForm(response.data.item, response.data.bots, response.data.constraints));
      }
      return null;
    },
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", window.location.href);
      if (!url.pathname.startsWith("/api/bots/")) { return false; }
      var detail = T.botDetail.result(source, response, target);
      if (detail) { return detail; }
      if (target && target.id === "bot-queue-list") { T.bots.queue(target, response); return [target]; }
      if (target && target.id === "bot-detail-posts-list") { posts(target, response, source); return [target]; }
      return [];
    }, sources: sources, posts: posts
  };
  T.bots.dispose = function (root) { T.botDetail.dispose(root); };
  T.bots.hydrate = function (root) {
    T.botDetail.hydrate(root);
    Array.from((root || document).querySelectorAll("[data-bot-posts-view]")).forEach(function (view) {
      if (view.dataset.botPostsReady === "true") { return; }
      view.dataset.botPostsReady = "true";
      var state = JSON.parse(view.dataset.botPostsState);
      var postList = document.querySelector(state.list_target || "#bot-detail-posts-list");
      if (postList) { postList.dataset.botPostsUrl = JSON.parse(view.dataset.botPostsState).url; }
      var filterId = state.filter_modal || "bot-detail-posts-filter-modal";
      var form = T.components.render("bots/post-filter-form", JSON.parse(view.dataset.botPostsState));
      U.filterPanel(filterId, form);
    });
    var list = document.querySelector("#bot-detail-posts-list");
    if (list && !list.dataset.botPostsReady) {
      list.dataset.botPostsReady = "true";
      list.addEventListener("tinycat:success", refreshPosts);
    }

  };
}());
