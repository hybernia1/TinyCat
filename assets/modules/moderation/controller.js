import "../../ui/content-updates.js";
import "../../core/dates.js";
import "../../core/component-renderer.js";
import "../../ui/component-builders.js";
import "../../ui/dialogs.js";
import "../../core/dom.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  function reports(root, items, sorting, data) {
    var model = Object.assign({}, data, { items: items, sorting: sorting });
    T.content.replaceChildren(root, T.components.render("moderation/reports", Object.assign({}, model, { state: model })));
  }
  T.moderation = {
    modal: function (url, response, id) {
      if (!/^\/api\/moderation\/reports\/\d+$/.test(url.pathname)) { return null; }
      var U = T.ui, e = T.dom.element, data = response.data, post = data.content;
      if (url.searchParams.get("panel") === "report") {
        var reportId = Number(url.pathname.split("/").pop());
        var report = data.reports.find(function (entry) { return Number(entry.id) === reportId; });
        if (!report) { return null; }
        return U.dialog(id, "moderation.detail", T.components.render("moderation/report-preview", { report: report }));
      }
      var preview = e("div", { "class": "stack" });
      if (post) {
        if (post.status === "hidden") { preview.appendChild(e("p", { "class": "badge" }, U.text("moderation.hidden"))); }
        if (data.preview) {
          preview.classList.add("status-post-modal-content");
          preview.appendChild(T.components.render("status/card", { item: data.preview.item, anchor: false, open_modal: false, preview: true }));
          preview.appendChild(T.components.render("status/thread", { item: data.preview.item, comments: data.preview.comments, viewer: null, context: "moderation", preview: true }));
        }
      } else { preview.appendChild(e("p", {}, U.text("moderation.empty_content"))); }
      var history = T.components.render("moderation/history", data);
      var tabs = U.tabbedContent(id, [
        { name: "preview", label: "moderation.preview", icon: "eye", content: preview },
        { name: "reports", label: "moderation.reports_title", icon: "flag", content: history }
      ], "preview", U.text("moderation.detail"));
      var footer = null, actions = {};
      if (post && data.reports.some(function (report) { return report.status === "open"; })) {
        ["remove", "dismiss"].forEach(function (decision) {
          var action = T.components.render("ui/api-action", {
            url: url.pathname + window.location.search, method: "PATCH", target: "#moderation-reports", fields: { decision: decision },
            label: U.text(decision === "remove" ? "moderation.hide_content" : "moderation.dismiss_report"),
            confirm: decision === "remove" ? U.text("moderation.hide_confirm") : "",
            button_class: decision === "remove" ? "btn btn-danger" : "btn btn-primary"
          });
          action.setAttribute("data-modal-close-on-success", "true");
          actions[decision] = action;
        });
        footer = U.editorActions(actions.dismiss, actions.remove);
      }
      if (post && post.status === "hidden") {
        var publish = T.components.render("ui/api-action", {
          url: "/api/moderation/posts/" + Number(post.id) + "/publication" + window.location.search,
          method: "POST", target: "#moderation-reports", fields: {},
          label: U.text("moderation.publish_content"), confirm: U.text("moderation.publish_confirm"), button_class: "btn btn-primary"
        });
        publish.setAttribute("data-modal-close-on-success", "true");
        footer = U.editorActions(publish);
      }
      var dialog = U.dialog(id, "moderation.detail", tabs.content, false, footer, { size: "workspace" });
      if (footer) { U.editorFrame(dialog); }
      U.pinDialogTabs(dialog, tabs.content);
      return dialog;
    },
    hydrate: function (root) {
      Array.from((root || document).querySelectorAll("[data-moderation-reports-view]")).forEach(function (view) {
        if (view.dataset.moderationReportsReady === "true") { return; }
        view.dataset.moderationReportsReady = "true";
        var data = JSON.parse(view.dataset.moderationReportsState || "{}");
        if (!data.filters) { return; }
        var form = T.components.render("moderation/filter-form", data);
        form.querySelectorAll("input, select").forEach(function (field) {
          field.defaultValue = field.value;
          if (field.options) { Array.from(field.options).forEach(function (option) { option.defaultSelected = option.selected; }); }
        });
        T.ui.filterPanel("moderation-filter-modal", form);
      });
    },
    result: function (source, response, target) {
      var url = new URL(source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", window.location.href);
      if (!url.pathname.startsWith("/api/moderation/")) { return false; }
      if ((url.pathname.startsWith("/api/moderation/reports") || /^\/api\/moderation\/posts\/\d+\/publication$/.test(url.pathname)) && target) { reports(target, response.data.items, response.data.sorting, Object.assign({}, response.data, { pagination: T.ui.pagination(response) })); return [target]; }
      return [];
    }, reports: reports
  };
}());
