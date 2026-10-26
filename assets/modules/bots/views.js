import "../../core/dates.js";
import "../../core/components.js";
import "../../core/http.js";
import "../../ui/avatars.js";
import "../../ui/dialogs.js";
import "../../ui/fields.js";
import "../../ui/form-state.js";
import "../../ui/forms.js";
import "../../ui/toasts.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  function sourceUrl(suffix) {
    var bot = new URLSearchParams(window.location.search).get("bot");
    return "/api/bots/sources" + (suffix || "") + (Number(bot) > 0 ? "?bot=" + encodeURIComponent(bot) : "");
  }
  function submit(form, key) { form.appendChild(U.button(key || "common.save", { type: "submit", "class": "btn btn-primary btn-sm" })); return form; }
  function sourceForm(source, bots) {
    var form = U.form(sourceUrl(source.id ? "/" + source.id : ""), source.id ? "PATCH" : "POST");
    form.dataset.ajaxTarget = "#bots-list"; form.dataset.modalCloseOnSuccess = "true";
    form.appendChild(T.components.render("bots/source-fields", { source: source, bots: bots }));
    return submit(form);
  }
  function sources(root, data) {
    var view = T.components.render("bots/sources", Object.assign({}, data, { state: data }));
    root.replaceChildren(view); bindSources(view, data);
  }
  function bindSources(root, data) {
    if (root.dataset.botSourcesReady === "true") { return; }
    root.dataset.botSourcesReady = "true";
    root.querySelectorAll("[data-bot-source-edit]").forEach(function (button) {
      button.addEventListener("click", function () {
        var source = data.items.find(function (item) { return Number(item.id) === Number(button.dataset.botSourceEdit); });
        if (!source) { return; }
        var dialog = U.dialog("bot-source-resource-" + source.id, "bots.edit_source", sourceForm(source, data.bots));
        T.dialog.open(dialog);
      });
    });
  }
  function accounts(root, response) {
    var data = response.data, pagination = U.pagination(response);
    root.querySelectorAll(".modal").forEach(function (modal) { document.body.appendChild(modal); });
    var model = Object.assign({}, data, { pagination: pagination });
    root.replaceChildren(T.components.render("bots/accounts", model));
    var filter = document.getElementById("bot-accounts-filter-modal");
    if (filter) { filter.querySelectorAll("input, select").forEach(function (control) { var value = control.name === "per_page" ? pagination.per_page : data.filters[control.name]; if (value !== undefined) { control.value = String(value); control.defaultValue = String(value); } }); }
  }
  function detail(data) {
    var stats = { sources: data.sources.length, active_sources: data.sources.filter(function (source) { return Boolean(source.enabled); }).length, posts: data.item ? data.item.post_count : 0, runs: data.runs.length };
    document.querySelectorAll("[data-bot-stat]").forEach(function (node) {
      var value = stats[node.dataset.botStat];
      if (Number.isFinite(Number(value))) { node.textContent = String(value); }
    });
    var root = document.querySelector("[data-bot-sources]");
    if (root) { sources(root, { items: data.sources, bots: [data.item] }); }
    document.querySelectorAll("[data-bot-run-history]").forEach(function (node) {
      node.replaceChildren(T.components.render("bots/runs", { runs: data.runs, latest: node.getAttribute("data-bot-run-latest") !== null }));
    });
    if (data.item) {
      document.querySelectorAll('[data-avatar-user-id="' + data.item.id + '"]').forEach(function (node) { node.replaceWith(U.avatar(data.item)); });
    }
  }
  T.bots = {
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", window.location.href);
      if (!url.pathname.startsWith("/api/bots/")) { return false; }
      if (target && target.id === "bot-accounts-list") { accounts(target, response); }
      if (target && target.id === "bots-list") { sources(target, response.data); }
      var owner = document.querySelector("[data-bot-detail-id]");
      if (owner) {
        T.api.request("/api/bots/accounts/" + owner.dataset.botDetailId).then(function (result) { detail(result.data); if (T.forms.hydrate) { T.forms.hydrate(document); } }).catch(function (error) { T.toasts.show(error.message, "danger"); });
      }
      return true;
    }, sources: sources, bindSources: bindSources, accounts: accounts, sourceForm: sourceForm
  };
  T.bots.hydrate = function (root) {
    if (T.bots && T.bots.bindSources) {
      Array.from((root || document).querySelectorAll("[data-bot-sources-view]")).forEach(function (view) {
        T.bots.bindSources(view, JSON.parse(view.dataset.botSourcesState));
      });
    }
  };
}());
