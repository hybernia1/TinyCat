import "../../core/dom.js";
import "../../core/http.js";
import "../../ui/fields.js";
import "../../ui/tabs.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui, e = T.dom.element, states = new WeakMap();
  function owner() { return document.querySelector("[data-bot-workspace-id]"); }
  function request(state, url) {
    var controller = new AbortController();
    state.requests.add(controller);
    return T.api.request(url, { signal: controller.signal }).finally(function () { state.requests.delete(controller); });
  }
  function failed(error) { if (error.name !== "AbortError") { T.toasts.show(error.message, "danger"); } }
  function metadata(state, data) {
    var values = { sources: data.item.source_count, active_sources: data.item.active_source_count, posts: data.item.post_count, runs: data.item.run_count };
    state.summary.replaceChildren();
    Object.keys(values).forEach(function (key) { state.summary.appendChild(T.components.render("bots/stat", { stat: { key: key, value: values[key] } })); });
    state.status.replaceChildren(T.components.render("ui/account-status", { status: data.item.status, label: U.text("users.statuses." + data.item.status) }));
  }
  function refreshMetadata(state) {
    return request(state, "/api/bots/accounts/" + state.id).then(function (response) {
      if (state.dialog.isConnected) { metadata(state, response.data); }
    }).catch(failed);
  }
  function scopedSources(state, response) {
    var data = Object.assign({}, response.data, { pagination: U.pagination(response), scoped: true, list_target: "#bot-detail-sources-list" });
    Object.values(data.sorting || {}).forEach(function (heading) { heading.target = data.list_target; heading.history = false; });
    var navigation = data.navigation || {};
    [navigation.previous, navigation.next].concat(navigation.pages || []).forEach(function (link) { if (link) { link.history_url = false; } });
    state.sources.dataset.listUrl = "/api/bots/sources?" + new URLSearchParams(Object.assign({}, data.filters, { bot: state.id, page: data.pagination.page || 1 }));
    T.bots.sources(state.sources, data);
    state.sources.dataset.loaded = "true";
  }
  function scopedRuns(state, response) {
    var data = Object.assign({}, response.data, { pagination: U.pagination(response) });
    state.history.dataset.listUrl = data.url;
    state.history.replaceChildren(T.components.render("bots/runs-list", data));
    state.history.dataset.loaded = "true";
    var form = T.components.render("bots/run-filter-form", data);
    form.querySelectorAll("input, select").forEach(function (field) {
      field.defaultValue = field.value;
      if (field.options) { Array.from(field.options).forEach(function (option) { option.defaultSelected = option.selected; }); }
    });
    U.filterPanel(data.filter_modal, form);
  }
  function load(state, name, force) {
    var root = name === "sources" ? state.sources : name === "posts" ? state.posts : name === "history" ? state.history : null;
    if (!root || root.dataset.loading === "true" || root.dataset.loaded === "true" && !force) { return; }
    root.dataset.loading = "true"; root.setAttribute("aria-busy", "true");
    var url = name === "posts" ? root.dataset.botPostsUrl : root.dataset.listUrl, generation = root.dataset.apiGeneration;
    request(state, url).then(function (response) {
      if (!state.dialog.isConnected || generation !== root.dataset.apiGeneration || url !== (name === "posts" ? root.dataset.botPostsUrl : root.dataset.listUrl)) { return; }
      if (name === "sources") { scopedSources(state, response); }
      else if (name === "history") { scopedRuns(state, response); }
      else { T.bots.posts(root, response); root.dataset.loaded = "true"; }
      T.lifecycle.hydrate(root);
    }).catch(function (error) {
      if (error.name !== "AbortError" && state.dialog.isConnected) {
        var retry = U.button("common.refresh");
        retry.addEventListener("click", function () { load(state, name, true); });
        root.replaceChildren(retry); failed(error);
      }
    }).finally(function () { delete root.dataset.loading; root.removeAttribute("aria-busy"); });
  }
  function background() {
    var entries = [["#bot-accounts-list", "/api/bots/accounts", T.bots.accounts], ["#bots-list", "/api/bots/sources", function (root, response) { T.bots.sources(root, Object.assign({}, response.data, { pagination: U.pagination(response) })); }], ["#bot-queue-list", "/api/bots/queue", T.bots.queue]];
    var query = /^\/admin\/bots\/[0-9]+$/.test(window.location.pathname) ? "" : window.location.search;
    entries.forEach(function (entry) {
      var root = document.querySelector(entry[0]);
      if (root) { T.api.request(entry[1] + query).then(function (response) { if (root.isConnected) { entry[2](root, response); T.lifecycle.hydrate(root); } }).catch(failed); }
    });
  }
  function postUrl(id, query) {
    var params = new URLSearchParams();
    ["q", "source", "publication", "sort", "direction", "page", "per_page"].forEach(function (key) { if (query.has(key)) { params.set(key, query.get(key)); } });
    params.set("scope", "modal");
    return "/api/bots/accounts/" + id + "/posts?" + params;
  }
  T.botDetail = {
    modal: function (url, response, id) {
      var data = response.data, botId = Number(data.item.id);
      var form = U.form("/api/bots/accounts/" + botId, "PATCH");
      form.dataset.ajaxTarget = "#bot-detail-account";
      var fields = T.components.render("bots/account-fields", data);
      form.appendChild(fields);
      form.appendChild(U.button("common.save", { type: "submit", "class": "btn btn-primary" }));
      var dialog = U.adminDialog(id, "@" + data.item.username, form);
      dialog.dataset.botWorkspaceId = String(botId);
      var content = e("div", { "class": "stack stack-gap-16" });
      var status = U.accountDialogTitle(dialog, data.item);
      var summary = e("div", { "class": "grid sm:grid-2 md:grid-4" });
      var sources = e("div", { id: "bot-detail-sources-list", "class": "stack" });
      var posts = e("div", { id: "bot-detail-posts-list", "class": "stack" });
      sources.dataset.listUrl = "/api/bots/sources?bot=" + botId;
      posts.dataset.botPostsUrl = postUrl(botId, url.searchParams);
      var history = e("div", { id: "bot-detail-runs-list", "class": "stack" });
      history.dataset.listUrl = "/api/bots/accounts/" + botId + "/runs";
      var initial = url.searchParams.get("tab") || (url.searchParams.has("source") || url.searchParams.has("publication") || url.searchParams.has("q") ? "posts" : "account");
      var layout = U.tabbedContent(id, [
        { name: "account", label: "bots.detail_account", icon: "settings" },
        { name: "sources", label: "bots.detail_sources", icon: "rss" },
        { name: "history", label: "bots.detail_history", icon: "clock" },
        { name: "posts", label: "bots.detail_posts", icon: "file" }
      ], initial, "@" + data.item.username);
      initial = layout.initial;
      var panels = layout.panels, tabButtons = layout.buttons;
      content.appendChild(layout.content);
      panels.account.id = "bot-detail-account";
      tabButtons.account.setAttribute("aria-controls", panels.account.id);
      panels.account.append(summary, form); panels.sources.appendChild(sources); panels.history.appendChild(history); panels.posts.appendChild(posts);
      dialog.querySelector(".modal-body").replaceChildren(content);
      var state = { id: botId, dialog: dialog, sources: sources, posts: posts, history: history, summary: summary, status: status, requests: new Set() };
      states.set(dialog, state); metadata(state, data);
      var footer = dialog.querySelector(".modal-footer"); footer.hidden = initial !== "account";
      layout.content.addEventListener("tinycat:tab", function (event) { footer.hidden = event.detail.name !== "account"; load(state, event.detail.name); });
      content.addEventListener("click", function (event) {
        var link = event.target.closest && event.target.closest("a[href]");
        if (!link) { return; }
        var target = new URL(link.getAttribute("href"), window.location.href);
        if (target.pathname !== "/admin/bots/" + botId) { return; }
        event.preventDefault(); event.stopPropagation();
        if (!target.search) { T.tabs.activate(tabButtons.account, true); return; }
        posts.dataset.botPostsUrl = postUrl(botId, target.searchParams); delete posts.dataset.loaded;
        T.tabs.activate(tabButtons.posts, true);
      });
      dialog.addEventListener("tinycat:modal-close", function () { T.botDetail.dispose(dialog); background(); });
      // Hydration normally activates the selected tab; this also covers direct callers.
      dialog.addEventListener("tinycat:modal-open", function () { load(state, initial); });
      return dialog;
    },
    result: function (source, response, target) {
      var dialog = owner(), state = dialog && states.get(dialog);
      if (!state || !target || !["bot-detail-account", "bot-detail-sources-list", "bot-detail-runs-list"].includes(target.id)) { return false; }
      var method = (source.dataset.apiMethod || source.getAttribute("method") || "GET").toUpperCase();
      if (target === state.history && method === "GET") { scopedRuns(state, response); }
      else if (target === state.sources && method === "GET") { scopedSources(state, response); }
      else {
        refreshMetadata(state);
        if (target === state.sources || state.sources.dataset.loaded === "true") { load(state, "sources", true); }
        if (state.posts.dataset.loaded === "true") { load(state, "posts", true); }
        if (state.history.dataset.loaded === "true") { load(state, "history", true); }
      }
      return true;
    },
    dispose: function (root) {
      var state = states.get(root);
      if (!state) { return; }
      state.requests.forEach(function (controller) { controller.abort(); });
      T.filters.remove("bot-detail-posts-filter-modal");
      T.filters.remove("bot-detail-runs-filter-modal");
      states.delete(root);
    },
    hydrate: function (root) {
      Array.from((root || document).querySelectorAll("[data-bot-detail-open]")).forEach(function (marker) {
        if (marker.dataset.ready === "true") { return; }
        marker.dataset.ready = "true";
        var id = Number(marker.dataset.botDetailOpen), query = new URLSearchParams(marker.dataset.botDetailQuery || "");
        query.delete("view");
        query.set("panel", "detail");
        var url = new URL("/api/bots/accounts/" + id + "?" + query, window.location.href);
        T.api.request(url.pathname + url.search).then(function (response) {
          if (!marker.isConnected) { return; }
          var dialog = T.botDetail.modal(url, response, "bot-detail-modal");
          dialog.addEventListener("tinycat:modal-close", function () {
            if (window.location.pathname === "/admin/bots/" + id) { window.history.replaceState({}, "", "/admin/bots/accounts"); }
          });
          T.lifecycle.hydrate(dialog); T.dialog.open(dialog);
        }).catch(failed);
      });
    }
  };
}());
