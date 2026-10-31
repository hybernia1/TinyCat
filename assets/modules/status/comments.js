import "../../ui/content.js";
import "../../core/dom.js";
import "./editor.js";
import "./forms.js";
import "./views.js";
import "../../ui/dialogs.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var dataSelector = TinyCat.dom.dataSelector;
  var modalScrollElement = TinyCat.dialog.scrollElement;
  function keepModalReplyVisible(field) {
    var form = field && field.closest ? field.closest(".status-comment-form.is-reply") : null;
    var modal = form ? form.closest(".modal[data-open='true']") : null;
    var scroller = modal ? modalScrollElement(modal) : null;
    var viewport = window.visualViewport || null;
    var formRect;
    var scrollRect;
    var visibleTop;
    var visibleBottom;
    var adjustment = 0;

    if (!form || !modal || !scroller) {
      return;
    }

    formRect = form.getBoundingClientRect();
    scrollRect = scroller.getBoundingClientRect();
    visibleTop = Math.max(scrollRect.top, viewport ? viewport.offsetTop : 0) + 12;
    visibleBottom = Math.min(scrollRect.bottom, viewport ? viewport.offsetTop + viewport.height : window.innerHeight) - 12;

    if (formRect.bottom > visibleBottom) {
      adjustment = formRect.bottom - visibleBottom;
    } else if (formRect.top < visibleTop) {
      adjustment = formRect.top - visibleTop;
    }

    if (adjustment !== 0) {
      scroller.scrollTop += adjustment;
    }
  }
  function scheduleModalReplyVisibility(field) {
    if (!field || !field.closest || !field.closest(".status-comment-form.is-reply")) {
      return;
    }

    TinyCat.__modalReplyField = field;

    if (TinyCat.__modalReplyVisibilityQueued === true) {
      return;
    }

    TinyCat.__modalReplyVisibilityQueued = true;
    window.requestAnimationFrame(function () {
      TinyCat.__modalReplyVisibilityQueued = false;
      keepModalReplyVisible(TinyCat.__modalReplyField);
    });
  }
    function positionMenu(menu) {
      var popover = qs(".context-menu-popover", menu);
      var summary = qs("summary", menu);
      if (!popover || !summary) { return; }
      menu.classList.remove("opens-up");
      var bottom = window.innerHeight - 8;
      var parent = menu.parentElement;
      while (parent) {
        if (/(auto|scroll|hidden|clip)/.test(window.getComputedStyle(parent).overflowY)) {
          bottom = Math.min(bottom, parent.getBoundingClientRect().bottom - 8);
        }
        parent = parent.parentElement;
      }
      if (summary.getBoundingClientRect().bottom + popover.getBoundingClientRect().height + 4 > bottom) {
        menu.classList.add("opens-up");
      }
    }

  function initCommentReplies () {
    if (TinyCat.__commentReplyEventsBound === true) {
      return;
    }

    TinyCat.__commentReplyEventsBound = true;

    document.addEventListener("toggle", function (event) {
      var menu = event.target;
      if (menu && menu.matches && menu.matches(".context-menu[open]") && menu.closest(".status-comment, .status-post-modal")) { positionMenu(menu); }
    }, true);
    window.addEventListener("resize", function () {
      qsa(".status-comment .context-menu[open], .status-post-modal .context-menu[open]").forEach(positionMenu);
    });


    document.addEventListener("toggle", function (event) {
      var details = event.target;
      var editorRoot;
      var input;
      var length;

      if (!details || !details.matches || !details.matches(".status-reply-details") || !details.open) {
        return;
      }

      editorRoot = qs("[data-status-editor]", details.closest(".status-comment-main"));
      input = (editorRoot ? TinyCat.status.controllers.editor.statusEditorInput(editorRoot) : null) || qs(".status-reply-form-container .status-comment-input", details.closest(".status-comment-main"));

      if (!input) {
        return;
      }

      input.focus();
      length = input.isContentEditable ? TinyCat.status.controllers.editor.statusEditorText(input).length : input.value.length;

      if (input.isContentEditable) {
        TinyCat.status.controllers.editor.setStatusEditorText(input, TinyCat.status.controllers.editor.statusEditorText(input), length);
      } else if (input.setSelectionRange) {
        input.setSelectionRange(length, length);
      }

      scheduleModalReplyVisibility(input);

    }, true);

    document.addEventListener("focusin", function (event) {
      scheduleModalReplyVisibility(event.target);
    });

    if (window.visualViewport) {
      ["resize", "scroll"].forEach(function (eventName) {
        window.visualViewport.addEventListener(eventName, function () {
          scheduleModalReplyVisibility(document.activeElement);
        }, { passive: true });
      });
    }
  }
  function updateCommentLike(payload) {
    var id = payload && payload.comment_id ? String(payload.comment_id) : "";

    if (!id) {
      return;
    }

    qsa("[data-comment-like-count]" + dataSelector("data-comment-id", id)).forEach(function (node) {
      node.textContent = String(payload.likes_count || 0);
    });

    qsa("[data-comment-like-button]" + dataSelector("data-comment-id", id)).forEach(function (button) {
      var liked = Boolean(payload.liked);
      var label = liked ? button.dataset.unlikeLabel : button.dataset.likeLabel;

      button.classList.toggle("is-active", liked);

      if (button.matches("button")) {
        button.setAttribute("aria-pressed", liked ? "true" : "false");

        if (label) {
          button.setAttribute("aria-label", label);
          button.setAttribute("title", label);
        }
      }
    });
  }
  function ensureCommentReplies(parent) {
    var list = qs("[data-comment-replies]", parent);
    var main;

    if (list) {
      return list;
    }

    main = qs(".status-comment-main", parent);

    if (!main) {
      return null;
    }

    list = document.createElement("div");
    list.className = "status-comment-replies";
    list.dataset.commentReplies = "true";
    list.dataset.commentId = parent.dataset.commentId || "";
    main.appendChild(list);

    return list;
  }
  function removeStatusComment(commentId) {
    var id = commentId ? String(commentId) : "";

    if (!id) {
      return;
    }

    qsa("[data-comment-id]" + dataSelector("data-comment-id", id)).forEach(function (node) {
      TinyCat.content.remove(node);
    });
  }
  function resetStatusCommentForm(form) {
    var replyContainer;
    var fields;
    var details;
    var parentField;

    if (!form || TinyCat.status.controllers.forms.statusFormAction(form) === "") {
      return;
    }

    if (TinyCat.status.controllers.forms.statusFormAction(form) === "comment") {
      parentField = qs('input[name="parent_id"]', form);
      details = form.closest(".status-reply-details");
      replyContainer = form.closest(".status-reply-form-container");
      if (!details && replyContainer && replyContainer.previousElementSibling) {
        details = qs(".status-reply-details", replyContainer.previousElementSibling);
      }
      form.reset();
      fields = qsa("textarea.status-comment-input", form);
      fields.forEach(function (field) {
        field.value = "";
        field.defaultValue = "";
        field.dispatchEvent(new Event("input", { bubbles: true }));
        field.dispatchEvent(new Event("change", { bubbles: true }));
      });
      qsa("[data-status-editor]", form).forEach(function (root) {
        if (TinyCat.status.controllers.editor.resetStatusEditor) {
          TinyCat.status.controllers.editor.resetStatusEditor(root);
        }
      });

      if (details && parseInt((parentField && parentField.value) || "0", 10) > 0) {
        details.open = false;
      }
    }
  }
  TinyCat.status.controllers.comments = { keepModalReplyVisible: keepModalReplyVisible, scheduleModalReplyVisibility: scheduleModalReplyVisibility, initCommentReplies: initCommentReplies, updateCommentLike: updateCommentLike, ensureCommentReplies: ensureCommentReplies, removeStatusComment: removeStatusComment, resetStatusCommentForm: resetStatusCommentForm };
}());
