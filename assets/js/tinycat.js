(function () {
  "use strict";

  var TinyCat = window.TinyCat || {};
  var activeModal = null;
  var modalStack = [];

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

  function compactUrl(url) {
    if (url.origin === window.location.origin) {
      return url.pathname + url.search + url.hash;
    }

    return url.toString();
  }

  function urlWithFormData(action, formData, omit) {
    var url = new URL(action || window.location.href, window.location.href);
    var ignored = omit || [];
    var keys = [];

    formData.forEach(function (_value, key) {
      if (keys.indexOf(key) === -1) {
        keys.push(key);
      }
    });

    keys.forEach(function (key) {
      url.searchParams.delete(key);
    });

    formData.forEach(function (value, key) {
      var stringValue = String(value || "");

      if (ignored.indexOf(key) !== -1 || stringValue === "") {
        return;
      }

      url.searchParams.append(key, stringValue);
    });

    return compactUrl(url);
  }

  function historyUrl(source, fallback, formData) {
    var setting = source && source.dataset ? source.dataset.history : "";

    if (!setting || setting === "false") {
      return "";
    }

    if (formData) {
      return urlWithFormData(setting === "true" ? fallback : setting, formData, ["api", "view", "_csrf"]);
    }

    return setting === "true" ? fallback : setting;
  }

  function pushHistory(url) {
    if (!url || !window.history || !window.history.pushState) {
      return;
    }

    window.history.pushState({}, "", url);
  }

  function currentAjaxUrl(trigger) {
    var current = new URL(window.location.href);
    var source = new URL(trigger.dataset.url || trigger.getAttribute("href") || window.location.href, window.location.href);

    source.searchParams.forEach(function (value, key) {
      current.searchParams.set(key, value);
    });

    return compactUrl(current);
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

  function unwrapResult(data) {
    if (data && typeof data === "object" && Object.prototype.hasOwnProperty.call(data, "ok") && Object.prototype.hasOwnProperty.call(data, "data")) {
      return data.data;
    }

    return data;
  }

  function resultErrors(data) {
    if (!data || typeof data !== "object") {
      return null;
    }

    if (data.errors) {
      return data.errors;
    }

    if (data.error && data.error.code === "validation_error" && data.error.details) {
      return data.error.details;
    }

    return null;
  }

  function renderTarget(target, data) {
    if (!target) {
      return;
    }

    var html = unwrapResult(data);

    if (html && typeof html === "object") {
      html = html.html || html.content || html.view || "";
    }

    if (typeof html === "string") {
      target.innerHTML = html;
    }
  }

  function renderTargets(data) {
    var payload = unwrapResult(data);
    var targets = null;

    if (payload && typeof payload === "object" && payload.targets) {
      targets = payload.targets;
    } else if (data && typeof data === "object" && data.targets) {
      targets = data.targets;
    }

    if (!targets || typeof targets !== "object") {
      return;
    }

    Object.keys(targets).forEach(function (selector) {
      var target = qs(selector);

      if (target) {
        target.innerHTML = String(targets[selector] || "");
      }
    });
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

  function resetForm(form) {
    if (!form || form.dataset.reset !== "true") {
      return;
    }

    form.reset();

    qsa("textarea[data-editor]", form).forEach(function (textarea) {
      var instance = textarea.__tinycatEditor;

      if (!instance) {
        return;
      }

      instance.surface.innerHTML = textarea.value || "";
      instance.source.value = textarea.value || "";
      instance.surface.dispatchEvent(new Event("input", { bubbles: true }));
    });

    qsa("[data-tagifier]", form).forEach(function (root) {
      root.__tinycatTags = parseList(root.dataset.tags || "");
      TinyCat.renderTagifier(root);
    });

    qsa("[data-dropzone-files]", form).forEach(function (target) {
      target.innerHTML = "";
    });

    qsa("[data-captcha]", form).forEach(function (root) {
      if (root.__tinycatCaptchaSync) {
        root.__tinycatCaptchaSync();
      }
    });

    qsa("[data-file-picker-value], [data-media-picker-value]", form).forEach(function (input) {
      var targetKey = input.dataset.filePickerValue || input.dataset.mediaPickerValue || "";
      var removeInput = findData("data-file-picker-remove", targetKey, form) || findData("data-media-picker-remove", targetKey, form);

      if (removeInput) {
        removeInput.value = "0";
      }

      TinyCat.renderFilePreview(targetKey, {
        id: input.value || "",
        url: input.dataset.fileDefaultUrl || input.dataset.mediaDefaultUrl || "",
        title: input.dataset.fileDefaultTitle || input.dataset.mediaDefaultTitle || ""
      });
    });
  }

  function formSnapshot(form) {
    var data = new FormData(form);
    var values = [];

    data.forEach(function (value, key) {
      if (key === "_csrf") {
        return;
      }

      if (value instanceof File) {
        values.push(key + "=" + value.name + ":" + value.size + ":" + value.lastModified);
        return;
      }

      values.push(key + "=" + String(value || ""));
    });

    return JSON.stringify(values);
  }

  function markFormClean(form) {
    if (!form || form.dataset.confirmUnsaved !== "true") {
      return;
    }

    form.__tinycatInitialSnapshot = formSnapshot(form);
    form.dataset.dirty = "false";
  }

  function updateDirtyForm(form) {
    if (!form || form.dataset.confirmUnsaved !== "true") {
      return;
    }

    if (typeof form.__tinycatInitialSnapshot !== "string") {
      markFormClean(form);
    }

    form.dataset.dirty = formSnapshot(form) === form.__tinycatInitialSnapshot ? "false" : "true";
  }

  function dirtyForms(modal) {
    return qsa('form[data-confirm-unsaved="true"][data-dirty="true"]', modal);
  }

  async function confirmModalClose(modal) {
    var forms = dirtyForms(modal);
    var form;

    if (forms.length === 0) {
      return true;
    }

    form = forms[0];

    return TinyCat.confirm({
      title: form.dataset.confirmUnsavedTitle || "Unsaved changes",
      message: form.dataset.confirmUnsavedMessage || "Discard unsaved changes?",
      confirmLabel: form.dataset.confirmUnsavedOk || "Discard",
      cancelLabel: form.dataset.confirmUnsavedCancel || "Stay",
      variant: "danger"
    });
  }

  function initTagifierRoot(root) {
    var hidden = qs("[data-tag-value]", root);
    var values = hidden && hidden.value ? hidden.value : root.dataset.value;

    if (root.dataset.tagifierReady === "true") {
      return;
    }

    root.dataset.tagifierReady = "true";
    root.__tinycatTags = uniqueList(parseList(values));
    TinyCat.renderTagifier(root);
  }

  function parsePercent(value, fallback) {
    var parsed = parseFloat(String(value || "").replace("%", ""));

    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function initCaptchaRoot(root) {
    var slider = qs("[data-captcha-slider]", root);
    var answer = qs("[data-captcha-answer]", root);
    var status = qs("[data-captcha-status]", root);
    var token = root.dataset.captchaToken || "";
    var target = parsePercent(root.style.getPropertyValue("--captcha-target"), 50);
    var tolerance = parsePercent(root.dataset.captchaTolerance, 4);

    if (root.dataset.captchaReady === "true") {
      return;
    }

    if (!slider || !answer) {
      return;
    }

    function sync() {
      var value = parsePercent(slider.value, 0);
      var solved = Math.abs(value - target) <= tolerance;
      var moved = String(slider.value) !== String(slider.defaultValue || "");

      root.style.setProperty("--captcha-position", value + "%");
      root.dataset.captchaState = solved ? "solved" : (moved ? "active" : "idle");
      answer.value = token + ":" + String(Math.round(value));

      if (status) {
        status.textContent = solved
          ? (root.dataset.captchaSolved || status.textContent || "")
          : (root.dataset.captchaHint || status.textContent || "");
      }
    }

    root.dataset.captchaReady = "true";
    root.__tinycatCaptchaSync = sync;
    slider.addEventListener("input", sync);
    slider.addEventListener("change", sync);
    sync();
  }

  function hydrateDynamic(root) {
    qsa("[data-tagifier]", root || document).forEach(initTagifierRoot);
    qsa("[data-captcha]", root || document).forEach(initCaptchaRoot);

    if (TinyCat.initTabs) {
      TinyCat.initTabs();
    }

    if (TinyCat.Editor && TinyCat.Editor.initAll) {
      TinyCat.Editor.initAll();
    }

    if (TinyCat.initDropzones) {
      TinyCat.initDropzones(root || document);
    }

    if (TinyCat.initDirtyForms) {
      TinyCat.initDirtyForms(root || document);
    }
  }

  function handleResult(source, data, target) {
    var payload = unwrapResult(data);
    var modalToClose = source && source.dataset && source.dataset.modalCloseOnSuccess === "true"
      ? source.closest(".modal")
      : null;

    if (data && typeof data === "object") {
      var redirect = data.redirect || (payload && typeof payload === "object" ? payload.redirect : "");

      if (redirect) {
        window.location.assign(redirect);
        return;
      }

      if (data.message) {
        TinyCat.toast(data.message, data.type || (data.meta && data.meta.type) || (data.ok === false ? "danger" : "info"));
      }
    }

    renderTarget(target, payload);
    renderTargets(data);
    hydrateDynamic(target || document);
    resetForm(source);
    markFormClean(source);

    if (modalToClose) {
      TinyCat.closeModal(modalToClose);
    }

    emit(source, "tinycat:success", { data: data, payload: payload, target: target });
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

  function createElement(tag, className, text) {
    var element = document.createElement(tag);

    if (className) {
      element.className = className;
    }

    if (text !== undefined && text !== null) {
      element.textContent = String(text);
    }

    return element;
  }

  function findData(name, value, root) {
    var nodes = qsa("[" + name + "]", root || document);

    for (var index = 0; index < nodes.length; index += 1) {
      if (nodes[index].getAttribute(name) === String(value)) {
        return nodes[index];
      }
    }

    return null;
  }

  function confirmOptions(trigger) {
    return {
      title: trigger.dataset.confirmTitle || "Confirm action",
      message: trigger.dataset.confirm || "",
      confirmLabel: trigger.dataset.confirmOk || "Confirm",
      cancelLabel: trigger.dataset.confirmCancel || "Cancel",
      variant: trigger.dataset.confirmVariant || (trigger.classList.contains("btn-danger") ? "danger" : "")
    };
  }

  function confirmAction(trigger) {
    if (!trigger || !trigger.dataset.confirm) {
      return Promise.resolve(true);
    }

    return TinyCat.confirm(confirmOptions(trigger));
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
    var index;

    if (!modal) {
      return null;
    }

    index = modalStack.indexOf(modal);
    if (index !== -1) {
      modalStack.splice(index, 1);
    }
    modalStack.push(modal);
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
    var index;

    if (!modal) {
      return;
    }

    modal.dataset.open = "false";
    modal.setAttribute("aria-hidden", "true");

    index = modalStack.indexOf(modal);
    if (index !== -1) {
      modalStack.splice(index, 1);
    }

    activeModal = modalStack.length > 0 ? modalStack[modalStack.length - 1] : null;
    document.body.classList.toggle("has-modal", activeModal !== null);

    if (modal.__tinycatPreviousFocus && modal.__tinycatPreviousFocus.focus) {
      modal.__tinycatPreviousFocus.focus();
    }

    emit(modal, "tinycat:modal-close");
  };

  TinyCat.requestCloseModal = async function (target) {
    var modal = getModal(target) || activeModal;

    if (!modal) {
      return false;
    }

    if (!await confirmModalClose(modal)) {
      return false;
    }

    TinyCat.closeModal(modal);
    return true;
  };

  TinyCat.markFormClean = markFormClean;
  TinyCat.updateDirtyForm = updateDirtyForm;

  TinyCat.toggleModal = function (target) {
    var modal = getModal(target);

    if (!modal) {
      return null;
    }

    if (modal.dataset.open === "true") {
      TinyCat.requestCloseModal(modal);
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

  TinyCat.setAdminNav = function (open) {
    var isOpen = Boolean(open);
    var sidebar = qs("[data-admin-sidebar]");

    document.body.classList.toggle("admin-nav-open", isOpen);

    qsa("[data-admin-nav-toggle]").forEach(function (button) {
      button.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });

    if (isOpen && sidebar) {
      focusFirst(sidebar);
    }
  };

  TinyCat.toggleAdminNav = function () {
    TinyCat.setAdminNav(!document.body.classList.contains("admin-nav-open"));
  };

  TinyCat.initAdminNav = function () {
    document.addEventListener("click", function (event) {
      var toggle = event.target.closest && event.target.closest("[data-admin-nav-toggle]");
      var close = event.target.closest && event.target.closest("[data-admin-nav-close]");
      var navLink = event.target.closest && event.target.closest(".admin-nav-link");

      if (toggle) {
        event.preventDefault();
        TinyCat.toggleAdminNav();
        return;
      }

      if (close || navLink) {
        TinyCat.setAdminNav(false);
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        TinyCat.setAdminNav(false);
      }
    });
  };

  TinyCat.confirm = function (options) {
    var settings = typeof options === "string" ? { message: options } : (options || {});
    var modal = createElement("div", "modal");
    var backdrop = createElement("div", "modal-backdrop");
    var panel = createElement("div", "modal-panel modal-confirm-panel");
    var header = createElement("div", "modal-header");
    var title = createElement("h2", "modal-title text-lg m-0", settings.title || "Confirm action");
    var close = createElement("button", "btn btn-ghost btn-icon", "x");
    var body = createElement("div", "modal-body");
    var message = createElement("p", "modal-confirm-message", settings.message || "");
    var footer = createElement("div", "modal-footer");
    var cancel = createElement("button", "btn btn-secondary", settings.cancelLabel || "Cancel");
    var confirm = createElement("button", "btn " + (settings.variant === "danger" ? "btn-danger" : "btn-primary"), settings.confirmLabel || "Confirm");

    return new Promise(function (resolve) {
      function finish(value) {
        document.removeEventListener("keydown", onKeydown, true);
        TinyCat.closeModal(modal);
        modal.remove();
        resolve(value);
      }

      function onKeydown(event) {
        if (event.key === "Escape") {
          event.preventDefault();
          event.stopPropagation();
          finish(false);
        }
      }

      modal.setAttribute("aria-hidden", "true");
      modal.setAttribute("role", "dialog");
      modal.setAttribute("aria-modal", "true");

      close.type = "button";
      close.setAttribute("aria-label", "Close");
      cancel.type = "button";
      confirm.type = "button";

      header.appendChild(title);
      header.appendChild(close);
      body.appendChild(message);
      footer.appendChild(cancel);
      footer.appendChild(confirm);
      panel.appendChild(header);
      panel.appendChild(body);
      panel.appendChild(footer);
      modal.appendChild(backdrop);
      modal.appendChild(panel);
      document.body.appendChild(modal);

      backdrop.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        finish(false);
      });
      close.addEventListener("click", function () { finish(false); });
      cancel.addEventListener("click", function () { finish(false); });
      confirm.addEventListener("click", function () { finish(true); });
      document.addEventListener("keydown", onKeydown, true);

      TinyCat.openModal(modal);
      confirm.focus();
    });
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
        TinyCat.requestCloseModal(close ? close.closest(".modal") : target.closest(".modal"));
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && activeModal) {
        TinyCat.requestCloseModal(activeModal);
      }
    });
  };

  TinyCat.initAjax = function () {
    document.addEventListener("submit", async function (event) {
      var form = event.target.closest && event.target.closest("form[data-ajax-form]");

      if (!form) {
        return;
      }

      event.preventDefault();

      if (form.dataset.confirm && !await confirmAction(form)) {
        return;
      }

      clearErrors(form);

      var target = form.dataset.ajaxTarget ? qs(form.dataset.ajaxTarget) : null;
      var method = (form.getAttribute("method") || "POST").toUpperCase();
      var action = form.getAttribute("action") || window.location.href;
      var body = new FormData(form);
      var headers = {};
      var override = body.get("_method");
      var url = action;
      var nextHistory = "";
      var requestOptions;

      if (target) {
        headers["X-TinyCat-View"] = "partial";
      }

      if (override) {
        headers["X-HTTP-Method-Override"] = String(override).toUpperCase();
      }

      if (method === "GET") {
        url = urlWithFormData(action, body, []);
        nextHistory = historyUrl(form, action, body);
        body = null;
      }

      requestOptions = {
        method: method,
        headers: headers
      };

      if (body) {
        requestOptions.body = body;
      }

      try {
        setLoading(form, true);
        emit(form, "tinycat:before", { target: target });
        var data = await TinyCat.request(url, requestOptions);
        handleResult(form, data, target);
        pushHistory(nextHistory);
      } catch (error) {
        var errors = resultErrors(error.data);

        if (errors) {
          applyErrors(form, errors);
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

      event.preventDefault();

      if (link.dataset.confirm && !await confirmAction(link)) {
        return;
      }

      var url = link.dataset.ajaxCurrent === "true" ? currentAjaxUrl(link) : (link.dataset.url || link.getAttribute("href"));
      var target = link.dataset.ajaxTarget ? qs(link.dataset.ajaxTarget) : null;
      var method = (link.dataset.method || "GET").toUpperCase();
      var headers = {};
      var nextHistory = historyUrl(link, url);

      if (target) {
        headers["X-TinyCat-View"] = "partial";
      }

      try {
        setLoading(link, true);
        emit(link, "tinycat:before", { target: target });
        var data = await TinyCat.request(url, { method: method, headers: headers });
        handleResult(link, data, target);
        pushHistory(nextHistory);
      } catch (error) {
        TinyCat.toast((error.data && error.data.message) || error.message || "Request failed", "danger");
        emit(link, "tinycat:error", { error: error, target: target });
      } finally {
        setLoading(link, false);
      }
    });
  };

  TinyCat.initConfirm = function () {
    document.addEventListener("click", async function (event) {
      var trigger = event.target.closest && event.target.closest("[data-confirm]:not(form):not([data-ajax])");

      if (!trigger || trigger.closest("form[data-ajax-form]")) {
        return;
      }

      event.preventDefault();

      if (!await confirmAction(trigger)) {
        return;
      }

      if (trigger.tagName === "A" && trigger.href) {
        window.location.assign(trigger.href);
        return;
      }

      if (trigger.type === "submit" && trigger.form) {
        trigger.form.__tinycatConfirmPass = true;

        if (trigger.form.requestSubmit) {
          trigger.form.requestSubmit(trigger);
        } else {
          trigger.form.submit();
        }
      }
    });

    document.addEventListener("submit", async function (event) {
      var form = event.target.closest && event.target.closest("form[data-confirm]:not([data-ajax-form])");

      if (!form) {
        return;
      }

      if (form.__tinycatConfirmPass) {
        delete form.__tinycatConfirmPass;
        return;
      }

      event.preventDefault();

      if (await confirmAction(form)) {
        form.__tinycatConfirmPass = true;

        if (form.requestSubmit) {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }
    });
  };

  TinyCat.initToasts = function () {
    qsa("[data-tinycat-flashes]").forEach(function (node) {
      var messages;

      if (node.dataset.toastReady === "true") {
        return;
      }

      node.dataset.toastReady = "true";

      try {
        messages = JSON.parse(node.textContent || "[]");
      } catch (error) {
        messages = [];
      }

      if (Array.isArray(messages)) {
        messages.forEach(function (item) {
          TinyCat.toast(item.message || "", item.type || "info");
        });
      }

      node.remove();
    });

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

    if (TinyCat.__tabsEventsBound === true) {
      return;
    }

    TinyCat.__tabsEventsBound = true;

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

  TinyCat.commitTagifierInput = function (root) {
    var input = qs("[data-tag-input]", root);
    var value = input ? input.value : "";

    if (String(value || "").trim() !== "") {
      TinyCat.addTag(root, value);
    } else {
      TinyCat.renderTagifier(root);
    }
  };

  TinyCat.initTagifiers = function () {
    qsa("[data-tagifier]").forEach(initTagifierRoot);

    if (TinyCat.__tagifierEventsBound === true) {
      return;
    }

    TinyCat.__tagifierEventsBound = true;

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

    document.addEventListener("submit", function (event) {
      qsa("[data-tagifier]", event.target).forEach(function (root) {
        TinyCat.commitTagifierInput(root);
      });
    }, true);
  };

  TinyCat.initCaptcha = function (scope) {
    qsa("[data-captcha]", scope || document).forEach(initCaptchaRoot);
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

  TinyCat.renderFilePreview = function (target, file) {
    var preview = findData("data-file-picker-preview", target, document) || findData("data-media-picker-preview", target, document);
    var data = file || {};
    var emptyText;
    var mimeType;
    var extension;
    var urlPath;
    var isImage;
    var image;
    var caption;
    var empty;

    if (!preview) {
      return;
    }

    preview.innerHTML = "";
    emptyText = preview.dataset.emptyText || "";
    mimeType = String(data.mimeType || data.mime_type || "");
    extension = String(data.extension || "").toLowerCase();
    urlPath = String(data.url || "").split(/[?#]/)[0].toLowerCase();
    isImage = data.type === "image"
      || mimeType.indexOf("image/") === 0
      || ["jpg", "jpeg", "png", "gif", "webp", "svg"].indexOf(extension) !== -1
      || /\.(jpe?g|png|gif|webp|svg)$/.test(urlPath);

    if (data.url && isImage) {
      image = document.createElement("img");
      image.src = String(data.url);
      image.alt = String(data.title || "");
      image.loading = "lazy";
      preview.appendChild(image);

      if (data.title) {
        caption = createElement("span", "table-meta truncate", data.title);
        preview.appendChild(caption);
      }

      preview.dataset.empty = "false";
      return;
    }

    if (data.id || data.url || data.title) {
      empty = createElement("span", "content-image-preview-empty", data.title || data.url || emptyText);
      preview.appendChild(empty);
      preview.dataset.empty = "false";
      return;
    }

    empty = createElement("span", "content-image-preview-empty", emptyText);
    preview.appendChild(empty);
    preview.dataset.empty = "true";
  };

  TinyCat.fileInput = function (target) {
    return findData("data-file-picker-value", target, document) || findData("data-media-picker-value", target, document);
  };

  TinyCat.fileRemoveInput = function (target) {
    return findData("data-file-picker-remove", target, document) || findData("data-media-picker-remove", target, document);
  };

  TinyCat.setFileValue = function (target, file, remove) {
    var input = TinyCat.fileInput(target);
    var removeInput = TinyCat.fileRemoveInput(target);
    var data = file || {};

    if (!input) {
      return;
    }

    input.value = remove ? "" : String(data.id || "");

    if (removeInput) {
      removeInput.value = remove ? "1" : "0";
    }

    TinyCat.renderFilePreview(target, remove ? {} : data);
    emit(input, "tinycat:file", {
      id: input.value,
      url: remove ? "" : String(data.url || ""),
      title: remove ? "" : String(data.title || ""),
      type: remove ? "" : String(data.type || ""),
      mimeType: remove ? "" : String(data.mimeType || data.mime_type || ""),
      extension: remove ? "" : String(data.extension || ""),
      remove: Boolean(remove)
    });
    emit(input, "tinycat:media", {
      id: input.value,
      url: remove ? "" : String(data.url || ""),
      title: remove ? "" : String(data.title || ""),
      type: remove ? "" : String(data.type || ""),
      mimeType: remove ? "" : String(data.mimeType || data.mime_type || ""),
      extension: remove ? "" : String(data.extension || ""),
      remove: Boolean(remove)
    });
  };

  TinyCat.activeFileTarget = function (modal) {
    return modal ? (modal.dataset.fileActiveTarget || modal.dataset.mediaActiveTarget || "") : "";
  };

  TinyCat.refreshFileSelection = function (modal) {
    var target = TinyCat.activeFileTarget(modal);
    var input = target ? TinyCat.fileInput(target) : null;
    var selected = input ? String(input.value || "") : "";

    qsa("[data-file-item], [data-media-item]", modal || document).forEach(function (item) {
      var itemId = item.dataset.fileId || item.dataset.mediaId || "";
      var active = selected !== "" && itemId === selected;

      item.dataset.selected = active ? "true" : "false";
      qsa("[data-file-select], [data-media-select]", item).forEach(function (button) {
        button.setAttribute("aria-pressed", active ? "true" : "false");
      });
    });
  };

  TinyCat.filterFilePicker = function (root, query) {
    var needle = String(query || "").trim().toLowerCase();
    var visible = 0;
    var empty = qs("[data-file-empty], [data-media-empty]", root);

    qsa("[data-file-item], [data-media-item]", root).forEach(function (item) {
      var haystack = String(item.dataset.fileSearch || item.dataset.mediaSearch || "").toLowerCase();
      var match = needle === "" || haystack.indexOf(needle) !== -1;

      item.hidden = !match;
      if (match) {
        visible += 1;
      }
    });

    if (empty) {
      empty.hidden = visible !== 0;
    }
  };

  TinyCat.openFilePicker = function (trigger) {
    var modal = getModal(trigger.dataset.filePickerOpen || trigger.dataset.mediaPickerOpen || "content-file-picker");
    var target = trigger.dataset.filePickerTarget || trigger.dataset.mediaPickerTarget || "";
    var mode = trigger.dataset.filePickerMode || trigger.dataset.mediaPickerMode || "field";
    var type = trigger.dataset.filePickerType || trigger.dataset.mediaPickerType || "image";
    var screen;
    var search;

    if (!modal || (target === "" && mode !== "editor")) {
      return null;
    }

    type = type === "file" ? "file" : "image";
    modal.dataset.filePickerType = type;
    modal.dataset.filePickerMode = mode;
    modal.dataset.fileActiveTarget = target;
    modal.dataset.mediaPickerMode = mode;
    modal.dataset.mediaActiveTarget = target;
    qsa("[data-file-picker-screen]", modal).forEach(function (screen) {
      screen.hidden = screen.dataset.filePickerScreen !== type;
    });
    screen = qs('[data-file-picker-screen="' + type + '"]', modal) || modal;
    TinyCat.refreshFileSelection(modal);
    qsa("[data-file-clear], [data-media-clear]", modal).forEach(function (button) {
      button.hidden = mode === "editor";
    });
    search = qs("[data-file-search]", screen) || qs("[data-media-search]", screen);

    if (search) {
      TinyCat.filterFilePicker(screen, search.value);
    }

    return TinyCat.openModal(modal);
  };

  TinyCat.selectFile = function (button) {
    var item = button.closest("[data-file-item], [data-media-item]");
    var modal = button.closest(".modal");
    var target = button.dataset.fileTarget || button.dataset.mediaTarget || TinyCat.activeFileTarget(modal);
    var file;

    if (!item || target === "") {
      return;
    }

    file = {
      id: item.dataset.fileId || item.dataset.mediaId || "",
      url: item.dataset.fileUrl || item.dataset.mediaUrl || "",
      title: item.dataset.fileTitle || item.dataset.mediaTitle || "",
      mimeType: item.dataset.fileMime || item.dataset.mediaMime || "",
      extension: item.dataset.fileExtension || item.dataset.mediaExtension || "",
      type: item.dataset.fileType || modal.dataset.filePickerType || "image"
    };

    if (modal && (modal.dataset.filePickerMode || modal.dataset.mediaPickerMode) === "editor") {
      emit(modal, "tinycat:file-select", {
        mode: "editor",
        target: target,
        file: file,
        media: file
      });
      emit(modal, "tinycat:media-select", {
        mode: "editor",
        target: target,
        media: file
      });

      TinyCat.closeModal(modal);
      return;
    }

    TinyCat.setFileValue(target, file, false);
    TinyCat.refreshFileSelection(modal);

    if (modal) {
      TinyCat.closeModal(modal);
    }
  };

  TinyCat.clearFileSelection = function (button) {
    var modal = button.closest(".modal");
    var target = TinyCat.activeFileTarget(modal);

    if (target === "") {
      return;
    }

    TinyCat.setFileValue(target, {}, true);
    TinyCat.refreshFileSelection(modal);

    if (modal) {
      TinyCat.closeModal(modal);
    }
  };

  TinyCat.clearDeletedFile = function (id) {
    qsa("[data-file-picker-value], [data-media-picker-value]").forEach(function (input) {
      if (String(input.value || "") === String(id || "")) {
        TinyCat.setFileValue(input.dataset.filePickerValue || input.dataset.mediaPickerValue, {}, true);
      }
    });
  };

  TinyCat.initFilePicker = function () {
    if (TinyCat.__filePickerEventsBound === true) {
      return;
    }

    TinyCat.__filePickerEventsBound = true;

    document.addEventListener("click", function (event) {
      var opener = event.target.closest && event.target.closest("[data-file-picker-open], [data-media-picker-open]");
      var select = event.target.closest && event.target.closest("[data-file-select], [data-media-select]");
      var clear = event.target.closest && event.target.closest("[data-file-clear], [data-media-clear]");

      if (opener) {
        event.preventDefault();
        TinyCat.openFilePicker(opener);
        return;
      }

      if (clear) {
        event.preventDefault();
        TinyCat.clearFileSelection(clear);
        return;
      }

      if (select) {
        event.preventDefault();
        TinyCat.selectFile(select);
      }
    });

    document.addEventListener("input", function (event) {
      var search = event.target.closest && event.target.closest("[data-file-search], [data-media-search]");
      var modal;

      if (!search) {
        return;
      }

      modal = search.closest("[data-file-picker-screen]") || search.closest(".modal") || document;
      TinyCat.filterFilePicker(modal, search.value);
    });

    document.addEventListener("tinycat:success", function (event) {
      var source = event.target;
      var upload = source.closest && source.closest("[data-file-upload-form], [data-media-upload-form]");
      var deleted = source.closest && source.closest("[data-file-delete], [data-media-delete]");
      var payload = event.detail ? event.detail.payload : null;
      var modal;
      var search;

      if (upload) {
        modal = upload.closest(".modal");

        if (payload && (payload.file || payload.media)) {
          payload.media = payload.file || payload.media;

          if (modal && (modal.dataset.filePickerMode || modal.dataset.mediaPickerMode) === "editor") {
            emit(modal, "tinycat:file-select", {
              mode: "editor",
              target: TinyCat.activeFileTarget(modal),
              file: payload.media,
              media: payload.media
            });
            emit(modal, "tinycat:media-select", {
              mode: "editor",
              target: TinyCat.activeFileTarget(modal),
              media: payload.media
            });
            TinyCat.closeModal(modal);
            return;
          }

          TinyCat.setFileValue(TinyCat.activeFileTarget(modal), payload.media, false);
          TinyCat.refreshFileSelection(modal);
          TinyCat.closeModal(modal);
        }
      }

      if (deleted) {
        modal = deleted.closest(".modal");
        TinyCat.clearDeletedFile(deleted.dataset.fileDelete || deleted.dataset.mediaDelete);

        if (modal) {
          TinyCat.refreshFileSelection(modal);
          var screen = qs('[data-file-picker-screen="' + (modal.dataset.filePickerType || "image") + '"]', modal) || modal;
          search = qs("[data-file-search]", screen) || qs("[data-media-search]", screen);

          if (search) {
            TinyCat.filterFilePicker(screen, search.value);
          }
        }
      }
    });
  };

  TinyCat.renderMediaPreview = TinyCat.renderFilePreview;
  TinyCat.mediaInput = TinyCat.fileInput;
  TinyCat.mediaRemoveInput = TinyCat.fileRemoveInput;
  TinyCat.setMediaValue = TinyCat.setFileValue;
  TinyCat.activeMediaTarget = TinyCat.activeFileTarget;
  TinyCat.refreshMediaSelection = TinyCat.refreshFileSelection;
  TinyCat.filterMediaPicker = TinyCat.filterFilePicker;
  TinyCat.openMediaPicker = TinyCat.openFilePicker;
  TinyCat.selectMedia = TinyCat.selectFile;
  TinyCat.clearMediaSelection = TinyCat.clearFileSelection;
  TinyCat.clearDeletedMedia = TinyCat.clearDeletedFile;
  TinyCat.initMediaPicker = TinyCat.initFilePicker;

  TinyCat.initDirtyForms = function (scope) {
    qsa('form[data-confirm-unsaved="true"]', scope || document).forEach(function (form) {
      if (form.dataset.dirtyReady === "true") {
        return;
      }

      form.dataset.dirtyReady = "true";
      markFormClean(form);
    });

    if (TinyCat.__dirtyFormEventsBound === true) {
      return;
    }

    TinyCat.__dirtyFormEventsBound = true;

    document.addEventListener("input", function (event) {
      updateDirtyForm(event.target.closest && event.target.closest('form[data-confirm-unsaved="true"]'));
    });

    document.addEventListener("change", function (event) {
      updateDirtyForm(event.target.closest && event.target.closest('form[data-confirm-unsaved="true"]'));
    });

    ["tinycat:tags", "tinycat:file", "tinycat:media", "tinycat:file-select", "tinycat:editor-sync"].forEach(function (name) {
      document.addEventListener(name, function (event) {
        updateDirtyForm(event.target.closest && event.target.closest('form[data-confirm-unsaved="true"]'));
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

  TinyCat.initDropzones = function (scope) {
    qsa("[data-dropzone]", scope || document).forEach(function (root) {
      var input = qs('input[type="file"]', root);

      if (root.dataset.dropzoneReady === "true") {
        return;
      }

      root.dataset.dropzoneReady = "true";

      if (input) {
        input.addEventListener("change", function () {
          TinyCat.renderDropzoneFiles(root, input.files);
          emit(root, "tinycat:files", { files: input.files });
        });
      }
    });

    if (TinyCat.__dropzoneEventsBound === true) {
      return;
    }

    TinyCat.__dropzoneEventsBound = true;

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

  TinyCat.initAutoSubmit = function () {
    if (TinyCat.__autoSubmitEventsBound === true) {
      return;
    }

    TinyCat.__autoSubmitEventsBound = true;

    document.addEventListener("change", function (event) {
      var field = event.target.closest && event.target.closest("[data-submit-on-change]");

      if (!field || !field.form) {
        return;
      }

      if (field.form.requestSubmit) {
        field.form.requestSubmit();
        return;
      }

      field.form.submit();
    });
  };

  TinyCat.init = function () {
    TinyCat.initAdminNav();
    TinyCat.initModals();
    TinyCat.initAjax();
    TinyCat.initConfirm();
    TinyCat.initToasts();
    TinyCat.initTabs();
    TinyCat.initTagifiers();
    TinyCat.initCaptcha();
    TinyCat.initSortable();
    TinyCat.initFilePicker();
    TinyCat.initDirtyForms();
    TinyCat.initDropzones();
    TinyCat.initAutoSubmit();
  };

  window.TinyCat = TinyCat;
  ready(TinyCat.init);
}());
