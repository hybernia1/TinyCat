import "../../core/modules.js";
import "../component-builders.js";
import "../content-updates.js";
import "./comments.js";
import "./editor-entry.js";
(function () {
  "use strict";
  var T = window.TinyCat, C = T.publishing.controllers, U = T.ui, all = T.dom.all;
  function render(item, thread) {
    return T.components.render("publishing/comment", { item: item, viewer: thread.__viewer, show_replies: true, show_reply_form: true, api_base: thread.dataset.discussionApi });
  }
  function find(items, id) {
    for (var item of items || []) { if (Number(item.id) === Number(id)) return item; var child = find(item.replies, id); if (child) return child; }
    return null;
  }
  function hydrate(root) {
    all("[data-discussion-form]", root).forEach(function (form) {
      form.dataset.ajaxForm = "";
      var action = (form.elements.namedItem("action") || {}).value;
      if (action === "react" || action === "comment_like") {
        var button = form.querySelector("[aria-pressed]");
        form.dataset.apiMethod = button && button.getAttribute("aria-pressed") === "true" ? "DELETE" : "PUT";
      }
    });
    C.editor.hydrate(root);
  }
  T.modules.register("publishing-discussion", {
    hydrate: hydrate,
    init: C.comments.initCommentReplies,
    result: function (source, response) {
      if (!source.matches("[data-discussion-form]")) return false;
      var data = response.data, id = data.target.id, thread = document.querySelector('[data-discussion-id="' + Number(id) + '"]');
      if (!thread) return [];
      thread.__viewer = data.viewer;
      var item = find(data.items, data.comment_id), roots = [];
      if (data.action === "comment" && item) {
        var parent = item.parent_id && thread.querySelector('.status-comment[data-comment-id="' + Number(item.parent_id) + '"] .status-comment-main');
        var list = parent ? C.comments.ensureCommentReplies(parent.closest(".status-comment")) : thread.querySelector("[data-status-comment-list]");
        if (list) { var node = render(item, thread); list.appendChild(node); roots.push(node); }
        C.comments.resetStatusCommentForm(source);
      } else if (data.action === "comment_update" && item) {
        all('.status-comment[data-comment-id="' + Number(item.id) + '"] .status-comment-body', thread).forEach(function (body) { body.replaceChildren(T.components.render("publishing/segments", { segments: item.segments })); });
      } else if (data.action === "comment_delete") {
        all('.status-comment[data-comment-id="' + Number(data.comment_id) + '"]', thread).forEach(T.content.remove);
      }
      all("form[data-discussion-form]", thread).forEach(function (form) {
        var field = form.elements.namedItem("action"), button = form.querySelector("[aria-pressed]");
        if (!button || !field) return;
        var value = field.value === "react" ? data : find(data.items, button.dataset.commentId);
        if (!value) return;
        button.setAttribute("aria-pressed", value.liked ? "true" : "false");
        button.classList.toggle("is-active", Boolean(value.liked));
        button.setAttribute("aria-label", value.liked ? button.dataset.unlikeLabel : button.dataset.likeLabel);
        var count = button.querySelector("[data-status-count], [data-comment-like-count]");
        if (count) count.textContent = String(value.likes_count);
      });
      hydrate(thread);
      return roots;
    },
    modal: function (url, response, id) {
      var data = response.data;
      if (!data.target || !data.item || !data.target.api_url) return null;
      var item = data.item, content = T.dom.element("div", { "class": "stack stack-gap-16" }), endpoint = data.target.api_url + "/comments/" + item.id;
      if (item.capabilities.edit) {
        var form = U.form(endpoint, "PATCH");
        form.dataset.discussionForm = ""; form.dataset.modalCloseOnSuccess = "true";
        form.appendChild(T.components.render("publishing/editor", { name: "comment", value: item.editable_body, comment: true, max_length: 2000, required: true }));
        form.appendChild(U.button("common.save", { type: "submit" })); content.appendChild(form);
      }
      if (item.capabilities.delete) {
        var remove = U.form(endpoint, "DELETE"); remove.dataset.discussionForm = ""; remove.dataset.modalCloseOnSuccess = "true";
        remove.dataset.confirm = U.text("common.confirm_action"); remove.appendChild(U.button("common.delete", { type: "submit", "class": "btn btn-danger" })); content.appendChild(remove);
      }
      if (data.revisions && data.revisions.length) {
        var revisions = T.dom.element("details"), summary = T.dom.element("summary", {}, U.text("account.status_comment_history"));
        revisions.appendChild(summary); revisions.appendChild(C.comments.historyContent(data.revisions)); content.appendChild(revisions);
      }
      return U.adminDialog(id, "account.status_comments", content, false);
    }
  });
}());
