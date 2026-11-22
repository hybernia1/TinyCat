// @component-owned: structure is declared in owner catalogs.
import "../content/comments.js";
import "../content/runtime.js";
import "../../../../Core/Ui/assets/content-updates.js";
import "../../../../Core/Ui/assets/runtime/dates.js";
import "../../../../Core/Ui/assets/runtime/component-renderer.js";
import "../../../../Core/Ui/assets/runtime/dom.js";
import "../../../../Core/Ui/assets/component-builders.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui;
  function base(id) { return "/api/statuses/" + Number(id); }
  function form(url, action, id, body, className, enctype) {
    return T.components.render("status/dialog-form", { url: url, action: action, id: id, body: body, form_class: className || null, enctype: enctype || null });
  }
  T.status = {
    segments: function (segments) {
      return T.components.render("publishing/segments", { segments: segments || [] });
    },
    card: function (item, anchor, openModal) {
      return T.components.render("status/card", { item: item, anchor: anchor !== false, open_modal: openModal !== false, action: window.location.pathname });
    },
    fragment: function (items) { var fragment = document.createDocumentFragment(); (items || []).forEach(function (item) { fragment.appendChild(T.status.card(item)); }); return { content: fragment }; },
    comment: function (item, context, viewer) {
      return T.components.render("status/comment", { item: item, context: context || "", viewer: viewer, show_replies: true, show_reply_form: true });
    },
    edit: function (data, id, actionUrl) {
      if (!data.item.capabilities.edit) { throw new Error(U.text("auth.forbidden")); }
      var edit = form(actionUrl, "update", data.item.id, T.components.render("status/edit-fields", data), null, "multipart/form-data");
      return U.formDialog(id, "account.status_edit", edit, false, { size: "wide" });
    },
    modal: function (url, response, id) {
      var data = response.data, item = data.item;
      if (url.pathname === "/api/moderation/report-options") {
        var reportModel = Object.assign({}, data, { id: url.searchParams.get("id"), note_max_length: data.note_max_length || 1000,
          reasons: Object.fromEntries(data.reasons.map(function (reason) { return [reason, U.text("moderation.report_reasons." + reason)]; })) });
        var report = form("/api/moderation/reports", "report", reportModel.id, T.components.render("status/report-fields", reportModel), "status-modal-form");
        return U.formDialog(id, "moderation.report_submit", report);
      }
      if (!/^\/api\/statuses\//.test(url.pathname)) { return null; }
      if (/\/embed$/.test(url.pathname)) {
        var previewUrl = new URL(data.frame_url, window.location.href);
        if (previewUrl.origin !== window.location.origin || !/\/status\/[1-9][0-9]*\/embed$/.test(previewUrl.pathname)) throw new TypeError("Invalid embed preview URL.");
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
        var code = content.querySelector("textarea");
        code.addEventListener("click", function () { code.select(); });
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
    },
    method: function (form, action) {
      if (action === "react" || action === "like" || action === "comment_like") {
        var button = form.querySelector("[aria-pressed]"); return button && button.getAttribute("aria-pressed") === "true" ? "DELETE" : "PUT";
      }
      return { update: "PATCH", comment_update: "PATCH", delete: "DELETE", comment_delete: "DELETE" }[action] || "POST";
    },
    feed: function (target, response) {
      var section = target.matches(".home-feed-section") ? target : target.closest(".home-feed-section");
      var feed = section.querySelector("[data-status-feed]");
      T.status.controllers.feed.dispose(feed);
      T.content.replaceChildren(feed, T.status.fragment(response.data.items).content);
      feed.id = "status-feed-" + (response.data.feed || "all");
      section.querySelectorAll("[data-status-empty], [data-status-feed-more], [data-feed-login-required]").forEach(T.content.remove);
      section.querySelectorAll(".feed-switch-link").forEach(function (link) { var following = link.getAttribute("href").indexOf("following") !== -1; if (following === (response.data.feed === "following")) { link.setAttribute("aria-current", "page"); } else { link.removeAttribute("aria-current"); } });
      if (!response.data.items.length) { section.appendChild(T.components.render("status/feed-empty", response.data)); }
      var pagination = U.pagination(response);
      if (pagination.next_url) {
        section.appendChild(T.components.render("status/feed-more", { target: "#" + feed.id, url: pagination.next_url }));
      }
    }
  };
  T.status.result = function (source, response, target) {
    var url = new URL(source.dataset.url || source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", window.location.href);
    if (url.pathname !== "/api/statuses" || !target || !target.matches(".home-feed-section")) { return false; }
    T.status.feed(target, response); return [target];
  };
  T.status.controllers = {};
}());
