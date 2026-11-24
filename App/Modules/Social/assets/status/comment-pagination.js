import "./comments.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat, qs = TinyCat.dom.find, qsa = TinyCat.dom.all;
  var comments = TinyCat.status.controllers.comments, remove = comments.removeStatusComment;
  function ensureCommentReplies(root) { return comments.ensureCommentReplies(root); }
  function insertComment(list, item, context, viewer) {
    var thread = list.closest(".status-comments-thread"), id = String(item.id);
    if (!thread || (thread.__removedComments && thread.__removedComments.has(id)) || qs('.status-comment[data-comment-id="' + Number(id) + '"]', thread)) return;
    var node = TinyCat.status.comment(item, context, viewer);
    var at = String(item.created_at || ""), before = Array.from(list.children).find(function (child) {
      var childAt = child.dataset.commentCreatedAt || "";
      return childAt && (childAt > at || (childAt === at && Number(child.dataset.commentId) > Number(id)));
    });
    list.insertBefore(node, before || null);
    return node;
  }
  function initCommentPagination() {
    if (TinyCat.__commentPaginationBound) return;
    TinyCat.__commentPaginationBound = true;
    document.addEventListener("click", async function (event) {
      var button = event.target.closest && event.target.closest("[data-comment-page]");
      if (!button || button.disabled) return;
      var thread = button.closest(".status-comments-thread"), parent = Number(button.dataset.commentParent || 0), url = button.dataset.commentPage;
      var root = parent ? button.closest(".status-comment") : null;
      if (!thread || !url || (parent && !root)) return;
      button.disabled = true;
      try {
        var response = await TinyCat.api.request(url, { method: "GET" });
        var modal = thread.closest(".modal");
        if (!button.isConnected || !thread.isConnected || button.dataset.commentPage !== url || (root && !root.isConnected) || (modal && modal.dataset.open !== "true")) return;
        var list = parent ? ensureCommentReplies(root) : qs("[data-status-comment-list]", thread);
        if (!list) return;
        (response.data.items || []).forEach(function (item) { var node = insertComment(list, item, thread.closest(".modal") ? "modal" : "", response.data.viewer); if (node) TinyCat.lifecycle.hydrate(node); });
        var next = TinyCat.ui.pagination(response).next_url || "";
        if (next) button.dataset.commentPage = next;
        else {
          if (document.activeElement === button) { list.setAttribute("tabindex", "-1"); list.focus(); }
          TinyCat.content.remove(button);
        }
      } catch (error) {
        if (button.isConnected) TinyCat.toasts.show(error.message, "danger");
      } finally { button.disabled = false; }
    });
  }
  comments.removeStatusComment = function (id) {
    qsa(".status-comments-thread").forEach(function (thread) {
      if (!thread.__removedComments) thread.__removedComments = new Set();
      thread.__removedComments.add(String(id));
    });
    remove(id);
  };
  comments.insertComment = insertComment;
  comments.initCommentPagination = initCommentPagination;
}());
