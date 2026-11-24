import "../../../../Core/Ui/assets/content-updates.js";
import "../../../../Core/Ui/assets/runtime/dom.js";
import "../../../../Core/Ui/assets/runtime/api-client.js";
import "../../../../Core/Ui/assets/runtime/i18n.js";
import "../../../../Core/Ui/assets/runtime/lifecycle.js";
import "../../../../Core/Ui/assets/runtime/navigation.js";
import "./comments.js";
import "./feed-loader.js";
import "./form-context.js";
import "./controller.js";
import "../../../../Core/Ui/assets/confirm-actions.js";
import "../../../../Core/Ui/assets/dialogs.js";
import "../../../../Core/Ui/assets/form-state.js";
import "../../../../Core/Ui/assets/form-requests.js";
import "../../../../Core/Ui/assets/toasts.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var dataSelector = TinyCat.dom.dataSelector;
  var unwrapResult = TinyCat.api.unwrap;
  var shouldSubmitOnEnter = TinyCat.actions.submitOnEnter;
  var submitForm = TinyCat.actions.submit;
  function detachModalFromCard(modal, card) {
    if (modal && card && card.contains(modal)) {
      document.body.appendChild(modal);
    }
  }
  function updateStatusSummary(summary) {
    var id = summary && summary.id ? String(summary.id) : "";

    if (!id) {
      return;
    }

    qsa(dataSelector("data-status-id", id) + " [data-status-count]").forEach(function (node) {
      var type = node.dataset.statusCount;
      var value = summary[type + "_count"];

      if (value !== undefined && value !== null) {
        node.textContent = String(value);
      }
    });

    qsa(dataSelector("data-status-id", id) + " [data-status-like-button]").forEach(function (button) {
      var liked = Boolean(summary.liked);
      var label = liked ? button.dataset.unlikeLabel : button.dataset.likeLabel;

      button.classList.toggle("is-active", liked);
      button.setAttribute("aria-pressed", liked ? "true" : "false");

      if (label) {
        button.setAttribute("aria-label", label);
        button.setAttribute("title", label);
      }
    });

  }
  function removeStatusCard(statusId, form) {
    var id = statusId ? String(statusId) : "";
    var current = TinyCat.status.controllers.forms.statusFormCard(form);

    if (!id && current) {
      id = TinyCat.status.controllers.feed.statusCardId(current);
    }

    if (!id) {
      return;
    }

    qsa(".status-card").forEach(function (card) {
      if (TinyCat.status.controllers.feed.statusCardId(card) === id) {
        TinyCat.content.remove(card);
      }
    });
  }
  async function handleStatusJsonResponse(form, data) {
    var payload = unwrapResult(data), action = payload.action || TinyCat.status.controllers.forms.statusFormAction(form), modal = form.closest(".modal"), roots = [];
    var hiddenItem = payload.item && payload.item.status === "hidden";
    if (hiddenItem) { removeStatusCard(payload.item.id, form); }
    var refreshFilteredHome = payload.item && !hiddenItem && ["create", "update"].indexOf(action) !== -1 && qs(".home-feed-toolbar") && new URL(window.location.href).searchParams.has("media");
    if (payload.status) { updateStatusSummary(payload.status); }
    if (payload.comment_like) { TinyCat.status.controllers.comments.updateCommentLike(payload.comment_like); }
    if (payload.comment) {
      var scope = modal || document;
      var list = payload.comment.parent_id ? qs('[data-comment-replies][data-comment-id="' + Number(payload.comment.parent_id) + '"]', scope) : qs('[data-status-comment-list][data-status-id="' + Number(payload.comment.content_id) + '"]', scope);
      if (!list && payload.comment.parent_id) { var parent = qs('.status-comment[data-comment-id="' + Number(payload.comment.parent_id) + '"] .status-comment-main', scope); if (parent) { list = TinyCat.status.controllers.comments.ensureCommentReplies(parent.closest(".status-comment")); } }
      if (list) { var insert = TinyCat.status.controllers.comments.insertComment; var node = insert ? insert(list, payload.comment, modal ? "modal" : "", payload.comment.author) : list.appendChild(TinyCat.status.comment(payload.comment, modal ? "modal" : "", payload.comment.author)); if (node) roots.push(node); }
    }
    if (payload.updated_comment) {
      qsa('.status-comment[data-comment-id="' + Number(payload.updated_comment.id) + '"]').forEach(function (node) {
        var body = qs(".status-comment-body", node); if (body) { body.classList.add("is-resource-text"); body.replaceChildren(TinyCat.status.segments(payload.updated_comment.segments)); roots.push(body); }
      });
    }
    if (payload.item && !hiddenItem) {
      if (action === "create") { var feed = TinyCat.status.controllers.feed.statusFeedForForm(form) || qs("[data-status-feed]"); if (feed && !refreshFilteredHome) { var created = TinyCat.status.card(payload.item); feed.prepend(created); roots.push(created); } }
      else if (!refreshFilteredHome) { qsa('.status-card[data-status-id="' + Number(payload.item.id) + '"]').forEach(function (card) { var inModal = Boolean(card.closest(".status-post-modal")); if (TinyCat.dialog.current()) { detachModalFromCard(TinyCat.dialog.current(), card); } var next = TinyCat.status.card(payload.item, Boolean(card.id), !inModal); TinyCat.content.replaceNode(card, next); roots.push(next); }); }
    }
    if (payload.deleted_status_id) { removeStatusCard(payload.deleted_status_id, form); }
    if (payload.deleted_comment_id) { TinyCat.status.controllers.comments.removeStatusComment(payload.deleted_comment_id); }
    if (refreshFilteredHome) { TinyCat.forms.markClean(form); await TinyCat.navigation.restore({ state: Object.assign({}, window.history.state, { scroll: [window.scrollX, window.scrollY] }) }); }
    return roots;
  }
  function prepareForms(root) {
    var forms = qsa("form[data-status-form]", root);
    if (root && root.matches && root.matches("form[data-status-form]")) { forms.push(root); }
    forms.forEach(function (form) {
      if (form.dataset.submitController && form.dataset.submitController !== "social") { return; }
      var action = TinyCat.status.controllers.forms.statusFormAction(form);
      form.dataset.ajaxForm = "";
      form.dataset.apiMethod = TinyCat.status.method(form, action);
      form.dataset.reset = ["create", "comment"].includes(action) ? "true" : "false";
      form.dataset.modalCloseOnSuccess = ["update", "comment_update", "delete", "report"].indexOf(action) !== -1 ? "true" : "false";
    });
  }
  function initStatusForms () {
    if (TinyCat.__statusFormEventsBound === true) {
      return;
    }

    TinyCat.__statusFormEventsBound = true;

    document.addEventListener("keydown", function (event) {
      var field = event.target.closest && event.target.closest(".status-comment-input, .status-comment-editor [data-status-editor-input]");
      var form = field ? (field.form || field.closest("form")) : null;

      if (!form || !shouldSubmitOnEnter(event)) {
        return;
      }

      event.preventDefault();
      submitForm(form);
    });
  }
  TinyCat.status.controllers.actions = { updateStatusSummary: updateStatusSummary, removeStatusCard: removeStatusCard, handleStatusJsonResponse: handleStatusJsonResponse, prepareForms: prepareForms, initStatusForms: initStatusForms };
}());
