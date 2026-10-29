import "../../core/components.js";
import "../../core/dates.js";
import "../../ui/fields.js";

(function () {
  "use strict";
  var T = window.TinyCat;
  var timer = null, pending = null;
  function editing(target) {
    return document.hidden || document.querySelector('.modal[data-open="true"]') ||
      (target.contains(document.activeElement) && document.activeElement.matches("input, select, textarea, [contenteditable]")) ||
      target.querySelector('[aria-busy="true"]');
  }
  async function poll() {
    var target = document.querySelector("#scheduler-list");
    if (!target || pending || editing(target)) { return; }
    var view = target.firstElementChild, generation = target.dataset.apiGeneration;
    var url = "/api/scheduler/tasks" + window.location.search;
    var controller = new AbortController(); pending = controller;
    try {
      var response = await T.api.request(url, { method: "GET", cache: "no-store", signal: controller.signal });
      if (!target.isConnected || target.firstElementChild !== view || target.dataset.apiGeneration !== generation ||
          url !== "/api/scheduler/tasks" + window.location.search || editing(target)) { return; }
      var data = Object.assign({}, response.data, { pagination: T.ui.pagination(response) });
      if (JSON.stringify(data) === JSON.stringify(JSON.parse(view.dataset.schedulerState))) { return; }
      T.scheduler.list(target, response);
      T.lifecycle.hydrate(target);
    } catch (_error) {
      // Keep the current history visible; the next poll retries transient failures.
    } finally { if (pending === controller) { pending = null; } }
  }
  T.scheduler = {
    init: function () {
      document.addEventListener("visibilitychange", function () { if (!document.hidden) { poll(); } });
    },
    dispose: function (root) {
      var target = document.querySelector("#scheduler-list");
      if (target && (root === target || root.contains(target))) {
        window.clearInterval(timer); timer = null;
        if (pending) { pending.abort(); pending = null; }
      }
    },
    modal: function (url, response, id) {
      if (!/^\/api\/scheduler\/runs\/[0-9]+$/.test(url.pathname)) { return null; }
      return T.ui.dialog(id, "cron.run_details", T.components.render("scheduler/run-detail", response.data));
    },
    list: function (target, response) {
      var data = Object.assign({}, response.data, { pagination: T.ui.pagination(response) });
      var view = T.components.render("scheduler/list", Object.assign({}, data, { state: data }));
      target.replaceChildren(view);
      view.dataset.schedulerReady = "true";
      T.scheduler.filters(data);
    },
    filters: function (data) {
      var previous = document.getElementById("scheduler-filter-modal");
      if (previous) { T.dialog.close(previous); previous.remove(); }
      var form = T.components.render("scheduler/filter-form", data);
      form.querySelectorAll("input, select, textarea").forEach(function (field) {
        field.defaultValue = field.value;
        if (field.options) { Array.from(field.options).forEach(function (option) { option.defaultSelected = option.selected; }); }
      });
      T.ui.dialog("scheduler-filter-modal", "common.filters", form, true);
    },
    hydrate: function (root) {
      Array.from((root || document).querySelectorAll("[data-scheduler-view]")).forEach(function (view) {
        if (timer === null) { timer = window.setInterval(poll, 5000); }
        if (view.dataset.schedulerReady === "true") { return; }
        view.dataset.schedulerReady = "true";
        T.scheduler.filters(JSON.parse(view.dataset.schedulerState));
      });
    },
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.dataset.url || source.getAttribute("href") || "/", window.location.href);
      if (url.pathname === "/api/scheduler/tasks" || /^\/api\/scheduler\/tasks\/[a-z-]+\/runs$/.test(url.pathname)) {
        var root = target || document.querySelector("#scheduler-list");
        if (root) { T.scheduler.list(root, response); }
        return true;
      }
      if (url.pathname !== "/api/scheduler/tokens") { return false; }
      var current = document.querySelector("[data-scheduler-token]"), token = response.data.token;
      document.querySelectorAll("[data-scheduler-command]").forEach(function (node) { node.textContent = node.textContent.replace(/(Authorization: Bearer )[^"]*/g, function (_, prefix) { return prefix + token; }); });
      if (current) { current.value = token; }
      return true;
    }
  };
}());
