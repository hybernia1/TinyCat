import "../legacy-modal-links.js";
// @component-owned: structure is declared in owner catalogs.
import "../../../../Core/Ui/assets/content-updates.js";
import "../../../../Core/Ui/assets/runtime/dates.js";
import "../../../../Core/Ui/assets/runtime/component-renderer.js";
import "../../../../Core/Ui/assets/component-builders.js";
import "../../../../Core/Ui/assets/dialogs.js";
import "../../../../Core/Ui/assets/runtime/dom.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.moderation = {
    modal: function (url, response, id) {
      if (!/^\/api\/social\/moderation\/reports\/\d+$/.test(url.pathname)) { return null; }
      var U = T.ui, data = response.data, post = data.content, definition = T.adminDefinitions["social.moderation-reports"];
      if (url.searchParams.get("panel") === "report") {
        var reportId = Number(url.pathname.split("/").pop());
        var report = data.reports.find(function (entry) { return Number(entry.id) === reportId; });
        if (!report) { return null; }
        return U.dialog(id, "moderation.detail", T.components.render("moderation/report-preview", { report: report }));
      }
      var preview = T.components.render("moderation/detail-preview", data);
      var history = T.components.render("moderation/history", data);
      var tabs = U.tabbedContent(id, [
        { name: "preview", label: "moderation.preview", icon: "eye", content: preview },
        { name: "reports", label: "moderation.reports_title", icon: "flag", content: history }
      ], "preview", U.text("moderation.detail"));
      var footer = null, actions = {};
      if (post && data.reports.some(function (report) { return report.status === "open"; })) {
        ["remove", "dismiss"].forEach(function (decision) {
          var action = T.components.render("ui/api-action", {
            url: url.pathname + window.location.search, method: "PATCH", target: definition.target, fields: { decision: decision },
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
          url: definition.api + "/posts/" + Number(post.id) + "/publication" + window.location.search,
          method: "POST", target: definition.target, fields: {},
          label: U.text("moderation.publish_content"), confirm: U.text("moderation.publish_confirm"), button_class: "btn btn-primary"
        });
        publish.setAttribute("data-modal-close-on-success", "true");
        footer = U.editorActions(publish);
      }
      var dialog = U.dialog(id, "moderation.detail", tabs.content, false, footer, { size: "workspace" });
      if (footer) { U.editorFrame(dialog); }
      U.pinDialogTabs(dialog, tabs.content);
      return dialog;
    }
  };
}());
