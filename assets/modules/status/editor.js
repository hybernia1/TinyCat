import "../../core/dom.js";
import "../../core/api-client.js";
import "../../core/navigation.js";
import "./controller.js";
import "../../ui/avatar-rendering.js";
import "../../ui/component-builders.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var createElement = TinyCat.dom.create;
  var emit = TinyCat.dom.emit;
  var appendUserAvatar = TinyCat.ui.appendAvatar;
  var compactUrl = TinyCat.navigation.compact;
  var unwrapResult = TinyCat.api.unwrap;
  var statusEditorCounterId = 0;
  function statusTagValue(value) {
    var tag = String(value || "");
    return tag.length <= 32 && /^(?![0-9]+$)[a-z0-9]+(?:-[a-z0-9]+)*$/.test(tag) ? tag : "";
  }
  function statusUsername(value) {
    var text = String(value || "").trim();

    if (text.charAt(0) === "@") {
      text = text.slice(1);
    }

    return Array.from(text.replace(/[^\p{L}\p{N}\p{M}_.-]+/gu, "")).slice(0, 32).join("");
  }
  function statusEditorSource(root) {
    return qs("[data-status-editor-source]", root);
  }
  function statusEditorInput(root) {
    return qs("[data-status-editor-input]", root);
  }
  function statusEditorBox(root) {
    return qs("[data-status-editor-suggestions]", root);
  }
  function statusEditorCounter(root) {
    return root.__tinycatStatusCounter || qs("[data-status-editor-counter]", root);
  }
  function syncStatusEditorModalSize(root) {
    var panel = root && root.closest ? root.closest('.modal[data-modal-size="reading"] > .modal-panel') : null;

    if (!panel) {
      return;
    }

    panel.classList.toggle(
      "has-editor-suggestions",
      Boolean(qs("[data-status-editor-suggestions]:not([hidden])", panel))
    );
  }
  function statusEditorSuggestUrl(root) {
    var source = statusEditorSource(root);

    return source ? String(source.dataset.statusSuggestUrl || "") : "";
  }
  function statusEditorText(editor) {
    var text = String(editor.innerText || "").replace(/\r\n/g, "\n").replace(/\u00a0/g, " ");

    // Browsers may retain one or more line breaks in an otherwise empty
    // contenteditable editor. Treat that visual empty state as an empty draft.
    return text.trim() === "" ? "" : text;
  }
  function setStatusEditorText(editor, text, caret) {
    var target = Math.max(0, Math.min(Number(caret || 0), String(text || "").length));
    var walker;
    var node;
    var seen = 0;
    var selection;
    var range;

    editor.textContent = String(text || "");
    selection = window.getSelection && window.getSelection();

    if (!selection) {
      return;
    }

    range = document.createRange();
    walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
    node = walker.nextNode();

    while (node) {
      var next = seen + node.nodeValue.length;

      if (target <= next) {
        range.setStart(node, target - seen);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
        return;
      }

      seen = next;
      node = walker.nextNode();
    }

    if (!editor.firstChild) {
      editor.appendChild(document.createTextNode(""));
    }

    range.selectNodeContents(editor);
    range.collapse(false);
    selection.removeAllRanges();
    selection.addRange(range);
  }
  function statusSelectionOffsets(editor) {
    var selection = window.getSelection && window.getSelection();
    var range;
    var startRange;
    var endRange;

    if (!selection || selection.rangeCount === 0) {
      return { start: 0, end: 0 };
    }

    range = selection.getRangeAt(0);

    if (!editor.contains(range.startContainer) || !editor.contains(range.endContainer)) {
      return { start: statusEditorText(editor).length, end: statusEditorText(editor).length };
    }

    startRange = range.cloneRange();
    startRange.selectNodeContents(editor);
    startRange.setEnd(range.startContainer, range.startOffset);

    endRange = range.cloneRange();
    endRange.selectNodeContents(editor);
    endRange.setEnd(range.endContainer, range.endOffset);

    return {
      start: startRange.toString().length,
      end: endRange.toString().length
    };
  }
  function statusEditorCaret(editor) {
    return statusSelectionOffsets(editor).end;
  }
  function replaceStatusEditorRange(editor, start, end, value) {
    var text = statusEditorText(editor);
    var before = text.slice(0, start);
    var after = text.slice(end);
    var next = before + value + after;
    var caret = before.length + value.length;

    setStatusEditorText(editor, next, caret);
    editor.dispatchEvent(new Event("input", { bubbles: true }));
  }
  function statusEditorActiveToken(root) {
    var editor = statusEditorInput(root);
    var text;
    var caret;
    var before;
    var match;

    if (!editor) {
      return null;
    }

    text = statusEditorText(editor);
    caret = statusEditorCaret(editor);
    before = text.slice(0, caret);
    if (/(?:https?:\/\/|www\.|(?:[a-z0-9-]+\.)+[a-z]{2,}[/?#])[^\s]*$/i.test(before)) return null;
    match = /(?<![\p{L}\p{N}\p{M}_.@#-])(?:(@)([\p{L}\p{N}\p{M}_.-]*)|(#)([\p{L}\p{N}\p{M}_-]*))$/u.exec(before);

    if (!match) {
      return null;
    }

    var marker = match[1] || match[3], query = match[2] || match[4] || "";
    return {
      marker: marker,
      type: marker === "@" ? "user" : "tag",
      query: query,
      start: caret - query.length - 1,
      end: caret
    };
  }
  function syncStatusEditor(root) {
    var editor = statusEditorInput(root);
    var source = statusEditorSource(root);
    var counter = statusEditorCounter(root);
    var text;
    var max;
    var remaining;
    var template;

    if (!editor || !source) {
      return;
    }

    text = statusEditorText(editor);
    max = Number(source.getAttribute("maxlength") || 0);

    if (max > 0 && text.length > max) {
      text = text.slice(0, max);
      setStatusEditorText(editor, text, max);
    }

    source.value = text;
    if (counter && max > 0) {
      remaining = Math.max(0, max - text.length);
      template = counter.dataset.statusCounterTemplate || "{count}";
      counter.textContent = template.replace("{count}", String(remaining));
      counter.classList.toggle("is-warning", remaining <= Math.max(20, Math.floor(max * 0.1)));
      counter.classList.toggle("is-limit", remaining === 0);
    }
    emit(root, "tinycat:editor-sync", { value: text });
  }
  function hideStatusEditorSuggestions(root) {
    var box = statusEditorBox(root);

    root.__tinycatStatusToken = null;
    root.__tinycatStatusSuggestionIndex = 0;
    root.__tinycatStatusSuggestFingerprint = "";
    window.clearTimeout(root.__tinycatStatusSuggestTimer);

    if (root.__tinycatStatusSuggestAbort) {
      root.__tinycatStatusSuggestAbort.abort();
      root.__tinycatStatusSuggestAbort = null;
    }

    if (box) {
      box.hidden = true;
      box.innerHTML = "";
    }

    syncStatusEditorModalSize(root);
  }
  function selectStatusEditorSuggestion(root, index) {
    var box = statusEditorBox(root);
    var items = box ? qsa("[data-status-editor-suggestion]", box) : [];

    if (items.length === 0) {
      return;
    }

    root.__tinycatStatusSuggestionIndex = (index + items.length) % items.length;

    items.forEach(function (item, itemIndex) {
      var active = itemIndex === root.__tinycatStatusSuggestionIndex;
      item.classList.toggle("is-active", active);
      item.setAttribute("aria-selected", active ? "true" : "false");
    });
  }
  function renderStatusEditorSuggestionItems(root, items) {
    var box = statusEditorBox(root);
    var seen = {};

    if (!box) {
      return;
    }

    box.innerHTML = "";
    root.__tinycatStatusSuggestionIndex = 0;

    items.forEach(function (item) {
      var type = item && item.type === "user" ? "user" : "tag";
      var value = type === "user" ? statusUsername(item.value || item.title) : statusTagValue(item.value);
      var title = item.title || (type === "user" ? "@" + value : "#" + value);
      var key = type + ":" + value;
      var button;
      var text;

      if (!value || seen[key]) {
        return;
      }

      seen[key] = true;
      button = document.createElement("button");
      button.className = "status-editor-suggestion";
      button.type = "button";
      button.dataset.statusEditorSuggestion = value;
      button.dataset.statusEditorSuggestionType = type;
      button.setAttribute("role", "option");

      if (type === "user") {
        appendUserAvatar(button, item.avatar_url, "", "status-editor-suggestion-avatar", "@");
      }

      text = createElement("span", "status-editor-suggestion-text");
      text.appendChild(createElement("strong", "", title));
      button.appendChild(text);
      box.appendChild(button);
    });

    if (!box.firstElementChild) {
      box.hidden = true;
      syncStatusEditorModalSize(root);
      return;
    }

    box.hidden = false;
    syncStatusEditorModalSize(root);
    selectStatusEditorSuggestion(root, 0);
  }
  function requestStatusEditorSuggestions(root, token) {
    var suggestUrl = statusEditorSuggestUrl(root);
    var fingerprint;

    window.clearTimeout(root.__tinycatStatusSuggestTimer);
    root.__tinycatStatusSuggestFingerprint = "";
    if (root.__tinycatStatusSuggestAbort) root.__tinycatStatusSuggestAbort.abort();
    root.__tinycatStatusSuggestAbort = null;

    if (!suggestUrl || !token || !token.query || (token.type === "tag" && /^\p{N}+$/u.test(token.query))) {
      return;
    }

    fingerprint = token.type + ":" + token.start + ":" + token.end + ":" + token.query;
    root.__tinycatStatusSuggestFingerprint = fingerprint;

    root.__tinycatStatusSuggestTimer = window.setTimeout(function () {
      var url = new URL(suggestUrl, window.location.href);
      var controller = window.AbortController ? new AbortController() : null;

      root.__tinycatStatusSuggestAbort = controller;
      url.searchParams.set("type", token.type);
      url.searchParams.set("q", token.query);
      url.searchParams.set("limit", "8");

      TinyCat.api.request(compactUrl(url), {
        method: "GET",
        cache: "no-store",
        signal: controller ? controller.signal : undefined
      })
        .then(function (response) {
          var data = unwrapResult(response) || {};
          var items = token.type === "user" ? data.users : data.tags;

          if ((controller && controller.signal.aborted) || root.isConnected === false || root.__tinycatStatusSuggestFingerprint !== fingerprint) {
            return;
          }

          renderStatusEditorSuggestionItems(root, Array.isArray(items) ? items : []);
        })
        .catch(function (error) {
          if (error && error.name === "AbortError") {
            return;
          }
        });
    }, 120);
  }
  function renderStatusEditorSuggestions(root) {
    var box = statusEditorBox(root);
    var token = statusEditorActiveToken(root);
    if (!box || !token) {
      hideStatusEditorSuggestions(root);
      return;
    }

    root.__tinycatStatusToken = token;
    renderStatusEditorSuggestionItems(root, []);
    requestStatusEditorSuggestions(root, token);
  }
  function insertStatusEditorSuggestion(root, value, type) {
    var editor = statusEditorInput(root);
    var token = root.__tinycatStatusToken || statusEditorActiveToken(root);
    var suggestionType = type || (token ? token.type : "tag");
    var marker = suggestionType === "user" ? "@" : "#";
    var clean = suggestionType === "user" ? statusUsername(value) : statusTagValue(value);

    if (!editor || !token || !clean) {
      hideStatusEditorSuggestions(root);
      return;
    }

    replaceStatusEditorRange(editor, token.start, token.end, marker + clean + " ");
    hideStatusEditorSuggestions(root);
    editor.focus();
  }
  function initStatusEditorRoot(root) {
    var source = statusEditorSource(root);
    var shell;
    var editor;
    var box;
    var meta;
    var counter;
    var counterId;
    var form;
    var metaSlot;

    if (root.dataset.statusEditorReady === "true" || !source) {
      return;
    }

    root.dataset.statusEditorReady = "true";

    shell = document.createElement("div");
    shell.className = "status-editor-shell";

    editor = document.createElement("div");
    editor.className = "status-editor-input";
    editor.dataset.statusEditorInput = "true";
    editor.dataset.validationFor = source.name;
    editor.dataset.placeholder = source.dataset.statusPlaceholder || source.getAttribute("placeholder") || "";
    editor.setAttribute("contenteditable", "plaintext-only");
    editor.setAttribute("role", "textbox");
    editor.setAttribute("aria-multiline", "true");
    editor.setAttribute("aria-label", source.getAttribute("placeholder") || TinyCat.i18n.t("account.status_body"));
    if (source.hasAttribute("aria-labelledby")) { editor.setAttribute("aria-labelledby", source.getAttribute("aria-labelledby")); }
    editor.spellcheck = true;

    box = document.createElement("div");
    box.className = "status-editor-suggestions";
    box.dataset.statusEditorSuggestions = "true";
    box.setAttribute("role", "listbox");
    box.hidden = true;

    shell.appendChild(editor);
    shell.appendChild(box);
    source.insertAdjacentElement("beforebegin", shell);

    if (Number(source.getAttribute("maxlength") || 0) > 0 && source.dataset.statusEditorCounterDisabled !== "true") {
      statusEditorCounterId += 1;
      counterId = source.id ? source.id + "-counter" : "status-editor-counter-" + statusEditorCounterId;
      meta = document.createElement("div");
      meta.className = "status-editor-meta";
      counter = document.createElement("small");
      counter.id = counterId;
      counter.className = "status-editor-counter";
      counter.dataset.statusEditorCounter = "true";
      counter.dataset.statusCounterTemplate = source.dataset.statusCounter || "{count}";
      counter.setAttribute("aria-live", "polite");
      root.__tinycatStatusCounter = counter;
      meta.appendChild(counter);
      form = root.closest("form");
      metaSlot = form ? qs("[data-status-editor-meta-slot]", form) : null;

      if (metaSlot) {
        metaSlot.appendChild(meta);
      } else {
        shell.insertAdjacentElement("afterend", meta);
      }

      editor.setAttribute("aria-describedby", counterId);
    }

    source.hidden = true;

    setStatusEditorText(editor, source.value || "", (source.value || "").length);
    syncStatusEditor(root);

    editor.addEventListener("input", function () {
      syncStatusEditor(root);
      renderStatusEditorSuggestions(root);
    });

    editor.addEventListener("click", function () {
      renderStatusEditorSuggestions(root);
    });

    editor.addEventListener("focus", function () {
      renderStatusEditorSuggestions(root);
    });

    editor.addEventListener("blur", function () {
      window.setTimeout(function () {
        if (!root.contains(document.activeElement)) {
          hideStatusEditorSuggestions(root);
        }
      }, 120);
    });

    editor.addEventListener("paste", function (event) {
      var clipboard = event.clipboardData || window.clipboardData;
      var pasted = clipboard ? clipboard.getData("text") : "";
      var selection = statusSelectionOffsets(editor);

      if (pasted === "") {
        return;
      }

      event.preventDefault();
      replaceStatusEditorRange(editor, selection.start, selection.end, pasted);
    });

    editor.addEventListener("keydown", function (event) {
      var items = box.hidden ? [] : qsa("[data-status-editor-suggestion]", box);
      var index = root.__tinycatStatusSuggestionIndex || 0;

      if (items.length > 0) {
        if (event.key === "ArrowDown") {
          event.preventDefault();
          selectStatusEditorSuggestion(root, index + 1);
          return;
        } else if (event.key === "ArrowUp") {
          event.preventDefault();
          selectStatusEditorSuggestion(root, index - 1);
          return;
        } else if ((event.key === "Enter" && !event.shiftKey) || event.key === "Tab") {
          event.preventDefault();
          insertStatusEditorSuggestion(
            root,
            items[root.__tinycatStatusSuggestionIndex || 0].dataset.statusEditorSuggestion,
            items[root.__tinycatStatusSuggestionIndex || 0].dataset.statusEditorSuggestionType
          );
          return;
        } else if (event.key === "Escape") {
          event.preventDefault();
          hideStatusEditorSuggestions(root);
          return;
        }
      }
    });

    box.addEventListener("pointerdown", function (event) {
      var suggestion = event.target.closest && event.target.closest("[data-status-editor-suggestion]");

      if (!suggestion) {
        return;
      }

      event.preventDefault();
      insertStatusEditorSuggestion(root, suggestion.dataset.statusEditorSuggestion, suggestion.dataset.statusEditorSuggestionType);
    });

    form = root.closest("form");

    if (form && form.dataset.statusEditorSubmitReady !== "true") {
      form.dataset.statusEditorSubmitReady = "true";
      form.addEventListener("submit", function () {
        qsa("[data-status-editor]", form).forEach(syncStatusEditor);
      }, true);
    }
  }
  function resetStatusEditor (root) {
    var source = statusEditorSource(root);
    var editor = statusEditorInput(root);

    if (!source || !editor) {
      return;
    }

    setStatusEditorText(editor, source.value || "", (source.value || "").length);
    syncStatusEditor(root);
    hideStatusEditorSuggestions(root);
  }
  function initStatusEditors (scope) {
    qsa("[data-status-editor]", scope || document).forEach(initStatusEditorRoot);
  }
  TinyCat.status.controllers.editor = { statusUsername: statusUsername, statusEditorSource: statusEditorSource, statusEditorInput: statusEditorInput, statusEditorBox: statusEditorBox, statusEditorCounter: statusEditorCounter, syncStatusEditorModalSize: syncStatusEditorModalSize, statusEditorSuggestUrl: statusEditorSuggestUrl, statusEditorText: statusEditorText, setStatusEditorText: setStatusEditorText, statusSelectionOffsets: statusSelectionOffsets, statusEditorCaret: statusEditorCaret, replaceStatusEditorRange: replaceStatusEditorRange, statusEditorActiveToken: statusEditorActiveToken, syncStatusEditor: syncStatusEditor, hideStatusEditorSuggestions: hideStatusEditorSuggestions, selectStatusEditorSuggestion: selectStatusEditorSuggestion, renderStatusEditorSuggestionItems: renderStatusEditorSuggestionItems, requestStatusEditorSuggestions: requestStatusEditorSuggestions, renderStatusEditorSuggestions: renderStatusEditorSuggestions, insertStatusEditorSuggestion: insertStatusEditorSuggestion, initStatusEditorRoot: initStatusEditorRoot, resetStatusEditor: resetStatusEditor, initStatusEditors: initStatusEditors };
}());
