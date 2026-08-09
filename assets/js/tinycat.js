(function () {
  "use strict";

  var TinyCat = window.TinyCat || {};
  var activeModal = null;
  var modalStack = [];
  var modalPageLock = null;
  var modalCounterId = 0;
  var fieldErrorCounterId = 0;
  var statusEditorCounterId = 0;
  var statusFeedMaxCards = 120;
  var statusFeedKeepCards = 96;
  var statusFeedPruneMargin = 700;
  var statusFeedLastScrollY = window.scrollY || 0;
  var statusFeedScrollQueued = false;

  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function qsa(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function selectorValue(value) {
    value = String(value || "");

    if (window.CSS && typeof window.CSS.escape === "function") {
      return window.CSS.escape(value);
    }

    return value.replace(/["\\]/g, "\\$&");
  }

  function dataSelector(name, value) {
    return "[" + name + "=\"" + selectorValue(value) + "\"]";
  }

  function ready(callback) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback, { once: true });
      return;
    }

    callback();
  }

  function loadGoogleAnalytics(measurementId) {
    measurementId = String(measurementId || "").trim();

    if (!/^G-[A-Z0-9]+$/i.test(measurementId) || window.__tinycatGoogleAnalytics === measurementId) {
      return;
    }

    window.__tinycatGoogleAnalytics = measurementId;
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () {
      window.dataLayer.push(arguments);
    };
    window.gtag("consent", "default", {
      analytics_storage: "granted",
      ad_storage: "denied",
      ad_user_data: "denied",
      ad_personalization: "denied",
      wait_for_update: 500
    });
    window.gtag("js", new Date());
    window.gtag("config", measurementId);

    var script = document.createElement("script");

    script.async = true;
    script.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(measurementId);
    script.dataset.googleAnalytics = measurementId;
    document.head.appendChild(script);
  }

  ready(function () {
    qsa("[data-cookie-consent-choice]").forEach(function (button) {
      button.addEventListener("click", function () {
        var banner = button.closest("[data-cookie-consent]");
        var choice = button.getAttribute("data-cookie-consent-choice") === "granted" ? "granted" : "denied";

        document.cookie = "tinycat_analytics_consent=" + choice + "; Max-Age=" + (180 * 86400) + "; Path=/; SameSite=Lax" + (window.location.protocol === "https:" ? "; Secure" : "");

        if (choice === "granted" && banner) {
          loadGoogleAnalytics(banner.dataset.googleMeasurementId);
        }

        if (banner) {
          banner.remove();
        }
      });
    });
  });

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

  function uiText(name, fallback) {
    var body = document.body;
    var key = "ui" + name.charAt(0).toUpperCase() + name.slice(1);
    var value = body && body.dataset ? body.dataset[key] : "";

    return value || fallback;
  }

  function isFocusableVisible(element) {
    var style;

    if (!element || element.disabled || element.hidden || element.closest('[hidden], [aria-hidden="true"]')) {
      return false;
    }

    style = window.getComputedStyle ? window.getComputedStyle(element) : null;
    return !style || (style.display !== "none" && style.visibility !== "hidden");
  }

  function modalFocusable(modal) {
    return qsa('a[href], area[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), iframe, [contenteditable="true"], [tabindex]:not([tabindex="-1"])', modal)
      .filter(isFocusableVisible);
  }

  function focusFirst(modal) {
    var focusable = modalFocusable(modal);
    var autofocus = focusable.find(function (element) {
      return element.hasAttribute("autofocus");
    });
    var target = autofocus || focusable[0];
    var panel;

    if (!target) {
      panel = qs(".modal-panel", modal);

      if (panel) {
        panel.setAttribute("tabindex", "-1");
        target = panel;
      }
    }

    if (target && target.focus) {
      target.focus();
    }
  }

  function trapModalFocus(event, modal) {
    var focusable = modalFocusable(modal);
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    var current = document.activeElement;

    if (focusable.length === 0) {
      event.preventDefault();
      focusFirst(modal);
      return;
    }

    if (!modal.contains(current)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
      return;
    }

    if (event.shiftKey && (current === first || current === qs(".modal-panel", modal))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && current === last) {
      event.preventDefault();
      first.focus();
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

    return data.errors && typeof data.errors === "object" ? data.errors : null;
  }

  function resultErrorDetails(data) {
    return data && data.error && data.error.details && typeof data.error.details === "object"
      ? data.error.details
      : {};
  }

  function refreshFormCaptcha(form, data) {
    var details = resultErrorDetails(data);
    var captchaHtml = details.captcha_html || "";
    var current = form ? qs("[data-captcha]", form) : null;
    var template;
    var replacement;

    if (!captchaHtml) {
      return;
    }

    template = htmlTemplate(captchaHtml);
    replacement = qs("[data-captcha]", template.content);

    if (!replacement) {
      return;
    }

    if (!current) {
      current = qs('button[type="submit"]', form);

      if (!current || !current.parentNode) {
        return;
      }

      current.parentNode.insertBefore(replacement, current);
      hydrateDynamic(form);
      return;
    }

    if (!current.parentNode) {
      return;
    }

    current.parentNode.replaceChild(replacement, current);
    hydrateDynamic(form);
  }

  function unsafeUrl(value) {
    var url = String(value || "").trim().replace(/[\u0000-\u001F\u007F\s]+/g, "").toLowerCase();

    return url.indexOf("javascript:") === 0
      || url.indexOf("vbscript:") === 0
      || url.indexOf("data:text/html") === 0;
  }

  function sanitizeHtml(root) {
    if (!root) {
      return root;
    }

    qsa("script, iframe, object, embed, base, meta", root).forEach(function (node) {
      node.remove();
    });

    qsa("*", root).forEach(function (node) {
      Array.prototype.slice.call(node.attributes || []).forEach(function (attr) {
        var name = attr.name.toLowerCase();
        var value = attr.value || "";

        if (name.indexOf("on") === 0 || name === "srcdoc") {
          node.removeAttribute(attr.name);
          return;
        }

        if (["href", "src", "action", "formaction", "xlink:href"].indexOf(name) !== -1 && unsafeUrl(value)) {
          node.removeAttribute(attr.name);
        }
      });
    });

    return root;
  }

  function htmlTemplate(html) {
    var template = document.createElement("template");

    template.innerHTML = String(html || "");
    sanitizeHtml(template.content);

    return template;
  }

  function replaceHtml(target, html) {
    var template;

    if (!target) {
      return;
    }

    template = htmlTemplate(html);
    target.innerHTML = "";
    target.appendChild(template.content);
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
      replaceHtml(target, html);
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
        replaceHtml(target, targets[selector] || "");
      }
    });
  }

  function clearErrors(form) {
    qsa('[aria-invalid="true"]', form).forEach(function (field) {
      var errorId = field.dataset.tinycatFieldErrorId || "";
      var describedBy;

      field.removeAttribute("aria-invalid");

      if (errorId) {
        describedBy = String(field.getAttribute("aria-describedby") || "")
          .split(/\s+/)
          .filter(function (id) {
            return id && id !== errorId;
          });

        if (describedBy.length > 0) {
          field.setAttribute("aria-describedby", describedBy.join(" "));
        } else {
          field.removeAttribute("aria-describedby");
        }

        delete field.dataset.tinycatFieldErrorId;
      }
    });

    qsa("[data-field-error]", form).forEach(function (error) {
      error.remove();
    });
  }

  function applyErrors(form, errors) {
    var firstInvalid = null;

    if (!errors || typeof errors !== "object") {
      return firstInvalid;
    }

    Object.keys(errors).forEach(function (name) {
      var field = form.elements.namedItem(name);
      var fields;
      var input;
      var message;
      var error;
      var container;

      if (!field) {
        return;
      }

      fields = field instanceof RadioNodeList ? Array.prototype.slice.call(field) : [field];
      input = fields[0];
      message = Array.isArray(errors[name]) ? errors[name][0] : errors[name];

      if (!input) {
        return;
      }

      fieldErrorCounterId += 1;
      error = document.createElement("span");
      error.id = "field-error-" + fieldErrorCounterId;
      error.className = "field-error";
      error.dataset.fieldError = name;
      error.textContent = String(message || "");

      fields.filter(Boolean).forEach(function (control) {
        var describedBy = String(control.getAttribute("aria-describedby") || "")
          .split(/\s+/)
          .filter(Boolean);

        control.setAttribute("aria-invalid", "true");
        control.dataset.tinycatFieldErrorId = error.id;

        if (describedBy.indexOf(error.id) === -1) {
          describedBy.push(error.id);
          control.setAttribute("aria-describedby", describedBy.join(" "));
        }
      });

      container = input.closest("[data-captcha], .field");

      if (container) {
        container.appendChild(error);
      } else if (input.closest(".check-line")) {
        input.closest(".check-line").insertAdjacentElement("afterend", error);
      } else {
        input.insertAdjacentElement("afterend", error);
      }

      if (!firstInvalid && isFocusableVisible(input)) {
        firstInvalid = input;
      }
    });

    return firstInvalid;
  }

  function resetForm(form) {
    if (!form || form.dataset.reset !== "true") {
      return;
    }

    form.reset();

    qsa("[data-status-editor]", form).forEach(function (root) {
      if (TinyCat.resetStatusEditor) {
        TinyCat.resetStatusEditor(root);
      }
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

  function confirmDirtyForms(forms) {
    var form = forms[0];

    if (!form) {
      return Promise.resolve(true);
    }

    return TinyCat.confirm({
      title: form.dataset.confirmUnsavedTitle || "Unsaved changes",
      message: form.dataset.confirmUnsavedMessage || "Discard unsaved changes?",
      confirmLabel: form.dataset.confirmUnsavedOk || "Leave",
      cancelLabel: form.dataset.confirmUnsavedCancel || "Stay",
      variant: "danger"
    });
  }

  function navigateAfterDiscard(url) {
    // The user has already explicitly chosen to discard this draft in our
    // modal. Let this one intentional navigation pass the native beforeunload
    // guard without asking the same question again.
    TinyCat.__discardingDirtyForms = true;
    window.location.assign(url);

    // A failed/cancelled navigation must not disable the protection for a
    // later, unrelated attempt to leave the page.
    window.setTimeout(function () {
      TinyCat.__discardingDirtyForms = false;
    }, 1000);
  }

  function navigateWithDirtyCheck(url) {
    var forms = dirtyForms(document);

    if (forms.length === 0) {
      window.location.assign(url);
      return;
    }

    confirmDirtyForms(forms).then(function (confirmed) {
      if (confirmed) {
        navigateAfterDiscard(url);
      }
    });
  }

  function confirmDirtyNavigation(event) {
    var link = event.target.closest && event.target.closest("a[href]");
    var forms;
    var url;

    if (event.defaultPrevented || !link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    if (link.target || link.hasAttribute("download") || link.hasAttribute("data-ajax") || link.hasAttribute("data-modal-open")) {
      return;
    }

    try {
      url = new URL(link.href, window.location.href);
    } catch (_error) {
      return;
    }

    if (url.origin === window.location.origin
      && url.pathname === window.location.pathname
      && url.search === window.location.search
      && url.hash !== "") {
      return;
    }

    forms = dirtyForms(document);
    if (forms.length === 0) {
      return;
    }

    event.preventDefault();
    confirmDirtyForms(forms).then(function (confirmed) {
      if (confirmed) {
        navigateAfterDiscard(url.href);
      }
    });
  }

  document.addEventListener("keydown", function (event) {
    if (event.key !== "F1" || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) {
      return;
    }

    event.preventDefault();

    if (window.location.pathname !== "/privacy") {
      navigateWithDirtyCheck("/privacy");
    }
  }, true);

  async function confirmModalClose(modal) {
    var forms = dirtyForms(modal);

    if (forms.length === 0) {
      return true;
    }

    return confirmDirtyForms(forms);
  }

  var captchaProviderLoads = {};

  function captchaProviderApi(provider) {
    if (provider === "recaptcha") {
      return window.grecaptcha;
    }

    if (provider === "turnstile") {
      return window.turnstile;
    }

    if (provider === "hcaptcha") {
      return window.hcaptcha;
    }

    return null;
  }

  function captchaProviderScript(provider) {
    if (provider === "recaptcha") {
      return "https://www.google.com/recaptcha/api.js?render=explicit";
    }

    if (provider === "turnstile") {
      return "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    }

    if (provider === "hcaptcha") {
      return "https://js.hcaptcha.com/1/api.js?render=explicit";
    }

    return "";
  }

  function loadCaptchaProvider(provider) {
    var source = captchaProviderScript(provider);

    if (captchaProviderApi(provider)) {
      return Promise.resolve(captchaProviderApi(provider));
    }

    if (captchaProviderLoads[provider]) {
      return captchaProviderLoads[provider];
    }

    if (!source) {
      return Promise.reject(new Error("Unknown CAPTCHA provider"));
    }

    captchaProviderLoads[provider] = new Promise(function (resolve, reject) {
      var script = document.createElement("script");

      script.src = source;
      script.async = true;
      script.defer = true;
      script.onload = function () {
        var api = captchaProviderApi(provider);

        if (api) {
          resolve(api);
          return;
        }

        reject(new Error("CAPTCHA provider did not initialize"));
      };
      script.onerror = function () {
        reject(new Error("CAPTCHA provider could not be loaded"));
      };
      document.head.appendChild(script);
    });

    return captchaProviderLoads[provider];
  }

  function initExternalCaptchaRoot(root) {
    var provider = root.dataset.captchaProvider || "";
    var sitekey = root.dataset.captchaSitekey || "";
    var theme = root.dataset.captchaTheme || "auto";
    var context = root.dataset.captchaContext || "form";
    var widget = qs("[data-captcha-widget]", root);

    if (root.dataset.captchaReady || !provider || !sitekey || !widget) {
      return;
    }

    root.dataset.captchaReady = "loading";
    loadCaptchaProvider(provider).then(function (api) {
      var options;

      if (!root.isConnected || root.dataset.captchaReady !== "loading") {
        return;
      }

      options = {
        sitekey: sitekey,
        theme: theme,
        callback: function () {
          root.dataset.captchaState = "verified";
        },
        "expired-callback": function () {
          root.dataset.captchaState = "expired";
        },
        "error-callback": function () {
          root.dataset.captchaState = "error";
        }
      };

      if (provider === "turnstile") {
        options.action = context;
      }

      api.render(widget, options);
      root.dataset.captchaReady = "true";
    }).catch(function () {
      root.dataset.captchaReady = "";
      root.dataset.captchaState = "error";
    });
  }

  function initCaptchaRoot(root) {
    initExternalCaptchaRoot(root);
  }

  function markStatusLinkImageMissing(image) {
    var media = image && image.closest ? image.closest("[data-status-link-media]") : null;
    var card = image && image.closest ? image.closest(".status-link-card") : null;
    var fallback = media ? qs("[data-status-link-fallback]", media) : null;

    if (!image) {
      return;
    }

    image.hidden = true;
    image.dataset.statusLinkImageMissing = "true";

    if (card) {
      card.classList.add("is-image-missing");
      card.classList.remove("is-image-ready");
    }

    if (fallback) {
      fallback.hidden = false;
    }
  }

  function markStatusLinkImageReady(image) {
    var media = image && image.closest ? image.closest("[data-status-link-media]") : null;
    var card = image && image.closest ? image.closest(".status-link-card") : null;
    var fallback = media ? qs("[data-status-link-fallback]", media) : null;

    if (!image) {
      return;
    }

    image.hidden = false;
    image.dataset.statusLinkImageMissing = "false";

    if (card) {
      card.classList.add("is-image-ready");
      card.classList.remove("is-image-missing");
    }

    if (fallback) {
      fallback.hidden = true;
    }
  }

  function initStatusLinkImageRoot(image) {
    if (!image || image.dataset.statusLinkImageReady === "true") {
      return;
    }

    image.dataset.statusLinkImageReady = "true";
    image.addEventListener("load", function () {
      markStatusLinkImageReady(image);
    });
    image.addEventListener("error", function () {
      markStatusLinkImageMissing(image);
    });

    if (image.complete) {
      if (image.naturalWidth > 0) {
        markStatusLinkImageReady(image);
      } else {
        markStatusLinkImageMissing(image);
      }
    }
  }

  function hydrateDynamic(root) {
    initUserAvatarImages(root || document);
    qsa("[data-captcha]", root || document).forEach(initCaptchaRoot);
    qsa("[data-client-image-input]", root || document).forEach(initClientImageInput);
    qsa("[data-avatar-upload]", root || document).forEach(initAvatarUploadRoot);
    qsa("[data-status-image]", root || document).forEach(initStatusImageRoot);
    qsa("[data-status-video]", root || document).forEach(initStatusVideoRoot);
    qsa("[data-status-link-image]", root || document).forEach(initStatusLinkImageRoot);

    if (TinyCat.initStatusEditors) {
      TinyCat.initStatusEditors(root || document);
    }

    if (TinyCat.initStatusFeedLazy) {
      TinyCat.initStatusFeedLazy(root || document);
    }

    if (TinyCat.initGlobalSearch) {
      TinyCat.initGlobalSearch(root || document);
    }

    if (TinyCat.initStickySidebars) {
      TinyCat.initStickySidebars(root || document);
    }

    if (TinyCat.initPublicSidebar) {
      TinyCat.initPublicSidebar(root || document);
    }

    if (TinyCat.initFollowForms) {
      TinyCat.initFollowForms(root || document);
    }

    if (TinyCat.initTabs) {
      TinyCat.initTabs();
    }

    if (TinyCat.initDirtyForms) {
      TinyCat.initDirtyForms(root || document);
    }
  }

  function allowedStatusVideoUrl(value) {
    var url;
    var allowedHosts = [
      "www.youtube-nocookie.com",
      "youtube-nocookie.com",
      "www.youtube.com",
      "youtube.com",
      "player.vimeo.com",
      "www.dailymotion.com",
      "dailymotion.com"
    ];

    try {
      url = new URL(String(value || ""), window.location.href);
    } catch (error) {
      return "";
    }

    if (url.protocol !== "https:" || allowedHosts.indexOf(url.hostname.toLowerCase()) === -1) {
      return "";
    }

    return url.href;
  }

  function initStatusVideoRoot(root) {
    var button;
    var embedUrl;

    if (!root || root.dataset.statusVideoReady === "true") {
      return;
    }

    button = qs("[data-status-video-load]", root);
    embedUrl = allowedStatusVideoUrl(root.dataset.embedUrl || "");

    if (!button || !embedUrl) {
      return;
    }

    root.dataset.statusVideoReady = "true";
    button.addEventListener("click", function () {
      var iframe = document.createElement("iframe");
      var url = new URL(embedUrl);
      var host = url.hostname.toLowerCase();

      url.searchParams.set("autoplay", "1");
      url.searchParams.set("playsinline", "1");

      if (host === "youtube.com" || host === "www.youtube.com" || host === "youtube-nocookie.com" || host === "www.youtube-nocookie.com") {
        url.searchParams.set("origin", window.location.origin);
        url.searchParams.set("rel", "0");
      }

      iframe.className = "status-video-frame";
      iframe.src = url.href;
      iframe.loading = "lazy";
      iframe.referrerPolicy = "strict-origin-when-cross-origin";
      iframe.allow = "accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; web-share";
      iframe.allowFullscreen = true;
      root.innerHTML = "";
      root.appendChild(iframe);
    });
  }

  function clientImageFile(file, maxDimension, maxBytes) {
    return new Promise(function (resolve, reject) {
      var bitmapPromise;

      if (!file || ["image/png", "image/jpeg", "image/webp"].indexOf(file.type) === -1) {
        reject(new Error("unsupported_image"));
        return;
      }

      if (maxBytes && file.size > maxBytes) {
        reject(new Error("image_too_large"));
        return;
      }

      if (typeof window.createImageBitmap !== "function" || typeof window.DataTransfer !== "function") {
        reject(new Error("image_resize_unavailable"));
        return;
      }

      bitmapPromise = window.createImageBitmap(file, { imageOrientation: "from-image" }).catch(function () {
        return window.createImageBitmap(file);
      });

      bitmapPromise.then(function (bitmap) {
        var sourceWidth = bitmap.width;
        var sourceHeight = bitmap.height;
        var scale = Math.min(1, maxDimension / Math.max(sourceWidth, sourceHeight));
        var width = Math.max(1, Math.round(sourceWidth * scale));
        var height = Math.max(1, Math.round(sourceHeight * scale));
        var canvas = document.createElement("canvas");
        var context = canvas.getContext("2d", { alpha: true });

        if (!context) {
          if (typeof bitmap.close === "function") {
            bitmap.close();
          }
          reject(new Error("image_resize_unavailable"));
          return;
        }

        canvas.width = width;
        canvas.height = height;
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = "high";
        context.drawImage(bitmap, 0, 0, width, height);
        if (typeof bitmap.close === "function") {
          bitmap.close();
        }

        canvas.toBlob(function (blob) {
          var extension;
          var filename;

          if (!blob || blob.size < 1) {
            reject(new Error("image_resize_failed"));
            return;
          }

          extension = blob.type === "image/webp" ? "webp" : "png";
          filename = String(file.name || "image").replace(/\.[^.]+$/, "") + "." + extension;
          resolve(new File([blob], filename, { type: blob.type, lastModified: Date.now() }));
        }, "image/webp", 0.9);
      }).catch(function () {
        reject(new Error("image_resize_failed"));
      });
    });
  }

  function replaceImageInputFile(input, file) {
    var transfer = new DataTransfer();

    transfer.items.add(file);
    input.files = transfer.files;
  }

  function clientImagePreparing(input, preparing) {
    var form = input.form;
    var active;

    input.disabled = preparing;

    if (!form) {
      return;
    }

    active = Math.max(0, parseInt(form.dataset.clientImagePreparing || "0", 10) || 0) + (preparing ? 1 : -1);
    form.dataset.clientImagePreparing = String(active);
    qsa('button[type="submit"], input[type="submit"]', form).forEach(function (button) {
      if (preparing) {
        if (button.dataset.clientImageWasDisabled === undefined) {
          button.dataset.clientImageWasDisabled = button.disabled ? "1" : "0";
        }
        button.disabled = true;
      } else if (active === 0) {
        if (button.dataset.clientImageWasDisabled !== "1") {
          button.disabled = false;
        }
        delete button.dataset.clientImageWasDisabled;
      }
    });
  }

  function prepareClientImageInput(input, file) {
    var maxDimension = parseInt(input.dataset.clientImageMaxDimension || "0", 10) || 0;
    var maxBytes = parseInt(input.dataset.clientImageMaxBytes || "0", 10) || 0;

    if (!maxDimension || !maxBytes) {
      return Promise.resolve(file);
    }

    clientImagePreparing(input, true);
    return clientImageFile(file, maxDimension, maxBytes).finally(function () {
      clientImagePreparing(input, false);
    });
  }

  function initClientImageInput(input) {
    var selectionRequestId = 0;

    if (!input || input.dataset.clientImageInputReady === "true") {
      return;
    }

    input.dataset.clientImageInputReady = "true";
    input.addEventListener("change", async function () {
      var file = input.files && input.files[0] ? input.files[0] : null;
      var requestId = ++selectionRequestId;
      var prepared;

      if (!file) {
        return;
      }

      try {
        prepared = await prepareClientImageInput(input, file);
        if (requestId !== selectionRequestId) {
          return;
        }
        replaceImageInputFile(input, prepared);
      } catch (error) {
        if (requestId !== selectionRequestId) {
          return;
        }
        input.value = "";
        TinyCat.toast("The selected image could not be prepared for upload.", "danger");
      }
    });
  }

  function initAvatarUploadRoot(root) {
    var input = qs("[data-avatar-upload-input]", root);
    var preview = qs("[data-avatar-upload-preview]", root);
    var empty = qs("[data-avatar-upload-empty]", root);
    var emptyNote = qs("[data-avatar-upload-empty-note]", root);
    var form = root.closest("form");
    var action = form ? qs("[data-avatar-upload-action]", form) : null;
    var remove = form ? qs("[data-avatar-upload-remove]", form) : null;
    var originalSrc = preview ? String(preview.getAttribute("src") || "") : "";
    var previewRequestId = 0;
    var selectionRequestId = 0;

    if (!input || !preview || root.dataset.avatarUploadReady === "true") {
      return;
    }

    root.dataset.avatarUploadReady = "true";

    function showOriginal() {
      if (originalSrc) {
        preview.src = originalSrc;
        preview.hidden = false;
      } else {
        preview.removeAttribute("src");
        preview.hidden = true;
      }

      if (empty) {
        empty.hidden = Boolean(originalSrc);
      }

      if (emptyNote) {
        emptyNote.hidden = Boolean(originalSrc);
      }
    }

    preview.addEventListener("error", function () {
      originalSrc = "";
      showOriginal();
    });

    if (preview.complete && preview.getAttribute("src") && preview.naturalWidth < 1) {
      originalSrc = "";
      showOriginal();
    }

    function showFile(file) {
      var reader = new FileReader();
      var requestId = ++previewRequestId;

      reader.addEventListener("load", function () {
        if (requestId !== previewRequestId || typeof reader.result !== "string") {
          return;
        }

        preview.src = reader.result;
        preview.hidden = false;
      });
      reader.addEventListener("error", showOriginal);
      reader.readAsDataURL(file);

      if (empty) {
        empty.hidden = true;
      }

      if (emptyNote) {
        emptyNote.hidden = true;
      }
    }

    input.addEventListener("change", async function () {
      var file = input.files && input.files[0] ? input.files[0] : null;
      var requestId = ++selectionRequestId;
      var prepared;

      if (action) {
        action.value = "upload";
      }

      if (!file || ["image/png", "image/jpeg", "image/webp"].indexOf(file.type) === -1) {
        showOriginal();
        return;
      }

      try {
        prepared = await prepareClientImageInput(input, file);
        if (requestId !== selectionRequestId) {
          return;
        }
        replaceImageInputFile(input, prepared);
        showFile(prepared);
      } catch (error) {
        if (requestId !== selectionRequestId) {
          return;
        }
        input.value = "";
        TinyCat.toast("The selected image could not be prepared for upload.", "danger");
        showOriginal();
      }
    });

    if (remove && form) {
      remove.addEventListener("click", async function () {
        var confirmed = await TinyCat.confirm({
          title: root.dataset.avatarRemoveTitle || "Remove avatar",
          message: root.dataset.avatarRemoveMessage || "Remove the current avatar?",
          confirmLabel: root.dataset.avatarRemoveOk || "Remove",
          cancelLabel: root.dataset.avatarRemoveCancel || "Cancel",
          variant: "danger"
        });

        if (!confirmed) {
          return;
        }

        selectionRequestId++;
        input.value = "";
        if (action) {
          action.value = "remove";
        }

        form.requestSubmit();
      });
    }
  }

  function initStatusImageRoot(root) {
    var input = qs("[data-status-image-input]", root);
    var preview = qs("[data-status-image-preview]", root);
    var previewWrap = qs("[data-status-image-preview-wrap]", root);
    var picker = qs("[data-status-image-picker]", root);
    var removeButton = qs("[data-status-image-remove]", root);
    var removeInput = qs("[data-status-image-remove-input]", root);
    var altWrap = qs("[data-status-image-alt-wrap]", root);
    var originalSrc = preview ? String(preview.getAttribute("src") || "") : "";
    var originalAlt = preview ? String(preview.getAttribute("alt") || "") : "";
    var previewRequestId = 0;
    var selectionRequestId = 0;

    if (!input || !preview || root.dataset.statusImageReady === "true") {
      return;
    }

    root.dataset.statusImageReady = "true";

    function revokePreview() {
      previewRequestId++;
    }

    function showOriginal() {
      revokePreview();
      previewWrap.hidden = !originalSrc;
      preview.hidden = !originalSrc;
      if (originalSrc) {
        preview.src = originalSrc;
      } else {
        preview.removeAttribute("src");
      }
      preview.alt = originalAlt;
      if (altWrap) {
        altWrap.hidden = !originalSrc;
      }
      if (picker) {
        picker.hidden = Boolean(originalSrc);
      }
    }

    function showFile(file) {
      var reader;
      var requestId;

      revokePreview();
      requestId = previewRequestId;
      preview.removeAttribute("src");
      preview.alt = "";
      preview.hidden = false;
      previewWrap.hidden = false;
      if (altWrap) {
        altWrap.hidden = false;
      }
      if (removeInput) {
        removeInput.value = "0";
      }
      if (picker) {
        picker.hidden = true;
      }

      reader = new FileReader();
      reader.addEventListener("load", function () {
        if (requestId !== previewRequestId || typeof reader.result !== "string") {
          return;
        }

        preview.src = reader.result;
        preview.alt = originalAlt;
      });
      reader.addEventListener("error", function () {
        if (requestId === previewRequestId) {
          preview.hidden = true;
          previewWrap.hidden = true;
          if (picker) {
            picker.hidden = false;
          }
        }
      });
      reader.readAsDataURL(file);
    }

    input.addEventListener("change", async function () {
      var file = input.files && input.files[0] ? input.files[0] : null;
      var requestId = ++selectionRequestId;
      var prepared;

      if (!file) {
        showOriginal();
        return;
      }

      if (["image/jpeg", "image/png", "image/webp"].indexOf(file.type) === -1) {
        input.value = "";
        TinyCat.toast("The selected image is not supported or exceeds the size limit.", "danger");
        showOriginal();
        return;
      }

      try {
        prepared = await prepareClientImageInput(input, file);
        if (requestId !== selectionRequestId) {
          return;
        }
        replaceImageInputFile(input, prepared);
        showFile(prepared);
      } catch (error) {
        if (requestId !== selectionRequestId) {
          return;
        }
        input.value = "";
        TinyCat.toast("The selected image could not be prepared for upload.", "danger");
        showOriginal();
      }
    });

    if (removeButton) {
      removeButton.addEventListener("click", function () {
        selectionRequestId++;
        revokePreview();
        input.value = "";
        preview.removeAttribute("src");
        preview.hidden = true;
        previewWrap.hidden = true;
        if (altWrap) {
          altWrap.hidden = true;
        }
        if (removeInput) {
          removeInput.value = "1";
        }
        if (picker) {
          picker.hidden = false;
        }
      });
    }

    if (root.closest("form")) {
      root.closest("form").addEventListener("reset", function () {
        window.setTimeout(showOriginal, 0);
      });
    }
  }

  function showUserAvatarFallback(image) {
    var root = image && image.parentElement;
    var fallback = root ? qs("[data-user-avatar-fallback]", root) : null;

    if (!image) {
      return;
    }

    image.hidden = true;
    image.removeAttribute("src");

    if (fallback) {
      fallback.hidden = false;
    }
  }

  function initUserAvatarImages(scope) {
    qsa("[data-user-avatar-image]", scope || document).forEach(function (image) {
      if (image.complete && image.naturalWidth < 1) {
        showUserAvatarFallback(image);
      }
    });
  }

  document.addEventListener("error", function (event) {
    var image = event.target;

    if (image && image.matches && image.matches("[data-user-avatar-image]")) {
      showUserAvatarFallback(image);
    }
  }, true);

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

      var message = data.message || (payload && typeof payload === "object" ? payload.message : "");
      var type = data.type
        || (data.meta && data.meta.type)
        || (payload && typeof payload === "object" ? (payload.type || (payload.meta && payload.meta.type)) : "")
        || (data.ok === false ? "danger" : "success");

      if (message) {
        TinyCat.toast(message, type);
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

  function appendUserAvatar(container, url, alt, className, fallbackText) {
    var fallback = createElement("span", "avatar-fallback" + (className ? " " + className : ""), fallbackText || "U");
    var image;

    fallback.dataset.userAvatarFallback = "true";

    if (url) {
      image = document.createElement("img");
      image.className = className || "";
      image.src = url;
      image.alt = alt || "";
      image.loading = "lazy";
      image.dataset.userAvatarImage = "true";
      fallback.hidden = true;
      container.appendChild(image);
    }

    container.appendChild(fallback);
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
      var error = new Error(response.statusText || uiText("requestFailed", "Request failed"));
      error.response = response;
      error.data = data;
      throw error;
    }

    return data;
  };

  function modalUsesVisualViewport(modal) {
    return Boolean(modal && modal.matches && modal.matches(".modal-mobile-fullscreen, .modal-form"));
  }

  function modalNeedsMobilePageLock(modal) {
    return modalUsesVisualViewport(modal)
      && window.matchMedia
      && window.matchMedia("(max-width: 760px), (hover: none) and (pointer: coarse)").matches;
  }

  function lockPageForModal(modal) {
    var body;
    var scrollY;

    if (modalPageLock !== null || !modalNeedsMobilePageLock(modal)) {
      return;
    }

    body = document.body;
    scrollY = window.scrollY || window.pageYOffset || 0;
    modalPageLock = {
      scrollY: scrollY,
      position: body.style.position,
      top: body.style.top,
      left: body.style.left,
      right: body.style.right,
      width: body.style.width
    };

    body.style.position = "fixed";
    body.style.top = "-" + Math.round(scrollY) + "px";
    body.style.left = "0";
    body.style.right = "0";
    body.style.width = "100%";
  }

  function unlockPageForModal() {
    var body = document.body;
    var lock = modalPageLock;

    if (lock === null) {
      return;
    }

    body.style.position = lock.position;
    body.style.top = lock.top;
    body.style.left = lock.left;
    body.style.right = lock.right;
    body.style.width = lock.width;
    modalPageLock = null;

    window.requestAnimationFrame(function () {
      window.scrollTo(0, lock.scrollY);
    });
  }

  function syncModalVisualViewport(modal) {
    var viewport = window.visualViewport;

    if (!modalUsesVisualViewport(modal)) {
      return;
    }

    if (!viewport || viewport.width <= 0 || viewport.height <= 0) {
      modal.style.removeProperty("--tinycat-modal-viewport-left");
      modal.style.removeProperty("--tinycat-modal-viewport-top");
      modal.style.removeProperty("--tinycat-modal-viewport-width");
      modal.style.removeProperty("--tinycat-modal-viewport-height");
      return;
    }

    modal.style.setProperty("--tinycat-modal-viewport-left", Math.round(viewport.offsetLeft) + "px");
    modal.style.setProperty("--tinycat-modal-viewport-top", Math.round(viewport.offsetTop) + "px");
    modal.style.setProperty("--tinycat-modal-viewport-width", Math.round(viewport.width) + "px");
    modal.style.setProperty("--tinycat-modal-viewport-height", Math.round(viewport.height) + "px");
  }

  function syncOpenModalVisualViewports() {
    modalStack.forEach(syncModalVisualViewport);
  }

  function scheduleOpenModalViewportSync() {
    if (TinyCat.__modalViewportSyncQueued === true) {
      return;
    }

    TinyCat.__modalViewportSyncQueued = true;
    window.requestAnimationFrame(function () {
      TinyCat.__modalViewportSyncQueued = false;
      syncOpenModalVisualViewports();
    });
  }

  function scheduleModalViewportSettles() {
    [120, 360].forEach(function (delay) {
      window.setTimeout(function () {
        if (activeModal && modalUsesVisualViewport(activeModal)) {
          scheduleOpenModalViewportSync();
        }
      }, delay);
    });
  }

  TinyCat.openModal = function (target) {
    var modal = getModal(target);
    var index;
    var previousModal = activeModal;

    if (!modal) {
      return null;
    }

    if (modal.parentNode !== document.body) {
      document.body.appendChild(modal);
    }

    index = modalStack.indexOf(modal);
    if (index !== -1) {
      modalStack.splice(index, 1);
    }

    if (previousModal && previousModal !== modal) {
      previousModal.setAttribute("aria-hidden", "true");
    }

    modalStack.push(modal);
    activeModal = modal;
    modal.dataset.open = "true";
    modal.setAttribute("aria-hidden", "false");
    modal.__tinycatPreviousFocus = previousModal === modal ? modal.__tinycatPreviousFocus : document.activeElement;
    document.body.classList.add("has-modal");
    lockPageForModal(modal);
    syncModalVisualViewport(modal);
    scheduleModalViewportSettles();
    focusFirst(modal);
    emit(modal, "tinycat:modal-open");

    return modal;
  };

  TinyCat.closeModal = function (target) {
    var modal = getModal(target) || activeModal;
    var index;
    var wasActive;
    var previousFocus;

    if (!modal) {
      return;
    }

    wasActive = modal === activeModal;
    previousFocus = modal.__tinycatPreviousFocus;

    modal.dataset.open = "false";
    modal.setAttribute("aria-hidden", "true");
    modal.style.removeProperty("--tinycat-modal-viewport-left");
    modal.style.removeProperty("--tinycat-modal-viewport-top");
    modal.style.removeProperty("--tinycat-modal-viewport-width");
    modal.style.removeProperty("--tinycat-modal-viewport-height");

    index = modalStack.indexOf(modal);
    if (index !== -1) {
      modalStack.splice(index, 1);
    }

    activeModal = modalStack.length > 0 ? modalStack[modalStack.length - 1] : null;
    document.body.classList.toggle("has-modal", activeModal !== null);

    if (activeModal === null) {
      unlockPageForModal();
    }

    if (activeModal) {
      activeModal.setAttribute("aria-hidden", "false");
    }

    if (wasActive && previousFocus && previousFocus.isConnected && previousFocus.focus) {
      previousFocus.focus();
    } else if (wasActive && activeModal) {
      focusFirst(activeModal);
    }

    delete modal.__tinycatPreviousFocus;

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

  async function loadRemoteModal(target, url, host, force) {
    var modal = getModal(target);
    var modalIndex;
    var data;
    var payload;
    var html;
    var template;

    if (!url) {
      return modal;
    }

    if (modal && modal.dataset.remoteLoaded === "true" && modal.dataset.modalUrl === url && force !== true) {
      return modal;
    }

    data = await TinyCat.request(url, { method: "GET" });
    payload = unwrapResult(data);
    html = payload && typeof payload === "object" ? (payload.html || "") : "";

    if (!html) {
      throw new Error("Modal content is empty.");
    }

    if (modal) {
      modalIndex = modalStack.indexOf(modal);

      if (modalIndex !== -1) {
        modalStack.splice(modalIndex, 1);
      }

      if (activeModal === modal) {
        activeModal = modalStack.length > 0 ? modalStack[modalStack.length - 1] : null;
      }

      modal.remove();
      document.body.classList.toggle("has-modal", activeModal !== null);
    }

    template = htmlTemplate(html);
    (host || document.body).appendChild(template.content);
    modal = getModal(target);

    if (!modal) {
      throw new Error("Modal was not found.");
    }

    modal.dataset.remoteLoaded = "true";
    modal.dataset.modalUrl = url;
    hydrateDynamic(modal);

    return modal;
  }

  async function openRemoteModal(trigger, force) {
    var parent = trigger && trigger.closest ? trigger.closest("[data-modal-parent-open]") : null;
    var target = trigger ? (trigger.dataset.modalOpen || (parent ? parent.dataset.modalParentOpen : "")) : "";
    var url = trigger ? (trigger.dataset.modalUrl || (parent ? parent.dataset.modalParentUrl : "")) : "";
    var host = trigger && trigger.closest ? trigger.closest(".status-card") : null;
    var modal;

    if (!url) {
      return TinyCat.openModal(target);
    }

    trigger.dataset.modalBusy = "true";
    setLoading(trigger, true);

    try {
      modal = await loadRemoteModal(target, url, host, force);
      return TinyCat.openModal(modal);
    } finally {
      delete trigger.dataset.modalBusy;
      setLoading(trigger, false);
    }
  }

  function mobileStatusUrl(trigger) {
    var parent;
    var modalTarget;
    var card;
    var href;
    var statusId;

    if (!trigger || !window.matchMedia || !window.matchMedia("(max-width: 760px), (hover: none) and (pointer: coarse)").matches) {
      return "";
    }

    parent = trigger.closest("[data-modal-parent-open]");
    modalTarget = String(trigger.dataset.modalOpen || (parent ? parent.dataset.modalParentOpen : ""));
    if (modalTarget.indexOf("status-post-modal-") !== 0) {
      return "";
    }

    href = String(trigger.getAttribute("href") || "");
    if (href.indexOf("/status/") === 0) {
      return mobileStatusPageUrl(href);
    }

    card = trigger.closest(".status-card");
    if (card && card.dataset.statusUrl) {
      return mobileStatusPageUrl(String(card.dataset.statusUrl));
    }

    statusId = parseInt(modalTarget.slice("status-post-modal-".length), 10) || 0;
    return statusId > 0 ? mobileStatusPageUrl("/status/" + statusId) : "";
  }

  function mobileStatusPageUrl(value) {
    var url = new URL(value, window.location.origin);

    url.searchParams.set("compact", "1");
    return compactUrl(url);
  }

  function modalAnchorId(trigger) {
    var href = trigger ? String(trigger.getAttribute("href") || "") : "";
    var hash = "";

    if (!href) {
      return "";
    }

    try {
      hash = decodeURIComponent(new URL(href, window.location.href).hash.slice(1));
    } catch (_error) {
      return "";
    }

    return /^status-comments-thread-[1-9][0-9]*$/.test(hash) ? hash : "";
  }

  function scrollModalToAnchor(modal, id) {
    var target;
    var scroller;
    var offset;

    if (!modal || !id) {
      return;
    }

    target = qsa("[id]", modal).find(function (element) {
      return element.id === id;
    });
    scroller = modalScrollElement(modal);

    if (!target || !scroller) {
      return;
    }

    window.requestAnimationFrame(function () {
      offset = target.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop;
      scroller.scrollTop = Math.max(0, offset - 12);
    });
  }

  function toastIconName(type) {
    if (type === "success") {
      return "check-circle";
    }

    if (type === "danger") {
      return "x-circle";
    }

    if (type === "warning") {
      return "alert";
    }

    return "info";
  }

  function normalizeToastType(type) {
    type = String(type || "info").toLowerCase();

    if (type === "error") {
      return "danger";
    }

    return ["success", "danger", "warning", "info"].indexOf(type) === -1 ? "info" : type;
  }

  function iconSpriteHref(name) {
    var sprite = document.body ? document.body.getAttribute("data-icon-sprite") : "";

    return String(sprite || "") + "#" + String(name || "");
  }

  function createSvgIcon(name) {
    var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    var use = document.createElementNS("http://www.w3.org/2000/svg", "use");

    svg.setAttribute("class", "icon");
    svg.setAttribute("width", "1em");
    svg.setAttribute("height", "1em");
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");
    use.setAttribute("href", iconSpriteHref(name));
    svg.appendChild(use);

    return svg;
  }

  function createToastIcon(type) {
    var icon = document.createElement("span");

    icon.className = "toast-icon";
    icon.setAttribute("aria-hidden", "true");
    icon.appendChild(createSvgIcon(toastIconName(type)));

    return icon;
  }

  TinyCat.toast = function (message, type, timeout) {
    var stack = qs(".toast-stack");
    var normalizedType = normalizeToastType(type);
    var closeTimeout;

    if (!stack) {
      stack = document.createElement("div");
      stack.className = "toast-stack";
      stack.setAttribute("aria-live", "polite");
      stack.setAttribute("aria-atomic", "true");
      document.body.appendChild(stack);
    }

    var toast = document.createElement("div");
    var body = document.createElement("div");
    var close = document.createElement("button");

    toast.className = "toast toast-" + normalizedType;
    toast.setAttribute("role", normalizedType === "danger" || normalizedType === "warning" ? "alert" : "status");
    body.className = "toast-body";
    body.textContent = String(message || "");
    close.className = "toast-close";
    close.type = "button";
    close.setAttribute("aria-label", uiText("close", "Close"));
    close.appendChild(createSvgIcon("close"));

    toast.appendChild(createToastIcon(normalizedType));
    toast.appendChild(body);
    toast.appendChild(close);
    stack.appendChild(toast);

    function removeToast() {
      toast.remove();
    }

    close.addEventListener("click", removeToast);
    closeTimeout = window.setTimeout(removeToast, timeout || (normalizedType === "danger" ? 5600 : 4200));

    toast.addEventListener("mouseenter", function () {
      window.clearTimeout(closeTimeout);
    });

    toast.addEventListener("mouseleave", function () {
      closeTimeout = window.setTimeout(removeToast, 1800);
    });

    return toast;
  };

  function renderFlashMessages(scope) {
    qsa("[data-tinycat-flashes]", scope || document).forEach(function (node) {
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
  }

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
      var navLink = event.target.closest && event.target.closest("a.admin-nav-link");

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
    var modalId;
    var modal = createElement("div", "modal");
    var backdrop = createElement("div", "modal-backdrop");
    var panel = createElement("div", "modal-panel modal-confirm-panel");
    var header = createElement("div", "modal-header");
    var title = createElement("h2", "modal-title text-lg m-0", settings.title || uiText("confirmTitle", "Confirm action"));
    var close = createElement("button", "btn btn-ghost btn-icon", "×");
    var body = createElement("div", "modal-body");
    var message = createElement("p", "modal-confirm-message", settings.message || "");
    var footer = createElement("div", "modal-footer");
    var cancel = createElement("button", "btn btn-secondary", settings.cancelLabel || uiText("cancel", "Cancel"));
    var confirm = createElement("button", "btn " + (settings.variant === "danger" ? "btn-danger" : "btn-primary"), settings.confirmLabel || uiText("confirm", "Confirm"));

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
      modalCounterId += 1;
      modalId = "confirm-modal-" + modalCounterId;
      title.id = modalId + "-title";
      message.id = modalId + "-description";
      modal.setAttribute("aria-labelledby", title.id);
      modal.setAttribute("aria-describedby", message.id);

      close.type = "button";
      close.setAttribute("aria-label", uiText("close", "Close"));
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
      var modalScroll = target.closest && target.closest("[data-modal-scroll]");
      var close = target.closest && target.closest("[data-modal-close]");
      var anchorId;
      var statusUrl;

      if (open) {
        statusUrl = mobileStatusUrl(open);
        event.preventDefault();
        if (statusUrl) {
          window.location.assign(statusUrl);
          return;
        }
        anchorId = modalAnchorId(open);
        openRemoteModal(open, open.dataset.modalRefresh === "true")
          .then(function (modal) {
            scrollModalToAnchor(modal, anchorId);
          })
          .catch(function (error) {
            TinyCat.toast((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
          });
        return;
      }

      if (modalScroll) {
        anchorId = modalAnchorId(modalScroll);

        if (anchorId && modalScroll.closest(".modal[data-open='true']")) {
          event.preventDefault();
          scrollModalToAnchor(modalScroll.closest(".modal[data-open='true']"), anchorId);
          return;
        }
      }

      if (close || (target.classList && target.classList.contains("modal-backdrop"))) {
        event.preventDefault();
        TinyCat.requestCloseModal(close ? close.closest(".modal") : target.closest(".modal"));
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && activeModal) {
        event.preventDefault();
        TinyCat.requestCloseModal(activeModal);
      } else if (event.key === "Tab" && activeModal) {
        trapModalFocus(event, activeModal);
      }
    });

    document.addEventListener("focusin", function (event) {
      if (activeModal && !activeModal.contains(event.target)) {
        focusFirst(activeModal);
      } else if (activeModal && modalUsesVisualViewport(activeModal)) {
        scheduleModalViewportSettles();
      }
    });

    if (window.visualViewport) {
      ["resize", "scroll"].forEach(function (eventName) {
        window.visualViewport.addEventListener(eventName, scheduleOpenModalViewportSync, { passive: true });
      });
    }

    window.addEventListener("orientationchange", scheduleOpenModalViewportSync, { passive: true });
    window.addEventListener("resize", scheduleOpenModalViewportSync, { passive: true });
  };

  TinyCat.initAjax = function () {
    document.addEventListener("submit", async function (event) {
      var form = event.target.closest && event.target.closest("form[data-ajax-form]");
      var invalidField = null;

      if (!form) {
        return;
      }

      event.preventDefault();

      if (form.dataset.confirm && !(await confirmAction(form))) {
        return;
      }

      clearErrors(form);

      var target = form.dataset.ajaxTarget ? qs(form.dataset.ajaxTarget) : null;
      var method = (form.getAttribute("method") || "POST").toUpperCase();
      var action = form.dataset.ajaxAction || form.getAttribute("action") || window.location.href;
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

        refreshFormCaptcha(form, error.data);

        if (errors) {
          invalidField = applyErrors(form, errors);
        }

        TinyCat.toast((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
        emit(form, "tinycat:error", { error: error, target: target });
      } finally {
        setLoading(form, false);

        if (invalidField && invalidField.isConnected && invalidField.focus) {
          invalidField.focus();
        }
      }
    });

    document.addEventListener("click", async function (event) {
      var link = event.target.closest && event.target.closest("[data-ajax]");
      var url;
      var target;
      var method;
      var headers;
      var nextHistory;

      if (!link) {
        return;
      }

      event.preventDefault();

      if (link.dataset.confirm && !await confirmAction(link)) {
        return;
      }

      url = link.dataset.ajaxCurrent === "true" ? currentAjaxUrl(link) : (link.dataset.url || link.getAttribute("href"));
      target = link.dataset.ajaxTarget ? qs(link.dataset.ajaxTarget) : null;
      method = (link.dataset.method || "GET").toUpperCase();
      headers = {};
      nextHistory = historyUrl(link, url);

      if (target && !await confirmDirtyForms(dirtyForms(target))) {
        return;
      }

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
        TinyCat.toast((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
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
      var form = event.target.closest && event.target.closest("form[data-confirm]:not([data-ajax-form]):not([data-status-form])");

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
    renderFlashMessages(document);

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

    qsa("[data-tab-submit]", root.closest(".modal-panel") || root).forEach(function (button) {
      var panel = qsa("[data-tab-panel]", root).find(function (item) {
        return item.dataset.tabPanel === name;
      });

      if (panel && panel.tagName === "FORM" && panel.id) {
        button.setAttribute("form", panel.id);
        button.disabled = false;
      } else {
        button.removeAttribute("form");
        button.disabled = true;
      }
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

  function parseJsonArray(value) {
    try {
      var parsed = JSON.parse(String(value || "[]"));

      if (Array.isArray(parsed)) {
        return parsed.map(function (item) {
          return String(item || "").trim();
        }).filter(Boolean);
      }
    } catch (_error) {
      return parseList(value);
    }

    return [];
  }

  function statusSlug(value) {
    var text = String(value || "").trim();

    if (text.charAt(0) === "#") {
      text = text.slice(1);
    }

    if (text.normalize) {
      text = text.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    }

    return text
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/-+/g, "-")
      .replace(/^-|-$/g, "")
      .slice(0, 32);
  }

  function statusUsername(value) {
    var text = String(value || "").trim();

    if (text.charAt(0) === "@") {
      text = text.slice(1);
    }

    return text.toLowerCase().replace(/[^a-z0-9_]+/g, "").slice(0, 32);
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
    var panel = root && root.closest ? root.closest(".status-post-modal-panel") : null;

    if (!panel) {
      return;
    }

    panel.classList.toggle(
      "has-editor-suggestions",
      Boolean(qs("[data-status-editor-suggestions]:not([hidden])", panel))
    );
  }

  function statusEditorTags(root) {
    if (!root.__tinycatStatusTags) {
      var source = statusEditorSource(root);
      root.__tinycatStatusTags = uniqueList(parseJsonArray(source ? source.dataset.statusTags : "[]").map(statusSlug).filter(Boolean));
    }

    return root.__tinycatStatusTags;
  }

  function statusEditorSuggestUrl(root) {
    var source = statusEditorSource(root);

    return source ? String(source.dataset.statusSuggestUrl || "") : "";
  }

  function statusEditorText(editor) {
    var text = String(editor.innerText || "").replace(/\r\n/g, "\n").replace(/\u00a0/g, " ");

    return text === "\n" ? "" : text;
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
    match = /(^|[\s([{])([#@])([^\s#@]*)$/.exec(before);

    if (!match) {
      return null;
    }

    return {
      marker: String(match[2] || "#"),
      type: String(match[2] || "#") === "@" ? "user" : "tag",
      query: String(match[3] || ""),
      start: caret - String(match[3] || "").length - 1,
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

  function statusEditorLocalSuggestions(root, token) {
    var needle;
    var tags;

    if (!token || token.type !== "tag") {
      return [];
    }

    needle = statusSlug(token.query);
    tags = statusEditorTags(root).filter(function (tag) {
      return !needle || tag.indexOf(needle) !== -1;
    });

    if (needle && tags.indexOf(needle) === -1) {
      tags.unshift(needle);
    }

    return tags.slice(0, 8).map(function (tag) {
      return {
        type: "tag",
        title: "#" + tag,
        value: tag
      };
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
      var value = type === "user" ? statusUsername(item.value || item.title) : statusSlug(item.value || item.title);
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

    if (!suggestUrl || !token || !token.query) {
      return;
    }

    window.clearTimeout(root.__tinycatStatusSuggestTimer);
    fingerprint = token.type + ":" + token.start + ":" + token.end + ":" + token.query;
    root.__tinycatStatusSuggestFingerprint = fingerprint;

    root.__tinycatStatusSuggestTimer = window.setTimeout(function () {
      var url = new URL(suggestUrl, window.location.href);
      var controller = window.AbortController ? new AbortController() : null;

      if (root.__tinycatStatusSuggestAbort) {
        root.__tinycatStatusSuggestAbort.abort();
      }

      root.__tinycatStatusSuggestAbort = controller;
      url.searchParams.set("type", token.type);
      url.searchParams.set("q", token.query);
      url.searchParams.set("limit", "8");

      TinyCat.request(compactUrl(url), {
        method: "GET",
        cache: "no-store",
        signal: controller ? controller.signal : undefined
      })
        .then(function (response) {
          var data = unwrapResult(response) || {};
          var items = token.type === "user" ? data.users : data.tags;

          if (root.__tinycatStatusSuggestFingerprint !== fingerprint) {
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
    var items;

    if (!box || !token) {
      hideStatusEditorSuggestions(root);
      return;
    }

    root.__tinycatStatusToken = token;
    items = statusEditorLocalSuggestions(root, token);
    renderStatusEditorSuggestionItems(root, items);
    requestStatusEditorSuggestions(root, token);
  }

  function insertStatusEditorSuggestion(root, value, type) {
    var editor = statusEditorInput(root);
    var token = root.__tinycatStatusToken || statusEditorActiveToken(root);
    var suggestionType = type || (token ? token.type : "tag");
    var marker = suggestionType === "user" ? "@" : "#";
    var clean = suggestionType === "user" ? statusUsername(value) : statusSlug(value);

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
    editor.dataset.placeholder = source.dataset.statusPlaceholder || source.getAttribute("placeholder") || "";
    editor.setAttribute("contenteditable", "plaintext-only");
    editor.setAttribute("role", "textbox");
    editor.setAttribute("aria-multiline", "true");
    editor.setAttribute("aria-label", source.getAttribute("placeholder") || "Status");
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

  TinyCat.resetStatusEditor = function (root) {
    var source = statusEditorSource(root);
    var editor = statusEditorInput(root);

    if (!source || !editor) {
      return;
    }

    setStatusEditorText(editor, source.value || "", (source.value || "").length);
    syncStatusEditor(root);
    hideStatusEditorSuggestions(root);
  };

  TinyCat.initStatusEditors = function (scope) {
    qsa("[data-status-editor]", scope || document).forEach(initStatusEditorRoot);
  };

  function globalSearchInput(root) {
    return qs("[data-global-search-input]", root);
  }

  function globalSearchResults(root) {
    return qs("[data-global-search-results]", root);
  }

  function globalSearchShowMessage(root, message) {
    var box = globalSearchResults(root);
    var item;

    if (!box) {
      return;
    }

    box.innerHTML = "";
    item = createElement("div", "global-search-empty", message);
    box.appendChild(item);
    box.hidden = false;
  }

  function globalSearchHide(root) {
    var box = globalSearchResults(root);

    if (box) {
      box.hidden = true;
      box.innerHTML = "";
    }
  }

  function globalSearchItem(item) {
    var link = createElement("a", "global-search-item");
    var title = createElement("strong", "global-search-title", item.title || "");
    var metaText = item.type === "tag"
      ? (item.excerpt || "")
      : (item.type === "user" ? "@" + (item.title || "") : (item.published_label || item.excerpt || ""));
    var meta = createElement("span", "global-search-meta", metaText);
    var excerpt = createElement("span", "global-search-excerpt", item.excerpt || "");
    var avatar = createElement("span", "global-search-avatar");
    var text = createElement("span", "global-search-text");

    link.href = item.url || "#";
    link.dataset.globalSearchLink = "true";

    if (item.type === "tag") {
      avatar.textContent = "#";
    } else if (item.type === "search") {
      avatar.textContent = ">";
    } else if (item.type === "user") {
      appendUserAvatar(avatar, item.avatar_url, item.title || "", "", "U");
    } else {
      avatar.textContent = "#";
    }

    text.appendChild(title);
    if (metaText) {
      text.appendChild(meta);
    }

    if (item.excerpt && item.type !== "tag" && item.type !== "search") {
      text.appendChild(excerpt);
    }

    link.appendChild(avatar);
    link.appendChild(text);

    return link;
  }

  function renderGlobalSearchSection(root, box, label, items) {
    var section;
    var heading;

    if (!items || items.length === 0) {
      return;
    }

    section = createElement("section", "global-search-section");
    heading = createElement("div", "global-search-heading", label);
    section.appendChild(heading);

    items.forEach(function (item) {
      section.appendChild(globalSearchItem(item));
    });

    box.appendChild(section);
  }

  function renderGlobalSearchAll(root, box, query) {
    var link;

    if (!query) {
      return;
    }

    link = globalSearchItem({
      type: "search",
      title: (root.dataset.searchAll || "Search all") + ": " + query,
      excerpt: "",
      url: "/search?q=" + encodeURIComponent(query)
    });
    link.classList.add("global-search-all");
    box.appendChild(link);
  }

  function renderGlobalSearch(root, data) {
    var payload = unwrapResult(data) || {};
    var box = globalSearchResults(root);
    var query = payload.query || "";
    var tags = payload.tags || [];
    var users = payload.users || [];
    var content = payload.content || [];

    if (!box) {
      return;
    }

    box.innerHTML = "";

    if (tags.length === 0 && users.length === 0 && content.length === 0) {
      renderGlobalSearchAll(root, box, query);
      box.hidden = false;
      return;
    }

    renderGlobalSearchSection(root, box, root.dataset.searchTags || "Tags", tags);
    renderGlobalSearchSection(root, box, root.dataset.searchUsers || "Profiles", users);
    renderGlobalSearchSection(root, box, root.dataset.searchContent || "Posts", content);
    renderGlobalSearchAll(root, box, query);
    box.hidden = false;
  }

  function searchCaptchaDetails(error) {
    return error && error.data && error.data.error && error.data.error.details
      ? error.data.error.details
      : {};
  }

  function openGlobalSearchCaptcha(root, error) {
    var details = searchCaptchaDetails(error);
    var captchaHtml = details.captcha_html || "";
    var verifyUrl = details.verify_url || "/api/search-captcha";
    var message = (error && error.data && error.data.message) || root.dataset.searchCaptchaRequired || "Please complete the security check.";
    var modal = qs("#global-search-captcha-modal");
    var panel;
    var form;
    var body;
    var footer;
    var submit;
    var title;

    if (!captchaHtml) {
      globalSearchShowMessage(root, message);
      return;
    }

    if (modal) {
      modal.remove();
    }

    modal = createElement("div", "modal");
    modal.id = "global-search-captcha-modal";
    modal.setAttribute("aria-hidden", "true");
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-labelledby", "global-search-captcha-title");
    modal.innerHTML = ''
      + '<div class="modal-backdrop" data-modal-close></div>'
      + '<div class="modal-panel modal-confirm-panel search-captcha-modal-panel">'
      + '<div class="modal-header">'
      + '<h2 class="modal-title text-lg m-0" id="global-search-captcha-title"></h2>'
      + '<button class="btn btn-icon btn-ghost" type="button" data-modal-close></button>'
      + '</div>'
      + '<form class="modal-body stack stack-gap-12" data-search-captcha-form></form>'
      + '</div>';

    panel = qs(".modal-panel", modal);
    title = qs(".modal-title", modal);
    form = qs("[data-search-captcha-form]", modal);
    qs("[data-modal-close]", modal).setAttribute("aria-label", uiText("close", "Close"));
    qs("[data-modal-close]", modal).textContent = "×";

    if (title) {
      title.textContent = root.dataset.searchCaptchaTitle || "Security check";
    }

    if (form) {
      body = createElement("div", "muted", message);
      footer = createElement("div", "modal-footer justify-end");
      submit = createElement("button", "btn btn-primary", root.dataset.searchCaptchaSubmit || "OK");
      submit.type = "submit";
      footer.appendChild(submit);
      form.action = verifyUrl;
      form.method = "post";
      form.appendChild(body);
      form.appendChild(htmlTemplate(captchaHtml).content);
      form.appendChild(footer);
      form.addEventListener("submit", function (event) {
        event.preventDefault();

        TinyCat.request(verifyUrl, {
          method: "POST",
          body: new FormData(form)
        })
          .then(function () {
            if (modal.parentNode) {
              TinyCat.closeModal(modal);
              modal.remove();
            }

            root.__tinycatSearchCache = {};
            runGlobalSearch(root);
          })
          .catch(function (submitError) {
            var submitDetails = searchCaptchaDetails(submitError);

            if (submitDetails.captcha_html) {
              openGlobalSearchCaptcha(root, submitError);
              return;
            }

            globalSearchShowMessage(root, (submitError && submitError.data && submitError.data.message) || message);
          });
      });
    }

    document.body.appendChild(modal);
    hydrateDynamic(modal);
    TinyCat.openModal(modal);

    return panel;
  }

  function runGlobalSearch(root) {
    var input = globalSearchInput(root);
    var query = input ? input.value.trim() : "";
    var cacheKey = query.toLowerCase();
    var url;
    var requestId;
    var controller;

    if (query === "") {
      globalSearchHide(root);
      return;
    }

    if (query.length < 2) {
      globalSearchShowMessage(root, root.dataset.searchMin || "Type at least 2 characters.");
      return;
    }

    root.__tinycatSearchCache = root.__tinycatSearchCache || {};

    if (root.__tinycatSearchCache[cacheKey]) {
      renderGlobalSearch(root, root.__tinycatSearchCache[cacheKey]);
      return;
    }

    if (root.__tinycatSearchController) {
      root.__tinycatSearchController.abort();
    }

    controller = "AbortController" in window ? new AbortController() : null;
    root.__tinycatSearchController = controller;
    root.__tinycatSearchRequest = (root.__tinycatSearchRequest || 0) + 1;
    requestId = root.__tinycatSearchRequest;
    url = new URL(root.dataset.searchApi || "/api/search", window.location.href);
    url.searchParams.set("q", query);
    url = compactUrl(url);
    root.setAttribute("aria-busy", "true");

    TinyCat.request(url, {
      method: "GET",
      signal: controller ? controller.signal : undefined
    })
      .then(function (data) {
        if (requestId !== root.__tinycatSearchRequest) {
          return;
        }

        root.__tinycatSearchCache[cacheKey] = data;
        renderGlobalSearch(root, data);
      })
      .catch(function (error) {
        if (error && error.name === "AbortError") {
          return;
        }

        if (error && error.data && error.data.error && error.data.error.code === "captcha_required") {
          openGlobalSearchCaptcha(root, error);
          return;
        }

        if (requestId === root.__tinycatSearchRequest) {
          globalSearchShowMessage(root, (error && error.data && error.data.message) || root.dataset.searchEmpty || "Nothing found.");
        }
      })
      .finally(function () {
        if (requestId === root.__tinycatSearchRequest) {
          root.removeAttribute("aria-busy");
          if (root.__tinycatSearchController === controller) {
            root.__tinycatSearchController = null;
          }
        }
      });
  }

  function initGlobalSearchRoot(root) {
    var input = globalSearchInput(root);

    if (root.dataset.globalSearchReady === "true" || !input) {
      return;
    }

    root.dataset.globalSearchReady = "true";

    input.addEventListener("input", function () {
      window.clearTimeout(root.__tinycatSearchTimer);
      root.__tinycatSearchTimer = window.setTimeout(function () {
        runGlobalSearch(root);
      }, 220);
    });

    input.addEventListener("focus", function () {
      if (input.value.trim() !== "") {
        runGlobalSearch(root);
      }
    });

    input.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        globalSearchHide(root);
        input.blur();
      }
    });

    root.addEventListener("submit", function (event) {
      if (input.value.trim().length < 2) {
        event.preventDefault();
        globalSearchShowMessage(root, root.dataset.searchMin || "Type at least 2 characters.");
      }
    });
  }

  TinyCat.initGlobalSearch = function (scope) {
    qsa("[data-global-search]", scope || document).forEach(initGlobalSearchRoot);

    if (TinyCat.__globalSearchEventsBound === true) {
      return;
    }

    TinyCat.__globalSearchEventsBound = true;

    document.addEventListener("pointerdown", function (event) {
      qsa("[data-global-search]").forEach(function (root) {
        if (!root.contains(event.target)) {
          globalSearchHide(root);
        }
      });
    });
  };

  function stickySidebarNodes(scope) {
    var root = scope || document;
    var selector = ".public-sidebar, .profile-sidebar-stack";
    var nodes = qsa(selector, root);

    if (root.nodeType === 1 && root.matches(selector)) {
      nodes.unshift(root);
    }

    return nodes;
  }

  function refreshStickySidebars(direction) {
    var desktop = window.matchMedia && window.matchMedia("(min-width: 900px)").matches;
    var topOffset = 76;
    var bottomOffset = 16;

    if (direction === "up" || direction === "down") {
      TinyCat.__stickySidebarDirection = direction;
    }

    qsa(".public-sidebar, .profile-sidebar-stack").forEach(function (sidebar) {
      var sidebarHeight;
      var stickyOffset;

      if (!desktop) {
        sidebar.style.removeProperty("--sidebar-sticky-top");
        return;
      }

      sidebarHeight = Math.ceil(sidebar.getBoundingClientRect().height);
      stickyOffset = TinyCat.__stickySidebarDirection === "up"
        ? topOffset
        : Math.min(topOffset, window.innerHeight - sidebarHeight - bottomOffset);
      sidebar.style.setProperty("--sidebar-sticky-top", stickyOffset + "px");
    });
  }

  TinyCat.initStickySidebars = function (scope) {
    var nodes = stickySidebarNodes(scope);

    if (nodes.length === 0) {
      return;
    }

    if (TinyCat.__stickySidebarObserver == null && window.ResizeObserver) {
      TinyCat.__stickySidebarObserver = new ResizeObserver(function () {
        refreshStickySidebars();
      });
    }

    nodes.forEach(function (sidebar) {
      if (sidebar.dataset.stickySidebarReady === "true") {
        return;
      }

      sidebar.dataset.stickySidebarReady = "true";

      if (TinyCat.__stickySidebarObserver) {
        TinyCat.__stickySidebarObserver.observe(sidebar);
      }
    });

    if (TinyCat.__stickySidebarEventsBound !== true) {
      TinyCat.__stickySidebarEventsBound = true;
      TinyCat.__stickySidebarDirection = "down";
      TinyCat.__stickySidebarLastScrollY = window.scrollY || 0;
      window.addEventListener("resize", refreshStickySidebars, { passive: true });
      window.addEventListener("scroll", function () {
        var nextScrollY = window.scrollY || 0;

        if (nextScrollY === TinyCat.__stickySidebarLastScrollY) {
          return;
        }

        refreshStickySidebars(nextScrollY < TinyCat.__stickySidebarLastScrollY ? "up" : "down");
        TinyCat.__stickySidebarLastScrollY = nextScrollY;
      }, { passive: true });

      if (window.matchMedia) {
        window.matchMedia("(min-width: 900px)").addEventListener("change", refreshStickySidebars);
      }
    }

    refreshStickySidebars();
  };

  TinyCat.initPublicSidebar = function (scope) {
    var desktopMedia = window.matchMedia ? window.matchMedia("(min-width: 900px)") : null;

    function publicSidebarSlot(url) {
      var slot = document.createElement("template");

      slot.dataset.publicSidebarSlot = "";
      slot.dataset.sidebarUrl = url;

      return slot;
    }

    function unloadPublicSidebars() {
      qsa("[data-public-sidebar-url]", document).forEach(function (sidebar) {
        if (TinyCat.__stickySidebarObserver) {
          TinyCat.__stickySidebarObserver.unobserve(sidebar);
        }

        sidebar.replaceWith(publicSidebarSlot(sidebar.dataset.publicSidebarUrl || ""));
      });
    }

    if (TinyCat.__publicSidebarViewportBound !== true && desktopMedia) {
      TinyCat.__publicSidebarViewportBound = true;

      if (typeof desktopMedia.addEventListener === "function") {
        desktopMedia.addEventListener("change", function (event) {
          if (event.matches) {
            TinyCat.initPublicSidebar();
          } else {
            unloadPublicSidebars();
          }
        });
      } else if (typeof desktopMedia.addListener === "function") {
        desktopMedia.addListener(function (event) {
          if (event.matches) {
            TinyCat.initPublicSidebar();
          } else {
            unloadPublicSidebars();
          }
        });
      }
    }

    if (desktopMedia && !desktopMedia.matches) {
      return;
    }

    qsa("[data-public-sidebar-slot][data-sidebar-url]", scope || document).forEach(function (sidebar) {
      var url = sidebar.dataset.sidebarUrl || "";

      if (!url || sidebar.dataset.sidebarLoading === "true") {
        return;
      }

      sidebar.dataset.sidebarLoading = "true";
      sidebar.classList.add("is-loading");

      TinyCat.request(url, { method: "GET", cache: "no-store" })
        .then(function (data) {
          var payload = unwrapResult(data) || {};
          var template;
          var next;

          if (!payload.html) {
            return;
          }

          template = htmlTemplate(payload.html);
          next = template.content.firstElementChild;

          if (!next) {
            return;
          }

          if (desktopMedia && !desktopMedia.matches) {
            return;
          }

          sidebar.replaceWith(next);
          hydrateDynamic(next);
        })
        .catch(function () {
          sidebar.classList.remove("is-loading");
        })
        .finally(function () {
          delete sidebar.dataset.sidebarLoading;
        });
    });
  };

  TinyCat.initCaptcha = function (scope) {
    qsa("[data-captcha]", scope || document).forEach(initCaptchaRoot);
  };

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

    document.addEventListener("tinycat:editor-sync", function (event) {
      updateDirtyForm(event.target.closest && event.target.closest('form[data-confirm-unsaved="true"]'));
    });

    document.addEventListener("click", confirmDirtyNavigation);

    window.addEventListener("beforeunload", function (event) {
      if (TinyCat.__discardingDirtyForms === true) {
        return;
      }

      if (dirtyForms(document).length === 0) {
        return;
      }

      event.preventDefault();
      event.returnValue = "";
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

  TinyCat.initDismissibleMenus = function () {
    if (TinyCat.__dismissibleMenuEventsBound === true) {
      return;
    }

    TinyCat.__dismissibleMenuEventsBound = true;

    function closeMenus(except) {
      qsa("[data-dismissible-menu][open]").forEach(function (openMenu) {
        if (openMenu !== except) {
          openMenu.open = false;
        }
      });
    }

    document.addEventListener("click", function (event) {
      var menu = event.target.closest && event.target.closest("[data-dismissible-menu]");
      var action = event.target.closest && event.target.closest("[data-dismissible-menu-action]");

      closeMenus(menu);

      if (menu && action) {
        menu.open = false;
      }
    });

    document.addEventListener("keydown", function (event) {
      var activeMenu;
      var summary;

      if (event.key !== "Escape") {
        return;
      }

      activeMenu = document.activeElement && document.activeElement.closest
        ? document.activeElement.closest("[data-dismissible-menu][open]")
        : null;
      closeMenus(null);

      if (activeMenu) {
        summary = qs("summary", activeMenu);

        if (summary) {
          summary.focus();
        }
      }
    });

    document.addEventListener("toggle", function (event) {
      var menu = event.target;

      if (!menu || !menu.matches || !menu.matches("[data-dismissible-menu]") || !menu.open) {
        return;
      }

      closeMenus(menu);
    }, true);
  };

  TinyCat.initCommentReplies = function () {
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
      input = (editorRoot ? statusEditorInput(editorRoot) : null) || qs(".status-reply-form-container .status-comment-input", details.closest(".status-comment-main"));

      if (!input) {
        return;
      }

      input.focus();
      length = input.isContentEditable ? statusEditorText(input).length : input.value.length;

      if (input.isContentEditable) {
        setStatusEditorText(input, statusEditorText(input), length);
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
  };

  function statusFormActionField(form) {
    var field = form && form.elements ? form.elements.namedItem("action") : null;

    if (field instanceof RadioNodeList) {
      field = field[0] || null;
    }

    if (!field && form) {
      field = qs('[name="action"]', form);
    }

    return field ? String(field.value || "") : "";
  }

  function statusFormAction(form, body) {
    var data = body;
    var value = "";

    try {
      if (!data) {
        data = new FormData(form);
      }

      value = String(data.get("action") || "");
    } catch (error) {
      value = "";
    }

    return value || statusFormActionField(form) || "create";
  }

  function shouldSubmitOnEnter(event) {
    return event.key === "Enter"
      && !event.shiftKey
      && !event.altKey
      && !event.ctrlKey
      && !event.metaKey
      && !event.isComposing
      && event.keyCode !== 229;
  }

  function submitForm(form) {
    if (!form || form.dataset.statusBusy === "true") {
      return;
    }

    if (form.requestSubmit) {
      form.requestSubmit();
      return;
    }

    form.submit();
  }

  function statusFeedPayload(data) {
    var payload = unwrapResult(data);

    return payload && typeof payload === "object" ? payload : {};
  }

  function statusFeedTarget(control) {
    var selector = control ? control.dataset.statusFeedTarget : "";

    return selector ? qs(selector) : null;
  }

  function statusFeedNearViewport(control) {
    var rect;

    if (!control || control.hidden) {
      return false;
    }

    rect = control.getBoundingClientRect();

    return rect.top < window.innerHeight + 400;
  }

  function statusFeedCards(target) {
    return Array.prototype.slice.call(target ? target.children : []).filter(function (node) {
      return node.classList && node.classList.contains("status-card");
    });
  }

  function statusFeedGap(target) {
    var style = window.getComputedStyle ? window.getComputedStyle(target) : null;
    var value = style ? parseFloat(style.rowGap || style.gap || "0") : 0;

    return Number.isFinite(value) ? value : 0;
  }

  function statusFeedSpacer(target) {
    var spacer = qs("[data-status-feed-spacer]", target);

    if (spacer) {
      return spacer;
    }

    spacer = document.createElement("div");
    spacer.className = "status-feed-spacer";
    spacer.setAttribute("aria-hidden", "true");
    spacer.dataset.statusFeedSpacer = "true";
    target.insertBefore(spacer, target.firstChild);

    return spacer;
  }

  function statusFeedSpacerHeight(target) {
    var spacer = qs("[data-status-feed-spacer]", target);

    return spacer ? Math.max(0, parseFloat(spacer.dataset.height || spacer.style.height || "0") || 0) : 0;
  }

  function setStatusFeedSpacerHeight(target, height) {
    var spacer = statusFeedSpacer(target);
    var value = Math.max(0, Math.round(height));

    spacer.dataset.height = String(value);
    spacer.style.height = value + "px";
    spacer.hidden = value < 1;
  }

  function statusFeedFirstPageUrl(url) {
    var next = new URL(url || window.location.href, window.location.href);

    next.searchParams.delete("offset");
    next.searchParams.delete("cursor_at");
    next.searchParams.delete("cursor_id");

    return compactUrl(next);
  }

  function statusFeedControlForTarget(target) {
    var controls = qsa("[data-status-feed-more]");
    var i;

    for (i = 0; i < controls.length; i += 1) {
      if (statusFeedTarget(controls[i]) === target) {
        return controls[i];
      }
    }

    return null;
  }

  function statusFeedCardProtected(card) {
    return Boolean(
      card.contains(document.activeElement)
        || qs(".modal[data-open='true'], [data-status-busy='true'], [data-modal-busy='true']", card)
    );
  }

  function pruneStatusFeed(target, refreshUrl) {
    var cards = statusFeedCards(target);
    var excess = cards.length - statusFeedKeepCards;
    var gap;
    var currentSpacerHeight;
    var remove = [];
    var removedHeight = 0;
    var anchor;
    var beforeTop;
    var afterTop;

    if (!target || cards.length <= statusFeedMaxCards || excess < 1) {
      return;
    }

    cards.some(function (card) {
      var rect = card.getBoundingClientRect();

      if (remove.length >= excess || rect.bottom > -statusFeedPruneMargin || statusFeedCardProtected(card)) {
        return true;
      }

      remove.push(card);
      removedHeight += rect.height;

      return false;
    });

    if (remove.length < 1) {
      return;
    }

    gap = statusFeedGap(target);
    currentSpacerHeight = statusFeedSpacerHeight(target);
    anchor = cards[remove.length] || null;
    beforeTop = anchor ? anchor.getBoundingClientRect().top : 0;
    setStatusFeedSpacerHeight(
      target,
      currentSpacerHeight + removedHeight + gap * (currentSpacerHeight > 0 ? remove.length : Math.max(0, remove.length - 1))
    );
    target.dataset.statusFeedPruned = "true";

    if (refreshUrl) {
      target.dataset.statusFeedRefreshUrl = statusFeedFirstPageUrl(refreshUrl);
    }

    remove.forEach(function (card) {
      card.remove();
    });

    if (anchor) {
      afterTop = anchor.getBoundingClientRect().top;

      if (Math.abs(afterTop - beforeTop) > 1) {
        window.scrollBy(0, afterTop - beforeTop);
      }
    }
  }

  async function refreshPrunedStatusFeed(target) {
    var control = statusFeedControlForTarget(target);
    var url = target ? target.dataset.statusFeedRefreshUrl : "";
    var data;
    var payload;
    var template;

    if (!target || !url || target.dataset.statusFeedRefreshing === "true" || activeModal || qs(".modal[data-open='true']", target)) {
      return;
    }

    target.dataset.statusFeedRefreshing = "true";

    try {
      data = await TinyCat.request(url, { method: "GET", cache: "no-store" });
      payload = statusFeedPayload(data);
      template = htmlTemplate(payload.html || "");
      target.innerHTML = "";
      target.appendChild(template.content);
      delete target.dataset.statusFeedPruned;

      if (control) {
        if (payload.next_url && payload.done !== true) {
          control.dataset.statusFeedUrl = String(payload.next_url || "");
          control.hidden = false;
        } else {
          control.remove();
        }
      }

      hydrateDynamic(target);

      window.requestAnimationFrame(function () {
        target.scrollIntoView({ block: "start" });
      });
    } catch (error) {
      TinyCat.toast((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
    } finally {
      delete target.dataset.statusFeedRefreshing;
    }
  }

  function maybeRefreshPrunedStatusFeeds() {
    qsa("[data-status-feed][data-status-feed-pruned='true']").forEach(function (target) {
      var spacer = qs("[data-status-feed-spacer]", target);
      var rect;

      if (!spacer || spacer.hidden || target.dataset.statusFeedRefreshing === "true") {
        return;
      }

      rect = spacer.getBoundingClientRect();

      if (rect.top < window.innerHeight && rect.bottom > -80) {
        refreshPrunedStatusFeed(target);
      }
    });
  }

  function queuePrunedStatusFeedRefreshCheck() {
    var y = window.scrollY || 0;
    var scrollingUp = y < statusFeedLastScrollY;

    statusFeedLastScrollY = y;

    if (!scrollingUp || statusFeedScrollQueued) {
      return;
    }

    statusFeedScrollQueued = true;
    window.requestAnimationFrame(function () {
      statusFeedScrollQueued = false;
      maybeRefreshPrunedStatusFeeds();
    });
  }

  async function loadStatusFeedMore(control) {
    var target = statusFeedTarget(control);
    var url = control ? control.dataset.statusFeedUrl : "";
    var button = qs("[data-status-feed-load]", control);
    var state = qs("[data-status-feed-state]", control);
    var data;
    var payload;
    var template;

    if (!control || !target || !url || control.dataset.statusFeedBusy === "true") {
      return;
    }

    control.dataset.statusFeedBusy = "true";
    control.classList.add("is-loading");

    if (button) {
      button.disabled = true;
    }

    if (state) {
      state.hidden = false;
    }

    try {
      data = await TinyCat.request(url, { method: "GET" });
      payload = statusFeedPayload(data);

      if (payload.html) {
        template = htmlTemplate(payload.html || "");
        target.appendChild(template.content);
        pruneStatusFeed(target, url);
        hydrateDynamic(target);
      }

      if (!payload.next_url || payload.done === true || Number(payload.count || 0) < 1) {
        if (target.dataset.statusFeedPruned === "true") {
          control.hidden = true;
          return;
        }

        control.remove();
        return;
      }

      control.dataset.statusFeedUrl = String(payload.next_url || "");
    } catch (error) {
      TinyCat.toast((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
    } finally {
      delete control.dataset.statusFeedBusy;
      control.classList.remove("is-loading");

      if (button) {
        button.disabled = false;
      }

      if (state) {
        state.hidden = true;
      }
    }

    if (statusFeedNearViewport(control)) {
      window.setTimeout(function () {
        loadStatusFeedMore(control);
      }, 120);
    }
  }

  async function loadFollowingMore(button) {
    var modal = button ? button.closest(".modal") : null;
    var grid = modal ? qs(".following-modal-grid", modal) : null;
    var url = button ? button.dataset.followingUrl : "";
    var data;
    var payload;
    var template;

    if (!button || !grid || !url || button.dataset.followingBusy === "true") {
      return;
    }

    button.dataset.followingBusy = "true";
    button.disabled = true;

    try {
      data = await TinyCat.request(url, { method: "GET" });
      payload = data && data.data ? data.data : data;
      template = htmlTemplate(String((payload && payload.items_html) || ""));
      grid.appendChild(template.content);

      if (payload && payload.next_url && payload.done !== true) {
        button.dataset.followingUrl = String(payload.next_url);
        button.disabled = false;
      } else {
        button.remove();
      }
    } catch (error) {
      TinyCat.toast((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
      button.disabled = false;
    } finally {
      delete button.dataset.followingBusy;
    }
  }

  function replaceFromDocument(selector, doc) {
    var current = qs(selector);
    var next = qs(selector, doc);
    var imported;

    if (!current || !next) {
      return false;
    }

    imported = document.importNode(next, true);
    current.replaceWith(imported);
    hydrateDynamic(imported);

    return true;
  }

  function replaceStatusRegion(doc) {
    return replaceFromDocument(".profile-layout", doc)
      || replaceFromDocument(".public-layout", doc)
      || replaceFromDocument(".home-feed-section", doc)
      || replaceFromDocument(".profile-main", doc);
  }

  function modalScrollElement(modal) {
    var body = qs(".modal-body", modal);
    var panel = qs(".modal-panel", modal);
    var bodyStyle;

    if (body && body.scrollHeight > body.clientHeight + 2) {
      bodyStyle = window.getComputedStyle ? window.getComputedStyle(body) : null;

      if (!bodyStyle || bodyStyle.overflowY === "auto" || bodyStyle.overflowY === "scroll") {
        return body;
      }
    }

    return panel || body || modal;
  }

  function captureModalScroll(modal) {
    var scroller = modal ? modalScrollElement(modal) : null;
    var remaining;

    if (!scroller) {
      return null;
    }

    remaining = scroller.scrollHeight - scroller.clientHeight - scroller.scrollTop;

    return {
      top: scroller.scrollTop,
      atBottom: remaining <= 80
    };
  }

  function restoreModalScroll(modal, state) {
    var scroller;

    if (!modal || !state) {
      return;
    }

    scroller = modalScrollElement(modal);

    if (!scroller) {
      return;
    }

    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () {
        scroller.scrollTop = state.atBottom ? scroller.scrollHeight : Math.min(state.top, scroller.scrollHeight);
      });
    });
  }

  function shouldKeepStatusModalOpen(action, modal) {
    return Boolean(
      modal
        && modal.id
        && modal.id.indexOf("status-post-modal-") === 0
        && ["react", "comment", "comment_like", "comment_delete"].indexOf(action) !== -1
    );
  }

  function detachModalFromCard(modal, card) {
    if (modal && card && card.contains(modal)) {
      document.body.appendChild(modal);
    }
  }

  async function refreshOpenStatusModal(modal, url, scrollState) {
    var data;
    var payload;
    var html;
    var template;
    var nextModal;
    var currentPanel;
    var nextPanel;
    var importedPanel;
    var labelledBy;

    if (!modal || !url) {
      restoreModalScroll(modal, scrollState);
      return false;
    }

    data = await TinyCat.request(url, { method: "GET" });
    payload = unwrapResult(data);
    html = payload && typeof payload === "object" ? (payload.html || "") : "";

    if (!html) {
      return false;
    }

    template = htmlTemplate(html);
    nextModal = qs(".modal", template.content);
    currentPanel = qs(".modal-panel", modal);
    nextPanel = nextModal ? qs(".modal-panel", nextModal) : null;

    if (!nextModal || !currentPanel || !nextPanel) {
      return false;
    }

    modal.className = nextModal.className;
    modal.setAttribute("role", nextModal.getAttribute("role") || "dialog");
    modal.setAttribute("aria-modal", nextModal.getAttribute("aria-modal") || "true");
    labelledBy = nextModal.getAttribute("aria-labelledby") || "";
    if (labelledBy) {
      modal.setAttribute("aria-labelledby", labelledBy);
    } else {
      modal.removeAttribute("aria-labelledby");
    }
    modal.setAttribute("aria-hidden", "false");
    modal.dataset.open = "true";
    modal.dataset.remoteLoaded = "true";
    modal.dataset.modalUrl = url;

    importedPanel = document.importNode(nextPanel, true);
    currentPanel.replaceWith(importedPanel);
    hydrateDynamic(modal);
    restoreModalScroll(modal, scrollState);

    return true;
  }

  function statusCardId(card) {
    var match = card && card.id ? /^status-(\d+)$/.exec(card.id) : null;

    return match ? match[1] : "";
  }

  function statusFormCard(form) {
    var card = form ? form.closest(".status-card") : null;
    var id = form && form.dataset ? form.dataset.statusId : "";
    var field;

    if (card) {
      return card;
    }

    if (!id && form) {
      field = qs('input[name="id"]', form);
      id = field ? field.value : "";
    }

    return id ? document.getElementById("status-" + id) : null;
  }

  function statusCardAction(form) {
    var card = statusFormCard(form);
    var action = card && card.dataset ? (card.dataset.statusAction || "") : "";
    var url;

    if (action) {
      return action;
    }

    url = new URL(window.location.href);

    return url.pathname + url.search;
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

  function elementFromHtml(html) {
    var template = htmlTemplate(String(html || "").trim());

    return template.content.firstElementChild;
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

  function appendStatusComment(comment, scope) {
    var contentId = comment && comment.content_id ? String(comment.content_id) : "";
    var parentId = comment && comment.parent_id ? String(comment.parent_id) : "";
    var node = elementFromHtml(comment ? comment.html : "");
    var root = scope || document;
    var list;
    var parent;

    if (!node || !contentId) {
      return;
    }

    if (parentId && parentId !== "0") {
      parent = qs("[data-comment-id]" + dataSelector("data-comment-id", parentId), root);
      list = parent ? ensureCommentReplies(parent) : null;
    } else {
      list = qs("[data-status-comment-list]" + dataSelector("data-status-id", contentId), root);
    }

    if (!list) {
      return;
    }

    list.appendChild(node);
    hydrateDynamic(node);
  }

  function removeStatusComment(commentId) {
    var id = commentId ? String(commentId) : "";

    if (!id) {
      return;
    }

    qsa("[data-comment-id]" + dataSelector("data-comment-id", id)).forEach(function (node) {
      node.remove();
    });
  }

  function updateStatusComment(comment) {
    var id = comment && comment.comment_id ? String(comment.comment_id) : "";
    var html = comment && comment.body_html ? String(comment.body_html) : "";

    if (!id || !html) {
      return;
    }

    qsa("[data-comment-id]" + dataSelector("data-comment-id", id) + " .status-comment-body").forEach(function (body) {
      body.innerHTML = "";
      body.appendChild(htmlTemplate(html).content);
    });
  }

  function statusFeedScope(form) {
    return form
      ? form.closest(".home-feed-section, .profile-main, .public-layout, .profile-layout")
      : null;
  }

  function statusFeedForForm(form) {
    var scope = statusFeedScope(form);

    return (scope ? qs("[data-status-feed]", scope) : null) || qs("[data-status-feed]");
  }

  function prependStatusCard(form, html) {
    var target = statusFeedForForm(form);
    var node = elementFromHtml(html);
    var spacer;

    if (!target || !node) {
      return;
    }

    qsa("[data-status-empty]", statusFeedScope(form) || document).forEach(function (empty) {
      empty.remove();
    });

    spacer = qs("[data-status-feed-spacer]", target);

    if (spacer && spacer.nextSibling) {
      target.insertBefore(node, spacer.nextSibling);
    } else {
      target.insertBefore(node, target.firstChild);
    }

    hydrateDynamic(node);
  }

  function replaceStatusCard(form, html) {
    var current = statusFormCard(form);
    var node = elementFromHtml(html);

    if (!current || !node) {
      return false;
    }

    current.replaceWith(node);
    hydrateDynamic(node);

    return true;
  }

  function removeStatusCard(statusId, form) {
    var id = statusId ? String(statusId) : "";
    var current = statusFormCard(form);

    if (!id && current) {
      id = statusCardId(current);
    }

    if (!id) {
      return;
    }

    qsa(".status-card").forEach(function (card) {
      if (statusCardId(card) === id) {
        card.remove();
      }
    });
  }

  function resetStatusCreateForm(form) {
    if (!form || statusFormAction(form) !== "create") {
      return;
    }

    form.reset();
    qsa("[data-status-editor-source]", form).forEach(function (source) {
      source.value = "";
    });
    qsa("[data-status-editor]", form).forEach(function (root) {
      if (TinyCat.resetStatusEditor) {
        TinyCat.resetStatusEditor(root);
      }
    });
  }

  function resetStatusCommentForm(form) {
    var replyContainer;
    var fields;
    var details;
    var parentField;

    if (!form || statusFormAction(form) === "") {
      return;
    }

    if (statusFormAction(form) === "comment") {
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
        if (TinyCat.resetStatusEditor) {
          TinyCat.resetStatusEditor(root);
        }
      });

      if (details && parseInt((parentField && parentField.value) || "0", 10) > 0) {
        details.open = false;
      }
    }
  }

  function handleStatusJsonResponse(form, data) {
    var payload = unwrapResult(data);
    var action = payload && payload.action ? String(payload.action) : statusFormAction(form);
    var modal = form ? form.closest(".modal") : null;
    var messageType = (payload && payload.type) || (data && data.type) || (data && data.meta && data.meta.type) || "success";

    if (data && data.message) {
      TinyCat.toast(data.message, data.type || (data.ok === false ? "danger" : "success"));
    } else if (payload && payload.message) {
      TinyCat.toast(payload.message, messageType);
    }

    if (payload && payload.status) {
      updateStatusSummary(payload.status);
    }

    if (payload && payload.comment_like) {
      updateCommentLike(payload.comment_like);
    }

    if (payload && payload.comment) {
      appendStatusComment(payload.comment, form.closest(".modal") || document);
      resetStatusCommentForm(form);
    }

    if (payload && payload.updated_comment) {
      updateStatusComment(payload.updated_comment);
    }

    if (payload && payload.card_html) {
      if (action === "create") {
        prependStatusCard(form, payload.card_html);
        resetStatusCreateForm(form);
      } else {
        replaceStatusCard(form, payload.card_html);
      }
    }

    if (payload && payload.deleted_status_id) {
      removeStatusCard(payload.deleted_status_id, form);
    }

    if (payload && payload.deleted_comment_id) {
      removeStatusComment(payload.deleted_comment_id);
    }

    if (modal && payload && payload.modal_close) {
      TinyCat.closeModal(modal);
    }

    markFormClean(form);
    emit(form, "tinycat:success", { data: data, payload: payload, target: null });
  }

  async function refreshStatusCard(form, currentCard) {
    var id = statusCardId(currentCard);
    var action = statusCardAction(form);
    var url;
    var data;
    var payload;
    var html;
    var template;
    var nextCard;

    if (!id) {
      return null;
    }

    url = "/api/status-card?id=" + encodeURIComponent(id) + "&action=" + encodeURIComponent(action);
    data = await TinyCat.request(url, { method: "GET" });
    payload = unwrapResult(data);
    html = payload && typeof payload === "object" ? (payload.html || "") : "";

    if (!html) {
      return null;
    }

    template = htmlTemplate(html);
    nextCard = qs(".status-card", template.content);

    if (!nextCard) {
      return null;
    }

    currentCard.replaceWith(nextCard);
    hydrateDynamic(nextCard);

    return nextCard;
  }

  async function syncStatusResponse(form, doc, action) {
    var currentCard = statusFormCard(form);
    var openedModal = form.closest(".modal");
    var keepModalOpen = shouldKeepStatusModalOpen(action, openedModal);
    var reopenModalId = openedModal && openedModal.dataset.open === "true" ? openedModal.id : "";
    var reopenModalUrl = openedModal && openedModal.dataset.modalUrl ? openedModal.dataset.modalUrl : "";
    var modalScroll = captureModalScroll(openedModal);
    var nextCard;
    var imported;
    var reopenedModal;

    if (keepModalOpen) {
      detachModalFromCard(openedModal, currentCard);
    } else if (openedModal) {
      TinyCat.closeModal(openedModal);
    }

    if (currentCard && currentCard.id) {
      nextCard = doc.getElementById(currentCard.id);

      if (nextCard) {
        imported = document.importNode(nextCard, true);
        currentCard.replaceWith(imported);
        hydrateDynamic(imported);

        if (keepModalOpen) {
          await refreshOpenStatusModal(openedModal, reopenModalUrl, modalScroll);
        } else if (reopenModalId && action !== "update" && action !== "delete" && action !== "report") {
          if (reopenModalUrl) {
            loadRemoteModal(reopenModalId, reopenModalUrl, imported, true)
              .then(function (modal) {
                reopenedModal = TinyCat.openModal(modal);
                restoreModalScroll(reopenedModal, modalScroll);
              })
              .catch(function (error) {
                TinyCat.toast((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
              });
          } else {
            reopenedModal = TinyCat.openModal(reopenModalId);
            restoreModalScroll(reopenedModal, modalScroll);
          }
        }

        return true;
      }

      if (action === "delete") {
        currentCard.remove();
        return true;
      }

      imported = await refreshStatusCard(form, currentCard);

      if (imported) {
        if (keepModalOpen) {
          await refreshOpenStatusModal(openedModal, reopenModalUrl, modalScroll);
        } else if (reopenModalId && action !== "update" && action !== "report") {
          if (reopenModalUrl) {
            loadRemoteModal(reopenModalId, reopenModalUrl, imported, true)
              .then(function (modal) {
                reopenedModal = TinyCat.openModal(modal);
                restoreModalScroll(reopenedModal, modalScroll);
              })
              .catch(function (error) {
                TinyCat.toast((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
              });
          } else {
            reopenedModal = TinyCat.openModal(reopenModalId);
            restoreModalScroll(reopenedModal, modalScroll);
          }
        }

        return true;
      }

      if (replaceStatusRegion(doc)) {
        if (keepModalOpen) {
          await refreshOpenStatusModal(openedModal, reopenModalUrl, modalScroll);
        }

        return true;
      }

      currentCard.remove();
      return true;
    }

    if (keepModalOpen) {
      replaceStatusRegion(doc);
      await refreshOpenStatusModal(openedModal, reopenModalUrl, modalScroll);
      return true;
    }

    return replaceStatusRegion(doc);
  }

  TinyCat.initStatusForms = function () {
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
      var headers;
      var response;
      var contentType;
      var jsonData;
      var html;
      var doc;
      var responseUrl;
      var responsePath;
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
      action = statusFormAction(form, body);
      method = (form.getAttribute("method") || "POST").toUpperCase();
      url = form.getAttribute("action") || window.location.href;
      headers = {
        "Accept": "application/json, text/html;q=0.9, application/xhtml+xml;q=0.8, */*;q=0.7",
        "X-Requested-With": "XMLHttpRequest"
      };

      if (getCsrfToken()) {
        headers["X-CSRF-Token"] = getCsrfToken();
      }

      try {
        form.dataset.statusBusy = "true";
        setLoading(form, true);

        response = await fetch(url, {
          method: method,
          headers: headers,
          body: method === "GET" ? null : body
        });

        responseUrl = response.url || "";
        contentType = response.headers.get("content-type") || "";

        if (response.redirected && responseUrl) {
          responsePath = new URL(responseUrl, window.location.href).pathname;

          if (responsePath === "/login" || responsePath === "/install") {
            window.location.assign(responseUrl);
            return;
          }
        }

        if (contentType.indexOf("application/json") !== -1) {
          jsonData = await response.json();

          if (!response.ok) {
            var requestError = new Error(jsonData.message || (jsonData.error && jsonData.error.message) || response.statusText || uiText("requestFailed", "Request failed"));
            requestError.data = jsonData;
            throw requestError;
          }

          handleStatusJsonResponse(form, jsonData);
          return;
        }

        html = await response.text();

        if (!response.ok) {
          throw new Error(response.statusText || uiText("requestFailed", "Request failed"));
        }

        doc = new DOMParser().parseFromString(html, "text/html");
        renderFlashMessages(doc);

        if (!await syncStatusResponse(form, doc, action)) {
          window.location.assign(responseUrl || url);
        }
      } catch (error) {
        var errors = resultErrors(error.data);

        if (errors) {
          invalidField = applyErrors(form, errors);
        }

        TinyCat.toast(error.message || uiText("requestFailed", "Request failed"), "danger");
      } finally {
        delete form.dataset.statusBusy;
        setLoading(form, false);

        if (invalidField && invalidField.isConnected && invalidField.focus) {
          invalidField.focus();
        }
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
  };

  TinyCat.initStatusFeedLazy = function (scope) {
    qsa("[data-status-feed-more]", scope || document).forEach(function (control) {
      if (control.dataset.statusFeedReady === "true") {
        return;
      }

      control.dataset.statusFeedReady = "true";

      if ("IntersectionObserver" in window) {
        if (!TinyCat.__statusFeedObserver) {
          TinyCat.__statusFeedObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting) {
                loadStatusFeedMore(entry.target);
              }
            });
          }, { rootMargin: "420px 0px" });
        }

        TinyCat.__statusFeedObserver.observe(control);
      }
    });

    if (TinyCat.__statusFeedPruneScrollReady !== true) {
      TinyCat.__statusFeedPruneScrollReady = true;
      window.addEventListener("scroll", queuePrunedStatusFeedRefreshCheck, { passive: true });
    }

    if (TinyCat.__statusFeedClickReady === true) {
      return;
    }

    TinyCat.__statusFeedClickReady = true;

    document.addEventListener("click", function (event) {
      var trigger = event.target.closest && event.target.closest("[data-status-feed-load]");

      if (!trigger) {
        return;
      }

      event.preventDefault();
      loadStatusFeedMore(trigger.closest("[data-status-feed-more]"));
    });

    document.addEventListener("click", function (event) {
      var trigger = event.target.closest && event.target.closest("[data-following-load]");

      if (!trigger) {
        return;
      }

      event.preventDefault();
      loadFollowingMore(trigger);
    });
  };

  function updateFollowResult(form, data) {
    var payload = unwrapResult(data) || {};
    var authorId = payload.author_id ? String(payload.author_id) : (form ? form.dataset.authorId : "");
    var next = elementFromHtml(payload.html || "");

    if (data && data.message) {
      TinyCat.toast(data.message, data.type || "success");
    } else if (payload.message) {
      TinyCat.toast(payload.message, payload.type || "success");
    }

    if (authorId) {
      qsa("[data-author-stat='followers']" + dataSelector("data-author-id", authorId)).forEach(function (node) {
        node.textContent = String(payload.followers_count || 0);
      });
      qsa("[data-author-stat='following']" + dataSelector("data-author-id", authorId)).forEach(function (node) {
        node.textContent = String(payload.following_count || 0);
      });
    }

    if (form && next) {
      form.replaceWith(next);
      hydrateDynamic(next);
    }
  }

  TinyCat.initFollowForms = function () {
    if (TinyCat.__followFormEventsBound === true) {
      return;
    }

    TinyCat.__followFormEventsBound = true;

    document.addEventListener("submit", async function (event) {
      var form = event.target.closest && event.target.closest("form[data-follow-form]");
      var method;
      var url;
      var body;
      var data;

      if (!form) {
        return;
      }

      event.preventDefault();

      if (form.dataset.followBusy === "true") {
        return;
      }

      method = (form.getAttribute("method") || "POST").toUpperCase();
      url = form.getAttribute("action") || window.location.href;
      body = new FormData(form);

      try {
        form.dataset.followBusy = "true";
        setLoading(form, true);
        data = await TinyCat.request(url, {
          method: method,
          body: method === "GET" ? null : body
        });
        updateFollowResult(form, data);
      } catch (error) {
        TinyCat.toast((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
      } finally {
        delete form.dataset.followBusy;
        setLoading(form, false);
      }
    });
  };

  function notificationBadgeText(count) {
    count = Math.max(0, parseInt(count || "0", 10) || 0);
    return count > 99 ? "99+" : String(count);
  }

  function updateNotificationButton(button, state) {
    var unread = Math.max(0, parseInt((state && state.unread) || "0", 10) || 0);
    var latestId = Math.max(0, parseInt((state && state.latest_id) || "0", 10) || 0);
    var badge = qs("[data-notification-count]", button);
    var menu = button.closest && button.closest("[data-notification-menu]");
    var menuBadge = menu ? qs("[data-notification-menu-count]", menu) : null;
    var list = menu ? qs("[data-notification-list]", menu) : null;

    button.dataset.notificationUnread = String(unread);
    button.dataset.notificationLatestId = String(latestId);
    button.classList.toggle("has-unread", unread > 0);

    if (badge) {
      badge.hidden = unread < 1;
      badge.textContent = notificationBadgeText(unread);
    }

    if (menuBadge) {
      menuBadge.hidden = unread < 1;
      menuBadge.textContent = notificationBadgeText(unread);
    }

    if (list && state && typeof state.html === "string") {
      replaceHtml(list, state.html);
    }
  }

  function setNotificationMenu(menu, open) {
    if (!menu) {
      return;
    }

    var button = qs("[data-notification-button]", menu);
    var popover = qs("[data-notification-popover]", menu);

    if (!button || !popover) {
      return;
    }

    menu.classList.toggle("is-open", open);
    popover.hidden = !open;
    button.setAttribute("aria-expanded", open ? "true" : "false");
  }

  function closeNotificationMenus(except) {
    qsa("[data-notification-menu]").forEach(function (menu) {
      if (except && menu === except) {
        return;
      }

      setNotificationMenu(menu, false);
    });
  }

  TinyCat.initNotifications = function () {
    if (TinyCat.__notificationMenuEventsBound !== true) {
      TinyCat.__notificationMenuEventsBound = true;

      document.addEventListener("click", function (event) {
        var button = event.target.closest && event.target.closest("[data-notification-button]");
        var menu = event.target.closest && event.target.closest("[data-notification-menu]");

        if (button) {
          if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || button.target === "_blank") {
            return;
          }

          event.preventDefault();
          setNotificationMenu(button.closest("[data-notification-menu]"), button.getAttribute("aria-expanded") !== "true");
          return;
        }

        if (!menu) {
          closeNotificationMenus();
        }
      });

      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          closeNotificationMenus();
        }
      });
    }

    qsa("[data-notification-button]").forEach(function (button) {
      var api;
      var interval;
      var latestId;
      var unread;
      var first = true;
      var polling = false;

      if (button.dataset.notificationReady === "true") {
        return;
      }

      api = button.dataset.notificationApi || "/api/notifications";
      interval = Math.max(3000, parseInt(button.dataset.notificationInterval || "5000", 10) || 5000);
      latestId = Math.max(0, parseInt(button.dataset.notificationLatestId || "0", 10) || 0);
      unread = Math.max(0, parseInt(button.dataset.notificationUnread || "0", 10) || 0);
      button.dataset.notificationReady = "true";
      updateNotificationButton(button, {
        unread: unread,
        latest_id: latestId
      });

      async function poll() {
        var state;
        var nextLatestId;
        var nextUnread;
        var message;

        if (document.hidden || polling) {
          return;
        }

        polling = true;

        try {
          state = await TinyCat.request(api, {
            method: "GET",
            cache: "no-store"
          });
          state = unwrapResult(state) || {};
          nextLatestId = Math.max(0, parseInt((state && state.latest_id) || "0", 10) || 0);
          nextUnread = Math.max(0, parseInt((state && state.unread) || "0", 10) || 0);
          message = (state && state.message) || button.dataset.notificationMessage || "";

          if (!first && message && nextUnread > 0 && (nextLatestId > latestId || nextUnread > unread)) {
            TinyCat.toast(message, "info", 4200);
          }

          updateNotificationButton(button, state || {});
          latestId = nextLatestId;
          unread = nextUnread;
          first = false;
        } catch (_error) {
          first = false;
        } finally {
          polling = false;
        }
      }

      window.setInterval(poll, interval);
      document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
          poll();
        }
      });
    });
  };

  TinyCat.init = function () {
    initUserAvatarImages(document);
    TinyCat.initAdminNav();
    TinyCat.initModals();
    TinyCat.initAjax();
    TinyCat.initConfirm();
    TinyCat.initToasts();
    TinyCat.initTabs();
    TinyCat.initStatusEditors();
    qsa("[data-status-image]", document).forEach(initStatusImageRoot);
    qsa("[data-status-video]", document).forEach(initStatusVideoRoot);
    qsa("[data-status-link-image]", document).forEach(initStatusLinkImageRoot);
    TinyCat.initStatusFeedLazy();
    TinyCat.initGlobalSearch();
    TinyCat.initStickySidebars();
    TinyCat.initPublicSidebar();
    TinyCat.initCaptcha();
    TinyCat.initDirtyForms();
    TinyCat.initAutoSubmit();
    TinyCat.initDismissibleMenus();
    TinyCat.initCommentReplies();
    TinyCat.initStatusForms();
    TinyCat.initFollowForms();
    TinyCat.initNotifications();
  };

  window.TinyCat = TinyCat;
  ready(TinyCat.init);
}());
