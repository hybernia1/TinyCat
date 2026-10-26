import "../../core/components.js";
import "../../core/dom.js";
import "../../ui/fields.js";
(function () {
  "use strict";
  var T = window.TinyCat, U = T.ui, e = T.dom.element;
  function base(id) { return "/api/statuses/" + Number(id); }
  function form(url, action, id) {
    var root = e("form", { action: url, method: "post", "data-status-form": "", "data-status-id": id });
    if (["update", "comment", "comment_update"].indexOf(action) !== -1) {
      root.dataset.confirmUnsaved = "true";
      root.dataset.confirmUnsavedTitle = U.text("common.unsaved_title"); root.dataset.confirmUnsavedMessage = U.text("common.unsaved_message");
      root.dataset.confirmUnsavedOk = U.text("common.leave"); root.dataset.confirmUnsavedCancel = U.text("common.stay");
    }
    root.appendChild(U.hidden("action", action)); return root;
  }
  function editor(name, body, placeholder) {
    return T.components.render("status/editor", { name: name, comment: name === "comment", value: body || "", placeholder: U.text(placeholder) });
  }
  T.status = {
    segments: function (segments) {
      return T.components.render("status/segments", { segments: segments || [] });
    },
    card: function (item, anchor, openModal) {
      return T.components.render("status/card", { item: item, anchor: anchor !== false, open_modal: openModal !== false, action: window.location.pathname });
    },
    fragment: function (items) { var fragment = document.createDocumentFragment(); (items || []).forEach(function (item) { fragment.appendChild(T.status.card(item)); }); return { content: fragment }; },
    comment: function (item, context, viewer) {
      return T.components.render("status/comment", { item: item, context: context || "", viewer: viewer, show_replies: true, show_reply_form: true });
    },
    modal: function (url, response, id) {
      var data = response.data, content = e("div", { "class": "stack" }), item = data.item;
      if (url.pathname === "/api/moderation/report-options") {
        var report = form("/api/moderation/reports", "report", url.searchParams.get("id"));
        report.appendChild(U.hidden("id", url.searchParams.get("id")));
        report.appendChild(U.select("moderation.report_submit", "reason", "spam", data.reasons));
        report.appendChild(U.field("moderation.report_submit", "note", "", "textarea", { maxlength: "2000" }));
        report.appendChild(U.button("moderation.report_submit", { type: "submit", "class": "btn btn-primary" }));
        return U.dialog(id, "moderation.report_submit", report);
      }
      if (!/^\/api\/statuses\//.test(url.pathname)) { return null; }
      if (/\/revisions$/.test(url.pathname)) {
        data.items.forEach(function (revision) {
          var change = revision.changes && revision.changes.body || {};
          content.append(e("time", {}, revision.at || revision.created_at || ""), e("pre", {}, String(change.from || "")), e("pre", {}, String(change.to || "")));
        });
        return U.dialog(id, "account.status_comment_history", content);
      }
      var panel = url.searchParams.get("panel");
      if (panel === "edit") {
        if (!item.capabilities.edit) { throw new Error(U.text("auth.forbidden")); }
        var edit = form(base(item.id), "update", item.id); edit.dataset.confirmUnsaved = "true"; edit.enctype = "multipart/form-data";
        edit.appendChild(T.components.render("status/composer-body", { viewer: data.viewer || item.author, value: item.editable_body, image: item.image, enabled: data.options.images_enabled, submit_label: U.text("common.save"), submit_icon: "save" }));
        var editDialog = U.dialog(id, "account.status_edit", edit); editDialog.classList.add("modal-form");
        editDialog.querySelector(".modal-panel").classList.add("modal-panel-lg"); editDialog.querySelector(".modal-panel").classList.add("status-edit-modal-panel");
        editDialog.querySelector(".modal-body").classList.add("status-edit-modal-body"); return editDialog;
      }
      if (panel === "comment-edit") {
        var wanted = Number(url.searchParams.get("comment_id")), selected;
        data.comments.forEach(function (comment) { if (comment.id === wanted) { selected = comment; } comment.replies.forEach(function (reply) { if (reply.id === wanted) { selected = reply; } }); });
        if (!selected || !selected.capabilities.edit) { throw new Error(U.text("auth.forbidden")); }
        var commentEdit = form(base(item.id) + "/comments/" + selected.id, "comment_update", item.id); commentEdit.dataset.confirmUnsaved = "true";
        commentEdit.appendChild(editor("comment", selected.editable_body, "account.status_comment_placeholder"));
        commentEdit.appendChild(U.button("common.save", { type: "submit", "class": "btn btn-primary" })); return U.dialog(id, "common.edit", commentEdit);
      }
      content.className = "status-post-modal-content";
      content.appendChild(T.status.card(item, false, false));
      content.appendChild(T.components.render("status/thread", { item: item, comments: data.comments, viewer: data.viewer, context: "modal" }));
      var dialog = U.dialog(id, "account.status_thread_title", content);
      dialog.classList.add("status-post-modal"); dialog.classList.add("modal-mobile-fullscreen");
      dialog.querySelector(".modal-panel").classList.add("status-post-modal-panel");
      dialog.querySelector(".modal-body").classList.add("status-post-modal-body");
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
      feed.replaceChildren(T.status.fragment(response.data.items).content);
      feed.id = "status-feed-" + (response.data.feed || "all");
      section.querySelectorAll("[data-status-empty], [data-status-feed-more], [data-feed-login-required]").forEach(function (node) { node.remove(); });
      section.querySelectorAll(".feed-switch-link").forEach(function (link) { var following = link.getAttribute("href").indexOf("following") !== -1; if (following === (response.data.feed === "following")) { link.setAttribute("aria-current", "page"); } else { link.removeAttribute("aria-current"); } });
      if (!response.data.items.length) { section.appendChild(e("div", { "class": "alert alert-info", "data-status-empty": "" }, U.text(response.data.login_required ? "auth.login_required" : (response.data.feed === "following" ? "public.feed_empty_following" : "public.feed_empty")))); }
      var pagination = U.pagination(response);
      if (pagination.next_url) {
        section.appendChild(T.components.render("status/feed-more", { target: "#" + feed.id, url: pagination.next_url }));
      }
    }
  };
  T.status.result = function (source, response, target) {
    var url = new URL(source.dataset.url || source.dataset.ajaxAction || source.getAttribute("action") || source.getAttribute("href") || "/", window.location.href);
    if (url.pathname !== "/api/statuses" || !target || !target.matches(".home-feed-section")) { return false; }
    T.status.feed(target, response); return true;
  };
  T.status.controllers = {};
}());
