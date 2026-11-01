import "../../ui/content-updates.js";
import "../../core/dom.js";
import "../../core/api-client.js";
import "../../core/i18n.js";
import "../../core/lifecycle.js";
import "../../core/navigation.js";
import "./comments.js";
import "./editor.js";
import "./feed-loader.js";
import "./form-context.js";
import "./controller.js";
import "../../ui/confirm-actions.js";
import "../../ui/dialogs.js";
import "../../ui/form-state.js";
import "../../ui/form-requests.js";
import "../../ui/toasts.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var dataSelector = TinyCat.dom.dataSelector;
  var emit = TinyCat.dom.emit;
  var hydrateDynamic = TinyCat.lifecycle.hydrate;
  var clearErrors = TinyCat.forms.clearErrors;
  var applyErrors = TinyCat.forms.applyErrors;
  var markFormClean = TinyCat.forms.markClean;
  var uiText = TinyCat.i18n.label;
  var unwrapResult = TinyCat.api.unwrap;
  var resultErrors = TinyCat.api.errors;
  var confirmAction = TinyCat.actions.confirm;
  var shouldSubmitOnEnter = TinyCat.actions.submitOnEnter;
  var submitForm = TinyCat.actions.submit;
  function detachModalFromCard(modal, card) {
    if (modal && card && card.contains(modal)) {
      document.body.appendChild(modal);
    }
  }
  function shouldKeepStatusModalOpen(action, modal) {
    return Boolean(
      modal
        && modal.id
        && modal.id.indexOf("status-post-modal-") === 0
        && ["react", "comment", "comment_like", "comment_delete"].indexOf(action) !== -1
    );
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
  function resetStatusCreateForm(form) {
    if (!form || TinyCat.status.controllers.forms.statusFormAction(form) !== "create") {
      return;
    }

    form.reset();
    qsa("[data-status-editor-source]", form).forEach(function (source) {
      source.value = "";
    });
    qsa("[data-status-editor]", form).forEach(function (root) {
      if (TinyCat.status.controllers.editor.resetStatusEditor) {
        TinyCat.status.controllers.editor.resetStatusEditor(root);
      }
    });
  }
  async function handleStatusJsonResponse(form, data) {
    var payload = unwrapResult(data), action = payload.action || TinyCat.status.controllers.forms.statusFormAction(form), modal = form.closest(".modal");
    var hiddenItem = payload.item && payload.item.status === "hidden";
    if (hiddenItem) { removeStatusCard(payload.item.id, form); }
    var refreshFilteredHome = payload.item && !hiddenItem && ["create", "update"].indexOf(action) !== -1 && qs(".home-feed-toolbar") && new URL(window.location.href).searchParams.has("media");
    if (data.message) { TinyCat.toasts.show(data.message, "success"); }
    if (payload.status) { updateStatusSummary(payload.status); }
    if (payload.comment_like) { TinyCat.status.controllers.comments.updateCommentLike(payload.comment_like); }
    if (payload.comment) {
      var scope = modal || document;
      var list = payload.comment.parent_id ? qs('[data-comment-replies][data-comment-id="' + Number(payload.comment.parent_id) + '"]', scope) : qs('[data-status-comment-list][data-status-id="' + Number(payload.comment.content_id) + '"]', scope);
      if (!list && payload.comment.parent_id) { var parent = qs('.status-comment[data-comment-id="' + Number(payload.comment.parent_id) + '"] .status-comment-main', scope); if (parent) { list = TinyCat.dom.element("div", { "class": "status-comment-replies", "data-comment-replies": "", "data-comment-id": payload.comment.parent_id }); parent.appendChild(list); } }
      if (list) { list.appendChild(TinyCat.status.comment(payload.comment, modal ? "modal" : "", payload.comment.author)); hydrateDynamic(list); }
      TinyCat.status.controllers.comments.resetStatusCommentForm(form);
    }
    if (payload.updated_comment) {
      qsa('.status-comment[data-comment-id="' + Number(payload.updated_comment.id) + '"]').forEach(function (node) {
        var body = qs(".status-comment-body", node); if (body) { body.classList.add("is-resource-text"); body.replaceChildren(TinyCat.status.segments(payload.updated_comment.segments)); }
      });
    }
    if (payload.item && !hiddenItem) {
      if (action === "create") { var feed = TinyCat.status.controllers.feed.statusFeedForForm(form) || qs("[data-status-feed]"); if (feed && !refreshFilteredHome) { feed.prepend(TinyCat.status.card(payload.item)); hydrateDynamic(feed); } resetStatusCreateForm(form); }
      else if (!refreshFilteredHome) { qsa('.status-card[data-status-id="' + Number(payload.item.id) + '"]').forEach(function (card) { var inModal = Boolean(card.closest(".status-post-modal")); if (TinyCat.dialog.current()) { detachModalFromCard(TinyCat.dialog.current(), card); } var next = TinyCat.status.card(payload.item, Boolean(card.id), !inModal); TinyCat.content.replaceNode(card, next); hydrateDynamic(next); }); }
    }
    if (payload.deleted_status_id) { removeStatusCard(payload.deleted_status_id, form); }
    if (payload.deleted_comment_id) { TinyCat.status.controllers.comments.removeStatusComment(payload.deleted_comment_id); }
    markFormClean(form);
    if (modal && ["update", "comment_update", "delete", "report"].indexOf(action) !== -1) { TinyCat.dialog.close(modal); }
    emit(form, "tinycat:success", { data: data, payload: payload });
    if (refreshFilteredHome) { await TinyCat.navigation.restore({ state: Object.assign({}, window.history.state, { scroll: [window.scrollX, window.scrollY] }) }); }
  }
  function initStatusForms () {
    if (TinyCat.__statusFormEventsBound === true) {
      return;
    }

    TinyCat.__statusFormEventsBound = true;

    document.addEventListener("submit", async function (event) {
      var form = event.target.closest && event.target.closest("form[data-status-form]");
      var body;
      var action;
      var method;
      var url;
      var jsonData;
      var invalidField = null;

      if (!form) {
        return;
      }

      event.preventDefault();

      if (form.dataset.statusBusy === "true") {
        return;
      }

      if (form.dataset.confirm && !(await confirmAction(form))) {
        return;
      }

      clearErrors(form);
      body = new FormData(form);
      if (event.submitter && event.submitter.name) {
        body.append(event.submitter.name, event.submitter.value || "");
      }

      action = TinyCat.status.controllers.forms.statusFormAction(form, body);
      method = (form.getAttribute("method") || "POST").toUpperCase();
      url = form.getAttribute("action") || window.location.href;
      method = TinyCat.status.method(form, action);
      try {
        form.dataset.statusBusy = "true";
        jsonData = await TinyCat.forms.request(form, url, { method: method, body: body });
        await handleStatusJsonResponse(form, jsonData);
      } catch (error) {
        if (error.name === "AbortError") { return; }
        var errors = resultErrors(error);

        if (errors) {
          invalidField = applyErrors(form, errors);
        }

        TinyCat.toasts.show(error.message || uiText("requestFailed", "Request failed"), "danger");
      } finally {
        delete form.dataset.statusBusy;

        TinyCat.forms.focusInvalid(invalidField);
      }
    });

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
  TinyCat.status.controllers.actions = { shouldKeepStatusModalOpen: shouldKeepStatusModalOpen, updateStatusSummary: updateStatusSummary, removeStatusCard: removeStatusCard, resetStatusCreateForm: resetStatusCreateForm, handleStatusJsonResponse: handleStatusJsonResponse, initStatusForms: initStatusForms };
}());
