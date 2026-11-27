// @component-owned: structure is declared in owner catalogs.
import "../content/comments.js";
import "../content/runtime.js";
import "../../../../Core/Ui/assets/content-updates.js";
import "../../../../Core/Ui/assets/runtime/dates.js";
import "../../../../Core/Ui/assets/runtime/component-renderer.js";
import "../../../../Core/Ui/assets/runtime/dom.js";
import "../../../../Core/Ui/assets/component-builders.js";
import "./controller.js";
import "./form-actions.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  function base(id) { return "/api/social/statuses/" + Number(id); }
  function form(url, action, id, body, className, enctype) {
    var view = T.components.render("status/dialog-form", { action: action, id: id, body: body, form_class: className || null, enctype: enctype || null });
    var prepared = U.form(url, T.status.method(view, action), view);
    T.status.controllers.actions.prepareForms(prepared);
    return prepared;
  }
  Object.assign(T.status, {
    edit: function (data, id, actionUrl) {
      if (!data.item.capabilities.edit) { throw new Error(U.text("auth.forbidden")); }
      var edit = form(actionUrl, "update", data.item.id, T.components.render("status/edit-fields", data), null, "multipart/form-data");
      return U.formDialog(id, "account.status_edit", edit, false, { size: "wide" });
    },
    modal: function (url, response, id) {
      var data = response.data, item = data.item;
      if (url.pathname === "/api/social/moderation/report-options") {
        var reportModel = Object.assign({}, data, { id: url.searchParams.get("id"), note_max_length: data.note_max_length || 1000,
          reasons: Object.fromEntries(data.reasons.map(function (reason) { return [reason, U.text("moderation.report_reasons." + reason)]; })) });
        var report = form("/api/social/moderation/reports", "report", reportModel.id, T.components.render("status/report-fields", reportModel), "status-modal-form");
        return U.formDialog(id, "moderation.report_submit", report);
      }
      if (!/^\/api\/social\/statuses\//.test(url.pathname)) { return null; }
      if (/\/embed$/.test(url.pathname)) {
        var previewUrl = new URL(data.frame_url, window.location.href);
        var previewPath = url.pathname.replace(/^\/api\/social\/statuses\//, "/status/");
        if (previewUrl.origin !== window.location.origin || previewUrl.username || previewUrl.password || previewUrl.pathname !== previewPath || !/^\/status\/[1-9][0-9]*\/embed$/.test(previewPath) || previewUrl.search || previewUrl.hash) throw new TypeError("Invalid embed preview URL.");
        var preview = document.createElement("iframe");
        preview.src = previewUrl.href; preview.className = "status-embed-preview";
        preview.title = U.text("account.status_embed"); preview.loading = "lazy"; preview.referrerPolicy = "no-referrer"; preview.height = "340";
        var previewObserver;
        preview.addEventListener("load", function () {
          if (previewObserver) previewObserver.disconnect();
          var card = preview.contentDocument && preview.contentDocument.querySelector(".embed-card");
          if (!card) return;
          function resize() { preview.height = String(Math.min(600, Math.ceil(card.getBoundingClientRect().height))); }
          if (window.ResizeObserver) { previewObserver = new window.ResizeObserver(resize); previewObserver.observe(card); }
          resize();
        });
        var content = T.components.render("status/embed-content", { snippet: data.snippet, preview: preview });
        var embedDialog = U.dialog(id, "account.status_embed", content);
        embedDialog.addEventListener("tinycat:modal-close", function () { if (previewObserver) previewObserver.disconnect(); });
        return embedDialog;
      }
      if (/\/revisions$/.test(url.pathname)) {
        return U.dialog(id, "account.status_comment_history", T.publishing.controllers.comments.historyContent(data.items));
      }
      var panel = url.searchParams.get("panel");
      if (panel === "edit") { return T.status.edit(data, id, base(item.id)); }
      if (panel === "comment-edit") {
        var wanted = Number(url.searchParams.get("comment_id")), selected;
        data.comments.forEach(function (comment) { if (comment.id === wanted) { selected = comment; } comment.replies.forEach(function (reply) { if (reply.id === wanted) { selected = reply; } }); });
        if (!selected || !selected.capabilities.edit) { throw new Error(U.text("auth.forbidden")); }
        var commentEdit = form(base(item.id) + "/comments/" + selected.id, "comment_update", item.id,
          T.components.render("status/comment-edit-fields", { comment: selected, constraints: data.constraints, dialog_id: id }), "status-modal-form");
        return U.formDialog(id, "common.edit", commentEdit);
      }
      var content = T.components.render("status/thread-dialog", Object.assign({}, data, { page_path: window.location.pathname }));
      var dialog = U.dialog(id, "account.status_thread_title", content, false, null, { size: "reading", mobile: "fullscreen" });
      dialog.classList.add("status-post-modal");
      return dialog;
    }
  });
}());
