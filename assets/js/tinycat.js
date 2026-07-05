(function () {
  "use strict";

  var TinyCat = window.TinyCat || {};
  var activeModal = null;

  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function qsa(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function ready(callback) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback, { once: true });
      return;
    }

    callback();
  }

  function emit(element, name, detail) {
    element.dispatchEvent(new CustomEvent(name, {
      bubbles: true,
      detail: detail || {}
    }));
  }

  function getCsrfToken() {
    var meta = qs('meta[name="csrf-token"]');

    if (meta && meta.content) {
      return meta.content;
    }

    var input = qs('input[name="_csrf"]');

    return input ? input.value : "";
  }

  function normalizeBody(body, headers) {
    if (!body || body instanceof FormData || body instanceof Blob || typeof body === "string") {
      return body;
    }

    if (body instanceof URLSearchParams) {
      return body;
    }

    headers["Content-Type"] = headers["Content-Type"] || "application/json";
    return JSON.stringify(body);
  }

  async function parseResponse(response) {
    var type = response.headers.get("content-type") || "";

    if (type.indexOf("application/json") !== -1) {
      return response.json();
    }

    return response.text();
  }

  function getModal(target) {
    if (!target) {
      return null;
    }

    if (target instanceof Element) {
      return target;
    }

    if (target.charAt(0) === "#" || target.charAt(0) === ".") {
      return qs(target);
    }

    return document.getElementById(target);
  }

  function focusFirst(modal) {
    var focusable = qs('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])', modal);

    if (focusable) {
      focusable.focus();
    }
  }

  function setLoading(element, loading) {
    if (!element) {
      return;
    }

    element.classList.toggle("is-loading", loading);
    element.setAttribute("aria-busy", loading ? "true" : "false");

    qsa("button, input, select, textarea", element).forEach(function (field) {
      if (loading) {
        field.dataset.tinycatWasDisabled = field.disabled ? "true" : "false";
        field.disabled = true;
      } else if (field.dataset.tinycatWasDisabled !== "true") {
        field.disabled = false;
        delete field.dataset.tinycatWasDisabled;
      }
    });
  }

  function renderTarget(target, data) {
    if (!target) {
      return;
    }

    var html = data;

    if (data && typeof data === "object") {
      html = data.html || data.content || data.view || "";
    }

    if (typeof html === "string") {
      target.innerHTML = html;
    }
  }

  function clearErrors(form) {
    qsa('[aria-invalid="true"]', form).forEach(function (field) {
      field.removeAttribute("aria-invalid");
    });

    qsa("[data-field-error]", form).forEach(function (error) {
      error.remove();
    });
  }

  function applyErrors(form, errors) {
    if (!errors || typeof errors !== "object") {
      return;
    }

    Object.keys(errors).forEach(function (name) {
      var field = form.elements.namedItem(name);

      if (!field) {
        return;
      }

      var input = field instanceof RadioNodeList ? field[0] : field;
      var message = Array.isArray(errors[name]) ? errors[name][0] : errors[name];

      input.setAttribute("aria-invalid", "true");

      var error = document.createElement("div");
      error.className = "field-error";
      error.dataset.fieldError = name;
      error.textContent = String(message || "");

      input.insertAdjacentElement("afterend", error);
    });
  }

  function handleResult(source, data, target) {
    if (data && typeof data === "object") {
      if (data.redirect) {
        window.location.assign(data.redirect);
        return;
      }

      if (data.message) {
        TinyCat.toast(data.message, data.type || "info");
      }
    }

    renderTarget(target, data);
    emit(source, "tinycat:success", { data: data, target: target });
  }

  function parseList(value) {
    return String(value || "")
      .split(",")
      .map(function (item) {
        return item.trim().replace(/\s+/g, " ");
      })
      .filter(Boolean);
  }

  function uniqueList(values) {
    var seen = {};

    return values.filter(function (value) {
      var key = value.toLowerCase();

      if (seen[key]) {
        return false;
      }

      seen[key] = true;
      return true;
    });
  }

  function formatBytes(bytes) {
    var size = Number(bytes || 0);
    var units = ["B", "KB", "MB", "GB"];
    var index = 0;

    while (size >= 1024 && index < units.length - 1) {
      size = size / 1024;
      index += 1;
    }

    return (index === 0 ? size : size.toFixed(1)) + " " + units[index];
  }

  TinyCat.qs = qs;
  TinyCat.qsa = qsa;
  TinyCat.ready = ready;

  TinyCat.request = async function (url, options) {
    var settings = options || {};
    var headers = Object.assign({
      "Accept": "application/json, text/html;q=0.9, */*;q=0.8",
      "X-Requested-With": "XMLHttpRequest"
    }, settings.headers || {});
    var csrf = getCsrfToken();

    if (csrf && !headers["X-CSRF-Token"]) {
      headers["X-CSRF-Token"] = csrf;
    }

    var response = await fetch(url, Object.assign({}, settings, {
      headers: headers,
      body: normalizeBody(settings.body, headers)
    }));
    var data = await parseResponse(response);

    if (!response.ok) {
      var error = new Error(response.statusText || "Request failed");
      error.response = response;
      error.data = data;
      throw error;
    }

    return data;
  };

  TinyCat.openModal = function (target) {
    var modal = getModal(target);

    if (!modal) {
      return null;
    }

    activeModal = modal;
    modal.dataset.open = "true";
    modal.setAttribute("aria-hidden", "false");
    modal.__tinycatPreviousFocus = document.activeElement;
    document.body.classList.add("has-modal");
    focusFirst(modal);
    emit(modal, "tinycat:modal-open");

    return modal;
  };

  TinyCat.closeModal = function (target) {
    var modal = getModal(target) || activeModal;

    if (!modal) {
      return;
    }

    modal.dataset.open = "false";
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("has-modal");

    if (modal.__tinycatPreviousFocus && modal.__tinycatPreviousFocus.focus) {
      modal.__tinycatPreviousFocus.focus();
    }

    if (activeModal === modal) {
      activeModal = null;
    }

    emit(modal, "tinycat:modal-close");
  };

  TinyCat.toggleModal = function (target) {
    var modal = getModal(target);

    if (!modal) {
      return null;
    }

    if (modal.dataset.open === "true") {
      TinyCat.closeModal(modal);
      return modal;
    }

    return TinyCat.openModal(modal);
  };

  TinyCat.toast = function (message, type, timeout) {
    var stack = qs(".toast-stack");

    if (!stack) {
      stack = document.createElement("div");
      stack.className = "toast-stack";
      stack.setAttribute("aria-live", "polite");
      document.body.appendChild(stack);
    }

    var toast = document.createElement("div");
    toast.className = "toast toast-" + (type || "info");
    toast.textContent = String(message || "");
    stack.appendChild(toast);

    window.setTimeout(function () {
      toast.remove();
    }, timeout || 3600);

    return toast;
  };

  TinyCat.initModals = function () {
    document.addEventListener("click", function (event) {
      var target = event.target;
      var open = target.closest && target.closest("[data-modal-open]");
      var close = target.closest && target.closest("[data-modal-close]");

      if (open) {
        event.preventDefault();
        TinyCat.openModal(open.dataset.modalOpen);
        return;
      }

      if (close || (target.classList && target.classList.contains("modal-backdrop"))) {
        event.preventDefault();
        TinyCat.closeModal(close ? close.closest(".modal") : target.closest(".modal"));
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && activeModal) {
        TinyCat.closeModal(activeModal);
      }
    });
  };

  TinyCat.initAjax = function () {
    document.addEventListener("submit", async function (event) {
      var form = event.target.closest && event.target.closest("form[data-ajax-form]");

      if (!form) {
        return;
      }

      if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
        return;
      }

      event.preventDefault();
      clearErrors(form);

      var target = form.dataset.ajaxTarget ? qs(form.dataset.ajaxTarget) : null;
      var method = (form.getAttribute("method") || "POST").toUpperCase();
      var action = form.getAttribute("action") || window.location.href;

      try {
        setLoading(form, true);
        emit(form, "tinycat:before", { target: target });
        var data = await TinyCat.request(action, {
          method: method,
          body: new FormData(form)
        });
        handleResult(form, data, target);
      } catch (error) {
        if (error.data && error.data.errors) {
          applyErrors(form, error.data.errors);
        }

        TinyCat.toast((error.data && error.data.message) || error.message || "Request failed", "danger");
        emit(form, "tinycat:error", { error: error, target: target });
      } finally {
        setLoading(form, false);
      }
    });

    document.addEventListener("click", async function (event) {
      var link = event.target.closest && event.target.closest("[data-ajax]");

      if (!link) {
        return;
      }

      if (link.dataset.confirm && !window.confirm(link.dataset.confirm)) {
        event.preventDefault();
        return;
      }

      event.preventDefault();

      var url = link.dataset.url || link.getAttribute("href");
      var target = link.dataset.ajaxTarget ? qs(link.dataset.ajaxTarget) : null;
      var method = (link.dataset.method || "GET").toUpperCase();

      try {
        setLoading(link, true);
        emit(link, "tinycat:before", { target: target });
        var data = await TinyCat.request(url, { method: method });
        handleResult(link, data, target);
      } catch (error) {
        TinyCat.toast((error.data && error.data.message) || error.message || "Request failed", "danger");
        emit(link, "tinycat:error", { error: error, target: target });
      } finally {
        setLoading(link, false);
      }
    });
  };

  TinyCat.initConfirm = function () {
    document.addEventListener("click", function (event) {
      var trigger = event.target.closest && event.target.closest("[data-confirm]:not(form):not([data-ajax])");

      if (trigger && !window.confirm(trigger.dataset.confirm)) {
        event.preventDefault();
      }
    });
  };

  TinyCat.initToasts = function () {
    document.addEventListener("click", function (event) {
      var trigger = event.target.closest && event.target.closest("[data-toast]");

      if (!trigger) {
        return;
      }

      event.preventDefault();
      TinyCat.toast(trigger.dataset.toast, trigger.dataset.toastType || "info");
    });
  };

  TinyCat.activateTab = function (tab, focus) {
    var root = tab.closest("[data-tabs]");

    if (!root) {
      return;
    }

    var name = tab.dataset.tab;

    qsa("[data-tab]", root).forEach(function (item) {
      var selected = item === tab;
      item.setAttribute("aria-selected", selected ? "true" : "false");
      item.tabIndex = selected ? 0 : -1;
    });

    qsa("[data-tab-panel]", root).forEach(function (panel) {
      panel.hidden = panel.dataset.tabPanel !== name;
    });

    if (focus) {
      tab.focus();
    }

    emit(root, "tinycat:tab", { tab: tab, name: name });
  };

  TinyCat.initTabs = function () {
    qsa("[data-tabs]").forEach(function (root) {
      var selected = qs('[data-tab][aria-selected="true"]', root) || qs("[data-tab]", root);

      if (selected) {
        TinyCat.activateTab(selected, false);
      }
    });

    document.addEventListener("click", function (event) {
      var tab = event.target.closest && event.target.closest("[data-tab]");

      if (!tab) {
        return;
      }

      event.preventDefault();
      TinyCat.activateTab(tab, false);
    });

    document.addEventListener("keydown", function (event) {
      var tab = event.target.closest && event.target.closest("[data-tab]");

      if (!tab) {
        return;
      }

      var root = tab.closest("[data-tabs]");
      var tabs = qsa("[data-tab]", root);
      var index = tabs.indexOf(tab);
      var next = null;

      if (event.key === "ArrowRight" || event.key === "ArrowDown") {
        next = tabs[(index + 1) % tabs.length];
      } else if (event.key === "ArrowLeft" || event.key === "ArrowUp") {
        next = tabs[(index - 1 + tabs.length) % tabs.length];
      } else if (event.key === "Home") {
        next = tabs[0];
      } else if (event.key === "End") {
        next = tabs[tabs.length - 1];
      }

      if (next) {
        event.preventDefault();
        TinyCat.activateTab(next, true);
      }
    });
  };

  TinyCat.renderTagifier = function (root) {
    var list = qs("[data-tag-list]", root);
    var input = qs("[data-tag-input]", root);
    var hidden = qs("[data-tag-value]", root);
    var tags = root.__tinycatTags || [];

    if (list) {
      list.innerHTML = "";
      tags.forEach(function (value, index) {
        var tag = document.createElement("span");
        var remove = document.createElement("button");

        tag.className = "tag";
        tag.dataset.tag = value;
        tag.appendChild(document.createTextNode(value));

        remove.className = "tag-remove";
        remove.type = "button";
        remove.dataset.tagRemove = String(index);
        remove.setAttribute("aria-label", "Remove tag");
        remove.textContent = "x";
        tag.appendChild(remove);
        list.appendChild(tag);
      });
    }

    if (hidden) {
      hidden.value = root.dataset.tagFormat === "json" ? JSON.stringify(tags) : tags.join(",");
    }

    TinyCat.renderTagSuggestions(root, input ? input.value : "");
  };

  TinyCat.renderTagSuggestions = function (root, query) {
    var box = qs("[data-tag-suggestions]", root);
    var input = qs("[data-tag-input]", root);
    var tags = root.__tinycatTags || [];
    var selected = tags.map(function (value) {
      return value.toLowerCase();
    });
    var needle = String(query || "").trim().toLowerCase();
    var suggestions = uniqueList(parseList(root.dataset.suggestions))
      .filter(function (value) {
        var lower = value.toLowerCase();
        return selected.indexOf(lower) === -1 && (!needle || lower.indexOf(needle) !== -1);
      })
      .slice(0, 8);

    if (!box) {
      return;
    }

    box.innerHTML = "";

    if (suggestions.length === 0 || (!needle && input !== document.activeElement)) {
      box.hidden = true;
      return;
    }

    suggestions.forEach(function (value) {
      var button = document.createElement("button");
      button.className = "tag-suggestion";
      button.type = "button";
      button.dataset.tagSuggestion = value;
      button.textContent = value;
      box.appendChild(button);
    });

    box.hidden = false;
  };

  TinyCat.addTag = function (root, value) {
    var clean = String(value || "").trim().replace(/\s+/g, " ");
    var input = qs("[data-tag-input]", root);

    if (!clean) {
      return;
    }

    root.__tinycatTags = uniqueList((root.__tinycatTags || []).concat(clean));

    if (input) {
      input.value = "";
      input.focus();
    }

    TinyCat.renderTagifier(root);
    emit(root, "tinycat:tags", { tags: root.__tinycatTags });
  };

  TinyCat.removeTag = function (root, index) {
    root.__tinycatTags = (root.__tinycatTags || []).filter(function (_value, itemIndex) {
      return itemIndex !== index;
    });

    TinyCat.renderTagifier(root);
    emit(root, "tinycat:tags", { tags: root.__tinycatTags });
  };

  TinyCat.initTagifiers = function () {
    qsa("[data-tagifier]").forEach(function (root) {
      var hidden = qs("[data-tag-value]", root);
      var values = hidden && hidden.value ? hidden.value : root.dataset.value;

      if (root.dataset.tagifierReady === "true") {
        return;
      }

      root.dataset.tagifierReady = "true";
      root.__tinycatTags = uniqueList(parseList(values));
      TinyCat.renderTagifier(root);
    });

    document.addEventListener("input", function (event) {
      var input = event.target.closest && event.target.closest("[data-tag-input]");

      if (input) {
        TinyCat.renderTagSuggestions(input.closest("[data-tagifier]"), input.value);
      }
    });

    document.addEventListener("keydown", function (event) {
      var input = event.target.closest && event.target.closest("[data-tag-input]");
      var root;

      if (!input) {
        return;
      }

      root = input.closest("[data-tagifier]");

      if (event.key === "Enter" || event.key === ",") {
        event.preventDefault();
        TinyCat.addTag(root, input.value);
      } else if (event.key === "Backspace" && input.value === "") {
        TinyCat.removeTag(root, (root.__tinycatTags || []).length - 1);
      } else if (event.key === "Escape") {
        var suggestions = qs("[data-tag-suggestions]", root);
        if (suggestions) {
          suggestions.hidden = true;
        }
      }
    });

    document.addEventListener("paste", function (event) {
      var input = event.target.closest && event.target.closest("[data-tag-input]");
      var root;
      var pasted;

      if (!input) {
        return;
      }

      pasted = (event.clipboardData || window.clipboardData).getData("text");

      if (pasted.indexOf(",") === -1) {
        return;
      }

      event.preventDefault();
      root = input.closest("[data-tagifier]");
      parseList(pasted).forEach(function (value) {
        TinyCat.addTag(root, value);
      });
    });

    document.addEventListener("click", function (event) {
      var remove = event.target.closest && event.target.closest("[data-tag-remove]");
      var suggestion = event.target.closest && event.target.closest("[data-tag-suggestion]");
      var box = event.target.closest && event.target.closest(".tag-box");
      var root;
      var input;

      if (remove) {
        event.preventDefault();
        TinyCat.removeTag(remove.closest("[data-tagifier]"), Number(remove.dataset.tagRemove));
        return;
      }

      if (suggestion) {
        event.preventDefault();
        root = suggestion.closest("[data-tagifier]");
        TinyCat.addTag(root, suggestion.dataset.tagSuggestion);
        return;
      }

      if (box) {
        input = qs("[data-tag-input]", box);

        if (input) {
          input.focus();
        }
      }
    });

    document.addEventListener("focusout", function (event) {
      var root = event.target.closest && event.target.closest("[data-tagifier]");

      if (!root) {
        return;
      }

      window.setTimeout(function () {
        var suggestions = qs("[data-tag-suggestions]", root);

        if (suggestions && !root.contains(document.activeElement)) {
          suggestions.hidden = true;
        }
      }, 120);
    });
  };

  function sortableItems(root) {
    return qsa("[data-sortable-item]", root);
  }

  function getDragAfterElement(root, y) {
    return sortableItems(root)
      .filter(function (item) {
        return item.dataset.dragging !== "true";
      })
      .reduce(function (closest, child) {
        var box = child.getBoundingClientRect();
        var offset = y - box.top - box.height / 2;

        if (offset < 0 && offset > closest.offset) {
          return { offset: offset, element: child };
        }

        return closest;
      }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
  }

  TinyCat.syncSortable = function (root) {
    var order = sortableItems(root).map(function (item, index) {
      var indexTarget = qs("[data-sortable-index]", item);

      if (indexTarget) {
        indexTarget.textContent = String(index + 1);
      }

      return item.dataset.id || item.id || String(index + 1);
    });
    var input = root.dataset.sortableInput ? qs(root.dataset.sortableInput) : null;

    if (input) {
      input.value = order.join(",");
    }

    emit(root, "tinycat:sort", { order: order });
  };

  TinyCat.initSortable = function () {
    qsa("[data-sortable]").forEach(function (root) {
      sortableItems(root).forEach(function (item) {
        item.draggable = true;
      });
      TinyCat.syncSortable(root);
    });

    document.addEventListener("dragstart", function (event) {
      var item = event.target.closest && event.target.closest("[data-sortable-item]");

      if (!item) {
        return;
      }

      item.dataset.dragging = "true";
      event.dataTransfer.effectAllowed = "move";
      event.dataTransfer.setData("text/plain", item.dataset.id || item.id || "");
    });

    document.addEventListener("dragover", function (event) {
      var root = event.target.closest && event.target.closest("[data-sortable]");
      var dragging = qs('[data-sortable-item][data-dragging="true"]');
      var after;

      if (!root || !dragging || !root.contains(dragging)) {
        return;
      }

      event.preventDefault();
      after = getDragAfterElement(root, event.clientY);

      if (after === null) {
        root.appendChild(dragging);
      } else if (after !== dragging) {
        root.insertBefore(dragging, after);
      }
    });

    document.addEventListener("drop", function (event) {
      var root = event.target.closest && event.target.closest("[data-sortable]");

      if (!root) {
        return;
      }

      event.preventDefault();
      TinyCat.syncSortable(root);
    });

    document.addEventListener("dragend", function () {
      qsa("[data-sortable]").forEach(function (root) {
        sortableItems(root).forEach(function (item) {
          delete item.dataset.dragging;
          delete item.dataset.dragOver;
        });
        TinyCat.syncSortable(root);
      });
    });
  };

  TinyCat.renderDropzoneFiles = function (root, files) {
    var target = qs("[data-dropzone-files]", root);

    if (!target) {
      return;
    }

    target.innerHTML = "";

    Array.prototype.slice.call(files || []).forEach(function (file) {
      var item = document.createElement("div");
      var name = document.createElement("strong");
      var size = document.createElement("span");

      item.className = "dropzone-file";
      name.textContent = file.name;
      size.className = "table-meta";
      size.textContent = formatBytes(file.size);
      item.appendChild(name);
      item.appendChild(size);
      target.appendChild(item);
    });
  };

  TinyCat.initDropzones = function () {
    qsa("[data-dropzone]").forEach(function (root) {
      var input = qs('input[type="file"]', root);

      if (input) {
        input.addEventListener("change", function () {
          TinyCat.renderDropzoneFiles(root, input.files);
          emit(root, "tinycat:files", { files: input.files });
        });
      }
    });

    ["dragenter", "dragover"].forEach(function (name) {
      document.addEventListener(name, function (event) {
        var root = event.target.closest && event.target.closest("[data-dropzone]");

        if (!root) {
          return;
        }

        event.preventDefault();
        root.dataset.dragover = "true";
      });
    });

    document.addEventListener("dragleave", function (event) {
      var root = event.target.closest && event.target.closest("[data-dropzone]");

      if (root && !root.contains(event.relatedTarget)) {
        root.dataset.dragover = "false";
      }
    });

    document.addEventListener("drop", function (event) {
      var root = event.target.closest && event.target.closest("[data-dropzone]");
      var input;

      if (!root) {
        return;
      }

      event.preventDefault();
      root.dataset.dragover = "false";
      input = qs('input[type="file"]', root);

      if (input) {
        input.files = event.dataTransfer.files;
      }

      TinyCat.renderDropzoneFiles(root, event.dataTransfer.files);
      emit(root, "tinycat:files", { files: event.dataTransfer.files });
    });
  };

  TinyCat.init = function () {
    TinyCat.initModals();
    TinyCat.initAjax();
    TinyCat.initConfirm();
    TinyCat.initToasts();
    TinyCat.initTabs();
    TinyCat.initTagifiers();
    TinyCat.initSortable();
    TinyCat.initDropzones();
  };

  window.TinyCat = TinyCat;
  ready(TinyCat.init);
}());
