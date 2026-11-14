import "../component-builders.js";
import "../../core/dates.js";
import "../content-updates.js";
import "../../core/dom.js";
import "./editor.js";

import "./runtime.js";
import "../dialogs.js";
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
  function initCommentReplies () {
    if (TinyCat.__commentReplyEventsBound === true) {
      return;
    }

    TinyCat.__commentReplyEventsBound = true;

    document.addEventListener("toggle", function (event) {
      var details = event.target;
      var editorRoot;
      var input;
      var length;

      if (!details || !details.matches || !details.matches(".status-reply-details") || !details.open) {
        return;
      }

      editorRoot = qs("[data-status-editor]", details.closest(".status-comment-main"));
      input = (editorRoot ? TinyCat.publishing.controllers.editor.getInput(editorRoot) : null) || qs(".status-reply-form-container .status-comment-input", details.closest(".status-comment-main"));

      if (!input) {
        return;
      }

      input.focus();
      length = input.isContentEditable ? TinyCat.publishing.controllers.editor.getText(input).length : input.value.length;

      if (input.isContentEditable) {
        TinyCat.publishing.controllers.editor.setText(input, TinyCat.publishing.controllers.editor.getText(input), length);
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

    if (!form || String((form.elements.namedItem("action") || {}).value || "") === "") {
      return;
    }

    if (String((form.elements.namedItem("action") || {}).value || "") === "comment") {
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
        if (TinyCat.publishing.controllers.editor.reset) {
          TinyCat.publishing.controllers.editor.reset(root);
        }
      });

      if (details && parseInt((parentField && parentField.value) || "0", 10) > 0) {
        details.open = false;
      }
    }
  }
  function historyContent(revisions) {
    var T = TinyCat, U = T.ui, e = T.dom.element, content = e("div");
        content.className = "status-comment-history";
        revisions.forEach(function (revision) {
          var change = revision.changes && revision.changes.body || {};
          var entry = e("article", { "class": "status-comment-history-entry" });
          var at = revision.at || revision.created_at || "";
          entry.appendChild(e("time", { "class": "status-comment-history-meta", datetime: at }, T.dates && T.dates.format ? T.dates.format(at) : at));
          var before = String(change.from || ""), after = String(change.to || ""), start = 0, end = 0;
          while (start < before.length && start < after.length && before[start] === after[start]) { start++; }
          while (end < before.length - start && end < after.length - start && before[before.length - end - 1] === after[after.length - end - 1]) { end++; }
          [[before, "del", "removed"], [after, "ins", "added"]].forEach(function (version) {
            var row = e("div", { "class": "status-comment-history-diff" });
            row.appendChild(e("span", { "class": "status-comment-diff-marker", "aria-hidden": "true" }, version[1] === "del" ? "-" : "+"));
            row.appendChild(document.createTextNode(version[0].slice(0, start)));
            row.appendChild(e(version[1], { "class": "status-comment-diff-" + version[2] }, version[0].slice(start, version[0].length - end)));
            row.appendChild(document.createTextNode(end ? version[0].slice(-end) : ""));
            entry.appendChild(row);
          });
          content.appendChild(entry);
        });
    return content;
  }
  TinyCat.publishing.controllers.comments = { historyContent: historyContent, keepModalReplyVisible: keepModalReplyVisible, scheduleModalReplyVisibility: scheduleModalReplyVisibility, initCommentReplies: initCommentReplies, updateCommentLike: updateCommentLike, ensureCommentReplies: ensureCommentReplies, removeStatusComment: removeStatusComment, resetStatusCommentForm: resetStatusCommentForm };
}());
